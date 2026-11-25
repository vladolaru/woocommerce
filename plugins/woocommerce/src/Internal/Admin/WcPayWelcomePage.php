<?php
declare( strict_types=1 );

namespace Automattic\WooCommerce\Internal\Admin;

/**
 * Class WcPayWelcomePage
 *
 * Kept only so third-party references keep resolving; every method is an inert no-op.
 * Also reachable through the `Automattic\WooCommerce\Admin\Features\WcPayWelcomePage` alias.
 *
 * @deprecated 9.9.0 The WooPayments welcome page no longer exists. Scheduled for removal in WooCommerce 12.0.0.
 */
class WcPayWelcomePage {
	/**
	 * The incentive type for the WooPayments welcome page.
	 */
	const INCENTIVE_TYPE = 'welcome_page';

	/**
	 * Class instance.
	 *
	 * @var ?WcPayWelcomePage
	 */
	protected static ?WcPayWelcomePage $instance = null;

	/**
	 * Get class instance.
	 *
	 * @deprecated 9.9.0 The WooPayments welcome page no longer exists.
	 *
	 * @return ?WcPayWelcomePage
	 */
	public static function instance(): ?WcPayWelcomePage {
		wc_deprecated_function( __METHOD__, '9.9.0' );

		self::$instance = is_null( self::$instance ) ? new self() : self::$instance;

		return self::$instance;
	}

	/**
	 * WCPayWelcomePage constructor.
	 *
	 * @deprecated 9.9.0 The WooPayments welcome page no longer exists.
	 */
	public function __construct() {
		wc_deprecated_function( __METHOD__, '9.9.0' );
	}

	/**
	 * There is never an incentive to show.
	 *
	 * @deprecated 9.9.0 The WooPayments welcome page no longer exists.
	 *
	 * @param bool $skip_wcpay_active Unused.
	 *
	 * @return bool Always false.
	 */
	public function has_incentive( bool $skip_wcpay_active = false ): bool { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- Kept for signature compatibility.
		wc_deprecated_function( __METHOD__, '9.9.0' );

		return false;
	}
}
