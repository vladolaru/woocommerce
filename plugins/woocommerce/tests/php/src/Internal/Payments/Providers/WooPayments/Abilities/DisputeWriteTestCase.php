<?php
/**
 * Test fixtures for registered WooPayments dispute writes.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments\Abilities;

use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api\HttpClient;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsDatabaseCache;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsDisputesRestController;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments\Api\FakeHttpClient;
use Automattic\WooCommerce\Testing\Tools\TestingContainer;

/** Discover the dispute ability with a real merchant route and a recording transport. */
abstract class DisputeWriteTestCase extends AbilityTestCase {
	protected const NAME       = '';
	protected const CACHE_KEYS = array(
		WooPaymentsDatabaseCache::DISPUTE_STATUS_COUNTS_KEY,
		WooPaymentsDatabaseCache::DISPUTE_STATUS_COUNTS_KEY_TEST_MODE,
		WooPaymentsDatabaseCache::ACTIVE_DISPUTES_KEY,
	);

	/** @var array Merchant route dispatches before WordPress adds matched URL parameters. */
	protected $dispatches = array();

	/** @var FakeHttpClient Platform transport. */
	protected $http;

	/** @var \WP_Ability Registered dispute ability. */
	protected $sut;

	/** @var \WP_REST_Server|null Server in place before this test. */
	private $original_server;

	/** @var WooPaymentsDisputesRestController Dispute routes served during this test. */
	private $controller;

	/** Discover the ability on a connected store whose dispute routes use a fake platform transport. */
	public function setUp(): void {
		parent::setUp();
		$this->http = new FakeHttpClient();
		$container  = wc_get_container();
		$this->assertInstanceOf( TestingContainer::class, $container );
		$container->replace( HttpClient::class, $this->http );
		$this->reset_container_resolutions();
		$this->install_root();
		add_filter( self::GATE, '__return_true' );

		$this->original_server     = $GLOBALS['wp_rest_server'] ?? null;
		$GLOBALS['wp_rest_server'] = null;
		$this->controller          = $container->get( WooPaymentsDisputesRestController::class );
		$this->assertInstanceOf( WooPaymentsDisputesRestController::class, $this->controller );
		add_action( 'rest_api_init', array( $this->controller, 'register_routes' ) );
		rest_get_server();

		$this->assertTrue( wp_has_ability( static::NAME ), static::NAME );
		$ability = wp_get_ability( static::NAME );
		$this->assertInstanceOf( \WP_Ability::class, $ability );
		$this->sut = $ability;

		add_filter(
			'rest_pre_dispatch',
			function ( $result, $server, $request ) {
				if ( 0 === strpos( $request->get_route(), '/wc/v3/' ) ) {
					$this->dispatches[] = array( $request->get_method(), $request->get_route(), $request->get_params() );
				}
				return $result;
			},
			1,
			3
		);
	}

	/** Restore the REST server and the original transport. */
	public function tearDown(): void {
		try {
			remove_action( 'rest_api_init', array( $this->controller, 'register_routes' ) );
			$GLOBALS['wp_rest_server'] = $this->original_server;
			$this->reset_container_replacements();
			$this->reset_container_resolutions();
		} finally {
			parent::tearDown();
		}
	}

	/** Check both the internal dispatch and transport boundary for local refusals. */
	protected function assert_nothing_sent(): void {
		$this->assertSame( array(), $this->dispatches );
		$this->assertSame( 0, $this->http->request_count );
	}

	/**
	 * Build a platform dispute.
	 *
	 * @param array $fields Fields to override.
	 */
	protected function dispute( array $fields = array() ): array {
		return array_merge(
			array(
				'id'     => 'du_test',
				'status' => 'needs_response',
				'reason' => 'fraudulent',
				'charge' => 'ch_disputed',
			),
			$fields
		);
	}

	/**
	 * Build a platform error response.
	 *
	 * @param string $code    Platform error code.
	 * @param string $message Platform error message.
	 * @param int    $status  HTTP status.
	 */
	protected function error_response( string $code, string $message, int $status ): array {
		return array(
			'status' => $status,
			'body'   => array(
				'error' => array(
					'code'    => $code,
					'message' => $message,
				),
			),
		);
	}

	/**
	 * Queue the transport's JSON responses, one per request.
	 *
	 * @param array ...$bodies Platform bodies, or a status and body from error_response().
	 */
	protected function respond_in_turn( array ...$bodies ): void {
		foreach ( $bodies as $body ) {
			$status = 200;
			if ( isset( $body['status'], $body['body'] ) && is_int( $body['status'] ) ) {
				$status = $body['status'];
				$body   = $body['body'];
			}
			$this->http->responses[] = array(
				'response' => array( 'code' => $status ),
				'headers'  => array( 'content-type' => 'application/json' ),
				'body'     => wp_json_encode( $body ),
			);
		}
	}
}
