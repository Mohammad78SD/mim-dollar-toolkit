<?php
/**
 * One-time data migration from the earlier internal toolkit plugin (azk_ / AZK_ prefix).
 *
 * This file intentionally references the OLD names. It copies options, renames meta keys, order statuses and
 * payment methods, drops old schedules, and deactivates the old plugin. It is idempotent and guarded by the
 * `wtt_migration_version` option plus a short-lived add_option() lock.
 *
 * @package WooTomanToolkit
 */

defined( 'ABSPATH' ) || exit;

class WTT_Migration {

	/** Bump when a new migration step is added (steps must stay idempotent). */
	const VERSION = '1';

	const OPTION_VERSION = 'wtt_migration_version';
	const OPTION_LOCK    = 'wtt_migration_lock';
	const OPTION_NOTICE  = 'wtt_migration_notice';
	const LOCK_TTL       = 300;

	/** Marker the old plugin's main file defines; used to recognise it without hard-coding its slug. */
	const OLD_MARKER = 'AZK_TK_FILE';

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
	 * Is there anything from the old plugin on this site?
	 *
	 * @return bool
	 */
	private static function legacy_detected() {
		global $wpdb;
		if ( self::old_plugin_active() ) {
			return true;
		}
		$found = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s LIMIT 1",
				$wpdb->esc_like( 'azk_' ) . '%',
				$wpdb->esc_like( 'woocommerce_azk_' ) . '%'
			)
		);
		if ( $found ) {
			return true;
		}
		if ( $wpdb->get_var( $wpdb->prepare( "SELECT meta_id FROM {$wpdb->usermeta} WHERE meta_key LIKE %s LIMIT 1", $wpdb->esc_like( 'azk_' ) . '%' ) ) ) {
			return true;
		}
		return (bool) $wpdb->get_var( $wpdb->prepare( "SELECT meta_id FROM {$wpdb->postmeta} WHERE meta_key LIKE %s LIMIT 1", $wpdb->esc_like( '_azk_' ) . '%' ) );
	}

	private static function old_plugin_active() {
		return '' !== self::find_old_plugin();
	}

	/**
	 * Basename (dir/file.php) of the active old plugin, or '' when it is not active.
	 * Uses the marker constant when the old plugin is already loaded, otherwise scans active plugin headers.
	 *
	 * @return string
	 */
	private static function find_old_plugin() {
		$active = (array) get_option( 'active_plugins', array() );
		if ( is_multisite() ) {
			$active = array_merge( $active, array_keys( (array) get_site_option( 'active_sitewide_plugins', array() ) ) );
		}
		if ( defined( self::OLD_MARKER ) ) {
			$basename = plugin_basename( constant( self::OLD_MARKER ) );
			if ( in_array( $basename, $active, true ) ) {
				return $basename;
			}
		}
		$own = plugin_basename( WTT_TK_FILE );
		foreach ( $active as $plugin ) {
			$plugin = (string) $plugin;
			if ( $plugin === $own || '.php' !== substr( $plugin, -4 ) ) {
				continue;
			}
			$path = WP_PLUGIN_DIR . '/' . $plugin;
			if ( ! is_readable( $path ) ) {
				continue;
			}
			$head = file_get_contents( $path, false, null, 0, 8192 ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			if ( false !== $head && 1 === preg_match( '/define\s*\(\s*[\'"]' . preg_quote( self::OLD_MARKER, '/' ) . '[\'"]/', $head ) ) {
				return $plugin;
			}
		}
		return '';
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
			$replaced = self::deactivate_old_plugin();

			self::step_options();
			self::step_gateway_order();
			self::step_meta();
			self::step_orders();
			self::step_schedules();
			self::step_transients();
			self::step_caches();

			update_option( self::OPTION_VERSION, self::VERSION );
			self::log( 'info', __( 'Migration from the legacy plugin finished.', 'woo-toman-toolkit' ) );
			update_option(
				self::OPTION_NOTICE,
				array(
					'replaced' => $replaced,
					'lines'    => self::$report,
				),
				false
			);
			if ( $replaced ) {
				// The old plugin stays loaded for the rest of this request and may re-schedule its cron on init:20.
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
		if ( class_exists( 'WTT_Logger' ) ) {
			WTT_Logger::add( 'toolkit', $level, $message );
		}
	}

	/* ------------------------------------------------------------ steps */

	/**
	 * Deactivate the old plugin silently (no deactivation hooks) before touching data.
	 *
	 * @return bool Whether the old plugin was active.
	 */
	private static function deactivate_old_plugin() {
		$old = self::find_old_plugin();
		if ( '' === $old ) {
			return false;
		}
		if ( ! function_exists( 'deactivate_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		deactivate_plugins( $old, true, is_multisite() && is_plugin_active_for_network( $old ) );
		self::log( 'info', __( 'The old plugin was deactivated; it has been replaced by this plugin and can be deleted.', 'woo-toman-toolkit' ) );
		return true;
	}

	/**
	 * Copy azk_* / woocommerce_azk_* options to wtt_* (only when the target is missing), keeping autoload.
	 */
	private static function step_options() {
		global $wpdb;
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT option_name, option_value, autoload FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
				$wpdb->esc_like( 'azk_' ) . '%',
				$wpdb->esc_like( 'woocommerce_azk_' ) . '%'
			)
		);
		$copied  = 0;
		$skipped = 0;
		foreach ( (array) $rows as $row ) {
			$target = self::map_option_name( $row->option_name );
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
		self::log( 'info', sprintf( __( 'Options: %1$d copied, %2$d skipped (already present).', 'woo-toman-toolkit' ), $copied, $skipped ) );
	}

	/**
	 * azk_foo -> wtt_foo, woocommerce_azk_foo -> woocommerce_wtt_foo.
	 *
	 * @param string $name Old option name.
	 * @return string
	 */
	public static function map_option_name( $name ) {
		if ( 0 === strpos( $name, 'woocommerce_azk_' ) ) {
			return 'woocommerce_wtt_' . substr( $name, strlen( 'woocommerce_azk_' ) );
		}
		return 'wtt_' . substr( $name, strlen( 'azk_' ) );
	}

	/**
	 * Payment gateway ordering (woocommerce_gateway_order) keyed by gateway id.
	 */
	private static function step_gateway_order() {
		$order = get_option( 'woocommerce_gateway_order', null );
		if ( ! is_array( $order ) || ! array_key_exists( 'azk_preinvoice', $order ) || array_key_exists( 'wtt_preinvoice', $order ) ) {
			return;
		}
		$new = array();
		foreach ( $order as $key => $pos ) {
			$new[ 'azk_preinvoice' === $key ? 'wtt_preinvoice' : $key ] = $pos;
		}
		update_option( 'woocommerce_gateway_order', $new );
		self::log( 'info', __( 'Payment gateway ordering updated.', 'woo-toman-toolkit' ) );
	}

	/**
	 * Rename meta keys: postmeta/HPOS order meta _azk_ -> _wtt_, usermeta azk_ -> wtt_, order item meta if any.
	 */
	private static function step_meta() {
		global $wpdb;
		$post_like = $wpdb->esc_like( '_azk_' ) . '%';
		$user_like = $wpdb->esc_like( 'azk_' ) . '%';

		$n = $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->postmeta} SET meta_key = CONCAT('_wtt_', SUBSTRING(meta_key, 6)) WHERE meta_key LIKE %s", $post_like ) );
		/* translators: %d: number of rows */
		self::log( 'info', sprintf( __( 'Post meta renamed: %d rows.', 'woo-toman-toolkit' ), (int) $n ) );

		$n = $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->usermeta} SET meta_key = CONCAT('wtt_', SUBSTRING(meta_key, 5)) WHERE meta_key LIKE %s", $user_like ) );
		/* translators: %d: number of rows */
		self::log( 'info', sprintf( __( 'User meta renamed: %d rows.', 'woo-toman-toolkit' ), (int) $n ) );

		$orders_meta = $wpdb->prefix . 'wc_orders_meta';
		if ( self::table_exists( $orders_meta ) ) {
			$n = $wpdb->query( $wpdb->prepare( "UPDATE `{$orders_meta}` SET meta_key = CONCAT('_wtt_', SUBSTRING(meta_key, 6)) WHERE meta_key LIKE %s", $post_like ) );
			/* translators: %d: number of rows */
			self::log( 'info', sprintf( __( 'HPOS order meta: %d rows.', 'woo-toman-toolkit' ), (int) $n ) );
		}

		$item_meta = $wpdb->prefix . 'woocommerce_order_itemmeta';
		if ( self::table_exists( $item_meta ) ) {
			$n = $wpdb->query( $wpdb->prepare( "UPDATE `{$item_meta}` SET meta_key = CONCAT('_wtt_', SUBSTRING(meta_key, 6)) WHERE meta_key LIKE %s", $post_like ) );
			/* translators: %d: number of rows */
			self::log( 'info', sprintf( __( 'Order item meta: %d rows.', 'woo-toman-toolkit' ), (int) $n ) );
		}
	}

	/**
	 * Order status wc-azk-preinvoice -> wc-wtt-preinvoice and payment method azk_preinvoice -> wtt_preinvoice,
	 * in the legacy posts tables, HPOS tables and the analytics lookup table.
	 */
	private static function step_orders() {
		global $wpdb;
		$old_status = 'wc-azk-preinvoice';
		$new_status = 'wc-wtt-preinvoice';

		$n = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$wpdb->posts} SET post_status = %s WHERE post_status = %s AND post_type IN ('shop_order','shop_order_placehold')",
				$new_status,
				$old_status
			)
		);
		/* translators: %d: number of orders */
		self::log( 'info', sprintf( __( 'Orders status (posts table): %d.', 'woo-toman-toolkit' ), (int) $n ) );

		$n = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$wpdb->postmeta} SET meta_value = %s WHERE meta_key = '_payment_method' AND meta_value = %s",
				'wtt_preinvoice',
				'azk_preinvoice'
			)
		);
		/* translators: %d: number of orders */
		self::log( 'info', sprintf( __( 'Payment method (posts table): %d.', 'woo-toman-toolkit' ), (int) $n ) );

		$hpos = $wpdb->prefix . 'wc_orders';
		if ( self::table_exists( $hpos ) ) {
			$n = $wpdb->query( $wpdb->prepare( "UPDATE `{$hpos}` SET status = %s WHERE status = %s", $new_status, $old_status ) );
			/* translators: %d: number of orders */
			self::log( 'info', sprintf( __( 'Orders status (HPOS): %d.', 'woo-toman-toolkit' ), (int) $n ) );
			$n = $wpdb->query( $wpdb->prepare( "UPDATE `{$hpos}` SET payment_method = %s WHERE payment_method = %s", 'wtt_preinvoice', 'azk_preinvoice' ) );
			/* translators: %d: number of orders */
			self::log( 'info', sprintf( __( 'Payment method (HPOS): %d.', 'woo-toman-toolkit' ), (int) $n ) );
		}

		$stats = $wpdb->prefix . 'wc_order_stats';
		if ( self::table_exists( $stats ) ) {
			$n = $wpdb->query( $wpdb->prepare( "UPDATE `{$stats}` SET status = %s WHERE status = %s", $new_status, $old_status ) );
			/* translators: %d: number of rows */
			self::log( 'info', sprintf( __( 'Order analytics status: %d.', 'woo-toman-toolkit' ), (int) $n ) );
		}
	}

	/**
	 * Remove old WP-Cron events and pending Action Scheduler actions, then schedule the new ones.
	 */
	private static function step_schedules() {
		$cleared = self::clear_old_cron();

		$as = 0;
		if ( function_exists( 'as_unschedule_all_actions' ) && did_action( 'init' ) ) {
			foreach ( array( 'azk_usd_sync_batch', 'azk_sms_send' ) as $hook ) {
				as_unschedule_all_actions( $hook );
				++$as;
			}
			foreach ( array( 'azk_usd', 'azk_sms' ) as $group ) {
				as_unschedule_all_actions( '', array(), $group );
			}
		}
		/* translators: 1: WP-Cron events removed, 2: Action Scheduler hooks cleaned */
		self::log( 'info', sprintf( __( 'Schedules: %1$d legacy cron events removed, %2$d Action Scheduler hooks cleaned.', 'woo-toman-toolkit' ), $cleared, $as ) );

		// New schedules are created by the module activate() methods (enabled modules only).
		if ( class_exists( 'WTT_Toolkit' ) && class_exists( 'WooCommerce' ) ) {
			foreach ( WTT_Toolkit::enabled_modules() as $module ) {
				$module->activate();
			}
			self::log( 'info', __( 'New schedules created.', 'woo-toman-toolkit' ) );
		}
	}

	/**
	 * Unschedule every azk_* WP-Cron hook.
	 *
	 * @return int Events removed.
	 */
	public static function clear_old_cron() {
		$crons = _get_cron_array();
		$count = 0;
		if ( ! is_array( $crons ) ) {
			return 0;
		}
		foreach ( $crons as $events ) {
			foreach ( (array) $events as $hook => $instances ) {
				if ( 0 === strpos( (string) $hook, 'azk_' ) ) {
					$count += count( (array) $instances );
					wp_clear_scheduled_hook( $hook );
					// Events stored with args are not matched by an empty-args clear; remove each explicitly.
					foreach ( (array) $instances as $instance ) {
						if ( ! empty( $instance['args'] ) ) {
							wp_clear_scheduled_hook( $hook, $instance['args'] );
						}
					}
				}
			}
		}
		return $count;
	}

	/**
	 * Delete azk_* transients and locks (they are cheap to rebuild).
	 */
	private static function step_transients() {
		global $wpdb;
		$n = 0;
		foreach ( array( '_transient_azk_', '_transient_timeout_azk_', '_site_transient_azk_', '_site_transient_timeout_azk_' ) as $prefix ) {
			$n += (int) $wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( $prefix ) . '%' ) );
		}
		/* translators: %d: number of option rows */
		self::log( 'info', sprintf( __( 'Legacy transients deleted: %d rows.', 'woo-toman-toolkit' ), $n ) );
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
	 * Are there old azk_* options left to remove? Only offered once the migration is done.
	 *
	 * @return bool
	 */
	public static function legacy_options_remain() {
		global $wpdb;
		if ( get_option( self::OPTION_VERSION ) !== self::VERSION ) {
			return false;
		}
		return (bool) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT option_id FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s LIMIT 1",
				$wpdb->esc_like( 'azk_' ) . '%',
				$wpdb->esc_like( 'woocommerce_azk_' ) . '%'
			)
		);
	}

	/**
	 * Delete the old azk_* options (the data was copied to wtt_*). Meta was moved, not copied.
	 *
	 * @return int Options deleted.
	 */
	public static function remove_legacy_options() {
		global $wpdb;
		if ( get_option( self::OPTION_VERSION ) !== self::VERSION ) {
			return 0;
		}
		$n = (int) $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
				$wpdb->esc_like( 'azk_' ) . '%',
				$wpdb->esc_like( 'woocommerce_azk_' ) . '%'
			)
		);
		wp_cache_delete( 'alloptions', 'options' );
		wp_cache_delete( 'notoptions', 'options' );
		/* translators: %d: number of options */
		self::log( 'info', sprintf( __( 'Legacy options removed: %d.', 'woo-toman-toolkit' ), $n ) );
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

		echo '<div class="notice notice-success is-dismissible"><p><strong>' . esc_html__( 'Toman Toolkit for WooCommerce: data migrated from the old plugin.', 'woo-toman-toolkit' ) . '</strong></p>';
		if ( ! empty( $data['replaced'] ) ) {
			echo '<p>' . esc_html__( 'The old plugin was deactivated because this plugin replaces it. You can delete it now.', 'woo-toman-toolkit' ) . '</p>';
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
