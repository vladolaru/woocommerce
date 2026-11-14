<?php
/**
 * WooPaymentsSessionServiceTest file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments;

use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsSessionService;
use WC_Session;
use WC_Session_Handler;
use WC_Unit_Test_Case;

/**
 * Tests for the session payment values WooPaymentsSessionService keeps readable only in the session that stored them.
 */
class WooPaymentsSessionServiceTest extends WC_Unit_Test_Case {

	/**
	 * The System Under Test.
	 *
	 * @var WooPaymentsSessionService
	 */
	private $sut;

	/**
	 * The WooCommerce session before the test.
	 *
	 * @var WC_Session|null
	 */
	private $original_session;

	/**
	 * Set up test fixtures.
	 */
	public function setUp(): void {
		parent::setUp();
		$this->original_session = WC()->session;
		$this->sut              = new WooPaymentsSessionService();
	}

	/**
	 * Put back the session the test replaced and the current user.
	 */
	public function tearDown(): void {
		try {
			WC()->session = $this->original_session;
			wp_set_current_user( 0 );
		} finally {
			parent::tearDown();
		}
	}

	/**
	 * @testdox A stored value is read only in the session that stored it, under the extension's owner key: $_dataName.
	 *
	 * Client 11.3.0 class-wc-payments-customer-service.php:161-165 and :396-405, class-duplicate-payment-prevention-service.php:303
	 * and :330-337, class-wc-payment-gateway-wcpay.php:2451-2455 and class-wc-payments-order-success-page.php:579-582.
	 * A value with no recorded session, as one written before client 11.3.0, reads as absent there too.
	 *
	 * @dataProvider provide_owned_values
	 *
	 * @param string     $key       Session key of the value.
	 * @param string     $owner_key Session key recording the session that stored it.
	 * @param string|int $value     The value.
	 */
	public function test_value_is_read_only_in_the_session_that_stored_it( string $key, string $owner_key, $value ): void {
		$session = $this->create_session( 't_shopper' );

		WooPaymentsSessionService::set_owned_value( $session, $key, $value );

		$this->assertSame( 't_shopper', $session->get( $owner_key ) );
		$this->assertSame( $value, WooPaymentsSessionService::get_owned_value( $session, $key ) );

		$session->set( $owner_key, 't_another_session' );
		$this->assertNull( WooPaymentsSessionService::get_owned_value( $session, $key ), 'A value another session stored is ignored.' );

		$session->set( $owner_key, null );
		$this->assertNull( WooPaymentsSessionService::get_owned_value( $session, $key ), 'A value with no recorded session is ignored.' );

		WooPaymentsSessionService::set_owned_value( $session, $key, $value );
		WooPaymentsSessionService::set_owned_value( $session, $key, null );
		$this->assertNull( $session->get( $key ) );
		$this->assertNull( $session->get( $owner_key ) );
	}

	/**
	 * The values a session stores with its owner, and the extension's owner keys.
	 *
	 * @return array<string,array{0:string,1:string,2:string|int}>
	 */
	public function provide_owned_values(): array {
		return array(
			'guest customer'   => array( 'wcpay_customer_id', 'wcpay_customer_id_session', 'cus_guest' ),
			'processing order' => array( 'wcpay_processing_order', 'wcpay_processing_order_session', 123 ),
			'paid intent'      => array( 'wcpay_paid_intent_id', 'wcpay_paid_intent_id_session', 'pi_paid' ),
		);
	}

	/**
	 * @testdox A stored record is read only in the session that stored it, which it carries in session_customer_id.
	 *
	 * Client 11.3.0 class-woopay-session.php:1043 and class-woopay-utilities.php:518-530.
	 */
	public function test_record_is_read_only_in_the_session_that_stored_it(): void {
		$session = $this->create_session( 't_shopper' );

		WooPaymentsSessionService::set_owned_record( $session, 'woopay-user-data', array( 'save_user_in_woopay' => true ) );

		$this->assertSame(
			array(
				'save_user_in_woopay' => true,
				'session_customer_id' => 't_shopper',
			),
			WooPaymentsSessionService::get_owned_record( $session, 'woopay-user-data' )
		);
		$session->set(
			'woopay-user-data',
			array(
				'save_user_in_woopay' => true,
				'session_customer_id' => 't_another_session',
			)
		);
		$this->assertSame( array(), WooPaymentsSessionService::get_owned_record( $session, 'woopay-user-data' ) );
		$session->set( 'woopay-user-data', array( 'save_user_in_woopay' => true ) );
		$this->assertSame( array(), WooPaymentsSessionService::get_owned_record( $session, 'woopay-user-data' ) );
	}

	/**
	 * @testdox The values a guest session stored stay readable when WooCommerce moves the session to the shopper's account, and a value another session stored stays ignored.
	 *
	 * Core moves the session inside the request that logs the shopper in or creates their account
	 * (WC_Session_Handler::init_session_cookie()); client 11.3.0 class-wc-payments-session-service.php:43-75 and
	 * class-woopay-session.php:1059-1075 move each owner the guest session holds.
	 */
	public function test_guest_session_values_follow_the_shopper_into_their_account_session(): void {
		global $wpdb;
		$guest_id = 't_' . wc_rand_hash( '', 30 );
		$user_id  = self::factory()->user->create( array( 'role' => 'customer' ) );
		$expiry   = time() + DAY_IN_SECONDS;
		$wpdb->insert(
			$wpdb->prefix . 'woocommerce_sessions',
			array(
				'session_key'    => $guest_id,
				'session_value'  => maybe_serialize(
					array(
						'wcpay_customer_id'              => 'cus_guest',
						'wcpay_customer_id_session'      => $guest_id,
						'wcpay_processing_order'         => 123,
						'wcpay_processing_order_session' => $guest_id,
						'wcpay_paid_intent_id'           => 'pi_elsewhere',
						'wcpay_paid_intent_id_session'   => 't_another_session',
						'woopay-user-data'               => maybe_serialize(
							array(
								'save_user_in_woopay' => true,
								'session_customer_id' => $guest_id,
							)
						),
					)
				),
				'session_expiry' => $expiry,
			),
			array( '%s', '%s', '%d' )
		);
		$handler = $this->getMockBuilder( WC_Session_Handler::class )->onlyMethods( array( 'get_session_cookie', 'set_customer_session_cookie' ) )->getMock();
		$handler->method( 'get_session_cookie' )->willReturn( array( $guest_id, $expiry, $expiry - HOUR_IN_SECONDS, 'cookie_hash' ) );
		WC()->session = $handler;
		$this->sut->register();
		wp_set_current_user( $user_id );

		$handler->init_session_cookie();

		$this->assertSame( (string) $user_id, $handler->get_customer_id(), 'Core moved the session to the account.' );
		$this->assertSame( 'cus_guest', WooPaymentsSessionService::get_owned_value( $handler, 'wcpay_customer_id' ) );
		$this->assertSame( 123, WooPaymentsSessionService::get_owned_value( $handler, 'wcpay_processing_order' ) );
		$this->assertTrue( WooPaymentsSessionService::get_owned_record( $handler, 'woopay-user-data' )['save_user_in_woopay'] ?? false );
		$this->assertNull( WooPaymentsSessionService::get_owned_value( $handler, 'wcpay_paid_intent_id' ) );
		$this->assertSame( 't_another_session', $handler->get( 'wcpay_paid_intent_id_session' ) );
	}

	/**
	 * @testdox The move changes nothing unless it names the current session as the account's: $_dataName.
	 *
	 * Client 11.3.0 class-wc-payments-session-service.php:56-62.
	 *
	 * @testWith ["account session is not the current one", "t_guest", "42"]
	 *           ["empty guest session", "", "7"]
	 *           ["non-string account session", "t_guest", 7]
	 *
	 * @param string $label            Case label.
	 * @param mixed  $guest_session_id The former session ID.
	 * @param mixed  $user_session_id  The ID the guest session moved to.
	 */
	public function test_move_changes_nothing_for_another_session( string $label, $guest_session_id, $user_session_id ): void {
		unset( $label );
		$session = $this->create_session( 't_guest' );
		WooPaymentsSessionService::set_owned_value( $session, 'wcpay_processing_order', 123 );
		$this->set_customer_id( $session, '7' );
		WC()->session = $session;

		$this->sut->move_owned_values_to_user_session( $guest_session_id, $user_session_id );

		$this->assertSame( 't_guest', $session->get( 'wcpay_processing_order_session' ) );
	}

	/**
	 * Create a session for the given session ID.
	 *
	 * @param string $customer_id Session ID.
	 * @return WC_Session
	 */
	private function create_session( string $customer_id ): WC_Session {
		$session = new class() extends WC_Session {};
		$this->set_customer_id( $session, $customer_id );

		return $session;
	}

	/**
	 * Set a session's ID, as core does when it moves a guest session to an account.
	 *
	 * @param WC_Session $session     Session.
	 * @param string     $customer_id Session ID.
	 */
	private function set_customer_id( WC_Session $session, string $customer_id ): void {
		$property = new \ReflectionProperty( WC_Session::class, '_customer_id' );
		$property->setAccessible( true );
		$property->setValue( $session, $customer_id );
	}
}
