<?php

declare( strict_types=1 );

namespace Automattic\WooCommerce\Admin\Features\OnboardingTasks\Tasks;

use Automattic\WooCommerce\Admin\Features\OnboardingTasks\Task;

/**
 * WooCommercePayments Task.
 *
 * Kept only so third-party references keep resolving; every method is an inert no-op and
 * WooCommerce never registers this task.
 *
 * @deprecated 9.9.0 The WooPayments onboarding task no longer exists; use the Payments task. Scheduled for removal in WooCommerce 12.0.0.
 */
class WooCommercePayments extends Task {

	/**
	 * ID.
	 *
	 * @deprecated 9.9.0 The WooPayments onboarding task no longer exists.
	 *
	 * @return string The task ID.
	 */
	public function get_id() {
		wc_deprecated_function( __METHOD__, '9.9.0', Payments::class );

		return 'woocommerce-payments';
	}

	/**
	 * Contextual image URL.
	 *
	 * @deprecated 9.9.0 The WooPayments onboarding task no longer exists.
	 *
	 * @return string Always empty.
	 */
	public function get_image_url() {
		wc_deprecated_function( __METHOD__, '9.9.0', Payments::class );

		return '';
	}

	/**
	 * Alt text for the contextual image.
	 *
	 * @deprecated 9.9.0 The WooPayments onboarding task no longer exists.
	 *
	 * @return string Always empty.
	 */
	public function get_image_alt() {
		wc_deprecated_function( __METHOD__, '9.9.0', Payments::class );

		return '';
	}

	/**
	 * Title.
	 *
	 * @deprecated 9.9.0 The WooPayments onboarding task no longer exists.
	 *
	 * @return string Always empty.
	 */
	public function get_title() {
		wc_deprecated_function( __METHOD__, '9.9.0', Payments::class );

		return '';
	}

	/**
	 * Badge.
	 *
	 * @deprecated 9.9.0 The WooPayments onboarding task no longer exists.
	 *
	 * @return string Always empty.
	 */
	public function get_badge() {
		wc_deprecated_function( __METHOD__, '9.9.0', Payments::class );

		return '';
	}

	/**
	 * Content.
	 *
	 * @deprecated 9.9.0 The WooPayments onboarding task no longer exists.
	 *
	 * @return string Always empty.
	 */
	public function get_content() {
		wc_deprecated_function( __METHOD__, '9.9.0', Payments::class );

		return '';
	}

	/**
	 * Additional data.
	 *
	 * @deprecated 9.9.0 The WooPayments onboarding task no longer exists.
	 *
	 * @return mixed Always null.
	 */
	public function get_additional_data() {
		wc_deprecated_function( __METHOD__, '9.9.0', Payments::class );

		return null;
	}

	/**
	 * Time.
	 *
	 * @deprecated 9.9.0 The WooPayments onboarding task no longer exists.
	 *
	 * @return string Always empty.
	 */
	public function get_time() {
		wc_deprecated_function( __METHOD__, '9.9.0', Payments::class );

		return '';
	}

	/**
	 * Action label.
	 *
	 * @deprecated 9.9.0 The WooPayments onboarding task no longer exists.
	 *
	 * @return string Always empty.
	 */
	public function get_action_label() {
		wc_deprecated_function( __METHOD__, '9.9.0', Payments::class );

		return '';
	}

	/**
	 * Task completion.
	 *
	 * @deprecated 9.9.0 The WooPayments onboarding task no longer exists.
	 *
	 * @return bool Always false.
	 */
	public function is_complete() {
		wc_deprecated_function( __METHOD__, '9.9.0', Payments::class );

		return false;
	}

	/**
	 * Task visibility: the task is never shown.
	 *
	 * @deprecated 9.9.0 The WooPayments onboarding task no longer exists.
	 *
	 * @return bool Always false.
	 */
	public function can_view() {
		wc_deprecated_function( __METHOD__, '9.9.0', Payments::class );

		return false;
	}

	/**
	 * Check if the WooPayments plugin was requested during onboarding.
	 *
	 * @deprecated 9.9.0 The WooPayments onboarding task no longer exists.
	 *
	 * @return bool Always false.
	 */
	public static function is_requested() {
		wc_deprecated_function( __METHOD__, '9.9.0', Payments::class );

		return false;
	}

	/**
	 * Check if the WooPayments plugin is installed.
	 *
	 * @deprecated 9.9.0 The WooPayments onboarding task no longer exists.
	 *
	 * @return bool Always false.
	 */
	public static function is_installed() {
		wc_deprecated_function( __METHOD__, '9.9.0', Payments::class );

		return false;
	}

	/**
	 * Check if the WooPayments plugin is active.
	 *
	 * @deprecated 9.9.0 The WooPayments onboarding task no longer exists.
	 *
	 * @return bool Always false.
	 */
	public static function is_wcpay_active() {
		wc_deprecated_function( __METHOD__, '9.9.0', Payments::class );

		return false;
	}

	/**
	 * Check if WooPayments is connected.
	 *
	 * @deprecated 9.9.0 The WooPayments onboarding task no longer exists.
	 *
	 * @return bool Always false.
	 */
	public static function is_connected() {
		wc_deprecated_function( __METHOD__, '9.9.0', Payments::class );

		return false;
	}

	/**
	 * Check if WooPayments needs setup.
	 *
	 * @deprecated 9.9.0 The WooPayments onboarding task no longer exists.
	 *
	 * @return bool Always false.
	 */
	public static function is_account_partially_onboarded() {
		wc_deprecated_function( __METHOD__, '9.9.0', Payments::class );

		return false;
	}

	/**
	 * Get the WooPayments payment gateway suggestion.
	 *
	 * @deprecated 9.9.0 The WooPayments onboarding task no longer exists.
	 *
	 * @return object|null Always null.
	 */
	public static function get_suggestion() {
		wc_deprecated_function( __METHOD__, '9.9.0', Payments::class );

		return null;
	}

	/**
	 * Check if the store location is in a WooPayments supported country.
	 *
	 * @deprecated 9.9.0 The WooPayments onboarding task no longer exists.
	 *
	 * @return bool Always false.
	 */
	public static function is_supported() {
		wc_deprecated_function( __METHOD__, '9.9.0', Payments::class );

		return false;
	}

	/**
	 * Check if the store has any enabled ecommerce gateways, other than WooPayments.
	 *
	 * @deprecated 9.9.0 The WooPayments onboarding task no longer exists.
	 *
	 * @return bool Always false.
	 */
	public static function has_other_ecommerce_gateways(): bool {
		wc_deprecated_function( __METHOD__, '9.9.0', Payments::class );

		return false;
	}

	/**
	 * The task action URL.
	 *
	 * @deprecated 9.9.0 The WooPayments onboarding task no longer exists.
	 *
	 * @return string Always empty.
	 */
	public function get_action_url() {
		wc_deprecated_function( __METHOD__, '9.9.0', Payments::class );

		return '';
	}
}
