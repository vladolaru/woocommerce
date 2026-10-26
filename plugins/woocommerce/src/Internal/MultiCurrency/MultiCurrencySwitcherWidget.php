<?php
/**
 * MultiCurrencySwitcherWidget class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\MultiCurrency;

use Automattic\WooCommerce\Internal\MultiCurrency\Services\SwitcherRenderer;
use Automattic\WooCommerce\Internal\MultiCurrency\Services\RequestContext;

/**
 * The currency switcher widget: a select of the enabled currencies, with optional symbols and flags.
 *
 * @since 11.0.0
 * @internal
 */
class MultiCurrencySwitcherWidget extends \WC_Widget {

	/**
	 * Switcher renderer.
	 *
	 * @var SwitcherRenderer
	 */
	private SwitcherRenderer $switcher_renderer;

	/**
	 * Compatibility controller.
	 *
	 * @var MultiCurrencyCompatibilityController
	 */
	private MultiCurrencyCompatibilityController $compatibility_controller;

	/**
	 * Constructor.
	 *
	 * @param SwitcherRenderer                     $switcher_renderer Switcher renderer.
	 * @param MultiCurrencyCompatibilityController $compatibility_controller    Compatibility controller.
	 */
	public function __construct(
		SwitcherRenderer $switcher_renderer,
		MultiCurrencyCompatibilityController $compatibility_controller
	) {
		$this->switcher_renderer        = $switcher_renderer;
		$this->compatibility_controller = $compatibility_controller;

		$this->widget_cssclass    = 'woocommerce widget_currency_switcher';
		$this->widget_description = __( 'Let customers switch between enabled currencies.', 'woocommerce' );
		$this->widget_id          = 'currency_switcher_widget';
		$this->widget_name        = __( 'Currency switcher widget', 'woocommerce' );
		$this->settings           = array(
			'title'  => array(
				'type'  => 'text',
				'std'   => '',
				'label' => __( 'Title', 'woocommerce' ),
			),
			'symbol' => array(
				'type'  => 'checkbox',
				'std'   => true,
				'label' => __( 'Display currency symbols', 'woocommerce' ),
			),
			'flag'   => array(
				'type'  => 'checkbox',
				'std'   => false,
				'label' => __( 'Display flags on supported devices', 'woocommerce' ),
			),
		);

		parent::__construct();
	}

	/**
	 * Output widget markup.
	 *
	 * @see \WP_Widget
	 *
	 * @param array<mixed> $args     Widget arguments.
	 * @param array<mixed> $instance Saved values from the database.
	 */
	public function widget( $args, $instance ): void {
		$markup = $this->switcher_renderer->get_widget_markup(
			is_array( $instance ) ? $instance : array(),
			is_array( $args ) ? $args : array(),
			RequestContext::get_current_query_args(),
			$this->compatibility_controller->should_disable_currency_switching()
		);

		echo $markup; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- The switcher renderer escapes the generated markup.
	}
}
