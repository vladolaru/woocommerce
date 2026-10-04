<?php
/**
 * Tests for the payer entity.
 *
 * @package Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Entity
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Entity;

use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Entity\Address;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Entity\Payer;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Entity\PayerName;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Entity\PayerTaxInfo;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Entity\PhoneWithType;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\WalletTestCase;
use DateTime;
use Mockery\MockInterface;

/**
 * The payer on a PayPal order. Every part but the name, the email and the payer ID is optional, and a part that is not
 * there is left out of the array. This is wallet code: the buyer's PayPal account is the payer.
 *
 * @group paypal-wallet
 */
class PayerTest extends WalletTestCase {

	/**
	 * A mock of a payer part that writes a one-entry array. An address is also asked for its country code once.
	 *
	 * @param string $class_name The part's class.
	 * @param string $entry      The entry the part writes.
	 * @return MockInterface
	 */
	private function make_part( string $class_name, string $entry ): MockInterface {
		$part = $this->mock( $class_name );
		$part->shouldReceive( 'to_array' )->once()->andReturn( array( $entry ) );
		if ( Address::class === $class_name ) {
			$part->shouldReceive( 'country_code' )->once()->andReturn( 'GB' );
		}

		return $part;
	}

	/**
	 * A payer over mocks of its parts. A part given in the overrides replaces the default mock (null for none).
	 *
	 * @param array $overrides Values that replace the defaults, keyed by "payer_id", "phone", "tax_info" or "birthday".
	 * @return array{payer: Payer, parts: array}
	 */
	private function make_payer( array $overrides = array() ): array {
		$defaults = array(
			'name'     => fn() => $this->make_part( PayerName::class, 'payerName' ),
			'address'  => fn() => $this->make_part( Address::class, 'address' ),
			'payer_id' => fn() => 'payerId',
			'birthday' => fn() => new DateTime(),
			'phone'    => fn() => $this->make_part( PhoneWithType::class, 'phone' ),
			'tax_info' => fn() => $this->make_part( PayerTaxInfo::class, 'taxInfo' ),
		);

		// A default is only built when it is not overridden, so no mock is left with an expectation nothing meets.
		$parts = array();
		foreach ( $defaults as $key => $build ) {
			$parts[ $key ] = array_key_exists( $key, $overrides ) ? $overrides[ $key ] : $build();
		}

		$payer = new Payer( $parts['name'], 'email@example.com', $parts['payer_id'], $parts['address'], $parts['birthday'], $parts['phone'], $parts['tax_info'] );

		return array(
			'payer' => $payer,
			'parts' => $parts,
		);
	}

	/**
	 * @testdox Should hold every part it is given and write each one into the array.
	 */
	public function test_payer(): void {
		$made  = $this->make_payer();
		$payer = $made['payer'];
		$parts = $made['parts'];

		$this->assertSame( $parts['name'], $payer->name() );
		$this->assertSame( 'email@example.com', $payer->email_address() );
		$this->assertSame( 'payerId', $payer->payer_id() );
		$this->assertSame( $parts['address'], $payer->address() );
		$this->assertSame( $parts['birthday'], $payer->birthdate() );
		$this->assertSame( $parts['phone'], $payer->phone() );
		$this->assertSame( $parts['tax_info'], $payer->tax_info() );

		$array = $payer->to_array();
		$this->assertSame( $parts['birthday']->format( 'Y-m-d' ), $array['birth_date'] );
		$this->assertSame( array( 'payerName' ), $array['name'] );
		$this->assertSame( 'email@example.com', $array['email_address'] );
		$this->assertSame( array( 'address' ), $array['address'] );
		$this->assertSame( 'payerId', $array['payer_id'] );
		$this->assertSame( array( 'phone' ), $array['phone'] );
		$this->assertSame( array( 'taxInfo' ), $array['tax_info'] );
	}

	/**
	 * @testdox Should leave the payer ID out of the array when it is empty.
	 */
	public function test_payer_no_id(): void {
		$made = $this->make_payer( array( 'payer_id' => '' ) );

		$this->assertSame( '', $made['payer']->payer_id() );
		$this->assertArrayNotHasKey( 'payer_id', $made['payer']->to_array() );
	}

	/**
	 * @testdox Should leave the phone out of the array when there is none.
	 */
	public function test_payer_no_phone(): void {
		$made = $this->make_payer( array( 'phone' => null ) );

		$this->assertNull( $made['payer']->phone() );
		$this->assertArrayNotHasKey( 'phone', $made['payer']->to_array() );
	}

	/**
	 * @testdox Should leave the tax info out of the array when there is none.
	 */
	public function test_payer_no_tax_info(): void {
		$made = $this->make_payer( array( 'tax_info' => null ) );

		$this->assertNull( $made['payer']->tax_info() );
		$this->assertArrayNotHasKey( 'tax_info', $made['payer']->to_array() );
	}

	/**
	 * @testdox Should leave the birth date out of the array when there is none.
	 */
	public function test_payer_no_birth_date(): void {
		$made = $this->make_payer( array( 'birthday' => null ) );

		$this->assertNull( $made['payer']->birthdate() );
		$this->assertArrayNotHasKey( 'birth_date', $made['payer']->to_array() );
	}
}
