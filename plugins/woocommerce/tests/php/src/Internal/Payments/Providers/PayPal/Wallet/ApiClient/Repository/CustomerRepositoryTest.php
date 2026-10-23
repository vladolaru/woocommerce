<?php
/**
 * Tests for the customer repository.
 *
 * @package Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Repository
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Repository;

use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Repository\CustomerRepository;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\WalletTestCase;

/**
 * The PayPal customer ID of a user: the most recently assigned one (user meta "_ppcp_target_customer_id") wins over the
 * legacy vaulted one ("ppcp_customer_id"). The users are real, with real meta; a filter on the meta read records which
 * keys the repository asks for, so a case can still say "never reads the legacy key".
 *
 * @group paypal-wallet
 */
class CustomerRepositoryTest extends WalletTestCase {

	private const TARGET_KEY = '_ppcp_target_customer_id';
	private const LEGACY_KEY = 'ppcp_customer_id';

	/**
	 * The System Under Test.
	 *
	 * @var CustomerRepository
	 */
	private CustomerRepository $sut;

	/**
	 * Build the repository.
	 */
	public function setUp(): void {
		parent::setUp();

		$this->sut = new CustomerRepository( 'ppcp_prefix_' );
	}

	/**
	 * The meta keys a spy on the user meta read has seen, in order.
	 *
	 * @param \ArrayObject $calls The calls spy_filter() recorded on "get_user_metadata".
	 * @return string[]
	 */
	private function keys_read( \ArrayObject $calls ): array {
		return array_map(
			static function ( array $args ) {
				return $args[2];
			},
			$calls->getArrayCopy()
		);
	}

	/**
	 * GIVEN a user with both the most recently assigned PayPal customer ID and the legacy vaulted ID stored
	 * WHEN resolving the PayPal customer ID for that user
	 * THEN the most recently assigned ID takes precedence over the legacy one
	 *
	 * @testdox Should prefer the most recently assigned customer ID over the legacy one.
	 */
	public function test_most_recently_assigned_id_takes_precedence_over_legacy_id(): void {
		$user_id = self::factory()->user->create();
		update_user_meta( $user_id, self::TARGET_KEY, 'most-recent-customer-id' );
		update_user_meta( $user_id, self::LEGACY_KEY, 'legacy-customer-id' );
		$reads = $this->spy_filter( 'get_user_metadata' );

		$this->assertSame( 'most-recent-customer-id', $this->sut->paypal_customer_id_for_user( $user_id ) );
		$this->assertSame( array( self::TARGET_KEY ), $this->keys_read( $reads ), 'The legacy key should not be read' );
	}

	/**
	 * GIVEN a user whose most recently assigned PayPal customer ID was written back by a billing agreement conversion,
	 *       with no legacy vaulted ID present
	 * WHEN resolving the PayPal customer ID for that user
	 * THEN the previously assigned ID is found, preventing a duplicate PayPal customer from being created on a
	 *      subsequent renewal
	 *
	 * @testdox Should find the ID that a billing agreement conversion wrote back.
	 */
	public function test_finds_id_written_back_by_billing_agreement_conversion(): void {
		$user_id = self::factory()->user->create();
		update_user_meta( $user_id, self::TARGET_KEY, 'converted-customer-id' );
		$reads = $this->spy_filter( 'get_user_metadata' );

		$this->assertSame( 'converted-customer-id', $this->sut->paypal_customer_id_for_user( $user_id ) );
		$this->assertSame( array( self::TARGET_KEY ), $this->keys_read( $reads ) );
	}

	/**
	 * GIVEN a user with only the legacy vaulted PayPal customer ID stored
	 * WHEN resolving the PayPal customer ID for that user
	 * THEN the legacy ID is returned as a fallback
	 *
	 * @testdox Should fall back to the legacy customer ID when no recently assigned one is stored.
	 */
	public function test_falls_back_to_legacy_id_when_most_recently_assigned_id_is_missing(): void {
		$user_id = self::factory()->user->create();
		update_user_meta( $user_id, self::LEGACY_KEY, 'legacy-customer-id' );
		$reads = $this->spy_filter( 'get_user_metadata' );

		$this->assertSame( 'legacy-customer-id', $this->sut->paypal_customer_id_for_user( $user_id ) );
		$this->assertSame( array( self::TARGET_KEY, self::LEGACY_KEY ), $this->keys_read( $reads ) );
	}

	/**
	 * @testdox Should return an empty string for a user who was never vaulted.
	 */
	public function test_returns_empty_string_when_never_vaulted(): void {
		$user_id = self::factory()->user->create();
		$reads   = $this->spy_filter( 'get_user_metadata' );

		$this->assertSame( '', $this->sut->paypal_customer_id_for_user( $user_id ) );
		$this->assertSame( array( self::TARGET_KEY, self::LEGACY_KEY ), $this->keys_read( $reads ) );
	}

	/**
	 * @testdox Should return an empty string for a guest and not read any user meta.
	 */
	public function test_returns_empty_string_for_guest_user(): void {
		$reads = $this->spy_filter( 'get_user_metadata' );

		$this->assertSame( '', $this->sut->paypal_customer_id_for_user( 0 ) );
		$this->assertCount( 0, $reads );
	}
}
