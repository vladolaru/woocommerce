<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments;

use Automattic\WooCommerce\Internal\MultiCurrency\MultiCurrencyRuntimeArbiter;
use Automattic\WooCommerce\Internal\MultiCurrency\MultiCurrencyFeatureController;
use Automattic\WooCommerce\Proxies\LegacyProxy;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsRuntimeArbiter;
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
		$this->reset_container_replacements();
		parent::tearDown();
	}

	/**
	 * @testdox Payment and capture notes preserve reference copy and omit the currency code in a single-currency store.
	 *
	 * Source: client 11.1.0 class-wc-payments-order-service.php:2904-2910 and explicit-price-formatter.php:167-190.
	 */
	public function test_formats_payment_and_capture_notes_with_reference_copy_and_explicit_currency(): void {
		$order = wc_create_order();
		$this->assertInstanceOf( WC_Order::class, $order );
		$order->set_currency( 'USD' );
		$order->set_total( '25.00' );
		$order->save();
		$sut             = new WooPaymentsOrderNoteService();
		$transaction_url = $sut->transaction_url( 'pi_test_charge', 'ch_test_charge', 'txn_test_charge' );

		$this->assertStringContainsString( 'path=%2Fpayments%2Ftransactions%2Fdetails', $transaction_url );
		$this->assertSame(
			sprintf(
				'A payment of %1$s was <strong>successfully charged</strong> using WooPayments (<a href="%2$s" target="_blank" rel="noopener noreferrer">pi_test_charge</a>).',
				wc_price( 25.00, array( 'currency' => 'USD' ) ),
				$transaction_url
			),
			$sut->format_payment_success_note_candidates( $order, 'pi_test_charge', 'ch_test_charge', 'txn_test_charge' )[0]
		);
		$this->assertStringContainsString(
			'successfully captured</strong> using WooPayments',
			$sut->format_capture_success_note_candidates( $order, 'pi_test_charge', 'ch_test_charge', 'txn_test_charge' )[0]
		);
		$this->assertStringContainsString(
			'A capture of',
			$sut->format_capture_failed_note_candidates( $order, 'pi_test_charge', 'ch_test_charge', 'Capture failed.' )[0]
		);
		$this->assertStringContainsString(
			'Capture failed.',
			$sut->format_capture_failed_note_candidates( $order, 'pi_test_charge', 'ch_test_charge', 'Capture failed.' )[0]
		);
	}

	/**
	 * @testdox Refund and payment notes follow the WooPayments extension's explicit-price rule.
	 *
	 * Source: client 11.1.0 class-wc-payments-order-service.php:2630-2631 (created refund note), :2904-2910 (order amount) and
	 * class-wc-payments-explicit-price-formatter.php:55-74,167-190 (Multi-Currency on with a second currency, then the filter).
	 *
	 * @dataProvider explicit_price_rule_provider
	 *
	 * @param bool        $core_multi_currency Whether core Multi-Currency owns the runtime.
	 * @param string|null $plugin_flag         Stale WooPayments `_wcpay_feature_customer_multi_currency` value, or null when absent.
	 * @param bool|null   $filter_result       Value the filter returns, or null for no filter.
	 * @param bool        $expected_default    Default the filter must receive.
	 * @param string      $code                Expected currency code suffix, including its leading space, or ''.
	 */
	public function test_notes_follow_the_woopayments_extension_explicit_price_rule( bool $core_multi_currency, ?string $plugin_flag, ?bool $filter_result, bool $expected_default, string $code ): void {
		$this->configure_second_currency();
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
		$order = wc_create_order();
		$this->assertInstanceOf( WC_Order::class, $order );
		$order->set_currency( 'USD' );
		$order->set_total( '25.00' );
		$order->save();
		$sut = new WooPaymentsOrderNoteService();

		$refund_note  = $sut->format_created_refund_note( $order, 4.00, 'USD', 're_123', '', false );
		$payment_note = $sut->format_payment_success_note_candidates( $order, 'pi_123', 'ch_123', 'txn_123' )[0];

		$this->assertSame( "A refund of \$4.00{$code} was successfully processed using WooPayments (re_123).", html_entity_decode( wp_strip_all_tags( $refund_note ) ) );
		$this->assertSame( "A payment of \$25.00{$code} was successfully charged using WooPayments (pi_123).", html_entity_decode( wp_strip_all_tags( $payment_note ) ) );
		$this->assertSame( array( $expected_default, $expected_default ), $defaults, 'The filter must run once per note with the client default.' );
	}

	/**
	 * Store states with the client's note outcome.
	 *
	 * @return array<string,array{0:bool,1:?string,2:?bool,3:bool,4:string}>
	 */
	public function explicit_price_rule_provider(): array {
		return array(
			'Multi-Currency on with a second currency'     => array( true, null, null, true, ' USD' ),
			'Multi-Currency on, stale plugin flag off'     => array( true, '0', null, true, ' USD' ),
			'Multi-Currency off, stale enabled currencies' => array( false, '1', null, false, '' ),
			'filter forces the code while Multi-Currency is off' => array( false, null, true, false, ' USD' ),
			'filter removes the code while Multi-Currency is on' => array( true, null, false, true, '' ),
		);
	}

	/**
	 * Enable EUR next to the USD store currency, with a manual rate so Multi-Currency can offer it.
	 */
	private function configure_second_currency(): void {
		update_option( 'woocommerce_currency', 'USD' );
		update_option( 'wcpay_multi_currency_enabled_currencies', array( 'USD', 'EUR' ) );
		update_option( 'wcpay_multi_currency_exchange_rate_eur', 'manual' );
		update_option( 'wcpay_multi_currency_manual_rate_eur', '0.9' );
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
	 * @testdox Unusable saved payment method notes use actionable escaped renewal copy.
	 */
	public function test_formats_unusable_saved_payment_method_note_candidates(): void {
		$order = wc_create_order();
		$this->assertInstanceOf( WC_Order::class, $order );
		$order->set_currency( 'EUR' );
		$order->set_total( '12.50' );
		$order->save();
		$sut = new WooPaymentsOrderNoteService();
		$this->install_test_translations(
			array(
				'woocommerce-payments' => array(
					'A payment of %1$s <strong>failed</strong>: the saved payment method <strong>%2$s</strong> can no longer be used. A new payment method is required.' => 'Plugin payment %1$s <strong>failed</strong>: saved method <strong>%2$s</strong> needs replacing.',
					'A payment of %1$s <strong>failed</strong>: the saved payment method can no longer be used. A new payment method is required.' => 'Plugin payment %1$s <strong>failed</strong>: saved method needs replacing.',
				),
			)
		);

		$named_candidates   = $sut->format_unusable_saved_payment_method_note_candidates( $order, 'Visa <ending 4242>' );
		$unnamed_candidates = $sut->format_unusable_saved_payment_method_note_candidates( $order, '' );

		$this->assertSame(
			'A payment of ' . wc_price( 12.50, array( 'currency' => 'EUR' ) ) . ' <strong>failed</strong>: the saved payment method <strong>Visa &lt;ending 4242&gt;</strong> can no longer be used. A new payment method is required.',
			$named_candidates[0]
		);
		$this->assertSame(
			'A payment of ' . wc_price( 12.50, array( 'currency' => 'EUR' ) ) . ' <strong>failed</strong>: the saved payment method can no longer be used. A new payment method is required.',
			$unnamed_candidates[0]
		);
		$this->assertSame(
			'Plugin payment ' . wc_price( 12.50, array( 'currency' => 'EUR' ) ) . ' <strong>failed</strong>: saved method <strong>Visa &lt;ending 4242&gt;</strong> needs replacing.',
			$named_candidates[1]
		);
		$this->assertSame(
			'Plugin payment ' . wc_price( 12.50, array( 'currency' => 'EUR' ) ) . ' <strong>failed</strong>: saved method needs replacing.',
			$unnamed_candidates[1]
		);
	}

	/**
	 * @testdox Payment success notes identify test payments without changing live-note copy.
	 */
	public function test_formats_test_mode_payment_success_note_with_reference_copy(): void {
		$order = wc_create_order();
		$this->assertInstanceOf( WC_Order::class, $order );
		$order->set_currency( 'USD' );
		$order->set_total( '25.00' );
		$order->save();
		$sut             = new WooPaymentsOrderNoteService();
		$transaction_url = $sut->transaction_url( 'pi_test_charge', 'ch_test_charge', 'txn_test_charge' );

		$this->assertSame(
			sprintf(
				'A test payment of %1$s was processed using WooPayments in <strong>test mode</strong> (<a href="%2$s" target="_blank" rel="noopener noreferrer">pi_test_charge</a>). No real funds were collected.',
				wc_price( 25.00, array( 'currency' => 'USD' ) ),
				$transaction_url
			),
			$sut->format_payment_success_note_candidates( $order, 'pi_test_charge', 'ch_test_charge', 'txn_test_charge', 'test' )[0]
		);
	}

	/**
	 * @testdox Payment success candidates use the persisted test mode when no synchronous mode is supplied.
	 */
	public function test_payment_success_candidates_use_persisted_test_mode_with_core_first_catalog_order(): void {
		$order = wc_create_order();
		$this->assertInstanceOf( WC_Order::class, $order );
		$order->set_currency( 'USD' );
		$order->set_total( '25.00' );
		$order->update_meta_data( '_wcpay_mode', 'test' );
		$order->save();
		$sut = new WooPaymentsOrderNoteService();
		$this->install_test_translations(
			array(
				'woocommerce'          => array(
					'A test payment of %1$s was processed using %2$s in <strong>test mode</strong> (<a>%3$s</a>). No real funds were collected.' => 'Core test payment %1$s using %2$s (<a>%3$s</a>).',
				),
				'woocommerce-payments' => array(
					'A test payment of %1$s was processed using %2$s in <strong>test mode</strong> (<a>%3$s</a>). No real funds were collected.' => 'Plugin test payment %1$s using %2$s (<a>%3$s</a>).',
				),
			)
		);

		$this->assertSame(
			array(
				sprintf( 'Core test payment %1$s using WooPayments (<a href="%2$s" target="_blank" rel="noopener noreferrer">pi_test_charge</a>).', wc_price( 25.00, array( 'currency' => 'USD' ) ), $sut->transaction_url( 'pi_test_charge', 'ch_test_charge', 'txn_test_charge' ) ),
				sprintf( 'Plugin test payment %1$s using WooPayments (<a href="%2$s" target="_blank" rel="noopener noreferrer">pi_test_charge</a>).', wc_price( 25.00, array( 'currency' => 'USD' ) ), $sut->transaction_url( 'pi_test_charge', 'ch_test_charge', 'txn_test_charge' ) ),
			),
			$sut->format_payment_success_note_candidates( $order, 'pi_test_charge', 'ch_test_charge', 'txn_test_charge' )
		);
	}

	/**
	 * @testdox Live payment success copy remains unchanged for non-test mode inputs.
	 */
	public function test_payment_success_candidates_preserve_live_copy_for_non_test_modes(): void {
		$sut                      = new WooPaymentsOrderNoteService();
		$expected_live_candidates = array(
			sprintf( 'A payment of %1$s was <strong>successfully charged</strong> using WooPayments (<a href="%2$s" target="_blank" rel="noopener noreferrer">pi_test_charge</a>).', wc_price( 25.00, array( 'currency' => 'USD' ) ), $sut->transaction_url( 'pi_test_charge', 'ch_test_charge', 'txn_test_charge' ) ),
		);

		$cases = array(
			'explicit live'  => array( 'test', 'prod' ),
			'persisted live' => array( 'prod', null ),
			'missing mode'   => array( '', null ),
			'unknown mode'   => array( 'unknown', null ),
		);

		foreach ( $cases as $case => $modes ) {
			$order = wc_create_order();
			$this->assertInstanceOf( WC_Order::class, $order );
			$order->set_currency( 'USD' );
			$order->set_total( '25.00' );
			if ( '' !== $modes[0] ) {
				$order->update_meta_data( '_wcpay_mode', $modes[0] );
			}
			$order->save();

			$candidates = null === $modes[1]
				? $sut->format_payment_success_note_candidates( $order, 'pi_test_charge', 'ch_test_charge', 'txn_test_charge' )
				: $sut->format_payment_success_note_candidates( $order, 'pi_test_charge', 'ch_test_charge', 'txn_test_charge', $modes[1] );

			$this->assertSame( $expected_live_candidates, $candidates, $case );
		}
	}

	/**
	 * @testdox Payment success candidates retain their existing duplicate collapse for live modes.
	 */
	public function test_payment_success_candidates_retain_duplicate_collapse_for_live_modes(): void {
		$order = wc_create_order();
		$this->assertInstanceOf( WC_Order::class, $order );
		$order->set_currency( 'USD' );
		$order->set_total( '25.00' );
		$order->update_meta_data( '_wcpay_mode', 'prod' );
		$order->save();
		$this->install_test_translations(
			array(
				'woocommerce'          => array( 'A payment of %1$s was <strong>successfully charged</strong> using %2$s (<a>%3$s</a>).' => 'Unchanged live note.' ),
				'woocommerce-payments' => array( 'A payment of %1$s was <strong>successfully charged</strong> using %2$s (<a>%3$s</a>).' => 'Unchanged live note.' ),
			)
		);

		$this->assertSame( array( 'Unchanged live note.' ), ( new WooPaymentsOrderNoteService() )->format_payment_success_note_candidates( $order, 'pi_test_charge', 'ch_test_charge', 'txn_test_charge' ) );
	}

	/**
	 * @testdox Checkout payment-failure notes render the decline details: $_dataName.
	 * @dataProvider provider_checkout_payment_failed_notes
	 *
	 * @param string $error_message   Error details.
	 * @param string $seller_message  Merchant seller message.
	 * @param string $decline_code    Decline code.
	 * @param string $expected_format Expected note format, with the price as the first placeholder.
	 * @param string $expected_detail Expected detail, the second placeholder.
	 */
	public function test_formats_checkout_payment_failed_note( string $error_message, string $seller_message, string $decline_code, string $expected_format, string $expected_detail ): void {
		$order = wc_create_order();
		$this->assertInstanceOf( WC_Order::class, $order );
		$order->set_currency( 'USD' );
		$order->set_total( '25.00' );
		$order->save();
		$sut = new WooPaymentsOrderNoteService();

		$candidates = $sut->format_checkout_payment_failed_note_candidates( $order, $error_message, $seller_message, 'card_error', $decline_code );

		$this->assertSame( sprintf( $expected_format, wc_price( 25.00, array( 'currency' => 'USD' ) ), $expected_detail ), $candidates[0] );
	}

	/**
	 * Checkout decline inputs and the note each one renders.
	 *
	 * @return array<string,array{string,string,string,string,string}>
	 */
	public function provider_checkout_payment_failed_notes(): array {
		$message_format = 'A payment of %1$s <strong>failed</strong> to complete with the following message: <code>%2$s</code>.';

		return array(
			'with a seller message'    => array(
				'Error: Your card was declined.',
				'The bank did not return any further details with this decline.',
				'card_declined',
				$message_format,
				'Error: Your card was declined. The bank did not return any further details with this decline',
			),
			'without a seller message' => array( 'Error: Your card has insufficient funds.', '', 'card_declined', $message_format, 'Error: Your card has insufficient funds' ),
			'incorrect zip guidance'   => array(
				'Error: Your card\'s zip code failed validation.',
				'',
				'incorrect_zip',
				'A payment of %1$s <strong>failed</strong>. %2$s',
				'We couldn’t verify the postal code in the billing address. If the issue persists, suggest the customer to reach out to the card issuing bank.',
			),
		);
	}

	/**
	 * @testdox Fraud-blocked notes link the blocked transaction: $_dataName.
	 * @dataProvider provider_fraud_blocked_notes
	 *
	 * @param string               $intent_id       Blocked intent ID.
	 * @param array<string,string> $ruleset_results Fraud ruleset results.
	 * @param string               $expected_format Expected note format, with the price and the transaction URL as placeholders.
	 */
	public function test_formats_fraud_blocked_note( string $intent_id, array $ruleset_results, string $expected_format ): void {
		$order = wc_create_order();
		$this->assertInstanceOf( WC_Order::class, $order );
		$order->set_currency( 'USD' );
		$order->set_total( '25.00' );
		$order->save();
		$sut = new WooPaymentsOrderNoteService();

		$candidates = $sut->format_fraud_blocked_note_candidates( $order, $intent_id, $ruleset_results );
		$url        = $sut->blocked_transaction_url( $intent_id, (string) $order->get_id() );

		$this->assertStringContainsString( 'path=%2Fpayments%2Ftransactions%2Fdetails', $url );
		$this->assertSame( sprintf( $expected_format, wc_price( 25.00, array( 'currency' => 'USD' ) ), $url ), $candidates[0] );
	}

	/**
	 * Fraud ruleset results and the blocked note each one renders. Allowed rules stay out of the fired-filter list.
	 *
	 * @return array<string,array{string,array<string,string>,string}>
	 */
	public function provider_fraud_blocked_notes(): array {
		return array(
			'fired risk filters listed'    => array(
				'pi_blocked_test',
				array(
					'international_ip_address' => 'block',
					'order_items_threshold'    => 'allow',
				),
				'&#x1F6AB; A payment of %1$s was <strong>blocked</strong> by the following risk filters:<br>&#8226; Block if the country resolved from customer IP is not listed in your selling countries<br><br><a href="%2$s" target="_blank" rel="noopener noreferrer">View more details</a>.',
			),
			'generic copy without results' => array(
				'',
				array(),
				'&#x1F6AB; A payment of %1$s was <strong>blocked</strong> by one or more risk filters.<br><br><a href="%2$s" target="_blank" rel="noopener noreferrer">View more details</a>.',
			),
		);
	}

	/**
	 * @testdox Held-for-review notes use exact Core and WooPayments extension candidates with shared labels.
	 */
	public function test_formats_fraud_held_for_review_note_candidates_and_ruleset_labels(): void {
		$order = wc_create_order();
		$this->assertInstanceOf( WC_Order::class, $order );
		$order->set_currency( 'USD' );
		$order->set_total( '25.00' );
		$order->save();
		$sut = new WooPaymentsOrderNoteService();

		$ruleset_results = array(
			'new_platform_rule'        => 'unexpected',
			'avs_verification'         => 'review',
			'international_ip_address' => 'block',
			'order_items_threshold'    => 'allow',
			0                          => 'review',
			'bad_outcome'              => array(),
		);
		$candidates      = $sut->format_fraud_held_for_review_note_candidates( $order, 'pi_review_test', 'ch_review_test', $ruleset_results );

		$this->assertStringContainsString( '<strong>held for review</strong>', $candidates[0] );
		$this->assertStringContainsString( 'Place in review if the AVS verification fails', $candidates[0] );
		$this->assertStringContainsString( 'Block if the country resolved from customer IP is not listed in your selling countries', $candidates[0] );
		$this->assertStringContainsString( 'New platform rule', $candidates[0] );
		$this->assertStringNotContainsString( 'Order items threshold', $candidates[0] );
		$this->assertSame(
			array(
				'New platform rule',
				'Place in review if the AVS verification fails',
				'Block if the country resolved from customer IP is not listed in your selling countries',
			),
			$sut->get_fraud_ruleset_result_labels_for_display( $ruleset_results )
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

		$this->assertStringContainsString( '<strong>authorized</strong> using WooPayments', $sut->format_payment_authorized_note_candidates( $order, 'pi_authorized', 'ch_authorized' )[0] );
		$this->assertStringContainsString( '<strong>started</strong> using WooPayments', $sut->format_payment_started_note_candidates( $order, 'pi_started' )[0] );
	}

	/**
	 * @testdox Every known lifecycle note exposes finite exact Core and WooPayments extension catalog candidates.
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
			'payment success'       => array( 'format_payment_success_note_candidates', array( $order, 'pi_success', 'ch_success', 'txn_success' ), null ),
			'payment authorized'    => array( 'format_payment_authorized_note_candidates', array( $order, 'pi_authorized', 'ch_authorized' ), null ),
			'payment started'       => array( 'format_payment_started_note_candidates', array( $order, 'pi_started' ), null ),
			'capture success'       => array( 'format_capture_success_note_candidates', array( $order, 'pi_captured', 'ch_captured', 'txn_captured' ), null ),
			'capture failure'       => array( 'format_capture_failed_note_candidates', array( $order, 'pi_failed', 'ch_failed', 'Provider diagnostic.' ), null ),
			'capture cancellation'  => array( 'format_capture_cancelled_note_candidates', array( 'pi_canceled', 'ch_canceled' ), null ),
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
			list( $candidate_method, $arguments ) = $case;

			$candidates = $sut->{$candidate_method}( ...$arguments );
			$this->assertCount( 2, $candidates, "Expected Core and plugin candidates for {$case_name}." );
			$this->assertStringContainsString( 'Legacy', $candidates[1], "Expected the plugin catalog rendering for {$case_name}." );
			$this->assertStringNotContainsString( 'Legacy', $candidates[0], "Native rendering must stay first for {$case_name}." );
			if ( in_array( $case_name, $amount_case_names, true ) ) {
				$this->assertStringNotContainsString( ' USD ', $candidates[0], "Native {$case_name} candidate must omit the code in a single-currency store, as the client." );
				$this->assertStringNotContainsString( ' USD ', $candidates[1], "Default-store plugin {$case_name} candidate must match the plugin's suffix-free amount." );
			}
		}

		update_option( '_wcpay_feature_customer_multi_currency', '1' );
		update_option( 'wcpay_multi_currency_enabled_currencies', array( 'EUR' ) );
		$this->set_core_multi_currency( false );
		foreach ( $amount_case_names as $case_name ) {
			list( $candidate_method, $arguments ) = $cases[ $case_name ];
			$candidates                           = $sut->{$candidate_method}( ...$arguments );
			$this->assertCount( 3, $candidates, "Ambiguous plugin readiness must preserve both historical amount variants for {$case_name}." );
			$this->assertStringNotContainsString( ' USD ', $candidates[0], "Native {$case_name} candidate must omit the code while core Multi-Currency is off, whatever the stale plugin options say." );
			$this->assertStringNotContainsString( ' USD ', $candidates[1], "Ambiguous plugin {$case_name} candidates must include the suffix-free historical rendering first." );
			$this->assertStringContainsString( ' USD ', $candidates[2], "Ambiguous plugin {$case_name} candidates must also include the explicit-currency historical rendering." );
		}

		update_option( 'wcpay_multi_currency_exchange_rate_eur', 'manual' );
		update_option( 'wcpay_multi_currency_manual_rate_eur', '0.90' );
		$this->set_core_multi_currency( true );
		foreach ( $amount_case_names as $case_name ) {
			list( $candidate_method, $arguments ) = $cases[ $case_name ];
			$candidates                           = $sut->{$candidate_method}( ...$arguments );
			$this->assertCount( 3, $candidates, "Configured plugin readiness must preserve both historical amount variants for {$case_name}." );
			$this->assertStringContainsString( ' USD ', $candidates[0], "Native {$case_name} candidate must carry the code when multi-currency is enabled." );
			$this->assertStringNotContainsString( ' USD ', $candidates[1], "Configured plugin {$case_name} candidates must include the suffix-free historical rendering first." );
			$this->assertStringContainsString( ' USD ', $candidates[2], "Configured plugin {$case_name} candidates must also include the explicit-currency historical rendering." );
		}

		update_option( '_wcpay_feature_customer_multi_currency', '0' );
		foreach ( $amount_case_names as $case_name ) {
			list( $candidate_method, $arguments ) = $cases[ $case_name ];
			$candidates                           = $sut->{$candidate_method}( ...$arguments );
			$this->assertStringContainsString( ' USD ', $candidates[0], "Native {$case_name} candidate must follow core Multi-Currency, not the stale plugin flag." );
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
	 * @testdox Created-refund candidates keep the exact Core and WooPayments extension renderings: $_dataName.
	 * @dataProvider provider_created_refund_note_candidates
	 *
	 * @param float                              $amount       Refund amount.
	 * @param string                             $currency     Order and refund currency.
	 * @param string                             $refund_id    Provider refund ID.
	 * @param string                             $reason       Refund reason.
	 * @param bool                               $is_pending   Whether the refund is pending.
	 * @param array<string,array<string,string>> $translations Test translations keyed by text domain.
	 * @param string[]                           $present      Substrings the translated extension candidate contains.
	 * @param string[]                           $absent       Substrings the translated extension candidate omits.
	 */
	public function test_created_refund_note_candidates( float $amount, string $currency, string $refund_id, string $reason, bool $is_pending, array $translations, array $present, array $absent ): void {
		$order = wc_create_order();
		$this->assertInstanceOf( WC_Order::class, $order );
		$order->set_currency( $currency );
		$order->save();
		$sut = new WooPaymentsOrderNoteService();

		$this->assertCount(
			1,
			$sut->format_created_refund_note_candidates( $order, $amount, $currency, $refund_id, $reason, $is_pending ),
			'Identical catalog renderings should collapse to one exact candidate.'
		);

		$this->install_test_translations( $translations );
		switch_to_locale( 'de_DE' );

		$candidates = $sut->format_created_refund_note_candidates( $order, $amount, $currency, $refund_id, $reason, $is_pending );

		$this->assertCount( 2, $candidates );
		$this->assertSame( $sut->format_created_refund_note( $order, $amount, $currency, $refund_id, $reason, $is_pending ), $candidates[0] );
		foreach ( $present as $substring ) {
			$this->assertStringContainsString( $substring, $candidates[1] );
		}
		foreach ( $absent as $substring ) {
			$this->assertStringNotContainsString( $substring, $candidates[1] );
		}
		$this->assert_refund_amount_candidate_states( $sut, $order, $amount, $currency, $refund_id, $reason, $is_pending );
	}

	/**
	 * Refund states, the test translations each needs, and what the translated extension candidate shows.
	 *
	 * @return array<string,array{float,string,string,string,bool,array<string,array<string,string>>,string[],string[]}>
	 */
	public function provider_created_refund_note_candidates(): array {
		$with_reason    = array( 'A refund of %1$s %5$s using %2$s. Reason: %3$s. (<code>%4$s</code>)' => 'Eine Rueckerstattung von %1$s %5$s mit %2$s. Grund: %3$s. (<code>%4$s</code>)' );
		$without_reason = array( 'A refund of %1$s %4$s using %2$s (<code>%3$s</code>).' => 'Eine Rueckerstattung von %1$s %4$s mit %2$s (<code>%3$s</code>).' );
		$success        = array( 'was successfully processed' => 'wurde erfolgreich verarbeitet' );
		$pending        = array( 'is pending' => 'ist ausstehend' );

		return array(
			'success with a reason'    => array(
				4.00,
				'USD',
				're_123',
				'Requested by customer',
				false,
				array( 'woocommerce-payments' => array_merge( $with_reason, $success ) ),
				array( 'Eine Rueckerstattung von', 'wurde erfolgreich verarbeitet', 'Grund: Requested by customer.' ),
				array( 'pending-refunds' ),
			),
			'pending without a reason' => array(
				3.50,
				'EUR',
				're_pending',
				'',
				true,
				array( 'woocommerce-payments' => array_merge( $without_reason, $pending ) ),
				array( 'Eine Rueckerstattung von', '<a href="https://woocommerce.com/document/woopayments/managing-money/#pending-refunds" target="_blank" rel="noopener noreferrer">ist ausstehend</a>' ),
				array( 'Reason:' ),
			),
			'success without a reason' => array(
				2.50,
				'USD',
				're_success',
				'',
				false,
				array( 'woocommerce-payments' => array_merge( $without_reason, $success ) ),
				array( 'wurde erfolgreich verarbeitet' ),
				array( 'Grund:' ),
			),
			'pending with a reason'    => array(
				6.00,
				'EUR',
				're_pending_reason',
				'Customer request',
				true,
				array( 'woocommerce-payments' => array_merge( $with_reason, $pending ) ),
				array( '>ist ausstehend</a>', 'Grund: Customer request.' ),
				array( 'successfully processed' ),
			),
		);
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
		$this->set_core_multi_currency( false );
		$formatted_price             = wc_price( $amount, array( 'currency' => $currency ) );
		$explicit_price              = $formatted_price . ' ' . strtoupper( $order->get_currency() );
		$feature_disabled_candidates = $sut->format_created_refund_note_candidates( $order, $amount, $currency, $refund_id, $reason, $is_pending );

		$this->assertCount( 2, $feature_disabled_candidates, 'Feature-disabled refunds should expose native and suffix-free plugin candidates.' );
		// WooPayments 11.1.0 explicit price formatter :170-172 adds no currency code while the multi-currency flag is off.
		$this->assertStringContainsString( $formatted_price, $feature_disabled_candidates[0], 'Native refund candidate zero must contain the formatted refund amount.' );
		$this->assertStringNotContainsString( $explicit_price, $feature_disabled_candidates[0], 'Feature-disabled native refund candidate must omit the stale explicit-currency suffix.' );
		$this->assertStringContainsString( $formatted_price, $feature_disabled_candidates[1], 'Plugin refund candidate must contain the formatted refund amount.' );
		$this->assertStringNotContainsString( $explicit_price, $feature_disabled_candidates[1], 'Feature-disabled plugin refund candidate must omit the stale explicit-currency suffix.' );

		update_option( '_wcpay_feature_customer_multi_currency', '1' );
		update_option( 'wcpay_multi_currency_exchange_rate_eur', 'manual' );
		update_option( 'wcpay_multi_currency_manual_rate_eur', '0.9' );
		$this->set_core_multi_currency( true );
		$ambiguous_candidates = $sut->format_created_refund_note_candidates( $order, $amount, $currency, $refund_id, $reason, $is_pending );

		$this->assertCount( 3, $ambiguous_candidates, 'Ambiguous refund readiness should preserve both historical plugin amount variants.' );
		$this->assertStringContainsString( $explicit_price, $ambiguous_candidates[0], 'Native refund candidate zero must carry the code while core Multi-Currency runs with a second currency.' );
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
			$sut->format_capture_cancelled_note_candidates( 'pi_canceled', 'ch_canceled' )[0]
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
	 * @testdox Finding a note by equivalent content writes nothing to that note.
	 */
	public function test_find_note_by_equivalent_content_writes_nothing(): void {
		$order = wc_create_order();
		$this->assertInstanceOf( WC_Order::class, $order );
		$order->save();
		$note_id  = (int) $order->add_order_note( 'Plugin payment success note.' );
		$identity = 'payment_lifecycle:pi_equivalent|completed|payment_success';
		$sut      = wc_get_container()->get( WooPaymentsOrderNoteService::class );

		$this->assertSame( $note_id, $sut->find_note( $order, 'Core payment success note.', $identity, array( 'Plugin payment success note.' ) ) );
		$this->assertSame( array(), get_comment_meta( $note_id, '_wc_woopayments_note_identity', false ) );
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

		$this->assertTrue( $sut->add_note_once( $order, 'New note', 'event:one', array(), $before_add ) );
		$this->assertFalse( $sut->add_note_once( $order, 'Translated note', 'event:one', array(), $before_add ) );
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
	/**
	 * @testdox Should render the WooPayments extension's failed-cancel notes with and without a provider message.
	 */
	public function test_format_cancel_failed_note_candidates(): void {
		$sut = new WooPaymentsOrderNoteService();

		$this->assertSame(
			array( 'Canceling authorization <strong>failed</strong> to complete.' ),
			$sut->format_cancel_failed_note_candidates( '' )
		);
		$this->assertSame(
			array( 'Canceling authorization <strong>failed</strong> to complete with the following message: <code>Not &lt;now&gt;.</code>.' ),
			$sut->format_cancel_failed_note_candidates( 'Not <now>.' )
		);
	}

	/**
	 * @testdox Should format actionable early-fraud-warning notes with safe transaction and refund links.
	 */
	public function test_formats_actionable_early_fraud_warning_note_candidates(): void {
		$sut = new WooPaymentsOrderNoteService();

		$notes = $sut->format_early_fraud_warning_note_candidates( 'ch_early_warning', true, 'made_with_stolen_card' );

		$transaction_url = $sut->transaction_url( '', 'ch_early_warning' );
		$this->assertSame(
			array(
				'Payment has received an early fraud warning with reason &quot;Made with stolen card&quot;. <a href="' . $transaction_url . '" class="wcpay-efw-refund-link" target="_blank" rel="noopener noreferrer">Refunding the payment now</a> can prevent a dispute. See <a href="' . $transaction_url . '" target="_blank" rel="noopener noreferrer">payment details</a> for more information.',
			),
			$notes
		);
	}

	/**
	 * @testdox Should preserve distinct Core and seeded WooPayments extension catalog candidates for cutover deduplication.
	 */
	public function test_formats_seeded_woopayments_extension_catalog_early_fraud_warning_note_candidate(): void {
		$this->install_test_translations(
			array(
				'woocommerce-payments' => array(
					'Payment has received an early fraud warning with reason "%1$s". <refund>Refunding the payment now</refund> can prevent a dispute. See <a>payment details</a> for more information.' => 'Legacy warning: "%1$s". <refund>Refund now</refund>. See <a>legacy details</a>.',
					'Made with stolen card' => 'Legacy stolen card',
				),
			)
		);
		$sut = new WooPaymentsOrderNoteService();

		$notes = $sut->format_early_fraud_warning_note_candidates( 'ch_early_warning', true, 'made_with_stolen_card' );

		$this->assertCount( 2, $notes );
		$this->assertStringContainsString( 'Payment has received an early fraud warning with reason &quot;Made with stolen card&quot;.', $notes[0] );
		$this->assertStringContainsString( 'Legacy warning: &quot;Legacy stolen card&quot;.', $notes[1] );
		$this->assertStringContainsString( '<a href="' . $sut->transaction_url( '', 'ch_early_warning' ) . '" class="wcpay-efw-refund-link" target="_blank" rel="noopener noreferrer">Refund now</a>', $notes[1] );
		$this->assertStringContainsString( '<a href="' . $sut->transaction_url( '', 'ch_early_warning' ) . '" target="_blank" rel="noopener noreferrer">legacy details</a>', $notes[1] );
	}

	/**
	 * @testdox Should omit unknown reasons and refund links from resolved early fraud warning notes.
	 */
	public function test_formats_resolved_early_fraud_warning_without_unknown_reason_or_refund_link(): void {
		$notes = ( new WooPaymentsOrderNoteService() )->format_early_fraud_warning_note_candidates( 'ch_<unsafe>', false, '<unknown>' );

		$this->assertCount( 1, $notes );
		$this->assertStringContainsString( 'no longer actionable', $notes[0] );
		$this->assertStringNotContainsString( '&lt;unknown&gt;', $notes[0] );
		$this->assertStringNotContainsString( 'wcpay-efw-refund-link', $notes[0] );
		$this->assertStringContainsString( 'target="_blank" rel="noopener noreferrer"', $notes[0] );
		$this->assertStringNotContainsString( 'ch_<unsafe>', $notes[0] );
	}

	/**
	 * @testdox Should use the generic actionable warning copy for an unknown reason.
	 */
	public function test_formats_actionable_early_fraud_warning_without_unknown_reason(): void {
		$notes = ( new WooPaymentsOrderNoteService() )->format_early_fraud_warning_note_candidates( 'ch_early_warning', true, '<unknown>' );

		$this->assertCount( 1, $notes );
		$this->assertStringContainsString( 'Payment has received an early fraud warning.', $notes[0] );
		$this->assertStringNotContainsString( '&lt;unknown&gt;', $notes[0] );
		$this->assertStringContainsString( 'class="wcpay-efw-refund-link"', $notes[0] );
	}

	/**
	 * @testdox Should provide only the seven known early fraud warning reason labels.
	 *
	 * @dataProvider early_fraud_warning_reason_provider
	 *
	 * @param string $fraud_type Expected provider reason.
	 * @param string $label Expected merchant label.
	 */
	public function test_gets_known_early_fraud_warning_reason_labels( string $fraud_type, string $label ): void {
		$this->assertSame( $label, ( new WooPaymentsOrderNoteService() )->get_early_fraud_warning_reason_for_display( $fraud_type ) );
	}

	/**
	 * @return array<string,array{string,string}>
	 */
	public function early_fraud_warning_reason_provider(): array {
		return array(
			'card never received'    => array( 'card_never_received', 'Card never received' ),
			'fraudulent application' => array( 'fraudulent_card_application', 'Fraudulent card application' ),
			'counterfeit card'       => array( 'made_with_counterfeit_card', 'Made with counterfeit card' ),
			'lost card'              => array( 'made_with_lost_card', 'Made with lost card' ),
			'stolen card'            => array( 'made_with_stolen_card', 'Made with stolen card' ),
			'other'                  => array( 'misc', 'Other' ),
			'unauthorized use'       => array( 'unauthorized_use_of_card', 'Unauthorized use of card' ),
			'unknown'                => array( 'unknown', '' ),
			'empty'                  => array( '', '' ),
		);
	}
}
