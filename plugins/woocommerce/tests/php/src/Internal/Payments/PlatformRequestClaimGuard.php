<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments;

use Automattic\WooCommerce\Internal\Payments\OrderPaymentLock;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api\Client;
use Automattic\WooCommerce\Internal\Payments\TransientRowLock;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments\Api\FakeHttpClient;
use PHPUnit\Framework\Assert;
use ReflectionProperty;

/**
 * Observes signed transport attempts and public platform HTTP requests in Payments tests.
 */
final class PlatformRequestClaimGuard {

	/**
	 * The order lock's platform request permissions. Each row requires the holder operation, verb and route,
	 * and every listed provider caller > callee pair. The money operations and their decision reads come first.
	 * No row lets checkout, capture, payment intent creation or terminal capture read the account: each entry point
	 * reads it once before its claim, so the Level 3 lookup and the composition data settler read it from memory.
	 * The webhooks' two account reads are the remaining stated exceptions, until events read the account before their claim.
	 *
	 * Stated limit: the cached account is checked for expiry on every read. When it expires between the read before
	 * the claim and a read under it, the later read fetches the account under the claim. That is about 1 in 2,500 admin
	 * captures and 1 in 30,000 front-end payments, and it costs about a second of hold. Closing it would change what the
	 * account cache means for every reader in a request, so it is not closed. No test moves the clock between the reads.
	 *
	 * Hook callbacks under the claim are outside coverage if they use another transport or never run in the test.
	 * The guard also misses requests to other hosts, tests that mock the HTTP client below the guard,
	 * and Payments tests that use a base class outside the WC_Unit_Test_Case hierarchy.
	 */
	private const RULES = array(
		array( 'checkout', 'POST intentions', 'WooPaymentsPaymentOperationService::send_charge_request>create_and_confirm_payment_intention' ),
		array( 'checkout', 'POST setup_intents', 'WooPaymentsPaymentOperationService::create_setup_intent>create_setup_intention' ),
		array( 'checkout', 'POST setup_intents', 'WooPaymentsPaymentOperationService::create_setup_intent>create_and_confirm_setup_intention' ),
		array( 'checkout', 'GET intentions', 'WooPaymentsChargeAmbiguityService::find_order_intents>list_payment_intentions', 'WooPaymentsPaymentOperationService::settle_earlier_charge>find_order_intents' ),
		array( 'checkout', 'GET intentions', 'WooPaymentsChargeAmbiguityService::find_account_order_intents>list_payment_intentions_created_between', 'WooPaymentsPaymentOperationService::settle_earlier_charge>find_order_intents' ),
		array( 'payment intent creation', 'POST intentions', 'WooPaymentsPaymentDetailsRestController::create_payment_intent>create_and_confirm_payment_intention' ),
		array( 'capture', 'POST intentions/{id}/capture', 'WooPaymentsPaymentOperationService::capture>capture_intention' ),
		array( 'capture', 'GET intentions/{id}', 'WooPaymentsPaymentOperationService::refuse_a_target_of_another_order>get_payment_intention', 'WooPaymentsPaymentOperationService::capture>refuse_a_target_of_another_order' ),
		array( 'capture', 'GET intentions/{id}', 'WooPaymentsPaymentOperationService::refetch_intent_after_failure>get_payment_intention', 'WooPaymentsPaymentOperationService::capture>refetch_intent_after_failure' ),
		array( 'cancel', 'POST intentions/{id}/cancel', 'WooPaymentsPaymentOperationService::cancel>cancel_intention' ),
		array( 'cancel', 'GET intentions/{id}', 'WooPaymentsPaymentOperationService::refuse_a_target_of_another_order>get_payment_intention', 'WooPaymentsPaymentOperationService::cancel>refuse_a_target_of_another_order' ),
		array( 'cancel', 'GET intentions/{id}', 'WooPaymentsPaymentOperationService::refetch_intent_after_failure>get_payment_intention', 'WooPaymentsPaymentOperationService::cancel>refetch_intent_after_failure' ),
		array( 'refund', 'GET payment_methods/{id}', 'WooPaymentsPaymentOperationService::is_interac_present_payment>get_payment_method' ),
		array( 'refund', 'GET refunds', 'WooPaymentsPaymentOperationService::link_card_reader_refund>list_charge_refunds' ),
		array( 'refund', 'GET refunds', 'WooPaymentsRefundAmbiguityService::decide>list_charge_refunds' ),
		array( 'refund', 'POST refunds', 'WooPaymentsPaymentOperationService::send_refund>refund_charge' ),
		array( 'refund', 'POST refunds', 'WooPaymentsPaymentDetailsRestController::refund_charge_without_order>refund_charge' ),
		array( 'terminal capture', 'GET intentions/{id}', 'WooPaymentsTerminalPaymentsRestController::capture_terminal_payment_under_lock>get_payment_intention' ),
		array( 'terminal capture', 'POST intentions/{id}/capture', 'WooPaymentsTerminalPaymentsRestController::capture_terminal_payment_under_lock>capture_intention' ),
		array( 'checkout', 'GET intentions/{id}', 'WooPaymentsPaymentOperationService::charge_order_total>get_payment_intention' ),
		array( 'checkout', 'GET setup_intents/{id}', 'WooPaymentsPaymentOperationService::set_up_payment_method>get_setup_intention' ),

		// Stated call-site exceptions.
		array( 'checkout', 'POST customers', 'WooPaymentsPaymentOperationService::send_charge_request>replace_missing_customer_for_order', 'WooPaymentsCustomerService::replace_missing_customer_for_order>create_customer' ),
		array( 'checkout', 'POST customers', 'WooPaymentsPaymentOperationService::customer_replaced_refusal_outcome>replace_missing_customer_for_order', 'WooPaymentsCustomerService::replace_missing_customer_for_order>create_customer' ),
		array( 'checkout', 'POST customers', 'WooPaymentsPaymentOperationService::set_up_payment_method>replace_missing_customer_for_order', 'WooPaymentsCustomerService::replace_missing_customer_for_order>create_customer' ),
		array( 'checkout', 'POST customers', 'WooPaymentsPaymentOperationService::charge_order_total>get_customer_id_for_context', 'WooPaymentsCustomerService::get_or_create_customer_id>create_customer' ),
		array( 'checkout', 'POST invoices/{id}/pay', 'StripeBilling/StripeBillingInvoiceService::try_first_invoice_payment>charge_invoice', 'StripeBilling/StripeBillingApi::charge_invoice>send_array' ),
		array( 'checkout', 'POST subscriptions/{id}', 'StripeBilling/StripeBillingSubscriptionService::update_wcpay_subscription_payment_method>update_subscription', 'StripeBilling/StripeBillingApi::update_subscription>send_array' ),
		array( 'payment status update', 'GET intentions/{id}', 'Webhooks/PaymentIntentEventHandler::build_lifecycle_event>get_payment_intention' ),
		array( 'dispute webhook', 'GET disputes/{id}/summary', 'Webhooks/DisputeEventHandler::get_lost_dispute_amount>get_dispute_summary', 'Webhooks/DisputeEventHandler::get_dispute_summary>get_dispute_summary' ),
		array( 'payment status update', 'GET accounts', 'WooPaymentsCompositionDataSettler::run_settled>get_cached_account_data', 'Webhooks/PaymentIntentEventHandler::apply_succeeded_payment_intent>run_settled', 'WooPaymentsAccountService::fetch_account_data>get_account' ),
		array( 'payment status update', 'GET payment_methods', 'Webhooks/PaymentIntentEventHandler::apply_succeeded_payment_intent>repair_recurring_order_token', 'WooPaymentsCustomerService::get_payment_methods_for_customer>get_payment_methods' ),
		array( 'payment status update', 'GET payment_methods/{id}', 'Webhooks/PaymentIntentEventHandler::apply_succeeded_payment_intent>repair_recurring_order_token', 'WooPaymentsPaymentMethodDetailsService::fetch_payment_method_details>get_payment_method' ),
		array( '*', 'GET payment_methods/{id}', 'WooPaymentsOrderCardInfo::handle_order_payment_card_info>fetch_payment_method_details', '@failed-email' ),
		array( 'payment status update', 'GET accounts', 'Webhooks/PaymentIntentEventHandler::process_order_payment_event>run_settled', 'WooPaymentsCompositionDataSettler::run_settled>get_cached_account_data', 'WooPaymentsAccountService::fetch_account_data>get_account' ),
		array( 'capture', 'GET accounts/fraud_services', 'WooPaymentsFraudOrderTrackingService::handle_woocommerce_update_order>is_sift_tracking_enabled', 'WooPaymentsFraudService::get_cached_public_config>fetch_public_fraud_services_config' ),
	);

	/** @var bool Whether a Payments test is running. */
	private static bool $active = false;

	/** @var array<string,bool> Tokens the fixture took on behalf of another request. */
	private static array $rivals = array();

	/** @var string[] Requests to report after cleanup, outside any provider exception handler. */
	private static array $violations = array();

	/**
	 * Start observing requests after WordPress has saved the test's hooks.
	 */
	public static function start(): void {
		self::$active     = true;
		self::$violations = array();
		self::$rivals     = array();
		add_filter( 'pre_http_request', array( self::class, 'observe_http' ), PHP_INT_MIN, 3 );
	}

	/**
	 * Identify just the token taken for the other request in a race fixture.
	 *
	 * @param string|null $token Claim result.
	 * @return string|null The same token for the fixture to release.
	 */
	public static function rival_token( ?string $token ): ?string {
		if ( null !== $token ) {
			self::$rivals[ $token ] = true;
		}
		return $token;
	}

	/**
	 * Observe platform traffic through WordPress, including the public unsigned endpoints.
	 *
	 * @param mixed               $response Preempted response.
	 * @param array<string,mixed> $args     HTTP request arguments.
	 * @param string              $url      Destination.
	 * @return mixed The original response.
	 */
	public static function observe_http( $response, $args, $url ) {
		if ( 'public-api.wordpress.com' === wp_parse_url( $url, PHP_URL_HOST ) && preg_match( '~/(?:wcpay|transact|tumblrpay)/~', (string) wp_parse_url( $url, PHP_URL_PATH ) ) ) {
			self::observe( $args['method'], $url );
		}
		return $response;
	}

	/**
	 * Inspect the request before the transport answers it, including attempts that throw or retry.
	 *
	 * @param string $method HTTP verb.
	 * @param string $path   Signed path or absolute public URL.
	 */
	public static function observe( string $method, string $path ): void {
		global $wp_filter;
		if ( ! self::$active ) {
			return;
		}

		$path  = (string) wp_parse_url( $path, PHP_URL_PATH );
		$route = preg_replace( '~^.*?/(?:wcpay|transact|tumblrpay)/~', '', $path );

		// Every successful claim registers its lock instance for shutdown, including locks constructed outside the container.
		foreach ( $wp_filter['shutdown']->callbacks[ PHP_INT_MAX ] ?? array() as $callback ) {
			$handler = $callback['function'];
			if ( is_array( $handler ) && $handler[0] instanceof OrderPaymentLock && 'release_held_claims' === $handler[1] ) {
				self::inspect_claims( $handler[0], $method . ' ' . $route );
			}
		}
	}

	/**
	 * Check only locally owned claims that still match the live holder after expiry or takeover.
	 *
	 * @param OrderPaymentLock $lock    Registered owner.
	 * @param string           $request Verb and route, without query parameters.
	 */
	private static function inspect_claims( OrderPaymentLock $lock, string $request ): void {
		static $registry;
		if ( null === $registry ) {
			$registry = new ReflectionProperty( OrderPaymentLock::class, 'held_claims' );
			$registry->setAccessible( true );
		}
		foreach ( $registry->getValue( $lock ) as $token => $claim ) {
			$order      = $claim['order'];
			$vocabulary = $claim['vocabulary'];
			if ( isset( self::$rivals[ $token ] ) || ! $lock->is_held( $order, $vocabulary ) ) {
				continue;
			}
			$row    = wc_get_container()->get( TransientRowLock::class )->read_holder( $vocabulary->get_order_lock_key( $order ) . '_holder' );
			$holder = null === $row ? null : maybe_unserialize( $row['record'] );
			if ( ( $holder['token'] ?? null ) === $token ) {
				self::check_request( $holder['operation'], $order->get_id(), $request );
			}
		}
	}

	/**
	 * Check the provider call sites in this dispatch. An outer dispatch cannot authorize a nested send.
	 *
	 * @param string $operation Holder operation.
	 * @param int    $order_id  Held order.
	 * @param string $request   Verb and route.
	 */
	private static function check_request( string $operation, int $order_id, string $request ): void {
		$sites         = array();
		$inside_client = false;
		$trace         = debug_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_debug_backtrace -- Identify the production caller at test dispatch without recording its arguments.
		$provider_path = WC_ABSPATH . 'src/Internal/Payments/Providers/WooPayments/';
		foreach ( $trace as $index => $frame ) {
			$class = $frame['class'] ?? '';
			if ( 'respond_to_request' === $frame['function'] && is_a( $class, FakeHttpClient::class, true ) ) {
				break;
			}
			if ( Client::class === $class && 'request_decoded' === $frame['function'] ) {
				if ( $inside_client ) {
					break;
				}
				$inside_client = true;
			}
			if ( is_a( $class, \WC_Email_Failed_Order::class, true ) || is_a( $class, \WC_Email_Customer_Failed_Order::class, true ) ) {
				$sites[] = '@failed-email';
			}
			$file = $frame['file'] ?? '';
			if ( 0 === strpos( $file, $provider_path ) && isset( $trace[ $index + 1 ] ) ) {
				$caller  = substr( $file, strlen( $provider_path ), -4 );
				$sites[] = $caller . '::' . $trace[ $index + 1 ]['function'] . '>' . $frame['function'];
			}
		}

		foreach ( self::RULES as $rule ) {
			list( $holder, $route ) = $rule;
			$pattern                = str_replace( '\{id\}', '[^/]+', preg_quote( $route, '~' ) );
			if ( ( $operation === $holder || '*' === $holder ) && preg_match( '~^' . $pattern . '$~D', $request ) && ! array_diff( array_slice( $rule, 2 ), $sites ) ) {
				return;
			}
		}
		self::$violations[] = "$operation order $order_id: $request via " . implode( ', ', $sites );
	}

	/**
	 * Report all violations after the parent test has restored hooks and rolled back the database.
	 */
	public static function finish(): void {
		self::$active     = false;
		$violations       = self::$violations;
		self::$violations = array();
		if ( $violations ) {
			Assert::fail( implode( "\n", $violations ) );
		}
	}
}
