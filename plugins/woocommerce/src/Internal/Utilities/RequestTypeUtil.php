<?php
/**
 * RequestTypeUtil class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Utilities;

use Automattic\Jetpack\Constants;

/**
 * Tells the one type of request WooCommerce is loading in, which the payments and Multi-Currency registration lists are
 * keyed by.
 *
 * Every request has exactly one type, checked in this order: WP-CLI, cron, AJAX, REST, admin, front. So `cron` includes
 * Action Scheduler's async request runner, and `admin` is a wp-admin request that is neither AJAX nor REST. This differs
 * from WooCommerce::is_request(), where `frontend` is also true on AJAX requests and `cron` reads only DOING_CRON.
 *
 * @since 11.2.0
 * @internal
 */
final class RequestTypeUtil {

	/** A WP-CLI command. */
	public const CLI = 'cli';

	/** A WordPress cron run or Action Scheduler's async request runner. */
	public const CRON = 'cron';

	/** A WordPress AJAX request (admin-ajax.php). */
	public const AJAX = 'ajax';

	/** A REST API request. */
	public const REST = 'rest';

	/** A wp-admin request that is neither AJAX nor REST. */
	public const ADMIN = 'admin';

	/** Any other request: a front-end page, feed or endpoint. */
	public const FRONT = 'front';

	/**
	 * Get the type of the current request.
	 *
	 * It reads only signals that are safe while WooCommerce loads: constants, WordPress's request checks and the REST answer
	 * the caller passes in, so it calls no WC() method. Every signal is read on every request, so the wp_doing_cron and
	 * wp_doing_ajax filters run once whatever the type turns out to be.
	 *
	 * @since 11.2.0
	 *
	 * @param bool $is_rest_api_request Whether the current request is a REST request.
	 * @return string One of the class constants.
	 */
	public static function get_request_type( bool $is_rest_api_request ): string {
		$is_cli   = Constants::is_true( 'WP_CLI' );
		$is_cron  = wp_doing_cron() || wc_is_running_from_async_action_scheduler();
		$is_ajax  = wp_doing_ajax();
		$is_admin = is_admin();

		if ( $is_cli ) {
			return self::CLI;
		}
		if ( $is_cron ) {
			return self::CRON;
		}
		if ( $is_ajax ) {
			return self::AJAX;
		}
		if ( $is_rest_api_request ) {
			return self::REST;
		}

		return $is_admin ? self::ADMIN : self::FRONT;
	}
}
