<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\MultiCurrency;

use Automattic\WooCommerce\Internal\MultiCurrency\MultiCurrencyRuntimeArbiter;
use Automattic\WooCommerce\Internal\MultiCurrency\MultiCurrencyStoreCurrencyLifecycleController;
use Automattic\WooCommerce\Internal\MultiCurrency\Services\StoreCurrencyLifecycleService;
use WC_Unit_Test_Case;

/**
 * Tests for the MultiCurrencyStoreCurrencyLifecycleController class.
 */
class MultiCurrencyStoreCurrencyLifecycleControllerTest extends WC_Unit_Test_Case {

	/**
	 * Tear down test fixtures.
	 */
	public function tearDown(): void {
		remove_all_filters( 'init' );

		parent::tearDown();
	}

	/**
	 * @testdox Should register exactly the store currency init hook the owner allows, once: $_dataName.
	 * @dataProvider provide_init_hook_owners
	 *
	 * @param string                            $owner    Runtime owner.
	 * @param array<string,array<string,int[]>> $expected Every hook the controller registers.
	 */
	public function test_registers_the_init_hook_its_owner_allows( string $owner, array $expected ): void {
		$sut = $this->create_controller( $owner, $this->create_lifecycle_service() );

		$sut->register();
		$sut->register();

		$this->assertSame( $expected, RegisteredHooks::of( $sut ) );
	}

	/**
	 * Owners and the hooks each registers: the init hook under core, nothing otherwise.
	 *
	 * @return array<string,array<int,mixed>>
	 */
	public function provide_init_hook_owners(): array {
		return array(
			'core owns'          => array(
				MultiCurrencyRuntimeArbiter::OWNER_BUILTIN,
				array(
					'init' => array( 'handle_init' => array( 10, 1 ) ),
				),
			),
			'the extension owns' => array( MultiCurrencyRuntimeArbiter::OWNER_EXTENSION, array() ),
			'nobody owns'        => array( MultiCurrencyRuntimeArbiter::OWNER_NONE, array() ),
		);
	}

	/**
	 * @testdox Should synchronize store currency on init.
	 */
	public function test_handle_init_synchronizes_store_currency(): void {
		$service = $this->create_lifecycle_service();
		$sut     = $this->create_controller( MultiCurrencyRuntimeArbiter::OWNER_BUILTIN, $service );

		$sut->handle_init();

		$this->assertSame( 1, $service->calls );
	}

	/**
	 * Create a store currency lifecycle controller.
	 *
	 * @param string                        $owner   Runtime owner.
	 * @param StoreCurrencyLifecycleService $service Lifecycle service.
	 * @return MultiCurrencyStoreCurrencyLifecycleController
	 */
	private function create_controller(
		string $owner,
		StoreCurrencyLifecycleService $service
	): MultiCurrencyStoreCurrencyLifecycleController {
		$controller = new MultiCurrencyStoreCurrencyLifecycleController();
		$controller->init(
			$this->create_arbiter( $owner ),
			ServiceFactories::with( $this, array( 'create_store_currency_lifecycle_service' => $service ) )
		);

		return $controller;
	}

	/**
	 * Create a lifecycle service test double.
	 *
	 * @return StoreCurrencyLifecycleService
	 */
	private function create_lifecycle_service(): StoreCurrencyLifecycleService {
		return new class() extends StoreCurrencyLifecycleService {
			/**
			 * Call count.
			 *
			 * @var int
			 */
			public int $calls = 0;

			/**
			 * Constructor.
			 */
			public function __construct() {}

			/**
			 * Synchronize store currency.
			 *
			 * @return bool
			 */
			public function synchronize_store_currency(): bool {
				++$this->calls;

				return true;
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
