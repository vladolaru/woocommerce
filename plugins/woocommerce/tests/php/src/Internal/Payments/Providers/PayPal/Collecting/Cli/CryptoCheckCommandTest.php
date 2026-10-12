<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Collecting\Cli;

use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\Cli\CryptoCheckCommand;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\Crypto\CryptoCheck;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Collecting\Crypto\CryptoCreateAnswer;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\WalletTestCase;
use RuntimeException;

/**
 * Tests for the output lines of `wp wc paypal-wallet crypto-check`.
 *
 * @group paypal-wallet
 */
class CryptoCheckCommandTest extends WalletTestCase {

	/**
	 * A check's result.
	 *
	 * @param array $overrides The fields to set.
	 * @return array
	 */
	private function result( array $overrides ): array {
		return $overrides + array(
			'outcome'      => CryptoCreateAnswer::UNKNOWN,
			'recorded'     => CryptoCreateAnswer::UNKNOWN !== ( $overrides['outcome'] ?? CryptoCreateAnswer::UNKNOWN ),
			'http_status'  => 0,
			'order_id'     => '',
			'order_status' => '',
			'debug_id'     => '',
			'error'        => '',
		);
	}

	/**
	 * The command over a check that answers the given result.
	 *
	 * @param array $result The result run() answers.
	 * @return CryptoCheckCommand
	 */
	private function command( array $result ): CryptoCheckCommand {
		$check = $this->mock( CryptoCheck::class );
		$check->shouldReceive( 'run' )->andReturn( $result );

		return new CryptoCheckCommand( $check );
	}

	/**
	 * @testdox Should print the order and debug IDs and a success for an accepted order.
	 */
	public function test_accepted(): void {
		$lines = $this->command(
			$this->result(
				array(
					'outcome'      => CryptoCreateAnswer::ACCEPTED,
					'http_status'  => 200,
					'order_id'     => 'PP-1',
					'order_status' => 'PAYER_ACTION_REQUIRED',
					'debug_id'     => 'debug-1',
				)
			)
		)->lines( false );

		$this->assertSame(
			array(
				array( 'log', 'PayPal order ID: PP-1' ),
				array( 'log', 'PayPal debug ID: debug-1' ),
				array( 'success', 'PayPal accepted the check order, so the merchant can take Pay with Crypto.' ),
			),
			$lines
		);
	}

	/**
	 * @testdox Should print the debug ID and a warning that says when to run the check again for a refusal.
	 */
	public function test_refused(): void {
		$lines = $this->command(
			$this->result(
				array(
					'outcome'     => CryptoCreateAnswer::REFUSED,
					'http_status' => 422,
					'debug_id'    => 'debug-2',
					'error'       => 'The \'API caller\' and/or \'payee\' is not setup.',
				)
			)
		)->lines( false );

		$this->assertSame(
			array(
				array( 'log', 'PayPal debug ID: debug-2' ),
				array( 'warning', 'PayPal refused the check order, so the merchant cannot take Pay with Crypto yet. Run the check again once PayPal finishes setting it up, which can take up to two business days.' ),
			),
			$lines
		);
	}

	/**
	 * @testdox Should end with an error that keeps the last result for any other answer: $scenario.
	 * @testWith ["an exception", "The PayPal request failed."]
	 *           ["an order status", "PayPal answered HTTP 201 with the order status CREATED."]
	 *           ["a bare status", "PayPal answered HTTP 500."]
	 *
	 * @param string $scenario   The case.
	 * @param string $detail The detail the line ends with.
	 */
	public function test_unknown( string $scenario, string $detail ): void {
		$fields = array( 'debug_id' => 'debug-3' );
		if ( 'an exception' === $scenario ) {
			$fields['error'] = 'The PayPal request failed.';
		} elseif ( 'an order status' === $scenario ) {
			$fields['http_status']  = 201;
			$fields['order_id']     = 'PP-3';
			$fields['order_status'] = 'CREATED';
		} else {
			$fields['http_status'] = 500;
		}

		$lines = $this->command( $this->result( $fields ) )->lines( false );

		$this->assertSame( array( 'log', 'PayPal debug ID: debug-3' ), $lines[ count( $lines ) - 2 ] );
		$this->assertSame( array( 'error', 'The check could not tell whether the merchant can take Pay with Crypto, so the last result is kept. ' . $detail ), end( $lines ) );
	}

	/**
	 * @testdox Should print the body and a success, and send nothing, for a dry run.
	 */
	public function test_dry_run(): void {
		$check = $this->mock( CryptoCheck::class );
		$check->shouldReceive( 'body' )->once()->andReturn( array( 'intent' => 'CAPTURE' ) );
		$check->shouldNotReceive( 'run' );

		$lines = ( new CryptoCheckCommand( $check ) )->lines( true );

		$this->assertSame(
			array(
				array( 'log', "{\n    \"intent\": \"CAPTURE\"\n}" ),
				array( 'success', 'Dry run: nothing was sent to PayPal.' ),
			),
			$lines
		);
	}

	/**
	 * @testdox Should print only the refusal as an error on a store that is not platform connected: $mode.
	 * @testWith ["dry run"]
	 *           ["check"]
	 *
	 * @param string $mode The mode.
	 */
	public function test_refused_outside_the_platform_connected_state( string $mode ): void {
		$check     = $this->mock( CryptoCheck::class );
		$exception = new RuntimeException( 'The Pay with Crypto check runs only on a store that is platform connected to its PayPal merchant.' );
		$check->shouldReceive( 'body' )->andThrow( $exception );
		$check->shouldReceive( 'run' )->andThrow( $exception );

		$lines = ( new CryptoCheckCommand( $check ) )->lines( 'dry run' === $mode );

		$this->assertSame( array( array( 'error', 'The Pay with Crypto check runs only on a store that is platform connected to its PayPal merchant.' ) ), $lines );
	}

	/**
	 * @testdox Should end with an error, not a success or warning, when an accepted or refused answer could not be stored: $outcome.
	 * @testWith ["accepted"]
	 *           ["refused"]
	 *
	 * @param string $outcome The outcome.
	 */
	public function test_an_answer_not_stored( string $outcome ): void {
		$lines = $this->command(
			$this->result(
				array(
					'outcome'  => $outcome,
					'recorded' => false,
					'order_id' => 'PP-4',
					'debug_id' => 'debug-4',
				)
			)
		)->lines( false );

		$this->assertSame(
			array(
				array( 'log', 'PayPal order ID: PP-4' ),
				array( 'log', 'PayPal debug ID: debug-4' ),
				array( 'error', 'The answer could not be stored: the platform connection has no merchant ID or environment.' ),
			),
			$lines
		);
	}

	/**
	 * @testdox Should print PayPal's description as text, not as the HTML entities the wallet's endpoint escaped it to.
	 */
	public function test_the_failure_detail_is_plain_text(): void {
		$lines = $this->command( $this->result( array( 'error' => 'The &#039;API caller&#039; &amp; payee' ) ) )->lines( false );

		$this->assertSame( 'The check could not tell whether the merchant can take Pay with Crypto, so the last result is kept. The \'API caller\' & payee', end( $lines )[1] );
	}
}
