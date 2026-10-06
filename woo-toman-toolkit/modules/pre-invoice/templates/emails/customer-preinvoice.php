<?php
/**
 * Customer pre-invoice email (HTML). Override in theme: woo-toman-toolkit/emails/customer-preinvoice.php
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
<p><?php echo wp_kses_post( WTT_PI_Settings::replace( $settings['email_intro'], $order ) ); ?></p>

<table cellspacing="0" cellpadding="6" style="width:100%;margin-bottom:20px;border:1px solid #e5e5e5" border="1">
	<tr><th style="text-align:start"><?php esc_html_e( 'Pre-invoice number', 'woo-toman-toolkit' ); ?></th><td><?php echo esc_html( WTT_PI_Format::digits( $pi_number ) ); ?></td></tr>
	<tr><th style="text-align:start"><?php esc_html_e( 'Issue date', 'woo-toman-toolkit' ); ?></th><td><?php echo esc_html( $issued ); ?></td></tr>
	<tr><th style="text-align:start"><?php esc_html_e( 'Valid until', 'woo-toman-toolkit' ); ?></th><td><?php echo esc_html( $valid_until ); ?></td></tr>
</table>

<?php
do_action( 'woocommerce_email_order_details', $order, $sent_to_admin, $plain_text, $email );

if ( $words ) {
	echo '<p><strong>' . esc_html__( 'Total in words', 'woo-toman-toolkit' ) . ':</strong> ' . esc_html( $words ) . '</p>';
}
?>

<p style="margin:20px 0"><a href="<?php echo esc_url( $pi_url ); ?>" style="background:#222;color:#fff;padding:10px 18px;text-decoration:none;border-radius:3px;display:inline-block"><?php esc_html_e( 'View & print pre-invoice', 'woo-toman-toolkit' ); ?></a></p>

<?php $account_line = WTT_PI_Page::account_sentence( $order ); ?>
<?php if ( '' !== $account_line ) : ?><p><?php echo wp_kses( $account_line, array( 'a' => array( 'href' => array() ) ) ); ?></p><?php endif; ?>

<p><?php echo wp_kses_post( WTT_PI_Settings::replace( $settings['payment_message'], $order ) ); ?></p>
<?php $reserved = WTT_PI_Settings::reserved_sentence( $order ); ?>
<?php if ( $reserved ) : ?><p><?php echo esc_html( $reserved ); ?></p><?php endif; ?>
<?php if ( ! empty( $settings['phone'] ) ) : ?>
<p><?php echo esc_html__( 'Phone', 'woo-toman-toolkit' ) . ': ' . esc_html( WTT_PI_Format::digits( $settings['phone'] ) ); ?></p>
<?php endif; ?>

<?php
if ( $additional_content ) {
	echo wp_kses_post( wpautop( wptexturize( $additional_content ) ) );
}
do_action( 'woocommerce_email_footer', $email );
