<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments\WooPay;

use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPay\Eligibility;

/**
 * Recording WooPay eligibility rules for controller tests: they answer from the flags the tests set on the recording
 * session service, so one double carries a test's WooPay state.
 */
class RecordingWooPayEligibility extends Eligibility {

	/**
	 * Recording session service whose flags these rules answer from.
	 *
	 * @var RecordingWooPaySessionService
	 */
	public RecordingWooPaySessionService $flags;

	/**
	 * Answer from a recording session service's flags.
	 *
	 * @param RecordingWooPaySessionService $flags Recording session service.
	 */
	public function __construct( RecordingWooPaySessionService $flags ) {
		$this->flags = $flags;
	}

	/**
	 * Tell whether WooPay is enabled.
	 *
	 * @return bool
	 */
	public function is_woopay_enabled(): bool {
		return $this->flags->woopay_enabled;
	}

	/**
	 * Tell whether the WooPay button should be shown.
	 *
	 * @param string $context Express checkout context.
	 * @return bool
	 */
	public function should_show_woopay_button( string $context = 'checkout' ): bool {
		unset( $context );

		return $this->flags->should_show_woopay_button;
	}

	/**
	 * Tell whether WooPay save-user assets should load.
	 *
	 * @param string $context Express checkout context.
	 * @return bool
	 */
	public function should_load_woopay_save_user_assets( string $context = 'checkout' ): bool {
		unset( $context );

		return $this->flags->should_load_woopay_save_user_assets;
	}

	/**
	 * Tell whether WooPay direct checkout is enabled.
	 *
	 * @return bool
	 */
	public function is_woopay_direct_checkout_enabled(): bool {
		return $this->flags->direct_checkout_enabled;
	}

	/**
	 * Tell whether WooPay direct checkout runs for the current shopper.
	 *
	 * @return bool
	 */
	public function should_run_woopay_direct_checkout(): bool {
		return $this->flags->direct_checkout_enabled && $this->flags->guest_rule_passes;
	}

	/**
	 * Tell whether WooPay global theme support is enabled.
	 *
	 * @return bool
	 */
	public function is_woopay_global_theme_support_enabled(): bool {
		return $this->flags->global_theme_support_enabled;
	}

	/**
	 * Tell whether the platform keeps the store on WooPay's signed path.
	 *
	 * @return bool
	 */
	public function is_woopay_force_signed_requests_enabled(): bool {
		return $this->flags->force_signed_requests;
	}
}
