<?php
/**
 * WooPaymentsDisputesTask class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\WooPayments;

use Automattic\WooCommerce\Admin\Features\OnboardingTasks\Task;
use Automattic\WooCommerce\Admin\Features\OnboardingTasks\TaskList;
use Automattic\WooCommerce\Internal\Admin\Settings\Utils;
use Automattic\WooCommerce\Internal\MultiCurrency\Services\MultiCurrencyLocalizationService;
use DateTime;
use DateTimeZone;

defined( 'ABSPATH' ) || exit;

/**
 * The WC Home "Things to do next" task that asks the merchant to respond to disputes due within a week.
 *
 * Ports client 11.1.0 `WC_Payments_Task_Disputes` (`includes/admin/tasks/class-wc-payments-task-disputes.php`),
 * reading the same cached list of disputes awaiting a response.
 *
 * @since 11.2.0
 * @internal Transitional internal component for the native payments runtime.
 */
class WooPaymentsDisputesTask extends Task {

	/**
	 * Task ID, shared with the client.
	 */
	public const ID = 'woocommerce_payments_disputes_task';

	private const PATH_DISPUTES = '/woopayments/disputes';

	private const PATH_TRANSACTION_DETAILS = '/woopayments/transactions/details';

	/**
	 * WooPayments account service.
	 *
	 * @var WooPaymentsAccountService
	 */
	private WooPaymentsAccountService $account_service;

	/**
	 * Service that owns the cached dispute data.
	 *
	 * @var WooPaymentsAdminMenuBadgeService
	 */
	private WooPaymentsAdminMenuBadgeService $dispute_data_service;

	/**
	 * Disputes awaiting a response, read once per request.
	 *
	 * @var array<int,array<string,mixed>>|null
	 */
	private ?array $active_disputes = null;

	/**
	 * Constructor.
	 *
	 * @param TaskList|null                    $task_list            Parent task list.
	 * @param WooPaymentsAccountService        $account_service      WooPayments account service.
	 * @param WooPaymentsAdminMenuBadgeService $dispute_data_service Service that owns the cached dispute data.
	 */
	public function __construct( $task_list, WooPaymentsAccountService $account_service, WooPaymentsAdminMenuBadgeService $dispute_data_service ) {
		parent::__construct( $task_list );
		$this->account_service      = $account_service;
		$this->dispute_data_service = $dispute_data_service;
	}

	/**
	 * ID.
	 *
	 * @return string
	 */
	public function get_id() {
		return self::ID;
	}

	/**
	 * Title.
	 *
	 * @return string
	 */
	public function get_title() {
		$due_within_7d = $this->get_disputes_due_within_days( 7 );
		if ( 1 === count( $due_within_7d ) ) {
			$amount = $this->format_amount( (int) ( $due_within_7d[0]['amount'] ?? 0 ), (string) ( $due_within_7d[0]['currency'] ?? '' ) );

			if ( count( $this->get_disputes_due_within_days( 1 ) ) > 0 ) {
				/* translators: %s is a currency formatted amount */
				return sprintf( __( 'Respond to a dispute for %s – Last day', 'woocommerce' ), $amount );
			}

			/* translators: %s is a currency formatted amount */
			return sprintf( __( 'Respond to a dispute for %s', 'woocommerce' ), $amount );
		}

		$active_disputes = $this->get_active_disputes();
		if ( 0 === count( $active_disputes ) ) {
			return '';
		}

		$currencies = array_values( array_unique( array_column( $active_disputes, 'currency' ) ) );
		if ( count( $currencies ) > 1 ) {
			/* translators: %d is a number greater than 1. */
			return sprintf( __( 'Respond to %d active disputes', 'woocommerce' ), count( $active_disputes ) );
		}

		$total = 0;
		foreach ( $active_disputes as $dispute ) {
			$total += (int) ( $dispute['amount'] ?? 0 );
		}

		return sprintf(
			/* translators: %1$d is a number greater than 1. %2$s is a formatted amount, eg: $10.00 */
			__( 'Respond to %1$d active disputes for a total of %2$s', 'woocommerce' ),
			count( $active_disputes ),
			$this->format_amount( $total, (string) ( $currencies[0] ?? '' ) )
		);
	}

	/**
	 * Content; the client puts the due-date copy in the additional info instead.
	 *
	 * @return string
	 */
	public function get_content() {
		return '';
	}

	/**
	 * Additional info: when the disputes are due.
	 *
	 * @return string
	 */
	public function get_additional_info() {
		$due_within_7d = $this->get_disputes_due_within_days( 7 );
		$due_within_1d = $this->get_disputes_due_within_days( 1 );

		if ( 1 === count( $due_within_7d ) ) {
			$local_timezone    = new DateTimeZone( wp_timezone_string() );
			$due_by_local_time = ( new DateTime( (string) $due_within_7d[0]['due_by'], new DateTimeZone( 'UTC' ) ) )->setTimezone( $local_timezone );
			// The client formats the local wall-clock time as a timestamp with the offset added.
			$due_by_ts = $due_by_local_time->getTimestamp() + $due_by_local_time->getOffset();

			if ( count( $due_within_1d ) > 0 ) {
				/* translators: %s is time, eg: 11:59 PM */
				return sprintf( __( 'Respond today by %s', 'woocommerce' ), date_i18n( wc_time_format(), $due_by_ts ) );
			}

			$days_left = (int) ( new DateTime( 'now', $local_timezone ) )->diff( $due_by_local_time )->days;

			return sprintf(
				/* translators: %1$s is a date, eg: Jan 1, 2021. %2$s is the number of days left, eg: 2 days. */
				__( 'By %1$s – %2$s left to respond', 'woocommerce' ),
				date_i18n( wc_date_format(), $due_by_ts ),
				/* translators: %d is the number of days left, e.g. 1 day. */
				sprintf( _n( '%d day', '%d days', $days_left, 'woocommerce' ), $days_left )
			);
		}

		if ( count( $due_within_1d ) > 0 ) {
			/* translators: %d is the number of disputes. */
			return sprintf( __( 'Final day to respond to %d of the disputes', 'woocommerce' ), count( $due_within_1d ) );
		}

		/* translators: %d is the number of disputes. */
		return sprintf( __( 'Last week to respond to %d of the disputes', 'woocommerce' ), count( $due_within_7d ) );
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
	 * Action URL: the transaction of a single due dispute, otherwise the disputes awaiting a response.
	 *
	 * @return string
	 */
	public function get_action_url() {
		$due_within_7d = $this->get_disputes_due_within_days( 7 );
		if ( 1 === count( $due_within_7d ) ) {
			return Utils::wc_payments_settings_url( self::PATH_TRANSACTION_DETAILS, array( 'id' => (string) ( $due_within_7d[0]['charge_id'] ?? '' ) ) );
		}

		return Utils::wc_payments_settings_url( self::PATH_DISPUTES, array( 'filter' => 'awaiting_response' ) );
	}

	/**
	 * The task shows while a dispute is due within 7 days, for valid accounts and users who can manage WooCommerce.
	 *
	 * @return bool
	 */
	public function can_view() {
		if ( ! current_user_can( 'manage_woocommerce' ) || ! $this->is_account_valid() ) {
			return false;
		}

		return count( $this->get_disputes_due_within_days( 7 ) ) > 0;
	}

	/**
	 * The task never completes; it hides once no dispute is due within a week.
	 *
	 * @return bool
	 */
	public function is_complete() {
		return false;
	}

	/**
	 * Client `WC_Payments_Account::is_stripe_account_valid()`, over the cached account without a refresh.
	 *
	 * @return bool
	 */
	private function is_account_valid(): bool {
		$account = $this->account_service->get_preserved_account_data_snapshot();

		return ! empty( $account )
			&& ! empty( $account['details_submitted'] )
			&& isset( $account['capabilities']['card_payments'] )
			&& 'unrequested' !== $account['capabilities']['card_payments'];
	}

	/**
	 * Get the cached disputes awaiting a response.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function get_active_disputes(): array {
		if ( null === $this->active_disputes ) {
			$this->active_disputes = $this->dispute_data_service->get_active_disputes();
		}

		return $this->active_disputes;
	}

	/**
	 * Get the disputes not yet due and due within the given number of whole days.
	 *
	 * @param int $num_days Number of days.
	 * @return array<int,array<string,mixed>>
	 */
	private function get_disputes_due_within_days( int $num_days ): array {
		$due = array();
		$utc = new DateTimeZone( 'UTC' );

		foreach ( $this->get_active_disputes() as $dispute ) {
			if ( empty( $dispute['due_by'] ) ) {
				continue;
			}

			$now_utc    = new DateTime( 'now', $utc );
			$due_by_utc = new DateTime( (string) $dispute['due_by'], $utc );
			if ( $now_utc > $due_by_utc ) {
				continue;
			}

			if ( $now_utc->diff( $due_by_utc )->days <= $num_days ) {
				$due[] = $dispute;
			}
		}

		return $due;
	}

	/**
	 * Client `WC_Payments_Utils::format_currency()` over a Stripe minor-unit amount.
	 *
	 * @param int    $amount   Amount in minor units.
	 * @param string $currency Currency code.
	 * @return string
	 */
	private function format_amount( int $amount, string $currency ): string {
		$currency = strtoupper( $currency );
		$args     = array( 'currency' => $currency );
		$format   = wc_get_container()->get( MultiCurrencyLocalizationService::class )->get_currency_format( $currency );
		$patterns = array(
			'right'       => '%2$s%1$s',
			'left_space'  => '%1$s %2$s',
			'right_space' => '%2$s %1$s',
		);

		if ( isset( $format['thousand_sep'] ) ) {
			$args['thousand_separator'] = $format['thousand_sep'];
		}
		if ( isset( $format['decimal_sep'] ) ) {
			$args['decimal_separator'] = $format['decimal_sep'];
		}
		if ( isset( $format['num_decimals'] ) ) {
			$args['decimals'] = $format['num_decimals'];
		}
		if ( isset( $format['currency_pos'] ) ) {
			$args['price_format'] = $patterns[ $format['currency_pos'] ] ?? '%1$s%2$s';
		}

		return html_entity_decode(
			wp_strip_all_tags(
				wc_price( WooPaymentsCurrencyUtils::amount_from_minor_units( $amount, $currency ), $args )
			),
			ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML401
		);
	}
}
