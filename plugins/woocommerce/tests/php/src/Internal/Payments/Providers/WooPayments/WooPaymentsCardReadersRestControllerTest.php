<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments;

use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api\WooPaymentsApiException;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsAccountService;
use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsCardReadersRestController;
use WC_Helper_Order;
use WC_REST_Unit_Test_Case;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Tests for the WooPaymentsCardReadersRestController class.
 */
class WooPaymentsCardReadersRestControllerTest extends WC_REST_Unit_Test_Case {

	/**
	 * The System Under Test.
	 *
	 * @var WooPaymentsCardReadersRestController
	 */
	private $sut;

	/**
	 * Recording API client.
	 *
	 * @var RecordingTerminalApiClient
	 */
	private RecordingTerminalApiClient $api_client;

	/**
	 * Mocked WooPayments gateway settings.
	 *
	 * @var array<string,mixed>
	 */
	private array $gateway_settings = array();

	/**
	 * Cached account data the account service double answers.
	 *
	 * @var array<string,mixed>
	 */
	private array $account_data = array();

	/**
	 * Number of account-data refreshes requested from the account service.
	 *
	 * @var int
	 */
	private int $account_refresh_calls = 0;

	/**
	 * Set up test fixtures.
	 */
	public function setUp(): void {
		parent::setUp();

		$this->api_client            = new RecordingTerminalApiClient();
		$this->gateway_settings      = array();
		$this->account_data          = array();
		$this->account_refresh_calls = 0;
		$this->sut                   = $this->create_controller();
		wp_set_current_user( $this->factory->user->create( array( 'role' => 'administrator' ) ) );
		delete_transient( 'wcpay_store_terminal_readers' );
	}

	/**
	 * Tear down test fixtures.
	 */
	public function tearDown(): void {
		delete_transient( 'wcpay_store_terminal_readers' );
		wc_get_container()->reset_all_replacements();
		parent::tearDown();
	}

	/**
	 * @testdox Reader registration forwards app-supplied metadata verbatim like the reference client.
	 */
	public function test_register_reader_forwards_metadata_verbatim(): void {
		$request = new WP_REST_Request( 'POST', '/wc/v3/payments/readers' );
		$request->set_param( 'location', 'tml_store' );
		$request->set_param( 'registration_code', 'puppies-plug-could' );
		$request->set_param(
			'metadata',
			array(
				'readerID'    => 'rdr_ABC',
				'pos.session' => 'session-42',
			)
		);

		$this->api_client->terminal_reader_response = array( 'id' => 'tmr_registered' );

		$response = $this->sut->register_reader( $request );

		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$metadata = $this->api_client->last_registered_reader['metadata'];

		$this->assertSame( 'rdr_ABC', $metadata['readerID'] );
		$this->assertSame( 'session-42', $metadata['pos.session'] );
	}

	/**
	 * @testdox Registering a reader refreshes the cached account data like the reference client.
	 */
	public function test_register_reader_refreshes_account_data(): void {
		$this->api_client->terminal_reader_response = array(
			'id'          => 'tmr_registered',
			'livemode'    => false,
			'device_type' => 'bbpos_wisepos_e',
			'label'       => 'Front desk',
			'location'    => 'tml_1',
			'metadata'    => array(),
			'status'      => 'online',
		);

		$request = new WP_REST_Request( 'POST', '/wc/v3/payments/readers' );
		$request->set_param( 'location', 'tml_1' );
		$request->set_param( 'registration_code', 'puppies-plug-could' );

		$response = $this->sut->register_reader( $request );

		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$this->assertSame( 1, $this->account_refresh_calls, 'A successful reader registration must refresh the cached account data.' );
	}

	/**
	 * @testdox A failed reader registration does not refresh the cached account data.
	 */
	public function test_failed_reader_registration_does_not_refresh_account_data(): void {
		$this->api_client->register_reader_exception = new WooPaymentsApiException( 'Bad code.', 'wcpay_invalid_registration_code', 400 );

		$request = new WP_REST_Request( 'POST', '/wc/v3/payments/readers' );
		$request->set_param( 'location', 'tml_1' );
		$request->set_param( 'registration_code', 'bad-code' );

		$response = $this->sut->register_reader( $request );

		$this->assertInstanceOf( WP_Error::class, $response );
		$this->assertSame( 0, $this->account_refresh_calls );
	}

	/**
	 * @testdox Reader listing uses the preserved transient and annotates the active reader.
	 */
	public function test_get_readers_uses_preserved_transient_and_active_status(): void {
		$this->api_client->terminal_readers_response      = array(
			'data' => array(
				array(
					'id'          => 'tmr_active',
					'livemode'    => false,
					'device_type' => 'bbpos_wisepos_e',
					'label'       => 'Counter',
					'location'    => 'tml_store',
					'metadata'    => array(),
					'status'      => 'online',
				),
			),
		);
		$this->api_client->reader_charge_summary_response = array(
			array(
				'reader_id' => 'tmr_active',
				'status'    => 'active',
			),
		);

		$response = $this->sut->get_readers( new WP_REST_Request( 'GET', '/wc/v3/payments/readers' ) );
		$cached   = get_transient( 'wcpay_store_terminal_readers' );

		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$data = $response->get_data();
		$this->assertSame( 'tmr_active', $data[0]['id'] );
		$this->assertTrue( $data[0]['is_active'] );
		$this->assertSame( $data, $cached );
	}

	/**
	 * @testdox An empty cached reader list is treated as a miss and re-fetched, like the reference client.
	 */
	public function test_get_readers_refetches_when_cached_list_is_empty(): void {
		set_transient( 'wcpay_store_terminal_readers', array() );
		$this->api_client->terminal_readers_response = array(
			'data' => array(
				array(
					'id'          => 'tmr_new',
					'livemode'    => false,
					'device_type' => 'bbpos_wisepos_e',
					'label'       => 'Front desk',
					'location'    => 'tml_1',
					'metadata'    => array(),
					'status'      => 'online',
				),
			),
		);

		$response = $this->sut->get_readers( new WP_REST_Request( 'GET', '/wc/v3/payments/readers' ) );

		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$this->assertCount( 1, $response->get_data(), 'A reader registered elsewhere must appear despite the cached empty list.' );
		$this->assertSame( 'tmr_new', $response->get_data()[0]['id'] );
	}

	/**
	 * @testdox Reader charge summary uses the source transaction creation date.
	 */
	public function test_get_reader_charge_summary_uses_transaction_created_date(): void {
		$this->api_client->transaction_response           = array(
			'id'      => 'txn_test',
			'created' => strtotime( '2026-06-01 12:00:00 UTC' ),
		);
		$this->api_client->reader_charge_summary_response = array(
			array(
				'reader_id' => 'tmr_active',
				'status'    => 'active',
			),
		);

		$request = new WP_REST_Request( 'GET', '/wc/v3/payments/readers/charges/txn_test' );
		$request->set_param( 'transaction_id', 'txn_test' );

		$response = $this->sut->get_reader_charge_summary( $request );

		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$this->assertSame( $this->api_client->reader_charge_summary_response, $response->get_data() );
		$this->assertSame(
			array(
				array(
					'charge_date'    => '2026-06-01',
					'transaction_id' => 'txn_test',
				),
			),
			$this->api_client->reader_charge_summary_calls
		);
	}

	/**
	 * @testdox Reader charge summary returns an empty response when the source transaction is missing.
	 */
	public function test_get_reader_charge_summary_returns_empty_when_transaction_is_missing(): void {
		$request = new WP_REST_Request( 'GET', '/wc/v3/payments/readers/charges/txn_missing' );
		$request->set_param( 'transaction_id', 'txn_missing' );

		$response = $this->sut->get_reader_charge_summary( $request );

		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$this->assertSame( array(), $response->get_data() );
		$this->assertSame( array(), $this->api_client->reader_charge_summary_calls );
	}

	/**
	 * @testdox Receipt preview accepts the preserved camelCase settings payload.
	 */
	public function test_preview_print_receipt_uses_preserved_settings_payload(): void {
		$request = new WP_REST_Request( 'POST', '/wc/v3/payments/readers/receipts/preview' );
		$request->set_body_params(
			array(
				'accountBusinessName'           => 'Receipt Lab',
				'accountBusinessSupportAddress' => array(
					'line1'       => '1 Support Way',
					'line2'       => 'Suite 2',
					'city'        => 'San Francisco',
					'state'       => 'CA',
					'postal_code' => '94107',
					'country'     => 'US',
				),
				'accountBusinessSupportPhone'   => '+1 555 0100',
				'accountBusinessSupportEmail'   => 'support@example.com',
			)
		);

		$response = $this->sut->preview_print_receipt( $request );
		$html     = $response->get_data()['html_content'];

		$this->assertStringContainsString( '<!DOCTYPE html>', $html );
		$this->assertStringContainsString( 'Receipt Lab', $html );
		$this->assertStringContainsString( '1 Support Way', $html );
		$this->assertStringContainsString( '+1 555 0100 support@example.com', $html );
		$this->assertStringContainsString( 'Sample', $html );
		$this->assertStringContainsString( 'Application name', $html );
		$this->assertStringContainsString( 'AID', $html );
		$this->assertStringContainsString( 'Powered by WooCommerce', $html );
	}

	/**
	 * @testdox Generated receipts include order lines and terminal receipt fields.
	 */
	public function test_generate_print_receipt_uses_order_and_charge_receipt_data(): void {
		$order = $this->create_order( 12.34, 'USD' );

		$this->account_data                           = $this->get_receipt_account();
		$this->gateway_settings                       = $this->get_saved_business_details();
		$this->api_client->payment_intention_response = array(
			'id'       => 'pi_receipt',
			'status'   => 'succeeded',
			'currency' => 'usd',
			'metadata' => array(
				'order_id' => (string) $order->get_id(),
			),
			'charges'  => array(
				'data' => array(
					array( 'id' => 'ch_receipt' ),
				),
			),
		);
		$this->api_client->charge_response            = array(
			'id'                     => 'ch_receipt',
			'amount_captured'        => 1234,
			'currency'               => 'usd',
			'order'                  => array(
				'number' => $order->get_id(),
			),
			'payment_method_details' => array(
				'card_present' => array(
					'brand'   => 'visa',
					'network' => 'eftpos_au',
					'last4'   => '0978',
					'receipt' => array(
						'application_preferred_name' => 'visa credit',
						'dedicated_file_name'        => 'a0000000031010',
						'account_type'               => 'credit',
					),
				),
			),
		);
		// Override: a second address line, which the recorded account leaves empty.
		$this->account_data['business_profile']['support_address']['line2'] = 'Suite 400';

		$request = new WP_REST_Request( 'GET', '/wc/v3/payments/readers/receipts/pi_receipt' );
		$request->set_param( 'payment_intent_id', 'pi_receipt' );

		$response = $this->sut->generate_print_receipt( $request );
		$html     = $response->get_data()['html_content'];

		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$this->assertStringContainsString( '<!DOCTYPE html>', $html );
		$this->assertStringContainsString( 'Generated Receipt Lab', $html );
		$this->assertSame( array( '123 Support St', 'Suite 400', 'San Francisco CA 94107 US' ), $this->get_receipt_address_lines( $html ), 'Every address component comes from the account.' );
		$this->assertStringContainsString( '+1 555 0200 generated@example.com', $html );
		$this->assertStringNotContainsString( 'Saved Store', $html, 'Business details saved in the settings must not be printed.' );
		$this->assertStringNotContainsString( '9 Saved Road', $html );
		$this->assertStringContainsString( 'Order ' . $order->get_id(), $html );
		$this->assertStringContainsString( 'AMOUNT PAID', $html );
		$this->assertStringContainsString( 'eftpos - 0978', $html );
		$this->assertStringNotContainsString( 'Visa - 0978', $html );
		$this->assertStringContainsString( 'Visa credit', $html );
		$this->assertStringContainsString( 'A0000000031010', $html );
		$this->assertStringContainsString( 'Credit', $html );
	}

	/**
	 * @testdox Generated receipts embed the account branding logo like the reference client.
	 */
	public function test_generate_print_receipt_embeds_account_branding_logo(): void {
		$order = $this->create_order( 12.34, 'USD' );

		$this->account_data                           = $this->get_receipt_account();
		$this->account_data['branding']['logo']       = 'file_logo_123';
		$this->gateway_settings                       = array( 'account_branding_logo' => 'file_saved_logo' );
		$this->api_client->file_contents_response     = array(
			'content_type' => 'image/png',
			'file_content' => base64_encode( 'logo-bytes' ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Test fixture image payload.
		);
		$this->api_client->payment_intention_response = array(
			'id'       => 'pi_receipt',
			'status'   => 'succeeded',
			'currency' => 'usd',
			'metadata' => array(
				'order_id' => (string) $order->get_id(),
			),
			'charges'  => array(
				'data' => array(
					array( 'id' => 'ch_receipt' ),
				),
			),
		);
		$this->api_client->charge_response            = array(
			'id'                     => 'ch_receipt',
			'amount_captured'        => 1234,
			'currency'               => 'usd',
			'order'                  => array(
				'number' => $order->get_id(),
			),
			'payment_method_details' => array(
				'card_present' => array(
					'brand'   => 'visa',
					'last4'   => '0978',
					'receipt' => array(
						'application_preferred_name' => 'visa credit',
						'dedicated_file_name'        => 'a0000000031010',
						'account_type'               => 'credit',
					),
				),
			),
		);

		$request = new WP_REST_Request( 'GET', '/wc/v3/payments/readers/receipts/pi_receipt' );
		$request->set_param( 'payment_intent_id', 'pi_receipt' );

		$response = $this->sut->generate_print_receipt( $request );
		$html     = $response->get_data()['html_content'];

		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$this->assertSame( array( 'file_logo_123', false ), $this->api_client->last_file_contents_request, 'The logo must be fetched platform-side (as_account false) like the reference client.' );
		$this->assertStringContainsString( 'class="branding-logo"', $html );
		$this->assertStringContainsString( 'data:image/png;base64,' . base64_encode( 'logo-bytes' ), $html ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Test fixture image payload.
	}

	/**
	 * @testdox Generated receipts fetch no logo when the account has none, whatever the settings hold.
	 */
	public function test_generate_print_receipt_fetches_no_logo_without_an_account_logo(): void {
		$order                  = $this->create_order( 12.34, 'USD' );
		$this->account_data     = $this->get_receipt_account();
		$this->gateway_settings = array( 'account_branding_logo' => 'file_saved_logo' );
		$this->prepare_receipt_payment( $order );

		$request = new WP_REST_Request( 'GET', '/wc/v3/payments/readers/receipts/pi_receipt' );
		$request->set_param( 'payment_intent_id', 'pi_receipt' );

		$html = $this->sut->generate_print_receipt( $request )->get_data()['html_content'];

		$this->assertSame( array(), $this->api_client->last_file_contents_request, 'No logo file is fetched for an account without a logo.' );
		$this->assertStringNotContainsString( 'class="branding-logo"', $html );
	}

	/**
	 * @testdox Generated receipts print only the business details the account has ($description).
	 * @dataProvider receipt_account_gaps_provider
	 *
	 * @param string              $description   Case.
	 * @param array<string,mixed> $account       Cached account data.
	 * @param string[]            $contains      Strings the receipt shows.
	 * @param string[]            $not_contains  Strings the receipt must not show.
	 * @param string[]|null       $address_lines The three printed address lines, or null when no address block prints.
	 */
	public function test_generate_print_receipt_prints_only_what_the_account_has( string $description, array $account, array $contains, array $not_contains, ?array $address_lines = null ): void {
		unset( $description );
		update_option( 'woocommerce_store_address', '500 Store Avenue' );
		update_option( 'woocommerce_store_address_2', 'Unit 8' );
		update_option( 'woocommerce_store_city', 'Seattle' );
		update_option( 'woocommerce_store_postcode', '98101' );
		$order                  = $this->create_order( 12.34, 'USD' );
		$this->account_data     = $account;
		$this->gateway_settings = $this->get_saved_business_details();
		$this->prepare_receipt_payment( $order );

		$request = new WP_REST_Request( 'GET', '/wc/v3/payments/readers/receipts/pi_receipt' );
		$request->set_param( 'payment_intent_id', 'pi_receipt' );

		$html = $this->sut->generate_print_receipt( $request )->get_data()['html_content'];

		foreach ( $contains as $expected ) {
			$this->assertStringContainsString( $expected, $html );
		}
		foreach ( $not_contains as $unexpected ) {
			$this->assertStringNotContainsString( $unexpected, $html );
		}
		if ( null !== $address_lines ) {
			$this->assertSame( $address_lines, $this->get_receipt_address_lines( $html ) );
		}
	}

	/**
	 * Provide accounts lacking business details: case, account, shown strings, absent strings, address lines.
	 *
	 * Expected values follow client includes/admin/class-wc-rest-payments-reader-controller.php:304-312 and its template
	 * (includes/in-person-payments/templates/html-in-person-payment-receipt.php:119-130): no address block for an empty
	 * address, an empty title and contact line without a profile. A partial address prints only the lines it has, as the
	 * client's template reads each of the six fields (:127-129) and never the store address.
	 *
	 * @return array<string,array{0:string,1:array<string,mixed>,2:string[],3:string[],4?:string[]}>
	 */
	public function receipt_account_gaps_provider(): array {
		$no_address                                        = $this->get_receipt_account();
		$no_address['business_profile']['support_address'] = array();
		// Override: an address with only its first line and city, a synthetic gap.
		$partial_address                                        = $this->get_receipt_account();
		$partial_address['business_profile']['support_address'] = array(
			'line1' => '4 Partial Pier',
			'city'  => 'Portland',
		);

		return array(
			'empty support address'   => array(
				'empty support address',
				$no_address,
				array( '<h1 class="title">Generated Receipt Lab</h1>', '+1 555 0200 generated@example.com' ),
				array( '<div class="store__address">', '500 Store Avenue', '9 Saved Road' ),
			),
			'no business profile'     => array(
				'no business profile',
				array(
					'account_id' => 'acct_receipt',
					'is_live'    => true,
				),
				array( '<h1 class="title"></h1>' ),
				array( '<div class="store__address">', 'Saved Store', get_bloginfo( 'name' ) . '</h1>', get_option( 'admin_email' ) ),
			),
			'partial support address' => array(
				'partial support address',
				$partial_address,
				array( '<h1 class="title">Generated Receipt Lab</h1>' ),
				array( '500 Store Avenue', 'Unit 8', 'Seattle', '98101', '9 Saved Road' ),
				array( '4 Partial Pier', '', 'Portland   ' ),
			),
		);
	}

	/**
	 * @testdox Receipt previews take each business detail the request leaves empty from the account ($description).
	 * @dataProvider preview_account_fallback_provider
	 *
	 * @param string              $description  Case.
	 * @param array<string,mixed> $params       Request body.
	 * @param array<string,mixed> $account       Cached account data.
	 * @param string[]            $contains      Strings the preview shows.
	 * @param string[]            $not_contains  Strings the preview must not show.
	 * @param string[]            $address_lines The three printed address lines.
	 */
	public function test_preview_print_receipt_reads_the_account_where_the_request_gives_nothing( string $description, array $params, array $account, array $contains, array $not_contains, array $address_lines ): void {
		unset( $description );
		update_option( 'woocommerce_store_address', '500 Store Avenue' );
		$this->account_data     = $account;
		$this->gateway_settings = $this->get_saved_business_details();
		$request                = new WP_REST_Request( 'POST', '/wc/v3/payments/readers/receipts/preview' );
		$request->set_body_params( $params );

		$html = $this->sut->preview_print_receipt( $request )->get_data()['html_content'];

		foreach ( $contains as $expected ) {
			$this->assertStringContainsString( $expected, $html );
		}
		foreach ( $not_contains as $unexpected ) {
			$this->assertStringNotContainsString( $unexpected, $html );
		}
		$this->assertSame( $address_lines, $this->get_receipt_address_lines( $html ) );
	}

	/**
	 * Provide preview rows: case, request body, account, shown strings, absent strings, address lines.
	 *
	 * Expected values follow client includes/admin/class-wc-rest-payments-reader-controller.php:347-360: each camelCase
	 * field when not empty, else the account, and always the six address lines (:351-357).
	 *
	 * @return array<string,array{0:string,1:array<string,mixed>,2:array<string,mixed>,3:string[],4:string[],5:string[]}>
	 */
	public function preview_account_fallback_provider(): array {
		$account = $this->get_receipt_account();
		// Override: a second address line, which the recorded account leaves empty.
		$account['business_profile']['support_address']['line2'] = 'Suite 400';
		$account_lines                                     = array( '123 Support St', 'Suite 400', 'San Francisco CA 94107 US' );
		$no_address                                        = $account;
		$no_address['business_profile']['support_address'] = array();
		$from_account                                      = array( '<h1 class="title">Generated Receipt Lab</h1>', '123 Support St', '+1 555 0200 generated@example.com' );
		$not_saved = array( 'Saved Store', '9 Saved Road', 'saved@example.test' );

		return array(
			'nothing in the request'            => array( 'nothing in the request', array(), $account, $from_account, $not_saved, $account_lines ),
			'empty address in the request'      => array( 'empty address in the request', array( 'accountBusinessSupportAddress' => array() ), $account, $from_account, $not_saved, $account_lines ),
			'request fields'                    => array(
				'request fields',
				array(
					'accountBusinessName'           => 'Request Store',
					'accountBusinessSupportAddress' => array(
						'line1'       => '1 Request Way',
						'line2'       => 'Floor 2',
						'city'        => 'Austin',
						'state'       => 'TX',
						'postal_code' => '73301',
						'country'     => 'US',
					),
					'accountBusinessSupportPhone'   => '+1 555 0400',
					'accountBusinessSupportEmail'   => 'request@example.test',
				),
				$account,
				array( '<h1 class="title">Request Store</h1>', '1 Request Way', '+1 555 0400 request@example.test' ),
				array( 'Generated Receipt Lab', '123 Support St', '+1 555 0200', 'generated@example.com' ),
				array( '1 Request Way', 'Floor 2', 'Austin TX 73301 US' ),
			),
			'snake_case fields'                 => array(
				'snake_case fields',
				array(
					'business_name'   => 'Alias Store',
					'support_address' => array( 'line1' => '7 Alias Lane' ),
					'support_phone'   => '+1 555 0300',
					'support_email'   => 'alias@example.test',
				),
				$account,
				$from_account,
				array( 'Alias Store', '7 Alias Lane', '+1 555 0300', 'alias@example.test' ),
				$account_lines,
			),
			'account without a support address' => array( 'account without a support address', array(), $no_address, array( '<div class="store__address">' ), array( '500 Store Avenue', '9 Saved Road' ), array( '', '', '   ' ) ),
		);
	}

	/**
	 * @testdox Print receipts render through the theme-overridable WooCommerce template.
	 */
	public function test_print_receipt_renders_through_the_overridable_template(): void {
		$override = get_temp_dir() . 'wcpay-receipt-override-' . wp_generate_password( 8, false ) . '.php';
		file_put_contents( $override, '<?php echo "THEME-OVERRIDE-RECEIPT"; ?>' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Test fixture file.

		$locate = static function ( $template, $template_name ) use ( $override ) {
			return 'html-in-person-payment-receipt.php' === $template_name ? $override : $template;
		};
		add_filter( 'woocommerce_locate_template', $locate, 10, 2 );

		try {
			$response = $this->sut->preview_print_receipt( new WP_REST_Request( 'POST', '/wc/v3/payments/readers/receipts/preview' ) );
		} finally {
			remove_filter( 'woocommerce_locate_template', $locate, 10 );
			unlink( $override ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Test fixture cleanup.
		}

		$this->assertSame( 'THEME-OVERRIDE-RECEIPT', $response->get_data()['html_content'] );
	}

	/**
	 * @testdox Generated receipts keep the preserved error envelope when the payment intent is invalid.
	 */
	public function test_generate_print_receipt_wraps_invalid_intent_in_preserved_error(): void {
		$this->api_client->payment_intention_response = array(
			'id'     => 'pi_receipt',
			'status' => 'requires_payment_method',
		);

		$request = new WP_REST_Request( 'GET', '/wc/v3/payments/readers/receipts/pi_receipt' );
		$request->set_param( 'payment_intent_id', 'pi_receipt' );

		$response = $this->sut->generate_print_receipt( $request );

		$this->assertInstanceOf( WP_Error::class, $response );
		$this->assertSame( 'generate_print_receipt_error', $response->get_error_code() );
		$this->assertSame( 'Invalid payment intent', $response->get_error_message() );
		$this->assertSame( 500, $response->get_error_data()['status'] );
	}

	/**
	 * Get an account with a business profile, in the shape recorded in Fixtures/platform-test-drive-account.json
	 * `account.business_profile` and `account.branding` (:597-609), with varied values: an address in Stripe's
	 * six-line shape with no second line, and no logo. The recording holds an empty support address; the populated one is
	 * an override with the six fields client includes/admin/class-wc-rest-payments-reader-controller.php:351-357 maps.
	 *
	 * @return array<string,mixed>
	 */
	private function get_receipt_account(): array {
		return array(
			'account_id'       => 'acct_receipt',
			'is_live'          => true,
			'business_profile' => array(
				'name'            => 'Generated Receipt Lab',
				'url'             => 'https://receipts.example.test',
				'support_address' => array(
					'city'        => 'San Francisco',
					'country'     => 'US',
					'line1'       => '123 Support St',
					'line2'       => null,
					'postal_code' => '94107',
					'state'       => 'CA',
				),
				'support_email'   => 'generated@example.com',
				'support_phone'   => '+1 555 0200',
			),
			'branding'         => array(
				'icon'            => null,
				'logo'            => null,
				'primary_color'   => '#873eff',
				'secondary_color' => '#2c045d',
			),
		);
	}

	/**
	 * Get the three lines of a receipt's address block.
	 *
	 * @param string $html Receipt HTML.
	 * @return string[]
	 */
	private function get_receipt_address_lines( string $html ): array {
		$this->assertSame( 1, preg_match( '#<div class="store__address">\s*<p>(.*?)</p>\s*<p>(.*?)</p>\s*<p>(.*?)</p>#s', $html, $matches ), 'The receipt prints an address block.' );

		return array_slice( $matches, 1 );
	}

	/**
	 * Get business details saved in the gateway settings with values the account does not have.
	 *
	 * @return array<string,mixed>
	 */
	private function get_saved_business_details(): array {
		return array(
			'account_business_name'            => 'Saved Store',
			'account_business_support_address' => array(
				'line1'       => '9 Saved Road',
				'line2'       => '',
				'city'        => 'Oakland',
				'state'       => 'CA',
				'postal_code' => '94601',
				'country'     => 'US',
			),
			'account_business_support_phone'   => '+1 555 0999',
			'account_business_support_email'   => 'saved@example.test',
			'account_branding_logo'            => 'file_saved_logo',
		);
	}

	/**
	 * Answer the receipt route's intent and charge reads with a succeeded card-present payment for the order.
	 *
	 * The intent's status and associated charge, then the charge's order number, as client 11.1.0 reads them in
	 * includes/admin/class-wc-rest-payments-reader-controller.php:283-295 (the associated charge is the last entry of
	 * charges.data, client includes/wc-payment-api/class-wc-payments-api-client.php:2403); the charge's amount and
	 * card-present receipt fields, as client
	 * includes/in-person-payments/class-wc-payments-in-person-payments-receipts-service.php:53,138-160 reads them. The
	 * values are synthetic.
	 *
	 * @param \WC_Order $order Order the payment is for.
	 */
	private function prepare_receipt_payment( \WC_Order $order ): void {
		$this->api_client->payment_intention_response = array(
			'id'       => 'pi_receipt',
			'status'   => 'succeeded',
			'currency' => 'usd',
			'metadata' => array(
				'order_id' => (string) $order->get_id(),
			),
			'charges'  => array(
				'data' => array(
					array( 'id' => 'ch_receipt' ),
				),
			),
		);
		$this->api_client->charge_response            = array(
			'id'                     => 'ch_receipt',
			'amount_captured'        => 1234,
			'currency'               => 'usd',
			'order'                  => array(
				'number' => $order->get_id(),
			),
			'payment_method_details' => array(
				'card_present' => array(
					'brand'   => 'visa',
					'last4'   => '0978',
					'receipt' => array(
						'application_preferred_name' => 'visa credit',
						'dedicated_file_name'        => 'a0000000031010',
						'account_type'               => 'credit',
					),
				),
			),
		);
	}

	/**
	 * Create a native mobile REST controller.
	 *
	 * @param bool                                      $test_mode  Whether the account runs in test mode.
	 * @param WooPaymentsCardReadersRestController|null $controller Controller instance to initialize, a new one when null.
	 * @return WooPaymentsCardReadersRestController
	 */
	private function create_controller( bool $test_mode = true, ?WooPaymentsCardReadersRestController $controller = null ): WooPaymentsCardReadersRestController {
		$account_service = $this->getMockBuilder( WooPaymentsAccountService::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'is_test_mode_enabled', 'get_mode', 'get_gateway_setting', 'refresh_account_data', 'get_cached_account_data' ) )
			->getMock();
		$account_service->method( 'is_test_mode_enabled' )->willReturn( $test_mode );
		$account_service->method( 'get_cached_account_data' )->willReturnCallback(
			function (): array {
				return $this->account_data;
			}
		);
		$account_service->method( 'refresh_account_data' )->willReturnCallback(
			function (): array {
				++$this->account_refresh_calls;

				return array();
			}
		);
		$account_service->method( 'get_mode' )->willReturn( $test_mode ? 'test' : 'live' );
		$account_service->method( 'get_gateway_setting' )->willReturnCallback(
			function ( string $key, $fallback = null ) {
				return array_key_exists( $key, $this->gateway_settings ) ? $this->gateway_settings[ $key ] : $fallback;
			}
		);

		$controller = $controller ?? new WooPaymentsCardReadersRestController();
		$controller->init( $this->api_client, $account_service );

		return $controller;
	}

	/**
	 * The mobile routes answer a failure as the extension's controllers do, so the apps read the same status and code.
	 *
	 * Faked failures are WooPaymentsApiException( message, code, status ), the shape the API client builds from a platform
	 * error body and its HTTP status; status 0 is the API client's encode failure (wcpay_client_unable_to_encode_json),
	 * which carries no HTTP status. A status of 0 reaches the app as HTTP 200, because WordPress's status_header( 0 )
	 * sends no status line; the answer's own status is asserted here.
	 *
	 * @testdox Mobile route failure "$scenario" answers status $expected_status with code $expected_code.
	 * @dataProvider provide_mobile_route_failures
	 *
	 * @param string $scenario        Scenario key.
	 * @param int    $expected_status Expected answer status.
	 * @param string $expected_code   Expected error code.
	 */
	public function test_mobile_route_failures_answer_as_the_extension_does( string $scenario, int $expected_status, string $expected_code ): void {
		$encode_failure  = new WooPaymentsApiException( 'Unable to encode the WooPayments request body.', 'wcpay_client_unable_to_encode_json', 0 );
		$missing         = new WooPaymentsApiException( 'No such object.', 'resource_missing', 404 );
		$refused_request = new WooPaymentsApiException( 'Invalid request.', 'invalid_request_error', 400 );
		$order           = $this->create_order( 12.34, 'USD' );
		$request         = new WP_REST_Request( 'POST', '/wc/v3/payments/test' );
		$request->set_param( 'order_id', $order->get_id() );

		switch ( $scenario ) {
			case 'readers, platform 404':
				$this->api_client->method_exceptions['get_terminal_readers'] = $missing;
				$response = $this->sut->get_readers( $request );
				break;
			case 'reader registration, status 0':
				$this->api_client->register_reader_exception = $encode_failure;
				$request->set_param( 'location', 'tml_1' );
				$request->set_param( 'registration_code', 'puppies-plug-could' );
				$response = $this->sut->register_reader( $request );
				break;
			case 'reader charge summary, platform 404':
				$this->api_client->method_exceptions['get_transaction'] = $missing;
				$request->set_param( 'transaction_id', 'txn_1' );
				$response = $this->sut->get_reader_charge_summary( $request );
				break;
			case 'receipt, status 0':
				$this->api_client->payment_intention_exception = $encode_failure;
				$request->set_param( 'payment_intent_id', 'pi_receipt' );
				$response = $this->sut->generate_print_receipt( $request );
				break;
			default:
				$this->fail( "Unknown scenario {$scenario}." );
		}

		$this->assertInstanceOf( WP_Error::class, $response );
		$this->assertSame( $expected_code, $response->get_error_code() );
		$this->assertSame( $expected_status, rest_convert_error_to_response( $response )->get_status() );
	}

	/**
	 * Mobile route failures and the extension's answers (class-wc-rest-payments-orders-controller.php,
	 * class-wc-rest-payments-reader-controller.php, class-wc-rest-payments-terminal-locations-controller.php,
	 * class-wc-rest-payments-connection-tokens-controller.php).
	 *
	 * @return array<string,array{0:string,1:int,2:string}>
	 */
	public function provide_mobile_route_failures(): array {
		$cases = array(
			// A capture that did not succeed answers 502, as the extension's failure branch sets it (class-wc-payment-gateway-wcpay.php:4045-4049).
			array( 'readers, platform 404', 500, 'resource_missing' ),
			array( 'reader registration, status 0', 0, 'wcpay_client_unable_to_encode_json' ),
			array( 'reader charge summary, platform 404', 500, 'wcpay_get_summary' ),
			array( 'receipt, status 0', 0, 'generate_print_receipt_error' ),
		);

		return array_combine( array_column( $cases, 0 ), $cases );
	}

	/**
	 * @testdox Reader registration sends an empty location to the platform, which the extension does not check either.
	 */
	public function test_reader_registration_does_not_refuse_an_empty_location(): void {
		$request = new WP_REST_Request( 'POST', '/wc/v3/payments/readers' );
		$request->set_param( 'location', '' );
		$request->set_param( 'registration_code', 'puppies-plug-could' );

		$this->sut->register_reader( $request );

		$this->assertSame( '', $this->api_client->last_registered_reader['location'] ?? null );
	}

	/**
	 * Create a minimal order for terminal-route tests.
	 *
	 * @param float  $total    Order total.
	 * @param string $currency Order currency.
	 * @return \WC_Order
	 */
	private function create_order( float $total = 10.0, string $currency = 'USD' ): \WC_Order {
		$order = WC_Helper_Order::create_order();
		$order->set_total( $total );
		$order->set_currency( $currency );
		$order->set_status( 'pending' );
		$order->save();

		return $order;
	}
}
