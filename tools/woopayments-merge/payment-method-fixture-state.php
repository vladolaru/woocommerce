<?php
/**
 * Local payment-method fixture state driver for WooPayments merge gates.
 *
 * This file is normally executed through `wp eval-file -` by local-only
 * verification harnesses. It stages one split WooPayments method by updating
 * the same store settings, split gateway settings, account capability cache,
 * currency, and country that the browser checkout gates need.
 *
 * @package WooCommerce\Tools\WooPaymentsMerge
 */

$tool_args    = isset( $args ) && is_array( $args ) ? $args : array_slice( $argv ?? array(), 1 );
$fixture_mode = $tool_args[0] ?? '';

if ( 'stage-lpm-fixture' === $fixture_mode ) {
	$payload_b64 = isset( $tool_args[1] ) ? (string) $tool_args[1] : '';
	woopayments_merge_payment_method_fixture_emit( woopayments_merge_payment_method_fixture_stage( $payload_b64 ) );
	exit( 0 );
}

if ( 'snapshot-lpm-fixture' === $fixture_mode ) {
	$payload_b64 = isset( $tool_args[1] ) ? (string) $tool_args[1] : '';
	woopayments_merge_payment_method_fixture_emit( woopayments_merge_payment_method_fixture_stage( $payload_b64, false ) );
	exit( 0 );
}

if ( 'restore-lpm-fixture' === $fixture_mode ) {
	$payload_b64 = isset( $tool_args[1] ) ? (string) $tool_args[1] : '';
	woopayments_merge_payment_method_fixture_emit( woopayments_merge_payment_method_fixture_restore( $payload_b64 ) );
	exit( 0 );
}

woopayments_merge_payment_method_fixture_emit(
	array(
		'success' => false,
		'mode'    => $fixture_mode,
		'errors'  => array( 'Unknown mode. Use snapshot-lpm-fixture, stage-lpm-fixture, or restore-lpm-fixture.' ),
	)
);
exit( 2 );

/**
 * Emit a single JSON line.
 *
 * @param array<string,mixed> $payload Payload.
 */
function woopayments_merge_payment_method_fixture_emit( array $payload ): void {
	echo wp_json_encode( $payload, JSON_UNESCAPED_SLASHES ) . "\n";
}

/**
 * The split gateway method ids isolated by the local staged fixture.
 *
 * @return string[]
 */
function woopayments_merge_payment_method_fixture_split_method_ids(): array {
	return array(
		'sepa_debit',
		'ideal',
		'bancontact',
		'klarna',
		'affirm',
		'afterpay_clearpay',
		'eps',
		'p24',
		'multibanco',
		'au_becs_debit',
		'grabpay',
		'wechat_pay',
		'alipay',
		'amazon_pay',
	);
}

/**
 * Get the connected account capability key for a payment method.
 *
 * @param string $method Payment method id.
 * @return string
 */
function woopayments_merge_payment_method_fixture_capability_key( string $method ): string {
	$capability_keys = array(
		'card' => 'card_payments',
	);

	return $capability_keys[ $method ] ?? $method . '_payments';
}

/**
 * Snapshot option rows exactly as stored, including autoload metadata.
 *
 * @param string[] $option_names Option names.
 * @return array{options:array<string,array<string,mixed>>,errors:string[]}
 */
function woopayments_merge_payment_method_fixture_snapshot_options( array $option_names ): array {
	global $wpdb;

	$options = array();
	$errors  = array();
	foreach ( array_values( array_unique( $option_names ) ) as $option_name ) {
		$wpdb->last_error = '';
		$row              = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT option_value, autoload FROM {$wpdb->options} WHERE option_name = %s LIMIT 1",
				$option_name
			),
			ARRAY_A
		);
		if ( '' !== (string) $wpdb->last_error ) {
			$errors[] = $option_name . ':snapshot_read_failed';
			continue;
		}
		if ( null === $row ) {
			$options[ $option_name ] = array( 'exists' => false );
			continue;
		}
		$options[ $option_name ] = array(
			'exists'           => true,
			// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Preserves the raw serialized option value in JSON evidence.
			'option_value_b64' => base64_encode( (string) $row['option_value'] ),
			'autoload'         => (string) $row['autoload'],
		);
	}

	return array(
		'options' => $options,
		'errors'  => $errors,
	);
}

/**
 * Whether an option still has a value in any WordPress option-cache bucket.
 *
 * @param string $option_name Option name.
 * @return bool
 */
function woopayments_merge_payment_method_fixture_option_cache_contains( string $option_name ): bool {
	$found = false;
	wp_cache_get( $option_name, 'options', false, $found );
	if ( $found ) {
		return true;
	}

	foreach ( array( 'alloptions', 'notoptions' ) as $cache_key ) {
		$bucket_found = false;
		$bucket       = wp_cache_get( $cache_key, 'options', false, $bucket_found );
		if ( $bucket_found && is_array( $bucket ) && array_key_exists( $option_name, $bucket ) ) {
			return true;
		}
	}

	return false;
}

/**
 * Restore an exact option-row snapshot and verify database state and cache invalidation.
 *
 * @param array<string,array<string,mixed>> $snapshot Option-row snapshot.
 * @return array{restored_count:int,verified_count:int,mismatches:string[]}
 */
function woopayments_merge_payment_method_fixture_restore_options( array $snapshot ): array {
	global $wpdb;

	$mismatches     = array();
	$restored_count = 0;
	$verified_count = 0;
	foreach ( $snapshot as $option_name => $record ) {
		if ( ! is_string( $option_name ) || ! is_array( $record ) || ! isset( $record['exists'] ) ) {
			$mismatches[] = (string) $option_name . ':invalid_snapshot_record';
			continue;
		}
		if ( true === $record['exists'] ) {
			// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Decodes the gate-owned raw option snapshot.
			$option_value = isset( $record['option_value_b64'] ) ? base64_decode( (string) $record['option_value_b64'], true ) : false;
			$autoload     = isset( $record['autoload'] ) ? (string) $record['autoload'] : null;
			if ( false === $option_value || null === $autoload ) {
				$mismatches[] = $option_name . ':invalid_snapshot_value';
				continue;
			}
			$write_result = $wpdb->query(
				$wpdb->prepare(
					"INSERT INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, %s)
					ON DUPLICATE KEY UPDATE option_value = VALUES(option_value), autoload = VALUES(autoload)",
					$option_name,
					$option_value,
					$autoload
				)
			);
			if ( false === $write_result ) {
				$mismatches[] = $option_name . ':restore_write_failed';
				continue;
			}
		} else {
			$delete_result = $wpdb->delete( $wpdb->options, array( 'option_name' => $option_name ), array( '%s' ) );
			if ( false === $delete_result ) {
				$mismatches[] = $option_name . ':restore_delete_failed';
				continue;
			}
		}
		wp_cache_delete( $option_name, 'options' );
		++$restored_count;
	}
	wp_cache_delete( 'alloptions', 'options' );
	wp_cache_delete( 'notoptions', 'options' );

	foreach ( $snapshot as $option_name => $record ) {
		if ( ! is_string( $option_name ) || ! is_array( $record ) || ! isset( $record['exists'] ) ) {
			continue;
		}
		$wpdb->last_error = '';
		$row              = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT option_value, autoload FROM {$wpdb->options} WHERE option_name = %s LIMIT 1",
				$option_name
			),
			ARRAY_A
		);
		if ( '' !== (string) $wpdb->last_error ) {
			$mismatches[] = $option_name . ':verification_read_failed';
			continue;
		}
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Decodes the gate-owned raw option snapshot.
		$expected_value = isset( $record['option_value_b64'] ) ? base64_decode( (string) $record['option_value_b64'], true ) : false;
		if ( false === $record['exists'] ) {
			if ( null !== $row ) {
				$mismatches[] = $option_name . ':expected_absent';
				continue;
			}
		} elseif (
			null === $row
			|| false === $expected_value
			|| (string) $row['option_value'] !== $expected_value
			|| (string) ( $record['autoload'] ?? '' ) !== (string) $row['autoload']
		) {
			$mismatches[] = $option_name . ':value_mismatch';
			continue;
		}

		wp_cache_delete( $option_name, 'options' );
		wp_cache_delete( 'alloptions', 'options' );
		wp_cache_delete( 'notoptions', 'options' );
		if ( woopayments_merge_payment_method_fixture_option_cache_contains( $option_name ) ) {
			$mismatches[] = $option_name . ':cache_value_mismatch';
			continue;
		}
		++$verified_count;
	}

	return array(
		'restored_count' => $restored_count,
		'verified_count' => $verified_count,
		'mismatches'     => array_values( array_unique( $mismatches ) ),
	);
}

/**
 * Verify staged option values through the runtime option cache.
 *
 * @param array<string,mixed> $expected_options Expected effective option values.
 * @return array{verified_count:int,mismatches:string[]}
 */
function woopayments_merge_payment_method_fixture_verify_staged_options( array $expected_options ): array {
	$verified_count = 0;
	$mismatches     = array();

	foreach ( $expected_options as $option_name => $expected_value ) {
		wp_cache_delete( $option_name, 'options' );
	}
	wp_cache_delete( 'alloptions', 'options' );
	wp_cache_delete( 'notoptions', 'options' );

	foreach ( $expected_options as $option_name => $expected_value ) {
		$missing_marker = new stdClass();
		$actual_value   = get_option( $option_name, $missing_marker );
		if ( $missing_marker === $actual_value || $expected_value !== $actual_value ) {
			$mismatches[] = $option_name . ':stage_value_mismatch';
			continue;
		}
		++$verified_count;
	}

	return array(
		'verified_count' => $verified_count,
		'mismatches'     => $mismatches,
	);
}

/**
 * Whether the local fixture may stage a WPCOM-projected disabled capability.
 *
 * Local WPCOM can return a requested SEPA capability as `disabled` when the
 * payment method is globally unavailable there, even after Stripe accepts the
 * capability request. Only accept that projection when the account cache also
 * proves the capability has no remaining requirements and WPCOM supplied method
 * fee data; missing, unrequested, pending, or malformed capabilities still fail.
 *
 * @param string              $method Payment method id.
 * @param string              $capability_key Connected account capability key.
 * @param array<string,mixed> $account_data Cached WooPayments account data.
 * @return bool
 */
function woopayments_merge_payment_method_fixture_can_override_disabled_capability( string $method, string $capability_key, array $account_data ): bool {
	if ( 'sepa_debit' !== $method ) {
		return false;
	}

	$requirements = isset( $account_data['capability_requirements'][ $capability_key ] ) && is_array( $account_data['capability_requirements'][ $capability_key ] )
		? $account_data['capability_requirements'][ $capability_key ]
		: null;
	$fees         = isset( $account_data['fees'][ $method ] ) && is_array( $account_data['fees'][ $method ] )
		? $account_data['fees'][ $method ]
		: null;

	return is_array( $requirements ) && empty( $requirements ) && is_array( $fees ) && ! empty( $fees );
}

/**
 * Stage a local payment-method checkout fixture.
 *
 * @param string $payload_b64  Base64-encoded JSON array: [method, currency, country].
 * @param bool   $apply_changes Whether to apply the staged values after capturing prior state.
 * @return array<string,mixed>
 */
function woopayments_merge_payment_method_fixture_stage( string $payload_b64, bool $apply_changes = true ): array {
	// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Decodes the local harness JSON transport.
	$decoded_payload      = json_decode( base64_decode( $payload_b64 ), true );
	$errors               = array();
	$settings_option      = 'woocommerce_woocommerce_payments_settings';
	$account_option       = 'wcpay_account_data';
	$missing_marker       = '__woopayments_merge_missing_option__';
	$split_method_ids     = woopayments_merge_payment_method_fixture_split_method_ids();
	$stage_verified       = false;
	$stage_option_count   = 0;
	$stage_verified_count = 0;
	$stage_mismatches     = array();

	if ( ! is_array( $decoded_payload ) || 3 !== count( $decoded_payload ) ) {
		$errors[] = 'LPM fixture payload was invalid.';
		$method   = '';
		$currency = '';
		$country  = '';
	} else {
		$method   = sanitize_key( (string) $decoded_payload[0] );
		$currency = strtoupper( sanitize_text_field( (string) $decoded_payload[1] ) );
		$country  = strtoupper( sanitize_text_field( (string) $decoded_payload[2] ) );
	}

	$split_settings_option = 'woocommerce_woocommerce_payments_' . $method . '_settings';
	$capability_key        = woopayments_merge_payment_method_fixture_capability_key( $method );
	$option_names          = array(
		$settings_option,
		$account_option,
		'woocommerce_currency',
		'woocommerce_default_country',
	);
	foreach ( $split_method_ids as $split_method_id ) {
		$option_names[] = 'woocommerce_woocommerce_payments_' . $split_method_id . '_settings';
	}
	$option_snapshot_result      = woopayments_merge_payment_method_fixture_snapshot_options( $option_names );
	$previous_option_snapshot    = $option_snapshot_result['options'];
	$errors                      = array_merge( $errors, $option_snapshot_result['errors'] );
	$previous_settings           = get_option( $settings_option, $missing_marker );
	$previous_split_settings     = get_option( $split_settings_option, $missing_marker );
	$previous_split_settings_map = array();
	foreach ( $split_method_ids as $split_method_id ) {
		$option_name                                 = 'woocommerce_woocommerce_payments_' . $split_method_id . '_settings';
		$option_value                                = get_option( $option_name, $missing_marker );
		$previous_split_settings_map[ $option_name ] = array(
			'exists'   => $missing_marker !== $option_value,
			'settings' => $missing_marker !== $option_value ? $option_value : null,
		);
	}
	$previous_account_cache      = get_option( $account_option, $missing_marker );
	$previous_currency           = get_option( 'woocommerce_currency', $missing_marker );
	$previous_country            = get_option( 'woocommerce_default_country', $missing_marker );
	$previous_account_data       = is_array( $previous_account_cache ) && isset( $previous_account_cache['data'] ) && is_array( $previous_account_cache['data'] )
		? $previous_account_cache['data']
		: array();
	$previous_capability_status  = null;
	$capability_status_label     = 'missing';
	$capability_fixture_override = false;

	if ( ! empty( $previous_account_data['capabilities'] ) && is_array( $previous_account_data['capabilities'] ) && isset( $previous_account_data['capabilities'][ $capability_key ] ) ) {
		$raw_capability_status = $previous_account_data['capabilities'][ $capability_key ];
		if ( is_array( $raw_capability_status ) ) {
			$previous_capability_status = isset( $raw_capability_status['status'] ) && is_scalar( $raw_capability_status['status'] )
				? (string) $raw_capability_status['status']
				: null;
		} elseif ( is_scalar( $raw_capability_status ) ) {
			$previous_capability_status = (string) $raw_capability_status;
		}
		$capability_status_label = null !== $previous_capability_status ? $previous_capability_status : 'malformed';
	}
	$capability_fixture_override = 'disabled' === $previous_capability_status && woopayments_merge_payment_method_fixture_can_override_disabled_capability( $method, $capability_key, $previous_account_data );

	if ( '' === $method || '' === $currency || '' === $country ) {
		$errors[] = 'LPM fixture method, currency, and country are required.';
	}

	if ( empty( $previous_account_data ) || empty( $previous_account_data['account_id'] ) || ! is_scalar( $previous_account_data['account_id'] ) ) {
		$errors[] = 'LPM fixture requires a connected WooPayments account cache with account_id. Refresh/reconnect local WPCOM/Jetpack before running the browser checkout gate.';
	}

	if ( empty( $previous_account_data['test_publishable_key'] ) || ! is_scalar( $previous_account_data['test_publishable_key'] ) ) {
		$errors[] = 'LPM fixture requires a connected WooPayments account cache with test_publishable_key. Refresh/reconnect local WPCOM/Jetpack before running the browser checkout gate.';
	}

	if ( 'active' !== $previous_capability_status && ! $capability_fixture_override ) {
		$errors[] = sprintf(
			'LPM fixture requires the connected WooPayments account capability %1$s to be usable for browser checkout; current cached status is %2$s. Request/refresh the capability or create a suitable local Test Lab account before running this method. A WPCOM-projected disabled status is only accepted when account data includes method fees and no capability requirements.',
			$capability_key,
			$capability_status_label
		);
	}

	if ( empty( $errors ) && $apply_changes ) {
		$enabled_methods                            = array_values( array_unique( array( 'card', $method ) ) );
		$settings                                   = is_array( $previous_settings ) ? $previous_settings : array();
		$settings['enabled']                        = 'yes';
		$settings['test_mode']                      = 'yes';
		$settings['manual_capture']                 = 'no';
		$settings['upe_enabled_payment_method_ids'] = array_values( array_unique( array( 'card', $method ) ) );
		$staged_split_settings                      = array();
		foreach ( $split_method_ids as $split_method_id ) {
			$option_name         = 'woocommerce_woocommerce_payments_' . $split_method_id . '_settings';
			$previous_for_method = $previous_split_settings_map[ $option_name ]['settings'] ?? null;
			$split_settings      = is_array( $previous_for_method ) ? $previous_for_method : array();
			if ( $split_method_id === $method ) {
				$split_settings['enabled']                        = 'yes';
				$split_settings['saved_cards']                    = 'yes';
				$split_settings['test_mode']                      = 'yes';
				$split_settings['manual_capture']                 = 'no';
				$split_settings['upe_enabled_payment_method_ids'] = $enabled_methods;
			} else {
				$split_settings['enabled'] = 'no';
			}
			$staged_split_settings[ $option_name ] = $split_settings;
		}
		$account_cache                                   = $previous_account_cache;
		$account_data                                    = $previous_account_data;
		$account_data['account_id']                      = (string) $account_data['account_id'];
		$account_data['payments_enabled']                = true;
		$account_data['details_submitted']               = true;
		$account_data['status']                          = 'complete';
		$account_data['is_live']                         = false;
		$account_data['is_test_drive']                   = true;
		$account_data['country']                         = $country;
		$account_data['capabilities']                    = isset( $account_data['capabilities'] ) && is_array( $account_data['capabilities'] )
			? $account_data['capabilities']
			: array();
		$account_data['capabilities']['card_payments']   = 'active';
		$account_data['capabilities'][ $capability_key ] = 'active';
		$account_data['capability_requirements']         = isset( $account_data['capability_requirements'] ) && is_array( $account_data['capability_requirements'] )
			? $account_data['capability_requirements']
			: array();
		$account_data['capability_requirements']['card_payments']   = array();
		$account_data['capability_requirements'][ $capability_key ] = array();
		$account_data['store_currencies']                           = isset( $account_data['store_currencies'] ) && is_array( $account_data['store_currencies'] )
			? $account_data['store_currencies']
			: array();
		$account_data['store_currencies']['default']                = strtolower( $currency );
		$account_data['store_currencies']['supported']              = array_values(
			array_unique(
				array_merge(
					isset( $account_data['store_currencies']['supported'] ) && is_array( $account_data['store_currencies']['supported'] )
						? $account_data['store_currencies']['supported']
						: array(),
					array( strtolower( $currency ) )
				)
			)
		);

		$account_cache['data']               = $account_data;
		$account_cache['fetched']            = time();
		$account_cache['errored']            = false;
		$account_cache['consecutive_errors'] = 0;

		update_option( $settings_option, $settings );
		foreach ( $staged_split_settings as $option_name => $split_settings ) {
			update_option( $option_name, $split_settings );
		}
		update_option( $account_option, $account_cache, 'no' );
		wp_cache_delete( $account_option, 'options' );
		update_option( 'woocommerce_currency', $currency );
		update_option( 'woocommerce_default_country', $country );

		$expected_options     = array_merge(
			array(
				$settings_option              => $settings,
				$account_option               => $account_cache,
				'woocommerce_currency'        => $currency,
				'woocommerce_default_country' => $country,
			),
			$staged_split_settings
		);
		$stage_verification   = woopayments_merge_payment_method_fixture_verify_staged_options( $expected_options );
		$stage_option_count   = count( $expected_options );
		$stage_verified_count = $stage_verification['verified_count'];
		$stage_mismatches     = $stage_verification['mismatches'];
		$stage_verified       = $stage_option_count === $stage_verified_count && empty( $stage_mismatches );
		$errors               = array_merge( $errors, $stage_mismatches );
	}

	return array(
		'success'              => $apply_changes
			? empty( $errors ) && $stage_verified
			: empty( $option_snapshot_result['errors'] ) && '' !== $method && '' !== $currency && '' !== $country,
		'stage_ready'          => empty( $errors ),
		'stage_verified'       => $apply_changes ? $stage_verified : null,
		'stage_option_count'   => $stage_option_count,
		'stage_verified_count' => $stage_verified_count,
		'stage_mismatches'     => $stage_mismatches,
		'mode'                 => $apply_changes ? 'stage-lpm-fixture' : 'snapshot-lpm-fixture',
		'errors'               => $errors,
		'method'               => $method,
		'currency'             => $currency,
		'country'              => $country,
		'previous'             => array(
			'option_snapshot'             => $previous_option_snapshot,
			'option_snapshot_count'       => count( $previous_option_snapshot ),
			'settings_exists'             => $missing_marker !== $previous_settings,
			'settings'                    => $missing_marker !== $previous_settings ? $previous_settings : null,
			'split_settings_option'       => $split_settings_option,
			'split_settings_exists'       => $missing_marker !== $previous_split_settings,
			'split_settings'              => $missing_marker !== $previous_split_settings ? $previous_split_settings : null,
			'split_settings_map'          => $previous_split_settings_map,
			'isolated_split_method_ids'   => $split_method_ids,
			'account_cache_exists'        => $missing_marker !== $previous_account_cache,
			'account_cache'               => $missing_marker !== $previous_account_cache ? $previous_account_cache : null,
			'capability_key'              => $capability_key,
			'capability_status'           => $previous_capability_status,
			'capability_fixture_override' => $capability_fixture_override,
			'currency_exists'             => $missing_marker !== $previous_currency,
			'currency'                    => $missing_marker !== $previous_currency ? $previous_currency : null,
			'country_exists'              => $missing_marker !== $previous_country,
			'country'                     => $missing_marker !== $previous_country ? $previous_country : null,
		),
	);
}

/**
 * Restore a previously staged local payment-method checkout fixture.
 *
 * @param string $payload_b64 Base64-encoded stage response JSON.
 * @return array<string,mixed>
 */
function woopayments_merge_payment_method_fixture_restore( string $payload_b64 ): array {
	// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Decodes the local harness JSON transport.
	$payload         = json_decode( base64_decode( $payload_b64 ), true );
	$errors          = array();
	$settings_option = 'woocommerce_woocommerce_payments_settings';
	$account_option  = 'wcpay_account_data';
	$restored_count  = 0;
	$verified_count  = 0;
	$option_count    = 0;
	$mismatches      = array();

	if ( ! is_array( $payload ) || ! is_array( $payload['previous'] ?? null ) ) {
		$errors[] = 'Restore payload was invalid.';
	} elseif ( isset( $payload['previous']['option_snapshot'] ) && is_array( $payload['previous']['option_snapshot'] ) ) {
		$option_snapshot = $payload['previous']['option_snapshot'];
		$restore_result  = woopayments_merge_payment_method_fixture_restore_options( $option_snapshot );
		$option_count    = count( $option_snapshot );
		$restored_count  = $restore_result['restored_count'];
		$verified_count  = $restore_result['verified_count'];
		$mismatches      = $restore_result['mismatches'];
		$errors          = array_merge( $errors, $mismatches );
	} else {
		$previous = $payload['previous'];

		if ( ! empty( $previous['settings_exists'] ) ) {
			update_option( $settings_option, is_array( $previous['settings'] ) ? $previous['settings'] : array() );
		} else {
			delete_option( $settings_option );
		}

		if ( ! empty( $previous['split_settings_map'] ) && is_array( $previous['split_settings_map'] ) ) {
			foreach ( $previous['split_settings_map'] as $split_settings_option => $entry ) {
				$split_settings_option = (string) $split_settings_option;
				if ( '' === $split_settings_option ) {
					continue;
				}
				if ( ! empty( $entry['exists'] ) ) {
					update_option( $split_settings_option, is_array( $entry['settings'] ?? null ) ? $entry['settings'] : array() );
				} else {
					delete_option( $split_settings_option );
				}
			}
		} else {
			$split_settings_option = isset( $previous['split_settings_option'] ) ? (string) $previous['split_settings_option'] : '';
			if ( '' !== $split_settings_option ) {
				if ( ! empty( $previous['split_settings_exists'] ) ) {
					update_option( $split_settings_option, is_array( $previous['split_settings'] ) ? $previous['split_settings'] : array() );
				} else {
					delete_option( $split_settings_option );
				}
			}
		}

		if ( ! empty( $previous['account_cache_exists'] ) ) {
			update_option( $account_option, is_array( $previous['account_cache'] ) ? $previous['account_cache'] : array(), 'no' );
			wp_cache_delete( $account_option, 'options' );
		} else {
			delete_option( $account_option );
		}

		if ( ! empty( $previous['currency_exists'] ) ) {
			update_option( 'woocommerce_currency', (string) $previous['currency'] );
		} else {
			delete_option( 'woocommerce_currency' );
		}

		if ( ! empty( $previous['country_exists'] ) ) {
			update_option( 'woocommerce_default_country', (string) $previous['country'] );
		} else {
			delete_option( 'woocommerce_default_country' );
		}
	}

	return array(
		'success'        => empty( $errors ),
		'mode'           => 'restore-lpm-fixture',
		'errors'         => $errors,
		'option_count'   => $option_count,
		'restored_count' => $restored_count,
		'verified_count' => $verified_count,
		'mismatches'     => $mismatches,
	);
}
