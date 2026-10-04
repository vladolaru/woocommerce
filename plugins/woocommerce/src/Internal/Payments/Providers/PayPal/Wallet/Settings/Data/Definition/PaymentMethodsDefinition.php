<?php
/**
 * Payment Methods Definitions
 *
 * @package Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Settings\Data\Definition
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Settings\Data\Definition;

use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Axo\Gateway\AxoGateway;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\LocalAlternativePaymentMethods\BancontactGateway;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\LocalAlternativePaymentMethods\BlikGateway;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\LocalAlternativePaymentMethods\EPSGateway;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\LocalAlternativePaymentMethods\IDealGateway;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\LocalAlternativePaymentMethods\MultibancoGateway;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\LocalAlternativePaymentMethods\MyBankGateway;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\LocalAlternativePaymentMethods\P24Gateway;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\LocalAlternativePaymentMethods\PWCGateway;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\LocalAlternativePaymentMethods\TrustlyGateway;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Settings\Data\PaymentSettings;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Settings\Data\GeneralSettings;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\LocalAlternativePaymentMethods\OXXOGateway;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\WcGateway\Gateway\PayPalGateway;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\LocalAlternativePaymentMethods\PayUponInvoice\PayUponInvoiceGateway;

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
	private GeneralSettings $general_settings;

	/**
	 * Axo checkout configuration conflict notice.
	 *
	 * @var string
	 */
	private string $axo_checkout_config_notice;

	/**
	 * Axo incompatible plugins conflict notice.
	 *
	 * @var string
	 */
	private string $axo_incompatible_plugins_notice;

	/**
	 * List of WooCommerce payment gateways.
	 *
	 * @var array|null
	 */
	private ?array $wc_gateways = null;

	/**
	 * Constructor.
	 *
	 * @param PaymentSettings $settings                        Payment methods data model.
	 * @param GeneralSettings $general_settings                General plugin settings model.
	 * @param string          $axo_checkout_config_notice      Axo checkout config conflict notice.
	 * @param string          $axo_incompatible_plugins_notice Axo incompatible plugins notice.
	 */
	public function __construct(
		PaymentSettings $settings,
		GeneralSettings $general_settings,
		string $axo_checkout_config_notice = '',
		string $axo_incompatible_plugins_notice = ''
	) {
		$this->settings                        = $settings;
		$this->general_settings                = $general_settings;
		$this->axo_checkout_config_notice      = $axo_checkout_config_notice;
		$this->axo_incompatible_plugins_notice = $axo_incompatible_plugins_notice;
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

		return apply_filters( 'woocommerce_paypal_payments_gateway_group_paypal', $group );
	}

	/**
	 * Define embedded payment methods. The wallet offers none; the hook stays fired for third parties.
	 *
	 * @return array
	 */
	public function group_card_methods(): array {
		return apply_filters( 'woocommerce_paypal_payments_gateway_group_cards', array() );
	}

	/**
	 * Get default titles and descriptions for APM gateways.
	 * Single source of truth for all APM gateway metadata.
	 *
	 * @return array Array of default settings keyed by gateway ID.
	 */
	public static function get_apm_defaults(): array {
		return array(
			PWCGateway::ID            => array(
				'method_title'       => __( 'Pay with Crypto', 'woocommerce' ),
				'method_description' => __( 'A PayPal-powered checkout option letting customers pay with cryptocurrency. You receive funds in USD, settled directly to your PayPal balance — no crypto exposure, no chargeback risk.', 'woocommerce' ),
				'title'              => __( 'Pay with Crypto', 'woocommerce' ),
				'description'        => __( 'Pay with top wallets and coins.', 'woocommerce' ),
			),
			BancontactGateway::ID     => array(
				'method_title'       => __( 'Bancontact (via PayPal)', 'woocommerce' ),
				'method_description' => __( 'A popular and trusted electronic payment method in Belgium, used by Belgian customers with Bancontact cards issued by local banks. Transactions are processed in EUR.', 'woocommerce' ),
				'title'              => __( 'Bancontact', 'woocommerce' ),
				'description'        => '',
			),
			BlikGateway::ID           => array(
				'method_title'       => __( 'Blik (via PayPal)', 'woocommerce' ),
				'method_description' => __( 'A widely used mobile payment method in Poland, allowing Polish customers to pay directly via their banking apps. Transactions are processed in PLN.', 'woocommerce' ),
				'title'              => __( 'Blik', 'woocommerce' ),
				'description'        => '',
			),
			EPSGateway::ID            => array(
				'method_title'       => __( 'EPS (via PayPal)', 'woocommerce' ),
				'method_description' => __( 'An online payment method in Austria, enabling Austrian buyers to make secure payments directly through their bank accounts. Transactions are processed in EUR.', 'woocommerce' ),
				'title'              => __( 'EPS', 'woocommerce' ),
				'description'        => '',
			),
			IDealGateway::ID          => array(
				'method_title'       => __( 'iDeal (via PayPal)', 'woocommerce' ),
				'method_description' => __( 'The most common payment method in the Netherlands, allowing Dutch buyers to pay directly through their preferred bank. Transactions are processed in EUR.', 'woocommerce' ),
				'title'              => __( 'iDeal', 'woocommerce' ),
				'description'        => '',
			),
			MyBankGateway::ID         => array(
				'method_title'       => __( 'MyBank (via PayPal)', 'woocommerce' ),
				'method_description' => __( 'A European online banking payment solution primarily used in Italy, enabling customers to make secure bank transfers during checkout. Transactions are processed in EUR.', 'woocommerce' ),
				'title'              => __( 'MyBank', 'woocommerce' ),
				'description'        => '',
			),
			P24Gateway::ID            => array(
				'method_title'       => __( 'Przelewy24 (via PayPal)', 'woocommerce' ),
				'method_description' => __( 'A popular online payment gateway in Poland, offering various payment options for Polish customers. Transactions can be processed in PLN or EUR.', 'woocommerce' ),
				'title'              => __( 'Przelewy24', 'woocommerce' ),
				'description'        => '',
			),
			TrustlyGateway::ID        => array(
				'method_title'       => __( 'Trustly (via PayPal)', 'woocommerce' ),
				'method_description' => __( 'A European payment method that allows buyers to make payments directly from their bank accounts, suitable for customers across multiple European countries. Supported currencies include EUR, DKK, SEK, GBP, and NOK.', 'woocommerce' ),
				'title'              => __( 'Trustly', 'woocommerce' ),
				'description'        => '',
			),
			MultibancoGateway::ID     => array(
				'method_title'       => __( 'Multibanco (via PayPal)', 'woocommerce' ),
				'method_description' => __( 'An online payment method in Portugal, enabling Portuguese buyers to make secure payments directly through their bank accounts. Transactions are processed in EUR.', 'woocommerce' ),
				'title'              => __( 'Multibanco', 'woocommerce' ),
				'description'        => '',
			),
			PayUponInvoiceGateway::ID => array(
				'method_title'       => __( 'Pay upon Invoice', 'woocommerce' ),
				'method_description' => __( 'Pay upon Invoice is an invoice payment method in Germany. It is a local buy now, pay later payment method that allows the buyer to place an order, receive the goods, try them, verify they are in good order, and then pay the invoice within 30 days.', 'woocommerce' ),
				'title'              => __( 'Pay upon Invoice', 'woocommerce' ),
				'description'        => '',
			),
			OXXOGateway::ID           => array(
				'method_title'       => __( 'OXXO', 'woocommerce' ),
				'method_description' => __( 'OXXO is a Mexican chain of convenience stores. *Get PayPal account permission to use OXXO payment functionality by contacting us at (+52) 800–925–0304', 'woocommerce' ),
				'title'              => __( 'OXXO', 'woocommerce' ),
				'description'        => __( 'OXXO allows you to pay bills and online purchases in-store with cash.', 'woocommerce' ),
			),
		);
	}

	/**
	 * Builds an array of payment method definitions, which includes details
	 * of all APM gateways.
	 *
	 * @return array List of payment method definitions.
	 */
	public function group_apms(): array {
		$defaults = self::get_apm_defaults();
		$warnings = $this->get_warning_messages();

		$group = array(
			array(
				'id'          => PWCGateway::ID,
				'title'       => $defaults[ PWCGateway::ID ]['title'],
				'description' => $defaults[ PWCGateway::ID ]['method_description'],
				'icon'        => 'payment-method-pwc',
			),
			array(
				'id'          => BancontactGateway::ID,
				'title'       => $defaults[ BancontactGateway::ID ]['title'],
				'description' => $defaults[ BancontactGateway::ID ]['method_description'],
				'icon'        => 'payment-method-bancontact',
			),
			array(
				'id'          => BlikGateway::ID,
				'title'       => $defaults[ BlikGateway::ID ]['title'],
				'description' => $defaults[ BlikGateway::ID ]['method_description'],
				'icon'        => 'payment-method-blik',
			),
			array(
				'id'          => EPSGateway::ID,
				'title'       => $defaults[ EPSGateway::ID ]['title'],
				'description' => $defaults[ EPSGateway::ID ]['method_description'],
				'icon'        => 'payment-method-eps',
			),
			array(
				'id'          => IDealGateway::ID,
				'title'       => $defaults[ IDealGateway::ID ]['title'],
				'description' => $defaults[ IDealGateway::ID ]['method_description'],
				'icon'        => 'payment-method-ideal',
			),
			array(
				'id'          => MyBankGateway::ID,
				'title'       => $defaults[ MyBankGateway::ID ]['title'],
				'description' => $defaults[ MyBankGateway::ID ]['method_description'],
				'icon'        => 'payment-method-mybank',
			),
			array(
				'id'          => P24Gateway::ID,
				'title'       => $defaults[ P24Gateway::ID ]['title'],
				'description' => $defaults[ P24Gateway::ID ]['method_description'],
				'icon'        => 'payment-method-przelewy24',
			),
			array(
				'id'          => TrustlyGateway::ID,
				'title'       => $defaults[ TrustlyGateway::ID ]['title'],
				'description' => $defaults[ TrustlyGateway::ID ]['method_description'],
				'icon'        => 'payment-method-trustly',
			),
			array(
				'id'          => MultibancoGateway::ID,
				'title'       => $defaults[ MultibancoGateway::ID ]['title'],
				'description' => $defaults[ MultibancoGateway::ID ]['method_description'],
				'icon'        => 'payment-method-multibanco',
			),
			array(
				'id'              => PayUponInvoiceGateway::ID,
				'title'           => $defaults[ PayUponInvoiceGateway::ID ]['title'],
				'description'     => $defaults[ PayUponInvoiceGateway::ID ]['method_description'],
				'icon'            => 'payment-method-ratepay',
				'fields'          => array(
					'puiBrandName'                   => array(
						'type'     => 'text',
						'default'  => $this->settings->get_pui_brand_name(),
						'label'    => __( 'Brand name', 'woocommerce' ),
						'required' => true,
					),
					'puiLogoUrl'                     => array(
						'type'     => 'text',
						'default'  => $this->settings->get_pui_logo_url(),
						'label'    => __( 'Logo URL', 'woocommerce' ),
						'required' => true,
					),
					'puiCustomerServiceInstructions' => array(
						'type'     => 'text',
						'default'  => $this->settings->get_pui_customer_service_instructions(),
						'label'    => __( 'Customer service instructions', 'woocommerce' ),
						'required' => true,
					),
				),
				'warningMessages' => $warnings[ PayUponInvoiceGateway::ID ] ?? array(),
				'warningSeverity' => 'error',
			),
			array(
				'id'          => OXXOGateway::ID,
				'title'       => $defaults[ OXXOGateway::ID ]['title'],
				'description' => $defaults[ OXXOGateway::ID ]['method_description'],
				'icon'        => 'payment-method-oxxo',
			),
		);

		return apply_filters( 'woocommerce_paypal_payments_gateway_group_apm', $group );
	}

	/**
	 * Returns warning definitions keyed by gateway ID.
	 *
	 * Each gateway can have multiple warnings. Each warning can be either:
	 * - A plain string: always displayed.
	 * - An object with { message, visibleWhen }: displayed only when the
	 *   visibleWhen condition evaluates to true.
	 *
	 * @return array Warning definitions keyed by gateway ID.
	 */
	private function get_warning_messages(): array {
		$warnings = array();

		// Pay upon Invoice warnings.
		$warnings[ PayUponInvoiceGateway::ID ] = array(
			'pui_required_fields' => array(
				/* translators: %s: comma-separated list of missing field names. */
				'message'     => __(
					'Pay upon Invoice requires %s to be configured. Click the settings icon to configure.',
					'woocommerce'
				),
				'visibleWhen' => array(
					'store'     => 'payment',
					'condition' => 'any_empty',
					'fields'    => array(
						'puiBrandName'                   => __( 'Brand name', 'woocommerce' ),
						'puiLogoUrl'                     => __( 'Logo URL', 'woocommerce' ),
						'puiCustomerServiceInstructions' => __( 'Customer service instructions', 'woocommerce' ),
					),
				),
			),
		);

		// Fastlane (Axo) conflict warnings.
		$warnings[ AxoGateway::ID ] = array_filter(
			array(
				'axo_checkout_config'      => $this->axo_checkout_config_notice,
				'axo_incompatible_plugins' => $this->axo_incompatible_plugins_notice,
			)
		);

		return $warnings;
	}
}
