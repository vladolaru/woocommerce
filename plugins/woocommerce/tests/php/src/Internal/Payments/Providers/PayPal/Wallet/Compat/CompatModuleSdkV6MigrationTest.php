<?php
/**
 * Tests for the SDK v6 default migration of the compatibility module (ported from the extension's
 * CompatModuleSdkV6MigrationTest).
 *
 * @package Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\Compat
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\Compat;

use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Compat\CompatModule;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Settings\Data\SettingsProvider;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\WalletTestCase;
use Automattic\WooCommerce\Vendor\Psr\Container\ContainerInterface;

/**
 * The migration hands a store over to the v6 SDK once, on the update hook, unless the merchant's country is withheld.
 * All cases are wallet cases.
 *
 * @group paypal-wallet
 */
class CompatModuleSdkV6MigrationTest extends WalletTestCase {

	private const HOOK            = 'woocommerce_paypal_payments_gateway_migrate_on_update';
	private const MARKER_OPTION   = 'woocommerce_ppcp-is_sdk_v6_default_migrated';
	private const ELIGIBLE_OPTION = 'woocommerce-ppcp-sdk-v6-eligible';
	private const FILTER          = 'woocommerce_paypal_payments_sdk_v6_unsupported_countries';

	/**
	 * Anonymous subclass that exposes the protected migration.
	 *
	 * @var object
	 */
	private $testee;

	/**
	 * Start from no listener on the update hook, no filter on the country list and no stored answers.
	 */
	public function setUp(): void {
		parent::setUp();

		remove_all_actions( self::HOOK );
		remove_all_filters( self::FILTER );
		delete_option( self::MARKER_OPTION );
		delete_option( self::ELIGIBLE_OPTION );

		$this->testee = new class() extends CompatModule {
			/**
			 * Register the migration on the update hook.
			 *
			 * @param ContainerInterface $c The container.
			 */
			public function run_migration( ContainerInterface $c ): void {
				$this->migrate_sdk_v6_default( $c );
			}
		};
	}

	/**
	 * Delete the options the migration may have written.
	 */
	public function tearDown(): void {
		delete_option( self::MARKER_OPTION );
		delete_option( self::ELIGIBLE_OPTION );

		parent::tearDown();
	}

	/**
	 * A container that resolves the settings provider, once, to one that reports the given merchant country.
	 *
	 * @param string $merchant_country The merchant country.
	 * @return ContainerInterface
	 */
	private function container_resolving_to( string $merchant_country ): ContainerInterface {
		$settings_provider = $this->createStub( SettingsProvider::class );
		$settings_provider->method( 'merchant_country' )->willReturn( $merchant_country );

		$container = $this->mock( ContainerInterface::class );
		$container->shouldReceive( 'get' )->once()->with( 'settings.settings-provider' )->andReturn( $settings_provider );

		return $container;
	}

	/**
	 * Stored eligibility answers that carry no "yes".
	 *
	 * @return array<string, array{string|null}>
	 */
	public function data_no_or_absent_answers(): array {
		return array(
			'eligible explicitly no' => array( 'no' ),
			'eligible absent'        => array( null ),
		);
	}

	/**
	 * @testdox Should register no callback when the store already went through the handover (wallet).
	 */
	public function test_marker_already_set_registers_no_callback(): void {
		update_option( self::MARKER_OPTION, true );
		$container = $this->mock( ContainerInterface::class );
		$container->shouldNotReceive( 'get' );

		$this->testee->run_migration( $container );

		$this->assertFalse( has_action( self::HOOK ), 'The store is never re-evaluated' );
	}

	/**
	 * @testdox Should leave a store that is already eligible untouched, and still set the marker (wallet).
	 */
	public function test_already_eligible_store_is_untouched_and_marker_is_set(): void {
		update_option( self::ELIGIBLE_OPTION, 'yes' );
		$container = $this->mock( ContainerInterface::class );
		$container->shouldNotReceive( 'get' );

		$this->testee->run_migration( $container );
		do_action( self::HOOK );

		$this->assertSame( 'yes', get_option( self::ELIGIBLE_OPTION ) );
		$this->assertTrue( (bool) get_option( self::MARKER_OPTION ), 'The migration does not run again' );
	}

	/**
	 * @testdox Should hand a held-back or unanswered store in a supported country over to v6 and set the marker (wallet).
	 * @dataProvider data_no_or_absent_answers
	 *
	 * @param string|null $eligible_value The stored answer, or null for none.
	 */
	public function test_supported_store_is_handed_over_to_v6( ?string $eligible_value ): void {
		if ( null !== $eligible_value ) {
			update_option( self::ELIGIBLE_OPTION, $eligible_value );
		}

		$this->testee->run_migration( $this->container_resolving_to( 'US' ) );
		do_action( self::HOOK );

		$this->assertSame( 'yes', get_option( self::ELIGIBLE_OPTION ) );
		$this->assertTrue( (bool) get_option( self::MARKER_OPTION ) );
	}

	/**
	 * @testdox Should write "no" when a filter withholds the merchant's country, whatever the stored answer was (wallet).
	 * @dataProvider data_no_or_absent_answers
	 *
	 * @param string|null $eligible_value The stored answer, or null for none.
	 */
	public function test_country_withheld_by_filter_is_written_no( ?string $eligible_value ): void {
		if ( null !== $eligible_value ) {
			update_option( self::ELIGIBLE_OPTION, $eligible_value );
		}
		add_filter(
			self::FILTER,
			static function () {
				return array( 'MX', 'BR' );
			}
		);

		$this->testee->run_migration( $this->container_resolving_to( 'BR' ) );
		do_action( self::HOOK );

		$this->assertSame( 'no', get_option( self::ELIGIBLE_OPTION ) );
		$this->assertTrue( (bool) get_option( self::MARKER_OPTION ) );
	}
}
