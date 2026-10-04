<?php
/**
 * Tests for the access token entity.
 *
 * @package Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Entity
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Entity;

use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Entity\Token;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Exception\RuntimeException;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\WalletTestCase;
use stdClass;

/**
 * The token PayPal hands back: its validity window and the JSON round trip.
 *
 * @group paypal-wallet
 */
class TokenTest extends WalletTestCase {

	/**
	 * Token data that is valid, with and without a creation time.
	 *
	 * @return array<string, array{stdClass}>
	 */
	public function data_valid_tokens(): array {
		return array(
			'default'            => array(
				(object) array(
					'created'    => time(),
					'expires_in' => 100,
					'token'      => 'abc',
				),
			),
			'created_not_needed' => array(
				(object) array(
					'expires_in' => 100,
					'token'      => 'abc',
				),
			),
		);
	}

	/**
	 * Token data the constructor must reject.
	 *
	 * @return array<string, array{stdClass}>
	 */
	public function data_invalid_tokens(): array {
		return array(
			'created_is_not_integer'      => array(
				(object) array(
					'created'    => 'abc',
					'expires_in' => 123,
					'token'      => 'abc',
				),
			),
			'expires_in_is_not_integer'   => array(
				(object) array(
					'expires_in' => 'abc',
					'token'      => 'abc',
				),
			),
			'access_token_is_not_string'  => array(
				(object) array(
					'expires_in' => 123,
					'token'      => array( 'abc' ),
				),
			),
			'access_token_does_not_exist' => array(
				(object) array( 'expires_in' => 123 ),
			),
			'expires_in_does_not_exist'   => array(
				(object) array( 'token' => 'abc' ),
			),
		);
	}

	/**
	 * @testdox Should hold a valid token with or without a creation time.
	 * @dataProvider data_valid_tokens
	 *
	 * @param stdClass $data The token data.
	 */
	public function test_default( stdClass $data ): void {
		$token = new Token( $data );

		$this->assertSame( $data->token, $token->token() );
		$this->assertTrue( $token->is_valid() );
	}

	/**
	 * @testdox Should report a token past its expiry as invalid.
	 */
	public function test_is_valid(): void {
		$created = time() - 100;
		$token   = new Token(
			(object) array(
				'created'    => $created,
				'expires_in' => 99,
				'token'      => 'abc',
			)
		);

		$this->assertFalse( $token->is_valid() );
		$this->assertSame( $created + 99, $token->expiration_timestamp() );
	}

	/**
	 * @testdox Should treat a token inside the safety margin of its expiry as already invalid.
	 */
	public function test_is_valid_applies_the_safety_margin(): void {
		$within_margin = new Token(
			(object) array(
				'created'    => time(),
				'expires_in' => Token::EXPIRATION_SAFETY_MARGIN - 30,
				'token'      => 'abc',
			)
		);
		$beyond_margin = new Token(
			(object) array(
				'created'    => time(),
				'expires_in' => Token::EXPIRATION_SAFETY_MARGIN + 60,
				'token'      => 'abc',
			)
		);

		$this->assertFalse( $within_margin->is_valid(), 'A token about to expire is refreshed early' );
		$this->assertTrue( $beyond_margin->is_valid() );
	}

	/**
	 * @testdox Should build a token from the bearer JSON and from the identity JSON.
	 */
	public function test_from_json_reads_both_response_shapes(): void {
		$bearer   = Token::from_json(
			(string) wp_json_encode(
				array(
					'expires_in'   => 100,
					'access_token' => 'bearer-value',
				)
			)
		);
		$identity = Token::from_json(
			(string) wp_json_encode(
				array(
					'expires_in'   => 100,
					'client_token' => 'client-value',
				)
			)
		);

		$this->assertSame( 'bearer-value', $bearer->token() );
		$this->assertTrue( $bearer->is_valid() );
		$this->assertSame( 'client-value', $identity->token() );
		$this->assertTrue( $identity->is_valid() );
	}

	/**
	 * @testdox Should write the token back as JSON that holds the same token, creation time and lifetime.
	 */
	public function test_as_json(): void {
		$token = new Token(
			(object) array(
				'created'    => 100,
				'expires_in' => 100,
				'token'      => 'abc',
			)
		);

		$json = json_decode( $token->as_json() );

		$this->assertSame( 'abc', $json->token );
		$this->assertSame( 100, $json->created );
		$this->assertSame( 100, $json->expires_in );
	}

	/**
	 * @testdox Should reject token data with a missing or mistyped field.
	 * @dataProvider data_invalid_tokens
	 *
	 * @param stdClass $data The token data.
	 */
	public function test_constructor_rejects_malformed_data( stdClass $data ): void {
		$this->expectException( RuntimeException::class );

		new Token( $data );
	}
}
