<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\MultiCurrency\Services;

use Automattic\WooCommerce\Internal\MultiCurrency\Services\RequestContext;
use WC_Unit_Test_Case;

/**
 * Tests for the RequestContext class.
 */
class RequestContextTest extends WC_Unit_Test_Case {

	/**
	 * Original request data.
	 *
	 * @var array<string,mixed>
	 */
	private array $original_request;

	/**
	 * Original server data.
	 *
	 * @var array<string,mixed>
	 */
	private array $original_server;

	/**
	 * Set up test fixtures.
	 */
	public function set_up(): void {
		parent::set_up();

		$this->original_request = $_REQUEST; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$this->original_server  = $_SERVER;
	}

	/**
	 * Tear down test fixtures.
	 */
	public function tear_down(): void {
		$_REQUEST = $this->original_request;
		$_SERVER  = $this->original_server;

		parent::tear_down();
	}

	/**
	 * @testdox Should find an instance or static method call on the real call stack, and nothing that is not on it.
	 */
	public function test_is_call_in_backtrace_matches_the_real_stack(): void {
		$fixture = new class() {
			/**
			 * Ask from inside an instance method.
			 *
			 * @return bool
			 */
			public function matches_instance_method_call(): bool {
				return ( new RequestContext() )->is_call_in_backtrace( array( self::class . '->matches_instance_method_call' ) );
			}

			/**
			 * Ask from inside a static method.
			 *
			 * @return bool
			 */
			public static function matches_static_method_call(): bool {
				return ( new RequestContext() )->is_call_in_backtrace( array( self::class . '::matches_static_method_call' ) );
			}
		};

		$this->assertTrue( $fixture->matches_instance_method_call() );
		$this->assertTrue( $fixture::matches_static_method_call() );
		$this->assertFalse( ( new RequestContext() )->is_call_in_backtrace( array( 'Nonexistent_Class->nonexistent_method' ) ) );
	}

	/**
	 * @testdox Should register frontend hooks and selected-currency writers only on a frontend request: $_dataName.
	 * @dataProvider provide_frontend_request_shapes
	 *
	 * @param bool                $is_admin   Whether the request is admin.
	 * @param bool                $doing_cron Whether cron is running.
	 * @param bool                $is_rest    Whether this is a WooCommerce REST request.
	 * @param array<string,mixed> $server     Server keys the request sets.
	 * @param bool[]              $expected   Admin REST, frontend request, frontend hooks, selected-currency writers.
	 */
	public function test_registers_frontend_hooks_only_on_a_frontend_request( bool $is_admin, bool $doing_cron, bool $is_rest, array $server, array $expected ): void {
		foreach ( $server as $key => $value ) {
			$_SERVER[ $key ] = $value;
		}
		$sut = $this->create_context( $is_admin, $doing_cron, $is_rest, false );

		$this->assertSame(
			$expected,
			array(
				$sut->is_admin_api_request(),
				$sut->is_frontend_request(),
				$sut->should_register_frontend_hooks(),
				$sut->should_register_selected_currency_entry_hooks(),
			)
		);
	}

	/**
	 * Request shapes: a normal request, admin, cron, and an admin-originated WooCommerce REST request.
	 *
	 * @return array<string,array{0:bool,1:bool,2:bool,3:array<string,mixed>,4:bool[]}>
	 */
	public function provide_frontend_request_shapes(): array {
		return array(
			'normal request' => array( false, false, false, array(), array( false, true, true, true ) ),
			'admin'          => array( true, false, false, array(), array( false, false, false, false ) ),
			'cron'           => array( false, true, false, array(), array( false, false, false, false ) ),
			'admin REST'     => array( false, false, true, array( 'HTTP_REFERER' => admin_url( 'admin.php?page=wc-admin' ) ), array( true, false, false, false ) ),
		);
	}

	/**
	 * @testdox Should allow REST request overrides only on a non-Store REST request: $_dataName.
	 * @dataProvider provide_rest_override_request_shapes
	 *
	 * @param bool                $is_rest      Whether this is a WooCommerce REST request.
	 * @param bool                $is_store_api Whether this is a Store API request.
	 * @param array<string,mixed> $request      Request keys the request sets.
	 * @param bool                $expected     Whether REST request overrides register.
	 */
	public function test_allows_rest_request_overrides_only_in_non_store_rest_context( bool $is_rest, bool $is_store_api, array $request, bool $expected ): void {
		foreach ( $request as $key => $value ) {
			$_REQUEST[ $key ] = $value;
		}
		$sut = $this->create_context( false, false, $is_rest, $is_store_api );

		$this->assertSame( $expected, $sut->should_register_rest_request_overrides() );
	}

	/**
	 * Request shapes: a non-Store REST request, the Store API, a Store API batch and a non-REST request.
	 *
	 * @return array<string,array{0:bool,1:bool,2:array<string,mixed>,3:bool}>
	 */
	public static function provide_rest_override_request_shapes(): array {
		return array(
			'non-Store REST'  => array( true, false, array(), true ),
			'Store API'       => array( true, true, array(), false ),
			'Store API batch' => array( true, false, array( 'rest_route' => '/wc/store/v1/batch' ), false ),
			'not REST'        => array( false, false, array(), false ),
		);
	}

	/**
	 * @testdox Should allow selected currency writers for Store API context.
	 */
	public function test_allows_selected_currency_writers_for_store_api_context(): void {
		$sut = $this->create_context( true, false, false, true );

		$this->assertFalse( $sut->should_register_frontend_hooks(), 'Frontend hooks should remain blocked in admin Store API contexts.' );
		$this->assertTrue( $sut->should_register_selected_currency_entry_hooks(), 'Selected-currency writers should register for Store API requests.' );
	}

	/**
	 * @testdox Should detect $_dataName without resolving the WooCommerce singleton.
	 * @dataProvider provide_rest_detection_requests
	 *
	 * @param string      $method      Detection method the case calls.
	 * @param string      $request_uri Request URI.
	 * @param string|null $rest_route  The rest_route query argument, or null when absent.
	 */
	public function test_detects_store_api_and_rest_requests_without_resolving_woocommerce_singleton( string $method, string $request_uri, ?string $rest_route ): void {
		$sentinel = $this->install_woocommerce_singleton_sentinel();
		$sut      = new class() extends RequestContext {
			/**
			 * Expose REST request detection for testing.
			 *
			 * @return bool
			 */
			public function is_wc_rest_api_request_for_test(): bool {
				return $this->is_wc_rest_api_request();
			}
		};

		$_SERVER['REQUEST_URI'] = $request_uri;
		if ( null !== $rest_route ) {
			$_GET['rest_route'] = $rest_route;
		}

		try {
			$this->assertTrue( $sut->$method(), 'The request should be detected from the request URI or the rest_route argument.' );
		} finally {
			unset( $_GET['rest_route'] );
			$this->restore_woocommerce_singleton_sentinel( $sentinel );
		}

		$this->assertFalse( $sentinel->is_store_api_request_called, 'Request context must not call WC() while WooCommerce is still constructing.' );
		$this->assertFalse( $sentinel->is_rest_api_request_called, 'Request context must not call WC() while WooCommerce is still constructing.' );
	}

	/**
	 * Requests each detection reads: a Store API path, a WooCommerce REST path, and a WooCommerce REST route passed as a
	 * parameter, as plain permalinks and Jetpack-signed calls pass it (core `WooCommerce::is_rest_api_request()`).
	 *
	 * @return array<string,array{0:string,1:string,2:string|null}>
	 */
	public static function provide_rest_detection_requests(): array {
		return array(
			'a Store API request'                   => array( 'is_store_api_request', '/wp-json/wc/store/v1/cart', null ),
			'a WooCommerce REST request'            => array( 'is_wc_rest_api_request_for_test', '/wp-json/wc/v3/products', null ),
			'a WooCommerce REST rest_route request' => array( 'is_wc_rest_api_request_for_test', '/index.php?rest_route=/wc/v3/products', '/wc/v3/products' ),
		);
	}

	/**
	 * @testdox Should detect Store API batch routes.
	 */
	public function test_detects_store_api_batch_routes(): void {
		$_REQUEST['rest_route'] = '/wc/store/v1/batch';
		$sut                    = $this->create_context();

		$this->assertTrue( $sut->is_store_batch_request(), 'Store API batch routes should be detected from rest_route.' );
	}

	/**
	 * Create a request context test double.
	 *
	 * @param bool      $is_admin    Whether the request is admin.
	 * @param bool      $doing_cron  Whether cron is running.
	 * @param bool      $is_rest     Whether this is a REST request.
	 * @param bool|null $is_store_api Store API result, or null to use URI fallback.
	 * @return RequestContext
	 */
	private function create_context(
		bool $is_admin = false,
		bool $doing_cron = false,
		bool $is_rest = false,
		?bool $is_store_api = false
	): RequestContext {
		return new class( $is_admin, $doing_cron, $is_rest, $is_store_api ) extends RequestContext {
			/**
			 * Whether the request is admin.
			 *
			 * @var bool
			 */
			private bool $is_admin;

			/**
			 * Whether cron is running.
			 *
			 * @var bool
			 */
			private bool $doing_cron;

			/**
			 * Whether this is a REST request.
			 *
			 * @var bool
			 */
			private bool $is_rest;

			/**
			 * Store API result.
			 *
			 * @var bool|null
			 */
			private ?bool $is_store_api;

			/**
			 * Constructor.
			 *
			 * @param bool      $is_admin    Whether the request is admin.
			 * @param bool      $doing_cron  Whether cron is running.
			 * @param bool      $is_rest     Whether this is a REST request.
			 * @param bool|null $is_store_api Store API result, or null to use URI fallback.
			 */
			public function __construct( bool $is_admin, bool $doing_cron, bool $is_rest, ?bool $is_store_api ) {
				$this->is_admin     = $is_admin;
				$this->doing_cron   = $doing_cron;
				$this->is_rest      = $is_rest;
				$this->is_store_api = $is_store_api;
			}

			/**
			 * Tell whether the request is admin.
			 *
			 * @return bool
			 */
			protected function is_wp_admin(): bool {
				return $this->is_admin;
			}

			/**
			 * Tell whether cron is running.
			 *
			 * @return bool
			 */
			protected function is_doing_cron(): bool {
				return $this->doing_cron;
			}

			/**
			 * Tell whether this is a WooCommerce REST request.
			 *
			 * @return bool
			 */
			public function is_wc_rest_api_request(): bool {
				return $this->is_rest;
			}

			/**
			 * Get the WooCommerce Store API request result.
			 *
			 * @return bool|null
			 */
			protected function get_wc_store_api_request(): ?bool {
				return $this->is_store_api;
			}
		};
	}

	/**
	 * Install a WooCommerce singleton sentinel.
	 *
	 * @return object
	 */
	private function install_woocommerce_singleton_sentinel(): object {
		$reflection = new \ReflectionClass( \WooCommerce::class );
		$instance   = $reflection->getProperty( '_instance' );
		$instance->setAccessible( true );

		$sentinel                    = new class() extends \WooCommerce {
			/**
			 * Previous WooCommerce singleton instance.
			 *
			 * @var mixed
			 */
			public $previous_instance;

			/**
			 * Whether is_rest_api_request was called.
			 *
			 * @var bool
			 */
			public bool $is_rest_api_request_called = false;

			/**
			 * Whether is_store_api_request was called.
			 *
			 * @var bool
			 */
			public bool $is_store_api_request_called = false;

			/**
			 * Constructor intentionally avoids booting WooCommerce.
			 */
			public function __construct() {}

			/**
			 * Record accidental WooCommerce singleton access.
			 *
			 * @return bool
			 */
			public function is_rest_api_request() {
				$this->is_rest_api_request_called = true;

				return true;
			}

			/**
			 * Record accidental WooCommerce singleton access.
			 *
			 * @return bool
			 */
			public function is_store_api_request() {
				$this->is_store_api_request_called = true;

				return true;
			}
		};
		$sentinel->previous_instance = $instance->getValue();

		$instance->setValue( $sentinel );

		return $sentinel;
	}

	/**
	 * Restore the WooCommerce singleton after a sentinel test.
	 *
	 * @param object $sentinel WooCommerce singleton sentinel.
	 */
	private function restore_woocommerce_singleton_sentinel( object $sentinel ): void {
		$reflection = new \ReflectionClass( \WooCommerce::class );
		$instance   = $reflection->getProperty( '_instance' );
		$instance->setAccessible( true );
		$instance->setValue( $sentinel->previous_instance );
	}
}
