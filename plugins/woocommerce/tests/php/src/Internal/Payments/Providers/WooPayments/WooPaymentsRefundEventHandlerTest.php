<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments;

use Automattic\WooCommerce\Internal\MultiCurrency\MultiCurrencyRuntimeArbiter;
use Automattic\WooCommerce\Internal\MultiCurrency\MultiCurrencyFeatureController;
use Automattic\WooCommerce\Internal\Payments\ProviderPersistenceVocabulary;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsLegacyRuntime;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsPersistenceProfile;
use Automattic\WooCommerce\Proxies\LegacyProxy;
use Automattic\WooCommerce\Internal\Payments\NativePaymentsRuntimeArbiter;
use Automattic\WooCommerce\Internal\Payments\OrderPaymentStore;
use Automattic\WooCommerce\Internal\Payments\PaymentContext;
use Automattic\WooCommerce\Internal\Payments\PaymentOutcome;
use Automattic\WooCommerce\Internal\Payments\PaymentProcessingService;
use Automattic\WooCommerce\Internal\Payments\ProviderOperationEffectApplier;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsHtmlUtils;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsOrderEffectApplier;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsOrderEffectPlan;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsOrderNoteService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsRefundEventHandler;
use Automattic\WooCommerce\Tests\Internal\Payments\RecordingProvider;
use WC_Order;
use WC_Order_Refund;
use WC_Unit_Test_Case;

/**
 * Tests for the WooPaymentsRefundEventHandler class.
 */
class WooPaymentsRefundEventHandlerTest extends WC_Unit_Test_Case {
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
		$this->reset_container_replacements();
		parent::tearDown();
	}

	/**
	 * @testdox Failed refund notes follow the client explicit-price rule.
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
	public function test_failed_refund_note_follows_the_client_explicit_price_rule( bool $core_multi_currency, ?string $plugin_flag, ?bool $filter_result, bool $expected_default, string $expected_note ): void {
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
		$order  = $this->create_refundable_order();
		$method = new \ReflectionMethod( $this->sut, 'get_failed_refund_note' );
		$method->setAccessible( true );

		$note = $method->invoke( $this->sut, $order, 're_123', 400, 'usd', true, '' );

		$this->assertSame( $expected_note, html_entity_decode( wp_strip_all_tags( $note ) ) );
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
		$arbiter->init( $container->get( NativePaymentsRuntimeArbiter::class ), $container->get( LegacyProxy::class ), $container->get( MultiCurrencyFeatureController::class ) );
		$arbiter->method( 'should_core_register' )->willReturn( $enabled );
		wc_get_container()->replace( MultiCurrencyRuntimeArbiter::class, $arbiter );
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
			$provider           = new class( new PaymentOutcome( PaymentOutcome::STATUS_COMPLETED, 're_123' ), $effect_applier, $provider_result ) extends RecordingProvider implements ProviderOperationEffectApplier {
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
				 * @param PaymentContext $context   Payment context.
				 * @param PaymentOutcome $outcome   Provider outcome.
				 * @param string         $operation Operation name.
				 * @return PaymentOutcome
				 */
				public function apply_operation_effects( PaymentContext $context, PaymentOutcome $outcome, string $operation ): PaymentOutcome {
					unset( $operation );

					return $this->effect_applier->apply( $context, $outcome, WooPaymentsOrderEffectPlan::for_refund( $this->provider_result ) );
				}
			};
			$processing_service = wc_get_container()->get( PaymentProcessingService::class );

			$this->assertTrue( $processing_service->process_refund( PaymentContext::for_refund( $order, OrderPaymentStore::GATEWAY_ID, 4.00, 'Requested by customer' ), $provider ) );
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
			$this->assertSame( hash( 'sha256', 'refund:re_123:created_successful' ), get_comment_meta( $refund_notes[0]->id, WooPaymentsOrderNoteService::NOTE_IDENTITY_META_KEY, true ) );
			$this->assertSame( '', $order->get_meta( '_wc_native_woopayments_refund_note_' . md5( 're_123|created_successful' ), true ) );
		} finally {
			false === $previous_enabled_currencies
				? delete_option( 'wcpay_multi_currency_enabled_currencies' )
				: update_option( 'wcpay_multi_currency_enabled_currencies', $previous_enabled_currencies );
		}
	}

	/**
	 * @testdox A German plugin-era refund note is adopted without adding the Core-catalog rendering.
	 */
	public function test_german_plugin_refund_note_followed_by_webhook_converges(): void {
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
			$plugin_note = sprintf(
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
			$order->add_order_note( $plugin_note );
			$order->save();

			$this->sut->process( 'charge.refunded', $this->get_successful_refund_charge() );

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
	 * @testdox A successful synchronous refund followed by a `charge.refund.updated` succeeded webhook keeps one note.
	 *
	 * `:102` (`test_successful_synchronous_refund_followed_by_webhook_converges`)
	 * proves this convergence for `charge.refunded`, the full-charge event;
	 * this proves it for `charge.refund.updated`, the bare-refund event, which
	 * that test does not exercise (client `os:1937`, `wh:355-359`). Recorded
	 * webhook body: REC-5a R-c (`Fixtures/rec-5a-refund-updated-event.json`,
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
		$order->set_payment_method( OrderPaymentStore::GATEWAY_ID );
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
		$provider           = new class( new PaymentOutcome( PaymentOutcome::STATUS_COMPLETED, $refund_id ), $effect_applier, $provider_result ) extends RecordingProvider implements ProviderOperationEffectApplier {
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
			 * @param PaymentContext $context   Payment context.
			 * @param PaymentOutcome $outcome   Provider outcome.
			 * @param string         $operation Operation name.
			 * @return PaymentOutcome
			 */
			public function apply_operation_effects( PaymentContext $context, PaymentOutcome $outcome, string $operation ): PaymentOutcome {
				unset( $operation );

				return $this->effect_applier->apply( $context, $outcome, WooPaymentsOrderEffectPlan::for_refund( $this->provider_result ) );
			}
		};
		$processing_service = wc_get_container()->get( PaymentProcessingService::class );

		$this->assertTrue( $processing_service->process_refund( PaymentContext::for_refund( $order, OrderPaymentStore::GATEWAY_ID, $amount, $reason ), $provider ) );

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
		$provider       = new class( new PaymentOutcome( PaymentOutcome::STATUS_COMPLETED, 're_123' ), $effect_applier ) extends RecordingProvider implements ProviderOperationEffectApplier {
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
			 * @param PaymentContext $context   Payment context.
			 * @param PaymentOutcome $outcome   Provider outcome.
			 * @param string         $operation Operation name.
			 * @return PaymentOutcome
			 */
			public function apply_operation_effects( PaymentContext $context, PaymentOutcome $outcome, string $operation ): PaymentOutcome {
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

		$this->assertTrue( wc_get_container()->get( PaymentProcessingService::class )->process_refund( PaymentContext::for_refund( $order, OrderPaymentStore::GATEWAY_ID, 4.00, 'Requested by customer' ), $provider ) );

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
		$fixture = file_get_contents( __DIR__ . '/Fixtures/rec-5a-refund-updated-event.json' );
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
	 * Create a refundable WooPayments order.
	 *
	 * @return WC_Order
	 */
	private function create_refundable_order(): WC_Order {
		$order = wc_create_order();
		$this->assertInstanceOf( WC_Order::class, $order );
		$order->set_payment_method( OrderPaymentStore::GATEWAY_ID );
		$order->set_currency( 'USD' );
		$order->set_total( '10.00' );
		$order->set_status( 'processing' );
		$order->update_meta_data( '_charge_id', 'ch_123' );
		$order->save();

		return $order;
	}

	/**
	 * @testdox A charge.refunded that read the order before an admin refund linked its row, and claims the lock after, reuses that row instead of creating a second refund.
	 */
	public function test_charge_refunded_looks_up_the_linked_refund_under_the_lock(): void {
		$order   = $this->create_refundable_order();
		$store   = new class() extends OrderPaymentStore {
			/**
			 * Whether the admin refund has been linked.
			 *
			 * @var bool
			 */
			public bool $admin_refund_linked = false;

			/**
			 * Finish the admin refund, as the gateway does under the lock, then grant the webhook's claim.
			 *
			 * @param WC_Order                      $order     Order being locked.
			 * @param ProviderPersistenceVocabulary $profile   Persistence profile.
			 * @param string|null                   $reference Payment reference.
			 * @param string                        $operation Operation claiming the lock.
			 * @return string|null
			 */
			public function claim_order_payment_lock_for_operation( WC_Order $order, ProviderPersistenceVocabulary $profile, ?string $reference, string $operation ): ?string {
				unset( $profile, $reference, $operation );
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
				}

				return 'test_lock_token';
			}

			/**
			 * Release nothing: the claim above holds no lock.
			 *
			 * @param WC_Order                      $order      Order being unlocked.
			 * @param ProviderPersistenceVocabulary $profile    Persistence profile.
			 * @param string                        $lock_token Claim token.
			 */
			public function release_order_payment_lock( WC_Order $order, ProviderPersistenceVocabulary $profile, string $lock_token ): void {
				unset( $order, $profile, $lock_token );
			}
		};
		$handler = new WooPaymentsRefundEventHandler();
		$handler->init( wc_get_container()->get( WooPaymentsLegacyRuntime::class ), $store, new WooPaymentsPersistenceProfile() );

		$handler->process( 'charge.refunded', $this->get_successful_refund_charge() );

		$this->assertTrue( $store->admin_refund_linked, 'The webhook must claim the order payment lock.' );
		$refunds = wc_get_order( $order->get_id() )->get_refunds();
		$this->assertCount( 1, $refunds, 'One platform refund keeps one local refund row.' );
		$this->assertSame( 're_123', $refunds[0]->get_meta( '_wcpay_refund_id', true ) );
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
						'balance_transaction' => array( 'id' => 'txn_123' ),
					),
				),
			),
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
