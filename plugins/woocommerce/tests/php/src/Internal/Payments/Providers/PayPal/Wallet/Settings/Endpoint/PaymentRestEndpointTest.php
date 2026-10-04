<?php
/**
 * Tests for the payment methods REST endpoint.
 *
 * @package Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\Settings\Endpoint
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\Settings\Endpoint;

use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Settings\Data\Definition\PaymentMethodsDefinition;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Settings\Data\Definition\PaymentMethodsDependenciesDefinition;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Settings\Data\GeneralSettings;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Settings\Data\PaymentSettings;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Settings\Endpoint\PaymentRestEndpoint;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\WalletTestCase;
use WP_REST_Request;

/**
 * What the settings UI reads and writes through the payment methods endpoint, over the real settings models.
 *
 * The extension keeps `fastlane_display_watermark` in the same stored option. The endpoint does not expose it as
 * `fastlaneDisplayWatermark` or write it, and a stored value must stay as it is.
 *
 * @group paypal-wallet
 */
class PaymentRestEndpointTest extends WalletTestCase {

	private const OPTION = 'woocommerce-ppcp-data-payment';

	/**
	 * Build the endpoint over the stored option as it is now.
	 *
	 * @return PaymentRestEndpoint
	 */
	private function create_endpoint(): PaymentRestEndpoint {
		$settings = new PaymentSettings();

		return new PaymentRestEndpoint(
			$settings,
			new PaymentMethodsDefinition( $settings, new GeneralSettings( 'US', 'USD', false ) ),
			new PaymentMethodsDependenciesDefinition()
		);
	}

	/**
	 * Post the given parameters to the update callback.
	 *
	 * @param PaymentRestEndpoint  $endpoint The endpoint.
	 * @param array<string, mixed> $params   The request parameters.
	 * @return array The response data.
	 */
	private function post( PaymentRestEndpoint $endpoint, array $params ): array {
		$request = new WP_REST_Request( 'POST', '/wc/v3/wc_paypal/payment' );
		$request->set_body_params( $params );

		return $endpoint->update_details( $request )->get_data();
	}

	/**
	 * @testdox Should list PayPal, Venmo and Pay Later with the PayPal logo choice.
	 */
	public function test_details_list_the_paypal_methods_and_the_logo_choice(): void {
		$this->set_wallet_option( self::OPTION, array( 'paypal_show_logo' => true ) );

		$data = $this->create_endpoint()->get_details()->get_data();

		$this->assertTrue( $data['success'] );
		foreach ( array( 'ppcp-gateway', 'venmo', 'pay-later' ) as $id ) {
			$this->assertArrayHasKey( $id, $data['data'], "$id should be listed" );
		}
		$this->assertTrue( $data['data']['paypalShowLogo'] );
	}

	/**
	 * @testdox Should store the PayPal logo choice and the Venmo state a request carries.
	 */
	public function test_update_stores_the_logo_choice_and_the_venmo_state(): void {
		$this->set_wallet_option( self::OPTION, array() );
		$this->set_wallet_option( 'woocommerce_venmo_settings', array() );

		$this->post(
			$this->create_endpoint(),
			array(
				'paypalShowLogo' => true,
				'venmo'          => array( 'enabled' => true ),
			)
		);

		$stored = get_option( self::OPTION );
		$this->assertTrue( $stored['paypal_show_logo'] );
		$this->assertTrue( $stored['venmo_enabled'] );
	}

	/**
	 * @testdox Should leave a Fastlane watermark value the extension stored as it is when a request changes something else.
	 */
	public function test_update_leaves_a_stored_fastlane_watermark_alone(): void {
		$this->set_wallet_option( self::OPTION, array( 'fastlane_display_watermark' => true ) );

		$this->post( $this->create_endpoint(), array( 'paypalShowLogo' => true ) );

		$stored = get_option( self::OPTION );
		$this->assertTrue( $stored['fastlane_display_watermark'] );
		$this->assertTrue( $stored['paypal_show_logo'] );
	}

	/**
	 * @testdox Should not expose the stored Fastlane watermark in the details.
	 */
	public function test_details_do_not_expose_the_fastlane_watermark(): void {
		$this->set_wallet_option( self::OPTION, array( 'fastlane_display_watermark' => true ) );

		$data = $this->create_endpoint()->get_details()->get_data();

		$this->assertArrayNotHasKey( 'fastlaneDisplayWatermark', $data['data'] );
	}

	/**
	 * @testdox Should ignore a Fastlane watermark a request carries and leave the stored value as it is.
	 */
	public function test_update_ignores_a_fastlane_watermark_in_the_request(): void {
		$this->set_wallet_option( self::OPTION, array( 'fastlane_display_watermark' => false ) );

		$this->post( $this->create_endpoint(), array( 'fastlaneDisplayWatermark' => true ) );

		$stored = get_option( self::OPTION );
		$this->assertFalse( $stored['fastlane_display_watermark'] );
	}
}
