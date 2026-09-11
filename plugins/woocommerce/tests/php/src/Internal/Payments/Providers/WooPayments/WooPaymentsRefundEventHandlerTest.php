<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments;

use Automattic\WooCommerce\Internal\Payments\OrderPaymentStore;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsHtmlUtils;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsOrderNoteService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsRefundEventHandler;
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
		parent::tearDown();
	}

	/**
	 * @testdox A successful synchronous refund and its webhook converge on one canonical note and refund row.
	 */
	public function test_successful_synchronous_refund_followed_by_webhook_converges(): void {
		$previous_enabled_currencies = get_option( 'wcpay_multi_currency_enabled_currencies', false );
		update_option( 'wcpay_multi_currency_enabled_currencies', array( 'EUR' ) );

		try {
			$order  = $this->create_refundable_order();
			$refund = $this->create_local_refund( $order );
			$refund->update_meta_data( '_wcpay_refund_id', 're_123' );
			$refund->save_meta_data();
			$order->update_meta_data( '_wcpay_refund_status', 'successful' );
			$order->add_order_note(
				wc_get_container()->get( WooPaymentsOrderNoteService::class )->format_created_refund_note(
					$order,
					4.00,
					(string) $order->get_currency(),
					're_123',
					'Requested by customer',
					false
				)
			);
			$order->save();

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
			$this->assertSame(
				sprintf(
					'A refund of %s USD was successfully processed using WooPayments. Reason: Requested by customer. (<code>re_123</code>)',
					wc_price( 4.00, array( 'currency' => 'USD' ) )
				),
				$refund_notes[0]->content
			);
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
