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
	 * @testdox A succeeded intent maps to a completed outcome whose data holds only the charge ID.
	 */
	public function test_outcome_from_intention_maps_a_succeeded_intent(): void {
		$outcome = WooPaymentsIntentCodec::outcome_from_intention(
			// Shape: the succeeded PaymentIntent in Fixtures/platform-intention-basic-card.json (entries[0]), with charges as a Stripe list
			// object and an expanded balance_transaction, reduced to the fields the codec reads.
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
			new WooPaymentsIntentMappingContext( 'https://example.test/order-received/42' )
		);

		$data = $outcome->get_data();

		$this->assertSame( PaymentOutcome::STATUS_COMPLETED, $outcome->get_status() );
		$this->assertSame( 'pi_neutral', $outcome->get_provider_payment_id() );
		$this->assertSame( 'pm_neutral', $outcome->get_payment_method_id() );
		$this->assertSame( 'cus_neutral', $outcome->get_customer_id() );
		$this->assertSame( array( 'charge_id' => 'ch_neutral' ), $data );
	}

	/**
	 * @testdox Asynchronous debit intents map to authorized outcomes without local effects.
	 */
	public function test_outcome_from_intention_maps_processing_intent_to_authorized_outcome(): void {
		$outcome = WooPaymentsIntentCodec::outcome_from_intention(
			// Shape: a processing PaymentIntent, reduced to the fields the client deserializer reads
			// (client 11.1.0 class-wc-payments-api-client.php:2403-2409); client 11.1.0 class-wc-payments-order-service.php:410
			// treats processing as authorized.
			array(
				'id'             => 'pi_sepa',
				'status'         => 'processing',
				'customer'       => 'cus_sepa',
				'payment_method' => 'pm_sepa',
			),
			new WooPaymentsIntentMappingContext( 'https://example.test/order-received/42' )
		);

		$this->assertSame( PaymentOutcome::STATUS_AUTHORIZED, $outcome->get_status() );
		$this->assertSame( array(), $outcome->get_data() );
	}

	/**
	 * @testdox Customer-action intents use the prebuilt boundary redirect.
	 */
	public function test_outcome_from_intention_uses_supplied_customer_action_redirect(): void {
		$outcome = WooPaymentsIntentCodec::outcome_from_intention(
			// Shape: a requires_action PaymentIntent with no next_action body, which the client deserializer reads as an empty
			// next action (client 11.1.0 class-wc-payments-api-client.php:2404); client 11.1.0 class-wc-payment-gateway-wcpay.php:2107
			// sends any next action other than a URL redirect or Multibanco details to the confirmation hash.
			array(
				'id'                   => 'pi_action',
				'status'               => 'requires_action',
				'client_secret'        => 'secret_action',
				'payment_method'       => 'pm_action',
				'payment_method_types' => array( 'wechat_pay' ),
			),
			new WooPaymentsIntentMappingContext(
				'https://example.test/order-received/42',
				'pm_request',
				'cus_fallback',
				'#wcpay-confirm-pi:42:secret_action:explicit_nonce'
			)
		);

		$this->assertSame( PaymentOutcome::STATUS_REQUIRES_CUSTOMER_ACTION, $outcome->get_status() );
		$this->assertSame( '#wcpay-confirm-pi:42:secret_action:explicit_nonce', $outcome->get_redirect_url() );
		$this->assertSame( 'cus_fallback', $outcome->get_customer_id() );
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
			new WooPaymentsIntentMappingContext(
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
		// A filter that rewrites every URL: the outcome must carry the context's URL as it is, filtered once by its caller.
		$clean_url = static function ( string $url ): string {
			unset( $url );

			return 'https://filtered.example/should-not-run';
		};
		add_filter( 'clean_url', $clean_url );

		try {
			$outcome = WooPaymentsIntentCodec::outcome_from_intention(
				// Shape: next_action.redirect_to_url.url, the field client 11.1.0 class-wc-payment-gateway-wcpay.php:2094 reads.
				array(
					'id'          => 'pi_ideal',
					'status'      => 'requires_action',
					'next_action' => array(
						'type'            => 'redirect_to_url',
						'redirect_to_url' => array( 'url' => 'https://hooks.stripe.com/redirect/ideal' ),
					),
				),
				new WooPaymentsIntentMappingContext(
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
	}

	/**
	 * @testdox Multibanco voucher intents use the explicitly supplied order-received URL.
	 */
	public function test_outcome_from_intention_uses_supplied_order_received_url_for_multibanco(): void {
		$outcome = WooPaymentsIntentCodec::outcome_from_intention(
			// Shape: the multibanco_display_details next action client 11.1.0 class-wc-payment-gateway-wcpay.php:2099-2106 reads,
			// reduced to its reference; the codec reads only the next-action type.
			array(
				'id'          => 'pi_multibanco',
				'status'      => 'requires_action',
				'next_action' => array(
					'type'                       => 'multibanco_display_details',
					'multibanco_display_details' => array( 'reference' => '123 456 789' ),
				),
			),
			new WooPaymentsIntentMappingContext( 'https://example.test/order-received/42' )
		);

		$this->assertSame( PaymentOutcome::STATUS_AUTHORIZED, $outcome->get_status() );
		$this->assertSame( 'https://example.test/order-received/42', $outcome->get_redirect_url() );
	}

	/**
	 * @testdox The latest charge payment method precedes submitted credential fallback.
	 */
	public function test_outcome_from_intention_uses_charge_payment_method_before_credential_fallback(): void {
		$outcome = WooPaymentsIntentCodec::outcome_from_intention(
			// Shape: charges.data[].payment_method as in Fixtures/platform-intention-basic-card.json (entries[0]), reduced to that one field.
			array(
				'id'      => 'pi_charge_pm',
				'status'  => 'succeeded',
				'charges' => array(
					'data' => array( array( 'payment_method' => 'pm_from_charge' ) ),
				),
			),
			new WooPaymentsIntentMappingContext( '', 'ctoken_submitted' )
		);

		$this->assertSame( 'pm_from_charge', $outcome->get_payment_method_id() );
	}

	/**
	 * @testdox A failed intent ($_dataName) keeps the raw diagnostics apart from the shopper's copy.
	 *
	 * Client 11.1.0 `class-wc-payments-utils.php`: a card decline code gets its localized copy, an untyped error keeps its
	 * message (`:770`), a non-card error type gets the generic copy (`:797-798`).
	 *
	 * @dataProvider provider_failed_intent_shopper_messages
	 *
	 * @param string              $intent_id          Intent ID.
	 * @param array<string,mixed> $last_payment_error The intent's last_payment_error.
	 * @param string              $shopper_message    Expected shopper message.
	 */
	public function test_failed_intent_mapping_shopper_message( string $intent_id, array $last_payment_error, string $shopper_message ): void {
		$outcome = WooPaymentsIntentCodec::outcome_from_intention(
			array(
				'id'                 => $intent_id,
				'status'             => 'requires_payment_method',
				'last_payment_error' => $last_payment_error,
			),
			new WooPaymentsIntentMappingContext( 'https://example.test/order-received/42' )
		);

		$data = $outcome->get_data();

		$this->assertSame( PaymentOutcome::STATUS_FAILED, $outcome->get_status() );
		$this->assertSame( $last_payment_error['code'], $data[ PaymentOutcome::DATA_ERROR_CODE ] );
		$this->assertSame( $last_payment_error['message'], $data[ PaymentOutcome::DATA_ERROR_MESSAGE ] );
		$this->assertSame( $shopper_message, $data[ WooPaymentsIntentCodec::SHOPPER_ERROR_MESSAGE_KEY ] );
	}

	/**
	 * Failed intents and the shopper copy each gets.
	 *
	 * @return array<string,array{0:string,1:array<string,string>,2:string}>
	 */
	public function provider_failed_intent_shopper_messages(): array {
		$card_testing = "Error: We're not able to add this payment method. Please try again later.";

		return array(
			// Shape: last_payment_error from Fixtures/platform-intention-declines.json (entries[2]: card_error, card_declined,
			// insufficient_funds), with a synthetic diagnostic message.
			'card decline, insufficient funds' => array(
				'pi_declined',
				array(
					'type'         => 'card_error',
					'code'         => 'card_declined',
					'decline_code' => 'insufficient_funds',
					'message'      => 'Provider diagnostic: balance check failed for request req_private.',
				),
				'Error: Your card has insufficient funds.',
			),
			// The platform's own card-testing refusal, which carries no Stripe error type; client 11.1.0
			// class-wc-payments-api-client.php:2952 reads the code.
			'grounded card-testing message'    => array(
				'pi_card_testing_prevention',
				array(
					'type'    => '',
					'code'    => 'wcpay_card_testing_prevention',
					'message' => $card_testing,
				),
				$card_testing,
			),
			// Synthetic: provider_internal_failure stands for any code the message catalog does not know.
			'unknown non-card error'           => array(
				'pi_unknown_failure',
				array(
					'type'    => 'api_error',
					'code'    => 'provider_internal_failure',
					'message' => 'Raw provider secret must remain diagnostic only.',
				),
				"We're not able to process this request. Please refresh the page and try again.",
			),
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
				// Deliberately malformed: type and decline_code are arrays, which no platform response carries.
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
				new WooPaymentsIntentMappingContext( 'https://example.test/order-received/42' )
			);
		} finally {
			restore_error_handler();
		}

		$data = $outcome->get_data();

		$this->assertSame( 'Malformed provider metadata for request req_private.', $data[ PaymentOutcome::DATA_ERROR_MESSAGE ] );
		// A non-string type is dropped, so the error is typeless and keeps its message (client `class-wc-payments-utils.php:770`).
		$this->assertSame( 'Malformed provider metadata for request req_private.', $data[ WooPaymentsIntentCodec::SHOPPER_ERROR_MESSAGE_KEY ] );
		$this->assertSame( array(), $warnings );
	}

	/**
	 * @testdox Failed transport outcomes carry the platform status and an amount_too_small minimum, as the admin capture route answers them.
	 */
	public function test_failed_transport_outcome_carries_status_and_minimum_amount(): void {
		// The platform's top-level amount_too_small envelope, mapped by the client to Amount_Too_Small_Exception (class-wc-payments-api-client.php:2845-2851).
		$too_small = WooPaymentsIntentCodec::failed_transport_outcome(
			'capture',
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
		$declined  = WooPaymentsIntentCodec::failed_transport_outcome( 'capture', new WooPaymentsApiException( 'Declined.', 'card_declined', 402 ) );

		$this->assertSame( 400, $too_small->get_data()['http_code'] );
		$this->assertSame(
			array(
				'minimum_amount'          => 50,
				'minimum_amount_currency' => 'USD',
			),
			$too_small->get_data()['extra_details']
		);
		$this->assertSame( 402, $declined->get_data()['http_code'] );
		$this->assertSame( array(), $declined->get_data()['extra_details'] );
	}

	/**
	 * @testdox A failed $operation transport outcome ($_dataName) keeps the platform's diagnostics and shows the shopper the client's copy.
	 *
	 * Client 11.1.0 `class-wc-payments-utils.php:770-821`: a card decline code gets its localized copy, a typed non-card error
	 * the generic copy (`:797-798`), and grounded or typeless messages pass through.
	 *
	 * @dataProvider provider_failed_transport_shopper_messages
	 *
	 * @param string $operation           Operation that failed.
	 * @param string $message             Normalized platform message.
	 * @param string $error_code          Error code.
	 * @param int    $http_code           HTTP status.
	 * @param string $error_type          Error type.
	 * @param string $decline_code        Decline code.
	 * @param string $provider_payment_id Explicit provider payment ID, '' for none (the default).
	 * @param string $shopper_message     Expected shopper message.
	 */
	public function test_failed_transport_outcome_shopper_message( string $operation, string $message, string $error_code, int $http_code, string $error_type, string $decline_code, string $provider_payment_id, string $shopper_message ): void {
		$outcome = WooPaymentsIntentCodec::failed_transport_outcome(
			$operation,
			new WooPaymentsApiException( $message, $error_code, $http_code, $error_type, $decline_code ),
			$provider_payment_id
		);

		$data = $outcome->get_data();

		$this->assertSame( PaymentOutcome::STATUS_FAILED, $outcome->get_status() );
		$this->assertSame( $error_code, $data[ PaymentOutcome::DATA_ERROR_CODE ] );
		$this->assertSame( $message, $data[ PaymentOutcome::DATA_ERROR_MESSAGE ] );
		$this->assertSame( $shopper_message, $data[ WooPaymentsIntentCodec::SHOPPER_ERROR_MESSAGE_KEY ] ?? null );
		$this->assertSame( $operation, $data['operation'] );
	}

	/**
	 * Failed transport errors and the shopper copy each gets.
	 *
	 * @return array<string,array{0:string,1:string,2:string,3:int,4:string,5:string,6:string,7:string}>
	 */
	public function provider_failed_transport_shopper_messages(): array {
		$generic = "We're not able to process this request. Please refresh the page and try again.";
		$too_big = 'Error: The payment could not be captured because the requested capture amount is greater than the amount you can capture for this charge.';

		return array(
			'card decline, insufficient funds' => array( 'charge', 'Error: Provider diagnostic for request req_private.', 'card_declined', 402, 'card_error', 'insufficient_funds', '', 'Error: Your card has insufficient funds.' ),
			'card-testing prevention'          => array( 'charge', "Error: We're not able to add this payment method. Please try again later.", 'wcpay_card_testing_prevention', 400, '', '', '', "Error: We're not able to add this payment method. Please try again later." ),
			'unmapped card error'              => array( 'charge', 'Error: Your card does not support this type of purchase.', 'provider_internal_detail', 400, 'card_error', '', '', 'Error: Your card does not support this type of purchase.' ),
			'typed invalid request error'      => array( 'charge', 'Error: Invalid string length: 55555501004242424242424242 must be at most 20 characters', 'invalid_request_error', 400, 'invalid_request_error', '', '', $generic ),
			'non-card api error'               => array( 'charge', 'Error: Private upstream host refused the request.', 'provider_internal_failure', 500, 'api_error', '', '', $generic ),
			'amount_too_small without data'    => array( 'charge', 'Amount too small', 'amount_too_small', 400, '', '', '', 'Amount too small' ),
			'amount_too_large capture'         => array( 'capture', $too_big, 'amount_too_large', 400, 'invalid_request_error', '', 'pi_auth_test', $too_big ),
		);
	}

	/**
	 * @testdox A failed transport outcome keeps the intent ID ($_dataName), so the failed order stays traceable from the transaction.
	 *
	 * @dataProvider provider_failed_transport_provider_payment_ids
	 *
	 * @param string                  $operation           Operation that failed.
	 * @param WooPaymentsApiException $exception           Platform error, with the intent ID of its envelope.
	 * @param string                  $provider_payment_id Explicit provider payment ID, '' for none (the default).
	 * @param string                  $expected            Provider payment ID the outcome carries.
	 */
	public function test_failed_transport_outcome_provider_payment_id( string $operation, WooPaymentsApiException $exception, string $provider_payment_id, string $expected ): void {
		$sut = WooPaymentsIntentCodec::failed_transport_outcome( $operation, $exception, $provider_payment_id );

		$this->assertSame( $expected, $sut->get_provider_payment_id(), 'A declined charge must keep its intent id so support can find the order from the transaction.' );
	}

	/**
	 * Failed transport outcomes and the intent ID each keeps.
	 *
	 * @return array<string,array{0:string,1:WooPaymentsApiException,2:string,3:string}>
	 */
	public function provider_failed_transport_provider_payment_ids(): array {
		return array(
			'envelope ID only'          => array( 'charge', new WooPaymentsApiException( 'Error: Your card was declined.', 'card_declined', 402, 'card_error', 'do_not_honor', array(), 'pi_declined_test' ), '', 'pi_declined_test' ),
			'explicit ID over envelope' => array( 'capture', new WooPaymentsApiException( 'Error: boom.', 'processing_error', 402, '', '', array(), 'pi_envelope' ), 'pi_explicit', 'pi_explicit' ),
		);
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

		$this->assertSame( 'The selected payment method requires a total amount of at least $0.50.', $data[ WooPaymentsIntentCodec::SHOPPER_ERROR_MESSAGE_KEY ] ?? null );
		$this->assertSame( 'Amount must be at least $0.50 usd', $data[ PaymentOutcome::DATA_ERROR_MESSAGE ] );
		$this->assertSame( 50, get_transient( 'wcpay_minimum_amount_usd' ), 'The platform floor must be cached per currency so the next attempt can fail before the API call.' );

		delete_transient( 'wcpay_minimum_amount_usd' );
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
	 * @testdox An intent whose charge attempts are $_dataName: fully refunded $fully_refunded, disputed $disputed.
	 *
	 * Lists shaped as the platform returns them. A PaymentIntent can make several charge attempts, and listing its charges
	 * shows "both successful and unsuccessful attempts", at most one of them succeeded (Stripe docs, "The Payment Intents
	 * API", Identifying charges on a PaymentIntent). Stripe lists them newest first (API reference, Pagination,
	 * https://docs.stripe.com/api/pagination), so a succeeded charge comes before the declines it followed. An intent whose
	 * attempts were all declined is back to `requires_payment_method` (Stripe docs, "How PaymentIntents work").
	 *
	 * @dataProvider charge_attempt_lists
	 *
	 * @param string                         $intent_status  The intent's `status`.
	 * @param array<int,array<string,mixed>> $charges        The intent's `charges.data`.
	 * @param bool                           $fully_refunded Expected is_fully_refunded().
	 * @param bool                           $disputed       Expected is_disputed().
	 */
	public function test_refund_and_dispute_checks_read_every_charge_attempt( string $intent_status, array $charges, bool $fully_refunded, bool $disputed ): void {
		$this->assert_given_back_checks( self::intent_with_charges( $intent_status, $charges ), $fully_refunded, $disputed );
	}

	/**
	 * Charge attempt lists of one PaymentIntent, as the platform returns them.
	 *
	 * @return array<string,array{0:string,1:array<int,array<string,mixed>>,2:bool,3:bool}>
	 */
	public function charge_attempt_lists(): array {
		$declined_first  = self::failed_charge_attempt( 'ch_declined_1' );
		$declined_second = self::failed_charge_attempt( 'ch_declined_2' );

		return array(
			'a refunded charge after two declines'     => array( 'succeeded', array( self::fully_refunded_charge(), $declined_second, $declined_first ), true, false ),
			'a disputed charge after a decline'        => array( 'succeeded', array( self::succeeded_charge( array( 'disputed' => true ) ), $declined_first ), false, true ),
			'a partly refunded charge after a decline' => array( 'succeeded', array( self::succeeded_charge( array( 'amount_refunded' => 500 ) ), $declined_first ), false, false ),
			'declines only'                            => array( 'requires_payment_method', array( $declined_second, $declined_first ), false, false ),
		);
	}

	/**
	 * @testdox Defensive input the platform does not send, $_dataName: fully refunded $fully_refunded, disputed $disputed.
	 *
	 * These lists break Stripe's contract on purpose, so the checks do not rely on it: a list in the reverse of Stripe's
	 * newest-first order (API reference, Pagination, https://docs.stripe.com/api/pagination), and a charge refunded up to
	 * its amount whose `refunded` is still false, which Stripe sets once a charge is fully refunded (API reference, "The
	 * Charge object", https://docs.stripe.com/api/charges/object).
	 *
	 * @dataProvider defensive_charge_attempt_lists
	 *
	 * @param array<int,array<string,mixed>> $charges        The intent's `charges.data`.
	 * @param bool                           $fully_refunded Expected is_fully_refunded().
	 * @param bool                           $disputed       Expected is_disputed().
	 */
	public function test_refund_and_dispute_checks_hold_for_defensive_charge_lists( array $charges, bool $fully_refunded, bool $disputed ): void {
		$this->assert_given_back_checks( self::intent_with_charges( 'succeeded', $charges ), $fully_refunded, $disputed );
	}

	/**
	 * Charge attempt lists that break Stripe's contract.
	 *
	 * @return array<string,array{0:array<int,array<string,mixed>>,1:bool,2:bool}>
	 */
	public function defensive_charge_attempt_lists(): array {
		$declined_first  = self::failed_charge_attempt( 'ch_declined_1' );
		$declined_second = self::failed_charge_attempt( 'ch_declined_2' );

		return array(
			'a refunded charge listed after its declines' => array( array( $declined_first, $declined_second, self::fully_refunded_charge() ), true, false ),
			'a disputed charge listed after its decline'  => array( array( $declined_first, self::succeeded_charge( array( 'disputed' => true ) ) ), false, true ),
			'a fully refunded amount with refunded false' => array( array( self::succeeded_charge( array( 'amount_refunded' => 1200 ) ), $declined_first ), true, false ),
		);
	}

	/**
	 * Assert the two given-back checks on an intent.
	 *
	 * @param array<string,mixed> $intent         PaymentIntent.
	 * @param bool                $fully_refunded Expected is_fully_refunded().
	 * @param bool                $disputed       Expected is_disputed().
	 */
	private function assert_given_back_checks( array $intent, bool $fully_refunded, bool $disputed ): void {
		$this->assertSame( $fully_refunded, WooPaymentsIntentCodec::is_fully_refunded( $intent ), 'is_fully_refunded()' );
		$this->assertSame( $disputed, WooPaymentsIntentCodec::is_disputed( $intent ), 'is_disputed()' );
	}

	/**
	 * A PaymentIntent with the given status and charge attempts.
	 *
	 * @param string                         $status  The intent's `status`.
	 * @param array<int,array<string,mixed>> $charges The intent's `charges.data`.
	 * @return array<string,mixed>
	 */
	private static function intent_with_charges( string $status, array $charges ): array {
		return array(
			'id'      => 'pi_attempts',
			'object'  => 'payment_intent',
			'status'  => $status,
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
	}

	/**
	 * A fully refunded charge as Stripe returns it: `refunded` true and `amount_refunded` equal to its amount (API reference,
	 * "The Charge object", https://docs.stripe.com/api/charges/object).
	 *
	 * @return array<string,mixed>
	 */
	private static function fully_refunded_charge(): array {
		return self::succeeded_charge(
			array(
				'refunded'        => true,
				'amount_refunded' => 1200,
			)
		);
	}

	/**
	 * A succeeded charge, reduced to the fields the checks read (Stripe API reference, "The Charge object",
	 * https://docs.stripe.com/api/charges/object: `refunded` is true only once the charge is fully refunded, a partial refund
	 * leaves it false and raises `amount_refunded`).
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
