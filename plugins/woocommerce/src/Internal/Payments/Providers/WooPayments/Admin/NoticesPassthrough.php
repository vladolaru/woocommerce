<?php
/**
 * NoticesPassthrough class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Admin;

use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Cutover\WooPaymentsCutoverController;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsRuntimeArbiter;
use Automattic\WooCommerce\Internal\RegisterHooksInterface;

/**
 * Keeps the WooPayments store currency notices and the cutover notices on the WooPayments pages of the Payments
 * settings tab, so a merchant sees a currency problem and, mid-switch, the switch's state on every WooPayments page.
 *
 * Core's Payments tab removes every non-core `admin_notices` callback (`WC_Settings_Payment_Gateways::suppress_admin_notices()`).
 * On the WooPayments routes, onboarding aside, this puts back the callbacks of the classes it names, as they were
 * registered. On a store waiting for its switch it also puts back the switch notice on the Payments tab itself, the one
 * offer of the switch there; every other Payments tab route keeps core's stripping.
 *
 * @since 11.2.0
 * @internal
 */
class NoticesPassthrough implements RegisterHooksInterface {

	/**
	 * Classes whose `admin_notices` callbacks are kept on the WooPayments routes.
	 *
	 * The cutover notices (switch in progress, reconnect, completed) are kept, so a merchant mid-switch sees them on every
	 * WooPayments page. Multi-Currency's notices are not kept: they show on the Multi-currency tab and the other admin pages.
	 */
	private const KEPT_NOTICE_CLASSES = array(
		CurrencyComplianceNotice::class,
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
		add_action( 'in_admin_header', array( $this, 'keep_woopayments_notices' ), PHP_INT_MAX - 1 );
	}

	/**
	 * On a WooPayments route, note the kept `admin_notices` callbacks and schedule putting them back.
	 *
	 * @internal
	 */
	public function keep_woopayments_notices(): void {
		global $wp_filter;

		$this->kept_callbacks = array();
		if ( ! isset( $wp_filter['admin_notices'] ) ) {
			return;
		}
		if ( $this->is_woopayments_route() ) {
			$kept_classes = self::KEPT_NOTICE_CLASSES;
		} elseif ( $this->is_payments_tab() && wc_get_container()->get( WooPaymentsRuntimeArbiter::class )->is_awaiting_switch() ) {
			$kept_classes = array( WooPaymentsCutoverController::class );
		} else {
			return;
		}

		foreach ( $wp_filter['admin_notices']->callbacks as $priority => $callbacks ) {
			foreach ( (array) $callbacks as $callback ) {
				$function = is_array( $callback ) ? ( $callback['function'] ?? null ) : null;
				$object   = is_array( $function ) ? ( $function[0] ?? null ) : null;
				if ( ! is_object( $object ) || ! $this->is_kept_notice_object( $object, $kept_classes ) ) {
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
	 * @param object                  $callback_object The callback's object.
	 * @param array<int,class-string> $kept_classes    The classes whose notices are kept on this route.
	 * @return bool
	 */
	private function is_kept_notice_object( object $callback_object, array $kept_classes ): bool {
		foreach ( $kept_classes as $class_name ) {
			if ( $callback_object instanceof $class_name ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Tell whether the request is the Payments settings tab itself, its provider list.
	 *
	 * @return bool
	 */
	private function is_payments_tab(): bool {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only request routing.
		$page    = isset( $_GET['page'] ) && is_scalar( $_GET['page'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['page'] ) ) : '';
		$tab     = isset( $_GET['tab'] ) && is_scalar( $_GET['tab'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['tab'] ) ) : '';
		$path    = isset( $_GET['path'] ) && is_scalar( $_GET['path'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['path'] ) ) : '';
		$section = isset( $_GET['section'] ) && is_scalar( $_GET['section'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['section'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		return 'wc-settings' === $page && 'checkout' === $tab && '' === trim( $path, '/' ) && '' === $section;
	}

	/**
	 * Tell whether the request is a WooPayments page of the Payments settings tab (not its onboarding).
	 *
	 * @return bool
	 */
	private function is_woopayments_route(): bool {
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
