<?php
/**
 * WooPaymentsSetUpLinkNote class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\WooPayments;

use Automattic\WooCommerce\Admin\Notes\DataStore as NotesDataStore;
use Automattic\WooCommerce\Admin\Notes\Note;
use Automattic\WooCommerce\Admin\Notes\Notes;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\PaymentMethods\WooPaymentsPaymentMethodRegistry;

/**
 * Inbox note suggesting Link by Stripe to merchants who accept cards without Link.
 *
 * Ports client 11.1.0 `includes/notes/class-wc-payments-notes-set-up-stripelink.php`.
 *
 * @since 11.2.0
 * @internal Transitional internal component for the native payments runtime.
 */
class WooPaymentsSetUpLinkNote {

	/**
	 * Note name.
	 *
	 * @var string
	 */
	public const NOTE_NAME = 'wc-payments-notes-set-up-stripe-link';

	/**
	 * Documentation URL of the note action.
	 *
	 * @var string
	 */
	public const NOTE_DOCUMENTATION_URL = 'https://woocommerce.com/document/woopayments/payment-methods/link-by-stripe/';

	private const CARD = 'card';

	private const LINK = 'link';

	/**
	 * WooPayments account service.
	 *
	 * @var WooPaymentsAccountService
	 */
	private WooPaymentsAccountService $account_service;

	/**
	 * Payment method definition registry.
	 *
	 * @var WooPaymentsPaymentMethodRegistry
	 */
	private WooPaymentsPaymentMethodRegistry $payment_method_registry;

	/**
	 * Initialize the class instance.
	 *
	 * @internal
	 *
	 * @param WooPaymentsAccountService        $account_service         WooPayments account service.
	 * @param WooPaymentsPaymentMethodRegistry $payment_method_registry Payment method definition registry.
	 */
	final public function init( WooPaymentsAccountService $account_service, WooPaymentsPaymentMethodRegistry $payment_method_registry ): void {
		$this->account_service         = $account_service;
		$this->payment_method_registry = $payment_method_registry;
	}

	/**
	 * Add the note when Link is available and cards are enabled at checkout without Link.
	 *
	 * @return void
	 */
	public function possibly_add_note(): void {
		if ( ! $this->should_display_note() ) {
			return;
		}

		/**
		 * Notes data store.
		 *
		 * @var NotesDataStore $data_store
		 */
		$data_store = Notes::load_data_store();
		if ( ! empty( $data_store->get_notes_with_name( self::NOTE_NAME ) ) ) {
			return;
		}

		$this->get_note()->save();
	}

	/**
	 * Tell whether the note applies to the store.
	 *
	 * Client `should_display_note()` (lines 39-58): Link is among the available methods, and the methods
	 * enabled at checkout and backed by account fees include card but not Link.
	 *
	 * @return bool
	 */
	public function should_display_note(): bool {
		$account_data = $this->account_service->get_cached_account_data();
		$fees         = is_array( $account_data['fees'] ?? null ) ? $account_data['fees'] : array();
		$fee_ids      = array_map( 'strval', array_keys( $fees ) );

		if ( ! in_array( self::LINK, $this->payment_method_registry->get_available_payment_method_ids_with_fees( $fees ), true ) ) {
			return false;
		}

		// The client drops Link from the enabled methods when card is not enabled, so only card-without-Link qualifies.
		return $this->is_enabled_at_checkout( self::CARD, $fee_ids )
			&& ! $this->is_enabled_at_checkout( self::LINK, $fee_ids );
	}

	/**
	 * Build the note.
	 *
	 * @return Note
	 */
	public function get_note(): Note {
		$note = new Note();
		$note->set_title( __( 'Increase conversion at checkout', 'woocommerce' ) );
		$note->set_content( __( 'Reduce cart abandonment and create a frictionless checkout experience with Link by Stripe. Link autofills your customer’s payment and shipping details, so they can check out in just six seconds with the Link optimized experience.', 'woocommerce' ) );
		$note->set_content_data( (object) array() );
		$note->set_type( Note::E_WC_ADMIN_NOTE_INFORMATIONAL );
		$note->set_name( self::NOTE_NAME );
		$note->set_source( 'woocommerce-payments' );
		$note->add_action(
			self::NOTE_NAME,
			__( 'Set up now', 'woocommerce' ),
			self::NOTE_DOCUMENTATION_URL,
			Note::E_WC_ADMIN_NOTE_UNACTIONED,
			true
		);

		return $note;
	}

	/**
	 * Tell whether a payment method is enabled at checkout and backed by account fees.
	 *
	 * Client `get_payment_method_ids_enabled_at_checkout_filtered_by_fees( null, true )` for card and Link on an admin
	 * request: the method is in the enabled setting, supports the store currency, and its account capability is active
	 * (an empty capability map counts as an active card capability). Manual capture keeps card and Link, and neither
	 * has amount limits or country restrictions, so those checks cannot change the result.
	 *
	 * @param string   $payment_method_id Payment method ID.
	 * @param string[] $fee_ids           Payment method IDs with account fees.
	 * @return bool
	 */
	private function is_enabled_at_checkout( string $payment_method_id, array $fee_ids ): bool {
		$enabled_ids = $this->account_service->get_gateway_setting( 'upe_enabled_payment_method_ids' );
		if ( ! is_array( $enabled_ids ) || ! in_array( $payment_method_id, $enabled_ids, true ) || ! in_array( $payment_method_id, $fee_ids, true ) ) {
			return false;
		}

		$definition = $this->payment_method_registry->get( $payment_method_id );
		if ( null === $definition || ! $definition->is_available_for( get_woocommerce_currency(), $this->account_service->get_account_country() ) ) {
			return false;
		}

		return $this->account_service->is_capability_active( $definition->get_account_capability_key() );
	}
}
