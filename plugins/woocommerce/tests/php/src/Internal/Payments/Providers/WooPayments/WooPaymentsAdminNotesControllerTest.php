<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments;

use Automattic\WooCommerce\Admin\Notes\Note;
use Automattic\WooCommerce\Admin\Notes\Notes;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\PaymentMethods\WooPaymentsPaymentMethodRegistry;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsAccountService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsAdminNotesController;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsSetHttpsForCheckoutNote;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsSetUpLinkNote;
use Automattic\WooCommerce\Tests\Internal\Payments\StaticNativeRuntimeArbiter;
use WC_Unit_Test_Case;

/**
 * Tests for the WooPaymentsAdminNotesController class and the notes it adds on admin_init.
 *
 * Expectations come from client 11.1.0 `includes/notes/class-wc-payments-notes-set-https-for-checkout.php`,
 * `includes/notes/class-wc-payments-notes-set-up-stripelink.php`, their client unit tests, and the note rows
 * the client wrote on a fresh test-drive store.
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
		$this->delete_notes( WooPaymentsSetUpLinkNote::NOTE_NAME );
		update_option( 'woocommerce_currency', 'USD' );
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
	 * @testdox Adds the Link note with the client's name, copy and action when card is enabled without Link.
	 */
	public function test_adds_link_note_when_card_is_enabled_without_link(): void {
		$sut = $this->create_sut( true, $this->get_link_account_data(), array( 'card' ) );

		$sut->add_woo_admin_notes();

		$note = $this->get_single_note( WooPaymentsSetUpLinkNote::NOTE_NAME );
		$this->assertSame( 'Increase conversion at checkout', $note->get_title() );
		$this->assertSame( 'Reduce cart abandonment and create a frictionless checkout experience with Link by Stripe. Link autofills your customer’s payment and shipping details, so they can check out in just six seconds with the Link optimized experience.', $note->get_content() );
		$this->assertSame( Note::E_WC_ADMIN_NOTE_INFORMATIONAL, $note->get_type() );
		$this->assertSame( 'woocommerce-payments', $note->get_source() );

		$actions = $note->get_actions();
		$this->assertCount( 1, $actions );
		$this->assertSame( 'wc-payments-notes-set-up-stripe-link', $actions[0]->name );
		$this->assertSame( 'Set up now', $actions[0]->label );
		$this->assertSame( 'https://woocommerce.com/document/woopayments/payment-methods/link-by-stripe/', $actions[0]->query );
		$this->assertSame( Note::E_WC_ADMIN_NOTE_UNACTIONED, $actions[0]->status );
	}

	/**
	 * @testdox Adds the Link note when Link is enabled but cannot run at checkout in the store currency.
	 */
	public function test_adds_link_note_when_enabled_link_does_not_support_store_currency(): void {
		update_option( 'woocommerce_currency', 'EUR' );
		$sut = $this->create_sut( true, $this->get_link_account_data(), array( 'card', 'link' ) );

		$sut->add_woo_admin_notes();

		$this->assertCount( 1, Notes::load_data_store()->get_notes_with_name( WooPaymentsSetUpLinkNote::NOTE_NAME ), 'The client treats Link as not enabled at checkout outside its supported currencies.' );
	}

	/**
	 * @testdox Does not add the Link note in the cases the client rules out.
	 * @dataProvider provide_link_note_rejections
	 *
	 * @param array<string,mixed> $account_data Cached account data.
	 * @param string[]            $enabled_ids  Enabled payment method IDs.
	 * @param bool                $filter_link  Whether the available-methods filter removes Link.
	 */
	public function test_does_not_add_link_note_when_client_rules_it_out( array $account_data, array $enabled_ids, bool $filter_link ): void {
		if ( $filter_link ) {
			add_filter(
				'wcpay_upe_available_payment_methods',
				static fn( array $ids ): array => array_values( array_diff( $ids, array( 'link' ) ) )
			);
		}
		$sut = $this->create_sut( true, $account_data, $enabled_ids );

		$sut->add_woo_admin_notes();

		$this->assertSame( array(), Notes::load_data_store()->get_notes_with_name( WooPaymentsSetUpLinkNote::NOTE_NAME ) );
	}

	/**
	 * Cases where the client does not add the Link note.
	 *
	 * @return array<string,array{array<string,mixed>,string[],bool}>
	 */
	public function provide_link_note_rejections(): array {
		$account_data                  = $this->get_link_account_data();
		$without_link_fees             = $account_data;
		$without_link_fees['fees']     = array( 'card' => array( 'base' => 0.1 ) );
		$inactive_card                 = $account_data;
		$inactive_card['capabilities'] = array(
			'card_payments' => 'pending',
			'link_payments' => 'active',
		);

		return array(
			'Link already enabled'       => array( $account_data, array( 'card', 'link' ), false ),
			'card not enabled'           => array( $account_data, array( 'link' ), false ),
			'no Link fees'               => array( $without_link_fees, array( 'card' ), false ),
			'Link filtered out'          => array( $account_data, array( 'card' ), true ),
			'card capability not active' => array( $inactive_card, array( 'card' ), false ),
			'no account data'            => array( array(), array( 'card' ), false ),
		);
	}

	/**
	 * Account data with card and Link fees and active capabilities.
	 *
	 * The fee shape follows client 11.1.0 `tests/unit/payment-methods/test-class-upe-payment-gateway.php:210-214`.
	 *
	 * @return array<string,mixed>
	 */
	private function get_link_account_data(): array {
		return array(
			'country'      => 'US',
			'fees'         => array(
				'card' => array( 'base' => 0.1 ),
				'link' => array( 'base' => 0.1 ),
			),
			'capabilities' => array(
				'card_payments' => 'active',
				'link_payments' => 'active',
			),
		);
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
	 * @param bool                $native_owns_runtime Whether native owns the payments runtime.
	 * @param array<string,mixed> $account_data        Cached account data.
	 * @param string[]            $enabled_ids         Enabled payment method IDs.
	 * @return WooPaymentsAdminNotesController
	 */
	private function create_sut( bool $native_owns_runtime, array $account_data = array(), array $enabled_ids = array( 'card' ) ): WooPaymentsAdminNotesController {
		$account_service = $this->getMockBuilder( WooPaymentsAccountService::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'get_cached_account_data', 'get_gateway_setting', 'get_account_country' ) )
			->getMock();
		$account_service->method( 'get_cached_account_data' )->willReturn( $account_data );
		$account_service->method( 'get_gateway_setting' )->willReturnCallback(
			static fn( string $key ) => 'upe_enabled_payment_method_ids' === $key ? $enabled_ids : null
		);
		$account_service->method( 'get_account_country' )->willReturn( (string) ( $account_data['country'] ?? '' ) );

		$link_note = new WooPaymentsSetUpLinkNote();
		$link_note->init( $account_service, new WooPaymentsPaymentMethodRegistry() );

		$sut = new WooPaymentsAdminNotesController();
		$sut->init( new StaticNativeRuntimeArbiter( $native_owns_runtime ), new WooPaymentsSetHttpsForCheckoutNote(), $link_note );

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
