<?php
/**
 * Lossless debug-log observer for the WooPayments critical-flow harness.
 *
 * @package WooCommerce\Tools\WooPaymentsCriticalFlows
 */

// This observer requires direct POSIX stream and inode operations that WP_Filesystem cannot represent.
// phpcs:disable WordPress.WP.AlternativeFunctions, WordPress.PHP.NoSilencedErrors.Discouraged

/**
 * Proxy marked debug logs through authenticated, lossless FIFO observers.
 */
final class WooPaymentsCriticalFlowsLogObserver {

	private const MARKER_SCHEMA   = 'woopayments_debug_log_marker.v6';
	private const ORIGIN_SCHEMA   = 'woopayments_debug_log_origin.v2';
	private const RECORD_SCHEMA   = 'woopayments_debug_log_observer_record.v2';
	private const LEASE_SCHEMA    = 'woopayments_debug_log_forwarder_lease.v2';
	private const CONTROL_SCHEMA  = 'woopayments_debug_log_forwarder_control.v1';
	private const BACKING_SUFFIX  = '.woopayments-critical-flows.%s.backing';
	private const LEASE_SUFFIX    = '.woopayments-critical-flows.%s.lease';
	private const JOURNAL_SUFFIX  = '.woopayments-critical-flows.%s.journal';
	private const CONTROL_SUFFIX  = '.woopayments-critical-flows.%s.control';
	private const MAX_PATHS       = 8;
	private const MAX_RECORDS     = 1000;
	private const MAX_JOURNAL     = 1048576;
	private const MAX_CONTROL     = 8192;
	private const MAX_PARSER_LINE = 65536;
	private const READ_BYTES      = 8192;
	private const SELECT_USEC     = 100000;

	private const STARTUP_FRAME_MAX_BYTES   = 16;
	private const STARTUP_GATE_TIMEOUT_NS   = 2000000000;
	private const STARTUP_REAP_GRACE_NS     = 250000000;
	private const STARTUP_REAP_KILL_NS      = 2000000000;
	private const STARTUP_DRAIN_TIMEOUT_NS  = 2000000000;
	private const STARTUP_DRAIN_STABLE_USEC = 50000;
	private const STARTUP_DRAIN_MAX_BYTES   = 8388608;

	/**
	 * Raw HMAC key inherited from the observer environment.
	 *
	 * @var string
	 */
	private static $context_key = '';

	/**
	 * Exact critical-flow run stamp.
	 *
	 * @var string
	 */
	private static $run_stamp = '';

	/**
	 * Exact authenticated store/flow/purpose tuple and marker timestamp.
	 *
	 * @var string
	 */
	private static $store = '';

	/**
	 * Exact critical-flow identifier.
	 *
	 * @var string
	 */
	private static $flow_id = '';

	/**
	 * Fixed clean-log evidence purpose.
	 *
	 * @var string
	 */
	private static $purpose = '';

	/**
	 * Exact marker creation timestamp.
	 *
	 * @var string
	 */
	private static $marker_created_at = '';

	/**
	 * Sorted safe path identities repeated in every authenticated context.
	 *
	 * @var array<int,array{path:string,path_id:string}>
	 */
	private static $path_contexts = array();

	/**
	 * Authenticated observer UUID.
	 *
	 * @var string
	 */
	private static $observer_id = '';

	/**
	 * Marker-origin HMAC exposed in safe readiness evidence.
	 *
	 * @var string
	 */
	private static $origin_binding = '';

	/**
	 * Monotonic safe-record sequence.
	 *
	 * @var int
	 */
	private static $sequence = 0;

	/**
	 * Previous record HMAC without its algorithm prefix.
	 *
	 * @var string
	 */
	private static $previous_hmac = '';

	/**
	 * Active path state keyed by the original absolute path.
	 *
	 * @var array<string,array<string,mixed>>
	 */
	private static $paths = array();

	/**
	 * Whether the idempotent restoration routine has run.
	 *
	 * @var bool
	 */
	private static $restoration_attempted = false;

	/**
	 * Whether an INT or TERM handler interrupted the observer.
	 *
	 * @var bool
	 */
	private static $interrupted = false;

	/**
	 * Detached forwarder process ID.
	 *
	 * @var int
	 */
	private static $forwarder_pid = 0;

	/**
	 * Fresh authenticated forwarder nonce.
	 *
	 * @var string
	 */
	private static $forwarder_nonce = '';

	/**
	 * Same-directory durable artifact paths.
	 *
	 * @var array{lease:string,journal:string,control:string}
	 */
	private static $forwarder_artifacts = array(
		'lease'   => '',
		'journal' => '',
		'control' => '',
	);

	/**
	 * Exact identities captured when durable artifacts are exclusively reserved.
	 *
	 * @var array<string,array{kind:string,dev:int,ino:int,mode:int}>
	 */
	private static $forwarder_artifact_identities = array();

	/**
	 * Exact streams retained when durable artifacts are exclusively reserved.
	 *
	 * @var array<string,resource>
	 */
	private static $forwarder_artifact_streams = array();

	/**
	 * Exact signed lease bytes durably read back during this startup.
	 *
	 * @var string
	 */
	private static $forwarder_lease_bytes = '';

	/**
	 * Fixed reason an identity-strict ordinary restoration failed.
	 *
	 * @var string
	 */
	private static $restoration_blocker = '';

	/**
	 * Child-owned authenticated journal stream.
	 *
	 * @var resource|null
	 */
	private static $journal_stream = null;

	/**
	 * Number of safe records written to the child journal.
	 *
	 * @var int
	 */
	private static $journal_record_count = 0;

	/**
	 * Number of encoded bytes written to the child journal.
	 *
	 * @var int
	 */
	private static $journal_byte_count = 0;

	/**
	 * Whether shutdown must preserve durable artifacts for keyed recovery.
	 *
	 * @var bool
	 */
	private static $durable_forwarder_active = false;

	/**
	 * Last coordinator challenge answered by the child.
	 *
	 * @var string
	 */
	private static $last_challenge_nonce = '';

	/**
	 * Execute the requested observer action.
	 *
	 * @internal
	 *
	 * @param array<int,string> $tool_args WP-CLI eval-file arguments.
	 */
	public static function run( array $tool_args ): void {
		self::$run_stamp = (string) ( $tool_args[0] ?? '' );
		$action          = (string) ( $tool_args[1] ?? 'observe' );

		if ( ! preg_match( '/^[0-9]{8}T[0-9]{6}Z-[0-9]+$/', self::$run_stamp ) ) {
			self::exit_without_marker( 'invalid_run_stamp' );
		}

		$key_hex = getenv( 'CRITICAL_FLOWS_RUN_CONTEXT_KEY' );
		if ( ! is_string( $key_hex ) || ! preg_match( '/^[0-9a-f]{64}$/', $key_hex ) ) {
			self::exit_without_marker( 'missing_context_key' );
		}
		$key = hex2bin( $key_hex );
		if ( false === $key ) {
			self::exit_without_marker( 'missing_context_key' );
		}
		self::$context_key   = $key;
		self::$previous_hmac = str_repeat( '0', 64 );

		$marker = self::load_marker( 'observe' === $action );
		if ( null === $marker ) {
			self::exit_without_marker( 'invalid_marker_origin' );
		}

		self::$observer_id       = $marker['observer_id'];
		self::$origin_binding    = $marker['origin_binding'];
		self::$store             = $marker['store'];
		self::$flow_id           = $marker['flow_id'];
		self::$purpose           = $marker['purpose'];
		self::$marker_created_at = $marker['created_at'];
		self::$path_contexts     = self::path_contexts( $marker['paths'] );

		if ( 'recover' === $action ) {
			self::recover( $marker );
		}
		if ( 'stop' === $action ) {
			self::send_terminal_sentinels( $marker );
		}
		if ( 'observe' !== $action ) {
			self::emit_and_exit( 'blocked', array( 'blocker_code' => 'invalid_observer_action' ), 3 );
		}

		self::observe( $marker );
	}

	/**
	 * Send one authenticated terminal sentinel to every active FIFO.
	 *
	 * @param array<string,mixed> $marker Authenticated marker.
	 */
	private static function send_terminal_sentinels( array $marker ): void {
		foreach ( array_keys( $marker['paths'] ) as $path ) {
			$path_stat = @lstat( $path );
			if ( false === $path_stat || ! self::is_fifo_stat( $path_stat ) ) {
				self::emit_and_exit( 'blocked', array( 'blocker_code' => 'observer_path_replaced' ), 3 );
			}
			$material  = self::terminal_material( $path, $marker['paths'][ $path ]['path_id'] );
			$sentinel  = '[woopayments-critical-flows-log-observer-stop] hmac-sha256:';
			$sentinel .= hash_hmac( 'sha256', $material, self::$context_key ) . "\n";
			$written   = @file_put_contents( $path, $sentinel );
			if ( strlen( $sentinel ) !== $written ) {
				self::emit_and_exit( 'blocked', array( 'blocker_code' => 'sentinel_write_failed' ), 3 );
			}
		}

		self::emit( 'sentinel_sent', array( 'status' => 'pass' ) );
		exit( 0 );
	}

	/**
	 * Run the long-lived FIFO proxy.
	 *
	 * @param array<string,mixed> $marker Authenticated marker.
	 */
	private static function observe( array $marker ): void {
		if (
			! function_exists( 'posix_mkfifo' )
			|| ! function_exists( 'pcntl_fork' )
			|| ! function_exists( 'pcntl_waitpid' )
			|| ! function_exists( 'posix_kill' )
			|| ! function_exists( 'posix_setsid' )
			|| ! function_exists( 'stream_socket_pair' )
			|| ! function_exists( 'hrtime' )
		) {
			self::emit_and_exit( 'blocked', array( 'blocker_code' => 'fifo_unavailable' ), 3 );
		}

		$primary_path              = (string) array_key_first( $marker['paths'] );
		self::$forwarder_artifacts = self::forwarder_artifact_paths( $primary_path, self::$observer_id );
		foreach ( $marker['paths'] as $path => $observation ) {
			$backing_path = self::backing_path( $path, self::$observer_id );
			if ( file_exists( $backing_path ) || is_link( $backing_path ) ) {
				self::emit_and_exit( 'blocked', array( 'blocker_code' => 'backing_collision' ), 3 );
			}
		}
		foreach ( self::$forwarder_artifacts as $artifact_path ) {
			if ( file_exists( $artifact_path ) || is_link( $artifact_path ) ) {
				self::emit_and_exit( 'blocked', array( 'blocker_code' => 'forwarder_artifact_collision' ), 3 );
			}
		}
		if ( ! self::reserve_forwarder_artifacts() ) {
			self::emit_and_exit( 'blocked', array( 'blocker_code' => 'fifo_setup_failed' ), 3 );
		}

		register_shutdown_function( array( __CLASS__, 'shutdown_restore' ) );
		self::install_signal_handlers();

		foreach ( $marker['paths'] as $path => $observation ) {
			if ( ! self::proxy_path( $path, $observation ) ) {
				self::restore_all();
				self::remove_forwarder_artifacts();
				self::emit_and_exit( 'blocked', array( 'blocker_code' => 'fifo_setup_failed' ), 3 );
			}
		}
		if ( ! self::start_forwarder_foundation() ) {
			self::emit_and_exit( 'blocked', array( 'blocker_code' => 'forwarder_start_failed' ), 3 );
		}

		self::coordinate_forwarder();
	}

	/**
	 * Exclusively reserve the lease, journal, and control artifacts.
	 */
	private static function reserve_forwarder_artifacts(): bool {
		$identities = array();
		$streams    = array();
		foreach ( self::$forwarder_artifacts as $kind => $artifact_path ) {
			$stream = @fopen( $artifact_path, 'x+b' );
			if ( false === $stream ) {
				self::close_streams( $streams );
				self::remove_matching_artifacts( $identities );
				return false;
			}
			$mode_set = @chmod( $artifact_path, 0600 );
			clearstatcache( true, $artifact_path );
			$stream_stat = @fstat( $stream );
			$path_stat   = @lstat( $artifact_path );
			if (
				false === $stream_stat
				|| false === $path_stat
				|| ! self::is_regular_stat( $stream_stat )
				|| ! self::is_regular_stat( $path_stat )
				|| (int) $stream_stat['dev'] !== (int) $path_stat['dev']
				|| (int) $stream_stat['ino'] !== (int) $path_stat['ino']
			) {
				@fclose( $stream );
				self::close_streams( $streams );
				self::remove_matching_artifacts( $identities );
				return false;
			}
			$identities[ $kind ] = array(
				'kind' => $kind,
				'dev'  => (int) $path_stat['dev'],
				'ino'  => (int) $path_stat['ino'],
				'mode' => (int) $path_stat['mode'] & 0777,
			);
			$streams[ $kind ]    = $stream;
			if ( ! $mode_set || 0600 !== $identities[ $kind ]['mode'] ) {
				self::close_streams( $streams );
				self::remove_matching_artifacts( $identities );
				return false;
			}
		}

		self::$forwarder_artifact_identities = $identities;
		self::$forwarder_artifact_streams    = $streams;
		return true;
	}

	/**
	 * Start the detached child and persist its authenticated lease foundation.
	 */
	private static function start_forwarder_foundation(): bool {
		self::$forwarder_nonce = self::new_uuid();
		$gates                 = @stream_socket_pair( STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0 );
		if ( false === $gates || 2 !== count( $gates ) ) {
			self::cleanup_failed_forwarder_start( 0, null );
			return false;
		}
		if ( ! @stream_set_blocking( $gates[0], false ) || ! @stream_set_blocking( $gates[1], false ) ) {
			@fclose( $gates[0] );
			@fclose( $gates[1] );
			self::cleanup_failed_forwarder_start( 0, null );
			return false;
		}
		$child_pid = @pcntl_fork();
		if ( -1 === $child_pid ) {
			@fclose( $gates[0] );
			@fclose( $gates[1] );
			self::cleanup_failed_forwarder_start( 0, null );
			return false;
		}
		if ( 0 === $child_pid ) {
			self::$restoration_attempted = true;
			@fclose( $gates[0] );
			if ( -1 === posix_setsid() ) {
				exit( 3 );
			}
			@fclose( STDIN );
			@fclose( STDOUT );
			@fclose( STDERR );
			$deadline = self::monotonic_deadline( self::STARTUP_GATE_TIMEOUT_NS );
			if ( 'PREPARE' !== self::read_startup_frame( $gates[1], array( 'PREPARE' ), $deadline ) ) {
				exit( 3 );
			}
			self::close_forwarder_artifact_streams( array( 'journal' ) );
			self::$journal_stream             = self::$forwarder_artifact_streams['journal'] ?? null;
			self::$forwarder_artifact_streams = array();
			if ( ! is_resource( self::$journal_stream ) || ! self::journal_stream_matches_reserved_identity() ) {
				exit( 3 );
			}
			$deadline = self::monotonic_deadline( self::STARTUP_GATE_TIMEOUT_NS );
			if ( ! self::write_startup_frame( $gates[1], 'PREPARED', $deadline ) ) {
				exit( 3 );
			}
			$deadline = self::monotonic_deadline( self::STARTUP_GATE_TIMEOUT_NS );
			if ( 'COMMIT' !== self::read_startup_frame( $gates[1], array( 'COMMIT' ), $deadline ) ) {
				exit( 3 );
			}
			@fclose( $gates[1] );
			self::run_forwarder();
		}

		@fclose( $gates[1] );
		self::$forwarder_pid            = $child_pid;
		self::$durable_forwarder_active = true;
		$deadline                       = self::monotonic_deadline( self::STARTUP_GATE_TIMEOUT_NS );
		if (
			! self::write_startup_frame( $gates[0], 'PREPARE', $deadline )
			|| 'PREPARED' !== self::read_startup_frame( $gates[0], array( 'PREPARED' ), $deadline )
		) {
			self::cleanup_failed_forwarder_start( $child_pid, $gates[0] );
			return false;
		}

		$lease = self::build_forwarder_lease( $child_pid );
		if (
			! is_array( $lease )
			|| ! self::persist_forwarder_lease( $lease )
			|| ! self::complete_startup_identities_match()
			|| ! self::startup_fifos_are_quiet()
		) {
			self::cleanup_failed_forwarder_start( $child_pid, $gates[0] );
			return false;
		}

		$deadline = self::monotonic_deadline( self::STARTUP_GATE_TIMEOUT_NS );
		if ( ! self::write_startup_frame( $gates[0], 'COMMIT', $deadline ) ) {
			self::cleanup_failed_forwarder_start( $child_pid, $gates[0] );
			return false;
		}
		@fclose( $gates[0] );
		self::close_forwarder_artifact_streams();
		return true;
	}

	/**
	 * Build the exact authenticated startup lease.
	 *
	 * @param int $child_pid Exact direct child process ID.
	 * @return array<string,mixed>|false
	 */
	private static function build_forwarder_lease( int $child_pid ) {
		$paths = array();
		foreach ( self::$paths as $path => $state ) {
			$paths[] = array(
				'path'        => basename( $path ),
				'path_id'     => $state['path_id'],
				'fifo_dev'    => $state['fifo_dev'],
				'fifo_ino'    => $state['fifo_ino'],
				'backing_dev' => $state['dev'],
				'backing_ino' => $state['ino'],
			);
		}
		usort(
			$paths,
			static function ( array $left, array $right ): int {
				return strcmp( $left['path_id'], $right['path_id'] );
			}
		);
		$lease   = array(
			'schema'            => self::LEASE_SCHEMA,
			'run_stamp'         => self::$run_stamp,
			'store'             => self::$store,
			'flow_id'           => self::$flow_id,
			'purpose'           => self::$purpose,
			'marker_created_at' => self::$marker_created_at,
			'observer_id'       => self::$observer_id,
			'origin_binding'    => self::$origin_binding,
			'forwarder_nonce'   => self::$forwarder_nonce,
			'coordinator_pid'   => getmypid(),
			'child_pid'         => $child_pid,
			'journal'           => basename( self::$forwarder_artifacts['journal'] ),
			'control'           => basename( self::$forwarder_artifacts['control'] ),
			'artifacts'         => self::$forwarder_artifact_identities,
			'paths'             => $paths,
		);
		$encoded = wp_json_encode( $lease );
		if ( ! is_string( $encoded ) ) {
			return false;
		}
		$lease['hmac'] = 'hmac-sha256:' . hash_hmac( 'sha256', self::LEASE_SCHEMA . "\0" . $encoded, self::$context_key );
		$encoded       = wp_json_encode( $lease );
		if ( ! is_string( $encoded ) ) {
			return false;
		}

		return $lease;
	}

	/**
	 * Close an arbitrary finite stream set.
	 *
	 * @param array<string,resource> $streams Streams to close.
	 */
	private static function close_streams( array $streams ): void {
		foreach ( $streams as $stream ) {
			if ( is_resource( $stream ) ) {
				@fclose( $stream );
			}
		}
	}

	/**
	 * Close retained artifact streams except explicitly preserved kinds.
	 *
	 * @param array<int,string> $preserved_kinds Artifact kinds to retain.
	 */
	private static function close_forwarder_artifact_streams( array $preserved_kinds = array() ): void {
		foreach ( self::$forwarder_artifact_streams as $kind => $stream ) {
			if ( in_array( $kind, $preserved_kinds, true ) ) {
				continue;
			}
			if ( is_resource( $stream ) ) {
				@fclose( $stream );
			}
			unset( self::$forwarder_artifact_streams[ $kind ] );
		}
	}

	/**
	 * Return a finite deadline from the monotonic clock.
	 *
	 * @param int $duration_ns Positive duration in nanoseconds.
	 */
	private static function monotonic_deadline( int $duration_ns ): int {
		return (int) hrtime( true ) + $duration_ns;
	}

	/**
	 * Wait for one stream direction until a monotonic deadline.
	 *
	 * @param resource $stream      Open stream.
	 * @param bool     $write       Whether to wait for writability.
	 * @param int      $deadline_ns Monotonic deadline in nanoseconds.
	 */
	private static function wait_for_stream( $stream, bool $write, int $deadline_ns ): bool {
		$remaining_ns = $deadline_ns - (int) hrtime( true );
		if ( $remaining_ns <= 0 ) {
			return false;
		}
		$seconds        = intdiv( $remaining_ns, 1000000000 );
		$microseconds   = (int) intdiv( $remaining_ns % 1000000000, 1000 );
		$read_streams   = $write ? null : array( $stream );
		$write_streams  = $write ? array( $stream ) : null;
		$except_streams = null;
		$selected       = @stream_select( $read_streams, $write_streams, $except_streams, $seconds, max( 1, $microseconds ) );
		return is_int( $selected ) && 1 === $selected;
	}

	/**
	 * Write one complete finite startup frame.
	 *
	 * @param resource $stream      Gate stream.
	 * @param string   $token       Finite protocol token.
	 * @param int      $deadline_ns Monotonic deadline in nanoseconds.
	 */
	private static function write_startup_frame( $stream, string $token, int $deadline_ns ): bool {
		if ( ! in_array( $token, array( 'PREPARE', 'PREPARED', 'COMMIT' ), true ) ) {
			return false;
		}
		$frame  = pack( 'N', strlen( $token ) ) . $token;
		$length = strlen( $frame );
		$offset = 0;
		while ( $offset < $length ) {
			if ( ! self::wait_for_stream( $stream, true, $deadline_ns ) ) {
				return false;
			}
			$written = @fwrite( $stream, substr( $frame, $offset ) );
			if ( false === $written || 0 === $written ) {
				return false;
			}
			$offset += $written;
		}
		return true;
	}

	/**
	 * Read an exact byte count from one gate stream.
	 *
	 * @param resource $stream      Gate stream.
	 * @param int      $length      Exact positive byte count.
	 * @param int      $deadline_ns Monotonic deadline in nanoseconds.
	 * @return string|false
	 */
	private static function read_startup_bytes( $stream, int $length, int $deadline_ns ) {
		$bytes        = '';
		$bytes_length = 0;
		while ( $bytes_length < $length ) {
			if ( ! self::wait_for_stream( $stream, false, $deadline_ns ) ) {
				return false;
			}
			$chunk = @fread( $stream, $length - $bytes_length );
			if ( ! is_string( $chunk ) || '' === $chunk ) {
				return false;
			}
			$bytes        .= $chunk;
			$bytes_length += strlen( $chunk );
		}
		return $bytes;
	}

	/**
	 * Read one complete finite startup frame.
	 *
	 * @param resource          $stream         Gate stream.
	 * @param array<int,string> $expected_tokens Allowed tokens in this state.
	 * @param int               $deadline_ns    Monotonic deadline in nanoseconds.
	 * @return string|false
	 */
	private static function read_startup_frame( $stream, array $expected_tokens, int $deadline_ns ) {
		$header = self::read_startup_bytes( $stream, 4, $deadline_ns );
		if ( ! is_string( $header ) ) {
			return false;
		}
		$unpacked = unpack( 'Nlength', $header );
		$length   = is_array( $unpacked ) ? (int) ( $unpacked['length'] ?? 0 ) : 0;
		if ( $length < 1 || $length > self::STARTUP_FRAME_MAX_BYTES ) {
			return false;
		}
		$token = self::read_startup_bytes( $stream, $length, $deadline_ns );
		return is_string( $token ) && in_array( $token, $expected_tokens, true ) ? $token : false;
	}

	/**
	 * Check a retained artifact stream against both reserved and named identity.
	 *
	 * @param string   $kind   Artifact kind.
	 * @param resource $stream Retained stream.
	 */
	private static function artifact_stream_matches_reserved_identity( string $kind, $stream ): bool {
		$identity = self::$forwarder_artifact_identities[ $kind ] ?? null;
		$stat     = is_resource( $stream ) ? @fstat( $stream ) : false;
		return is_array( $identity )
			&& is_array( $stat )
			&& self::is_regular_stat( $stat )
			&& (int) $stat['dev'] === $identity['dev']
			&& (int) $stat['ino'] === $identity['ino']
			&& ( (int) $stat['mode'] & 0777 ) === $identity['mode']
			&& self::artifact_identity_matches( $kind, $identity );
	}

	/**
	 * Check the child journal descriptor and pathname against its reservation.
	 */
	private static function journal_stream_matches_reserved_identity(): bool {
		return self::artifact_stream_matches_reserved_identity( 'journal', self::$journal_stream );
	}

	/**
	 * Persist, synchronize, read back, and authenticate the exact lease inode.
	 *
	 * @param array<string,mixed> $lease Signed expected lease.
	 */
	private static function persist_forwarder_lease( array $lease ): bool {
		$stream = self::$forwarder_artifact_streams['lease'] ?? null;
		if ( ! is_resource( $stream ) || ! self::artifact_stream_matches_reserved_identity( 'lease', $stream ) ) {
			return false;
		}
		$encoded = wp_json_encode( $lease );
		if ( ! is_string( $encoded ) || 0 !== @fseek( $stream, 0, SEEK_SET ) || ! @ftruncate( $stream, 0 ) ) {
			return false;
		}
		$offset = 0;
		$length = strlen( $encoded );
		while ( $offset < $length ) {
			$written = @fwrite( $stream, substr( $encoded, $offset ) );
			if ( false === $written || 0 === $written ) {
				return false;
			}
			$offset += $written;
		}
		if (
			! @fflush( $stream )
			|| ! self::sync_stream( $stream )
			|| 0 !== @fseek( $stream, 0, SEEK_SET )
		) {
			return false;
		}
		$readback = @stream_get_contents( $stream );
		if ( ! is_string( $readback ) || $encoded !== $readback ) {
			return false;
		}
		$decoded = json_decode( $readback, true );
		if ( ! is_array( $decoded ) || $decoded !== $lease || ! isset( $decoded['hmac'] ) || ! is_string( $decoded['hmac'] ) ) {
			return false;
		}
		$signature = $decoded['hmac'];
		unset( $decoded['hmac'] );
		$unsigned = wp_json_encode( $decoded );
		$expected = is_string( $unsigned )
			? 'hmac-sha256:' . hash_hmac( 'sha256', self::LEASE_SCHEMA . "\0" . $unsigned, self::$context_key )
			: '';
		$valid    = '' !== $expected
			&& hash_equals( $expected, $signature )
			&& self::artifact_stream_matches_reserved_identity( 'lease', $stream );
		if ( $valid ) {
			self::$forwarder_lease_bytes = $encoded;
		}
		return $valid;
	}

	/**
	 * Compare one retained artifact stream with exact expected bytes.
	 *
	 * @param string $kind     Artifact kind.
	 * @param string $expected Exact expected bytes.
	 */
	private static function artifact_stream_contents_match( string $kind, string $expected ): bool {
		$stream = self::$forwarder_artifact_streams[ $kind ] ?? null;
		if ( ! is_resource( $stream ) || ! self::artifact_stream_matches_reserved_identity( $kind, $stream ) ) {
			return false;
		}
		$stat = @fstat( $stream );
		if ( ! is_array( $stat ) || strlen( $expected ) !== (int) $stat['size'] || 0 !== @fseek( $stream, 0, SEEK_SET ) ) {
			return false;
		}
		$actual = @stream_get_contents( $stream );
		return is_string( $actual ) && $expected === $actual;
	}

	/**
	 * Check the complete current store and retained artifact identity sets.
	 */
	private static function complete_startup_identities_match(): bool {
		if ( '' !== self::path_integrity_error() || ! self::forwarder_artifacts_match() ) {
			return false;
		}
		foreach ( self::$forwarder_artifact_streams as $kind => $stream ) {
			if ( ! self::artifact_stream_matches_reserved_identity( $kind, $stream ) ) {
				return false;
			}
		}
		$lease_bytes = '' !== self::$forwarder_lease_bytes ? self::$forwarder_lease_bytes : '';
		return array_keys( self::$forwarder_artifacts ) === array_keys( self::$forwarder_artifact_streams )
			&& self::artifact_stream_contents_match( 'lease', $lease_bytes )
			&& self::artifact_stream_contents_match( 'journal', '' )
			&& self::artifact_stream_contents_match( 'control', '' );
	}

	/**
	 * Stop and reap the exact startup child before any cleanup mutation.
	 *
	 * @param int           $child_pid Exact PID returned by pcntl_fork().
	 * @param resource|null $gate      Parent gate endpoint.
	 */
	private static function abort_and_reap_startup_child( int $child_pid, $gate ): bool {
		if ( is_resource( $gate ) ) {
			@fclose( $gate );
		}
		$state = self::wait_for_exact_child( $child_pid, self::monotonic_deadline( self::STARTUP_REAP_GRACE_NS ) );
		if ( 'reaped' === $state ) {
			return true;
		}
		if ( 'live' !== $state ) {
			return false;
		}
		@posix_kill( $child_pid, SIGKILL );
		return 'reaped' === self::wait_for_exact_child( $child_pid, self::monotonic_deadline( self::STARTUP_REAP_KILL_NS ) );
	}

	/**
	 * Wait for an exact direct-child result without trusting another PID source.
	 *
	 * @param int $child_pid  Exact PID returned by pcntl_fork().
	 * @param int $deadline_ns Monotonic deadline in nanoseconds.
	 * @return string reaped, live, or unowned.
	 */
	private static function wait_for_exact_child( int $child_pid, int $deadline_ns ): string {
		while ( (int) hrtime( true ) < $deadline_ns ) {
			$status = 0;
			$waited = @pcntl_waitpid( $child_pid, $status, WNOHANG );
			if ( $child_pid === $waited ) {
				return 'reaped';
			}
			if ( -1 === $waited ) {
				if ( function_exists( 'pcntl_get_last_error' ) && defined( 'PCNTL_EINTR' ) && PCNTL_EINTR === pcntl_get_last_error() ) {
					continue;
				}
				return 'unowned';
			}
			usleep( 10000 );
		}
		return 'live';
	}

	/**
	 * Prove retained FIFO and backing resources still name their captured inodes.
	 *
	 * @param array<string,mixed> $state Retained path state.
	 */
	private static function retained_path_resources_match( array $state ): bool {
		$fifo_stat    = is_resource( $state['fifo_stream'] ) ? @fstat( $state['fifo_stream'] ) : false;
		$backing_stat = is_resource( $state['backing_stream'] ) ? @fstat( $state['backing_stream'] ) : false;
		return is_array( $fifo_stat )
			&& self::is_fifo_stat( $fifo_stat )
			&& (int) $fifo_stat['dev'] === $state['fifo_dev']
			&& (int) $fifo_stat['ino'] === $state['fifo_ino']
			&& is_array( $backing_stat )
			&& self::is_regular_stat( $backing_stat )
			&& (int) $backing_stat['dev'] === $state['dev']
			&& (int) $backing_stat['ino'] === $state['ino'];
	}

	/**
	 * Prove one retained backing descriptor can accept and synchronize bytes.
	 *
	 * @param array<string,mixed> $state Retained path state.
	 */
	private static function retained_backing_sink_ready( array $state ): bool {
		if ( ! self::retained_path_resources_match( $state ) ) {
			return false;
		}
		$metadata = @stream_get_meta_data( $state['backing_stream'] );
		$mode     = is_array( $metadata ) ? (string) ( $metadata['mode'] ?? '' ) : '';
		return '' !== $mode
			&& ( false !== strpos( $mode, '+' ) || in_array( substr( $mode, 0, 1 ), array( 'a', 'w', 'x', 'c' ), true ) )
			&& 0 === @fseek( $state['backing_stream'], 0, SEEK_END )
			&& @fflush( $state['backing_stream'] )
			&& self::sync_stream( $state['backing_stream'] );
	}

	/**
	 * Reject FIFO input already observable before startup COMMIT.
	 *
	 * Authorization is supplied by the runner waiting for authenticated ready;
	 * this finite observation does not identify or quiesce arbitrary processes.
	 */
	private static function startup_fifos_are_quiet(): bool {
		for ( $observation = 0; $observation < 2; ++$observation ) {
			if ( ! self::complete_startup_identities_match() ) {
				return false;
			}
			$read_streams = array();
			foreach ( self::$paths as $state ) {
				if ( ! is_resource( $state['fifo_stream'] ) ) {
					return false;
				}
				$read_streams[] = $state['fifo_stream'];
			}
			$write_streams  = null;
			$except_streams = null;
			$selected       = @stream_select(
				$read_streams,
				$write_streams,
				$except_streams,
				0,
				self::STARTUP_DRAIN_STABLE_USEC
			);
			if ( 0 !== $selected ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Best-effort drain bounded unauthorized pre-ready bytes during blocked cleanup.
	 *
	 * A false result proves only that cleanup could not finish; it makes no
	 * archival guarantee for bytes implicated in that blocked condition.
	 */
	private static function drain_pre_ready_bytes(): bool {
		$paths = array_keys( self::$paths );
		usort(
			$paths,
			static function ( string $left, string $right ): int {
				return strcmp( self::$paths[ $left ]['path_id'], self::$paths[ $right ]['path_id'] );
			}
		);
		foreach ( $paths as $path ) {
			if ( ! self::retained_backing_sink_ready( self::$paths[ $path ] ) ) {
				return false;
			}
		}
		$deadline    = self::monotonic_deadline( self::STARTUP_DRAIN_TIMEOUT_NS );
		$total_bytes = 0;
		$stable      = 0;
		while ( (int) hrtime( true ) < $deadline && $stable < 2 ) {
			$read_streams = array();
			foreach ( $paths as $path ) {
				$read_streams[] = self::$paths[ $path ]['fifo_stream'];
			}
			$write_streams  = null;
			$except_streams = null;
			$selected       = @stream_select( $read_streams, $write_streams, $except_streams, 0, self::STARTUP_DRAIN_STABLE_USEC );
			if ( false === $selected ) {
				return false;
			}
			if ( 0 === $selected ) {
				++$stable;
				continue;
			}
			if ( $total_bytes >= self::STARTUP_DRAIN_MAX_BYTES ) {
				return false;
			}
			$stable = 0;
			foreach ( $paths as $path ) {
				$stream = self::$paths[ $path ]['fifo_stream'];
				if ( ! in_array( $stream, $read_streams, true ) ) {
					continue;
				}
				if ( $total_bytes >= self::STARTUP_DRAIN_MAX_BYTES ) {
					break;
				}
				if ( ! self::retained_backing_sink_ready( self::$paths[ $path ] ) ) {
					return false;
				}
				$remaining = self::STARTUP_DRAIN_MAX_BYTES - $total_bytes;
				$bytes     = @fread( $stream, min( self::READ_BYTES, $remaining ) );
				if ( ! is_string( $bytes ) || '' === $bytes ) {
					return false;
				}
				if (
					! self::append_to_backing( self::$paths[ $path ], $bytes )
					|| ! self::sync_stream( self::$paths[ $path ]['backing_stream'] )
				) {
					return false;
				}
				$total_bytes += strlen( $bytes );
			}
		}
		return $stable >= 2;
	}

	/**
	 * Resolve one failed startup without any permissive restoration.
	 *
	 * @param int           $child_pid Exact child PID, or zero before a child exists.
	 * @param resource|null $gate      Parent gate endpoint.
	 */
	private static function cleanup_failed_forwarder_start( int $child_pid, $gate ): void {
		self::$durable_forwarder_active = true;
		if ( $child_pid > 0 ) {
			if ( ! self::abort_and_reap_startup_child( $child_pid, $gate ) ) {
				return;
			}
		} elseif ( is_resource( $gate ) ) {
			@fclose( $gate );
		}
		if ( ! self::drain_pre_ready_bytes() || ! self::complete_startup_identities_match() ) {
			return;
		}
		if ( ! self::restore_all( true ) || ! self::remove_forwarder_artifacts() ) {
			return;
		}
		self::$durable_forwarder_active = false;
	}

	/**
	 * Drain FIFO bytes in the detached child and write only safe signed records.
	 */
	private static function run_forwarder(): void {
		self::emit(
			'ready',
			array(
				'status'          => 'pass',
				'path_count'      => count( self::$paths ),
				'origin_binding'  => self::$origin_binding,
				'key_fingerprint' => 'sha256:' . hash( 'sha256', self::$context_key ),
			)
		);

		$maximum_seconds = self::maximum_seconds();
		$started_at      = microtime( true );
		$invalid_stop    = false;
		$blocker_code    = '';

		while ( true ) {
			$control        = self::forwarder_control();
			$control_action = (string) ( $control['action'] ?? '' );
			$challenge      = (string) ( $control['challenge_nonce'] ?? '' );
			if ( 'challenge' === $control_action && self::valid_uuid( $challenge ) && $challenge !== self::$last_challenge_nonce ) {
				self::$last_challenge_nonce = $challenge;
				self::emit(
					'heartbeat',
					array(
						'challenge_nonce' => $challenge,
						'child_pid'       => getmypid(),
					)
				);
			}
			if ( self::$interrupted || 'interrupt' === $control_action ) {
				$blocker_code = 'observer_interrupted';
				break;
			}

			$read_streams = array();
			foreach ( self::$paths as $state ) {
				if ( ! $state['stopped'] ) {
					$read_streams[] = $state['fifo_stream'];
				}
			}
			if ( empty( $read_streams ) ) {
				break;
			}

			$write_streams  = null;
			$except_streams = null;
			$selected       = @stream_select( $read_streams, $write_streams, $except_streams, 0, self::SELECT_USEC );
			if ( false === $selected ) {
				$blocker_code = self::$interrupted ? 'observer_interrupted' : 'observer_read_failed';
				break;
			}

			foreach ( $read_streams as $stream ) {
				$path = self::path_for_fifo_stream( $stream );
				if ( '' === $path ) {
					$blocker_code = 'observer_read_failed';
					break 2;
				}
				$bytes = @fread( $stream, self::READ_BYTES );
				if ( false === $bytes ) {
					$blocker_code = 'observer_read_failed';
					break 2;
				}
				if ( '' !== $bytes && ! self::forward_bytes( $path, $bytes, $invalid_stop ) ) {
					$blocker_code = 'backing_write_failed';
					break 2;
				}
			}

			$integrity_error = self::path_integrity_error();
			if ( '' !== $integrity_error ) {
				$blocker_code = $integrity_error;
				if ( 'backing_path_changed' !== $integrity_error ) {
					break;
				}
			}

			if ( microtime( true ) - $started_at >= $maximum_seconds ) {
				$blocker_code = '' !== $blocker_code
					? $blocker_code
					: ( $invalid_stop ? 'sentinel_timeout' : 'observer_lifetime_exceeded' );
				break;
			}
		}

		if ( '' === $blocker_code && self::parser_overflowed() ) {
			$blocker_code = 'observer_line_too_long';
		}
		if ( '' === $blocker_code ) {
			$blocker_code = self::path_integrity_error();
		}
		if ( ! self::flush_forwarder_buffers() && '' === $blocker_code ) {
			$blocker_code = 'backing_write_failed';
		}

		if ( '' === $blocker_code ) {
			self::emit( 'complete', array( 'status' => 'pass' ) );
			@fclose( self::$journal_stream );
			exit( 0 );
		}

		self::emit( 'blocked', array( 'blocker_code' => $blocker_code ) );
		@fclose( self::$journal_stream );
		exit( 3 );
	}

	/**
	 * Mirror authenticated child journal records and restore after its terminal record.
	 */
	private static function coordinate_forwarder(): void {
		$mirrored_records = array();
		$mirrored_hmacs   = array();
		$interrupt_sent   = false;
		$child_state      = 'live';

		while ( 'live' === $child_state ) {
			$control           = self::forwarder_control();
			$control_action    = (string) ( $control['action'] ?? '' );
			$control_challenge = (string) ( $control['challenge_nonce'] ?? '' );
			if ( 'coordinator_challenge' === $control_action && self::valid_uuid( $control_challenge ) ) {
				self::write_forwarder_control( 'coordinator_heartbeat', $control_challenge, getmypid() );
			} elseif ( 'coordinator_exit' === $control_action && self::valid_uuid( $control_challenge ) ) {
				exit( 3 );
			}
			if ( self::$interrupted && ! $interrupt_sent ) {
				$interrupt_sent = self::write_forwarder_control( 'interrupt' );
			}

			$snapshot = self::validated_forwarder_journal_snapshot();
			if ( is_array( $snapshot ) && self::journal_extends_mirrored_records( $snapshot['records'], $mirrored_records ) ) {
				foreach ( array_slice( $snapshot['records'], count( $mirrored_records ) ) as $record ) {
					if ( in_array( $record['kind'], array( 'complete', 'blocked' ), true ) ) {
						break;
					}
					$encoded = wp_json_encode( $record );
					if ( ! is_string( $encoded ) ) {
						break;
					}
					WP_CLI::line( $encoded );
					$mirrored_records[] = $record;
					$mirrored_hmacs[]   = $record['hmac'];
				}
			}

			$status = 0;
			$waited = @pcntl_waitpid( self::$forwarder_pid, $status, WNOHANG );
			if ( self::$forwarder_pid === $waited ) {
				$child_state = 'reaped';
			} elseif ( -1 === $waited ) {
				if ( ! function_exists( 'pcntl_get_last_error' ) || ! defined( 'PCNTL_EINTR' ) || PCNTL_EINTR !== pcntl_get_last_error() ) {
					$child_state = 'unowned';
				}
			}
			if ( 'live' === $child_state ) {
				usleep( self::SELECT_USEC );
			}
		}

		if ( 'reaped' !== $child_state ) {
			self::emit_coordinator_blocked( $mirrored_hmacs, 'forwarder_artifact_changed' );
		}

		$first_final = self::final_forwarder_journal_snapshot();
		usleep( self::STARTUP_DRAIN_STABLE_USEC );
		$final = self::final_forwarder_journal_snapshot();
		if (
			! is_array( $first_final )
			|| ! is_array( $final )
			|| $first_final['identity'] !== $final['identity']
			|| $first_final['bytes'] !== $final['bytes']
			|| ! self::journal_extends_mirrored_records( $final['records'], $mirrored_records )
		) {
			self::preserve_ambiguous_reaped_fifo_bytes();
			self::emit_coordinator_blocked( $mirrored_hmacs, 'forwarder_artifact_changed' );
		}

		$terminal         = null;
		$terminal_encoded = '';
		foreach ( array_slice( $final['records'], count( $mirrored_records ) ) as $record ) {
			$encoded = wp_json_encode( $record );
			if ( ! is_string( $encoded ) ) {
				self::emit_coordinator_blocked( $mirrored_hmacs, 'forwarder_artifact_changed' );
			}
			if ( in_array( $record['kind'], array( 'complete', 'blocked' ), true ) ) {
				$terminal         = $record;
				$terminal_encoded = $encoded;
				break;
			}
			WP_CLI::line( $encoded );
			$mirrored_records[] = $record;
			$mirrored_hmacs[]   = $record['hmac'];
		}

		$control_snapshot = self::reserved_artifact_snapshot( 'control', self::MAX_CONTROL );
		if (
			! is_array( $control_snapshot )
			|| ( '' !== $control_snapshot['bytes'] && ! is_array( self::decode_forwarder_control( $control_snapshot['bytes'] ) ) )
		) {
			self::preserve_ambiguous_reaped_fifo_bytes();
			self::emit_coordinator_blocked( $mirrored_hmacs, 'forwarder_artifact_changed' );
		}
		$integrity_error = self::path_integrity_error();
		if ( '' !== $integrity_error ) {
			self::emit_coordinator_blocked( $mirrored_hmacs, $integrity_error );
		}
		if ( ! self::coordinator_recovery_context_matches( $final, $control_snapshot ) ) {
			self::emit_coordinator_blocked( $mirrored_hmacs, 'forwarder_artifact_changed' );
		}

		if ( null === $terminal ) {
			if ( ! self::recover_terminal_less_child( $final, $control_snapshot ) ) {
				$integrity_error = self::path_integrity_error();
				self::emit_coordinator_blocked( $mirrored_hmacs, '' !== $integrity_error ? $integrity_error : 'forwarder_artifact_changed' );
			}
			self::$durable_forwarder_active = false;
			self::emit_coordinator_blocked( $mirrored_hmacs, 'observer_forced_recovery' );
		}

		self::refresh_backing_sizes();
		if ( ! self::coordinator_recovery_context_matches( $final, $control_snapshot ) ) {
			$integrity_error = self::path_integrity_error();
			self::emit_coordinator_blocked( $mirrored_hmacs, '' !== $integrity_error ? $integrity_error : 'forwarder_artifact_changed' );
		}
		$restored = self::restore_all( true );
		if ( ! $restored ) {
			$blocker_code = '' !== self::$restoration_blocker ? self::$restoration_blocker : 'backing_write_failed';
			self::emit_coordinator_blocked( $mirrored_hmacs, $blocker_code );
		}
		if ( ! self::coordinator_artifacts_match( $final, $control_snapshot ) || ! self::remove_forwarder_artifacts() ) {
			self::emit_coordinator_blocked( $mirrored_hmacs, 'forwarder_artifact_changed' );
		}
		self::$durable_forwarder_active = false;
		WP_CLI::line( $terminal_encoded );
		exit( 'complete' === ( $terminal['kind'] ?? '' ) ? 0 : 3 );
	}

	/**
	 * Emit an authenticated alternative terminal without mutating the child journal.
	 *
	 * @param array<int,string> $mirrored_hmacs Exact nonterminal HMAC chain already emitted.
	 * @param string            $blocker_code   Fixed secret-safe blocker.
	 */
	private static function emit_coordinator_blocked( array $mirrored_hmacs, string $blocker_code ): void {
		self::$sequence      = count( $mirrored_hmacs );
		$previous            = empty( $mirrored_hmacs ) ? '' : (string) end( $mirrored_hmacs );
		self::$previous_hmac = '' === $previous ? str_repeat( '0', 64 ) : substr( $previous, strlen( 'hmac-sha256:' ) );
		self::emit_and_exit( 'blocked', array( 'blocker_code' => $blocker_code ), 3 );
	}

	/**
	 * Match exact lease, journal, and finite control content at a recovery barrier.
	 *
	 * @param array<string,mixed> $journal Expected stable final journal snapshot.
	 * @param array<string,mixed> $control Expected finite control snapshot.
	 */
	private static function coordinator_artifacts_match( array $journal, array $control ): bool {
		if ( ! self::forwarder_artifacts_match() ) {
			return false;
		}
		$lease_current   = self::reserved_artifact_snapshot( 'lease', self::MAX_JOURNAL );
		$journal_current = self::reserved_artifact_snapshot( 'journal', self::MAX_JOURNAL );
		$control_current = self::reserved_artifact_snapshot( 'control', self::MAX_CONTROL );
		return is_array( $lease_current )
			&& is_array( $journal_current )
			&& is_array( $control_current )
			&& $lease_current['bytes'] === self::$forwarder_lease_bytes
			&& ( $journal['bytes'] ?? null ) === $journal_current['bytes']
			&& ( $journal['identity'] ?? null ) === $journal_current['identity']
			&& $control_current === $control
			&& ( '' === $control_current['bytes'] || is_array( self::decode_forwarder_control( $control_current['bytes'] ) ) );
	}

	/**
	 * Match stable raw artifact bytes while deliberately assigning them no trust.
	 *
	 * @param array<string,mixed> $journal Expected raw journal snapshot.
	 * @param array<string,mixed> $control Expected raw control snapshot.
	 */
	private static function ambiguous_recovery_context_matches( array $journal, array $control ): bool {
		if ( '' !== self::path_integrity_error() || ! self::forwarder_artifacts_match() ) {
			return false;
		}
		$lease_current   = self::reserved_artifact_snapshot( 'lease', self::MAX_JOURNAL );
		$journal_current = self::reserved_artifact_snapshot( 'journal', self::MAX_JOURNAL );
		$control_current = self::reserved_artifact_snapshot( 'control', self::MAX_CONTROL );
		if (
			! is_array( $lease_current )
			|| ! is_array( $journal_current )
			|| ! is_array( $control_current )
			|| $lease_current['bytes'] !== self::$forwarder_lease_bytes
			|| $journal_current !== $journal
			|| $control_current !== $control
		) {
			return false;
		}
		foreach ( self::$paths as $state ) {
			if ( ! self::retained_backing_sink_ready( $state ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Best-effort append unread bytes before an ambiguous exact-reap block.
	 *
	 * Journal/control bytes remain untrusted. Failure preserves authenticated
	 * nodes where possible but is not a byte-conservation proof.
	 */
	private static function preserve_ambiguous_reaped_fifo_bytes(): bool {
		$journal = self::reserved_artifact_snapshot( 'journal', self::MAX_JOURNAL );
		$control = self::reserved_artifact_snapshot( 'control', self::MAX_CONTROL );
		if ( ! is_array( $journal ) || ! is_array( $control ) ) {
			return false;
		}
		usleep( self::STARTUP_DRAIN_STABLE_USEC );
		$journal_confirmed = self::reserved_artifact_snapshot( 'journal', self::MAX_JOURNAL );
		$control_confirmed = self::reserved_artifact_snapshot( 'control', self::MAX_CONTROL );
		if ( $journal !== $journal_confirmed || $control !== $control_confirmed || ! self::ambiguous_recovery_context_matches( $journal, $control ) ) {
			return false;
		}
		self::refresh_backing_sizes();
		$paths = array_keys( self::$paths );
		usort(
			$paths,
			static function ( string $left, string $right ): int {
				return strcmp( self::$paths[ $left ]['path_id'], self::$paths[ $right ]['path_id'] );
			}
		);
		$deadline    = self::monotonic_deadline( self::STARTUP_DRAIN_TIMEOUT_NS );
		$total_bytes = 0;
		$stable      = 0;
		while ( (int) hrtime( true ) < $deadline && $stable < 2 ) {
			if ( ! self::ambiguous_recovery_context_matches( $journal, $control ) ) {
				return false;
			}
			$read_streams = array();
			foreach ( $paths as $path ) {
				$read_streams[] = self::$paths[ $path ]['fifo_stream'];
			}
			$write_streams  = null;
			$except_streams = null;
			$selected       = @stream_select( $read_streams, $write_streams, $except_streams, 0, self::STARTUP_DRAIN_STABLE_USEC );
			if ( false === $selected ) {
				return false;
			}
			if ( 0 === $selected ) {
				++$stable;
				continue;
			}
			if ( $total_bytes >= self::STARTUP_DRAIN_MAX_BYTES ) {
				return false;
			}
			$stable = 0;
			foreach ( $paths as $path ) {
				$stream = self::$paths[ $path ]['fifo_stream'];
				if ( ! in_array( $stream, $read_streams, true ) ) {
					continue;
				}
				if ( $total_bytes >= self::STARTUP_DRAIN_MAX_BYTES || ! self::ambiguous_recovery_context_matches( $journal, $control ) ) {
					return false;
				}
				$remaining = self::STARTUP_DRAIN_MAX_BYTES - $total_bytes;
				$bytes     = @fread( $stream, min( self::READ_BYTES, $remaining ) );
				if ( ! is_string( $bytes ) || '' === $bytes || ! self::append_to_backing( self::$paths[ $path ], $bytes ) || ! self::sync_stream( self::$paths[ $path ]['backing_stream'] ) ) {
					return false;
				}
				$total_bytes += strlen( $bytes );
			}
		}
		return $stable >= 2;
	}

	/**
	 * Match the complete retained/named store and artifact recovery context.
	 *
	 * @param array<string,mixed> $journal Expected stable final journal snapshot.
	 * @param array<string,mixed> $control Expected finite control snapshot.
	 */
	private static function coordinator_recovery_context_matches( array $journal, array $control ): bool {
		if ( '' !== self::path_integrity_error() || ! self::coordinator_artifacts_match( $journal, $control ) ) {
			return false;
		}
		foreach ( self::$paths as $state ) {
			if ( ! self::retained_backing_sink_ready( $state ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Seed parent recovery state from the exact durable backing suffixes.
	 */
	private static function seed_recovery_terminal_tails(): bool {
		foreach ( self::$paths as $path => &$state ) {
			$stat     = is_resource( $state['backing_stream'] ) ? @fstat( $state['backing_stream'] ) : false;
			$terminal = self::terminal_line( $path, $state['path_id'] );
			if ( ! is_array( $stat ) || ! self::is_regular_stat( $stat ) ) {
				unset( $state );
				return false;
			}
			$size   = (int) $stat['size'];
			$length = min( $size, strlen( $terminal ) );
			if ( 0 !== @fseek( $state['backing_stream'], $size - $length, SEEK_SET ) ) {
				unset( $state );
				return false;
			}
			$suffix = 0 === $length ? '' : @stream_get_contents( $state['backing_stream'], $length );
			if ( ! is_string( $suffix ) || strlen( $suffix ) !== $length || 0 !== @fseek( $state['backing_stream'], 0, SEEK_END ) ) {
				unset( $state );
				return false;
			}
			$state['byte_count']    = $size;
			$state['terminal_tail'] = self::terminal_suffix_tail( '', $suffix, $terminal );
		}
		unset( $state );
		return true;
	}

	/**
	 * Recover an exact-reaped child through retained descriptors only.
	 *
	 * @param array<string,mixed> $journal Stable final journal snapshot.
	 * @param array<string,mixed> $control Stable finite control snapshot.
	 */
	private static function recover_terminal_less_child( array $journal, array $control ): bool {
		if ( ! self::coordinator_recovery_context_matches( $journal, $control ) || ! self::seed_recovery_terminal_tails() ) {
			return false;
		}
		$paths = array_keys( self::$paths );
		usort(
			$paths,
			static function ( string $left, string $right ): int {
				return strcmp( self::$paths[ $left ]['path_id'], self::$paths[ $right ]['path_id'] );
			}
		);
		$deadline    = self::monotonic_deadline( self::STARTUP_DRAIN_TIMEOUT_NS );
		$total_bytes = 0;
		$stable      = 0;
		while ( (int) hrtime( true ) < $deadline && $stable < 2 ) {
			if ( ! self::coordinator_recovery_context_matches( $journal, $control ) ) {
				return false;
			}
			$read_streams = array();
			foreach ( $paths as $path ) {
				$read_streams[] = self::$paths[ $path ]['fifo_stream'];
			}
			$write_streams  = null;
			$except_streams = null;
			$selected       = @stream_select( $read_streams, $write_streams, $except_streams, 0, self::STARTUP_DRAIN_STABLE_USEC );
			if ( false === $selected ) {
				return false;
			}
			if ( 0 === $selected ) {
				++$stable;
				continue;
			}
			if ( $total_bytes >= self::STARTUP_DRAIN_MAX_BYTES ) {
				return false;
			}
			$stable = 0;
			foreach ( $paths as $path ) {
				$stream = self::$paths[ $path ]['fifo_stream'];
				if ( ! in_array( $stream, $read_streams, true ) ) {
					continue;
				}
				if ( $total_bytes >= self::STARTUP_DRAIN_MAX_BYTES ) {
					break;
				}
				if ( ! self::coordinator_recovery_context_matches( $journal, $control ) ) {
					return false;
				}
				$remaining = self::STARTUP_DRAIN_MAX_BYTES - $total_bytes;
				$bytes     = @fread( $stream, min( self::READ_BYTES, $remaining ) );
				if ( ! is_string( $bytes ) || '' === $bytes ) {
					return false;
				}
				if ( ! self::append_to_backing( self::$paths[ $path ], $bytes ) || ! self::sync_stream( self::$paths[ $path ]['backing_stream'] ) ) {
					return false;
				}
				$total_bytes += strlen( $bytes );

				$terminal = self::terminal_line( $path, self::$paths[ $path ]['path_id'] );

				self::$paths[ $path ]['terminal_tail'] = self::terminal_suffix_tail( self::$paths[ $path ]['terminal_tail'], $bytes, $terminal );
			}
		}
		if ( $stable < 2 ) {
			return false;
		}
		foreach ( $paths as $path ) {
			$terminal = self::terminal_line( $path, self::$paths[ $path ]['path_id'] );
			if ( hash_equals( $terminal, self::$paths[ $path ]['terminal_tail'] ) ) {
				if (
					! self::coordinator_recovery_context_matches( $journal, $control )
					|| ! self::truncate_backing_suffix( self::$paths[ $path ], strlen( $terminal ) )
				) {
					return false;
				}
			}
		}
		if ( ! self::coordinator_recovery_context_matches( $journal, $control ) || ! self::restore_all( true ) ) {
			return false;
		}
		return self::coordinator_artifacts_match( $journal, $control ) && self::remove_forwarder_artifacts();
	}

	/**
	 * Return one authenticated coordinator control packet.
	 *
	 * @return array<string,mixed>
	 */
	private static function forwarder_control(): array {
		$snapshot = self::reserved_artifact_snapshot( 'control', self::MAX_CONTROL );
		if ( ! is_array( $snapshot ) || '' === $snapshot['bytes'] ) {
			return array();
		}
		$control = self::decode_forwarder_control( $snapshot['bytes'] );
		return is_array( $control ) ? $control : array();
	}

	/**
	 * Decode one exact finite authenticated control form.
	 *
	 * @param string $encoded Exact reserved control bytes.
	 * @return array<string,mixed>|null
	 */
	private static function decode_forwarder_control( string $encoded ) {
		$control = json_decode( $encoded, true );
		if ( ! is_array( $control ) || ! isset( $control['hmac'] ) || ! is_string( $control['hmac'] ) ) {
			return null;
		}
		$action = $control['action'] ?? '';

		$common = array( 'schema', 'run_stamp', 'store', 'flow_id', 'purpose', 'marker_created_at', 'observer_id', 'forwarder_nonce', 'paths', 'action' );

		$extra = array(
			'challenge'             => array( 'challenge_nonce' ),
			'interrupt'             => array(),
			'coordinator_challenge' => array( 'challenge_nonce' ),
			'coordinator_heartbeat' => array( 'challenge_nonce', 'responder_pid' ),
			'coordinator_exit'      => array( 'challenge_nonce' ),
		);
		if ( ! is_string( $action ) || ! isset( $extra[ $action ] ) || array_keys( $control ) !== array_merge( $common, $extra[ $action ], array( 'hmac' ) ) ) {
			return null;
		}
		$signature = $control['hmac'];
		unset( $control['hmac'] );
		$canonical = wp_json_encode( $control );
		$expected  = is_string( $canonical )
			? 'hmac-sha256:' . hash_hmac( 'sha256', self::CONTROL_SCHEMA . "\0" . $canonical, self::$context_key )
			: '';
		if (
			! hash_equals( $expected, $signature )
			|| self::CONTROL_SCHEMA !== ( $control['schema'] ?? '' )
			|| ( $control['run_stamp'] ?? '' ) !== self::$run_stamp
			|| ( $control['store'] ?? '' ) !== self::$store
			|| ( $control['flow_id'] ?? '' ) !== self::$flow_id
			|| ( $control['purpose'] ?? '' ) !== self::$purpose
			|| ( $control['marker_created_at'] ?? '' ) !== self::$marker_created_at
			|| ( $control['observer_id'] ?? '' ) !== self::$observer_id
			|| ( $control['forwarder_nonce'] ?? '' ) !== self::$forwarder_nonce
			|| ( $control['paths'] ?? null ) !== self::$path_contexts
			|| ( isset( $control['challenge_nonce'] ) && ! self::valid_uuid( $control['challenge_nonce'] ) )
			|| ( isset( $control['responder_pid'] ) && ( ! is_int( $control['responder_pid'] ) || $control['responder_pid'] <= 1 ) )
		) {
			return null;
		}

		return $control;
	}

	/**
	 * Persist one authenticated coordinator control action.
	 *
	 * @param string $action          Fixed control action.
	 * @param string $challenge_nonce Optional challenge UUID.
	 * @param int    $responder_pid   Optional authenticated responder PID.
	 */
	private static function write_forwarder_control( string $action, string $challenge_nonce = '', int $responder_pid = 0 ): bool {
		$control = array(
			'schema'            => self::CONTROL_SCHEMA,
			'run_stamp'         => self::$run_stamp,
			'store'             => self::$store,
			'flow_id'           => self::$flow_id,
			'purpose'           => self::$purpose,
			'marker_created_at' => self::$marker_created_at,
			'observer_id'       => self::$observer_id,
			'forwarder_nonce'   => self::$forwarder_nonce,
			'paths'             => self::$path_contexts,
			'action'            => $action,
		);
		if ( '' !== $challenge_nonce ) {
			$control['challenge_nonce'] = $challenge_nonce;
		}
		if ( $responder_pid > 1 ) {
			$control['responder_pid'] = $responder_pid;
		}
		$encoded = wp_json_encode( $control );
		if ( ! is_string( $encoded ) ) {
			return false;
		}
		$control['hmac'] = 'hmac-sha256:' . hash_hmac( 'sha256', self::CONTROL_SCHEMA . "\0" . $encoded, self::$context_key );
		$encoded         = wp_json_encode( $control );
		return is_string( $encoded )
			&& strlen( $encoded ) === @file_put_contents( self::$forwarder_artifacts['control'], $encoded, LOCK_EX )
			&& @chmod( self::$forwarder_artifacts['control'], 0600 );
	}

	/**
	 * Flush every durable backing stream and discard parser-only fragments.
	 */
	private static function flush_forwarder_buffers(): bool {
		$success = true;
		foreach ( self::$paths as &$state ) {
			$state['buffer'] = '';
			$success         = @fflush( $state['backing_stream'] ) && self::sync_stream( $state['backing_stream'] ) && $success;
		}
		unset( $state );
		return $success;
	}

	/**
	 * Return whether any logical line exceeded the fixed parser bound.
	 */
	private static function parser_overflowed(): bool {
		foreach ( self::$paths as $state ) {
			if ( $state['parser_overflow'] ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Refresh parent state from the child-written backing streams.
	 */
	private static function refresh_backing_sizes(): void {
		foreach ( self::$paths as &$state ) {
			$stat = @fstat( $state['backing_stream'] );
			if ( is_array( $stat ) ) {
				$state['byte_count'] = (int) $stat['size'];
			}
		}
		unset( $state );
	}

	/**
	 * Remove every reserved forwarder artifact after proven restoration.
	 */
	private static function remove_forwarder_artifacts(): bool {
		if ( array_keys( self::$forwarder_artifacts ) !== array_keys( self::$forwarder_artifact_identities ) ) {
			return false;
		}
		self::close_forwarder_artifact_streams();
		$removed = self::remove_matching_artifacts( self::$forwarder_artifact_identities );
		if ( $removed ) {
			self::$forwarder_artifact_identities = array();
		}

		return $removed;
	}

	/**
	 * Remove only artifacts whose complete current identity set still matches.
	 *
	 * @param array<string,array{kind:string,dev:int,ino:int,mode:int}> $identities Expected identities.
	 */
	private static function remove_matching_artifacts( array $identities ): bool {
		if ( empty( $identities ) || ! self::artifact_identities_match( $identities ) ) {
			return empty( $identities );
		}
		foreach ( $identities as $kind => $identity ) {
			if ( ! self::artifact_identity_matches( $kind, $identity ) || ! @unlink( self::$forwarder_artifacts[ $kind ] ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Check a complete artifact identity set without mutating any path.
	 *
	 * @param array<string,array{kind:string,dev:int,ino:int,mode:int}> $identities Expected identities.
	 */
	private static function artifact_identities_match( array $identities ): bool {
		foreach ( $identities as $kind => $identity ) {
			if ( ! self::artifact_identity_matches( $kind, $identity ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Check the complete retained lease, journal, and control identity set.
	 */
	private static function forwarder_artifacts_match(): bool {
		return array_keys( self::$forwarder_artifacts ) === array_keys( self::$forwarder_artifact_identities )
			&& self::artifact_identities_match( self::$forwarder_artifact_identities );
	}

	/**
	 * Check one named artifact immediately against its retained identity.
	 *
	 * @param string                                      $kind     Finite artifact kind.
	 * @param array{kind:string,dev:int,ino:int,mode:int} $identity Expected identity.
	 */
	private static function artifact_identity_matches( string $kind, array $identity ): bool {
		if ( ! isset( self::$forwarder_artifacts[ $kind ] ) || $kind !== $identity['kind'] ) {
			return false;
		}
		$path = self::$forwarder_artifacts[ $kind ];
		clearstatcache( true, $path );
		$stat = @lstat( $path );
		return false !== $stat
			&& self::is_regular_stat( $stat )
			&& (int) $stat['dev'] === $identity['dev']
			&& (int) $stat['ino'] === $identity['ino']
			&& ( (int) $stat['mode'] & 0777 ) === $identity['mode'];
	}

	/**
	 * Move one marked log behind a FIFO.
	 *
	 * @param string              $path        Marked path.
	 * @param array<string,mixed> $observation Marker observation.
	 */
	private static function proxy_path( string $path, array $observation ): bool {
		$backing_path = self::backing_path( $path, self::$observer_id );
		$backing      = @fopen( $path, 'r+b' );
		if ( false === $backing || 0 !== @fseek( $backing, 0, SEEK_END ) ) {
			if ( is_resource( $backing ) ) {
				@fclose( $backing );
			}
			return false;
		}

		if ( ! @rename( $path, $backing_path ) ) {
			@fclose( $backing );
			return false;
		}

		self::$paths[ $path ] = array(
			'path_id'         => $observation['path_id'],
			'backing_path'    => $backing_path,
			'backing_stream'  => $backing,
			'fifo_stream'     => null,
			'fifo_dev'        => -1,
			'fifo_ino'        => -1,
			'buffer'          => '',
			'parser_overflow' => false,
			'terminal_tail'   => '',
			'line'            => $observation['line_count'],
			'stopped'         => false,
			'owner'           => $observation['owner'],
			'group'           => $observation['group'],
			'mode'            => $observation['mode'],
			'dev'             => $observation['dev'],
			'ino'             => $observation['ino'],
			'byte_count'      => $observation['byte_count'],
			'initial_bytes'   => $observation['initial_bytes'],
		);

		if ( ! @posix_mkfifo( $path, 0600 ) || ! @chmod( $path, 0600 ) ) {
			return false;
		}
		$fifo = @fopen( $path, 'r+' );
		if (
			false === $fifo
			|| ! @stream_set_blocking( $fifo, false )
			|| 0 !== @stream_set_read_buffer( $fifo, 0 )
		) {
			if ( is_resource( $fifo ) ) {
				@fclose( $fifo );
			}
			return false;
		}

		$fifo_stat = @lstat( $path );
		if ( false === $fifo_stat || ! self::is_fifo_stat( $fifo_stat ) ) {
			@fclose( $fifo );
			return false;
		}

		self::$paths[ $path ]['fifo_stream'] = $fifo;
		self::$paths[ $path ]['fifo_dev']    = (int) $fifo_stat['dev'];
		self::$paths[ $path ]['fifo_ino']    = (int) $fifo_stat['ino'];
		return true;
	}

	/**
	 * Forward one byte chunk and emit metadata for complete lines.
	 *
	 * @param string $path         Proxied path.
	 * @param string $bytes        Exact FIFO bytes.
	 * @param bool   $invalid_stop Whether an invalid sentinel was observed.
	 */
	private static function forward_bytes( string $path, string $bytes, bool &$invalid_stop ): bool {
		$state = &self::$paths[ $path ];
		if ( ! self::append_to_backing( $state, $bytes ) || ! self::sync_stream( $state['backing_stream'] ) ) {
			return false;
		}

		$terminal_line          = self::terminal_line( $path, $state['path_id'] );
		$state['terminal_tail'] = self::terminal_suffix_tail( $state['terminal_tail'], $bytes, $terminal_line );
		$terminal_seen          = hash_equals( $terminal_line, $state['terminal_tail'] );
		if ( ! $state['parser_overflow'] ) {
			$state['buffer'] .= $bytes;
		}
		if ( $terminal_seen ) {
			if ( ! $state['parser_overflow'] ) {
				$state['buffer'] = substr( $state['buffer'], 0, -strlen( $terminal_line ) );
			}
			if ( ! self::truncate_backing_suffix( $state, strlen( $terminal_line ) ) ) {
				return false;
			}
		}

		$newline = $state['parser_overflow'] ? false : strpos( $state['buffer'], "\n" );
		while ( false !== $newline && ! $state['parser_overflow'] ) {
			if ( $newline > self::MAX_PARSER_LINE ) {
				$state['buffer']          = '';
				$state['parser_overflow'] = true;
				break;
			}
			$line            = substr( $state['buffer'], 0, $newline + 1 );
			$state['buffer'] = substr( $state['buffer'], $newline + 1 );
			$line_bytes      = rtrim( $line, "\r\n" );
			++$state['line'];
			$category = self::line_category( $line_bytes );
			if ( 0 === strpos( $line_bytes, '[woopayments-critical-flows-log-observer-stop]' ) ) {
				$invalid_stop = true;
			}

			self::emit(
				'line',
				array(
					'path'        => basename( $path ),
					'line'        => $state['line'],
					'category'    => $category,
					'fingerprint' => 'sha256:' . hash( 'sha256', $line_bytes ),
				)
			);
			$newline = strpos( $state['buffer'], "\n" );
		}
		if ( ! $state['parser_overflow'] && strlen( $state['buffer'] ) > self::MAX_PARSER_LINE ) {
			$state['buffer']          = '';
			$state['parser_overflow'] = true;
		}
		if ( $terminal_seen ) {
			$state['stopped'] = true;
			self::emit(
				'line',
				array(
					'path'        => basename( $path ),
					'line'        => $state['line'],
					'category'    => 'terminal',
					'fingerprint' => 'sha256:' . hash( 'sha256', substr( $terminal_line, 0, -1 ) ),
				)
			);
		}

		return true;
	}

	/**
	 * Remove one fully authenticated durable suffix and synchronize the result.
	 *
	 * @param array<string,mixed> $state  Path state.
	 * @param int                 $length Exact authenticated suffix length.
	 */
	private static function truncate_backing_suffix( array &$state, int $length ): bool {
		return self::truncate_stream_suffix( $state['backing_stream'], $state['byte_count'], $length );
	}

	/**
	 * Remove an exact durable suffix from one stream and synchronize the result.
	 *
	 * @param resource $stream     Open durable stream.
	 * @param int      $byte_count Current durable byte count.
	 * @param int      $length     Exact authenticated suffix length.
	 */
	private static function truncate_stream_suffix( $stream, int &$byte_count, int $length ): bool {
		if ( $length < 1 || $byte_count < $length ) {
			return false;
		}
		$size = $byte_count - $length;
		if (
			! @ftruncate( $stream, $size )
			|| 0 !== @fseek( $stream, 0, SEEK_END )
			|| ! @fflush( $stream )
			|| ! self::sync_stream( $stream )
		) {
			return false;
		}
		$byte_count = $size;
		return true;
	}

	/**
	 * Return a fixed finite category for one complete line.
	 *
	 * @param string $line Exact line bytes without its terminator.
	 */
	private static function line_category( string $line ): string {
		if (
			false !== stripos( $line, 'Function _load_textdomain_just_in_time was called' )
			|| false !== strpos( $line, 'sopreda/archi/zoho/class-zoho-integration.php' )
		) {
			return 'allowlisted_noise';
		}

		if ( preg_match( '/\b(?:PHP )?(Fatal error|Parse error|Warning|Notice|Deprecated|Strict Standards)\b/i', $line, $matched ) ) {
			return strtolower( str_replace( ' ', '_', $matched[1] ) );
		}

		return 'other';
	}

	/**
	 * Construct the exact authenticated terminal line for one path.
	 *
	 * @param string $path    Proxied path.
	 * @param string $path_id Non-reversible keyed path identity.
	 */
	private static function terminal_line( string $path, string $path_id ): string {
		$material = self::terminal_material( $path, $path_id );
		$terminal = '[woopayments-critical-flows-log-observer-stop] hmac-sha256:';
		return $terminal . hash_hmac( 'sha256', $material, self::$context_key ) . "\n";
	}

	/**
	 * Retain only the bounded bytes that can form the exact terminal suffix.
	 *
	 * @param string $tail     Previously retained suffix bytes.
	 * @param string $bytes    Newly durable bytes.
	 * @param string $terminal Exact authenticated terminal line.
	 */
	private static function terminal_suffix_tail( string $tail, string $bytes, string $terminal ): string {
		$combined = $tail . $bytes;
		$length   = strlen( $terminal );
		return strlen( $combined ) > $length ? substr( $combined, -$length ) : $combined;
	}

	/**
	 * Construct the authenticated terminal material for one keyed path.
	 *
	 * @param string $path    Canonical absolute path.
	 * @param string $path_id Non-reversible keyed path identity.
	 */
	private static function terminal_material( string $path, string $path_id ): string {
		return implode(
			"\0",
			array(
				'terminal',
				self::$run_stamp,
				self::$store,
				self::$flow_id,
				self::$purpose,
				self::$marker_created_at,
				self::$observer_id,
				$path_id,
				basename( $path ),
			)
		);
	}

	/**
	 * Return a fixed blocker when a proxied node changes identity.
	 */
	private static function path_integrity_error(): string {
		foreach ( self::$paths as $path => $state ) {
			$path_stat = @lstat( $path );
			if (
				false === $path_stat
				|| ! self::is_fifo_stat( $path_stat )
				|| (int) $path_stat['dev'] !== $state['fifo_dev']
				|| (int) $path_stat['ino'] !== $state['fifo_ino']
			) {
				return 'observer_path_replaced';
			}

			$backing_stat = @lstat( $state['backing_path'] );
			if (
				false === $backing_stat
				|| ! self::is_regular_stat( $backing_stat )
				|| (int) $backing_stat['dev'] !== $state['dev']
				|| (int) $backing_stat['ino'] !== $state['ino']
			) {
				return 'backing_path_changed';
			}
		}

		return '';
	}

	/**
	 * Restore every proxied path exactly once.
	 *
	 * @param bool $strict_identity Whether only the exact leased FIFO/backing nodes may be restored.
	 */
	private static function restore_all( bool $strict_identity = false ): bool {
		if ( self::$restoration_attempted ) {
			return empty( self::$paths );
		}
		self::$restoration_attempted = true;
		self::$restoration_blocker   = '';
		if ( $strict_identity ) {
			return self::restore_all_strict();
		}
		$success = true;

		foreach ( self::$paths as $path => &$state ) {
			$identity_preserved = true;
			if ( is_resource( $state['fifo_stream'] ) ) {
				@fclose( $state['fifo_stream'] );
			}

			$path_stat = @lstat( $path );
			if ( false !== $path_stat && self::is_regular_stat( $path_stat ) ) {
				self::merge_replacement_suffix( $path, $state );
			}
			if ( file_exists( $path ) || is_link( $path ) ) {
				if ( ! @unlink( $path ) ) {
					$success = false;
					continue;
				}
			}

			if ( is_resource( $state['backing_stream'] ) ) {
				@fflush( $state['backing_stream'] );
				self::sync_stream( $state['backing_stream'] );
			}

			$backing_stat = @lstat( $state['backing_path'] );
			if (
				false !== $backing_stat
				&& self::is_regular_stat( $backing_stat )
				&& (int) $backing_stat['dev'] === $state['dev']
				&& (int) $backing_stat['ino'] === $state['ino']
			) {
				if ( ! @rename( $state['backing_path'], $path ) ) {
					$success = false;
				}
			} elseif ( ! self::copy_unlinked_backing( $path, $state ) ) {
				$success = false;
			} else {
				$identity_preserved = false;
			}

			if ( is_resource( $state['backing_stream'] ) ) {
				@fclose( $state['backing_stream'] );
			}

			if ( ! self::restore_metadata( $path, $state, $identity_preserved ) ) {
				$success = false;
			}
		}
		unset( $state );

		self::$paths = array();
		return $success;
	}

	/**
	 * Restore only the complete authenticated ordinary path set.
	 */
	private static function restore_all_strict(): bool {
		foreach ( self::$paths as $path => $state ) {
			$identity_error = self::ordinary_node_identity_error( $path, $state );
			if ( '' !== $identity_error ) {
				self::$restoration_blocker = $identity_error;
				return false;
			}
		}
		foreach ( self::$paths as &$state ) {
			if ( is_resource( $state['fifo_stream'] ) ) {
				@fclose( $state['fifo_stream'] );
				$state['fifo_stream'] = null;
			}
		}
		unset( $state );

		foreach ( self::$paths as $path => &$state ) {
			$identity_error = self::ordinary_node_identity_error( $path, $state );
			if ( '' !== $identity_error || ! @unlink( $path ) ) {
				self::$restoration_blocker = '' !== $identity_error ? $identity_error : 'observer_path_replaced';
				return false;
			}
			if (
				! @fflush( $state['backing_stream'] )
				|| ! self::sync_stream( $state['backing_stream'] )
			) {
				self::$restoration_blocker = 'backing_write_failed';
				return false;
			}

			clearstatcache( true, $path );
			clearstatcache( true, $state['backing_path'] );
			$path_stat    = @lstat( $path );
			$backing_stat = @lstat( $state['backing_path'] );
			if ( false !== $path_stat ) {
				self::$restoration_blocker = 'observer_path_replaced';
				return false;
			}
			if (
				false === $backing_stat
				|| ! self::is_regular_stat( $backing_stat )
				|| (int) $backing_stat['dev'] !== $state['dev']
				|| (int) $backing_stat['ino'] !== $state['ino']
				|| ! @rename( $state['backing_path'], $path )
			) {
				self::$restoration_blocker = 'backing_path_changed';
				return false;
			}
			if ( is_resource( $state['backing_stream'] ) ) {
				@fclose( $state['backing_stream'] );
				$state['backing_stream'] = null;
			}
			if ( ! self::restore_metadata( $path, $state ) ) {
				self::$restoration_blocker = 'backing_write_failed';
				return false;
			}
		}
		unset( $state );

		self::$paths = array();
		return true;
	}

	/**
	 * Return a fixed error when one ordinary FIFO/backing identity differs.
	 *
	 * @param string              $path  Public FIFO path.
	 * @param array<string,mixed> $state Retained path state.
	 */
	private static function ordinary_node_identity_error( string $path, array $state ): string {
		clearstatcache( true, $path );
		clearstatcache( true, $state['backing_path'] );
		$path_stat    = @lstat( $path );
		$backing_stat = @lstat( $state['backing_path'] );
		if (
			false === $path_stat
			|| ! self::is_fifo_stat( $path_stat )
			|| (int) $path_stat['dev'] !== $state['fifo_dev']
			|| (int) $path_stat['ino'] !== $state['fifo_ino']
		) {
			return 'observer_path_replaced';
		}
		if (
			false === $backing_stat
			|| ! self::is_regular_stat( $backing_stat )
			|| (int) $backing_stat['dev'] !== $state['dev']
			|| (int) $backing_stat['ino'] !== $state['ino']
		) {
			return 'backing_path_changed';
		}

		return '';
	}

	/**
	 * Preserve bytes appended to a replacement that retained the marked prefix.
	 *
	 * @param string              $path  Replacement path.
	 * @param array<string,mixed> $state Path state.
	 */
	private static function merge_replacement_suffix( string $path, array &$state ): void {
		$replacement = @file_get_contents( $path );
		if ( ! is_string( $replacement ) ) {
			return;
		}

		$initial_length = strlen( $state['initial_bytes'] );
		if ( substr( $replacement, 0, $initial_length ) === $state['initial_bytes'] ) {
			$suffix = substr( $replacement, $initial_length );
			if ( '' !== $suffix ) {
				self::append_to_backing( $state, $suffix );
			}
		}
	}

	/**
	 * Append exact bytes to one backing stream during restoration.
	 *
	 * @param array<string,mixed> $state Path state.
	 * @param string              $bytes Exact bytes.
	 */
	private static function append_to_backing( array &$state, string $bytes ): bool {
		$length = strlen( $bytes );
		$offset = 0;
		while ( $offset < $length ) {
			$written = @fwrite( $state['backing_stream'], substr( $bytes, $offset ) );
			if ( false === $written || 0 === $written ) {
				return false;
			}
			$offset += $written;
		}
		$state['byte_count'] += $length;
		return @fflush( $state['backing_stream'] );
	}

	/**
	 * Materialize an unlinked but open backing stream at its original path.
	 *
	 * @param string              $path  Original path.
	 * @param array<string,mixed> $state Path state.
	 */
	private static function copy_unlinked_backing( string $path, array &$state ): bool {
		$recovery_path = $state['backing_path'] . '.recovery';
		if ( file_exists( $recovery_path ) || is_link( $recovery_path ) ) {
			return false;
		}
		$recovery = @fopen( $recovery_path, 'x+b' );
		if ( false === $recovery || 0 !== @fseek( $state['backing_stream'], 0, SEEK_SET ) ) {
			if ( is_resource( $recovery ) ) {
				@fclose( $recovery );
				@unlink( $recovery_path );
			}
			return false;
		}

		$copied = @stream_copy_to_stream( $state['backing_stream'], $recovery );
		@fflush( $recovery );
		self::sync_stream( $recovery );
		@fclose( $recovery );
		if ( false === $copied || $copied !== $state['byte_count'] || ! @rename( $recovery_path, $path ) ) {
			@unlink( $recovery_path );
			return false;
		}

		return true;
	}

	/**
	 * Reapply and verify the marked metadata and resulting length.
	 *
	 * @param string              $path             Restored path.
	 * @param array<string,mixed> $state            Path state.
	 * @param bool                $require_identity Whether restoration must retain the marked inode.
	 */
	private static function restore_metadata( string $path, array $state, bool $require_identity = true ): bool {
		@chown( $path, $state['owner'] );
		@chgrp( $path, $state['group'] );
		@chmod( $path, $state['mode'] );
		clearstatcache( true, $path );
		$stat = @stat( $path );
		return false !== $stat
			&& self::is_regular_stat( $stat )
			&& (int) $stat['uid'] === $state['owner']
			&& (int) $stat['gid'] === $state['group']
			&& ( (int) $stat['mode'] & 07777 ) === $state['mode']
			&& ( ! $require_identity || ( (int) $stat['dev'] === $state['dev'] && (int) $stat['ino'] === $state['ino'] ) )
			&& (int) $stat['size'] === $state['byte_count'];
	}

	/**
	 * Restore a crashed observer from the authenticated marker and backing names.
	 *
	 * @param array<string,mixed> $marker Authenticated marker.
	 */
	private static function recover( array $marker ): void {
		$primary_path              = (string) array_key_first( $marker['paths'] );
		self::$forwarder_artifacts = self::forwarder_artifact_paths( $primary_path, self::$observer_id );
		$lease                     = self::load_forwarder_lease( $marker );
		if ( null === $lease ) {
			self::emit_recovery_result( false );
		}

		self::$forwarder_nonce = $lease['forwarder_nonce'];
		self::$forwarder_pid   = $lease['child_pid'];
		$challenge_nonce       = self::new_uuid();
		if ( ! self::write_forwarder_control( 'challenge', $challenge_nonce ) ) {
			self::emit_recovery_result( false );
		}
		if ( ! self::wait_for_forwarder_heartbeat( $challenge_nonce, self::$forwarder_pid ) ) {
			if ( @posix_kill( self::$forwarder_pid, 0 ) || ! self::emergency_recover_dead_forwarder( $marker, $lease ) ) {
				self::emit_recovery_result( false );
			}
			self::emit_recovery_result( self::remove_forwarder_artifacts() );
		}
		if ( ! self::write_recovery_sentinels( $marker ) || ! self::wait_for_forwarder_complete( self::$forwarder_pid ) ) {
			self::emit_recovery_result( false );
		}

		$success = self::restore_recovered_paths( $marker, $lease );
		if ( $success && ! self::remove_forwarder_artifacts() ) {
			$success = false;
		}
		self::emit_recovery_result( $success );
	}

	/**
	 * Load and authenticate the exact durable forwarder lease.
	 *
	 * @param array<string,mixed> $marker Authenticated marker.
	 * @return array<string,mixed>|null
	 */
	private static function load_forwarder_lease( array $marker ) {
		$encoded = @file_get_contents( self::$forwarder_artifacts['lease'] );
		$lease   = is_string( $encoded ) ? json_decode( $encoded, true ) : null;
		if (
			! is_array( $lease )
			|| array_keys( $lease ) !== array( 'schema', 'run_stamp', 'store', 'flow_id', 'purpose', 'marker_created_at', 'observer_id', 'origin_binding', 'forwarder_nonce', 'coordinator_pid', 'child_pid', 'journal', 'control', 'artifacts', 'paths', 'hmac' )
			|| self::LEASE_SCHEMA !== $lease['schema']
			|| self::$run_stamp !== $lease['run_stamp']
			|| self::$store !== $lease['store']
			|| self::$flow_id !== $lease['flow_id']
			|| self::$purpose !== $lease['purpose']
			|| self::$marker_created_at !== $lease['marker_created_at']
			|| self::$observer_id !== $lease['observer_id']
			|| self::$origin_binding !== $lease['origin_binding']
			|| ! self::valid_uuid( $lease['forwarder_nonce'] )
			|| ! is_int( $lease['coordinator_pid'] )
			|| $lease['coordinator_pid'] <= 1
			|| ! is_int( $lease['child_pid'] )
			|| $lease['child_pid'] <= 1
			|| basename( self::$forwarder_artifacts['journal'] ) !== $lease['journal']
			|| basename( self::$forwarder_artifacts['control'] ) !== $lease['control']
			|| ! self::valid_forwarder_artifact_identities( $lease['artifacts'] )
			|| ! is_array( $lease['paths'] )
			|| ! is_string( $lease['hmac'] )
		) {
			return null;
		}

		$signature = $lease['hmac'];
		unset( $lease['hmac'] );
		$canonical     = wp_json_encode( $lease );
		$expected      = is_string( $canonical )
			? 'hmac-sha256:' . hash_hmac( 'sha256', self::LEASE_SCHEMA . "\0" . $canonical, self::$context_key )
			: '';
		$lease['hmac'] = $signature;
		if ( ! hash_equals( $expected, $signature ) ) {
			return null;
		}
		if ( ! self::artifact_identities_match( $lease['artifacts'] ) ) {
			return null;
		}
		self::$forwarder_artifact_identities = $lease['artifacts'];

		$expected_paths = array();
		foreach ( $marker['paths'] as $path => $observation ) {
			$path_stat    = @lstat( $path );
			$backing_stat = @lstat( self::backing_path( $path, self::$observer_id ) );
			if (
				false === $path_stat
				|| ! self::is_fifo_stat( $path_stat )
				|| false === $backing_stat
				|| ! self::is_regular_stat( $backing_stat )
			) {
				return null;
			}
			$expected_paths[] = array(
				'path'        => basename( $path ),
				'path_id'     => $observation['path_id'],
				'fifo_dev'    => (int) $path_stat['dev'],
				'fifo_ino'    => (int) $path_stat['ino'],
				'backing_dev' => (int) $backing_stat['dev'],
				'backing_ino' => (int) $backing_stat['ino'],
			);
		}
		usort(
			$expected_paths,
			static function ( array $left, array $right ): int {
				return strcmp( $left['path_id'], $right['path_id'] );
			}
		);
		if ( $expected_paths !== $lease['paths'] ) {
			return null;
		}
		foreach ( self::$forwarder_artifacts as $artifact_path ) {
			$stat = @lstat( $artifact_path );
			if ( false === $stat || ! self::is_regular_stat( $stat ) || 0600 !== ( (int) $stat['mode'] & 0777 ) ) {
				return null;
			}
		}

		return $lease;
	}

	/**
	 * Validate bounded artifact identities before authenticating their values.
	 *
	 * @param mixed $identities Untrusted lease field.
	 */
	private static function valid_forwarder_artifact_identities( $identities ): bool {
		$kinds = array_keys( self::$forwarder_artifacts );
		if ( ! is_array( $identities ) || array_keys( $identities ) !== $kinds ) {
			return false;
		}
		$inode_ids = array();
		foreach ( $identities as $kind => $identity ) {
			if (
				! is_array( $identity )
				|| array_keys( $identity ) !== array( 'kind', 'dev', 'ino', 'mode' )
				|| $kind !== $identity['kind']
				|| ! is_int( $identity['dev'] )
				|| $identity['dev'] < 0
				|| ! is_int( $identity['ino'] )
				|| $identity['ino'] < 1
				|| 0600 !== $identity['mode']
			) {
				return false;
			}
			$inode_id = $identity['dev'] . ':' . $identity['ino'];
			if ( isset( $inode_ids[ $inode_id ] ) ) {
				return false;
			}
			$inode_ids[ $inode_id ] = true;
		}

		return true;
	}

	/**
	 * Wait for the live child to authenticate one fresh coordinator challenge.
	 *
	 * @param string $challenge_nonce Fresh challenge UUID.
	 * @param int    $child_pid       Lease-bound child PID hint.
	 */
	private static function wait_for_forwarder_heartbeat( string $challenge_nonce, int $child_pid ): bool {
		$deadline = microtime( true ) + 1;
		while ( microtime( true ) < $deadline ) {
			$records = self::validated_forwarder_journal();
			if ( is_array( $records ) ) {
				foreach ( $records as $record ) {
					if (
						'heartbeat' === ( $record['kind'] ?? '' )
						&& ( $record['challenge_nonce'] ?? '' ) === $challenge_nonce
						&& ( $record['child_pid'] ?? 0 ) === $child_pid
					) {
						return true;
					}
				}
			}
			usleep( self::SELECT_USEC );
		}

		return false;
	}

	/**
	 * Wait for the authenticated child journal to end in durable completion.
	 *
	 * @param int $child_pid Freshly challenged child PID.
	 */
	private static function wait_for_forwarder_complete( int $child_pid ): bool {
		$deadline = microtime( true ) + 3;
		while ( microtime( true ) < $deadline ) {
			$records = self::validated_forwarder_journal();
			$last    = is_array( $records ) && ! empty( $records ) ? end( $records ) : null;
			if ( is_array( $last ) && 'complete' === ( $last['kind'] ?? '' ) ) {
				if ( ! @posix_kill( $child_pid, 0 ) ) {
					return true;
				}
			}
			usleep( self::SELECT_USEC );
		}

		return false;
	}

	/**
	 * Validate the complete bounded forwarder journal HMAC chain.
	 *
	 * @return array<int,array<string,mixed>>|null
	 */
	private static function validated_forwarder_journal() {
		$snapshot = self::validated_forwarder_journal_snapshot();
		return is_array( $snapshot ) ? $snapshot['records'] : null;
	}

	/**
	 * Validate one stable complete journal snapshot and its exact file identity.
	 *
	 * @return array{records:array<int,array<string,mixed>>,identity:array{dev:int,ino:int,size:int,sha256:string},bytes:string}|null
	 */
	private static function validated_forwarder_journal_snapshot() {
		$journal_path = self::$forwarder_artifacts['journal'];
		clearstatcache( true, $journal_path );
		$path_before = @lstat( $journal_path );
		$journal     = @fopen( $journal_path, 'rb' );
		$stat_before = is_resource( $journal ) ? @fstat( $journal ) : false;
		if (
			! is_resource( $journal )
			|| false === $path_before
			|| false === $stat_before
			|| ! self::is_regular_stat( $path_before )
			|| ! self::is_regular_stat( $stat_before )
			|| (int) $path_before['dev'] !== (int) $stat_before['dev']
			|| (int) $path_before['ino'] !== (int) $stat_before['ino']
			|| 0600 !== ( (int) $path_before['mode'] & 0777 )
			|| (int) $stat_before['size'] < 1
			|| (int) $stat_before['size'] > self::MAX_JOURNAL
		) {
			if ( is_resource( $journal ) ) {
				@fclose( $journal );
			}
			return null;
		}

		$size         = (int) $stat_before['size'];
		$encoded      = '';
		$encoded_size = 0;
		while ( $encoded_size < $size ) {
			$chunk = @fread( $journal, min( self::READ_BYTES, $size - $encoded_size ) );
			if ( ! is_string( $chunk ) || '' === $chunk ) {
				@fclose( $journal );
				return null;
			}
			$encoded      .= $chunk;
			$encoded_size += strlen( $chunk );
		}
		$stat_after = @fstat( $journal );
		@fclose( $journal );
		clearstatcache( true, $journal_path );
		$path_after = @lstat( $journal_path );
		if (
			false === $stat_after
			|| false === $path_after
			|| ! self::is_regular_stat( $path_after )
			|| (int) $stat_after['dev'] !== (int) $stat_before['dev']
			|| (int) $stat_after['ino'] !== (int) $stat_before['ino']
			|| (int) $stat_after['size'] !== $size
			|| (int) $path_after['dev'] !== (int) $stat_before['dev']
			|| (int) $path_after['ino'] !== (int) $stat_before['ino']
			|| (int) $path_after['size'] !== $size
			|| 0600 !== ( (int) $path_after['mode'] & 0777 )
			|| "\n" !== substr( $encoded, -1 )
		) {
			return null;
		}

		$lines = explode( "\n", substr( $encoded, 0, -1 ) );
		if ( empty( $lines ) || count( $lines ) > self::MAX_RECORDS ) {
			return null;
		}

		$records       = array();
		$previous      = str_repeat( '0', 64 );
		$sequence      = 0;
		$ready_seen    = false;
		$terminal_seen = false;
		foreach ( $lines as $line ) {
			$record = json_decode( $line, true );
			if ( ! is_array( $record ) || ! self::valid_forwarder_record_shape( $record ) ) {
				return null;
			}
			$signed_record = $record;
			$signature     = $record['hmac'];
			unset( $record['hmac'] );
			$canonical = $record;
			ksort( $canonical, SORT_STRING );
			$canonical = wp_json_encode( $canonical );
			$expected  = is_string( $canonical )
				? 'hmac-sha256:' . hash_hmac( 'sha256', $previous . "\0" . $canonical, self::$context_key )
				: '';
			++$sequence;
			$kind = $record['kind'];
			if (
				! hash_equals( $expected, $signature )
				|| self::RECORD_SCHEMA !== ( $record['schema'] ?? '' )
				|| ( $record['run_stamp'] ?? '' ) !== self::$run_stamp
				|| ( $record['store'] ?? '' ) !== self::$store
				|| ( $record['flow_id'] ?? '' ) !== self::$flow_id
				|| ( $record['purpose'] ?? '' ) !== self::$purpose
				|| ( $record['marker_created_at'] ?? '' ) !== self::$marker_created_at
				|| ( $record['observer_id'] ?? '' ) !== self::$observer_id
				|| ( $record['paths'] ?? null ) !== self::$path_contexts
				|| ( $record['sequence'] ?? 0 ) !== $sequence
				|| ( $record['previous_hmac'] ?? '' ) !== 'hmac-sha256:' . $previous
				|| ( 1 === $sequence && 'ready' !== $kind )
				|| ( 1 < $sequence && ! $ready_seen )
				|| ( 'ready' === $kind && $ready_seen )
				|| $terminal_seen
			) {
				return null;
			}
			$ready_seen    = $ready_seen || 'ready' === $kind;
			$terminal_seen = in_array( $kind, array( 'complete', 'blocked' ), true );
			$records[]     = $signed_record;
			$previous      = substr( $signature, strlen( 'hmac-sha256:' ) );
		}

		return array(
			'records'  => $records,
			'bytes'    => $encoded,
			'identity' => array(
				'dev'    => (int) $stat_before['dev'],
				'ino'    => (int) $stat_before['ino'],
				'size'   => $size,
				'sha256' => 'sha256:' . hash( 'sha256', $encoded ),
			),
		);
	}

	/**
	 * Read one exact reserved artifact without accepting a pathname substitution.
	 *
	 * @param string $kind      Reserved artifact kind.
	 * @param int    $max_bytes Inclusive finite size limit.
	 * @return array{bytes:string,identity:array{dev:int,ino:int,size:int,sha256:string}}|null
	 */
	private static function reserved_artifact_snapshot( string $kind, int $max_bytes ) {
		$identity = self::$forwarder_artifact_identities[ $kind ] ?? null;
		if ( ! isset( self::$forwarder_artifacts[ $kind ] ) || ! is_array( $identity ) || ! self::artifact_identity_matches( $kind, $identity ) ) {
			return null;
		}
		$path        = self::$forwarder_artifacts[ $kind ];
		$path_before = @lstat( $path );
		$stream      = @fopen( $path, 'rb' );
		$stat_before = is_resource( $stream ) ? @fstat( $stream ) : false;
		if (
			! is_resource( $stream )
			|| false === $path_before
			|| false === $stat_before
			|| ! self::is_regular_stat( $path_before )
			|| ! self::is_regular_stat( $stat_before )
			|| (int) $path_before['dev'] !== (int) $stat_before['dev']
			|| (int) $path_before['ino'] !== (int) $stat_before['ino']
			|| 0600 !== ( (int) $path_before['mode'] & 0777 )
			|| (int) $stat_before['size'] < 0
			|| (int) $stat_before['size'] > $max_bytes
		) {
			if ( is_resource( $stream ) ) {
				@fclose( $stream );
			}
			return null;
		}
		$size         = (int) $stat_before['size'];
		$bytes        = '';
		$bytes_length = 0;
		while ( $bytes_length < $size ) {
			$chunk = @fread( $stream, min( self::READ_BYTES, $size - $bytes_length ) );
			if ( ! is_string( $chunk ) || '' === $chunk ) {
				@fclose( $stream );
				return null;
			}
			$bytes        .= $chunk;
			$bytes_length += strlen( $chunk );
		}
		$stat_after = @fstat( $stream );
		@fclose( $stream );
		clearstatcache( true, $path );
		$path_after = @lstat( $path );
		if (
			false === $stat_after
			|| false === $path_after
			|| ! self::is_regular_stat( $path_after )
			|| (int) $stat_after['dev'] !== (int) $stat_before['dev']
			|| (int) $stat_after['ino'] !== (int) $stat_before['ino']
			|| (int) $stat_after['size'] !== $size
			|| (int) $path_after['dev'] !== (int) $stat_before['dev']
			|| (int) $path_after['ino'] !== (int) $stat_before['ino']
			|| (int) $path_after['size'] !== $size
			|| ! self::artifact_identity_matches( $kind, $identity )
		) {
			return null;
		}

		return array(
			'bytes'    => $bytes,
			'identity' => array(
				'dev'    => (int) $stat_before['dev'],
				'ino'    => (int) $stat_before['ino'],
				'size'   => $size,
				'sha256' => 'sha256:' . hash( 'sha256', $bytes ),
			),
		);
	}

	/**
	 * Observe an exact final journal, including the legitimate pre-ready empty state.
	 *
	 * @return array{records:array<int,array<string,mixed>>,identity:array{dev:int,ino:int,size:int,sha256:string},bytes:string}|null
	 */
	private static function final_forwarder_journal_snapshot() {
		$raw = self::reserved_artifact_snapshot( 'journal', self::MAX_JOURNAL );
		if ( ! is_array( $raw ) ) {
			return null;
		}
		if ( '' === $raw['bytes'] ) {
			return array(
				'records'  => array(),
				'identity' => $raw['identity'],
				'bytes'    => '',
			);
		}
		$validated = self::validated_forwarder_journal_snapshot();
		return is_array( $validated )
			&& $validated['identity'] === $raw['identity']
			&& $validated['bytes'] === $raw['bytes']
			? $validated
			: null;
	}

	/**
	 * Require each previously mirrored record to remain an exact HMAC prefix.
	 *
	 * @param array<int,array<string,mixed>> $records         Validated journal records.
	 * @param array<int,array<string,mixed>> $mirrored_records Exact records already sent to the host.
	 */
	private static function journal_extends_mirrored_records( array $records, array $mirrored_records ): bool {
		if ( count( $records ) < count( $mirrored_records ) ) {
			return false;
		}
		foreach ( $mirrored_records as $index => $record ) {
			if ( $records[ $index ] !== $record ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Validate one exact kind-specific forwarder record shape.
	 *
	 * @param array<string,mixed> $record Signed candidate record.
	 */
	private static function valid_forwarder_record_shape( array $record ): bool {
		$common_fields = array(
			'schema',
			'sequence',
			'kind',
			'run_stamp',
			'store',
			'flow_id',
			'purpose',
			'marker_created_at',
			'observer_id',
			'paths',
			'previous_hmac',
			'hmac',
		);
		$extra_fields  = array(
			'ready'     => array( 'status', 'path_count', 'origin_binding', 'key_fingerprint' ),
			'heartbeat' => array( 'challenge_nonce', 'child_pid' ),
			'line'      => array( 'path', 'line', 'category', 'fingerprint' ),
			'complete'  => array( 'status' ),
			'blocked'   => array( 'blocker_code' ),
		);
		$kind          = $record['kind'] ?? '';
		if ( ! is_string( $kind ) || ! isset( $extra_fields[ $kind ] ) ) {
			return false;
		}
		$expected_fields = array_merge( $common_fields, $extra_fields[ $kind ] );
		$actual_fields   = array_keys( $record );
		sort( $expected_fields, SORT_STRING );
		sort( $actual_fields, SORT_STRING );
		if ( $expected_fields !== $actual_fields || ! self::valid_hmac( $record['hmac'] ) ) {
			return false;
		}

		if ( 'ready' === $kind ) {
			return 'pass' === $record['status']
				&& is_int( $record['path_count'] )
				&& count( self::$path_contexts ) === $record['path_count']
				&& is_string( $record['origin_binding'] )
				&& hash_equals( self::$origin_binding, $record['origin_binding'] )
				&& is_string( $record['key_fingerprint'] )
				&& hash_equals( 'sha256:' . hash( 'sha256', self::$context_key ), $record['key_fingerprint'] );
		}
		if ( 'heartbeat' === $kind ) {
			return self::valid_uuid( $record['challenge_nonce'] )
				&& is_int( $record['child_pid'] )
				&& self::$forwarder_pid === $record['child_pid'];
		}
		if ( 'line' === $kind ) {
			$path_names = array_column( self::$path_contexts, 'path' );
			return is_string( $record['path'] )
				&& in_array( $record['path'], $path_names, true )
				&& is_int( $record['line'] )
				&& 0 < $record['line']
				&& in_array(
					$record['category'],
					array( 'allowlisted_noise', 'fatal_error', 'parse_error', 'warning', 'notice', 'deprecated', 'strict_standards', 'other', 'terminal' ),
					true
				)
				&& self::valid_fingerprint( $record['fingerprint'] );
		}
		if ( 'complete' === $kind ) {
			return 'pass' === $record['status'];
		}

		return in_array(
			$record['blocker_code'],
			array( 'observer_interrupted', 'observer_read_failed', 'backing_write_failed', 'observer_path_replaced', 'backing_path_changed', 'sentinel_timeout', 'observer_lifetime_exceeded', 'observer_forced_recovery', 'observer_line_too_long', 'forwarder_artifact_changed' ),
			true
		);
	}

	/**
	 * Write the authenticated terminal sentinel to each validated FIFO.
	 *
	 * @param array<string,mixed> $marker Authenticated marker.
	 */
	private static function write_recovery_sentinels( array $marker ): bool {
		foreach ( array_keys( $marker['paths'] ) as $path ) {
			$sentinel = self::terminal_line( $path, $marker['paths'][ $path ]['path_id'] );
			if ( strlen( $sentinel ) !== @file_put_contents( $path, $sentinel ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Drain FIFO bytes after authenticating the surviving coordinator.
	 *
	 * @param array<string,mixed> $marker Authenticated marker.
	 * @param array<string,mixed> $lease  Authenticated lease.
	 */
	private static function emergency_recover_dead_forwarder( array $marker, array $lease ): bool {
		$challenge_nonce = self::new_uuid();
		if (
			! self::write_forwarder_control( 'coordinator_challenge', $challenge_nonce )
			|| ! self::wait_for_coordinator_heartbeat( $challenge_nonce, $lease['coordinator_pid'] )
		) {
			return false;
		}

		$streams = array();
		foreach ( $marker['paths'] as $path => $observation ) {
			$fifo    = @fopen( $path, 'rb' );
			$backing = @fopen( self::backing_path( $path, self::$observer_id ), 'ab' );
			if ( false === $fifo || false === $backing || ! @stream_set_blocking( $fifo, false ) ) {
				self::close_emergency_streams( $streams );
				if ( is_resource( $fifo ) ) {
					@fclose( $fifo );
				}
				if ( is_resource( $backing ) ) {
					@fclose( $backing );
				}
				return false;
			}
			$fifo_stat    = @fstat( $fifo );
			$backing_stat = @fstat( $backing );
			$lease_path   = null;
			foreach ( $lease['paths'] as $candidate ) {
				if (
					basename( $path ) === ( $candidate['path'] ?? '' )
					&& ( $candidate['path_id'] ?? '' ) === $observation['path_id']
				) {
					$lease_path = $candidate;
					break;
				}
			}
			if (
				! is_array( $fifo_stat )
				|| ! is_array( $backing_stat )
				|| ! is_array( $lease_path )
				|| (int) $fifo_stat['dev'] !== $lease_path['fifo_dev']
				|| (int) $fifo_stat['ino'] !== $lease_path['fifo_ino']
				|| (int) $backing_stat['dev'] !== $lease_path['backing_dev']
				|| (int) $backing_stat['ino'] !== $lease_path['backing_ino']
			) {
				@fclose( $fifo );
				@fclose( $backing );
				self::close_emergency_streams( $streams );
				return false;
			}
			$streams[] = array(
				'path'          => $path,
				'path_id'       => $observation['path_id'],
				'fifo'          => $fifo,
				'backing'       => $backing,
				'byte_count'    => (int) $backing_stat['size'],
				'terminal_tail' => '',
			);
		}

		if ( ! self::write_forwarder_control( 'coordinator_exit', $challenge_nonce ) ) {
			self::close_emergency_streams( $streams );
			return false;
		}

		$deadline = microtime( true ) + 2;
		$complete = false;
		while ( microtime( true ) < $deadline ) {
			$complete = true;
			foreach ( $streams as &$stream ) {
				$bytes = @fread( $stream['fifo'], self::READ_BYTES );
				if ( false === $bytes ) {
					unset( $stream );
					self::close_emergency_streams( $streams );
					return false;
				}
				if ( '' !== $bytes ) {
					if (
						! self::write_all( $stream['backing'], $bytes )
						|| ! @fflush( $stream['backing'] )
						|| ! self::sync_stream( $stream['backing'] )
					) {
						unset( $stream );
						self::close_emergency_streams( $streams );
						return false;
					}
					$terminal                = self::terminal_line( $stream['path'], $stream['path_id'] );
					$stream['byte_count']   += strlen( $bytes );
					$stream['terminal_tail'] = self::terminal_suffix_tail( $stream['terminal_tail'], $bytes, $terminal );
				}
				if ( ! feof( $stream['fifo'] ) ) {
					$complete = false;
				}
			}
			unset( $stream );
			if ( $complete ) {
				break;
			}
			usleep( self::SELECT_USEC );
		}
		if ( ! $complete ) {
			self::close_emergency_streams( $streams );
			return false;
		}
		foreach ( $streams as &$stream ) {
			$terminal = self::terminal_line( $stream['path'], $stream['path_id'] );
			if (
				hash_equals( $terminal, $stream['terminal_tail'] )
				&& ! self::truncate_stream_suffix( $stream['backing'], $stream['byte_count'], strlen( $terminal ) )
			) {
				unset( $stream );
				self::close_emergency_streams( $streams );
				return false;
			}
			if ( ! @fflush( $stream['backing'] ) || ! self::sync_stream( $stream['backing'] ) ) {
				unset( $stream );
				self::close_emergency_streams( $streams );
				return false;
			}
		}
		unset( $stream );
		self::close_emergency_streams( $streams );

		return self::append_emergency_journal_record() && self::restore_recovered_paths( $marker, $lease );
	}

	/**
	 * Wait for an authenticated response from the surviving coordinator.
	 *
	 * @param string $challenge_nonce Fresh challenge UUID.
	 * @param int    $coordinator_pid Lease-bound coordinator PID.
	 */
	private static function wait_for_coordinator_heartbeat( string $challenge_nonce, int $coordinator_pid ): bool {
		$deadline = microtime( true ) + 1;
		while ( microtime( true ) < $deadline ) {
			$control = self::forwarder_control();
			if (
				'coordinator_heartbeat' === ( $control['action'] ?? '' )
				&& ( $control['challenge_nonce'] ?? '' ) === $challenge_nonce
				&& ( $control['responder_pid'] ?? 0 ) === $coordinator_pid
			) {
				return true;
			}
			usleep( self::SELECT_USEC );
		}

		return false;
	}

	/**
	 * Append one fixed authenticated emergency-recovery journal record.
	 */
	private static function append_emergency_journal_record(): bool {
		$records = self::validated_forwarder_journal();
		if ( ! is_array( $records ) || empty( $records ) ) {
			return false;
		}
		$last = end( $records );
		if ( ! is_array( $last ) || ! isset( $last['hmac'] ) || ! is_string( $last['hmac'] ) ) {
			return false;
		}
		self::$sequence             = (int) $last['sequence'];
		self::$previous_hmac        = substr( $last['hmac'], strlen( 'hmac-sha256:' ) );
		self::$journal_record_count = count( $records );
		$size                       = @filesize( self::$forwarder_artifacts['journal'] );
		self::$journal_byte_count   = is_int( $size ) ? $size : self::MAX_JOURNAL;
		self::$journal_stream       = @fopen( self::$forwarder_artifacts['journal'], 'ab' );
		if ( ! is_resource( self::$journal_stream ) ) {
			return false;
		}
		self::emit( 'blocked', array( 'blocker_code' => 'observer_forced_recovery' ) );
		$closed = @fflush( self::$journal_stream ) && self::sync_stream( self::$journal_stream );
		@fclose( self::$journal_stream );
		self::$journal_stream = null;
		return $closed;
	}

	/**
	 * Write every byte to one stream.
	 *
	 * @param resource $stream Destination stream.
	 * @param string   $bytes  Exact bytes.
	 */
	private static function write_all( $stream, string $bytes ): bool {
		$length = strlen( $bytes );
		$offset = 0;
		while ( $offset < $length ) {
			$written = @fwrite( $stream, substr( $bytes, $offset ) );
			if ( false === $written || 0 === $written ) {
				return false;
			}
			$offset += $written;
		}

		return true;
	}

	/**
	 * Close every emergency FIFO/backing stream pair.
	 *
	 * @param array<int,array{fifo:resource,backing:resource}> $streams Streams.
	 */
	private static function close_emergency_streams( array $streams ): void {
		foreach ( $streams as $stream ) {
			@fclose( $stream['fifo'] );
			@fclose( $stream['backing'] );
		}
	}

	/**
	 * Restore paths after the child journal proves durable completion.
	 *
	 * @param array<string,mixed> $marker Authenticated marker.
	 * @param array<string,mixed> $lease  Authenticated forwarder lease.
	 */
	private static function restore_recovered_paths( array $marker, array $lease ): bool {
		$recovery_paths = array();
		foreach ( $marker['paths'] as $path => $observation ) {
			$backing_path = self::backing_path( $path, self::$observer_id );
			$lease_path   = null;
			foreach ( $lease['paths'] as $candidate ) {
				if (
					basename( $path ) === ( $candidate['path'] ?? '' )
					&& ( $candidate['path_id'] ?? '' ) === $observation['path_id']
				) {
					if ( null !== $lease_path ) {
						return false;
					}
					$lease_path = $candidate;
				}
			}
			if ( ! is_array( $lease_path ) ) {
				return false;
			}
			$recovery_path = array(
				'path'         => $path,
				'path_id'      => $lease_path['path_id'],
				'backing_path' => $backing_path,
				'observation'  => $observation,
				'fifo_dev'     => $lease_path['fifo_dev'],
				'fifo_ino'     => $lease_path['fifo_ino'],
				'backing_dev'  => $lease_path['backing_dev'],
				'backing_ino'  => $lease_path['backing_ino'],
			);
			if ( ! self::recovery_nodes_match_lease( $recovery_path ) ) {
				return false;
			}
			$recovery_paths[] = $recovery_path;
		}
		if ( count( $recovery_paths ) !== count( $lease['paths'] ) ) {
			return false;
		}

		foreach ( $recovery_paths as $recovery_path ) {
			if ( ! self::recovery_nodes_match_lease( $recovery_path ) ) {
				return false;
			}
			$path         = $recovery_path['path'];
			$backing_path = $recovery_path['backing_path'];
			$observation  = $recovery_path['observation'];
			if ( ! @unlink( $path ) ) {
				return false;
			}

			clearstatcache( true, $backing_path );
			$backing_stat = @lstat( $backing_path );
			if (
				false === $backing_stat
				|| ! self::is_regular_stat( $backing_stat )
				|| (int) $backing_stat['dev'] !== $recovery_path['backing_dev']
				|| (int) $backing_stat['ino'] !== $recovery_path['backing_ino']
				|| ! @rename( $backing_path, $path )
			) {
				return false;
			}
			@chown( $path, $observation['owner'] );
			@chgrp( $path, $observation['group'] );
			@chmod( $path, $observation['mode'] );
			clearstatcache( true, $path );
			$restored = @stat( $path );
			if (
				false === $restored
				|| ! self::is_regular_stat( $restored )
				|| (int) $restored['uid'] !== $observation['owner']
				|| (int) $restored['gid'] !== $observation['group']
				|| ( (int) $restored['mode'] & 07777 ) !== $observation['mode']
				|| (int) $restored['dev'] !== $observation['dev']
				|| (int) $restored['ino'] !== $observation['ino']
			) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Check one named FIFO/backing pair against its authenticated lease tuple.
	 *
	 * @param array<string,mixed> $recovery_path Lease-bound recovery path.
	 */
	private static function recovery_nodes_match_lease( array $recovery_path ): bool {
		clearstatcache( true, $recovery_path['path'] );
		clearstatcache( true, $recovery_path['backing_path'] );
		$path_stat    = @lstat( $recovery_path['path'] );
		$backing_stat = @lstat( $recovery_path['backing_path'] );
		return false !== $path_stat
			&& self::is_fifo_stat( $path_stat )
			&& (int) $path_stat['dev'] === $recovery_path['fifo_dev']
			&& (int) $path_stat['ino'] === $recovery_path['fifo_ino']
			&& false !== $backing_stat
			&& self::is_regular_stat( $backing_stat )
			&& (int) $backing_stat['dev'] === $recovery_path['backing_dev']
			&& (int) $backing_stat['ino'] === $recovery_path['backing_ino'];
	}

	/**
	 * Emit the fixed forced-recovery result and terminate.
	 *
	 * @param bool $success Whether authenticated recovery completed.
	 */
	private static function emit_recovery_result( bool $success ): void {
		self::emit(
			'recovered',
			array(
				'status'       => 'blocked',
				'blocker_code' => $success ? 'observer_forced_recovery' : 'observer_recovery_failed',
			)
		);
		exit( 3 );
	}

	/**
	 * Load and authenticate the strict v5 marker.
	 *
	 * @param bool $require_regular Whether current paths must still be marked regular files.
	 * @return array<string,mixed>|null
	 */
	private static function load_marker( bool $require_regular ) {
		$marker           = get_option( 'woopayments_critical_flows_debug_log_marker', array() );
		$expected_store   = getenv( 'CRITICAL_FLOWS_STORE' );
		$expected_flow_id = getenv( 'CRITICAL_FLOWS_FLOW_ID' );
		$expected_purpose = getenv( 'CRITICAL_FLOWS_LOG_PURPOSE' );
		if (
			! is_array( $marker )
			|| array_keys( $marker ) !== array( 'schema', 'created_at', 'run_stamp', 'store', 'flow_id', 'purpose', 'origin_nonce', 'observer_id', 'key_fingerprint', 'origin_binding', 'paths' )
			|| self::MARKER_SCHEMA !== $marker['schema']
			|| ! is_string( $marker['created_at'] )
			|| false === strtotime( $marker['created_at'] )
			|| self::$run_stamp !== $marker['run_stamp']
			|| ! is_string( $expected_store )
			|| ! is_string( $expected_flow_id )
			|| ! is_string( $expected_purpose )
			|| $expected_store !== $marker['store']
			|| $expected_flow_id !== $marker['flow_id']
			|| $expected_purpose !== $marker['purpose']
			|| ! in_array( $marker['store'], array( 'ref', 'target' ), true )
			|| ! is_string( $marker['flow_id'] )
			|| ! preg_match( '/^[A-Z]{2,3}-[0-9]{2}-[a-z0-9]+(?:-[a-z0-9]+)*$/', $marker['flow_id'] )
			|| 'clean-debug-log' !== $marker['purpose']
			|| ! self::valid_uuid( $marker['origin_nonce'] )
			|| ! self::valid_uuid( $marker['observer_id'] )
			|| ! is_string( $marker['key_fingerprint'] )
			|| ! hash_equals( 'sha256:' . hash( 'sha256', self::$context_key ), $marker['key_fingerprint'] )
			|| ! is_string( $marker['origin_binding'] )
			|| ! preg_match( '/^hmac-sha256:[0-9a-f]{64}$/', $marker['origin_binding'] )
			|| ! is_array( $marker['paths'] )
			|| empty( $marker['paths'] )
			|| count( $marker['paths'] ) > self::MAX_PATHS
		) {
			return null;
		}

		$seen_basenames = array();
		$loaded_paths   = array();
		foreach ( $marker['paths'] as $path => $observation ) {
			if (
				! is_string( $path )
				|| '' === $path
				|| basename( $path ) === $path
				|| realpath( $path ) !== $path
				|| ! is_array( $observation )
				|| array_keys( $observation ) !== array( 'path_id', 'line_count', 'byte_count', 'identity_fingerprint', 'prefix_fingerprint', 'canary_fingerprint', 'owner', 'group', 'mode' )
				|| ! self::valid_marker_observation( $observation )
				|| ! hash_equals( 'hmac-sha256:' . hash_hmac( 'sha256', "woopayments_debug_log_path.v1\0" . $path, self::$context_key ), $observation['path_id'] )
			) {
				return null;
			}

			$basename = basename( $path );
			if ( '' === $basename || isset( $seen_basenames[ $basename ] ) ) {
				return null;
			}
			$seen_basenames[ $basename ] = true;

			$stat = $require_regular ? @lstat( $path ) : @lstat( self::backing_path( $path, $marker['observer_id'] ) );
			if ( false === $stat || ! self::is_regular_stat( $stat ) ) {
				return null;
			}
			$initial_bytes = $require_regular ? @file_get_contents( $path ) : '';
			if ( $require_regular && ! is_string( $initial_bytes ) ) {
				return null;
			}

			$identity = 'sha256:' . hash( 'sha256', $stat['dev'] . ':' . $stat['ino'] );
			if (
				$identity !== $observation['identity_fingerprint']
				|| (int) $stat['uid'] !== $observation['owner']
				|| (int) $stat['gid'] !== $observation['group']
				|| ( (int) $stat['mode'] & 07777 ) !== $observation['mode']
			) {
				return null;
			}

			if ( $require_regular && ! self::validate_initial_bytes( $initial_bytes, $observation ) ) {
				return null;
			}
			$observation['dev']           = (int) $stat['dev'];
			$observation['ino']           = (int) $stat['ino'];
			$observation['initial_bytes'] = $initial_bytes;
			$loaded_paths[ $path ]        = $observation;
		}

		$material = self::origin_material( $marker );
		$expected = 'hmac-sha256:' . hash_hmac( 'sha256', $material, self::$context_key );
		if ( ! hash_equals( $expected, $marker['origin_binding'] ) ) {
			return null;
		}

		$marker['paths'] = $loaded_paths;
		return $marker;
	}

	/**
	 * Validate one marker observation's bounded scalar fields.
	 *
	 * @param array<string,mixed> $observation Marker observation.
	 */
	private static function valid_marker_observation( array $observation ): bool {
		return self::valid_hmac( $observation['path_id'] )
			&& is_int( $observation['line_count'] )
			&& $observation['line_count'] >= 1
			&& is_int( $observation['byte_count'] )
			&& $observation['byte_count'] >= 1
			&& self::valid_fingerprint( $observation['identity_fingerprint'] )
			&& self::valid_fingerprint( $observation['prefix_fingerprint'] )
			&& self::valid_fingerprint( $observation['canary_fingerprint'] )
			&& is_int( $observation['owner'] )
			&& $observation['owner'] >= 0
			&& is_int( $observation['group'] )
			&& $observation['group'] >= 0
			&& is_int( $observation['mode'] )
			&& $observation['mode'] >= 0
			&& $observation['mode'] <= 07777;
	}

	/**
	 * Validate exact marked bytes and the final canary line.
	 *
	 * @param string              $bytes       Exact file bytes.
	 * @param array<string,mixed> $observation Marker observation.
	 */
	private static function validate_initial_bytes( string $bytes, array $observation ): bool {
		if (
			strlen( $bytes ) !== $observation['byte_count']
			|| 'sha256:' . hash( 'sha256', $bytes ) !== $observation['prefix_fingerprint']
		) {
			return false;
		}

		$lines = preg_split( '/\r\n|\n|\r/', $bytes );
		if ( false === $lines ) {
			return false;
		}
		if ( '' === end( $lines ) ) {
			array_pop( $lines );
		}
		return count( $lines ) === $observation['line_count']
			&& ! empty( $lines )
			&& 'sha256:' . hash( 'sha256', (string) end( $lines ) ) === $observation['canary_fingerprint'];
	}

	/**
	 * Construct the versioned marker-origin HMAC material.
	 *
	 * @param array<string,mixed> $marker Marker.
	 */
	private static function origin_material( array $marker ): string {
		$parts = array(
			self::ORIGIN_SCHEMA,
			$marker['run_stamp'],
			$marker['store'],
			$marker['flow_id'],
			$marker['purpose'],
			$marker['created_at'],
			$marker['origin_nonce'],
			$marker['observer_id'],
			$marker['key_fingerprint'],
		);
		$paths = $marker['paths'];
		uasort(
			$paths,
			static function ( array $left, array $right ): int {
				return strcmp( $left['path_id'], $right['path_id'] );
			}
		);
		foreach ( $paths as $path => $observation ) {
			$parts[] = $observation['path_id'];
			$parts[] = basename( $path );
			$parts[] = (string) $observation['line_count'];
			$parts[] = (string) $observation['byte_count'];
			$parts[] = $observation['identity_fingerprint'];
			$parts[] = $observation['prefix_fingerprint'];
			$parts[] = $observation['canary_fingerprint'];
			$parts[] = (string) $observation['owner'];
			$parts[] = (string) $observation['group'];
			$parts[] = (string) $observation['mode'];
		}

		return implode( "\0", $parts );
	}

	/**
	 * Return sorted safe basename/keyed-ID pairs for authenticated contexts.
	 *
	 * @param array<string,array<string,mixed>> $paths Authenticated marker paths.
	 * @return array<int,array{path:string,path_id:string}>
	 */
	private static function path_contexts( array $paths ): array {
		$contexts = array();
		foreach ( $paths as $path => $observation ) {
			$contexts[] = array(
				'path'    => basename( $path ),
				'path_id' => $observation['path_id'],
			);
		}
		usort(
			$contexts,
			static function ( array $left, array $right ): int {
				return strcmp( $left['path_id'], $right['path_id'] );
			}
		);

		return $contexts;
	}

	/**
	 * Emit one safe authenticated record.
	 *
	 * @param string              $kind   Record kind.
	 * @param array<string,mixed> $fields Safe fields.
	 */
	private static function emit( string $kind, array $fields ): void {
		++self::$sequence;
		$record    = array_merge(
			array(
				'schema'            => self::RECORD_SCHEMA,
				'sequence'          => self::$sequence,
				'kind'              => $kind,
				'run_stamp'         => self::$run_stamp,
				'store'             => self::$store,
				'flow_id'           => self::$flow_id,
				'purpose'           => self::$purpose,
				'marker_created_at' => self::$marker_created_at,
				'observer_id'       => self::$observer_id,
				'paths'             => self::$path_contexts,
				'previous_hmac'     => 'hmac-sha256:' . self::$previous_hmac,
			),
			$fields
		);
		$canonical = $record;
		ksort( $canonical, SORT_STRING );
		$encoded = wp_json_encode( $canonical );
		if ( ! is_string( $encoded ) ) {
			exit( 3 );
		}
		$signature           = hash_hmac( 'sha256', self::$previous_hmac . "\0" . $encoded, self::$context_key );
		$record['hmac']      = 'hmac-sha256:' . $signature;
		self::$previous_hmac = $signature;
		$encoded             = wp_json_encode( $record );
		if ( ! is_string( $encoded ) ) {
			exit( 3 );
		}
		if ( is_resource( self::$journal_stream ) ) {
			$encoded .= "\n";
			if (
				self::$journal_record_count >= self::MAX_RECORDS
				|| self::$journal_byte_count + strlen( $encoded ) > self::MAX_JOURNAL
				|| strlen( $encoded ) !== @fwrite( self::$journal_stream, $encoded )
				|| ! @fflush( self::$journal_stream )
				|| ! self::sync_stream( self::$journal_stream )
			) {
				exit( 3 );
			}
			++self::$journal_record_count;
			self::$journal_byte_count += strlen( $encoded );
			return;
		}
		WP_CLI::line( $encoded );
	}

	/**
	 * Emit one record and terminate with a fixed status.
	 *
	 * @param string              $kind      Record kind.
	 * @param array<string,mixed> $fields    Safe fields.
	 * @param int                 $exit_code Process exit code.
	 */
	private static function emit_and_exit( string $kind, array $fields, int $exit_code ): void {
		self::emit( $kind, $fields );
		exit( $exit_code ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- An integer process status is not response output.
	}

	/**
	 * Emit a safe blocker before a marker is available.
	 *
	 * @param string $blocker_code Fixed blocker code.
	 */
	private static function exit_without_marker( string $blocker_code ): void {
		self::$previous_hmac = str_repeat( '0', 64 );
		self::emit_and_exit( 'blocked', array( 'blocker_code' => $blocker_code ), 3 );
	}

	/**
	 * Restore active paths during ordinary PHP shutdown.
	 */
	public static function shutdown_restore(): void {
		if ( ! self::$durable_forwarder_active && ! self::$restoration_attempted && ! empty( self::$paths ) ) {
			self::restore_all();
		}
	}

	/**
	 * Install bounded signal handlers when pcntl is available.
	 */
	private static function install_signal_handlers(): void {
		if ( ! function_exists( 'pcntl_signal' ) ) {
			return;
		}
		if ( function_exists( 'pcntl_async_signals' ) ) {
			pcntl_async_signals( true );
		}
		$handler = static function (): void {
			self::$interrupted = true;
		};
		pcntl_signal( SIGINT, $handler );
		pcntl_signal( SIGTERM, $handler );
	}

	/**
	 * Return the bounded observer lifetime.
	 */
	private static function maximum_seconds(): int {
		$value = getenv( 'CRITICAL_FLOWS_LOG_OBSERVER_MAX_SECONDS' );
		if ( ! is_string( $value ) || ! preg_match( '/^[0-9]+$/', $value ) ) {
			return 900;
		}
		$seconds = (int) $value;
		return max( 1, min( 3600, $seconds ) );
	}

	/**
	 * Opportunistically synchronize after the required PHP flush.
	 *
	 * PHP 7.4 has no standard fsync function. Absence therefore keeps the
	 * append-and-flush result but does not establish stable-media durability.
	 *
	 * @param resource $stream Open stream.
	 */
	private static function sync_stream( $stream ): bool {
		if ( ! function_exists( 'fsync' ) ) {
			return true;
		}

		return true === @call_user_func( 'fsync', $stream );
	}

	/**
	 * Find the path associated with one FIFO resource.
	 *
	 * @param resource $stream FIFO stream.
	 */
	private static function path_for_fifo_stream( $stream ): string {
		foreach ( self::$paths as $path => $state ) {
			if ( $state['fifo_stream'] === $stream ) {
				return $path;
			}
		}
		return '';
	}

	/**
	 * Return the exclusive same-directory backing path.
	 *
	 * @param string $path        Original log path.
	 * @param string $observer_id Authenticated observer UUID.
	 */
	private static function backing_path( string $path, string $observer_id ): string {
		return $path . sprintf( self::BACKING_SUFFIX, $observer_id );
	}

	/**
	 * Return the same-directory durable forwarder artifact paths.
	 *
	 * @param string $path        Primary original log path.
	 * @param string $observer_id Authenticated observer UUID.
	 * @return array{lease:string,journal:string,control:string}
	 */
	private static function forwarder_artifact_paths( string $path, string $observer_id ): array {
		return array(
			'lease'   => $path . sprintf( self::LEASE_SUFFIX, $observer_id ),
			'journal' => $path . sprintf( self::JOURNAL_SUFFIX, $observer_id ),
			'control' => $path . sprintf( self::CONTROL_SUFFIX, $observer_id ),
		);
	}

	/**
	 * Generate one UUID-shaped cryptographic nonce.
	 */
	private static function new_uuid(): string {
		$bytes    = random_bytes( 16 );
		$bytes[6] = chr( ( ord( $bytes[6] ) & 0x0f ) | 0x40 );
		$bytes[8] = chr( ( ord( $bytes[8] ) & 0x3f ) | 0x80 );
		$hex      = bin2hex( $bytes );
		$segments = array(
			substr( $hex, 0, 8 ),
			substr( $hex, 8, 4 ),
			substr( $hex, 12, 4 ),
			substr( $hex, 16, 4 ),
			substr( $hex, 20, 12 ),
		);
		return implode( '-', $segments );
	}

	/**
	 * Determine whether a stat result describes a regular file.
	 *
	 * @param array<string,mixed> $stat Stat result.
	 */
	private static function is_regular_stat( array $stat ): bool {
		return ( (int) $stat['mode'] & 0170000 ) === 0100000;
	}

	/**
	 * Determine whether a stat result describes a FIFO.
	 *
	 * @param array<string,mixed> $stat Stat result.
	 */
	private static function is_fifo_stat( array $stat ): bool {
		return ( (int) $stat['mode'] & 0170000 ) === 0010000;
	}

	/**
	 * Validate one SHA-256 fingerprint.
	 *
	 * @param mixed $value Candidate fingerprint.
	 */
	private static function valid_fingerprint( $value ): bool {
		return is_string( $value ) && 1 === preg_match( '/^sha256:[0-9a-f]{64}$/', $value );
	}

	/**
	 * Validate one HMAC-SHA-256 identifier.
	 *
	 * @param mixed $value Candidate HMAC.
	 */
	private static function valid_hmac( $value ): bool {
		return is_string( $value ) && 1 === preg_match( '/^hmac-sha256:[0-9a-f]{64}$/', $value );
	}

	/**
	 * Validate one UUID-shaped nonce.
	 *
	 * @param mixed $value Candidate UUID.
	 */
	private static function valid_uuid( $value ): bool {
		return is_string( $value ) && 1 === preg_match( '/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $value );
	}
}

// phpcs:enable WordPress.WP.AlternativeFunctions, WordPress.PHP.NoSilencedErrors.Discouraged

WooPaymentsCriticalFlowsLogObserver::run( $args );
