<?php
/**
 * USD price fields on the product edit screen, variations and the products list.
 *
 * @package WooTomanToolkit
 */

defined( 'ABSPATH' ) || exit;

class WTT_USD_Product_Fields {

	const META_REGULAR = '_wtt_usd_regular_price';
	const META_SALE    = '_wtt_usd_sale_price';

	public static function init() {
		add_action( 'woocommerce_product_options_pricing', array( __CLASS__, 'render_simple_fields' ) );
		// Priority 20: run after WooCommerce core has applied the posted Toman fields so our values win.
		add_action( 'woocommerce_admin_process_product_object', array( __CLASS__, 'save_simple' ), 20, 1 );

		add_action( 'woocommerce_variation_options_pricing', array( __CLASS__, 'render_variation_fields' ), 10, 3 );
		add_action( 'woocommerce_admin_process_variation_object', array( __CLASS__, 'save_variation' ), 20, 2 );

		add_filter( 'manage_edit-product_columns', array( __CLASS__, 'add_column' ), 20 );
		add_action( 'manage_product_posts_custom_column', array( __CLASS__, 'render_column' ), 10, 2 );

		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
	}

	/**
	 * Enqueue assets on product screens.
	 *
	 * @param string $hook Hook suffix.
	 */
	public static function enqueue( $hook ) {
		$screen = get_current_screen();
		if ( ! $screen || ! in_array( $screen->id, array( 'product', 'edit-product' ), true ) ) {
			return;
		}
		wp_enqueue_style( 'wtt-usd-admin', WTT_USD_URL . 'assets/admin.css', array(), WTT_USD_VERSION );
		if ( 'product' !== $screen->id ) {
			return;
		}
		wp_enqueue_script( 'wtt-usd-admin', WTT_USD_URL . 'assets/admin.js', array( 'jquery' ), WTT_USD_VERSION, true );
		$rate = WTT_USD_Rate_Manager::get_effective_rate();
		wp_localize_script(
			'wtt-usd-admin',
			'wttUsd',
			array(
				'rate'          => $rate,
				'rateFormatted' => $rate > 0 ? number_format_i18n( $rate, 0 ) : '',
				/* translators: 1: USD price, 2: exchange rate (Toman per USD) */
				'hint'          => __( 'Auto-calculated from USD ($%1$s × rate %2$s)', 'woo-toman-toolkit' ),
				'noRate'        => __( 'No exchange rate is available yet; Toman prices will be calculated after the first rate fetch.', 'woo-toman-toolkit' ),
			)
		);
	}

	/**
	 * Display a stored USD value in the user's locale.
	 *
	 * @param mixed $value Stored value.
	 * @return string
	 */
	private static function display_value( $value ) {
		return '' === (string) $value ? '' : wc_format_localized_price( $value );
	}

	public static function render_simple_fields() {
		global $post;
		$post_id = $post ? (int) $post->ID : 0;

		echo '<div class="wtt-usd-fields" data-wtt-usd-scope="simple">';
		woocommerce_wp_text_input(
			array(
				'id'        => self::META_REGULAR,
				'label'     => __( 'Regular price (USD)', 'woo-toman-toolkit' ),
				'class'     => 'short wc_input_price wtt-usd-regular',
				'data_type' => 'price',
				'value'     => self::display_value( $post_id ? get_post_meta( $post_id, self::META_REGULAR, true ) : '' ),
			)
		);
		woocommerce_wp_text_input(
			array(
				'id'        => self::META_SALE,
				'label'     => __( 'Sale price (USD)', 'woo-toman-toolkit' ),
				'class'     => 'short wc_input_price wtt-usd-sale',
				'data_type' => 'price',
				'value'     => self::display_value( $post_id ? get_post_meta( $post_id, self::META_SALE, true ) : '' ),
			)
		);
		echo '<p class="form-field wtt-usd-hint-wrap"><span class="wtt-usd-hint description"></span></p>';
		echo '</div>';
	}

	/**
	 * @param int     $loop           Variation loop index.
	 * @param array   $variation_data Variation data.
	 * @param WP_Post $variation      Variation post.
	 */
	public static function render_variation_fields( $loop, $variation_data, $variation ) {
		$id = (int) $variation->ID;

		echo '<div class="wtt-usd-fields" data-wtt-usd-scope="variation">';
		woocommerce_wp_text_input(
			array(
				'id'            => self::META_REGULAR . $loop,
				'name'          => self::META_REGULAR . '[' . $loop . ']',
				'label'         => __( 'Regular price (USD)', 'woo-toman-toolkit' ),
				'class'         => 'wc_input_price wtt-usd-regular',
				'data_type'     => 'price',
				'wrapper_class' => 'form-row form-row-first',
				'value'         => self::display_value( get_post_meta( $id, self::META_REGULAR, true ) ),
			)
		);
		woocommerce_wp_text_input(
			array(
				'id'            => self::META_SALE . $loop,
				'name'          => self::META_SALE . '[' . $loop . ']',
				'label'         => __( 'Sale price (USD)', 'woo-toman-toolkit' ),
				'class'         => 'wc_input_price wtt-usd-sale',
				'data_type'     => 'price',
				'wrapper_class' => 'form-row form-row-last',
				'value'         => self::display_value( get_post_meta( $id, self::META_SALE, true ) ),
			)
		);
		echo '<p class="form-row form-row-full wtt-usd-hint-wrap"><span class="wtt-usd-hint description"></span></p>';
		echo '</div>';
	}

	/**
	 * Parse a posted decimal.
	 *
	 * @param mixed $raw Raw posted value.
	 * @return string|null '' when empty, null when invalid, else a dot-decimal string.
	 */
	private static function parse_decimal( $raw ) {
		if ( ! is_scalar( $raw ) ) {
			return '';
		}
		$raw = trim( (string) $raw );
		if ( '' === $raw ) {
			return '';
		}
		$value = wc_format_decimal( $raw, false, true );
		if ( '' === $value || ! is_numeric( $value ) || (float) $value < 0 ) {
			return null;
		}
		return $value;
	}

	private static function add_error( $message ) {
		if ( class_exists( 'WC_Admin_Meta_Boxes' ) ) {
			WC_Admin_Meta_Boxes::add_error( $message );
		}
	}

	/**
	 * Validate posted USD values, store them as meta on the object and compute Toman prices.
	 *
	 * @param WC_Product $product  Product or variation (unsaved).
	 * @param mixed      $raw_reg  Posted regular.
	 * @param mixed      $raw_sale Posted sale.
	 * @param string     $label    Label for notices.
	 */
	private static function apply_posted( $product, $raw_reg, $raw_sale, $label ) {
		$reg  = self::parse_decimal( $raw_reg );
		$sale = self::parse_decimal( $raw_sale );

		if ( null === $reg ) {
			/* translators: %s: product label */
			self::add_error( sprintf( __( '%s: the USD regular price must be a non-negative number; it was ignored.', 'woo-toman-toolkit' ), $label ) );
			return;
		}

		if ( '' === $reg || (float) $reg <= 0 ) {
			// Revert to manual Toman pricing; leave current Toman values as they are.
			$product->delete_meta_data( self::META_REGULAR );
			$product->delete_meta_data( self::META_SALE );
			return;
		}

		if ( null === $sale ) {
			/* translators: %s: product label */
			self::add_error( sprintf( __( '%s: the USD sale price must be a non-negative number; it was ignored.', 'woo-toman-toolkit' ), $label ) );
			$sale = '';
		} elseif ( '' !== $sale && (float) $sale >= (float) $reg ) {
			/* translators: %s: product label */
			self::add_error( sprintf( __( '%s: the USD sale price must be lower than the regular price; it was ignored.', 'woo-toman-toolkit' ), $label ) );
			$sale = '';
		} elseif ( '' !== $sale && (float) $sale <= 0 ) {
			$sale = '';
		}

		$product->update_meta_data( self::META_REGULAR, $reg );
		if ( '' === $sale ) {
			$product->delete_meta_data( self::META_SALE );
		} else {
			$product->update_meta_data( self::META_SALE, $sale );
		}

		$rate = WTT_USD_Rate_Manager::get_effective_rate();
		if ( $rate > 0 ) {
			// Sets props on the object; WooCommerce saves it right after this hook and decides _price (sale schedule aware).
			WTT_USD_Price_Sync::apply_to_product( $product, $rate );
		}
	}

	/**
	 * @param WC_Product $product Product being saved.
	 */
	public static function save_simple( $product ) {
		if ( WTT_USD_Price_Sync::is_syncing() || ! $product->is_type( 'simple' ) ) {
			return;
		}
		// WooCommerce's own meta-box nonce is verified before this hook; verify again defensively.
		$nonce = isset( $_POST['woocommerce_meta_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['woocommerce_meta_nonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, 'woocommerce_save_data' ) ) {
			return;
		}
		if ( ! current_user_can( 'edit_post', $product->get_id() ) ) {
			return;
		}
		if ( ! isset( $_POST[ self::META_REGULAR ] ) ) {
			return;
		}
		$reg  = sanitize_text_field( wp_unslash( $_POST[ self::META_REGULAR ] ) );
		$sale = isset( $_POST[ self::META_SALE ] ) ? sanitize_text_field( wp_unslash( $_POST[ self::META_SALE ] ) ) : '';

		self::apply_posted( $product, $reg, $sale, $product->get_name() );
	}

	/**
	 * @param WC_Product_Variation $variation Variation being saved.
	 * @param int                  $i         Loop index.
	 */
	public static function save_variation( $variation, $i ) {
		if ( WTT_USD_Price_Sync::is_syncing() ) {
			return;
		}
		// Variation saving runs in WooCommerce's nonce-checked AJAX/meta-box flow; capability is checked here.
		if ( ! current_user_can( 'edit_post', $variation->get_id() ) ) {
			return;
		}
		$i = absint( $i );
		if ( ! isset( $_POST[ self::META_REGULAR ] ) || ! is_array( $_POST[ self::META_REGULAR ] ) || ! isset( $_POST[ self::META_REGULAR ][ $i ] ) ) {
			return;
		}
		$reg  = sanitize_text_field( wp_unslash( $_POST[ self::META_REGULAR ][ $i ] ) );
		$sale = '';
		if ( isset( $_POST[ self::META_SALE ] ) && is_array( $_POST[ self::META_SALE ] ) && isset( $_POST[ self::META_SALE ][ $i ] ) ) {
			$sale = sanitize_text_field( wp_unslash( $_POST[ self::META_SALE ][ $i ] ) );
		}
		self::apply_posted( $variation, $reg, $sale, '#' . $variation->get_id() );
	}

	/**
	 * Add a USD column after the price column.
	 *
	 * @param array $columns Columns.
	 * @return array
	 */
	public static function add_column( $columns ) {
		$out = array();
		foreach ( $columns as $key => $label ) {
			$out[ $key ] = $label;
			if ( 'price' === $key ) {
				$out['wtt_usd'] = __( 'USD', 'woo-toman-toolkit' );
			}
		}
		if ( ! isset( $out['wtt_usd'] ) ) {
			$out['wtt_usd'] = __( 'USD', 'woo-toman-toolkit' );
		}
		return $out;
	}

	private static function format_usd( $amount ) {
		return wc_price(
			(float) $amount,
			array(
				'currency'   => 'USD',
				'decimals'   => 2,
				'trim_zeros' => true,
			)
		);
	}

	/**
	 * @param string $column  Column key.
	 * @param int    $post_id Post ID.
	 */
	public static function render_column( $column, $post_id ) {
		if ( 'wtt_usd' !== $column ) {
			return;
		}
		$product = wc_get_product( $post_id );
		if ( ! $product ) {
			return;
		}

		if ( $product->is_type( 'variable' ) ) {
			$prices = array();
			foreach ( $product->get_children() as $child_id ) {
				$value = get_post_meta( $child_id, self::META_REGULAR, true );
				if ( '' !== (string) $value && (float) $value > 0 ) {
					$prices[] = (float) $value;
				}
			}
			if ( empty( $prices ) ) {
				echo '<span class="na">&ndash;</span>';
				return;
			}
			$min = min( $prices );
			$max = max( $prices );
			echo wp_kses_post( $min === $max ? self::format_usd( $min ) : self::format_usd( $min ) . ' &ndash; ' . self::format_usd( $max ) );
			return;
		}

		$regular = get_post_meta( $post_id, self::META_REGULAR, true );
		if ( '' === (string) $regular || (float) $regular <= 0 ) {
			echo '<span class="na">&ndash;</span>';
			return;
		}
		$sale = get_post_meta( $post_id, self::META_SALE, true );
		if ( '' !== (string) $sale && (float) $sale > 0 && (float) $sale < (float) $regular ) {
			echo wp_kses_post( '<del>' . self::format_usd( $regular ) . '</del> <ins>' . self::format_usd( $sale ) . '</ins>' );
		} else {
			echo wp_kses_post( self::format_usd( $regular ) );
		}
	}
}
