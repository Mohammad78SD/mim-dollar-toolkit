<?php
/**
 * Customer-facing pieces: printable pre-invoice page, thank-you page, My Account links, cart/checkout texts.
 *
 * @package WooTomanToolkit
 */

defined( 'ABSPATH' ) || exit;

class WTT_PI_Page {

	const QUERY_VAR = 'wtt-pi';

	public static function init() {
		add_action( 'template_redirect', array( __CLASS__, 'maybe_render' ), 1 );
		add_action( 'woocommerce_thankyou_' . WTT_PI_Settings::GATEWAY_ID, array( __CLASS__, 'thankyou' ) );
		add_filter( 'woocommerce_thankyou_order_received_text', array( __CLASS__, 'received_text' ), 10, 2 );
		add_filter( 'woocommerce_my_account_my_orders_actions', array( __CLASS__, 'account_actions' ), 10, 2 );
		add_action( 'woocommerce_order_details_after_order_table', array( __CLASS__, 'view_order_button' ) );
		add_filter( 'woocommerce_available_payment_gateways', array( __CLASS__, 'only_gateway' ) );
		add_filter( 'gettext', array( __CLASS__, 'cart_button_text' ), 20, 3 );
		add_filter( 'render_block_data', array( __CLASS__, 'cart_block_data' ), 10, 2 );
		add_action( 'woocommerce_checkout_before_order_review_heading', array( __CLASS__, 'checkout_notice' ) );
	}

	/**
	 * Public URL of an order's pre-invoice.
	 *
	 * @param WC_Order $order Order.
	 * @return string
	 */
	public static function url( $order ) {
		return add_query_arg(
			array(
				self::QUERY_VAR => $order->get_id(),
				'key'           => $order->get_order_key(),
			),
			home_url( '/' )
		);
	}

	/* ------------------------------------------------------------ printable page */

	public static function maybe_render() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- capability URL secured by the order key.
		if ( ! isset( $_GET[ self::QUERY_VAR ] ) ) {
			return;
		}
		$order_id = absint( wp_unslash( $_GET[ self::QUERY_VAR ] ) );
		$key      = isset( $_GET['key'] ) ? sanitize_text_field( wp_unslash( $_GET['key'] ) ) : '';
		// phpcs:enable

		$order = $order_id ? wc_get_order( $order_id ) : false;
		if ( ! $order instanceof WC_Order || '' === (string) $order->get_meta( '_wtt_pi_number' ) ) {
			self::deny();
		}

		$allowed = ( '' !== $key && hash_equals( (string) $order->get_order_key(), $key ) )
			|| ( is_user_logged_in() && $order->get_customer_id() && (int) $order->get_customer_id() === get_current_user_id() )
			|| current_user_can( 'edit_shop_orders' );
		if ( ! $allowed ) {
			self::deny();
		}

		nocache_headers();
		header( 'X-Robots-Tag: noindex, nofollow' );
		header( 'Content-Type: text/html; charset=' . get_option( 'blog_charset' ) );

		$template = locate_template( array( 'woo-toman-toolkit/pre-invoice.php' ) );
		if ( ! $template ) {
			$template = WTT_TK_DIR . 'modules/pre-invoice/templates/pre-invoice.php';
		}
		$data = self::build_data( $order );
		include $template;
		exit;
	}

	private static function deny() {
		status_header( 404 );
		nocache_headers();
		wp_die( esc_html__( 'Pre-invoice not found.', 'woo-toman-toolkit' ), '', array( 'response' => 404 ) );
	}

	/**
	 * Data for the printable template.
	 *
	 * @param WC_Order $order Order.
	 * @return array
	 */
	public static function build_data( $order ) {
		$settings = WTT_PI_Settings::all();
		$fa       = wtt_is_fa_locale();
		$status   = $order->get_status();
		$badge    = '';
		if ( 'cancelled' === $status ) {
			$badge = 'cancelled';
		} elseif ( in_array( $status, array( 'processing', 'completed' ), true ) ) {
			$badge = 'paid';
		}
		$logo = $settings['logo_id'] ? wp_get_attachment_image_url( (int) $settings['logo_id'], 'medium' ) : '';
		$sign = $settings['signature_id'] ? wp_get_attachment_image_url( (int) $settings['signature_id'], 'medium' ) : '';

		$items = array();
		foreach ( $order->get_items() as $item ) {
			$product = $item->get_product();
			$img     = '';
			if ( ! empty( $settings['show_images'] ) && $product && $product->get_image_id() ) {
				$img = (string) wp_get_attachment_image_url( $product->get_image_id(), 'thumbnail' );
			}
			$meta = array();
			foreach ( $item->get_formatted_meta_data() as $m ) {
				$meta[] = wp_strip_all_tags( $m->display_key ) . ': ' . wp_strip_all_tags( $m->display_value );
			}
			$qty     = (float) $item->get_quantity();
			$items[] = array(
				'name'  => $item->get_name(),
				'meta'  => $meta,
				'sku'   => $product ? $product->get_sku() : '',
				'image' => $img,
				'qty'   => ( floor( $qty ) === $qty ) ? (string) (int) $qty : wc_format_decimal( $qty, '' ),
				'unit'  => $qty ? $order->get_item_subtotal( $item, false, true ) : 0,
				'total' => (float) $order->get_line_subtotal( $item, false, true ),
			);
		}

		$totals = array(
			array( __( 'Subtotal', 'woo-toman-toolkit' ), (float) $order->get_subtotal() ),
		);
		if ( (float) $order->get_total_discount() > 0 ) {
			$totals[] = array( __( 'Discount', 'woo-toman-toolkit' ), -1 * (float) $order->get_total_discount() );
		}
		if ( (float) $order->get_shipping_total() > 0 ) {
			$totals[] = array( __( 'Shipping', 'woo-toman-toolkit' ), (float) $order->get_shipping_total() );
		}
		foreach ( $order->get_fees() as $fee ) {
			$totals[] = array( $fee->get_name(), (float) $fee->get_total() );
		}
		if ( (float) $order->get_total_tax() > 0 ) {
			$totals[] = array( __( 'Tax', 'woo-toman-toolkit' ), (float) $order->get_total_tax() );
		}

		return array(
			'settings'    => $settings,
			'fa'          => $fa,
			'rtl'         => $fa || is_rtl(),
			'lang'        => $fa ? 'fa' : str_replace( '_', '-', get_locale() ),
			'number'      => (string) $order->get_meta( '_wtt_pi_number' ),
			'issued'      => WTT_PI_Format::date( (int) $order->get_meta( '_wtt_pi_issued_at' ) ),
			'reserved'    => WTT_PI_Settings::reserved_sentence( $order ),
			'valid_until' => WTT_PI_Format::datetime( (int) $order->get_meta( '_wtt_pi_valid_until' ) ),
			'badge'       => $badge,
			'logo'        => (string) $logo,
			'signature'   => (string) $sign,
			'items'       => $items,
			'totals'      => $totals,
			'words'       => WTT_PI_Format::total_in_words( $order ),
			'footer'      => $settings['footer_note'] ? WTT_PI_Settings::replace( $settings['footer_note'], $order ) : '',
			'disclaimer'  => $settings['disclaimer'] ? WTT_PI_Settings::replace( $settings['disclaimer'], $order ) : '',
			'shipping_differs' => $order->has_shipping_address() && $order->get_formatted_shipping_address() !== $order->get_formatted_billing_address(),
		);
	}

	/* --------------------------------------------------------------- thank you */

	/**
	 * "You can view this pre-invoice anytime in your account" sentence (only when the order belongs to an account).
	 *
	 * @param WC_Order $order Order.
	 * @return string Plain text with %s replaced by the My Account URL ('' when not applicable).
	 */
	public static function account_sentence( $order, $link = true ) {
		if ( ! $order instanceof WC_Order || ! $order->get_customer_id() || ! function_exists( 'wc_get_page_permalink' ) ) {
			return '';
		}
		$url = wc_get_page_permalink( 'myaccount' );
		if ( ! $url ) {
			return '';
		}
		if ( $link ) {
			/* translators: %s: link to the My Account page */
			return sprintf( esc_html__( 'You can view this pre-invoice anytime in your account: %s', 'woo-toman-toolkit' ), '<a href="' . esc_url( $url ) . '">' . esc_html__( 'My account', 'woo-toman-toolkit' ) . '</a>' );
		}
		/* translators: %s: My Account URL */
		return sprintf( __( 'You can view this pre-invoice anytime in your account: %s', 'woo-toman-toolkit' ), esc_url_raw( $url ) );
	}

	public static function thankyou( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order || '' === (string) $order->get_meta( '_wtt_pi_number' ) ) {
			return;
		}
		echo '<div class="woocommerce-message wtt-pi-thankyou" style="margin:1em 0;padding:1em 1.2em;border:1px solid #ddd;border-radius:4px">';
		echo '<p>' . wp_kses_post( WTT_PI_Settings::replace( WTT_PI_Settings::get( 'thankyou_message' ), $order ) ) . '</p>';
		$reserved = WTT_PI_Settings::reserved_sentence( $order );
		if ( $reserved ) {
			echo '<p>' . esc_html( $reserved ) . '</p>';
		}
		$account = self::account_sentence( $order );
		if ( '' !== $account ) {
			echo '<p>' . wp_kses( $account, array( 'a' => array( 'href' => array() ) ) ) . '</p>';
		}
		echo '<p><a class="button" href="' . esc_url( self::url( $order ) ) . '" target="_blank" rel="noopener">' . esc_html__( 'View & print pre-invoice', 'woo-toman-toolkit' ) . '</a></p>';
		echo '</div>';
	}

	public static function received_text( $text, $order ) {
		if ( $order instanceof WC_Order && WTT_PI_Settings::GATEWAY_ID === $order->get_payment_method() ) {
			return esc_html__( 'Pre-invoice request received.', 'woo-toman-toolkit' );
		}
		return $text;
	}

	/* -------------------------------------------------------------- my account */

	public static function account_actions( $actions, $order ) {
		if ( $order instanceof WC_Order && '' !== (string) $order->get_meta( '_wtt_pi_number' ) ) {
			$actions['wtt-preinvoice'] = array(
				'url'  => self::url( $order ),
				'name' => __( 'Pre-invoice', 'woo-toman-toolkit' ),
			);
		}
		return $actions;
	}

	public static function view_order_button( $order ) {
		if ( ! function_exists( 'is_wc_endpoint_url' ) || ! is_wc_endpoint_url( 'view-order' ) ) {
			return;
		}
		if ( $order instanceof WC_Order && '' !== (string) $order->get_meta( '_wtt_pi_number' ) ) {
			echo '<p><a class="button" href="' . esc_url( self::url( $order ) ) . '" target="_blank" rel="noopener">' . esc_html__( 'View & print pre-invoice', 'woo-toman-toolkit' ) . '</a></p>';
		}
	}

	/* ------------------------------------------------------- gateway / texts */

	public static function only_gateway( $gateways ) {
		if ( is_admin() && ! wp_doing_ajax() ) {
			return $gateways;
		}
		if ( WTT_PI_Settings::get( 'only_gateway' ) && isset( $gateways[ WTT_PI_Settings::GATEWAY_ID ] ) ) {
			return array( WTT_PI_Settings::GATEWAY_ID => $gateways[ WTT_PI_Settings::GATEWAY_ID ] );
		}
		return $gateways;
	}

	public static function cart_button_text( $translation, $text, $domain ) {
		if ( 'woocommerce' === $domain && 'Proceed to checkout' === $text && ! is_admin() ) {
			$custom = (string) WTT_PI_Settings::get( 'cart_button_text' );
			if ( '' !== trim( $custom ) ) {
				return $custom;
			}
		}
		return $translation;
	}

	/**
	 * The cart block renders its label client-side from the buttonLabel attribute.
	 */
	public static function cart_block_data( $parsed_block, $source_block = array() ) {
		if ( is_array( $parsed_block ) && isset( $parsed_block['blockName'] ) && 'woocommerce/proceed-to-checkout-block' === $parsed_block['blockName'] ) {
			$custom = (string) WTT_PI_Settings::get( 'cart_button_text' );
			if ( '' !== trim( $custom ) ) {
				if ( ! isset( $parsed_block['attrs'] ) || ! is_array( $parsed_block['attrs'] ) ) {
					$parsed_block['attrs'] = array();
				}
				$parsed_block['attrs']['buttonLabel'] = $custom;
			}
		}
		return $parsed_block;
	}

	public static function checkout_notice() {
		$notice = (string) WTT_PI_Settings::get( 'checkout_notice' );
		if ( '' !== trim( $notice ) ) {
			echo '<div class="woocommerce-info wtt-pi-checkout-notice">' . wp_kses_post( WTT_PI_Settings::replace( $notice ) ) . '</div>';
		}
	}
}
