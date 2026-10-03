<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments;

use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsFailedTransactionRateLimiter;
use WC_Unit_Test_Case;

/**
 * Tests for the WooPaymentsFailedTransactionRateLimiter class.
 */
class WooPaymentsFailedTransactionRateLimiterTest extends WC_Unit_Test_Case {

	/**
	 * Tear down test fixtures.
	 */
	public function tearDown(): void {
		delete_option( 'wcpay_session_rate_limiter_disabled_wcpay_card_declined_registry' );

		parent::tearDown();
	}

	/**
	 * @testdox Should record failed transaction attempts in the extension-compatible session key.
	 */
	public function test_bump_records_attempt_in_extension_session_key(): void {
		$session = $this->create_session();
		$sut     = new WooPaymentsFailedTransactionRateLimiter( $session );

		$before = time();
		$sut->bump();
		$after = time();

		$registry = $session->get( WooPaymentsFailedTransactionRateLimiter::SESSION_KEY );
		$this->assertIsArray( $registry );
		$this->assertCount( 1, $registry );
		$this->assertGreaterThanOrEqual( $before, $registry[0] );
		$this->assertLessThanOrEqual( $after, $registry[0] );
	}

	/**
	 * @testdox Should limit checkout after five failed transactions inside the ten-minute window.
	 */
	public function test_is_limited_returns_true_after_threshold_inside_window(): void {
		$session = $this->create_session();
		$session->set(
			WooPaymentsFailedTransactionRateLimiter::SESSION_KEY,
			array_fill( 0, 5, time() - 30 )
		);
		$sut = new WooPaymentsFailedTransactionRateLimiter( $session );

		$this->assertTrue( $sut->is_limited() );
	}

	/**
	 * @testdox Should reset the failed transaction registry after the ten-minute window expires.
	 */
	public function test_is_limited_clears_registry_after_window_expires(): void {
		$session = $this->create_session();
		$session->set(
			WooPaymentsFailedTransactionRateLimiter::SESSION_KEY,
			array_fill( 0, 5, time() - ( 10 * MINUTE_IN_SECONDS ) - 1 )
		);
		$sut = new WooPaymentsFailedTransactionRateLimiter( $session );

		$this->assertFalse( $sut->is_limited() );
		$this->assertSame( array(), $session->get( WooPaymentsFailedTransactionRateLimiter::SESSION_KEY ) );
	}

	/**
	 * @testdox Should honor the extension-compatible disable option.
	 */
	public function test_is_limited_honors_extension_disable_option(): void {
		$session = $this->create_session();
		$session->set(
			WooPaymentsFailedTransactionRateLimiter::SESSION_KEY,
			array_fill( 0, 5, time() )
		);
		$sut = new WooPaymentsFailedTransactionRateLimiter( $session );
		update_option( 'wcpay_session_rate_limiter_disabled_wcpay_card_declined_registry', 'yes' );

		$this->assertFalse( $sut->is_limited() );
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
