<?php
/**
 * WooPaymentsCLICommand class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\WooPayments;

use WP_CLI;

defined( 'ABSPATH' ) || exit;

/**
 * Prints the WooPayments status report as `wp wc woopayments status`.
 *
 * @since 11.0.0
 * @internal
 */
class WooPaymentsCLICommand {

	/**
	 * WooPayments status report.
	 *
	 * @var WooPaymentsStatusReport
	 */
	private WooPaymentsStatusReport $status_report;

	/**
	 * Initialize the class instance.
	 *
	 * @internal
	 *
	 * @param WooPaymentsStatusReport $status_report Status report service.
	 */
	final public function init( WooPaymentsStatusReport $status_report ): void {
		$this->status_report = $status_report;
	}

	/**
	 * Print the WooPayments status.
	 *
	 * ## EXAMPLES
	 *
	 *     wp wc woopayments status
	 *
	 * @param array<int,string>        $args Positional args.
	 * @param array<string,string|int> $assoc_args Associative args.
	 */
	public function status( array $args = array(), array $assoc_args = array() ): void {
		unset( $args, $assoc_args );

		foreach ( $this->get_status_lines() as $line ) {
			// @phpstan-ignore-next-line class.notFound
			WP_CLI::line( $line );
		}
	}

	/**
	 * Get the formatted status lines.
	 *
	 * It reads keys WooPaymentsStatusReport::get_status_data() always returns; WC_CLI registers the command as
	 * `wc woopayments`.
	 *
	 * @return string[]
	 */
	public function get_status_lines(): array {
		$data               = $this->status_report->get_status_data();
		$account_id         = '' !== $data['account_id'] ? (string) $data['account_id'] : '-';
		$preflight_failures = is_array( $data['preflight_failures'] ) ? array_values( array_map( 'strval', $data['preflight_failures'] ) ) : array();
		$woopay_locations   = is_array( $data['woopay']['enabled_locations'] ) ? array_values( array_map( 'strval', $data['woopay']['enabled_locations'] ) ) : array();
		$payment_methods    = is_array( $data['enabled_payment_methods'] ) ? array_values( array_map( 'strval', $data['enabled_payment_methods'] ) ) : array();
		$multi_currency     = is_array( $data['multi_currency'] ) ? $data['multi_currency'] : array();
		$rate_provider      = isset( $multi_currency['rate_provider'] ) ? (string) $multi_currency['rate_provider'] : '-';
		$rate_available     = ! empty( $multi_currency['rate_provider_available'] ) ? 'available' : 'unavailable';

		return array(
			'Owner: ' . (string) $data['runtime_owner'],
			sprintf(
				'Native enabled: %s',
				(bool) $data['builtin_enabled'] ? 'yes' : 'no'
			),
			sprintf(
				'Filter: %s (source: %s)',
				(string) $data['builtin_enabled_filter'],
				(string) $data['builtin_enabled_source']
			),
			'Preflight failures: ' . ( empty( $preflight_failures ) ? 'none' : implode( ', ', $preflight_failures ) ),
			sprintf(
				'Account: %s (%s)',
				$account_id,
				(bool) $data['account_connected'] ? 'connected' : 'not connected'
			),
			'Gateway enabled: ' . ( (bool) $data['gateway_enabled'] ? 'yes' : 'no' ),
			'Test mode: ' . ( (bool) $data['test_mode'] ? 'yes' : 'no' ),
			'Enabled payment methods: ' . ( empty( $payment_methods ) ? '-' : implode( ', ', $payment_methods ) ),
			'WooPay: ' . ( (bool) $data['woopay']['enabled'] ? 'enabled' : 'disabled' ) . ' (' . ( empty( $woopay_locations ) ? 'no locations enabled' : implode( ', ', $woopay_locations ) ) . ')',
			'Multi-currency: ' . ( ! empty( $multi_currency['enabled'] ) ? 'enabled' : 'disabled' ) . ' (rate provider: ' . $rate_provider . ', ' . $rate_available . ')',
			'Last webhook fetch: ' . ( (int) $data['last_webhook_fetch'] > 0 ? (string) $data['last_webhook_fetch'] : 'never' ),
			'Note: ' . (string) $data['builtin_enabled_note'],
		);
	}
}
