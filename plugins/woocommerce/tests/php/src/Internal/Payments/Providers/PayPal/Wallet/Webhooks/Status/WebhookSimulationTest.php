<?php
/**
 * Tests for the PayPal wallet webhook simulation (ported from the extension's WebhookSimulationTest).
 *
 * @package Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\Webhooks\Status
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\Webhooks\Status;

use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Endpoint\WebhookEndpoint;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Entity\Webhook;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\ApiClient\Entity\WebhookEvent;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Webhooks\Status\WebhookSimulation;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\WalletTestCase;

/**
 * The simulation tracks one simulated PayPal event in an option. Starting a simulation is switched off in the code
 * ("Disabled for 3.3.1 release"), so the state tests seed the option the way start() would have saved it.
 *
 * @group paypal-wallet
 */
class WebhookSimulationTest extends WalletTestCase {

	private const EVENT_TYPE = 'CHECKOUT.ORDER.APPROVED';
	private const EVENT_ID   = '123';

	/**
	 * The PayPal webhook endpoint mock.
	 *
	 * @var WebhookEndpoint|\Mockery\MockInterface
	 */
	private $webhook_endpoint;

	/**
	 * The simulation under test.
	 *
	 * @var WebhookSimulation
	 */
	private $sut;

	/**
	 * Build the simulation over a mocked endpoint.
	 */
	public function setUp(): void {
		parent::setUp();

		$this->webhook_endpoint = $this->mock( WebhookEndpoint::class );
		$this->sut              = new WebhookSimulation( $this->webhook_endpoint, new Webhook( 'https://example.com', array() ), self::EVENT_TYPE, '2.0' );
	}

	/**
	 * A webhook event with the given ID.
	 *
	 * @param string $id The event ID.
	 * @return WebhookEvent
	 */
	private function create_event( string $id ): WebhookEvent {
		return new WebhookEvent( $id, null, '', '', self::EVENT_TYPE, '', '', (object) array() );
	}

	/**
	 * Save the simulation state the way start() would have.
	 *
	 * @param string $state The state.
	 */
	private function seed_simulation( string $state ): void {
		$this->set_wallet_option(
			WebhookSimulation::OPTION_ID,
			array(
				'id'    => self::EVENT_ID,
				'state' => $state,
			)
		);
	}

	/**
	 * @testdox Should report no event as a simulation event, without throwing, when no simulation was saved.
	 */
	public function test_is_simulation_never_throws(): void {
		$this->assertFalse( $this->sut->is_simulation_event( $this->create_event( self::EVENT_ID ) ) );
	}

	/**
	 * New case: pins the "disabled" state, so re-enabling the simulation is a deliberate change.
	 *
	 * @testdox Should do nothing when started: no request to PayPal and nothing saved.
	 */
	public function test_start_is_disabled(): void {
		$this->webhook_endpoint->shouldNotReceive( 'simulate' );

		$this->sut->start();

		$this->assertFalse( get_option( WebhookSimulation::OPTION_ID ), 'Nothing should be saved' );
	}

	/**
	 * New case, standing in for the extension's testSimulation (skipped upstream because start() is disabled): the
	 * state handling after the saved simulation.
	 *
	 * @testdox Should mark the simulation received only when the matching event arrives.
	 */
	public function test_receive_marks_only_the_matching_event_as_received(): void {
		$this->seed_simulation( WebhookSimulation::STATE_WAITING );

		$this->assertTrue( $this->sut->is_simulation_event( $this->create_event( self::EVENT_ID ) ) );
		$this->assertFalse( $this->sut->is_simulation_event( $this->create_event( '456' ) ) );

		$this->assertFalse( $this->sut->receive( $this->create_event( '456' ) ) );
		$this->assertSame( WebhookSimulation::STATE_WAITING, $this->sut->get_state() );

		$this->assertTrue( $this->sut->receive( $this->create_event( self::EVENT_ID ) ) );
		$this->assertSame( WebhookSimulation::STATE_RECEIVED, $this->sut->get_state() );
	}
}
