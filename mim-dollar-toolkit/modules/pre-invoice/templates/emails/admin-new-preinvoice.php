<?php
/**
 * Admin new pre-invoice email (HTML). Override in theme: mim-dollar-toolkit/emails/admin-new-preinvoice.php
 *
 * @package MimDollarToolkit
 * @var WC_Order $order
 */

defined( 'ABSPATH' ) || exit;

do_action( 'woocommerce_email_header', $email_heading, $email );
?>

<p>
<?php
/* translators: 1: pre-invoice number, 2: customer name */
printf( esc_html__( 'A new pre-invoice request %1$s was received from %2$s.', 'mim-dollar-toolkit' ), esc_html( $pi_number ), esc_html( trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() ) ) );
?>
</p>
<p><?php echo esc_html__( 'Valid until', 'mim-dollar-toolkit' ) . ': ' . esc_html( $valid_until ); ?></p>

<?php
do_action( 'woocommerce_email_order_details', $order, $sent_to_admin, $plain_text, $email );
do_action( 'woocommerce_email_customer_details', $order, $sent_to_admin, $plain_text, $email );
?>

<p>
	<a href="<?php echo esc_url( $order->get_edit_order_url() ); ?>"><?php esc_html_e( 'Edit order in admin', 'mim-dollar-toolkit' ); ?></a>
	&middot;
	<a href="<?php echo esc_url( $pi_url ); ?>"><?php esc_html_e( 'View pre-invoice', 'mim-dollar-toolkit' ); ?></a>
</p>

<?php
if ( $additional_content ) {
	echo wp_kses_post( wpautop( wptexturize( $additional_content ) ) );
}
do_action( 'woocommerce_email_footer', $email );
