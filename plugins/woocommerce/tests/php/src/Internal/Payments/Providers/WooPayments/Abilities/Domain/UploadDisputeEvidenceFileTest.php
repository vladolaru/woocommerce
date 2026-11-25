<?php
/**
 * Registered WooPayments dispute evidence uploads.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments\Abilities\Domain;

use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Abilities\Domain\UploadDisputeEvidenceFile;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api\ApiException;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api\Client;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api\HttpClient;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsSetupTier;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments\Abilities\AbilityTestCase;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments\Api\FakeHttpClient;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments\RecordingWcLogger;
use Automattic\WooCommerce\Testing\Tools\TestingContainer;

/** Exercise registered uploads through the Files transport used by the merchant screen. */
class UploadDisputeEvidenceFileTest extends AbilityTestCase {
	private const INPUT = array(
		'file_name'     => 'receipt.pdf',
		'file_type'     => 'application/pdf',
		'file_contents' => 'aGVsbG8=',
	);

	/** @var FakeHttpClient Platform transport. */
	private $http;

	/** @var \WP_Ability Registered upload ability. */
	private $sut;

	/** Discover the ability on a connected store with a fake platform transport. */
	public function setUp(): void {
		parent::setUp();
		$this->http = new FakeHttpClient();
		$container  = wc_get_container();
		$this->assertInstanceOf( TestingContainer::class, $container );
		$container->replace( HttpClient::class, $this->http );
		$this->reset_container_resolutions();
		$this->install_root();
		add_filter( self::GATE, '__return_true' );
		$ability = wp_get_ability( self::PREFIX . 'upload-dispute-evidence-file' );
		$this->assertInstanceOf( \WP_Ability::class, $ability );
		$this->sut = $ability;
	}

	/** Restore the original transport between tests. */
	public function tearDown(): void {
		try {
			$this->reset_container_replacements();
			$this->reset_container_resolutions();
		} finally {
			parent::tearDown();
		}
	}

	/**
	 * A manager can upload through the merchant Files endpoint. Transport encoding preserves the caller's bytes.
	 *
	 * @testWith ["receipt.pdf", "aGVsbG8="]
	 *           ["../../receipt.pdf", "aGVs\nbG8="]
	 *           ["receipt.pdf", "aGVsbG8"]
	 * @param string $name File name.
	 * @param string $contents Caller-supplied base64.
	 */
	public function test_uploads_through_the_client( string $name, string $contents ): void {
		$this->login_as_role( 'shop_manager' );
		$file = array(
			'id'       => 'file_receipt',
			'filename' => 'receipt.pdf',
			'purpose'  => 'dispute_evidence',
			'size'     => 5,
			'type'     => 'pdf',
		);
		$this->respond( $file );
		$input                  = self::INPUT;
		$input['file_name']     = $name;
		$input['file_contents'] = $contents;
		$this->assertSame( $file, $this->sut->execute( $input ) );
		$this->assertSame( 1, $this->http->request_count );
		$this->assertSame( 'POST', $this->http->last_method );
		$this->assertSame( '/sites/123/wcpay/files', $this->http->last_path );
		$body = json_decode( (string) $this->http->last_body, true );
		$this->assertSame( $contents, $body['file'] );
		$this->assertSame( 'receipt.pdf', $body['file_name'] );
		$this->assertSame( 'application/pdf', $body['file_type'] );
		$this->assertSame( 'dispute_evidence', $body['purpose'] );
		$this->assertFalse( $body['as_account'] );
		$this->assertArrayNotHasKey( 'idempotency_key', $body );
	}

	/**
	 * Invalid input never reaches the platform.
	 *
	 * @testWith ["file_name", "", "wcpay_missing_file_input"]
	 *           ["file_name", "   ", "wcpay_missing_file_input"]
	 *           ["file_contents", "", "wcpay_missing_file_input"]
	 *           ["file_contents", "@@@", "wcpay_invalid_file_contents"]
	 *           ["file_type", "image/gif", "ability_invalid_input"]
	 * @param string $field Input field.
	 * @param string $value Invalid value.
	 * @param string $code Expected refusal.
	 */
	public function test_refusals_send_nothing( string $field, string $value, string $code ): void {
		$input           = self::INPUT;
		$input[ $field ] = $value;
		$result          = $this->sut->execute( $input );
		$this->assertWPError( $result );
		$this->assertSame( $code, $result->get_error_code() );
		$this->assertSame( 0, $this->http->request_count );
	}

	/** Direct calls also enforce the advertised MIME types. */
	public function test_direct_call_checks_file_type(): void {
		$result = UploadDisputeEvidenceFile::execute( array_merge( self::INPUT, array( 'file_type' => 'image/gif' ) ) );
		$this->assertWPError( $result );
		$this->assertSame( 'wcpay_unsupported_file_type', $result->get_error_code() );
		$this->assertSame( 0, $this->http->request_count );
	}

	/**
	 * Enforce the inclusive limit on decoded bytes.
	 *
	 * @testWith [4500000]
	 *           [4500001]
	 * @param int $size File size in bytes.
	 */
	public function test_size_limit_is_inclusive( int $size ): void {
		$file = array( 'id' => 'file_limit' );
		$this->respond( $file );
		$input                  = self::INPUT;
		$input['file_contents'] = base64_encode( str_repeat( 'x', $size ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Generate a file of the specified decoded size.
		$result                 = $this->sut->execute( $input );
		if ( 4500000 === $size ) {
			$this->assertSame( $file, $result );
			$this->assertSame( 1, $this->http->request_count );
		} else {
			$this->assertWPError( $result );
			$this->assertSame( 'wcpay_file_too_large', $result->get_error_code() );
			$this->assertStringContainsString( '4500000', $result->get_error_message() );
			$this->assertSame( 0, $this->http->request_count );
		}
	}

	/**
	 * The caller receives the underlying failure while the log holds a fixed diagnostic.
	 *
	 * @testWith [false]
	 *           [true]
	 * @param bool $runtime Whether the client throws an unexpected exception.
	 */
	public function test_upload_failure( bool $runtime ): void {
		$logger  = RecordingWcLogger::install();
		$message = 'The provider refused this receipt.';
		if ( $runtime ) {
			$failure   = new \RuntimeException( $message );
			$client    = $this->createMock( Client::class );
			$container = wc_get_container();
			$this->assertInstanceOf( TestingContainer::class, $container );
			$client->method( 'upload_file_contents' )->willThrowException( $failure );
			$container->replace( Client::class, $client );
		} else {
			$this->respond(
				array(
					'error' => array(
						'code'    => 'file_too_large',
						'message' => $message,
					),
				),
				413
			);
		}

		$result = $this->sut->execute( self::INPUT );
		$this->assertWPError( $result );
		$this->assertSame( 'wcpay_file_upload_failed', $result->get_error_code() );
		$exception = $result->get_error_data()['exception'];
		$this->assertSame( $exception->getMessage(), $result->get_error_message() );
		$this->assertStringContainsString( $message, $result->get_error_message() );
		if ( $runtime ) {
			$this->assertSame( $failure, $exception );
		} else {
			$this->assertInstanceOf( ApiException::class, $exception );
			$this->assertSame( 'wcpay_evidence_file_upload_error', $exception->get_error_code() );
			$this->assertSame( 413, $exception->get_http_code() );
		}

		$code  = $runtime ? 'wcpay_file_upload_failed' : 'wcpay_evidence_file_upload_error';
		$lines = array();
		foreach ( $logger->lines as $index => $line ) {
			if ( 'woopayments-abilities' === $line[2] ) {
				$lines[] = $line;
				$this->assertSame( array( 'source' => 'woopayments-abilities' ), $logger->contexts[ $index ] );
			}
		}
		$this->assertSame( array( array( 'error', "Dispute evidence file upload failed ($code).", 'woopayments-abilities' ) ), $lines );
	}

	/**
	 * @testdox A customer, guest, or manager whose store withdrew opt-in cannot upload.
	 * @testWith ["customer"]
	 *           ["guest"]
	 *           ["opt-out"]
	 *
	 * @param string $caller Caller or store state.
	 */
	public function test_refused_permission( string $caller ): void {
		if ( 'opt-out' === $caller ) {
			add_filter( self::GATE, '__return_false', 20 );
		} elseif ( 'guest' === $caller ) {
			wp_set_current_user( 0 );
		} else {
			$this->login_as_role( $caller );
		}

		$result = $this->sut->execute( self::INPUT );
		$this->assertWPError( $result );
		$this->assertSame( 'ability_invalid_permissions', $result->get_error_code() );
		$this->assertSame( 0, $this->http->request_count );
	}

	/**
	 * @testdox A retained ability cannot upload after the current store leaves the connected tier.
	 */
	public function test_execution_rechecks_current_site_tier(): void {
		$this->assertTrue( $this->sut->check_permissions( self::INPUT ) );
		$this->assertTrue( wc_get_container()->get( WooPaymentsSetupTier::class )->write_tier( WooPaymentsSetupTier::AVAILABLE ) );

		$result = $this->sut->execute( self::INPUT );
		$this->assertWPError( $result );
		$this->assertSame( 'ability_invalid_permissions', $result->get_error_code() );
		$this->assertSame( 0, $this->http->request_count );
	}

	/**
	 * Supply the transport's next JSON response.
	 *
	 * @param array $body Platform response.
	 * @param int   $status HTTP status.
	 */
	private function respond( array $body, int $status = 200 ): void {
		$this->http->response = array(
			'response' => array( 'code' => $status ),
			'headers'  => array( 'content-type' => 'application/json' ),
			'body'     => wp_json_encode( $body ),
		);
	}
}
