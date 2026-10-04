<?php
/**
 * PayPal Commerce Features Definitions
 *
 * @package Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Settings\Data\Definition
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Settings\Data\Definition;

use Automattic\WooCommerce\Vendor\Psr\Log\LoggerInterface;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Settings\Service\FeaturesEligibilityService;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Settings\Data\GeneralSettings;

/**
 * Class FeaturesDefinition
 *
 * Provides the definitions for all available features in the system.
 * Each feature has a title, description, eligibility condition, and associated action.
 */
class FeaturesDefinition {


	/**
	 * Save tokenized PayPal and Venmo payment details, required for subscriptions and saving
	 * payment methods in user account.
	 */
	public const FEATURE_SAVE_PAYPAL_AND_VENMO = 'save_paypal_and_venmo';

	/**
	 * Allow to pay in installments.
	 */
	public const FEATURE_INSTALLMENTS = 'installments';

	/**
	 * Allow customers to buy now and pay later with PayPal
	 */
	public const FEATURE_PAY_LATER_MESSAGING = 'pay_later_messaging';

	/**
	 * Advanced card processing capability of the seller. Read as a gate for Pay Later; it has no feature card of its own.
	 */
	public const FEATURE_ADVANCED_CREDIT_AND_DEBIT_CARDS = 'advanced_credit_and_debit_cards';

	/**
	 * Contact module allows the merchant to unlock the "Custom Shipping Contact" toggle.
	 */
	public const FEATURE_CONTACT_MODULE = 'contact_module';

	/**
	 * Whether Pay With Crypto Feature is supported.
	 */
	public const FEATURE_PAY_WITH_CRYPTO = 'pwc';

	/**
	 * Whether the Vault Component feature is enabled.
	 * Renders paypal.Vault() inline on checkout for returning customers.
	 */
	public const FEATURE_VAULT_COMPONENT = 'vault_component';

	protected FeaturesEligibilityService $eligibilities;
	protected GeneralSettings $settings;

	/**
	 * The merchant capabilities.
	 *
	 * @var array
	 */
	protected array $merchant_capabilities;
	protected LoggerInterface $logger;

	public function __construct(
		FeaturesEligibilityService $eligibilities,
		GeneralSettings $settings,
		array $merchant_capabilities,
		LoggerInterface $logger
	) {
		$this->eligibilities         = $eligibilities;
		$this->settings              = $settings;
		$this->merchant_capabilities = $merchant_capabilities;
		$this->logger                = $logger;
	}

	/**
	 * Returns the full list of feature definitions with their eligibility conditions.
	 *
	 * Only features whose eligibility check passes are included.
	 *
	 * @return array The array of feature definitions.
	 */
	public function eligible_features(): array {
		$all_features       = $this->all_available_features();
		$eligible_features  = array();
		$eligibility_checks = $this->eligibilities->get_eligibility_checks();
		foreach ( $all_features as $feature_key => $feature ) {
			if ( isset( $eligibility_checks[ $feature_key ] ) && $eligibility_checks[ $feature_key ]() ) {
				$eligible_features[ $feature_key ] = $feature;
			}
		}

		return $eligible_features;
	}

	/**
	 * Returns whether a specific feature is eligible.
	 *
	 * @param string $feature_name One of the FEATURE_* constants.
	 * @return bool true if the feature is eligible, false otherwise or if unknown.
	 */
	public function is_feature_eligible( string $feature_name ): bool {
		$eligibility_checks = $this->eligibilities->get_eligibility_checks();

		if ( ! isset( $eligibility_checks[ $feature_name ] ) ) {
			$this->logger->warning(
				sprintf(
					'No eligibility check registered for feature "%s".',
					$feature_name
				)
			);
			return false;
		}

		return (bool) $eligibility_checks[ $feature_name ]();
	}

	/**
	 * Returns all available features.
	 *
	 * @return array[] The array of all available features.
	 */
	public function all_available_features(): array {
		$paylater_documentation_supported_countries = array(
			'UK',
			'ES',
			'IT',
			'FR',
			'US',
			'DE',
			'AU',
		);

		$store_country                  = $this->settings->get_woo_settings()['country'];
		$paylater_docs_country_location = in_array( $store_country, $paylater_documentation_supported_countries, true ) ? strtolower( $store_country ) : 'us';

		$feature_items = array(
			self::FEATURE_PAY_WITH_CRYPTO                 => array(
				'title'       => __( 'Pay with Crypto', 'woocommerce' ),
				'description' => __( 'Enable customers to pay with cryptocurrency, and receive payments in USD in your PayPal balance.', 'woocommerce' ),
				'enabled'     => $this->merchant_capabilities[ self::FEATURE_PAY_WITH_CRYPTO ],
				'buttons'     => array(
					array(
						'type'     => 'secondary',
						'text'     => __( 'Configure', 'woocommerce' ),
						'action'   => array(
							'type'    => 'tab',
							'tab'     => 'payment_methods',
							'section' => 'ppcp-pwc',
						),
						'showWhen' => 'enabled',
						'class'    => 'small-button',
					),
					array(
						'type'     => 'secondary',
						'text'     => __( 'Sign up', 'woocommerce' ),
						'urls'     => array(
							'sandbox' => 'https://www.sandbox.paypal.com/bizsignup/add-product?product=CRYPTO_PYMTS',
							'live'    => 'https://www.paypal.com/bizsignup/add-product?product=CRYPTO_PYMTS',
						),
						'showWhen' => 'disabled',
						'class'    => 'small-button',
					),
					array(
						'type'  => 'tertiary',
						'text'  => __( 'Learn more', 'woocommerce' ),
						'url'   => 'https://www.paypal.com/us/digital-wallet/manage-money/crypto',
						'class' => 'small-button',
					),
				),
			),
			self::FEATURE_SAVE_PAYPAL_AND_VENMO           => array(
				'title'       => __( 'Save PayPal and Venmo', 'woocommerce' ),
				'description' => __( 'Securely save PayPal and Venmo payment methods for subscriptions or return buyers.', 'woocommerce' ),
				'enabled'     => $this->merchant_capabilities[ self::FEATURE_SAVE_PAYPAL_AND_VENMO ],
				'buttons'     => array(
					array(
						'type'     => 'secondary',
						'text'     => __( 'Configure', 'woocommerce' ),
						'action'   => array(
							'type'    => 'tab',
							'tab'     => 'settings',
							'section' => 'ppcp-save-paypal-and-venmo',
						),
						'showWhen' => 'enabled',
						'class'    => 'small-button',
					),
					array(
						'type'     => 'secondary',
						'text'     => __( 'Sign up', 'woocommerce' ),
						'urls'     => array(
							'sandbox' => 'https://www.sandbox.paypal.com/bizsignup/entry?product=ADVANCED_VAULTING',
							'live'    => 'https://www.paypal.com/bizsignup/entry?product=ADVANCED_VAULTING',
						),
						'showWhen' => 'disabled',
						'class'    => 'small-button',
					),
					array(
						'type'  => 'tertiary',
						'text'  => __( 'Learn more', 'woocommerce' ),
						'url'   => 'https://www.paypal.com/us/enterprise/payment-processing/accept-venmo',
						'class' => 'small-button',
					),
				),
			),
			self::FEATURE_PAY_LATER_MESSAGING             => array(
				'title'       => __( 'Pay Later Messaging', 'woocommerce' ),
				'description' => __(
					'Help grow sales with Pay Later messaging. Let customers know they have flexible payment options as they browse, shop, and check out.',
					'woocommerce'
				),
				'enabled'     => $this->merchant_capabilities[ self::FEATURE_PAY_LATER_MESSAGING ],
				'buttons'     => array(
					array(
						'type'     => 'secondary',
						'text'     => __( 'Configure', 'woocommerce' ),
						'action'   => array(
							'type' => 'tab',
							'tab'  => 'pay_later_messaging',
						),
						'showWhen' => 'enabled',
						'class'    => 'small-button',
					),
					array(
						'type'  => 'tertiary',
						'text'  => __( 'Learn more', 'woocommerce' ),
						'url'   => "https://www.paypal.com/$paylater_docs_country_location/business/accept-payments/checkout/installments",
						'class' => 'small-button',
					),
				),
			),
			self::FEATURE_INSTALLMENTS                    => array(
				'title'       => __( 'Installments', 'woocommerce' ),
				'description' =>
					__( 'Allow your customers to pay in installments without interest while you receive the full payment.*', 'woocommerce' ) .
					'<p>' . __( 'Activate your Installments without interest with PayPal.', 'woocommerce' ) . '</p>' .
					'<p>' . sprintf(
					/* translators: %s: Link to terms and conditions */
						__( '*You will receive the full payment minus the applicable PayPal fee. See %s.', 'woocommerce' ),
						'<a href="https://www.paypal.com/mx/webapps/mpp/merchant-fees">' . __( 'terms and conditions', 'woocommerce' ) . '</a>'
					) . '</p>',
				'enabled'     => $this->merchant_capabilities[ self::FEATURE_INSTALLMENTS ],
				'buttons'     => array(
					array(
						'type'     => 'secondary',
						'text'     => __( 'Configure', 'woocommerce' ),
						'url'      => 'https://www.paypal.com/businessmanage/preferences/installmentplan',
						'showWhen' => 'enabled',
						'class'    => 'small-button',
					),
					array(
						'type'     => 'secondary',
						'text'     => __( 'Sign up', 'woocommerce' ),
						'url'      => 'https://www.paypal.com/businessmanage/preferences/installmentplan',
						'showWhen' => 'disabled',
						'class'    => 'small-button',
					),
				),
			),
		);

		return apply_filters( 'woocommerce_paypal_payments_features_list', $feature_items );
	}
}
