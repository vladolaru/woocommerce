<?php
/**
 * PayeeFilters class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\Order;

use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\ConnectionState;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\State\CollectingState;

/**
 * Names the store payee on new and patched PayPal orders and on the JS SDK, while the platform serves the store.
 *
 * While collecting, buyers pay the payee email; once platform connected, the merchant ID PayPal confirmed. Values the
 * filters cannot read pass through unchanged, as they do for a store the platform does not serve.
 *
 * @since 11.3.0
 * @internal POC component for the PayPal Wallet in core proof of concept.
 */
final class PayeeFilters {

	/**
	 * The path of one whole purchase unit in an order patch, by reference ID or by index; not a field inside one.
	 */
	private const PURCHASE_UNIT_PATH = "#^/purchase_units/(@reference_id=='[^']*'|\\d+)$#";

	/**
	 * The connection state reader.
	 *
	 * @var ConnectionState
	 */
	private ConnectionState $connection_state;

	/**
	 * The collecting state, for the payee.
	 *
	 * @var CollectingState
	 */
	private CollectingState $state;

	/**
	 * Constructor.
	 *
	 * @param ConnectionState $connection_state The connection state reader.
	 * @param CollectingState $state            The collecting state.
	 */
	public function __construct( ConnectionState $connection_state, CollectingState $state ) {
		$this->connection_state = $connection_state;
		$this->state            = $state;
	}

	/**
	 * Set the payee of every purchase unit of a new PayPal order.
	 *
	 * @since 11.3.0
	 *
	 * @param mixed $data The create-order request body.
	 * @return mixed The body, with the payee when it could be set.
	 */
	public function handle_ppcp_create_order_request_body_data( $data ) {
		if ( ! is_array( $data ) || ! isset( $data['purchase_units'] ) || ! is_array( $data['purchase_units'] ) ) {
			return $data;
		}
		$payee = $this->payee();
		if ( null === $payee ) {
			return $data;
		}

		foreach ( $data['purchase_units'] as $key => $unit ) {
			if ( is_array( $unit ) ) {
				$data['purchase_units'][ $key ]['payee'] = $payee;
			}
		}

		return $data;
	}

	/**
	 * Keep the payee on purchase units an order patch replaces or adds whole, since a patched unit has no payee of its own.
	 *
	 * The order processor and the early order handler patch each purchase unit with the unit built from the WooCommerce
	 * order, which carries no payee; without this, the patch would drop the payee the order was created with.
	 *
	 * @since 11.3.0
	 *
	 * @param mixed $patches The order patch request body: a list of operations.
	 * @return mixed The body, with the payee on each whole purchase unit it sets.
	 */
	public function handle_ppcp_patch_order_request_body_data( $patches ) {
		if ( ! is_array( $patches ) ) {
			return $patches;
		}
		$payee = $this->payee();
		if ( null === $payee ) {
			return $patches;
		}

		foreach ( $patches as $key => $patch ) {
			if (
				is_array( $patch )
				&& in_array( $patch['op'] ?? null, array( 'replace', 'add' ), true )
				&& is_string( $patch['path'] ?? null )
				&& 1 === preg_match( self::PURCHASE_UNIT_PATH, $patch['path'] )
				&& is_array( $patch['value'] ?? null )
			) {
				$patches[ $key ]['value']['payee'] = $payee;
			}
		}

		return $patches;
	}

	/**
	 * Load the JS SDK for the payee: add `merchant-id` to the SDK URL params the scripts read, and to the URL built from them.
	 *
	 * @since 11.3.0
	 *
	 * @param mixed $localize The smart button script data.
	 * @return mixed The script data, with the merchant-id when it could be set.
	 */
	public function handle_woocommerce_paypal_payments_localized_script_data( $localize ) {
		if ( ! is_array( $localize ) || ! isset( $localize['url_params'] ) || ! is_array( $localize['url_params'] ) ) {
			return $localize;
		}
		$payee = $this->payee();
		if ( null === $payee ) {
			return $localize;
		}

		$merchant_id                           = self::payee_id( $payee );
		$localize['url_params']['merchant-id'] = $merchant_id;
		if ( isset( $localize['url'] ) && is_string( $localize['url'] ) ) {
			$localize['url'] = add_query_arg( 'merchant-id', rawurlencode( $merchant_id ), $localize['url'] );
		}

		return $localize;
	}

	/**
	 * The ID the JS SDK names the payee by: its email while collecting, its merchant ID once platform connected.
	 *
	 * @param array<string, string> $payee The PayPal payee object from payee().
	 * @return string
	 */
	private static function payee_id( array $payee ): string {
		return $payee['email_address'] ?? $payee['merchant_id'] ?? '';
	}

	/**
	 * The payee for the store's state: the payee email while collecting, the merchant ID once platform connected.
	 *
	 * @return array<string, string>|null The PayPal payee object, or null when the platform does not serve the store.
	 */
	private function payee(): ?array {
		$state = $this->connection_state->resolve();
		if ( ConnectionState::COLLECTING === $state ) {
			$email = $this->state->payee_email();

			return '' === $email ? null : array( 'email_address' => $email );
		}
		if ( ConnectionState::PLATFORM_CONNECTED === $state ) {
			$merchant_id = $this->state->merchant_id();

			return '' === $merchant_id ? null : array( 'merchant_id' => $merchant_id );
		}

		return null;
	}
}
