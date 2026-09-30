<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments;

use Automattic\WooCommerce\Admin\Notes\Note;
use Automattic\WooCommerce\Admin\Notes\Notes;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsAdminNotesController;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsSetHttpsForCheckoutNote;
use Automattic\WooCommerce\Tests\Internal\Payments\StaticNativeRuntimeArbiter;
use WC_Unit_Test_Case;

/**
 * Tests for the WooPaymentsAdminNotesController class and the notes it adds on admin_init.
 *
 * Expectations come from client 11.1.0 `includes/notes/class-wc-payments-notes-set-https-for-checkout.php`
 * and the note rows the client wrote on a fresh test-drive store.
 */
class WooPaymentsAdminNotesControllerTest extends WC_Unit_Test_Case {

	/**
	 * The System Under Test.
	 *
	 * @var WooPaymentsAdminNotesController
	 */
	private $sut;

	/**
	 * Set up test fixtures.
	 */
	public function setUp(): void {
		parent::setUp();

		$this->delete_notes( WooPaymentsSetHttpsForCheckoutNote::NOTE_NAME );
		add_filter( 'pre_option_home', array( $this, 'get_http_home_url' ) );
		delete_option( 'woocommerce_force_ssl_checkout' );

		$this->sut = $this->create_sut( true );
	}

	/**
	 * @testdox Registers the admin_init note hook when native owns the runtime.
	 */
	public function test_registers_admin_init_hook_when_native_owns_runtime(): void {
		$this->sut->register();

		$this->assertSame( 10, has_action( 'admin_init', array( $this->sut, 'add_woo_admin_notes' ) ) );
	}

	/**
	 * @testdox Registers no note hook on a plugin-owned store.
	 */
	public function test_registers_no_hook_on_plugin_owned_store(): void {
		$sut = $this->create_sut( false );

		$sut->register();

		$this->assertFalse( has_action( 'admin_init', array( $sut, 'add_woo_admin_notes' ) ), 'A plugin-owned store must never get native notes.' );
	}

	/**
	 * @testdox Adds the secure checkout note with the client's name, copy and action when checkout is not HTTPS.
	 */
	public function test_adds_https_note_when_checkout_is_not_secure(): void {
		$this->sut->add_woo_admin_notes();

		$note = $this->get_single_note( WooPaymentsSetHttpsForCheckoutNote::NOTE_NAME );
		$this->assertSame( 'Enable secure checkout', $note->get_title() );
		$this->assertSame( 'Enable HTTPS on your checkout pages to display all available payment methods and protect your customers data.', $note->get_content() );
		$this->assertSame( Note::E_WC_ADMIN_NOTE_INFORMATIONAL, $note->get_type() );
		$this->assertSame( 'woocommerce-payments', $note->get_source() );
		$this->assertEquals( (object) array(), $note->get_content_data() );

		$actions = $note->get_actions();
		$this->assertCount( 1, $actions );
		$this->assertSame( 'wc-payments-notes-set-https-for-checkout', $actions[0]->name );
		$this->assertSame( 'Read more', $actions[0]->label );
		$this->assertSame( 'https://woocommerce.com/document/ssl-and-https/#woocommerce-force-ssl-setting', $actions[0]->query );
		$this->assertSame( Note::E_WC_ADMIN_NOTE_UNACTIONED, $actions[0]->status );
	}

	/**
	 * @testdox Adds the secure checkout note only once.
	 */
	public function test_adds_https_note_once(): void {
		$this->sut->add_woo_admin_notes();
		$this->sut->add_woo_admin_notes();

		$this->assertCount( 1, Notes::load_data_store()->get_notes_with_name( WooPaymentsSetHttpsForCheckoutNote::NOTE_NAME ) );
	}

	/**
	 * @testdox Does not add the secure checkout note when checkout forces SSL.
	 */
	public function test_does_not_add_https_note_when_checkout_forces_ssl(): void {
		update_option( 'woocommerce_force_ssl_checkout', 'yes' );

		$this->sut->add_woo_admin_notes();

		$this->assertSame( array(), Notes::load_data_store()->get_notes_with_name( WooPaymentsSetHttpsForCheckoutNote::NOTE_NAME ) );
	}

	/**
	 * @testdox Does not add the secure checkout note when the site uses HTTPS.
	 */
	public function test_does_not_add_https_note_when_site_is_https(): void {
		remove_filter( 'pre_option_home', array( $this, 'get_http_home_url' ) );
		add_filter( 'pre_option_home', array( $this, 'get_https_home_url' ) );

		$this->sut->add_woo_admin_notes();

		$this->assertSame( array(), Notes::load_data_store()->get_notes_with_name( WooPaymentsSetHttpsForCheckoutNote::NOTE_NAME ) );
	}

	/**
	 * @testdox Adds no note on an AJAX request.
	 */
	public function test_adds_no_note_on_ajax_request(): void {
		add_filter( 'wp_doing_ajax', '__return_true' );

		$this->sut->add_woo_admin_notes();

		$this->assertSame( array(), Notes::load_data_store()->get_notes_with_name( WooPaymentsSetHttpsForCheckoutNote::NOTE_NAME ) );
	}

	/**
	 * Return a plain HTTP home URL.
	 *
	 * @return string
	 */
	public function get_http_home_url(): string {
		return 'http://example.org';
	}

	/**
	 * Return an HTTPS home URL.
	 *
	 * @return string
	 */
	public function get_https_home_url(): string {
		return 'https://example.org';
	}

	/**
	 * Create the controller.
	 *
	 * @param bool $native_owns_runtime Whether native owns the payments runtime.
	 * @return WooPaymentsAdminNotesController
	 */
	private function create_sut( bool $native_owns_runtime ): WooPaymentsAdminNotesController {
		$sut = new WooPaymentsAdminNotesController();
		$sut->init( new StaticNativeRuntimeArbiter( $native_owns_runtime ), new WooPaymentsSetHttpsForCheckoutNote() );

		return $sut;
	}

	/**
	 * Get the only stored note with a name.
	 *
	 * @param string $name Note name.
	 * @return Note
	 */
	private function get_single_note( string $name ): Note {
		$note_ids = Notes::load_data_store()->get_notes_with_name( $name );
		$this->assertCount( 1, $note_ids, 'Exactly one ' . $name . ' note should be stored.' );
		$note = Notes::get_note( (int) $note_ids[0] );
		$this->assertInstanceOf( Note::class, $note );

		return $note;
	}

	/**
	 * Delete stored notes with a name.
	 *
	 * @param string $name Note name.
	 */
	private function delete_notes( string $name ): void {
		$data_store = Notes::load_data_store();
		foreach ( $data_store->get_notes_with_name( $name ) as $note_id ) {
			$note = Notes::get_note( (int) $note_id );
			if ( $note instanceof Note ) {
				$data_store->delete( $note );
			}
		}
	}
}
