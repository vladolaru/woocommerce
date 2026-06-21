<?php
/**
 * Local account-cache scenario driver for A4 admin browser coverage.
 *
 * This runs inside a local WordPress store via WP-CLI. It temporarily changes
 * only the store's cached WooPayments account data and prints enough JSON for
 * the host-side harness to restore the exact previous option value.
 *
 * @package WooCommerce\Tools\WooPaymentsMerge
 */

$mode     = (string) ( $args[0] ?? '' );
$snapshot = (string) ( $args[1] ?? '' );
$option   = 'wcpay_account_data';

/**
 * Summarize only the account capability flags this scenario owns.
 *
 * @param mixed $cache Account cache wrapper.
 * @return array<string,mixed>
 */
function a4_account_scenario_flags( $cache ): array {
	$data    = is_array( $cache ) && isset( $cache['data'] ) && is_array( $cache['data'] ) ? $cache['data'] : array();
	$capital = is_array( $data['capital'] ?? null ) ? $data['capital'] : array();

	return array(
		'account_id'                 => isset( $data['account_id'] ) && is_scalar( $data['account_id'] ) ? (string) $data['account_id'] : '',
		'status'                     => isset( $data['status'] ) && is_scalar( $data['status'] ) ? (string) $data['status'] : '',
		'card_present_eligible'      => ! empty( $data['card_present_eligible'] ),
		'has_card_readers_available' => ! empty( $data['has_card_readers_available'] ),
		'has_previous_capital_loans' => ! empty( $capital['has_previous_loans'] ),
		'is_documents_enabled'       => ! empty( $data['is_documents_enabled'] ),
		'fetched'                    => is_array( $cache ) ? ( $cache['fetched'] ?? null ) : null,
		'errored'                    => is_array( $cache ) ? ( $cache['errored'] ?? null ) : null,
	);
}

/**
 * Read the raw account cache option with an existence marker.
 *
 * @param string $option Option name.
 * @return array{exists:bool,value:mixed,autoload?:?string}
 */
function a4_account_scenario_read_option( string $option ): array {
	global $wpdb;

	$missing = new stdClass();
	$value   = get_option( $option, $missing );
	$exists  = $value !== $missing;
	$state   = array(
		'exists' => $exists,
		'value'  => $value,
	);

	if ( $exists && isset( $wpdb ) && method_exists( $wpdb, 'get_var' ) && method_exists( $wpdb, 'prepare' ) ) {
		$autoload = $wpdb->get_var( $wpdb->prepare( "SELECT autoload FROM {$wpdb->options} WHERE option_name = %s", $option ) );
		if ( null !== $autoload ) {
			$state['autoload'] = (string) $autoload;
		}
	}

	return $state;
}

/**
 * Restore an option's original autoload column exactly when it was captured.
 *
 * update_option() can change autoload when the third parameter is provided and
 * may normalize historical values. The harness needs to avoid contaminating
 * later autoload/perf probes, so restoration writes the original column value.
 *
 * @param string $option   Option name.
 * @param mixed  $autoload Captured autoload value.
 * @return void
 */
function a4_account_scenario_restore_autoload( string $option, $autoload ): void {
	global $wpdb;

	if ( null === $autoload || ! is_scalar( $autoload ) || ! isset( $wpdb ) || ! method_exists( $wpdb, 'update' ) ) {
		return;
	}

	$wpdb->update(
		$wpdb->options,
		array( 'autoload' => (string) $autoload ),
		array( 'option_name' => $option )
	);
	wp_cache_delete( 'alloptions', 'options' );
}

/**
 * Persist or delete the account cache option from a snapshot wrapper.
 *
 * @param string                  $option Option name.
 * @param array{exists:bool,value:mixed,autoload?:?string} $state  Snapshot state.
 * @return void
 */
function a4_account_scenario_write_option( string $option, array $state ): void {
	if ( empty( $state['exists'] ) ) {
		delete_option( $option );
	} else {
		update_option( $option, $state['value'] );
		if ( array_key_exists( 'autoload', $state ) ) {
			a4_account_scenario_restore_autoload( $option, $state['autoload'] );
		}
	}

	wp_cache_delete( $option, 'options' );
}

/**
 * Encode a snapshot wrapper for transport as a CLI arg.
 *
 * @param array{exists:bool,value:mixed,autoload?:?string} $state Snapshot state.
 * @return string
 */
function a4_account_scenario_encode_snapshot( array $state ): string {
	return base64_encode( wp_json_encode( $state ) );
}

/**
 * Decode a snapshot wrapper from a CLI arg.
 *
 * @param string $snapshot Encoded snapshot.
 * @return array{exists:bool,value:mixed,autoload?:?string}
 */
function a4_account_scenario_decode_snapshot( string $snapshot ): array {
	$json  = base64_decode( $snapshot, true );
	$state = false === $json ? null : json_decode( $json, true );

	if ( ! is_array( $state ) || ! array_key_exists( 'exists', $state ) || ! array_key_exists( 'value', $state ) ) {
		WP_CLI::error( 'Invalid account-cache snapshot.' );
	}

	return $state;
}

/**
 * Assert a restored option state matches the transported snapshot exactly.
 *
 * @param array{exists:bool,value:mixed,autoload?:?string} $expected Expected snapshot state.
 * @param array{exists:bool,value:mixed,autoload?:?string} $actual   Actual option state after restore.
 * @return void
 */
function a4_account_scenario_assert_restored_snapshot( array $expected, array $actual ): void {
	if ( $expected !== $actual ) {
		WP_CLI::error( 'Account-cache restore verification failed: restored option does not match the original snapshot.' );
	}
}

if ( ! in_array( $mode, array( 'snapshot', 'apply-optional-admin', 'restore' ), true ) ) {
	WP_CLI::error( 'Usage: wp eval-file a4-account-scenario.php <snapshot|apply-optional-admin|restore> [snapshot]' );
}

$before_state = a4_account_scenario_read_option( $option );
$before_cache = $before_state['value'];

if ( 'snapshot' === $mode ) {
	WP_CLI::line(
		wp_json_encode(
			array(
				'action'   => 'snapshot',
				'snapshot' => a4_account_scenario_encode_snapshot( $before_state ),
				'flags'    => a4_account_scenario_flags( $before_cache ),
			)
		)
	);
	return;
}

if ( 'restore' === $mode ) {
	$restore_state = a4_account_scenario_decode_snapshot( $snapshot );
	a4_account_scenario_write_option( $option, $restore_state );
	$after_state = a4_account_scenario_read_option( $option );
	a4_account_scenario_assert_restored_snapshot( $restore_state, $after_state );

	WP_CLI::line(
		wp_json_encode(
			array(
				'action'                  => 'restore',
				'restored_snapshot_exact' => true,
				'before'                  => a4_account_scenario_flags( $before_cache ),
				'after'                   => a4_account_scenario_flags( $after_state['value'] ),
			)
		)
	);
	return;
}

if ( ! is_array( $before_cache ) || ! isset( $before_cache['data'] ) || ! is_array( $before_cache['data'] ) ) {
	WP_CLI::error( 'Cannot apply optional-admin scenario without a cached WooPayments account payload.' );
}

$next_cache = $before_cache;
$next_cache['data']['card_present_eligible']      = true;
$next_cache['data']['has_card_readers_available'] = true;
$next_cache['data']['is_documents_enabled']       = true;

if ( ! isset( $next_cache['data']['capital'] ) || ! is_array( $next_cache['data']['capital'] ) ) {
	$next_cache['data']['capital'] = array();
}

$next_cache['data']['capital']['has_previous_loans'] = true;
$next_cache['fetched']                               = time();
$next_cache['errored']                               = false;
$next_cache['consecutive_errors']                    = 0;

a4_account_scenario_write_option(
	$option,
	array(
		'exists' => true,
		'value'  => $next_cache,
	)
);

$after_state = a4_account_scenario_read_option( $option );

WP_CLI::line(
	wp_json_encode(
		array(
			'action'   => 'apply-optional-admin',
			'snapshot' => a4_account_scenario_encode_snapshot( $before_state ),
			'before'   => a4_account_scenario_flags( $before_cache ),
			'after'    => a4_account_scenario_flags( $after_state['value'] ),
		)
	)
);
