<?php
/**
 * Tests for the credential validation of the authentication manager.
 *
 * @package Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\Settings\Service
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\Settings\Service;

use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Exception\RuntimeException;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Factory\PayPalBearerFactory;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Settings\Data\GeneralSettings;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Settings\Service\AuthenticationManager;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\WcGateway\Helper\ConnectionState;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\WcGateway\Helper\EnvironmentConfig;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\WalletTestCase;

/**
 * The shape check on the client ID and secret a merchant types in for a manual connection, before any request is sent:
 * 70 to 90 letters, digits, underscores or hyphens each.
 *
 * @group paypal-wallet
 */
class AuthenticationManagerValidationTest extends WalletTestCase {

	/**
	 * The System Under Test.
	 *
	 * @var AuthenticationManager
	 */
	private $sut;

	/**
	 * Build the manager over collaborators the validation never touches.
	 */
	public function setUp(): void {
		parent::setUp();

		$this->sut = new AuthenticationManager(
			$this->mock( GeneralSettings::class ),
			$this->mock( EnvironmentConfig::class ),
			$this->mock( EnvironmentConfig::class ),
			$this->mock( ConnectionState::class ),
			$this->mock( PayPalBearerFactory::class )
		);
	}

	/**
	 * @testdox Should refuse an empty client ID.
	 */
	public function test_empty_client_id_throws(): void {
		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessage( 'No client ID provided.' );

		$this->sut->validate_id_and_secret( '', 'Avalid_secret_that_is_exactly_eighty_characters_long_padded_to_meet_length_needed' );
	}

	/**
	 * @testdox Should refuse a client ID of the wrong shape.
	 */
	public function test_invalid_client_id_pattern_throws(): void {
		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessage( 'Invalid client ID provided.' );

		$this->sut->validate_id_and_secret( 'too-short', 'Avalid_secret_that_is_exactly_eighty_characters_long_padded_to_meet_length_needed' );
	}

	/**
	 * @testdox Should refuse an empty client secret.
	 */
	public function test_empty_client_secret_throws(): void {
		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessage( 'No client secret provided.' );

		$this->sut->validate_id_and_secret( str_repeat( 'a', 80 ), '' );
	}

	/**
	 * @testdox Should refuse a client secret of the wrong shape.
	 */
	public function test_invalid_client_secret_pattern_throws(): void {
		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessage( 'Invalid client secret provided.' );

		$this->sut->validate_id_and_secret( str_repeat( 'a', 80 ), 'invalid' );
	}

	/**
	 * @testdox Should accept a client ID and a client secret of 80 characters.
	 */
	public function test_valid_credentials_pass(): void {
		$this->sut->validate_id_and_secret( str_repeat( 'a', 80 ), str_repeat( 'b', 80 ) );

		$this->addToAssertionCount( 1 );
	}

	/**
	 * @testdox Should accept a client secret that does not start with the letter A.
	 */
	public function test_valid_credentials_not_starting_with_a_pass(): void {
		$this->sut->validate_id_and_secret( str_repeat( 'a', 80 ), 'E' . str_repeat( 'b', 79 ) );

		$this->addToAssertionCount( 1 );
	}

	/**
	 * Lengths at and around the 70 and 90 character bounds, for the client ID alone and for the client secret alone.
	 *
	 * @return array<string, array{0: string, 1: int, 2: bool}>
	 */
	public function data_length_bounds(): array {
		$cases = array();
		foreach ( array( 'client ID', 'client secret' ) as $field ) {
			$cases[ "$field of 69 characters is too short" ] = array( $field, 69, false );
			$cases[ "$field of 70 characters is enough" ]    = array( $field, 70, true );
			$cases[ "$field of 90 characters is enough" ]    = array( $field, 90, true );
			$cases[ "$field of 91 characters is too long" ]  = array( $field, 91, false );
		}

		return $cases;
	}

	/**
	 * The other credential is valid in every case, so each bound can only be reached through the field under test.
	 *
	 * @testdox Should accept a client ID or a client secret of 70 to 90 characters, and refuse one outside that range.
	 *
	 * @dataProvider data_length_bounds
	 *
	 * @param string $field    The field under test, "client ID" or "client secret".
	 * @param int    $length   The length of that field.
	 * @param bool   $is_valid Whether the field passes.
	 */
	public function test_length_bounds( string $field, int $length, bool $is_valid ): void {
		$tested = substr( str_repeat( 'a-_', (int) ceil( $length / 3 ) ), 0, $length );
		$valid  = str_repeat( 'b', 80 );

		if ( ! $is_valid ) {
			$this->expectException( RuntimeException::class );
			$this->expectExceptionMessage( "Invalid $field provided." );
		}

		if ( 'client ID' === $field ) {
			$this->sut->validate_id_and_secret( $tested, $valid );
		} else {
			$this->sut->validate_id_and_secret( $valid, $tested );
		}

		$this->addToAssertionCount( 1 );
	}
}
