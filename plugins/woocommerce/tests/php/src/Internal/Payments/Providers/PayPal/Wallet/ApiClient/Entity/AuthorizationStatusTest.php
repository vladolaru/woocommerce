<?php
/**
 * Tests for the authorization status entity.
 *
 * @package Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Entity
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Entity;

use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Entity\AuthorizationStatus;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Exception\RuntimeException;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\WalletTestCase;

/**
 * The statuses an authorization can be in. Anything else is rejected.
 *
 * @group paypal-wallet
 */
class AuthorizationStatusTest extends WalletTestCase {

	/**
	 * Every status the entity accepts.
	 *
	 * @return array<string, array{string}>
	 */
	public function data_status(): array {
		return array(
			'INTERNAL'           => array( 'INTERNAL' ),
			'CREATED'            => array( 'CREATED' ),
			'CAPTURED'           => array( 'CAPTURED' ),
			'DENIED'             => array( 'DENIED' ),
			'EXPIRED'            => array( 'EXPIRED' ),
			'PARTIALLY_CAPTURED' => array( 'PARTIALLY_CAPTURED' ),
			'VOIDED'             => array( 'VOIDED' ),
			'PENDING'            => array( 'PENDING' ),
		);
	}

	/**
	 * @testdox Should accept each valid status and report it by name.
	 * @dataProvider data_status
	 *
	 * @param string $status The status.
	 */
	public function test_valid_status_provided( string $status ): void {
		$authorization_status = new AuthorizationStatus( $status );

		$this->assertSame( $status, $authorization_status->name() );
	}

	/**
	 * @testdox Should reject a status it does not know.
	 */
	public function test_invalid_status_provided(): void {
		$this->expectException( RuntimeException::class );

		new AuthorizationStatus( 'invalid' );
	}

	/**
	 * @testdox Should tell whether it is a given status.
	 */
	public function test_status_comparison(): void {
		$authorization_status = new AuthorizationStatus( 'CREATED' );

		$this->assertTrue( $authorization_status->is( 'CREATED' ) );
		$this->assertFalse( $authorization_status->is( 'NOT_CREATED' ) );
	}
}
