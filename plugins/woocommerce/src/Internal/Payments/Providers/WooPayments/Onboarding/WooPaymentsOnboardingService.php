<?php
/**
 * WooPaymentsOnboardingService class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Onboarding;

use Automattic\WooCommerce\Admin\Features\PaymentGatewaySuggestions\DefaultPaymentGateways;
use Automattic\WooCommerce\Admin\Notes\DataStore as NotesDataStore;
use Automattic\WooCommerce\Admin\Notes\Note;
use Automattic\WooCommerce\Internal\Admin\Settings\PaymentsProviders\WooPayments\WooPaymentsService;
use Automattic\WooCommerce\Internal\Admin\Settings\Utils;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api\WooPaymentsApiClient;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api\WooPaymentsApiException;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\PaymentMethods\WooPaymentsPaymentMethodRegistry;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsAccountMilestonesController;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsAccountService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsDatabaseCache;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsFrontendTrackingController;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsGatewaySettingsSynchronizer;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsLogger;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsPaymentsSettingsAdapter;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsPersistenceVocabulary;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsTokenService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsTracks;
use Automattic\WooCommerce\Proxies\LegacyProxy;
use Throwable;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Creates the merchant's WooPayments account with the platform for the WooPayments built into WooCommerce: a
 * test-drive account, or an embedded KYC session and its finish. It applies the payment methods the merchant
 * picked, turns WooPay on when the platform asks, and deletes the account when onboarding is reset. It also stores
 * partner referral codes and caches the payment methods the platform recommends for the store's country.
 *
 * @since 11.2.0
 * @internal
 */
class WooPaymentsOnboardingService {

	private const TEST_DRIVE_SETTINGS_FOR_LIVE_ACCOUNT_TRANSIENT = 'test_drive_account_settings_for_live_account';

	private const REFERRAL_CODE_TRANSIENT = 'woopayments_referral_code';

	/**
	 * The LegacyProxy instance.
	 *
	 * @var LegacyProxy
	 */
	private LegacyProxy $proxy;

	/**
	 * The WooPayments API client.
	 *
	 * @var WooPaymentsApiClient|null
	 */
	private ?WooPaymentsApiClient $api_client = null;

	/**
	 * The WooPayments account service.
	 *
	 * @var WooPaymentsAccountService|null
	 */
	private ?WooPaymentsAccountService $account_service = null;

	/**
	 * The WooPayments payment method registry.
	 *
	 * @var WooPaymentsPaymentMethodRegistry
	 */
	private WooPaymentsPaymentMethodRegistry $payment_method_registry;

	/**
	 * Initialize the class instance.
	 *
	 * The API client and the account service are resolved on first use.
	 *
	 * @internal
	 *
	 * @param LegacyProxy                      $proxy                   The LegacyProxy instance.
	 * @param WooPaymentsPaymentMethodRegistry $payment_method_registry The WooPayments payment method registry.
	 */
	final public function init( LegacyProxy $proxy, WooPaymentsPaymentMethodRegistry $payment_method_registry ): void {
		$this->proxy                   = $proxy;
		$this->payment_method_registry = $payment_method_registry;
	}

	/**
	 * Create a test-drive account, with the payment methods the merchant picked.
	 *
	 * @param string $location     The store's country, an ISO 3166-1 alpha-2 code.
	 * @param array  $capabilities The payment methods the merchant picked, keyed by payment method ID.
	 * @return array|WP_Error The platform's answer, or the platform's error.
	 */
	public function init_test_account( string $location, array $capabilities ) {
		try {
			return $this->init_test_drive_account( $location, $capabilities );
		} catch ( WooPaymentsApiException $e ) {
			return $this->get_wp_error_from_api_exception( $e, esc_html__( 'An unexpected error happened while initializing the test account.', 'woocommerce' ) );
		}
	}

	/**
	 * Create an embedded KYC session, after applying the payment methods the merchant picked.
	 *
	 * @param bool  $is_dev_mode     Whether the gateway is in dev mode, which makes the account a test account.
	 * @param array $self_assessment The merchant's answers about their business.
	 * @param array $capabilities    The payment methods the merchant picked, keyed by payment method ID.
	 * @return array|WP_Error The session, or the platform's error.
	 */
	public function create_kyc_session( bool $is_dev_mode, array $self_assessment, array $capabilities ) {
		try {
			return $this->create_embedded_kyc_session( $is_dev_mode, $self_assessment, $capabilities );
		} catch ( WooPaymentsApiException $e ) {
			return $this->get_wp_error_from_api_exception( $e, esc_html__( 'An unexpected error happened while creating the KYC session.', 'woocommerce' ) );
		}
	}

	/**
	 * Finish the embedded KYC session and enable the gateway in the account's mode.
	 *
	 * @param string $source The onboarding source.
	 * @return array|WP_Error The platform's answer, or the platform's error.
	 */
	public function finish_kyc_session( string $source ) {
		try {
			return $this->finalize_embedded_kyc( $source );
		} catch ( WooPaymentsApiException $e ) {
			return $this->get_wp_error_from_api_exception( $e, esc_html__( 'An unexpected error happened while finalizing the KYC session.', 'woocommerce' ) );
		}
	}

	/**
	 * Delete the account in the current onboarding mode, so onboarding can start over.
	 *
	 * @return array|WP_Error The platform's answer, or the platform's error.
	 */
	public function reset_onboarding() {
		try {
			return $this->delete_account( $this->is_onboarding_test_mode_enabled() );
		} catch ( WooPaymentsApiException $e ) {
			return $this->get_wp_error_from_api_exception( $e, esc_html__( 'An unexpected error happened while resetting onboarding.', 'woocommerce' ) );
		}
	}

	/**
	 * Delete a test account so the merchant can set up a live one.
	 *
	 * A test-drive account's enabled payment methods are saved first, so the live account gets them back after KYC.
	 *
	 * @param bool $is_test_drive Whether the account is a test-drive account rather than a sandbox account.
	 * @return array|WP_Error The platform's answer, or the platform's error.
	 */
	public function disable_test_account( bool $is_test_drive ) {
		try {
			if ( $is_test_drive ) {
				$this->save_test_drive_settings_for_live_account();
				return $this->delete_account( true );
			}

			return $this->delete_account( $this->is_onboarding_test_mode_enabled() );
		} catch ( WooPaymentsApiException $e ) {
			return $this->get_wp_error_from_api_exception( $e, esc_html__( 'An unexpected error happened while disabling the test account.', 'woocommerce' ) );
		}
	}

	/**
	 * Turn WooPay on because the platform asked for it during onboarding, then forget the request.
	 *
	 * Link wins, so WooPay stays off when Link is enabled.
	 */
	public function activate_woopay_enabled_by_default(): void {
		$this->add_enabled_payment_methods( array(), true );
		$this->proxy->call_function( 'delete_transient', WooPaymentsPaymentsSettingsAdapter::WOOPAY_ENABLED_BY_DEFAULT_TRANSIENT );
	}

	/**
	 * Store a partner's referral code for the KYC session and record the referral.
	 *
	 * The code is cut to 50 characters, lowercased and trimmed, and kept for 30 days (client 11.1.0
	 * `includes/class-wc-payments-onboarding-service.php:1580-1587`). An empty code stores nothing.
	 *
	 * @param string $referral_code The sanitized code from the referral link.
	 * @return string The stored code, or an empty string when there is none.
	 */
	public function store_referral_code( string $referral_code ): string {
		$referral_code = trim( strtolower( substr( $referral_code, 0, 50 ) ) );
		if ( empty( $referral_code ) ) {
			return '';
		}

		set_transient( self::REFERRAL_CODE_TRANSIENT, $referral_code, 30 * DAY_IN_SECONDS );
		WooPaymentsTracks::record_wcadmin_event_with_tracking_info(
			'wcpay_account_referral',
			array(
				'referral_code' => $referral_code,
				'referrer'      => wp_get_referer(),
			)
		);

		return $referral_code;
	}

	/**
	 * Get the recommended payment methods list for onboarding.
	 *
	 * The platform's answer is cached as client 11.1.0 caches it (`includes/class-wc-payments-onboarding-service.php:185-217`):
	 * through the database cache, valid only for the same country and locale, keeping the last good answer after an error.
	 * A country WooPayments does not support gets an empty list without a platform call (client 11.1.0 `includes/class-wc-payments-account.php:698-702`).
	 *
	 * @param string $country_code Optional. Business location country code.
	 * @return array<int,array<string,mixed>>
	 */
	public function get_recommended_payment_methods( string $country_code = '' ): array {
		$country_code = strtoupper( trim( $country_code ) );
		if ( ! in_array( $country_code, DefaultPaymentGateways::get_wcpay_countries(), true ) ) {
			return array();
		}

		$locale      = get_user_locale();
		$cached_data = wc_get_container()->get( WooPaymentsDatabaseCache::class )->get_or_add(
			WooPaymentsDatabaseCache::RECOMMENDED_PAYMENT_METHODS_KEY,
			function () use ( $country_code, $locale ) {
				try {
					$payment_methods = $this->get_api_client()->get_recommended_payment_methods( $country_code, $locale );
				} catch ( Throwable $exception ) {
					wc_get_container()->get( WooPaymentsLogger::class )->log_throwable( 'Failed to fetch the WooPayments recommended payment methods.', $exception );

					return null;
				}

				return array(
					'payment_methods' => $payment_methods,
					'__locale'        => $locale,
					'__country_code'  => $country_code,
				);
			},
			static function ( $data ) use ( $country_code, $locale ): bool {
				return is_array( $data )
					&& isset( $data['payment_methods'], $data['__locale'], $data['__country_code'] )
					&& $data['__locale'] === $locale
					&& $data['__country_code'] === $country_code;
			}
		);

		$payment_methods = is_array( $cached_data ) ? ( $cached_data['payment_methods'] ?? null ) : null;

		return is_array( $payment_methods ) ? $this->normalize_recommended_payment_methods( $payment_methods ) : array();
	}

	/**
	 * Normalize recommended payment methods for the settings provider pipeline.
	 *
	 * @param array $recommended_pms Raw recommended payment methods.
	 * @return array<int,array<string,mixed>>
	 */
	private function normalize_recommended_payment_methods( array $recommended_pms ): array {
		$recommended_pms = array_values(
			array_filter(
				$recommended_pms,
				static function ( $payment_method ): bool {
					return is_array( $payment_method ) && isset( $payment_method['id'], $payment_method['title'] );
				}
			)
		);

		return array_map(
			static function ( array $payment_method, int $index ): array {
				if ( ! isset( $payment_method['enabled'] ) ) {
					$payment_method['enabled'] = ! isset( $payment_method['type'] ) || 'available' !== $payment_method['type'];
				}

				$payment_method['priority'] = isset( $payment_method['priority'] ) ? (int) $payment_method['priority'] : $index;

				return $payment_method;
			},
			$recommended_pms,
			array_keys( $recommended_pms )
		);
	}

	/**
	 * Create a test-drive account and store the account the platform answers with.
	 *
	 * @param string $location     Account country.
	 * @param array  $capabilities Requested payment method capabilities.
	 * @return array
	 */
	private function init_test_drive_account( string $location, array $capabilities ): array {
		$this->set_onboarding_test_mode( true );

		// The merchant's own name goes with the test account, as client 11.1.0 sends it
		// (includes/class-wc-payments-onboarding-service.php:731-742); the platform fills a placeholder name otherwise.
		$current_user = wp_get_current_user();
		$account_data = $this->build_account_creation_data(
			'test_drive',
			array(
				'business_type' => 'individual',
				'country'       => $location,
				'individual'    => array(
					'first_name' => $current_user->first_name ?? null,
					'last_name'  => $current_user->last_name ?? null,
				),
			),
			$capabilities
		);

		$response = $this->get_api_client()->initialize_onboarding(
			false,
			$this->get_onboarding_return_url(),
			$this->get_onboarding_site_data(),
			$this->array_filter_recursive( $this->get_onboarding_user_data() ),
			$this->array_filter_recursive( $account_data ),
			$this->get_actioned_inbox_notes()
		);

		// Client 11.1.0 init_test_drive_account() remembers the platform's WooPay default only when should_enable_woopay() agrees.
		if ( $this->should_enable_woopay_by_default( filter_var( $response['woopay_enabled_by_default'] ?? false, FILTER_VALIDATE_BOOLEAN ), $capabilities ) ) {
			$this->proxy->call_function( 'set_transient', WooPaymentsPaymentsSettingsAdapter::WOOPAY_ENABLED_BY_DEFAULT_TRANSIENT, true, DAY_IN_SECONDS );
		}

		$success = array_key_exists( 'url', $response ) && false === $response['url'];
		if ( $success ) {
			$this->update_gateway_settings_after_test_account_init( $capabilities, $response );
			$is_live = $this->get_response_bool( $response, array( 'is_live' ), false );
			// The platform answers without an onboarding URL only once the account's details are submitted.
			if ( ! $this->sync_account_cache_from_response( $response, $is_live, ! $is_live, true ) ) {
				$this->get_account_service()->clear_cache();
			}
		}

		return array_merge(
			array(
				'success' => $success,
			),
			$response
		);
	}

	/**
	 * Create an embedded KYC session.
	 *
	 * @param bool  $is_dev_mode          Whether the gateway is in dev mode.
	 * @param array $self_assessment_data Self assessment data.
	 * @param array $capabilities         Requested payment method capabilities.
	 * @return array
	 */
	private function create_embedded_kyc_session( bool $is_dev_mode, array $self_assessment_data, array $capabilities ): array {
		$setup_mode = $is_dev_mode ? 'test' : 'live';
		$this->set_onboarding_test_mode( 'live' !== $setup_mode );

		// Client 11.1.0 create_embedded_kyc_session() applies the picks before it calls the platform.
		if ( ! empty( $capabilities ) && ! $this->apply_payment_method_picks( $capabilities ) ) {
			$this->log_onboarding_error( 'WooPayments could not save the payment methods picked in onboarding.' );
		}

		if ( 'live' === $setup_mode ) {
			foreach ( $this->get_test_drive_enabled_payment_method_ids() as $payment_method_id ) {
				$definition = $this->payment_method_registry->get( $payment_method_id );
				if ( null !== $definition ) {
					$capabilities[ $definition->get_account_capability_key() ] = true;
				}
			}
		}

		$referral_code = get_transient( self::REFERRAL_CODE_TRANSIENT );
		$session       = $this->get_api_client()->initialize_onboarding_embedded_kyc(
			'live' === $setup_mode,
			$this->get_onboarding_site_data(),
			$this->array_filter_recursive( $this->get_onboarding_user_data() ),
			$this->array_filter_recursive( $this->build_account_creation_data( $setup_mode, $self_assessment_data, $capabilities ) ),
			$this->get_actioned_inbox_notes(),
			empty( $referral_code ) ? null : (string) $referral_code
		);

		// Client 11.1.0 create_embedded_kyc_session() remembers whether the platform wants WooPay on after KYC.
		$this->proxy->call_function(
			'set_transient',
			WooPaymentsPaymentsSettingsAdapter::WOOPAY_ENABLED_BY_DEFAULT_TRANSIENT,
			filter_var( $session['woopay_enabled_by_default'] ?? false, FILTER_VALIDATE_BOOLEAN ),
			DAY_IN_SECONDS
		);

		if ( $this->is_account_created_response( $session ) ) {
			$is_live = $this->get_response_bool( $session, array( 'is_live' ), 'live' === $setup_mode );
			if ( ! $this->sync_account_cache_from_response( $session, $is_live, false, false ) ) {
				$this->get_account_service()->clear_cache();
			}
		}

		return $this->normalize_kyc_session_response( $session );
	}

	/**
	 * Finish the embedded KYC session; like client 11.1.0 finalize_embedded_connection(), it applies no NOX picks.
	 *
	 * The platform answers `success: false` when the merchant left KYC before submitting the account details; like client
	 * 11.1.0 finalize_embedded_kyc() (includes/class-wc-payments-onboarding-service.php:442-444), that is an error.
	 *
	 * @param string $source Onboarding source.
	 * @return array
	 * @throws WooPaymentsApiException When the platform did not finalize the session.
	 */
	private function finalize_embedded_kyc( string $source ): array {
		$response = $this->get_api_client()->finalize_onboarding_embedded_kyc(
			$this->proxy->call_function( 'get_user_locale' ),
			$source,
			$this->get_actioned_inbox_notes()
		);

		if ( empty( $response['success'] ) ) {
			throw new WooPaymentsApiException( esc_html__( 'Failed to finalize onboarding session.', 'woocommerce' ), 'wcpay-onboarding-finalize-error', 400 );
		}

		$mode    = isset( $response['mode'] ) && is_scalar( $response['mode'] ) ? (string) $response['mode'] : '';
		$is_live = 'live' === $mode;
		if ( ! $this->sync_account_cache_from_response( $response, $is_live, false, true ) ) {
			$this->get_account_service()->clear_cache();
		}

		$this->apply_kyc_connection( $is_live );
		$this->clear_onboarding_data();

		$response['params'] = array(
			'promo'                    => isset( $response['promotion_id'] ) && is_scalar( $response['promotion_id'] ) ? (string) $response['promotion_id'] : '',
			'from'                     => WooPaymentsService::FROM_NOX_IN_CONTEXT,
			'source'                   => $source,
			'wcpay-connection-success' => '1',
		);

		$this->refresh_account_after_kyc();

		return $response;
	}

	/**
	 * Apply a KYC return from the platform's hosted onboarding, like client 11.1.0 finalize_connection() after its state check.
	 *
	 * Like the client (class-wc-payments-account.php:2417-2428), a merchant who left KYC early keeps the onboarding data.
	 *
	 * @since 11.2.0
	 *
	 * @param bool $is_live         Whether the returned account is live.
	 * @param bool $is_kyc_complete Whether the merchant finished KYC rather than leaving it early.
	 */
	public function finish_hosted_kyc_connection( bool $is_live, bool $is_kyc_complete ): void {
		$this->apply_kyc_connection( $is_live );

		if ( $is_kyc_complete ) {
			$this->clear_onboarding_data();
		}
	}

	/**
	 * Apply the local side of a finished KYC, shared by client 11.1.0 finalize_embedded_connection() and finalize_connection().
	 *
	 * @param bool $is_live Whether the finalized account is live.
	 */
	private function apply_kyc_connection( bool $is_live ): void {
		$this->enable_gateway_after_kyc( $is_live );
		$this->restore_test_drive_payment_methods();

		// Flag the new connection for the Overview's wcpay_stripe_connected Tracks event.
		$this->proxy->call_function( 'update_option', '_wcpay_onboarding_stripe_connected', array( 'is_existing_stripe_account' => false ), false );

		// Stamp a live KYC submission once, for the post-KYC nudge clock.
		if ( $is_live && ! $this->proxy->call_function( 'get_option', WooPaymentsAccountMilestonesController::KYC_SUBMITTED_DATE_OPTION ) ) {
			$this->proxy->call_function( 'update_option', WooPaymentsAccountMilestonesController::KYC_SUBMITTED_DATE_OPTION, $this->proxy->call_function( 'time' ), false );
		}
	}

	/**
	 * Drop the data only the initial onboarding uses, like client 11.1.0 cleanup_on_account_onboarded().
	 *
	 * The business types cache is left alone: nothing here writes it.
	 */
	private function clear_onboarding_data(): void {
		wc_get_container()->get( WooPaymentsDatabaseCache::class )->delete( WooPaymentsDatabaseCache::RECOMMENDED_PAYMENT_METHODS_KEY );
		$this->get_account_service()->clear_onboarding_fields_cache();
	}

	/**
	 * Enable the gateway in the finalized account's mode, as client 11.1.0 finalize_embedded_connection() does.
	 *
	 * The write through the settings synchronizer also turns on each method's own gateway and moves a `connected` setup
	 * tier to `active`.
	 *
	 * @param bool $is_live Whether the finalized account is live.
	 */
	private function enable_gateway_after_kyc( bool $is_live ): void {
		$settings              = $this->proxy->call_function( 'get_option', WooPaymentsPersistenceVocabulary::GATEWAY_SETTINGS_OPTION, array() );
		$settings              = is_array( $settings ) ? $settings : array();
		$settings['enabled']   = 'yes';
		$settings['test_mode'] = $is_live ? 'no' : 'yes';

		$persist_result = wc_get_container()->get( WooPaymentsGatewaySettingsSynchronizer::class )->persist( $settings );
		if ( ! $persist_result['persisted'] ) {
			$this->log_onboarding_error(
				'WooPayments could not enable the gateway after KYC finalization.',
				array( 'failed_options' => $persist_result['failed_option_names'] )
			);
		}
	}

	/**
	 * Merge the saved test-drive payment methods back into the enabled set, like client 11.1.0
	 * restore_test_drive_enabled_payment_methods(): no capability or availability check, and a restored Link turns WooPay off.
	 */
	private function restore_test_drive_payment_methods(): void {
		$test_drive_settings = $this->proxy->call_function( 'get_transient', self::TEST_DRIVE_SETTINGS_FOR_LIVE_ACCOUNT_TRANSIENT );
		if ( ! is_array( $test_drive_settings ) || empty( $test_drive_settings['enabled_payment_methods'] ) || ! is_array( $test_drive_settings['enabled_payment_methods'] ) ) {
			return;
		}

		$restored_ids = $this->get_test_drive_enabled_payment_method_ids();
		if ( ! empty( $restored_ids ) && ! $this->add_enabled_payment_methods( $restored_ids ) ) {
			// Keep the Test Drive selection until the transient expires, so it is not lost with the failed write.
			$this->log_onboarding_error( 'WooPayments could not restore the Test Drive payment methods after KYC finalization.' );
			return;
		}

		$this->proxy->call_function( 'delete_transient', self::TEST_DRIVE_SETTINGS_FOR_LIVE_ACCOUNT_TRANSIENT );
	}

	/**
	 * Apply the NOX payment-method picks like client 11.1.0 update_enabled_payment_methods_ids(): enable the picked methods,
	 * set WooPay from the `woopay` pick and the Apple Pay / Google Pay gateways from the `apple_google` pick.
	 *
	 * @param array<mixed> $picks NOX picks keyed by payment method ID.
	 * @return bool Whether the canonical settings and split gateways were persisted.
	 */
	private function apply_payment_method_picks( array $picks ): bool {
		$wallets_picked = $this->is_pick_selected( $picks['apple_google'] ?? false )
			|| $this->is_pick_selected( $picks['apple_pay'] ?? false )
			|| $this->is_pick_selected( $picks['google_pay'] ?? false );

		return $this->add_enabled_payment_methods(
			$this->get_picked_payment_method_ids( $picks ),
			$this->is_pick_selected( $picks['woopay'] ?? false ),
			$wallets_picked,
			true
		);
	}

	/**
	 * Add payment methods to the stored enabled set without capability or availability checks, as client 11.1.0
	 * update_enabled_payment_methods_ids() and restore_test_drive_enabled_payment_methods() write the gateway option.
	 *
	 * @param string[]  $payment_method_ids      Payment method IDs to enable.
	 * @param bool|null $woopay_picked           WooPay pick, or null to keep the stored WooPay setting.
	 * @param bool|null $payment_request_enabled Apple Pay / Google Pay pick, or null to keep the stored wallet state.
	 * @param bool      $enable_card_gateway     Whether an enabled card method also turns the card gateway on (client 11.1.0
	 *                                           `includes/class-wc-payments-onboarding-service.php:1530-1537` enables
	 *                                           every listed method's gateway).
	 * @return bool Whether the canonical settings and split gateways were persisted.
	 */
	private function add_enabled_payment_methods( array $payment_method_ids, ?bool $woopay_picked = null, ?bool $payment_request_enabled = null, bool $enable_card_gateway = false ): bool {
		$settings           = $this->proxy->call_function( 'get_option', WooPaymentsPersistenceVocabulary::GATEWAY_SETTINGS_OPTION, array() );
		$settings           = is_array( $settings ) ? $settings : array();
		$enabled_ids        = is_array( $settings['upe_enabled_payment_method_ids'] ?? null ) ? $settings['upe_enabled_payment_method_ids'] : array( 'card' );
		$enabled_ids        = array_values( array_unique( array_merge( $enabled_ids, $payment_method_ids ) ) );
		$was_woopay_enabled = 'yes' === ( $settings['platform_checkout'] ?? 'no' );
		// WooPay and Link are never both on, and Link wins.
		$is_woopay_enabled = ( $woopay_picked ?? $was_woopay_enabled ) && ! in_array( 'link', $enabled_ids, true );

		$settings['upe_enabled_payment_method_ids'] = $enabled_ids;
		if ( $enable_card_gateway && in_array( 'card', $enabled_ids, true ) ) {
			$settings['enabled'] = 'yes';
		}
		if ( $is_woopay_enabled !== $was_woopay_enabled ) {
			$settings['platform_checkout'] = $is_woopay_enabled ? 'yes' : 'no';
			if ( ! $is_woopay_enabled ) {
				$settings['platform_checkout_last_disable_date'] = gmdate( 'Y-m-d' );
			}
		}

		// The canonical write also enables each method's split gateway and syncs its duplicated list.
		$persist_result = wc_get_container()->get( WooPaymentsGatewaySettingsSynchronizer::class )->persist( $settings, $payment_request_enabled );
		if ( $is_woopay_enabled !== $was_woopay_enabled ) {
			wc_get_container()->get( WooPaymentsFrontendTrackingController::class )->record_woopay_admin_event(
				$is_woopay_enabled ? 'woopay_enabled' : 'woopay_disabled',
				array( 'test_mode' => $this->get_account_service()->is_test_mode_enabled() ? 1 : 0 )
			);
		}

		return $persist_result['persisted'];
	}

	/**
	 * Tell whether to remember the platform's WooPay default, like client 11.1.0 should_enable_woopay(): the platform
	 * answer when no picks were sent, otherwise the `woopay_payments` pick.
	 *
	 * @param bool         $default_value The platform's `woopay_enabled_by_default` answer.
	 * @param array<mixed> $capabilities  NOX picks keyed by payment method ID.
	 * @return bool
	 */
	private function should_enable_woopay_by_default( bool $default_value, array $capabilities ): bool {
		if ( empty( $capabilities ) ) {
			return $default_value;
		}

		return ! empty( $capabilities['woopay_payments'] );
	}

	/**
	 * Refresh account data after finalization without invalidating the non-replayable success.
	 */
	private function refresh_account_after_kyc(): void {
		try {
			$this->get_account_service()->refresh_account_data();
		} catch ( \Throwable $e ) {
			return;
		}
	}

	/**
	 * Get the picked payment methods that have their own account capability, skipping the `woopay` and `apple_google` placeholders.
	 *
	 * @param array<mixed> $picks NOX picks keyed by payment method ID.
	 * @return string[]
	 */
	private function get_picked_payment_method_ids( array $picks ): array {
		$picked_ids = array();

		foreach ( $picks as $payment_method_id => $selected ) {
			if ( ! is_string( $payment_method_id ) || ! $this->is_pick_selected( $selected ) ) {
				continue;
			}

			$definition = $this->payment_method_registry->get( $payment_method_id );
			if ( null === $definition || $definition->get_account_capability_key() !== $definition->get_stripe_id() ) {
				continue;
			}

			$picked_ids[] = $definition->get_id();
		}

		return array_values( array_unique( $picked_ids ) );
	}

	/**
	 * Tell whether a stored NOX pick value is turned on.
	 *
	 * @param mixed $selected Stored pick value.
	 * @return bool
	 */
	private function is_pick_selected( $selected ): bool {
		if ( is_bool( $selected ) ) {
			return $selected;
		}

		return is_scalar( $selected ) && wc_string_to_bool( (string) $selected );
	}

	/**
	 * Save the test-drive account's enabled payment methods for an hour, so the live account gets them back after KYC.
	 */
	private function save_test_drive_settings_for_live_account(): void {
		$settings                = $this->proxy->call_function( 'get_option', WooPaymentsPersistenceVocabulary::GATEWAY_SETTINGS_OPTION, array() );
		$enabled_payment_methods = is_array( $settings ) && is_array( $settings['upe_enabled_payment_method_ids'] ?? null )
			? $this->normalize_test_drive_payment_method_ids( $settings['upe_enabled_payment_method_ids'] )
			: array();

		$this->proxy->call_function(
			'set_transient',
			self::TEST_DRIVE_SETTINGS_FOR_LIVE_ACCOUNT_TRANSIENT,
			array(
				'capabilities'            => $this->get_test_drive_payment_method_capabilities( $enabled_payment_methods ),
				'enabled_payment_methods' => $enabled_payment_methods,
			),
			HOUR_IN_SECONDS
		);
	}

	/**
	 * Get the saved test-drive payment method IDs, normalized.
	 *
	 * @return string[]
	 */
	private function get_test_drive_enabled_payment_method_ids(): array {
		$settings = $this->proxy->call_function( 'get_transient', self::TEST_DRIVE_SETTINGS_FOR_LIVE_ACCOUNT_TRANSIENT );
		if ( ! is_array( $settings ) || ! is_array( $settings['enabled_payment_methods'] ?? null ) ) {
			return array();
		}

		return $this->normalize_test_drive_payment_method_ids( $settings['enabled_payment_methods'] );
	}

	/**
	 * Keep the lowercased payment method IDs the payment method registry knows.
	 *
	 * @param array<int,mixed> $payment_method_ids Payment method IDs.
	 * @return string[]
	 */
	private function normalize_test_drive_payment_method_ids( array $payment_method_ids ): array {
		$normalized_ids = array();

		foreach ( $payment_method_ids as $payment_method_id ) {
			if ( ! is_scalar( $payment_method_id ) ) {
				continue;
			}

			$payment_method_id = strtolower( trim( (string) $payment_method_id ) );
			if ( '' === $payment_method_id || sanitize_key( $payment_method_id ) !== $payment_method_id || null === $this->payment_method_registry->get( $payment_method_id ) ) {
				continue;
			}

			$normalized_ids[] = $payment_method_id;
		}

		return array_values( array_unique( $normalized_ids ) );
	}

	/**
	 * Build the platform's capability requests for the saved payment methods.
	 *
	 * @param string[] $payment_method_ids Payment method IDs.
	 * @return array<string,array{requested:string}>
	 */
	private function get_test_drive_payment_method_capabilities( array $payment_method_ids ): array {
		$capabilities = array();

		foreach ( $payment_method_ids as $payment_method_id ) {
			$definition = $this->payment_method_registry->get( $payment_method_id );
			if ( null !== $definition ) {
				$capabilities[ $definition->get_account_capability_key() ] = array( 'requested' => 'true' );
			}
		}

		return $capabilities;
	}

	/**
	 * Log an onboarding failure whatever the logging setting.
	 *
	 * @param string              $message Log message.
	 * @param array<string,mixed> $context Log context.
	 */
	private function log_onboarding_error( string $message, array $context = array() ): void {
		wc_get_container()->get( WooPaymentsLogger::class )->log_always( $message, 'error', $context );
	}

	/**
	 * Delete the connected account, reading as no account while the platform deletes it, then reset the store's account state.
	 *
	 * @param bool $test_mode Whether to delete a test-mode account.
	 * @return array
	 * @throws WooPaymentsApiException When account deletion fails.
	 */
	private function delete_account( bool $test_mode ): array {
		$this->get_account_service()->overwrite_cache_with_no_account();

		try {
			$response = $this->get_api_client()->delete_account( $test_mode );
		} catch ( WooPaymentsApiException $e ) {
			$this->get_account_service()->clear_cache();
			throw $e;
		}

		$success = 'success' === ( $response['result'] ?? '' );
		if ( $success ) {
			// Client 11.1.0 resets the store after both deletions (includes/class-wc-payments-onboarding-service.php:837, :905).
			$this->get_account_service()->cleanup_after_account_reset();
			// Its account.deleted webhook, sent during the delete, also clears these (includes/class-wc-payments-webhook-processing-service.php:209-221).
			// It cannot land here: with the account cache emptied above, the store no longer registers its webhook route.
			$this->proxy->call_function( 'delete_option', WooPaymentsService::NOX_PROFILE_OPTION_KEY );
			$this->proxy->call_function( 'delete_option', WooPaymentsService::NOX_ONBOARDING_LOCKED_KEY );
			wc_get_container()->get( WooPaymentsTokenService::class )->clear_all_cached_payment_methods();
		} else {
			$this->get_account_service()->clear_cache();
		}

		return array_merge(
			array(
				'success' => $success,
			),
			$response
		);
	}

	/**
	 * Get the WooPayments API client, resolved on first use.
	 *
	 * @return WooPaymentsApiClient
	 */
	private function get_api_client(): WooPaymentsApiClient {
		if ( null === $this->api_client ) {
			$this->api_client = wc_get_container()->get( WooPaymentsApiClient::class );
		}

		return $this->api_client;
	}

	/**
	 * Get the WooPayments account service, resolved on first use.
	 *
	 * @return WooPaymentsAccountService
	 */
	private function get_account_service(): WooPaymentsAccountService {
		if ( null === $this->account_service ) {
			$this->account_service = wc_get_container()->get( WooPaymentsAccountService::class );
		}

		return $this->account_service;
	}

	/**
	 * Get the site data sent with onboarding requests.
	 *
	 * @return array
	 */
	private function get_onboarding_site_data(): array {
		return array(
			'site_username' => wp_get_current_user()->user_login,
			'site_locale'   => get_locale(),
		);
	}

	/**
	 * Get the URL the platform returns the merchant to after the test-drive account request.
	 *
	 * @return string
	 */
	private function get_onboarding_return_url(): string {
		return Utils::wc_payments_settings_url( WooPaymentsPaymentsSettingsAdapter::OVERVIEW_PATH );
	}

	/**
	 * Get the user and browser data sent with onboarding requests.
	 *
	 * @return array
	 */
	private function get_onboarding_user_data(): array {
		return array(
			'user_id'           => get_current_user_id(),
			'ip_address'        => \WC_Geolocation::get_ip_address(),
			'browser'           => array(
				'user_agent'       => isset( $_SERVER['HTTP_USER_AGENT'] ) ? wc_clean( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '',
				'accept_language'  => isset( $_SERVER['HTTP_ACCEPT_LANGUAGE'] ) ? wc_clean( wp_unslash( $_SERVER['HTTP_ACCEPT_LANGUAGE'] ) ) : '',
				'content_language' => empty( get_user_locale() ) ? 'en-US' : str_replace( '_', '-', get_user_locale() ),
			),
			'referer'           => isset( $_SERVER['HTTP_REFERER'] ) ? esc_url_raw( wp_unslash( $_SERVER['HTTP_REFERER'] ) ) : '',
			'onboarding_source' => WooPaymentsService::SESSION_ENTRY_DEFAULT,
		);
	}

	/**
	 * Get the names of the last 10 actioned WooPayments promotion notes, which the platform uses to pick the accepted promotion.
	 *
	 * Mirrors client 11.1.0 WC_Payments_Onboarding_Service::get_actioned_notes(), but reads each name from the query row instead
	 * of loading every note again. A note store failure is logged and sends no notes, so onboarding is never blocked.
	 *
	 * @return string[]
	 */
	private function get_actioned_inbox_notes(): array {
		$add_like_clause = static function ( $where_clause ) {
			return $where_clause . " AND name LIKE 'wcpay-promo-%'";
		};

		try {
			/**
			 * The admin note data store, which WC_Data_Store forwards calls to.
			 *
			 * @var NotesDataStore $data_store
			 */
			$data_store = \WC_Data_Store::load( 'admin-note' );

			add_filter( 'woocommerce_note_where_clauses', $add_like_clause );
			try {
				$notes = $data_store->get_notes(
					array(
						'status'     => array( Note::E_WC_ADMIN_NOTE_ACTIONED ),
						'is_deleted' => false,
						'per_page'   => 10,
					)
				);
			} finally {
				remove_filter( 'woocommerce_note_where_clauses', $add_like_clause );
			}
		} catch ( \Throwable $e ) {
			// Logged only while WooPayments logging is on (client 11.1.0 `includes/class-wc-payments-onboarding-service.php:1029-1032`); a PHP Error is always logged.
			wc_get_container()->get( WooPaymentsLogger::class )->log_throwable( 'WooPayments could not read the actioned promotion notes for onboarding.', $e );

			return array();
		}

		if ( ! is_array( $notes ) ) {
			return array();
		}

		$note_names = array();
		foreach ( $notes as $note ) {
			if ( isset( $note->name ) && is_string( $note->name ) ) {
				$note_names[] = $note->name;
			}
		}

		return $note_names;
	}

	/**
	 * Build the account data sent with an account creation request.
	 *
	 * @param string $setup_mode           Setup mode.
	 * @param array  $self_assessment_data Self assessment data.
	 * @param array  $capabilities         Requested payment method capabilities.
	 * @return array
	 */
	private function build_account_creation_data( string $setup_mode, array $self_assessment_data, array $capabilities ): array {
		$home_url          = get_home_url();
		$home_is_localhost = 'localhost' === wp_parse_url( $home_url, PHP_URL_HOST );
		$fallback_url      = ( 'live' !== $setup_mode || $home_is_localhost ) ? 'https://wcpay.test' : null;
		$current_user      = wp_get_current_user();
		$account_data      = array(
			'setup_mode'    => $setup_mode,
			'country'       => WC()->countries->get_base_country() ?? null,
			'url'           => ! $home_is_localhost && wp_http_validate_url( $home_url ) ? $home_url : $fallback_url,
			'business_name' => get_bloginfo( 'name' ),
		);

		foreach ( $capabilities as $capability => $should_request ) {
			if ( ! is_string( $capability ) ) {
				continue;
			}

			if ( strlen( $capability ) >= 9 && '_payments' === substr( $capability, -9 ) ) {
				$capability = substr( $capability, 0, -9 );
			}

			if ( in_array( $capability, array( 'apple_google', 'woopay' ), true ) ) {
				continue;
			}

			if ( 'card' === $capability ) {
				$account_data['capabilities']['card_payments'] = array( 'requested' => 'true' );
				$account_data['capabilities']['transfers']     = array( 'requested' => 'true' );
				continue;
			}

			if ( $should_request ) {
				$account_data['capabilities'][ $capability . '_payments' ] = array( 'requested' => 'true' );
			}
		}

		if ( ! empty( $self_assessment_data ) ) {
			$business_type = $self_assessment_data['business_type'] ?? null;
			$account_data  = $this->array_merge_recursive_distinct(
				$account_data,
				array(
					'country'       => $self_assessment_data['country'] ?? null,
					'email'         => $self_assessment_data['email'] ?? null,
					'business_name' => $self_assessment_data['business_name'] ?? null,
					'url'           => $self_assessment_data['site'] ?? null,
					'mcc'           => $self_assessment_data['mcc'] ?? null,
					'business_type' => $business_type,
					'company'       => array(
						'structure' => 'company' === $business_type ? ( $self_assessment_data['company']['structure'] ?? null ) : null,
					),
					'individual'    => array(
						'first_name' => $self_assessment_data['individual']['first_name'] ?? null,
						'last_name'  => $self_assessment_data['individual']['last_name'] ?? null,
						'phone'      => $self_assessment_data['phone'] ?? null,
					),
				)
			);
		} elseif ( 'test_drive' === $setup_mode ) {
			$account_data = $this->array_merge_recursive_distinct(
				$account_data,
				array(
					'individual' => array(
						'first_name' => $current_user->first_name ?? null,
						'last_name'  => $current_user->last_name ?? null,
					),
				)
			);
		} elseif ( 'test' === $setup_mode ) {
			$account_data = $this->array_merge_recursive_distinct(
				$account_data,
				array(
					'business_type' => 'individual',
					'mcc'           => '5734',
					'individual'    => array(
						'first_name' => $current_user->first_name ?? null,
						'last_name'  => $current_user->last_name ?? null,
					),
				)
			);
		}

		return $account_data;
	}

	/**
	 * Shape the embedded KYC session for the Payments settings page.
	 *
	 * @param array $session The platform's KYC session.
	 * @return array
	 */
	private function normalize_kyc_session_response( array $session ): array {
		return array(
			'clientSecret'   => $session['client_secret'] ?? '',
			'expiresAt'      => $session['expires_at'] ?? 0,
			'accountId'      => $session['account_id'] ?? '',
			'isLive'         => $session['is_live'] ?? false,
			'accountCreated' => $session['account_created'] ?? false,
			'publishableKey' => $session['publishable_key'] ?? '',
			'locale'         => $this->proxy->call_function( 'get_user_locale' ),
		);
	}

	/**
	 * Tell whether an onboarding response created an account.
	 *
	 * @param array $response Platform response.
	 * @return bool
	 */
	private function is_account_created_response( array $response ): bool {
		return $this->get_response_bool( $response, array( 'account_created' ), false );
	}

	/**
	 * Cache the account from an onboarding response that carries an account ID and a publishable key, and set the
	 * gateway's test mode from it.
	 *
	 * Readiness the response omits comes from the same account's cached record. Payments otherwise read as disabled until
	 * the next account read succeeds: client 11.1.0 clears its cache here, so a failed read leaves it no account to take
	 * payments with (includes/class-wc-payments-onboarding-service.php:451, :796; and :405 for a new account's KYC session).
	 *
	 * @param array $response                  Platform response.
	 * @param bool  $is_live                   Whether the account is live.
	 * @param bool  $is_test_drive             Whether the account is a test-drive account.
	 * @param bool  $details_submitted_default Whether the details count as submitted when the response and the cache omit them.
	 * @return bool Whether account data was cached.
	 */
	private function sync_account_cache_from_response( array $response, bool $is_live, bool $is_test_drive, bool $details_submitted_default ): bool {
		$existing_cache  = $this->get_account_service()->get_cached_account_data();
		$publishable_key = $this->get_response_scalar(
			$response,
			array(
				'publishable_key',
				$is_live ? 'live_publishable_key' : 'test_publishable_key',
			),
			$existing_cache[ $is_live ? 'live_publishable_key' : 'test_publishable_key' ] ?? ''
		);
		$account_id      = $this->get_response_scalar(
			$response,
			array(
				'account_id',
			),
			$existing_cache['account_id'] ?? ''
		);

		if ( '' === $account_id || '' === $publishable_key ) {
			return false;
		}

		$existing_account_id                   = isset( $existing_cache['account_id'] ) && is_scalar( $existing_cache['account_id'] )
			? (string) $existing_cache['account_id']
			: '';
		$readiness_default                     = $account_id === $existing_account_id ? $existing_cache : array();
		$publishable_key_name                  = $is_live ? 'live_publishable_key' : 'test_publishable_key';
		$account_data                          = $existing_cache;
		$account_data['account_id']            = $account_id;
		$account_data[ $publishable_key_name ] = $publishable_key;
		$account_data['is_live']               = $is_live;
		$account_data['is_test_drive']         = $is_test_drive;
		$account_data['payments_enabled']      = $this->get_response_bool(
			$response,
			array(
				'payments_enabled',
			),
			$readiness_default['payments_enabled'] ?? false
		);
		$account_data['details_submitted']     = $this->get_response_bool(
			$response,
			array(
				'details_submitted',
			),
			$readiness_default['details_submitted'] ?? $details_submitted_default
		);

		$this->get_account_service()->cache_account_data_until_refreshed( $account_data );
		$this->set_gateway_test_mode( ! $is_live );

		return true;
	}

	/**
	 * Read the first scalar response value among the given keys.
	 *
	 * @param array    $response      Platform response.
	 * @param string[] $keys          Candidate keys.
	 * @param mixed    $default_value Default value.
	 * @return string
	 */
	private function get_response_scalar( array $response, array $keys, $default_value = '' ): string {
		foreach ( $keys as $key ) {
			if ( array_key_exists( $key, $response ) && is_scalar( $response[ $key ] ) ) {
				return (string) $response[ $key ];
			}
		}

		return is_scalar( $default_value ) ? (string) $default_value : '';
	}

	/**
	 * Read the first response boolean among the given keys.
	 *
	 * @param array    $response      Platform response.
	 * @param string[] $keys          Candidate keys.
	 * @param mixed    $default_value Default value.
	 * @return bool
	 */
	private function get_response_bool( array $response, array $keys, $default_value ): bool {
		foreach ( $keys as $key ) {
			if ( array_key_exists( $key, $response ) ) {
				return filter_var( $response[ $key ], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE ) ?? false;
			}
		}

		return filter_var( $default_value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE ) ?? false;
	}

	/**
	 * Write the onboarding test-mode option, autoloaded.
	 *
	 * @param bool $test_mode Whether test-mode onboarding is enabled.
	 * @return void
	 */
	private function set_onboarding_test_mode( bool $test_mode ): void {
		$this->proxy->call_function( 'update_option', 'wcpay_onboarding_test_mode', $test_mode ? 'yes' : 'no', true );
	}

	/**
	 * Write the gateway's test mode setting.
	 *
	 * @param bool $test_mode Whether gateway test mode is enabled.
	 * @return void
	 */
	private function set_gateway_test_mode( bool $test_mode ): void {
		$settings              = $this->proxy->call_function( 'get_option', WooPaymentsPersistenceVocabulary::GATEWAY_SETTINGS_OPTION, array() );
		$settings              = is_array( $settings ) ? $settings : array();
		$settings['test_mode'] = $test_mode ? 'yes' : 'no';

		// Keep the gateway settings autoloaded: the gateway reads them on every front-end request via WC_Payment_Gateway::init_settings().
		$this->proxy->call_function( 'update_option', WooPaymentsPersistenceVocabulary::GATEWAY_SETTINGS_OPTION, $settings );
	}

	/**
	 * Tell whether the onboarding test-mode option is on.
	 *
	 * @return bool
	 */
	private function is_onboarding_test_mode_enabled(): bool {
		return in_array( $this->proxy->call_function( 'get_option', 'wcpay_onboarding_test_mode', 'no' ), array( 'yes', '1' ), true );
	}

	/**
	 * Enable the gateway in the test-drive account's mode and apply the payment method picks.
	 *
	 * @param array $capabilities Capability request map.
	 * @param array $response     Platform response.
	 * @return void
	 */
	private function update_gateway_settings_after_test_account_init( array $capabilities, array $response ): void {
		$settings              = $this->proxy->call_function( 'get_option', WooPaymentsPersistenceVocabulary::GATEWAY_SETTINGS_OPTION, array() );
		$settings              = is_array( $settings ) ? $settings : array();
		$settings['enabled']   = 'yes';
		$settings['test_mode'] = $this->get_response_bool( $response, array( 'is_live' ), false ) ? 'no' : 'yes';

		// Keep the gateway settings autoloaded: the gateway reads them on every front-end request via WC_Payment_Gateway::init_settings().
		$this->proxy->call_function( 'update_option', WooPaymentsPersistenceVocabulary::GATEWAY_SETTINGS_OPTION, $settings );

		// Client 11.1.0 init_test_drive_account() applies the picks once the test-drive account exists.
		if ( ! empty( $capabilities ) ) {
			$this->apply_payment_method_picks( $capabilities );
		}

		$this->proxy->call_function( 'update_option', '_wcpay_onboarding_stripe_connected', array( 'is_existing_stripe_account' => true ), false );
	}

	/**
	 * Recursively filter empty values from an array.
	 *
	 * @param array $input The array to filter.
	 * @return array
	 */
	private function array_filter_recursive( array $input ): array {
		foreach ( $input as $key => &$value ) {
			if ( is_array( $value ) ) {
				$value = $this->array_filter_recursive( $value );
				if ( empty( $value ) ) {
					unset( $input[ $key ] );
				}
			} elseif ( empty( $value ) ) {
				unset( $input[ $key ] );
			}
		}
		unset( $value );

		return $input;
	}

	/**
	 * Recursively merge arrays while replacing string keys.
	 *
	 * @param array $base     Base array.
	 * @param array $override Override array.
	 * @return array
	 */
	private function array_merge_recursive_distinct( array $base, array $override ): array {
		foreach ( $override as $key => $value ) {
			if ( is_array( $value ) && isset( $base[ $key ] ) && is_array( $base[ $key ] ) ) {
				$base[ $key ] = $this->array_merge_recursive_distinct( $base[ $key ], $value );
				continue;
			}

			if ( null !== $value ) {
				$base[ $key ] = $value;
			}
		}

		return $base;
	}

	/**
	 * Turn a platform error into the WP_Error the Payments settings onboarding routes return.
	 *
	 * The data carries the platform's error code and error type
	 * (client 11.1.0 `includes/admin/class-wc-rest-payments-onboarding-controller.php:370-385`), so callers
	 * can tell non-recoverable errors from transient ones.
	 *
	 * @param WooPaymentsApiException $exception        API exception.
	 * @param string                  $fallback_message Fallback error message.
	 * @return WP_Error
	 */
	private function get_wp_error_from_api_exception( WooPaymentsApiException $exception, string $fallback_message ): WP_Error {
		$error_code    = $exception->get_error_code();
		$error_message = $exception->getMessage();

		return new WP_Error(
			'' !== $error_code ? $error_code : 'woocommerce_woopayments_onboarding_client_api_exception',
			'' !== $error_message ? $error_message : $fallback_message,
			array(
				'code'       => $exception->get_http_code(),
				'message'    => $error_message,
				'error_code' => $error_code,
				'error_type' => $exception->get_error_type(),
			)
		);
	}
}
