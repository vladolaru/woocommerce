<?php
/**
 * Payment Methods Definitions
 *
 * @package Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Settings\Data\Definition
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Settings\Data\Definition;

use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Settings\Data\PaymentSettings;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Settings\Data\GeneralSettings;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\WcGateway\Gateway\PayPalGateway;

/**
 * Class PaymentMethodsDefinition
 *
 * Provides a list of all payment methods that are available in the settings UI.
 */
class PaymentMethodsDefinition {
	/**
	 * Data model that manages the payment method configuration.
	 *
	 * @var PaymentSettings
	 */
	private PaymentSettings $settings;

	/**
	 * Data model for the general plugin settings, used to access flags like
	 * "own brand only" to modify the payment method details.
	 *
	 * @var GeneralSettings
	 */
	private GeneralSettings $general_settings; // @phpstan-ignore property.onlyWritten (set by the constructor and never read; kept as it is in the extension)

	/**
	 * List of WooCommerce payment gateways.
	 *
	 * @var array|null
	 */
	private ?array $wc_gateways = null;

	/**
	 * Constructor.
	 *
	 * @param PaymentSettings $settings         Payment methods data model.
	 * @param GeneralSettings $general_settings General plugin settings model.
	 */
	public function __construct(
		PaymentSettings $settings,
		GeneralSettings $general_settings
	) {
		$this->settings         = $settings;
		$this->general_settings = $general_settings;
	}

	/**
	 * Returns the payment method definitions.
	 *
	 * @return array
	 */
	public function get_definitions(): array {
		// Refresh the WooCommerce gateway details before we build the definitions.
		$this->wc_gateways = WC()->payment_gateways()->payment_gateways();
		$all_methods       = array_merge(
			$this->group_paypal_methods(),
			$this->group_card_methods(),
			$this->group_apms(),
		);
		$result            = array();
		foreach ( $all_methods as $method ) {
			$method_id = $method['id'];

			$result[ $method_id ] = $this->build_method_definition(
				$method_id,
				$method['title'],
				$method['description'],
				$method['icon'],
				$method['fields'] ?? array(),
				$method['warningMessages'] ?? array(),
				$method['warningSeverity'] ?? 'warning',
			);
		}

		return $result;
	}

	/**
	 * Returns a new payment method configuration array that contains all
	 * common attributes which must be present in every method definition.
	 *
	 * @param string      $gateway_id                 The payment method ID.
	 * @param string      $title                      Admin-side payment method title.
	 * @param string      $description                Admin-side info about the payment method.
	 * @param string      $icon                       Admin-side icon of the payment method.
	 * @param array|false $fields                     Optional. Additional fields to display in the
	 *                                                edit modal. Setting this to false omits all
	 *                                                fields.
	 * @param array       $warning_messages           Optional. Warning messages to display in the
	 *                                                UI.
	 * @param string      $warning_severity           Optional. Severity level: 'warning' (yellow)
	 *                                                or 'error' (red).
	 * @return array Payment method definition.
	 */
	private function build_method_definition(
		string $gateway_id,
		string $title,
		string $description,
		string $icon,
		$fields = array(),
		array $warning_messages = array(),
		string $warning_severity = 'warning'
	): array {
		$gateway = $this->wc_gateways[ $gateway_id ] ?? null;

		$gateway_title       = $gateway ? $gateway->get_title() : $title;
		$gateway_description = $gateway ? $gateway->description : $description;
		$enabled             = $this->settings->is_method_enabled( $gateway_id );
		$config              = array(
			'id'              => $gateway_id,
			'enabled'         => $enabled,
			'title'           => str_replace( '&amp;', '&', $gateway_title ),
			'description'     => $gateway_description,
			'icon'            => $icon,
			'itemTitle'       => $title,
			'itemDescription' => $description,
			'warningMessages' => $warning_messages,
			'warningSeverity' => $warning_severity,
		);

		if ( is_array( $fields ) ) {
			$base_fields = array(
				'checkoutPageTitle' => array(
					'type'    => 'text',
					'default' => $gateway_title,
					'label'   => __( 'Checkout page title', 'woocommerce' ),
				),
			);

			$base_fields['checkoutPageDescription'] = array(
				'type'    => 'text',
				'default' => $gateway_description,
				'label'   => __( 'Checkout page description', 'woocommerce' ),
			);

			$config['fields'] = array_merge( $base_fields, $fields );
		}

		return $config;
	}

	// Payment method groups.

	/**
	 * Defines PayPal's branded payment methods; not affected by the "own_brand_only" setting.
	 *
	 * @return array
	 */
	public function group_paypal_methods(): array {
		$group = array(
			array(
				'id'          => PayPalGateway::ID,
				'title'       => __( 'PayPal', 'woocommerce' ),
				'description' => __( 'Our all-in-one checkout solution lets you offer PayPal, Venmo, Pay Later options, and more to help maximize conversion.', 'woocommerce' ),
				'icon'        => 'payment-method-paypal',
				'fields'      => array(
					'paypalShowLogo' => array(
						'type'    => 'toggle',
						'default' => $this->settings->get_paypal_show_logo(),
						'label'   => __( 'Show logo', 'woocommerce' ),
					),
				),
			),
			array(
				'id'          => 'venmo',
				'title'       => __( 'Venmo', 'woocommerce' ),
				'description' => __(
					'Offer Venmo at checkout to millions of active users.',
					'woocommerce'
				),
				'icon'        => 'payment-method-venmo',
				'fields'      => false,
			),
			array(
				'id'          => 'pay-later',
				'title'       => __( 'Pay Later', 'woocommerce' ),
				'description' => __(
					'Get paid in full at checkout while giving your customers the flexibility to pay in installments over time with no late fees.',
					'woocommerce'
				),
				'icon'        => 'payment-method-paypal',
				'fields'      => false,
			),
		);

		/**
		 * Filters the payment methods of the PayPal group.
		 *
		 * @since 11.3.0
		 *
		 * @param array $group The payment method definitions of the group.
		 */
		return apply_filters( 'woocommerce_paypal_payments_gateway_group_paypal', $group );
	}

	/**
	 * Define embedded payment methods. The wallet offers none; the hook stays fired for third parties.
	 *
	 * @return array
	 */
	public function group_card_methods(): array {
		/**
		 * Filters the payment methods of the card group.
		 *
		 * @since 11.3.0
		 *
		 * @param array $group The payment method definitions of the group; empty by default.
		 */
		return apply_filters( 'woocommerce_paypal_payments_gateway_group_cards', array() );
	}

	/**
	 * Define local payment methods. The wallet offers none; the hook stays fired for third parties.
	 *
	 * @return array
	 */
	public function group_apms(): array {
		/**
		 * Filters the payment methods of the alternative payment methods group.
		 *
		 * @since 11.3.0
		 *
		 * @param array $group The payment method definitions of the group; empty by default.
		 */
		return apply_filters( 'woocommerce_paypal_payments_gateway_group_apm', array() );
	}
}
