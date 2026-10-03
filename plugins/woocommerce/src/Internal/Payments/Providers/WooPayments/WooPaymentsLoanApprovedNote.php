<?php
/**
 * WooPaymentsLoanApprovedNote class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\WooPayments;

use Automattic\WooCommerce\Admin\Notes\DataStore as NotesDataStore;
use Automattic\WooCommerce\Admin\Notes\Note;
use Automattic\WooCommerce\Admin\Notes\Notes;
use Automattic\WooCommerce\Internal\Admin\Settings\Utils;
use Automattic\WooCommerce\Internal\MultiCurrency\Services\MultiCurrencyExplicitPriceProjectionService;
use Automattic\WooCommerce\Internal\Payments\NativePaymentsRuntimeArbiter;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api\WooPaymentsApiClient;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api\WooPaymentsApiException;
use Automattic\WooCommerce\Internal\RegisterHooksInterface;
use Throwable;

/**
 * Inbox note telling the merchant their WooPayments Capital loan was paid out.
 *
 * Ports client 11.1.0 `includes/notes/class-wc-payments-notes-loan-approved.php` and its account-refresh handler
 * `WC_Payments_Account::handle_loan_approved_inbox_note()` (`includes/class-wc-payments-account.php:135,2778-2805`).
 *
 * @since 11.2.0
 * @internal Transitional internal component for the native payments runtime.
 */
class WooPaymentsLoanApprovedNote implements RegisterHooksInterface {

	/**
	 * Note name.
	 *
	 * @var string
	 */
	public const NOTE_NAME = 'wc-payments-notes-loan-approved';

	/**
	 * Runtime owner arbiter.
	 *
	 * @var NativePaymentsRuntimeArbiter
	 */
	private NativePaymentsRuntimeArbiter $arbiter;

	/**
	 * WooPayments API client.
	 *
	 * @var WooPaymentsApiClient
	 */
	private WooPaymentsApiClient $api_client;

	/**
	 * Initialize the class instance.
	 *
	 * @internal
	 *
	 * @param NativePaymentsRuntimeArbiter $arbiter    Runtime owner arbiter.
	 * @param WooPaymentsApiClient         $api_client WooPayments API client.
	 */
	final public function init( NativePaymentsRuntimeArbiter $arbiter, WooPaymentsApiClient $api_client ): void {
		$this->arbiter    = $arbiter;
		$this->api_client = $api_client;
	}

	/**
	 * Register the account refresh hook.
	 */
	public function register() {
		if ( ! $this->arbiter->should_native_register() ) {
			return;
		}

		add_action( 'woocommerce_payments_account_refreshed', array( $this, 'handle_loan_approved_inbox_note' ) );
	}

	/**
	 * Add, replace or delete the loan note from refreshed account data.
	 *
	 * @internal
	 *
	 * @param mixed $account Account data from the WooPayments account refresh hook.
	 */
	public function handle_loan_approved_inbox_note( $account ): void {
		if ( empty( $account ) || ! is_array( $account ) ) {
			return;
		}

		try {
			if ( empty( $account['capital']['has_active_loan'] ) ) {
				$this->delete_notes();
				return;
			}

			try {
				$loan_info = $this->api_client->get_capital_active_loan_summary();
			} catch ( WooPaymentsApiException $exception ) {
				return;
			}

			$this->possibly_add_note( $loan_info );
		} catch ( Throwable $exception ) {
			wc_get_logger()->error(
				'Failed to refresh the native WooPayments loan approved note. ' . $exception->getMessage(),
				array( 'source' => 'woopayments' )
			);
		}
	}

	/**
	 * Add the note for the active loan, replacing a note written for another loan.
	 *
	 * @param array<string,mixed> $loan_info Active loan summary from `GET capital/active_loan_summary`.
	 */
	private function possibly_add_note( array $loan_info ): void {
		$details = is_array( $loan_info['details'] ?? null ) ? $loan_info['details'] : array();
		if (
			! isset( $details['currency'], $details['advance_amount'], $details['advance_paid_out_at'] )
			|| ! is_numeric( $details['advance_amount'] )
			|| empty( $details['currency'] )
		) {
			return;
		}

		$details['currency'] = strtoupper( (string) $details['currency'] );

		/**
		 * Notes data store.
		 *
		 * @var NotesDataStore $data_store
		 */
		$data_store = Notes::load_data_store();
		$note_ids   = $data_store->get_notes_with_name( self::NOTE_NAME );
		if ( ! empty( $note_ids ) ) {
			$note = Notes::get_note( (int) $note_ids[0] );
			if ( $note instanceof Note ) {
				$content_data = (array) $note->get_content_data();
				if (
					isset( $content_data['advance_paid_out_at'], $content_data['advance_amount'] )
					&& $details['advance_paid_out_at'] === $content_data['advance_paid_out_at']
					&& $details['advance_amount'] === $content_data['advance_amount']
				) {
					return;
				}

				$data_store->delete( $note );
			}
		}

		if ( ! empty( $data_store->get_notes_with_name( self::NOTE_NAME ) ) ) {
			return;
		}

		$this->get_note( $details )->save();
	}

	/**
	 * Build the note.
	 *
	 * The client formats the amount through a throwaway order it creates with `wc_create_order()` on every build
	 * (client `get_note()` lines 42-44), leaving an empty pending order behind; native passes the loan currency to the
	 * explicit-price formatter instead, which yields the same text without writing an order.
	 *
	 * @param array<string,mixed> $details Validated loan details with an upper-case currency.
	 * @return Note
	 */
	private function get_note( array $details ): Note {
		$currency = (string) $details['currency'];
		// The client calls interpret_stripe_amount() without a currency, so it always divides by 100 (client line 53).
		$price = WooPaymentsCurrencyUtils::format_price_in_currency(
			WooPaymentsCurrencyUtils::amount_from_minor_units( (int) $details['advance_amount'], 'usd' ),
			$currency
		);

		$note = new Note();
		$note->set_title( __( 'Your capital loan has been approved!', 'woocommerce' ) );
		$note->set_content(
			sprintf(
				/* translators: %1$s: total amount lent to the merchant formatted in the account currency, %2$s: WooPayments */
				__( 'Congratulations! Your capital loan has been approved and %1$s was deposited into the bank account linked to %2$s. You\'ll automatically repay the loan, plus a flat fee, through a fixed percentage of each %2$s transaction.', 'woocommerce' ),
				MultiCurrencyExplicitPriceProjectionService::get_explicit_price_with_currency(
					$price,
					$currency,
					MultiCurrencyExplicitPriceProjectionService::should_output_explicit_admin_price()
				),
				'WooPayments'
			)
		);
		$note->set_content_data(
			(object) array(
				'advance_amount'      => $details['advance_amount'],
				'advance_paid_out_at' => $details['advance_paid_out_at'],
			)
		);
		$note->set_type( Note::E_WC_ADMIN_NOTE_INFORMATIONAL );
		$note->set_name( self::NOTE_NAME );
		$note->set_source( 'woocommerce-payments' );
		$note->add_action(
			self::NOTE_NAME,
			__( 'View loan details', 'woocommerce' ),
			// A stored note outlives a switch back to the plugin, so it keeps the client route; native redirects it.
			Utils::wc_payments_legacy_admin_url( '/payments/loans' ),
			Note::E_WC_ADMIN_NOTE_UNACTIONED,
			true
		);

		return $note;
	}

	/**
	 * Delete every stored loan note.
	 */
	private function delete_notes(): void {
		/**
		 * Notes data store.
		 *
		 * @var NotesDataStore $data_store
		 */
		$data_store = Notes::load_data_store();
		foreach ( $data_store->get_notes_with_name( self::NOTE_NAME ) as $note_id ) {
			$note = Notes::get_note( (int) $note_id );
			if ( $note instanceof Note ) {
				$data_store->delete( $note );
			}
		}
	}
}
