<?php
/**
 * Read-only projector for exact local WPCOM merchant-forwarding jobs.
 *
 * @package WooCommerce\Tools\WooPaymentsCriticalFlows
 */

declare( strict_types = 1 );

const WOOPAYMENTS_MD01_RAW_JOBS_SCHEMA = 'woopayments_md01_wpcom_jobs_raw.v1';

/**
 * Emit a bounded raw job projection.
 *
 * @param array<string,mixed> $payload Projection payload.
 */
function woopayments_md01_emit_jobs( array $payload ): void {
	echo wp_json_encode( $payload, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE );
}

/**
 * Read one nested array value.
 *
 * @param array<string,mixed> $value Source array.
 * @param array<int,string>   $path  Nested keys.
 * @return mixed
 */
function woopayments_md01_nested_value( array $value, array $path ) {
	$current = $value;
	foreach ( $path as $key ) {
		if ( ! is_array( $current ) || ! array_key_exists( $key, $current ) ) {
			return null;
		}
		$current = $current[ $key ];
	}
	return $current;
}

/**
 * Project a matching forwarding job without retaining its raw body.
 *
 * @param object $row        WPCOM async job row.
 * @param string $intent_id  Exact driven PaymentIntent ID.
 * @param string $charge_id  Exact driven charge ID.
 * @return array<string,mixed>|null
 */
function woopayments_md01_project_job( object $row, string $intent_id, string $charge_id ): ?array {
	if ( 'wcpay_process_job' !== (string) ( $row->type ?? '' ) ) {
		return null;
	}

	$data = maybe_unserialize( $row->data ?? null );
	if ( ! is_array( $data ) ) {
		return null;
	}
	$job_data = $data['wcpay_job_data'] ?? null;
	$body     = $data['body'] ?? null;
	$blog_id  = absint( $data['blog_id'] ?? 0 );
	if (
		! is_array( $job_data )
		|| ! is_array( $body )
		|| 'WCPay\Remote_Site\Remote_Site_Client' !== (string) ( $job_data['class_name'] ?? '' )
		|| 'handle_async_webhook_send' !== (string) ( $job_data['method'] ?? '' )
		|| $blog_id <= 0
		|| absint( $job_data['blog_id'] ?? 0 ) !== $blog_id
	) {
		return null;
	}

	$event_type      = (string) ( $body['type'] ?? '' );
	$event_id        = (string) ( $body['id'] ?? '' );
	$object_id       = (string) woopayments_md01_nested_value( $body, array( 'data', 'object', 'id' ) );
	$event_charge_id = '';
	if ( 'payment_intent.succeeded' === $event_type && $object_id === $intent_id ) {
		$event_charge_id = (string) woopayments_md01_nested_value( $body, array( 'data', 'object', 'latest_charge' ) );
		if ( '' === $event_charge_id ) {
			$event_charge_id = (string) woopayments_md01_nested_value( $body, array( 'data', 'object', 'charges', 'data', '0', 'id' ) );
		}
	} elseif ( 'charge.dispute.created' === $event_type ) {
		$event_charge_id = (string) woopayments_md01_nested_value( $body, array( 'data', 'object', 'charge' ) );
	} else {
		return null;
	}

	if (
		$event_charge_id !== $charge_id
		|| 1 !== preg_match( '/^evt_[A-Za-z0-9_]+$/', $event_id )
		|| 1 !== preg_match( '/^(?:pi|du|dp)_[A-Za-z0-9_]+$/', $object_id )
	) {
		return null;
	}

	return array(
		'job_id'     => absint( $row->id ?? 0 ),
		'event_id'   => $event_id,
		'event_type' => $event_type,
		'object_id'  => $object_id,
		'charge_id'  => $event_charge_id,
		'blog_id'    => $blog_id,
	);
}

$intent_id = (string) ( $argv[1] ?? '' );
$charge_id = (string) ( $argv[2] ?? '' );
$result    = array(
	'schema'          => WOOPAYMENTS_MD01_RAW_JOBS_SCHEMA,
	'payment_intent'  => array(),
	'dispute_created' => array(),
);

try {
	if (
		'1' !== getenv( 'WPCOM_LOCAL_RUNTIME' )
		|| 1 !== preg_match( '/^pi_[A-Za-z0-9_]+$/', $intent_id )
		|| 1 !== preg_match( '/^(?:ch|py)_[A-Za-z0-9_]+$/', $charge_id )
		|| ! is_readable( '/usr/local/share/wpcom-local/jobs-runner.php' )
	) {
		throw new RuntimeException( 'The local WPCOM job projection boundary is unavailable.' );
	}

	$jobs_runner = '/usr/local/share/wpcom-local/jobs-runner.php';
	// This generated runtime file is intentionally unavailable in the source checkout PHPStan analyzes.
	// phpcs:ignore Squiz.Commenting.InlineComment.InvalidEndChar
	// @phpstan-ignore-next-line
	require_once $jobs_runner;
	$prepare_callback = getenv( 'WPCOM_LOCAL_JOBS_PREPARE_CALLBACK' );
	if ( ! is_string( $prepare_callback ) || '' === $prepare_callback ) {
		$prepare_callback = 'wpcom_local_jobs_prepare_wordpress_bootstrap';
	}
	$docroot_callback = getenv( 'WPCOM_LOCAL_JOBS_DOCROOT_CALLBACK' );
	if ( ! is_string( $docroot_callback ) || '' === $docroot_callback ) {
		$docroot_callback = 'wpcom_local_jobs_docroot';
	}
	if ( ! is_callable( $prepare_callback ) || ! is_callable( $docroot_callback ) ) {
		throw new RuntimeException( 'The local WPCOM jobs bootstrap is unavailable.' );
	}
	call_user_func( $prepare_callback );
	$docroot = call_user_func( $docroot_callback );
	if ( ! is_string( $docroot ) || '' === $docroot ) {
		throw new RuntimeException( 'The local WPCOM document root is unavailable.' );
	}
	require_once $docroot . '/wp-load.php';

	global $wpdb;
	$rows = $wpdb->get_results(
		"SELECT `id`, `type`, `data` FROM `wpj_jobs` WHERE `workerpid` IS NULL AND `type` = 'wcpay_process_job' ORDER BY `id` ASC LIMIT 1000"
	);
	if ( ! is_array( $rows ) || '' !== (string) $wpdb->last_error ) {
		throw new RuntimeException( 'The local WPCOM job source is unavailable.' );
	}

	foreach ( $rows as $row ) {
		if ( ! is_object( $row ) ) {
			continue;
		}
		$projection = woopayments_md01_project_job( $row, $intent_id, $charge_id );
		if ( null === $projection || $projection['job_id'] <= 0 ) {
			continue;
		}
		$key              = 'payment_intent.succeeded' === $projection['event_type'] ? 'payment_intent' : 'dispute_created';
		$result[ $key ][] = $projection;
	}

	woopayments_md01_emit_jobs( $result );
} catch ( Throwable $throwable ) {
	unset( $throwable );
	woopayments_md01_emit_jobs( $result );
	exit( 3 );
}
