<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments\Webhooks;

use Automattic\WooCommerce\Internal\MultiCurrency\MultiCurrencyRuntimeArbiter;
use Automattic\WooCommerce\Internal\MultiCurrency\MultiCurrencyFeatureController;
use Automattic\WooCommerce\Internal\Payments\OrderPaymentNotes;
use Automattic\WooCommerce\Internal\Payments\ProviderPersistenceVocabularyInterface;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Webhooks\WooPaymentsEventOrderResolver;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsPersistenceVocabulary;
use Automattic\WooCommerce\Proxies\LegacyProxy;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsRuntimeArbiter;
use Automattic\WooCommerce\Internal\Payments\OrderPaymentLifecycleService;
use Automattic\WooCommerce\Internal\Payments\OrderPaymentLock;
use Automattic\WooCommerce\Internal\Payments\PaymentOperationContext;
use Automattic\WooCommerce\Internal\Payments\PaymentOutcome;
use Automattic\WooCommerce\Internal\Payments\PaymentProcessingService;
use Automattic\WooCommerce\Internal\Payments\RefundRowCapture;
use Automattic\WooCommerce\Internal\Payments\ProviderOperationEffectApplierInterface;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api\WooPaymentsApiClient;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsAccountService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsHtmlUtils;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsOrderEffectApplier;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsOrderEffectPlan;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsOrderNoteService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Webhooks\WooPaymentsRefundEventHandler;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Webhooks\WooPaymentsOtherChargeRecorder;
use Automattic\WooCommerce\Tests\Internal\Payments\OrderPaymentLockWithClaimHook;
use Automattic\WooCommerce\Tests\Internal\Payments\OrderPaymentLockTestTrait;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments\RecordingWcLogger;
use Automattic\WooCommerce\Tests\Internal\Payments\UncachedOrderWriter;
use Automattic\WooCommerce\Tests\Internal\Payments\RecordingProvider;
use WC_Order;
use WC_Order_Refund;
use WC_Unit_Test_Case;

/**
 * Tests for the WooPaymentsRefundEventHandler class.
 */
class WooPaymentsRefundEventHandlerTest extends WC_Unit_Test_Case {

	use OrderPaymentLockTestTrait;

	/**
	 * Original multi-currency options restored after each test.
	 *
	 * @var array<string,mixed>
	 */
	private array $original_multi_currency_options = array();

	/**
	 * Test-only gettext replacements keyed by text domain and source text.
	 *
	 * @var array<string,array<string,string>>
	 */
	private array $gettext_replacements = array();

	/**
	 * The System Under Test.
	 *
	 * @var WooPaymentsRefundEventHandler
	 */
	private WooPaymentsRefundEventHandler $sut;

	/**
	 * Set up test fixtures.
	 */
	public function setUp(): void {
		parent::setUp();
		$this->original_multi_currency_options = array(
			'_wcpay_feature_customer_multi_currency'  => get_option( '_wcpay_feature_customer_multi_currency', null ),
			'wcpay_multi_currency_enabled_currencies' => get_option( 'wcpay_multi_currency_enabled_currencies', null ),
			'wcpay_multi_currency_exchange_rate_eur'  => get_option( 'wcpay_multi_currency_exchange_rate_eur', null ),
			'wcpay_multi_currency_manual_rate_eur'    => get_option( 'wcpay_multi_currency_manual_rate_eur', null ),
			'woocommerce_currency'                    => get_option( 'woocommerce_currency', null ),
		);
		$this->sut                             = wc_get_container()->get( WooPaymentsRefundEventHandler::class );
	}

	/**
	 * Tear down test fixtures.
	 */
	public function tearDown(): void {
		remove_filter( 'gettext', array( $this, 'translate_test_string' ), 10 );
		restore_current_locale();
		$this->gettext_replacements = array();
		foreach ( $this->original_multi_currency_options as $option_name => $option_value ) {
			if ( null === $option_value ) {
				delete_option( $option_name );
			} else {
				update_option( $option_name, $option_value );
			}
		}
		$this->original_multi_currency_options = array();
		delete_option( 'wcpay_account_data' );
		wc_get_container()->get( WooPaymentsAccountService::class )->clear_cache();
		$this->reset_container_replacements();
		parent::tearDown();
	}

	/**
	 * @testdox Failed refund notes follow the WooPayments extension's explicit-price rule.
	 *
	 * Source: client 11.1.0 class-wc-payments-order-service.php:1969-1972 (failed refund note amount) and
	 * class-wc-payments-explicit-price-formatter.php:55-74,167-190 (Multi-Currency on with a second currency, then the filter).
	 *
	 * @dataProvider explicit_price_rule_provider
	 *
	 * @param bool        $core_multi_currency Whether core Multi-Currency owns the runtime.
	 * @param string|null $plugin_flag         Stale WooPayments `_wcpay_feature_customer_multi_currency` value, or null when absent.
	 * @param bool|null   $filter_result       Value the filter returns, or null for no filter.
	 * @param bool        $expected_default    Default the filter must receive.
	 * @param string      $expected_note       Expected plain-text note.
	 */
	public function test_failed_refund_note_follows_the_woopayments_extension_explicit_price_rule( bool $core_multi_currency, ?string $plugin_flag, ?bool $filter_result, bool $expected_default, string $expected_note ): void {
		update_option( 'woocommerce_currency', 'USD' );
		update_option( 'wcpay_multi_currency_enabled_currencies', array( 'USD', 'EUR' ) );
		update_option( 'wcpay_multi_currency_exchange_rate_eur', 'manual' );
		update_option( 'wcpay_multi_currency_manual_rate_eur', '0.9' );
		null === $plugin_flag ? delete_option( '_wcpay_feature_customer_multi_currency' ) : update_option( '_wcpay_feature_customer_multi_currency', $plugin_flag );
		$this->set_core_multi_currency( $core_multi_currency );
		$defaults = array();
		add_filter(
			'wcpay_multi_currency_should_output_explicit_price',
			static function ( bool $current_default ) use ( &$defaults, $filter_result ): bool {
				$defaults[] = $current_default;
				return $filter_result ?? $current_default;
			}
		);
		$order = $this->create_refundable_order();

		// A Refund object, as charge.refund.updated carries it; client 11.1.0 reads id, charge, amount, currency, status,
		// balance_transaction and failure_reason (class-wc-payments-webhook-processing-service.php:300-353).
		$this->sut->process(
			'charge.refund.updated',
			array(
				'id'       => 're_123',
				'charge'   => 'ch_123',
				'amount'   => 400,
				'currency' => 'usd',
				'status'   => 'canceled',
			)
		);

		$notes = array_values(
			array_filter(
				wc_get_order_notes( array( 'order_id' => $order->get_id() ) ),
				static fn( $note ): bool => false !== strpos( (string) $note->content, 're_123' )
			)
		);
		$this->assertCount( 1, $notes );
		$this->assertSame( $expected_note, html_entity_decode( wp_strip_all_tags( $notes[0]->content ) ) );
		$this->assertSame( array( $expected_default ), $defaults, 'The filter must run once per note with the client default.' );
	}

	/**
	 * Store states with the client's failed-refund note outcome.
	 *
	 * @return array<string,array{0:bool,1:?string,2:?bool,3:bool,4:string}>
	 */
	public function explicit_price_rule_provider(): array {
		$with_code    = 'A refund of $4.00 USD was cancelled using WooPayments (re_123).';
		$without_code = 'A refund of $4.00 was cancelled using WooPayments (re_123).';

		return array(
			'Multi-Currency on with a second currency'     => array( true, null, null, true, $with_code ),
			'Multi-Currency on, stale plugin flag off'     => array( true, '0', null, true, $with_code ),
			'Multi-Currency off, stale enabled currencies' => array( false, '1', null, false, $without_code ),
			'filter forces the code while Multi-Currency is off' => array( false, null, true, false, $with_code ),
			'filter removes the code while Multi-Currency is on' => array( true, null, false, true, $without_code ),
		);
	}

	/**
	 * @testdox With Multi-Currency on and a second currency, the charge.refunded note names the amount with its currency code.
	 *
	 * Client 11.1.0 writes the created refund note with the amount through its explicit-price formatter
	 * (class-wc-payments-order-service.php:1934-1938, :2629-2631; class-wc-payments-explicit-price-formatter.php:55-74). The
	 * event writes the first of its note candidates, the one with the code; the other wording is only for recognising a
	 * note the extension wrote.
	 */
	public function test_charge_refunded_note_carries_the_currency_code_with_multi_currency(): void {
		update_option( 'woocommerce_currency', 'USD' );
		update_option( 'wcpay_multi_currency_enabled_currencies', array( 'USD', 'EUR' ) );
		update_option( 'wcpay_multi_currency_exchange_rate_eur', 'manual' );
		update_option( 'wcpay_multi_currency_manual_rate_eur', '0.9' );
		$this->set_core_multi_currency( true );
		$order                    = $this->create_refundable_order();
		$charge                   = $this->get_successful_refund_charge();
		$charge['payment_intent'] = 'pi_123';

		$this->sut->process( 'charge.refunded', $charge );

		$notes = $this->get_notes_containing( wc_get_order( $order->get_id() ), 're_123' );
		$this->assertCount( 1, $notes );
		$this->assertStringContainsString( 'A refund of $4.00 USD', html_entity_decode( wp_strip_all_tags( $notes[0]->content ) ) );
	}

	/**
	 * Make core Multi-Currency own the runtime, or not.
	 *
	 * @param bool $enabled Whether core Multi-Currency should own the runtime.
	 */
	private function set_core_multi_currency( bool $enabled ): void {
		$arbiter   = $this->getMockBuilder( MultiCurrencyRuntimeArbiter::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'should_core_register' ) )
			->getMock();
		$container = wc_get_container();
		// Keep the real feature definition working if FeaturesController registers it while the mock is in place.
		$arbiter->init( $container->get( WooPaymentsRuntimeArbiter::class ), $container->get( LegacyProxy::class ), $container->get( MultiCurrencyFeatureController::class ) );
		$arbiter->method( 'should_core_register' )->willReturn( $enabled );
		wc_get_container()->replace( MultiCurrencyRuntimeArbiter::class, $arbiter );
	}

	/**
	 * @testdox A $status refund update on an order carrying the note the WooPayments extension wrote for it adds no second note.
	 *
	 * Client 11.1.0 writes the failed or cancelled note in handle_failed_refund() (class-wc-payments-order-service.php:1979-1999),
	 * deduped by exact text, and the insufficient-balance note with FROD support in handle_insufficient_balance_for_refund()
	 * and get_frod_support_note() (:3065-3077, :3157-3170), or without FROD support get_insufficient_balance_note() (:3178-3189).
	 * The amount is wc_price() output, whose <bdi> the notes keep.
	 *
	 * @dataProvider woopayments_extension_failed_refund_note_provider
	 *
	 * @param string $status                Refund status in the event.
	 * @param string $failure_reason        Refund failure reason in the event.
	 * @param string $extension_note_format The extension's note, with %s for the formatted amount.
	 * @param string $account_country       WooPayments account country, or '' for the test store's default (US).
	 */
	public function test_failed_refund_update_recognises_the_woopayments_extension_note( string $status, string $failure_reason, string $extension_note_format, string $account_country ): void {
		$order          = $this->create_refundable_order();
		$extension_note = sprintf( $extension_note_format, wc_price( 4.00, array( 'currency' => 'USD' ) ) );
		$order->add_order_note( $extension_note );
		if ( '' !== $account_country ) {
			$this->set_woopayments_account_country( $account_country );
		}

		// A Refund object, as charge.refund.updated carries it (class-wc-payments-webhook-processing-service.php:300-353).
		$this->sut->process(
			'charge.refund.updated',
			array(
				'id'             => 're_123',
				'charge'         => 'ch_123',
				'amount'         => 400,
				'currency'       => 'usd',
				'status'         => $status,
				'failure_reason' => $failure_reason,
			)
		);

		$refund_notes = array_values(
			array_filter(
				wc_get_order_notes( array( 'order_id' => $order->get_id() ) ),
				static fn( $note ): bool => false !== strpos( (string) $note->content, 'efund of' )
			)
		);
		$this->assertCount( 1, $refund_notes );
		$this->assertSame( $extension_note, $refund_notes[0]->content );
	}

	/**
	 * Refund failure notes as the WooPayments extension writes them.
	 *
	 * @return array<string,array{0:string,1:string,2:string,3:string}>
	 */
	public function woopayments_extension_failed_refund_note_provider(): array {
		return array(
			'failed, lost or stolen card' => array( 'failed', 'lost_or_stolen_card', 'A refund of %s was <strong>unsuccessful</strong> using WooPayments (<code>re_123</code>): The card used for the original payment has been reported lost or stolen.', '' ),
			'canceled'                    => array( 'canceled', 'merchant_request', 'A refund of %s was <strong>cancelled</strong> using WooPayments (<code>re_123</code>).', '' ),
			'failed, insufficient funds'  => array( 'failed', 'insufficient_funds', 'Refund of %s <strong>failed</strong> due to insufficient funds in your WooPayments balance. To prevent delays in refunding customers, please consider adding funds to your Future Refunds or Disputes (FROD) balance. <a href="https://woocommerce.com/document/woopayments/fees/preventing-negative-balances/#adding-funds" target="_blank" rel="noopener noreferrer">Learn more</a>.', '' ),
			'failed, insufficient funds, FROD not supported' => array( 'failed', 'insufficient_funds', 'Refund of %s <strong>failed</strong> due to insufficient funds in your WooPayments balance.', 'HK' ),
		);
	}

	/**
	 * @testdox A successful synchronous refund and its webhook converge on one canonical note and refund row.
	 */
	public function test_successful_synchronous_refund_followed_by_webhook_converges(): void {
		$previous_enabled_currencies = get_option( 'wcpay_multi_currency_enabled_currencies', false );
		update_option( 'wcpay_multi_currency_enabled_currencies', array( 'EUR' ) );

		try {
			$order              = $this->create_refundable_order();
			$refund             = $this->create_local_refund( $order );
			$provider_result    = array(
				'id'                  => 're_123',
				'status'              => 'succeeded',
				'balance_transaction' => array( 'id' => 'txn_123' ),
			);
			$effect_applier     = wc_get_container()->get( WooPaymentsOrderEffectApplier::class );
			$provider           = new class( new PaymentOutcome( PaymentOutcome::STATUS_COMPLETED, 're_123' ), $effect_applier, $provider_result ) extends RecordingProvider implements ProviderOperationEffectApplierInterface {
				/**
				 * WooPayments effect applier.
				 *
				 * @var WooPaymentsOrderEffectApplier
				 */
				private WooPaymentsOrderEffectApplier $effect_applier;

				/**
				 * Provider refund result.
				 *
				 * @var array<string,mixed>
				 */
				private array $provider_result;

				/**
				 * Constructor.
				 *
				 * @param PaymentOutcome                $outcome         Provider outcome.
				 * @param WooPaymentsOrderEffectApplier $effect_applier WooPayments effect applier.
				 * @param array<string,mixed>           $provider_result Provider refund result.
				 */
				public function __construct( PaymentOutcome $outcome, WooPaymentsOrderEffectApplier $effect_applier, array $provider_result ) {
					parent::__construct( $outcome );
					$this->effect_applier  = $effect_applier;
					$this->provider_result = $provider_result;
				}

				/**
				 * Apply the real WooPayments refund effects.
				 *
				 * @param PaymentOperationContext $context   Payment context.
				 * @param PaymentOutcome          $outcome   Provider outcome.
				 * @param string                  $operation Operation name.
				 * @return PaymentOutcome
				 */
				public function apply_operation_effects( PaymentOperationContext $context, PaymentOutcome $outcome, string $operation ): PaymentOutcome {
					unset( $operation );

					return $this->effect_applier->apply( $context, $outcome, WooPaymentsOrderEffectPlan::for_refund( $this->provider_result ) );
				}
			};
			$processing_service = wc_get_container()->get( PaymentProcessingService::class );

			$this->assertTrue( $processing_service->process_refund( PaymentOperationContext::for_refund( $order, WooPaymentsPersistenceVocabulary::GATEWAY_ID, 4.00, 'Requested by customer' ), $provider ) );
			$this->assertInstanceOf( WC_Order_Refund::class, $refund );
			$synchronous_notes = array_values(
				array_filter(
					wc_get_order_notes( array( 'order_id' => $order->get_id() ) ),
					static fn( $note ): bool => str_contains( $note->content, 're_123' )
				)
			);
			$this->assertCount( 1, $synchronous_notes );
			$synchronous_note = $synchronous_notes[0]->content;

			$this->install_test_translations(
				array(
					'woocommerce'          => array(
						'A refund of %1$s %5$s using %2$s. Reason: %3$s. (<code>%4$s</code>)' => 'Eine Rueckerstattung von %1$s %5$s mit %2$s. Grund: %3$s. (<code>%4$s</code>)',
						'was successfully processed' => 'wurde erfolgreich verarbeitet',
					),
					'woocommerce-payments' => array(
						'A refund of %1$s %5$s using %2$s. Reason: %3$s. (<code>%4$s</code>)' => 'Eine Rueckerstattung von %1$s %5$s mit %2$s. Grund: %3$s. (<code>%4$s</code>)',
						'was successfully processed' => 'wurde erfolgreich verarbeitet',
					),
				)
			);
			switch_to_locale( 'de_DE' );

			$this->sut->process( 'charge.refunded', $this->get_successful_refund_charge() );

			$order = wc_get_order( $order->get_id() );
			$this->assertInstanceOf( WC_Order::class, $order );
			$this->assertCount( 1, $order->get_refunds() );

			$refund_notes = array_values(
				array_filter(
					wc_get_order_notes( array( 'order_id' => $order->get_id() ) ),
					static fn( $note ): bool => str_contains( $note->content, 're_123' )
				)
			);
			$this->assertCount( 1, $refund_notes );
			$this->assertSame( $synchronous_note, $refund_notes[0]->content );
			$this->assertSame( hash( 'sha256', 'refund:re_123:created_successful' ), get_comment_meta( $refund_notes[0]->id, WooPaymentsPersistenceVocabulary::NOTE_IDENTITY_META_KEY, true ) );
		} finally {
			false === $previous_enabled_currencies
				? delete_option( 'wcpay_multi_currency_enabled_currencies' )
				: update_option( 'wcpay_multi_currency_enabled_currencies', $previous_enabled_currencies );
		}
	}

	/**
	 * @testdox A succeeded charge.refund.updated for a linked refund adopts the WooPayments extension's German refund note without adding the Core-catalog rendering.
	 */
	public function test_german_woopayments_extension_refund_note_followed_by_webhook_converges(): void {
		$previous_enabled_currencies = get_option( 'wcpay_multi_currency_enabled_currencies', false );
		update_option( '_wcpay_feature_customer_multi_currency', '0' );
		update_option( 'wcpay_multi_currency_enabled_currencies', array( 'EUR' ) );
		$this->install_test_translations(
			array(
				'woocommerce-payments' => array(
					'A refund of %1$s %5$s using %2$s. Reason: %3$s. (<code>%4$s</code>)' => 'Eine Rueckerstattung von %1$s %5$s mit %2$s. Grund: %3$s. (<code>%4$s</code>)',
					'was successfully processed' => 'wurde erfolgreich verarbeitet',
				),
			)
		);
		switch_to_locale( 'de_DE' );

		try {
			$order  = $this->create_refundable_order();
			$refund = $this->create_local_refund( $order );
			$refund->update_meta_data( '_wcpay_refund_id', 're_123' );
			$refund->save_meta_data();
			$order->update_meta_data( '_wcpay_refund_status', 'successful' );
			$plugin_note = $this->get_extension_created_refund_note();
			$order->add_order_note( $plugin_note );
			$order->save();

			// The Refund object charge.refund.updated carries (https://docs.stripe.com/api/refunds/object), with the values of
			// get_successful_refund_charge()'s refund.
			$this->sut->process(
				'charge.refund.updated',
				array(
					'id'                  => 're_123',
					'charge'              => 'ch_123',
					'amount'              => 400,
					'currency'            => 'usd',
					'status'              => 'succeeded',
					'balance_transaction' => 'txn_123',
				)
			);

			$order = wc_get_order( $order->get_id() );
			$this->assertInstanceOf( WC_Order::class, $order );
			$refunds = $order->get_refunds();
			$this->assertCount( 1, $refunds );
			$this->assertSame( 're_123', $refunds[0]->get_meta( '_wcpay_refund_id', true ) );
			$this->assertSame( 'successful', $order->get_meta( '_wcpay_refund_status', true ) );
			$refund_notes = array_values(
				array_filter(
					wc_get_order_notes( array( 'order_id' => $order->get_id() ) ),
					static fn( $note ): bool => str_contains( $note->content, 're_123' )
				)
			);

			$this->assertCount( 1, $refund_notes );
			$this->assertSame( $plugin_note, $refund_notes[0]->content );
			$this->assertNotSame( '', get_comment_meta( $refund_notes[0]->id, '_wc_woopayments_note_identity', true ) );
		} finally {
			false === $previous_enabled_currencies
				? delete_option( 'wcpay_multi_currency_enabled_currencies' )
				: update_option( 'wcpay_multi_currency_enabled_currencies', $previous_enabled_currencies );
		}
	}

	/**
	 * @testdox A $event_status charge.refunded for a linked refund whose created note is on the order ($existing_note) changes nothing.
	 *
	 * The note marks the refund complete: the gateway's refund, an earlier delivery or the WooPayments extension before the
	 * switch wrote it, after the refund status and metadata. The extension's note carries no identity and matches by its
	 * wording (client 11.1.0 class-wc-payments-order-service.php:1935-1939). Rows from 2224ece1012 and acaf26865d5 (identity
	 * notes) and cf039e3d930 (the extension's note).
	 *
	 * @dataProvider provider_linked_refund_with_its_created_note
	 *
	 * @param string $event_status  Refund status the event carries.
	 * @param string $existing_note Created note on the order: an identity note type (created_successful, created_pending) or 'extension'.
	 * @param string $refund_status Order refund status before the event.
	 */
	public function test_charge_refunded_for_a_linked_refund_with_its_created_note_changes_nothing( string $event_status, string $existing_note, string $refund_status ): void {
		$order  = $this->create_refundable_order();
		$refund = $this->create_local_refund( $order );
		$refund->update_meta_data( '_wcpay_refund_id', 're_123' );
		$refund->update_meta_data( '_wcpay_refund_transaction_id', 'txn_existing' );
		$refund->save_meta_data();
		$order->update_meta_data( '_wcpay_refund_status', $refund_status );
		$order->save();
		if ( 'extension' === $existing_note ) {
			$order->add_order_note( $this->get_extension_created_refund_note() );
		} else {
			// The created note the gateway path writes when it links the refund, with the same identity.
			wc_get_container()->get( WooPaymentsOrderNoteService::class )->add_note_once( $order, 'Refund re_123 recorded.', 'refund:re_123:' . $existing_note );
		}
		$note_count = count( wc_get_order_notes( array( 'order_id' => $order->get_id() ) ) );

		$charge                                 = $this->get_successful_refund_charge();
		$charge['refunds']['data'][0]['status'] = $event_status;
		$this->sut->process( 'charge.refunded', $charge );

		$order   = wc_get_order( $order->get_id() );
		$refunds = $order->get_refunds();
		$this->assertCount( 1, $refunds );
		$this->assertSame( $refund_status, $order->get_meta( '_wcpay_refund_status', true ) );
		$this->assertSame( 'txn_existing', $refunds[0]->get_meta( '_wcpay_refund_transaction_id', true ) );
		$this->assertCount( $note_count, wc_get_order_notes( array( 'order_id' => $order->get_id() ) ) );
	}

	/**
	 * Linked refunds whose created note is already on the order.
	 *
	 * @return array<string,array{0:string,1:string,2:string}>
	 */
	public function provider_linked_refund_with_its_created_note(): array {
		return array(
			'identity note, succeeded event, successful refund' => array( 'succeeded', 'created_successful', 'successful' ),
			'identity note, pending event, successful refund'   => array( 'pending', 'created_successful', 'successful' ),
			'identity note, succeeded event, pending refund'    => array( 'succeeded', 'created_pending', 'pending' ),
			'extension note, pending event, successful refund'  => array( 'pending', 'extension', 'successful' ),
		);
	}

	/**
	 * @testdox A successful synchronous refund followed by a `charge.refund.updated` succeeded webhook keeps one note.
	 *
	 * `:102` (`test_successful_synchronous_refund_followed_by_webhook_converges`)
	 * proves this convergence for `charge.refunded`, the full-charge event;
	 * this proves it for `charge.refund.updated`, the bare-refund event, which
	 * that test does not exercise (client `os:1937`, `wh:355-359`). Recorded
	 * webhook body: REC-5a R-c (`Fixtures/platform-refund-updated-events.json`,
	 * pair `afterpay_clearpay_refund_updated_succeeded`), the primary
	 * redirect-method refund-updated event local WPCOM forwarded for this
	 * recording (`data/rec-5a-refunds.md`).
	 */
	public function test_successful_synchronous_refund_followed_by_refund_updated_webhook_keeps_one_note(): void {
		$refund_object = $this->load_recorded_refund_updated_event( 'afterpay_clearpay_refund_updated_succeeded' );
		$charge_id     = (string) $refund_object['charge'];
		$refund_id     = (string) $refund_object['id'];
		$amount        = ( (int) $refund_object['amount'] ) / 100;
		$amount_string = sprintf( '%.2f', $amount );
		$reason        = (string) $refund_object['reason'];

		$order = wc_create_order();
		$this->assertInstanceOf( WC_Order::class, $order );
		$order->set_payment_method( WooPaymentsPersistenceVocabulary::GATEWAY_ID );
		$order->set_currency( strtoupper( (string) $refund_object['currency'] ) );
		$order->set_total( $amount_string );
		$order->set_status( 'processing' );
		$order->update_meta_data( '_charge_id', $charge_id );
		$order->save();

		$refund = wc_create_refund(
			array(
				'amount'   => $amount_string,
				'reason'   => $reason,
				'order_id' => $order->get_id(),
			)
		);
		$this->assertInstanceOf( WC_Order_Refund::class, $refund );

		$provider_result    = array(
			'id'                  => $refund_id,
			'status'              => 'succeeded',
			'balance_transaction' => $refund_object['balance_transaction'],
		);
		$effect_applier     = wc_get_container()->get( WooPaymentsOrderEffectApplier::class );
		$provider           = new class( new PaymentOutcome( PaymentOutcome::STATUS_COMPLETED, $refund_id ), $effect_applier, $provider_result ) extends RecordingProvider implements ProviderOperationEffectApplierInterface {
			/**
			 * WooPayments effect applier.
			 *
			 * @var WooPaymentsOrderEffectApplier
			 */
			private WooPaymentsOrderEffectApplier $effect_applier;

			/**
			 * Provider refund result.
			 *
			 * @var array<string,mixed>
			 */
			private array $provider_result;

			/**
			 * Constructor.
			 *
			 * @param PaymentOutcome                $outcome         Provider outcome.
			 * @param WooPaymentsOrderEffectApplier $effect_applier WooPayments effect applier.
			 * @param array<string,mixed>           $provider_result Provider refund result.
			 */
			public function __construct( PaymentOutcome $outcome, WooPaymentsOrderEffectApplier $effect_applier, array $provider_result ) {
				parent::__construct( $outcome );
				$this->effect_applier  = $effect_applier;
				$this->provider_result = $provider_result;
			}

			/**
			 * Apply the real WooPayments refund effects.
			 *
			 * @param PaymentOperationContext $context   Payment context.
			 * @param PaymentOutcome          $outcome   Provider outcome.
			 * @param string                  $operation Operation name.
			 * @return PaymentOutcome
			 */
			public function apply_operation_effects( PaymentOperationContext $context, PaymentOutcome $outcome, string $operation ): PaymentOutcome {
				unset( $operation );

				return $this->effect_applier->apply( $context, $outcome, WooPaymentsOrderEffectPlan::for_refund( $this->provider_result ) );
			}
		};
		$processing_service = wc_get_container()->get( PaymentProcessingService::class );

		$this->assertTrue( $processing_service->process_refund( PaymentOperationContext::for_refund( $order, WooPaymentsPersistenceVocabulary::GATEWAY_ID, $amount, $reason ), $provider ) );

		$synchronous_notes = array_values(
			array_filter(
				wc_get_order_notes( array( 'order_id' => $order->get_id() ) ),
				static fn( $note ): bool => str_contains( $note->content, $refund_id )
			)
		);
		$this->assertCount( 1, $synchronous_notes, 'The synchronous refund leg must journal exactly one note.' );

		$this->sut->process( 'charge.refund.updated', $refund_object );

		$order = wc_get_order( $order->get_id() );
		$this->assertInstanceOf( WC_Order::class, $order );
		$this->assertSame( 'successful', $order->get_meta( '_wcpay_refund_status', true ) );

		$refund_notes = array_values(
			array_filter(
				wc_get_order_notes( array( 'order_id' => $order->get_id() ) ),
				static fn( $note ): bool => str_contains( $note->content, $refund_id )
			)
		);
		$this->assertCount( 1, $refund_notes, 'The refund.updated webhook must not add a second note once the synchronous leg already recorded one.' );
	}

	/**
	 * @testdox A failed refund webhook deletes only the refund row the synchronous refund linked, never an older manual refund.
	 *
	 * Client 11.1.0 links the provider refund to the order's newest refund (class-wc-payment-gateway-wcpay.php:3003,
	 * class-wc-payments-utils.php:1080-1096), finds the row for a refund update by `_wcpay_refund_id`
	 * (class-wc-payments-webhook-processing-service.php:327-342) and deletes only that row when the refund fails
	 * (class-wc-payments-webhook-processing-service.php:346-350, class-wc-payments-order-service.php:1964-1967).
	 */
	public function test_failed_refund_webhook_deletes_only_the_linked_refund_row(): void {
		$order         = $this->create_refundable_order();
		$manual_refund = $this->create_local_refund( $order );
		$manual_refund->set_date_created( time() - DAY_IN_SECONDS );
		$manual_refund->save();

		$refund         = $this->create_local_refund( $order );
		$effect_applier = wc_get_container()->get( WooPaymentsOrderEffectApplier::class );
		$provider       = new class( new PaymentOutcome( PaymentOutcome::STATUS_COMPLETED, 're_123' ), $effect_applier ) extends RecordingProvider implements ProviderOperationEffectApplierInterface {
			/**
			 * WooPayments effect applier.
			 *
			 * @var WooPaymentsOrderEffectApplier
			 */
			private WooPaymentsOrderEffectApplier $effect_applier;

			/**
			 * Constructor.
			 *
			 * @param PaymentOutcome                $outcome        Provider outcome.
			 * @param WooPaymentsOrderEffectApplier $effect_applier WooPayments effect applier.
			 */
			public function __construct( PaymentOutcome $outcome, WooPaymentsOrderEffectApplier $effect_applier ) {
				parent::__construct( $outcome );
				$this->effect_applier = $effect_applier;
			}

			/**
			 * Apply the real WooPayments refund effects.
			 *
			 * @param PaymentOperationContext $context   Payment context.
			 * @param PaymentOutcome          $outcome   Provider outcome.
			 * @param string                  $operation Operation name.
			 * @return PaymentOutcome
			 */
			public function apply_operation_effects( PaymentOperationContext $context, PaymentOutcome $outcome, string $operation ): PaymentOutcome {
				unset( $operation );

				return $this->effect_applier->apply(
					$context,
					$outcome,
					WooPaymentsOrderEffectPlan::for_refund(
						array(
							'id'                  => 're_123',
							'status'              => 'succeeded',
							'balance_transaction' => 'txn_123',
						)
					)
				);
			}
		};

		$this->assertTrue( wc_get_container()->get( PaymentProcessingService::class )->process_refund( PaymentOperationContext::for_refund( $order, WooPaymentsPersistenceVocabulary::GATEWAY_ID, 4.00, 'Requested by customer' ), $provider ) );

		$this->sut->process(
			'charge.refund.updated',
			array(
				'id'             => 're_123',
				'charge'         => 'ch_123',
				'amount'         => 400,
				'currency'       => 'usd',
				'status'         => 'failed',
				'failure_reason' => 'lost_or_stolen_card',
			)
		);

		$this->assertFalse( wc_get_order( $refund->get_id() ), 'The refund row linked to the failed provider refund must be deleted.' );
		$manual_refund = wc_get_order( $manual_refund->get_id() );
		$this->assertInstanceOf( WC_Order_Refund::class, $manual_refund, 'An older manual refund of the same amount must survive the failed refund.' );
		$this->assertSame( '', $manual_refund->get_meta( '_wcpay_refund_id', true ) );
	}

	/**
	 * Load a REC-5a R-c recorded `charge.refund.updated` event object by pair key.
	 *
	 * @param string $pair REC-5a R-c fixture pair key.
	 * @return array<string,mixed>
	 */
	private function load_recorded_refund_updated_event( string $pair ): array {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reads a local immutable test fixture.
		$fixture = file_get_contents( __DIR__ . '/../Fixtures/platform-refund-updated-events.json' );
		$this->assertIsString( $fixture );
		$decoded = json_decode( $fixture, true );
		$this->assertIsArray( $decoded );

		foreach ( $decoded['entries'] as $entry ) {
			if ( is_array( $entry ) && ( $entry['pair'] ?? '' ) === $pair ) {
				return $entry['body']['data']['object'];
			}
		}

		$this->fail( "REC-5a R-c fixture has no entry for pair '$pair'." );
	}

	/**
	 * @testdox A charge.refunded event on a charge that is not the order's payment is recorded: one note and one warning line, no refund, the order's status and charge kept.
	 *
	 * Client 11.1.0 refunds the charge's order whatever its gateway (class-wc-payments-webhook-processing-service.php:1108-1119);
	 * here the refund is recorded, because the charge is not the order's payment. The charge carries its `payment_intent`
	 * (https://docs.stripe.com/api/charges/object#charge_object-payment_intent) and, for a charge no order holds, the store's
	 * `metadata` with `order_id` and `order_key` (https://docs.stripe.com/api/charges/object#charge_object-metadata).
	 *
	 * @dataProvider provider_charge_refunded_on_another_charge
	 *
	 * @param callable $create_order          Creates the order the charge resolves to.
	 * @param string   $charge_id             Refunded charge ID.
	 * @param string   $intent_id             The charge's payment intent.
	 * @param bool     $metadata_names_order  Whether the charge's metadata names the order.
	 * @param int      $refund_amount         Refund amount in minor units.
	 * @param string   $expected_charge_meta  `_charge_id` on the order after the event.
	 * @param string   $order_payment_method  Order payment method named by the warning line.
	 */
	public function test_charge_refunded_on_another_charge_is_recorded( callable $create_order, string $charge_id, string $intent_id, bool $metadata_names_order, int $refund_amount, string $expected_charge_meta, string $order_payment_method ): void {
		$order                                  = $create_order( $this );
		$charge                                 = $this->get_successful_refund_charge();
		$charge['id']                           = $charge_id;
		$charge['payment_intent']               = $intent_id;
		$charge['refunds']['data'][0]['amount'] = $refund_amount;
		if ( $metadata_names_order ) {
			$charge['metadata'] = array(
				'order_id'  => (string) $order->get_id(),
				'order_key' => $order->get_order_key(),
			);
		}
		$logger = RecordingWcLogger::install();

		$this->sut->process( 'charge.refunded', $charge );

		$order = wc_get_order( $order->get_id() );
		$this->assertInstanceOf( WC_Order::class, $order );
		$this->assertCount( 0, $order->get_refunds() );
		$this->assertSame( 'processing', $order->get_status() );
		$this->assertSame( $expected_charge_meta, $order->get_meta( '_charge_id', true ) );
		$this->assertSame( '', $order->get_meta( '_wcpay_refund_status', true ) );
		$record_notes = $this->get_notes_containing( $order, 'No refund was added to the order.' );
		$this->assertCount( 1, $record_notes );
		$this->assertStringContainsString( 're_123', $record_notes[0]->content );
		$this->assertStringContainsString( $charge_id, $record_notes[0]->content );
		$record_lines = array_keys(
			array_filter(
				$logger->lines,
				static fn( array $line ): bool => 'warning' === $line[0] && 'other charge recorded: order ' . $order->get_id() . ', charge.refunded, charge ' . $charge_id . ', order payment method ' . $order_payment_method === $line[1]
			)
		);
		$this->assertCount( 1, $record_lines );
		$this->assertSame( 'woopayments', $logger->contexts[ $record_lines[0] ]['source'] );
	}

	/**
	 * Refunds of a charge that is not the order's payment.
	 *
	 * @return array<string,array{0:callable,1:string,2:string,3:bool,4:int,5:string,6:string}>
	 */
	public function provider_charge_refunded_on_another_charge(): array {
		$gateway_id = WooPaymentsPersistenceVocabulary::GATEWAY_ID;

		return array(
			'order paid by another gateway'             => array(
				static fn( self $test ): WC_Order => $test->create_order_paid_by_another_gateway( 'cod', 'ch_cod' ),
				'ch_cod',
				'pi_cod',
				false,
				1000,
				'ch_cod',
				'cod',
			),
			'order paid by another intent, holding the charge' => array(
				static function ( self $test ): WC_Order {
					$order = $test->create_refundable_order();
					$order->update_meta_data( '_charge_id', 'ch_second' );
					$order->save();
					return $order;
				},
				'ch_second',
				'pi_second',
				false,
				400,
				'ch_second',
				$gateway_id,
			),
			'second charge no order holds, by metadata' => array(
				static fn( self $test ): WC_Order => $test->create_refundable_order(),
				'ch_second',
				'pi_second',
				true,
				400,
				'ch_123',
				$gateway_id,
			),
			'charge no order holds, the order\'s own intent' => array(
				static fn( self $test ): WC_Order => $test->create_refundable_order(),
				'ch_unheld',
				'pi_123',
				true,
				400,
				'ch_123',
				$gateway_id,
			),
		);
	}

	/**
	 * @testdox A charge.refunded event found by charge metadata adds the refund row when the order saved the charge as its payment before the event claimed the lock.
	 */
	public function test_charge_refunded_found_by_metadata_applies_when_the_order_saved_the_charge_before_the_claim(): void {
		UncachedOrderWriter::enable_hpos_data_caching();
		$order = wc_create_order();
		$this->assertInstanceOf( WC_Order::class, $order );
		$order->set_payment_method( WooPaymentsPersistenceVocabulary::GATEWAY_ID );
		$order->set_currency( 'USD' );
		$order->set_total( '10.00' );
		$order->save();
		$store                    = $this->create_lock_that_saves_the_payment_on_claim( 'pi_late', 'ch_late', 'processing' );
		$handler                  = new WooPaymentsRefundEventHandler();
		$charge                   = $this->get_successful_refund_charge();
		$charge['id']             = 'ch_late';
		$charge['payment_intent'] = 'pi_late';
		$charge['metadata']       = array(
			'order_id'  => (string) $order->get_id(),
			'order_key' => $order->get_order_key(),
		);
		$handler->init( $store, new WooPaymentsPersistenceVocabulary(), wc_get_container()->get( WooPaymentsEventOrderResolver::class ), wc_get_container()->get( WooPaymentsOtherChargeRecorder::class ), wc_get_container()->get( WooPaymentsAccountService::class ) );

		$handler->process( 'charge.refunded', $charge );

		$this->assertTrue( $store->hook_ran, 'The payment must be saved inside the claim.' );
		$order = wc_get_order( $order->get_id() );
		$this->assertInstanceOf( WC_Order::class, $order );
		$refunds = $order->get_refunds();
		$this->assertCount( 1, $refunds );
		$this->assertSame( 're_123', $refunds[0]->get_meta( '_wcpay_refund_id', true ) );
		$this->assertCount( 0, $this->get_notes_containing( $order, 'No refund was added to the order.' ) );
	}

	/**
	 * @testdox A recorded refund whose note is not saved fails without a log line and releases the lock, and its redelivery records it once.
	 */
	public function test_recorded_refund_whose_note_is_not_saved_fails_and_records_on_redelivery(): void {
		$order      = $this->create_order_paid_by_another_gateway( 'cod', 'ch_cod' );
		$notes      = new class() extends WooPaymentsOrderNoteService {
			/**
			 * Insertions left to fail.
			 *
			 * @var int
			 */
			public int $failures = 1;

			/**
			 * Fail the first insertion without throwing, as a comment insert that returns no ID does.
			 *
			 * @param WC_Order      $order            Order object.
			 * @param string        $note             Note content.
			 * @param string        $identity         Stable private note identity.
			 * @param string[]      $equivalent_notes Equivalent note renderings.
			 * @param callable|null $before_add       Callback invoked before insertion.
			 * @return bool
			 */
			public function add_note_once( WC_Order $order, string $note, string $identity = '', array $equivalent_notes = array(), ?callable $before_add = null ): bool {
				if ( 0 < $this->failures ) {
					--$this->failures;
					return false;
				}

				return parent::add_note_once( $order, $note, $identity, $equivalent_notes, $before_add );
			}
		};
		$recorder   = new WooPaymentsOtherChargeRecorder();
		$vocabulary = new WooPaymentsPersistenceVocabulary();
		$handler    = new WooPaymentsRefundEventHandler();
		$notes->init( wc_get_container()->get( OrderPaymentNotes::class ), wc_get_container()->get( WooPaymentsPersistenceVocabulary::class ) );
		$recorder->init( $notes );
		$handler->init( wc_get_container()->get( OrderPaymentLock::class ), $vocabulary, wc_get_container()->get( WooPaymentsEventOrderResolver::class ), $recorder, wc_get_container()->get( WooPaymentsAccountService::class ) );
		$charge                   = $this->get_successful_refund_charge();
		$charge['id']             = 'ch_cod';
		$charge['payment_intent'] = 'pi_cod';
		$logger                   = RecordingWcLogger::install();

		$failure = null;
		try {
			$handler->process( 'charge.refunded', $charge );
		} catch ( \RuntimeException $exception ) {
			$failure = $exception;
		}

		$this->assertInstanceOf( \RuntimeException::class, $failure );
		$this->assertStringContainsString( 'Could not save the other-charge note for charge.refunded re_123', $failure->getMessage() );
		$this->assertFalse( $this->is_order_payment_lock_held_for( $order, $vocabulary, 'refund_webhook_re_123' ) );
		$this->assertCount( 0, $this->get_notes_containing( wc_get_order( $order->get_id() ), 'No refund was added to the order.' ) );
		$this->assertCount( 0, array_filter( $logger->lines, static fn( array $line ): bool => 0 === strpos( $line[1], 'other charge recorded:' ) ) );

		$handler->process( 'charge.refunded', $charge );

		$this->assertCount( 1, $this->get_notes_containing( wc_get_order( $order->get_id() ), 'No refund was added to the order.' ) );
		$this->assertCount( 1, array_filter( $logger->lines, static fn( array $line ): bool => 0 === strpos( $line[1], 'other charge recorded:' ) ) );
	}

	/**
	 * Build an order payment lock whose first claim saves the order's payment, as a checkout finishing just before it would.
	 *
	 * The checkout writes straight to the database, leaving this request's caches as they were.
	 *
	 * @param string $intent_id Payment intent the checkout saves.
	 * @param string $charge_id Charge the checkout saves.
	 * @param string $status    Order status the checkout leaves.
	 * @return OrderPaymentLockWithClaimHook
	 */
	private function create_lock_that_saves_the_payment_on_claim( string $intent_id, string $charge_id, string $status ): OrderPaymentLockWithClaimHook {
		return new OrderPaymentLockWithClaimHook(
			static function ( WC_Order $order ) use ( $intent_id, $charge_id, $status ): void {
				UncachedOrderWriter::write(
					$order->get_id(),
					array(
						'status'         => $status,
						'transaction_id' => $intent_id,
					),
					array(
						'_intent_id' => $intent_id,
						'_charge_id' => $charge_id,
					)
				);
			}
		);
	}

	/**
	 * @testdox A $event_type event refused before any write throws a RuntimeException and leaves the order without a refund or refund status.
	 *
	 * A RuntimeException goes through the reliability service's retry path; an InvalidArgumentException would be dropped. The
	 * ingestor passes the event object to this handler unchanged and does not catch.
	 *
	 * @dataProvider provider_refund_events_refused_before_any_write
	 *
	 * @param string   $event_type       Refund event type.
	 * @param callable $change_event     Changes the event object built for the order.
	 * @param string   $expected_message Fragment of the exception message.
	 */
	public function test_refund_events_refused_before_any_write( string $event_type, callable $change_event, string $expected_message ): void {
		$order = $this->create_refundable_order();

		$thrown = null;
		try {
			$this->sut->process( $event_type, $change_event( $this->get_refund_event_object( $event_type, $order ), $order ) );
		} catch ( \RuntimeException $exception ) {
			$thrown = $exception;
		}

		$this->assertInstanceOf( \RuntimeException::class, $thrown );
		$this->assertStringContainsString( $expected_message, $thrown->getMessage() );
		$order = wc_get_order( $order->get_id() );
		$this->assertInstanceOf( WC_Order::class, $order );
		$this->assertCount( 0, $order->get_refunds() );
		$this->assertSame( '', $order->get_meta( '_wcpay_refund_status', true ) );
	}

	/**
	 * Refund events refused before any write.
	 *
	 * @return array<string,array{0:string,1:callable,2:string}>
	 */
	public function provider_refund_events_refused_before_any_write(): array {
		$refunded = 'charge.refunded';
		$updated  = 'charge.refund.updated';

		return array(
			'captured missing'                   => array(
				$refunded,
				static function ( array $charge ): array {
					unset( $charge['captured'] );
					return $charge;
				},
				'missing required field: captured',
			),
			'captured malformed'                 => array(
				$refunded,
				static fn( array $charge ): array => array_merge( $charge, array( 'captured' => 'yes' ) ),
				'missing required field: captured',
			),
			'refund above the order total'       => array(
				$refunded,
				static function ( array $charge ): array {
					$charge['refunds']['data'][0]['amount'] = 1500;
					return $charge;
				},
				'refund amount is not valid',
			),
			'negative refund amount'             => array(
				$refunded,
				static function ( array $charge ): array {
					$charge['refunds']['data'][0]['amount'] = -400;
					return $charge;
				},
				'refund amount is not valid',
			),
			'no refund data'                     => array(
				$refunded,
				static function ( array $charge ): array {
					$charge['refunds']['data'] = array();
					return $charge;
				},
				'missing refund data',
			),
			'refund ID empty'                    => array(
				$refunded,
				static function ( array $charge ): array {
					$charge['refunds']['data'][0]['id'] = '';
					return $charge;
				},
				'missing required field: id',
			),
			'charge no order holds, no metadata' => array(
				$refunded,
				static fn( array $charge ): array => array_merge(
					$charge,
					array(
						'id'       => 'ch_unknown',
						'metadata' => array(
							'order_id'  => '',
							'order_key' => '',
						),
					)
				),
				'Could not find WooPayments order via charge ID',
			),
			'held charge, wrong order key'       => array(
				$refunded,
				static fn( array $charge, WC_Order $order ): array => array_merge(
					$charge,
					array(
						'metadata' => array(
							'order_id'  => (string) $order->get_id(),
							'order_key' => 'wc_order_wrong_key',
						),
					)
				),
				'Could not find WooPayments order via charge ID',
			),
			'unheld charge, another order key'   => array(
				$refunded,
				static fn( array $charge, WC_Order $order ): array => array_merge(
					$charge,
					array(
						'id'       => 'ch_second',
						'metadata' => array(
							'order_id'  => (string) $order->get_id(),
							'order_key' => 'wc_order_another_site',
						),
					)
				),
				'Could not find WooPayments order via charge ID: ch_second',
			),
			'unknown update status'              => array(
				$updated,
				static fn( array $refund ): array => array_merge( $refund, array( 'status' => 'requires_action' ) ),
				'Invalid refund update status',
			),
			'update status empty'                => array(
				$updated,
				static fn( array $refund ): array => array_merge( $refund, array( 'status' => '' ) ),
				'missing required field: status',
			),
		);
	}

	/**
	 * Build a refund event object for a refundable order, as the WooPayments ingestor tests did.
	 *
	 * charge.refunded carries the Charge with its refunds list and the store's metadata. Client 11.1.0 reads id, status,
	 * captured, amount, currency and refunds.data[0], and resolves the order by the charge ID
	 * (class-wc-payments-webhook-processing-service.php:1079-1143); the metadata's order key is read only by native, whose
	 * order resolver refuses an order with another key. charge.refund.updated carries the Refund; the client reads id,
	 * charge, amount, currency and status, and balance_transaction and failure_reason when present (:300-353).
	 *
	 * @param string   $event_type Refund event type.
	 * @param WC_Order $order      Order holding the charge.
	 * @return array<string,mixed>
	 */
	private function get_refund_event_object( string $event_type, WC_Order $order ): array {
		$charge_id = (string) $order->get_meta( '_charge_id', true );
		if ( 'charge.refund.updated' === $event_type ) {
			return array(
				'id'       => 're_123',
				'charge'   => $charge_id,
				'amount'   => 400,
				'currency' => 'usd',
				'status'   => 'failed',
			);
		}

		return array(
			'id'       => $charge_id,
			'status'   => 'succeeded',
			'amount'   => 1000,
			'currency' => 'usd',
			'captured' => true,
			'metadata' => array(
				'order_id'  => (string) $order->get_id(),
				'order_key' => $order->get_order_key(),
			),
			'refunds'  => array(
				'data' => array(
					array(
						'id'                  => 're_123',
						'status'              => 'succeeded',
						'amount'              => 400,
						'currency'            => 'usd',
						'reason'              => 'requested_by_customer',
						'balance_transaction' => 'txn_123',
					),
				),
			),
		);
	}

	/**
	 * @testdox A failed charge.refund.updated on a charge recorded on an order another gateway paid keeps its refund row and status, and adds one note.
	 *
	 * Refund object fields: https://docs.stripe.com/api/refunds/object.
	 */
	public function test_failed_refund_update_on_another_charge_changes_nothing_but_a_note(): void {
		$order  = $this->create_order_paid_by_another_gateway( 'cod', 'ch_other' );
		$refund = wc_create_refund(
			array(
				'amount'   => '10.00',
				'order_id' => $order->get_id(),
			)
		);
		$this->assertInstanceOf( WC_Order_Refund::class, $refund );
		$refund->update_meta_data( '_wcpay_refund_id', 're_other' );
		$refund->save();
		$this->assertSame( 'refunded', wc_get_order( $order->get_id() )->get_status() );

		$this->sut->process(
			'charge.refund.updated',
			array(
				'id'             => 're_other',
				'charge'         => 'ch_other',
				'payment_intent' => 'pi_other',
				'amount'         => 1000,
				'currency'       => 'usd',
				'status'         => 'failed',
				'failure_reason' => 'lost_or_stolen_card',
			)
		);

		$order = wc_get_order( $order->get_id() );
		$this->assertInstanceOf( WC_Order::class, $order );
		$this->assertInstanceOf( WC_Order_Refund::class, wc_get_order( $refund->get_id() ), 'The refund row must stay.' );
		$this->assertSame( 'refunded', $order->get_status() );
		$this->assertSame( '', $order->get_meta( '_wcpay_refund_status', true ) );
		$record_notes = $this->get_notes_containing( $order, 'which does not pay this order, is now failed.' );
		$this->assertCount( 1, $record_notes );
		$this->assertStringContainsString( 're_other', $record_notes[0]->content );
	}

	/**
	 * @testdox A succeeded charge.refund.updated on a charge recorded on an order another gateway paid adds one note.
	 *
	 * Refund object fields: https://docs.stripe.com/api/refunds/object.
	 */
	public function test_succeeded_refund_update_on_another_charge_adds_one_note(): void {
		$order = $this->create_order_paid_by_another_gateway( 'cod', 'ch_other' );

		$this->sut->process(
			'charge.refund.updated',
			array(
				'id'             => 're_other',
				'charge'         => 'ch_other',
				'payment_intent' => 'pi_other',
				'amount'         => 400,
				'currency'       => 'usd',
				'status'         => 'succeeded',
			)
		);

		$order = wc_get_order( $order->get_id() );
		$this->assertInstanceOf( WC_Order::class, $order );
		$this->assertCount( 0, $order->get_refunds() );
		$this->assertSame( '', $order->get_meta( '_wcpay_refund_status', true ) );
		$this->assertCount( 1, $this->get_notes_containing( $order, 'which does not pay this order, is now successful.' ) );
	}

	/**
	 * Create an order another gateway paid, holding a WooPayments charge recorded on it.
	 *
	 * @param string $payment_method Order payment method.
	 * @param string $charge_id      Recorded charge ID.
	 * @return WC_Order
	 */
	private function create_order_paid_by_another_gateway( string $payment_method, string $charge_id ): WC_Order {
		$order = wc_create_order();
		$this->assertInstanceOf( WC_Order::class, $order );
		$order->set_payment_method( $payment_method );
		$order->set_currency( 'USD' );
		$order->set_total( '10.00' );
		$order->set_status( 'processing' );
		$order->set_date_paid( time() );
		$order->update_meta_data( '_charge_id', $charge_id );
		$order->save();

		return $order;
	}

	/**
	 * Get an order's notes containing a text.
	 *
	 * @param WC_Order $order Order.
	 * @param string   $text  Text the note contains.
	 * @return array<int,object>
	 */
	private function get_notes_containing( WC_Order $order, string $text ): array {
		return array_values(
			array_filter(
				wc_get_order_notes( array( 'order_id' => $order->get_id() ) ),
				static fn( $note ): bool => false !== strpos( (string) $note->content, $text )
			)
		);
	}

	/**
	 * @testdox A charge.refunded event on the charge that paid the order adds the refund row (payment method $payment_method, transaction ID $transaction_id).
	 * @dataProvider own_charge_order_provider
	 *
	 * The event's `payment_intent` is the Stripe Charge field (https://docs.stripe.com/api/charges/object#charge_object-payment_intent).
	 *
	 * @param string $payment_method Order payment method ID.
	 * @param string $transaction_id Order transaction ID.
	 */
	public function test_charge_refunded_on_the_orders_own_charge_adds_the_refund_row( string $payment_method, string $transaction_id ): void {
		$order = $this->create_refundable_order();
		$order->set_payment_method( $payment_method );
		$order->set_transaction_id( $transaction_id );
		$order->save();
		$charge                   = $this->get_successful_refund_charge();
		$charge['payment_intent'] = 'pi_123';

		$this->sut->process( 'charge.refunded', $charge );

		$order = wc_get_order( $order->get_id() );
		$this->assertInstanceOf( WC_Order::class, $order );
		$refunds = $order->get_refunds();
		$this->assertCount( 1, $refunds );
		$this->assertSame( '-4.00', $refunds[0]->get_total() );
		$this->assertSame( 're_123', $refunds[0]->get_meta( '_wcpay_refund_id', true ) );
		$this->assertSame( 'successful', $order->get_meta( '_wcpay_refund_status', true ) );
	}

	/**
	 * Paid orders whose transaction ID names the refunded charge's payment.
	 *
	 * @return array<string,array{0:string,1:string}>
	 */
	public function own_charge_order_provider(): array {
		return array(
			'payment method changed by the merchant' => array( 'cod', 'pi_123' ),
			'transaction ID is the charge'           => array( WooPaymentsPersistenceVocabulary::GATEWAY_ID, 'ch_123' ),
		);
	}

	/**
	 * @testdox A charge.refunded retried after an error left its refund row behind records the refund once.
	 *
	 * wc_create_refund() deletes the refund it saved only when a hook throws an Exception (includes/wc-order-functions.php:749-754),
	 * so an Error from a woocommerce_order_refunded callback leaves the row, and the store retries the event.
	 */
	public function test_charge_refunded_retried_after_an_error_left_its_row_records_the_refund_once(): void {
		wc_get_container()->get( RefundRowCapture::class )->register();
		$order      = $this->create_refundable_order();
		$thrown     = 0;
		$throw_once = static function () use ( &$thrown ): void {
			if ( 0 === $thrown++ ) {
				throw new \Error( 'Refund hook failed.' );
			}
		};
		add_action( 'woocommerce_order_refunded', $throw_once );

		$caught = null;
		try {
			$this->sut->process( 'charge.refunded', $this->get_successful_refund_charge() );
		} catch ( \Error $error ) {
			$caught = $error;
		}
		$this->sut->process( 'charge.refunded', $this->get_successful_refund_charge() );
		remove_action( 'woocommerce_order_refunded', $throw_once );

		$this->assertInstanceOf( \Error::class, $caught, 'The first delivery fails after the refund row is saved.' );
		$order   = wc_get_order( $order->get_id() );
		$refunds = $order->get_refunds();
		$this->assertCount( 1, $refunds );
		$this->assertSame( '-4.00', $refunds[0]->get_total() );
		$this->assertSame( 're_123', $refunds[0]->get_meta( '_wcpay_refund_id', true ) );
		$this->assertSame( 'txn_123', $refunds[0]->get_meta( '_wcpay_refund_transaction_id', true ) );
		$this->assertSame( 'successful', $order->get_meta( '_wcpay_refund_status', true ) );
		$this->assertCount( 1, $this->get_notes_containing( $order, 're_123' ) );
	}

	/**
	 * @testdox A charge.refunded retried after its refund was $status records no refund and keeps the failed status.
	 * @testWith ["failed", "insufficient_funds"]
	 *           ["canceled", ""]
	 *
	 * The first charge.refunded delivery failed before it wrote anything, and the charge.refund.updated for the same refund
	 * landed before the store's retry. The Refund object fields are Stripe's (https://docs.stripe.com/api/refunds/object).
	 *
	 * @param string $status         Refund status the update reports.
	 * @param string $failure_reason Refund failure reason.
	 */
	public function test_charge_refunded_retried_after_its_refund_failed_records_no_refund( string $status, string $failure_reason ): void {
		$order  = $this->create_refundable_order();
		$update = array(
			'id'       => 're_123',
			'charge'   => 'ch_123',
			'amount'   => 400,
			'currency' => 'usd',
			'status'   => $status,
		);
		if ( '' !== $failure_reason ) {
			$update['failure_reason'] = $failure_reason;
		}

		$this->sut->process( 'charge.refund.updated', $update );
		$this->sut->process( 'charge.refunded', $this->get_successful_refund_charge() );

		$order = wc_get_order( $order->get_id() );
		$this->assertCount( 0, $order->get_refunds() );
		$this->assertSame( 'failed', $order->get_meta( '_wcpay_refund_status', true ) );
	}

	/**
	 * @testdox A charge.refunded event on a charge two orders share refunds the order created last, whatever their IDs.
	 *
	 * Client 11.1.0 finds a charge's order with the wc_get_orders() defaults, the newest by creation date
	 * (class-wc-payments-db.php:24-31, :87-104), as the other webhook lookups do. Here the order created last has the lower ID.
	 */
	public function test_charge_refunded_on_a_shared_charge_refunds_the_order_created_last(): void {
		$created_last  = $this->create_refundable_order();
		$created_first = $this->create_refundable_order();
		$created_first->set_date_created( time() - DAY_IN_SECONDS );
		$created_first->save();
		$this->assertGreaterThan( $created_last->get_id(), $created_first->get_id() );

		$this->sut->process( 'charge.refunded', $this->get_successful_refund_charge() );

		$this->assertCount( 1, wc_get_order( $created_last->get_id() )->get_refunds(), 'The order created last takes the refund.' );
		$this->assertCount( 0, wc_get_order( $created_first->get_id() )->get_refunds(), 'The older order sharing the charge is left alone.' );
	}

	/**
	 * @testdox A charge.refunded event whose refund is in requires_action records it as pending, and a succeeded charge.refund.updated settles it.
	 *
	 * Monitor ruling 2026-10-10 13:35 (U1): the webhook's refund row follows the same pending rule as the refund call. The
	 * refund is HAND-BUILT (`Fixtures/stripe-docs-requires-action-refund.json`, from Stripe's refund object,
	 * https://docs.stripe.com/api/refunds/object, and https://docs.stripe.com/refunds#requires-action): no WooPayments
	 * payment method reaches requires_action today. The charge fields are those client 11.1.0 reads
	 * (class-wc-payments-webhook-processing-service.php:1079-1143).
	 */
	public function test_charge_refunded_with_a_requires_action_refund_records_it_as_pending(): void {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reads a local immutable test fixture.
		$fixture = json_decode( (string) file_get_contents( dirname( __DIR__ ) . '/Fixtures/stripe-docs-requires-action-refund.json' ), true );
		$this->assertTrue( $fixture['_meta']['hand_built'] ?? false );
		$refund = $fixture['entries'][0]['response']['body'];
		$order  = wc_create_order();
		$order->set_payment_method( WooPaymentsPersistenceVocabulary::GATEWAY_ID );
		$order->set_currency( 'EUR' );
		$order->set_total( '10.00' );
		$order->set_status( 'processing' );
		$order->set_transaction_id( (string) $refund['payment_intent'] );
		$order->update_meta_data( '_intent_id', (string) $refund['payment_intent'] );
		$order->update_meta_data( '_charge_id', (string) $refund['charge'] );
		$order->save();

		$this->sut->process(
			'charge.refunded',
			array(
				'id'             => (string) $refund['charge'],
				'status'         => 'succeeded',
				'captured'       => true,
				'amount'         => 1000,
				'currency'       => 'eur',
				'payment_intent' => (string) $refund['payment_intent'],
				'refunds'        => array( 'data' => array( $refund ) ),
			)
		);
		$order   = wc_get_order( $order->get_id() );
		$refunds = $order->get_refunds();
		$status  = $order->get_meta( '_wcpay_refund_status', true );
		$notes   = $this->get_notes_containing( $order, (string) $refund['id'] );

		$refund['status'] = 'succeeded';
		$this->sut->process( 'charge.refund.updated', $refund );

		$this->assertCount( 1, $refunds );
		$this->assertSame( (string) $refund['id'], $refunds[0]->get_meta( '_wcpay_refund_id', true ) );
		$this->assertSame( 'pending', $status, 'A requires_action refund is recorded as pending.' );
		$this->assertCount( 1, $notes );
		$this->assertStringContainsString( 'is pending', (string) $notes[0]->content );
		$this->assertSame( 'successful', wc_get_order( $order->get_id() )->get_meta( '_wcpay_refund_status', true ), 'The succeeded update settles it.' );
	}

	/**
	 * Create a refundable order in the state a WooPayments payment leaves it: paid, with the intent as its transaction ID.
	 *
	 * @return WC_Order
	 */
	private function create_refundable_order(): WC_Order {
		$order = wc_create_order();
		$this->assertInstanceOf( WC_Order::class, $order );
		$order->set_payment_method( WooPaymentsPersistenceVocabulary::GATEWAY_ID );
		$order->set_currency( 'USD' );
		$order->set_total( '10.00' );
		$order->set_status( 'processing' );
		$order->set_transaction_id( 'pi_123' );
		$order->update_meta_data( '_intent_id', 'pi_123' );
		$order->update_meta_data( '_charge_id', 'ch_123' );
		$order->save();

		return $order;
	}

	/**
	 * @testdox A charge.refunded that read the order before an admin refund linked its row, and claims the lock after, finds that row under the lock and adds no second refund.
	 */
	public function test_charge_refunded_looks_up_the_linked_refund_under_the_lock(): void {
		$order   = $this->create_refundable_order();
		$store   = new class() extends OrderPaymentLock {
			/**
			 * Whether the admin refund has been linked.
			 *
			 * @var bool
			 */
			public bool $admin_refund_linked = false;

			/**
			 * Finish the admin refund, as the gateway does under the lock, then grant the webhook's claim.
			 *
			 * @param WC_Order                               $order     Order being locked.
			 * @param ProviderPersistenceVocabularyInterface $vocabulary   Persistence profile.
			 * @param string|null                            $reference Payment reference.
			 * @param string                                 $operation Operation claiming the lock.
			 * @return string|null
			 */
			public function claim( WC_Order $order, ProviderPersistenceVocabularyInterface $vocabulary, ?string $reference, string $operation ): ?string {
				unset( $vocabulary, $reference, $operation );
				if ( ! $this->admin_refund_linked ) {
					$this->admin_refund_linked = true;
					$refund                    = wc_create_refund(
						array(
							'amount'   => '4.00',
							'reason'   => 'Requested by customer',
							'order_id' => $order->get_id(),
						)
					);
					$refund->update_meta_data( '_wcpay_refund_id', 're_123' );
					$refund->save();
					// The webhook's request cached the order's refund IDs before the admin refund existed.
					wp_cache_set( \WC_Cache_Helper::get_cache_prefix( 'orders' ) . 'refund_ids' . $order->get_id(), array(), 'orders' );
				}

				return 'test_lock_token';
			}

			/**
			 * Release nothing: the claim above holds no lock.
			 *
			 * @param WC_Order                               $order      Order being unlocked.
			 * @param ProviderPersistenceVocabularyInterface $vocabulary    Persistence profile.
			 * @param string                                 $lock_token Claim token.
			 */
			public function release( WC_Order $order, ProviderPersistenceVocabularyInterface $vocabulary, string $lock_token ): void {
				unset( $order, $vocabulary, $lock_token );
			}
		};
		$handler = new WooPaymentsRefundEventHandler();
		$handler->init( $store, new WooPaymentsPersistenceVocabulary(), wc_get_container()->get( WooPaymentsEventOrderResolver::class ), wc_get_container()->get( WooPaymentsOtherChargeRecorder::class ), wc_get_container()->get( WooPaymentsAccountService::class ) );

		$handler->process( 'charge.refunded', $this->get_successful_refund_charge() );

		$this->assertTrue( $store->admin_refund_linked, 'The webhook must claim the order payment lock.' );
		$refunds = wc_get_order( $order->get_id() )->get_refunds();
		$this->assertCount( 1, $refunds, 'One platform refund keeps one local refund row.' );
		$this->assertSame( 're_123', $refunds[0]->get_meta( '_wcpay_refund_id', true ) );
	}

	/**
	 * @testdox A refund the platform made whose local effects failed keeps only its link, and a $event_status charge.refunded completes it, also when a hook callback throws: one note, the refund status and the transaction ID, no second refund row.
	 *
	 * Rows from 2224ece1012 and acaf26865d5 (no hook), cd8f8176b18 (a woocommerce_order_note_added callback throws: the
	 * delivery fails, but the status and transaction ID were saved before the note) and 94c3d0fabe6 (a
	 * woocommerce_before_order_object_save callback throws: WC_Abstract_Order::save() catches it and the delivery succeeds).
	 * The order is read past its caches, so an unsaved change on a cached object cannot pass for a saved one.
	 *
	 * @dataProvider provider_link_only_refund_completion
	 *
	 * @param string      $event_status      Refund status the event carries.
	 * @param string      $throwing_hook     Hook a callback throws from during the delivery, or '' for none.
	 * @param string|null $expected_failure  Message the delivery fails with, or null when it succeeds.
	 * @param string      $expected_status   Order refund status the event writes.
	 * @param string      $note_text         Status words of the created note the event adds.
	 * @param bool        $save_hooks_see_it Whether the order save hooks see the written refund status (not when a save hook throws).
	 */
	public function test_charge_refunded_completes_a_refund_whose_local_effects_failed( string $event_status, string $throwing_hook, ?string $expected_failure, string $expected_status, string $note_text, bool $save_hooks_see_it ): void {
		$order = $this->create_refundable_order();
		// An earlier refund attempt on the order failed.
		$order->update_meta_data( '_wcpay_refund_status', 'failed' );
		$order->save();
		$refund   = $this->create_local_refund( $order );
		$provider = new class( new PaymentOutcome( PaymentOutcome::STATUS_COMPLETED, 're_123' ) ) extends RecordingProvider implements ProviderOperationEffectApplierInterface {
			// phpcs:disable Squiz.Commenting.FunctionComment.InvalidNoReturn -- This test double always throws.
			/**
			 * Fail the local refund effects after the platform refund succeeded.
			 *
			 * @param PaymentOperationContext $context   Payment context.
			 * @param PaymentOutcome          $outcome   Provider outcome.
			 * @param string                  $operation Operation name.
			 * @return PaymentOutcome
			 * @throws \RuntimeException Always.
			 */
			public function apply_operation_effects( PaymentOperationContext $context, PaymentOutcome $outcome, string $operation ): PaymentOutcome {
				unset( $context, $outcome, $operation );
				throw new \RuntimeException( 'Local refund effect failed.' );
			}
			// phpcs:enable Squiz.Commenting.FunctionComment.InvalidNoReturn
		};

		$this->assertTrue( wc_get_container()->get( PaymentProcessingService::class )->process_refund( PaymentOperationContext::for_refund( $order, WooPaymentsPersistenceVocabulary::GATEWAY_ID, 4.00, 'Requested by customer' ), $provider ) );
		$refund = wc_get_order( $refund->get_id() );
		$this->assertInstanceOf( WC_Order_Refund::class, $refund );
		$this->assertSame( 're_123', $refund->get_meta( '_wcpay_refund_id', true ), 'The failed effects leave only the link.' );
		$this->assertSame( '', $refund->get_meta( '_wcpay_refund_transaction_id', true ) );

		$throwing = static function (): void {
			throw new \RuntimeException( 'A hook callback failed.' );
		};
		if ( '' !== $throwing_hook ) {
			add_action( $throwing_hook, $throwing );
		}
		// The writer saves the order after the refund status, as the client does (class-wc-payments-order-service.php:1948),
		// so listeners on the order save hooks see it. On HPOS, save_meta_data() can also run a full save when the order's
		// date_modified is older than the current second (OrdersTableDataStore::after_meta_change()); in the same second only
		// the explicit save fires the hooks, so this check covers it whenever the test runs within the second the order was
		// created, which is the usual case (carried item B-R30).
		$saved_statuses = array();
		$record_save    = static function ( $saved_order ) use ( $order, &$saved_statuses ): void {
			if ( $saved_order instanceof WC_Order && $saved_order->get_id() === $order->get_id() ) {
				$saved_statuses[] = $saved_order->get_meta( '_wcpay_refund_status', true );
			}
		};
		add_action( 'woocommerce_after_order_object_save', $record_save );
		$charge                                 = $this->get_successful_refund_charge();
		$charge['refunds']['data'][0]['status'] = $event_status;
		$failure                                = null;
		try {
			$this->sut->process( 'charge.refunded', $charge );
		} catch ( \RuntimeException $exception ) {
			$failure = $exception->getMessage();
		} finally {
			remove_action( $throwing_hook, $throwing );
			remove_action( 'woocommerce_after_order_object_save', $record_save );
		}

		$this->assertSame( $expected_failure, $failure );
		$this->assertSame( $save_hooks_see_it, in_array( $expected_status, $saved_statuses, true ) );
		$order   = wc_get_container()->get( OrderPaymentLifecycleService::class )->get_fresh_order_from_data_store( $order );
		$refunds = $order->get_refunds();
		$this->assertCount( 1, $refunds );
		$this->assertSame( $refund->get_id(), $refunds[0]->get_id() );
		$this->assertSame( $expected_status, $order->get_meta( '_wcpay_refund_status', true ) );
		$this->assertSame( 'txn_123', $refunds[0]->get_meta( '_wcpay_refund_transaction_id', true ) );
		$created_notes = array_filter(
			wc_get_order_notes( array( 'order_id' => $order->get_id() ) ),
			static fn( $note ): bool => str_contains( $note->content, 're_123' ) && str_contains( $note->content, $note_text )
		);
		$this->assertCount( 1, $created_notes );
	}

	/**
	 * Link-only refunds a charge.refunded completes.
	 *
	 * @return array<string,array{0:string,1:string,2:?string,3:string,4:string,5:bool}>
	 */
	public function provider_link_only_refund_completion(): array {
		return array(
			'succeeded'        => array( 'succeeded', '', null, 'successful', 'was successfully processed', true ),
			'pending'          => array( 'pending', '', null, 'pending', 'is pending', true ),
			'note hook throws' => array( 'succeeded', 'woocommerce_order_note_added', 'A hook callback failed.', 'successful', 'was successfully processed', true ),
			'save hook throws' => array( 'succeeded', 'woocommerce_before_order_object_save', null, 'successful', 'was successfully processed', false ),
		);
	}

	/**
	 * @testdox A succeeded charge.refund.updated finds the linked refund even when the request cached the order's refund IDs before it was linked.
	 */
	public function test_succeeded_refund_update_is_not_hidden_by_refund_ids_cached_earlier(): void {
		$refund_object = $this->load_recorded_refund_updated_event( 'afterpay_clearpay_refund_updated_succeeded' );
		$order         = wc_create_order();
		$order->set_payment_method( WooPaymentsPersistenceVocabulary::GATEWAY_ID );
		$order->set_currency( strtoupper( (string) $refund_object['currency'] ) );
		$order->set_total( sprintf( '%.2f', ( (int) $refund_object['amount'] ) / 100 ) );
		$order->set_status( 'processing' );
		$order->update_meta_data( '_charge_id', (string) $refund_object['charge'] );
		$order->save();
		// An earlier action of the same request read the order's refunds before the refund was linked.
		$this->assertSame( array(), $order->get_refunds() );
		$refund = wc_create_refund(
			array(
				'amount'   => sprintf( '%.2f', ( (int) $refund_object['amount'] ) / 100 ),
				'order_id' => $order->get_id(),
			)
		);
		$refund->update_meta_data( '_wcpay_refund_id', (string) $refund_object['id'] );
		$refund->save();
		$order->update_meta_data( '_wcpay_refund_status', 'pending' );
		$order->save();
		wp_cache_set( \WC_Cache_Helper::get_cache_prefix( 'orders' ) . 'refund_ids' . $order->get_id(), array(), 'orders' );

		$this->sut->process( 'charge.refund.updated', $refund_object );

		$this->assertSame( 'successful', wc_get_order( $order->get_id() )->get_meta( '_wcpay_refund_status', true ) );
	}

	/**
	 * Cache a fresh live account with this country, readable through the account service while the platform is connected.
	 *
	 * The same account cache the ingestor test sets (WooPaymentsEventIngestorTest::set_woopayments_account_country()).
	 *
	 * @param string $country Account country.
	 */
	private function set_woopayments_account_country( string $country ): void {
		wc_get_container()->get( WooPaymentsAccountService::class )->clear_cache();
		// The option envelope client 11.1.0 Database_Cache writes (class-database-cache.php:377-382), with the account fields
		// recorded in ../Fixtures/platform-test-drive-account.json `account`; `is_live` true keeps onboarding test mode off.
		update_option(
			'wcpay_account_data',
			array(
				'data'    => array(
					'account_id' => 'acct_123',
					'is_live'    => true,
					'country'    => $country,
				),
				'fetched' => time(),
				'errored' => false,
			)
		);
		wc_get_container()->replace(
			WooPaymentsApiClient::class,
			new class() extends WooPaymentsApiClient {
				/**
				 * Report the platform as connected, so the account service reads the cached account.
				 *
				 * @return bool
				 */
				public function is_available(): bool {
					return true;
				}
			}
		);
	}

	/**
	 * Create the local refund row produced by the synchronous path.
	 *
	 * @param WC_Order $order Parent order.
	 * @return WC_Order_Refund
	 */
	private function create_local_refund( WC_Order $order ): WC_Order_Refund {
		$refund = wc_create_refund(
			array(
				'amount'   => '4.00',
				'reason'   => 'Requested by customer',
				'order_id' => $order->get_id(),
			)
		);
		$this->assertInstanceOf( WC_Order_Refund::class, $refund );

		return $refund;
	}

	/**
	 * Get a successful charge.refunded payload.
	 *
	 * @return array<string,mixed>
	 */
	private function get_successful_refund_charge(): array {
		// A Stripe Charge with its refunds list, as charge.refunded carries it. Client 11.1.0 reads status, captured, amount,
		// currency and refunds.data[0] (id, amount, reason, status, balance_transaction as an ID) at
		// class-wc-payments-webhook-processing-service.php:1079-1143. Tests add the Charge's `payment_intent` and the
		// store's `metadata` (https://docs.stripe.com/api/charges/object#charge_object-payment_intent).
		return array(
			'id'       => 'ch_123',
			'status'   => 'succeeded',
			'captured' => true,
			'amount'   => 1000,
			'currency' => 'usd',
			'refunds'  => array(
				'data' => array(
					array(
						'id'                  => 're_123',
						'amount'              => 400,
						'reason'              => 'Requested by customer',
						'status'              => 'succeeded',
						'balance_transaction' => 'txn_123',
					),
				),
			),
		);
	}

	/**
	 * Build the successful created-refund note the WooPayments extension writes for `re_123`, in its own text domain.
	 *
	 * Client 11.1.0 adds it with `add_order_note()` and no identity (class-wc-payments-order-service.php:1935-1939).
	 *
	 * @return string
	 */
	private function get_extension_created_refund_note(): string {
		return sprintf(
			WooPaymentsHtmlUtils::escape_interpolated_html(
				/* translators: %1$s: refund amount, %2$s: WooPayments, %3$s: refund reason, %4$s: provider refund ID, %5$s: refund status. */
				__( 'A refund of %1$s %5$s using %2$s. Reason: %3$s. (<code>%4$s</code>)', 'woocommerce-payments' ), // phpcs:ignore WordPress.WP.I18n.TextDomainMismatch -- The fixture emulates the legacy plugin catalog.
				array( 'code' => '<code>' )
			),
			wc_price( 4.00, array( 'currency' => 'USD' ) ),
			'WooPayments',
			'Requested by customer',
			're_123',
			__( 'was successfully processed', 'woocommerce-payments' ) // phpcs:ignore WordPress.WP.I18n.TextDomainMismatch -- The fixture emulates the legacy plugin catalog.
		);
	}

	/**
	 * Install test-only catalog translations.
	 *
	 * @param array<string,array<string,string>> $replacements Source-to-translation maps keyed by text domain.
	 */
	private function install_test_translations( array $replacements ): void {
		$this->gettext_replacements = $replacements;
		add_filter( 'gettext', array( $this, 'translate_test_string' ), 10, 3 );
	}

	/**
	 * Translate a fixture string for the requested text domain.
	 *
	 * @param string $translation Translated text.
	 * @param string $text        Source text.
	 * @param string $domain      Text domain.
	 * @return string
	 */
	public function translate_test_string( string $translation, string $text, string $domain ): string {
		return $this->gettext_replacements[ $domain ][ $text ] ?? $translation;
	}
}
