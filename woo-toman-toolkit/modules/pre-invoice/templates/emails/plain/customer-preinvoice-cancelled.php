<?php
/**
 * Customer pre-invoice cancelled email (plain).
 *
 * @package WooTomanToolkit
 * @var WC_Order $order
 */

defined( 'ABSPATH' ) || exit;

echo '= ' . esc_html( wp_strip_all_tags( $email_heading ) ) . " =\n\n";
/* translators: %s: customer first name */
echo esc_html( sprintf( __( 'Hi %s,', 'woo-toman-toolkit' ), $order->get_billing_first_name() ) ) . "\n\n";
/* translators: %s: pre-invoice number */
echo esc_html( sprintf( __( 'Your pre-invoice %s has expired or been cancelled. The reserved items have been released. Please contact us if you would like us to re-issue it.', 'woo-toman-toolkit' ), $pi_number ) ) . "\n\n";
if ( ! empty( $settings['phone'] ) ) {
	echo esc_html__( 'Phone', 'woo-toman-toolkit' ) . ': ' . esc_html( $settings['phone'] ) . "\n\n";
}
do_action( 'woocommerce_email_order_details', $order, $sent_to_admin, $plain_text, $email );
if ( $additional_content ) {
	echo esc_html( wp_strip_all_tags( wptexturize( $additional_content ) ) ) . "\n\n";
}
echo wp_kses_post( apply_filters( 'woocommerce_email_footer_text', get_option( 'woocommerce_email_footer_text' ) ) );
