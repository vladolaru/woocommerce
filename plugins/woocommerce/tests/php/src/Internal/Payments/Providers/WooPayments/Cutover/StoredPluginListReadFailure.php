<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments\Cutover;

/**
 * Makes the switch's stored plugin-list reads fail as a database error does: WordPress's query filter points each one at
 * a missing table, so the real wpdb sets its last error.
 *
 * The match is by query text, not by caller. The site read's text is also WordPress's uncached get_option() read, which
 * this suite never issues because active_plugins is autoloaded and so cached, and update_option()'s autoload read, which
 * every write of active_plugins issues. A count set before such a read is consumed by it. The network read does not
 * collide: WordPress puts meta_key before site_id. The read count leaves out the autoload read by its leading SELECT.
 */
class StoredPluginListReadFailure {

	/** The site list read, as PreflightService queries it. */
	private const SITE_READ = "WHERE option_name = 'active_plugins' LIMIT 1";

	/** The network list read, as PreflightService queries it. */
	private const NETWORK_READ = "AND meta_key = 'active_sitewide_plugins' LIMIT 1";

	/** @var int Number of upcoming site list reads that fail. */
	public int $site_failures = 0;

	/** @var int Number of upcoming network list reads that fail. */
	public int $network_failures = 0;

	/** @var int Number of site and network list reads seen, by their text as PreflightService queries them. */
	public int $reads = 0;

	/** @var bool Whether wpdb suppressed errors before. */
	private bool $suppressed_errors;

	/**
	 * Start matching the reads; none fails until a count is set.
	 */
	public function __construct() {
		global $wpdb;
		$this->suppressed_errors = (bool) $wpdb->suppress_errors( true );
		add_filter( 'query', array( $this, 'fail_matching_read' ) );
	}

	/**
	 * Point a matching read at a missing table while its count lasts.
	 *
	 * @param string $query SQL query.
	 * @return string
	 */
	public function fail_matching_read( $query ) {
		if ( ! is_string( $query ) ) {
			return $query;
		}
		if ( ( 0 === strpos( $query, 'SELECT option_value FROM' ) && false !== strpos( $query, self::SITE_READ ) ) || false !== strpos( $query, self::NETWORK_READ ) ) {
			++$this->reads;
		}
		if ( $this->site_failures > 0 && false !== strpos( $query, self::SITE_READ ) ) {
			--$this->site_failures;
			return 'SELECT option_value FROM woocommerce_cutover_test_missing_table';
		}
		if ( $this->network_failures > 0 && false !== strpos( $query, self::NETWORK_READ ) ) {
			--$this->network_failures;
			return 'SELECT meta_value FROM woocommerce_cutover_test_missing_table';
		}

		return $query;
	}

	/**
	 * Stop matching and restore wpdb's error reporting.
	 */
	public function remove(): void {
		global $wpdb;
		remove_filter( 'query', array( $this, 'fail_matching_read' ) );
		$wpdb->suppress_errors( $this->suppressed_errors );
	}
}
