<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments;

use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsAccountService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsFraudPreventionService;
use WC_Unit_Test_Case;

/**
 * Tests for the WooPaymentsFraudPreventionService class.
 */
class WooPaymentsFraudPreventionServiceTest extends WC_Unit_Test_Case {

	/**
	 * @testdox Should generate and persist a fraud-prevention token in the WooCommerce session.
	 */
	public function test_get_token_generates_and_persists_session_token(): void {
		$session = $this->create_session();
		$sut     = $this->create_service( true, $session );

		$token = $sut->get_token();

		$this->assertSame( 16, strlen( $token ), 'Fraud-prevention tokens should match the extension token length.' );
		$this->assertSame( $token, $session->get( WooPaymentsFraudPreventionService::TOKEN_NAME ) );
		$this->assertSame( $token, $sut->get_token(), 'Subsequent reads should reuse the session token.' );
	}

	/**
	 * @testdox Should verify only the exact session fraud-prevention token.
	 */
	public function test_verify_token_accepts_exact_session_token_only(): void {
		$session = $this->create_session();
		$session->set( WooPaymentsFraudPreventionService::TOKEN_NAME, 'known-token' );
		$sut = $this->create_service( true, $session );

		$this->assertTrue( $sut->verify_token( 'known-token' ) );
		$this->assertFalse( $sut->verify_token( 'tampered-token' ) );
		$this->assertFalse( $sut->verify_token( null ) );
	}

	/**
	 * @testdox Should regenerate the fraud-prevention token in the same session key.
	 */
	public function test_regenerate_token_replaces_session_token(): void {
		$session = $this->create_session();
		$session->set( WooPaymentsFraudPreventionService::TOKEN_NAME, 'old-token' );
		$sut = $this->create_service( true, $session );

		$new_token = $sut->regenerate_token();

		$this->assertSame( 16, strlen( $new_token ) );
		$this->assertNotSame( 'old-token', $new_token );
		$this->assertSame( $new_token, $session->get( WooPaymentsFraudPreventionService::TOKEN_NAME ) );
	}

	/**
	 * @testdox Should read card-testing protection eligibility from cached WooPayments account data.
	 */
	public function test_is_enabled_reads_card_testing_protection_eligibility_from_account_cache(): void {
		$this->assertTrue( $this->create_service( true )->is_enabled() );
		$this->assertFalse( $this->create_service( false )->is_enabled() );
	}

	/**
	 * @testdox Should expose the token to shopper scripts once per page, only when protection applies.
	 */
	public function test_maybe_enqueue_token_script_is_a_guarded_singleton(): void {
		$session = $this->create_session();
		$session->set( WooPaymentsFraudPreventionService::TOKEN_NAME, 'known-token' );

		$disabled = $this->create_service( false, $session );
		$disabled->maybe_enqueue_token_script();
		$this->assertFalse( wp_script_is( WooPaymentsFraudPreventionService::TOKEN_NAME, 'enqueued' ) );

		$sut = $this->create_service( true, $session );
		$sut->maybe_enqueue_token_script();
		$this->assertTrue( wp_script_is( WooPaymentsFraudPreventionService::TOKEN_NAME, 'enqueued' ) );
		$inline = wp_scripts()->get_data( WooPaymentsFraudPreventionService::TOKEN_NAME, 'after' );
		$this->assertStringContainsString( "window.wcpayFraudPreventionToken = 'known-token';", implode( '', array_filter( (array) $inline, 'is_string' ) ) );

		// A second owner calling again must not duplicate the inline script.
		$sut->maybe_enqueue_token_script();
		$inline = wp_scripts()->get_data( WooPaymentsFraudPreventionService::TOKEN_NAME, 'after' );
		$this->assertCount( 1, array_filter( (array) $inline, 'is_string' ) );

		wp_dequeue_script( WooPaymentsFraudPreventionService::TOKEN_NAME );
		wp_deregister_script( WooPaymentsFraudPreventionService::TOKEN_NAME );
	}

	/**
	 * Create a fraud-prevention service with controlled account eligibility.
	 *
	 * @param bool             $eligible Whether the account is eligible for card-testing protection.
	 * @param \WC_Session|null $session  Optional session.
	 * @return WooPaymentsFraudPreventionService
	 */
	private function create_service( bool $eligible, ?\WC_Session $session = null ): WooPaymentsFraudPreventionService {
		$account_service = $this->getMockBuilder( WooPaymentsAccountService::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'get_cached_account_data' ) )
			->getMock();
		$account_service
			->method( 'get_cached_account_data' )
			->willReturn( array( 'card_testing_protection_eligible' => $eligible ) );

		$service = new WooPaymentsFraudPreventionService( $session ?? $this->create_session() );
		$service->init( $account_service );

		return $service;
	}

	/**
	 * Create a WooCommerce session test double.
	 *
	 * @return \WC_Session
	 */
	private function create_session(): \WC_Session {
		return new class() extends \WC_Session {
			/**
			 * Session data.
			 *
			 * @var array<string,mixed>
			 */
			protected $_data = array(); // phpcs:ignore PSR2.Classes.PropertyDeclaration.Underscore

			/**
			 * Get a session value.
			 *
			 * @param string $key           Session key.
			 * @param mixed  $default_value Default value.
			 * @return mixed
			 */
			public function get( $key, $default_value = null ) {
				return $this->_data[ $key ] ?? $default_value;
			}

			/**
			 * Set a session value.
			 *
			 * @param string $key   Session key.
			 * @param mixed  $value Session value.
			 */
			public function set( $key, $value ) {
				if ( null === $value ) {
					unset( $this->_data[ $key ] );
					return;
				}

				$this->_data[ $key ] = $value;
			}

			/**
			 * Set the customer session cookie.
			 *
			 * @param bool $set Whether to set the cookie.
			 */
			public function set_customer_session_cookie( bool $set ): void {}
		};
	}
}
