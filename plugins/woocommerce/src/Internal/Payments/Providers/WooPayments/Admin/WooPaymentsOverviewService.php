<?php
/**
 * WooPaymentsOverviewService class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Admin;

use Automattic\WooCommerce\Internal\Admin\Settings\PaymentsProviders\WooPayments\WooPaymentsService;
use Automattic\WooCommerce\Internal\Admin\Settings\Utils;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsAccountService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsDatabaseCache;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsPersistenceVocabulary;

defined( 'ABSPATH' ) || exit;

/**
 * Builds the WooPayments Overview page's data from the stored account data, and the account summary the WooPayments
 * pages read.
 *
 * The Overview data reads the stored account without a refresh; the summary reads through the account service, which
 * can refresh it.
 *
 * @since 11.0.0
 * @internal
 */
class WooPaymentsOverviewService {

	/**
	 * WooPayments account service.
	 *
	 * @var WooPaymentsAccountService
	 */
	private WooPaymentsAccountService $account_service;

	/**
	 * Initialize the class instance.
	 *
	 * @internal
	 *
	 * @param WooPaymentsAccountService $account_service WooPayments account service.
	 */
	final public function init( WooPaymentsAccountService $account_service ): void {
		$this->account_service = $account_service;
	}

	/**
	 * Get the Overview page's data.
	 *
	 * @return array<string,mixed>
	 */
	public function get_overview(): array {
		$this->prime_overview_option_caches();

		$account_data = $this->account_service->get_stored_account_data();

		return array(
			'account'                               => $this->get_account_summary_fields( $account_data ),
			'account_status'                        => $this->get_account_status_fields( $account_data ),
			'overview_tasks_visibility'             => $this->get_overview_tasks_visibility(),
			'is_connection_success_modal_dismissed' => $this->is_truthy( get_option( 'wcpay_connection_success_modal_dismissed', false ) ),
			'disputes_awaiting_response_count'      => $this->get_cached_disputes_awaiting_response_count(),
			'account_details'                       => $this->get_account_details( $account_data ),
			'account_fees'                          => $this->get_active_account_fees( $account_data ),
			'feature_flags'                         => $this->get_feature_flags(),
			'account_loans'                         => $this->get_capital_loans( $account_data ),
			// Client 11.1.0 `class-wc-payments-admin.php:1062` localizes this option as `instantDepositsPreviouslyEligible`.
			'instant_deposits_previously_eligible'  => $this->is_truthy( get_option( 'wcpay_instant_deposits_previously_eligible', false ) ),
			// Client 11.1.0 `class-wc-payments-admin.php:1061` localizes this option as `isInstantDepositNoticeDismissed`.
			'is_instant_deposit_notice_dismissed'   => $this->is_truthy( get_option( 'wcpay_instant_deposit_notice_dismissed', false ) ),
			'urls'                                  => array(
				'overview_page' => Utils::wc_payments_settings_url( '/woopayments/overview' ),
				'settings'      => Utils::wc_payments_settings_url( '/woopayments/settings' ),
				'onboarding'    => Utils::wc_payments_settings_url( '/woopayments/onboarding' ),
				'setup'         => Utils::wc_payments_settings_url( '/woopayments/onboarding' ),
			),
		);
	}

	/**
	 * Get the account summary the WooPayments pages read: the account's state, its Documents fields and the setup link.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	public function get_account_summary(): array {
		$account_service = $this->account_service;

		return array(
			'account'   => array(
				'connected'  => $account_service->has_account(),
				'test_drive' => $account_service->has_test_account(),
				'sandbox'    => $account_service->has_sandbox_account(),
				'live'       => $account_service->has_live_account(),
			),
			'documents' => array(
				'enabled'                => $account_service->is_documents_enabled(),
				'has_submitted_vat_data' => $account_service->has_submitted_vat_data(),
				'country'                => $account_service->get_account_country(),
			),
			'urls'      => array(
				// Client 11.1.0's Activate payments modal sends a test or sandbox account to onboarding (`sandbox-mode-switch-to-live-notice/modal/index.tsx:39-45`).
				'setup' => Utils::wc_payments_settings_url( WooPaymentsService::ONBOARDING_PATH_BASE ),
			),
		);
	}

	/**
	 * Get safe account fields needed by the Overview shell.
	 *
	 * @param array<string,mixed> $account_data Stored account data.
	 * @return array<string,mixed>
	 */
	private function get_account_summary_fields( array $account_data ): array {
		$account_id = $this->get_account_id( $account_data );
		$test_mode  = $this->account_service->is_test_mode_enabled();

		return array(
			'id'                   => $account_id,
			'mode'                 => $test_mode ? 'test' : 'live',
			'connected'            => '' !== $account_id,
			'working'              => '' !== $account_id && $this->is_truthy( $account_data['payments_enabled'] ?? false ),
			'details_submitted'    => $this->is_truthy( $account_data['details_submitted'] ?? false ),
			'test_mode'            => $test_mode,
			'test_mode_onboarding' => in_array( get_option( 'wcpay_onboarding_test_mode', 'no' ), array( 'yes', '1' ), true ),
			'dev_mode'             => $this->account_service->is_dev_mode_enabled(),
			'test_drive'           => '' !== $account_id && $this->is_truthy( $account_data['is_test_drive'] ?? false ),
			'sandbox'              => '' !== $account_id
				&& array_key_exists( 'is_live', $account_data )
				&& ! $this->is_truthy( $account_data['is_live'] )
				&& ! $this->is_truthy( $account_data['is_test_drive'] ?? false ),
			'live'                 => '' !== $account_id && $this->is_truthy( $account_data['is_live'] ?? false ),
		);
	}

	/**
	 * Get safe account status fields needed by Overview tasks.
	 *
	 * @param array<string,mixed> $account_data Cached account data.
	 * @return array<string,mixed>
	 */
	private function get_account_status_fields( array $account_data ): array {
		return array(
			'status'            => $this->get_account_status( $account_data ),
			'current_deadline'  => $this->get_current_deadline( $account_data ),
			'past_due'          => $this->has_past_due_requirements( $account_data ),
			'account_link'      => $this->get_dashboard_login_url( $account_data ),
			'requirements'      => array(
				'errors' => $this->get_requirement_errors( $account_data ),
			),
			'details_submitted' => $this->is_truthy( $account_data['details_submitted'] ?? false ),
			'payments_enabled'  => $this->is_truthy( $account_data['payments_enabled'] ?? false ),
			'deposits_enabled'  => $this->are_deposits_enabled( $account_data ),
			'deposits'          => $this->get_payouts_status( $account_data ),
		);
	}

	/**
	 * Get the cached account's payout fields that the Overview payouts card reads.
	 *
	 * Client 11.1.0 `class-wc-payments-account.php:378` passes the cached `deposits` object as `accountStatus.deposits`,
	 * and `components/deposits-overview/index.tsx:82-108` reads these three fields from it. Bank account details stay out.
	 *
	 * @param array<string,mixed> $account_data Cached account data.
	 * @return array{restrictions:string,completed_waiting_period:bool,minimum_scheduled_deposit_amounts:array<string,int>}
	 */
	private function get_payouts_status( array $account_data ): array {
		$deposits        = is_array( $account_data['deposits'] ?? null ) ? $account_data['deposits'] : array();
		$minimum_amounts = array();

		if ( is_array( $deposits['minimum_scheduled_deposit_amounts'] ?? null ) ) {
			foreach ( $deposits['minimum_scheduled_deposit_amounts'] as $currency => $amount ) {
				if ( is_string( $currency ) && is_numeric( $amount ) ) {
					$minimum_amounts[ $currency ] = (int) $amount;
				}
			}
		}

		return array(
			'restrictions'                      => $this->get_scalar( $deposits['restrictions'] ?? '' ),
			'completed_waiting_period'          => $this->is_truthy( $deposits['completed_waiting_period'] ?? false ),
			'minimum_scheduled_deposit_amounts' => $minimum_amounts,
		);
	}

	/**
	 * Tell whether the platform reports payouts as enabled.
	 *
	 * Client 11.1.0 `overview/index.js:118` reads `accountStatus.deposits?.status === 'enabled'`, which
	 * `class-wc-payments-account.php:378` fills from the cached account's `deposits` object.
	 *
	 * @param array<string,mixed> $account_data Cached account data.
	 * @return bool
	 */
	private function are_deposits_enabled( array $account_data ): bool {
		$deposits = is_array( $account_data['deposits'] ?? null ) ? $account_data['deposits'] : array();

		return 'enabled' === $this->get_scalar( $deposits['status'] ?? '' );
	}

	/**
	 * Get the Stripe dashboard login URL that `WooPaymentsAdminLinkController::handle_login_request()` serves.
	 *
	 * Client 11.1.0 `class-wc-payments-account.php:382,1888-1895`: test-drive accounts have no dashboard access, so they get no link.
	 *
	 * @param array<string,mixed> $account_data Cached account data.
	 * @return string
	 */
	private function get_dashboard_login_url( array $account_data ): string {
		if ( '' === $this->get_account_id( $account_data ) || $this->is_truthy( $account_data['is_test_drive'] ?? false ) ) {
			return '';
		}

		return Utils::wc_payments_settings_url(
			'/woopayments/overview',
			array(
				'wcpay-login' => '1',
				'_wpnonce'    => wp_create_nonce( 'wcpay-login' ),
			)
		);
	}

	/**
	 * Get the account status: the platform's status when the stored data has one, else one derived from the details and
	 * requirements.
	 *
	 * @param array<string,mixed> $account_data Cached account data.
	 * @return string
	 */
	private function get_account_status( array $account_data ): string {
		if ( array() === $account_data ) {
			return 'not_connected';
		}

		$explicit_status = $this->get_scalar( $account_data['status'] ?? '' );
		if ( '' !== $explicit_status ) {
			return $explicit_status;
		}

		if ( ! $this->is_truthy( $account_data['details_submitted'] ?? false ) ) {
			return 'restricted';
		}

		if ( $this->has_past_due_requirements( $account_data ) ) {
			return 'restricted';
		}

		return 'complete';
	}

	/**
	 * Get the current requirements deadline.
	 *
	 * @param array<string,mixed> $account_data Cached account data.
	 * @return int|null
	 */
	private function get_current_deadline( array $account_data ): ?int {
		$deadline = $account_data['current_deadline'] ?? null;

		if ( ! is_numeric( $deadline ) ) {
			return null;
		}

		$deadline = (int) $deadline;

		return $deadline > 0 ? $deadline : null;
	}

	/**
	 * Tell whether cached account data has past-due requirements.
	 *
	 * @param array<string,mixed> $account_data Cached account data.
	 * @return bool
	 */
	private function has_past_due_requirements( array $account_data ): bool {
		return $this->is_truthy( $account_data['has_overdue_requirements'] ?? false );
	}

	/**
	 * Get task visibility options in the Overview response shape.
	 *
	 * @return array<string,mixed>
	 */
	private function get_overview_tasks_visibility(): array {
		return array(
			'dismissed_todo_tasks'       => $this->get_string_list_option( 'woocommerce_dismissed_todo_tasks' ),
			'deleted_todo_tasks'         => $this->get_string_list_option( 'woocommerce_deleted_todo_tasks' ),
			'remind_me_later_todo_tasks' => $this->get_remind_me_later_tasks_option(),
		);
	}

	/**
	 * Prime the option caches get_overview() reads.
	 *
	 * @return void
	 */
	private function prime_overview_option_caches(): void {
		// Prime caches to reduce future queries.
		wp_prime_option_caches(
			array(
				WooPaymentsDatabaseCache::ACCOUNT_KEY,
				WooPaymentsPersistenceVocabulary::GATEWAY_SETTINGS_OPTION,
				'wcpay_onboarding_test_mode',
				WooPaymentsDatabaseCache::DISPUTE_STATUS_COUNTS_KEY,
				WooPaymentsDatabaseCache::DISPUTE_STATUS_COUNTS_KEY_TEST_MODE,
				'woocommerce_dismissed_todo_tasks',
				'woocommerce_deleted_todo_tasks',
				'woocommerce_remind_me_later_todo_tasks',
				'wcpay_connection_success_modal_dismissed',
				'_wcpay_feature_dispute_readiness_overview',
				'wcpay_instant_deposits_previously_eligible',
				'wcpay_instant_deposit_notice_dismissed',
			)
		);
	}

	/**
	 * Get the AccountDetails card data from the stored account data.
	 *
	 * @param array<string,mixed> $account_data Stored account data.
	 * @return array<string,mixed>|null
	 */
	private function get_account_details( array $account_data ): ?array {
		$account_details = $account_data['account_details'] ?? null;

		if (
			! is_array( $account_details )
			|| ! is_array( $account_details['account_status'] ?? null )
			|| ! is_array( $account_details['payout_status'] ?? null )
			|| ! array_key_exists( 'banner', $account_details )
		) {
			return null;
		}

		return array(
			'account_status' => $this->sanitize_scalar_array( $account_details['account_status'] ),
			'payout_status'  => $this->sanitize_scalar_array( $account_details['payout_status'] ),
			'banner'         => is_array( $account_details['banner'] ) ? $this->sanitize_scalar_array( $account_details['banner'] ) : null,
		);
	}

	/**
	 * Get active discounted account fee rows for enabled payment methods, in the account's fee order, as client 11.1.0
	 * `client/overview/index.js:146-163` builds them.
	 *
	 * @param array<string,mixed> $account_data Stored account data.
	 * @return array<int,array{payment_method:string,fee:array<string,mixed>}>
	 */
	private function get_active_account_fees( array $account_data ): array {
		$fees        = is_array( $account_data['fees'] ?? null ) ? $account_data['fees'] : array();
		$enabled_ids = $this->get_enabled_payment_method_ids();
		$active_fees = array();

		foreach ( $fees as $payment_method_id => $fee_structure ) {
			$payment_method_id = (string) $payment_method_id;
			if ( ! in_array( $payment_method_id, $enabled_ids, true ) || ! is_array( $fee_structure ) ) {
				continue;
			}

			if ( ! $this->has_non_empty_array( $fee_structure['discount'] ?? array() ) ) {
				continue;
			}

			$active_fees[] = array(
				'payment_method' => $payment_method_id,
				'fee'            => $this->sanitize_scalar_array( $fee_structure ),
			);
		}

		return $active_fees;
	}

	/**
	 * Get enabled WooPayments method IDs from gateway settings.
	 *
	 * @return string[]
	 */
	private function get_enabled_payment_method_ids(): array {
		$enabled_ids = $this->account_service->get_gateway_setting( 'upe_enabled_payment_method_ids' );
		$enabled_ids = is_array( $enabled_ids ) ? $enabled_ids : array( 'card' );

		$sanitized_ids = array();
		foreach ( $enabled_ids as $enabled_id ) {
			$enabled_id = $this->get_scalar( $enabled_id );
			if ( '' !== $enabled_id ) {
				$sanitized_ids[] = $enabled_id;
			}
		}

		return array_values( array_unique( $sanitized_ids ) );
	}

	/**
	 * Get Overview feature flags.
	 *
	 * @return array<string,bool>
	 */
	private function get_feature_flags(): array {
		return array(
			'dispute_readiness_overview' => $this->is_truthy( get_option( '_wcpay_feature_dispute_readiness_overview', '1' ) ),
		);
	}

	/**
	 * Get the Overview's Capital loan data.
	 *
	 * `loans` keeps the platform's `<loan id>|<status>` strings, which client 11.1.0 reads from `wcpaySettings.accountLoans.loans`
	 * to link the active loan's transactions (`components/active-loan-summary/index.tsx:114-123`).
	 *
	 * @param array<string,mixed> $account_data Stored account data.
	 * @return array{has_active_loan:bool,loans:string[]}
	 */
	private function get_capital_loans( array $account_data ): array {
		$account_loans = $account_data['capital'] ?? array();
		$account_loans = is_array( $account_loans ) ? $account_loans : array();
		$loans         = array();

		foreach ( is_array( $account_loans['loans'] ?? null ) ? $account_loans['loans'] : array() as $loan ) {
			$loan = $this->get_scalar( $loan );
			if ( '' !== $loan ) {
				$loans[] = $loan;
			}
		}

		return array(
			'has_active_loan' => $this->is_truthy( $account_loans['has_active_loan'] ?? false ),
			'loans'           => $loans,
		);
	}

	/**
	 * Get the account ID from the stored account data.
	 *
	 * @param array<string,mixed> $account_data Stored account data.
	 * @return string
	 */
	private function get_account_id( array $account_data ): string {
		return $this->get_scalar( $account_data['account_id'] ?? '' );
	}

	/**
	 * Get the cached actionable dispute count without refreshing the platform-backed cache.
	 *
	 * @return int|null
	 */
	private function get_cached_disputes_awaiting_response_count(): ?int {
		$cache_key      = $this->account_service->is_test_mode_enabled()
			? WooPaymentsDatabaseCache::DISPUTE_STATUS_COUNTS_KEY_TEST_MODE
			: WooPaymentsDatabaseCache::DISPUTE_STATUS_COUNTS_KEY;
		$cache_contents = get_option( $cache_key, false );
		$counts         = is_array( $cache_contents ) && is_array( $cache_contents['data'] ?? null )
			? $cache_contents['data']
			: null;

		if ( ! is_array( $counts ) ) {
			return null;
		}

		return max(
			0,
			(int) ( $counts['needs_response'] ?? 0 ) + (int) ( $counts['warning_needs_response'] ?? 0 )
		);
	}

	/**
	 * Get normalized requirement errors from cached account data.
	 *
	 * @param array<string,mixed> $account_data Cached account data.
	 * @return array<int,array<string,string>>
	 */
	private function get_requirement_errors( array $account_data ): array {
		$requirements = is_array( $account_data['requirements'] ?? null ) ? $account_data['requirements'] : array();
		$errors       = is_array( $requirements['errors'] ?? null ) ? $requirements['errors'] : array();
		$normalized   = array();

		foreach ( $errors as $error ) {
			if ( ! is_array( $error ) ) {
				continue;
			}

			$normalized_error = array();
			foreach ( array( 'code', 'reason', 'requirement' ) as $key ) {
				$value = $this->get_scalar( $error[ $key ] ?? '' );
				if ( '' !== $value ) {
					$normalized_error[ $key ] = $value;
				}
			}

			if ( ! empty( $normalized_error ) ) {
				$normalized[] = $normalized_error;
			}
		}

		return $normalized;
	}

	/**
	 * Get a string-list option.
	 *
	 * @param string $option_name Option name.
	 * @return array<int,string>
	 */
	private function get_string_list_option( string $option_name ): array {
		$value = get_option( $option_name, array() );
		if ( ! is_array( $value ) ) {
			return array();
		}

		$strings = array();
		foreach ( $value as $item ) {
			if ( is_scalar( $item ) ) {
				$strings[] = (string) $item;
			}
		}

		return $strings;
	}

	/**
	 * Get the remind-me-later task timestamp map.
	 *
	 * @return array<string,int>
	 */
	private function get_remind_me_later_tasks_option(): array {
		$value = get_option( 'woocommerce_remind_me_later_todo_tasks', array() );
		if ( ! is_array( $value ) ) {
			return array();
		}

		$tasks = array();
		foreach ( $value as $task_key => $timestamp ) {
			if ( ! is_numeric( $timestamp ) ) {
				continue;
			}

			$tasks[ (string) $task_key ] = (int) $timestamp;
		}

		return $tasks;
	}

	/**
	 * Tell whether a value is a non-empty array.
	 *
	 * @param mixed $value Value.
	 * @return bool
	 */
	private function has_non_empty_array( $value ): bool {
		return is_array( $value ) && ! empty( $value );
	}

	/**
	 * Get a scalar value as a string.
	 *
	 * @param mixed $value Value.
	 * @return string
	 */
	private function get_scalar( $value ): string {
		return is_scalar( $value ) ? (string) $value : '';
	}

	/**
	 * Recursively keep scalar/null array values only.
	 *
	 * @param array<mixed> $value Raw array.
	 * @return array<mixed>
	 */
	private function sanitize_scalar_array( array $value ): array {
		$sanitized = array();

		foreach ( $value as $key => $item ) {
			if ( is_array( $item ) ) {
				$sanitized[ $key ] = $this->sanitize_scalar_array( $item );
				continue;
			}

			if ( is_scalar( $item ) || null === $item ) {
				$sanitized[ $key ] = $item;
			}
		}

		return $sanitized;
	}

	/**
	 * Normalize boolean-like values.
	 *
	 * @param mixed $value Raw boolean-like value.
	 * @return bool
	 */
	private function is_truthy( $value ): bool {
		return filter_var( $value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE ) ?? false;
	}
}
