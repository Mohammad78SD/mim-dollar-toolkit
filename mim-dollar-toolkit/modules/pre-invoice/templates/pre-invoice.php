<?php
/**
 * Printable pre-invoice (standalone document). Override in theme: mim-dollar-toolkit/pre-invoice.php
 *
 * @package MimDollarToolkit
 * @var WC_Order $order
 * @var array    $data
 */

defined( 'ABSPATH' ) || exit;

$s        = $data['settings'];
$currency = $order->get_currency();
$d        = array( 'MDT_PI_Format', 'digits' );
$font     = $data['fa'] ? 'Vazirmatn, Tahoma, sans-serif' : '-apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif';

$shop_lines = array();
foreach ( array(
	'address'         => '',
	'phone'           => __( 'Phone', 'mim-dollar-toolkit' ),
	'email'           => __( 'Email', 'mim-dollar-toolkit' ),
	'website'         => __( 'Website', 'mim-dollar-toolkit' ),
	'national_id'     => __( 'National ID', 'mim-dollar-toolkit' ),
	'economic_code'   => __( 'Economic code', 'mim-dollar-toolkit' ),
	'registration_no' => __( 'Registration no.', 'mim-dollar-toolkit' ),
) as $k => $label ) {
	if ( '' !== trim( (string) $s[ $k ] ) ) {
		$shop_lines[] = ( $label ? $label . ': ' : '' ) . call_user_func( $d, $s[ $k ] );
	}
}

// Addresses without name/company lines (shown separately above the address).
$addr_strip    = array( 'first_name' => '', 'last_name' => '', 'company' => '' );
$billing_addr  = WC()->countries->get_formatted_address( array_merge( $order->get_address( 'billing' ), $addr_strip ) );
$shipping_addr = WC()->countries->get_formatted_address( array_merge( $order->get_address( 'shipping' ), $addr_strip ) );
$shipping_name = trim( $order->get_shipping_first_name() . ' ' . $order->get_shipping_last_name() );
?>
<!DOCTYPE html>
<html lang="<?php echo esc_attr( $data['lang'] ); ?>" dir="<?php echo $data['rtl'] ? 'rtl' : 'ltr'; ?>">
<head>
<meta charset="<?php echo esc_attr( get_option( 'blog_charset' ) ); ?>">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title><?php echo esc_html( sprintf( /* translators: %s: number */ __( 'Pre-invoice %s', 'mim-dollar-toolkit' ), $data['number'] ) ); ?></title>
<style>
@page { size: A4; margin: 12mm; }
* { box-sizing: border-box; }
body { margin: 0; background: #f3f3f3; color: #222; font: 14px/1.6 <?php echo $font; // phpcs:ignore WordPress.Security.EscapeOutput ?>; }
.sheet { position: relative; max-width: 210mm; margin: 16px auto; background: #fff; padding: 12mm; box-shadow: 0 1px 6px rgba(0,0,0,.15); overflow: hidden; }
.head { display: flex; justify-content: space-between; gap: 16px; border-bottom: 2px solid #222; padding-bottom: 10px; margin-bottom: 14px; }
.head img.logo { max-height: 70px; max-width: 200px; }
.shop h1 { margin: 0 0 4px; font-size: 20px; }
.shop div { font-size: 12px; color: #444; }
.meta { text-align: end; }
.meta h2 { margin: 0 0 6px; font-size: 20px; }
.meta table td { padding: 1px 0 1px 8px; }
.cols { display: flex; gap: 16px; margin-bottom: 14px; }
.cols > div { flex: 1; border: 1px solid #ddd; padding: 8px 10px; border-radius: 3px; font-size: 13px; }
.cols h3 { margin: 0 0 4px; font-size: 13px; text-transform: uppercase; color: #666; }
table.items { width: 100%; border-collapse: collapse; margin-bottom: 10px; }
table.items th, table.items td { border: 1px solid #ccc; padding: 6px 8px; text-align: start; vertical-align: middle; }
table.items th { background: #f0f0f0; font-size: 12px; }
table.items td.num, table.items th.num { text-align: end; white-space: nowrap; }
table.items img { width: 44px; height: 44px; object-fit: cover; border-radius: 3px; display: block; }
.item-meta { font-size: 12px; color: #666; }
table.totals { margin-inline-start: auto; min-width: 280px; border-collapse: collapse; }
table.totals td { padding: 4px 8px; border-bottom: 1px solid #eee; }
table.totals td.num { text-align: end; white-space: nowrap; }
table.totals tr.grand td { font-weight: 700; font-size: 16px; border-top: 2px solid #222; border-bottom: 0; }
.words { margin: 8px 0 14px; font-weight: 600; }
.notes { font-size: 12px; color: #444; margin-top: 10px; }
.notes .box { border: 1px dashed #bbb; padding: 6px 10px; margin-top: 8px; }
.sign { margin-top: 16px; text-align: end; }
.sign img { max-height: 110px; max-width: 220px; }
.wm { position: absolute; inset: 0; display: flex; align-items: center; justify-content: center; pointer-events: none; font-size: 96px; font-weight: 800; transform: rotate(-25deg); opacity: .12; color: #c00; text-transform: uppercase; }
.badge-paid { position: absolute; top: 14mm; inset-inline-end: 14mm; border: 3px solid #0a7a2f; color: #0a7a2f; padding: 2px 14px; font-weight: 800; font-size: 22px; transform: rotate(-8deg); border-radius: 4px; }
.toolbar { max-width: 210mm; margin: 12px auto 0; text-align: end; }
.toolbar button { padding: 8px 16px; font-size: 14px; cursor: pointer; }
@media print {
	body { background: #fff; }
	.sheet { box-shadow: none; margin: 0; padding: 0; max-width: none; }
	.toolbar { display: none; }
}
</style>
</head>
<body>
<div class="toolbar"><button type="button" onclick="window.print()"><?php esc_html_e( 'Print / Save as PDF', 'mim-dollar-toolkit' ); ?></button></div>
<div class="sheet">
<?php if ( 'cancelled' === $data['badge'] ) : ?>
	<div class="wm"><?php esc_html_e( 'Cancelled / Expired', 'mim-dollar-toolkit' ); ?></div>
<?php elseif ( 'paid' === $data['badge'] ) : ?>
	<div class="badge-paid"><?php esc_html_e( 'PAID', 'mim-dollar-toolkit' ); ?></div>
<?php endif; ?>

	<div class="head">
		<div class="shop">
			<?php if ( $data['logo'] ) : ?><img class="logo" src="<?php echo esc_url( $data['logo'] ); ?>" alt=""><?php endif; ?>
			<h1><?php echo esc_html( $s['shop_name'] ); ?></h1>
			<?php foreach ( $shop_lines as $line ) : ?>
				<div><?php echo esc_html( $line ); ?></div>
			<?php endforeach; ?>
		</div>
		<div class="meta">
			<h2><?php esc_html_e( 'Pre-invoice', 'mim-dollar-toolkit' ); ?></h2>
			<table>
				<tr><td><?php esc_html_e( 'Number', 'mim-dollar-toolkit' ); ?></td><td><strong><?php echo esc_html( call_user_func( $d, $data['number'] ) ); ?></strong></td></tr>
				<tr><td><?php esc_html_e( 'Issue date', 'mim-dollar-toolkit' ); ?></td><td><?php echo esc_html( $data['issued'] ); ?></td></tr>
				<tr><td><?php esc_html_e( 'Valid until', 'mim-dollar-toolkit' ); ?></td><td><?php echo esc_html( $data['valid_until'] ); ?></td></tr>
			</table>
		</div>
	</div>

	<div class="cols">
		<div>
			<h3><?php esc_html_e( 'Customer', 'mim-dollar-toolkit' ); ?></h3>
			<div><strong><?php echo esc_html( trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() ) ); ?></strong></div>
			<?php if ( $order->get_billing_company() ) : ?><div><?php echo esc_html( $order->get_billing_company() ); ?></div><?php endif; ?>
			<?php if ( $order->get_billing_phone() ) : ?><div><?php echo esc_html( call_user_func( $d, $order->get_billing_phone() ) ); ?></div><?php endif; ?>
			<?php if ( $order->get_billing_email() ) : ?><div><?php echo esc_html( $order->get_billing_email() ); ?></div><?php endif; ?>
			<div><?php echo wp_kses_post( call_user_func( $d, $billing_addr ) ); ?></div>
		</div>
		<?php if ( $data['shipping_differs'] ) : ?>
		<div>
			<h3><?php esc_html_e( 'Ship to', 'mim-dollar-toolkit' ); ?></h3>
			<?php if ( $shipping_name ) : ?><div><strong><?php echo esc_html( $shipping_name ); ?></strong></div><?php endif; ?>
			<?php if ( $order->get_shipping_company() ) : ?><div><?php echo esc_html( $order->get_shipping_company() ); ?></div><?php endif; ?>
			<div><?php echo wp_kses_post( call_user_func( $d, $shipping_addr ) ); ?></div>
		</div>
		<?php endif; ?>
	</div>

	<table class="items">
		<thead>
			<tr>
				<th>#</th>
				<?php if ( ! empty( $s['show_images'] ) ) : ?><th></th><?php endif; ?>
				<th><?php esc_html_e( 'Product', 'mim-dollar-toolkit' ); ?></th>
				<th><?php esc_html_e( 'SKU', 'mim-dollar-toolkit' ); ?></th>
				<th class="num"><?php esc_html_e( 'Qty', 'mim-dollar-toolkit' ); ?></th>
				<th class="num"><?php esc_html_e( 'Unit price', 'mim-dollar-toolkit' ); ?></th>
				<th class="num"><?php esc_html_e( 'Total', 'mim-dollar-toolkit' ); ?></th>
			</tr>
		</thead>
		<tbody>
		<?php foreach ( $data['items'] as $i => $row ) : ?>
			<tr>
				<td><?php echo esc_html( call_user_func( $d, $i + 1 ) ); ?></td>
				<?php if ( ! empty( $s['show_images'] ) ) : ?>
				<td><?php if ( $row['image'] ) : ?><img src="<?php echo esc_url( $row['image'] ); ?>" alt=""><?php endif; ?></td>
				<?php endif; ?>
				<td>
					<?php echo esc_html( $row['name'] ); ?>
					<?php foreach ( $row['meta'] as $m ) : ?><div class="item-meta"><?php echo esc_html( call_user_func( $d, $m ) ); ?></div><?php endforeach; ?>
				</td>
				<td><?php echo esc_html( $row['sku'] ); ?></td>
				<td class="num"><?php echo esc_html( call_user_func( $d, $row['qty'] ) ); ?></td>
				<td class="num"><?php echo MDT_PI_Format::money( $row['unit'], $currency ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in helper. ?></td>
				<td class="num"><?php echo MDT_PI_Format::money( $row['total'], $currency ); // phpcs:ignore WordPress.Security.EscapeOutput ?></td>
			</tr>
		<?php endforeach; ?>
		</tbody>
	</table>

	<table class="totals">
		<?php foreach ( $data['totals'] as $row ) : ?>
		<tr><td><?php echo esc_html( $row[0] ); ?></td><td class="num"><?php echo MDT_PI_Format::money( $row[1], $currency ); // phpcs:ignore WordPress.Security.EscapeOutput ?></td></tr>
		<?php endforeach; ?>
		<tr class="grand"><td><?php esc_html_e( 'Total', 'mim-dollar-toolkit' ); ?></td><td class="num"><?php echo MDT_PI_Format::money( $order->get_total(), $currency ); // phpcs:ignore WordPress.Security.EscapeOutput ?></td></tr>
	</table>

	<?php if ( $data['words'] ) : ?>
		<p class="words"><?php echo esc_html__( 'Total in words', 'mim-dollar-toolkit' ) . ': ' . esc_html( $data['words'] ); ?></p>
	<?php endif; ?>

	<div class="notes">
		<?php if ( $order->get_customer_note() ) : ?>
			<div class="box"><strong><?php esc_html_e( 'Customer note', 'mim-dollar-toolkit' ); ?>:</strong> <?php echo esc_html( $order->get_customer_note() ); ?></div>
		<?php endif; ?>
		<?php if ( ! empty( $data['reserved'] ) ) : ?><div class="box"><?php echo esc_html( $data['reserved'] ); ?></div><?php endif; ?>
		<?php if ( $data['disclaimer'] ) : ?><div class="box"><?php echo wp_kses_post( $data['disclaimer'] ); ?></div><?php endif; ?>
		<?php if ( $data['footer'] ) : ?><div class="box"><?php echo wp_kses_post( $data['footer'] ); ?></div><?php endif; ?>
	</div>

	<?php if ( $data['signature'] ) : ?>
		<div class="sign"><img src="<?php echo esc_url( $data['signature'] ); ?>" alt=""></div>
	<?php endif; ?>
</div>
</body>
</html>
