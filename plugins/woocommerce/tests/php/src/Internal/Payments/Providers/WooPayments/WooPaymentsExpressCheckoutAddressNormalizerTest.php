<?php
declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\WooPayments;

use Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsExpressCheckoutAddressNormalizer;
use WC_Unit_Test_Case;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Tests for the WooPayments express checkout address normalizer.
 */
class WooPaymentsExpressCheckoutAddressNormalizerTest extends WC_Unit_Test_Case {

	/**
	 * The System Under Test.
	 *
	 * @var WooPaymentsExpressCheckoutAddressNormalizer
	 */
	private $sut;

	/**
	 * Set up test fixtures.
	 */
	public function setUp(): void {
		parent::setUp();
		$this->sut = new WooPaymentsExpressCheckoutAddressNormalizer();
	}

	/**
	 * Tear down test fixtures.
	 */
	public function tearDown(): void {
		remove_filter( 'rest_post_dispatch', array( $this->sut, 'clear_tokenized_postcode_validation' ), 10 );
		remove_filter( 'woocommerce_validate_postcode', array( $this->sut, 'maybe_skip_postcode_validation' ), 10 );
		parent::tearDown();
	}

	/**
	 * @testdox Should leave generic tokenized Hong Kong addresses unchanged without a valid nonce.
	 * @dataProvider invalid_generic_tokenized_cart_nonce_provider
	 *
	 * @param string|null $tokenized_nonce Generic tokenized cart nonce.
	 */
	public function test_leaves_generic_tokenized_hong_kong_addresses_unchanged_without_valid_nonce( ?string $tokenized_nonce ): void {
		$request = $this->create_tokenized_store_api_request( 'POST', '/wc/store/v1/checkout', $tokenized_nonce );
		$request->set_param(
			'billing_address',
			array(
				'country'  => 'HK',
				'state'    => '',
				'postcode' => '',
				'city'     => 'Tai Po',
			)
		);

		$this->sut->normalize_tokenized_cart_store_api_addresses( $request );

		$billing_address = $request->get_param( 'billing_address' );
		$this->assertSame( '', $billing_address['state'] );
	}

	/**
	 * Data provider for missing or invalid generic tokenized cart nonces.
	 *
	 * @return array<string,array{0:string|null}>
	 */
	public function invalid_generic_tokenized_cart_nonce_provider(): array {
		return array(
			'Missing nonce' => array( null ),
			'Invalid nonce' => array( 'invalid-tokenized-cart-nonce' ),
		);
	}

	/**
	 * @testdox Should normalize tokenized checkout states before Store API validation.
	 */
	public function test_normalizes_tokenized_checkout_states_before_store_api_validation(): void {
		$request = $this->create_tokenized_store_api_request( 'POST', '/wc/store/v1/checkout', wp_create_nonce( 'woopayments_tokenized_cart_nonce' ) );
		$request->set_param(
			'shipping_address',
			array(
				'country' => 'IT',
				'state'   => 'Venezia',
			)
		);

		$this->sut->normalize_tokenized_cart_store_api_addresses( $request );

		$shipping_address = $request->get_param( 'shipping_address' );
		$this->assertSame( 'VE', $shipping_address['state'] );
	}

	/**
	 * @testdox Should match a wallet state exactly by its express checkout code, name or local name before matching a state name inside it ($_dataName).
	 * @dataProvider express_checkout_state_provider
	 *
	 * @param string $country        Country code.
	 * @param string $state          State value the wallet sends.
	 * @param string $expected_state Expected state after normalization.
	 */
	public function test_matches_express_checkout_state_exactly_before_the_substring_match( string $country, string $state, string $expected_state ): void {
		$request = $this->create_tokenized_store_api_request( 'POST', '/wc/store/v1/checkout', wp_create_nonce( 'woopayments_tokenized_cart_nonce' ) );
		$request->set_param(
			'shipping_address',
			array(
				'country' => $country,
				'state'   => $state,
			)
		);

		$this->sut->normalize_tokenized_cart_store_api_addresses( $request );

		$shipping_address = $request->get_param( 'shipping_address' );
		$this->assertSame( $expected_state, $shipping_address['state'] );
	}

	/**
	 * Wallet state values: names that contain another state's name, a wallet code and a local name, which only the
	 * express checkout state list knows, then values the exact pass leaves to the existing checks, entries the list keys
	 * differently from WooCommerce, and names WooCommerce's own list holds exactly.
	 *
	 * @return array<string,array{0:string,1:string,2:string}>
	 */
	public function express_checkout_state_provider(): array {
		return array(
			'West Virginia, not Virginia'              => array( 'US', 'West Virginia', 'WV' ),
			'Baja California Sur, not Baja California' => array( 'MX', 'Baja California Sur', 'BS' ),
			'wallet code B.C.S.'                       => array( 'MX', 'B.C.S.', 'BS' ),
			'local name Nouvelle-Écosse'               => array( 'CA', 'Nouvelle-Écosse', 'NS' ),
			'lower case, without the accent'           => array( 'CA', 'nouvelle-ecosse', 'NS' ),
			'state key already'                        => array( 'US', 'VA', 'VA' ),
			'name only found inside the value'         => array( 'US', 'Commonwealth of Virginia', 'VA' ),
			'unknown name left as sent'                => array( 'US', 'Atlantis', 'Atlantis' ),
			'Chiang Mai, keyed as WooCommerce keys it' => array( 'TH', 'Chiang Mai', 'TH-50' ),
			'Chiang Rai, keyed as WooCommerce keys it' => array( 'TH', 'Chiang Rai', 'TH-57' ),
			'Odisha under its current key'             => array( 'IN', 'Odisha', 'OD' ),
			'Zürich, not Uri found inside it'          => array( 'CH', 'Zürich', 'ZH' ),
			'a full name before a name inside it'      => array( 'IN', 'Dādra and Nagar Haveli and Damān and Diu', 'DH' ),
		);
	}

	/**
	 * @testdox Should recover Hong Kong tokenized checkout regions from district values.
	 * @dataProvider hong_kong_region_recovery_provider
	 *
	 * @param string $field           Address field that carries the region candidate.
	 * @param string $value           Candidate value.
	 * @param string $expected_region Expected normalized Hong Kong region.
	 */
	public function test_recovers_hong_kong_tokenized_checkout_region_from_district( string $field, string $value, string $expected_region ): void {
		$request = $this->create_tokenized_store_api_request( 'POST', '/wc/store/v1/checkout', wp_create_nonce( 'woopayments_tokenized_cart_nonce' ) );
		$address = array(
			'country'  => 'HK',
			'state'    => '',
			'postcode' => '',
			'city'     => '',
		);

		$address[ $field ] = $value;
		$request->set_param(
			'billing_address',
			$address
		);

		$this->sut->normalize_tokenized_cart_store_api_addresses( $request );

		$billing_address = $request->get_param( 'billing_address' );
		$this->assertSame( $expected_region, $billing_address['state'] );
	}

	/**
	 * Data provider for Hong Kong region recovery.
	 *
	 * @return array<string,array{0:string,1:string,2:string}>
	 */
	public function hong_kong_region_recovery_provider(): array {
		return array(
			'Tai Po city'           => array( 'city', 'Tai Po', 'NEW TERRITORIES' ),
			'Happy Valley state'    => array( 'state', 'happy valley', 'HONG KONG' ),
			'Hung Hom state'        => array( 'state', 'hung hom', 'KOWLOON' ),
			'Fanling postcode'      => array( 'postcode', 'fanling', 'NEW TERRITORIES' ),
			'Chinese Kowloon value' => array( 'city', '紅磡', 'KOWLOON' ),
		);
	}

	/**
	 * @testdox Should normalize redacted tokenized update-customer postcodes before Store API validation.
	 */
	public function test_normalizes_tokenized_update_customer_postcodes_before_store_api_validation(): void {
		$request = $this->create_tokenized_store_api_request( 'POST', '/wc/store/v1/cart/update-customer', wp_create_nonce( 'woopayments_tokenized_cart_nonce' ) );
		$request->set_param(
			'shipping_address',
			array(
				'country'  => 'GB',
				'postcode' => 'N1C',
			)
		);

		$this->sut->normalize_tokenized_cart_store_api_addresses( $request );

		$shipping_address = $request->get_param( 'shipping_address' );
		$this->assertSame( 'N1C000', $shipping_address['postcode'] );
		$this->assertTrue( $this->sut->maybe_skip_postcode_validation( false, 'N1C000', 'GB' ) );
		$this->assertTrue( $this->sut->maybe_skip_postcode_validation( false, 'N1C 000', 'GB' ) );
		$this->assertFalse( $this->sut->maybe_skip_postcode_validation( false, 'SW1000', 'GB' ) );
	}

	/**
	 * @testdox Should clear the tokenized postcode validation bypass after Store API dispatch.
	 */
	public function test_clears_tokenized_postcode_validation_after_store_api_dispatch(): void {
		$request = $this->create_tokenized_store_api_request( 'POST', '/wc/store/v1/cart/update-customer', wp_create_nonce( 'woopayments_tokenized_cart_nonce' ) );
		$request->set_param(
			'shipping_address',
			array(
				'country'  => 'GB',
				'postcode' => 'N1C',
			)
		);

		$this->sut->normalize_tokenized_cart_store_api_addresses( $request );

		$this->assertTrue( $this->sut->maybe_skip_postcode_validation( false, 'N1C000', 'GB' ) );

		$this->sut->clear_tokenized_postcode_validation( new WP_REST_Response( array() ), null, $request );

		$this->assertFalse( $this->sut->maybe_skip_postcode_validation( false, 'N1C000', 'GB' ) );
	}

	/**
	 * Create a Store API request with the tokenized cart headers.
	 *
	 * @param string      $method          Request method.
	 * @param string      $route           Request route.
	 * @param string|null $tokenized_nonce Tokenized cart nonce, or null to leave the nonce header out.
	 * @return WP_REST_Request
	 */
	private function create_tokenized_store_api_request( string $method, string $route, ?string $tokenized_nonce ): WP_REST_Request {
		$request = new WP_REST_Request( $method, $route );
		$request->set_header( 'X-WooPayments-Tokenized-Cart', 'true' );

		if ( null !== $tokenized_nonce ) {
			$request->set_header( 'X-WooPayments-Tokenized-Cart-Nonce', $tokenized_nonce );
		}

		return $request;
	}
}
