<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\MultiCurrency;

use Automattic\WooCommerce\Internal\MultiCurrency\MultiCurrencyRuntimeArbiter;
use Automattic\WooCommerce\Internal\MultiCurrency\MultiCurrencyTrackingController;
use Automattic\WooCommerce\Internal\MultiCurrency\Services\MultiCurrencyRuntimeServiceFactory;
use Automattic\WooCommerce\Internal\MultiCurrency\Services\MultiCurrencyTrackingProjectionService;
use WC_Unit_Test_Case;

/**
 * Tests for the MultiCurrencyTrackingController class.
 */
class MultiCurrencyTrackingControllerTest extends WC_Unit_Test_Case {

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
	public function test_does_not_register_tracker_data_when_plugin_owns_runtime(): void {
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
		$hpos_service   = $this->create_projection_service();
		$legacy_service = $this->create_projection_service();
		$hpos           = $this->create_controller( MultiCurrencyRuntimeArbiter::OWNER_BUILTIN, $hpos_service, static fn(): bool => true );
		$legacy         = $this->create_controller( MultiCurrencyRuntimeArbiter::OWNER_BUILTIN, $legacy_service, static fn(): bool => false );

		$result = $hpos->add_tracker_data( array( 'existing' => 'value' ) );
		$legacy->add_tracker_data( array() );

		$this->assertSame(
			array(
				'existing'             => 'value',
				'wcpay_multi_currency' => array( 'hpos' => true ),
			),
			$result
		);
		$this->assertSame( array( 'existing' => 'value' ), $hpos_service->last_data );
		$this->assertTrue( $hpos_service->last_is_hpos_enabled );
		$this->assertFalse( $legacy_service->last_is_hpos_enabled );
	}

	/**
	 * Create a tracking controller.
	 *
	 * @param string                                      $owner              Runtime owner.
	 * @param MultiCurrencyTrackingProjectionService|null $projection_service Projection service.
	 * @param callable|null                               $storage_resolver   Storage mode resolver.
	 * @return MultiCurrencyTrackingController
	 */
	private function create_controller(
		string $owner,
		?MultiCurrencyTrackingProjectionService $projection_service = null,
		?callable $storage_resolver = null
	): MultiCurrencyTrackingController {
		$controller = new MultiCurrencyTrackingController();
		$controller->init(
			$this->create_arbiter( $owner ),
			wc_get_container()->get( MultiCurrencyRuntimeServiceFactory::class )
		);

		if ( null !== $projection_service ) {
			$controller->set_tracking_projection_service( $projection_service );
		}

		if ( null !== $storage_resolver ) {
			$controller->set_hpos_enabled_resolver( $storage_resolver );
		}

		return $controller;
	}

	/**
	 * Create a projection service test double.
	 *
	 * @return MultiCurrencyTrackingProjectionService&object{last_data: array<string,mixed>, last_is_hpos_enabled: bool|null}
	 */
	private function create_projection_service(): MultiCurrencyTrackingProjectionService {
		return new class() extends MultiCurrencyTrackingProjectionService {
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
			public function project_tracker_data( array $data, bool $is_hpos_enabled ): array {
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
			public function should_core_register(): bool {
				return MultiCurrencyRuntimeArbiter::OWNER_BUILTIN === $this->owner;
			}
		};
	}
}
