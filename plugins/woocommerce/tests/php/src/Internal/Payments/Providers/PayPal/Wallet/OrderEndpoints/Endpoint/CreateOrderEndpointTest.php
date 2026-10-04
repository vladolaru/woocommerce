<?php
/**
 * Tests for the PayPal wallet create-order endpoint (ported from the extension's CreateOrderEndpointTest).
 *
 * @package Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\OrderEndpoints\Endpoint
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\OrderEndpoints\Endpoint;

use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Endpoint\OrderEndpoint;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Factory\ContactPreferenceFactory;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Factory\ExperienceContextBuilder;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Factory\PayerFactory;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Factory\PurchaseUnitFactory;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Factory\ReturnUrlFactory;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Factory\ShippingPreferenceFactory;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Button\Session\CartDataFactory;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Button\Session\CartDataTransientStorage;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\OrderEndpoints\Endpoint\CreateOrderEndpoint;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\OrderEndpoints\Endpoint\RequestData;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\OrderEndpoints\Helper\EarlyOrderHandler;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Session\SessionHandler;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Settings\Data\SettingsProvider;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\WalletTestCase;
use Automattic\WooCommerce\Vendor\Psr\Log\NullLogger;
use Mockery;
use ReflectionMethod;

/**
 * The payer the create-order endpoint hands to the payer factory has a phone number PayPal accepts.
 *
 * @group paypal-wallet
 */
class CreateOrderEndpointTest extends WalletTestCase {

	/**
	 * The payer factory mock.
	 *
	 * @var PayerFactory|\Mockery\MockInterface
	 */
	private $payer_factory;

	/**
	 * The endpoint under test.
	 *
	 * @var CreateOrderEndpoint
	 */
	private $sut;

	/**
	 * Build the endpoint over mocked collaborators.
	 */
	public function setUp(): void {
		parent::setUp();

		$this->payer_factory = $this->mock( PayerFactory::class );

		$settings_provider = $this->mock( SettingsProvider::class );
		$settings_provider->shouldReceive( 'save_paypal_and_venmo' )->andReturn( false );

		$this->sut = new CreateOrderEndpoint(
			$this->mock( RequestData::class ),
			$this->mock( PurchaseUnitFactory::class ),
			$this->mock( ShippingPreferenceFactory::class ),
			$this->mock( ReturnUrlFactory::class ),
			$this->mock( ContactPreferenceFactory::class ),
			$this->mock( ExperienceContextBuilder::class ),
			$this->mock( OrderEndpoint::class ),
			$this->payer_factory,
			$this->mock( SessionHandler::class ),
			$settings_provider,
			$this->mock( EarlyOrderHandler::class ),
			$this->mock( CartDataFactory::class ),
			$this->mock( CartDataTransientStorage::class ),
			false,
			false,
			array( 'checkout' ),
			false,
			false,
			array( 'paypal' ),
			new NullLogger()
		);
	}

	/**
	 * A phone number PayPal rejects (empty, over 14 digits, or not numeric) is cleaned or dropped before the payer is
	 * built; the payer factory receives the cleaned payer.
	 *
	 * @testdox Should hand the payer factory the payer with its phone number $name.
	 *
	 * @dataProvider data_for_phone_number
	 *
	 * @param string $name            Case name.
	 * @param array  $data            The request data.
	 * @param array  $expected_payer  The payer the factory should receive.
	 */
	public function test_payer_verifies_phone_number( string $name, array $data, array $expected_payer ): void {
		unset( $name );
		$expected_object = json_decode( wp_json_encode( $expected_payer ) );

		$this->payer_factory
			->expects( 'from_paypal_response' )
			->once()
			->with(
				Mockery::on(
					static function ( $payer ) use ( $expected_object ): bool {
						return $payer == $expected_object; // phpcs:ignore Universal.Operators.StrictComparisons.LooseEqual -- Comparing decoded objects structurally.
					}
				)
			);

		$method = new ReflectionMethod( CreateOrderEndpoint::class, 'payer' );
		$method->setAccessible( true );
		$method->invokeArgs( $this->sut, array( $data ) );
	}

	/**
	 * Request data with the payer as the button sends it, and the payer the factory should get.
	 *
	 * @return array<string, array>
	 */
	public function data_for_phone_number(): array {
		return array(
			'empty string'       => array(
				'empty string',
				$this->request_with_phone( '' ),
				array( 'name' => array( 'given_name' => 'testName' ) ),
			),
			'too long'           => array(
				'too long',
				$this->request_with_phone( '43241341234123412341234123123412341' ),
				$this->payer_with_phone( '43241341234123' ),
			),
			'non-digits removed' => array(
				'non-digits removed',
				$this->request_with_phone( '432a34as73737373' ),
				$this->payer_with_phone( '4323473737373' ),
			),
			'no digits at all'   => array(
				'no digits at all',
				$this->request_with_phone( 'this is_notaPhone' ),
				array( 'name' => array( 'given_name' => 'testName' ) ),
			),
		);
	}

	/**
	 * Request data whose payer has the given national phone number.
	 *
	 * @param string $national_number The phone number.
	 * @return array
	 */
	private function request_with_phone( string $national_number ): array {
		return array(
			'context' => 'none',
			'payer'   => $this->payer_with_phone( $national_number ),
		);
	}

	/**
	 * A payer array with the given national phone number.
	 *
	 * @param string $national_number The phone number.
	 * @return array
	 */
	private function payer_with_phone( string $national_number ): array {
		return array(
			'name'  => array( 'given_name' => 'testName' ),
			'phone' => array( 'phone_number' => array( 'national_number' => $national_number ) ),
		);
	}
}
