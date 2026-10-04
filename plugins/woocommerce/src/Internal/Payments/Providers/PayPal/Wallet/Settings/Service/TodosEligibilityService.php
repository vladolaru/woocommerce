<?php
/**
 * Eligibility service for Todos.
 *
 * This file contains the TodosEligibilityService class which manages eligibility checks
 * for various features including Pay Later messaging and PayPal buttons.
 *
 * @package Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Settings\Service
 */

declare(strict_types=1);

namespace Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Settings\Service;

/**
 * Manages eligibility checks for various PayPal Commerce features.
 */
class TodosEligibilityService {
	/**
	 * Whether Pay Later messaging is eligible.
	 *
	 * @var bool
	 */
	private bool $is_pay_later_messaging_eligible;

	/**
	 * Whether Pay Later messaging for product page is eligible.
	 *
	 * @var bool
	 */
	private bool $is_pay_later_messaging_product_eligible;

	/**
	 * Whether Pay Later messaging for cart is eligible.
	 *
	 * @var bool
	 */
	private bool $is_pay_later_messaging_cart_eligible;

	/**
	 * Whether Pay Later messaging for checkout is eligible.
	 *
	 * @var bool
	 */
	private bool $is_pay_later_messaging_checkout_eligible;

	/**
	 * Whether PayPal buttons for cart are eligible.
	 *
	 * @var bool
	 */
	private bool $is_paypal_buttons_cart_eligible;

	/**
	 * Whether PayPal buttons for block checkout are eligible.
	 *
	 * @var bool
	 */
	private bool $is_paypal_buttons_block_checkout_eligible;

	/**
	 * Whether PayPal buttons for product page are eligible.
	 *
	 * @var bool
	 */
	private bool $is_paypal_buttons_product_eligible;

	/**
	 * Whether enabling Installments is eligible.
	 *
	 * @var bool
	 */
	private bool $is_enable_installments_eligible;

	/**
	 * Whether enabling Working Capital is eligible.
	 *
	 * @var bool
	 */
	private bool $is_working_capital_eligible;

	/**
	 * Constructor.
	 *
	 * @param bool $is_pay_later_messaging_eligible     Whether Pay Later messaging is eligible.
	 * @param bool $is_pay_later_messaging_product_eligible Whether Pay Later messaging for product page is eligible.
	 * @param bool $is_pay_later_messaging_cart_eligible Whether Pay Later messaging for cart is eligible.
	 * @param bool $is_pay_later_messaging_checkout_eligible Whether Pay Later messaging for checkout is eligible.
	 * @param bool $is_paypal_buttons_cart_eligible     Whether PayPal buttons for cart are eligible.
	 * @param bool $is_paypal_buttons_block_checkout_eligible Whether PayPal buttons for block checkout are eligible.
	 * @param bool $is_paypal_buttons_product_eligible  Whether PayPal buttons for product page are eligible.
	 * @param bool $is_enable_installments_eligible     Whether enabling Installments is eligible.
	 * @param bool $is_working_capital_eligible         Whether applying for Working Capital is eligible.
	 */
	public function __construct(
		bool $is_pay_later_messaging_eligible,
		bool $is_pay_later_messaging_product_eligible,
		bool $is_pay_later_messaging_cart_eligible,
		bool $is_pay_later_messaging_checkout_eligible,
		bool $is_paypal_buttons_cart_eligible,
		bool $is_paypal_buttons_block_checkout_eligible,
		bool $is_paypal_buttons_product_eligible,
		bool $is_enable_installments_eligible,
		bool $is_working_capital_eligible
	) {
		$this->is_pay_later_messaging_eligible           = $is_pay_later_messaging_eligible;
		$this->is_pay_later_messaging_product_eligible   = $is_pay_later_messaging_product_eligible;
		$this->is_pay_later_messaging_cart_eligible      = $is_pay_later_messaging_cart_eligible;
		$this->is_pay_later_messaging_checkout_eligible  = $is_pay_later_messaging_checkout_eligible;
		$this->is_paypal_buttons_cart_eligible           = $is_paypal_buttons_cart_eligible;
		$this->is_paypal_buttons_block_checkout_eligible = $is_paypal_buttons_block_checkout_eligible;
		$this->is_paypal_buttons_product_eligible        = $is_paypal_buttons_product_eligible;
		$this->is_enable_installments_eligible           = $is_enable_installments_eligible;
		$this->is_working_capital_eligible               = $is_working_capital_eligible;
	}

	/**
	 * Returns all eligibility checks as callables.
	 *
	 * @return array<string, callable>
	 */
	public function get_eligibility_checks(): array {
		return array(
			'enable_pay_later_messaging'           => fn() => $this->is_pay_later_messaging_eligible,
			'add_pay_later_messaging_product_page' => fn() => $this->is_pay_later_messaging_product_eligible,
			'add_pay_later_messaging_cart'         => fn() => $this->is_pay_later_messaging_cart_eligible,
			'add_pay_later_messaging_checkout'     => fn() => $this->is_pay_later_messaging_checkout_eligible,
			'add_paypal_buttons_cart'              => fn() => $this->is_paypal_buttons_cart_eligible,
			'add_paypal_buttons_block_checkout'    => fn() => $this->is_paypal_buttons_block_checkout_eligible,
			'add_paypal_buttons_product'           => fn() => $this->is_paypal_buttons_product_eligible,
			'enable_installments'                  => fn() => $this->is_enable_installments_eligible,
			'apply_for_working_capital'            => fn() => $this->is_working_capital_eligible,
		);
	}
}
