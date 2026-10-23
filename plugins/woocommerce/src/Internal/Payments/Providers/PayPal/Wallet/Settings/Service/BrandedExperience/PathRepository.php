<?php
/**
 * Persist the Branded Experience path.
 *
 * @package Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Settings\Service\BrandedExperience
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Settings\Service\BrandedExperience;

use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Settings\Data\GeneralSettings;

/**
 * Class that includes logic for persisting the Branded Experience path.
 */
class PathRepository {
	/**
	 * The Branded Experience activation path detector.
	 *
	 * @var ActivationDetector
	 */
	private ActivationDetector $activation_detector;

	/**
	 * The general settings.
	 *
	 * @var GeneralSettings
	 */
	private GeneralSettings $general_settings;

	/**
	 * PathRepository constructor.
	 *
	 * @param ActivationDetector $activation_detector The Branded Experience activation path detector.
	 * @param GeneralSettings    $general_settings The general settings.
	 */
	public function __construct( ActivationDetector $activation_detector, GeneralSettings $general_settings ) {
		$this->activation_detector = $activation_detector;
		$this->general_settings    = $general_settings;
	}

	/**
	 * Persists Branded Experience activation path only once.
	 *
	 * @return void
	 */
	public function persist(): void {
		$persisted = $this->general_settings->get_installation_path();
		if ( $persisted ) {
			return;
		}

		$this->general_settings->set_installation_path( $this->activation_detector->detect_activation_path() );
		$this->general_settings->save();
	}
}
