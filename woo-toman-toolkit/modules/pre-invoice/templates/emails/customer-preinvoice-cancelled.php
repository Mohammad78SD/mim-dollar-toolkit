<?php
/**
 * Customer pre-invoice cancelled email (HTML). Override in theme: woo-toman-toolkit/emails/customer-preinvoice-cancelled.php
 *
 * @package WooTomanToolkit
 * @var WC_Order $order
 */

defined( 'ABSPATH' ) || exit;

do_action( 'woocommerce_email_header', $email_heading, $email );
?>

<p>
<?php
/* translators: %s: customer first name */
printf( esc_html__( 'Hi %s,', 'woo-toman-toolkit' ), esc_html( $order->get_billing_first_name() ) );
?>
</p>
<p>
<?php
/* translators: %s: pre-invoice number */
printf( esc_html__( 'Your pre-invoice %s has expired or been cancelled. The reserved items have been released. Please contact us if you would like us to re-issue it.', 'woo-toman-toolkit' ), esc_html( WTT_PI_Format::digits( $pi_number ) ) );
?>
</p>
<?php if ( ! empty( $settings['phone'] ) ) : ?>
<p><?php echo esc_html__( 'Phone', 'woo-toman-toolkit' ) . ': ' . esc_html( WTT_PI_Format::digits( $settings['phone'] ) ); ?></p>
<?php endif; ?>

<?php
do_action( 'woocommerce_email_order_details', $order, $sent_to_admin, $plain_text, $email );

if ( $additional_content ) {
	echo wp_kses_post( wpautop( wptexturize( $additional_content ) ) );
}
do_action( 'woocommerce_email_footer', $email );
