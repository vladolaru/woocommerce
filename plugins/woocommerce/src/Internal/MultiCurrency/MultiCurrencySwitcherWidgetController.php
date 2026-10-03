<?php
/**
 * MultiCurrencySwitcherWidgetController class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\MultiCurrency;

use Automattic\WooCommerce\Internal\MultiCurrency\Services\MultiCurrencyRuntimeServiceFactory;
use Automattic\WooCommerce\Internal\MultiCurrency\Services\MultiCurrencySwitcherProjectionService;
use Automattic\WooCommerce\Internal\RegisterHooksInterface;

/**
 * Registers native multi-currency switcher widget hooks when core owns multi-currency.
 *
 * @since 11.0.0
 * @internal Transitional internal component for the native multi-currency runtime.
 */
class MultiCurrencySwitcherWidgetController implements RegisterHooksInterface {

	/**
	 * Runtime owner arbiter.
	 *
	 * @var MultiCurrencyRuntimeArbiter
	 */
	private MultiCurrencyRuntimeArbiter $arbiter;

	/**
	 * Compatibility controller.
	 *
	 * @var MultiCurrencyCompatibilityController
	 */
	private MultiCurrencyCompatibilityController $compatibility_controller;

	/**
	 * Switcher projection service.
	 *
	 * @var MultiCurrencySwitcherProjectionService|null
	 */
	private ?MultiCurrencySwitcherProjectionService $switcher_projection_service = null;

	/**
	 * Registered widget instance.
	 *
	 * @var MultiCurrencySwitcherWidget|null
	 */
	private ?MultiCurrencySwitcherWidget $widget = null;

	/**
	 * Runtime service factory.
	 *
	 * @var MultiCurrencyRuntimeServiceFactory
	 */
	private MultiCurrencyRuntimeServiceFactory $runtime_service_factory;

	/**
	 * Initialize the class instance.
	 *
	 * @internal
	 *
	 * @param MultiCurrencyRuntimeArbiter          $arbiter                  Runtime owner arbiter.
	 * @param MultiCurrencyCompatibilityController $compatibility_controller Compatibility controller.
	 * @param MultiCurrencyRuntimeServiceFactory   $runtime_service_factory  Runtime service factory.
	 */
	final public function init(
		MultiCurrencyRuntimeArbiter $arbiter,
		MultiCurrencyCompatibilityController $compatibility_controller,
		MultiCurrencyRuntimeServiceFactory $runtime_service_factory
	): void {
		$this->arbiter                  = $arbiter;
		$this->compatibility_controller = $compatibility_controller;
		$this->runtime_service_factory  = $runtime_service_factory;
	}

	/**
	 * Set the switcher projection service.
	 *
	 * @internal Used by tests and future explicit bootstrap definitions.
	 *
	 * @param MultiCurrencySwitcherProjectionService $switcher_projection_service Switcher projection service.
	 */
	public function set_switcher_projection_service( MultiCurrencySwitcherProjectionService $switcher_projection_service ): void {
		$this->switcher_projection_service = $switcher_projection_service;
	}

	/**
	 * Register switcher widget hooks.
	 */
	public function register() {
		if ( ! $this->arbiter->should_core_register() ) {
			return;
		}

		$this->add_action_once( 'widgets_init', array( $this, 'handle_widgets_init' ) );
	}

	/**
	 * Register the native switcher widget.
	 *
	 * @internal
	 */
	public function handle_widgets_init(): void {
		if ( null !== $this->widget ) {
			return;
		}

		$this->widget = new MultiCurrencySwitcherWidget(
			$this->get_switcher_projection_service(),
			$this->compatibility_controller
		);

		register_widget( $this->widget );
	}

	/**
	 * Get the registered widget instance.
	 *
	 * @return MultiCurrencySwitcherWidget|null
	 */
	public function get_registered_widget(): ?MultiCurrencySwitcherWidget {
		return $this->widget;
	}

	/**
	 * Get the registered switcher widget markup.
	 *
	 * @since 11.2.0
	 *
	 * @param array $instance Widget instance settings.
	 * @param array $args     Widget arguments.
	 * @return string Currency switcher widget markup.
	 */
	public function get_switcher_widget_markup( array $instance = array(), array $args = array() ): string {
		global $wp_widget_factory;

		if ( MultiCurrencyRuntimeArbiter::OWNER_PLUGIN === $this->arbiter->get_runtime_owner() && function_exists( 'WC_Payments_Multi_Currency' ) ) {
			$plugin_multi_currency = \WC_Payments_Multi_Currency(); // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.FunctionNameInvalid -- WooPayments public compatibility facade.
			if ( is_object( $plugin_multi_currency ) && is_callable( array( $plugin_multi_currency, 'get_switcher_widget_markup' ) ) ) {
				return (string) call_user_func( array( $plugin_multi_currency, 'get_switcher_widget_markup' ), $instance, $args );
			}
		}

		if ( ! $this->arbiter->should_core_register() || ! is_object( $this->widget ) || ! is_object( $wp_widget_factory ) || ! isset( $wp_widget_factory->widgets ) || ! is_array( $wp_widget_factory->widgets ) ) {
			return '';
		}

		$widget_key = array_search( $this->widget, $wp_widget_factory->widgets, true );
		if ( false === $widget_key ) {
			return '';
		}

		/**
		 * Filters the currency switcher widget instance settings used by themes.
		 *
		 * @since 11.2.0
		 *
		 * @param array $instance Widget instance settings.
		 */
		$filtered_instance = apply_filters( 'wcpay_multi_currency_theme_widget_instance', $instance );
		$instance          = is_array( $filtered_instance ) ? $filtered_instance : $instance;

		/**
		 * Filters the currency switcher widget arguments used by themes.
		 *
		 * @since 11.2.0
		 *
		 * @param array $args Widget arguments.
		 */
		$filtered_args = apply_filters( 'wcpay_multi_currency_theme_widget_args', $args );
		$args          = is_array( $filtered_args ) ? $filtered_args : $args;
		$buffer_level  = ob_get_level();

		ob_start();
		try {
			the_widget( (string) $widget_key, $instance, $args );

			return (string) ob_get_clean();
		} finally {
			while ( ob_get_level() > $buffer_level ) {
				ob_end_clean();
			}
		}
	}

	/**
	 * Get the switcher projection service.
	 *
	 * @return MultiCurrencySwitcherProjectionService
	 */
	private function get_switcher_projection_service(): MultiCurrencySwitcherProjectionService {
		if ( null === $this->switcher_projection_service ) {
			$this->switcher_projection_service = $this->runtime_service_factory->create_switcher_projection_service();
		}

		return $this->switcher_projection_service;
	}

	/**
	 * Register an action only once for this controller instance.
	 *
	 * @param string   $hook          Hook name.
	 * @param callable $callback      Hook callback.
	 * @param int      $priority      Hook priority.
	 * @param int      $accepted_args Accepted argument count.
	 */
	private function add_action_once( string $hook, callable $callback, int $priority = 10, int $accepted_args = 1 ): void {
		if ( false === has_action( $hook, $callback ) ) {
			add_action( $hook, $callback, $priority, $accepted_args );
		}
	}
}
