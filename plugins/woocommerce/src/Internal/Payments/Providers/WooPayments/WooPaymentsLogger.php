<?php
/**
 * WooPaymentsLogger class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\WooPayments;

use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api\WooPaymentsApiException;
use Exception;
use Throwable;

defined( 'ABSPATH' ) || exit;

/**
 * Writes WooPayments log lines under one source, only when debug logging is on or in dev mode.
 *
 * Mirrors client 11.1.0 `src/Internal/Logger.php:22,64-91`: every level is gated, and the setting is read
 * from the gateway settings option so no gateway needs to be initialized. The one exception is a PHP Error
 * caught where the client catches only exceptions: see log_throwable().
 *
 * @since 11.2.0
 * @internal
 */
class WooPaymentsLogger {

	/**
	 * Log source the client writes to.
	 */
	public const SOURCE = 'woopayments';

	/**
	 * Number of stack frames written for a caught throwable.
	 */
	private const TRACE_FRAMES = 5;

	/**
	 * Logged in place of a platform or Stripe code this class does not list.
	 */
	public const UNKNOWN_ERROR_CODE = 'unknown_error';

	/**
	 * Platform and Stripe error codes a log line may carry as they are.
	 *
	 * A code reaches the log only from this list, because the platform's code field is free text to this store: an
	 * error envelope could hold a URL, an email or a token there. The list is the codes this code base throws or acts
	 * on: the transport's own codes (WooPaymentsApiClient, StripeBillingApi, the request classes), the codes the
	 * provider branches on, Stripe's error types (`throw_api_error()` uses the type when the code is missing) and the
	 * card errors and decline codes WooPaymentsErrorMessages::get_localized_messages() translates.
	 */
	private const LOGGABLE_ERROR_CODES = array(
		// Transport and request validation codes.
		'invalid_fraud_outcome_status',
		'wcpay_client_error_code_missing',
		'wcpay_client_unable_to_encode_json',
		'wcpay_core_invalid_request_parameter_invalid_redirect_url',
		'wcpay_evidence_file_max_size',
		'wcpay_evidence_file_read_error',
		'wcpay_evidence_file_upload_error',
		'wcpay_http_request_failed',
		'wcpay_invalid_filtered_request',
		'wcpay_invalid_payment_credential',
		'wcpay_invalid_payment_method_types',
		'wcpay_invalid_terminal_location_request',
		'wcpay_mandatory_currency_from_missing',
		'wcpay_mandatory_customer_id_missing',
		'wcpay_mandatory_price_id_missing',
		'wcpay_mandatory_product_id_missing',
		'wcpay_missing_payment_method_types',
		'wcpay_request_missing_hook',
		'wcpay_route_validation_failure',
		'wcpay_unparseable_or_null_body',
		'wcpay_wpcom_not_connected',
		// Platform codes the provider acts on.
		'amount_too_large',
		'amount_too_small',
		'idempotency_key_in_use',
		'insufficient_balance_for_refund',
		'resource_missing',
		'wcpay_account_not_found',
		'wcpay_api_error',
		'wcpay_bad_request',
		'wcpay_blocked_by_fraud_rule',
		'wcpay_card_testing_prevention',
		'wcpay_fraud_ruleset_not_found',
		'wcpay_on_boarding_disabled',
		// Stripe error types.
		'api_error',
		'card_error',
		'idempotency_error',
		'invalid_request_error',
		// Card errors and decline codes with a translated shopper message.
		'authentication_required',
		'card_declined',
		'country_code_invalid',
		'email_invalid',
		'expired_card',
		'fraudulent',
		'incomplete_cvc',
		'incomplete_expiry',
		'incomplete_number',
		'incorrect_cvc',
		'incorrect_number',
		'incorrect_zip',
		'insufficient_funds',
		'invalid_cvc',
		'invalid_expiry_month',
		'invalid_expiry_year',
		'invalid_expiry_year_past',
		'invalid_number',
		'invalid_sofort_country',
		'invalid_wallet_type',
		'missing',
		'payment_intent_authentication_failure',
		'postal_code_invalid',
		'processing_error',
		'tax_id_invalid',
	);

	/**
	 * WP_Error codes WordPress's HTTP API sets on a failed request (`WP_Http::request()`), logged as they are.
	 */
	private const LOGGABLE_TRANSPORT_ERROR_CODES = array( 'http_request_failed', 'http_request_not_executed', 'http_failure' );

	/**
	 * Account service.
	 *
	 * @var WooPaymentsAccountService
	 */
	private WooPaymentsAccountService $account_service;

	/**
	 * Initialize the class instance.
	 *
	 * @internal
	 *
	 * @param WooPaymentsAccountService $account_service Account service.
	 */
	final public function init( WooPaymentsAccountService $account_service ): void {
		$this->account_service = $account_service;
	}

	/**
	 * Tell whether log lines are written: in dev mode or with the gateway's `enable_logging` setting on.
	 *
	 * @return bool
	 */
	public function can_log(): bool {
		return $this->account_service->is_dev_mode_enabled() || 'yes' === $this->account_service->get_gateway_setting( 'enable_logging' );
	}

	/**
	 * Write a log line when logging is enabled.
	 *
	 * @param string              $message Message.
	 * @param string              $level   Log level.
	 * @param array<string,mixed> $context Context, such as order_id or intent_id.
	 */
	public function log( string $message, string $level = 'info', array $context = array() ): void {
		if ( ! $this->can_log() ) {
			return;
		}

		$this->write( $message, $level, $context, true );
	}

	/**
	 * Write an error line when logging is enabled.
	 *
	 * @param string              $message Message.
	 * @param array<string,mixed> $context Context, such as order_id or intent_id.
	 */
	public function error( string $message, array $context = array() ): void {
		$this->log( $message, 'error', $context );
	}

	/**
	 * Log a caught throwable with its class, code and a short trace, plus the platform's status and code for a platform error.
	 *
	 * An Exception follows the logging setting, as on the client (`includes/class-logger.php:100-112`). Any other
	 * throwable is a PHP Error the client would let fatal, so it is always written at error level (monitor rule 2026-10-04).
	 *
	 * @param string              $message   Message.
	 * @param Throwable           $throwable Caught throwable.
	 * @param array<string,mixed> $context   Context, such as order_id or intent_id.
	 * @param string              $level     Log level for an Exception.
	 */
	public function log_throwable( string $message, Throwable $throwable, array $context = array(), string $level = 'error' ): void {
		if ( $throwable instanceof Exception ) {
			$this->log( $message, $level, array_merge( $context, self::get_failure_context( $throwable ) ) );
			return;
		}

		$this->log_throwable_always( $message, $throwable, $context );
	}

	/**
	 * Write an error line for a caught throwable whatever the logging setting.
	 *
	 * @param string              $message   Message.
	 * @param Throwable           $throwable Caught throwable.
	 * @param array<string,mixed> $context   Context, such as order_id or intent_id.
	 */
	public function log_throwable_always( string $message, Throwable $throwable, array $context = array() ): void {
		$this->log_always( $message, 'error', array_merge( $context, self::get_failure_context( $throwable ) ) );
	}

	/**
	 * Write a line whatever the logging setting, for an anomaly support needs to see: on the money path, or a failure that
	 * leaves settings or onboarding silently wrong.
	 *
	 * @param string              $message Message.
	 * @param string              $level   Log level.
	 * @param array<string,mixed> $context Context, such as order_id or intent_id.
	 */
	public function log_always( string $message, string $level, array $context = array() ): void {
		$this->write( $message, $level, $context, $this->can_log() );
	}

	/**
	 * Write a line, with the request context only while WooPayments logging is on, as the client adds it only then.
	 *
	 * @param string              $message              Message.
	 * @param string              $level                Log level.
	 * @param array<string,mixed> $context              Context, such as order_id or intent_id.
	 * @param bool                $with_request_context Whether to add the request context.
	 */
	private function write( string $message, string $level, array $context, bool $with_request_context ): void {
		$request_context = $with_request_context ? $this->get_request_context() : array();
		wc_get_logger()->log( $level, $message, array_merge( $request_context, $context, array( 'source' => self::SOURCE ) ) );
	}

	/**
	 * Get the request context the client adds to every line (client 11.1.0 `src/Internal/LoggerContext.php:140-164`).
	 *
	 * The referrer and the request's query string are left out: on order-pay pages they carry the order key. The user is
	 * read only after `init`, when WordPress has loaded and settled it.
	 *
	 * @return array<string,string>
	 */
	private function get_request_context(): array {
		$user = did_action( 'init' ) ? wp_get_current_user() : null;
		// phpcs:disable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitized below, as the client does.
		$user_agent  = isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '--';
		$request_uri = isset( $_SERVER['REQUEST_URI'] ) ? wp_parse_url( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ), PHP_URL_PATH ) : '--';
		// phpcs:enable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

		return array(
			'WP_USER'          => null === $user ? '--' : ( $user->exists() ? $user->user_login : 'Guest (non logged-in user)' ),
			'HTTP_USER_AGENT'  => $user_agent,
			'REQUEST_URI'      => is_string( $request_uri ) ? $request_uri : '--',
			'DOING_AJAX'       => wp_doing_ajax() ? '1' : '',
			'DOING_CRON'       => wp_doing_cron() ? '1' : '',
			'WP_CLI'           => defined( 'WP_CLI' ) && WP_CLI ? '1' : '',
			'WOOPAYMENTS_MODE' => $this->account_service->is_test_mode_enabled() ? WooPaymentsOrderMode::TEST : WooPaymentsOrderMode::PRODUCTION,
		);
	}

	/**
	 * Get what a log line may say about a caught throwable: get_throwable_context() plus get_api_error_context().
	 *
	 * @param Throwable $throwable Caught throwable.
	 * @return array<string,int|string>
	 */
	public static function get_failure_context( Throwable $throwable ): array {
		return array_merge( self::get_throwable_context( $throwable ), self::get_api_error_context( $throwable ) );
	}

	/**
	 * Get the HTTP status and the listed codes of the platform error a throwable is, or wraps; empty for any other.
	 *
	 * Never the message: the platform and Stripe write it, and a transport failure's message can name the host or URL.
	 *
	 * @param Throwable $throwable Caught throwable.
	 * @return array<string,int|string>
	 */
	public static function get_api_error_context( Throwable $throwable ): array {
		$api_error = $throwable;
		while ( null !== $api_error && ! $api_error instanceof WooPaymentsApiException ) {
			$api_error = $api_error->getPrevious();
		}
		if ( ! $api_error instanceof WooPaymentsApiException ) {
			return array();
		}

		$context = array(
			'http_status' => $api_error->get_http_code(),
			'error_code'  => self::get_loggable_error_code( $api_error->get_error_code() ),
		);
		if ( '' !== $api_error->get_decline_code() ) {
			$context['decline_code'] = self::get_loggable_error_code( $api_error->get_decline_code() );
		}
		$transport_error_code = $api_error->get_error_data()['transport_error_code'] ?? null;
		if ( is_string( $transport_error_code ) && '' !== $transport_error_code ) {
			$context['transport_error_code'] = in_array( $transport_error_code, self::LOGGABLE_TRANSPORT_ERROR_CODES, true ) ? $transport_error_code : self::UNKNOWN_ERROR_CODE;
		}

		return $context;
	}

	/**
	 * Get a platform or Stripe code as a log line may carry it: the code when this class lists it, else unknown_error.
	 *
	 * @param string $code Platform or Stripe code.
	 * @return string
	 */
	public static function get_loggable_error_code( string $code ): string {
		return in_array( $code, self::LOGGABLE_ERROR_CODES, true ) ? $code : self::UNKNOWN_ERROR_CODE;
	}

	/**
	 * Get a throwable's class, code and first stack frames, without its message or call arguments.
	 *
	 * Public for the WooPay lines still written under their own log source.
	 *
	 * @param Throwable $throwable Caught throwable.
	 * @return array{exception:string,code:int|string,trace:string}
	 */
	public static function get_throwable_context( Throwable $throwable ): array {
		$frames = array();
		foreach ( array_slice( $throwable->getTrace(), 0, self::TRACE_FRAMES ) as $index => $frame ) {
			$frames[] = sprintf(
				'#%d %s(%s): %s%s%s()',
				$index,
				$frame['file'] ?? '[internal function]',
				$frame['line'] ?? '',
				$frame['class'] ?? '',
				$frame['type'] ?? '',
				$frame['function']
			);
		}

		return array(
			'exception' => get_class( $throwable ),
			'code'      => $throwable->getCode(),
			'trace'     => implode( "\n", $frames ),
		);
	}
}
