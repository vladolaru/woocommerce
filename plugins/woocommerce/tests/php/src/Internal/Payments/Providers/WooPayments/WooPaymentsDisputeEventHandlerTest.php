<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments;

use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api\WooPaymentsApiClient;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsDisputeCacheService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsDisputeEventHandler;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsLegacyRuntime;
use ReflectionClass;
use WC_Unit_Test_Case;

/**
 * Tests for the WooPaymentsDisputeEventHandler class.
 */
class WooPaymentsDisputeEventHandlerTest extends WC_Unit_Test_Case {

	/**
	 * The System Under Test.
	 *
	 * @var WooPaymentsDisputeEventHandler
	 */
	private $sut;

	/**
	 * Set up test fixtures.
	 */
	public function setUp(): void {
		parent::setUp();

		$this->sut = new WooPaymentsDisputeEventHandler();
		$this->sut->init(
			wc_get_container()->get( WooPaymentsLegacyRuntime::class ),
			new class() extends WooPaymentsApiClient {},
			wc_get_container()->get( WooPaymentsDisputeCacheService::class )
		);
	}

	/**
	 * Invoke a private method on the System Under Test.
	 *
	 * @param string       $method Method name.
	 * @param array<mixed> $args   Method arguments.
	 * @return mixed
	 */
	private function invoke_private( string $method, array $args ) {
		$reflection = new ReflectionClass( $this->sut );
		$method     = $reflection->getMethod( $method );
		$method->setAccessible( true );

		return $method->invokeArgs( $this->sut, $args );
	}

	/**
	 * @testdox Should escape the webhook dispute status in a closed dispute note.
	 */
	public function test_dispute_closed_note_escapes_status(): void {
		$note = $this->invoke_private(
			'get_dispute_closed_note',
			array( 'ch_123', '<script>alert(1)</script>', false, 'txn_1' )
		);

		$this->assertStringNotContainsString( '<script>', $note, 'The raw script tag must not appear in the closed dispute note.' );
		$this->assertStringContainsString( '&lt;script&gt;', $note, 'The status must be HTML-escaped in the closed dispute note.' );
	}

	/**
	 * @testdox Should escape the webhook dispute status in a closed inquiry note.
	 */
	public function test_dispute_closed_inquiry_note_escapes_status(): void {
		$note = $this->invoke_private(
			'get_dispute_closed_note',
			array( 'ch_123', '<script>alert(1)</script>', true, 'txn_1' )
		);

		$this->assertStringNotContainsString( '<script>', $note, 'The raw script tag must not appear in the closed inquiry note.' );
		$this->assertStringContainsString( '&lt;script&gt;', $note, 'The status must be HTML-escaped in the closed inquiry note.' );
	}

	/**
	 * @testdox Should escape the webhook reason and due date in a created dispute note.
	 */
	public function test_dispute_created_note_escapes_reason_and_due_by(): void {
		$note = $this->invoke_private(
			'get_dispute_created_note',
			array( 'ch_123', '$10.00', '<b>reason</b>', '<i>due</i>', false, 'txn_1' )
		);

		$this->assertStringNotContainsString( '<b>reason</b>', $note, 'The raw reason markup must not appear in the created dispute note.' );
		$this->assertStringNotContainsString( '<i>due</i>', $note, 'The raw due date markup must not appear in the created dispute note.' );
		$this->assertStringContainsString( '&lt;b&gt;reason&lt;/b&gt;', $note, 'The reason must be HTML-escaped in the created dispute note.' );
		$this->assertStringContainsString( '&lt;i&gt;due&lt;/i&gt;', $note, 'The due date must be HTML-escaped in the created dispute note.' );
	}

	/**
	 * @testdox Should escape the webhook reason and due date in a created inquiry note.
	 */
	public function test_dispute_created_inquiry_note_escapes_reason_and_due_by(): void {
		$note = $this->invoke_private(
			'get_dispute_created_note',
			array( 'ch_123', '$10.00', '<b>reason</b>', '<i>due</i>', true, 'txn_1' )
		);

		$this->assertStringNotContainsString( '<b>reason</b>', $note, 'The raw reason markup must not appear in the created inquiry note.' );
		$this->assertStringNotContainsString( '<i>due</i>', $note, 'The raw due date markup must not appear in the created inquiry note.' );
		$this->assertStringContainsString( '&lt;b&gt;reason&lt;/b&gt;', $note, 'The reason must be HTML-escaped in the created inquiry note.' );
		$this->assertStringContainsString( '&lt;i&gt;due&lt;/i&gt;', $note, 'The due date must be HTML-escaped in the created inquiry note.' );
	}

	/**
	 * @testdox Should not double-escape the pre-formatted amount markup in a created dispute note.
	 */
	public function test_dispute_created_note_preserves_amount_markup(): void {
		$amount = wc_price( 10.0, array( 'currency' => 'USD' ) );

		$note = $this->invoke_private(
			'get_dispute_created_note',
			array( 'ch_123', $amount, 'Fraudulent', 'June 1, 2026', false, 'txn_1' )
		);

		$this->assertStringContainsString( $amount, $note, 'The pre-formatted price HTML must be preserved without double-escaping.' );
	}

	/**
	 * @testdox Should include an explicit currency code in dispute amounts when multiple currencies are enabled.
	 */
	public function test_formatted_dispute_amount_includes_currency_code_when_multiple_currencies_are_enabled(): void {
		$previous_store_currency     = get_option( 'woocommerce_currency' );
		$previous_enabled_currencies = get_option( 'wcpay_multi_currency_enabled_currencies' );

		try {
			update_option( 'woocommerce_currency', 'USD' );
			update_option( 'wcpay_multi_currency_enabled_currencies', array( 'GBP', 'EUR' ) );

			$order = wc_create_order();
			$order->set_currency( 'USD' );

			$amount = $this->invoke_private( 'get_formatted_dispute_amount', array( $order, 5000 ) );

			$this->assertStringContainsString( '$50.00 USD', wp_strip_all_tags( html_entity_decode( $amount ) ), 'Dispute amounts should match the extension explicit-currency note format.' );
		} finally {
			update_option( 'woocommerce_currency', $previous_store_currency );
			update_option( 'wcpay_multi_currency_enabled_currencies', $previous_enabled_currencies );
		}
	}
}
