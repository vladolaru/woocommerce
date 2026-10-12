<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Collecting\Gating;

use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\Gating\PlatformPWCProductStatus;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\State\Options;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Helper\ProductStatusResultCache;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\PayWithCrypto\PWCGateway;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\WcGateway\Gateway\PayPalGateway;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Settings\Data\Definition\FeaturesDefinition;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\WcGateway\Helper\PWCProductStatus;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Collecting\BootsCollectingContainer;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Collecting\Doubles\FakePlatformTransport;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Collecting\Doubles\TransportBindingModule;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Collecting\Surface\HoldsWalletState;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\WalletTestCase;
use Automattic\WooCommerce\Vendor\Psr\Container\ContainerInterface;

/**
 * Tests that every reader of the Pay with Crypto capability gets "no" on a collecting store and the Pay with Crypto check's
 * answer on a platform-connected one, through the decorated `wcgateway.pwc-product-status`, whatever the shared cache
 * holds, and the wallet's own answer on a first-party store.
 *
 * @group paypal-wallet
 */
class PlatformPWCProductStatusTest extends WalletTestCase {
	use BootsCollectingContainer;
	use HoldsWalletState;

	/**
	 * WooCommerce's gateway list before the test rebuilt it.
	 *
	 * @var array
	 */
	private array $original_gateways = array();

	/**
	 * The store currency before the test.
	 *
	 * @var mixed
	 */
	private $original_currency;

	/**
	 * Remember WooCommerce's gateway list and the currency.
	 */
	public function setUp(): void {
		parent::setUp();
		$this->original_gateways = WC()->payment_gateways()->payment_gateways;
		$this->original_currency = get_option( 'woocommerce_currency' );
		update_option( 'woocommerce_currency', 'USD' );
	}

	/**
	 * Put WooCommerce's gateway list and the currency back.
	 */
	public function tearDown(): void {
		try {
			WC()->payment_gateways()->payment_gateways = $this->original_gateways;
			update_option( 'woocommerce_currency', $this->original_currency );
		} finally {
			parent::tearDown();
		}
	}

	/**
	 * Store a Pay with Crypto check answer.
	 *
	 * @param bool   $available   Whether PayPal accepted the check order.
	 * @param string $merchant_id The merchant the answer was recorded for.
	 * @param string $environment The environment the answer was recorded for.
	 */
	private function set_marker( bool $available, string $merchant_id = 'M2', string $environment = 'sandbox' ): void {
		$this->set_wallet_option(
			Options::PWC_CHECK,
			array(
				'available'   => $available,
				'checked_at'  => 1760000000,
				'environment' => $environment,
				'merchant_id' => $merchant_id,
			)
		);
	}

	/**
	 * Boot the wallet with a ready transport and the wallet gateway on, run the hooks the modules add after the theme
	 * setup, and rebuild WooCommerce's gateway list, as a served request does.
	 *
	 * @return ContainerInterface
	 */
	private function boot(): ContainerInterface {
		$this->set_wallet_option(
			'woocommerce_ppcp-gateway_settings',
			array(
				'enabled' => 'yes',
				'ppcp'    => '',
			)
		);
		remove_all_actions( 'after_setup_theme' );
		$container = $this->boot_container( array( new TransportBindingModule( new FakePlatformTransport() ) ) );
		do_action( 'after_setup_theme' );
		WC()->payment_gateways()->init();

		return $container;
	}

	/**
	 * Every reader's answer for Pay with Crypto.
	 *
	 * @param ContainerInterface $container The booted container.
	 * @return array<string, bool>
	 */
	private function readers( ContainerInterface $container ): array {
		$features = apply_filters( 'woocommerce_paypal_payments_rest_common_merchant_features', array() );
		$methods  = apply_filters( 'woocommerce_paypal_payments_payment_methods', array( PWCGateway::ID => array() ) );

		return array(
			'product status'   => $container->get( 'wcgateway.pwc-product-status' )->is_active(),
			'merchant feature' => $features[ FeaturesDefinition::FEATURE_PAY_WITH_CRYPTO ]['enabled'] ?? null,
			'capabilities'     => $container->get( 'settings.service.merchant_capabilities' )[ FeaturesDefinition::FEATURE_PAY_WITH_CRYPTO ],
			'method check'     => $container->get( 'settings.service.payment_methods_eligibilities' )->get_eligibility_checks()[ PWCGateway::ID ](),
			'gateway'          => isset( WC()->payment_gateways()->payment_gateways()[ PWCGateway::ID ] ),
			'feature card'     => $container->get( 'settings.data.definition.features' )->all_available_features()[ FeaturesDefinition::FEATURE_PAY_WITH_CRYPTO ]['enabled'],
			'to-do'            => $container->get( 'settings.service.todos_eligibilities' )->get_eligibility_checks()['enable_pwc'](),
			'settings method'  => array_key_exists( PWCGateway::ID, $methods ),
		);
	}

	/**
	 * @testdox Should give every reader the check's answer on a platform-connected store, whatever the shared cache holds: $marker.
	 * @testWith ["available", true]
	 *           ["not available", false]
	 *           ["none", false]
	 *           ["another merchant", false]
	 *           ["another environment", false]
	 *
	 * @param string $marker   The stored answer.
	 * @param bool   $expected What every reader should see.
	 */
	public function test_every_reader_follows_the_check_on_a_platform_connected_store( string $marker, bool $expected ): void {
		$this->set_platform_connected();
		if ( 'available' === $marker ) {
			$this->set_marker( true );
		} elseif ( 'not available' === $marker ) {
			$this->set_marker( false );
		} elseif ( 'another merchant' === $marker ) {
			$this->set_marker( true, 'OTHER' );
		} elseif ( 'another environment' === $marker ) {
			$this->set_marker( true, 'M2', 'production' );
		}
		// The shared cache says the opposite, as a first-party or extension answer left there would.
		$cache = new ProductStatusResultCache();
		$cache->set( PWCProductStatus::KEY, $expected ? 'no' : 'yes' );
		$container = $this->boot();

		$this->assertInstanceOf( PlatformPWCProductStatus::class, $container->get( 'wcgateway.pwc-product-status' ) );
		$this->assertSame( array_fill_keys( array_keys( $this->readers( $container ) ), $expected ), $this->readers( $container ) );
		$this->assertSame( $expected ? 'no' : 'yes', ( new ProductStatusResultCache() )->get( PWCProductStatus::KEY ), 'The shared cache is neither read nor written' );
	}

	/**
	 * @testdox Should leave the wallet's own Pay with Crypto status, and its answer, to a first-party store.
	 */
	public function test_a_first_party_store_keeps_the_wallets_status(): void {
		$this->set_first_party_connected();
		$this->set_marker( true );
		( new ProductStatusResultCache() )->set( PWCProductStatus::KEY, 'no' );

		$status = $this->boot()->get( 'wcgateway.pwc-product-status' );

		$this->assertSame( PWCProductStatus::class, get_class( $status ) );
		$this->assertFalse( $status->is_active(), 'The wallet\'s cached answer, not the check\'s' );
	}

	/**
	 * Put a stale "yes" in the shared product-status entry, in the shape the wallet's ProductStatusResultCache stores, as
	 * the extension or an earlier connection may have left it.
	 */
	private function set_stale_cached_yes(): void {
		$this->set_wallet_transient(
			'woocommerce-ppcp-cache-product-status',
			array(
				PWCProductStatus::KEY => array(
					'value'      => 'yes',
					'expires_at' => 0,
				),
			)
		);
	}

	/**
	 * @testdox Should give every reader "no" on a collecting store whose shared cache holds a stale "yes", without reading or writing that cache.
	 */
	public function test_every_reader_says_no_on_a_collecting_store_with_a_stale_cached_yes(): void {
		$this->set_collecting();
		$this->set_stale_cached_yes();

		$container = $this->boot();
		$status    = $container->get( 'wcgateway.pwc-product-status' );
		$features  = apply_filters( 'woocommerce_paypal_payments_rest_common_merchant_features', array() );
		$methods   = apply_filters( 'woocommerce_paypal_payments_payment_methods', array( PWCGateway::ID => array() ) );
		$todos     = $container->get( 'settings.rest.todos' )->get_todos()->get_data()['data']['todos'];

		$this->assertInstanceOf( PlatformPWCProductStatus::class, $status );
		$this->assertFalse( $status->check_local_state(), 'A collecting store has no account to hold the capability' );
		$this->assertFalse( $status->is_active() );
		$this->assertFalse( $features[ FeaturesDefinition::FEATURE_PAY_WITH_CRYPTO ]['enabled'], 'The merchant feature' );
		$this->assertFalse( $container->get( 'settings.service.merchant_capabilities' )[ FeaturesDefinition::FEATURE_PAY_WITH_CRYPTO ], 'The capability row' );
		$this->assertFalse( $container->get( 'settings.service.payment_methods_eligibilities' )->get_eligibility_checks()[ PWCGateway::ID ](), 'The method check' );
		$this->assertFalse( $container->get( 'settings.service.todos_eligibilities' )->get_eligibility_checks()['enable_pwc'](), 'The to-do check' );
		$this->assertArrayNotHasKey( PWCGateway::ID, $methods, 'The settings method' );
		$this->assertNotContains( 'enable_pwc', array_column( $todos, 'id' ), 'The to-do the settings app receives' );
		$this->assertArrayNotHasKey( FeaturesDefinition::FEATURE_PAY_WITH_CRYPTO, $container->get( 'settings.data.definition.features' )->all_available_features(), 'The card, dropped while collecting' );
		$this->assertFalse( isset( WC()->payment_gateways()->payment_gateways()[ PWCGateway::ID ] ), 'The gateway' );
		$this->assertSame( 'yes', ( new ProductStatusResultCache() )->get( PWCProductStatus::KEY ), 'The shared cache is neither read nor written' );
	}

	/**
	 * @testdox Should keep Pay with Crypto out of the real gateway list of a collecting store whose shared cache holds a stale "yes", with no collecting callback on the list.
	 */
	public function test_a_stale_cached_yes_does_not_register_the_gateway_in_the_real_filter_chain(): void {
		$this->set_collecting();
		$this->set_wallet_option(
			'woocommerce_ppcp-gateway_settings',
			array(
				'enabled' => 'yes',
				'ppcp'    => '',
			)
		);
		$this->set_stale_cached_yes();
		remove_all_actions( 'after_setup_theme' );
		$this->boot_container( array( new TransportBindingModule( new FakePlatformTransport() ) ) );
		do_action( 'after_setup_theme' );

		$gateways = apply_filters( 'woocommerce_payment_gateways', array() ); // phpcs:ignore WooCommerce.Commenting.CommentHooks.MissingHookComment

		$this->assertNotEmpty( array_filter( $gateways, static fn( $gateway ): bool => $gateway instanceof PayPalGateway ), 'The wallet\'s filters ran' );
		$this->assertCount( 0, array_filter( $gateways, static fn( $gateway ): bool => $gateway instanceof PWCGateway ), 'The wallet\'s own Pay with Crypto filter reads "no"' );
	}

	/**
	 * @testdox Should register the gateway on a first-party store whose wallet cache says "yes", as the wallet does.
	 */
	public function test_a_first_party_store_follows_the_wallets_cached_yes(): void {
		$this->set_first_party_connected();
		$this->set_stale_cached_yes();

		$status = $this->boot()->get( 'wcgateway.pwc-product-status' );

		$this->assertSame( PWCProductStatus::class, get_class( $status ) );
		$this->assertTrue( $status->is_active() );
		$this->assertTrue( isset( WC()->payment_gateways()->payment_gateways()[ PWCGateway::ID ] ), 'The gateway' );
	}
}
