<?php
/**
 * WooPaymentsGatewaySettingsSynchronizer class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\WooPayments;

use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\PaymentMethods\WooPaymentsPaymentMethodRegistry;

/**
 * Persists canonical WooPayments settings and projects them to split gateways.
 *
 * @since 11.0.0
 * @internal Transitional internal component for the native payments runtime.
 */
final class WooPaymentsGatewaySettingsSynchronizer {

	private const SETTINGS_OPTION = 'woocommerce_woocommerce_payments_settings';

	private const DEPRECATED_PAYMENT_METHOD_IDS = array( 'giropay', 'sofort' );

	private const PAYMENT_REQUEST_METHOD_IDS = array( 'apple_pay', 'google_pay' );

	private const PAYMENT_REQUEST_PENDING_OPTION = 'woocommerce_woocommerce_payments_payment_request_projection_pending';

	/**
	 * Payment method registry.
	 *
	 * @var WooPaymentsPaymentMethodRegistry
	 */
	private WooPaymentsPaymentMethodRegistry $registry;

	/**
	 * Constructor.
	 *
	 * @param WooPaymentsPaymentMethodRegistry|null $registry Optional payment method registry.
	 */
	public function __construct( ?WooPaymentsPaymentMethodRegistry $registry = null ) {
		$this->registry = $registry ?? wc_get_container()->get( WooPaymentsPaymentMethodRegistry::class );
	}

	/**
	 * Persist canonical settings and project their enabled state to split gateways.
	 *
	 * @param array<string,mixed> $settings                Canonical gateway settings.
	 * @param bool|null           $payment_request_enabled Optional explicit wallet enablement.
	 * @return array{settings:array<string,mixed>,updated_split_options:string[],removed_deprecated_method_ids:string[],persisted:bool,failed_option_names:string[]}
	 */
	public function persist( array $settings, ?bool $payment_request_enabled = null ): array {
		$missing_marker                  = new \stdClass();
		$stored_canonical_settings       = get_option( self::SETTINGS_OPTION, $missing_marker );
		$pending_payment_request_setting = get_option( self::PAYMENT_REQUEST_PENDING_OPTION, $missing_marker );
		$has_pending_payment_request     = in_array( $pending_payment_request_setting, array( 'yes', 'no' ), true );
		if (
			null === $payment_request_enabled &&
			$has_pending_payment_request
		) {
			$payment_request_enabled = 'yes' === $pending_payment_request_setting;
		} elseif (
			null === $payment_request_enabled &&
			array_key_exists( 'payment_request', $settings ) &&
			null === $this->get_payment_request_split_state()
		) {
			$payment_request_enabled = 'yes' === (string) $settings['payment_request'];
		}
		unset( $settings['payment_request'] );

		$normalization                  = $this->normalize_settings( $settings );
		$settings                       = $normalization['settings'];
		$canonical_projection_is_stable = is_array( $stored_canonical_settings ) && $stored_canonical_settings === $settings;
		if ( $missing_marker !== $pending_payment_request_setting && ! $has_pending_payment_request ) {
			return array(
				'settings'                      => $settings,
				'updated_split_options'         => array(),
				'removed_deprecated_method_ids' => $normalization['removed_deprecated_method_ids'],
				'persisted'                     => false,
				'failed_option_names'           => array( self::PAYMENT_REQUEST_PENDING_OPTION ),
			);
		}
		if (
			null !== $payment_request_enabled &&
			! $this->write_scalar_option_and_verify(
				self::PAYMENT_REQUEST_PENDING_OPTION,
				$payment_request_enabled ? 'yes' : 'no'
			)
		) {
			return array(
				'settings'                      => $settings,
				'updated_split_options'         => array(),
				'removed_deprecated_method_ids' => $normalization['removed_deprecated_method_ids'],
				'persisted'                     => false,
				'failed_option_names'           => array( self::PAYMENT_REQUEST_PENDING_OPTION ),
			);
		}

		if ( ! $this->write_option_and_verify( self::SETTINGS_OPTION, $settings ) ) {
			return array(
				'settings'                      => $settings,
				'updated_split_options'         => array(),
				'removed_deprecated_method_ids' => $normalization['removed_deprecated_method_ids'],
				'persisted'                     => false,
				'failed_option_names'           => array( self::SETTINGS_OPTION ),
			);
		}

		$split_projection      = $this->synchronize_split_settings( $settings, $canonical_projection_is_stable );
		$updated_split_options = $split_projection['updated_options'];
		$failed_option_names   = $split_projection['failed_option_names'];
		$wallet_failures       = array();
		if ( null !== $payment_request_enabled ) {
			$wallet_projection     = $this->synchronize_payment_request_settings( $payment_request_enabled );
			$updated_split_options = array_merge(
				$updated_split_options,
				$wallet_projection['updated_options']
			);
			$wallet_failures       = $wallet_projection['failed_option_names'];
			$failed_option_names   = array_merge( $failed_option_names, $wallet_failures );
		}
		$updated_split_options = array_values( array_unique( $updated_split_options ) );
		$failed_option_names   = array_values( array_unique( $failed_option_names ) );
		if ( empty( $wallet_failures ) && null !== $payment_request_enabled ) {
			if ( ! $this->delete_option_and_verify( self::PAYMENT_REQUEST_PENDING_OPTION ) ) {
				$failed_option_names[] = self::PAYMENT_REQUEST_PENDING_OPTION;
			}
		}
		$this->log_split_settings_drift(
			$split_projection['drifted_option_names'],
			array_values( array_intersect( $split_projection['drifted_option_names'], $failed_option_names ) )
		);

		return array(
			'settings'                      => $settings,
			'updated_split_options'         => $updated_split_options,
			'removed_deprecated_method_ids' => $normalization['removed_deprecated_method_ids'],
			'persisted'                     => empty( $failed_option_names ),
			'failed_option_names'           => $failed_option_names,
		);
	}

	/**
	 * Tell whether either split wallet gateway is enabled.
	 *
	 * The removed card-gateway switch is consulted only when neither split option exists.
	 *
	 * @param array<string,mixed> $legacy_settings Optional pre-10.4 card settings.
	 * @return bool
	 */
	public function is_payment_request_enabled( array $legacy_settings = array() ): bool {
		$split_state = $this->get_payment_request_split_state();
		if ( null !== $split_state ) {
			return $split_state;
		}

		return 'yes' === (string) ( $legacy_settings['payment_request'] ?? 'no' );
	}

	/**
	 * Get the aggregate split-wallet state, or null before split options exist.
	 *
	 * @return bool|null
	 */
	private function get_payment_request_split_state(): ?bool {
		$missing_marker   = new \stdClass();
		$has_split_option = false;

		foreach ( self::PAYMENT_REQUEST_METHOD_IDS as $method_id ) {
			$settings = get_option( $this->get_split_option_name( $method_id ), $missing_marker );
			if ( $missing_marker === $settings ) {
				continue;
			}

			$has_split_option = true;
			if ( is_array( $settings ) && 'yes' === (string) ( $settings['enabled'] ?? 'no' ) ) {
				return true;
			}
		}

		return $has_split_option ? false : null;
	}

	/**
	 * Tell whether one split payment-request gateway is enabled.
	 *
	 * @param string $method_id Apple Pay or Google Pay method ID.
	 * @return bool
	 */
	public function is_payment_request_method_enabled( string $method_id ): bool {
		if ( ! in_array( $method_id, self::PAYMENT_REQUEST_METHOD_IDS, true ) ) {
			return false;
		}

		$settings = get_option( $this->get_split_option_name( $method_id ), array() );

		return is_array( $settings ) && 'yes' === (string) ( $settings['enabled'] ?? 'no' );
	}

	/**
	 * Set Apple Pay and Google Pay enabled state while preserving split-only settings.
	 *
	 * @param bool $enabled Whether payment-request wallets are enabled.
	 * @return string[] Updated split option names.
	 */
	public function set_payment_request_enabled( bool $enabled ): array {
		return $this->synchronize_payment_request_settings( $enabled )['updated_options'];
	}

	/**
	 * Set split wallet enabled state and return its verified persistence outcome.
	 *
	 * @param bool $enabled Whether payment-request wallets are enabled.
	 * @return array{updated_options:string[],failed_option_names:string[]}
	 */
	private function synchronize_payment_request_settings( bool $enabled ): array {
		$updated_options     = array();
		$failed_option_names = array();

		foreach ( self::PAYMENT_REQUEST_METHOD_IDS as $method_id ) {
			$option_name                   = $this->get_split_option_name( $method_id );
			$existing                      = get_option( $option_name, array() );
			$projected_settings            = is_array( $existing ) ? $existing : array();
			$projected_settings['enabled'] = $enabled ? 'yes' : 'no';
			if ( $projected_settings === $existing ) {
				continue;
			}

			if ( $this->write_option_and_verify( $option_name, $projected_settings, true ) ) {
				$updated_options[] = $option_name;
			} else {
				$failed_option_names[] = $option_name;
			}
		}

		return array(
			'updated_options'     => $updated_options,
			'failed_option_names' => $failed_option_names,
		);
	}

	/**
	 * Remove deprecated method IDs from canonical method lists.
	 *
	 * @param array<string,mixed> $settings Canonical gateway settings.
	 * @return array{settings:array<string,mixed>,removed_deprecated_method_ids:string[]}
	 */
	private function normalize_settings( array $settings ): array {
		$removed = array();
		foreach ( array( 'upe_enabled_payment_method_ids', 'upe_available_payment_methods' ) as $setting_key ) {
			if ( ! is_array( $settings[ $setting_key ] ?? null ) ) {
				continue;
			}

			$previous                 = $this->normalize_string_list( $settings[ $setting_key ] );
			$filtered                 = array_values( array_diff( $previous, self::DEPRECATED_PAYMENT_METHOD_IDS ) );
			$removed                  = array_merge( $removed, array_intersect( $previous, self::DEPRECATED_PAYMENT_METHOD_IDS ) );
			$settings[ $setting_key ] = $filtered;
		}

		return array(
			'settings'                      => $settings,
			'removed_deprecated_method_ids' => array_values( array_unique( $removed ) ),
		);
	}

	/**
	 * Synchronize existing or enabled split gateway settings.
	 *
	 * @param array<string,mixed> $settings                       Canonical gateway settings.
	 * @param bool                $canonical_projection_is_stable Whether the canonical row is unchanged.
	 * @return array{updated_options:string[],failed_option_names:string[],drifted_option_names:string[]}
	 */
	private function synchronize_split_settings( array $settings, bool $canonical_projection_is_stable ): array {
		$enabled_method_ids = is_array( $settings['upe_enabled_payment_method_ids'] ?? null )
			? $this->normalize_string_list( $settings['upe_enabled_payment_method_ids'] )
			: array();
		$method_ids         = self::DEPRECATED_PAYMENT_METHOD_IDS;

		foreach ( $this->registry->get_all() as $definition ) {
			if (
				'card' !== $definition->get_id()
				&& ! in_array( $definition->get_id(), self::PAYMENT_REQUEST_METHOD_IDS, true )
				&& $definition->should_publish_gateway()
			) {
				$method_ids[] = $definition->get_id();
			}
		}

		$updated_options      = array();
		$failed_option_names  = array();
		$drifted_option_names = array();
		$missing_marker       = new \stdClass();
		foreach ( array_values( array_unique( $method_ids ) ) as $method_id ) {
			$option_name       = $this->get_split_option_name( $method_id );
			$existing          = get_option( $option_name, $missing_marker );
			$should_be_enabled = in_array( $method_id, $enabled_method_ids, true ) && ! in_array( $method_id, self::DEPRECATED_PAYMENT_METHOD_IDS, true );

			if ( $missing_marker === $existing && ! $should_be_enabled ) {
				continue;
			}

			$projected_settings                                   = is_array( $existing ) ? $existing : array();
			$projected_settings['enabled']                        = $should_be_enabled ? 'yes' : 'no';
			$projected_settings['upe_enabled_payment_method_ids'] = $enabled_method_ids;
			if ( $projected_settings === $existing ) {
				continue;
			}
			if ( $canonical_projection_is_stable && $missing_marker !== $existing ) {
				$drifted_option_names[] = $option_name;
			}

			if ( $this->write_option_and_verify( $option_name, $projected_settings, true ) ) {
				$updated_options[] = $option_name;
			} else {
				$failed_option_names[] = $option_name;
			}
		}

		return array(
			'updated_options'      => $updated_options,
			'failed_option_names'  => $failed_option_names,
			'drifted_option_names' => array_values( array_unique( $drifted_option_names ) ),
		);
	}

	/**
	 * Log split gateway settings that diverged from an unchanged canonical projection.
	 *
	 * Logging is best-effort and must not affect settings persistence.
	 *
	 * @param string[] $drifted_option_names Drifted split gateway option names.
	 * @param string[] $failed_option_names  Drifted option names that could not be healed.
	 */
	private function log_split_settings_drift( array $drifted_option_names, array $failed_option_names ): void {
		if ( empty( $drifted_option_names ) ) {
			return;
		}

		sort( $drifted_option_names );
		sort( $failed_option_names );
		try {
			wc_get_logger()->warning(
				'WooPayments split gateway settings drift was detected during canonical projection.',
				array(
					'source'               => 'woocommerce-woopayments-settings',
					'event'                => 'split_gateway_settings_drift',
					'drifted_option_names' => $drifted_option_names,
					'failed_option_names'  => $failed_option_names,
				)
			);
		} catch ( \Throwable $logging_exception ) {
			return;
		}
	}

	/**
	 * Write an option and verify the exact desired value was persisted.
	 *
	 * WordPress returns false from update_option() for both an unchanged value and a failed write,
	 * so readback is the persistence contract for these structured settings.
	 *
	 * @param string    $option_name Option name.
	 * @param array     $value       Desired option value.
	 * @param bool|null $autoload    Optional autoload behavior.
	 * @phpstan-param array<string,mixed> $value
	 * @return bool
	 */
	private function write_option_and_verify( string $option_name, array $value, ?bool $autoload = null ): bool {
		update_option( $option_name, $value, $autoload );

		return get_option( $option_name, null ) === $value;
	}

	/**
	 * Write a scalar option and verify the exact desired value was persisted.
	 *
	 * @param string $option_name Option name.
	 * @param string $value       Desired option value.
	 * @return bool
	 */
	private function write_scalar_option_and_verify( string $option_name, string $value ): bool {
		update_option( $option_name, $value, false );

		return get_option( $option_name, null ) === $value;
	}

	/**
	 * Delete an option and verify it no longer exists.
	 *
	 * @param string $option_name Option name.
	 * @return bool
	 */
	private function delete_option_and_verify( string $option_name ): bool {
		delete_option( $option_name );
		$missing_marker = new \stdClass();
		$stored_value   = get_option( $option_name, $missing_marker );

		return $missing_marker === $stored_value;
	}

	/**
	 * Get a split WooPayments gateway option name.
	 *
	 * @param string $method_id Payment method ID.
	 * @return string
	 */
	private function get_split_option_name( string $method_id ): string {
		return 'woocommerce_woocommerce_payments_' . $method_id . '_settings';
	}

	/**
	 * Normalize a list of scalar values to unique non-empty strings.
	 *
	 * @param array<mixed> $values Values.
	 * @return string[]
	 */
	private function normalize_string_list( array $values ): array {
		$result = array();
		foreach ( $values as $value ) {
			if ( ! is_scalar( $value ) ) {
				continue;
			}
			$value = sanitize_key( (string) $value );
			if ( '' !== $value && ! in_array( $value, $result, true ) ) {
				$result[] = $value;
			}
		}

		return $result;
	}
}
