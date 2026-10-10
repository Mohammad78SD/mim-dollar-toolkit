<?php
/**
 * The pure helpers of the legacy migration (prefix map building); the SQL steps need a real WordPress database.
 */
class MigrationTest extends MDT_TestCase {

	public function test_generations_are_listed_newest_first_with_their_markers() {
		$this->assertSame(
			array( 'wtt' => 'WTT_TK_FILE', 'azk' => 'AZK_TK_FILE' ),
			MDT_Migration::generations()
		);
	}

	public function test_prefix_map_for_wtt() {
		$map = MDT_Migration::prefix_map( 'wtt' );
		$this->assertSame( 'mdt', $map['new'] );
		$this->assertSame( array( 'wtt_', 'woocommerce_wtt_' ), $map['option_prefix'] );
		$this->assertSame( array( 'mdt_', 'woocommerce_mdt_' ), $map['option_new'] );
		$this->assertSame( '_wtt_', $map['postmeta_old'] );
		$this->assertSame( '_mdt_', $map['postmeta_new'] );
		$this->assertSame( 'wtt_', $map['usermeta_old'] );
		$this->assertSame( 'wc-wtt-preinvoice', $map['status_old'] );
		$this->assertSame( 'wc-mdt-preinvoice', $map['status_new'] );
		$this->assertSame( 'wtt_preinvoice', $map['method_old'] );
		$this->assertSame( 'mdt_preinvoice', $map['method_new'] );
		$this->assertSame( array( 'wtt_usd_sync_batch', 'wtt_sms_send' ), $map['as_hooks'] );
		$this->assertSame( array( 'wtt_usd', 'wtt_sms' ), $map['as_groups'] );
	}

	public function test_prefix_map_for_azk() {
		$map = MDT_Migration::prefix_map( 'azk' );
		$this->assertSame( 'wc-azk-preinvoice', $map['status_old'] );
		$this->assertSame( 'wc-mdt-preinvoice', $map['status_new'] );
		$this->assertContains( '_transient_timeout_azk_', $map['transient_like'] );
	}

	public function test_map_option_name() {
		$this->assertSame( 'mdt_usd_settings', MDT_Migration::map_option_name( 'wtt_usd_settings', 'wtt' ) );
		$this->assertSame( 'mdt_usd_settings', MDT_Migration::map_option_name( 'azk_usd_settings', 'azk' ) );
		$this->assertSame( 'woocommerce_mdt_preinvoice_settings', MDT_Migration::map_option_name( 'woocommerce_wtt_preinvoice_settings', 'wtt' ) );
		$this->assertSame( 'woocommerce_mdt_customer_preinvoice_settings', MDT_Migration::map_option_name( 'woocommerce_azk_customer_preinvoice_settings', 'azk' ) );
	}

	public function test_map_option_name_rejects_foreign_names() {
		$this->assertSame( '', MDT_Migration::map_option_name( 'azk_usd_settings', 'wtt' ) );
		$this->assertSame( '', MDT_Migration::map_option_name( 'blogname', 'wtt' ) );
	}

	public function test_bookkeeping_options_are_not_copied() {
		$this->assertTrue( MDT_Migration::is_bookkeeping_option( 'wtt_migration_version', 'wtt' ) );
		$this->assertTrue( MDT_Migration::is_bookkeeping_option( 'azk_migration_lock', 'azk' ) );
		$this->assertFalse( MDT_Migration::is_bookkeeping_option( 'wtt_usd_settings', 'wtt' ) );
	}
}
