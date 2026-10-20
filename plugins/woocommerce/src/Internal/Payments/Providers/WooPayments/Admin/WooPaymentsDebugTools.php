<?php
/**
 * WooPaymentsDebugTools class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Admin;

use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsAccountService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsCanceledAuthorizationFeeRemediationService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsFrontendStylesService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsOrderMode;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsRuntimeArbiter;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsSetupTier;
use Exception;
use Throwable;
use WC_Order;

defined( 'ABSPATH' ) || exit;

/**
 * The WooPayments tools on WooCommerce > Status > Tools: refetch the account, delete test orders, clear the checkout styles
 * cache, and preview or run the canceled-authorization fee fix.
 *
 * WooPaymentsStatusReport adds them on the woocommerce_debug_tools filter and resolves this class only when the tools are listed.
 *
 * @since 11.2.0
 * @internal
 */
class WooPaymentsDebugTools {

	/**
	 * Runtime owner arbiter.
	 *
	 * @var WooPaymentsRuntimeArbiter
	 */
	private WooPaymentsRuntimeArbiter $arbiter;

	/**
	 * WooPayments account service.
	 *
	 * @var WooPaymentsAccountService
	 */
	private WooPaymentsAccountService $account_service;

	/**
	 * Frontend styles service.
	 *
	 * @var WooPaymentsFrontendStylesService|null
	 */
	private ?WooPaymentsFrontendStylesService $frontend_styles_service = null;

	/**
	 * Setup tier.
	 *
	 * @var WooPaymentsSetupTier
	 */
	private WooPaymentsSetupTier $setup_tier;

	/**
	 * Canceled-authorization fee remediation service.
	 *
	 * @var WooPaymentsCanceledAuthorizationFeeRemediationService|null
	 */
	private ?WooPaymentsCanceledAuthorizationFeeRemediationService $fee_remediation_service = null;

	/**
	 * Initialize the class instance.
	 *
	 * @internal
	 *
	 * @param WooPaymentsRuntimeArbiter $arbiter         Runtime owner arbiter.
	 * @param WooPaymentsAccountService $account_service WooPayments account service.
	 * @param WooPaymentsSetupTier      $setup_tier      Setup tier.
	 */
	final public function init( WooPaymentsRuntimeArbiter $arbiter, WooPaymentsAccountService $account_service, WooPaymentsSetupTier $setup_tier ): void {
		$this->arbiter         = $arbiter;
		$this->account_service = $account_service;
		$this->setup_tier      = $setup_tier;
	}

	/**
	 * Add the WooPayments debug tools, none while the WooPayments extension owns payments (it lists its own).
	 *
	 * The two canceled-authorization tools schedule actions whose handlers load only in the connected and active setup tiers,
	 * which the built-in WooPayments reaches only as the owner, so they are listed only then.
	 *
	 * @param mixed $tools Debug tools.
	 * @return mixed
	 */
	public function add_debug_tools( $tools ) {
		if ( ! is_array( $tools ) || $this->arbiter->is_extension_owner() ) {
			return $tools;
		}

		$woopayments_tools = array(
			'clear_wcpay_account_cache' => array(
				'name'     => __( 'Clear WooPayments account cache', 'woocommerce' ),
				'button'   => __( 'Clear', 'woocommerce' ),
				'desc'     => __( 'This tool clears the cached account values used by WooPayments.', 'woocommerce' ),
				'callback' => array( $this, 'clear_account_cache' ),
			),
			'delete_wcpay_test_orders'  => array(
				'name'     => __( 'Delete WooPayments test orders', 'woocommerce' ),
				'button'   => __( 'Delete', 'woocommerce' ),
				'desc'     => sprintf(
					/* translators: %s: WooPayments */
					__( '<strong class="red">Note:</strong> This option deletes all test mode orders placed via %s. Orders placed via other gateways will not be affected. Use with caution, as this action cannot be undone.', 'woocommerce' ),
					'WooPayments'
				),
				'callback' => array( $this, 'delete_test_orders' ),
			),
			'clear_wcpay_styles_cache'  => array(
				'name'     => __( 'Clear WooPayments calculated styles', 'woocommerce' ),
				'button'   => __( 'Clear', 'woocommerce' ),
				'desc'     => __( 'This tool will clear the styles cached for the WooPayments gateway UI elements at checkout', 'woocommerce' ),
				'callback' => array( $this, 'clear_styles_cache' ),
			),
		);

		if ( in_array( $this->setup_tier->get_effective_tier(), array( WooPaymentsSetupTier::CONNECTED, WooPaymentsSetupTier::ACTIVE ), true ) ) {
			$woopayments_tools['remediate_canceled_auth_fees_dry_run'] = array(
				'name'     => __( 'Preview canceled authorization fix (Dry Run)', 'woocommerce' ),
				'button'   => $this->get_dry_run_button_text(),
				'desc'     => __( 'Preview what orders would be affected by the canceled authorization fix without making any changes. Results are logged to WooCommerce > Status > Logs.', 'woocommerce' ),
				'callback' => array( $this, 'schedule_canceled_auth_dry_run' ),
				'disabled' => $this->is_remediation_running_or_complete(),
			);
			$woopayments_tools['remediate_canceled_auth_fees']         = array(
				'name'     => __( 'Fix canceled authorization analytics', 'woocommerce' ),
				'button'   => $this->get_remediation_button_text(),
				'desc'     => $this->get_remediation_description(),
				'confirm'  => __( 'This will update order metadata and delete incorrect refund records for affected orders. This fixes negative values in WooCommerce Analytics. Make sure you have a recent backup before proceeding. Continue?', 'woocommerce' ),
				'callback' => array( $this, 'schedule_canceled_auth_remediation' ),
				'disabled' => $this->is_remediation_running_or_complete(),
			);
		}

		return array_merge( $tools, $woopayments_tools );
	}

	/**
	 * Refetch the WooPayments account so the cache holds fresh data, as the WooPayments extension's tool does.
	 *
	 * Returns the account data, or false when the fetch failed with no account to keep, so WooCommerce
	 * shows "Tool ran." or its error message.
	 *
	 * @return array<string,mixed>|false|string Account data, false on failure, or a permission message.
	 */
	public function clear_account_cache() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return __( 'You do not have permission to run this tool.', 'woocommerce' );
		}

		$account_service = $this->account_service;
		if ( ! $account_service->is_platform_connected() ) {
			return array();
		}

		$account_data = $account_service->refresh_account_data();

		if ( array() === $account_data && $account_service->has_account_or_is_connection_indeterminate() ) {
			return false;
		}

		return $account_data;
	}

	/**
	 * Delete test-mode WooPayments orders.
	 *
	 * @return string Result message.
	 */
	public function delete_test_orders(): string {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return __( 'You do not have permission to delete orders.', 'woocommerce' );
		}

		try {
			$orders        = wc_get_orders(
				array(
					'limit'      => -1,
					// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
					'meta_key'   => '_wcpay_mode',
					// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
					'meta_value' => WooPaymentsOrderMode::TEST,
					'return'     => 'objects',
				)
			);
			$deleted_count = 0;

			if ( ! is_array( $orders ) || empty( $orders ) ) {
				return __( 'No test orders found.', 'woocommerce' );
			}

			foreach ( $orders as $order ) {
				if ( $order instanceof WC_Order && $order->delete( true ) ) {
					++$deleted_count;
				}
			}

			return sprintf(
				/* translators: %d: number of orders deleted */
				_n(
					'%d test order has been permanently deleted.',
					'%d test orders have been permanently deleted.',
					$deleted_count,
					'woocommerce'
				),
				$deleted_count
			);
		} catch ( Throwable $exception ) {
			return sprintf(
				/* translators: %s: error message */
				__( 'Error deleting test orders: %s', 'woocommerce' ),
				$exception->getMessage()
			);
		}
	}

	/**
	 * Clear the WooPayments frontend styles cache.
	 *
	 * @return string Result message.
	 */
	public function clear_styles_cache(): string {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return __( 'You do not have permission to run this tool.', 'woocommerce' );
		}

		$this->get_frontend_styles_service()->invalidate_styles_cache_version();

		return __( 'WooPayments styles cleared', 'woocommerce' );
	}

	/**
	 * Schedule canceled-authorization fee remediation.
	 *
	 * @return string Result message.
	 */
	public function schedule_canceled_auth_remediation(): string {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return __( 'You do not have permission to run this tool.', 'woocommerce' );
		}

		try {
			if ( $this->get_fee_remediation_service()->is_complete() ) {
				return __( 'Remediation has already been completed.', 'woocommerce' );
			}

			if ( $this->is_any_remediation_action_scheduled() ) {
				return __( 'Remediation is already in progress. Check the Action Scheduler for status.', 'woocommerce' );
			}

			$this->get_fee_remediation_service()->schedule_remediation();

			return __( 'Remediation has been scheduled and will run in the background. You can monitor progress in the Action Scheduler.', 'woocommerce' );
		} catch ( Exception $e ) {
			return sprintf(
				/* translators: %s: error message */
				__( 'Error scheduling remediation: %s', 'woocommerce' ),
				$e->getMessage()
			);
		}
	}

	/**
	 * Schedule canceled-authorization fee remediation dry run.
	 *
	 * @return string Result message.
	 */
	public function schedule_canceled_auth_dry_run(): string {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return __( 'You do not have permission to run this tool.', 'woocommerce' );
		}

		try {
			if ( $this->get_fee_remediation_service()->is_complete() ) {
				return __( 'Remediation has already been completed.', 'woocommerce' );
			}

			if ( $this->is_any_remediation_action_scheduled() ) {
				return __( 'Remediation is already in progress. Check the Action Scheduler for status.', 'woocommerce' );
			}

			$this->get_fee_remediation_service()->schedule_dry_run();

			return __( 'Dry run has been scheduled and will run in the background. Check WooCommerce > Status > Logs for results (source: wcpay-fee-remediation).', 'woocommerce' );
		} catch ( Exception $e ) {
			return sprintf(
				/* translators: %s: error message */
				__( 'Error scheduling dry run: %s', 'woocommerce' ),
				$e->getMessage()
			);
		}
	}

	/**
	 * Get dry-run button text.
	 *
	 * @return string
	 */
	private function get_dry_run_button_text(): string {
		if ( $this->get_fee_remediation_service()->is_complete() ) {
			return __( 'Completed', 'woocommerce' );
		}

		if ( $this->is_remediation_running_or_complete() ) {
			return __( 'Running...', 'woocommerce' );
		}

		return __( 'Preview', 'woocommerce' );
	}

	/**
	 * Get remediation button text.
	 *
	 * @return string
	 */
	private function get_remediation_button_text(): string {
		if ( $this->get_fee_remediation_service()->is_complete() ) {
			return __( 'Completed', 'woocommerce' );
		}

		if ( $this->is_remediation_running_or_complete() ) {
			return __( 'Running...', 'woocommerce' );
		}

		return __( 'Run', 'woocommerce' );
	}

	/**
	 * Get remediation description.
	 *
	 * @return string
	 */
	private function get_remediation_description(): string {
		$base_desc = __( 'This tool removes incorrect refund records and fee data from orders where payment authorization was canceled (not captured). This fixes negative values appearing in WooCommerce Analytics for stores using manual capture.', 'woocommerce' );
		$stats     = $this->get_fee_remediation_service()->get_stats();

		if ( $this->get_fee_remediation_service()->is_complete() ) {
			if ( $stats['processed'] > 0 ) {
				return sprintf(
					/* translators: 1: base description, 2: number of orders processed, 3: number of orders remediated */
					__( '%1$s <strong>Status: Completed.</strong> Processed %2$d orders, remediated %3$d.', 'woocommerce' ),
					$base_desc,
					$stats['processed'],
					$stats['remediated']
				);
			}

			return sprintf(
				/* translators: %s: base description */
				__( '%s <strong>Status: Completed.</strong> No affected orders found.', 'woocommerce' ),
				$base_desc
			);
		}

		if ( $this->is_remediation_running_or_complete() ) {
			if ( $stats['processed'] > 0 ) {
				return sprintf(
					/* translators: 1: base description, 2: number of orders processed so far */
					__( '%1$s <strong>Status: Running...</strong> Processed %2$d orders so far. Check the Action Scheduler for details.', 'woocommerce' ),
					$base_desc,
					$stats['processed']
				);
			}

			return sprintf(
				/* translators: %s: base description */
				__( '%s <strong>Status: Running...</strong> Check the Action Scheduler for details.', 'woocommerce' ),
				$base_desc
			);
		}

		return $base_desc;
	}

	/**
	 * Tell whether remediation is running, complete, or scheduled.
	 *
	 * @return bool
	 */
	private function is_remediation_running_or_complete(): bool {
		return $this->get_fee_remediation_service()->is_complete()
			|| 'running' === get_option( WooPaymentsCanceledAuthorizationFeeRemediationService::STATUS_OPTION_KEY, '' )
			|| $this->is_remediation_action_scheduled();
	}

	/**
	 * Tell whether the full remediation run is scheduled. A pending dry run alone does not count.
	 *
	 * @return bool
	 */
	private function is_remediation_action_scheduled(): bool {
		if ( ! function_exists( 'as_has_scheduled_action' ) ) {
			return false;
		}

		return false !== as_has_scheduled_action( WooPaymentsCanceledAuthorizationFeeRemediationService::ACTION_HOOK, array(), WooPaymentsCanceledAuthorizationFeeRemediationService::ACTION_SCHEDULER_GROUP_ID );
	}

	/**
	 * Tell whether the full remediation run or its dry run is scheduled.
	 *
	 * @return bool
	 */
	private function is_any_remediation_action_scheduled(): bool {
		if ( ! function_exists( 'as_has_scheduled_action' ) ) {
			return false;
		}

		return $this->is_remediation_action_scheduled()
			|| false !== as_has_scheduled_action( WooPaymentsCanceledAuthorizationFeeRemediationService::DRY_RUN_ACTION_HOOK, array(), WooPaymentsCanceledAuthorizationFeeRemediationService::ACTION_SCHEDULER_GROUP_ID );
	}

	/**
	 * Get the frontend styles service, resolving it on first use.
	 *
	 * @return WooPaymentsFrontendStylesService
	 */
	private function get_frontend_styles_service(): WooPaymentsFrontendStylesService {
		if ( null === $this->frontend_styles_service ) {
			$this->frontend_styles_service = wc_get_container()->get( WooPaymentsFrontendStylesService::class );
		}

		return $this->frontend_styles_service;
	}

	/**
	 * Get the fee remediation service, resolving it on first use.
	 *
	 * @return WooPaymentsCanceledAuthorizationFeeRemediationService
	 */
	private function get_fee_remediation_service(): WooPaymentsCanceledAuthorizationFeeRemediationService {
		if ( null === $this->fee_remediation_service ) {
			$this->fee_remediation_service = wc_get_container()->get( WooPaymentsCanceledAuthorizationFeeRemediationService::class );
		}

		return $this->fee_remediation_service;
	}
}
