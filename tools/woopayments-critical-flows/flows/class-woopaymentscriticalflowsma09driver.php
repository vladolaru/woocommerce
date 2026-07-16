<?php
/**
 * Deterministic MA-09 transactions performance driver.
 *
 * @package WooCommerce\Tools\WooPaymentsCriticalFlows
 */

/**
 * Reconcile a large transactions ledger and measure representative REST calls.
 */
final class WooPaymentsCriticalFlowsMa09Driver {

	private const LIST_ROUTE   = '/wc/v3/payments/transactions';
	private const MINIMUM_ROWS = 500;
	private const LEDGER_PAGE  = 100;
	private const DEFAULT_PAGE = 25;
	private const DEEP_PAGE    = 20;
	private const MAX_PAGES    = 100;
	private const SAMPLE_COUNT = 3;
	private const MAX_SECONDS  = 10.0;
	private const CHECK_NAMES  = array(
		'route_registered',
		'dataset_minimum',
		'unique_ledger',
		'stable_ledger',
		'page_one_full',
		'deep_page_full',
		'pagination_exact',
		'type_filter_exact',
		'date_filter_exact',
	);

	/**
	 * Execute the driver.
	 *
	 * @internal
	 *
	 * @param array<int,string> $tool_args WP-CLI eval-file arguments.
	 */
	public static function run( array $tool_args ): void {
		$store    = (string) ( $tool_args[0] ?? '' );
		$errors   = array();
		$blockers = array();
		$checks   = array_fill_keys( self::CHECK_NAMES, false );
		$owner    = self::runtime_owner();

		if ( ! in_array( $store, array( 'ref', 'target' ), true ) ) {
			$blockers[] = 'Store must be ref or target.';
		}
		$expected_owner = 'ref' === $store ? 'plugin' : 'native';
		if ( $owner !== $expected_owner ) {
			$blockers[] = 'The active WooPayments runtime owner is not the expected store owner.';
		}
		$user = wp_get_current_user();
		if ( ! $user->exists() || ! current_user_can( 'manage_woocommerce' ) ) { // phpcs:ignore WordPress.WP.Capabilities.Unknown
			$blockers[] = 'The current user cannot manage WooCommerce.';
		}

		$routes                     = rest_get_server()->get_routes();
		$checks['route_registered'] = isset( $routes[ self::LIST_ROUTE ] );
		if ( ! $checks['route_registered'] ) {
			$blockers[] = 'The transactions REST route is not registered.';
		}

		if ( ! empty( $blockers ) ) {
			self::emit( self::payload( $store, $owner, 0, '', $checks, array(), $errors, $blockers ) );
			return;
		}

		$ledger_error_count = count( $errors );
		$ledger_complete    = false;
		$ledger             = self::fetch_all( array(), self::LEDGER_PAGE, $errors, $blockers, $ledger_complete );
		$count              = count( $ledger );
		$ids                = self::ids( $ledger );

		$checks['dataset_minimum'] = $ledger_complete && $count >= self::MINIMUM_ROWS;
		$checks['unique_ledger']   = count( array_unique( $ids ) ) === $count && ! in_array( '', $ids, true );
		if ( $ledger_complete && count( $errors ) === $ledger_error_count && ! $checks['dataset_minimum'] ) {
			$blockers[] = sprintf( 'The store has %d transactions; at least %d are required.', $count, self::MINIMUM_ROWS );
		}
		if ( ! $checks['unique_ledger'] ) {
			$errors[] = 'The exhaustive ledger contains duplicate or missing transaction IDs.';
		}

		$ledger_digest = self::digest_rows( $ledger );
		if ( ! empty( $blockers ) ) {
			self::emit( self::payload( $store, $owner, $count, $ledger_digest, $checks, array(), $errors, $blockers ) );
			return;
		}

		$default_complete           = false;
		$default_rows               = self::fetch_all( array(), self::DEFAULT_PAGE, $errors, $blockers, $default_complete );
		$expected_page_one          = array_slice( $ledger, 0, self::DEFAULT_PAGE );
		$expected_deep_page         = array_slice( $ledger, ( self::DEEP_PAGE - 1 ) * self::DEFAULT_PAGE, self::DEFAULT_PAGE );
		$observed_page_one          = array_slice( $default_rows, 0, self::DEFAULT_PAGE );
		$observed_deep_page         = array_slice( $default_rows, ( self::DEEP_PAGE - 1 ) * self::DEFAULT_PAGE, self::DEFAULT_PAGE );
		$checks['page_one_full']    = self::DEFAULT_PAGE === count( $observed_page_one );
		$checks['deep_page_full']   = self::DEFAULT_PAGE === count( $observed_deep_page );
		$checks['pagination_exact'] = $default_complete
			&& self::row_signatures( $default_rows ) === self::row_signatures( $ledger )
			&& self::row_signatures( $observed_page_one ) === self::row_signatures( $expected_page_one )
			&& self::row_signatures( $observed_deep_page ) === self::row_signatures( $expected_deep_page );
		if ( ! $checks['page_one_full'] ) {
			$errors[] = 'Page 1 did not return a full default-size page.';
		}
		if ( ! $checks['deep_page_full'] ) {
			$errors[] = 'Page 20 did not return a full default-size page.';
		}
		if ( ! $checks['pagination_exact'] ) {
			$errors[] = 'The 25-row pagination sequence does not exactly match the exhaustive ledger.';
		}

		$type_filter                 = array( 'type_is' => 'charge' );
		$expected_types              = array_values(
			array_filter(
				$ledger,
				static fn ( array $row ): bool => 'charge' === (string) ( $row['type'] ?? '' )
			)
		);
		$type_complete               = false;
		$type_rows                   = self::fetch_all( $type_filter, self::LEDGER_PAGE, $errors, $blockers, $type_complete );
		$checks['type_filter_exact'] = $type_complete && self::row_signatures( $type_rows ) === self::row_signatures( $expected_types );
		if ( ! $checks['type_filter_exact'] ) {
			$errors[] = 'The charge-filtered ledger does not equal the exact charge projection.';
		}

		$date_bounds                 = self::newest_day_bounds( $ledger, $blockers );
		$date_filter                 = array(
			'date_after'  => $date_bounds['after'],
			'date_before' => $date_bounds['before'],
		);
		$expected_dates              = array_values(
			array_filter(
				$ledger,
				static function ( array $row ) use ( $date_bounds ): bool {
					$date = (string) ( $row['date'] ?? '' );
					return $date >= $date_bounds['after'] && $date <= $date_bounds['before'];
				}
			)
		);
		$date_complete               = false;
		$date_rows                   = self::fetch_all( $date_filter, self::LEDGER_PAGE, $errors, $blockers, $date_complete );
		$checks['date_filter_exact'] = $date_complete && self::row_signatures( $date_rows ) === self::row_signatures( $expected_dates );
		if ( ! $checks['date_filter_exact'] ) {
			$errors[] = 'The date-filtered ledger does not equal the exact in-window projection.';
		}

		$timing_queries = array(
			'page_1'      => array(
				'filters'  => array(),
				'page'     => 1,
				'expected' => $expected_page_one,
			),
			'page_20'     => array(
				'filters'  => array(),
				'page'     => self::DEEP_PAGE,
				'expected' => $expected_deep_page,
			),
			'type_filter' => array(
				'filters'  => $type_filter,
				'page'     => 1,
				'expected' => array_slice( $expected_types, 0, self::DEFAULT_PAGE ),
			),
			'date_filter' => array(
				'filters'  => $date_filter,
				'page'     => 1,
				'expected' => array_slice( $expected_dates, 0, self::DEFAULT_PAGE ),
			),
		);
		$timings        = array();
		foreach ( $timing_queries as $name => $timing_query ) {
			$timings[ $name ] = self::measure(
				$name,
				$timing_query['filters'],
				$timing_query['page'],
				$timing_query['expected'],
				$errors
			);
		}

		$final_complete          = false;
		$final_ledger            = self::fetch_all( array(), self::LEDGER_PAGE, $errors, $blockers, $final_complete );
		$checks['stable_ledger'] = $final_complete && self::ids( $final_ledger ) === $ids && self::digest_rows( $final_ledger ) === $ledger_digest;
		if ( ! $checks['stable_ledger'] ) {
			$blockers[] = 'The transactions ledger changed while the performance probes were running.';
		}

		$details = array(
			'type_filter'        => 'charge',
			'type_filter_count'  => count( $type_rows ),
			'date_filter_after'  => $date_bounds['after'],
			'date_filter_before' => $date_bounds['before'],
			'date_filter_count'  => count( $date_rows ),
		);
		self::emit( self::payload( $store, $owner, $count, $ledger_digest, $checks, $timings, $errors, $blockers, $details ) );
	}

	/**
	 * Fetch every page for one exact filter shape.
	 *
	 * @param array<string,mixed> $filters  Filter parameters.
	 * @param int                 $page_size Page size.
	 * @param array<int,string>   $errors   Product errors.
	 * @param array<int,string>   $blockers Prerequisite blockers.
	 * @param bool                $complete Whether the full collection was read.
	 * @return array<int,array<string,mixed>>
	 */
	private static function fetch_all( array $filters, int $page_size, array &$errors, array &$blockers, bool &$complete ): array {
		$rows     = array();
		$complete = false;
		for ( $page = 1; $page <= self::MAX_PAGES; $page++ ) {
			$result = self::fetch_page( $filters, $page, $page_size, $errors );
			if ( null === $result ) {
				return $rows;
			}
			$rows = array_merge( $rows, $result['rows'] );
			if ( count( $result['rows'] ) < $page_size ) {
				$complete = true;
				return $rows;
			}
		}
		$blockers[] = 'Transactions pagination exceeded the safety bound.';
		return $rows;
	}

	/**
	 * Fetch one transactions page.
	 *
	 * @param array<string,mixed> $filters  Filter parameters.
	 * @param int                 $page     Page number.
	 * @param int                 $page_size Page size.
	 * @param array<int,string>   $errors   Product errors.
	 * @return array{rows:array<int,array<string,mixed>>,seconds:float}|null
	 */
	private static function fetch_page( array $filters, int $page, int $page_size, array &$errors ): ?array {
		$query   = array_merge(
			array(
				'page'      => $page,
				'paged'     => $page,
				'pagesize'  => $page_size,
				'per_page'  => $page_size,
				'sort'      => 'date',
				'orderby'   => 'date',
				'direction' => 'desc',
				'order'     => 'desc',
			),
			$filters
		);
		$request = new WP_REST_Request( 'GET', self::LIST_ROUTE );
		$request->set_query_params( $query );
		$started = microtime( true );
		try {
			$response = rest_do_request( $request );
		} catch ( Throwable $throwable ) {
			$errors[] = 'The internal REST request threw before returning a response.';
			return null;
		}
		$seconds = microtime( true ) - $started;
		if ( is_wp_error( $response ) ) {
			$errors[] = 'The transactions endpoint returned a WordPress error.';
			return null;
		}
		$payload = $response->get_data();
		if ( 200 !== $response->get_status() || ! is_array( $payload ) || ! is_array( $payload['data'] ?? null ) ) {
			$errors[] = sprintf( 'The transactions endpoint returned an invalid response for page %d.', $page );
			return null;
		}
		$rows = array_values(
			array_filter(
				$payload['data'],
				static fn ( $row ): bool => is_array( $row )
			)
		);
		if ( count( $rows ) !== count( $payload['data'] ) ) {
			$errors[] = sprintf( 'The transactions endpoint returned a non-object row for page %d.', $page );
		}
		foreach ( $rows as $row ) {
			if (
				'' === (string) ( $row['transaction_id'] ?? '' )
				|| '' === (string) ( $row['type'] ?? '' )
				|| 1 !== preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', (string) ( $row['date'] ?? '' ) )
				|| ! array_key_exists( 'amount', $row )
				|| ! array_key_exists( 'fees', $row )
				|| ! array_key_exists( 'net', $row )
				|| '' === (string) ( $row['currency'] ?? '' )
			) {
				$errors[] = sprintf( 'The transactions endpoint returned an incomplete row for page %d.', $page );
				break;
			}
		}
		return array(
			'rows'    => $rows,
			'seconds' => $seconds,
		);
	}

	/**
	 * Measure one query three times, treating the first sample as warmup.
	 *
	 * @param string                         $name     Query name.
	 * @param array<string,mixed>            $filters  Filter parameters.
	 * @param int                            $page     Page number.
	 * @param array<int,array<string,mixed>> $expected Expected rows.
	 * @param array<int,string>              $errors   Product errors.
	 * @return array{samples_seconds:array<int,float|null>,measured_median_seconds:float|null}
	 */
	private static function measure( string $name, array $filters, int $page, array $expected, array &$errors ): array {
		$samples = array();
		for ( $sample = 1; $sample <= self::SAMPLE_COUNT; $sample++ ) {
			$result = self::fetch_page( $filters, $page, self::DEFAULT_PAGE, $errors );
			if ( null === $result ) {
				$samples[] = null;
				continue;
			}
			$samples[] = $result['seconds'];
			if ( self::row_signatures( $result['rows'] ) !== self::row_signatures( $expected ) ) {
				$errors[] = sprintf( 'Timed %s sample %d returned unexpected rows.', $name, $sample );
			}
			if ( $result['seconds'] > self::MAX_SECONDS ) {
				$errors[] = sprintf( 'Timed %s sample %d exceeded 10 seconds.', $name, $sample );
			}
		}
		if ( in_array( null, $samples, true ) ) {
			$errors[] = sprintf( 'Timed %s did not produce three complete samples.', $name );
		}
		$measured          = array_slice( $samples, 1 );
		$measured_complete = ! in_array( null, $measured, true );
		return array(
			'samples_seconds'         => $samples,
			'measured_median_seconds' => $measured_complete ? array_sum( $measured ) / count( $measured ) : null,
		);
	}

	/**
	 * Build the UTC bounds for the newest observed transaction day.
	 *
	 * @param array<int,array<string,mixed>> $rows     Ledger rows.
	 * @param array<int,string>              $blockers Prerequisite blockers.
	 * @return array{after:string,before:string}
	 */
	private static function newest_day_bounds( array $rows, array &$blockers ): array {
		$date = (string) ( $rows[0]['date'] ?? '' );
		if ( 1 !== preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $date ) ) {
			$blockers[] = 'The newest transaction has no canonical UTC date.';
			return array(
				'after'  => '1970-01-01 00:00:00',
				'before' => '1970-01-01 23:59:59',
			);
		}
		$day = substr( $date, 0, 10 );
		return array(
			'after'  => $day . ' 00:00:00',
			'before' => $day . ' 23:59:59',
		);
	}

	/**
	 * Return stable transaction IDs.
	 *
	 * @param array<int,array<string,mixed>> $rows Ledger rows.
	 * @return array<int,string>
	 */
	private static function ids( array $rows ): array {
		return array_values(
			array_map( static fn ( array $row ): string => (string) ( $row['transaction_id'] ?? '' ), $rows )
		);
	}

	/**
	 * Return contract-relevant ordered row projections.
	 *
	 * @param array<int,array<string,mixed>> $rows Ledger rows.
	 * @return array<int,array<string,mixed>>
	 */
	private static function row_signatures( array $rows ): array {
		return array_values(
			array_map(
				static fn ( array $row ): array => array(
					'transaction_id' => (string) ( $row['transaction_id'] ?? '' ),
					'type'           => (string) ( $row['type'] ?? '' ),
					'date'           => (string) ( $row['date'] ?? '' ),
					'amount'         => $row['amount'] ?? null,
					'fees'           => $row['fees'] ?? null,
					'net'            => $row['net'] ?? null,
					'currency'       => (string) ( $row['currency'] ?? '' ),
				),
				$rows
			)
		);
	}

	/**
	 * Hash the contract-relevant ordered ledger projection.
	 *
	 * @param array<int,array<string,mixed>> $rows Ledger rows.
	 */
	private static function digest_rows( array $rows ): string {
		$projection = self::row_signatures( $rows );
		return 'sha256:' . hash( 'sha256', (string) wp_json_encode( $projection, JSON_UNESCAPED_SLASHES ) );
	}

	/**
	 * Build the strict result payload.
	 *
	 * @param string              $store         Store name.
	 * @param string              $owner         Runtime owner.
	 * @param int                 $count         Dataset count.
	 * @param string              $ledger_digest Ledger digest.
	 * @param array<string,bool>  $checks        State checks.
	 * @param array<string,mixed> $timings       Timing records.
	 * @param array<int,string>   $errors        Product errors.
	 * @param array<int,string>   $blockers      Prerequisite blockers.
	 * @param array<string,mixed> $details       Filter details.
	 * @return array<string,mixed>
	 */
	private static function payload( string $store, string $owner, int $count, string $ledger_digest, array $checks, array $timings, array $errors, array $blockers, array $details = array() ): array {
		$status = ! empty( $blockers ) ? 'blocked' : ( ! empty( $errors ) ? 'fail' : 'pass' );
		return array_merge(
			array(
				'schema'             => 'woopayments_ma09_deterministic.v1',
				'status'             => $status,
				'store'              => $store,
				'runtime_owner'      => $owner,
				'dataset_count'      => $count,
				'seeded_count'       => $count,
				'page_size'          => self::DEFAULT_PAGE,
				'deep_page'          => self::DEEP_PAGE,
				'ledger_sha256'      => '' !== $ledger_digest ? $ledger_digest : 'sha256:' . str_repeat( '0', 64 ),
				'type_filter'        => 'charge',
				'type_filter_count'  => 0,
				'date_filter_after'  => '1970-01-01 00:00:00',
				'date_filter_before' => '1970-01-01 23:59:59',
				'date_filter_count'  => 0,
				'checks'             => $checks,
				'timings'            => (object) $timings,
				'errors'             => array_values( array_unique( $errors ) ),
				'blockers'           => array_values( array_unique( $blockers ) ),
			),
			$details
		);
	}

	/** Return the active WooPayments runtime owner. */
	private static function runtime_owner(): string {
		$active_plugins = (array) get_option( 'active_plugins', array() );
		$owner          = in_array( 'woocommerce-payments/woocommerce-payments.php', $active_plugins, true ) ? 'plugin' : 'none';
		if ( class_exists( '\\Automattic\\WooCommerce\\Internal\\Payments\\NativePaymentsRuntimeArbiter' ) && function_exists( 'wc_get_container' ) ) {
			try {
				$owner = (string) wc_get_container()->get( '\\Automattic\\WooCommerce\\Internal\\Payments\\NativePaymentsRuntimeArbiter' )->get_runtime_owner();
			} catch ( Throwable $throwable ) {
				$owner = 'probe_failed';
			}
		}
		return $owner;
	}

	/**
	 * Emit stable JSON.
	 *
	 * @param array<string,mixed> $payload Result payload.
	 */
	private static function emit( array $payload ): void {
		echo wp_json_encode( $payload, JSON_UNESCAPED_SLASHES ) . "\n";
	}
}

$tool_args = isset( $args ) && is_array( $args ) ? $args : array_slice( $argv ?? array(), 1 );
WooPaymentsCriticalFlowsMa09Driver::run( $tool_args );
