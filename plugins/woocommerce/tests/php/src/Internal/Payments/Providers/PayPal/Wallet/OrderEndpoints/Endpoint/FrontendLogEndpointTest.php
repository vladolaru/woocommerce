<?php
/**
 * Tests for the frontend log endpoint.
 *
 * @package Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\OrderEndpoints\Endpoint
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\OrderEndpoints\Endpoint;

use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Button\Exception\NonceValidationException;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\OrderEndpoints\Endpoint\FrontendLogEndpoint;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\OrderEndpoints\Endpoint\RequestData;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\WalletTestCase;
use Automattic\WooCommerce\Vendor\Psr\Log\LoggerInterface;
use Mockery;
use Mockery\MockInterface;

/**
 * The log line the endpoint writes from what the browser reports, and the empty success it answers with whether or not
 * the report was written. The endpoint ends the request with a real wp_send_json_success().
 *
 * @group paypal-wallet
 */
class FrontendLogEndpointTest extends WalletTestCase {

	/**
	 * The request reader mock.
	 *
	 * @var RequestData&MockInterface
	 */
	private $request_data;

	/**
	 * The logger mock.
	 *
	 * @var LoggerInterface&MockInterface
	 */
	private $logger;

	/**
	 * The System Under Test.
	 *
	 * @var FrontendLogEndpoint
	 */
	private $sut;

	/**
	 * Build the endpoint over mocked collaborators.
	 */
	public function setUp(): void {
		parent::setUp();

		$this->request_data = $this->mock( RequestData::class );
		$this->logger       = $this->mock( LoggerInterface::class );

		$this->sut = new FrontendLogEndpoint( $this->request_data, $this->logger );
	}

	/**
	 * Make the request carry the given report.
	 *
	 * @param array $data The report.
	 */
	private function stub_posted_data( array $data ): void {
		$this->request_data->shouldReceive( 'read_request' )->once()->with( FrontendLogEndpoint::nonce() )->andReturn( $data );
	}

	/**
	 * Run the handler and assert it answered with the empty success.
	 */
	private function run_handler_expecting_empty_success(): void {
		$response = $this->run_ajax_handler( array( $this->sut, 'handle_request' ) );

		$this->assertSame( array( 'success' => true ), $response, 'The answer is an empty success' );
	}

	/**
	 * @testdox Should log one error line built from the tag, the event and the message, and tell the caller the report was logged.
	 */
	public function test_logs_a_line_built_from_tag_event_and_message(): void {
		$this->stub_posted_data(
			array(
				'tag'     => 'paypal-button',
				'event'   => 'sheet_failed',
				'message' => 'declined 5000',
			)
		);
		$this->logger->shouldReceive( 'log' )->once()->with( 'error', '[paypal-button] sheet_failed: declined 5000' );

		$this->run_handler_expecting_empty_success();
	}

	/**
	 * @testdox Should log nothing and send the same empty success when the nonce validation fails, since the response never reveals that the nonce was rejected.
	 */
	public function test_sends_same_empty_success_when_nonce_validation_fails(): void {
		$this->request_data->shouldReceive( 'read_request' )->once()->with( FrontendLogEndpoint::nonce() )->andThrow( new NonceValidationException( 'Could not validate nonce.' ) );
		$this->logger->shouldNotReceive( 'log' );

		$this->run_handler_expecting_empty_success();
	}

	/**
	 * @testdox Should fall back to the literal "frontend" tag and "unknown" event when the report has neither.
	 */
	public function test_line_falls_back_when_tag_and_event_are_absent(): void {
		$this->stub_posted_data( array() );
		$this->logger->shouldReceive( 'log' )->once()->with( 'error', '[frontend] unknown' );

		$this->run_handler_expecting_empty_success();
	}

	/**
	 * @testdox Should leave the trailing message out of the line when the report has none.
	 */
	public function test_line_omits_message_when_none_is_posted(): void {
		$this->stub_posted_data(
			array(
				'tag'   => 'sdk',
				'event' => 'call_failed',
			)
		);
		$this->logger->shouldReceive( 'log' )->once()->with( 'error', '[sdk] call_failed' );

		$this->run_handler_expecting_empty_success();
	}

	/**
	 * @testdox Should truncate the logged line to 1024 characters, since anyone who can load a storefront page holds a usable nonce.
	 */
	public function test_line_is_truncated_at_max_length(): void {
		$this->stub_posted_data(
			array(
				'tag'     => 'sdk',
				'event'   => 'call_failed',
				'message' => str_repeat( 'a', 2000 ),
			)
		);
		$this->logger->shouldReceive( 'log' )->once()->with(
			'error',
			Mockery::on(
				static function ( string $line ): bool {
					return 1024 === strlen( $line ) && 0 === strpos( $line, '[sdk] call_failed: aaa' );
				}
			)
		);

		$this->run_handler_expecting_empty_success();
	}

	/**
	 * @testdox Should log nothing and still tell the caller the report was logged when the frontend log filter returns false, so the response never reveals whether reporting is on.
	 */
	public function test_skips_logging_when_disabled_by_filter(): void {
		$calls = $this->spy_filter( 'woocommerce_paypal_payments_frontend_log_enabled', false );
		$this->stub_posted_data(
			array(
				'tag'   => 'sdk',
				'event' => 'call_failed',
			)
		);
		$this->logger->shouldNotReceive( 'log' );

		$this->run_handler_expecting_empty_success();

		$this->assertCount( 1, $calls );
		$this->assertTrue( $calls[0][0], 'The filter receives true as its default' );
	}

	/**
	 * @testdox Should log at the level "$expected_level" when the report names $label.
	 * @dataProvider level_data
	 *
	 * @param string $label          What the report names as its level.
	 * @param mixed  $level          The level the report holds.
	 * @param string $expected_level The level the line is logged at.
	 */
	public function test_logs_at_a_known_level_and_falls_back_to_error( string $label, $level, string $expected_level ): void {
		unset( $label );
		$this->stub_posted_data(
			array(
				'tag'   => 'sdk',
				'event' => 'call_failed',
				'level' => $level,
			)
		);
		$this->logger->shouldReceive( 'log' )->once()->with( $expected_level, '[sdk] call_failed' );

		$this->run_handler_expecting_empty_success();
	}

	/**
	 * Levels a report can name, with the level the line is logged at.
	 *
	 * @return array
	 */
	public function level_data(): array {
		return array(
			'debug'                    => array( 'debug', 'debug', 'debug' ),
			'info'                     => array( 'info', 'info', 'info' ),
			'warning'                  => array( 'warning', 'warning', 'warning' ),
			'error'                    => array( 'error', 'error', 'error' ),
			'an unknown level'         => array( 'an unknown level', 'emergency', 'error' ),
			'a level that is an array' => array( 'a level that is an array', array( 'warning' ), 'error' ),
		);
	}

	/**
	 * @testdox Should mark the line as a frontend request and skip the new-request entry while the line is logged.
	 */
	public function test_marks_the_line_as_a_frontend_request(): void {
		$this->stub_posted_data(
			array(
				'tag'   => 'sdk',
				'event' => 'call_failed',
			)
		);
		$request_kind = null;
		$skip_entry   = null;
		$this->logger->shouldReceive( 'log' )->once()->andReturnUsing(
			static function () use ( &$request_kind, &$skip_entry ): void {
				$request_kind = apply_filters( 'woocommerce_paypal_payments_log_request_kind', 'AJAX' );
				$skip_entry   = apply_filters( 'woocommerce_paypal_payments_skip_new_request_log', false );
			}
		);

		$this->run_handler_expecting_empty_success();

		$this->assertSame( 'FRONT', $request_kind );
		$this->assertTrue( $skip_entry );
	}
}
