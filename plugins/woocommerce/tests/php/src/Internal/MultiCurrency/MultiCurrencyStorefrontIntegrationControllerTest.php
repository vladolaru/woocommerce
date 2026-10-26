<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\MultiCurrency;

use Automattic\WooCommerce\Internal\MultiCurrency\Interfaces\MultiCurrencyLocalizationInterface;
use Automattic\WooCommerce\Internal\MultiCurrency\MultiCurrencyCompatibilityController;
use Automattic\WooCommerce\Internal\MultiCurrency\MultiCurrencyCurrency;
use Automattic\WooCommerce\Internal\MultiCurrency\MultiCurrencyRuntimeArbiter;
use Automattic\WooCommerce\Internal\MultiCurrency\MultiCurrencyState;
use Automattic\WooCommerce\Internal\MultiCurrency\MultiCurrencyStorefrontIntegrationController;
use Automattic\WooCommerce\Internal\MultiCurrency\Services\StateBuilder;
use Automattic\WooCommerce\Internal\MultiCurrency\Services\SwitcherRenderer;
use WC_Unit_Test_Case;

/**
 * Tests for the MultiCurrencyStorefrontIntegrationController class.
 */
class MultiCurrencyStorefrontIntegrationControllerTest extends WC_Unit_Test_Case {

	/**
	 * Hooks touched by the controller.
	 *
	 * @var string[]
	 */
	private array $hooks = array(
		'woocommerce_breadcrumb_defaults',
		'wp_enqueue_scripts',
		'wcpay_multi_currency_storefront_widget_instance',
		'wcpay_multi_currency_storefront_widget_args',
		'wcpay_multi_currency_storefront_widget_css',
		'wcpay_multi_currency_should_disable_currency_switching',
	);

	/**
	 * The theme directories before a test registered the fixture themes, or null when none did.
	 *
	 * @var array<int,string>|null
	 */
	private ?array $theme_directories = null;

	/**
	 * Tear down test fixtures.
	 */
	public function tear_down(): void {
		foreach ( $this->hooks as $hook ) {
			remove_all_filters( $hook );
		}

		unset(
			$_GET['currency'],
			$_GET['s'],
			$_GET['is_mc_onboarding_simulation'],
			$_GET['enable_storefront_switcher'],
			$_GET['pay_for_order']
		);
		delete_option( 'wcpay_multi_currency_enable_storefront_switcher' );
		wp_dequeue_style( 'storefront-style' );
		wp_deregister_style( 'storefront-style' );
		if ( null !== $this->theme_directories ) {
			$GLOBALS['wp_theme_directories'] = $this->theme_directories; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Restoring the directories taken in use_theme().
			search_theme_directories( true );
			$this->theme_directories = null;
		}

		parent::tear_down();
	}

	/**
	 * @testdox Should not register Storefront switcher hooks when plugin owns runtime.
	 */
	public function test_does_not_register_hooks_when_extension_owns_runtime(): void {
		update_option( 'wcpay_multi_currency_enable_storefront_switcher', 'yes' );
		$sut = $this->create_controller( MultiCurrencyRuntimeArbiter::OWNER_EXTENSION );

		$sut->register();

		$this->assertFalse( has_action( 'init', array( $sut, 'handle_init' ) ) );
		$this->assertFalse( has_filter( 'woocommerce_breadcrumb_defaults', array( $sut, 'handle_woocommerce_breadcrumb_defaults' ) ) );
		$this->assertFalse( has_action( 'wp_enqueue_scripts', array( $sut, 'handle_wp_enqueue_scripts' ) ) );
	}

	/**
	 * @testdox Should leave the Storefront decision to init, reading neither the theme nor the currency state while WooCommerce loads.
	 */
	public function test_register_defers_the_storefront_decision_to_init(): void {
		update_option( 'wcpay_multi_currency_enable_storefront_switcher', 'yes' );
		$state_builder = $this->create_state_builder( 2 );
		$sut           = $this->create_controller( MultiCurrencyRuntimeArbiter::OWNER_BUILTIN, 2, 'storefront', 'storefront', null, $state_builder );
		$theme_reads   = 0;
		add_filter(
			'stylesheet',
			static function () use ( &$theme_reads ): string {
				++$theme_reads;
				return 'storefront';
			}
		);

		$sut->register();
		$sut->register();

		// register() runs inside the WooCommerce constructor, where a state build reaches WC() and constructs WooCommerce again.
		$this->assertSame( 0, $state_builder->builds, 'register() must not build the currency state.' );
		$this->assertSame( 0, $theme_reads, 'register() must not read the theme, which can still change before init.' );
		$this->assertNotFalse( has_action( 'init', array( $sut, 'handle_init' ) ), 'The decision runs on init.' );
		$this->assertFalse( has_filter( 'woocommerce_breadcrumb_defaults', array( $sut, 'handle_woocommerce_breadcrumb_defaults' ) ) );
		$this->assertFalse( has_action( 'wp_enqueue_scripts', array( $sut, 'handle_wp_enqueue_scripts' ) ) );

		$sut->handle_init();

		$this->assertSame( 1, $state_builder->builds );
		$this->assertSame( 9999, has_filter( 'woocommerce_breadcrumb_defaults', array( $sut, 'handle_woocommerce_breadcrumb_defaults' ) ) );
		$this->assertSame( 50, has_action( 'wp_enqueue_scripts', array( $sut, 'handle_wp_enqueue_scripts' ) ) );
	}

	/**
	 * @testdox Should register Storefront switcher hooks for $label.
	 * @dataProvider registering_storefront_provider
	 *
	 * @param string      $label      Readable case name.
	 * @param string      $stylesheet Theme stylesheet.
	 * @param string      $template   Theme template.
	 * @param string      $setting    The saved Storefront switcher setting.
	 * @param string|null $preview    The preview's enable_storefront_switcher argument, or null outside a preview.
	 */
	public function test_registers_storefront_switcher_hooks( string $label, string $stylesheet, string $template, string $setting, ?string $preview ): void {
		unset( $label );
		$sut = $this->prepare_storefront_decision( $stylesheet, $template, 2, $setting, $preview );

		$sut->handle_init();
		$sut->handle_init();

		$this->assertSame( 9999, has_filter( 'woocommerce_breadcrumb_defaults', array( $sut, 'handle_woocommerce_breadcrumb_defaults' ) ) );
		$this->assertSame( 50, has_action( 'wp_enqueue_scripts', array( $sut, 'handle_wp_enqueue_scripts' ) ) );
	}

	/**
	 * Requests that show the Storefront switcher.
	 *
	 * @return array<string,array{0:string,1:string,2:string,3:string,4:string|null}>
	 */
	public function registering_storefront_provider(): array {
		return array(
			'Storefront with the setting on'           => array( 'Storefront with the setting on', 'storefront', 'storefront', 'yes', null ),
			'a Storefront child theme'                 => array( 'a Storefront child theme', 'child-theme', 'storefront', 'yes', null ),
			'a preview that shows it, the setting off' => array( 'a preview that shows it, the setting off', 'storefront', 'storefront', 'no', 'true' ),
		);
	}

	/**
	 * @testdox Should not register Storefront switcher hooks when $label.
	 * @dataProvider non_registering_storefront_provider
	 *
	 * @param string      $label      Readable case name.
	 * @param string      $stylesheet Theme stylesheet.
	 * @param string      $template   Theme template.
	 * @param int         $currencies Enabled currency count.
	 * @param string      $setting    The saved Storefront switcher setting.
	 * @param string|null $preview    The preview's enable_storefront_switcher argument, or null outside a preview.
	 */
	public function test_does_not_register_storefront_switcher_hooks( string $label, string $stylesheet, string $template, int $currencies, string $setting, ?string $preview ): void {
		unset( $label );
		$sut = $this->prepare_storefront_decision( $stylesheet, $template, $currencies, $setting, $preview );

		$sut->handle_init();

		$this->assertFalse( has_filter( 'woocommerce_breadcrumb_defaults', array( $sut, 'handle_woocommerce_breadcrumb_defaults' ) ) );
		$this->assertFalse( has_action( 'wp_enqueue_scripts', array( $sut, 'handle_wp_enqueue_scripts' ) ) );
	}

	/**
	 * Requests that do not show the Storefront switcher.
	 *
	 * @return array<string,array{0:string,1:string,2:string,3:int,4:string,5:string|null}>
	 */
	public function non_registering_storefront_provider(): array {
		return array(
			'the saved setting is off'                => array( 'the saved setting is off', 'storefront', 'storefront', 2, 'no', null ),
			'the settings preview hides the switcher' => array( 'the settings preview hides the switcher', 'storefront', 'storefront', 2, 'yes', 'false' ),
			'the theme is not Storefront'             => array( 'the theme is not Storefront', 'twentytwentyfive', 'twentytwentyfive', 2, 'yes', null ),
			'one currency is enabled'                 => array( 'one currency is enabled', 'storefront', 'storefront', 1, 'yes', null ),
		);
	}

	/**
	 * Store the switcher setting and the preview arguments, and build the controller.
	 *
	 * @param string      $stylesheet Theme stylesheet.
	 * @param string      $template   Theme template.
	 * @param int         $currencies Enabled currency count.
	 * @param string      $setting    The saved Storefront switcher setting.
	 * @param string|null $preview    The preview's enable_storefront_switcher argument, or null outside a preview.
	 * @return MultiCurrencyStorefrontIntegrationController
	 */
	private function prepare_storefront_decision( string $stylesheet, string $template, int $currencies, string $setting, ?string $preview ): MultiCurrencyStorefrontIntegrationController {
		update_option( 'wcpay_multi_currency_enable_storefront_switcher', $setting );
		if ( null !== $preview ) {
			$_GET['is_mc_onboarding_simulation'] = 'true';
			$_GET['enable_storefront_switcher']  = $preview;
		}

		return $this->create_controller( MultiCurrencyRuntimeArbiter::OWNER_BUILTIN, $currencies, $stylesheet, $template );
	}

	/**
	 * @testdox Should inject switcher markup into Storefront breadcrumb defaults.
	 */
	public function test_injects_switcher_markup_into_breadcrumb_defaults(): void {
		update_option( 'wcpay_multi_currency_enable_storefront_switcher', 'yes' );
		$_GET['currency'] = 'EUR';
		$_GET['s']        = 'shirts';
		$switcher         = $this->create_switcher_service( '<div id="woocommerce-payments-multi-currency-storefront-widget">Switcher</div>' );
		$sut              = $this->create_controller( MultiCurrencyRuntimeArbiter::OWNER_BUILTIN, 2, 'storefront', 'storefront', $switcher );

		add_filter(
			'wcpay_multi_currency_storefront_widget_instance',
			static function () {
				return array( 'title' => 'Header currency' );
			}
		);
		add_filter(
			'wcpay_multi_currency_storefront_widget_args',
			static function () {
				return array(
					'before_widget' => '<aside>',
					'after_widget'  => '</aside>',
				);
			}
		);

		$result = $sut->handle_woocommerce_breadcrumb_defaults(
			array(
				'wrap_before' => '<div class="storefront-breadcrumb"><nav class="woocommerce-breadcrumb">',
				'wrap_after'  => '</nav></div>',
			)
		);

		$this->assertSame(
			'<div class="storefront-breadcrumb"><div id="woocommerce-payments-multi-currency-storefront-widget">Switcher</div><nav class="woocommerce-breadcrumb">',
			$result['wrap_before']
		);
		$this->assertSame( '</nav></div>', $result['wrap_after'] );
		$this->assertSame( array( 'title' => 'Header currency' ), $switcher->last_instance );
		$this->assertSame(
			array(
				'before_widget' => '<aside>',
				'after_widget'  => '</aside>',
			),
			$switcher->last_args
		);
		$this->assertSame(
			array(
				'currency' => 'EUR',
				's'        => 'shirts',
			),
			$switcher->last_query_args
		);
		$this->assertFalse( $switcher->last_switching_disabled );
	}

	/**
	 * @testdox Should keep breadcrumb defaults unchanged when switching is disabled.
	 */
	public function test_keeps_breadcrumb_defaults_when_switching_is_disabled(): void {
		update_option( 'wcpay_multi_currency_enable_storefront_switcher', 'yes' );
		$switcher = $this->create_switcher_service( '<div>Switcher</div>' );
		$sut      = $this->create_controller( MultiCurrencyRuntimeArbiter::OWNER_BUILTIN, 2, 'storefront', 'storefront', $switcher );

		add_filter( 'wcpay_multi_currency_should_disable_currency_switching', '__return_true' );

		$defaults = array(
			'wrap_before' => '<div class="storefront-breadcrumb"><nav class="woocommerce-breadcrumb">',
		);
		$result   = $sut->handle_woocommerce_breadcrumb_defaults( $defaults );

		$this->assertSame( $defaults, $result );
		$this->assertTrue( $switcher->last_switching_disabled );
	}

	/**
	 * @testdox Should show the switcher on the order-pay page when a callback turns the switching decision off.
	 *
	 * Client 11.1.0 seeds the decision with true on the order-pay page and returns the filter's answer
	 * (`includes/multi-currency/Compatibility.php:109-146`), read by the widget Storefront renders (`CurrencySwitcherWidget.php:80`).
	 */
	public function test_order_pay_page_switcher_follows_the_switching_decision_filter(): void {
		update_option( 'wcpay_multi_currency_enable_storefront_switcher', 'yes' );
		$_GET['pay_for_order'] = 'true';
		$switcher              = $this->create_switcher_service( '<div>Switcher</div>' );
		$sut                   = $this->create_controller( MultiCurrencyRuntimeArbiter::OWNER_BUILTIN, 2, 'storefront', 'storefront', $switcher );
		$seen                  = array();
		add_filter(
			'wcpay_multi_currency_should_disable_currency_switching',
			static function ( $should_disable ) use ( &$seen ) {
				$seen[] = $should_disable;
				return false;
			}
		);

		$result = $sut->handle_woocommerce_breadcrumb_defaults( array( 'wrap_before' => '<div class="storefront-breadcrumb"><nav class="woocommerce-breadcrumb">' ) );

		$this->assertSame( array( true ), $seen );
		$this->assertSame( '<div class="storefront-breadcrumb"><div>Switcher</div><nav class="woocommerce-breadcrumb">', $result['wrap_before'] );
	}

	/**
	 * @testdox Should pass the default widget args to the switcher and leave a breadcrumb without a nav unchanged.
	 */
	public function test_uses_default_widget_args_and_leaves_a_breadcrumb_without_a_nav(): void {
		$switcher = $this->create_switcher_service( '<div>Switcher</div>' );
		$sut      = $this->create_controller( MultiCurrencyRuntimeArbiter::OWNER_BUILTIN, 2, 'storefront', 'storefront', $switcher );

		$result = $sut->handle_woocommerce_breadcrumb_defaults( array( 'wrap_before' => '<span class="trail">Home</span>' ) );

		$this->assertSame( array( 'wrap_before' => '<span class="trail">Home</span>' ), $result );
		$this->assertSame(
			array(
				'before_widget' => '<div id="woocommerce-payments-multi-currency-storefront-widget" class="woocommerce-breadcrumb">',
				'after_widget'  => '</div>',
			),
			$switcher->last_args
		);
	}

	/**
	 * @testdox Should add the default Storefront inline CSS to the Storefront stylesheet.
	 */
	public function test_enqueues_default_storefront_inline_css(): void {
		$sut = $this->create_controller( MultiCurrencyRuntimeArbiter::OWNER_BUILTIN );
		wp_register_style( 'storefront-style', false, array(), '1.0.0' );

		$sut->handle_wp_enqueue_scripts();

		$style_data = wp_styles()->get_data( 'storefront-style', 'after' );
		$this->assertIsArray( $style_data );
		$this->assertStringContainsString( '#woocommerce-payments-multi-currency-storefront-widget {', $style_data[0] );
		$this->assertStringContainsString( 'float: right;', $style_data[0] );
		$this->assertStringContainsString( '#woocommerce-payments-multi-currency-storefront-widget form {', $style_data[0] );
		$this->assertStringContainsString( 'margin: 0;', $style_data[0] );
	}

	/**
	 * @testdox Should enqueue filtered Storefront inline CSS.
	 */
	public function test_enqueues_filtered_storefront_inline_css(): void {
		$sut = $this->create_controller( MultiCurrencyRuntimeArbiter::OWNER_BUILTIN );

		wp_register_style( 'storefront-style', false, array(), '1.0.0' );
		add_filter(
			'wcpay_multi_currency_storefront_widget_css',
			static function () {
				return '#woocommerce-payments-multi-currency-storefront-widget { float: left; }';
			}
		);

		$sut->handle_wp_enqueue_scripts();

		$style_data = wp_styles()->get_data( 'storefront-style', 'after' );

		$this->assertIsArray( $style_data );
		$this->assertSame( '#woocommerce-payments-multi-currency-storefront-widget { float: left; }', $style_data[0] );
	}

	/**
	 * Create a Storefront integration controller.
	 *
	 * @param string                $owner                  Runtime owner.
	 * @param int                   $enabled_currency_count Enabled currency count.
	 * @param string                $stylesheet             Theme stylesheet.
	 * @param string                $template               Theme template.
	 * @param SwitcherRenderer|null $switcher_service       Switcher service.
	 * @param StateBuilder|null     $state_builder          State builder; one with the enabled currency count by default.
	 * @return MultiCurrencyStorefrontIntegrationController
	 */
	private function create_controller(
		string $owner,
		int $enabled_currency_count = 2,
		string $stylesheet = 'storefront',
		string $template = 'storefront',
		?SwitcherRenderer $switcher_service = null,
		?StateBuilder $state_builder = null
	): MultiCurrencyStorefrontIntegrationController {
		$controller = new MultiCurrencyStorefrontIntegrationController();
		$controller->init(
			$this->create_arbiter( $owner ),
			ServiceFactories::state_builder( $this, $state_builder ?? $this->create_state_builder( $enabled_currency_count ) ),
			ServiceFactories::with( $this, array( 'create_switcher_renderer' => $switcher_service ) ),
			wc_get_container()->get( MultiCurrencyCompatibilityController::class )
		);
		$this->use_theme( $stylesheet, $template );

		return $controller;
	}

	/**
	 * Make the given theme the active one, through the stylesheet filter.
	 *
	 * A theme that is not installed reads as its own template; the fixture child theme names Storefront as its template.
	 *
	 * @param string $stylesheet Theme stylesheet.
	 * @param string $template   Theme template the stylesheet has.
	 */
	private function use_theme( string $stylesheet, string $template ): void {
		global $wp_theme_directories;

		if ( null === $this->theme_directories ) {
			$this->theme_directories = (array) $wp_theme_directories;
		}
		register_theme_directory( __DIR__ . '/Fixtures/themes' );
		search_theme_directories( true );
		add_filter( 'stylesheet', static fn(): string => $stylesheet );

		if ( wp_get_theme()->get_template() !== $template ) {
			throw new \LogicException( 'The active theme does not have the template the case names.' );
		}
	}

	/**
	 * Create a static runtime arbiter.
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
			 * Get the runtime owner.
			 *
			 * @return string
			 */
			public function get_runtime_owner(): string {
				return $this->owner;
			}

			/**
			 * Tell whether core should register.
			 *
			 * @return bool
			 */
			public function is_builtin_owner(): bool {
				return MultiCurrencyRuntimeArbiter::OWNER_BUILTIN === $this->owner;
			}
		};
	}

	/**
	 * Create a state builder test double.
	 *
	 * @param int $enabled_currency_count Enabled currency count.
	 * @return StateBuilder&object{builds: int}
	 */
	private function create_state_builder( int $enabled_currency_count ): StateBuilder {
		$state = $this->create_state( $enabled_currency_count );

		return new class( $state ) extends StateBuilder {
			/**
			 * Number of build() calls.
			 *
			 * @var int
			 */
			public int $builds = 0;

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
			 * Build the state.
			 *
			 * @return MultiCurrencyState
			 */
			public function build(): MultiCurrencyState {
				++$this->builds;

				return $this->state;
			}
		};
	}

	/**
	 * Create a multi-currency state snapshot.
	 *
	 * @param int $enabled_currency_count Enabled currency count.
	 * @return MultiCurrencyState
	 */
	private function create_state( int $enabled_currency_count ): MultiCurrencyState {
		$localization = $this->create_localization_service();
		$usd          = new MultiCurrencyCurrency( $localization, 'USD', 1.0, true );
		$enabled      = array( 'USD' => $usd );

		if ( 1 < $enabled_currency_count ) {
			$enabled['CAD'] = new MultiCurrencyCurrency( $localization, 'CAD', 1.25, false );
		}

		return new MultiCurrencyState( $enabled, $enabled, $usd, $usd );
	}

	/**
	 * Create a localization service test double.
	 *
	 * @return MultiCurrencyLocalizationInterface
	 */
	private function create_localization_service(): MultiCurrencyLocalizationInterface {
		return new class() implements MultiCurrencyLocalizationInterface {
			/**
			 * Get a currency format.
			 *
			 * @param string $currency_code Currency code.
			 * @return array<string,mixed>
			 */
			public function get_currency_format( $currency_code ): array {
				return array(
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
				return array();
			}
		};
	}

	/**
	 * Create a switcher projection service test double.
	 *
	 * @param string $markup Markup to return.
	 * @return SwitcherRenderer&object{last_instance: array<string,mixed>, last_args: array<string,mixed>, last_query_args: array<string,mixed>, last_switching_disabled: bool}
	 */
	private function create_switcher_service( string $markup ): SwitcherRenderer {
		return new class( $markup ) extends SwitcherRenderer {
			/**
			 * Last widget instance.
			 *
			 * @var array<string,mixed>
			 */
			public array $last_instance = array();

			/**
			 * Last widget args.
			 *
			 * @var array<string,mixed>
			 */
			public array $last_args = array();

			/**
			 * Last query args.
			 *
			 * @var array<string,mixed>
			 */
			public array $last_query_args = array();

			/**
			 * Last switching-disabled flag.
			 *
			 * @var bool
			 */
			public bool $last_switching_disabled = false;

			/**
			 * Markup to return.
			 *
			 * @var string
			 */
			private string $markup;

			/**
			 * Constructor.
			 *
			 * @param string $markup Markup to return.
			 */
			public function __construct( string $markup ) {
				$this->markup = $markup;
			}

			/**
			 * Project widget markup.
			 *
			 * @param array<string,mixed> $instance           Widget instance.
			 * @param array<string,mixed> $args               Widget args.
			 * @param array<string,mixed> $query_args         Query args.
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

				return $switching_disabled ? '' : $this->markup;
			}
		};
	}
}
