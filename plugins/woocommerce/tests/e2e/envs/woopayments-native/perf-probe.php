<?php
/**
 * Plugin Name: WooCommerce Native Payments Performance Probe
 * Description: Internal must-use probe for native payments performance comparisons.
 *
 * @package WooCommerce\Tests\E2E
 */

declare( strict_types = 1 );

defined( 'ABSPATH' ) || exit;

/**
 * Emits request measurements for the native payments performance runner.
 */
final class WooCommerce_Native_Payments_Perf_Probe {
	/**
	 * The marker required before this fixture handles a WP-CLI command.
	 *
	 * @var string
	 */
	private const CLI_MARKER = 'woocommerce-native-perf-helper';

	/**
	 * The validated performance comparison control.
	 *
	 * @var array<string, string>|null
	 */
	private $control;

	/**
	 * The number of times the bootstrap control filter runs.
	 *
	 * @var int
	 */
	private $bootstrap_calls = 0;

	/**
	 * The number of outbound HTTP requests attempted during the measured request.
	 *
	 * @var int
	 */
	private $http_requests = 0;

	/**
	 * The state selected for local attribution, or an empty string.
	 *
	 * @var string
	 */
	private $trace_state = '';

	/**
	 * Queries and callers observed during a local attribution request.
	 *
	 * @var array<int, array{0: string, 1: array<int, string>}>
	 */
	private $query_trace = array();

	/**
	 * Handle the runner's product and reference-plugin operations.
	 *
	 * Arguments are supplied by `wp eval-file` in its local `$args` variable.
	 *
	 * @param array<int, string> $arguments Positional command arguments.
	 * @return void
	 */
	public static function run_cli_command( array $arguments ): void {
		if ( self::CLI_MARKER !== ( $arguments[0] ?? '' ) ) {
			self::cli_error( 'invalid-helper-marker' );
		}

		switch ( $arguments[1] ?? '' ) {
			case 'ensure-product':
				self::ensure_product();
				return;
			case 'install-reference':
				self::install_reference(
					self::require_cli_argument( $arguments, 2 ),
					self::require_cli_argument( $arguments, 3 ),
					self::require_cli_argument( $arguments, 4 )
				);
				return;
			default:
				self::cli_error( 'invalid-helper-operation' );
		}
	}

	/**
	 * Exit a fixture helper command with a short error token.
	 *
	 * @param string $message Error token.
	 * @return never
	 */
	private static function cli_error( string $message ): void {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- WP-CLI machine output is written directly to STDERR.
		fwrite( STDERR, $message . "\n" );
		exit( 1 );
	}

	/**
	 * Require one positional WP-CLI argument.
	 *
	 * @param array<int, string> $arguments Positional command arguments.
	 * @param int                $index Argument index.
	 * @return string
	 */
	private static function require_cli_argument( array $arguments, int $index ): string {
		if ( ! isset( $arguments[ $index ] ) || '' === $arguments[ $index ] ) {
			self::cli_error( 'missing-helper-argument' );
		}

		return $arguments[ $index ];
	}

	/**
	 * Ensure and print one product plus the canonical request targets.
	 *
	 * @return void
	 */
	private static function ensure_product(): void {
		/** @var array<int, WC_Product> $products */
		$products = wc_get_products(
			array(
				'status' => 'publish',
				'type'   => 'simple',
				'limit'  => 1,
			)
		);
		if ( empty( $products ) ) {
			$product = new WC_Product_Simple();
			$product->set_name( 'WooPayments Native Performance Product' );
			$product->set_status( 'publish' );
			$product->set_catalog_visibility( 'visible' );
			$product->set_regular_price( '10' );
			$product->set_virtual( true );
			$product->set_stock_status( 'instock' );
			if ( 0 === $product->save() ) {
				self::cli_error( 'performance-product-create-failed' );
			}
		} elseif ( $products[0] instanceof WC_Product ) {
			$product = $products[0];
		} else {
			self::cli_error( 'invalid-performance-product' );
		}

		foreach ( array( 'shop', 'cart', 'checkout' ) as $page_name ) {
			$page = get_post( wc_get_page_id( $page_name ) );
			if ( ! $page instanceof WP_Post || 'publish' !== $page->post_status ) {
				WC_Install::create_pages();
				break;
			}
		}

		$shop_page_id     = wc_get_page_id( 'shop' );
		$cart_page_id     = wc_get_page_id( 'cart' );
		$checkout_page_id = wc_get_page_id( 'checkout' );
		if ( 0 >= $shop_page_id || 0 >= $cart_page_id || 0 >= $checkout_page_id ) {
			self::cli_error( 'missing-performance-page' );
		}
		$urls    = array(
			home_url( '/' ),
			get_permalink( $shop_page_id ),
			get_permalink( $product->get_id() ),
			get_permalink( $cart_page_id ),
			get_permalink( $checkout_page_id ),
			rest_url( 'wc/store/v1/cart' ),
		);
		$targets = array();
		foreach ( $urls as $url ) {
			if ( ! is_string( $url ) ) {
				self::cli_error( 'invalid-performance-url' );
			}
			$targets[] = self::request_target( $url );
		}

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Tab-separated WP-CLI machine output is not HTML.
		echo $product->get_id() . "\t" . implode( "\t", $targets ) . "\n";
	}

	/**
	 * Return the path and query that identify a generated WordPress URL.
	 *
	 * @param string $url Absolute URL.
	 * @return string Request target.
	 */
	private static function request_target( string $url ): string {
		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) || empty( $parts['host'] ) ) {
			self::cli_error( 'invalid-performance-url' );
		}
		$target = isset( $parts['path'] ) && is_string( $parts['path'] ) && '' !== $parts['path'] ? $parts['path'] : '/';
		if ( '/' !== substr( $target, 0, 1 ) ) {
			self::cli_error( 'invalid-performance-url' );
		}
		if ( isset( $parts['query'] ) && is_string( $parts['query'] ) && '' !== $parts['query'] ) {
			$target .= '?' . $parts['query'];
		}
		return $target;
	}

	/**
	 * Download and stage the pinned reference plugin under an isolated slug.
	 *
	 * @param string $url Reference archive URL.
	 * @param string $expected_sha256 Expected archive SHA-256.
	 * @param string $target_slug Target plugin directory slug.
	 * @return void
	 */
	private static function install_reference( string $url, string $expected_sha256, string $target_slug ): void {
		if ( 'woocommerce-payments-reference' !== $target_slug ) {
			self::cli_error( 'invalid-reference-target' );
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';
		$archive = download_url( $url );
		if ( is_wp_error( $archive ) ) {
			self::cli_error( 'reference-download-failed' );
		}
		$actual_sha256 = hash_file( 'sha256', $archive );
		if ( false === $actual_sha256 || ! hash_equals( $expected_sha256, $actual_sha256 ) ) {
			wp_delete_file( $archive );
			self::cli_error( 'reference-integrity' );
		}

		$stage  = WP_PLUGIN_DIR . '/woocommerce-payments-reference-stage';
		$target = WP_PLUGIN_DIR . '/' . $target_slug;
		if ( file_exists( $stage ) || file_exists( $target ) ) {
			wp_delete_file( $archive );
			self::cli_error( 'reference-target-exists' );
		}

		$zip = new ZipArchive();
		if ( true !== $zip->open( $archive ) || ! $zip->extractTo( $stage ) ) {
			wp_delete_file( $archive );
			self::cli_error( 'reference-extract-failed' );
		}
		$zip->close();
		wp_delete_file( $archive );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename,WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- The fixture atomically claims and removes its isolated staging directory.
		if ( ! is_dir( $stage . '/woocommerce-payments' ) || ! rename( $stage . '/woocommerce-payments', $target ) || ! rmdir( $stage ) ) {
			self::cli_error( 'reference-stage-failed' );
		}

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Verified SHA-256 WP-CLI machine output is not HTML.
		echo $expected_sha256 . "\n";
	}

	/**
	 * Register the probe filters and shutdown reporter.
	 *
	 * @return void
	 */
	public function register(): void {
		$this->control = get_option( 'woocommerce_native_payments_perf_probe_control', null );
		ob_start();
		if ( ! $this->is_valid_control() ) {
			add_action( 'shutdown', array( $this, 'send_invalid_control_header' ), 0 );
			return;
		}
		$this->trace_state = $this->get_trace_state();

		add_filter( 'woocommerce_native_payments_bootstrap_enabled', array( $this, 'control_bootstrap' ) );
		add_filter( 'woocommerce_native_payments_enabled', array( $this, 'enable_native_runtime' ) );
		add_filter( 'pre_http_request', array( $this, 'count_http_request' ), PHP_INT_MIN );
		if ( '' !== $this->trace_state ) {
			add_filter( 'query', array( $this, 'handle_query' ), 9999 );
		}
		add_action( 'plugins_loaded', array( $this, 'make_reference_plugin_win' ), PHP_INT_MIN );
		add_action( 'shutdown', array( $this, 'send_measurement_header' ), 0 );
	}

	/**
	 * Return the whitelisted local attribution state requested by the runner.
	 *
	 * @return string Attribution state, or an empty string.
	 */
	private function get_trace_state(): string {
		$trace_state = $_SERVER['HTTP_X_WOOCOMMERCE_NATIVE_PAYMENTS_PERF_TRACE'] ?? '';
		if ( ! is_string( $trace_state ) || ! in_array( $trace_state, array( 'baseline_noop', 'disabled' ), true ) ) {
			return '';
		}

		return $trace_state === $this->get_control_value( 'state' ) ? $trace_state : '';
	}

	/**
	 * Record a query and its caller chain during a local attribution request.
	 *
	 * @internal
	 *
	 * @param mixed $sql Database query.
	 * @return mixed Unchanged database query.
	 */
	public function handle_query( $sql ) {
		if ( ! is_string( $sql ) ) {
			return $sql;
		}

		$this->query_trace[] = array( $sql, wp_debug_backtrace_summary( null, 0, false ) );
		return $sql;
	}

	/**
	 * Tell whether the control option has the expected private shape.
	 *
	 * @return bool True when the control is valid.
	 */
	private function is_valid_control(): bool {
		$states = array( 'baseline_noop', 'disabled', 'available', 'connected', 'active_native', 'active_plugin' );
		return is_array( $this->control ) && isset( $this->control['state'], $this->control['reference_plugin_slug'] ) && in_array( $this->control['state'], $states, true ) && 'woocommerce-payments-reference/woocommerce-payments.php' === $this->control['reference_plugin_slug'];
	}

	/**
	 * Disable the branch-native bootstrap only for the no-op reference request.
	 *
	 * @param bool $_enabled Whether bootstrap is enabled.
	 * @return bool Whether bootstrap is enabled.
	 */
	public function control_bootstrap( $_enabled ): bool {
		unset( $_enabled );
		++$this->bootstrap_calls;
		return 'baseline_noop' !== $this->get_control_value( 'state' );
	}

	/**
	 * Enable the current native runtime regardless of the future state option.
	 *
	 * @param bool $_enabled Whether native payments are enabled.
	 * @return bool True.
	 */
	public function enable_native_runtime( $_enabled ): bool {
		unset( $_enabled );
		return true;
	}

	/**
	 * Count an outbound HTTP attempt without changing whether WordPress performs it.
	 *
	 * @param mixed $preempt A preemptive HTTP response or false.
	 * @return mixed Unchanged preemptive response.
	 */
	public function count_http_request( $preempt ) {
		++$this->http_requests;
		return $preempt;
	}

	/**
	 * Make the isolated reference plugin win without including the canonical plugin file.
	 *
	 * @return void
	 */
	public function make_reference_plugin_win(): void {
		if ( 'active_plugin' !== $this->get_control_value( 'state' ) || ! $this->is_reference_plugin_loaded() ) {
			return;
		}

		add_filter(
			'option_active_plugins',
			static function ( $plugins ) {
				$plugins   = (array) $plugins;
				$plugins[] = 'woocommerce-payments/woocommerce-payments.php';
				return array_values( array_unique( $plugins ) );
			}
		);
	}

	/**
	 * Tell whether WordPress loaded the isolated reference plugin.
	 *
	 * @return bool True when the reference plugin is loaded.
	 */
	private function is_reference_plugin_loaded(): bool {
		return in_array( WP_PLUGIN_DIR . '/' . $this->get_control_value( 'reference_plugin_slug' ), get_included_files(), true );
	}

	/**
	 * Get a value from the validated private control.
	 *
	 * @param string $key Control key.
	 * @return string Control value.
	 */
	private function get_control_value( string $key ): string {
		return is_array( $this->control ) && isset( $this->control[ $key ] ) ? $this->control[ $key ] : '';
	}

	/**
	 * Send a short error header when the control option is invalid.
	 *
	 * @return void
	 */
	public function send_invalid_control_header(): void {
		header( 'X-WooCommerce-Native-Payments-Probe-Error: invalid-control' );
	}

	/**
	 * Send the request measurement header without resolving WooCommerce services.
	 *
	 * @return void
	 */
	public function send_measurement_header(): void {
		global $wp_filter;

		$tiers = array(
			'baseline_noop' => 'noop',
			'disabled'      => 'disabled',
			'available'     => 'available',
			'connected'     => 'connected',
			'active_native' => 'active',
			'active_plugin' => 'active',
		);
		$owner = $this->is_reference_plugin_loaded() ? 'plugin' : 'native';
		$state = $this->get_control_value( 'state' );
		$this->write_attribution_artifacts();
		header( sprintf( 'X-WooCommerce-Native-Payments-Probe: state=%s;tier=%s;owner=%s;bootstrap_calls=%d;queries=%d;used_peak_bytes=%d;hooks=%d;files=%d;http=%d', $state, $tiers[ $state ], $owner, $this->bootstrap_calls, get_num_queries(), memory_get_peak_usage( false ), count( $wp_filter ), count( get_included_files() ), $this->http_requests ) );
	}

	/**
	 * Write deterministic included-file and query traces for a local attribution request.
	 *
	 * @return void
	 */
	private function write_attribution_artifacts(): void {
		if ( '' === $this->trace_state ) {
			return;
		}

		$uploads = wp_upload_dir();
		$basedir = $uploads['basedir'] ?? '';
		if ( ! is_string( $basedir ) || ! is_dir( $basedir ) ) {
			return;
		}

		$files = get_included_files();
		sort( $files, SORT_STRING );
		$query_lines = array();
		foreach ( $this->query_trace as $query ) {
			$sql = preg_replace( array( '/\s+/', '/\b\d{2,}\b/' ), array( ' ', '?' ), $query[0] );
			if ( ! is_string( $sql ) ) {
				continue;
			}
			$frames = array_reverse(
				array_filter(
					$query[1],
					static function ( string $frame ): bool {
						return 1 !== preg_match( '/^(wpdb|WP_Hook|apply_filters|do_action|require|include|WooCommerce_Native_Payments_Perf_Probe|\{closure\})/', $frame );
					}
				)
			);
			$query_lines[] = trim( $sql ) . "\t" . implode( ' < ', array_slice( $frames, 0, 12 ) );
		}

		$prefix = $basedir . '/woocommerce-native-perf-' . $this->trace_state;
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- The local-only probe writes runner-requested diagnostic artifacts.
		file_put_contents( $prefix . '-files.txt', implode( "\n", $files ) . "\n" );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- The local-only probe writes runner-requested diagnostic artifacts.
		file_put_contents( $prefix . '-queries.tsv', implode( "\n", $query_lines ) . ( empty( $query_lines ) ? '' : "\n" ) );
	}
}

if ( defined( 'WP_CLI' ) && WP_CLI && isset( $args ) && is_array( $args ) && 'woocommerce-native-perf-helper' === ( $args[0] ?? '' ) ) {
	WooCommerce_Native_Payments_Perf_Probe::run_cli_command( $args );
	return;
}

$woocommerce_native_payments_perf_probe = new WooCommerce_Native_Payments_Perf_Probe();
$woocommerce_native_payments_perf_probe->register();
