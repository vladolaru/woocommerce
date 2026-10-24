<?php
/**
 * WooPaymentsAccountEventHandler class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\WooPayments;

use Automattic\WooCommerce\Internal\Admin\Settings\PaymentsProviders\WooPayments\WooPaymentsService;
use InvalidArgumentException;

/**
 * Applies the platform's `account.updated` and `account.deleted` webhook events to the store's WooPayments account.
 *
 * @since 11.0.0
 * @internal
 */
class WooPaymentsAccountEventHandler {

	/**
	 * Account service.
	 *
	 * @var WooPaymentsAccountService
	 */
	private WooPaymentsAccountService $account_service;

	/**
	 * Token service.
	 *
	 * @var WooPaymentsTokenService
	 */
	private WooPaymentsTokenService $token_service;

	/**
	 * Initialize the handler.
	 *
	 * @internal
	 *
	 * @param WooPaymentsAccountService $account_service Account service.
	 * @param WooPaymentsTokenService   $token_service   Token service.
	 */
	final public function init( WooPaymentsAccountService $account_service, WooPaymentsTokenService $token_service ): void {
		$this->account_service = $account_service;
		$this->token_service   = $token_service;
	}

	/**
	 * Tell whether the event is handled by this handler.
	 *
	 * @param string $event_type Event type.
	 * @return bool
	 */
	public function is_supported_event( string $event_type ): bool {
		return in_array( $event_type, array( 'account.updated', 'account.deleted' ), true );
	}

	/**
	 * Process an account lifecycle event.
	 *
	 * Only the type is read: the platform posts `{"type":"account.deleted"}` and `{"type":"account.updated"}` with nothing
	 * else, and forwards Stripe's account events with their ID, type, livemode and account but no object, as client 11.1.0
	 * reads them (includes/class-wc-payments-webhook-processing-service.php:205-221). Every delivery is applied.
	 *
	 * @param string $event_type Event type.
	 * @return void
	 * @throws InvalidArgumentException When the event type is unsupported.
	 */
	public function process( string $event_type ): void {
		switch ( $event_type ) {
			case 'account.updated':
				// A failed refresh is ignored and the saved payment methods are still cleared (client 11.1.0
				// `includes/class-wc-payments-webhook-processing-service.php:205-208`).
				$this->account_service->refresh_account_data();
				$this->token_service->clear_all_cached_payment_methods();
				return;
			case 'account.deleted':
				$this->account_service->cleanup_after_account_reset();
				// The webhook also clears the NOX onboarding profile and lock (client 11.1.0
				// includes/class-wc-payments-webhook-processing-service.php:210-215).
				delete_option( WooPaymentsService::NOX_PROFILE_OPTION_KEY );
				delete_option( WooPaymentsService::NOX_ONBOARDING_LOCKED_KEY );
				$this->account_service->refresh_account_data();
				$this->token_service->clear_all_cached_payment_methods();
				return;
		}

		throw new InvalidArgumentException( 'Unsupported WooPayments account event type.' );
	}
}
