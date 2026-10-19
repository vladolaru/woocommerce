<?php
/**
 * WooPaymentsGatewaySettingsSynchronizer class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\WooPayments;

use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\PaymentMethods\WooPaymentsPaymentMethodRegistry;
use Automattic\WooCommerce\Internal\RegisterHooksInterface;

/**
 * Saves the WooPayments gateway settings and copies each payment method's enabled state to its own gateway's settings
 * option.
 *
 * It also moves the setup tier between connected and active as the gateway is turned on or off, however the option is
 * written.
 *
 * @since 11.0.0
 * @internal
 */
final class WooPaymentsGatewaySettingsSynchronizer implements RegisterHooksInterface {

	/**
	 * The wallet payment methods that share the payment request switch.
	 */
	public const PAYMENT_REQUEST_METHOD_IDS = array( 'apple_pay', 'google_pay' );

	private const PAYMENT_REQUEST_PENDING_OPTION = 'woocommerce_woopayments_payment_request_sync_pending';

	/**
	 * Account service, the read home of the gateway settings.
	 *
	 * @var WooPaymentsAccountService
	 */
	private WooPaymentsAccountService $account_service;

	/**
	 * Payment method registry.
	 *
	 * @var WooPaymentsPaymentMethodRegistry
	 */
	private WooPaymentsPaymentMethodRegistry $registry;

	/**
	 * Setup tier store.
	 *
	 * @var WooPaymentsSetupTier|null
	 */
	private ?WooPaymentsSetupTier $setup_tier = null;

	/**
	 * Runtime arbiter.
	 *
	 * @var WooPaymentsRuntimeArbiter|null
	 */
	private ?WooPaymentsRuntimeArbiter $runtime_arbiter = null;

	/**
	 * Initialize the class instance.
	 *
	 * Without the setup tier and the arbiter, writes keep the tier as it is.
	 *
	 * @internal
	 *
	 * @param WooPaymentsAccountService        $account_service Account service.
	 * @param WooPaymentsPaymentMethodRegistry $registry        Payment method registry.
	 * @param WooPaymentsSetupTier|null        $setup_tier      Optional setup tier store.
	 * @param WooPaymentsRuntimeArbiter|null   $runtime_arbiter Optional payments runtime arbiter.
	 */
	final public function init( WooPaymentsAccountService $account_service, WooPaymentsPaymentMethodRegistry $registry, ?WooPaymentsSetupTier $setup_tier = null, ?WooPaymentsRuntimeArbiter $runtime_arbiter = null ): void { // phpcs:ignore Generic.CodeAnalysis.UnnecessaryFinalModifier.Found -- Required by WooCommerce injection method rules.
		$this->account_service = $account_service;
		$this->registry        = $registry;
		$this->setup_tier      = $setup_tier;
		$this->runtime_arbiter = $runtime_arbiter;
	}

	/**
	 * Save the gateway settings and copy each payment method's enabled state to its gateway.
	 *
	 * The Apple Pay and Google Pay switch waits in a pending option until both wallet gateways are written, so a failed
	 * write is retried on the next save.
	 *
	 * @param array<string,mixed> $settings                Canonical gateway settings.
	 * @param bool|null           $payment_request_enabled Optional explicit wallet enablement.
	 * @return array{settings:array<string,mixed>,updated_split_options:string[],removed_discontinued_method_ids:string[],persisted:bool,failed_option_names:string[]}
	 */
	public function persist( array $settings, ?bool $payment_request_enabled = null ): array {
		$missing_marker                  = new \stdClass();
		$stored_canonical_settings       = get_option( WooPaymentsPersistenceVocabulary::GATEWAY_SETTINGS_OPTION, $missing_marker );
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
			null === $this->account_service->get_payment_request_split_state()
		) {
			$payment_request_enabled = 'yes' === (string) $settings['payment_request'];
		}
		unset( $settings['payment_request'] );

		$normalization             = $this->normalize_settings( $settings );
		$settings                  = $normalization['settings'];
		$stored_settings_unchanged = is_array( $stored_canonical_settings ) && $stored_canonical_settings === $settings;
		if ( $missing_marker !== $pending_payment_request_setting && ! $has_pending_payment_request ) {
			return array(
				'settings'                        => $settings,
				'updated_split_options'           => array(),
				'removed_discontinued_method_ids' => $normalization['removed_discontinued_method_ids'],
				'persisted'                       => false,
				'failed_option_names'             => array( self::PAYMENT_REQUEST_PENDING_OPTION ),
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
				'settings'                        => $settings,
				'updated_split_options'           => array(),
				'removed_discontinued_method_ids' => $normalization['removed_discontinued_method_ids'],
				'persisted'                       => false,
				'failed_option_names'             => array( self::PAYMENT_REQUEST_PENDING_OPTION ),
			);
		}

		if ( ! $this->write_option_and_verify( WooPaymentsPersistenceVocabulary::GATEWAY_SETTINGS_OPTION, $settings ) ) {
			return array(
				'settings'                        => $settings,
				'updated_split_options'           => array(),
				'removed_discontinued_method_ids' => $normalization['removed_discontinued_method_ids'],
				'persisted'                       => false,
				'failed_option_names'             => array( WooPaymentsPersistenceVocabulary::GATEWAY_SETTINGS_OPTION ),
			);
		}
		$this->sync_setup_tier( $settings );

		$split_result          = $this->synchronize_split_settings( $settings, $stored_settings_unchanged );
		$updated_split_options = $split_result['updated_options'];
		$failed_option_names   = $split_result['failed_option_names'];
		$wallet_failures       = array();
		if ( null !== $payment_request_enabled ) {
			$wallet_result         = $this->synchronize_payment_request_settings( $payment_request_enabled );
			$updated_split_options = array_merge(
				$updated_split_options,
				$wallet_result['updated_options']
			);
			$wallet_failures       = $wallet_result['failed_option_names'];
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
			$split_result['drifted_option_names'],
			array_values( array_intersect( $split_result['drifted_option_names'], $failed_option_names ) )
		);

		return array(
			'settings'                        => $settings,
			'updated_split_options'           => $updated_split_options,
			'removed_discontinued_method_ids' => $normalization['removed_discontinued_method_ids'],
			'persisted'                       => empty( $failed_option_names ),
			'failed_option_names'             => $failed_option_names,
		);
	}

	/**
	 * Keep the setup tier in step with the gateway's enabled setting, however the setting is written.
	 *
	 * The classic toggle and the payment gateways REST route write the option directly, without `persist()`.
	 */
	public function register(): void {
		add_action( 'add_option_' . WooPaymentsPersistenceVocabulary::GATEWAY_SETTINGS_OPTION, array( $this, 'handle_settings_added' ), 10, 2 );
		add_action( 'update_option_' . WooPaymentsPersistenceVocabulary::GATEWAY_SETTINGS_OPTION, array( $this, 'handle_settings_updated' ), 10, 2 );
	}

	/**
	 * Recompute the tier when the gateway settings option is created.
	 *
	 * @internal
	 *
	 * @param mixed $option Option name.
	 * @param mixed $value  New settings.
	 */
	public function handle_settings_added( $option, $value ): void {
		unset( $option );
		$this->synchronize_after_direct_write( $value );
	}

	/**
	 * Recompute the tier when the gateway settings option changes.
	 *
	 * @internal
	 *
	 * @param mixed $old_value Previous settings.
	 * @param mixed $value     New settings.
	 */
	public function handle_settings_updated( $old_value, $value ): void {
		unset( $old_value );
		$this->synchronize_after_direct_write( $value );
	}

	/**
	 * Recompute the setup tier after a direct settings write, only while the built-in WooPayments owns payments.
	 *
	 * The WooPayments extension writes the same option while it owns payments; the stored tier is not its to change.
	 *
	 * @param mixed $value New settings.
	 */
	private function synchronize_after_direct_write( $value ): void {
		if ( null === $this->runtime_arbiter || ! $this->runtime_arbiter->is_builtin_owner() ) {
			return;
		}

		$this->sync_setup_tier( is_array( $value ) ? $value : array() );
	}

	/**
	 * Set the setup tier from the settings just written: `active` or `connected` by the enabled setting, and only from those
	 * two effective tiers, so nothing is written while the WooPayments feature is off.
	 *
	 * @param array<string,mixed> $settings Persisted canonical settings.
	 */
	private function sync_setup_tier( array $settings ): void {
		if ( null === $this->setup_tier || null === $this->runtime_arbiter ) {
			return;
		}

		$current_state = $this->setup_tier->get_effective_tier();
		if ( ! in_array( $current_state, array( WooPaymentsSetupTier::CONNECTED, WooPaymentsSetupTier::ACTIVE ), true ) ) {
			return;
		}

		$this->setup_tier->write_tier(
			'yes' === ( $settings['enabled'] ?? null ) ? WooPaymentsSetupTier::ACTIVE : WooPaymentsSetupTier::CONNECTED
		);
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
			$option_name                   = self::get_split_option_name( $method_id );
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
	 * Remove discontinued method IDs from canonical method lists.
	 *
	 * @param array<string,mixed> $settings Canonical gateway settings.
	 * @return array{settings:array<string,mixed>,removed_discontinued_method_ids:string[]}
	 */
	private function normalize_settings( array $settings ): array {
		$removed = array();
		foreach ( array( 'upe_enabled_payment_method_ids', 'upe_available_payment_methods' ) as $setting_key ) {
			if ( ! is_array( $settings[ $setting_key ] ?? null ) ) {
				continue;
			}

			$previous                 = $this->normalize_string_list( $settings[ $setting_key ] );
			$filtered                 = array_values( array_diff( $previous, WooPaymentsPaymentMethodRegistry::DISCONTINUED_PAYMENT_METHOD_IDS ) );
			$removed                  = array_merge( $removed, array_intersect( $previous, WooPaymentsPaymentMethodRegistry::DISCONTINUED_PAYMENT_METHOD_IDS ) );
			$settings[ $setting_key ] = $filtered;
		}

		return array(
			'settings'                        => $settings,
			'removed_discontinued_method_ids' => array_values( array_unique( $removed ) ),
		);
	}

	/**
	 * Synchronize existing or enabled split gateway settings.
	 *
	 * @param array<string,mixed> $settings                       Canonical gateway settings.
	 * @param bool                $stored_settings_unchanged Whether the canonical row is unchanged.
	 * @return array{updated_options:string[],failed_option_names:string[],drifted_option_names:string[]}
	 */
	private function synchronize_split_settings( array $settings, bool $stored_settings_unchanged ): array {
		$enabled_method_ids = is_array( $settings['upe_enabled_payment_method_ids'] ?? null )
			? $this->normalize_string_list( $settings['upe_enabled_payment_method_ids'] )
			: array();
		$method_ids         = WooPaymentsPaymentMethodRegistry::DISCONTINUED_PAYMENT_METHOD_IDS;

		foreach ( $this->registry->get_registered( $this->account_service ) as $definition ) {
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
			$option_name       = self::get_split_option_name( $method_id );
			$existing          = get_option( $option_name, $missing_marker );
			$should_be_enabled = in_array( $method_id, $enabled_method_ids, true ) && ! in_array( $method_id, WooPaymentsPaymentMethodRegistry::DISCONTINUED_PAYMENT_METHOD_IDS, true );

			if ( $missing_marker === $existing && ! $should_be_enabled ) {
				continue;
			}

			$projected_settings                                   = is_array( $existing ) ? $existing : array();
			$projected_settings['enabled']                        = $should_be_enabled ? 'yes' : 'no';
			$projected_settings['upe_enabled_payment_method_ids'] = $enabled_method_ids;
			if ( $projected_settings === $existing ) {
				continue;
			}
			if ( $stored_settings_unchanged && $missing_marker !== $existing ) {
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
	 * Log the payment method gateways whose settings changed although the stored gateway settings did not.
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
			wc_get_container()->get( WooPaymentsLogger::class )->log_always(
				'WooPayments split gateway settings drifted from the unchanged stored settings.',
				'warning',
				array(
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
	 * Get the settings option of one payment method's own gateway (a split gateway),
	 * `woocommerce_woocommerce_payments_<method>_settings`, the option the WooPayments extension's split gateways use
	 * (client 11.1.0 `includes/class-wc-payment-gateway-wcpay.php:292,3122-3124`).
	 *
	 * @param string $method_id Payment method ID.
	 * @return string
	 */
	public static function get_split_option_name( string $method_id ): string {
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
