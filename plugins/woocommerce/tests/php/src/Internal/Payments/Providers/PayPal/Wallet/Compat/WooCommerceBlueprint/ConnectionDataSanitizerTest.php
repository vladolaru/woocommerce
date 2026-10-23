<?php
/**
 * Tests for the Blueprint connection data sanitizer.
 *
 * @package Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\Compat\WooCommerceBlueprint
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\Compat\WooCommerceBlueprint;

use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Compat\WooCommerceBlueprint\ConnectionDataSanitizer;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Settings\Data\GeneralSettings;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\WalletTestCase;
use ReflectionClass;

/**
 * What counts as connection data in a Blueprint export, and the neutral value each piece is exported with. The
 * sanitizer works on plain arrays, so it needs no Blueprint classes.
 *
 * @group paypal-wallet
 */
class ConnectionDataSanitizerTest extends WalletTestCase {

	/**
	 * The System Under Test.
	 *
	 * @var ConnectionDataSanitizer
	 */
	private $sut;

	/**
	 * Build the sanitizer.
	 */
	public function setUp(): void {
		parent::setUp();

		$this->sut = new ConnectionDataSanitizer();
	}

	/**
	 * A fully populated common option, as exported from a connected store.
	 *
	 * @return array<string, mixed>
	 */
	private function connected_common_option(): array {
		return array(
			'use_sandbox'           => true,
			'use_manual_connection' => true,
			'is_send_only_country'  => true,
			'merchant_connected'    => true,
			'sandbox_merchant'      => true,
			'merchant_id'           => 'MERCHANT123',
			'merchant_email'        => 'merchant@example.com',
			'merchant_country'      => 'US',
			'client_id'             => 'client-id-value',
			'client_secret'         => 'client-secret-value',
			'seller_type'           => 'business',
			'wc_installation_path'  => 'core_profiler',
		);
	}

	/**
	 * The sanitized common option of a connected store.
	 *
	 * @return array<string, mixed>
	 */
	private function sanitized_common_option(): array {
		$result = $this->sut->sanitize( array( ConnectionDataSanitizer::OPTION_COMMON => $this->connected_common_option() ) );

		return $result[ ConnectionDataSanitizer::OPTION_COMMON ];
	}

	/**
	 * The client ID, client secret, merchant ID and merchant email must never survive a default export.
	 *
	 * @testdox Should remove the credentials and the merchant identifiers.
	 */
	public function test_credentials_are_removed(): void {
		$common = $this->sanitized_common_option();

		$this->assertSame( '', $common['client_id'] );
		$this->assertSame( '', $common['client_secret'] );
		$this->assertSame( '', $common['merchant_id'] );
		$this->assertSame( '', $common['merchant_email'] );
	}

	/**
	 * The merchant country and the installation path are not recomputed when the target store connects, so a stale
	 * value would silently mis-gate the payment methods and lock the store into the branded-only experience.
	 *
	 * @testdox Should remove the configuration the target store cannot recompute.
	 */
	public function test_configuration_the_target_store_cannot_recompute_is_removed(): void {
		$common = $this->sanitized_common_option();

		$this->assertSame( '', $common['merchant_country'] );
		$this->assertSame( '', $common['wc_installation_path'] );
	}

	/**
	 * @testdox Should leave the connection state self-consistent, as disconnected.
	 */
	public function test_connection_state_is_left_self_consistent(): void {
		$common = $this->sanitized_common_option();

		$this->assertFalse( $common['merchant_connected'] );
		$this->assertFalse( $common['sandbox_merchant'] );
		$this->assertSame( 'unknown', $common['seller_type'] );
		$this->assertFalse( $common['use_sandbox'] );
		$this->assertFalse( $common['use_manual_connection'] );
	}

	/**
	 * The send-only flag describes the target store, not the exporting merchant, and the general settings model
	 * recomputes it on every load.
	 *
	 * @testdox Should keep the send-only country flag.
	 */
	public function test_is_send_only_country_is_preserved(): void {
		$this->assertTrue( $this->sanitized_common_option()['is_send_only_country'] );
	}

	/**
	 * Resetting setup_done would let the new merchant defaults overwrite the styling settings this export exists to
	 * carry.
	 *
	 * @testdox Should reset the onboarding progress like a disconnect and keep setup_done.
	 */
	public function test_onboarding_mirrors_the_disconnect_state_and_keeps_setup_done(): void {
		$result = $this->sut->sanitize(
			array(
				ConnectionDataSanitizer::OPTION_ONBOARDING => array(
					'completed'            => true,
					'step'                 => 5,
					'is_casual_seller'     => false,
					'accept_card_payments' => true,
					'products'             => array( 'subscriptions' ),
					'setup_done'           => true,
					'gateways_synced'      => true,
					'gateways_refreshed'   => true,
				),
			)
		);

		$onboarding = $result[ ConnectionDataSanitizer::OPTION_ONBOARDING ];

		$this->assertFalse( $onboarding['completed'] );
		$this->assertSame( 0, $onboarding['step'] );
		$this->assertFalse( $onboarding['gateways_synced'] );
		$this->assertFalse( $onboarding['gateways_refreshed'] );
		$this->assertTrue( $onboarding['setup_done'], 'setup_done must survive sanitization' );
		$this->assertFalse( $onboarding['is_casual_seller'] );
		$this->assertTrue( $onboarding['accept_card_payments'] );
		$this->assertSame( array( 'subscriptions' ), $onboarding['products'] );
	}

	/**
	 * @testdox Should leave the options that hold no connection data alone.
	 */
	public function test_unrelated_options_are_untouched(): void {
		$options = array(
			'woocommerce-ppcp-data-styling' => array( 'cart' => array( 'shape' => 'pill' ) ),
			'woocommerce_venmo_settings'    => array( 'enabled' => 'yes' ),
		);

		$this->assertSame( $options, $this->sut->sanitize( $options ) );
	}

	/**
	 * Sanitizing must not add keys the stored option never had, or the import would write defaults the source store did
	 * not have either.
	 *
	 * @testdox Should not add keys the stored option does not have.
	 */
	public function test_absent_keys_are_not_added(): void {
		$result = $this->sut->sanitize( array( ConnectionDataSanitizer::OPTION_COMMON => array( 'client_id' => 'abc' ) ) );

		$this->assertSame( array( 'client_id' => '' ), $result[ ConnectionDataSanitizer::OPTION_COMMON ] );
	}

	/**
	 * @testdox Should ignore option values that are not arrays.
	 */
	public function test_non_array_option_values_are_ignored(): void {
		$options = array(
			ConnectionDataSanitizer::OPTION_COMMON     => 'unexpected-scalar',
			ConnectionDataSanitizer::OPTION_ONBOARDING => null,
		);

		$this->assertSame( $options, $this->sut->sanitize( $options ) );
	}

	/**
	 * The defaults of the general settings model, which it keeps protected.
	 *
	 * @return array<string, mixed>
	 */
	private function general_settings_defaults(): array {
		$reflection = new ReflectionClass( GeneralSettings::class );
		$method     = $reflection->getMethod( 'get_defaults' );
		$method->setAccessible( true );

		return $method->invoke( $reflection->newInstanceWithoutConstructor() );
	}

	/**
	 * Drift guard: CONNECTION_DEFAULTS repeats values that the protected GeneralSettings::get_defaults() owns, so this
	 * fails when the model changes one.
	 *
	 * @testdox Should keep its neutral connection values equal to the defaults of the general settings model.
	 */
	public function test_connection_defaults_match_the_general_settings_defaults(): void {
		$model_defaults = $this->general_settings_defaults();

		foreach ( ConnectionDataSanitizer::CONNECTION_DEFAULTS as $key => $neutral_value ) {
			$this->assertArrayHasKey( $key, $model_defaults, "GeneralSettings no longer defines '$key'" );
			$this->assertSame( $model_defaults[ $key ], $neutral_value, "Neutral value for '$key' no longer matches GeneralSettings::get_defaults()" );
		}
	}

	/**
	 * Reverse of the guard above: sanitize() only touches keys it knows, so a new merchant field must be neutralised or
	 * declared safe to export.
	 *
	 * @testdox Should neutralise every merchant field of the general settings model, or export it knowingly.
	 */
	public function test_every_merchant_field_is_either_sanitized_or_knowingly_exported(): void {
		// Recomputed from the target store on every load, so exporting it is harmless.
		$exported_on_purpose = array( 'is_send_only_country' );

		$unaccounted = array_diff(
			array_keys( $this->general_settings_defaults() ),
			array_keys( ConnectionDataSanitizer::CONNECTION_DEFAULTS ),
			$exported_on_purpose
		);

		$this->assertSame(
			array(),
			$unaccounted,
			'GeneralSettings defines field(s) the sanitizer does not handle: ' . implode( ', ', $unaccounted ) . '. Add each to ConnectionDataSanitizer::CONNECTION_DEFAULTS, or to $exported_on_purpose here once confirmed safe to share.'
		);
	}
}
