<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments;

use Automattic\WooCommerce\Admin\Notes\Note;
use Automattic\WooCommerce\Admin\Notes\Notes;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api\WooPaymentsApiClient;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api\WooPaymentsApiException;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsLoanApprovedNote;
use Automattic\WooCommerce\Tests\Internal\Payments\StaticNativeRuntimeArbiter;
use PHPUnit\Framework\MockObject\MockObject;
use WC_Unit_Test_Case;

/**
 * Tests for the WooPaymentsLoanApprovedNote class.
 *
 * Loan summaries come from client 11.1.0 fixtures: `tests/unit/test-class-wc-payments-account.php:3568-3640`
 * (1234567 in USD and CHF) and `client/components/active-loan-summary/__tests__/index.test.js:64-68`
 * (100000 in lower-case usd, paid out at 1643889167).
 */
class WooPaymentsLoanApprovedNoteTest extends WC_Unit_Test_Case {

	/**
	 * The System Under Test.
	 *
	 * @var WooPaymentsLoanApprovedNote
	 */
	private $sut;

	/**
	 * API client mock.
	 *
	 * @var WooPaymentsApiClient&MockObject
	 */
	private $api_client;

	/**
	 * Set up test fixtures.
	 */
	public function setUp(): void {
		parent::setUp();

		$this->delete_notes();
		$this->api_client = $this->createMock( WooPaymentsApiClient::class );
		$this->sut        = $this->create_sut( true );
	}

	/**
	 * @testdox Registers the account refresh hook when native owns the runtime.
	 */
	public function test_registers_account_refresh_hook_when_native_owns_runtime(): void {
		$this->sut->register();

		$this->assertSame( 10, has_action( 'woocommerce_payments_account_refreshed', array( $this->sut, 'handle_loan_approved_inbox_note' ) ) );
	}

	/**
	 * @testdox Registers no hook on a plugin-owned store.
	 */
	public function test_registers_no_hook_on_plugin_owned_store(): void {
		$sut = $this->create_sut( false );

		$sut->register();

		$this->assertFalse( has_action( 'woocommerce_payments_account_refreshed', array( $sut, 'handle_loan_approved_inbox_note' ) ), 'A plugin-owned store must never get native notes.' );
	}

	/**
	 * @testdox Adds the loan note with the client's name, copy, data and action for an active loan.
	 */
	public function test_adds_note_for_active_loan(): void {
		$paid_out_at = time();
		$this->api_client->expects( $this->once() )->method( 'get_capital_active_loan_summary' )->willReturn( $this->get_loan_summary( 1234567, $paid_out_at, 'USD' ) );

		$this->sut->handle_loan_approved_inbox_note( $this->get_active_loan_account() );

		$note = $this->get_single_note();
		$this->assertSame( 'Your capital loan has been approved!', $note->get_title() );
		$this->assertSame( Note::E_WC_ADMIN_NOTE_INFORMATIONAL, $note->get_type() );
		$this->assertSame( 'woocommerce-payments', $note->get_source() );
		$this->assertStringContainsString( 'Congratulations! Your capital loan has been approved and ', $note->get_content() );
		$this->assertStringContainsString( wp_kses_normalize_entities( wp_strip_all_tags( wc_price( 12345.67 ) ) ), $note->get_content() );
		$this->assertStringContainsString( ' was deposited into the bank account linked to WooPayments.', $note->get_content() );

		$content_data = (array) $note->get_content_data();
		$this->assertSame( 1234567, $content_data['advance_amount'] );
		$this->assertSame( $paid_out_at, $content_data['advance_paid_out_at'] );

		$actions = $note->get_actions();
		$this->assertCount( 1, $actions );
		$this->assertSame( 'wc-payments-notes-loan-approved', $actions[0]->name );
		$this->assertSame( 'View loan details', $actions[0]->label );
		$this->assertSame( admin_url( 'admin.php?page=wc-admin&path=/payments/loans' ), $actions[0]->query );
		$this->assertSame( Note::E_WC_ADMIN_NOTE_UNACTIONED, $actions[0]->status );
	}

	/**
	 * @testdox Formats the loan amount in the loan currency.
	 */
	public function test_formats_amount_in_loan_currency(): void {
		$this->api_client->method( 'get_capital_active_loan_summary' )->willReturn( $this->get_loan_summary( 1234567, time(), 'CHF' ) );

		$this->sut->handle_loan_approved_inbox_note( $this->get_active_loan_account() );

		$this->assertStringContainsString(
			wp_kses_normalize_entities( wp_strip_all_tags( wc_price( 12345.67, array( 'currency' => 'CHF' ) ) ) ),
			$this->get_single_note()->get_content()
		);
	}

	/**
	 * @testdox Appends the currency code when explicit prices are on.
	 */
	public function test_appends_currency_code_when_explicit_prices_are_on(): void {
		add_filter( 'wcpay_multi_currency_should_output_explicit_price', '__return_true' );
		$this->api_client->method( 'get_capital_active_loan_summary' )->willReturn( $this->get_loan_summary( 100000, 1643889167, 'usd' ) );

		$this->sut->handle_loan_approved_inbox_note( $this->get_active_loan_account() );

		$this->assertStringContainsString(
			wp_kses_normalize_entities( wp_strip_all_tags( wc_price( 1000.0, array( 'currency' => 'USD' ) ) ) ) . ' USD was deposited',
			$this->get_single_note()->get_content()
		);
	}

	/**
	 * @testdox Replaces the note when the active loan data changes.
	 */
	public function test_replaces_note_when_loan_data_changes(): void {
		$this->api_client->method( 'get_capital_active_loan_summary' )->willReturnOnConsecutiveCalls(
			$this->get_loan_summary( 1234567, time(), 'USD' ),
			$this->get_loan_summary( 100000, 1643889167, 'usd' )
		);
		$this->sut->handle_loan_approved_inbox_note( $this->get_active_loan_account() );
		$first_note_id = $this->get_single_note()->get_id();

		$this->sut->handle_loan_approved_inbox_note( $this->get_active_loan_account() );

		$note         = $this->get_single_note();
		$content_data = (array) $note->get_content_data();
		$this->assertNotSame( $first_note_id, $note->get_id(), 'The note for the previous loan should be replaced.' );
		$this->assertSame( 100000, $content_data['advance_amount'] );
		$this->assertSame( 1643889167, $content_data['advance_paid_out_at'] );
	}

	/**
	 * @testdox Keeps the note when the active loan data is unchanged.
	 */
	public function test_keeps_note_when_loan_data_is_unchanged(): void {
		$this->api_client->method( 'get_capital_active_loan_summary' )->willReturn( $this->get_loan_summary( 100000, 1643889167, 'usd' ) );
		$this->sut->handle_loan_approved_inbox_note( $this->get_active_loan_account() );
		$first_note_id = $this->get_single_note()->get_id();

		$this->sut->handle_loan_approved_inbox_note( $this->get_active_loan_account() );

		$this->assertSame( $first_note_id, $this->get_single_note()->get_id() );
	}

	/**
	 * @testdox Deletes the note when the account has no active loan.
	 * @dataProvider provide_accounts_without_active_loan
	 *
	 * @param array<string,mixed> $account Account data.
	 */
	public function test_deletes_note_without_active_loan( array $account ): void {
		$this->api_client->method( 'get_capital_active_loan_summary' )->willReturn( $this->get_loan_summary( 100000, 1643889167, 'usd' ) );
		$this->sut->handle_loan_approved_inbox_note( $this->get_active_loan_account() );
		$this->get_single_note();

		$this->sut->handle_loan_approved_inbox_note( $account );

		$this->assertSame( array(), Notes::load_data_store()->get_notes_with_name( WooPaymentsLoanApprovedNote::NOTE_NAME ) );
	}

	/**
	 * Accounts without an active loan (client `loan_approved_no_action_account_states`, minus the empty account).
	 *
	 * @return array<string,array{array<string,mixed>}>
	 */
	public function provide_accounts_without_active_loan(): array {
		return array(
			'no capital data' => array( array( 'capital' => array() ) ),
			'loan not active' => array( array( 'capital' => array( 'has_active_loan' => false ) ) ),
		);
	}

	/**
	 * @testdox Leaves notes alone and makes no request for an empty account.
	 */
	public function test_ignores_empty_account(): void {
		$this->api_client->method( 'get_capital_active_loan_summary' )->willReturn( $this->get_loan_summary( 100000, 1643889167, 'usd' ) );
		$this->sut->handle_loan_approved_inbox_note( $this->get_active_loan_account() );

		$api_client = $this->createMock( WooPaymentsApiClient::class );
		$api_client->expects( $this->never() )->method( 'get_capital_active_loan_summary' );
		$sut = new WooPaymentsLoanApprovedNote();
		$sut->init( new StaticNativeRuntimeArbiter( true ), $api_client );

		$sut->handle_loan_approved_inbox_note( array() );

		$this->get_single_note();
	}

	/**
	 * @testdox Adds no note when the loan summary request fails.
	 */
	public function test_adds_no_note_when_summary_request_fails(): void {
		$this->api_client->method( 'get_capital_active_loan_summary' )->willThrowException( new WooPaymentsApiException( 'test_exception', 'test_exception', 400 ) );

		$this->sut->handle_loan_approved_inbox_note( $this->get_active_loan_account() );

		$this->assertSame( array(), Notes::load_data_store()->get_notes_with_name( WooPaymentsLoanApprovedNote::NOTE_NAME ) );
	}

	/**
	 * @testdox Adds no note when the loan summary is invalid.
	 */
	public function test_adds_no_note_when_summary_is_invalid(): void {
		$this->api_client->method( 'get_capital_active_loan_summary' )->willReturn( array( 'test' ) );

		$this->sut->handle_loan_approved_inbox_note( $this->get_active_loan_account() );

		$this->assertSame( array(), Notes::load_data_store()->get_notes_with_name( WooPaymentsLoanApprovedNote::NOTE_NAME ) );
	}

	/**
	 * Create the note provider.
	 *
	 * @param bool $native_owns_runtime Whether native owns the payments runtime.
	 * @return WooPaymentsLoanApprovedNote
	 */
	private function create_sut( bool $native_owns_runtime ): WooPaymentsLoanApprovedNote {
		$sut = new WooPaymentsLoanApprovedNote();
		$sut->init( new StaticNativeRuntimeArbiter( $native_owns_runtime ), $this->api_client );

		return $sut;
	}

	/**
	 * Account data with an active loan (client `get_cached_account_loan_data()`).
	 *
	 * @return array<string,mixed>
	 */
	private function get_active_loan_account(): array {
		return array( 'capital' => array( 'has_active_loan' => true ) );
	}

	/**
	 * Build an active loan summary response.
	 *
	 * @param int    $advance_amount      Advance amount in minor units.
	 * @param int    $advance_paid_out_at Payout timestamp.
	 * @param string $currency            Loan currency.
	 * @return array<string,mixed>
	 */
	private function get_loan_summary( int $advance_amount, int $advance_paid_out_at, string $currency ): array {
		return array(
			'details' => array(
				'advance_amount'      => $advance_amount,
				'advance_paid_out_at' => $advance_paid_out_at,
				'currency'            => $currency,
			),
		);
	}

	/**
	 * Get the only stored loan note.
	 *
	 * @return Note
	 */
	private function get_single_note(): Note {
		$note_ids = Notes::load_data_store()->get_notes_with_name( WooPaymentsLoanApprovedNote::NOTE_NAME );
		$this->assertCount( 1, $note_ids, 'Exactly one loan note should be stored.' );
		$note = Notes::get_note( (int) $note_ids[0] );
		$this->assertInstanceOf( Note::class, $note );

		return $note;
	}

	/**
	 * Delete stored loan notes.
	 */
	private function delete_notes(): void {
		$data_store = Notes::load_data_store();
		foreach ( $data_store->get_notes_with_name( WooPaymentsLoanApprovedNote::NOTE_NAME ) as $note_id ) {
			$note = Notes::get_note( (int) $note_id );
			if ( $note instanceof Note ) {
				$data_store->delete( $note );
			}
		}
	}
}
