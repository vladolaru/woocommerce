<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\MultiCurrency;

use Automattic\WooCommerce\Internal\MultiCurrency\Integrations\IntegrationsController;
use Automattic\WooCommerce\Internal\MultiCurrency\MultiCurrencyRuntimeArbiter;
use Automattic\WooCommerce\Internal\MultiCurrency\MultiCurrencySwitcherBlockController;
use Automattic\WooCommerce\Internal\MultiCurrency\Services\SwitcherRenderer;
use WC_Unit_Test_Case;
use WP_Block_Type_Registry;

/**
 * Tests for the MultiCurrencySwitcherBlockController class.
 */
class MultiCurrencySwitcherBlockControllerTest extends WC_Unit_Test_Case {

	private const BLOCK_NAME                  = 'woocommerce-payments/multi-currency-switcher';
	private const EDITOR_SCRIPT_HANDLE        = 'wc-multi-currency-switcher';
	private const COMPAT_EDITOR_SCRIPT_HANDLE = 'woocommerce-payments/multi-currency-switcher';

	/**
	 * Hooks touched by the switcher block controller.
	 *
	 * @var string[]
	 */
	private array $hooks = array(
		'init',
	);

	/**
	 * Tear down test fixtures.
	 */
	public function tear_down(): void {
		foreach ( $this->hooks as $hook ) {
			remove_all_filters( $hook );
		}

		$this->unregister_block_type();
		wp_deregister_script( self::EDITOR_SCRIPT_HANDLE );
		wp_deregister_script( self::COMPAT_EDITOR_SCRIPT_HANDLE );

		unset( $_GET['currency'], $_GET['orderby'] );

		parent::tear_down();
	}

	/**
	 * @testdox Should register exactly the switcher block init hook the owner allows, once: $_dataName.
	 * @dataProvider provide_block_init_hook_owners
	 *
	 * @param string                            $owner    Runtime owner.
	 * @param array<string,array<string,int[]>> $expected Every hook the controller registers.
	 */
	public function test_registers_the_block_init_hook_its_owner_allows( string $owner, array $expected ): void {
		$sut = $this->create_controller( $owner );

		$sut->register();
		$sut->register();

		$this->assertSame( $expected, RegisteredHooks::of( $sut ) );
	}

	/**
	 * Owners and the hooks each registers: the init hook under core, nothing under the extension.
	 *
	 * @return array<string,array<int,mixed>>
	 */
	public function provide_block_init_hook_owners(): array {
		return array(
			'core owns'          => array(
				MultiCurrencyRuntimeArbiter::OWNER_BUILTIN,
				array(
					'init' => array( 'handle_init' => array( 10, 1 ) ),
				),
			),
			'the extension owns' => array( MultiCurrencyRuntimeArbiter::OWNER_EXTENSION, array() ),
		);
	}

	/**
	 * @testdox Should register switcher block type with preserved attributes.
	 */
	public function test_registers_switcher_block_type_with_api_version_render_callback_and_attributes(): void {
		$sut = $this->create_controller( MultiCurrencyRuntimeArbiter::OWNER_BUILTIN );

		$sut->handle_init();

		$block_type = WP_Block_Type_Registry::get_instance()->get_registered( self::BLOCK_NAME );

		$this->assertNotFalse( $block_type );
		$this->assertSame( 3, $block_type->api_version );
		$this->assertSame( array( $sut, 'render_block_widget' ), $block_type->render_callback );
		$expected_attributes = array(
			'symbol'          => array(
				'type'    => 'boolean',
				'default' => true,
			),
			'flag'            => array(
				'type'    => 'boolean',
				'default' => false,
			),
			'fontSize'        => array(
				'type'    => 'integer',
				'default' => 14,
			),
			'fontLineHeight'  => array(
				'type'    => 'number',
				'default' => 1.5,
			),
			'fontColor'       => array(
				'type'    => 'string',
				'default' => '#000000',
			),
			'border'          => array(
				'type'    => 'boolean',
				'default' => true,
			),
			'borderRadius'    => array(
				'type'    => 'integer',
				'default' => 3,
			),
			'borderColor'     => array(
				'type'    => 'string',
				'default' => '#000000',
			),
			'backgroundColor' => array(
				'type'    => 'string',
				'default' => 'transparent',
			),
		);

		$this->assertSame(
			$expected_attributes,
			array_intersect_key( $block_type->attributes, $expected_attributes )
		);
		$this->assertSame( array( self::EDITOR_SCRIPT_HANDLE ), $block_type->editor_script_handles );
		$this->assertTrue( wp_script_is( self::EDITOR_SCRIPT_HANDLE, 'registered' ) );
		$this->assertFalse( wp_script_is( self::EDITOR_SCRIPT_HANDLE, 'enqueued' ) );

		$registered_script = wp_scripts()->registered[ self::EDITOR_SCRIPT_HANDLE ] ?? null;

		$this->assertInstanceOf( \_WP_Dependency::class, $registered_script );
		$this->assertSame(
			WC()->plugin_url() . '/assets/client/blocks/multi-currency-switcher.js',
			$registered_script->src
		);
		$this->assertContains( 'react-jsx-runtime', $registered_script->deps );
		$this->assertContains( 'wp-blocks', $registered_script->deps );
		$this->assertContains( 'wp-block-editor', $registered_script->deps );
		$this->assertContains( 'wp-components', $registered_script->deps );
		$this->assertContains( 'wp-i18n', $registered_script->deps );
		$this->assertContains( 'wp-polyfill', $registered_script->deps );
		$this->assertContains( 'wp-server-side-render', $registered_script->deps );
		$this->assertNotContains( 'wp-element', $registered_script->deps );
		$this->assertNotEmpty( $registered_script->ver );

		$compat_script = wp_scripts()->registered[ self::COMPAT_EDITOR_SCRIPT_HANDLE ] ?? null;

		$this->assertInstanceOf( \_WP_Dependency::class, $compat_script );
		$this->assertFalse( $compat_script->src );
		$this->assertSame( array( self::EDITOR_SCRIPT_HANDLE ), $compat_script->deps );
	}

	/**
	 * @testdox Should preserve an existing public editor script registration.
	 */
	public function test_preserves_existing_editor_script_registration(): void {
		wp_register_script(
			self::EDITOR_SCRIPT_HANDLE,
			'https://example.test/existing-switcher.js',
			array( 'existing-dependency' ),
			'existing-version',
			true
		);

		$sut = $this->create_controller( MultiCurrencyRuntimeArbiter::OWNER_BUILTIN );

		$sut->handle_init();

		$registered_script = wp_scripts()->registered[ self::EDITOR_SCRIPT_HANDLE ] ?? null;

		$this->assertInstanceOf( \_WP_Dependency::class, $registered_script );
		$this->assertSame( 'https://example.test/existing-switcher.js', $registered_script->src );
		$this->assertSame( array( 'existing-dependency' ), $registered_script->deps );
		$this->assertSame( 'existing-version', $registered_script->ver );

		$block_type = WP_Block_Type_Registry::get_instance()->get_registered( self::BLOCK_NAME );

		$this->assertNotFalse( $block_type );
		$this->assertSame( array( self::EDITOR_SCRIPT_HANDLE ), $block_type->editor_script_handles );
	}

	/**
	 * @testdox Should render the block through the switcher renderer with its attributes, the query arguments and the switching decision: switching disabled $switching_disabled.
	 * @testWith [false]
	 *           [true]
	 *
	 * @param bool $switching_disabled Whether currency switching is disabled.
	 */
	public function test_render_callback_delegates_to_the_switcher_renderer( bool $switching_disabled ): void {
		$switcher_renderer = $this->create_switcher_renderer();
		$sut               = $this->create_controller( MultiCurrencyRuntimeArbiter::OWNER_BUILTIN, $switcher_renderer, $switching_disabled );
		$_GET['currency']  = 'EUR';
		$_GET['orderby']   = 'price';

		$markup = $sut->render_block_widget(
			array(
				'fontSize' => 18,
				'symbol'   => false,
			)
		);

		$this->assertSame( '<form>Block switcher</form>', $markup );
		$this->assertSame(
			array(
				'fontSize' => 18,
				'symbol'   => false,
			),
			$switcher_renderer->last_block_attributes
		);
		$this->assertSame(
			array(
				'currency' => 'EUR',
				'orderby'  => 'price',
			),
			$switcher_renderer->last_query_args
		);
		$this->assertSame( $switching_disabled, $switcher_renderer->last_switching_disabled );
	}

	/**
	 * Create a switcher block controller.
	 *
	 * @param string                $owner               Runtime owner.
	 * @param SwitcherRenderer|null $switcher_renderer          Projection service.
	 * @param bool                  $switching_disabled Whether switching is disabled.
	 * @return MultiCurrencySwitcherBlockController
	 */
	private function create_controller(
		string $owner,
		?SwitcherRenderer $switcher_renderer = null,
		bool $switching_disabled = false
	): MultiCurrencySwitcherBlockController {
		return $this->create_controller_with_arbiter( $this->create_arbiter( $owner ), $switcher_renderer, $switching_disabled );
	}

	/**
	 * Create a switcher block controller with an explicit runtime arbiter.
	 *
	 * @param MultiCurrencyRuntimeArbiter $arbiter            Runtime owner arbiter.
	 * @param SwitcherRenderer|null       $switcher_renderer         Projection service.
	 * @param bool                        $switching_disabled Whether switching is disabled.
	 * @return MultiCurrencySwitcherBlockController
	 */
	private function create_controller_with_arbiter(
		MultiCurrencyRuntimeArbiter $arbiter,
		?SwitcherRenderer $switcher_renderer = null,
		bool $switching_disabled = false
	): MultiCurrencySwitcherBlockController {
		$controller = new MultiCurrencySwitcherBlockController();
		$controller->init(
			$arbiter,
			$this->create_integrations_controller( $switching_disabled ),
			ServiceFactories::with( $this, array( 'create_switcher_renderer' => $switcher_renderer ?? $this->create_switcher_renderer() ) )
		);

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
			public function is_builtin_owner(): bool {
				return MultiCurrencyRuntimeArbiter::OWNER_BUILTIN === $this->owner;
			}
		};
	}

	/**
	 * Create an integrations controller test double.
	 *
	 * @param bool $switching_disabled Whether switching is disabled.
	 * @return IntegrationsController
	 */
	private function create_integrations_controller( bool $switching_disabled = false ): IntegrationsController {
		return new class( $switching_disabled ) extends IntegrationsController {
			/**
			 * Whether switching is disabled.
			 *
			 * @var bool
			 */
			private bool $switching_disabled;

			/**
			 * Constructor.
			 *
			 * @param bool $switching_disabled Whether switching is disabled.
			 */
			public function __construct( bool $switching_disabled ) {
				$this->switching_disabled = $switching_disabled;
			}

			/**
			 * Tell whether currency switching should be disabled.
			 *
			 * @return bool
			 */
			public function should_disable_currency_switching(): bool {
				return $this->switching_disabled;
			}
		};
	}

	/**
	 * Create a switcher projection service test double.
	 *
	 * @return SwitcherRenderer
	 */
	private function create_switcher_renderer(): SwitcherRenderer {
		return new class() extends SwitcherRenderer {
			/**
			 * Last block attributes.
			 *
			 * @var array<string,mixed>|null
			 */
			public ?array $last_block_attributes = null;

			/**
			 * Last query args.
			 *
			 * @var array<string,mixed>|null
			 */
			public ?array $last_query_args = null;

			/**
			 * Last switching-disabled flag.
			 *
			 * @var bool|null
			 */
			public ?bool $last_switching_disabled = null;

			/**
			 * Constructor.
			 */
			public function __construct() {}

			/**
			 * Project block markup.
			 *
			 * @param array<string,mixed> $block_attributes   Block attributes.
			 * @param array<string,mixed> $query_args         Query arguments to preserve.
			 * @param bool                $switching_disabled Whether switching is disabled.
			 * @return string
			 */
			public function get_block_markup(
				array $block_attributes = array(),
				array $query_args = array(),
				bool $switching_disabled = false
			): string {
				$this->last_block_attributes   = $block_attributes;
				$this->last_query_args         = $query_args;
				$this->last_switching_disabled = $switching_disabled;

				return '<form>Block switcher</form>';
			}
		};
	}

	/**
	 * Unregister the switcher block type if a test registered it.
	 */
	private function unregister_block_type(): void {
		$registry = WP_Block_Type_Registry::get_instance();

		if ( $registry->is_registered( self::BLOCK_NAME ) ) {
			$registry->unregister( self::BLOCK_NAME );
		}
	}
}
