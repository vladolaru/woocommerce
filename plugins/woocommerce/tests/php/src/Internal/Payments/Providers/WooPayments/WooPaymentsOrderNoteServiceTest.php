<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments;

use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsOrderNoteService;
use WC_Order;
use WC_Unit_Test_Case;

/**
 * Tests for the WooPaymentsOrderNoteService class.
 */
class WooPaymentsOrderNoteServiceTest extends WC_Unit_Test_Case {
	/**
	 * Original option values restored after each test.
	 *
	 * @var array<string,mixed>
	 */
	private array $original_options = array();

	/**
	 * Test-only gettext replacements keyed by text domain and source text.
	 *
	 * @var array<string,array<string,string>>
	 */
	private array $gettext_replacements = array();

	/**
	 * Set up test fixtures.
	 */
	public function setUp(): void {
		parent::setUp();
		$this->original_options = array(
			'woocommerce_currency'                    => get_option( 'woocommerce_currency', null ),
			'_wcpay_feature_customer_multi_currency'  => get_option( '_wcpay_feature_customer_multi_currency', null ),
			'wcpay_multi_currency_enabled_currencies' => get_option( 'wcpay_multi_currency_enabled_currencies', null ),
			'wcpay_multi_currency_exchange_rate_eur'  => get_option( 'wcpay_multi_currency_exchange_rate_eur', null ),
			'wcpay_multi_currency_manual_rate_eur'    => get_option( 'wcpay_multi_currency_manual_rate_eur', null ),
		);
	}

	/**
	 * Tear down test fixtures.
	 */
	public function tearDown(): void {
		remove_filter( 'gettext', array( $this, 'translate_test_string' ), 10 );
		restore_current_locale();
		$this->gettext_replacements = array();
		foreach ( $this->original_options as $option_name => $option_value ) {
			if ( null === $option_value ) {
				delete_option( $option_name );
			} else {
				update_option( $option_name, $option_value );
			}
		}
		$this->original_options = array();
		parent::tearDown();
	}

	/**
	 * @testdox Payment and capture notes preserve reference copy and explicit currency.
	 */
	public function test_formats_payment_and_capture_notes_with_reference_copy_and_explicit_currency(): void {
		$order = wc_create_order();
		$this->assertInstanceOf( WC_Order::class, $order );
		$order->set_currency( 'USD' );
		$order->set_total( '25.00' );
		$order->save();
		$sut             = new WooPaymentsOrderNoteService();
		$transaction_url = $sut->transaction_url( 'pi_test_charge', 'ch_test_charge', 'txn_test_charge' );

		$this->assertSame(
			sprintf(
				'A payment of %1$s USD was <strong>successfully charged</strong> using WooPayments (<a href="%2$s" target="_blank" rel="noopener noreferrer">pi_test_charge</a>).',
				wc_price( 25.00, array( 'currency' => 'USD' ) ),
				$transaction_url
			),
			$sut->format_payment_success_note( $order, 'pi_test_charge', 'ch_test_charge', 'txn_test_charge' )
		);
		$this->assertStringContainsString(
			'successfully captured</strong> using WooPayments',
			$sut->format_capture_success_note( $order, 'pi_test_charge', 'ch_test_charge', 'txn_test_charge' )
		);
		$this->assertStringContainsString(
			'A capture of',
			$sut->format_capture_failed_note( $order, 'pi_test_charge', 'ch_test_charge', 'Capture failed.' )
		);
		$this->assertStringContainsString(
			'Capture failed.',
			$sut->format_capture_failed_note( $order, 'pi_test_charge', 'ch_test_charge', 'Capture failed.' )
		);
	}

	/**
	 * @testdox Authorization and started notes preserve WooPayments reference copy.
	 */
	public function test_formats_authorization_and_started_notes(): void {
		$order = wc_create_order();
		$this->assertInstanceOf( WC_Order::class, $order );
		$order->set_currency( 'EUR' );
		$order->set_total( '65.00' );
		$sut = new WooPaymentsOrderNoteService();

		$this->assertStringContainsString( '<strong>authorized</strong> using WooPayments', $sut->format_payment_authorized_note( $order, 'pi_authorized', 'ch_authorized' ) );
		$this->assertStringContainsString( '<strong>started</strong> using WooPayments', $sut->format_payment_started_note( $order, 'pi_started' ) );
	}

	/**
	 * @testdox Every known lifecycle note exposes finite exact Core and plugin catalog candidates.
	 */
	public function test_lifecycle_note_candidate_matrix(): void {
		update_option( 'woocommerce_currency', 'USD' );
		delete_option( '_wcpay_feature_customer_multi_currency' );
		delete_option( 'wcpay_multi_currency_enabled_currencies' );
		delete_option( 'wcpay_multi_currency_exchange_rate_eur' );
		delete_option( 'wcpay_multi_currency_manual_rate_eur' );
		$order = wc_create_order();
		$this->assertInstanceOf( WC_Order::class, $order );
		$order->set_currency( 'USD' );
		$order->set_total( '25.00' );
		$order->save();
		$sut = wc_get_container()->get( WooPaymentsOrderNoteService::class );
		$this->install_test_translations(
			array(
				'woocommerce-payments' => array(
					'A payment of %1$s was <strong>successfully charged</strong> using %2$s (<a>%3$s</a>).' => 'Legacy payment %1$s was <strong>charged</strong> using %2$s (<a>%3$s</a>).',
					'A payment of %1$s was <strong>authorized</strong> using %2$s (<a>%3$s</a>).' => 'Legacy payment %1$s was <strong>authorized</strong> using %2$s (<a>%3$s</a>).',
					'A payment of %1$s was <strong>started</strong> using %2$s (<code>%3$s</code>).' => 'Legacy payment %1$s was <strong>started</strong> using %2$s (<code>%3$s</code>).',
					'A payment of %1$s was <strong>successfully captured</strong> using %2$s (<a>%3$s</a>).' => 'Legacy payment %1$s was <strong>captured</strong> using %2$s (<a>%3$s</a>).',
					'A capture of %1$s <strong>failed</strong> to complete using %2$s (<a>%3$s</a>).' => 'Legacy capture %1$s <strong>failed</strong> using %2$s (<a>%3$s</a>).',
					'Payment authorization was successfully <strong>cancelled</strong> (<a>%1$s</a>).' => 'Legacy authorization was <strong>cancelled</strong> (<a>%1$s</a>).',
					'A payment of %1$s <strong>failed</strong> using %2$s (<a>%3$s</a>).'          => 'Legacy payment %1$s <strong>failed</strong> using %2$s (<a>%3$s</a>).',
					'A terminal payment of %1$s <strong>failed</strong> using %2$s (<a>%3$s</a>)' => 'Legacy terminal payment %1$s <strong>failed</strong> using %2$s (<a>%3$s</a>)',
					'Payment authorization has <strong>expired</strong> (<a>%1$s</a>).'            => 'Legacy authorization has <strong>expired</strong> (<a>%1$s</a>).',
					'With the following message: <code>%s</code>'                                 => 'Legacy diagnostic: <code>%s</code>',
					"The customer's account has insufficient funds to cover this payment."       => 'Legacy insufficient funds.',
				),
			)
		);
		switch_to_locale( 'de_DE' );

		$cases             = array(
			'payment success'       => array( 'format_payment_success_note_candidates', array( $order, 'pi_success', 'ch_success', 'txn_success' ), 'format_payment_success_note' ),
			'payment authorized'    => array( 'format_payment_authorized_note_candidates', array( $order, 'pi_authorized', 'ch_authorized' ), 'format_payment_authorized_note' ),
			'payment started'       => array( 'format_payment_started_note_candidates', array( $order, 'pi_started' ), 'format_payment_started_note' ),
			'capture success'       => array( 'format_capture_success_note_candidates', array( $order, 'pi_captured', 'ch_captured', 'txn_captured' ), 'format_capture_success_note' ),
			'capture failure'       => array( 'format_capture_failed_note_candidates', array( $order, 'pi_failed', 'ch_failed', 'Provider diagnostic.' ), 'format_capture_failed_note' ),
			'capture cancellation'  => array( 'format_capture_cancelled_note_candidates', array( 'pi_canceled', 'ch_canceled' ), 'format_capture_cancelled_note' ),
			'payment failure'       => array(
				'format_payment_failed_note_candidates',
				array(
					$order,
					'pi_failed_payment',
					'ch_failed_payment',
					array( 'message' => 'Issuer unavailable.' ),
				),
				null,
			),
			'terminal failure'      => array(
				'format_terminal_payment_failed_note_candidates',
				array(
					$order,
					'pi_failed_terminal',
					'ch_failed_terminal',
					array( 'code' => 'insufficient_funds' ),
				),
				null,
			),
			'authorization expired' => array( 'format_capture_expired_note_candidates', array( 'pi_expired', 'ch_expired' ), null ),
		);
		$amount_case_names = array(
			'payment success',
			'payment authorized',
			'payment started',
			'capture success',
			'capture failure',
			'payment failure',
			'terminal failure',
		);

		foreach ( $cases as $case_name => $case ) {
			list( $candidate_method, $arguments, $native_method ) = $case;
			$this->assertTrue( method_exists( $sut, $candidate_method ), "Missing candidate formatter for {$case_name}." );
			if ( ! method_exists( $sut, $candidate_method ) ) {
				continue;
			}

			$candidates = $sut->{$candidate_method}( ...$arguments );
			$this->assertCount( 2, $candidates, "Expected Core and plugin candidates for {$case_name}." );
			$this->assertStringContainsString( 'Legacy', $candidates[1], "Expected the plugin catalog rendering for {$case_name}." );
			if ( null !== $native_method ) {
				$this->assertSame( $sut->{$native_method}( ...$arguments ), $candidates[0], "Native rendering must stay first for {$case_name}." );
			}
			if ( in_array( $case_name, $amount_case_names, true ) ) {
				$this->assertStringContainsString( ' USD ', $candidates[0], "Native {$case_name} candidate must preserve its explicit currency suffix." );
				$this->assertStringNotContainsString( ' USD ', $candidates[1], "Default-store plugin {$case_name} candidate must match the plugin's suffix-free amount." );
			}
		}

		update_option( '_wcpay_feature_customer_multi_currency', '1' );
		update_option( 'wcpay_multi_currency_enabled_currencies', array( 'EUR' ) );
		foreach ( $amount_case_names as $case_name ) {
			list( $candidate_method, $arguments ) = $cases[ $case_name ];
			$candidates                           = $sut->{$candidate_method}( ...$arguments );
			$this->assertCount( 3, $candidates, "Ambiguous plugin readiness must preserve both historical amount variants for {$case_name}." );
			$this->assertStringContainsString( ' USD ', $candidates[0], "Native {$case_name} candidate must stay unchanged with an uninitialized enabled-currency list." );
			$this->assertStringNotContainsString( ' USD ', $candidates[1], "Ambiguous plugin {$case_name} candidates must include the suffix-free historical rendering first." );
			$this->assertStringContainsString( ' USD ', $candidates[2], "Ambiguous plugin {$case_name} candidates must also include the explicit-currency historical rendering." );
		}

		update_option( 'wcpay_multi_currency_exchange_rate_eur', 'manual' );
		update_option( 'wcpay_multi_currency_manual_rate_eur', '0.90' );
		foreach ( $amount_case_names as $case_name ) {
			list( $candidate_method, $arguments ) = $cases[ $case_name ];
			$candidates                           = $sut->{$candidate_method}( ...$arguments );
			$this->assertCount( 3, $candidates, "Configured plugin readiness must preserve both historical amount variants for {$case_name}." );
			$this->assertStringContainsString( ' USD ', $candidates[0], "Native {$case_name} candidate must stay unchanged when multi-currency is enabled." );
			$this->assertStringNotContainsString( ' USD ', $candidates[1], "Configured plugin {$case_name} candidates must include the suffix-free historical rendering first." );
			$this->assertStringContainsString( ' USD ', $candidates[2], "Configured plugin {$case_name} candidates must also include the explicit-currency historical rendering." );
		}

		update_option( '_wcpay_feature_customer_multi_currency', '0' );
		foreach ( $amount_case_names as $case_name ) {
			list( $candidate_method, $arguments ) = $cases[ $case_name ];
			$candidates                           = $sut->{$candidate_method}( ...$arguments );
			$this->assertStringContainsString( ' USD ', $candidates[0], "Native {$case_name} candidate must stay unchanged when the plugin feature is disabled." );
			$this->assertStringNotContainsString( ' USD ', $candidates[1], "Feature-disabled plugin {$case_name} candidate must ignore stale multi-currency readiness data." );
		}

		$this->assertStringEndsWith( ' Provider diagnostic.', $sut->format_capture_failed_note_candidates( $order, 'pi_failed', 'ch_failed', 'Provider diagnostic.' )[0] );
		$this->assertStringEndsWith( ' Provider diagnostic.', $sut->format_capture_failed_note_candidates( $order, 'pi_failed', 'ch_failed', 'Provider diagnostic.' )[1] );
		$this->assertStringEndsWith(
			' Legacy diagnostic: <code>Issuer unavailable.</code>',
			$sut->format_payment_failed_note_candidates( $order, 'pi_failed_payment', 'ch_failed_payment', array( 'message' => 'Issuer unavailable.' ) )[1]
		);
		$this->assertStringEndsWith(
			' Legacy insufficient funds.',
			$sut->format_terminal_payment_failed_note_candidates( $order, 'pi_failed_terminal', 'ch_failed_terminal', array( 'code' => 'insufficient_funds' ) )[1]
		);
		$this->assertStringContainsString(
			'id=ch_failed_terminal',
			$sut->format_terminal_payment_failed_note_candidates( $order, 'pi_failed_terminal', 'ch_failed_terminal', array( 'code' => 'insufficient_funds' ) )[0],
			'Terminal failures must link by charge ID.'
		);
		$this->assertStringContainsString(
			'>pi_failed_terminal</a>',
			$sut->format_terminal_payment_failed_note_candidates( $order, 'pi_failed_terminal', 'ch_failed_terminal', array( 'code' => 'insufficient_funds' ) )[0],
			'Terminal failures must display the intent ID.'
		);
		$terminal_without_intent = $sut->format_terminal_payment_failed_note_candidates( $order, '', 'ch_failed_terminal', array( 'code' => 'insufficient_funds' ) )[0];
		$this->assertStringContainsString( 'id=ch_failed_terminal', $terminal_without_intent, 'Terminal failures must still link by charge ID without an intent ID.' );
		$this->assertStringContainsString( '></a>', $terminal_without_intent, 'The terminal oracle displays the nullable intent value rather than substituting the charge ID.' );
		$this->assertStringNotContainsString( '>ch_failed_terminal</a>', $terminal_without_intent, 'The charge ID belongs in the terminal URL, not the displayed oracle ID.' );
	}

	/**
	 * @testdox Created-refund candidates retain exact Core and plugin reason/success renderings.
	 */
	public function test_created_refund_candidates_for_reason_and_success(): void {
		$order = wc_create_order();
		$this->assertInstanceOf( WC_Order::class, $order );
		$order->set_currency( 'USD' );
		$order->save();
		$sut = new WooPaymentsOrderNoteService();

		$this->assertCount(
			1,
			$sut->format_created_refund_note_candidates( $order, 4.00, 'USD', 're_123', 'Requested by customer', false ),
			'Identical catalog renderings should collapse to one exact candidate.'
		);

		$this->install_test_translations(
			array(
				'woocommerce-payments' => array(
					'A refund of %1$s %5$s using %2$s. Reason: %3$s. (<code>%4$s</code>)' => 'Eine Rueckerstattung von %1$s %5$s mit %2$s. Grund: %3$s. (<code>%4$s</code>)',
					'was successfully processed' => 'wurde erfolgreich verarbeitet',
				),
			)
		);
		switch_to_locale( 'de_DE' );

		$candidates = $sut->format_created_refund_note_candidates( $order, 4.00, 'USD', 're_123', 'Requested by customer', false );

		$this->assertCount( 2, $candidates );
		$this->assertSame( $sut->format_created_refund_note( $order, 4.00, 'USD', 're_123', 'Requested by customer', false ), $candidates[0] );
		$this->assertStringContainsString( 'Eine Rueckerstattung von', $candidates[1] );
		$this->assertStringContainsString( 'wurde erfolgreich verarbeitet', $candidates[1] );
		$this->assertStringContainsString( 'Grund: Requested by customer.', $candidates[1] );
		$this->assert_refund_amount_candidate_states( $sut, $order, 4.00, 'USD', 're_123', 'Requested by customer', false );
	}

	/**
	 * @testdox Created-refund candidates retain the plugin pending hyperlink without a reason.
	 */
	public function test_created_refund_candidates_for_pending_without_reason(): void {
		$order = wc_create_order();
		$this->assertInstanceOf( WC_Order::class, $order );
		$order->set_currency( 'EUR' );
		$order->save();
		$sut = new WooPaymentsOrderNoteService();
		$this->install_test_translations(
			array(
				'woocommerce-payments' => array(
					'A refund of %1$s %4$s using %2$s (<code>%3$s</code>).' => 'Eine Rueckerstattung von %1$s %4$s mit %2$s (<code>%3$s</code>).',
					'is pending' => 'ist ausstehend',
				),
			)
		);
		switch_to_locale( 'de_DE' );

		$candidates = $sut->format_created_refund_note_candidates( $order, 3.50, 'EUR', 're_pending', '', true );

		$this->assertCount( 2, $candidates );
		$this->assertStringContainsString( 'Eine Rueckerstattung von', $candidates[1] );
		$this->assertStringContainsString(
			'<a href="https://woocommerce.com/document/woopayments/managing-money/#pending-refunds" target="_blank" rel="noopener noreferrer">ist ausstehend</a>',
			$candidates[1]
		);
		$this->assertStringNotContainsString( 'Reason:', $candidates[1] );
		$this->assert_refund_amount_candidate_states( $sut, $order, 3.50, 'EUR', 're_pending', '', true );
	}

	/**
	 * @testdox Created-refund candidates cover successful refunds without a reason.
	 */
	public function test_created_refund_candidates_for_success_without_reason(): void {
		$order = wc_create_order();
		$this->assertInstanceOf( WC_Order::class, $order );
		$order->set_currency( 'USD' );
		$order->save();
		$sut = new WooPaymentsOrderNoteService();
		$this->install_test_translations(
			array(
				'woocommerce-payments' => array(
					'A refund of %1$s %4$s using %2$s (<code>%3$s</code>).' => 'Eine Rueckerstattung von %1$s %4$s mit %2$s (<code>%3$s</code>).',
					'was successfully processed' => 'wurde erfolgreich verarbeitet',
				),
			)
		);
		switch_to_locale( 'de_DE' );

		$candidates = $sut->format_created_refund_note_candidates( $order, 2.50, 'USD', 're_success', '', false );

		$this->assertCount( 2, $candidates );
		$this->assertStringContainsString( 'wurde erfolgreich verarbeitet', $candidates[1] );
		$this->assertStringNotContainsString( 'Grund:', $candidates[1] );
		$this->assert_refund_amount_candidate_states( $sut, $order, 2.50, 'USD', 're_success', '', false );
	}

	/**
	 * @testdox Created-refund candidates cover pending refunds with a reason.
	 */
	public function test_created_refund_candidates_for_pending_with_reason(): void {
		$order = wc_create_order();
		$this->assertInstanceOf( WC_Order::class, $order );
		$order->set_currency( 'EUR' );
		$order->save();
		$sut = new WooPaymentsOrderNoteService();
		$this->install_test_translations(
			array(
				'woocommerce-payments' => array(
					'A refund of %1$s %5$s using %2$s. Reason: %3$s. (<code>%4$s</code>)' => 'Eine Rueckerstattung von %1$s %5$s mit %2$s. Grund: %3$s. (<code>%4$s</code>)',
					'is pending' => 'ist ausstehend',
				),
			)
		);
		switch_to_locale( 'de_DE' );

		$candidates = $sut->format_created_refund_note_candidates( $order, 6.00, 'EUR', 're_pending_reason', 'Customer request', true );

		$this->assertCount( 2, $candidates );
		$this->assertStringContainsString( '>ist ausstehend</a>', $candidates[1] );
		$this->assertStringContainsString( 'Grund: Customer request.', $candidates[1] );
		$this->assert_refund_amount_candidate_states( $sut, $order, 6.00, 'EUR', 're_pending_reason', 'Customer request', true );
	}

	/**
	 * Assert finite refund candidates for feature-disabled and ambiguous plugin state.
	 *
	 * @param WooPaymentsOrderNoteService $sut        Order-note service.
	 * @param WC_Order                    $order      Order object.
	 * @param float                       $amount     Refund amount.
	 * @param string                      $currency   Refund currency.
	 * @param string                      $refund_id  Provider refund ID.
	 * @param string                      $reason     Refund reason.
	 * @param bool                        $is_pending Whether the refund is pending.
	 */
	private function assert_refund_amount_candidate_states( WooPaymentsOrderNoteService $sut, WC_Order $order, float $amount, string $currency, string $refund_id, string $reason, bool $is_pending ): void {
		update_option( 'woocommerce_currency', 'USD' );
		update_option( 'wcpay_multi_currency_enabled_currencies', array( 'EUR' ) );
		update_option( '_wcpay_feature_customer_multi_currency', '0' );
		$formatted_price             = wc_price( $amount, array( 'currency' => $currency ) );
		$explicit_price              = $formatted_price . ' ' . strtoupper( $order->get_currency() );
		$feature_disabled_candidates = $sut->format_created_refund_note_candidates( $order, $amount, $currency, $refund_id, $reason, $is_pending );

		$this->assertCount( 2, $feature_disabled_candidates, 'Feature-disabled refunds should expose native and suffix-free plugin candidates.' );
		$this->assertStringContainsString( $explicit_price, $feature_disabled_candidates[0], 'Native refund candidate zero must retain its existing explicit-currency output.' );
		$this->assertStringContainsString( $formatted_price, $feature_disabled_candidates[1], 'Plugin refund candidate must contain the formatted refund amount.' );
		$this->assertStringNotContainsString( $explicit_price, $feature_disabled_candidates[1], 'Feature-disabled plugin refund candidate must omit the stale explicit-currency suffix.' );

		update_option( '_wcpay_feature_customer_multi_currency', '1' );
		$ambiguous_candidates = $sut->format_created_refund_note_candidates( $order, $amount, $currency, $refund_id, $reason, $is_pending );

		$this->assertCount( 3, $ambiguous_candidates, 'Ambiguous refund readiness should preserve both historical plugin amount variants.' );
		$this->assertStringContainsString( $explicit_price, $ambiguous_candidates[0], 'Native refund candidate zero must remain unchanged.' );
		$this->assertStringContainsString( $formatted_price, $ambiguous_candidates[1], 'Suffix-free plugin refund candidate must remain first.' );
		$this->assertStringNotContainsString( $explicit_price, $ambiguous_candidates[1], 'First plugin refund candidate must omit the explicit suffix.' );
		$this->assertStringContainsString( $explicit_price, $ambiguous_candidates[2], 'Second plugin refund candidate must include the explicit suffix.' );
	}

	/**
	 * @testdox Authorization cancellation notes preserve the WooPayments reference copy and transaction link.
	 */
	public function test_formats_authorization_cancellation_note(): void {
		$sut             = new WooPaymentsOrderNoteService();
		$transaction_url = $sut->transaction_url( 'pi_canceled', 'ch_canceled' );

		$this->assertSame(
			sprintf(
				'Payment authorization was successfully <strong>cancelled</strong> (<a href="%1$s" target="_blank" rel="noopener noreferrer">pi_canceled</a>).',
				$transaction_url
			),
			$sut->format_capture_cancelled_note( 'pi_canceled', 'ch_canceled' )
		);
	}

	/**
	 * @testdox Note-local identity deduplicates changed content without writing order metadata.
	 */
	public function test_note_local_identity_deduplicates_changed_content_without_order_metadata(): void {
		$order = wc_create_order();
		$this->assertInstanceOf( WC_Order::class, $order );
		$order->save();
		$sut = wc_get_container()->get( WooPaymentsOrderNoteService::class );

		$this->assertTrue( $sut->add_note_once( $order, 'Fee details in English', 'fee:charge:ch_123' ) );
		$this->assertFalse( $sut->add_note_once( $order, 'Uebersetzte Gebuehrendetails', 'fee:charge:ch_123' ) );

		$notes = wc_get_order_notes( array( 'order_id' => $order->get_id() ) );
		$this->assertCount( 1, $notes );
		$this->assertSame( 'Fee details in English', $notes[0]->content );
		$this->assertNotSame( '', get_comment_meta( $notes[0]->id, '_wc_woopayments_note_identity', true ) );
		$this->assertSame( '', $order->get_meta( '_wcpay_fee_breakdown_note_ids', true ) );
	}

	/**
	 * @testdox Exact note content can retain multiple private provider identities.
	 */
	public function test_exact_content_can_retain_multiple_private_identities(): void {
		$order = wc_create_order();
		$this->assertInstanceOf( WC_Order::class, $order );
		$order->save();
		$sut = wc_get_container()->get( WooPaymentsOrderNoteService::class );

		$this->assertTrue( $sut->add_note_once( $order, 'Shared fee details', 'fee:charge:ch_1' ) );
		$this->assertFalse( $sut->add_note_once( $order, 'Shared fee details', 'fee:charge:ch_2' ) );
		$this->assertFalse( $sut->add_note_once( $order, 'Translated fee details', 'fee:charge:ch_1' ) );
		$this->assertFalse( $sut->add_note_once( $order, 'Translated fee details', 'fee:charge:ch_2' ) );

		$notes = wc_get_order_notes( array( 'order_id' => $order->get_id() ) );
		$this->assertCount( 1, $notes );
		$this->assertCount( 2, get_comment_meta( $notes[0]->id, '_wc_woopayments_note_identity', false ) );
	}

	/**
	 * @testdox Legacy order-meta markers suppress a changed rendering after dedupe ownership migrates.
	 */
	public function test_legacy_order_meta_marker_suppresses_changed_note_rendering(): void {
		$order = wc_create_order();
		$this->assertInstanceOf( WC_Order::class, $order );
		$order->save();
		$order->add_order_note( 'Legacy lifecycle note' );

		$legacy_marker_key = '_wc_native_payments_note_' . md5( 'pi_legacy|started|payment_started' );
		$order->update_meta_data( $legacy_marker_key, 'yes' );
		$order->save_meta_data();

		$sut = wc_get_container()->get( WooPaymentsOrderNoteService::class );
		$this->assertFalse(
			$sut->add_note_once(
				$order,
				'Translated lifecycle note',
				'payment_lifecycle:pi_legacy|started|payment_started',
				array(),
				array( $legacy_marker_key )
			)
		);

		$notes = wc_get_order_notes( array( 'order_id' => $order->get_id() ) );
		$this->assertCount( 1, $notes );
		$this->assertSame( 'Legacy lifecycle note', $notes[0]->content );
	}

	/**
	 * @testdox New-note side effects run once and are skipped on identity replay.
	 */
	public function test_before_add_side_effect_runs_once_for_new_identity(): void {
		$order = wc_create_order();
		$this->assertInstanceOf( WC_Order::class, $order );
		$order->save();
		$sut              = wc_get_container()->get( WooPaymentsOrderNoteService::class );
		$before_add_calls = 0;
		$before_add       = static function () use ( &$before_add_calls ): void {
			++$before_add_calls;
		};

		$this->assertTrue( $sut->add_note_once( $order, 'New note', 'event:one', array(), array(), $before_add ) );
		$this->assertFalse( $sut->add_note_once( $order, 'Translated note', 'event:one', array(), array(), $before_add ) );
		$this->assertSame( 1, $before_add_calls );
		$this->assertCount( 1, wc_get_order_notes( array( 'order_id' => $order->get_id() ) ) );
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
