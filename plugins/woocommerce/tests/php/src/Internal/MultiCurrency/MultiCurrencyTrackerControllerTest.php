<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\MultiCurrency;

use Automattic\WooCommerce\Internal\DataStores\Orders\CustomOrdersTableController;
use Automattic\WooCommerce\Internal\MultiCurrency\MultiCurrencyRuntimeArbiter;
use Automattic\WooCommerce\Internal\MultiCurrency\MultiCurrencyTrackerController;
use Automattic\WooCommerce\Internal\MultiCurrency\Services\MultiCurrencyTrackerDataBuilder;
use WC_Unit_Test_Case;

/**
 * Tests for the MultiCurrencyTrackerController class.
 */
class MultiCurrencyTrackerControllerTest extends WC_Unit_Test_Case {

	private const TRACKER_HOOK = 'woocommerce_tracker_data';

	/**
	 * Tear down test fixtures.
	 */
	public function tear_down(): void {
		remove_all_filters( self::TRACKER_HOOK );

		parent::tear_down();
	}

	/**
	 * @testdox Should not register tracker data when plugin owns runtime.
	 */
	public function test_does_not_register_tracker_data_when_extension_owns_runtime(): void {
		$sut = $this->create_controller( MultiCurrencyRuntimeArbiter::OWNER_EXTENSION );

		$sut->register();

		$this->assertFalse( has_filter( self::TRACKER_HOOK, array( $sut, 'add_tracker_data' ) ) );
	}

	/**
	 * @testdox Should register tracker data once when core owns runtime.
	 */
	public function test_registers_tracker_data_once_when_core_owns_runtime(): void {
		$sut = $this->create_controller( MultiCurrencyRuntimeArbiter::OWNER_BUILTIN );

		$sut->register();
		$sut->register();

		$this->assertSame( 50, has_filter( self::TRACKER_HOOK, array( $sut, 'add_tracker_data' ) ) );
	}

	/**
	 * @testdox Should hand the tracker data and the resolved storage mode to the tracking service and return its result.
	 */
	public function test_passes_tracker_data_and_storage_mode_to_the_tracking_service(): void {
		$hpos_service  = $this->create_tracker_data_builder();
		$posts_service = $this->create_tracker_data_builder();
		$hpos          = $this->create_controller( MultiCurrencyRuntimeArbiter::OWNER_BUILTIN, $hpos_service );
		$posts         = $this->create_controller( MultiCurrencyRuntimeArbiter::OWNER_BUILTIN, $posts_service );
		$hpos_enabled  = true;
		add_filter(
			'pre_option_' . CustomOrdersTableController::CUSTOM_ORDERS_TABLE_USAGE_ENABLED_OPTION,
			static function () use ( &$hpos_enabled ): string {
				return $hpos_enabled ? 'yes' : 'no';
			}
		);

		$result       = $hpos->add_tracker_data( array( 'existing' => 'value' ) );
		$hpos_enabled = false;
		$posts->add_tracker_data( array() );

		$this->assertSame(
			array(
				'existing'             => 'value',
				'wcpay_multi_currency' => array( 'hpos' => true ),
			),
			$result
		);
		$this->assertSame( array( 'existing' => 'value' ), $hpos_service->last_data );
		$this->assertTrue( $hpos_service->last_is_hpos_enabled );
		$this->assertFalse( $posts_service->last_is_hpos_enabled );
	}

	/**
	 * Create a tracking controller.
	 *
	 * @param string                               $owner              Runtime owner.
	 * @param MultiCurrencyTrackerDataBuilder|null $tracker_data_builder Projection service.
	 * @return MultiCurrencyTrackerController
	 */
	private function create_controller(
		string $owner,
		?MultiCurrencyTrackerDataBuilder $tracker_data_builder = null
	): MultiCurrencyTrackerController {
		$controller = new MultiCurrencyTrackerController();
		$controller->init(
			$this->create_arbiter( $owner ),
			ServiceFactories::with( $this, array( 'create_tracker_data_builder' => $tracker_data_builder ) )
		);

		return $controller;
	}

	/**
	 * Create a projection service test double.
	 *
	 * @return MultiCurrencyTrackerDataBuilder&object{last_data: array<string,mixed>, last_is_hpos_enabled: bool|null}
	 */
	private function create_tracker_data_builder(): MultiCurrencyTrackerDataBuilder {
		return new class() extends MultiCurrencyTrackerDataBuilder {
			/**
			 * Last tracker data received by the projection.
			 *
			 * @var array<string,mixed>
			 */
			public array $last_data = array();

			/**
			 * Last storage mode received by the projection.
			 *
			 * @var bool|null
			 */
			public ?bool $last_is_hpos_enabled = null;

			/**
			 * Constructor.
			 */
			public function __construct() {}

			/**
			 * Project multi-currency tracker data.
			 *
			 * @param array<string,mixed> $data            Existing tracker data.
			 * @param bool                $is_hpos_enabled Whether HPOS order storage is enabled.
			 * @return array<string,mixed>
			 */
			public function build( array $data, bool $is_hpos_enabled ): array {
				$this->last_data            = $data;
				$this->last_is_hpos_enabled = $is_hpos_enabled;

				$data['wcpay_multi_currency'] = array( 'hpos' => $is_hpos_enabled );

				return $data;
			}
		};
	}

	/**
	 * Create a static multi-currency runtime arbiter.
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
			 * Get the multi-currency runtime owner for the current site.
			 *
			 * @return string
			 */
			public function get_runtime_owner(): string {
				return $this->owner;
			}

			/**
			 * Tell whether core multi-currency may register hooks.
			 *
			 * @return bool
			 */
			public function is_builtin_owner(): bool {
				return MultiCurrencyRuntimeArbiter::OWNER_BUILTIN === $this->owner;
			}
		};
	}
}
