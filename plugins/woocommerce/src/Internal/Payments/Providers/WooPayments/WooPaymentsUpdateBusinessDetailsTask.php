<?php
/**
 * WooPaymentsUpdateBusinessDetailsTask class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\WooPayments;

use Automattic\WooCommerce\Admin\Features\OnboardingTasks\Task;
use Automattic\WooCommerce\Admin\Features\OnboardingTasks\TaskList;
use Automattic\WooCommerce\Internal\Admin\Settings\PaymentsProviders\WooPayments\WooPaymentsService;
use Automattic\WooCommerce\Internal\Admin\Settings\Utils;

defined( 'ABSPATH' ) || exit;

/**
 * The WC Home "Things to do next" task that asks a restricted WooPayments account to update its business details.
 *
 * Ports client 11.1.0: `get_should_show_update_business_details_task()` (`class-wc-payments-admin.php:1222-1231`) decides
 * visibility, and `getUpdateBusinessDetailsTask()` (`client/overview/task-list/tasks/update-business-details-task.tsx`),
 * added to the extended list through `woocommerce_admin_onboarding_task_list` (`client/index.js:391-405`), gives the copy.
 *
 * @since 11.2.0
 * @internal Transitional internal component for the native payments runtime.
 */
class WooPaymentsUpdateBusinessDetailsTask extends Task {

	/**
	 * Task ID when the account submitted its details.
	 */
	public const ID_UPDATE_DETAILS = 'update-business-details';

	/**
	 * Task ID when the account never submitted its details.
	 */
	public const ID_COMPLETE_SETUP = 'complete-setup';

	/**
	 * Stripe requirement error codes the client never shows (client `tasks.tsx` `requirementBlacklist`).
	 */
	private const HIDDEN_REQUIREMENT_ERRORS = array( 'invalid_value_other' );

	/**
	 * WooPayments account service.
	 *
	 * @var WooPaymentsAccountService
	 */
	private WooPaymentsAccountService $account_service;

	/**
	 * Account data read once per request.
	 *
	 * @var array<string,mixed>|null
	 */
	private ?array $account_data = null;

	/**
	 * Constructor.
	 *
	 * @param TaskList|null             $task_list       Parent task list.
	 * @param WooPaymentsAccountService $account_service WooPayments account service.
	 */
	public function __construct( $task_list, WooPaymentsAccountService $account_service ) {
		parent::__construct( $task_list );
		$this->account_service = $account_service;
	}

	/**
	 * ID.
	 *
	 * @return string
	 */
	public function get_id() {
		return $this->is_details_submitted() ? self::ID_UPDATE_DETAILS : self::ID_COMPLETE_SETUP;
	}

	/**
	 * Title.
	 *
	 * @return string
	 */
	public function get_title() {
		return $this->is_details_submitted()
			/* translators: %s: WooPayments */
			? sprintf( __( 'Update %s business details', 'woocommerce' ), 'WooPayments' )
			/* translators: %s: WooPayments */
			: sprintf( __( 'Finish setting up %s', 'woocommerce' ), 'WooPayments' );
	}

	/**
	 * Content.
	 *
	 * The client maps known Stripe error codes to its own sentences; native uses the error's reason, the client's fallback.
	 *
	 * @return string
	 */
	public function get_content() {
		$account        = $this->get_account_data();
		$error_messages = $this->get_error_messages();
		$single_error   = 1 === count( $error_messages ) ? $error_messages[0] : '';
		$deadline       = $this->get_current_deadline();

		if ( 'restricted_soon' === $this->get_status() && null !== $deadline ) {
			$update_by = sprintf(
				/* translators: %s - formatted requirements current deadline (date) */
				__( 'Update by %s to avoid a disruption in payouts.', 'woocommerce' ),
				wp_date( 'ga M j, Y', $deadline )
			);

			return '' !== $single_error ? $single_error . ' ' . $update_by : $update_by;
		}

		if ( 'restricted' === $this->get_status() && ! empty( $account['has_overdue_requirements'] ) ) {
			if ( '' !== $single_error ) {
				return $single_error;
			}

			return $this->is_details_submitted()
				? __( 'Payments and payouts are disabled for this account until missing business information is updated.', 'woocommerce' )
				: __( 'Payments and payouts are disabled for this account until setup is completed.', 'woocommerce' );
		}

		return '';
	}

	/**
	 * Time.
	 *
	 * @return string
	 */
	public function get_time() {
		return '';
	}

	/**
	 * Action label.
	 *
	 * @return string
	 */
	public function get_action_label() {
		if ( count( $this->get_error_messages() ) > 1 ) {
			return __( 'More details', 'woocommerce' );
		}

		return $this->is_details_submitted() ? __( 'Update', 'woocommerce' ) : __( 'Finish setup', 'woocommerce' );
	}

	/**
	 * Action URL.
	 *
	 * The client opens a modal for several errors and the Stripe dashboard in a new tab; a Home task can only
	 * navigate, so several errors go to Overview, whose task opens the same modal, and the dashboard opens in place.
	 *
	 * @return string
	 */
	public function get_action_url() {
		if ( count( $this->get_error_messages() ) > 1 ) {
			return Utils::wc_payments_settings_url( WooPaymentsService::OVERVIEW_PATH );
		}

		if ( ! $this->is_details_submitted() ) {
			return Utils::wc_payments_settings_url(
				WooPaymentsService::ONBOARDING_PATH_BASE,
				array(
					'source' => 'wcpay-finish-setup-task',
					'from'   => 'WCPAY_OVERVIEW',
				)
			);
		}

		// Test-drive accounts have no dashboard access (client `get_account_status_data()` `accountLink`).
		if ( ! empty( $this->get_account_data()['is_test_drive'] ) ) {
			return Utils::wc_payments_settings_url( WooPaymentsService::OVERVIEW_PATH );
		}

		// The login link that `LegacyAdminLinkHandler::handle_login_request()` serves, with the client's attribution args.
		return Utils::wc_payments_settings_url(
			WooPaymentsService::OVERVIEW_PATH,
			array(
				'wcpay-login' => '1',
				'_wpnonce'    => wp_create_nonce( 'wcpay-login' ),
				'from'        => 'WCPAY_OVERVIEW',
				'source'      => 'wcpay-update-business-details-task',
			)
		);
	}

	/**
	 * The task shows while the account is restricted soon with a deadline, or restricted with overdue requirements.
	 *
	 * @return bool
	 */
	public function can_view() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return false;
		}

		$account = $this->get_account_data();
		// Client `get_account_status_data()` reports an error, and so no status, without these fields.
		if ( ! isset( $account['status'], $account['payments_enabled'] ) ) {
			return false;
		}

		$status = $this->get_status();

		return ( 'restricted_soon' === $status && ! empty( $account['current_deadline'] ) )
			|| ( 'restricted' === $status && ! empty( $account['has_overdue_requirements'] ) );
	}

	/**
	 * The task stays until the account is no longer restricted; the client never lets it be dismissed.
	 *
	 * @return bool
	 */
	public function is_complete() {
		return in_array( $this->get_status(), array( 'complete', 'enabled' ), true );
	}

	/**
	 * Get the cached account data without triggering a refresh.
	 *
	 * @return array<string,mixed>
	 */
	private function get_account_data(): array {
		if ( null === $this->account_data ) {
			$this->account_data = $this->account_service->get_preserved_account_data_snapshot();
		}

		return $this->account_data;
	}

	/**
	 * Get the account status.
	 *
	 * @return string
	 */
	private function get_status(): string {
		$status = $this->get_account_data()['status'] ?? '';

		return is_string( $status ) ? $status : '';
	}

	/**
	 * Tell whether the account submitted its details; the client assumes it did when the field is missing.
	 *
	 * @return bool
	 */
	private function is_details_submitted(): bool {
		return (bool) ( $this->get_account_data()['details_submitted'] ?? true );
	}

	/**
	 * Get the current requirements deadline timestamp.
	 *
	 * @return int|null
	 */
	private function get_current_deadline(): ?int {
		$deadline = $this->get_account_data()['current_deadline'] ?? null;

		return is_numeric( $deadline ) && (int) $deadline > 0 ? (int) $deadline : null;
	}

	/**
	 * Get the unique requirement error messages the client would show.
	 *
	 * @return array<int,string>
	 */
	private function get_error_messages(): array {
		$errors   = $this->get_account_data()['requirements']['errors'] ?? array();
		$messages = array();

		foreach ( is_array( $errors ) ? $errors : array() as $error ) {
			if ( ! is_array( $error ) || in_array( $error['code'] ?? '', self::HIDDEN_REQUIREMENT_ERRORS, true ) ) {
				continue;
			}

			$reason  = $error['reason'] ?? '';
			$code    = $error['code'] ?? '';
			$message = is_string( $reason ) && '' !== $reason ? $reason : ( is_string( $code ) ? $code : '' );
			if ( '' !== $message ) {
				$messages[] = $message;
			}
		}

		return array_values( array_unique( $messages ) );
	}
}
