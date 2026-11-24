<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments\Subscriptions;

use ActionScheduler;
use ActionScheduler_Store;
use Automattic\WooCommerce\Internal\Payments\PaymentsBootstrap;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api\Client;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api\TransportLog;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Subscriptions\SubscriptionsController;
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
 * A due renewal of an automatic subscription on a WooPayments method that cannot be charged again (iDEAL here) goes to
 * the shopper as Subscriptions' renewal invoice: the subscription becomes manual with the existing note, the pending
 * order loses its gateway, and nothing is charged.
 *
 * WooCommerce Subscriptions' actions run through Action Scheduler with the stand-ins of ScheduledRenewalDoubles, its
 * Customer Renewal Invoice is a stand-in that sends through WooCommerce's mailer, mail is captured, and the platform
 * transport records every request. Each iDEAL subscription keeps its parent's valid card token, so a charge by mistake
 * would show as an intent request.
 */
class NonReusableRenewalInvoiceTest extends WC_Unit_Test_Case {

	private const RENEWAL_ACTION = 'woocommerce_scheduled_subscription_payment';

	private const RETRY_ACTION = 'woocommerce_scheduled_subscription_payment_retry';

	private const CARD = 'woocommerce_payments';

	private const AMAZON_PAY = 'woocommerce_payments_amazon_pay';

	private const IDEAL = 'woocommerce_payments_ideal';

	private const SHOPPER_EMAIL = 'subscriber@example.com';

	private const FORCE_MANUAL_NOTE = 'Subscription set to manual renewal because ideal is a non-reusable payment method.';

	/** WooCommerce's own note when an email about an order is sent. */
	private const INVOICE_SENT_NOTE = 'Email "Customer Renewal Invoice" sent.';

	/**
	 * Every published WooPayments gateway outside card and Amazon Pay, written out so a gateway left out of the loop fails.
	 */
	private const NON_REUSABLE_GATEWAYS = array(
		'woocommerce_payments_affirm',
		'woocommerce_payments_afterpay_clearpay',
		'woocommerce_payments_alipay',
		'woocommerce_payments_bancontact',
		'woocommerce_payments_au_becs_debit',
		'woocommerce_payments_eps',
		'woocommerce_payments_grabpay',
		'woocommerce_payments_ideal',
		'woocommerce_payments_multibanco',
		'woocommerce_payments_klarna',
		'woocommerce_payments_p24',
		'woocommerce_payments_sepa_debit',
		'woocommerce_payments_wechat_pay',
		'woocommerce_payments_apple_pay',
		'woocommerce_payments_google_pay',
	);

	/**
	 * The platform transport, which records every request.
	 *
	 * @var FakeHttpClient
	 */
	private FakeHttpClient $transport;

	/**
	 * Mail as wp_mail() received it.
	 *
	 * @var array<int,array<string,mixed>>
	 */
	private array $sent = array();

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
	 * An active store the built-in owns, with Subscriptions and its renewal invoice loaded, mail captured and a platform
	 * that answers nothing a renewal should ask.
	 */
	public function setUp(): void {
		parent::setUp();

		update_option( WooPaymentsFeatureController::FEATURE_ENABLE_OPTION, 'yes' );
		update_option( 'active_plugins', array() );
		delete_site_option( 'active_sitewide_plugins' );
		delete_option( WooPaymentsRuntimeArbiter::EXTENSION_VERSION_OPTION );
		update_option( WooPaymentsSetupTier::OPTION_NAME, WooPaymentsSetupTier::ACTIVE );
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
		ScheduledRenewalDoubles::load_renewal_invoice_email();
		RecordedPublicFraudServices::answer();

		$this->sent = array();
		add_filter(
			'pre_wp_mail',
			function ( $short_circuit, array $atts ) {
				unset( $short_circuit );
				$this->sent[] = $atts;
				return true;
			},
			10,
			2
		);

		$this->transport = new class() extends FakeHttpClient {
			/**
			 * Record the request and refuse it.
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

				return array(
					'response' => array( 'code' => 500 ),
					'headers'  => array( 'content-type' => 'application/json' ),
					'body'     => wp_json_encode( array( 'error' => array( 'code' => 'unexpected_request' ) ) ),
				);
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

		wc_get_container()->get( WooPaymentsAccountService::class )->overwrite_cache_with_no_account();
		// Writing the empty account drops the stored tier; the case's store is active.
		update_option( WooPaymentsSetupTier::OPTION_NAME, WooPaymentsSetupTier::ACTIVE );
		$this->next_request();
	}

	/**
	 * Undo what the case's requests leave for the rest of the process.
	 */
	public function tearDown(): void {
		try {
			WC()->payment_gateways()->payment_gateways = $this->original_payment_gateways;
			$this->mailer_instance()->setValue( null, $this->original_mailer );
			if ( $this->original_mailer instanceof \WC_Emails ) {
				$this->original_mailer->emails = $this->original_emails;
			}
			unset(
				$GLOBALS[ WooCommerceSubscriptionsDoubles::SUBSCRIPTION_IDS ],
				$GLOBALS[ WooCommerceSubscriptionsDoubles::ORDER_SUBSCRIPTIONS ],
				$GLOBALS[ WooCommerceSubscriptionsDoubles::RENEWAL_SUBSCRIPTIONS ],
				$GLOBALS['wcpay_test_renewal_order_ids']
			);
			$fallback_hooks = new \ReflectionProperty( WooPaymentsGateway::class, 'classic_checkout_fallback_hooks_added' );
			$fallback_hooks->setAccessible( true );
			$fallback_hooks->setValue( null, false );
			as_unschedule_all_actions( '', array(), 'non-reusable-renewal' );
			$this->reset_container_replacements();
		} finally {
			wc_get_container()->reset_all_resolved();
			wc_get_container()->get( WooPaymentsRuntimeArbiter::class )->invalidate();
			wc_get_container()->get( WooPaymentsSetupTier::class )->invalidate();
			parent::tearDown();
		}
	}

	/**
	 * @testdox On a $_dataName store, attached twice, every published WooPayments gateway outside card and Amazon Pay has exactly one invoice callback on its scheduled-payment hook; card and Amazon Pay keep only their charge handler, and another provider's gateway and Link get nothing.
	 * @dataProvider builtin_owned_tiers
	 *
	 * @param string $tier Stored setup tier.
	 */
	public function test_each_non_reusable_gateway_gets_one_invoice_callback( string $tier ): void {
		update_option( WooPaymentsSetupTier::OPTION_NAME, $tier );
		$this->next_request();
		$this->bootstrap( RequestTypeUtil::CRON );
		// The card gateway attaches the same hooks when it is built; the controller can be asked again.
		new WooPaymentsGateway();
		$controller = wc_get_container()->get( SubscriptionsController::class );
		$controller->handle_plugins_loaded();

		foreach ( self::NON_REUSABLE_GATEWAYS as $gateway_id ) {
			$this->assertSame( array( array( $controller, 'invoice_non_reusable_renewal' ) ), $this->callbacks( 'woocommerce_scheduled_subscription_payment_' . $gateway_id ), "$gateway_id has one invoice callback." );
		}
		foreach ( array( self::CARD, self::AMAZON_PAY ) as $gateway_id ) {
			$this->assertSame( array( array( $controller, 'scheduled_subscription_payment' ) ), $this->callbacks( 'woocommerce_scheduled_subscription_payment_' . $gateway_id ), "$gateway_id keeps only its charge handler." );
		}
		foreach ( array( 'ppcp-gateway', 'woocommerce_payments_link' ) as $gateway_id ) {
			$this->assertSame( array(), $this->callbacks( 'woocommerce_scheduled_subscription_payment_' . $gateway_id ), "$gateway_id gets no callback." );
		}
	}

	/**
	 * @testdox At the lowest tier, neither the controller nor a card gateway built there attaches an invoice callback.
	 */
	public function test_the_lowest_tier_attaches_no_invoice_callback(): void {
		update_option( WooPaymentsSetupTier::OPTION_NAME, WooPaymentsSetupTier::AVAILABLE );
		$this->next_request();
		$this->bootstrap( RequestTypeUtil::CRON );
		new WooPaymentsGateway();
		wc_get_container()->get( SubscriptionsController::class )->handle_plugins_loaded();

		$this->assertNotSame( array(), $this->callbacks( 'woocommerce_scheduled_subscription_payment_' . self::CARD ), 'The card gateway attached its hooks.' );
		foreach ( self::NON_REUSABLE_GATEWAYS as $gateway_id ) {
			$this->assertSame( array(), $this->callbacks( 'woocommerce_scheduled_subscription_payment_' . $gateway_id ), "$gateway_id has no callback at the lowest tier." );
		}
	}

	/**
	 * @testdox On a $_dataName store, a due iDEAL renewal leaves one pending order with its amount and no gateway, a manual subscription on hold with the existing note and its original method, one invoice to the shopper for that order, and no request, note on the order or further action.
	 * @dataProvider builtin_owned_tiers
	 *
	 * @param string $tier Stored setup tier.
	 */
	public function test_a_due_renewal_is_invoiced_and_made_manual( string $tier ): void {
		update_option( WooPaymentsSetupTier::OPTION_NAME, $tier );
		$this->next_request();
		$subscription = $this->create_subscription();
		$this->bootstrap( RequestTypeUtil::CRON );

		$this->assertSame( ActionScheduler_Store::STATUS_COMPLETE, $this->run_action( self::RENEWAL_ACTION, array( 'subscription_id' => $subscription->get_id() ) ) );

		$renewal      = $this->last_renewal( $subscription );
		$subscription = wc_get_order( $subscription->get_id() );
		$this->assertSame( array( $renewal->get_id() ), $this->renewal_order_ids( $subscription ), 'One renewal order.' );
		$this->assertSame( 'pending', $renewal->get_status() );
		$this->assertSame( '10.99', $renewal->get_total() );
		$this->assertSame( '', $renewal->get_payment_method() );
		$this->assertSame( '', $renewal->get_payment_method_title() );
		$this->assertSame( array( self::INVOICE_SENT_NOTE ), $this->note_texts( $renewal ), 'No note on the renewal order but WooCommerce\'s record of the email.' );

		$this->assertSame( 'on-hold', $subscription->get_status() );
		$this->assertSame( 1, $subscription->get_suspension_count(), 'One move to on hold.' );
		$this->assertTrue( $subscription->get_requires_manual_renewal() );
		$this->assertSame( self::IDEAL, $subscription->get_payment_method() );
		$this->assertSame( self::IDEAL, $subscription->get_meta( '_wcpay_original_payment_method_id', true ) );
		$this->assertSame( 1, $this->count_notes( $subscription, self::FORCE_MANUAL_NOTE ) );

		$this->assertSame( array( 'Invoice for renewal order ' . $renewal->get_order_number() ), $this->subjects_to( self::SHOPPER_EMAIL ), 'One invoice to the shopper.' );
		$this->assertSame( array( $renewal->get_id() ), $this->invoiced_order_ids() );
		$this->assertStringContainsString( str_replace( '&', '&amp;', $renewal->get_checkout_payment_url() ), (string) $this->invoice_mail()['message'], 'The invoice links to the order to pay.' );
		$this->assertSame( array(), $this->transport->requests, 'No platform request.' );
		$this->assertSame( array(), $this->pending( self::RETRY_ACTION ) );
		$this->assertSame( array(), $this->pending( self::RENEWAL_ACTION ) );
	}

	/**
	 * @testdox An early-renewal iDEAL order dispatched to the gateway hook is left alone; the due renewal that follows is invoiced and made manual.
	 */
	public function test_an_early_renewal_is_skipped_and_the_due_one_is_not(): void {
		$subscription = $this->create_subscription();
		$this->bootstrap( RequestTypeUtil::FRONT );

		// The early-renewal modal marks and saves its order, then fires the gateway hook (class-wcs-early-renewal-modal-handler.php:249-253).
		$early = ScheduledRenewalDoubles::create_renewal_order( wc_get_order( $subscription->get_id() ) );
		$early->update_meta_data( '_subscription_renewal_early', $subscription->get_id() );
		$early->set_status( 'pending' );
		$early->save();
		WC()->payment_gateways();
		do_action( 'woocommerce_scheduled_subscription_payment_' . self::IDEAL, $early->get_total(), $early );

		$early = wc_get_order( $early->get_id() );
		$this->assertSame( self::IDEAL, $early->get_payment_method() );
		$this->assertSame( array(), $this->note_texts( $early ) );
		$this->assertFalse( wc_get_order( $subscription->get_id() )->get_requires_manual_renewal() );
		$this->assertSame( 0, $this->count_notes( wc_get_order( $subscription->get_id() ), self::FORCE_MANUAL_NOTE ) );
		$this->assertSame( array(), $this->invoiced_order_ids() );

		$this->run_action( self::RENEWAL_ACTION, array( 'subscription_id' => $subscription->get_id() ) );

		$due = $this->last_renewal( $subscription );
		$this->assertNotSame( $early->get_id(), $due->get_id() );
		$this->assertSame( array( $due->get_id() ), $this->invoiced_order_ids() );
		$this->assertTrue( wc_get_order( $subscription->get_id() )->get_requires_manual_renewal() );
		$this->assertSame( self::IDEAL, wc_get_order( $early->get_id() )->get_payment_method() );
		$this->assertSame( array(), $this->transport->requests );
	}

	/**
	 * @testdox After a card renewal's authentication email removes the invoice listeners in a request, a due iDEAL renewal in the same request still gets exactly one invoice.
	 */
	public function test_the_invoice_is_sent_after_the_authentication_email_in_one_request(): void {
		$subscription = $this->create_subscription();
		$this->bootstrap( RequestTypeUtil::CRON );
		$mailer  = $this->build_mailer_in_this_request();
		$invoice = $mailer->get_emails()[ ScheduledRenewalDoubles::RENEWAL_INVOICE_EMAIL ];

		WooCommerceSubscriptionsDoubles::load_renewal_detector();
		$card_renewal = wc_create_order();
		$card_renewal->set_billing_email( 'card-shopper@example.com' );
		$card_renewal->set_payment_method( self::CARD );
		$card_renewal->set_total( '24.90' );
		$card_renewal->save();
		$GLOBALS['wcpay_test_renewal_order_ids'][] = $card_renewal->get_id();
		do_action( 'woocommerce_woocommerce_payments_payment_requires_action', $card_renewal );
		$this->assertFalse( has_action( 'woocommerce_generated_manual_renewal_order_renewal_notification', array( $invoice, 'trigger' ) ), 'The authentication email removed the invoice listener.' );

		$this->run_action( self::RENEWAL_ACTION, array( 'subscription_id' => $subscription->get_id() ) );

		$card_mail = $this->subjects_to( 'card-shopper@example.com' );
		$this->assertCount( 1, $card_mail );
		$this->assertStringContainsString( 'Payment authorization needed', $card_mail[0] );
		$this->assertSame( array( 'Invoice for renewal order ' . $this->last_renewal( $subscription )->get_order_number() ), $this->subjects_to( self::SHOPPER_EMAIL ) );
	}

	/**
	 * @testdox A renewal stalled before the fix, the subscription $status and the order $order_status, offers "Retry Renewal Payment" in an admin request; applying it once keeps the same order, clears its gateway, makes the subscription manual, sends one invoice and withdraws the action.
	 * @testWith ["on-hold", "pending"]
	 *           ["pending", "pending"]
	 *           ["on-hold", "failed"]
	 *
	 * @param string $status       Subscription status.
	 * @param string $order_status Renewal order status.
	 */
	public function test_the_merchant_retry_recovers_a_stalled_renewal( string $status, string $order_status ): void {
		$subscription = $this->create_subscription();
		$subscription->update_status( $status, 'Subscription renewal payment due:' );
		$renewal = ScheduledRenewalDoubles::create_renewal_order( wc_get_order( $subscription->get_id() ) );
		$renewal->set_status( $order_status );
		$renewal->save();
		$this->bootstrap( RequestTypeUtil::ADMIN );
		WC()->payment_gateways();
		$renewal = wc_get_order( $renewal->get_id() );

		$this->assertTrue( ScheduledRenewalDoubles::can_renewal_order_be_retried( $renewal ), 'The order screen offers the retry.' );
		$notes_before = count( $this->note_texts( $renewal ) );
		ScheduledRenewalDoubles::process_retry_renewal_payment_action_request( $renewal );

		$renewal      = wc_get_order( $renewal->get_id() );
		$subscription = wc_get_order( $subscription->get_id() );
		$this->assertSame( array( $renewal->get_id() ), $this->renewal_order_ids( $subscription ), 'No new order.' );
		$this->assertSame( $order_status, $renewal->get_status() );
		$this->assertSame( '', $renewal->get_payment_method() );
		$notes = $this->note_texts( $renewal );
		$this->assertSame( array( self::INVOICE_SENT_NOTE, ScheduledRenewalDoubles::ADMIN_RETRY_NOTE ), array_slice( $notes, 0, count( $notes ) - $notes_before ), 'The retry adds only its own note and the email record.' );
		$this->assertTrue( $subscription->get_requires_manual_renewal() );
		$this->assertSame( 1, $this->count_notes( $subscription, self::FORCE_MANUAL_NOTE ) );
		$this->assertSame( array( $renewal->get_id() ), $this->invoiced_order_ids() );
		$this->assertFalse( ScheduledRenewalDoubles::can_renewal_order_be_retried( $renewal ), 'The retry is withdrawn.' );
		$this->assertSame( array(), $this->transport->requests );
	}

	/**
	 * @testdox A queued retry of a failed card renewal, after the merchant moved the subscription to iDEAL, makes it manual and invoices the original order, with no charge and no further retry.
	 */
	public function test_a_queued_retry_after_a_move_to_ideal_invoices_the_order(): void {
		$subscription = $this->create_subscription( self::CARD );
		$failed       = ScheduledRenewalDoubles::create_renewal_order( wc_get_order( $subscription->get_id() ) );
		$failed->set_status( 'failed' );
		$failed->save();
		wc_get_order( $subscription->get_id() )->update_status( 'on-hold' );
		$this->bootstrap( RequestTypeUtil::ADMIN );
		WC()->payment_gateways();
		$moved = wc_get_order( $subscription->get_id() );
		$moved->set_payment_method( self::IDEAL );
		$moved->save();
		$this->assertFalse( wc_get_order( $subscription->get_id() )->get_requires_manual_renewal(), 'The move keeps the subscription automatic.' );
		$shopper_mail = $this->subjects_to( self::SHOPPER_EMAIL );

		$this->run_action( self::RETRY_ACTION, array( 'order_id' => $failed->get_id() ) );

		$failed       = wc_get_order( $failed->get_id() );
		$subscription = wc_get_order( $subscription->get_id() );
		$this->assertTrue( $subscription->get_requires_manual_renewal() );
		$this->assertSame( array( $failed->get_id() ), $this->renewal_order_ids( $subscription ) );
		$this->assertSame( 'pending', $failed->get_status(), 'The same order, still to pay.' );
		$this->assertSame( '', $failed->get_payment_method() );
		$this->assertSame( array_merge( $shopper_mail, array( 'Invoice for renewal order ' . $failed->get_order_number() ) ), $this->subjects_to( self::SHOPPER_EMAIL ), 'One more email: the invoice.' );
		$this->assertSame( array(), $this->transport->requests );
		$this->assertSame( array(), $this->pending( self::RETRY_ACTION ) );
	}

	/**
	 * @testdox The callback reads the order again: an order a listener before it moved to bank transfer on its own copy is left alone, and a second controller instance on the same hook adds nothing.
	 */
	public function test_the_callback_reads_the_order_and_subscription_again(): void {
		$paid_first = $this->create_subscription();
		$twice      = $this->create_subscription();
		$this->bootstrap( RequestTypeUtil::CRON );
		$pay_first = static function ( $amount, $renewal ) use ( $paid_first ): void {
			unset( $amount );
			if ( in_array( $paid_first->get_id(), array_map( static fn( $s ) => $s->get_id(), wcs_get_subscriptions_for_renewal_order( $renewal->get_id() ) ), true ) ) {
				$copy = wc_get_order( $renewal->get_id() );
				$copy->set_payment_method( 'bacs' );
				$copy->save();
			}
		};
		add_action( 'woocommerce_scheduled_subscription_payment_' . self::IDEAL, $pay_first, 5, 2 );

		$this->run_action( self::RENEWAL_ACTION, array( 'subscription_id' => $paid_first->get_id() ) );

		$this->assertFalse( wc_get_order( $paid_first->get_id() )->get_requires_manual_renewal() );
		$this->assertSame( 'bacs', $this->last_renewal( $paid_first )->get_payment_method() );
		$this->assertSame( array(), $this->invoiced_order_ids() );

		remove_action( 'woocommerce_scheduled_subscription_payment_' . self::IDEAL, $pay_first, 5 );
		add_action( 'woocommerce_scheduled_subscription_payment_' . self::IDEAL, array( new SubscriptionsController(), 'invoice_non_reusable_renewal' ), 10, 2 );

		$this->run_action( self::RENEWAL_ACTION, array( 'subscription_id' => $twice->get_id() ) );

		$this->assertSame( 1, $this->count_notes( wc_get_order( $twice->get_id() ), self::FORCE_MANUAL_NOTE ) );
		$this->assertSame( array( $this->last_renewal( $twice )->get_id() ), $this->invoiced_order_ids() );
	}

	/**
	 * @testdox The latest pending iDEAL order of an on-hold subscription already made manual, dispatched to the hook again, gets no second note, invoice or change.
	 */
	public function test_an_already_converted_subscription_gets_nothing_more(): void {
		$subscription = $this->create_subscription();
		$subscription->update_status( 'on-hold' );
		$this->bootstrap( RequestTypeUtil::CRON );
		$controller = wc_get_container()->get( SubscriptionsController::class );
		$controller->maybe_force_subscription_to_manual( wc_get_order( $subscription->get_id() ) );
		$renewal = ScheduledRenewalDoubles::create_renewal_order( wc_get_order( $subscription->get_id() ) );
		$renewal->set_status( 'pending' );
		$renewal->save();

		do_action( 'woocommerce_scheduled_subscription_payment_' . self::IDEAL, $renewal->get_total(), wc_get_order( $renewal->get_id() ) );

		$this->assertSame( self::IDEAL, wc_get_order( $renewal->get_id() )->get_payment_method() );
		$this->assertSame( 1, $this->count_notes( wc_get_order( $subscription->get_id() ), self::FORCE_MANUAL_NOTE ) );
		$this->assertSame( array(), $this->invoiced_order_ids() );
	}

	/**
	 * @testdox When saving the manual subscription throws, the due action fails with no invoice sent, and the merchant's later Retry Renewal Payment converts and invoices once: one invoice in all.
	 */
	public function test_a_failed_conversion_sends_no_invoice_until_a_later_dispatch(): void {
		$subscription = $this->create_subscription();
		$this->bootstrap( RequestTypeUtil::CRON );
		$failures = 0;
		add_action(
			'woocommerce_before_subscription_object_save',
			static function ( $saved ) use ( $subscription, &$failures ): void {
				if ( 0 === $failures && $saved->get_id() === $subscription->get_id() && 'true' === $saved->get_meta( '_requires_manual_renewal', true ) ) {
					++$failures;
					// WC_Order::save() keeps an Exception to itself; an Error from a save listener reaches the action.
					throw new \Error( 'A save listener failed.' );
				}
			}
		);

		$this->assertSame( ActionScheduler_Store::STATUS_FAILED, $this->run_action( self::RENEWAL_ACTION, array( 'subscription_id' => $subscription->get_id() ) ) );
		$renewal = $this->last_renewal( $subscription );
		$this->assertSame( array(), $this->invoiced_order_ids() );
		$this->assertFalse( wc_get_order( $subscription->get_id() )->get_requires_manual_renewal() );
		$this->assertTrue( ScheduledRenewalDoubles::can_renewal_order_be_retried( $renewal ), 'Subscriptions still offers the retry.' );

		ScheduledRenewalDoubles::process_retry_renewal_payment_action_request( $renewal );

		$this->assertTrue( wc_get_order( $subscription->get_id() )->get_requires_manual_renewal() );
		$this->assertSame( array( $renewal->get_id() ), $this->invoiced_order_ids() );
	}

	/**
	 * @testdox A subscription that still holds its Stripe subscription ID is left alone at its due iDEAL renewal: no conversion, invoice or change to the order.
	 */
	public function test_a_subscription_holding_a_stripe_subscription_id_is_left_alone(): void {
		$subscription = $this->create_subscription();
		$subscription->update_meta_data( '_wcpay_subscription_id', 'sub_1UfFy0J4W8z6ZKLBstripe' );
		$subscription->save();
		$this->bootstrap( RequestTypeUtil::CRON );

		$this->run_action( self::RENEWAL_ACTION, array( 'subscription_id' => $subscription->get_id() ) );

		$this->assertFalse( wc_get_order( $subscription->get_id() )->get_requires_manual_renewal() );
		$this->assertSame( 0, $this->count_notes( wc_get_order( $subscription->get_id() ), self::FORCE_MANUAL_NOTE ) );
		$this->assertSame( self::IDEAL, $this->last_renewal( $subscription )->get_payment_method() );
		$this->assertSame( array(), $this->invoiced_order_ids() );
	}

	/**
	 * @testdox The merchant's retry of an old pending order after the subscription was reactivated changes nothing.
	 */
	public function test_a_retry_of_a_reactivated_subscriptions_old_order_changes_nothing(): void {
		$subscription = $this->create_subscription();
		$subscription->update_status( 'on-hold' );
		$renewal = ScheduledRenewalDoubles::create_renewal_order( wc_get_order( $subscription->get_id() ) );
		$renewal->set_status( 'pending' );
		$renewal->save();
		wc_get_order( $subscription->get_id() )->update_status( 'active' );
		$this->bootstrap( RequestTypeUtil::ADMIN );
		WC()->payment_gateways();

		ScheduledRenewalDoubles::process_retry_renewal_payment_action_request( wc_get_order( $renewal->get_id() ) );

		$this->assertSame( array( 'active', false ), array( wc_get_order( $subscription->get_id() )->get_status(), wc_get_order( $subscription->get_id() )->get_requires_manual_renewal() ) );
		$this->assertSame( self::IDEAL, wc_get_order( $renewal->get_id() )->get_payment_method() );
		$this->assertSame( array(), $this->invoiced_order_ids() );
	}

	/**
	 * @testdox The merchant's retry of the older of two outstanding renewals changes nothing, and the latest still offers the retry.
	 */
	public function test_a_retry_of_an_older_renewal_changes_nothing(): void {
		$subscription = $this->create_subscription();
		$subscription->update_status( 'on-hold' );
		$older  = ScheduledRenewalDoubles::create_renewal_order( wc_get_order( $subscription->get_id() ) );
		$latest = ScheduledRenewalDoubles::create_renewal_order( wc_get_order( $subscription->get_id() ) );
		$this->bootstrap( RequestTypeUtil::ADMIN );
		WC()->payment_gateways();

		ScheduledRenewalDoubles::process_retry_renewal_payment_action_request( wc_get_order( $older->get_id() ) );

		$this->assertFalse( wc_get_order( $subscription->get_id() )->get_requires_manual_renewal() );
		$this->assertSame( self::IDEAL, wc_get_order( $older->get_id() )->get_payment_method() );
		$this->assertSame( array(), $this->invoiced_order_ids() );
		$this->assertTrue( ScheduledRenewalDoubles::can_renewal_order_be_retried( wc_get_order( $latest->get_id() ) ) );
	}

	/**
	 * @testdox The merchant's retry of the latest renewal in another status a store made payable (on hold) changes nothing: the invoice would carry no pay link.
	 */
	public function test_a_retry_of_an_order_in_another_payable_status_changes_nothing(): void {
		$subscription = $this->create_subscription();
		$subscription->update_status( 'on-hold' );
		$renewal = ScheduledRenewalDoubles::create_renewal_order( wc_get_order( $subscription->get_id() ) );
		$renewal->set_status( 'on-hold' );
		$renewal->save();
		add_filter(
			'woocommerce_valid_order_statuses_for_payment',
			static fn( $statuses ) => array_merge( (array) $statuses, array( 'on-hold' ) )
		);
		$this->bootstrap( RequestTypeUtil::ADMIN );
		WC()->payment_gateways();
		$this->assertTrue( ScheduledRenewalDoubles::can_renewal_order_be_retried( wc_get_order( $renewal->get_id() ) ), 'The order screen offers the retry.' );

		ScheduledRenewalDoubles::process_retry_renewal_payment_action_request( wc_get_order( $renewal->get_id() ) );

		$this->assertFalse( wc_get_order( $subscription->get_id() )->get_requires_manual_renewal() );
		$this->assertSame( self::IDEAL, wc_get_order( $renewal->get_id() )->get_payment_method() );
		$this->assertSame( array(), $this->invoiced_order_ids() );
	}

	/**
	 * @testdox The invoice for a due iDEAL renewal shows the order's totals with no payment method line, as Subscriptions' manual invoice does.
	 */
	public function test_the_invoice_shows_no_payment_method(): void {
		$subscription = $this->create_subscription();
		$this->bootstrap( RequestTypeUtil::CRON );

		$this->run_action( self::RENEWAL_ACTION, array( 'subscription_id' => $subscription->get_id() ) );

		$body = (string) $this->invoice_mail()['message'];
		$this->assertStringContainsString( 'Total:', $body, 'The invoice shows the totals.' );
		$this->assertStringNotContainsString( 'Payment method:', $body );
	}

	/**
	 * The tiers whose controller handles renewals.
	 *
	 * @return array<string,array{string}>
	 */
	public static function builtin_owned_tiers(): array {
		return array(
			'connected' => array( WooPaymentsSetupTier::CONNECTED ),
			'active'    => array( WooPaymentsSetupTier::ACTIVE ),
		);
	}

	/**
	 * Create an active automatic subscription paying 10.99 USD, on iDEAL unless told otherwise, as a merchant's move from card
	 * leaves it: the parent's valid card token and payment method ID stay on it.
	 *
	 * @param string $payment_method Gateway ID.
	 * @return RenewalSubscriptionDouble
	 */
	private function create_subscription( string $payment_method = self::IDEAL ): RenewalSubscriptionDouble {
		$user_id = self::factory()->user->create();
		$token   = new WC_Payment_Token_CC();
		$token->set_token( 'pm_1UJhOFBzWlxcwgpPvcySvyc5' );
		$token->set_gateway_id( self::CARD );
		$token->set_card_type( 'visa' );
		$token->set_last4( '4242' );
		$token->set_expiry_month( '12' );
		$token->set_expiry_year( (string) ( (int) gmdate( 'Y' ) + 2 ) );
		$token->set_user_id( $user_id );
		$token->save();

		$subscription = new WC_Order();
		$subscription->save();
		$GLOBALS[ WooCommerceSubscriptionsDoubles::SUBSCRIPTION_IDS ][] = $subscription->get_id();

		$subscription = wc_get_order( $subscription->get_id() );
		$this->assertInstanceOf( RenewalSubscriptionDouble::class, $subscription );
		$subscription->set_customer_id( $user_id );
		$subscription->set_currency( 'USD' );
		$subscription->set_billing_email( self::SHOPPER_EMAIL );
		$subscription->set_payment_method( $payment_method );
		$subscription->set_requires_manual_renewal( false );
		$subscription->set_status( 'active' );
		$subscription->set_total( '10.99' );
		$subscription->add_payment_token( $token );
		$subscription->update_meta_data( '_payment_method_id', 'pm_1UJhOFBzWlxcwgpPvcySvyc5' );
		$subscription->update_meta_data( '_stripe_customer_id', 'cus_UsIeTbmGHPc9jY' );
		$subscription->save();

		return wc_get_order( $subscription->get_id() );
	}

	/**
	 * Start a fresh request: drop the request memos of the tier and the owner, and the services the last one resolved.
	 */
	private function next_request(): void {
		wc_get_container()->reset_all_resolved();
		wc_get_container()->get( WooPaymentsRuntimeArbiter::class )->invalidate();
		wc_get_container()->get( WooPaymentsSetupTier::class )->invalidate();
		$this->mailer_instance()->setValue( null, null );
	}

	/**
	 * Run the payments bootstrap for a request type, as WooCommerce does when it loads, and build the gateway list again.
	 *
	 * @param string $request_type Request type.
	 */
	private function bootstrap( string $request_type ): void {
		( new PaymentsBootstrap(
			static fn( $container, string $type ): array => $container->get( WooPaymentsSetupTier::class )->get_classes_for_request( $type ),
			static fn( $container ): bool => $container->get( WooPaymentsRuntimeArbiter::class )->is_builtin_owner()
		) )->register( wc_get_container(), $request_type );
		WC()->payment_gateways()->payment_gateways = array();
		WC()->payment_gateways()->init();
	}

	/**
	 * Build the mailer in this request, after the bootstrap, as an earlier email in the request would.
	 *
	 * @return \WC_Emails
	 */
	private function build_mailer_in_this_request(): \WC_Emails {
		$this->mailer_instance()->setValue( null, null );

		return WC()->mailer();
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
	 * Run an action through Action Scheduler, as its queue runner does, and get its status after.
	 *
	 * @param string              $hook Hook.
	 * @param array<string,mixed> $args Arguments.
	 * @return string
	 */
	private function run_action( string $hook, array $args ): string {
		$id = as_schedule_single_action( time() - 1, $hook, $args, 'non-reusable-renewal' );
		ActionScheduler::runner()->process_action( $id, 'WP Cron' );

		return ActionScheduler::store()->get_status( $id );
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
	 * Get the IDs of the subscription's renewal orders.
	 *
	 * @param WC_Order $subscription Subscription.
	 * @return array<int,int>
	 */
	private function renewal_order_ids( WC_Order $subscription ): array {
		$ids = array();
		foreach ( $GLOBALS[ WooCommerceSubscriptionsDoubles::RENEWAL_SUBSCRIPTIONS ] ?? array() as $order_id => $subscription_ids ) {
			if ( in_array( $subscription->get_id(), $subscription_ids, true ) ) {
				$ids[] = (int) $order_id;
			}
		}

		return $ids;
	}

	/**
	 * Get the IDs of the orders a Customer Renewal Invoice was sent for, in order, from the captured mail.
	 *
	 * @return array<int,int>
	 */
	private function invoiced_order_ids(): array {
		$ids = array();
		foreach ( $this->sent as $mail ) {
			if ( 1 === preg_match( '/^Invoice for renewal order (\d+)$/', (string) $mail['subject'], $matches ) ) {
				$ids[] = (int) $matches[1];
			}
		}

		return $ids;
	}

	/**
	 * Get the subjects of the mail sent to an address, in order.
	 *
	 * @param string $address Recipient.
	 * @return array<int,string>
	 */
	private function subjects_to( string $address ): array {
		$subjects = array();
		foreach ( $this->sent as $mail ) {
			if ( $address === $mail['to'] ) {
				$subjects[] = (string) $mail['subject'];
			}
		}

		return $subjects;
	}

	/**
	 * Get the one invoice mail sent.
	 *
	 * @return array<string,mixed>
	 */
	private function invoice_mail(): array {
		$invoices = array_values( array_filter( $this->sent, static fn( array $mail ): bool => 0 === strpos( (string) $mail['subject'], 'Invoice for renewal order ' ) ) );
		$this->assertCount( 1, $invoices );

		return $invoices[0];
	}

	/**
	 * Get an order's note texts.
	 *
	 * @param WC_Order $order Order.
	 * @return array<int,string>
	 */
	private function note_texts( WC_Order $order ): array {
		return array_map( static fn( $note ): string => (string) $note->content, wc_get_order_notes( array( 'order_id' => $order->get_id() ) ) );
	}

	/**
	 * Count an order's notes with the given text.
	 *
	 * @param WC_Order $order Order.
	 * @param string   $text  Note text.
	 * @return int
	 */
	private function count_notes( WC_Order $order, string $text ): int {
		return count( array_filter( $this->note_texts( $order ), static fn( string $content ): bool => $text === $content ) );
	}
}
