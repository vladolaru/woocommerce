<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\MultiCurrency\Compat;

use Automattic\WooCommerce\Internal\MultiCurrency\Compat\LegacyMultiCurrencyFacadeLoader;
use Automattic\WooCommerce\Internal\MultiCurrency\Interfaces\MultiCurrencyLocalizationInterface;
use Automattic\WooCommerce\Internal\MultiCurrency\MultiCurrencyCurrency;
use Automattic\WooCommerce\Internal\MultiCurrency\MultiCurrencyDepositsCompatibilityController;
use Automattic\WooCommerce\Internal\MultiCurrency\MultiCurrencyRuntimeArbiter;
use Automattic\WooCommerce\Internal\MultiCurrency\MultiCurrencyState;
use Automattic\WooCommerce\Internal\MultiCurrency\Services\MultiCurrencyPriceProjectionService;
use Automattic\WooCommerce\Internal\MultiCurrency\Services\MultiCurrencyProjectionServiceFactory;
use Automattic\WooCommerce\Internal\MultiCurrency\Services\MultiCurrencyStateBuilder;
use WC_Unit_Test_Case;

require_once __DIR__ . '/Fixtures/legacy-multi-currency-consumers.inc';

/**
 * Tests the native MultiCurrency legacy facade compatibility boundary.
 */
class LegacyMultiCurrencyFacadeLoaderTest extends WC_Unit_Test_Case {

	/**
	 * @testdox The core-owned facade preserves grounded extension calls and converts Deposits exactly once.
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_core_owned_facade_preserves_grounded_extension_calls(): void {
		$this->assertTrue( class_exists( LegacyMultiCurrencyFacadeLoader::class ), 'The native runtime should provide a dedicated MultiCurrency facade loader.' );
		if ( ! class_exists( LegacyMultiCurrencyFacadeLoader::class ) ) {
			return;
		}

		$failed_projection = $this->createMock( MultiCurrencyPriceProjectionService::class );
		$failed_projection->method( 'get_price' )->willThrowException( new \RuntimeException( 'Projection failed.' ) );
		$loader   = $this->create_loader( MultiCurrencyRuntimeArbiter::OWNER_CORE, $failed_projection );
		$consumer = new LegacyMultiCurrencyConsumerFixture();

		$this->assertTrue( class_exists( 'WCPay\\MultiCurrency\\MultiCurrency', false ), 'Core ownership should declare the namespaced compatibility facade.' );
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
		$this->assertFalse( $loader->did_project_product_price(), 'A failed facade call must not suppress the native Deposits conversion fallback.' );

		$projection_service = $this->create_projection_service( 2.0, 5.0 );
		$loader->set_price_projection_service( $projection_service );
		$loader->set_state_builder( $this->create_state_builder( $this->create_state() ) );

		$this->setExpectedDeprecated( 'WCPay\\MultiCurrency\\MultiCurrency::instance' );
		$first_instance = \WCPay\MultiCurrency\MultiCurrency::instance();
		$this->setExpectedDeprecated( 'WCPay\\MultiCurrency\\MultiCurrency::instance' );
		$this->assertSame( $first_instance, \WCPay\MultiCurrency\MultiCurrency::instance(), 'The compatibility instance should remain stable for the request.' );

		$this->setExpectedDeprecated( 'WCPay\\MultiCurrency\\MultiCurrency::get_price' );
		$this->assertSame( 20.0, $first_instance->get_price( 10.0, 'product' ) );

		$consumer = new LegacyMultiCurrencyConsumerFixture();
		$this->setExpectedDeprecated( 'WCPay\\MultiCurrency\\MultiCurrency::instance' );
		$this->setExpectedDeprecated( 'WCPay\\MultiCurrency\\MultiCurrency::get_selected_currency' );
		$this->setExpectedDeprecated( 'WCPay\\MultiCurrency\\MultiCurrency::get_default_currency' );
		$this->setExpectedDeprecated( 'WCPay\\MultiCurrency\\MultiCurrency::get_raw_conversion' );
		$this->assertSame( 5.0, $consumer->get_table_rate_base_currency_price( 10.0 ), 'The guarded Table Rate call shape should reach native selected/default state and raw conversion without an undefined method.' );
		$this->setExpectedDeprecated( 'WCPay\\MultiCurrency\\MultiCurrency::get_raw_conversion' );
		$this->assertSame( 20.0, $first_instance->get_raw_conversion( 10.0, 'EUR' ), 'Omitting the source currency should preserve the plugin facade default of the store currency.' );

		$this->setExpectedDeprecated( 'WCPay\\MultiCurrency\\MultiCurrency::instance' );
		$this->setExpectedDeprecated( 'WCPay\\MultiCurrency\\MultiCurrency::get_price' );
		$facade_projected_amount = $consumer->get_woopayments_multicurrency_price( 10.0 );

		$controller = new MultiCurrencyDepositsCompatibilityController();
		$controller->init( $this->create_arbiter( MultiCurrencyRuntimeArbiter::OWNER_CORE ), $this->createMock( MultiCurrencyProjectionServiceFactory::class ) );
		$controller->set_price_projection_service( $projection_service );
		$cart_contents = $controller->modify_cart_item_deposit_amounts(
			array(
				'deposit' => array(
					'is_deposit'     => true,
					'deposit_amount' => $facade_projected_amount,
				),
			)
		);

		$this->assertSame( 20.0, $facade_projected_amount, 'The Deposits facade call should apply the selected-currency rate.' );
		$this->assertSame( 20.0, $cart_contents['deposit']['deposit_amount'], 'Native Deposits compatibility must preserve the facade-projected amount instead of applying the rate twice.' );
	}

	/**
	 * @testdox The facade stays absent outside core-owned MultiCurrency.
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_facade_stays_absent_outside_core_ownership(): void {
		$this->assertTrue( class_exists( LegacyMultiCurrencyFacadeLoader::class ), 'The native runtime should provide a dedicated MultiCurrency facade loader.' );
		if ( ! class_exists( LegacyMultiCurrencyFacadeLoader::class ) ) {
			return;
		}

		foreach ( array( MultiCurrencyRuntimeArbiter::OWNER_PLUGIN, MultiCurrencyRuntimeArbiter::OWNER_NONE ) as $owner ) {
			$this->create_loader( $owner, $this->create_projection_service( 2.0 ) );
			$this->assertFalse( class_exists( 'WCPay\\MultiCurrency\\MultiCurrency', false ), 'The plugin and ownerless runtimes must retain authority over the plugin namespace.' );
		}
	}

	/**
	 * Create and register the facade loader under a controlled ownership decision.
	 *
	 * @param string                              $owner              Runtime owner.
	 * @param MultiCurrencyPriceProjectionService $projection_service Price projection service.
	 * @return LegacyMultiCurrencyFacadeLoader
	 */
	private function create_loader( string $owner, MultiCurrencyPriceProjectionService $projection_service ): LegacyMultiCurrencyFacadeLoader {
		$declaration_loader = new LegacyMultiCurrencyFacadeLoader();
		$declaration_loader->init( $this->create_arbiter( $owner ) );
		$declaration_loader->register();

		$runtime_loader = wc_get_container()->get( LegacyMultiCurrencyFacadeLoader::class );
		$runtime_loader->set_price_projection_service( $projection_service );

		return $runtime_loader;
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
	 * @return MultiCurrencyPriceProjectionService
	 */
	private function create_projection_service( float $price_rate, float $raw_conversion_value = 0.0 ): MultiCurrencyPriceProjectionService {
		$service = $this->createMock( MultiCurrencyPriceProjectionService::class );
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
	 * @return MultiCurrencyStateBuilder
	 */
	private function create_state_builder( MultiCurrencyState $state ): MultiCurrencyStateBuilder {
		return new class( $state ) extends MultiCurrencyStateBuilder {
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
	 * @return MultiCurrencyLocalizationInterface
	 */
	private function create_localization(): MultiCurrencyLocalizationInterface {
		return new class() implements MultiCurrencyLocalizationInterface {
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
