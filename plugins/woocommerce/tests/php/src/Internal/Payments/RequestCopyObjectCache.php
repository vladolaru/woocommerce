<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments;

/**
 * Keeps transients as a persistent object cache drop-in such as Redis Object Cache does: on a server every request
 * shares, with a copy per request that answers a read unless the read asks for a fresh value.
 *
 * Every other group is core's cache. Expiries are not kept.
 */
final class RequestCopyObjectCache extends \WP_Object_Cache {

	/**
	 * Values on the cache server, by key.
	 *
	 * @var array<string,mixed>
	 */
	private array $server = array();

	/**
	 * Each request's copy of the values it read or wrote, by request name and key.
	 *
	 * @var array<string,array<string,mixed>>
	 */
	private array $copies = array();

	/**
	 * Name of the request whose copy is current.
	 *
	 * @var string
	 */
	private string $request = 'main';

	/**
	 * Key and step to run once, right after a winning add of that key.
	 *
	 * @var array{0:string,1:callable}|null
	 */
	public ?array $after_add = null;

	/**
	 * Run a step as another request, with that request's copy current.
	 *
	 * @param string   $request Request name.
	 * @param callable $step    Step to run.
	 * @return mixed What the step returns.
	 */
	public function in_request( string $request, callable $step ) {
		$previous      = $this->request;
		$this->request = $request;
		try {
			return $step();
		} finally {
			$this->request = $previous;
		}
	}

	/**
	 * Delete a key on the server only, as the cache does when it loses a key.
	 *
	 * @param string $key Key.
	 */
	public function delete_on_server( string $key ): void {
		unset( $this->server[ $key ] );
	}

	/**
	 * Read a key on the server, without touching any copy.
	 *
	 * @param string $key Key.
	 * @return mixed The value, or false when the server does not have it.
	 */
	public function server_value( string $key ) {
		return array_key_exists( $key, $this->server ) ? $this->server[ $key ] : false;
	}

	/**
	 * Read from the request's copy unless forced; otherwise from the server. A miss on the server leaves the copy as it was.
	 *
	 * @param int|string $key   Key.
	 * @param string     $group Group.
	 * @param bool       $force Whether to read the server.
	 * @param bool|null  $found Whether the key was found.
	 * @return mixed
	 */
	public function get( $key, $group = 'default', $force = false, &$found = null ) {
		if ( 'transient' !== $group ) {
			return parent::get( $key, $group, $force, $found );
		}
		$key = (string) $key;
		if ( ! $force && isset( $this->copies[ $this->request ] ) && array_key_exists( $key, $this->copies[ $this->request ] ) ) {
			$found = true;
			return $this->copies[ $this->request ][ $key ];
		}
		$found = array_key_exists( $key, $this->server );
		if ( ! $found ) {
			return false;
		}
		$this->copies[ $this->request ][ $key ] = $this->server[ $key ];

		return $this->server[ $key ];
	}

	/**
	 * Add on the server when it lacks the key, never consulting the request's copy.
	 *
	 * @param int|string $key    Key.
	 * @param mixed      $data   Data.
	 * @param string     $group  Group.
	 * @param int        $expire Expiry.
	 * @return bool
	 */
	public function add( $key, $data, $group = 'default', $expire = 0 ) {
		if ( 'transient' !== $group ) {
			return parent::add( $key, $data, $group, $expire );
		}
		$key = (string) $key;
		if ( array_key_exists( $key, $this->server ) ) {
			return false;
		}
		$this->server[ $key ]                   = $data;
		$this->copies[ $this->request ][ $key ] = $data;
		if ( null !== $this->after_add && $key === $this->after_add[0] ) {
			$step            = $this->after_add[1];
			$this->after_add = null;
			$step();
		}

		return true;
	}

	/**
	 * Write the server and the request's copy.
	 *
	 * @param int|string $key    Key.
	 * @param mixed      $data   Data.
	 * @param string     $group  Group.
	 * @param int        $expire Expiry.
	 * @return bool
	 */
	public function set( $key, $data, $group = 'default', $expire = 0 ) {
		if ( 'transient' !== $group ) {
			return parent::set( $key, $data, $group, $expire );
		}
		$this->server[ (string) $key ]                   = $data;
		$this->copies[ $this->request ][ (string) $key ] = $data;

		return true;
	}

	/**
	 * Delete from the request's copy and the server.
	 *
	 * @param int|string $key        Key.
	 * @param string     $group      Group.
	 * @param bool       $deprecated Unused.
	 * @return bool Whether the server had the key.
	 */
	public function delete( $key, $group = 'default', $deprecated = false ) {
		if ( 'transient' !== $group ) {
			return parent::delete( $key, $group, $deprecated );
		}
		$key = (string) $key;
		unset( $this->copies[ $this->request ][ $key ] );
		$deleted = array_key_exists( $key, $this->server );
		unset( $this->server[ $key ] );

		return $deleted;
	}
}
