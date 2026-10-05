<?php
/**
 * PayPal Commerce eligibility service for WooCommerce.
 *
 * This file contains the FeaturesEligibilityService class which manages eligibility checks
 * for various PayPal Commerce features including saving PayPal and Venmo and Pay Later.
 *
 * @package Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Settings\Service
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Settings\Service;

use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Settings\Data\Definition\FeaturesDefinition;

/**
 * Manages eligibility checks for various PayPal Commerce features.
 */
class FeaturesEligibilityService {
	/**
	 * Whether saving PayPal and Venmo is eligible.
	 *
	 * @var bool
	 */
	private bool $is_save_paypal_eligible;

	/**
	 * Whether Pay Later is eligible.
	 *
	 * @var bool
	 */
	private bool $is_pay_later_eligible;

	/**
	 * Whether Installments is eligible.
	 *
	 * @var bool
	 */
	private bool $is_installments_eligible;

	/**
	 * Constructor.
	 *
	 * @param bool $is_save_paypal_eligible If saving PayPal and Venmo is eligible.
	 * @param bool $is_pay_later_eligible If Pay Later is eligible.
	 * @param bool $is_installments_eligible If Installments is eligible.
	 */
	public function __construct(
		bool $is_save_paypal_eligible,
		bool $is_pay_later_eligible,
		bool $is_installments_eligible
	) {
		$this->is_save_paypal_eligible  = $is_save_paypal_eligible;
		$this->is_pay_later_eligible    = $is_pay_later_eligible;
		$this->is_installments_eligible = $is_installments_eligible;
	}

	/**
	 * Returns all eligibility checks as callables.
	 *
	 * @return array<string, callable>
	 */
	public function get_eligibility_checks(): array {
		return array(
			FeaturesDefinition::FEATURE_SAVE_PAYPAL_AND_VENMO => fn() => $this->is_save_paypal_eligible,
			FeaturesDefinition::FEATURE_PAY_LATER_MESSAGING => fn() => $this->is_pay_later_eligible,
			FeaturesDefinition::FEATURE_INSTALLMENTS => fn() => $this->is_installments_eligible,
		);
	}
}
