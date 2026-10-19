<?php
/**
 * WooPaymentsPlatformConnectionService class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Cutover;

use Automattic\Jetpack\Connection\Manager as JetpackConnectionManager;
use Automattic\WooCommerce\Internal\Jetpack\JetpackConnection;

/**
 * Checks the WordPress.com connection the cutover needs: the site is connected and has a blog ID, and the connection
 * owner exists and has a user token.
 *
 * @since 11.0.0
 * @internal
 */
class WooPaymentsPlatformConnectionService {

	/** The site has no working WordPress.com connection. */
	public const FAILURE_CONNECTION_UNAVAILABLE = 'wpcom_connection_unavailable';

	/** The site has no WordPress.com blog ID. */
	public const FAILURE_BLOG_ID_UNAVAILABLE = 'wpcom_blog_id_unavailable';

	/** The connection has no owner. */
	public const FAILURE_CONNECTION_OWNER_UNAVAILABLE = 'wpcom_connection_owner_unavailable';

	/** The connection owner no longer exists or has no user token. */
	public const FAILURE_CONNECTION_OWNER_USER_TOKEN_UNAVAILABLE = 'wpcom_connection_owner_user_token_unavailable';

	/**
	 * Get the connection failure codes that block the cutover.
	 *
	 * @return array<int,string> Failure codes.
	 */
	public function get_cutover_preflight_failures(): array {
		$failures = array();
		$manager  = $this->get_connection_manager();

		if ( null === $manager ) {
			return array( self::FAILURE_CONNECTION_UNAVAILABLE );
		}

		try {
			if ( ! $manager->is_connected() ) {
				$failures[] = self::FAILURE_CONNECTION_UNAVAILABLE;
			}
		} catch ( \Throwable $e ) {
			$failures[] = self::FAILURE_CONNECTION_UNAVAILABLE;
		}

		if ( null === $this->get_blog_id() ) {
			$failures[] = self::FAILURE_BLOG_ID_UNAVAILABLE;
		}

		$connection_owner = $this->get_cutover_connection_owner_user_token_status();
		if ( $connection_owner['owner_id'] <= 0 ) {
			$failures[] = self::FAILURE_CONNECTION_OWNER_UNAVAILABLE;
		} elseif ( ! $connection_owner['owner_exists'] || ! $connection_owner['user_token_available'] ) {
			$failures[] = self::FAILURE_CONNECTION_OWNER_USER_TOKEN_UNAVAILABLE;
		}

		return array_values( array_unique( $failures ) );
	}

	/**
	 * Get the connection owner's ID, whether that user exists and whether it has a user token.
	 *
	 * The cutover preflight also reads it to tell a deleted owner apart.
	 *
	 * @return array{owner_id:int,owner_exists:bool,user_token_available:bool} Connection owner details.
	 */
	public function get_cutover_connection_owner_user_token_status(): array {
		$manager  = $this->get_connection_manager();
		$owner_id = null === $manager ? 0 : $this->get_connection_owner_id( $manager );
		if ( $owner_id <= 0 ) {
			return array(
				'owner_id'             => 0,
				'owner_exists'         => false,
				'user_token_available' => false,
			);
		}

		$owner_exists = null !== $this->get_connection_owner_user( $owner_id );
		return array(
			'owner_id'             => $owner_id,
			'owner_exists'         => $owner_exists,
			'user_token_available' => $owner_exists && null !== $manager && $this->is_user_connected( $manager, $owner_id ),
		);
	}

	/**
	 * Get the Jetpack connection manager.
	 *
	 * @return JetpackConnectionManager|null
	 */
	protected function get_connection_manager(): ?JetpackConnectionManager {
		try {
			return JetpackConnection::get_manager();
		} catch ( \Throwable $e ) {
			return null;
		}
	}

	/**
	 * Get the connected WPCOM blog ID.
	 *
	 * @return int|null
	 */
	protected function get_blog_id(): ?int {
		if ( ! class_exists( 'Jetpack_Options' ) ) {
			return null;
		}

		$blog_id = \Jetpack_Options::get_option( 'id' );

		return is_numeric( $blog_id ) && (int) $blog_id > 0 ? (int) $blog_id : null;
	}

	/**
	 * Get the connection owner ID.
	 *
	 * @param JetpackConnectionManager $manager Jetpack connection manager.
	 * @return int
	 */
	private function get_connection_owner_id( JetpackConnectionManager $manager ): int {
		try {
			return (int) $manager->get_connection_owner_id();
		} catch ( \Throwable $e ) {
			return 0;
		}
	}

	/**
	 * Tell whether the connection owner has a user token.
	 *
	 * @param JetpackConnectionManager $manager             Jetpack connection manager.
	 * @param int                      $connection_owner_id Connection owner user ID.
	 * @return bool
	 */
	private function is_user_connected( JetpackConnectionManager $manager, int $connection_owner_id ): bool {
		try {
			return (bool) $manager->is_user_connected( $connection_owner_id );
		} catch ( \Throwable $e ) {
			return false;
		}
	}

	/**
	 * Get the WordPress user for the connection owner.
	 *
	 * @param int $connection_owner_id Connection owner user ID.
	 * @return \WP_User|null Connection owner user.
	 */
	protected function get_connection_owner_user( int $connection_owner_id ): ?\WP_User {
		$user = get_user_by( 'id', $connection_owner_id );
		return $user instanceof \WP_User ? $user : null;
	}
}
