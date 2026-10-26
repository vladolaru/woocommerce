<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\MultiCurrency\Compat;

use Automattic\WooCommerce\Internal\MultiCurrency\Compat\WooPaymentsExtensionFacadeLoader;
use Automattic\WooCommerce\Internal\MultiCurrency\Interfaces\LocalizationInterface;
use Automattic\WooCommerce\Internal\MultiCurrency\MultiCurrencyCurrency;
use Automattic\WooCommerce\Internal\MultiCurrency\MultiCurrencyRuntimeArbiter;
use Automattic\WooCommerce\Internal\MultiCurrency\MultiCurrencyState;
use Automattic\WooCommerce\Internal\MultiCurrency\Services\PriceConverter;
use Automattic\WooCommerce\Internal\MultiCurrency\Services\ServiceFactory;
use Automattic\WooCommerce\Internal\MultiCurrency\Services\StateBuilder;
use Automattic\WooCommerce\Internal\MultiCurrency\Services\StateBuilderFactory;
use Automattic\WooCommerce\Tests\Internal\MultiCurrency\ServiceFactories;
use WC_Unit_Test_Case;

require_once __DIR__ . '/Fixtures/multi-currency-facade-consumers.inc';

/**
 * Tests the native MultiCurrency legacy facade compatibility boundary.
 */
class WooPaymentsExtensionFacadeLoaderTest extends WC_Unit_Test_Case {

	/**
	 * Tear down test fixtures.
	 */
	public function tearDown(): void {
		$this->reset_container_replacements();

		parent::tearDown();
	}

	/**
	 * @testdox The core-owned facade preserves grounded extension calls and converts a price exactly once.
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_core_owned_facade_preserves_grounded_extension_calls(): void {
		$failing_price_converter = $this->createMock( PriceConverter::class );
		$failing_price_converter->method( 'get_price' )->willThrowException( new \RuntimeException( 'Projection failed.' ) );
		$this->create_loader( MultiCurrencyRuntimeArbiter::OWNER_BUILTIN, $failing_price_converter );
		$consumer = new MultiCurrencyFacadeConsumerFixture();

		$this->assertTrue( class_exists( 'WCPay\\MultiCurrency\\MultiCurrency', false ), 'Core ownership should declare the namespaced compatibility facade.' );
		$this->assertTrue( class_exists( 'WCPay\\MultiCurrency\\Compatibility', false ), 'Core ownership declares the compatibility facade with it.' );
		if ( ! class_exists( 'WCPay\\MultiCurrency\\MultiCurrency', false ) ) {
			return;
		}

		$facade_class   = new \ReflectionClass( 'WCPay\\MultiCurrency\\MultiCurrency' );
		$instance_type  = $facade_class->getMethod( 'instance' )->getReturnType();
		$get_price      = $facade_class->getMethod( 'get_price' );
		$get_price_type = $get_price->getReturnType();
		$this->assertInstanceOf( \ReflectionNamedType::class, $instance_type );
		$this->assertInstanceOf( \ReflectionNamedType::class, $get_price_type );
		if ( ! $instance_type instanceof \ReflectionNamedType || ! $get_price_type instanceof \ReflectionNamedType ) {
			return;
		}
		$this->assertContains( $instance_type->getName(), array( 'self', 'WCPay\\MultiCurrency\\MultiCurrency' ), 'The singleton return type is part of the approved facade contract.' );
		$this->assertCount( 2, $get_price->getParameters() );
		$this->assertFalse( $get_price->getParameters()[0]->hasType(), 'The legacy amount parameter must continue accepting numeric strings.' );
		$this->assertSame( 'string', (string) $get_price->getParameters()[1]->getType() );
		$this->assertSame( 'float', $get_price_type->getName() );

		$this->setExpectedDeprecated( 'WCPay\\MultiCurrency\\MultiCurrency::instance' );
		$this->setExpectedDeprecated( 'WCPay\\MultiCurrency\\MultiCurrency::get_price' );
		try {
			$consumer->get_woopayments_multicurrency_price( 10.0 );
			$this->fail( 'Expected the projection exception.' );
		} catch ( \RuntimeException $exception ) {
			$this->assertSame( 'Projection failed.', $exception->getMessage() );
		}

		$price_converter = $this->create_recording_price_converter( 2.0, 5.0 );
		$this->create_loader( MultiCurrencyRuntimeArbiter::OWNER_BUILTIN, $price_converter, $this->create_state_builder( $this->create_state() ) );

		$this->setExpectedDeprecated( 'WCPay\\MultiCurrency\\MultiCurrency::instance' );
		$first_instance = \WCPay\MultiCurrency\MultiCurrency::instance();
		$this->setExpectedDeprecated( 'WCPay\\MultiCurrency\\MultiCurrency::instance' );
		$this->assertSame( $first_instance, \WCPay\MultiCurrency\MultiCurrency::instance(), 'The compatibility instance should remain stable for the request.' );

		$this->setExpectedDeprecated( 'WCPay\\MultiCurrency\\MultiCurrency::get_price' );
		$this->assertSame( 20.0, $first_instance->get_price( 10.0, 'product' ) );

		$consumer = new MultiCurrencyFacadeConsumerFixture();
		$this->setExpectedDeprecated( 'WCPay\\MultiCurrency\\MultiCurrency::instance' );
		$this->setExpectedDeprecated( 'WCPay\\MultiCurrency\\MultiCurrency::get_selected_currency' );
		$this->setExpectedDeprecated( 'WCPay\\MultiCurrency\\MultiCurrency::get_default_currency' );
		$this->setExpectedDeprecated( 'WCPay\\MultiCurrency\\MultiCurrency::get_raw_conversion' );
		$this->assertSame( 5.0, $consumer->get_table_rate_base_currency_price( 10.0 ), 'The guarded Table Rate call shape should reach native selected/default state and raw conversion without an undefined method.' );
		$this->setExpectedDeprecated( 'WCPay\\MultiCurrency\\MultiCurrency::get_raw_conversion' );
		$this->assertSame( 20.0, $first_instance->get_raw_conversion( 10.0, 'EUR' ), 'Omitting the source currency should preserve the plugin facade default of the store currency.' );

		$this->setExpectedDeprecated( 'WCPay\\MultiCurrency\\MultiCurrency::instance' );
		$this->setExpectedDeprecated( 'WCPay\\MultiCurrency\\MultiCurrency::get_price' );
		$facade_converted_amount = $consumer->get_woopayments_multicurrency_price( 10.0 );

		$this->assertSame( 20.0, $facade_converted_amount, 'The Deposits facade call should apply the selected-currency rate once.' );
	}

	/**
	 * @testdox The facade converts a subscription cart's disabled currency back to the store currency for shipping price bands.
	 *
	 * A renewal or resubscribe cart keeps its subscription's currency after the merchant disabled it. Table Rate Shipping
	 * compares the cart subtotal, converted back to the store currency, with its price bands; when that conversion throws, it
	 * compares the unconverted subtotal. GBP's manual rate 0.73 is kept, so a £73 subtotal is $100.
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_converts_a_disabled_subscription_currency_for_shipping_price_bands(): void {
		update_option( 'woocommerce_currency', 'USD' );
		update_option( 'wcpay_multi_currency_enabled_currencies', array( 'JPY' ) );
		update_option( 'wcpay_multi_currency_exchange_rate_jpy', 'manual' );
		update_option( 'wcpay_multi_currency_manual_rate_jpy', '150' );
		update_option( 'wcpay_multi_currency_exchange_rate_gbp', 'manual' );
		update_option( 'wcpay_multi_currency_manual_rate_gbp', '0.73' );
		// What the Subscriptions compatibility returns for a GBP subscription's cart.
		add_filter(
			'wcpay_multi_currency_override_selected_currency',
			static function () {
				StateBuilder::set_order_currency_override( 'GBP' );

				return 'GBP';
			}
		);
		$state_builder = wc_get_container()->get( StateBuilderFactory::class )->get_state_builder();
		$this->create_loader( MultiCurrencyRuntimeArbiter::OWNER_BUILTIN, wc_get_container()->get( ServiceFactory::class )->create_price_converter(), $state_builder );
		$this->setExpectedDeprecated( 'WCPay\\MultiCurrency\\MultiCurrency::instance' );
		$this->setExpectedDeprecated( 'WCPay\\MultiCurrency\\MultiCurrency::get_selected_currency' );
		$this->setExpectedDeprecated( 'WCPay\\MultiCurrency\\MultiCurrency::get_default_currency' );
		$this->setExpectedDeprecated( 'WCPay\\MultiCurrency\\MultiCurrency::get_raw_conversion' );

		$subtotal = ( new MultiCurrencyFacadeConsumerFixture() )->get_table_rate_base_currency_price( 73.0 );

		$this->assertEqualsWithDelta( 100.0, $subtotal, 0.000001 );
		$this->assertSame( 'from $90', $subtotal >= 90.0 ? 'from $90' : 'below $90', 'The subtotal falls in the store-currency price band it is worth.' );
	}

	/**
	 * @testdox The facade stays absent outside core-owned MultiCurrency.
	 */
	public function test_facade_stays_absent_outside_core_ownership(): void {
		// The case that declares the facade runs in its own process; an in-process declaration would show here first.
		$this->assertFalse( class_exists( 'WCPay\\MultiCurrency\\MultiCurrency', false ), 'Precondition: an earlier in-process test declared the facade.' );

		foreach ( array( MultiCurrencyRuntimeArbiter::OWNER_EXTENSION, MultiCurrencyRuntimeArbiter::OWNER_NONE ) as $owner ) {
			// Only the declaration loader: the shared runtime loader keeps its projection service across in-process tests.
			$declaration_loader = new WooPaymentsExtensionFacadeLoader();
			$declaration_loader->init( $this->create_arbiter( $owner ) );
			$declaration_loader->register();
			$this->assertFalse( class_exists( 'WCPay\\MultiCurrency\\MultiCurrency', false ), 'The plugin and ownerless runtimes must retain authority over the plugin namespace.' );
			$this->assertFalse( class_exists( 'WCPay\\MultiCurrency\\Compatibility', false ), 'The compatibility facade stays absent with the facade.' );
		}
	}

	/**
	 * Create and register the facade loader under a controlled ownership decision.
	 *
	 * The facade reads its services from the container, so the container hands out factories that return the given
	 * services.
	 *
	 * @param string            $owner           Runtime owner.
	 * @param PriceConverter    $price_converter Price projection service.
	 * @param StateBuilder|null $state_builder   State builder; the request's shared builder by default.
	 */
	private function create_loader( string $owner, PriceConverter $price_converter, ?StateBuilder $state_builder = null ): void {
		$declaration_loader = new WooPaymentsExtensionFacadeLoader();
		$declaration_loader->init( $this->create_arbiter( $owner ) );
		$declaration_loader->register();

		wc_get_container()->replace( ServiceFactory::class, ServiceFactories::with( $this, array( 'create_price_converter' => $price_converter ) ) );
		if ( null !== $state_builder ) {
			wc_get_container()->replace( StateBuilderFactory::class, ServiceFactories::state_builder( $this, $state_builder ) );
		}
	}

	/**
	 * Create a runtime arbiter test double.
	 *
	 * @param string $owner Runtime owner.
	 * @return MultiCurrencyRuntimeArbiter
	 */
	private function create_arbiter( string $owner ): MultiCurrencyRuntimeArbiter {
		return new class( $owner ) extends MultiCurrencyRuntimeArbiter {
			/** @var string */
			private string $owner;

			/**
			 * Constructor.
			 *
			 * @param string $owner Runtime owner.
			 */
			public function __construct( string $owner ) {
				$this->owner = $owner;
			}

			/**
			 * Get the runtime owner.
			 *
			 * @return string
			 */
			public function get_runtime_owner(): string {
				return $this->owner;
			}
		};
	}

	/**
	 * Create a recording projection service.
	 *
	 * @param float $price_rate           Product price conversion rate.
	 * @param float $raw_conversion_value Raw conversion result.
	 * @return PriceConverter
	 */
	private function create_recording_price_converter( float $price_rate, float $raw_conversion_value = 0.0 ): PriceConverter {
		$service = $this->createMock( PriceConverter::class );
		$service->method( 'get_price' )->willReturnCallback(
			static function ( $amount, string $type ) use ( $price_rate ): float {
				return 'product' === $type ? (float) $amount * $price_rate : (float) $amount;
			}
		);
		$service->method( 'get_raw_conversion' )->willReturnCallback(
			static function ( float $amount, string $to_currency, string $from_currency ) use ( $raw_conversion_value ): float {
				if ( 'EUR' === $to_currency && 'USD' === $from_currency ) {
					return $amount * 2.0;
				}

				return $raw_conversion_value;
			}
		);

		return $service;
	}

	/**
	 * Create deterministic USD/EUR state.
	 *
	 * @return MultiCurrencyState
	 */
	private function create_state(): MultiCurrencyState {
		$localization = $this->create_localization();
		$usd          = new MultiCurrencyCurrency( $localization, 'USD', 1.0, true );
		$eur          = new MultiCurrencyCurrency( $localization, 'EUR', 2.0, false );

		return new MultiCurrencyState(
			array(
				'USD' => $usd,
				'EUR' => $eur,
			),
			array(
				'USD' => $usd,
				'EUR' => $eur,
			),
			$usd,
			$eur
		);
	}

	/**
	 * Create a state builder test double.
	 *
	 * @param MultiCurrencyState $state State snapshot.
	 * @return StateBuilder
	 */
	private function create_state_builder( MultiCurrencyState $state ): StateBuilder {
		return new class( $state ) extends StateBuilder {
			/** @var MultiCurrencyState */
			private MultiCurrencyState $state;

			/**
			 * Constructor.
			 *
			 * @param MultiCurrencyState $state State snapshot.
			 */
			public function __construct( MultiCurrencyState $state ) {
				$this->state = $state;
			}

			/**
			 * Build the state snapshot.
			 *
			 * @return MultiCurrencyState
			 */
			public function build(): MultiCurrencyState {
				return $this->state;
			}
		};
	}

	/**
	 * Create a minimal localization boundary.
	 *
	 * @return LocalizationInterface
	 */
	private function create_localization(): LocalizationInterface {
		return new class() implements LocalizationInterface {
			/**
			 * Get currency formatting.
			 *
			 * @param string $currency_code Currency code.
			 * @return array<string,mixed>
			 */
			public function get_currency_format( $currency_code ): array {
				unset( $currency_code );

				return array(
					'currency_pos' => 'left',
					'thousand_sep' => ',',
					'decimal_sep'  => '.',
					'num_decimals' => 2,
				);
			}

			/**
			 * Get country locale data.
			 *
			 * @param string $country Country code.
			 * @return array<string,mixed>
			 */
			public function get_country_locale_data( $country ): array {
				unset( $country );

				return array();
			}
		};
	}
}
