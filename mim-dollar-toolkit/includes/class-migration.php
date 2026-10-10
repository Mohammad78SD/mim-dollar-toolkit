<?php
/**
 * One-time data migration from earlier internal versions of this plugin.
 *
 * Two earlier generations may be present on a site, each with its own prefix:
 *  - `wtt_` (marker constant WTT_TK_FILE), the newer one;
 *  - `azk_` (marker constant AZK_TK_FILE), the original.
 *
 * This file intentionally references the OLD names. For every legacy generation it copies options, renames meta
 * keys, order statuses and payment methods, drops old schedules, and deactivates the old plugin. Generations are
 * processed newest first and options are only copied when the target is missing, so on a site that still holds both
 * the newer values win. It is idempotent and guarded by the `mdt_migration_version` option plus a short-lived
 * add_option() lock.
 *
 * @package MimDollarToolkit
 */

defined( 'ABSPATH' ) || exit;

class MDT_Migration {

	/** Bump when a new migration step is added (steps must stay idempotent). */
	const VERSION = '1';

	const OPTION_VERSION = 'mdt_migration_version';
	const OPTION_LOCK    = 'mdt_migration_lock';
	const OPTION_NOTICE  = 'mdt_migration_notice';
	const LOCK_TTL       = 300;

	/** Prefix of the current plugin. */
	const NEW_PREFIX = 'mdt';

	/**
	 * Legacy generations, newest first (order matters: the first one to supply an option value wins).
	 * Each maps the old prefix to the constant the old plugin's main file defines, which is how the old plugin is
	 * recognised without hard-coding its folder name.
	 *
	 * @return array<string,string> prefix => marker constant.
	 */
	public static function generations() {
		return array(
			'wtt' => 'WTT_TK_FILE',
			'azk' => 'AZK_TK_FILE',
		);
	}

	/**
	 * Everything that changes between an old prefix and the new one. Pure (no WordPress calls), so it is unit tested.
	 *
	 * @param string $old Old prefix without trailing underscore, e.g. 'wtt'.
	 * @return array
	 */
	public static function prefix_map( $old ) {
		$new = self::NEW_PREFIX;
		return array(
			'old'            => $old,
			'new'            => $new,
			// Option names: <p>_foo and woocommerce_<p>_foo (gateway + email settings).
			'option_prefix'  => array( $old . '_', 'woocommerce_' . $old . '_' ),
			'option_new'     => array( $new . '_', 'woocommerce_' . $new . '_' ),
			'postmeta_old'   => '_' . $old . '_',
			'postmeta_new'   => '_' . $new . '_',
			'usermeta_old'   => $old . '_',
			'usermeta_new'   => $new . '_',
			'status_old'     => 'wc-' . $old . '-preinvoice',
			'status_new'     => 'wc-' . $new . '-preinvoice',
			'method_old'     => $old . '_preinvoice',
			'method_new'     => $new . '_preinvoice',
			'cron_prefix'    => $old . '_',
			'as_hooks'       => array( $old . '_usd_sync_batch', $old . '_sms_send' ),
			'as_groups'      => array( $old . '_usd', $old . '_sms' ),
			'transient_like' => array(
				'_transient_' . $old . '_',
				'_transient_timeout_' . $old . '_',
				'_site_transient_' . $old . '_',
				'_site_transient_timeout_' . $old . '_',
			),
		);
	}

	/**
	 * wtt_foo -> mdt_foo, woocommerce_wtt_foo -> woocommerce_mdt_foo.
	 *
	 * @param string $name Old option name.
	 * @param string $old  Old prefix.
	 * @return string Empty string when the name does not belong to that prefix.
	 */
	public static function map_option_name( $name, $old ) {
		$map = self::prefix_map( $old );
		foreach ( $map['option_prefix'] as $i => $from ) {
			if ( 0 === strpos( $name, $from ) ) {
				return $map['option_new'][ $i ] . substr( $name, strlen( $from ) );
			}
		}
		return '';
	}

	/**
	 * Migration bookkeeping options of an older generation must never be copied (they would mark this one done).
	 *
	 * @param string $name Old option name.
	 * @param string $old  Old prefix.
	 * @return bool
	 */
	public static function is_bookkeeping_option( $name, $old ) {
		return in_array( $name, array( $old . '_migration_version', $old . '_migration_lock', $old . '_migration_notice' ), true );
	}

	/** @var string[] Log lines collected during a run. */
	private static $report = array();

	public static function init() {
		// Priority 2: after Action Scheduler's data store is ready (init:1) and before module setup (init:20).
		add_action( 'init', array( __CLASS__, 'maybe_run' ), 2 );
		add_action( 'admin_notices', array( __CLASS__, 'admin_notice' ) );
	}

	/**
	 * Cheap check: one autoloaded option once migrated.
	 */
	public static function maybe_run() {
		if ( get_option( self::OPTION_VERSION ) === self::VERSION ) {
			return;
		}
		if ( ! self::legacy_detected() ) {
			// Fresh install: nothing to migrate, never check again.
			update_option( self::OPTION_VERSION, self::VERSION );
			return;
		}
		self::run();
	}

	/**
	 * Is there anything from an old generation on this site?
	 *
	 * @return bool
	 */
	private static function legacy_detected() {
		global $wpdb;
		if ( array() !== self::find_old_plugins() ) {
			return true;
		}
		foreach ( array_keys( self::generations() ) as $old ) {
			$map   = self::prefix_map( $old );
			$found = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s LIMIT 1",
					$wpdb->esc_like( $map['option_prefix'][0] ) . '%',
					$wpdb->esc_like( $map['option_prefix'][1] ) . '%'
				)
			);
			if ( $found ) {
				return true;
			}
			if ( $wpdb->get_var( $wpdb->prepare( "SELECT meta_id FROM {$wpdb->usermeta} WHERE meta_key LIKE %s LIMIT 1", $wpdb->esc_like( $map['usermeta_old'] ) . '%' ) ) ) {
				return true;
			}
			if ( $wpdb->get_var( $wpdb->prepare( "SELECT meta_id FROM {$wpdb->postmeta} WHERE meta_key LIKE %s LIMIT 1", $wpdb->esc_like( $map['postmeta_old'] ) . '%' ) ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Basenames (dir/file.php) of the active old plugins, one per generation found.
	 * Uses the marker constant when the old plugin is already loaded, otherwise scans the first 8 KB of every
	 * active plugin's main file for the marker.
	 *
	 * @return string[]
	 */
	private static function find_old_plugins() {
		$active = (array) get_option( 'active_plugins', array() );
		if ( is_multisite() ) {
			$active = array_merge( $active, array_keys( (array) get_site_option( 'active_sitewide_plugins', array() ) ) );
		}
		$own   = plugin_basename( MDT_TK_FILE );
		$found = array();
		foreach ( self::generations() as $marker ) {
			if ( defined( $marker ) ) {
				$basename = plugin_basename( constant( $marker ) );
				if ( $basename !== $own && in_array( $basename, $active, true ) ) {
					$found[] = $basename;
					continue;
				}
			}
			foreach ( $active as $plugin ) {
				$plugin = (string) $plugin;
				if ( $plugin === $own || '.php' !== substr( $plugin, -4 ) || in_array( $plugin, $found, true ) ) {
					continue;
				}
				$path = WP_PLUGIN_DIR . '/' . $plugin;
				if ( ! is_readable( $path ) ) {
					continue;
				}
				$head = file_get_contents( $path, false, null, 0, 8192 ); // phpcs:ignore WordPress.WP.AlternativeFunctions
				if ( false !== $head && 1 === preg_match( '/define\s*\(\s*[\'"]' . preg_quote( $marker, '/' ) . '[\'"]/', $head ) ) {
					$found[] = $plugin;
					break;
				}
			}
		}
		return array_values( array_unique( $found ) );
	}

	/**
	 * Run all steps under a lock. Safe to call repeatedly.
	 *
	 * @return bool True when this call performed the migration.
	 */
	public static function run() {
		if ( get_option( self::OPTION_VERSION ) === self::VERSION ) {
			return false;
		}
		if ( ! self::acquire_lock() ) {
			return false;
		}

		self::$report = array();
		try {
			$replaced = self::deactivate_old_plugins();

			foreach ( array_keys( self::generations() ) as $old ) {
				$map = self::prefix_map( $old );
				/* translators: %s: old option prefix */
				self::log( 'info', sprintf( __( 'Migrating data of the earlier version with prefix %s.', 'mim-dollar-toolkit' ), $old . '_' ) );
				self::step_options( $map );
				self::step_gateway_order( $map );
				self::step_meta( $map );
				self::step_orders( $map );
				self::step_schedules_old( $map );
				self::step_transients( $map );
			}
			self::step_schedules_new();
			self::step_caches();

			update_option( self::OPTION_VERSION, self::VERSION );
			self::log( 'info', __( 'Migration from the legacy plugin finished.', 'mim-dollar-toolkit' ) );
			update_option(
				self::OPTION_NOTICE,
				array(
					'replaced' => $replaced,
					'lines'    => self::$report,
				),
				false
			);
			if ( $replaced ) {
				// An old plugin stays loaded for the rest of this request and may re-schedule its cron on init:20.
				add_action( 'shutdown', array( __CLASS__, 'clear_old_cron' ), 99 );
			}
		} catch ( \Throwable $e ) {
			self::log( 'error', 'Migration failed: ' . $e->getMessage() );
			self::release_lock();
			return false;
		}

		self::release_lock();
		return true;
	}

	/* ------------------------------------------------------------ lock / log */

	private static function acquire_lock() {
		if ( add_option( self::OPTION_LOCK, (string) time(), '', 'no' ) ) {
			return true;
		}
		$taken = (int) get_option( self::OPTION_LOCK, 0 );
		if ( $taken && ( time() - $taken ) > self::LOCK_TTL ) {
			// Stale lock from a crashed run: take it over.
			update_option( self::OPTION_LOCK, (string) time(), false );
			return true;
		}
		return false;
	}

	private static function release_lock() {
		delete_option( self::OPTION_LOCK );
	}

	private static function log( $level, $message ) {
		self::$report[] = $message;
		if ( class_exists( 'MDT_Logger' ) ) {
			MDT_Logger::add( 'toolkit', $level, $message );
		}
	}

	/* ------------------------------------------------------------ steps */

	/**
	 * Deactivate every active old plugin silently (no deactivation hooks) before touching data.
	 *
	 * @return bool Whether an old plugin was active.
	 */
	private static function deactivate_old_plugins() {
		$old = self::find_old_plugins();
		if ( array() === $old ) {
			return false;
		}
		if ( ! function_exists( 'deactivate_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		foreach ( $old as $basename ) {
			deactivate_plugins( $basename, true, is_multisite() && is_plugin_active_for_network( $basename ) );
		}
		self::log( 'info', __( 'The old plugin was deactivated; it has been replaced by this plugin and can be deleted.', 'mim-dollar-toolkit' ) );
		return true;
	}

	/**
	 * Copy <old>_* / woocommerce_<old>_* options to mdt_* (only when the target is missing), keeping autoload.
	 *
	 * @param array $map Prefix map.
	 */
	private static function step_options( $map ) {
		global $wpdb;
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT option_name, option_value, autoload FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
				$wpdb->esc_like( $map['option_prefix'][0] ) . '%',
				$wpdb->esc_like( $map['option_prefix'][1] ) . '%'
			)
		);
		$copied  = 0;
		$skipped = 0;
		foreach ( (array) $rows as $row ) {
			if ( self::is_bookkeeping_option( $row->option_name, $map['old'] ) ) {
				continue;
			}
			$target = self::map_option_name( $row->option_name, $map['old'] );
			if ( '' === $target ) {
				continue;
			}
			$exists = $wpdb->get_var( $wpdb->prepare( "SELECT option_id FROM {$wpdb->options} WHERE option_name = %s", $target ) );
			if ( $exists ) {
				++$skipped;
				continue;
			}
			// Raw copy keeps serialized values byte-identical.
			$ok = $wpdb->insert(
				$wpdb->options,
				array(
					'option_name'  => $target,
					'option_value' => $row->option_value,
					'autoload'     => $row->autoload,
				)
			);
			if ( $ok ) {
				++$copied;
			}
		}
		wp_cache_delete( 'alloptions', 'options' );
		wp_cache_delete( 'notoptions', 'options' );
		/* translators: 1: options copied, 2: options skipped because the new one already existed */
		self::log( 'info', sprintf( __( 'Options: %1$d copied, %2$d skipped (already present).', 'mim-dollar-toolkit' ), $copied, $skipped ) );
	}

	/**
	 * Payment gateway ordering (woocommerce_gateway_order) keyed by gateway id.
	 *
	 * @param array $map Prefix map.
	 */
	private static function step_gateway_order( $map ) {
		$order = get_option( 'woocommerce_gateway_order', null );
		if ( ! is_array( $order ) || ! array_key_exists( $map['method_old'], $order ) ) {
			return;
		}
		$has_new = array_key_exists( $map['method_new'], $order );
		$new     = array();
		foreach ( $order as $key => $pos ) {
			if ( $map['method_old'] === $key ) {
				if ( $has_new ) {
					continue; // The newer generation's position wins.
				}
				$key = $map['method_new'];
			}
			$new[ $key ] = $pos;
		}
		update_option( 'woocommerce_gateway_order', $new );
		self::log( 'info', __( 'Payment gateway ordering updated.', 'mim-dollar-toolkit' ) );
	}

	/**
	 * Rename meta keys: postmeta/HPOS order meta _<old>_ -> _mdt_, usermeta <old>_ -> mdt_, order item meta.
	 *
	 * @param array $map Prefix map.
	 */
	private static function step_meta( $map ) {
		global $wpdb;
		$post_like = $wpdb->esc_like( $map['postmeta_old'] ) . '%';
		$user_like = $wpdb->esc_like( $map['usermeta_old'] ) . '%';
		$post_cut  = strlen( $map['postmeta_old'] ) + 1;
		$user_cut  = strlen( $map['usermeta_old'] ) + 1;

		$n = $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->postmeta} SET meta_key = CONCAT(%s, SUBSTRING(meta_key, %d)) WHERE meta_key LIKE %s", $map['postmeta_new'], $post_cut, $post_like ) );
		/* translators: %d: number of rows */
		self::log( 'info', sprintf( __( 'Post meta renamed: %d rows.', 'mim-dollar-toolkit' ), (int) $n ) );

		$n = $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->usermeta} SET meta_key = CONCAT(%s, SUBSTRING(meta_key, %d)) WHERE meta_key LIKE %s", $map['usermeta_new'], $user_cut, $user_like ) );
		/* translators: %d: number of rows */
		self::log( 'info', sprintf( __( 'User meta renamed: %d rows.', 'mim-dollar-toolkit' ), (int) $n ) );

		$orders_meta = $wpdb->prefix . 'wc_orders_meta';
		if ( self::table_exists( $orders_meta ) ) {
			$n = $wpdb->query( $wpdb->prepare( "UPDATE `{$orders_meta}` SET meta_key = CONCAT(%s, SUBSTRING(meta_key, %d)) WHERE meta_key LIKE %s", $map['postmeta_new'], $post_cut, $post_like ) );
			/* translators: %d: number of rows */
			self::log( 'info', sprintf( __( 'HPOS order meta: %d rows.', 'mim-dollar-toolkit' ), (int) $n ) );
		}

		$item_meta = $wpdb->prefix . 'woocommerce_order_itemmeta';
		if ( self::table_exists( $item_meta ) ) {
			$n = $wpdb->query( $wpdb->prepare( "UPDATE `{$item_meta}` SET meta_key = CONCAT(%s, SUBSTRING(meta_key, %d)) WHERE meta_key LIKE %s", $map['postmeta_new'], $post_cut, $post_like ) );
			/* translators: %d: number of rows */
			self::log( 'info', sprintf( __( 'Order item meta: %d rows.', 'mim-dollar-toolkit' ), (int) $n ) );
		}
	}

	/**
	 * Order status wc-<old>-preinvoice -> wc-mdt-preinvoice and payment method <old>_preinvoice -> mdt_preinvoice,
	 * in the legacy posts tables, HPOS tables and the analytics lookup table.
	 *
	 * @param array $map Prefix map.
	 */
	private static function step_orders( $map ) {
		global $wpdb;
		$old_status = $map['status_old'];
		$new_status = $map['status_new'];

		$n = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$wpdb->posts} SET post_status = %s WHERE post_status = %s AND post_type IN ('shop_order','shop_order_placehold')",
				$new_status,
				$old_status
			)
		);
		/* translators: %d: number of orders */
		self::log( 'info', sprintf( __( 'Orders status (posts table): %d.', 'mim-dollar-toolkit' ), (int) $n ) );

		$n = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$wpdb->postmeta} SET meta_value = %s WHERE meta_key = '_payment_method' AND meta_value = %s",
				$map['method_new'],
				$map['method_old']
			)
		);
		/* translators: %d: number of orders */
		self::log( 'info', sprintf( __( 'Payment method (posts table): %d.', 'mim-dollar-toolkit' ), (int) $n ) );

		$hpos = $wpdb->prefix . 'wc_orders';
		if ( self::table_exists( $hpos ) ) {
			$n = $wpdb->query( $wpdb->prepare( "UPDATE `{$hpos}` SET status = %s WHERE status = %s", $new_status, $old_status ) );
			/* translators: %d: number of orders */
			self::log( 'info', sprintf( __( 'Orders status (HPOS): %d.', 'mim-dollar-toolkit' ), (int) $n ) );
			$n = $wpdb->query( $wpdb->prepare( "UPDATE `{$hpos}` SET payment_method = %s WHERE payment_method = %s", $map['method_new'], $map['method_old'] ) );
			/* translators: %d: number of orders */
			self::log( 'info', sprintf( __( 'Payment method (HPOS): %d.', 'mim-dollar-toolkit' ), (int) $n ) );
		}

		$stats = $wpdb->prefix . 'wc_order_stats';
		if ( self::table_exists( $stats ) ) {
			$n = $wpdb->query( $wpdb->prepare( "UPDATE `{$stats}` SET status = %s WHERE status = %s", $new_status, $old_status ) );
			/* translators: %d: number of rows */
			self::log( 'info', sprintf( __( 'Order analytics status: %d.', 'mim-dollar-toolkit' ), (int) $n ) );
		}
	}

	/**
	 * Remove old WP-Cron events and pending Action Scheduler actions of one generation.
	 *
	 * @param array $map Prefix map.
	 */
	private static function step_schedules_old( $map ) {
		$cleared = self::clear_old_cron( array( $map['old'] ) );

		$as = 0;
		if ( function_exists( 'as_unschedule_all_actions' ) && did_action( 'init' ) ) {
			foreach ( $map['as_hooks'] as $hook ) {
				as_unschedule_all_actions( $hook );
				++$as;
			}
			foreach ( $map['as_groups'] as $group ) {
				as_unschedule_all_actions( '', array(), $group );
			}
		}
		/* translators: 1: WP-Cron events removed, 2: Action Scheduler hooks cleaned */
		self::log( 'info', sprintf( __( 'Schedules: %1$d legacy cron events removed, %2$d Action Scheduler hooks cleaned.', 'mim-dollar-toolkit' ), $cleared, $as ) );
	}

	/**
	 * New schedules are created by the module activate() methods (enabled modules only).
	 */
	private static function step_schedules_new() {
		if ( class_exists( 'MDT_Toolkit' ) && class_exists( 'WooCommerce' ) ) {
			foreach ( MDT_Toolkit::enabled_modules() as $module ) {
				$module->activate();
			}
			self::log( 'info', __( 'New schedules created.', 'mim-dollar-toolkit' ) );
		}
	}

	/**
	 * Unschedule every WP-Cron hook starting with an old prefix.
	 *
	 * @param string[]|null $prefixes Old prefixes without underscore; null = all known generations.
	 * @return int Events removed.
	 */
	public static function clear_old_cron( $prefixes = null ) {
		if ( ! is_array( $prefixes ) ) {
			$prefixes = array_keys( self::generations() );
		}
		$crons = _get_cron_array();
		$count = 0;
		if ( ! is_array( $crons ) ) {
			return 0;
		}
		foreach ( $crons as $events ) {
			foreach ( (array) $events as $hook => $instances ) {
				foreach ( $prefixes as $old ) {
					if ( 0 !== strpos( (string) $hook, $old . '_' ) ) {
						continue;
					}
					$count += count( (array) $instances );
					wp_clear_scheduled_hook( $hook );
					// Events stored with args are not matched by an empty-args clear; remove each explicitly.
					foreach ( (array) $instances as $instance ) {
						if ( ! empty( $instance['args'] ) ) {
							wp_clear_scheduled_hook( $hook, $instance['args'] );
						}
					}
					break;
				}
			}
		}
		return $count;
	}

	/**
	 * Delete old transients and locks (they are cheap to rebuild).
	 *
	 * @param array $map Prefix map.
	 */
	private static function step_transients( $map ) {
		global $wpdb;
		$n = 0;
		foreach ( $map['transient_like'] as $prefix ) {
			$n += (int) $wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( $prefix ) . '%' ) );
		}
		/* translators: %d: number of option rows */
		self::log( 'info', sprintf( __( 'Legacy transients deleted: %d rows.', 'mim-dollar-toolkit' ), $n ) );
	}

	private static function step_caches() {
		if ( function_exists( 'wc_delete_product_transients' ) ) {
			wc_delete_product_transients( 0 );
		}
		if ( class_exists( 'WC_Cache_Helper' ) ) {
			WC_Cache_Helper::invalidate_cache_group( 'orders' );
		}
		wp_cache_flush();
	}

	/* ------------------------------------------------------------ helpers */

	private static function table_exists( $table ) {
		global $wpdb;
		return $table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );
	}

	/* ------------------------------------------------------------ legacy cleanup (admin button) */

	/**
	 * Are there old options (any generation) left to remove? Only offered once the migration is done.
	 *
	 * @return bool
	 */
	public static function legacy_options_remain() {
		global $wpdb;
		if ( get_option( self::OPTION_VERSION ) !== self::VERSION ) {
			return false;
		}
		foreach ( array_keys( self::generations() ) as $old ) {
			$map = self::prefix_map( $old );
			if ( $wpdb->get_var(
				$wpdb->prepare(
					"SELECT option_id FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s LIMIT 1",
					$wpdb->esc_like( $map['option_prefix'][0] ) . '%',
					$wpdb->esc_like( $map['option_prefix'][1] ) . '%'
				)
			) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Delete the old options of every generation (the data was copied to mdt_*). Meta was moved, not copied.
	 *
	 * @return int Options deleted.
	 */
	public static function remove_legacy_options() {
		global $wpdb;
		if ( get_option( self::OPTION_VERSION ) !== self::VERSION ) {
			return 0;
		}
		$n = 0;
		foreach ( array_keys( self::generations() ) as $old ) {
			$map = self::prefix_map( $old );
			$n  += (int) $wpdb->query(
				$wpdb->prepare(
					"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
					$wpdb->esc_like( $map['option_prefix'][0] ) . '%',
					$wpdb->esc_like( $map['option_prefix'][1] ) . '%'
				)
			);
		}
		wp_cache_delete( 'alloptions', 'options' );
		wp_cache_delete( 'notoptions', 'options' );
		/* translators: %d: number of options */
		self::log( 'info', sprintf( __( 'Legacy options removed: %d.', 'mim-dollar-toolkit' ), $n ) );
		return $n;
	}

	/* ------------------------------------------------------------ notice */

	/**
	 * One-time summary notice after a migration.
	 */
	public static function admin_notice() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		$data = get_option( self::OPTION_NOTICE );
		if ( ! is_array( $data ) ) {
			return;
		}
		delete_option( self::OPTION_NOTICE );

		echo '<div class="notice notice-success is-dismissible"><p><strong>' . esc_html__( 'MiM Dollar Toolkit: data migrated from the old plugin.', 'mim-dollar-toolkit' ) . '</strong></p>';
		if ( ! empty( $data['replaced'] ) ) {
			echo '<p>' . esc_html__( 'The old plugin was deactivated because this plugin replaces it. You can delete it now.', 'mim-dollar-toolkit' ) . '</p>';
		}
		if ( ! empty( $data['lines'] ) && is_array( $data['lines'] ) ) {
			echo '<ul style="list-style:disc;margin-inline-start:1.5em">';
			foreach ( $data['lines'] as $line ) {
				echo '<li>' . esc_html( (string) $line ) . '</li>';
			}
			echo '</ul>';
		}
		echo '</div>';
	}
}
