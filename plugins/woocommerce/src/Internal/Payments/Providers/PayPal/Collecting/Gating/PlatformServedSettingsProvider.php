<?php
/**
 * PlatformServedSettingsProvider class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\Gating;

use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\State\CollectingState;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Settings\Data\GeneralSettings;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Settings\Data\OnboardingProfile;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Settings\Data\PayLaterMessagingSettings;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Settings\Data\PaymentSettings;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Settings\Data\SettingsModel;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Settings\Data\SettingsProvider;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Settings\Data\StylingSettings;

/**
 * The wallet's settings provider for a store the platform serves: saved PayPal and Venmo always reads as off, and the
 * merchant email is the payee's.
 *
 * Every checkout reader of the setting (buttons, block method, vault component, Subscriptions mode, SDK v6) asks the
 * provider, so vaulting stays inert. The merchant's stored setting is only read, never changed.
 *
 * @since 11.3.0
 * @internal POC component for the PayPal Wallet in core proof of concept.
 */
class PlatformServedSettingsProvider extends SettingsProvider {

	/**
	 * The collecting state, which holds the payee.
	 *
	 * @var CollectingState
	 */
	private CollectingState $state;

	/**
	 * Constructor.
	 *
	 * @param GeneralSettings           $general_settings            The general settings.
	 * @param OnboardingProfile         $onboarding_profile          The onboarding profile.
	 * @param PaymentSettings           $payment_settings            The payment settings.
	 * @param SettingsModel             $settings_model              The settings model.
	 * @param StylingSettings           $styling_settings            The styling settings.
	 * @param PayLaterMessagingSettings $paylater_messaging_settings The Pay Later messaging settings.
	 * @param CollectingState           $state                       The collecting state, which holds the payee.
	 */
	public function __construct(
		GeneralSettings $general_settings,
		OnboardingProfile $onboarding_profile,
		PaymentSettings $payment_settings,
		SettingsModel $settings_model,
		StylingSettings $styling_settings,
		PayLaterMessagingSettings $paylater_messaging_settings,
		CollectingState $state
	) {
		parent::__construct( $general_settings, $onboarding_profile, $payment_settings, $settings_model, $styling_settings, $paylater_messaging_settings );
		$this->state = $state;
	}

	/**
	 * Saved PayPal and Venmo is off while the platform serves the store.
	 *
	 * @since 11.3.0
	 *
	 * @return bool Always false.
	 */
	public function save_paypal_and_venmo(): bool {
		return false;
	}

	/**
	 * The payee's email: the store has no first-party merchant, and the wallet's gateway disabler hides the gateway
	 * from every checkout when this is empty.
	 *
	 * @since 11.3.0
	 *
	 * @return string
	 */
	public function merchant_email(): string {
		return $this->state->payee_email();
	}
}
