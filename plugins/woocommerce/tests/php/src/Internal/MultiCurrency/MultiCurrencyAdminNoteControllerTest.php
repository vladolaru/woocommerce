<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\MultiCurrency;

use Automattic\WooCommerce\Admin\Notes\Note;
use Automattic\WooCommerce\Internal\MultiCurrency\Interfaces\CurrencyRateProvider;
use Automattic\WooCommerce\Internal\MultiCurrency\MultiCurrencyAdminNoteController;
use Automattic\WooCommerce\Internal\MultiCurrency\MultiCurrencyRuntimeArbiter;
use WC_Unit_Test_Case;

/**
 * Tests for the MultiCurrencyAdminNoteController class.
 */
class MultiCurrencyAdminNoteControllerTest extends WC_Unit_Test_Case {

	private const ADMIN_INIT = 'admin_init';

	/**
	 * Tear down test fixtures.
	 */
	public function tear_down(): void {
		remove_all_filters( self::ADMIN_INIT );

		parent::tear_down();
	}

	/**
	 * @testdox Should not register admin note hook when plugin owns runtime.
	 */
	public function test_does_not_register_admin_note_hook_when_plugin_owns_runtime(): void {
		$sut = $this->create_controller( MultiCurrencyRuntimeArbiter::OWNER_EXTENSION, true );

		$sut->register();

		$this->assertFalse( has_action( self::ADMIN_INIT, array( $sut, 'handle_admin_init' ) ) );
	}

	/**
	 * @testdox Should not register admin note hook outside admin requests.
	 */
	public function test_does_not_register_admin_note_hook_outside_admin_requests(): void {
		$sut = $this->create_controller( MultiCurrencyRuntimeArbiter::OWNER_BUILTIN, false );

		$sut->register();

		$this->assertFalse( has_action( self::ADMIN_INIT, array( $sut, 'handle_admin_init' ) ) );
	}

	/**
	 * @testdox Should register admin note hook once when core owns runtime in admin.
	 */
	public function test_registers_admin_note_hook_once_when_core_owns_runtime_in_admin(): void {
		$sut = $this->create_controller( MultiCurrencyRuntimeArbiter::OWNER_BUILTIN, true );

		$sut->register();
		$sut->register();

		$this->assertSame( 10, has_action( self::ADMIN_INIT, array( $sut, 'handle_admin_init' ) ) );
	}

	/**
	 * @testdox Should not save admin note when add-note blockers are present.
	 *
	 * @dataProvider add_note_blocker_provider
	 *
	 * @param bool $is_ajax            Whether the request is Ajax.
	 * @param bool $provider_connected Whether the provider is connected.
	 * @param bool $can_be_added       Whether the note can be added.
	 */
	public function test_does_not_save_admin_note_when_blockers_are_present(
		bool $is_ajax,
		bool $provider_connected,
		bool $can_be_added
	): void {
		$saved_notes = array();
		$sut         = $this->create_controller( MultiCurrencyRuntimeArbiter::OWNER_BUILTIN, true );
		$sut->set_ajax_request_resolver( static fn(): bool => $is_ajax );
		$sut->set_provider_connected_resolver( static fn(): bool => $provider_connected );
		$sut->set_note_can_be_added_resolver( static fn(): bool => $can_be_added );
		$sut->set_note_saver(
			static function ( Note $note ) use ( &$saved_notes ): void {
				$saved_notes[] = $note;
			}
		);

		$sut->handle_admin_init();

		$this->assertSame( array(), $saved_notes );
	}

	/**
	 * @testdox Should save the multi-currency availability note, with its setup link on the Multi-currency settings tab, when eligible.
	 */
	public function test_saves_multi_currency_availability_note_when_eligible(): void {
		$saved_notes = array();
		$sut         = $this->create_controller( MultiCurrencyRuntimeArbiter::OWNER_BUILTIN, true );
		$sut->set_ajax_request_resolver( static fn(): bool => false );
		$sut->set_note_can_be_added_resolver( static fn(): bool => true );
		$sut->set_note_saver(
			static function ( Note $note ) use ( &$saved_notes ): void {
				$saved_notes[] = $note;
			}
		);

		$sut->handle_admin_init();

		$this->assertCount( 1, $saved_notes );
		$note    = $saved_notes[0];
		$actions = $note->get_actions();
		$this->assertSame(
			array(
				'name'         => 'wc-payments-notes-multi-currency-available',
				'title'        => 'Sell worldwide in multiple currencies',
				'content'      => 'Boost your international sales by allowing your customers to shop and pay in their local currency.',
				'content_data' => array(),
				'type'         => 'info',
				'source'       => 'woocommerce-payments',
				'actions'      => array(
					array(
						'name'          => 'wc-payments-notes-multi-currency-available',
						'label'         => 'Set up now',
						'query'         => admin_url( 'admin.php?page=wc-settings&tab=wcpay_multi_currency' ),
						'status'        => 'unactioned',
						'actioned_text' => '',
					),
				),
			),
			array(
				'name'         => $note->get_name(),
				'title'        => $note->get_title(),
				'content'      => $note->get_content(),
				'content_data' => (array) $note->get_content_data(),
				'type'         => $note->get_type(),
				'source'       => $note->get_source(),
				'actions'      => array_map(
					static fn( $action ): array => array(
						'name'          => $action->name,
						'label'         => $action->label,
						'query'         => $action->query,
						'status'        => $action->status,
						'actioned_text' => $action->actioned_text,
					),
					$actions
				),
			)
		);
	}

	/**
	 * @testdox Should not look up the stored note when the provider is disconnected.
	 */
	public function test_does_not_look_up_stored_note_when_provider_is_disconnected(): void {
		$lookups = 0;
		$sut     = $this->create_controller( MultiCurrencyRuntimeArbiter::OWNER_BUILTIN, true );
		$sut->set_ajax_request_resolver( static fn(): bool => false );
		$sut->set_provider_connected_resolver( static fn(): bool => false );
		$sut->set_note_can_be_added_resolver(
			static function () use ( &$lookups ): bool {
				++$lookups;
				return true;
			}
		);

		$sut->handle_admin_init();

		$this->assertSame( 0, $lookups );
	}

	/**
	 * @testdox Should use provider-neutral account resolver when no test override is set.
	 */
	public function test_uses_provider_account_resolver_when_no_override_is_set(): void {
		$saved_notes = array();
		$sut         = $this->create_controller( MultiCurrencyRuntimeArbiter::OWNER_BUILTIN, true, false );
		$sut->set_ajax_request_resolver( static fn(): bool => false );
		$sut->set_note_can_be_added_resolver( static fn(): bool => true );
		$sut->set_note_saver(
			static function ( Note $note ) use ( &$saved_notes ): void {
				$saved_notes[] = $note;
			}
		);

		$sut->handle_admin_init();

		$this->assertSame( array(), $saved_notes );
	}

	/**
	 * Data provider for add-note blockers.
	 *
	 * @return array<string,array{0: bool, 1: bool, 2: bool}>
	 */
	public function add_note_blocker_provider(): array {
		return array(
			'ajax request'          => array( true, true, true ),
			'provider disconnected' => array( false, false, true ),
			'note cannot be added'  => array( false, true, false ),
		);
	}

	/**
	 * Create an admin note controller.
	 *
	 * @param string $owner              Runtime owner.
	 * @param bool   $is_admin           Whether the request is admin.
	 * @param bool   $provider_connected Whether the provider account is connected.
	 * @return MultiCurrencyAdminNoteController
	 */
	private function create_controller( string $owner, bool $is_admin, bool $provider_connected = true ): MultiCurrencyAdminNoteController {
		$provider = $this->createMock( CurrencyRateProvider::class );
		$provider->method( 'is_connected' )->willReturn( $provider_connected );

		$controller = new MultiCurrencyAdminNoteController();
		$controller->init( $this->create_arbiter( $owner ), RateServices::with( $provider ) );
		$controller->set_admin_request_resolver( static fn(): bool => $is_admin );

		return $controller;
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
