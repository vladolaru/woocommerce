<?php
/**
 * WooPaymentsLegacySubscriptionsGuard multisite tests.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments\Subscriptions;

use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Subscriptions\WooPaymentsLegacySubscriptionsGuard;
use WC_Unit_Test_Case;

/**
 * The guard on a site visited with switch_to_blog(), whose plugins are not loaded.
 *
 * @group multisite
 */
class WooPaymentsLegacySubscriptionsGuardMultisiteTest extends WC_Unit_Test_Case {

	/**
	 * Remove the network activation the tests add.
	 */
	public function tearDown(): void {
		try {
			delete_site_option( 'active_sitewide_plugins' );
		} finally {
			parent::tearDown();
		}
	}

	/**
	 * @testdox A visited site with a Stripe-billed subscription is bundled unless it or the network activates WooCommerce Subscriptions, whatever its folder ($label).
	 * @testWith ["not active", [], [], true]
	 *           ["active on the site", ["woocommerce-subscriptions/woocommerce-subscriptions.php"], [], false]
	 *           ["woocommerce.com folder", ["woocommerce-com-woocommerce-subscriptions/woocommerce-subscriptions.php"], [], false]
	 *           ["network active", [], ["woocommerce-subscriptions/woocommerce-subscriptions.php"], false]
	 *
	 * @param string   $label            Case name.
	 * @param string[] $site_plugins     Plugins active on the visited site.
	 * @param string[] $network_plugins  Plugins active on the network.
	 * @param bool     $expected_bundled Whether the visited site is bundled.
	 */
	public function test_reads_woocommerce_subscriptions_activation_on_a_visited_site( string $label, array $site_plugins, array $network_plugins, bool $expected_bundled ): void {
		unset( $label );
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Test only runs on Multisite.' );
		}
		$site_id = self::factory()->blog->create();
		update_site_option( 'active_sitewide_plugins', array_fill_keys( $network_plugins, time() ) );

		switch_to_blog( $site_id );
		try {
			register_post_type( 'shop_subscription' );
			update_option( 'active_plugins', $site_plugins );
			$subscription_id = wp_insert_post(
				array(
					'post_type'   => 'shop_subscription',
					'post_status' => 'wc-active',
				)
			);
			update_post_meta( $subscription_id, '_wcpay_subscription_id', 'sub_1UM1VrBzWlxcwgpP6A3GwGLe' );

			$is_bundled = ( new WooPaymentsLegacySubscriptionsGuard() )->is_bundled_stripe_billing_store();
		} finally {
			restore_current_blog();
		}

		$this->assertSame( $expected_bundled, $is_bundled );
	}
}
