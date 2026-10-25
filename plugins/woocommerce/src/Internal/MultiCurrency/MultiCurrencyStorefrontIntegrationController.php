<?php
/**
 * MultiCurrencyStorefrontIntegrationController class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\MultiCurrency;

use Automattic\WooCommerce\Internal\MultiCurrency\Services\MultiCurrencyServiceFactory;
use Automattic\WooCommerce\Internal\MultiCurrency\Services\MultiCurrencyStateBuilder;
use Automattic\WooCommerce\Internal\MultiCurrency\Services\MultiCurrencyStateBuilderFactory;
use Automattic\WooCommerce\Internal\MultiCurrency\Services\MultiCurrencySwitcherRenderer;
use Automattic\WooCommerce\Internal\RegisterHooksInterface;
use Automattic\WooCommerce\Internal\MultiCurrency\Services\MultiCurrencyRequestContext;

/**
 * Registers native Storefront multi-currency switcher hooks when core owns multi-currency.
 *
 * @since 11.0.0
 * @internal Transitional internal component for the native multi-currency runtime.
 */
class MultiCurrencyStorefrontIntegrationController implements RegisterHooksInterface {

	private const THEME_STOREFRONT = 'storefront';
	private const STYLE_HANDLE     = 'storefront-style';
	private const WIDGET_ID        = 'woocommerce-payments-multi-currency-storefront-widget';
	private const WIDGET_CSS       = '
			#' . self::WIDGET_ID . ' {
				float: right;
			}
			#' . self::WIDGET_ID . ' form {
				margin: 0;
			}
		';

	/**
	 * Runtime owner arbiter.
	 *
	 * @var MultiCurrencyRuntimeArbiter
	 */
	private MultiCurrencyRuntimeArbiter $arbiter;

	/**
	 * State builder.
	 *
	 * @var MultiCurrencyStateBuilder|null
	 */
	private ?MultiCurrencyStateBuilder $state_builder = null;

	/**
	 * State builder factory.
	 *
	 * @var MultiCurrencyStateBuilderFactory
	 */
	private MultiCurrencyStateBuilderFactory $state_builder_factory;

	/**
	 * Multi-Currency service factory.
	 *
	 * @var MultiCurrencyServiceFactory
	 */
	private MultiCurrencyServiceFactory $service_factory;

	/**
	 * Compatibility controller.
	 *
	 * @var MultiCurrencyCompatibilityController
	 */
	private MultiCurrencyCompatibilityController $compatibility_controller;

	/**
	 * Switcher projection service.
	 *
	 * @var MultiCurrencySwitcherRenderer|null
	 */
	private ?MultiCurrencySwitcherRenderer $switcher_renderer = null;

	/**
	 * Initialize the class instance.
	 *
	 * @internal
	 *
	 * @param MultiCurrencyRuntimeArbiter          $arbiter                  Runtime owner arbiter.
	 * @param MultiCurrencyStateBuilderFactory     $state_builder_factory    State builder factory.
	 * @param MultiCurrencyServiceFactory          $service_factory          Multi-Currency service factory.
	 * @param MultiCurrencyCompatibilityController $compatibility_controller Compatibility controller.
	 */
	final public function init(
		MultiCurrencyRuntimeArbiter $arbiter,
		MultiCurrencyStateBuilderFactory $state_builder_factory,
		MultiCurrencyServiceFactory $service_factory,
		MultiCurrencyCompatibilityController $compatibility_controller
	): void {
		$this->arbiter                  = $arbiter;
		$this->state_builder_factory    = $state_builder_factory;
		$this->service_factory          = $service_factory;
		$this->compatibility_controller = $compatibility_controller;
	}

	/**
	 * Defer the Storefront switcher decision to `init`, as the client does (client 11.1.0 `MultiCurrency.php:258,374-376`).
	 *
	 * This runs inside the WooCommerce constructor, where building currency state calls WC() and constructs WooCommerce again.
	 */
	public function register() {
		if ( ! $this->arbiter->is_builtin_owner() ) {
			return;
		}

		add_action( 'init', array( $this, 'handle_init' ) );
	}

	/**
	 * Register Storefront switcher hooks when Storefront is active and more than one currency is enabled.
	 *
	 * @internal
	 */
	public function handle_init(): void {
		$theme = wp_get_theme();
		if ( self::THEME_STOREFRONT !== $theme->get_stylesheet() && self::THEME_STOREFRONT !== $theme->get_template() ) {
			return;
		}

		if ( 1 >= count( $this->get_state_builder()->build()->get_enabled_currencies() ) ) {
			return;
		}

		// The settings preview's choice wins over the saved setting.
		$simulated_choice = $this->get_simulated_storefront_switcher_choice();
		if ( ! ( $simulated_choice ?? $this->is_using_storefront_switcher() ) ) {
			return;
		}

		add_filter( 'woocommerce_breadcrumb_defaults', array( $this, 'handle_woocommerce_breadcrumb_defaults' ), 9999 );
		add_action( 'wp_enqueue_scripts', array( $this, 'handle_wp_enqueue_scripts' ), 50 );
	}

	/**
	 * Inject the native switcher into Storefront breadcrumb defaults.
	 *
	 * @internal
	 *
	 * @param array<string,mixed> $defaults Breadcrumb defaults.
	 * @return array<string,mixed>
	 */
	public function handle_woocommerce_breadcrumb_defaults( array $defaults ): array {
		$instance = array();
		/**
		 * Filters the native multi-currency Storefront widget instance.
		 *
		 * @param array<string,mixed> $instance Widget instance.
		 *
		 * @since 11.0.0
		 */
		$instance = apply_filters( MultiCurrencyStateBuilder::FILTER_PREFIX . 'storefront_widget_instance', $instance );
		$instance = is_array( $instance ) ? $instance : array();

		$default_args = array(
			'before_widget' => '<div id="' . self::WIDGET_ID . '" class="woocommerce-breadcrumb">',
			'after_widget'  => '</div>',
		);
		/**
		 * Filters the native multi-currency Storefront widget args.
		 *
		 * @param array<string,string> $args Widget args.
		 *
		 * @since 11.0.0
		 */
		$args = apply_filters( MultiCurrencyStateBuilder::FILTER_PREFIX . 'storefront_widget_args', $default_args );
		$args = is_array( $args ) ? $args : $default_args;

		$widget_markup = $this->get_switcher_renderer()->get_widget_markup(
			$instance,
			$args,
			MultiCurrencyRequestContext::get_current_query_args(),
			$this->compatibility_controller->should_disable_currency_switching()
		);

		if ( ! isset( $defaults['wrap_before'] ) || ! is_string( $defaults['wrap_before'] ) ) {
			return $defaults;
		}

		$defaults['wrap_before'] = str_replace( '<nav', $widget_markup . '<nav', $defaults['wrap_before'] );

		return $defaults;
	}

	/**
	 * Add Storefront inline CSS for the native switcher.
	 *
	 * @internal
	 */
	public function handle_wp_enqueue_scripts(): void {
		/**
		 * Filters the native multi-currency Storefront widget CSS.
		 *
		 * @param string $css Inline CSS.
		 *
		 * @since 11.0.0
		 */
		$css = apply_filters( MultiCurrencyStateBuilder::FILTER_PREFIX . 'storefront_widget_css', self::WIDGET_CSS );
		if ( ! is_string( $css ) ) {
			return;
		}

		wp_add_inline_style( self::STYLE_HANDLE, $css );
	}

	/**
	 * Get the state builder.
	 *
	 * @return MultiCurrencyStateBuilder
	 */
	private function get_state_builder(): MultiCurrencyStateBuilder {
		if ( null === $this->state_builder ) {
			$this->state_builder = $this->state_builder_factory->create();
		}

		return $this->state_builder;
	}

	/**
	 * Get the switcher projection service.
	 *
	 * @return MultiCurrencySwitcherRenderer
	 */
	private function get_switcher_renderer(): MultiCurrencySwitcherRenderer {
		if ( null === $this->switcher_renderer ) {
			$this->switcher_renderer = $this->service_factory->create_switcher_renderer();
		}

		return $this->switcher_renderer;
	}

	/**
	 * Tell whether the Storefront switcher setting is enabled.
	 *
	 * @return bool
	 */
	private function is_using_storefront_switcher(): bool {
		return 'yes' === get_option( MultiCurrencyStateBuilder::OPTION_PREFIX . '_enable_storefront_switcher', 'no' );
	}

	/**
	 * Get the settings preview's Storefront switcher choice from the query string.
	 *
	 * @return bool|null The previewed choice, or null outside a preview that sets one.
	 */
	private function get_simulated_storefront_switcher_choice(): ?bool {
		$query_args    = MultiCurrencyRequestContext::get_current_query_args();
		$is_simulation = filter_var( $query_args['is_mc_onboarding_simulation'] ?? false, FILTER_VALIDATE_BOOLEAN );

		if ( ! $is_simulation || ! array_key_exists( 'enable_storefront_switcher', $query_args ) ) {
			return null;
		}

		return filter_var( $query_args['enable_storefront_switcher'], FILTER_VALIDATE_BOOLEAN );
	}
}
