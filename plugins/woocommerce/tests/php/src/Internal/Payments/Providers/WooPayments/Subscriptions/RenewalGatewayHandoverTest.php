<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments\Subscriptions;

use ActionScheduler;
use ActionScheduler_Store;
use Automattic\WooCommerce\Internal\Payments\PaymentsBootstrap;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api\Client;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api\TransportLog;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\PaymentMethods\PaymentMethodRegistry;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\StripeBilling\StripeBillingMigrator;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\StripeBilling\StripeBillingModule;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\StripeBilling\StripeBillingSubscriptionService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Subscriptions\FailedRenewalAuthenticationEmail;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Subscriptions\RenewalGatewayHandover;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Subscriptions\SubscriptionMethodPolicy;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Tokens\AmazonPayToken;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Tokens\SepaToken;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsDatabaseCache;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsPersistenceVocabulary;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsProvider;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsStatusChangeRetryListener;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsAccountService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsFeatureController;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsGateway;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsRuntimeArbiter;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsSetupTier;
use Automattic\WooCommerce\Internal\Utilities\RequestTypeUtil;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments\Api\FakeHttpClient;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments\Fixtures\RecordedPublicFraudServices;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments\StripeBilling\Fixtures\WooCommerceSubscriptionsDoubles;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments\Subscriptions\Fixtures\RenewalSubscriptionDouble;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments\Subscriptions\Fixtures\ScheduledRenewalDoubles;
use WC_Order;
use WC_Payment_Token_CC;
use WC_Unit_Test_Case;

/**
 * A renewal of a WooPayments subscription that falls due while the setup tier is wrongly the lowest is handed to the
 * gateway at its due time, on scheduled and command-line requests only, as the extension's always-registered gateway is.
 *
 * WooCommerce Subscriptions' due-renewal and retry actions run through Action Scheduler with the stand-ins of
 * ScheduledRenewalDoubles, and the platform answers through a fake transport. Every row writes the filter's hook name out,
 * so the rows also run where the hand-over does not exist: there the renewals stay manual.
 */
class RenewalGatewayHandoverTest extends WC_Unit_Test_Case {

	private const IS_MANUAL_FILTER = 'woocommerce_subscription_is_manual';

	private const RENEWAL_ACTION = 'woocommerce_scheduled_subscription_payment';

	private const RETRY_ACTION = 'woocommerce_scheduled_subscription_payment_retry';

	private const CARD = 'woocommerce_payments';

	private const AMAZON_PAY = 'woocommerce_payments_amazon_pay';

	private const ROW = 'wcpay_account_data';

	/**
	 * The ten Stripe Billing values a subscription can carry, written out so a missing one in the production list fails.
	 */
	private const STRIPE_BILLING_KEYS = array(
		'_wcpay_subscription_id',
		'_wcpay_subscription_discount_ids',
		'_wcpay_pending_invoice_id',
		'_wcpay_billing_invoice_id',
		'_cancelled_wcpay_subscription_id',
		'_migrated_wcpay_subscription_id',
		'_migrated_wcpay_subscription_discount_ids',
		'_migrated_wcpay_pending_invoice_id',
		'_migrated_wcpay_billing_invoice_id',
		'_wcpay_subscription_migrated_during',
	);

	/**
	 * The platform transport.
	 *
	 * @var FakeHttpClient
	 */
	private FakeHttpClient $transport;

	/**
	 * Answers an intent request: takes the decoded body and returns the transport response.
	 *
	 * @var callable|null
	 */
	private $intent_answer = null;

	/**
	 * Gateway list before the case.
	 *
	 * @var array<int|string,mixed>
	 */
	private array $original_payment_gateways = array();

	/**
	 * The mailer before the case, null when none was built.
	 *
	 * @var \WC_Emails|null
	 */
	private $original_mailer = null;

	/**
	 * That mailer's emails.
	 *
	 * @var array<string,mixed>
	 */
	private array $original_emails = array();

	/**
	 * A store at the lowest tier the built-in owns, with Subscriptions loaded, an empty account row and a connected
	 * platform whose answers the case sets.
	 */
	public function setUp(): void {
		parent::setUp();

		update_option( WooPaymentsFeatureController::FEATURE_ENABLE_OPTION, 'yes' );
		update_option( 'active_plugins', array() );
		delete_site_option( 'active_sitewide_plugins' );
		delete_option( WooPaymentsRuntimeArbiter::EXTENSION_VERSION_OPTION );
		update_option( WooPaymentsSetupTier::OPTION_NAME, WooPaymentsSetupTier::AVAILABLE );
		update_option(
			'woocommerce_woocommerce_payments_settings',
			array(
				'enabled'   => 'yes',
				'test_mode' => 'yes',
			)
		);

		$this->original_payment_gateways = WC()->payment_gateways()->payment_gateways;
		$this->original_mailer           = $this->mailer_instance()->getValue();
		$this->original_emails           = $this->original_mailer instanceof \WC_Emails ? $this->original_mailer->emails : array();

		$this->register_legacy_proxy_function_mocks(
			array(
				'class_exists' => static fn( $class_name, ...$args ) => in_array( $class_name, array( 'WC_Subscriptions', 'WC_Subscriptions_Core_Plugin', 'WC_Subscriptions_Data_Copier' ), true ) || class_exists( $class_name, ...$args ),
			)
		);
		ScheduledRenewalDoubles::load();
		RecordedPublicFraudServices::answer();

		$test            = $this;
		$this->transport = new class( $test ) extends FakeHttpClient {
			/**
			 * The case, which answers intent requests.
			 *
			 * @var RenewalGatewayHandoverTest
			 */
			private $test;

			/**
			 * Initialize the transport.
			 *
			 * @param RenewalGatewayHandoverTest $test The case.
			 */
			public function __construct( $test ) {
				$this->test = $test;
			}

			/**
			 * Record the request and answer an intent request through the case.
			 *
			 * @param string      $method         HTTP method.
			 * @param string      $path           WPCOM path.
			 * @param string[]    $headers        Request headers.
			 * @param string|null $body           Request body.
			 * @param int         $timeout        Request timeout.
			 * @param bool        $use_user_token Whether to sign with the owner's token.
			 * @param bool        $blocking       Whether the request blocks.
			 * @return mixed
			 */
			public function respond_to_request( string $method, string $path, array $headers = array(), ?string $body = null, int $timeout = 70, bool $use_user_token = false, bool $blocking = true ) {
				parent::respond_to_request( $method, $path, $headers, $body, $timeout, $use_user_token, $blocking );

				return $this->test->answer( $method, $path, (array) json_decode( (string) $body, true ) );
			}
		};
		$client          = new class() extends Client {
			/**
			 * Retry at once.
			 *
			 * @param int $backoff_microseconds Backoff.
			 */
			protected function sleep_before_retry( int $backoff_microseconds ): void {
				unset( $backoff_microseconds );
			}
		};
		wc_get_container()->replace( Client::class, $client );
		wc_get_container()->reset_all_resolved();
		$client->init( $this->transport, wc_get_container()->get( WooPaymentsAccountService::class ), wc_get_container()->get( TransportLog::class ) );
		$this->intent_answer = fn( array $body ): array => self::platform_answer( 200, $this->recorded_renewal_intent( $body ) );

		wc_get_container()->get( WooPaymentsAccountService::class )->overwrite_cache_with_no_account();
		$this->next_request();
	}

	/**
	 * Undo what the case's requests leave for the rest of the process.
	 */
	public function tearDown(): void {
		try {
			WC()->payment_gateways()->payment_gateways = $this->original_payment_gateways;
			// A mailer the case built or changed goes; the one before it comes back with its emails.
			$this->mailer_instance()->setValue( null, $this->original_mailer );
			if ( $this->original_mailer instanceof \WC_Emails ) {
				$this->original_mailer->emails = $this->original_emails;
			}
			unset(
				$GLOBALS[ WooCommerceSubscriptionsDoubles::SUBSCRIPTION_IDS ],
				$GLOBALS[ WooCommerceSubscriptionsDoubles::ORDER_SUBSCRIPTIONS ],
				$GLOBALS[ WooCommerceSubscriptionsDoubles::RENEWAL_SUBSCRIPTIONS ],
				$GLOBALS[ WooCommerceSubscriptionsDoubles::DUPLICATE_SITE ]
			);
			$fallback_hooks = new \ReflectionProperty( WooPaymentsGateway::class, 'classic_checkout_fallback_hooks_added' );
			$fallback_hooks->setAccessible( true );
			$fallback_hooks->setValue( null, false );
			as_unschedule_all_actions( '', array(), 'b1039' );
			$this->reset_container_replacements();
		} finally {
			wc_get_container()->reset_all_resolved();
			wc_get_container()->get( WooPaymentsRuntimeArbiter::class )->invalidate();
			wc_get_container()->get( WooPaymentsSetupTier::class )->invalidate();
			parent::tearDown();
		}
	}

	/**
	 * Answer a platform request: an intent request through the case's answer, anything else as an unexpected request.
	 *
	 * @internal
	 *
	 * @param string              $method HTTP method.
	 * @param string              $path   WPCOM path.
	 * @param array<string,mixed> $body   Decoded body.
	 * @return mixed
	 */
	public function answer( string $method, string $path, array $body ) {
		if ( 'POST' === $method && 1 === preg_match( '#/intentions$#', $path ) && null !== $this->intent_answer ) {
			return ( $this->intent_answer )( $body );
		}

		return self::platform_answer( 500, array( 'error' => array( 'code' => 'unexpected_request' ) ) );
	}

	/**
	 * @testdox N2: a due renewal of a $_dataName runs the hand-over filter with no query, option read or class load, and registers nothing of WooPayments.
	 * @testWith ["bacs", false]
	 *           ["bacs", true]
	 *
	 * @param string $payment_method Gateway ID.
	 * @param bool   $manual         Whether the subscription renews manually.
	 */
	public function test_n2_another_gateways_renewal_costs_nothing_more( string $payment_method, bool $manual ): void {
		$subscription = $this->create_subscription( $payment_method );
		$subscription->set_requires_manual_renewal( $manual );
		$subscription->save();
		$this->bootstrap( RequestTypeUtil::CRON );
		$meter = $this->meter();
		add_filter( self::IS_MANUAL_FILTER, array( $meter, 'start_filter' ), -1 );
		add_filter( self::IS_MANUAL_FILTER, array( $meter, 'stop_filter' ), 1 );

		$this->run_action( self::RENEWAL_ACTION, array( 'subscription_id' => $subscription->get_id() ) );

		$this->assertGreaterThan( 0, $meter->starts, 'The renewal asked whether the subscription is manual.' );
		$this->assertSame( 0, $meter->queries, implode( "\n", $meter->sql ) );
		$this->assertSame( 0, $meter->option_reads );
		$this->assertSame( array(), $meter->autoloads );
		$this->assertSame( 0, $this->renewal_handler_count(), 'No renewal handler is registered.' );
	}

	/**
	 * @testdox N2 extended: the Stripe Billing check on a due WooPayments renewal reads the loaded subscription and runs no query.
	 */
	public function test_n2_the_stripe_billing_check_runs_no_query(): void {
		$subscription = $this->create_subscription( self::CARD, array( '_migrated_wcpay_subscription_id' => 'sub_migrated' ) );
		$this->bootstrap( RequestTypeUtil::CRON );
		$meter = $this->meter();
		add_filter( self::IS_MANUAL_FILTER, array( $meter, 'start_filter' ), -1 );
		add_filter( self::IS_MANUAL_FILTER, array( $meter, 'stop_filter' ), 1 );

		$this->run_action( self::RENEWAL_ACTION, array( 'subscription_id' => $subscription->get_id() ) );

		$this->assertTrue( $meter->stopped );
		$this->assertSame( 0, $meter->queries, implode( "\n", $meter->sql ) );
	}

	/**
	 * @testdox N3: at the lowest tier with an empty account row, a WooPayments renewal due by WP-Cron is charged once at its due time: the renewal is paid, the subscription active, the intent sent in the settings' test mode, no account read and no manual note.
	 */
	public function test_n3_a_due_renewal_is_charged(): void {
		$subscription = $this->create_subscription();
		$this->bootstrap( RequestTypeUtil::CRON );

		$this->assertSame( ActionScheduler_Store::STATUS_COMPLETE, $this->run_action( self::RENEWAL_ACTION, array( 'subscription_id' => $subscription->get_id() ) ) );

		$renewal = $this->last_renewal( $subscription );
		$this->assertTrue( $renewal->is_paid(), 'The renewal is paid, not ' . $renewal->get_status() );
		$this->assertSame( 'active', wc_get_order( $subscription->get_id() )->get_status() );
		$this->assertSame( array( $renewal->get_id() ), $this->charged_order_ids() );
		$this->assertTrue( $this->intent_bodies()[0]['test_mode'] );
		$this->assertSame( array(), $this->account_requests() );
		$this->assertFalse( $this->has_note( $renewal, ScheduledRenewalDoubles::MANUAL_NOTE ) );
	}

	/**
	 * @testdox N4: when the platform no longer knows the account, the renewal ends as an ordinary failed renewal: failed with its failure note, the subscription on hold, no manual note.
	 */
	public function test_n4_an_account_gone_at_the_platform_fails_the_renewal(): void {
		$this->intent_answer = static fn(): array => self::platform_answer(
			404,
			array(
				'code'    => 'wcpay_account_not_found',
				'message' => 'No such account.',
			)
		);
		$subscription        = $this->create_subscription();
		$this->bootstrap( RequestTypeUtil::CRON );

		$this->run_action( self::RENEWAL_ACTION, array( 'subscription_id' => $subscription->get_id() ) );

		$renewal = $this->last_renewal( $subscription );
		$this->assertSame( 'failed', $renewal->get_status() );
		$this->assertCount( 1, $this->intent_bodies() );
		$this->assertSame( 'on-hold', wc_get_order( $subscription->get_id() )->get_status() );
		$this->assertFalse( $this->has_note( $renewal, ScheduledRenewalDoubles::MANUAL_NOTE ) );
	}

	/**
	 * @testdox N5: with the WordPress.com connection gone, the renewal sends nothing and ends as an ordinary failed renewal.
	 */
	public function test_n5_a_disconnected_store_fails_the_renewal_without_a_request(): void {
		$this->transport->connected = false;
		$subscription               = $this->create_subscription();
		$this->bootstrap( RequestTypeUtil::CRON );

		$this->run_action( self::RENEWAL_ACTION, array( 'subscription_id' => $subscription->get_id() ) );

		$renewal = $this->last_renewal( $subscription );
		$this->assertSame( 'failed', $renewal->get_status() );
		$this->assertSame( array(), $this->transport->requests );
		$this->assertFalse( $this->has_note( $renewal, ScheduledRenewalDoubles::MANUAL_NOTE ) );
	}

	/**
	 * @return array<string,array{string,bool,string}>
	 */
	public static function provide_stripe_billed_card_cases(): array {
		$rows = array();
		foreach ( self::request_shapes() as $shape => list( $request, $ajax ) ) {
			foreach ( array( 'due renewal', 'gateway hook fired after a hand-over', 'retry' ) as $what_runs ) {
				$rows[ "$shape, $what_runs" ] = array( $request, $ajax, $what_runs );
			}
		}

		return $rows;
	}

	/**
	 * @testdox N6: a card subscription Stripe Billing bills is never charged by us at the lowest tier ($_dataName): its renewal stays manual as today, a gateway hook fired after another subscription's hand-over is refused, a retry is skipped as manual.
	 * @dataProvider provide_stripe_billed_card_cases
	 *
	 * @param string $request Request type.
	 * @param bool   $ajax    Whether the request arrives over admin-ajax.
	 * @param string $what_runs What runs.
	 */
	public function test_n6_a_stripe_billed_card_subscription_is_never_charged( string $request, bool $ajax, string $what_runs ): void {
		update_option( StripeBillingModule::TOGGLE_OPTION, '1' );
		$this->build_mailer_in_this_request();
		$billed = $this->create_subscription( self::CARD, array( '_wcpay_subscription_id' => 'sub_1UM1VrBzWlxcwgpP6A3GwGLe' ) );
		$other  = $this->create_subscription();
		$this->start_request( $request, $ajax );

		$billed_orders = $this->run_stripe_billed_case( $billed, $other, $what_runs );

		$this->assertSame( array(), array_values( array_intersect( $this->charged_order_ids(), $billed_orders ) ), 'No intent for the Stripe-billed subscription.' );
		if ( 'due renewal' === $what_runs ) {
			$this->assertTrue( $this->has_note( $this->last_renewal( $billed ), ScheduledRenewalDoubles::MANUAL_NOTE ), 'A manual renewal, as today.' );
		}
	}

	/**
	 * @testdox N7: a subscription paying with a saved SEPA debit loads its token and is charged.
	 */
	public function test_n7_a_sepa_subscription_is_charged(): void {
		$token = new SepaToken();
		$token->set_gateway_id( self::CARD );
		$token->set_token( 'pm_1UJhOFBzWlxcwgpPvcySvyc5' );
		$token->set_last4( '3000' );
		$subscription = $this->create_subscription( self::CARD, array(), $token );
		$this->bootstrap( RequestTypeUtil::CRON );

		$this->run_action( self::RENEWAL_ACTION, array( 'subscription_id' => $subscription->get_id() ) );

		$renewal = $this->last_renewal( $subscription );
		$this->assertSame( array( $renewal->get_id() ), $this->charged_order_ids() );
		$this->assertSame( 'pm_1UJhOFBzWlxcwgpPvcySvyc5', $this->intent_bodies()[0]['payment_method'] );
		$this->assertTrue( $renewal->is_paid() );
	}

	/**
	 * @testdox N8: a WooPayments subscription that is $_dataName stays manual and nothing is registered.
	 * @testWith ["set to renew manually"]
	 *           ["on a staging copy"]
	 *
	 * @param string $reason Why it renews manually.
	 */
	public function test_n8_a_manual_subscription_stays_manual( string $reason ): void {
		$subscription = $this->create_subscription();
		if ( 'set to renew manually' === $reason ) {
			$subscription->set_requires_manual_renewal( true );
			$subscription->save();
		} else {
			$GLOBALS[ WooCommerceSubscriptionsDoubles::DUPLICATE_SITE ] = true;
		}
		$this->bootstrap( RequestTypeUtil::CRON );

		$this->run_action( self::RENEWAL_ACTION, array( 'subscription_id' => $subscription->get_id() ) );

		$this->assertTrue( $this->has_note( $this->last_renewal( $subscription ), ScheduledRenewalDoubles::MANUAL_NOTE ) );
		$this->assertSame( array(), $this->intent_bodies() );
		$this->assertSame( 0, $this->renewal_handler_count(), 'Nothing is registered.' );
		$this->assertNotContains( self::CARD, $this->listed_gateway_ids() );
	}

	/**
	 * @testdox N9a: an Amazon Pay subscription on a store whose account row holds the empty account is charged with its Amazon Pay token, the Amazon Pay gateway listed in that request.
	 */
	public function test_n9a_an_amazon_pay_subscription_is_charged_with_an_empty_row(): void {
		$this->warm_public_fraud_config();
		$subscription = $this->create_subscription( self::AMAZON_PAY, array(), $this->amazon_pay_token() );
		$this->bootstrap( RequestTypeUtil::CRON );

		$this->run_action( self::RENEWAL_ACTION, array( 'subscription_id' => $subscription->get_id() ) );

		$renewal = $this->last_renewal( $subscription );
		$this->assertContains( self::AMAZON_PAY, $this->listed_gateway_ids() );
		$this->assertSame( array( $renewal->get_id() ), $this->charged_order_ids() );
		$this->assertSame( 'pm_amazon_pay_renewal', $this->intent_bodies()[0]['payment_method'] );
		$this->assertFalse( $this->has_note( $renewal, ScheduledRenewalDoubles::MANUAL_NOTE ) );
	}

	/**
	 * @testdox N9b and N9c: an Amazon Pay subscription stays manual, with the Amazon Pay gateway not listed, when the account row is $_dataName.
	 * @testWith ["absent"]
	 *           ["opted out of confirmation tokens"]
	 *
	 * @param string $row The account row.
	 */
	public function test_n9b_n9c_an_amazon_pay_subscription_stays_manual( string $row ): void {
		if ( 'absent' === $row ) {
			delete_option( self::ROW );
		} else {
			update_option(
				self::ROW,
				array(
					'data'    => array( 'ece_confirmation_tokens_disabled' => true ),
					'fetched' => time(),
					'errored' => false,
				)
			);
		}
		$this->next_request();
		$subscription = $this->create_subscription( self::AMAZON_PAY, array(), $this->amazon_pay_token() );
		$this->bootstrap( RequestTypeUtil::CRON );

		$this->run_action( self::RENEWAL_ACTION, array( 'subscription_id' => $subscription->get_id() ) );

		$this->assertTrue( $this->has_note( $this->last_renewal( $subscription ), ScheduledRenewalDoubles::MANUAL_NOTE ) );
		$this->assertNotContains( self::AMAZON_PAY, $this->listed_gateway_ids() );
		$this->assertSame( array(), $this->intent_bodies() );
	}

	/**
	 * @testdox N10: a retry of a failed WooPayments renewal is charged.
	 */
	public function test_n10_a_retry_is_charged(): void {
		$subscription = $this->create_subscription();
		$renewal      = $this->create_failed_renewal( $subscription );
		$this->bootstrap( RequestTypeUtil::CRON );

		$this->run_action( self::RETRY_ACTION, array( 'order_id' => $renewal->get_id() ) );

		$this->assertSame( array( $renewal->get_id() ), $this->charged_order_ids() );
		$this->assertTrue( wc_get_order( $renewal->get_id() )->is_paid() );
		$this->assertFalse( $this->has_note( $renewal, ScheduledRenewalDoubles::RETRY_SKIPPED_NOTE ) );
	}

	/**
	 * @testdox N11: on a WP-CLI run with the account row expired, the renewal makes no account request.
	 */
	public function test_n11_a_cli_run_makes_no_account_request(): void {
		update_option(
			self::ROW,
			array(
				'data'    => array(),
				'fetched' => time() - WEEK_IN_SECONDS,
				'errored' => false,
			)
		);
		$this->next_request();
		$subscription = $this->create_subscription();
		$this->bootstrap( RequestTypeUtil::CLI );

		$this->run_action( self::RENEWAL_ACTION, array( 'subscription_id' => $subscription->get_id() ) );

		$this->assertSame( array(), $this->account_requests() );
		$this->assertSame( array( $this->last_renewal( $subscription )->get_id() ), $this->charged_order_ids() );
	}

	/**
	 * @testdox N12: when the tier is raised to active inside the request before the renewal runs, the renewal is charged once and the card gateway is listed once.
	 */
	public function test_n12_a_tier_raised_in_the_request_charges_once(): void {
		$subscription = $this->create_subscription();
		$this->bootstrap( RequestTypeUtil::CRON );
		update_option( WooPaymentsSetupTier::OPTION_NAME, WooPaymentsSetupTier::ACTIVE );
		wc_get_container()->get( WooPaymentsSetupTier::class )->invalidate();

		$this->run_action( self::RENEWAL_ACTION, array( 'subscription_id' => $subscription->get_id() ) );

		$this->assertSame( array( $this->last_renewal( $subscription )->get_id() ), $this->charged_order_ids() );
		$this->assertSame( 1, $this->count_listed( self::CARD ) );
	}

	/**
	 * @testdox N13: two WooPayments renewals in one request are charged once each, with one card gateway listed and one renewal handler.
	 */
	public function test_n13_two_renewals_in_one_request(): void {
		$first  = $this->create_subscription();
		$second = $this->create_subscription();
		$this->bootstrap( RequestTypeUtil::CRON );

		$this->run_action( self::RENEWAL_ACTION, array( 'subscription_id' => $first->get_id() ) );
		$this->run_action( self::RENEWAL_ACTION, array( 'subscription_id' => $second->get_id() ) );

		$this->assertSame( array( $this->last_renewal( $first )->get_id(), $this->last_renewal( $second )->get_id() ), $this->charged_order_ids() );
		$this->assertSame( 1, $this->count_listed( self::CARD ) );
		$this->assertSame( 1, $this->renewal_handler_count() );
	}

	/**
	 * @testdox N14: the renewal order gets neither the subscription's intent nor its charge key, and a subscription bought in live mode on a store now in test mode is put on hold with no renewal order and a failed action.
	 */
	public function test_n14_the_renewal_copies_no_payment_meta_and_checks_the_mode(): void {
		$copied = $this->create_subscription(
			self::CARD,
			array(
				'_intent_id'                    => 'pi_parent',
				'_wcpay_charge_idempotency_key' => 'key_parent',
			)
		);
		$parent = wc_create_order();
		$parent->update_meta_data( '_wcpay_mode', 'prod' );
		$parent->save();
		$moded = $this->create_subscription();
		$moded->set_parent_id( $parent->get_id() );
		$moded->save();
		$this->bootstrap( RequestTypeUtil::CRON );

		$this->run_action( self::RENEWAL_ACTION, array( 'subscription_id' => $copied->get_id() ) );
		$moded_status = $this->run_action( self::RENEWAL_ACTION, array( 'subscription_id' => $moded->get_id() ) );

		$renewal = $this->last_renewal( $copied );
		$this->assertNotSame( 'pi_parent', $renewal->get_meta( '_intent_id', true ) );
		$this->assertNotSame( 'key_parent', $renewal->get_meta( '_wcpay_charge_idempotency_key', true ) );
		$this->assertSame( ActionScheduler_Store::STATUS_FAILED, $moded_status );
		$this->assertSame( 'on-hold', wc_get_order( $moded->get_id() )->get_status() );
		$this->assertFalse( wc_get_order( $moded->get_id() )->get_last_order( 'all', 'renewal' ) );
	}

	/**
	 * @testdox N15: with the mailer built before the renewal, a renewal that needs authentication reaches the authentication email.
	 */
	public function test_n15_the_authentication_email_is_reached_when_the_mailer_was_built(): void {
		$this->build_mailer_in_this_request();
		$this->intent_answer = fn(): array => self::platform_answer( 200, $this->recorded_requires_action_intent() );
		$subscription        = $this->create_subscription();
		$this->bootstrap( RequestTypeUtil::CRON );
		$reached = 0;
		add_action(
			'woocommerce_woocommerce_payments_payment_requires_action',
			static function () use ( &$reached ): void {
				++$reached;
			},
			11
		);

		$this->run_action( self::RENEWAL_ACTION, array( 'subscription_id' => $subscription->get_id() ) );

		$this->assertSame( 1, $reached, 'The requires-action hook fired.' );
		$emails = array_filter(
			$this->callbacks( 'woocommerce_woocommerce_payments_payment_requires_action' ),
			static fn( $callback ): bool => is_array( $callback ) && $callback[0] instanceof FailedRenewalAuthenticationEmail
		);
		$this->assertCount( 1, $emails, 'The authentication email listens.' );
	}

	/**
	 * @testdox N16: the Fee details job the charge schedules runs in the same request with a handler.
	 */
	public function test_n16_the_fee_details_job_runs_in_the_same_request(): void {
		$subscription = $this->create_subscription();
		$this->bootstrap( RequestTypeUtil::CRON );
		$this->run_action( self::RENEWAL_ACTION, array( 'subscription_id' => $subscription->get_id() ) );
		$jobs = $this->pending( 'wcpay_add_fee_breakdown_to_order_notes' );
		$this->assertCount( 1, $jobs, 'The charge scheduled the Fee details job.' );

		ActionScheduler::runner()->process_action( $jobs[0], 'WP Cron' );

		$this->assertSame( ActionScheduler_Store::STATUS_COMPLETE, ActionScheduler::store()->get_status( $jobs[0] ) );
		$this->assertNotSame( array(), array_filter( $this->transport->requests, static fn( array $request ): bool => false !== strpos( $request['path'], '/timeline/' ) ), 'The handler read the timeline.' );
	}

	/**
	 * @testdox N17: a renewal paid with another gateway before our charge claims it is not charged.
	 */
	public function test_n17_a_renewal_paid_first_by_another_gateway_is_not_charged(): void {
		$subscription = $this->create_subscription();
		$this->bootstrap( RequestTypeUtil::CRON );
		add_action(
			'woocommerce_scheduled_subscription_payment_' . self::CARD,
			static function ( $amount, $renewal ): void {
				unset( $amount );
				$paid = wc_get_order( $renewal->get_id() );
				$paid->set_payment_method( 'bacs' );
				$paid->set_status( 'processing' );
				$paid->save();
			},
			5,
			2
		);

		$this->run_action( self::RENEWAL_ACTION, array( 'subscription_id' => $subscription->get_id() ) );

		$this->assertSame( array(), $this->intent_bodies() );
		$renewal = $this->last_renewal( $subscription );
		$this->assertSame( array( 'processing', 'bacs' ), array( $renewal->get_status(), $renewal->get_payment_method() ) );
	}

	/**
	 * @testdox N18: when the extension takes payments over after the request started, the renewal stays manual and nothing of ours is registered or listed.
	 */
	public function test_n18_a_takeover_in_the_request_keeps_the_renewal_manual(): void {
		$subscription = $this->create_subscription();
		$this->bootstrap( RequestTypeUtil::CRON );
		update_option( 'active_plugins', array( WooPaymentsRuntimeArbiter::PLUGIN_FILE ) );
		wc_get_container()->get( WooPaymentsRuntimeArbiter::class )->invalidate();

		$this->run_action( self::RENEWAL_ACTION, array( 'subscription_id' => $subscription->get_id() ) );

		$this->assertTrue( $this->has_note( $this->last_renewal( $subscription ), ScheduledRenewalDoubles::MANUAL_NOTE ) );
		$this->assertSame( 0, $this->renewal_handler_count() );
		$this->assertSame( array(), $this->callbacks( 'woocommerce_subscription_payment_gateway_supports' ), 'No Stripe Billing hook.' );
		$this->assertSame( array(), $this->callbacks( 'woocommerce_payment_token_class' ), 'No token class map.' );
		$this->assertNotContains( self::CARD, $this->listed_gateway_ids() );
	}

	/**
	 * @testdox N19: the filter's gateway IDs are the reusable WooPayments gateways, and its Stripe Billing keys are every value Stripe Billing records on a subscription.
	 */
	public function test_n19_the_lists_match_their_sources(): void {
		$this->assertSame( SubscriptionMethodPolicy::get_reusable_gateway_ids(), WooPaymentsStatusChangeRetryListener::RENEWAL_GATEWAY_IDS );
		$this->assertSame( WooPaymentsPersistenceVocabulary::GATEWAY_ID, WooPaymentsStatusChangeRetryListener::RENEWAL_GATEWAY_IDS[0] );

		WooCommerceSubscriptionsDoubles::load_background_repairer();
		$migrated = new \ReflectionClassConstant( StripeBillingMigrator::class, 'META_KEYS_TO_MIGRATE' );
		$keys     = array_merge(
			array(
				StripeBillingSubscriptionService::SUBSCRIPTION_ID_META_KEY,
				StripeBillingSubscriptionService::MIGRATED_SUBSCRIPTION_ID_META_KEY,
				StripeBillingSubscriptionService::SUBSCRIPTION_DISCOUNT_IDS_META_KEY,
				'_cancelled' . StripeBillingSubscriptionService::SUBSCRIPTION_ID_META_KEY,
				'_wcpay_subscription_migrated_during',
			),
			$migrated->getValue(),
			array_map( static fn( string $key ): string => '_migrated' . $key, $migrated->getValue() )
		);
		$expected = array_values( array_unique( $keys ) );
		sort( $expected );
		$actual = WooPaymentsStatusChangeRetryListener::STRIPE_BILLING_META_KEYS;
		sort( $actual );
		$this->assertSame( $expected, $actual );
		$written = self::STRIPE_BILLING_KEYS;
		sort( $written );
		$this->assertSame( $written, $actual );
	}

	/**
	 * @testdox N20: outside the renewal and retry actions, a WooPayments subscription stays manual and nothing is registered.
	 */
	public function test_n20_outside_the_actions_nothing_changes(): void {
		$subscription = $this->create_subscription();
		$this->bootstrap( RequestTypeUtil::CRON );

		$is_manual = null;
		add_action(
			'woocommerce_scheduled_subscription_customer_notification_renewal',
			static function ( $subscription_id ) use ( &$is_manual ): void {
				$is_manual = wcs_get_subscription( $subscription_id )->is_manual();
			}
		);
		$this->run_action( 'woocommerce_scheduled_subscription_customer_notification_renewal', array( 'subscription_id' => $subscription->get_id() ) );

		$this->assertTrue( $is_manual );
		$this->assertSame( 0, $this->renewal_handler_count() );
	}

	/**
	 * @testdox N21: Amazon Pay follows the stored row only in the hand-over's gateway list: with an empty row, the express and settings readers stay off on a connected store, on the lowest tier without a hand-over and in a hand-over request, and a connected store's gateways list no Amazon Pay.
	 */
	public function test_n21_the_stored_row_rule_stays_in_the_hand_over(): void {
		$account_service = static fn(): WooPaymentsAccountService => wc_get_container()->get( WooPaymentsAccountService::class );
		$readers         = static fn(): array => array(
			wc_get_container()->get( PaymentMethodRegistry::class )->is_amazon_pay_enabled( $account_service() ),
			$account_service()->is_ece_confirmation_tokens_enabled(),
		);

		update_option( WooPaymentsSetupTier::OPTION_NAME, WooPaymentsSetupTier::CONNECTED );
		$this->next_request();
		$this->bootstrap( RequestTypeUtil::CRON );
		$this->assertSame( array( false, false ), $readers(), 'Connected store.' );
		$connected_ids = array_map( static fn( $gateway ): string => $gateway->id, wc_get_container()->get( WooPaymentsProvider::class )->get_payment_gateways() );
		$this->assertContains( self::CARD, $connected_ids );
		$this->assertNotContains( self::AMAZON_PAY, $connected_ids );

		update_option( WooPaymentsSetupTier::OPTION_NAME, WooPaymentsSetupTier::AVAILABLE );
		$this->next_request();
		$this->bootstrap( RequestTypeUtil::CRON );
		$this->assertSame( array( false, false ), $readers(), 'Lowest tier, no hand-over.' );

		$this->warm_public_fraud_config();
		$subscription = $this->create_subscription( self::AMAZON_PAY, array(), $this->amazon_pay_token() );
		$this->run_action( self::RENEWAL_ACTION, array( 'subscription_id' => $subscription->get_id() ) );
		$this->assertContains( self::AMAZON_PAY, $this->listed_gateway_ids(), 'The hand-over lists Amazon Pay.' );
		$this->assertSame( array( false, false ), $readers(), 'Hand-over request.' );
	}

	/**
	 * @testdox N22: a Fee details job left to a later request at the lowest tier fails once with no handler, and the renewal order is unchanged.
	 */
	public function test_n22_a_follow_up_job_in_a_later_request_fails_once(): void {
		$subscription = $this->create_subscription();
		$this->bootstrap( RequestTypeUtil::CRON );
		$this->run_action( self::RENEWAL_ACTION, array( 'subscription_id' => $subscription->get_id() ) );
		$renewal = $this->last_renewal( $subscription );
		$before  = array( $renewal->get_status(), $renewal->get_meta( '_intent_id', true ), count( wc_get_order_notes( array( 'order_id' => $renewal->get_id() ) ) ) );
		$jobs    = $this->pending( 'wcpay_add_fee_breakdown_to_order_notes' );
		$this->assertCount( 1, $jobs );

		// A later request at the lowest tier registers no handler for the job.
		remove_all_actions( 'wcpay_add_fee_breakdown_to_order_notes' );
		ActionScheduler::runner()->process_action( $jobs[0], 'WP Cron' );

		$this->assertSame( ActionScheduler_Store::STATUS_FAILED, ActionScheduler::store()->get_status( $jobs[0] ) );
		$renewal = wc_get_order( $renewal->get_id() );
		$this->assertSame( $before, array( $renewal->get_status(), $renewal->get_meta( '_intent_id', true ), count( wc_get_order_notes( array( 'order_id' => $renewal->get_id() ) ) ) ) );
	}

	/**
	 * @testdox N23: after a hand-over, a later job in the same request reads another WooPayments subscription as automatic, and nothing more is registered.
	 */
	public function test_n23_the_gateway_stays_listed_for_the_rest_of_the_request(): void {
		$first  = $this->create_subscription();
		$second = $this->create_subscription();
		$this->bootstrap( RequestTypeUtil::CRON );
		$this->run_action( self::RENEWAL_ACTION, array( 'subscription_id' => $first->get_id() ) );

		$is_manual = null;
		add_action(
			'woocommerce_scheduled_subscription_customer_notification_renewal',
			static function ( $subscription_id ) use ( &$is_manual ): void {
				$is_manual = wcs_get_subscription( $subscription_id )->is_manual();
			}
		);
		$this->run_action( 'woocommerce_scheduled_subscription_customer_notification_renewal', array( 'subscription_id' => $second->get_id() ) );

		$this->assertFalse( $is_manual );
		$this->assertSame( 1, $this->renewal_handler_count() );
	}

	/**
	 * @testdox N24: when the platform answers 5xx, the renewal keeps its charge key and the ambiguity record, and is not made a manual renewal.
	 */
	public function test_n24_an_ambiguous_answer_keeps_the_charge_key(): void {
		$this->intent_answer = static fn(): array => self::platform_answer( 503, array( 'error' => array( 'code' => 'service_unavailable' ) ) );
		$subscription        = $this->create_subscription();
		$this->bootstrap( RequestTypeUtil::CRON );

		$this->run_action( self::RENEWAL_ACTION, array( 'subscription_id' => $subscription->get_id() ) );

		$renewal = $this->last_renewal( $subscription );
		$this->assertNotSame( '', (string) $renewal->get_meta( '_wcpay_charge_idempotency_key', true ) );
		$this->assertNotEmpty( $renewal->get_meta( '_wcpay_charge_ambiguity', true ) );
		$this->assertNotSame( array(), $this->intent_bodies() );
		$this->assertFalse( $this->has_note( $renewal, ScheduledRenewalDoubles::MANUAL_NOTE ) );
	}

	/**
	 * @return array<string,array{string,array<string,string>,string,bool,string}>
	 */
	public static function provide_stripe_billing_carriers(): array {
		$carriers = array(
			'N25 Amazon Pay with the live Stripe ID'    => array( self::AMAZON_PAY, array( '_wcpay_subscription_id' => 'sub_live' ) ),
			'N27 Amazon Pay with only the cancelled ID' => array( self::AMAZON_PAY, array( '_cancelled_wcpay_subscription_id' => 'sub_cancelled' ) ),
			'N27 card with only the cancelled ID'       => array( self::CARD, array( '_cancelled_wcpay_subscription_id' => 'sub_cancelled' ) ),
		);
		$rows     = array();
		foreach ( $carriers as $carrier => list( $payment_method, $meta ) ) {
			foreach ( self::request_shapes() as $shape => list( $request, $ajax ) ) {
				foreach ( array( 'first renewal', 'after a hand-over', 'retry' ) as $what_runs ) {
					$rows[ "$carrier, $shape, $what_runs" ] = array( $payment_method, $meta, $request, $ajax, $what_runs );
				}
			}
		}

		return $rows;
	}

	/**
	 * @testdox N25 and N27: a subscription carrying a Stripe Billing value is never handed over ($_dataName): no intent for it, a manual renewal, a retry skipped as manual.
	 * @dataProvider provide_stripe_billing_carriers
	 *
	 * @param string               $payment_method Gateway ID.
	 * @param array<string,string> $meta           Stripe Billing meta.
	 * @param string               $request        Request type.
	 * @param bool                 $ajax           Whether the request arrives over admin-ajax.
	 * @param string               $what_runs      What runs.
	 */
	public function test_n25_n27_a_stripe_billing_carrier_is_never_handed_over( string $payment_method, array $meta, string $request, bool $ajax, string $what_runs ): void {
		$carrier = $this->create_subscription( $payment_method, $meta, self::AMAZON_PAY === $payment_method ? $this->amazon_pay_token() : null );
		$other   = $this->create_subscription();
		$handled = $this->spy_on_handover();
		$this->start_request( $request, $ajax );

		$carrier_orders = $this->run_carrier_case( $carrier, $other, $what_runs );

		$this->assertSame( array(), array_values( array_intersect( $this->charged_order_ids(), $carrier_orders ) ), 'No intent for the carrier.' );
		$this->assertNotContains( $carrier->get_id(), $handled(), 'The carrier never reaches the hand-over.' );
		if ( 'retry' === $what_runs ) {
			$this->assertTrue( $this->has_note( wc_get_order( $carrier_orders[0] ), ScheduledRenewalDoubles::RETRY_SKIPPED_NOTE ), 'The retry is skipped as manual.' );
		} else {
			$this->assertTrue( $this->has_note( $this->last_renewal( $carrier ), ScheduledRenewalDoubles::MANUAL_NOTE ), 'A manual renewal.' );
		}
	}

	/**
	 * @return array<string,array{string}>
	 */
	public static function provide_stripe_billing_keys(): array {
		$rows = array();
		foreach ( self::STRIPE_BILLING_KEYS as $key ) {
			$rows[ $key ] = array( $key );
		}

		return $rows;
	}

	/**
	 * @testdox N28: an Amazon Pay subscription carrying only $key stays manual, with no intent.
	 * @dataProvider provide_stripe_billing_keys
	 *
	 * @param string $key Stripe Billing meta key.
	 */
	public function test_n28_each_stripe_billing_key_keeps_the_renewal_manual( string $key ): void {
		$subscription = $this->create_subscription( self::AMAZON_PAY, array( $key => 'value_' . $key ), $this->amazon_pay_token() );
		$this->bootstrap( RequestTypeUtil::CRON );

		$this->run_action( self::RENEWAL_ACTION, array( 'subscription_id' => $subscription->get_id() ) );

		$this->assertTrue( $this->has_note( $this->last_renewal( $subscription ), ScheduledRenewalDoubles::MANUAL_NOTE ) );
		$this->assertSame( array(), $this->intent_bodies() );
	}

	/**
	 * @testdox N29: subscriptions Stripe no longer bills but that carry a Stripe Billing value ($_dataName) stay manual at the lowest tier, with no platform request: the recorded over-exclusion.
	 * @testWith ["Amazon Pay, Stripe subscription cancelled at Stripe", "woocommerce_payments_amazon_pay", "_wcpay_subscription_id"]
	 *           ["card, migrated off Stripe Billing", "woocommerce_payments", "_migrated_wcpay_subscription_id"]
	 *
	 * @param string $label          Case label.
	 * @param string $payment_method Gateway ID.
	 * @param string $key            Stripe Billing meta key.
	 */
	public function test_n29_the_over_exclusion_is_recorded( string $label, string $payment_method, string $key ): void {
		unset( $label );
		$subscription = $this->create_subscription( $payment_method, array( $key => 'sub_ended' ), self::AMAZON_PAY === $payment_method ? $this->amazon_pay_token() : null );
		$this->bootstrap( RequestTypeUtil::CRON );

		$this->run_action( self::RENEWAL_ACTION, array( 'subscription_id' => $subscription->get_id() ) );

		$this->assertTrue( $this->has_note( $this->last_renewal( $subscription ), ScheduledRenewalDoubles::MANUAL_NOTE ) );
		$this->assertSame( array(), $this->transport->requests );
	}

	/**
	 * @testdox A hand-over leaves no WooPayments callback on Action Scheduler's recurring-actions hook, so it schedules no recurring work.
	 */
	public function test_the_hand_over_schedules_no_recurring_work(): void {
		$subscription = $this->create_subscription();
		$this->bootstrap( RequestTypeUtil::CRON );

		$this->run_action( self::RENEWAL_ACTION, array( 'subscription_id' => $subscription->get_id() ) );

		$this->assertSame( array( $this->last_renewal( $subscription )->get_id() ), $this->charged_order_ids(), 'The hand-over ran.' );
		$ours = array_filter(
			$this->callbacks( 'action_scheduler_ensure_recurring_actions' ),
			static fn( $callback ): bool => is_array( $callback ) && is_object( $callback[0] ) && false !== strpos( get_class( $callback[0] ), 'Providers\\WooPayments' )
		);
		$this->assertSame( array(), $ours );
	}

	// ---------------------------------------------------------------------------------------------------------------
	// Helpers.
	// ---------------------------------------------------------------------------------------------------------------

	/**
	 * Build the mailer anew, as a request whose earlier action sent an email does: WooCommerce's email action fires in it.
	 * The case's mailer goes back when the case ends.
	 */
	private function build_mailer_in_this_request(): void {
		$this->mailer_instance()->setValue( null, null );
		WC()->mailer();
		$this->assertGreaterThan( 0, did_action( 'woocommerce_email' ) );
	}

	/**
	 * Get WooCommerce's mailer singleton property.
	 *
	 * @return \ReflectionProperty
	 */
	private function mailer_instance(): \ReflectionProperty {
		$instance = new \ReflectionProperty( \WC_Emails::class, 'instance' );
		$instance->setAccessible( true );

		return $instance;
	}

	/**
	 * Store the public fraud-services config as read earlier the same day.
	 *
	 * With a cold cache, the Sift check of an Amazon Pay renewal's first save under the order lock fetches it, a request the
	 * claim guard refuses. These rows test the hand-over, so they start from a warm cache.
	 */
	private function warm_public_fraud_config(): void {
		set_transient( 'woocommerce_woopayments_public_fraud_services', array( 'stripe' => array() ), DAY_IN_SECONDS );
	}

	/**
	 * The request shapes that can run a renewal at the lowest tier: the bootstrap's request type and whether it arrives over
	 * admin-ajax. Front and admin requests register the cron classes when Action Scheduler begins the action.
	 *
	 * @return array<string,array{string,bool}>
	 */
	private static function request_shapes(): array {
		return array(
			'WP-Cron'                 => array( RequestTypeUtil::CRON, false ),
			'async runner'            => array( RequestTypeUtil::CRON, true ),
			'WP-CLI'                  => array( RequestTypeUtil::CLI, false ),
			'ALTERNATE_WP_CRON front' => array( RequestTypeUtil::FRONT, false ),
			'Tools Run link'          => array( RequestTypeUtil::ADMIN, false ),
		);
	}

	/**
	 * Start a request of a shape.
	 *
	 * @param string $request Request type.
	 * @param bool   $ajax    Whether it arrives over admin-ajax.
	 */
	private function start_request( string $request, bool $ajax ): void {
		if ( $ajax ) {
			add_filter( 'wp_doing_ajax', '__return_true' );
		}
		$this->bootstrap( $request );
	}

	/**
	 * Run one N6 case for a Stripe-billed card subscription and get the IDs of its orders that must not be charged.
	 *
	 * @param WC_Order $billed Stripe-billed subscription.
	 * @param WC_Order $other  Another WooPayments subscription, handed over first in two cases.
	 * @param string   $what_runs What runs.
	 * @return array<int,int>
	 */
	private function run_stripe_billed_case( WC_Order $billed, WC_Order $other, string $what_runs ): array {
		if ( 'due renewal' === $what_runs ) {
			$this->run_action( self::RENEWAL_ACTION, array( 'subscription_id' => $billed->get_id() ) );

			return array( $this->last_renewal( $billed )->get_id() );
		}

		$this->run_action( self::RENEWAL_ACTION, array( 'subscription_id' => $other->get_id() ) );
		if ( 'retry' === $what_runs ) {
			$renewal = $this->create_failed_renewal( $billed );
			$this->run_action( self::RETRY_ACTION, array( 'order_id' => $renewal->get_id() ) );

			return array( $renewal->get_id() );
		}

		$renewal = ScheduledRenewalDoubles::create_renewal_order( wc_get_order( $billed->get_id() ) );
		$this->assertInstanceOf( WC_Order::class, $renewal );
		do_action( 'woocommerce_scheduled_subscription_payment_' . self::CARD, $renewal->get_total(), $renewal );

		return array( $renewal->get_id() );
	}

	/**
	 * Run one N25 or N27 case for a subscription carrying a Stripe Billing value and get the IDs of its orders.
	 *
	 * @param WC_Order $carrier The subscription carrying the value.
	 * @param WC_Order $other   Another WooPayments subscription, handed over first in two cases.
	 * @param string   $what_runs What runs.
	 * @return array<int,int>
	 */
	private function run_carrier_case( WC_Order $carrier, WC_Order $other, string $what_runs ): array {
		if ( 'first renewal' !== $what_runs ) {
			$this->run_action( self::RENEWAL_ACTION, array( 'subscription_id' => $other->get_id() ) );
		}

		if ( 'retry' === $what_runs ) {
			$renewal = $this->create_failed_renewal( $carrier );
			$this->run_action( self::RETRY_ACTION, array( 'order_id' => $renewal->get_id() ) );

			return array( $renewal->get_id() );
		}

		$this->run_action( self::RENEWAL_ACTION, array( 'subscription_id' => $carrier->get_id() ) );

		return array( $this->last_renewal( $carrier )->get_id() );
	}

	/**
	 * Record which subscriptions reach the hand-over, where it exists.
	 *
	 * @return callable(): array<int,int> Gives the subscription IDs.
	 */
	private function spy_on_handover(): callable {
		$handled = new \ArrayObject();
		if ( class_exists( RenewalGatewayHandover::class ) ) {
			$spy = new class( $handled ) extends RenewalGatewayHandover {
				/**
				 * Subscription IDs handled.
				 *
				 * @var \ArrayObject
				 */
				private \ArrayObject $handled;

				/**
				 * Initialize the spy.
				 *
				 * @param \ArrayObject $handled Subscription IDs handled.
				 */
				public function __construct( \ArrayObject $handled ) {
					$this->handled = $handled;
				}

				/**
				 * Record the subscription, then hand over.
				 *
				 * @param WC_Order $subscription Subscription.
				 * @return bool
				 */
				public function handle( WC_Order $subscription ): bool {
					$this->handled->append( $subscription->get_id() );

					return parent::handle( $subscription );
				}
			};
			$spy->init( wc_get_container()->get( WooPaymentsRuntimeArbiter::class ), wc_get_container()->get( WooPaymentsDatabaseCache::class ) );
			wc_get_container()->replace( RenewalGatewayHandover::class, $spy );
		}

		return static fn(): array => $handled->getArrayCopy();
	}

	/**
	 * Create a failed renewal of a subscription, with the subscription on hold, as a failed renewal leaves them.
	 *
	 * @param WC_Order $subscription Subscription.
	 * @return WC_Order
	 */
	private function create_failed_renewal( WC_Order $subscription ): WC_Order {
		$renewal = ScheduledRenewalDoubles::create_renewal_order( wc_get_order( $subscription->get_id() ) );
		$this->assertInstanceOf( WC_Order::class, $renewal );
		$renewal->set_status( 'failed' );
		$renewal->save();
		$subscription = wc_get_order( $subscription->get_id() );
		if ( ! $subscription->has_status( 'on-hold' ) ) {
			$subscription->update_status( 'on-hold' );
		}

		return wc_get_order( $renewal->get_id() );
	}

	/**
	 * A saved Amazon Pay token.
	 *
	 * @return AmazonPayToken
	 */
	private function amazon_pay_token(): AmazonPayToken {
		$token = new AmazonPayToken();
		$token->set_gateway_id( self::AMAZON_PAY );
		$token->set_token( 'pm_amazon_pay_renewal' );
		$token->set_email( 'subscriber@example.com' );

		return $token;
	}

	/**
	 * Get the IDs of the gateways WooCommerce lists.
	 *
	 * @return array<int,string>
	 */
	private function listed_gateway_ids(): array {
		return array_keys( WC()->payment_gateways()->payment_gateways() );
	}

	/**
	 * Count the listed gateways with an ID.
	 *
	 * @param string $gateway_id Gateway ID.
	 * @return int
	 */
	private function count_listed( string $gateway_id ): int {
		return count( array_filter( WC()->payment_gateways()->payment_gateways, static fn( $gateway ): bool => $gateway instanceof \WC_Payment_Gateway && $gateway_id === $gateway->id ) );
	}

	/**
	 * Get a hook's callbacks.
	 *
	 * @param string $hook Hook name.
	 * @return array<int,mixed>
	 */
	private function callbacks( string $hook ): array {
		global $wp_filter;

		$callbacks = array();
		foreach ( isset( $wp_filter[ $hook ] ) ? $wp_filter[ $hook ]->callbacks : array() as $priority_callbacks ) {
			foreach ( $priority_callbacks as $callback ) {
				$callbacks[] = $callback['function'];
			}
		}

		return $callbacks;
	}

	/**
	 * Count the callbacks on the card gateway's renewal hook.
	 *
	 * @return int
	 */
	private function renewal_handler_count(): int {
		return count( $this->callbacks( 'woocommerce_scheduled_subscription_payment_' . self::CARD ) );
	}

	/**
	 * Get the IDs of a hook's pending actions.
	 *
	 * @param string $hook Hook.
	 * @return array<int,int>
	 */
	private function pending( string $hook ): array {
		return array_map(
			'intval',
			as_get_scheduled_actions(
				array(
					'hook'     => $hook,
					'status'   => ActionScheduler_Store::STATUS_PENDING,
					'per_page' => -1,
				),
				'ids'
			)
		);
	}

	/**
	 * Build a meter of queries, option reads and class autoloads.
	 *
	 * @return object
	 */
	private function meter(): object {
		return new class() {
			/** @var int */
			public int $queries = 0;

			/** @var int */
			public int $option_reads = 0;

			/** @var array<int,string> */
			public array $autoloads = array();

			/** @var array<int,string> */
			public array $sql = array();

			/** @var bool */
			public bool $stopped = false;

			/** @var int */
			public int $starts = 0;

			/** @var array<string,callable> */
			private array $callbacks = array();

			/** Start counting. */
			public function start(): void {
				++$this->starts;
				$this->callbacks = array(
					'query'    => function ( $query ) {
						++$this->queries;
						$this->sql[] = $query;
						return $query;
					},
					'all'      => function ( $hook_name ) {
						if ( is_string( $hook_name ) && 0 === strpos( $hook_name, 'pre_option_' ) ) {
							++$this->option_reads;
						}
					},
					'autoload' => function ( $class_name ) {
						$this->autoloads[] = $class_name;
					},
				);
				add_filter( 'query', $this->callbacks['query'] );
				add_action( 'all', $this->callbacks['all'] );
				spl_autoload_register( $this->callbacks['autoload'], true, true );
			}

			/** Stop counting. */
			public function stop(): void {
				if ( array() === $this->callbacks ) {
					return;
				}
				remove_filter( 'query', $this->callbacks['query'] );
				remove_action( 'all', $this->callbacks['all'] );
				spl_autoload_unregister( $this->callbacks['autoload'] );
				$this->callbacks = array();
				$this->stopped   = true;
			}

			/**
			 * Start counting from a filter.
			 *
			 * @param mixed $value Filtered value.
			 * @return mixed
			 */
			public function start_filter( $value ) {
				$this->start();
				return $value;
			}

			/**
			 * Stop counting from a filter.
			 *
			 * @param mixed $value Filtered value.
			 * @return mixed
			 */
			public function stop_filter( $value ) {
				$this->stop();
				return $value;
			}
		};
	}

	/**
	 * The platform's recorded answer to a saved-card intent that needs the customer to authenticate.
	 *
	 * @return array<string,mixed>
	 */
	private function recorded_requires_action_intent(): array {
		$recording = json_decode( (string) file_get_contents( dirname( __DIR__ ) . '/Fixtures/platform-intention-3ds-requires-action.json' ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local fixture file.
		foreach ( $recording['entries'] as $entry ) {
			if ( 'saved_payment_method_requires_action' === $entry['pair'] ) {
				return $entry['response']['body'];
			}
		}

		return array();
	}

	/**
	 * Start a fresh request: drop the request memos of the tier and the owner, and the services the last one resolved.
	 */
	private function next_request(): void {
		wc_get_container()->reset_all_resolved();
		wc_get_container()->get( WooPaymentsRuntimeArbiter::class )->invalidate();
		wc_get_container()->get( WooPaymentsSetupTier::class )->invalidate();
	}

	/**
	 * Run the payments bootstrap for a request type, as WooCommerce does when it loads.
	 *
	 * @param string $request_type Request type.
	 */
	private function bootstrap( string $request_type ): void {
		( new PaymentsBootstrap(
			static fn( $container, string $type ): array => $container->get( WooPaymentsSetupTier::class )->get_classes_for_request( $type ),
			static fn( $container ): bool => $container->get( WooPaymentsRuntimeArbiter::class )->is_builtin_owner()
		) )->register( wc_get_container(), $request_type );
	}

	/**
	 * Run an action through Action Scheduler, as its queue runner does, and get its status after.
	 *
	 * @param string              $hook Hook.
	 * @param array<string,mixed> $args Arguments.
	 * @return string
	 */
	private function run_action( string $hook, array $args ): string {
		$id = as_schedule_single_action( time() - 1, $hook, $args, 'b1039' );
		ActionScheduler::runner()->process_action( $id, 'WP Cron' );

		return ActionScheduler::store()->get_status( $id );
	}

	/**
	 * Create an active subscription paying 10.99 USD with a saved card, as its parent checkout leaves it.
	 *
	 * @param string                 $payment_method Gateway ID.
	 * @param array<string,string>   $meta           Extra subscription meta.
	 * @param \WC_Payment_Token|null $token        Saved token, a card when null.
	 * @return RenewalSubscriptionDouble
	 */
	private function create_subscription( string $payment_method = self::CARD, array $meta = array(), ?\WC_Payment_Token $token = null ): RenewalSubscriptionDouble {
		$user_id = self::factory()->user->create();
		if ( null === $token ) {
			$token = new WC_Payment_Token_CC();
			$token->set_token( 'pm_1UJhOFBzWlxcwgpPvcySvyc5' );
			$token->set_gateway_id( self::CARD );
			$token->set_card_type( 'visa' );
			$token->set_last4( '4242' );
			$token->set_expiry_month( '12' );
			$token->set_expiry_year( (string) ( (int) gmdate( 'Y' ) + 2 ) );
		}
		$token->set_user_id( $user_id );
		$token->save();

		$subscription = new WC_Order();
		$subscription->save();
		$GLOBALS[ WooCommerceSubscriptionsDoubles::SUBSCRIPTION_IDS ][] = $subscription->get_id();

		$subscription = wc_get_order( $subscription->get_id() );
		$this->assertInstanceOf( RenewalSubscriptionDouble::class, $subscription );
		$subscription->set_customer_id( $user_id );
		$subscription->set_currency( 'USD' );
		$subscription->set_billing_email( 'subscriber@example.com' );
		$subscription->set_payment_method( $payment_method );
		$subscription->set_requires_manual_renewal( false );
		$subscription->set_status( 'active' );
		$subscription->set_total( '10.99' );
		$subscription->add_payment_token( $token );
		$subscription->update_meta_data( '_stripe_customer_id', 'cus_UsIeTbmGHPc9jY' );
		foreach ( $meta as $key => $value ) {
			$subscription->update_meta_data( $key, $value );
		}
		$subscription->save();

		return wc_get_order( $subscription->get_id() );
	}

	/**
	 * Get the subscription's last renewal order.
	 *
	 * @param WC_Order $subscription Subscription.
	 * @return WC_Order
	 */
	private function last_renewal( WC_Order $subscription ): WC_Order {
		$renewal = wc_get_order( $subscription->get_id() )->get_last_order( 'all', 'renewal' );
		$this->assertInstanceOf( WC_Order::class, $renewal, 'A renewal order exists.' );

		return wc_get_order( $renewal->get_id() );
	}

	/**
	 * Get the decoded bodies of the intent requests sent.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function intent_bodies(): array {
		$bodies = array();
		foreach ( $this->transport->requests as $request ) {
			if ( 'POST' === $request['method'] && 1 === preg_match( '#/intentions$#', $request['path'] ) ) {
				$bodies[] = (array) json_decode( (string) $request['body'], true );
			}
		}

		return $bodies;
	}

	/**
	 * Get the IDs of the orders an intent request was sent for, in order.
	 *
	 * @return array<int,int>
	 */
	private function charged_order_ids(): array {
		return array_map( static fn( array $body ): int => (int) ( $body['metadata']['order_id'] ?? 0 ), $this->intent_bodies() );
	}

	/**
	 * Get the account requests sent.
	 *
	 * @return array<int,string>
	 */
	private function account_requests(): array {
		$paths = array();
		foreach ( $this->transport->requests as $request ) {
			if ( false !== strpos( $request['path'], '/accounts' ) ) {
				$paths[] = $request['method'] . ' ' . $request['path'];
			}
		}

		return $paths;
	}

	/**
	 * Tell whether an order has a note with the given text.
	 *
	 * @param WC_Order $order Order.
	 * @param string   $text  Note text.
	 * @return bool
	 */
	private function has_note( WC_Order $order, string $text ): bool {
		foreach ( wc_get_order_notes( array( 'order_id' => $order->get_id() ) ) as $note ) {
			if ( false !== strpos( (string) $note->content, $text ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * The platform's recorded answer to an off-session renewal intent, for the request's amount and order.
	 *
	 * @param array<string,mixed> $body Decoded request body.
	 * @return array<string,mixed>
	 */
	private function recorded_renewal_intent( array $body ): array {
		$recording = json_decode( (string) file_get_contents( dirname( __DIR__ ) . '/Fixtures/platform-intention-subscription.json' ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local fixture file.
		foreach ( $recording['entries'] as $entry ) {
			if ( 'renewal_off_session' === $entry['pair'] ) {
				$intent             = $entry['response']['body'];
				$intent['metadata'] = array_merge( (array) $intent['metadata'], (array) ( $body['metadata'] ?? array() ) );

				return $intent;
			}
		}

		return array();
	}

	/**
	 * Build a platform answer for the fake transport.
	 *
	 * @param int                 $code HTTP status.
	 * @param array<string,mixed> $body Decoded body.
	 * @return array<string,mixed>
	 */
	private static function platform_answer( int $code, array $body ): array {
		return array(
			'response' => array( 'code' => $code ),
			'headers'  => array( 'content-type' => 'application/json' ),
			'body'     => wp_json_encode( $body ),
		);
	}
}
