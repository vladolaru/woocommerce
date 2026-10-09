<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Collecting\Logging;

use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\Logging\RedactingLogger;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Collecting\Doubles\RecordingLogger;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\WalletTestCase;
use stdClass;
use WP_Error;

/**
 * Tests for the RedactingLogger class.
 *
 * @group paypal-wallet
 */
class RedactingLoggerTest extends WalletTestCase {

	/**
	 * A bearer token shaped like PayPal's; public so the anonymous class below can read it.
	 */
	public const TOKEN = 'A21AAJ-fake_token.value-123';

	/**
	 * The logger the SUT delegates to.
	 *
	 * @var RecordingLogger
	 */
	private RecordingLogger $inner;

	/**
	 * The System Under Test.
	 *
	 * @var RedactingLogger
	 */
	private RedactingLogger $sut;

	/**
	 * Build the SUT over a recording logger.
	 */
	public function setUp(): void {
		parent::setUp();
		$this->inner = new RecordingLogger();
		$this->sut   = new RedactingLogger( $this->inner );
	}

	/**
	 * @testdox Should redact the Authorization and PayPal-Auth-Assertion header values in a request's args, whatever their case.
	 */
	public function test_redacts_credential_headers(): void {
		$this->sut->warning(
			'Failed.',
			array(
				'args'  => array(
					'method'  => 'GET',
					'headers' => array(
						'Authorization'         => 'Bearer ' . self::TOKEN,
						'paypal-auth-assertion' => 'eyJhbGciOiJub25lIn0.eyJwYXllciI6IngifQ.',
						'Content-Type'          => 'application/json',
					),
				),
				'other' => array( 'authorization' => 'Basic cGxhdGZvcm0taWQ6cGxhdGZvcm0tc2VjcmV0' ),
			)
		);

		$record = $this->inner->records[0];
		$this->assertSame( 'warning', $record['level'] );
		$this->assertSame( '[redacted]', $record['context']['args']['headers']['Authorization'] );
		$this->assertSame( '[redacted]', $record['context']['args']['headers']['paypal-auth-assertion'] );
		$this->assertSame( 'application/json', $record['context']['args']['headers']['Content-Type'], 'Other headers are kept' );
		$this->assertSame( 'GET', $record['context']['args']['method'] );
		$this->assertSame( '[redacted]', $record['context']['other']['authorization'] );
	}

	/**
	 * @testdox Should redact bearer, basic and assertion credentials inside any string of the message or the context.
	 */
	public function test_redacts_credentials_inside_strings(): void {
		$this->sut->error(
			'Request with Bearer ' . self::TOKEN . ' failed',
			array(
				'note'  => 'sent "Bearer ' . self::TOKEN . '" and Basic cGxhdGZvcm0taWQ6cGxhdGZvcm0tc2VjcmV0',
				'json'  => '{"headers":{"Authorization":"Bearer ' . self::TOKEN . '","x":"y"}}',
				'lower' => 'authorization: bearer ' . self::TOKEN . ', basic cGxhdGZvcm0taWQ6cGxhdGZvcm0tc2VjcmV0',
				'raw'   => 'PayPal-Auth-Assertion: eyJhbGciOiJub25lIn0.eyJwYXllciI6IngifQ.',
				'plain' => 'Basic plan, Bearer of good news',
			)
		);

		$record = $this->inner->records[0];
		$this->assertSame( 'Request with Bearer [redacted] failed', $record['message'] );
		$this->assertSame( 'sent "Bearer [redacted]" and Basic [redacted]', $record['context']['note'], 'The quote after the token survives' );
		$this->assertSame( '{"headers":{"Authorization":"Bearer [redacted]","x":"y"}}', $record['context']['json'], 'The rest of a JSON string survives' );
		$this->assertSame( 'authorization: bearer [redacted], basic [redacted]', $record['context']['lower'], 'Lower-case schemes are credentials too' );
		$this->assertSame( 'PayPal-Auth-Assertion: [redacted]', $record['context']['raw'] );
		$this->assertStringNotContainsString( self::TOKEN, $this->inner->as_text() );
		$this->assertStringContainsString( 'Basic plan', $record['context']['plain'], 'A short word after Basic is not a credential' );
	}

	/**
	 * @testdox Should redact OAuth credential keys at any depth, whatever their case, and keep a generic code key.
	 */
	public function test_redacts_oauth_credential_keys(): void {
		$this->sut->warning(
			'Token.',
			array(
				'response' => array(
					'body'     => array(
						'access_token'  => self::TOKEN,
						'Refresh_Token' => 'refresh-value',
						'id_token'      => 'id-value',
						'nested'        => array(
							'client_secret' => 'secret-value',
							'code_verifier' => 'verifier-value',
						),
					),
					'code'     => 401,
					'response' => array( 'code' => 401 ),
				),
			)
		);

		$response = $this->inner->records[0]['context']['response'];
		$this->assertSame( '[redacted]', $response['body']['access_token'] );
		$this->assertSame( '[redacted]', $response['body']['Refresh_Token'] );
		$this->assertSame( '[redacted]', $response['body']['id_token'] );
		$this->assertSame( '[redacted]', $response['body']['nested']['client_secret'] );
		$this->assertSame( '[redacted]', $response['body']['nested']['code_verifier'] );
		$this->assertSame( 401, $response['code'], 'A generic code key is not a credential' );
		$this->assertSame( 401, $response['response']['code'] );
	}

	/**
	 * @testdox Should redact OAuth credential values inside a JSON, escaped JSON or form-encoded string, keeping the keys.
	 */
	public function test_redacts_oauth_credentials_inside_bodies(): void {
		$this->sut->debug(
			'Bodies.',
			array(
				'json'    => '{"scope":"x","access_token":"' . self::TOKEN . '","token_type":"Bearer","refresh_token" : "refresh-value","expires_in":32400}',
				'escaped' => '{\\"client_secret\\":\\"secret-value\\",\\"id_token\\":\\"id-value\\"}',
				'form'    => 'grant_type=refresh_token&refresh_token=refresh-value&code_verifier=verifier-value',
			)
		);

		$context = $this->inner->records[0]['context'];
		$this->assertSame( '{"scope":"x","access_token":"[redacted]","token_type":"Bearer","refresh_token" : "[redacted]","expires_in":32400}', $context['json'] );
		$this->assertSame( '{\\"client_secret\\":\\"[redacted]\\",\\"id_token\\":\\"[redacted]\\"}', $context['escaped'] );
		$this->assertSame( 'grant_type=refresh_token&refresh_token=[redacted]&code_verifier=[redacted]', $context['form'] );
		foreach ( array( self::TOKEN, 'refresh-value', 'secret-value', 'id-value', 'verifier-value' ) as $secret ) {
			$this->assertStringNotContainsString( $secret, $this->inner->as_text() );
		}
	}

	/**
	 * @testdox Should redact inside nested arrays, plain objects and WP_Error messages and data, and keep everything else.
	 */
	public function test_redacts_nested_values_and_objects(): void {
		$object          = new stdClass();
		$object->headers = array( 'Authorization' => 'Bearer ' . self::TOKEN );
		$object->count   = 2;
		$error           = new WP_Error( 'http_request_failed', 'cURL error with Bearer ' . self::TOKEN, array( 'Authorization' => 'Bearer ' . self::TOKEN ) );
		$error->add( 'second', 'Second message' );
		$untouched       = new stdClass();
		$untouched->name = 'kept';

		$this->sut->info(
			'Nested.',
			array(
				'deep'      => array( array( array( 'response' => array( 'headers' => array( 'Authorization' => 'Bearer ' . self::TOKEN ) ) ) ) ),
				'object'    => $object,
				'error'     => $error,
				'untouched' => $untouched,
				'int'       => 5,
				'float'     => 1.5,
				'bool'      => false,
				'null'      => null,
			)
		);

		$context = $this->inner->records[0]['context'];
		$this->assertSame( '[redacted]', $context['deep'][0][0]['response']['headers']['Authorization'] );
		$this->assertInstanceOf( stdClass::class, $context['object'], 'A plain object stays one' );
		$this->assertSame( '[redacted]', $context['object']->headers['Authorization'] );
		$this->assertSame( 2, $context['object']->count );
		$this->assertSame( 'Bearer ' . self::TOKEN, $object->headers['Authorization'], 'The caller\'s object is not changed' );
		$this->assertInstanceOf( WP_Error::class, $context['error'] );
		$this->assertSame( array( 'http_request_failed', 'second' ), $context['error']->get_error_codes() );
		$this->assertSame( 'cURL error with Bearer [redacted]', $context['error']->get_error_message( 'http_request_failed' ) );
		$this->assertSame( array( 'Authorization' => '[redacted]' ), $context['error']->get_error_data( 'http_request_failed' ) );
		$this->assertSame( 'Second message', $context['error']->get_error_message( 'second' ) );
		$this->assertSame( $untouched, $context['untouched'], 'An object with nothing to redact is passed through as is' );
		$this->assertSame( 5, $context['int'] );
		$this->assertSame( 1.5, $context['float'] );
		$this->assertFalse( $context['bool'] );
		$this->assertNull( $context['null'] );
		$this->assertStringNotContainsString( self::TOKEN, $this->inner->as_text() );
	}

	/**
	 * @testdox Should replace an object whose public properties hold a credential with its redacted properties.
	 */
	public function test_redacts_the_public_properties_of_other_objects(): void {
		$object = new class() {
			/**
			 * A header list.
			 *
			 * @var array
			 */
			public array $headers = array( 'Authorization' => 'Bearer ' . RedactingLoggerTest::TOKEN );
		};

		$this->sut->debug( 'Object.', array( 'value' => $object ) );

		$this->assertSame( array( 'headers' => array( 'Authorization' => '[redacted]' ) ), $this->inner->records[0]['context']['value'] );
	}

	/**
	 * @testdox Should pass the level, a clean message and a clean context through unchanged.
	 */
	public function test_passes_clean_records_through(): void {
		$context = array(
			'source'   => 'paypal',
			'order_id' => 12,
		);

		$this->sut->log( 'notice', 'Nothing secret here.', $context );

		$this->assertSame(
			array(
				'level'   => 'notice',
				'message' => 'Nothing secret here.',
				'context' => $context,
			),
			$this->inner->records[0]
		);
	}

	/**
	 * @testdox Should stop at a self-referencing structure without recursing forever.
	 */
	public function test_stops_at_cycles(): void {
		$object       = new stdClass();
		$object->self = $object;
		$object->auth = 'Bearer ' . self::TOKEN;

		$this->sut->info( 'Cycle.', array( 'value' => $object ) );

		$this->assertStringNotContainsString( self::TOKEN, $this->inner->as_text() );
	}
}
