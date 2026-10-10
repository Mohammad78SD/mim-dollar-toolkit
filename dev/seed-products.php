<?php
// Idempotent sample product seeding. Run via: wp eval-file /seed-products.php
function mdt_seed_exists( $sku ) {
	return (bool) wc_get_product_id_by_sku( $sku );
}
function mdt_seed_simple( $sku, $name, $regular, $meta = array() ) {
	if ( mdt_seed_exists( $sku ) ) { echo "  exists: $name\n"; return; }
	$p = new WC_Product_Simple();
	$p->set_name( $name );
	$p->set_sku( $sku );
	$p->set_regular_price( $regular );
	$p->set_status( 'publish' );
	foreach ( $meta as $k => $v ) { $p->update_meta_data( $k, $v ); }
	$p->save();
	echo "  created: $name (#{$p->get_id()})\n";
}
mdt_seed_simple( 'MDT-USD-1', 'USD Product A', 1000000, array( '_mdt_usd_regular_price' => '100' ) );
// Product A: managed stock, qty 1 (pre-invoice stock reservation tests). Idempotent: only set once.
$mdt_a = wc_get_product( wc_get_product_id_by_sku( 'MDT-USD-1' ) );
if ( $mdt_a && ! $mdt_a->get_manage_stock() ) {
	$mdt_a->set_manage_stock( true );
	$mdt_a->set_stock_quantity( 1 );
	$mdt_a->set_stock_status( 'instock' );
	$mdt_a->save();
	echo "  stock: USD Product A managed, qty 1\n";
}
mdt_seed_simple( 'MDT-USD-2', 'USD Product B (sale)', 2500000, array( '_mdt_usd_regular_price' => '250.5', '_mdt_usd_sale_price' => '90' ) );
mdt_seed_simple( 'MDT-TOMAN', 'Toman Only Product', 5000000 );

if ( mdt_seed_exists( 'MDT-CARPET' ) ) {
	echo "  exists: Carpet Variable\n";
} else {
	$p = new WC_Product_Variable();
	$p->set_name( 'Carpet Variable' );
	$p->set_sku( 'MDT-CARPET' );
	$p->set_status( 'publish' );
	$attr = new WC_Product_Attribute();
	$attr->set_name( 'size' );
	$attr->set_options( array( '2x3', '3x4' ) );
	$attr->set_position( 0 );
	$attr->set_visible( true );
	$attr->set_variation( true );
	$p->set_attributes( array( $attr ) );
	$id = $p->save();
	foreach ( array( '2x3' => array( 300, 3000000 ), '3x4' => array( 450, 4500000 ) ) as $size => $v ) {
		$var = new WC_Product_Variation();
		$var->set_parent_id( $id );
		$var->set_attributes( array( 'size' => $size ) );
		$var->set_regular_price( $v[1] );
		$var->update_meta_data( '_mdt_usd_regular_price', (string) $v[0] );
		$var->set_status( 'publish' );
		$var->save();
	}
	WC_Product_Variable::sync( $id );
	echo "  created: Carpet Variable (#$id) with 2 variations\n";
}
