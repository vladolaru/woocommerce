<?php
/**
 * PlatformConnectionService class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Cutover;

use Automattic\Jetpack\Connection\Manager as JetpackConnectionManager;
use Automattic\WooCommerce\Internal\Jetpack\JetpackConnection;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api\HttpClient;

/**
 * Checks the WordPress.com connection the cutover needs: the site is connected and has a blog ID, and the connection
 * owner exists and has a user token.
 *
 * @since 11.0.0
 * @internal
 */
class PlatformConnectionService {

	/** The site has no working WordPress.com connection. */
	public const FAILURE_CONNECTION_UNAVAILABLE = 'wpcom_connection_unavailable';

	/** The site has no WordPress.com blog ID. */
	public const FAILURE_BLOG_ID_UNAVAILABLE = 'wpcom_blog_id_unavailable';

	/** The connection has no usable owner: the owner was deleted, or has no user token. */
	public const FAILURE_CONNECTION_OWNER_UNAVAILABLE = 'wpcom_connection_owner_unavailable';

	/**
	 * WooPayments HTTP client, which holds the connected blog ID.
	 *
	 * @var HttpClient
	 */
	private HttpClient $http_client;

	/**
	 * Initialize the class instance.
	 *
	 * @internal
	 *
	 * @param HttpClient $http_client WooPayments HTTP client.
	 */
	final public function init( HttpClient $http_client ): void {
		$this->http_client = $http_client;
	}

	/**
	 * Get the connection failure codes that block the cutover.
	 *
	 * @return array<int,string> Failure codes.
	 */
	public function get_preflight_failures(): array {
		$failures = array();
		$manager  = $this->get_connection_manager();

		if ( ! $manager->is_connected() ) {
			$failures[] = self::FAILURE_CONNECTION_UNAVAILABLE;
		}

		if ( null === $this->http_client->get_blog_id() ) {
			$failures[] = self::FAILURE_BLOG_ID_UNAVAILABLE;
		}

		if ( (int) $manager->get_connection_owner_id() <= 0 ) {
			$failures[] = self::FAILURE_CONNECTION_OWNER_UNAVAILABLE;
		}

		return array_values( array_unique( $failures ) );
	}

	/**
	 * Get the Jetpack connection manager.
	 *
	 * @return JetpackConnectionManager
	 */
	protected function get_connection_manager(): JetpackConnectionManager {
		return JetpackConnection::get_manager();
	}
}
