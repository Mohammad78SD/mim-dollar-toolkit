<?php
/**
 * Customer pre-invoice email (plain).
 *
 * @package MimDollarToolkit
 * @var WC_Order $order
 */

defined( 'ABSPATH' ) || exit;

echo '= ' . esc_html( wp_strip_all_tags( $email_heading ) ) . " =\n\n";
/* translators: %s: customer first name */
echo esc_html( sprintf( __( 'Hi %s,', 'mim-dollar-toolkit' ), $order->get_billing_first_name() ) ) . "\n\n";
echo esc_html( wp_strip_all_tags( MDT_PI_Settings::replace( $settings['email_intro'], $order ) ) ) . "\n\n";
echo esc_html__( 'Pre-invoice number', 'mim-dollar-toolkit' ) . ': ' . esc_html( $pi_number ) . "\n";
echo esc_html__( 'Issue date', 'mim-dollar-toolkit' ) . ': ' . esc_html( $issued ) . "\n";
echo esc_html__( 'Valid until', 'mim-dollar-toolkit' ) . ': ' . esc_html( $valid_until ) . "\n\n";
do_action( 'woocommerce_email_order_details', $order, $sent_to_admin, $plain_text, $email );
if ( $words ) {
	echo esc_html__( 'Total in words', 'mim-dollar-toolkit' ) . ': ' . esc_html( $words ) . "\n\n";
}
echo esc_html__( 'View & print pre-invoice', 'mim-dollar-toolkit' ) . ': ' . esc_url_raw( $pi_url ) . "\n\n";
$account_line = MDT_PI_Page::account_sentence( $order, false );
if ( '' !== $account_line ) {
	echo esc_html( $account_line ) . "\n\n";
}
echo esc_html( wp_strip_all_tags( MDT_PI_Settings::replace( $settings['payment_message'], $order ) ) ) . "\n";
$reserved = MDT_PI_Settings::reserved_sentence( $order );
if ( $reserved ) {
	echo esc_html( $reserved ) . "\n";
}
if ( ! empty( $settings['phone'] ) ) {
	echo esc_html__( 'Phone', 'mim-dollar-toolkit' ) . ': ' . esc_html( $settings['phone'] ) . "\n";
}
echo "\n";
if ( $additional_content ) {
	echo esc_html( wp_strip_all_tags( wptexturize( $additional_content ) ) ) . "\n\n";
}
echo wp_kses_post( apply_filters( 'woocommerce_email_footer_text', get_option( 'woocommerce_email_footer_text' ) ) );
