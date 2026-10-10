<?php
/**
 * Admin new pre-invoice email (plain).
 *
 * @package MimDollarToolkit
 * @var WC_Order $order
 */

defined( 'ABSPATH' ) || exit;

echo '= ' . esc_html( wp_strip_all_tags( $email_heading ) ) . " =\n\n";
/* translators: 1: pre-invoice number, 2: customer name */
echo esc_html( sprintf( __( 'A new pre-invoice request %1$s was received from %2$s.', 'mim-dollar-toolkit' ), $pi_number, trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() ) ) ) . "\n";
echo esc_html__( 'Valid until', 'mim-dollar-toolkit' ) . ': ' . esc_html( $valid_until ) . "\n\n";
do_action( 'woocommerce_email_order_details', $order, $sent_to_admin, $plain_text, $email );
do_action( 'woocommerce_email_customer_details', $order, $sent_to_admin, $plain_text, $email );
echo esc_html__( 'Edit order in admin', 'mim-dollar-toolkit' ) . ': ' . esc_url_raw( $order->get_edit_order_url() ) . "\n";
echo esc_html__( 'View pre-invoice', 'mim-dollar-toolkit' ) . ': ' . esc_url_raw( $pi_url ) . "\n\n";
if ( $additional_content ) {
	echo esc_html( wp_strip_all_tags( wptexturize( $additional_content ) ) ) . "\n\n";
}
echo wp_kses_post( apply_filters( 'woocommerce_email_footer_text', get_option( 'woocommerce_email_footer_text' ) ) );
