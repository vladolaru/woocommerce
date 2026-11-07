<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\MultiCurrency;

use Automattic\WooCommerce\Internal\MultiCurrency\Integrations\IntegrationsController;
use Automattic\WooCommerce\Internal\MultiCurrency\MultiCurrencyRuntimeArbiter;
use Automattic\WooCommerce\Internal\MultiCurrency\MultiCurrencySwitcherWidget;
use Automattic\WooCommerce\Internal\MultiCurrency\MultiCurrencySwitcherWidgetController;
use Automattic\WooCommerce\Internal\MultiCurrency\Services\SwitcherRenderer;
use Automattic\WooCommerce\Proxies\LegacyProxy;
use WC_Unit_Test_Case;

/**
 * Tests for the MultiCurrencySwitcherWidgetController and widget classes.
 */
class MultiCurrencySwitcherWidgetControllerTest extends WC_Unit_Test_Case {

	/**
	 * Hooks touched by the switcher widget controller.
	 *
	 * @var string[]
	 */
	private array $hooks = array(
		'widgets_init',
		'wcpay_multi_currency_theme_widget_instance',
		'wcpay_multi_currency_theme_widget_args',
	);

	/**
	 * Tear down test fixtures.
	 */
	public function tear_down(): void {
		foreach ( $this->hooks as $hook ) {
			remove_all_filters( $hook );
		}

		$this->remove_registered_switcher_widgets();

		unset( $_GET['currency'], $_GET['s'] );

		parent::tear_down();
	}

	/**
	 * @testdox Should register exactly the widget hook the owner allows, once: $_dataName.
	 * @dataProvider provide_widget_hook_owners
	 *
	 * @param string                            $owner    Runtime owner.
	 * @param array<string,array<string,int[]>> $expected Every hook the controller registers.
	 */
	public function test_registers_the_widget_hook_its_owner_allows( string $owner, array $expected ): void {
		$sut = $this->create_controller( $owner );

		$sut->register();
		$sut->register();

		$this->assertSame( $expected, RegisteredHooks::of( $sut ) );
	}

	/**
	 * Owners and the hooks each registers: the widgets hook under core, nothing under the extension.
	 *
	 * @return array<string,array<int,mixed>>
	 */
	public function provide_widget_hook_owners(): array {
		return array(
			'core owns'          => array(
				MultiCurrencyRuntimeArbiter::OWNER_BUILTIN,
				array(
					'widgets_init' => array( 'handle_widgets_init' => array( 10, 1 ) ),
				),
			),
			'the extension owns' => array( MultiCurrencyRuntimeArbiter::OWNER_EXTENSION, array() ),
		);
	}

	/**
	 * @testdox Should register single widget instance on widgets init.
	 */
	public function test_registers_single_widget_instance_on_widgets_init(): void {
		$sut = $this->create_controller( MultiCurrencyRuntimeArbiter::OWNER_BUILTIN );

		$before = $this->get_switcher_widgets();
		$sut->handle_widgets_init();
		$sut->handle_widgets_init();

		$this->assertCount( 1, array_diff_key( $this->get_switcher_widgets(), $before ), 'One widget instance must be registered with the widget factory, whatever key derivation the WordPress version uses.' );
	}

	/**
	 * @testdox Should return empty markup when the widget factory no longer holds the registered widget: $_dataName.
	 * @dataProvider provide_broken_widget_factories
	 *
	 * @param string $break_factory Fixture method that breaks the widget factory after registration.
	 */
	public function test_returns_empty_markup_when_registered_widget_is_absent_from_factory( string $break_factory ): void {
		global $wp_widget_factory;

		$sut              = $this->create_controller( MultiCurrencyRuntimeArbiter::OWNER_BUILTIN );
		$original_widgets = $wp_widget_factory->widgets;
		$sut->handle_widgets_init();
		$this->$break_factory();

		try {
			$this->assertSame( '', $sut->get_switcher_widget_markup() );
		} finally {
			$wp_widget_factory->widgets = $original_widgets;
		}
	}

	/**
	 * Broken widget factories: the registered widget removed, and a malformed factory without a widget list.
	 *
	 * @return array<string,array{0:string}>
	 */
	public static function provide_broken_widget_factories(): array {
		return array(
			'the registered widget removed' => array( 'remove_registered_switcher_widgets' ),
			'a malformed factory'           => array( 'empty_the_widget_factory' ),
		);
	}

	/**
	 * Leave the widget factory without a widget list, as a plugin that replaces it badly can.
	 */
	private function empty_the_widget_factory(): void {
		global $wp_widget_factory;

		$wp_widget_factory->widgets = null;
	}

	/**
	 * @testdox Should use the actual widget factory key and apply legacy theme filters in order.
	 */
	public function test_renders_with_actual_factory_key_and_applies_theme_widget_filters_in_order(): void {
		global $wp_widget_factory;

		$switcher_renderer = $this->create_switcher_renderer();
		$sut               = $this->create_controller( MultiCurrencyRuntimeArbiter::OWNER_BUILTIN, $switcher_renderer );
		$before            = $this->get_switcher_widgets();
		$sut->handle_widgets_init();
		$widget = current( array_diff_key( $this->get_switcher_widgets(), $before ) );
		$key    = array_search( $widget, $wp_widget_factory->widgets, true );
		unset( $wp_widget_factory->widgets[ $key ] );
		$wp_widget_factory->widgets[711] = $widget;
		$filter_order                    = array();

		add_filter(
			'wcpay_multi_currency_theme_widget_instance',
			static function ( $instance ) use ( &$filter_order ) {
				unset( $instance );
				$filter_order[] = 'instance';
				return array(
					'title' => 'Theme currency',
				);
			}
		);
		add_filter(
			'wcpay_multi_currency_theme_widget_args',
			static function ( $args ) use ( &$filter_order ) {
				unset( $args );
				$filter_order[] = 'args';
				return array(
					'before_widget' => '<section>',
					'after_widget'  => '</section>',
				);
			}
		);

		$markup = $sut->get_switcher_widget_markup( array( 'title' => 'Caller title' ), array( 'before_widget' => '<aside>' ) );

		$this->assertSame( '<form>Switcher</form>', $markup );
		$this->assertSame( array( 'instance', 'args' ), $filter_order );
		$this->assertSame( array( 'title' => 'Theme currency' ), $switcher_renderer->last_instance );
		$this->assertSame( '<section>', $switcher_renderer->last_args['before_widget'] );
		$this->assertSame( '</section>', $switcher_renderer->last_args['after_widget'] );
	}

	/**
	 * @testdox Should retain caller arrays when legacy theme filters return malformed values.
	 */
	public function test_retains_caller_arrays_when_theme_widget_filters_return_malformed_values(): void {
		$switcher_renderer = $this->create_switcher_renderer();
		$sut               = $this->create_controller( MultiCurrencyRuntimeArbiter::OWNER_BUILTIN, $switcher_renderer );
		$instance          = array( 'title' => 'Caller title' );
		$args              = array( 'before_widget' => '<aside>' );
		$sut->handle_widgets_init();
		add_filter( 'wcpay_multi_currency_theme_widget_instance', static fn() => 'not an array' );
		add_filter( 'wcpay_multi_currency_theme_widget_args', static fn() => 'not an array' );

		$sut->get_switcher_widget_markup( $instance, $args );

		$this->assertSame( $instance, $switcher_renderer->last_instance );
		$this->assertSame( $args['before_widget'], $switcher_renderer->last_args['before_widget'] );
	}

	/**
	 * @testdox Should not render core markup while the extension or no runtime owns multi-currency.
	 * @dataProvider other_owner_provider
	 *
	 * @param string $owner Runtime owner.
	 */
	public function test_does_not_render_core_markup_when_another_runtime_owns_multi_currency( string $owner ): void {
		$sut = $this->create_controller( $owner );
		$sut->handle_widgets_init();

		$this->assertSame( '', $sut->get_switcher_widget_markup() );
	}

	/** @return array<string,array{string}> */
	public static function other_owner_provider(): array {
		return array(
			'extension' => array( MultiCurrencyRuntimeArbiter::OWNER_EXTENSION ),
			'none'      => array( MultiCurrencyRuntimeArbiter::OWNER_NONE ),
		);
	}

	/**
	 * @testdox Should delegate to the client facade only when the plugin owns multi-currency.
	 */
	public function test_delegates_to_the_extension_only_when_extension_owns_multi_currency(): void {
		$extension_facade = new class() {
			/**
			 * Last client instance settings.
			 *
			 * @var array
			 */
			public array $received_instance = array();

			/**
			 * Last client widget arguments.
			 *
			 * @var array
			 */
			public array $received_args = array();

			/**
			 * Get client switcher markup.
			 *
			 * @param array $instance Widget instance settings.
			 * @param array $args     Widget arguments.
			 * @return string
			 */
			public function get_switcher_widget_markup( array $instance = array(), array $args = array() ): string {
				$this->received_instance = $instance;
				$this->received_args     = $args;

				return '<form>Client switcher</form>';
			}
		};
		// The extension's global function, answered through the legacy proxy the controller asks.
		$this->register_legacy_proxy_function_mocks(
			array(
				'function_exists'            => static fn( string $function_name ): bool => 'WC_Payments_Multi_Currency' === $function_name || function_exists( $function_name ),
				'WC_Payments_Multi_Currency' => static fn() => $extension_facade,
			)
		);
		$instance = array( 'title' => 'Client title' );
		$args     = array( 'before_widget' => '<aside>' );
		$sut      = $this->create_controller( MultiCurrencyRuntimeArbiter::OWNER_EXTENSION );

		$this->assertSame( '<form>Client switcher</form>', $sut->get_switcher_widget_markup( $instance, $args ) );
		$this->assertSame( $instance, $extension_facade->received_instance );
		$this->assertSame( $args, $extension_facade->received_args );
	}

	/**
	 * @testdox Should clean the output buffer when widget rendering throws.
	 */
	public function test_cleans_output_buffer_when_widget_rendering_throws(): void {
		$switcher_renderer = new class() extends SwitcherRenderer {
			/**
			 * Constructor.
			 */
			public function __construct() {}

			/**
			 * Throw while projecting widget markup.
			 *
			 * @param array $instance           Widget instance settings.
			 * @param array $args               Widget arguments.
			 * @param array $query_args         Query arguments.
			 * @param bool  $switching_disabled Whether switching is disabled.
			 * @return string
			 */
			public function get_widget_markup( array $instance = array(), array $args = array(), array $query_args = array(), bool $switching_disabled = false ): string {
				unset( $instance, $args, $query_args );
				if ( $switching_disabled ) {
					return '';
				}

				throw new \RuntimeException( 'Rendering failed.' );
			}
		};
		$sut               = $this->create_controller( MultiCurrencyRuntimeArbiter::OWNER_BUILTIN, $switcher_renderer );
		$level             = ob_get_level();
		$sut->handle_widgets_init();

		try {
			$sut->get_switcher_widget_markup();
			$this->fail( 'Expected widget rendering to throw.' );
		} catch ( \RuntimeException $exception ) {
			$this->assertSame( 'Rendering failed.', $exception->getMessage() );
			$this->assertSame( $level, ob_get_level() );
		}
	}

	/**
	 * @testdox Should expose preserved widget metadata and sentence case settings.
	 */
	public function test_widget_exposes_id_name_description_and_sentence_case_labels(): void {
		$widget = $this->create_widget();

		$this->assertSame( 'currency_switcher_widget', $widget->widget_id );
		$this->assertSame( 'Currency switcher widget', $widget->widget_name );
		$this->assertSame( 'Let customers switch between enabled currencies.', $widget->widget_description );
		$this->assertSame( 'Title', $widget->settings['title']['label'] );
		$this->assertSame( 'Display currency symbols', $widget->settings['symbol']['label'] );
		$this->assertSame( 'Display flags on supported devices', $widget->settings['flag']['label'] );
	}

	/**
	 * @testdox Should render the widget through the switcher renderer with its instance, arguments, the query arguments and the switching decision: switching disabled $switching_disabled.
	 * @testWith [false]
	 *           [true]
	 *
	 * @param bool $switching_disabled Whether currency switching is disabled.
	 */
	public function test_widget_renders_the_switcher_renderer_markup_with_query_args( bool $switching_disabled ): void {
		$switcher_renderer = $this->create_switcher_renderer();
		$_GET['currency']  = 'EUR';
		$_GET['s']         = 'shirts';
		$widget            = $this->create_widget( $switcher_renderer, $switching_disabled );

		ob_start();
		$widget->widget(
			array(
				'before_widget' => '<aside>',
				'after_widget'  => '</aside>',
			),
			array(
				'title'  => 'Shop currency',
				'symbol' => false,
			)
		);
		$output = ob_get_clean();

		$this->assertSame( '<form>Switcher</form>', $output );
		$this->assertSame(
			array(
				'title'  => 'Shop currency',
				'symbol' => false,
			),
			$switcher_renderer->last_instance
		);
		$this->assertSame(
			array(
				'before_widget' => '<aside>',
				'after_widget'  => '</aside>',
			),
			$switcher_renderer->last_args
		);
		$this->assertSame(
			array(
				'currency' => 'EUR',
				's'        => 'shirts',
			),
			$switcher_renderer->last_query_args
		);
		$this->assertSame( $switching_disabled, $switcher_renderer->last_switching_disabled );
	}

	/**
	 * Get the switcher widgets registered with the widget factory, keyed by object id.
	 *
	 * @return array<int,MultiCurrencySwitcherWidget>
	 */
	private function get_switcher_widgets(): array {
		global $wp_widget_factory;

		$widgets = array();
		foreach ( $wp_widget_factory->widgets as $widget ) {
			if ( $widget instanceof MultiCurrencySwitcherWidget ) {
				$widgets[ spl_object_id( $widget ) ] = $widget;
			}
		}

		return $widgets;
	}

	/**
	 * Create a switcher widget controller.
	 *
	 * @param string           $owner      Runtime owner.
	 * @param SwitcherRenderer $switcher_renderer Projection service.
	 * @return MultiCurrencySwitcherWidgetController
	 */
	private function create_controller(
		string $owner,
		?SwitcherRenderer $switcher_renderer = null
	): MultiCurrencySwitcherWidgetController {
		$controller = new MultiCurrencySwitcherWidgetController();
		$controller->init(
			$this->create_arbiter( $owner ),
			$this->create_integrations_controller(),
			ServiceFactories::with( $this, array( 'create_switcher_renderer' => $switcher_renderer ?? $this->create_switcher_renderer() ) ),
			wc_get_container()->get( LegacyProxy::class )
		);

		return $controller;
	}

	/**
	 * Create a switcher widget.
	 *
	 * @param SwitcherRenderer|null $switcher_renderer          Projection service.
	 * @param bool                  $switching_disabled Whether switching is disabled.
	 * @return MultiCurrencySwitcherWidget
	 */
	private function create_widget(
		?SwitcherRenderer $switcher_renderer = null,
		bool $switching_disabled = false
	): MultiCurrencySwitcherWidget {
		return new MultiCurrencySwitcherWidget(
			$switcher_renderer ?? $this->create_switcher_renderer(),
			$this->create_integrations_controller( $switching_disabled )
		);
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
			 * Last widget instance settings.
			 *
			 * @var array<string,mixed>|null
			 */
			public ?array $last_instance = null;

			/**
			 * Last widget wrapper args.
			 *
			 * @var array<string,mixed>|null
			 */
			public ?array $last_args = null;

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
			 * Project widget markup.
			 *
			 * @param array<string,mixed> $instance           Widget instance settings.
			 * @param array<string,mixed> $args               Widget wrapper arguments.
			 * @param array<string,mixed> $query_args         Query arguments to preserve.
			 * @param bool                $switching_disabled Whether switching is disabled.
			 * @return string
			 */
			public function get_widget_markup(
				array $instance = array(),
				array $args = array(),
				array $query_args = array(),
				bool $switching_disabled = false
			): string {
				$this->last_instance           = $instance;
				$this->last_args               = $args;
				$this->last_query_args         = $query_args;
				$this->last_switching_disabled = $switching_disabled;

				return '<form>Switcher</form>';
			}
		};
	}

	/**
	 * Remove native switcher widgets from the global widget factory.
	 */
	private function remove_registered_switcher_widgets(): void {
		global $wp_widget_factory;

		if ( ! isset( $wp_widget_factory->widgets ) || ! is_array( $wp_widget_factory->widgets ) ) {
			return;
		}

		foreach ( $wp_widget_factory->widgets as $widget_key => $widget ) {
			if ( $widget instanceof MultiCurrencySwitcherWidget ) {
				unset( $wp_widget_factory->widgets[ $widget_key ] );
			}
		}
	}
}
