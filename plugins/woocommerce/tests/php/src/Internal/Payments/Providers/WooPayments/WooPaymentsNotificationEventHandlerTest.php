<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments;

use Automattic\WooCommerce\Admin\Notes\Note;
use Automattic\WooCommerce\Admin\Notes\Notes;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Webhooks\EventIngestor;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsNotificationEventHandler;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsRemoteNoteService;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments\Fixtures\RecordedPlatformEvents;
use InvalidArgumentException;
use WC_Unit_Test_Case;

/**
 * Tests for the WooPaymentsNotificationEventHandler class.
 */
class WooPaymentsNotificationEventHandlerTest extends WC_Unit_Test_Case {

	/**
	 * The System Under Test.
	 *
	 * @var EventIngestor
	 */
	private $sut;

	/**
	 * Set up test fixtures.
	 */
	public function setUp(): void {
		parent::setUp();
		$this->sut = wc_get_container()->get( EventIngestor::class );
	}

	/**
	 * Tear down test fixtures.
	 */
	public function tearDown(): void {
		delete_option( 'woocommerce_woocommerce_payments_settings' );
		parent::tearDown();
	}

	/**
	 * @testdox is_supported_event() matches only the wcpay.notification event type.
	 *
	 * Mirrors client 11.1.0's webhook dispatch switch
	 * (`includes/class-wc-payments-webhook-processing-service.php:205-225`), which handles
	 * `wcpay.notification` as its own case, distinct from every other event type.
	 */
	public function test_is_supported_event_matches_wcpay_notification_only(): void {
		$handler = new WooPaymentsNotificationEventHandler();
		$handler->init( new WooPaymentsRemoteNoteService() );

		$this->assertTrue( $handler->is_supported_event( 'wcpay.notification' ) );
		$this->assertFalse( $handler->is_supported_event( 'account.updated' ) );
	}

	/**
	 * @testdox wcpay.notification creates the platform's note, which carries no data.object: $_dataName.
	 *
	 * The bodies the platform sends (Fixtures/platform-notification-events.json), read as client 11.1.0 reads them
	 * (includes/notes/class-wc-payments-remote-note-service.php:64-107).
	 *
	 * @dataProvider provide_platform_notifications
	 *
	 * @param string      $pair          Recorded entry.
	 * @param string|null $action_path   Admin path the note's one action opens, or null when it has none.
	 * @param string|null $action_status Status of that action.
	 */
	public function test_wcpay_notification_creates_the_platform_note( string $pair, ?string $action_path, ?string $action_status ): void {
		update_option( 'woocommerce_woocommerce_payments_settings', array( 'test_mode' => 'yes' ) );
		$event = RecordedPlatformEvents::body( 'platform-notification-events.json', $pair );

		$this->sut->process( $event );

		$note = Notes::get_note_by_name( WooPaymentsRemoteNoteService::NOTE_NAME_PREFIX . $event['data']['name'] );
		$this->assertInstanceOf( Note::class, $note );
		$this->assertSame( $event['data']['title'], $note->get_title() );
		// Notes keep content through their own allowed-HTML filter (Note::set_content()), as on the client.
		$expected_content = new Note();
		$expected_content->set_content( $event['data']['content'] );
		$this->assertSame( $expected_content->get_content(), $note->get_content() );
		$actions = $note->get_actions();
		if ( null === $action_path ) {
			$this->assertSame( array(), $actions );
			return;
		}
		$this->assertCount( 1, $actions );
		$this->assertSame( admin_url( $action_path ), $actions[0]->query );
		$this->assertSame( $action_status, $actions[0]->status );
	}

	/**
	 * The platform's notes: the promotion start note opens the WooPayments settings, the dispute deadline note opens an
	 * admin path (url_is_admin), and the promotion end note has no action.
	 *
	 * @return array<string,array{string,string|null,string|null}>
	 */
	public function provide_platform_notifications(): array {
		return array(
			'promotion start note'  => array( 'promotion_start_note', 'admin.php?page=wc-settings&tab=checkout&section=woocommerce_payments', 'unactioned' ),
			'dispute deadline note' => array( 'dispute_deadline_note', 'admin.php?page=wc-admin&path=%2Fpayments%2Fdisputes', 'actioned' ),
			'promotion end note'    => array( 'promotion_end_note', null, null ),
		);
	}

	/**
	 * @testdox wcpay.notification fails closed for invalid remote note payloads.
	 */
	public function test_wcpay_notification_fails_closed_for_invalid_note_payload(): void {
		update_option( 'woocommerce_woocommerce_payments_settings', array( 'test_mode' => 'yes' ) );

		$this->expectException( InvalidArgumentException::class );

		$this->sut->process(
			array(
				'id'       => 'evt_note_invalid',
				'type'     => 'wcpay.notification',
				'livemode' => false,
				'data'     => array(
					'title' => 'Missing content',
				),
			)
		);
	}
}
