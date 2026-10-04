<?php
/**
 * PayPal Commerce eligibility service for WooCommerce.
 *
 * This file contains the FeaturesEligibilityService class which manages eligibility checks
 * for various PayPal Commerce features including saving PayPal and Venmo, alternative payment
 * methods, Google Pay, Apple Pay, and Pay Later.
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
	 * Whether alternative payment methods are eligible.
	 *
	 * @var bool
	 */
	private bool $is_apm_eligible;

	/**
	 * Whether Google Pay is eligible.
	 *
	 * @var callable
	 */
	private $check_google_pay_eligible;

	/**
	 * Whether Apple Pay is eligible.
	 *
	 * @var callable
	 */
	private $check_apple_pay_eligible;

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
	 * Whether the Pay with Crypto eligibility has been checked.
	 *
	 * @var bool
	 */
	private bool $is_pwc_eligibility_checked;

	/**
	 * Whether Pay upon Invoice is eligible.
	 *
	 * @var bool
	 */
	private bool $is_pui_eligible;

	/**
	 * Constructor.
	 *
	 * @param bool     $is_save_paypal_eligible If saving PayPal and Venmo is eligible.
	 * @param bool     $is_apm_eligible If alternative payment methods are eligible.
	 * @param callable $check_google_pay_eligible If Google Pay is eligible.
	 * @param callable $check_apple_pay_eligible If Apple Pay is eligible.
	 * @param bool     $is_pay_later_eligible If Pay Later is eligible.
	 * @param bool     $is_installments_eligible If Installments is eligible.
	 * @param bool     $is_pwc_eligibility_checked If Pay With Crypto eligibility has been checked.
	 * @param bool     $is_pui_eligible If Pay upon Invoice is eligible.
	 */
	public function __construct(
		bool $is_save_paypal_eligible,
		bool $is_apm_eligible,
		callable $check_google_pay_eligible,
		callable $check_apple_pay_eligible,
		bool $is_pay_later_eligible,
		bool $is_installments_eligible,
		bool $is_pwc_eligibility_checked,
		bool $is_pui_eligible
	) {
		$this->is_save_paypal_eligible    = $is_save_paypal_eligible;
		$this->is_apm_eligible            = $is_apm_eligible;
		$this->check_google_pay_eligible  = $check_google_pay_eligible;
		$this->check_apple_pay_eligible   = $check_apple_pay_eligible;
		$this->is_pay_later_eligible      = $is_pay_later_eligible;
		$this->is_installments_eligible   = $is_installments_eligible;
		$this->is_pwc_eligibility_checked = $is_pwc_eligibility_checked;
		$this->is_pui_eligible            = $is_pui_eligible;
	}

	/**
	 * Returns all eligibility checks as callables.
	 *
	 * @return array<string, callable>
	 */
	public function get_eligibility_checks(): array {
		return array(
			FeaturesDefinition::FEATURE_SAVE_PAYPAL_AND_VENMO => fn() => $this->is_save_paypal_eligible,
			FeaturesDefinition::FEATURE_ALTERNATIVE_PAYMENT_METHODS => fn() => $this->is_apm_eligible,
			FeaturesDefinition::FEATURE_GOOGLE_PAY       => $this->check_google_pay_eligible,
			FeaturesDefinition::FEATURE_APPLE_PAY        => $this->check_apple_pay_eligible,
			FeaturesDefinition::FEATURE_PAY_LATER_MESSAGING => fn() => $this->is_pay_later_eligible,
			FeaturesDefinition::FEATURE_INSTALLMENTS     => fn() => $this->is_installments_eligible,
			FeaturesDefinition::FEATURE_PAY_WITH_CRYPTO  => fn() => $this->is_pwc_eligibility_checked,
			FeaturesDefinition::FEATURE_PAY_UPON_INVOICE => fn() => $this->is_pui_eligible,
		);
	}
}
