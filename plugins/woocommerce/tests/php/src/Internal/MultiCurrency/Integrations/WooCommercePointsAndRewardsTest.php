<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\MultiCurrency\Integrations;

use Automattic\WooCommerce\Internal\MultiCurrency\Interfaces\LocalizationInterface;
use Automattic\WooCommerce\Internal\MultiCurrency\MultiCurrencyCurrency;
use Automattic\WooCommerce\Internal\MultiCurrency\Integrations\WooCommercePointsAndRewards;
use Automattic\WooCommerce\Internal\MultiCurrency\MultiCurrencyRuntimeArbiter;
use Automattic\WooCommerce\Internal\MultiCurrency\MultiCurrencyState;
use Automattic\WooCommerce\Internal\MultiCurrency\Services\StateBuilder;
use WC_Unit_Test_Case;
use Automattic\WooCommerce\Tests\Internal\MultiCurrency\CallStack;
use Automattic\WooCommerce\Tests\Internal\MultiCurrency\RegisteredHooks;
use Automattic\WooCommerce\Tests\Internal\MultiCurrency\RequestSignals;
use Automattic\WooCommerce\Tests\Internal\MultiCurrency\ServiceFactories;

/**
 * Tests for the WooCommercePointsAndRewards class.
 */
class WooCommercePointsAndRewardsTest extends WC_Unit_Test_Case {

	/**
	 * Hooks touched by the Points and Rewards compatibility controller.
	 *
	 * @var string[]
	 */
	private array $hooks = array(
		'plugins_loaded',
		'option_wc_points_rewards_earn_points_ratio',
		'option_wc_points_rewards_redeem_points_ratio',
	);

	/**
	 * Tear down test fixtures.
	 */
	public function tearDown(): void {
		foreach ( $this->hooks as $hook ) {
			remove_all_filters( $hook );
		}

		parent::tearDown();
	}

	/**
	 * @testdox Should register exactly the Points and Rewards hooks the request allows, once: $_dataName.
	 * @dataProvider provide_points_rewards_requests
	 *
	 * @param string                            $owner                    Runtime owner.
	 * @param bool                              $points_rewards_available Whether Points and Rewards is loaded.
	 * @param bool                              $is_admin                 Whether this is an admin request.
	 * @param array<string,array<string,int[]>> $expected                 Every hook the controller registers.
	 */
	public function test_registers_the_points_rewards_hooks_the_request_allows( string $owner, bool $points_rewards_available, bool $is_admin, array $expected ): void {
		$sut = $this->create_controller( $owner, $points_rewards_available, $is_admin );

		$sut->register();
		$sut->register();

		$this->assertSame( $expected, RegisteredHooks::of( $sut ) );
	}

	/**
	 * Requests and the Points and Rewards hooks each registers: both on the storefront under core, none otherwise.
	 *
	 * @return array<string,array{0:string,1:bool,2:bool,3:array<string,array<string,int[]>>}>
	 */
	public function provide_points_rewards_requests(): array {
		return array(
			'storefront under core'         => array(
				MultiCurrencyRuntimeArbiter::OWNER_BUILTIN,
				true,
				false,
				array(
					'option_wc_points_rewards_earn_points_ratio'   => array( 'convert_points_ratio' => array( 50, 1 ) ),
					'option_wc_points_rewards_redeem_points_ratio' => array( 'convert_points_ratio' => array( 50, 1 ) ),
				),
			),
			'the extension owns'            => array( MultiCurrencyRuntimeArbiter::OWNER_EXTENSION, true, false, array() ),
			'Points and Rewards not loaded' => array( MultiCurrencyRuntimeArbiter::OWNER_BUILTIN, false, false, array() ),
			'an admin request'              => array( MultiCurrencyRuntimeArbiter::OWNER_BUILTIN, true, true, array() ),
		);
	}

	/**
	 * @testdox Should defer Points and Rewards hook registration until plugins load.
	 */
	public function test_defers_points_rewards_registration_until_plugins_load(): void {
		$sut = $this->create_controller( MultiCurrencyRuntimeArbiter::OWNER_BUILTIN, false, false, false );

		$sut->register();

		$this->assertFalse( has_filter( 'option_wc_points_rewards_earn_points_ratio', array( $sut, 'convert_points_ratio' ) ) );
		$this->assertSame( 20, has_action( 'plugins_loaded', array( $sut, 'register_points_rewards_filters' ) ) );

		$sut->set_points_rewards_available( true );
		$sut->register_points_rewards_filters();

		$this->assertSame( 50, has_filter( 'option_wc_points_rewards_earn_points_ratio', array( $sut, 'convert_points_ratio' ) ) );
		$this->assertSame( 50, has_filter( 'option_wc_points_rewards_redeem_points_ratio', array( $sut, 'convert_points_ratio' ) ) );
	}

	/**
	 * @testdox Should convert Points and Rewards ratios by selected currency rate.
	 */
	public function test_converts_points_rewards_ratios_by_selected_currency_rate(): void {
		$sut = $this->create_controller();

		$this->assertSame( '10:1.6', $sut->convert_points_ratio( '10:2' ) );
		$this->assertSame( '0:0', $sut->convert_points_ratio( '' ) );
		$this->assertSame( '0:0', $sut->convert_points_ratio( 'bad' ) );
	}

	/**
	 * @testdox Should preserve ratios when selected currency is default currency.
	 */
	public function test_preserves_ratios_when_selected_currency_is_default_currency(): void {
		$sut = $this->create_controller( MultiCurrencyRuntimeArbiter::OWNER_BUILTIN, true, false, true, true );

		$this->assertSame( '10:2', $sut->convert_points_ratio( '10:2' ) );
	}

	/**
	 * @testdox Should preserve ratios while Points and Rewards discount data is calculated.
	 */
	public function test_preserves_ratios_while_discount_data_is_calculated(): void {
		$sut = $this->create_controller();
		$this->assertSame( '10:2', CallStack::run( array( 'WC_Points_Rewards_Discount->get_discount_data' ), fn() => $sut->convert_points_ratio( '10:2' ) ) );
	}

	/**
	 * Create a Points and Rewards compatibility controller with deterministic runtime context.
	 *
	 * @param string $owner                    Runtime owner.
	 * @param bool   $points_rewards_available Whether Points and Rewards runtime is available.
	 * @param bool   $is_admin                 Whether this is an admin request.
	 * @param bool   $plugins_loaded           Whether plugins have loaded.
	 * @param bool   $selected_is_default      Whether the selected currency is the store currency.
	 * @return WooCommercePointsAndRewards
	 */
	private function create_controller(
		string $owner = MultiCurrencyRuntimeArbiter::OWNER_BUILTIN,
		bool $points_rewards_available = true,
		bool $is_admin = false,
		bool $plugins_loaded = true,
		bool $selected_is_default = false
	): WooCommercePointsAndRewards {
		$controller = new class( $points_rewards_available, $is_admin, $plugins_loaded ) extends WooCommercePointsAndRewards {
			/**
			 * Whether Points and Rewards runtime is available.
			 *
			 * @var bool
			 */
			private bool $points_rewards_available;

			/**
			 * Whether this is an admin request.
			 *
			 * @var bool
			 */
			private bool $is_admin;

			/**
			 * Whether plugins have loaded.
			 *
			 * @var bool
			 */
			private bool $plugins_loaded;

			/**
			 * Constructor.
			 *
			 * @param bool $points_rewards_available Whether Points and Rewards runtime is available.
			 * @param bool $is_admin                 Whether this is an admin request.
			 * @param bool $plugins_loaded           Whether plugins have loaded.
			 */
			public function __construct( bool $points_rewards_available, bool $is_admin, bool $plugins_loaded ) {
				$this->points_rewards_available = $points_rewards_available;
				$this->is_admin                 = $is_admin;
				$this->plugins_loaded           = $plugins_loaded;
			}

			/**
			 * Set whether Points and Rewards runtime is available.
			 *
			 * @param bool $points_rewards_available Whether Points and Rewards runtime is available.
			 */
			public function set_points_rewards_available( bool $points_rewards_available ): void {
				$this->points_rewards_available = $points_rewards_available;
			}

			/**
			 * Check if Points and Rewards runtime is available.
			 *
			 * @return bool
			 */
			protected function is_points_rewards_runtime_available(): bool {
				return $this->points_rewards_available;
			}

			/**
			 * The request signals this double's case sets.
			 *
			 * @return array<string,bool>
			 */
			private function request_signals(): array {
				return array(
					'admin'          => $this->is_admin,
					'plugins_loaded' => $this->plugins_loaded,
				);
			}

			/** Register with this case's request signals set. */
			public function register() {
				RequestSignals::run( $this->request_signals(), fn() => parent::register() );
			}
		};

		$controller->init(
			$this->create_arbiter( $owner ),
			ServiceFactories::state_builder( $this, $this->create_state_builder( $this->create_state( $selected_is_default ) ) ),
			CallStack::request_context()
		);

		return $controller;
	}

	/**
	 * Create a runtime arbiter test double.
	 *
	 * @param string $owner Runtime owner.
	 * @return MultiCurrencyRuntimeArbiter
	 */
	private function create_arbiter( string $owner ): MultiCurrencyRuntimeArbiter {
		return new class( $owner ) extends MultiCurrencyRuntimeArbiter {
			/**
			 * Runtime owner.
			 *
			 * @var string
			 */
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
			 * Get the multi-currency runtime owner.
			 *
			 * @return string
			 */
			public function get_runtime_owner(): string {
				return $this->owner;
			}
		};
	}

	/**
	 * Create a state builder test double.
	 *
	 * @param MultiCurrencyState $state Multi-currency state.
	 * @return StateBuilder
	 */
	private function create_state_builder( MultiCurrencyState $state ): StateBuilder {
		return new class( $state ) extends StateBuilder {
			/**
			 * Multi-currency state.
			 *
			 * @var MultiCurrencyState
			 */
			private MultiCurrencyState $state;

			/**
			 * Constructor.
			 *
			 * @param MultiCurrencyState $state Multi-currency state.
			 */
			public function __construct( MultiCurrencyState $state ) {
				$this->state = $state;
			}

			/**
			 * Build a multi-currency state snapshot.
			 *
			 * @return MultiCurrencyState
			 */
			public function build(): MultiCurrencyState {
				return $this->state;
			}
		};
	}

	/**
	 * Create multi-currency state.
	 *
	 * @param bool $selected_is_default Whether selected currency should be default currency.
	 * @return MultiCurrencyState
	 */
	private function create_state( bool $selected_is_default = false ): MultiCurrencyState {
		$usd = $this->create_currency( 'USD', 1.0, true );
		$gbp = $this->create_currency( 'GBP', 0.8, false );

		$enabled = array(
			'USD' => $usd,
			'GBP' => $gbp,
		);

		return new MultiCurrencyState( $enabled, $enabled, $usd, $selected_is_default ? $usd : $gbp );
	}

	/**
	 * Create a currency.
	 *
	 * @param string $code       Currency code.
	 * @param float  $rate       Currency rate.
	 * @param bool   $is_default Whether this is the default currency.
	 * @return MultiCurrencyCurrency
	 */
	private function create_currency( string $code, float $rate, bool $is_default ): MultiCurrencyCurrency {
		return new MultiCurrencyCurrency( $this->create_localization(), $code, $rate, $is_default );
	}

	/**
	 * Create a localization test double.
	 *
	 * @return LocalizationInterface
	 */
	private function create_localization(): LocalizationInterface {
		return new class() implements LocalizationInterface {
			/**
			 * Get a currency format.
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
			 * Get locale data for a country.
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
