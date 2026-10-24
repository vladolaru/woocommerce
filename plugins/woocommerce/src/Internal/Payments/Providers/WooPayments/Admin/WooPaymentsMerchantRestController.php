<?php
declare( strict_types=1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Admin;

use Automattic\WooCommerce\Internal\RestApiControllerBase;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api\WooPaymentsApiClient;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api\WooPaymentsApiException;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\PaymentMethods\WooPaymentsPaymentMethodRegistry;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsRuntimeArbiter;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsAccountService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsApplePayDomainService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsPmPromotionsService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsFraudRulesetService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsSettingsService;
use Exception;
use WP_Error;
use WP_Http;
use WP_HTTP_Response;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Serves the REST routes of the WooPayments settings and admin pages: the account summary, the Overview data and the
 * settings notices' actions under `wc-admin/settings/payments/woopayments`, and, while the built-in WooPayments owns
 * payments, the settings, payment method promotions and files under `wc/v3/payments`.
 *
 * A file the platform marks public is served without a login.
 *
 * @internal
 */
class WooPaymentsMerchantRestController extends RestApiControllerBase {

	/**
	 * Public file purposes that may be served without payment gateway management permissions.
	 */
	private const PUBLIC_FILE_PURPOSES = array(
		'business_logo',
		'business_icon',
	);

	/**
	 * Prefix for cached provider file purposes.
	 */
	private const FILE_PURPOSE_CACHE_PREFIX = 'wcpay_file_purpose_';

	/**
	 * Lifetime, in seconds, of a cached provider file purpose: a day, as client 11.1.0 keeps it
	 * (includes/class-wc-payments-file-service.php:22-23).
	 *
	 * A file's purpose is set when the file is uploaded and the platform has no way to change it, so a day-old
	 * classification is still the file's classification.
	 */
	private const FILE_PURPOSE_CACHE_TTL = DAY_IN_SECONDS;

	/**
	 * The root namespace for the JSON REST API endpoints.
	 *
	 * @var string
	 */
	protected string $route_namespace = 'wc-admin';

	/**
	 * Route base.
	 *
	 * @var string
	 */
	protected string $rest_base = 'settings/payments/woopayments';

	/**
	 * Settings service.
	 *
	 * @var WooPaymentsSettingsService
	 */
	private WooPaymentsSettingsService $settings_service;

	/**
	 * Payment method promotions service.
	 *
	 * @var WooPaymentsPmPromotionsService
	 */
	private WooPaymentsPmPromotionsService $pm_promotions_service;

	/**
	 * Overview service.
	 *
	 * @var WooPaymentsOverviewService
	 */
	private WooPaymentsOverviewService $overview_service;

	/**
	 * Account service.
	 *
	 * @var WooPaymentsAccountService
	 */
	private WooPaymentsAccountService $account_service;

	/**
	 * Current-user settings notice state service.
	 *
	 * @var WooPaymentsAdminNoticeService
	 */
	private WooPaymentsAdminNoticeService $admin_notice_service;

	/**
	 * Apple Pay domain service, for the settings notice display acknowledgement.
	 *
	 * @var WooPaymentsApplePayDomainService
	 */
	private WooPaymentsApplePayDomainService $apple_pay_domain_service;

	/**
	 * Runtime arbiter.
	 *
	 * @var WooPaymentsRuntimeArbiter
	 */
	private WooPaymentsRuntimeArbiter $runtime_arbiter;

	/**
	 * WooPayments API client, for the settings file routes.
	 *
	 * @var WooPaymentsApiClient
	 */
	private WooPaymentsApiClient $api_client;

	/**
	 * Get the WooCommerce REST API namespace for the class.
	 *
	 * @return string
	 */
	protected function get_rest_api_namespace(): string {
		return 'wc-admin-settings-payments-woopayments-merchant';
	}

	/**
	 * Register the REST API endpoints handled by this controller.
	 *
	 * @param bool $override Whether to override the existing routes. Useful for testing.
	 */
	public function register_routes( bool $override = false ): void {
		if ( $this->should_register_settings_routes() ) {
			$this->register_settings_routes( $override );
		}

		register_rest_route(
			$this->route_namespace,
			'/' . $this->rest_base . '/admin-notices/(?P<notice_id>test_to_live|post_kyc_activation|one_and_done)/(?P<action>shown|dismiss|snooze|cta)',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => fn( $request ) => $this->run( $request, 'record_admin_notice_action' ),
				'permission_callback' => fn( $request ) => $this->check_admin_notice_permissions( $request ),
			),
			$override
		);

		// Same notice "shown" route family; the Apple Pay error is site state, not a per-user notice.
		register_rest_route(
			$this->route_namespace,
			'/' . $this->rest_base . '/admin-notices/apple_pay_domain_error/shown',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => fn( $request ) => $this->run( $request, 'record_apple_pay_domain_error_shown' ),
				'permission_callback' => fn( $request ) => $this->check_admin_notice_permissions( $request ),
				'args'                => array(
					'error_id' => array(
						'description' => __( 'SHA-256 identifier of the displayed Apple Pay domain error.', 'woocommerce' ),
						'type'        => 'string',
						'pattern'     => '^[a-f0-9]{64}$',
						'required'    => true,
					),
				),
			),
			$override
		);

		register_rest_route(
			$this->route_namespace,
			'/' . $this->rest_base . '/account',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => fn( $request ) => $this->run( $request, 'get_account_summary' ),
					'permission_callback' => fn( $request ) => $this->check_permissions( $request ),
				),
			),
			$override
		);
		register_rest_route(
			$this->route_namespace,
			'/' . $this->rest_base . '/overview',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => fn( $request ) => $this->run( $request, 'get_overview' ),
					'permission_callback' => fn( $request ) => $this->check_permissions( $request ),
				),
			),
			$override
		);
	}

	/**
	 * Initialize the class instance.
	 *
	 * @param WooPaymentsSettingsService       $settings_service         Settings service.
	 * @param WooPaymentsRuntimeArbiter        $runtime_arbiter          Runtime arbiter.
	 * @param WooPaymentsPmPromotionsService   $pm_promotions_service    Payment method promotions service.
	 * @param WooPaymentsOverviewService       $overview_service         Overview service.
	 * @param WooPaymentsAccountService        $account_service          Account service.
	 * @param WooPaymentsAdminNoticeService    $admin_notice_service     Current-user settings notice service.
	 * @param WooPaymentsApplePayDomainService $apple_pay_domain_service Apple Pay domain service.
	 * @param WooPaymentsApiClient             $api_client               WooPayments API client.
	 *
	 * @internal
	 */
	final public function init( WooPaymentsSettingsService $settings_service, WooPaymentsRuntimeArbiter $runtime_arbiter, WooPaymentsPmPromotionsService $pm_promotions_service, WooPaymentsOverviewService $overview_service, WooPaymentsAccountService $account_service, WooPaymentsAdminNoticeService $admin_notice_service, WooPaymentsApplePayDomainService $apple_pay_domain_service, WooPaymentsApiClient $api_client ): void {
		$this->settings_service      = $settings_service;
		$this->runtime_arbiter       = $runtime_arbiter;
		$this->pm_promotions_service = $pm_promotions_service;
		$this->overview_service      = $overview_service;
		$this->account_service       = $account_service;
		$this->admin_notice_service  = $admin_notice_service;

		$this->apple_pay_domain_service = $apple_pay_domain_service;
		$this->api_client               = $api_client;
	}

	/**
	 * Register the `wc/v3/payments` settings, promotions and file routes.
	 *
	 * @param bool $override Whether to override existing routes.
	 * @return void
	 */
	private function register_settings_routes( bool $override ): void {
		register_rest_route(
			'wc/v3',
			'/payments/settings',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => fn( $request ) => $this->run( $request, 'get_settings' ),
					'permission_callback' => fn( $request ) => $this->check_permissions( $request ),
				),
				array(
					'methods'             => \WP_REST_Server::EDITABLE,
					'callback'            => fn( $request ) => $this->run( $request, 'update_settings' ),
					'permission_callback' => fn( $request ) => $this->check_permissions( $request ),
					'args'                => $this->get_settings_update_args(),
				),
			),
			$override
		);

		register_rest_route(
			'wc/v3',
			'/payments/settings/(?P<option_name>[a-zA-Z0-9_-]+)',
			array(
				array(
					'methods'             => \WP_REST_Server::EDITABLE,
					'callback'            => fn( $request ) => $this->run( $request, 'update_settings_option' ),
					'permission_callback' => fn( $request ) => $this->check_permissions( $request ),
					'args'                => array(
						'option_name' => array(
							'required'          => true,
							'sanitize_callback' => 'sanitize_key',
						),
						'value'       => array(
							'required' => true,
						),
					),
				),
			),
			$override
		);

		register_rest_route(
			'wc/v3',
			'/payments/pm-promotions',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => fn( $request ) => $this->run( $request, 'get_pm_promotions' ),
					'permission_callback' => fn( $request ) => $this->check_permissions( $request ),
				),
			),
			$override
		);

		register_rest_route(
			'wc/v3',
			'/payments/pm-promotions/(?P<id>[^/]+)/activate',
			array(
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => fn( $request ) => $this->run( $request, 'activate_pm_promotion' ),
					'permission_callback' => fn( $request ) => $this->check_permissions( $request ),
					'args'                => $this->get_pm_promotion_route_args(),
				),
			),
			$override
		);

		register_rest_route(
			'wc/v3',
			'/payments/pm-promotions/(?P<id>[^/]+)/dismiss',
			array(
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => fn( $request ) => $this->run( $request, 'dismiss_pm_promotion' ),
					'permission_callback' => fn( $request ) => $this->check_permissions( $request ),
					'args'                => $this->get_pm_promotion_route_args(),
				),
			),
			$override
		);

		register_rest_route(
			'wc/v3',
			'/payments/file',
			array(
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => fn( $request ) => $this->run( $request, 'upload_settings_file' ),
					'permission_callback' => fn( $request ) => $this->check_permissions( $request ),
				),
			),
			$override
		);

		register_rest_route(
			'wc/v3',
			'/payments/file/(?P<file_id>\w+)/details',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => fn( $request ) => $this->run( $request, 'get_settings_file_details' ),
					'permission_callback' => fn( $request ) => $this->check_permissions( $request ),
					'args'                => $this->get_file_route_args(),
				),
			),
			$override
		);

		register_rest_route(
			'wc/v3',
			'/payments/file/(?P<file_id>\w+)/content',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => fn( $request ) => $this->run( $request, 'get_settings_file_contents' ),
					'permission_callback' => fn( $request ) => $this->check_permissions( $request ),
					'args'                => $this->get_file_route_args(),
				),
			),
			$override
		);

		register_rest_route(
			'wc/v3',
			'/payments/file/(?P<file_id>\w+)',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => fn( $request ) => $this->run( $request, 'get_public_settings_file' ),
					'permission_callback' => '__return_true',
					'args'                => $this->get_file_route_args(),
				),
			),
			$override
		);
	}

	/**
	 * Tell whether the built-in WooPayments owns payments, so these `wc/v3/payments` routes are its own; while the
	 * WooPayments extension owns payments, it serves them.
	 *
	 * @return bool
	 */
	private function should_register_settings_routes(): bool {
		return $this->runtime_arbiter->is_builtin_owner();
	}

	/**
	 * Get the validation args of the settings update route.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	private function get_settings_update_args(): array {
		$payment_method_ids = WooPaymentsPaymentMethodRegistry::SETTINGS_PAYMENT_METHOD_IDS;
		$express_method_ids = WooPaymentsSettingsService::get_express_checkout_method_ids();
		$args               = array(
			'enabled_payment_method_ids'           => $this->get_string_array_arg( $payment_method_ids ),
			'express_checkout_product_methods'     => $this->get_string_array_arg( $express_method_ids ),
			'express_checkout_cart_methods'        => $this->get_string_array_arg( $express_method_ids ),
			'express_checkout_checkout_methods'    => $this->get_string_array_arg( $express_method_ids ),
			'payment_request_button_border_radius' => $this->get_typed_arg( 'integer' ),
			'deposit_schedule_monthly_anchor'      => $this->get_typed_arg( array( 'integer', 'null' ) ),
			'advanced_fraud_protection_settings'   => $this->get_advanced_fraud_protection_settings_arg(),
			'account_business_support_address'     => array(
				'type'              => 'object',
				'required'          => false,
				'validate_callback' => array( $this, 'validate_business_support_address' ),
			),
			'account_business_support_phone'       => array(
				'type'              => 'string',
				'required'          => false,
				'validate_callback' => array( $this, 'validate_business_support_phone' ),
			),
			'account_statement_descriptor'         => array(
				'type'              => 'string',
				'required'          => false,
				'validate_callback' => array( $this, 'validate_statement_descriptor' ),
			),
			'account_communications_email'         => array(
				'type'              => 'string',
				'required'          => false,
				'validate_callback' => array( $this, 'validate_communications_email' ),
			),
			'account_business_support_email'       => array(
				'type'              => 'string',
				'required'          => false,
				'validate_callback' => array( $this, 'validate_business_support_email' ),
			),
			'account_branding_primary_color'       => array(
				'type'              => 'string',
				'required'          => false,
				'validate_callback' => array( $this, 'validate_branding_color' ),
			),
			'account_branding_secondary_color'     => array(
				'type'              => 'string',
				'required'          => false,
				'validate_callback' => array( $this, 'validate_branding_color' ),
			),
			'account_business_url'                 => array(
				'type'              => 'string',
				'required'          => false,
				'validate_callback' => array( $this, 'validate_business_url' ),
			),
		);

		foreach (
			array(
				'is_wcpay_enabled',
				'is_manual_capture_enabled',
				'is_test_mode_enabled',
				'is_debug_log_enabled',
				'is_saved_cards_enabled',
				'is_payment_request_enabled',
				'is_express_checkout_in_payment_methods_enabled',
				'is_woopay_enabled',
				'is_woopay_global_theme_support_enabled',
				'is_multi_currency_enabled',
				'is_wcpay_subscriptions_enabled',
				'is_stripe_billing_enabled',
			) as $key
		) {
			$args[ $key ] = $this->get_typed_arg( 'boolean' );
		}

		foreach (
			array(
				'payment_request_button_size',
				'payment_request_button_type',
				'payment_request_button_theme',
				'woopay_custom_message',
				'woopay_store_logo',
				'account_statement_descriptor_kanji',
				'account_statement_descriptor_kana',
				'account_business_name',
				'account_branding_logo',
				'account_branding_icon',
				'deposit_schedule_interval',
				'deposit_schedule_weekly_anchor',
				'current_protection_level',
			) as $key
		) {
			$args[ $key ] = $this->get_typed_arg( 'string' );
		}

		return $args;
	}

	/**
	 * Get a REST arg schema for typed scalar or array settings.
	 *
	 * @param string|string[] $type JSON schema type.
	 * @return array<string,mixed>
	 */
	private function get_typed_arg( $type ): array {
		return array(
			'type'              => $type,
			'required'          => false,
			'validate_callback' => 'rest_validate_request_arg',
		);
	}

	/**
	 * Get a REST arg schema for advanced fraud protection settings.
	 *
	 * The settings GET contract can expose the string "error" sentinel when the platform ruleset is unavailable. The POST route must accept that sentinel for round-trips without accepting arbitrary strings as fraud rulesets.
	 *
	 * @return array<string,mixed>
	 */
	private function get_advanced_fraud_protection_settings_arg(): array {
		return array(
			'type'              => array( 'string', 'array' ),
			'required'          => false,
			'validate_callback' => array( $this, 'validate_advanced_fraud_protection_settings' ),
		);
	}

	/**
	 * Validate the advanced fraud protection settings field.
	 *
	 * A structurally invalid advanced ruleset fails the whole save with a 400 here, before anything is saved: the settings
	 * service would otherwise skip the fraud block and answer 200 while the platform keeps the previous ruleset.
	 *
	 * @param mixed           $value   Advanced fraud protection settings value.
	 * @param WP_REST_Request $request Request.
	 * @param string          $param   Parameter name.
	 * @phpstan-param WP_REST_Request<array<string,mixed>> $request
	 * @return true|WP_Error
	 */
	public function validate_advanced_fraud_protection_settings( $value, WP_REST_Request $request, string $param ) {
		$validation = rest_validate_value_from_schema(
			$value,
			array(
				'type' => array( 'string', 'array' ),
			),
			$param
		);
		if ( is_wp_error( $validation ) ) {
			return $validation;
		}

		// The settings GET contract exposes the string "error" sentinel when the platform ruleset is unavailable; the POST route must keep accepting it for round-trips.
		if ( is_string( $value ) && 'error' !== $value ) {
			return new WP_Error(
				'rest_invalid_param',
				esc_html__( 'The advanced fraud protection settings field accepts only the error sentinel or a ruleset array.', 'woocommerce' ),
				array( 'status' => 400 )
			);
		}

		// Only an advanced-level save consumes the submitted ruleset; other levels use the built-in rulesets and ignore this field.
		if (
			is_array( $value )
			&& 'advanced' === $request->get_param( 'current_protection_level' )
			&& ! WooPaymentsFraudRulesetService::is_valid_fraud_ruleset( $value )
		) {
			return new WP_Error(
				'rest_invalid_pattern',
				__( 'Invalid ruleset configuration.', 'woocommerce' )
			);
		}

		return true;
	}

	/**
	 * Validate the account statement descriptor.
	 *
	 * The platform validates before Stripe as well, but a boundary rejection is the only shape that surfaces the field-specific inline error the settings UI renders.
	 *
	 * @param mixed           $value   Statement descriptor value.
	 * @param WP_REST_Request $request Request.
	 * @param string          $param   Parameter name.
	 * @phpstan-param WP_REST_Request<array<string,mixed>> $request
	 * @return true|WP_Error
	 */
	public function validate_statement_descriptor( $value, WP_REST_Request $request, string $param ) {
		$validation = rest_validate_request_arg( $value, $request, $param );
		if ( true !== $validation ) {
			return $validation;
		}

		$descriptor = trim( stripslashes( (string) $value ) );
		if (
			! preg_match( '/^.{5,22}$/', $descriptor )
			|| ! preg_match( '/^.*[a-zA-Z]+/', $descriptor )
			|| ! preg_match( '/^[^*"\'<>]*$/', $descriptor )
		) {
			return new WP_Error(
				'rest_invalid_pattern',
				__( 'Customer bank statement is invalid. Statement should be between 5 and 22 characters long, contain at least single Latin character and does not contain special characters: \' " * &lt; &gt;', 'woocommerce' )
			);
		}

		return true;
	}

	/**
	 * Validate the account communications email.
	 *
	 * The platform stores this address without validating it, so an empty or malformed value would silently detach the merchant from account notification emails.
	 *
	 * @param mixed           $value   Communications email value.
	 * @param WP_REST_Request $request Request.
	 * @param string          $param   Parameter name.
	 * @phpstan-param WP_REST_Request<array<string,mixed>> $request
	 * @return true|WP_Error
	 */
	public function validate_communications_email( $value, WP_REST_Request $request, string $param ) {
		$validation = rest_validate_request_arg( $value, $request, $param );
		if ( true !== $validation ) {
			return $validation;
		}

		if ( '' === $value ) {
			return new WP_Error(
				'rest_invalid_pattern',
				__( 'Error: Communications email is required.', 'woocommerce' )
			);
		}

		if ( ! is_email( $value ) ) {
			return new WP_Error(
				'rest_invalid_pattern',
				__( 'Error: Invalid email address: ', 'woocommerce' ) . $value
			);
		}

		return true;
	}

	/**
	 * Validate the business support email.
	 *
	 * Unlike the communications email, an empty support email is allowed.
	 *
	 * @param mixed           $value   Support email value.
	 * @param WP_REST_Request $request Request.
	 * @param string          $param   Parameter name.
	 * @phpstan-param WP_REST_Request<array<string,mixed>> $request
	 * @return true|WP_Error
	 */
	public function validate_business_support_email( $value, WP_REST_Request $request, string $param ) {
		$validation = rest_validate_request_arg( $value, $request, $param );
		if ( true !== $validation ) {
			return $validation;
		}

		if ( '' !== $value && ! is_email( $value ) ) {
			return new WP_Error(
				'rest_invalid_pattern',
				__( 'Error: Invalid email address: ', 'woocommerce' ) . $value
			);
		}

		return true;
	}

	/**
	 * Validate a branding color as empty or a hex color.
	 *
	 * The stored settings keep a copy of the sent value, so a non-hex value is refused instead of being stored empty while
	 * the platform gets the raw value. The settings page's color picker sends only hex values, so this only limits direct
	 * REST callers.
	 *
	 * @param mixed           $value   Branding color value.
	 * @param WP_REST_Request $request Request.
	 * @param string          $param   Parameter name.
	 * @phpstan-param WP_REST_Request<array<string,mixed>> $request
	 * @return true|WP_Error
	 */
	public function validate_branding_color( $value, WP_REST_Request $request, string $param ) {
		$validation = rest_validate_request_arg( $value, $request, $param );
		if ( true !== $validation ) {
			return $validation;
		}

		if ( '' !== $value && sanitize_hex_color( (string) $value ) !== (string) $value ) {
			return new WP_Error(
				'rest_invalid_pattern',
				__( 'Error: Invalid color value.', 'woocommerce' )
			);
		}

		return true;
	}

	/**
	 * Validate the business URL against dangerous schemes.
	 *
	 * Lenient: a value without a scheme, such as example.com, is sent as it is; only a value esc_url_raw() strips
	 * entirely, such as a javascript: URL, is refused.
	 *
	 * @param mixed           $value   Business URL value.
	 * @param WP_REST_Request $request Request.
	 * @param string          $param   Parameter name.
	 * @phpstan-param WP_REST_Request<array<string,mixed>> $request
	 * @return true|WP_Error
	 */
	public function validate_business_url( $value, WP_REST_Request $request, string $param ) {
		$validation = rest_validate_request_arg( $value, $request, $param );
		if ( true !== $validation ) {
			return $validation;
		}

		if ( '' !== $value && '' === esc_url_raw( (string) $value ) ) {
			return new WP_Error(
				'rest_invalid_pattern',
				__( 'Error: Invalid URL.', 'woocommerce' )
			);
		}

		return true;
	}

	/**
	 * Validate the business support phone.
	 *
	 * Japanese accounts require a +81 number even when the value is empty.
	 *
	 * @param mixed           $value   Support phone value.
	 * @param WP_REST_Request $request Request.
	 * @param string          $param   Parameter name.
	 * @phpstan-param WP_REST_Request<array<string,mixed>> $request
	 * @return true|WP_Error
	 */
	public function validate_business_support_phone( $value, WP_REST_Request $request, string $param ) {
		$validation = rest_validate_request_arg( $value, $request, $param );
		if ( true !== $validation ) {
			return $validation;
		}

		$phone = (string) $value;
		if ( '' !== $phone && ! \WC_Validation::is_phone( $phone ) ) {
			return new WP_Error(
				'rest_invalid_pattern',
				__( 'Error: Invalid phone number: ', 'woocommerce' ) . $phone
			);
		}

		if ( 'JP' === $this->account_service->get_account_country() && '+81' !== substr( $phone, 0, 3 ) ) {
			return new WP_Error(
				'rest_invalid_pattern',
				__( 'Error: Invalid Japanese phone number: ', 'woocommerce' ) . $phone
			);
		}

		return true;
	}

	/**
	 * Validate the business support address key allowlist.
	 *
	 * The platform maps the address straight onto the provider account, so a stray key fails the whole account update opaquely.
	 *
	 * @param mixed           $value   Support address value.
	 * @param WP_REST_Request $request Request.
	 * @param string          $param   Parameter name.
	 * @phpstan-param WP_REST_Request<array<string,mixed>> $request
	 * @return true|WP_Error
	 */
	public function validate_business_support_address( $value, WP_REST_Request $request, string $param ) {
		$validation = rest_validate_request_arg( $value, $request, $param );
		if ( true !== $validation ) {
			return $validation;
		}

		if ( is_array( $value ) ) {
			foreach ( array_keys( $value ) as $field ) {
				if ( ! in_array( $field, array( 'city', 'country', 'line1', 'line2', 'postal_code', 'state' ), true ) ) {
					return new WP_Error(
						'rest_invalid_pattern',
						__( 'Error: Invalid address format!', 'woocommerce' )
					);
				}
			}
		}

		return true;
	}

	/**
	 * Get a REST arg schema for string arrays.
	 *
	 * @param string[] $allowed_values Allowed values.
	 * @return array<string,mixed>
	 */
	private function get_string_array_arg( array $allowed_values ): array {
		return array(
			'type'              => 'array',
			'required'          => false,
			'uniqueItems'       => true,
			'maxItems'          => count( $allowed_values ),
			'items'             => array(
				'type' => 'string',
				'enum' => $allowed_values,
			),
			'validate_callback' => 'rest_validate_request_arg',
		);
	}

	/**
	 * Get validation args for file routes.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	private function get_file_route_args(): array {
		return array(
			'file_id'    => array(
				'required'          => true,
				'type'              => 'string',
				'pattern'           => '\w+',
				'validate_callback' => 'rest_validate_request_arg',
			),
			'as_account' => array(
				'required'          => false,
				'type'              => 'boolean',
				'validate_callback' => 'rest_validate_request_arg',
			),
		);
	}

	/**
	 * Get validation args for payment method promotion action routes.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	private function get_pm_promotion_route_args(): array {
		return array(
			'id' => array(
				'required'          => true,
				'type'              => 'string',
				'validate_callback' => fn( $value ) => $this->validate_pm_promotion_id( $value ),
			),
		);
	}

	/**
	 * Validate a payment method promotion ID.
	 *
	 * @param mixed $value Promotion ID.
	 * @return WP_Error|true
	 */
	private function validate_pm_promotion_id( $value ) {
		if ( ! is_string( $value ) || '' === $value || ! preg_match( '/^[A-Za-z0-9_-]+$/', $value ) ) {
			return new WP_Error(
				'rest_invalid_param',
				esc_html__( 'Invalid payment method promotion ID.', 'woocommerce' ),
				array( 'status' => 400 )
			);
		}

		return true;
	}

	/**
	 * Answer the account summary the WooPayments pages read; an exception answers a 500.
	 *
	 * @return WP_Error|WP_REST_Response The response or error.
	 */
	protected function get_account_summary() {
		try {
			$summary = $this->overview_service->get_account_summary();
		} catch ( Exception $e ) {
			return new WP_Error( 'woocommerce_rest_woopayments_account_error', $e->getMessage(), array( 'status' => WP_Http::INTERNAL_SERVER_ERROR ) );
		}

		return rest_ensure_response( $summary );
	}

	/**
	 * Answer the Overview page's data; an exception answers a 500.
	 *
	 * @return WP_Error|WP_REST_Response The response or error.
	 */
	protected function get_overview() {
		try {
			$overview = $this->overview_service->get_overview();
		} catch ( Exception $e ) {
			return new WP_Error( 'woocommerce_rest_woopayments_overview_error', $e->getMessage(), array( 'status' => WP_Http::INTERNAL_SERVER_ERROR ) );
		}

		return rest_ensure_response( $overview );
	}

	/**
	 * Answer the settings page's data.
	 *
	 * @return WP_REST_Response The response.
	 */
	protected function get_settings(): WP_REST_Response {
		return rest_ensure_response( $this->settings_service->get_settings() );
	}

	/**
	 * Save a settings page update.
	 *
	 * @param WP_REST_Request $request The request object.
	 * @phpstan-param WP_REST_Request<array<string,mixed>> $request
	 * @return WP_Error|WP_REST_Response The response or error.
	 */
	protected function update_settings( WP_REST_Request $request ) {
		$result = $this->settings_service->update_settings( $request->get_params() );
		if ( is_wp_error( $result ) ) {
			// A platform rejection of the account update with no field the page shows errors under answers `{ server_error }`
			// with a 400, which the settings page shows as a notice.
			if ( 'woocommerce_woopayments_account_update_rejected' === $result->get_error_code() ) {
				return new WP_REST_Response( array( 'server_error' => $result->get_error_message() ), 400 );
			}
			// A refused Multi-Currency untick also carries the Features page's confirmation URL, offered as the notice's action.
			if ( 'woocommerce_multi_currency_disable_protected' === $result->get_error_code() ) {
				$data = $result->get_error_data();

				return new WP_REST_Response(
					array(
						'server_error' => $result->get_error_message(),
						'disable_url'  => is_array( $data ) ? (string) ( $data['disable_url'] ?? '' ) : '',
					),
					400
				);
			}

			return $result;
		}

		return rest_ensure_response( $result );
	}

	/**
	 * Update one of the settings page's allowed options.
	 *
	 * @param WP_REST_Request $request The request object.
	 * @phpstan-param WP_REST_Request<array<string,mixed>> $request
	 * @return WP_Error|WP_REST_Response The response or error.
	 */
	protected function update_settings_option( WP_REST_Request $request ) {
		$option_name = (string) $request->get_param( 'option_name' );
		$value       = $request->get_param( 'value' );
		$result      = $this->settings_service->update_option( $option_name, $value );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return rest_ensure_response( array( 'success' => true ) );
	}

	/**
	 * Answer the payment method promotions the merchant can still use.
	 *
	 * @return WP_REST_Response The response.
	 */
	protected function get_pm_promotions(): WP_REST_Response {
		return rest_ensure_response( $this->pm_promotions_service->get_visible_promotions() ?? array() );
	}

	/**
	 * Record a current-user settings notice action.
	 *
	 * @since 11.2.0
	 *
	 * @param WP_REST_Request $request The request object.
	 * @phpstan-param WP_REST_Request<array<string,mixed>> $request
	 * @return WP_Error|WP_REST_Response
	 */
	protected function record_admin_notice_action( WP_REST_Request $request ) {
		$stage = $request->get_param( 'stage' );
		if ( null !== $stage && ! is_int( $stage ) ) {
			return new WP_Error( 'woocommerce_woopayments_invalid_notice_action', __( 'Invalid notice action.', 'woocommerce' ), array( 'status' => WP_Http::BAD_REQUEST ) );
		}

		$result = $this->admin_notice_service->record_action(
			sanitize_key( (string) $request->get_param( 'notice_id' ) ),
			sanitize_key( (string) $request->get_param( 'action' ) ),
			$stage
		);
		if ( is_wp_error( $result ) ) {
			$result->add_data( array( 'status' => WP_Http::BAD_REQUEST ) );
			return $result;
		}

		return rest_ensure_response( array( 'success' => $result ) );
	}

	/**
	 * Clear the Apple Pay domain error after the React settings notice showed it, if it is unchanged.
	 *
	 * @param WP_REST_Request $request The request object.
	 * @phpstan-param WP_REST_Request<array<string,mixed>> $request
	 * @return WP_Error|WP_REST_Response
	 */
	protected function record_apple_pay_domain_error_shown( WP_REST_Request $request ) {
		$cleared = $this->apple_pay_domain_service->clear_displayed_error_notice_if_unchanged( (string) $request->get_param( 'error_id' ) );

		return rest_ensure_response(
			array(
				'success' => true,
				'cleared' => $cleared,
			)
		);
	}

	/**
	 * Activate a payment method promotion.
	 *
	 * @param WP_REST_Request $request The request object.
	 * @phpstan-param WP_REST_Request<array<string,mixed>> $request
	 * @return WP_REST_Response The response.
	 */
	protected function activate_pm_promotion( WP_REST_Request $request ): WP_REST_Response {
		return rest_ensure_response(
			array(
				'success' => $this->pm_promotions_service->activate_promotion( (string) $request->get_param( 'id' ) ),
			)
		);
	}

	/**
	 * Dismiss a payment method promotion.
	 *
	 * @param WP_REST_Request $request The request object.
	 * @phpstan-param WP_REST_Request<array<string,mixed>> $request
	 * @return WP_REST_Response The response.
	 */
	protected function dismiss_pm_promotion( WP_REST_Request $request ): WP_REST_Response {
		return rest_ensure_response(
			array(
				'success' => $this->pm_promotions_service->dismiss_promotion( (string) $request->get_param( 'id' ) ),
			)
		);
	}

	/**
	 * Upload a settings page file to the platform.
	 *
	 * @param WP_REST_Request $request The request object.
	 * @phpstan-param WP_REST_Request<array<string,mixed>> $request
	 * @return WP_Error|WP_REST_Response The response or error.
	 */
	protected function upload_settings_file( WP_REST_Request $request ) {
		try {
			return rest_ensure_response( $this->api_client->upload_file( $request ) );
		} catch ( WooPaymentsApiException $exception ) {
			return $exception->to_wp_error();
		}
	}

	/**
	 * Answer a platform file's details.
	 *
	 * @param WP_REST_Request $request The request object.
	 * @phpstan-param WP_REST_Request<array<string,mixed>> $request
	 * @return WP_Error|WP_REST_Response The response or error.
	 */
	protected function get_settings_file_details( WP_REST_Request $request ) {
		try {
			return rest_ensure_response( $this->api_client->get_file( (string) $request->get_param( 'file_id' ), (bool) $request->get_param( 'as_account' ) ) );
		} catch ( WooPaymentsApiException $exception ) {
			return $exception->to_wp_error();
		}
	}

	/**
	 * Answer a platform file's contents.
	 *
	 * @param WP_REST_Request $request The request object.
	 * @phpstan-param WP_REST_Request<array<string,mixed>> $request
	 * @return WP_Error|WP_REST_Response The response or error.
	 */
	protected function get_settings_file_contents( WP_REST_Request $request ) {
		try {
			return rest_ensure_response( $this->api_client->get_file_contents( (string) $request->get_param( 'file_id' ), (bool) $request->get_param( 'as_account' ) ) );
		} catch ( WooPaymentsApiException $exception ) {
			return $exception->to_wp_error();
		}
	}

	/**
	 * Get a provider file as inline bytes when it is public, or when the current user may manage payment gateways.
	 *
	 * @param WP_REST_Request $request The request object.
	 * @phpstan-param WP_REST_Request<array<string,mixed>> $request
	 * @return WP_Error|WP_HTTP_Response The response or error.
	 */
	protected function get_public_settings_file( WP_REST_Request $request ) {
		$file_id    = (string) $request->get_param( 'file_id' );
		$as_account = (bool) $request->get_param( 'as_account' );
		$purpose    = $this->get_cached_file_purpose( $file_id, $as_account );

		if ( '' === $purpose ) {
			try {
				$file = $this->api_client->get_file( $file_id, $as_account );
			} catch ( WooPaymentsApiException $exception ) {
				return $this->get_file_error_response( $exception->to_wp_error() );
			}

			$purpose = isset( $file['purpose'] ) && is_scalar( $file['purpose'] ) ? (string) $file['purpose'] : '';
			if ( '' !== $purpose ) {
				set_transient( $this->get_file_purpose_cache_key( $file_id, $as_account ), $purpose, self::FILE_PURPOSE_CACHE_TTL );
			}
		}

		if ( ! $this->is_public_file_purpose( $purpose ) ) {
			$permission = $this->check_permissions( $request );
			if ( true !== $permission ) {
				return is_wp_error( $permission )
					? $permission
					: new WP_Error(
						'rest_forbidden',
						esc_html__( 'Sorry, you are not allowed to do that.', 'woocommerce' ),
						array( 'status' => rest_authorization_required_code() )
					);
			}
		}

		try {
			$contents = $this->api_client->get_file_contents( $file_id, $as_account );
		} catch ( WooPaymentsApiException $exception ) {
			return $this->get_file_error_response( $exception->to_wp_error() );
		}

		$file_content = isset( $contents['file_content'] ) && is_scalar( $contents['file_content'] ) ? (string) $contents['file_content'] : '';
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Decoding provider file contents for the inline file response.
		$decoded_file = base64_decode( $file_content, true );
		if ( false === $decoded_file ) {
			return new WP_Error(
				'woocommerce_woopayments_file_content_invalid',
				esc_html__( 'Unable to read the file contents.', 'woocommerce' ),
				array( 'status' => WP_Http::INTERNAL_SERVER_ERROR )
			);
		}

		add_filter( 'rest_pre_serve_request', array( $this, 'serve_public_file_response' ), 10, 2 );

		$content_type = isset( $contents['content_type'] ) && is_scalar( $contents['content_type'] ) ? (string) $contents['content_type'] : 'application/octet-stream';

		return new WP_HTTP_Response(
			$decoded_file,
			200,
			array(
				'Content-Type'        => $content_type,
				'Content-Disposition' => 'inline',
			)
		);
	}

	/**
	 * Stream a public provider file as the raw REST response body.
	 *
	 * Registered on `rest_pre_serve_request` by the public file route so the inline file bytes are emitted directly
	 * instead of being JSON-encoded. It is a named method, so a second dispatch in the same request adds no second
	 * callback: WordPress keeps one entry per object and method at a priority.
	 *
	 * @param bool             $served   Whether the request has already been served.
	 * @param WP_HTTP_Response $response The response to serve.
	 * @return bool
	 */
	public function serve_public_file_response( bool $served, WP_HTTP_Response $response ): bool {
		$content_disposition = $response->get_headers()['Content-Disposition'] ?? '';
		if ( 'inline' !== $content_disposition ) {
			return $served;
		}

		echo $response->get_data(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- File bytes are intentionally streamed as the response body.
		return true;
	}

	/**
	 * Get a cached provider file purpose.
	 *
	 * @param string $file_id    Provider file ID.
	 * @param bool   $as_account Whether the file is fetched as the connected account.
	 * @return string
	 */
	private function get_cached_file_purpose( string $file_id, bool $as_account ): string {
		$purpose = get_transient( $this->get_file_purpose_cache_key( $file_id, $as_account ) );

		return is_string( $purpose ) ? $purpose : '';
	}

	/**
	 * Get the transient cache key for a provider file purpose.
	 *
	 * @param string $file_id    Provider file ID.
	 * @param bool   $as_account Whether the file is fetched as the connected account.
	 * @return string
	 */
	private function get_file_purpose_cache_key( string $file_id, bool $as_account ): string {
		return self::FILE_PURPOSE_CACHE_PREFIX . $file_id . '_' . ( $as_account ? '1' : '0' );
	}

	/**
	 * Tell whether a provider file purpose may be served publicly.
	 *
	 * @param string $purpose Provider file purpose.
	 * @return bool
	 */
	private function is_public_file_purpose( string $purpose ): bool {
		return in_array( $purpose, self::PUBLIC_FILE_PURPOSES, true );
	}

	/**
	 * Answer a file error with a 404 when the platform has no such file, else a 500.
	 *
	 * @param WP_Error $error File API error.
	 * @return WP_Error
	 */
	private function get_file_error_response( WP_Error $error ): WP_Error {
		$status = 'resource_missing' === $error->get_error_code() ? WP_Http::NOT_FOUND : WP_Http::INTERNAL_SERVER_ERROR;

		return new WP_Error(
			$error->get_error_code(),
			$error->get_error_message(),
			array( 'status' => $status )
		);
	}

	/**
	 * Require an authenticated store manager for notice state changes.
	 *
	 * @since 11.2.0
	 *
	 * @param WP_REST_Request $request The request object.
	 * @phpstan-param WP_REST_Request<array<string,mixed>> $request
	 * @return bool|WP_Error
	 */
	private function check_admin_notice_permissions( WP_REST_Request $request ) {
		if ( ! is_user_logged_in() ) {
			return new WP_Error( 'rest_forbidden', __( 'Sorry, you are not allowed to do that.', 'woocommerce' ), array( 'status' => WP_Http::UNAUTHORIZED ) );
		}

		return $this->check_permissions( $request );
	}

	/**
	 * General permissions check for WooPayments settings REST API endpoint.
	 *
	 * @param WP_REST_Request $request The request for which the permission is checked.
	 * @phpstan-param WP_REST_Request<array<string,mixed>> $request
	 *
	 * @return bool|WP_Error True if the current user has the capability, otherwise an "Unauthorized" error or False if no error is available for the request method.
	 */
	private function check_permissions( WP_REST_Request $request ) {
		$context = 'read';
		if ( 'POST' === $request->get_method() ) {
			$context = 'edit';
		} elseif ( 'DELETE' === $request->get_method() ) {
			$context = 'delete';
		}

		if ( wc_rest_check_manager_permissions( 'payment_gateways', $context ) ) {
			return true;
		}

		$error_information = $this->get_authentication_error_by_method( $request->get_method() );
		if ( is_null( $error_information ) ) {
			return false;
		}

		return new WP_Error(
			$error_information['code'],
			$error_information['message'],
			array( 'status' => rest_authorization_required_code() )
		);
	}
}
