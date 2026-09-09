<?php
declare( strict_types=1 );

namespace Automattic\WooCommerce\Internal\Admin\Settings\PaymentsProviders\WooPayments;

use Automattic\WooCommerce\Internal\RestApiControllerBase;
use Automattic\WooCommerce\Internal\Payments\NativePaymentsRuntimeArbiter;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsAccountService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsPmPromotionsService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsSettingsService;
use Exception;
use WP_Error;
use WP_Http;
use WP_HTTP_Response;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Controller for the native WooPayments merchant REST endpoints.
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
	private const FILE_PURPOSE_CACHE_PREFIX = 'woocommerce_native_woopayments_file_purpose_';

	/**
	 * Lifetime, in seconds, of a cached provider file purpose.
	 *
	 * The cache only avoids re-fetching the file classification on repeated requests. It is intentionally short so a
	 * provider-side reclassification (e.g. a file flipped from public to private) propagates quickly instead of letting
	 * a stale "public" classification keep serving the file to unauthenticated callers.
	 */
	private const FILE_PURPOSE_CACHE_TTL = 5 * MINUTE_IN_SECONDS;

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
	 * The WooPayments-specific Payments settings page service.
	 *
	 * @var WooPaymentsService
	 */
	private WooPaymentsService $woopayments;

	/**
	 * The native WooPayments settings contract service.
	 *
	 * @var WooPaymentsSettingsService|null
	 */
	private ?WooPaymentsSettingsService $settings_service = null;

	/**
	 * The native WooPayments payment method promotions service.
	 *
	 * @var WooPaymentsPmPromotionsService|null
	 */
	private ?WooPaymentsPmPromotionsService $pm_promotions_service = null;

	/**
	 * The native WooPayments Overview projection service.
	 *
	 * @var WooPaymentsOverviewService|null
	 */
	private ?WooPaymentsOverviewService $overview_service = null;

	/**
	 * The native WooPayments account service.
	 *
	 * @var WooPaymentsAccountService|null
	 */
	private ?WooPaymentsAccountService $account_service = null;

	/**
	 * Native payments runtime arbiter.
	 *
	 * @var NativePaymentsRuntimeArbiter|null
	 */
	private ?NativePaymentsRuntimeArbiter $runtime_arbiter = null;

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
		if ( $this->should_register_native_settings_routes() ) {
			$this->register_native_settings_routes( $override );
		}

		register_rest_route(
			$this->route_namespace,
			'/' . $this->rest_base . '/account',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => fn( $request ) => $this->run( $request, 'get_account_summary' ),
					'validation_callback' => 'rest_validate_request_arg',
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
					'validation_callback' => 'rest_validate_request_arg',
					'permission_callback' => fn( $request ) => $this->check_permissions( $request ),
				),
			),
			$override
		);
	}

	/**
	 * Initialize the class instance.
	 *
	 * @param WooPaymentsService                  $woopayments           The WooPayments-specific Payments settings page service.
	 * @param WooPaymentsSettingsService|null     $settings_service      Optional native WooPayments settings service.
	 * @param NativePaymentsRuntimeArbiter|null   $runtime_arbiter       Optional native payments runtime arbiter.
	 * @param WooPaymentsPmPromotionsService|null $pm_promotions_service Optional native WooPayments PM promotions service.
	 * @param WooPaymentsOverviewService|null     $overview_service      Optional native WooPayments Overview projection service.
	 * @param WooPaymentsAccountService|null      $account_service       Optional native WooPayments account service.
	 *
	 * @internal
	 */
	final public function init( WooPaymentsService $woopayments, ?WooPaymentsSettingsService $settings_service = null, ?NativePaymentsRuntimeArbiter $runtime_arbiter = null, ?WooPaymentsPmPromotionsService $pm_promotions_service = null, ?WooPaymentsOverviewService $overview_service = null, ?WooPaymentsAccountService $account_service = null ): void {
		$this->woopayments           = $woopayments;
		$this->settings_service      = $settings_service;
		$this->runtime_arbiter       = $runtime_arbiter;
		$this->pm_promotions_service = $pm_promotions_service;
		$this->overview_service      = $overview_service;
		$this->account_service       = $account_service;
	}

	/**
	 * Register the legacy-compatible native WooPayments settings endpoints.
	 *
	 * @param bool $override Whether to override existing routes.
	 * @return void
	 */
	private function register_native_settings_routes( bool $override ): void {
		register_rest_route(
			'wc/v3',
			'/payments/settings',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => fn( $request ) => $this->run( $request, 'get_native_settings' ),
					'permission_callback' => fn( $request ) => $this->check_permissions( $request ),
				),
				array(
					'methods'             => \WP_REST_Server::EDITABLE,
					'callback'            => fn( $request ) => $this->run( $request, 'update_native_settings' ),
					'permission_callback' => fn( $request ) => $this->check_permissions( $request ),
					'args'                => $this->get_native_settings_update_args(),
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
					'callback'            => fn( $request ) => $this->run( $request, 'update_native_settings_option' ),
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
					'callback'            => fn( $request ) => $this->run( $request, 'get_native_pm_promotions' ),
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
					'callback'            => fn( $request ) => $this->run( $request, 'activate_native_pm_promotion' ),
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
					'callback'            => fn( $request ) => $this->run( $request, 'dismiss_native_pm_promotion' ),
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
					'callback'            => fn( $request ) => $this->run( $request, 'upload_native_settings_file' ),
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
					'callback'            => fn( $request ) => $this->run( $request, 'get_native_settings_file_details' ),
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
					'callback'            => fn( $request ) => $this->run( $request, 'get_native_settings_file_contents' ),
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
					'callback'            => fn( $request ) => $this->run( $request, 'get_native_public_settings_file' ),
					'permission_callback' => '__return_true',
					'args'                => $this->get_file_route_args(),
				),
			),
			$override
		);
	}

	/**
	 * Tell whether native WooPayments settings routes may register for this request.
	 *
	 * @return bool
	 */
	private function should_register_native_settings_routes(): bool {
		return null !== $this->runtime_arbiter && $this->runtime_arbiter->should_native_register();
	}

	/**
	 * Get validation args for the legacy-compatible WooPayments settings update route.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	private function get_native_settings_update_args(): array {
		$payment_method_ids = WooPaymentsSettingsService::get_supported_payment_method_ids();
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
	 * A structurally invalid advanced ruleset must fail the save here: the settings service would otherwise skip the fraud block silently and return 200 while the platform keeps the previous ruleset. The WooPayments plugin persists its non-fraud settings and then dies with a 500 on the same input; rejecting atomically with a 400 is a deliberate, strictly safer deviation.
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
			&& ! $this->get_settings_service()->is_valid_fraud_ruleset( $value )
		) {
			return new WP_Error(
				'rest_invalid_pattern',
				__( 'Invalid ruleset configuration.', 'woocommerce' )
			);
		}

		return true;
	}

	/**
	 * Validate the account statement descriptor with the WooPayments plugin's rules.
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
	 * Validate the account communications email with the WooPayments plugin's rules.
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
	 * Validate the business support email with the WooPayments plugin's rules.
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
	 * The plugin has no server-side rule here, but the native runtime mirrors the sent value locally, and an unvalidated non-hex value would previously be sanitized to an empty mirror while the raw value went to the platform. The UI's color picker only emits hex values, so this only constrains direct REST clients.
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
	 * Deliberately lenient — the plugin forwards scheme-less values like example.com raw, so only values esc_url_raw() strips entirely (e.g. javascript: URLs) are rejected.
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
	 * Validate the business support phone with the WooPayments plugin's rules.
	 *
	 * Japanese accounts require a +81 number even when the value is empty, matching the plugin.
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

		if ( 'JP' === $this->get_account_service()->get_account_country() && '+81' !== substr( $phone, 0, 3 ) ) {
			return new WP_Error(
				'rest_invalid_pattern',
				__( 'Error: Invalid Japanese phone number: ', 'woocommerce' ) . $phone
			);
		}

		return true;
	}

	/**
	 * Validate the business support address key allowlist with the WooPayments plugin's rules.
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
	 * Get the native WooPayments account service.
	 *
	 * @return WooPaymentsAccountService
	 */
	private function get_account_service(): WooPaymentsAccountService {
		if ( null === $this->account_service ) {
			$this->account_service = wc_get_container()->get( WooPaymentsAccountService::class );
		}

		return $this->account_service;
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
	 * Get a safe read-only account summary for the native WooPayments settings surface.
	 *
	 * @return WP_Error|WP_REST_Response The response or error.
	 */
	protected function get_account_summary() {
		try {
			$summary = $this->woopayments->get_account_summary();
		} catch ( Exception $e ) {
			return new WP_Error( 'woocommerce_rest_woopayments_account_error', $e->getMessage(), array( 'status' => WP_Http::INTERNAL_SERVER_ERROR ) );
		}

		return rest_ensure_response( $summary );
	}

	/**
	 * Get a safe read-only Overview projection for the native WooPayments settings surface.
	 *
	 * @return WP_Error|WP_REST_Response The response or error.
	 */
	protected function get_overview() {
		try {
			$overview = $this->get_overview_service()->get_overview();
		} catch ( Exception $e ) {
			return new WP_Error( 'woocommerce_rest_woopayments_overview_error', $e->getMessage(), array( 'status' => WP_Http::INTERNAL_SERVER_ERROR ) );
		}

		return rest_ensure_response( $overview );
	}

	/**
	 * Get the native WooPayments settings contract.
	 *
	 * @return WP_REST_Response The response.
	 */
	protected function get_native_settings(): WP_REST_Response {
		return rest_ensure_response( $this->get_settings_service()->get_settings() );
	}

	/**
	 * Update the native WooPayments settings contract.
	 *
	 * @param WP_REST_Request $request The request object.
	 * @phpstan-param WP_REST_Request<array<string,mixed>> $request
	 * @return WP_Error|WP_REST_Response The response or error.
	 */
	protected function update_native_settings( WP_REST_Request $request ) {
		$result = $this->get_settings_service()->update_settings( $request->get_params() );
		if ( is_wp_error( $result ) ) {
			// A platform account-update rejection with no inline-capable field uses the plugin's legacy body shape, which the settings UI reads from the server_error key.
			if ( 'woocommerce_woopayments_account_update_rejected' === $result->get_error_code() ) {
				return new WP_REST_Response( array( 'server_error' => $result->get_error_message() ), 400 );
			}

			return $result;
		}

		return rest_ensure_response( $result );
	}

	/**
	 * Update an allowlisted native WooPayments settings option.
	 *
	 * @param WP_REST_Request $request The request object.
	 * @phpstan-param WP_REST_Request<array<string,mixed>> $request
	 * @return WP_Error|WP_REST_Response The response or error.
	 */
	protected function update_native_settings_option( WP_REST_Request $request ) {
		$option_name = (string) $request->get_param( 'option_name' );
		$value       = $request->get_param( 'value' );
		$result      = $this->get_settings_service()->update_option( $option_name, $value );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return rest_ensure_response( array( 'success' => true ) );
	}

	/**
	 * Get visible native WooPayments payment method promotions.
	 *
	 * @return WP_REST_Response The response.
	 */
	protected function get_native_pm_promotions(): WP_REST_Response {
		return rest_ensure_response( $this->get_pm_promotions_service()->get_visible_promotions() ?? array() );
	}

	/**
	 * Activate a native WooPayments payment method promotion.
	 *
	 * @param WP_REST_Request $request The request object.
	 * @phpstan-param WP_REST_Request<array<string,mixed>> $request
	 * @return WP_REST_Response The response.
	 */
	protected function activate_native_pm_promotion( WP_REST_Request $request ): WP_REST_Response {
		return rest_ensure_response(
			array(
				'success' => $this->get_pm_promotions_service()->activate_promotion( (string) $request->get_param( 'id' ) ),
			)
		);
	}

	/**
	 * Dismiss a native WooPayments payment method promotion.
	 *
	 * @param WP_REST_Request $request The request object.
	 * @phpstan-param WP_REST_Request<array<string,mixed>> $request
	 * @return WP_REST_Response The response.
	 */
	protected function dismiss_native_pm_promotion( WP_REST_Request $request ): WP_REST_Response {
		return rest_ensure_response(
			array(
				'success' => $this->get_pm_promotions_service()->dismiss_promotion( (string) $request->get_param( 'id' ) ),
			)
		);
	}

	/**
	 * Upload a file for the native WooPayments settings page.
	 *
	 * @param WP_REST_Request $request The request object.
	 * @phpstan-param WP_REST_Request<array<string,mixed>> $request
	 * @return WP_Error|WP_REST_Response The response or error.
	 */
	protected function upload_native_settings_file( WP_REST_Request $request ) {
		$result = $this->get_settings_service()->upload_file( $request );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return rest_ensure_response( $result );
	}

	/**
	 * Get provider file details for the native WooPayments settings page.
	 *
	 * @param WP_REST_Request $request The request object.
	 * @phpstan-param WP_REST_Request<array<string,mixed>> $request
	 * @return WP_Error|WP_REST_Response The response or error.
	 */
	protected function get_native_settings_file_details( WP_REST_Request $request ) {
		$result = $this->get_settings_service()->get_file(
			(string) $request->get_param( 'file_id' ),
			(bool) $request->get_param( 'as_account' )
		);
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return rest_ensure_response( $result );
	}

	/**
	 * Get provider file contents for the native WooPayments settings page.
	 *
	 * @param WP_REST_Request $request The request object.
	 * @phpstan-param WP_REST_Request<array<string,mixed>> $request
	 * @return WP_Error|WP_REST_Response The response or error.
	 */
	protected function get_native_settings_file_contents( WP_REST_Request $request ) {
		$result = $this->get_settings_service()->get_file_contents(
			(string) $request->get_param( 'file_id' ),
			(bool) $request->get_param( 'as_account' )
		);
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return rest_ensure_response( $result );
	}

	/**
	 * Get a provider file as inline bytes when it is public, or when the current user may manage payment gateways.
	 *
	 * @param WP_REST_Request $request The request object.
	 * @phpstan-param WP_REST_Request<array<string,mixed>> $request
	 * @return WP_Error|WP_HTTP_Response The response or error.
	 */
	protected function get_native_public_settings_file( WP_REST_Request $request ) {
		$file_id    = (string) $request->get_param( 'file_id' );
		$as_account = (bool) $request->get_param( 'as_account' );
		$purpose    = $this->get_cached_file_purpose( $file_id, $as_account );

		if ( '' === $purpose ) {
			$file = $this->get_settings_service()->get_file( $file_id, $as_account );
			if ( is_wp_error( $file ) ) {
				return $this->get_file_error_response( $file );
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

		$contents = $this->get_settings_service()->get_file_contents( $file_id, $as_account );
		if ( is_wp_error( $contents ) ) {
			return $this->get_file_error_response( $contents );
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

		$serve_callback = array( $this, 'serve_public_file_response' );
		if ( false === has_filter( 'rest_pre_serve_request', $serve_callback ) ) {
			add_filter( 'rest_pre_serve_request', $serve_callback, 10, 2 );
		}

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
	 * instead of being JSON-encoded. It is a named method so the route can register it idempotently (at most once per
	 * request) and remove it, rather than stacking a fresh anonymous closure on every dispatch.
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
	 * Normalize public file route errors to the reference REST contract.
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
	 * Get the native WooPayments settings service.
	 *
	 * @return WooPaymentsSettingsService
	 */
	private function get_settings_service(): WooPaymentsSettingsService {
		if ( ! $this->settings_service instanceof WooPaymentsSettingsService ) {
			$this->settings_service = wc_get_container()->get( WooPaymentsSettingsService::class );
		}

		return $this->settings_service;
	}

	/**
	 * Get the native WooPayments payment method promotions service.
	 *
	 * @return WooPaymentsPmPromotionsService
	 */
	private function get_pm_promotions_service(): WooPaymentsPmPromotionsService {
		if ( ! $this->pm_promotions_service instanceof WooPaymentsPmPromotionsService ) {
			$this->pm_promotions_service = wc_get_container()->get( WooPaymentsPmPromotionsService::class );
		}

		return $this->pm_promotions_service;
	}

	/**
	 * Get the native WooPayments Overview projection service.
	 *
	 * @return WooPaymentsOverviewService
	 */
	private function get_overview_service(): WooPaymentsOverviewService {
		if ( ! $this->overview_service instanceof WooPaymentsOverviewService ) {
			$this->overview_service = wc_get_container()->get( WooPaymentsOverviewService::class );
		}

		return $this->overview_service;
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
