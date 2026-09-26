<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments;

use Automattic\WooCommerce\Admin\Notes\Note;
use Automattic\WooCommerce\Admin\Notes\Notes;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsEventIngestor;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsNotificationEventHandler;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsRemoteNoteService;
use InvalidArgumentException;
use WC_Unit_Test_Case;

/**
 * Tests for the WooPaymentsNotificationEventHandler class.
 */
class WooPaymentsNotificationEventHandlerTest extends WC_Unit_Test_Case {

	/**
	 * The System Under Test.
	 *
	 * @var WooPaymentsEventIngestor
	 */
	private $sut;

	/**
	 * Set up test fixtures.
	 */
	public function setUp(): void {
		parent::setUp();
		$this->sut = wc_get_container()->get( WooPaymentsEventIngestor::class );
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
	 * @testdox wcpay.notification creates a remote note without requiring a data.object payload.
	 */
	public function test_wcpay_notification_creates_remote_note_without_event_object(): void {
		$note_slug = 'h30-ingestor-' . wp_generate_uuid4();
		update_option( 'woocommerce_woocommerce_payments_settings', array( 'test_mode' => 'yes' ) );

		$this->sut->process(
			array(
				'id'       => 'evt_note',
				'type'     => 'wcpay.notification',
				'livemode' => false,
				'data'     => array(
					'name'    => $note_slug,
					'title'   => 'Remote note',
					'content' => 'Remote note content.',
					'actions' => array(
						'settings' => array(
							'label' => 'Open settings',
							'url'   => 'wcpay_settings',
						),
					),
				),
			)
		);

		$note = Notes::get_note_by_name( WooPaymentsRemoteNoteService::NOTE_NAME_PREFIX . $note_slug );

		$this->assertInstanceOf( Note::class, $note );
		$this->assertSame( 'Remote note', $note->get_title() );
		$this->assertSame( 'Remote note content.', $note->get_content() );
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
