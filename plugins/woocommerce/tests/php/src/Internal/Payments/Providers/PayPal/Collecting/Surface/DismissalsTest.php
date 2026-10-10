<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Collecting\Surface;

use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\Surface\Dismissals;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\WalletTestCase;

/**
 * Tests for the per-user dismissals of the PayPal Wallet setup surfaces.
 *
 * @group paypal-wallet
 */
class DismissalsTest extends WalletTestCase {
	use HoldsWalletState;

	/**
	 * The System Under Test.
	 *
	 * @var Dismissals
	 */
	private $sut;

	/**
	 * The user who dismisses.
	 *
	 * @var int
	 */
	private int $user_id;

	/**
	 * Create a user.
	 */
	public function setUp(): void {
		parent::setUp();
		$this->sut     = new Dismissals();
		$this->user_id = self::factory()->user->create();
	}

	/**
	 * Forget the user the test signed in.
	 */
	public function tearDown(): void {
		try {
			wp_set_current_user( 0 );
		} finally {
			parent::tearDown();
		}
	}

	/**
	 * The parameters of a dismiss request.
	 *
	 * @param string $nonce    The nonce.
	 * @param string $surface  The surface slug.
	 * @param string $order_id The order ID, left out when empty.
	 * @return array
	 */
	private function request( string $nonce, string $surface, string $order_id = '' ): array {
		$request = array(
			'_wpnonce' => $nonce,
			'surface'  => $surface,
		);
		if ( '' !== $order_id ) {
			$request['order_id'] = $order_id;
		}

		return $request;
	}

	/**
	 * The query arguments of a URL.
	 *
	 * @param string $url The URL.
	 * @return array
	 */
	private function query_of( string $url ): array {
		$query = array();
		parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $query );

		return $query;
	}

	/**
	 * @testdox Should store the latest order ID seen in the surface's user meta key.
	 */
	public function test_dismiss_stores_the_latest_order_id(): void {
		$this->assertTrue( $this->sut->dismiss( 'row-notice', $this->user_id, 41 ) );

		$this->assertSame( '41', get_user_meta( $this->user_id, 'wc_paypal_wallet_dismissed_row-notice', true ) );
	}

	/**
	 * @testdox Should fall back to the first-order ID when no order ID is given.
	 */
	public function test_dismiss_falls_back_to_the_first_order(): void {
		$this->set_first_order( 17 );

		$this->sut->dismiss( 'row-notice', $this->user_id );

		$this->assertSame( '17', get_user_meta( $this->user_id, 'wc_paypal_wallet_dismissed_row-notice', true ) );
	}

	/**
	 * @testdox Should treat a surface as dismissed until a newer order arrives: dismissed at $stored, asked since $since.
	 * @testWith [41, 41, true]
	 *           [41, 40, true]
	 *           [41, 42, false]
	 *
	 * @param int  $stored   The order ID stored at dismissal.
	 * @param int  $since    The latest order ID the surface knows of.
	 * @param bool $expected Whether it is still dismissed.
	 */
	public function test_is_dismissed_until_a_newer_order_arrives( int $stored, int $since, bool $expected ): void {
		$this->sut->dismiss( 'row-notice', $this->user_id, $stored );

		$this->assertSame( $expected, $this->sut->is_dismissed( 'row-notice', $this->user_id, $since ) );
	}

	/**
	 * @testdox Should keep dismissals apart per surface and per user.
	 */
	public function test_dismissals_are_per_surface_and_per_user(): void {
		$other = self::factory()->user->create();
		$this->sut->dismiss( 'row-notice', $this->user_id, 5 );

		$this->assertFalse( $this->sut->is_dismissed( 'plugins-notice', $this->user_id, 5 ), 'Another surface is not dismissed' );
		$this->assertFalse( $this->sut->is_dismissed( 'row-notice', $other, 5 ), 'Another user has not dismissed' );
	}

	/**
	 * @testdox Should store nothing and report not dismissed for a missing user or an empty surface.
	 */
	public function test_ignores_a_missing_user_or_surface(): void {
		$this->assertFalse( $this->sut->dismiss( 'row-notice', 0, 5 ) );
		$this->assertFalse( $this->sut->dismiss( '', $this->user_id, 5 ) );
		$this->assertFalse( $this->sut->is_dismissed( 'row-notice', 0, 5 ) );
		$this->assertFalse( $this->sut->is_dismissed( '', $this->user_id, 5 ) );
	}

	/**
	 * @testdox Should answer a dismiss request with 403 for a bad nonce or a user without the capability, 400 for an unknown surface, 500 for a failed write and 200 when stored.
	 */
	public function test_dismiss_from_request_statuses(): void {
		$this->set_first_order( 7 );
		$user_id = self::factory()->user->create( array( 'role' => 'shop_manager' ) );
		wp_set_current_user( $user_id );
		$nonce = wp_create_nonce( Dismissals::AJAX_ACTION );

		$this->assertSame( 403, $this->sut->dismiss_from_request( $this->request( 'bad', 'row-notice' ) ) );
		$this->assertSame( 403, $this->sut->dismiss_from_request( array( 'surface' => 'row-notice' ) ), 'No nonce' );
		$this->assertSame( 400, $this->sut->dismiss_from_request( $this->request( $nonce, 'anything-else' ) ) );
		$this->assertSame( 400, $this->sut->dismiss_from_request( $this->request( $nonce, 'order-notice' ) ), 'The order notice needs an order ID' );
		$this->assertSame( 200, $this->sut->dismiss_from_request( array( '_wpnonce' => $nonce ) ), 'No surface reads as the row notice' );
		$this->assertTrue( $this->sut->is_dismissed( 'row-notice', $user_id, 7 ) );
		$this->assertSame( 200, $this->sut->dismiss_from_request( $this->request( $nonce, 'order-notice', '12' ) ) );
		$this->assertSame( '12', get_user_meta( $user_id, 'wc_paypal_wallet_dismissed_order-notice', true ) );

		add_filter( 'update_user_metadata', '__return_false' );
		delete_user_meta( $user_id, 'wc_paypal_wallet_dismissed_order-notice' );
		$this->assertSame( 500, $this->sut->dismiss_from_request( $this->request( $nonce, 'order-notice', '13' ) ), 'A write that does not round-trip' );
		remove_filter( 'update_user_metadata', '__return_false' );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'customer' ) ) );
		$this->assertSame( 403, $this->sut->dismiss_from_request( $this->request( wp_create_nonce( Dismissals::AJAX_ACTION ), 'row-notice' ) ), 'No manage_woocommerce' );
	}

	/**
	 * @testdox Should build a wc_ajax dismiss URL that carries the nonce, the surface and the order ID.
	 */
	public function test_dismiss_url(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'shop_manager' ) ) );
		$query = $this->query_of( $this->sut->dismiss_url( 'order-notice', 12 ) );

		$this->assertSame( 'wc_paypal_wallet_dismiss_notice', $query['wc-ajax'] );
		$this->assertSame( 'order-notice', $query['surface'] );
		$this->assertSame( '12', $query['order_id'] );
		$this->assertSame( 1, wp_verify_nonce( $query['_wpnonce'], Dismissals::AJAX_ACTION ) );
		$this->assertArrayNotHasKey( 'order_id', $this->query_of( $this->sut->dismiss_url( 'row-notice' ) ) );
	}

	/**
	 * @testdox Should answer the wc_ajax action with an error and store nothing for a refused request, and with success once stored.
	 */
	public function test_ajax_handler_answers_with_the_status(): void {
		$this->set_first_order( 7 );
		$user_id = self::factory()->user->create( array( 'role' => 'shop_manager' ) );
		wp_set_current_user( $user_id );

		$saved = $_REQUEST; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Saving test state.

		try {
			// run_ajax_handler() returns the JSON body only, so a refusal shows as success false and no stored dismissal.
			$_REQUEST = array( 'surface' => 'row-notice' );
			$refused  = $this->run_ajax_handler( array( $this->sut, 'handle_wc_ajax_wc_paypal_wallet_dismiss_notice' ) );
			$_REQUEST = $this->request( wp_create_nonce( Dismissals::AJAX_ACTION ), 'row-notice' );
			$this->assertFalse( $this->sut->is_dismissed( 'row-notice', $user_id, 7 ), 'The refused request stored nothing' );
			$stored = $this->run_ajax_handler( array( $this->sut, 'handle_wc_ajax_wc_paypal_wallet_dismiss_notice' ) );
		} finally {
			$_REQUEST = $saved;
		}

		$this->assertFalse( $refused['success'], 'No nonce' );
		$this->assertTrue( $stored['success'] );
		$this->assertTrue( $this->sut->is_dismissed( 'row-notice', $user_id, 7 ) );
	}
}
