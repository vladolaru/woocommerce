<?php
/**
 * WooPaymentsAdminNoticesPassthrough class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Admin\Settings\PaymentsProviders\WooPayments;

use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsCurrencyComplianceNotice;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsCutoverController;
use Automattic\WooCommerce\Internal\RegisterHooksInterface;

/**
 * Keeps WooPayments' own admin notices on the native WooPayments pages of the Payments settings tab.
 *
 * Core's Payments tab removes every non-core `admin_notices` callback (`WC_Settings_Payment_Gateways::suppress_admin_notices()`).
 * Client 11.1.0 shows these notices on its WooPayments pages, so on native WooPayments routes this puts back the
 * callbacks it names, as they were registered; every other Payments tab route keeps core's stripping.
 *
 * @since 11.2.0
 * @internal Transitional internal component for the native payments runtime.
 */
class WooPaymentsAdminNoticesPassthrough implements RegisterHooksInterface {

	/**
	 * Classes whose `admin_notices` callbacks are kept on native WooPayments routes.
	 *
	 * The cutover notices (switch in progress, reconnect, completed) have no client counterpart; they are kept
	 * on these pages by monitor ruling N-261. The multi-currency notices, which the client also shows here, follow
	 * core's stripping by owner decision N-285; they still show on the Multi-currency tab and other admin pages.
	 */
	private const KEPT_NOTICE_CLASSES = array(
		WooPaymentsCurrencyComplianceNotice::class,
		WooPaymentsCutoverController::class,
	);

	/**
	 * Callbacks taken before core strips the notices, to put back after it.
	 *
	 * @var array<int,array{priority:int,callback:callable,accepted_args:int}>
	 */
	private array $kept_callbacks = array();

	/**
	 * Register hooks.
	 */
	public function register() {
		// Core strips the notices on `in_admin_header` at PHP_INT_MAX; take the kept callbacks just before.
		if ( false === has_action( 'in_admin_header', array( $this, 'keep_woopayments_notices' ) ) ) {
			add_action( 'in_admin_header', array( $this, 'keep_woopayments_notices' ), PHP_INT_MAX - 1 );
		}
	}

	/**
	 * On a native WooPayments route, note the kept `admin_notices` callbacks and schedule putting them back.
	 *
	 * @internal
	 */
	public function keep_woopayments_notices(): void {
		global $wp_filter;

		$this->kept_callbacks = array();
		if ( ! $this->is_native_woopayments_route() || ! isset( $wp_filter['admin_notices'] ) ) {
			return;
		}

		foreach ( $wp_filter['admin_notices']->callbacks as $priority => $callbacks ) {
			foreach ( (array) $callbacks as $callback ) {
				$function = is_array( $callback ) ? ( $callback['function'] ?? null ) : null;
				$object   = is_array( $function ) ? ( $function[0] ?? null ) : null;
				if ( ! is_object( $object ) || ! $this->is_kept_notice_object( $object ) ) {
					continue;
				}

				$this->kept_callbacks[] = array(
					'priority'      => (int) $priority,
					'callback'      => $function,
					'accepted_args' => (int) ( $callback['accepted_args'] ?? 1 ),
				);
			}
		}

		if ( array() !== $this->kept_callbacks ) {
			// Added while `in_admin_header` runs, so it runs after core's callback at the same priority.
			add_action( 'in_admin_header', array( $this, 'restore_woopayments_notices' ), PHP_INT_MAX );
		}
	}

	/**
	 * Put back the kept `admin_notices` callbacks that core removed.
	 *
	 * @internal
	 */
	public function restore_woopayments_notices(): void {
		foreach ( $this->kept_callbacks as $kept ) {
			if ( false === has_action( 'admin_notices', $kept['callback'] ) ) {
				add_action( 'admin_notices', $kept['callback'], $kept['priority'], $kept['accepted_args'] );
			}
		}

		$this->kept_callbacks = array();
		remove_action( 'in_admin_header', array( $this, 'restore_woopayments_notices' ), PHP_INT_MAX );
	}

	/**
	 * Tell whether a callback object is one whose notices are kept.
	 *
	 * @param object $callback_object The callback's object.
	 * @return bool
	 */
	private function is_kept_notice_object( object $callback_object ): bool {
		foreach ( self::KEPT_NOTICE_CLASSES as $class_name ) {
			if ( $callback_object instanceof $class_name ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Tell whether the request is a native WooPayments page of the Payments settings tab (not its onboarding).
	 *
	 * @return bool
	 */
	private function is_native_woopayments_route(): bool {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only request routing.
		$page = isset( $_GET['page'] ) && is_scalar( $_GET['page'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['page'] ) ) : '';
		$tab  = isset( $_GET['tab'] ) && is_scalar( $_GET['tab'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['tab'] ) ) : '';
		$path = isset( $_GET['path'] ) && is_scalar( $_GET['path'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['path'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		return 'wc-settings' === $page
			&& 'checkout' === $tab
			&& 0 === strpos( $path, '/woopayments/' )
			&& 0 !== strpos( $path, '/woopayments/onboarding' );
	}
}
