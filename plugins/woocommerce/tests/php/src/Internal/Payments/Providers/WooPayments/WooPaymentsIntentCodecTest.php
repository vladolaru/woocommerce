<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments;

use Automattic\WooCommerce\Internal\Payments\PaymentOutcome;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api\WooPaymentsApiException;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsIntentCodec;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsIntentMappingContext;
use WC_Unit_Test_Case;

/**
 * Tests for the WooPaymentsIntentCodec class.
 */
class WooPaymentsIntentCodecTest extends WC_Unit_Test_Case {

	/**
	 * @testdox Confirmation redirects use the explicitly supplied nonce.
	 */
	public function test_confirmation_redirect_uses_explicit_nonce(): void {
		$this->assertSame(
			'#wcpay-confirm-si:42:seti_secret:explicit_nonce:ctoken_123',
			WooPaymentsIntentCodec::confirmation_redirect_for( 42, 'seti_secret', 'explicit_nonce', 'si', 'ctoken_123' )
		);
	}

	/**
	 * @testdox Native intent decoding contains no order-effect data.
	 */
	public function test_outcome_from_intention_contains_no_order_effect_data(): void {
		$outcome = WooPaymentsIntentCodec::outcome_from_intention(
			array(
				'id'             => 'pi_neutral',
				'status'         => 'succeeded',
				'customer'       => 'cus_neutral',
				'payment_method' => 'pm_neutral',
				'currency'       => 'usd',
				'charges'        => array(
					'data' => array(
						array(
							'id'                  => 'ch_neutral',
							'balance_transaction' => array( 'id' => 'txn_neutral' ),
						),
					),
				),
			),
			WooPaymentsIntentMappingContext::for_native( 42, 'https://example.test/order-received/42' )
		);

		$data = $outcome->get_data();

		$this->assertSame( PaymentOutcome::STATUS_COMPLETED, $outcome->get_status() );
		$this->assertSame( 'pi_neutral', $outcome->get_provider_payment_id() );
		$this->assertSame( 'pm_neutral', $outcome->get_payment_method_id() );
		$this->assertSame( 'cus_neutral', $outcome->get_customer_id() );
		$this->assertSame( 'ch_neutral', $data['charge_id'] );
		$this->assertArrayNotHasKey( PaymentOutcome::DATA_META, $data );
		$this->assertArrayNotHasKey( PaymentOutcome::DATA_NOTE, $data );
		$this->assertArrayNotHasKey( PaymentOutcome::DATA_NOTE_TYPE, $data );
		$this->assertArrayNotHasKey( PaymentOutcome::DATA_ORDER_META, $data );
		$this->assertArrayNotHasKey( PaymentOutcome::DATA_REFUND_META, $data );
		$this->assertArrayNotHasKey( PaymentOutcome::DATA_REFUND_NOTE, $data );
	}

	/**
	 * @testdox Asynchronous debit intents map to authorized outcomes without local effects.
	 */
	public function test_outcome_from_intention_maps_processing_intent_to_authorized_outcome(): void {
		$outcome = WooPaymentsIntentCodec::outcome_from_intention(
			array(
				'id'             => 'pi_sepa',
				'status'         => 'processing',
				'customer'       => 'cus_sepa',
				'payment_method' => 'pm_sepa',
			),
			WooPaymentsIntentMappingContext::for_native( 42, 'https://example.test/order-received/42' )
		);

		$this->assertSame( PaymentOutcome::STATUS_AUTHORIZED, $outcome->get_status() );
		$this->assertSame( array(), $outcome->get_data() );
	}

	/**
	 * @testdox Customer-action intents use the prebuilt boundary redirect.
	 */
	public function test_outcome_from_intention_uses_supplied_customer_action_redirect(): void {
		$outcome = WooPaymentsIntentCodec::outcome_from_intention(
			array(
				'id'                   => 'pi_action',
				'status'               => 'requires_action',
				'client_secret'        => 'secret_action',
				'payment_method'       => 'pm_action',
				'payment_method_types' => array( 'wechat_pay' ),
			),
			WooPaymentsIntentMappingContext::for_native(
				42,
				'https://example.test/order-received/42',
				'pm_request',
				'cus_fallback',
				'#wcpay-confirm-pi:42:secret_action:explicit_nonce'
			)
		);

		$this->assertSame( PaymentOutcome::STATUS_REQUIRES_CUSTOMER_ACTION, $outcome->get_status() );
		$this->assertSame( '#wcpay-confirm-pi:42:secret_action:explicit_nonce', $outcome->get_redirect_url() );
		$this->assertSame( 'cus_fallback', $outcome->get_customer_id() );
		$this->assertArrayNotHasKey( PaymentOutcome::DATA_META, $outcome->get_data() );
	}

	/**
	 * @testdox A method-named handle-redirect next action falls through to the prebuilt confirmation hash.
	 *
	 * Oracle: WooPayments 11.1.0 `class-wc-payment-gateway-wcpay.php:2093-2114`. The plugin's redirect
	 * branch reads only `redirect_to_url`; every other next-action type, including a method's own
	 * `alipay_handle_redirect`, falls into the same `else` that builds the `#wcpay-confirm-pi:` hash for
	 * the provider's own script to complete client-side. This intent carries a real `next_action` body
	 * (unlike the wechat_pay case above, which has none) to prove the codec does not start reading
	 * `alipay_handle_redirect` as a raw redirect once a payload for it exists.
	 */
	public function test_outcome_from_intention_maps_method_handle_redirect_to_confirmation_hash(): void {
		$intent = array(
			'id'             => 'pi_alipay',
			'status'         => 'requires_action',
			'client_secret'  => 'secret_alipay',
			'payment_method' => 'pm_alipay',
			'next_action'    => array(
				'type'                   => 'alipay_handle_redirect',
				'alipay_handle_redirect' => array(
					'native_url' => 'https://pm-redirects.stripe.com/authorize/acct_test/pa_alipay',
				),
			),
		);

		$raw_redirect_url = WooPaymentsIntentCodec::raw_next_action_redirect_url( $intent );
		$this->assertSame( '', $raw_redirect_url );
		$this->assertTrue( WooPaymentsIntentCodec::requires_confirmation_redirect( $intent, esc_url_raw( $raw_redirect_url ) ) );

		$outcome = WooPaymentsIntentCodec::outcome_from_intention(
			$intent,
			WooPaymentsIntentMappingContext::for_native(
				42,
				'https://example.test/order-received/42',
				'pm_request',
				'cus_fallback',
				'#wcpay-confirm-pi:42:secret_alipay:explicit_nonce'
			)
		);

		$this->assertSame( PaymentOutcome::STATUS_REQUIRES_CUSTOMER_ACTION, $outcome->get_status() );
		$this->assertSame( '#wcpay-confirm-pi:42:secret_alipay:explicit_nonce', $outcome->get_redirect_url() );
	}

	/**
	 * @testdox Redirect next actions map to provider redirect outcomes.
	 */
	public function test_outcome_from_intention_maps_provider_redirect(): void {
		$clean_url_calls = 0;
		$clean_url       = static function ( string $url ) use ( &$clean_url_calls ): string {
			unset( $url );
			++$clean_url_calls;

			return 'https://filtered.example/should-not-run';
		};
		add_filter( 'clean_url', $clean_url );

		try {
			$outcome = WooPaymentsIntentCodec::outcome_from_intention(
				array(
					'id'          => 'pi_ideal',
					'status'      => 'requires_action',
					'next_action' => array(
						'type'            => 'redirect_to_url',
						'redirect_to_url' => array( 'url' => 'https://hooks.stripe.com/redirect/ideal' ),
					),
				),
				WooPaymentsIntentMappingContext::for_native(
					42,
					'https://example.test/order-received/42',
					'',
					'',
					'',
					'pi',
					'https://sanitized.example/redirect/ideal'
				),
			);
		} finally {
			remove_filter( 'clean_url', $clean_url );
		}

		$this->assertSame( PaymentOutcome::STATUS_REQUIRES_REDIRECT, $outcome->get_status() );
		$this->assertSame( 'https://sanitized.example/redirect/ideal', $outcome->get_redirect_url() );
		$this->assertSame( 'https://sanitized.example/redirect/ideal', $outcome->get_data()[ PaymentOutcome::DATA_CHECKOUT_REDIRECT ] );
		$this->assertSame( 0, $clean_url_calls, 'The pure codec must not dispatch URL filters.' );
	}

	/**
	 * @testdox Multibanco voucher intents use the explicitly supplied order-received URL.
	 */
	public function test_outcome_from_intention_uses_supplied_order_received_url_for_multibanco(): void {
		$outcome = WooPaymentsIntentCodec::outcome_from_intention(
			array(
				'id'          => 'pi_multibanco',
				'status'      => 'requires_action',
				'next_action' => array(
					'type'                       => 'multibanco_display_details',
					'multibanco_display_details' => array( 'reference' => '123 456 789' ),
				),
			),
			WooPaymentsIntentMappingContext::for_native( 42, 'https://example.test/order-received/42' )
		);

		$this->assertSame( PaymentOutcome::STATUS_AUTHORIZED, $outcome->get_status() );
		$this->assertSame( 'https://example.test/order-received/42', $outcome->get_redirect_url() );
		$this->assertArrayNotHasKey( PaymentOutcome::DATA_META, $outcome->get_data() );
	}

	/**
	 * @testdox The latest charge payment method precedes submitted credential fallback.
	 */
	public function test_outcome_from_intention_uses_charge_payment_method_before_credential_fallback(): void {
		$outcome = WooPaymentsIntentCodec::outcome_from_intention(
			array(
				'id'      => 'pi_charge_pm',
				'status'  => 'succeeded',
				'charges' => array(
					'data' => array( array( 'payment_method' => 'pm_from_charge' ) ),
				),
			),
			WooPaymentsIntentMappingContext::for_native( 42, '', 'ctoken_submitted' )
		);

		$this->assertSame( 'pm_from_charge', $outcome->get_payment_method_id() );
	}

	/**
	 * @testdox Failed intent mapping keeps raw diagnostics separate from localized shopper copy.
	 */
	public function test_failed_intent_mapping_separates_localized_shopper_message_from_raw_provider_message(): void {
		$outcome = WooPaymentsIntentCodec::outcome_from_intention(
			array(
				'id'                 => 'pi_declined',
				'status'             => 'requires_payment_method',
				'last_payment_error' => array(
					'type'         => 'card_error',
					'code'         => 'card_declined',
					'decline_code' => 'insufficient_funds',
					'message'      => 'Provider diagnostic: balance check failed for request req_private.',
				),
			),
			WooPaymentsIntentMappingContext::for_native( 42, 'https://example.test/order-received/42' )
		);

		$data = $outcome->get_data();

		$this->assertSame( PaymentOutcome::STATUS_FAILED, $outcome->get_status() );
		$this->assertSame( 'card_declined', $data[ PaymentOutcome::DATA_ERROR_CODE ] );
		$this->assertSame( 'Provider diagnostic: balance check failed for request req_private.', $data[ PaymentOutcome::DATA_ERROR_MESSAGE ] );
		$this->assertSame( 'Error: Your card has insufficient funds.', $data[ PaymentOutcome::DATA_SHOPPER_ERROR_MESSAGE ] );
	}

	/**
	 * @testdox Failed intent mapping preserves a grounded platform message without changing its diagnostic copy.
	 */
	public function test_failed_intent_mapping_preserves_grounded_platform_message(): void {
		$platform_message = "Error: We're not able to add this payment method. Please try again later.";
		$outcome          = WooPaymentsIntentCodec::outcome_from_intention(
			array(
				'id'                 => 'pi_card_testing_prevention',
				'status'             => 'requires_payment_method',
				'last_payment_error' => array(
					'type'    => '',
					'code'    => 'wcpay_card_testing_prevention',
					'message' => $platform_message,
				),
			),
			WooPaymentsIntentMappingContext::for_native( 42, 'https://example.test/order-received/42' )
		);

		$data = $outcome->get_data();

		$this->assertSame( $platform_message, $data[ PaymentOutcome::DATA_ERROR_MESSAGE ] );
		$this->assertSame( $platform_message, $data[ PaymentOutcome::DATA_SHOPPER_ERROR_MESSAGE ] );
	}

	/**
	 * @testdox Failed intent mapping redacts unknown provider details from shopper copy.
	 */
	public function test_failed_intent_mapping_redacts_unknown_provider_message(): void {
		$outcome = WooPaymentsIntentCodec::outcome_from_intention(
			array(
				'id'                 => 'pi_unknown_failure',
				'status'             => 'requires_payment_method',
				'last_payment_error' => array(
					'type'    => 'api_error',
					'code'    => 'provider_internal_failure',
					'message' => 'Raw provider secret must remain diagnostic only.',
				),
			),
			WooPaymentsIntentMappingContext::for_native( 42, 'https://example.test/order-received/42' )
		);

		$data = $outcome->get_data();

		$this->assertSame( 'Raw provider secret must remain diagnostic only.', $data[ PaymentOutcome::DATA_ERROR_MESSAGE ] );
		$this->assertSame(
			"We're not able to process this request. Please refresh the page and try again.",
			$data[ PaymentOutcome::DATA_SHOPPER_ERROR_MESSAGE ]
		);
	}

	/**
	 * @testdox Failed intent mapping ignores malformed metadata without warnings or diagnostic loss.
	 */
	public function test_failed_intent_mapping_ignores_malformed_error_metadata(): void {
		$warnings      = array();
		$error_handler = static function ( int $error_level, string $error_message ) use ( &$warnings ): bool {
			if ( E_WARNING !== $error_level ) {
				return false;
			}

			$warnings[] = $error_message;

			return true;
		};
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_set_error_handler -- Test instrumentation verifies malformed metadata emits no warnings.
		set_error_handler( $error_handler );

		try {
			$outcome = WooPaymentsIntentCodec::outcome_from_intention(
				array(
					'id'                 => 'pi_malformed_failure',
					'status'             => 'requires_payment_method',
					'last_payment_error' => array(
						'type'         => array( 'card_error' ),
						'code'         => 'card_declined',
						'decline_code' => array( 'insufficient_funds' ),
						'message'      => 'Malformed provider metadata for request req_private.',
					),
				),
				WooPaymentsIntentMappingContext::for_native( 42, 'https://example.test/order-received/42' )
			);
		} finally {
			restore_error_handler();
		}

		$data = $outcome->get_data();

		$this->assertSame( 'Malformed provider metadata for request req_private.', $data[ PaymentOutcome::DATA_ERROR_MESSAGE ] );
		// A non-string type is dropped, so the error is typeless and keeps its message (client `class-wc-payments-utils.php:770`).
		$this->assertSame( 'Malformed provider metadata for request req_private.', $data[ PaymentOutcome::DATA_SHOPPER_ERROR_MESSAGE ] );
		$this->assertSame( array(), $warnings );
	}

	/**
	 * @testdox Failed HTTP transport outcomes localize structured card decline details without replacing diagnostics.
	 */
	public function test_failed_transport_outcome_localizes_structured_card_decline(): void {
		$sut = WooPaymentsIntentCodec::failed_transport_outcome(
			'charge',
			new WooPaymentsApiException(
				'Error: Provider diagnostic for request req_private.',
				'card_declined',
				402,
				'card_error',
				'insufficient_funds'
			)
		);

		$data = $sut->get_data();

		$this->assertSame( PaymentOutcome::STATUS_FAILED, $sut->get_status() );
		$this->assertSame( 'card_declined', $data[ PaymentOutcome::DATA_ERROR_CODE ] );
		$this->assertSame( 'Error: Provider diagnostic for request req_private.', $data[ PaymentOutcome::DATA_ERROR_MESSAGE ] );
		$this->assertSame( 'Error: Your card has insufficient funds.', $data[ PaymentOutcome::DATA_SHOPPER_ERROR_MESSAGE ] ?? null );
		$this->assertSame( 'charge', $data['operation'] );
	}

	/**
	 * @testdox Failed transport outcomes preserve grounded platform messages without changing diagnostic copy.
	 *
	 * @dataProvider grounded_transport_error_data
	 *
	 * @param string $error_type       Error type.
	 * @param string $error_code       Error code.
	 * @param string $platform_message Normalized platform message.
	 */
	public function test_failed_transport_outcome_preserves_grounded_platform_message( string $error_type, string $error_code, string $platform_message ): void {
		$outcome = WooPaymentsIntentCodec::failed_transport_outcome(
			'charge',
			new WooPaymentsApiException( $platform_message, $error_code, 400, $error_type )
		);

		$data = $outcome->get_data();

		$this->assertSame( $platform_message, $data[ PaymentOutcome::DATA_ERROR_MESSAGE ] );
		$this->assertSame( $platform_message, $data[ PaymentOutcome::DATA_SHOPPER_ERROR_MESSAGE ] );
	}

	/**
	 * Grounded platform transport errors whose normalized message is safe to show.
	 *
	 * @return array<string,array{string,string,string}>
	 */
	public function grounded_transport_error_data(): array {
		return array(
			'card-testing prevention' => array( '', 'wcpay_card_testing_prevention', "Error: We're not able to add this payment method. Please try again later." ),
			'unmapped card error'     => array( 'card_error', 'provider_internal_detail', 'Error: Your card does not support this type of purchase.' ),
		);
	}

	/**
	 * @testdox Failed transport outcomes redact a typed invalid-request error for the shopper and keep it for diagnostics.
	 *
	 * Source: client 11.1.0 `class-wc-payments-utils.php:797-798` (typed non-card errors use the generic copy).
	 */
	public function test_failed_transport_outcome_redacts_typed_invalid_request_error(): void {
		$platform_message = 'Error: Invalid string length: 55555501004242424242424242 must be at most 20 characters';
		$outcome          = WooPaymentsIntentCodec::failed_transport_outcome(
			'charge',
			new WooPaymentsApiException( $platform_message, 'invalid_request_error', 400, 'invalid_request_error' )
		);

		$data = $outcome->get_data();

		$this->assertSame( $platform_message, $data[ PaymentOutcome::DATA_ERROR_MESSAGE ] );
		$this->assertSame( "We're not able to process this request. Please refresh the page and try again.", $data[ PaymentOutcome::DATA_SHOPPER_ERROR_MESSAGE ] );
	}

	/**
	 * @testdox Failed non-card transport outcomes use generic shopper copy without replacing diagnostics.
	 */
	public function test_failed_transport_outcome_redacts_non_card_error(): void {
		$sut = WooPaymentsIntentCodec::failed_transport_outcome(
			'charge',
			new WooPaymentsApiException(
				'Error: Private upstream host refused the request.',
				'provider_internal_failure',
				500,
				'api_error'
			)
		);

		$data = $sut->get_data();

		$this->assertSame( 'Error: Private upstream host refused the request.', $data[ PaymentOutcome::DATA_ERROR_MESSAGE ] );
		$this->assertSame(
			"We're not able to process this request. Please refresh the page and try again.",
			$data[ PaymentOutcome::DATA_SHOPPER_ERROR_MESSAGE ] ?? null
		);
	}

	/**
	 * @testdox Failed transport outcomes carry the declined payment intent id so the order stays traceable.
	 */
	public function test_failed_transport_outcome_uses_intent_id_from_error_envelope(): void {
		$sut = WooPaymentsIntentCodec::failed_transport_outcome(
			'charge',
			new WooPaymentsApiException(
				'Error: Your card was declined.',
				'card_declined',
				402,
				'card_error',
				'do_not_honor',
				array(),
				'pi_declined_test'
			)
		);

		$this->assertSame( 'pi_declined_test', $sut->get_provider_payment_id(), 'A declined charge must keep its intent id so support can find the order from the transaction.' );
	}

	/**
	 * @testdox Failed transport outcomes prefer an explicitly supplied provider payment id over the envelope id.
	 */
	public function test_failed_transport_outcome_prefers_explicit_provider_payment_id(): void {
		$sut = WooPaymentsIntentCodec::failed_transport_outcome(
			'capture',
			new WooPaymentsApiException( 'Error: boom.', 'processing_error', 402, '', '', array(), 'pi_envelope' ),
			'pi_explicit'
		);

		$this->assertSame( 'pi_explicit', $sut->get_provider_payment_id() );
	}

	/**
	 * @testdox Failed amount_too_small transport outcomes surface the platform minimum and cache it per currency.
	 */
	public function test_failed_transport_outcome_surfaces_and_caches_the_platform_minimum(): void {
		delete_transient( 'wcpay_minimum_amount_usd' );

		$sut = WooPaymentsIntentCodec::failed_transport_outcome(
			'charge',
			new WooPaymentsApiException(
				'Amount must be at least $0.50 usd',
				'amount_too_small',
				400,
				'',
				'',
				array(
					'minimum_amount' => 50,
					'currency'       => 'usd',
				)
			)
		);

		$data = $sut->get_data();

		$this->assertSame( 'The selected payment method requires a total amount of at least $0.50.', $data[ PaymentOutcome::DATA_SHOPPER_ERROR_MESSAGE ] ?? null );
		$this->assertSame( 'Amount must be at least $0.50 usd', $data[ PaymentOutcome::DATA_ERROR_MESSAGE ] );
		$this->assertSame( 50, get_transient( 'wcpay_minimum_amount_usd' ), 'The platform floor must be cached per currency so the next attempt can fail before the API call.' );

		delete_transient( 'wcpay_minimum_amount_usd' );
	}

	/**
	 * @testdox Failed amount_too_small outcomes without a data payload keep the typeless platform message.
	 *
	 * Without a floor to render, the error is an ordinary typeless error (client `class-wc-payments-utils.php:770`, `:821`).
	 */
	public function test_failed_transport_outcome_amount_too_small_without_data_keeps_platform_message(): void {
		$sut = WooPaymentsIntentCodec::failed_transport_outcome(
			'charge',
			new WooPaymentsApiException( 'Amount too small', 'amount_too_small', 400 )
		);

		$this->assertSame( 'Amount too small', $sut->get_data()[ PaymentOutcome::DATA_SHOPPER_ERROR_MESSAGE ] ?? null );
	}

	/**
	 * @testdox Failed amount_too_large transport outcomes pass the redacted capture message through to the shopper surface.
	 */
	public function test_failed_transport_outcome_passes_amount_too_large_message_through(): void {
		$sut = WooPaymentsIntentCodec::failed_transport_outcome(
			'capture',
			new WooPaymentsApiException(
				'Error: The payment could not be captured because the requested capture amount is greater than the amount you can capture for this charge.',
				'amount_too_large',
				400,
				'invalid_request_error'
			),
			'pi_auth_test'
		);

		$this->assertSame(
			'Error: The payment could not be captured because the requested capture amount is greater than the amount you can capture for this charge.',
			$sut->get_data()[ PaymentOutcome::DATA_SHOPPER_ERROR_MESSAGE ] ?? null
		);
	}

	/**
	 * @testdox Legacy mapping uses the supplied snapshot without reloading an order.
	 */
	public function test_legacy_result_uses_supplied_snapshot_without_reloading_order(): void {
		$outcome = WooPaymentsIntentCodec::outcome_from_legacy_result(
			array(
				'result'   => 'success',
				'redirect' => 'https://example.test/order-received/42',
			),
			WooPaymentsIntentMappingContext::for_legacy(
				42,
				'https://example.test/order-received/42',
				10.0,
				'pi_manual',
				'pm_manual',
				'requires_capture'
			)
		);

		$this->assertSame( PaymentOutcome::STATUS_AUTHORIZED, $outcome->get_status() );
		$this->assertSame( 'pi_manual', $outcome->get_provider_payment_id() );
		$this->assertSame( 'pm_manual', $outcome->get_payment_method_id() );
	}

	/**
	 * @testdox Failed refund mapping preserves raw provider error facts.
	 */
	public function test_failed_refund_mapping_preserves_raw_error_facts(): void {
		$outcome = WooPaymentsIntentCodec::outcome_from_refund_result(
			array(
				'id'             => 're_failed',
				'status'         => 'failed',
				'failure_reason' => 'lost_or_stolen_card',
			)
		);

		$this->assertSame( PaymentOutcome::STATUS_FAILED, $outcome->get_status() );
		$this->assertSame( 're_failed', $outcome->get_provider_payment_id() );
		$this->assertSame( 'lost_or_stolen_card', $outcome->get_data()[ PaymentOutcome::DATA_ERROR_CODE ] );
		$this->assertSame( 'lost_or_stolen_card', $outcome->get_data()[ PaymentOutcome::DATA_ERROR_MESSAGE ] );
	}

	/**
	 * @testdox An intent whose charges are $_dataName: fully refunded $fully_refunded, disputed $disputed.
	 *
	 * A PaymentIntent can make several charge attempts, and listing its charges shows "both successful and unsuccessful
	 * attempts", at most one of them succeeded (Stripe docs, "The Payment Intents API", Identifying charges on a
	 * PaymentIntent). Stripe lists newest first (API reference, Pagination), so the succeeded charge usually comes before
	 * earlier declines; both orders are covered.
	 *
	 * @dataProvider charge_attempt_lists
	 *
	 * @param array<int,array<string,mixed>> $charges        The intent's `charges.data`.
	 * @param bool                           $fully_refunded Expected is_fully_refunded().
	 * @param bool                           $disputed       Expected is_disputed().
	 */
	public function test_refund_and_dispute_checks_read_every_charge_attempt( array $charges, bool $fully_refunded, bool $disputed ): void {
		$intent = array(
			'id'      => 'pi_attempts',
			'object'  => 'payment_intent',
			'status'  => 'succeeded',
			'amount'  => 1200,
			// The PaymentIntent `charges` list as the platform returns it on Stripe-Version 2020-08-27.
			'charges' => array(
				'object'      => 'list',
				'data'        => $charges,
				'has_more'    => false,
				'total_count' => count( $charges ),
				'url'         => '/v1/charges?payment_intent=pi_attempts',
			),
		);

		$this->assertSame( $fully_refunded, WooPaymentsIntentCodec::is_fully_refunded( $intent ), 'is_fully_refunded()' );
		$this->assertSame( $disputed, WooPaymentsIntentCodec::is_disputed( $intent ), 'is_disputed()' );
		$this->assertSame( $fully_refunded || $disputed, WooPaymentsIntentCodec::has_given_money_back( $intent ), 'has_given_money_back()' );
	}

	/**
	 * Charge attempt lists of one PaymentIntent.
	 *
	 * @return array<string,array{0:array<int,array<string,mixed>>,1:bool,2:bool}>
	 */
	public function charge_attempt_lists(): array {
		$declined_first  = self::failed_charge_attempt( 'ch_declined_1' );
		$declined_second = self::failed_charge_attempt( 'ch_declined_2' );
		$fully_refunded  = self::succeeded_charge(
			array(
				'refunded'        => true,
				'amount_refunded' => 1200,
			)
		);

		return array(
			'refunded after two declines, newest first' => array( array( $fully_refunded, $declined_second, $declined_first ), true, false ),
			'refunded after two declines, oldest first' => array( array( $declined_first, $declined_second, $fully_refunded ), true, false ),
			'refunded up to its amount after a decline' => array( array( self::succeeded_charge( array( 'amount_refunded' => 1200 ) ), $declined_first ), true, false ),
			'disputed after a decline, newest first'    => array( array( self::succeeded_charge( array( 'disputed' => true ) ), $declined_first ), false, true ),
			'disputed after a decline, oldest first'    => array( array( $declined_first, self::succeeded_charge( array( 'disputed' => true ) ) ), false, true ),
			'partly refunded after a decline'           => array( array( $declined_first, self::succeeded_charge( array( 'amount_refunded' => 500 ) ) ), false, false ),
			'declines only'                             => array( array( $declined_second, $declined_first ), false, false ),
		);
	}

	/**
	 * A succeeded charge, reduced to the fields the checks read (Stripe API reference, "The Charge object": `refunded` is
	 * true only once the charge is fully refunded, a partial refund leaves it false and raises `amount_refunded`).
	 *
	 * @param array<string,mixed> $fields Fields to change.
	 * @return array<string,mixed>
	 */
	private static function succeeded_charge( array $fields = array() ): array {
		return array_merge(
			array(
				'id'              => 'ch_succeeded',
				'object'          => 'charge',
				'amount'          => 1200,
				'amount_captured' => 1200,
				'amount_refunded' => 0,
				'captured'        => true,
				'disputed'        => false,
				'paid'            => true,
				'payment_intent'  => 'pi_attempts',
				'refunded'        => false,
				'status'          => 'succeeded',
			),
			$fields
		);
	}

	/**
	 * A declined charge attempt of the same intent (Stripe API reference, "The Charge object": a failed charge is not
	 * paid, captured, refunded or disputed and names its failure_code).
	 *
	 * @param string $id Charge ID.
	 * @return array<string,mixed>
	 */
	private static function failed_charge_attempt( string $id ): array {
		return array(
			'id'              => $id,
			'object'          => 'charge',
			'amount'          => 1200,
			'amount_captured' => 0,
			'amount_refunded' => 0,
			'captured'        => false,
			'disputed'        => false,
			'failure_code'    => 'card_declined',
			'paid'            => false,
			'payment_intent'  => 'pi_attempts',
			'refunded'        => false,
			'status'          => 'failed',
		);
	}
}
