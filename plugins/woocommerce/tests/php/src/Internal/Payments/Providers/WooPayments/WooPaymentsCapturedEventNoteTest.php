<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments;

use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsCapturedEventNote;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments\Fixtures\ClientRenderedCapturedEvents;
use InvalidArgumentException;
use WC_Unit_Test_Case;

/**
 * Tests for the WooPaymentsCapturedEventNote class.
 */
class WooPaymentsCapturedEventNoteTest extends WC_Unit_Test_Case {

	/**
	 * Each event renders exactly the note client 11.1.0 rendered for it.
	 *
	 * The expected HTML in `Fixtures/rec-n296-captured-event-notes.json` was produced by running client 11.1.0
	 * `WC_Payments_Captured_Event_Note::generate_html_note()` on each event; see the fixture's `_meta`.
	 *
	 * @dataProvider client_rendered_captured_events
	 *
	 * @param array<string,mixed> $event       Captured timeline event.
	 * @param string              $client_html Note the client rendered for it.
	 */
	public function test_note_matches_the_client_rendering( array $event, string $client_html ): void {
		$this->assertSame( $client_html, ( new WooPaymentsCapturedEventNote( $event ) )->generate_html_note() );
	}

	/**
	 * Recorded and client test events with the client's rendering.
	 *
	 * @return array<string,array{array<string,mixed>,string}>
	 */
	public function client_rendered_captured_events(): array {
		return array_map(
			static fn( array $entry ): array => array( $entry['event'], $entry['client_html'] ),
			ClientRenderedCapturedEvents::all()
		);
	}

	/**
	 * @testdox Only captured events can be rendered, as in the client.
	 */
	public function test_rejects_an_event_that_is_not_captured(): void {
		$this->expectException( InvalidArgumentException::class );

		new WooPaymentsCapturedEventNote( array( 'type' => 'authorized' ) );
	}
}
