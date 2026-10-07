<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments;

use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsTracks;
use WC_Unit_Test_Case;

/**
 * Tests for the native WooPayments Tracks source marking.
 */
class WooPaymentsTracksTest extends WC_Unit_Test_Case {

	/**
	 * The event source scheme the owner approved on 2026-10-07: native events keep the client's names, carry
	 * `payments_runtime` = `woocommerce_core`, and send no `wcpay_version`, which client 11.1.0 fills with the plugin
	 * version.
	 *
	 * @testdox Should record a server-side admin event as sent by WooCommerce core, without a plugin version.
	 */
	public function test_records_admin_event_with_the_native_runtime_and_no_plugin_version(): void {
		update_option( 'woocommerce_allow_tracking', 'yes' );
		$recorded = array();
		$capture  = static function ( $properties, $event_name ) use ( &$recorded ) {
			$recorded[ $event_name ] = $properties;

			return $properties;
		};
		add_filter( 'woocommerce_tracks_event_properties', $capture, 10, 2 );

		try {
			WooPaymentsTracks::record_wcadmin_event(
				'wcpay_first_live_sale',
				array(
					'stage'         => 'one',
					'wcpay_version' => '11.1.0',
				)
			);
		} finally {
			remove_filter( 'woocommerce_tracks_event_properties', $capture, 10 );
		}

		$this->assertArrayHasKey( 'wcadmin_wcpay_first_live_sale', $recorded );
		$properties = $recorded['wcadmin_wcpay_first_live_sale'];
		$this->assertSame( 'woocommerce_core', $properties['payments_runtime'] );
		$this->assertSame( 'one', $properties['stage'] );
		$this->assertArrayNotHasKey( 'wcpay_version', $properties );
	}
}
