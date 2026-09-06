<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments;

use Automattic\WooCommerce\Internal\MultiCurrency\Services\MultiCurrencyExplicitPriceProjectionService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsIppReceiptEmail;
use WC_Emails;
use WC_Order;
use WC_Product_Simple;
use WC_Unit_Test_Case;

/**
 * Tests for the WooPaymentsIppReceiptEmail class.
 */
class WooPaymentsIppReceiptEmailTest extends WC_Unit_Test_Case {

	/**
	 * Tear down test fixtures.
	 */
	public function tearDown(): void {
		remove_all_actions( 'woocommerce_payments_email_ipp_receipt_store_details' );
		remove_all_actions( 'woocommerce_payments_email_ipp_receipt_compliance_details' );
		remove_all_actions( 'woocommerce_payments_email_ipp_receipt_notification' );
		remove_all_filters( 'woocommerce_email_preview_dummy_order' );
		remove_all_filters( 'woocommerce_email_preview_dummy_address' );
		remove_all_filters( 'woocommerce_email_preview_placeholders' );
		delete_option( 'woocommerce_woocommerce_payments_new_receipt_settings' );
		parent::tearDown();
	}

	/**
	 * @testdox Receipt email trigger sends once and records the preserved sent marker.
	 */
	public function test_trigger_sends_once_and_records_sent_marker(): void {
		$email = $this->create_recording_email();
		$email->init_hooks();
		$order = $this->create_receipt_order();

		$email->trigger( $order, $this->get_merchant_settings(), $this->get_card_present_charge() );
		$email->trigger( $order, $this->get_merchant_settings(), $this->get_card_present_charge() );

		$order = wc_get_order( $order->get_id() );

		$this->assertInstanceOf( WC_Order::class, $order );
		$this->assertSame( 1, $email->send_count, 'The receipt email should not be sent again after the preserved sent marker is written.' );
		$this->assertSame( 'true', $order->get_meta( '_new_receipt_email_sent', true ) );
	}

	/**
	 * @testdox Receipt email trigger skips orders created by the mobile POS channel.
	 */
	public function test_trigger_skips_mobile_pos_orders(): void {
		$email = $this->create_recording_email();
		$order = $this->create_receipt_order();
		$order->update_meta_data( '_wcpay_ipp_channel', 'mobile_pos' );
		$order->save();

		$email->trigger( $order, $this->get_merchant_settings(), $this->get_card_present_charge() );
		$order = wc_get_order( $order->get_id() );

		$this->assertInstanceOf( WC_Order::class, $order );
		$this->assertSame( 0, $email->send_count );
		$this->assertSame( '', $order->get_meta( '_new_receipt_email_sent', true ) );
	}

	/**
	 * @testdox Receipt email renders HTML content with extension-compatible receipt sections.
	 */
	public function test_receipt_email_renders_extension_compatible_html_content(): void {
		$email = $this->create_prepared_email();

		$html = $email->get_content_html();

		$this->assertStringContainsString( 'Hi Ada,', $html );
		$this->assertStringContainsString( 'This is the receipt for your order #', $html );
		$this->assertStringContainsString( 'Store Details', $html );
		$this->assertStringContainsString( 'Reader Store', $html );
		$this->assertStringContainsString( '123 Sample Street', $html );
		$this->assertStringContainsString( 'support@example.test', $html );
		$this->assertStringContainsString( 'Payment Method', $html );
		$this->assertStringContainsString( 'Visa - 4242', $html );
		$this->assertStringContainsString( 'Application Name', $html );
		$this->assertStringContainsString( 'Visa credit', $html );
		$this->assertStringContainsString( 'AID', $html );
		$this->assertStringContainsString( 'A0000000031010', $html );
		$this->assertStringContainsString( 'Account Type', $html );
		$this->assertStringContainsString( 'Credit', $html );
	}

	/**
	 * @testdox Receipt email renders plain text content with extension-compatible receipt sections.
	 */
	public function test_receipt_email_renders_extension_compatible_plain_content(): void {
		$email = $this->create_prepared_email();

		$plain = $email->get_content_plain();

		$this->assertStringContainsString( 'Hi Ada,', $plain );
		$this->assertStringContainsString( 'This is the receipt for your order #', $plain );
		$this->assertStringContainsString( 'Reader Store', $plain );
		$this->assertStringContainsString( '123 Sample Street', $plain );
		$this->assertStringContainsString( 'support@example.test', $plain );
		$this->assertStringContainsString( 'Payment Method:', $plain );
		$this->assertStringContainsString( 'Visa - 4242', $plain );
		$this->assertStringContainsString( 'Application Name:', $plain );
		$this->assertStringContainsString( 'Visa credit', $plain );
		$this->assertStringContainsString( 'AID:', $plain );
		$this->assertStringContainsString( 'A0000000031010', $plain );
		$this->assertStringContainsString( 'Account Type:', $plain );
		$this->assertStringContainsString( 'Credit', $plain );
	}

	/**
	 * @testdox Receipt email matches the complete normalized WooPayments 10.8.0 output.
	 */
	public function test_receipt_email_matches_woopayments_10_8_0_oracle(): void {
		$this->with_oracle_store_options(
			function (): void {
				list( $order, $product ) = $this->create_oracle_receipt_order();
				$explicit_price_filter   = static function ( string $price, ?\WC_Abstract_Order $filtered_order ): string {
					return MultiCurrencyExplicitPriceProjectionService::get_explicit_price( $price, $filtered_order, true, 'USD' );
				};
				add_filter( 'woocommerce_get_formatted_order_total', $explicit_price_filter, 100, 2 );
				new WC_Emails();
				$email  = $this->create_recording_email();
				$charge = $this->get_oracle_card_present_charge();

				try {
					$email->init_hooks();
					$email->trigger( $order, $this->get_merchant_settings(), $charge );

					$this->assertSame(
						$this->load_oracle_fixture( 'ipp-receipt-10.8.0.html' ),
						$this->normalize_receipt_content( $email->get_content_html(), $order, false )
					);
					$this->assertSame(
						$this->load_oracle_fixture( 'ipp-receipt-10.8.0.txt' ),
						$this->normalize_receipt_content( $email->get_content_plain(), $order, true )
					);
				} finally {
					remove_filter( 'woocommerce_get_formatted_order_total', $explicit_price_filter, 100 );
					$order->delete( true );
					$product->delete( true );
				}
			}
		);
	}

	/**
	 * @testdox Receipt email compliance details prefer a recognized terminal network.
	 */
	public function test_compliance_details_prefer_a_recognized_terminal_network(): void {
		$email  = $this->create_recording_email();
		$charge = $this->get_card_present_charge();
		$charge['payment_method_details']['card_present']['network'] = 'eftpos_au';
		$charge['payment_method_details']['card_present']['last4']   = '0978';

		ob_start();
		$email->compliance_details( $charge, false );
		$content = (string) ob_get_clean();

		$this->assertStringContainsString( 'eftpos - 0978', $content );
		$this->assertStringNotContainsString( 'Visa - 0978', $content );
	}

	/**
	 * Create an email test double that records sends.
	 *
	 * @return object
	 */
	private function create_recording_email(): object {
		$this->assertTrue( class_exists( WooPaymentsIppReceiptEmail::class ), 'Native IPP receipt email class should exist.' );

		return new class() extends WooPaymentsIppReceiptEmail {
			/**
			 * Number of send attempts.
			 *
			 * @var int
			 */
			public int $send_count = 0;

			/**
			 * Record the send without invoking wp_mail.
			 *
			 * @param string $to          Recipient.
			 * @param string $subject     Subject.
			 * @param string $message     Message.
			 * @param string $headers     Headers.
			 * @param array  $attachments Attachments.
			 * @return bool
			 */
			public function send( $to, $subject, $message, $headers, $attachments ) {
				unset( $to, $subject, $message, $headers, $attachments );
				++$this->send_count;
				return true;
			}
		};
	}

	/**
	 * Create an email instance prepared for content rendering.
	 *
	 * @return object
	 */
	private function create_prepared_email(): object {
		$email = $this->create_recording_email();
		$email->init_hooks();
		$email->trigger( $this->create_receipt_order(), $this->get_merchant_settings(), $this->get_card_present_charge() );

		return $email;
	}

	/**
	 * Create an order for receipt email tests.
	 *
	 * @return WC_Order
	 */
	private function create_receipt_order(): WC_Order {
		$product = \WC_Helper_Product::create_simple_product();
		$product->set_regular_price( '10.00' );
		$product->set_price( '10.00' );
		$product->save();

		$order = wc_create_order();
		$order->set_billing_first_name( 'Ada' );
		$order->set_billing_email( 'ada@example.test' );
		$order->add_product( $product, 1 );
		$order->calculate_totals();
		$order->save();

		return $order;
	}

	/**
	 * Create the deterministic order used by the WooPayments 10.8.0 oracle.
	 *
	 * @return array{WC_Order,WC_Product_Simple}
	 */
	private function create_oracle_receipt_order(): array {
		$product = new WC_Product_Simple();
		$product->set_name( 'Oracle Reader Product' );
		$product->set_regular_price( '10.00' );
		$product->set_price( '10.00' );
		$product->save();

		$order = wc_create_order();
		$order->set_date_created( '2024-01-02 03:04:05' );
		$order->set_billing_first_name( 'Ada' );
		$order->set_billing_last_name( 'Lovelace' );
		$order->set_billing_email( 'ada@example.test' );
		$order->add_product( $product, 1 );
		$order->calculate_totals();
		$order->save();

		return array( $order, $product );
	}

	/**
	 * Run a callback with the deterministic store settings used by the oracle.
	 *
	 * @param callable $callback Callback to run.
	 * @return void
	 */
	private function with_oracle_store_options( callable $callback ): void {
		$option_values = array(
			'blogname'                      => 'woocommerce',
			'gmt_offset'                    => 0,
			'timezone_string'               => 'UTC',
			'woocommerce_default_country'   => 'US:CA',
			'woocommerce_email_footer_text' => '{site_title}<br />{store_address}',
			'woocommerce_feature_email_improvements_enabled' => 'yes',
			'woocommerce_currency'          => 'USD',
			'woocommerce_store_address'     => '123 Main St',
			'woocommerce_store_address_2'   => '',
			'woocommerce_store_city'        => 'San Francisco',
			'woocommerce_store_postcode'    => '94107',
		);
		$missing_value = new \stdClass();
		$originals     = array();

		foreach ( $option_values as $option_name => $option_value ) {
			$original_value            = get_option( $option_name, $missing_value );
			$originals[ $option_name ] = array(
				'exists' => $missing_value !== $original_value,
				'value'  => $original_value,
			);
			update_option( $option_name, $option_value );
		}

		try {
			$callback();
		} finally {
			foreach ( $originals as $option_name => $original ) {
				if ( $original['exists'] ) {
					update_option( $option_name, $original['value'] );
				} else {
					delete_option( $option_name );
				}
			}
		}
	}

	/**
	 * Normalize identifiers and timestamps that differ between test runs.
	 *
	 * @param string   $content    Rendered email content.
	 * @param WC_Order $order      Rendered order.
	 * @param bool     $plain_text Whether this is plain-text content.
	 * @return string
	 */
	private function normalize_receipt_content( string $content, WC_Order $order, bool $plain_text ): string {
		$content      = str_replace( array( "\r\n", "\r" ), "\n", $content );
		$site_urls    = array();
		$date_created = $order->get_date_created();
		$order_url    = $order->get_view_order_url();
		$content      = preg_replace( '/<img\b[^>]*\balt="Placeholder"[^>]*\/?>/', '<PLACEHOLDER_IMAGE>', $content );
		$this->assertIsString( $content );

		$content = str_replace( $order_url, '<ORDER_URL>', $content );
		foreach ( $order->get_items() as $item ) {
			$product_url = get_permalink( $item->get_product_id() );
			if ( is_string( $product_url ) ) {
				$content = str_replace( $product_url, '<PRODUCT_URL>', $content );
			}
		}

		foreach ( array( home_url(), site_url() ) as $site_url ) {
			$site_urls[] = untrailingslashit( $site_url );
			$host        = wp_parse_url( $site_url, PHP_URL_HOST );
			$port        = wp_parse_url( $site_url, PHP_URL_PORT );
			if ( is_string( $host ) ) {
				$site_urls[] = $host;
				if ( is_int( $port ) ) {
					$site_urls[] = $host . ':' . $port;
				}
			}
		}
		$site_urls = array_values( array_unique( array_filter( $site_urls ) ) );

		usort(
			$site_urls,
			static function ( string $left, string $right ): int {
				return strlen( $right ) <=> strlen( $left );
			}
		);
		$content = str_replace( $site_urls, '<SITE_URL>', $content );
		$content = str_replace( (string) $order->get_id(), '<ORDER_ID>', $content );
		if ( null !== $date_created ) {
			$content = str_replace( wc_format_datetime( $date_created ), '<ORDER_DATE>', $content );
		}

		$content = preg_replace( '/\b\d{4}-\d{2}-\d{2} \d{2}:\d{2}(?:AM|PM)\b/', '<RENDERED_AT>', $content );
		$this->assertIsString( $content );

		if ( ! $plain_text ) {
			// Current WooCommerce email templates add presentational attributes that were absent from the 10.8.0 oracle.
			$content = str_replace( array( ' class="wc-product-name"', ' dir="auto"' ), '', $content );
			$content = preg_replace( '/\s+/', ' ', trim( $content ) );
			$this->assertIsString( $content );
			return $content;
		}

		$lines   = array_map( 'rtrim', explode( "\n", $content ) );
		$content = preg_replace( "/\n{3,}/", "\n\n", trim( implode( "\n", $lines ) ) );
		$this->assertIsString( $content );

		return $content;
	}

	/**
	 * Load a complete normalized WooPayments 10.8.0 output fixture.
	 *
	 * @param string $filename Fixture filename.
	 * @return string
	 */
	private function load_oracle_fixture( string $filename ): string {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reads a local immutable test fixture.
		$fixture = file_get_contents( __DIR__ . '/Fixtures/' . $filename );
		$this->assertIsString( $fixture );

		return rtrim( $fixture, "\r\n" );
	}

	/**
	 * Get merchant receipt settings.
	 *
	 * @return array<string,mixed>
	 */
	private function get_merchant_settings(): array {
		return array(
			'business_name' => 'Reader Store',
			'support_info'  => array(
				'address' => array(
					'line1'       => '123 Sample Street',
					'line2'       => 'Suite 100',
					'city'        => 'San Francisco',
					'state'       => 'CA',
					'postal_code' => '94107',
					'country'     => 'US',
				),
				'phone'   => '+1 555 0100',
				'email'   => 'support@example.test',
			),
		);
	}

	/**
	 * Get a card-present charge fixture.
	 *
	 * @return array<string,mixed>
	 */
	private function get_card_present_charge(): array {
		return array(
			'id'                     => 'ch_card_present',
			'amount_captured'        => 1000,
			'currency'               => 'usd',
			'payment_method_details' => array(
				'type'         => 'card_present',
				'card_present' => array(
					'brand'   => 'visa',
					'last4'   => '4242',
					'receipt' => array(
						'application_preferred_name' => 'visa credit',
						'dedicated_file_name'        => 'a0000000031010',
						'account_type'               => 'credit',
					),
				),
			),
		);
	}

	/**
	 * Get the card-present charge used by the WooPayments 10.8.0 oracle.
	 *
	 * @return array<string,mixed>
	 */
	private function get_oracle_card_present_charge(): array {
		$charge = $this->get_card_present_charge();
		$charge['payment_method_details']['card_present']['network'] = 'eftpos_au';
		$charge['payment_method_details']['card_present']['last4']   = '0978';
		$charge['payment_method_details']['card_present']['receipt'] = array(
			'application_preferred_name' => 'eftpos debit',
			'dedicated_file_name'        => 'a00000038410',
			'account_type'               => 'debit',
		);

		return $charge;
	}
}
