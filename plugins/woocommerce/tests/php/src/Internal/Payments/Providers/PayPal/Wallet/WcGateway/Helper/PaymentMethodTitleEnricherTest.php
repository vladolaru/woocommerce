<?php
/**
 * Tests for the payment method title enricher.
 *
 * @package Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\WcGateway\Helper
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\WcGateway\Helper;

use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\Assets\AssetGetter;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\WcGateway\Gateway\PayPalGateway;
use Automattic\WooCommerce\Internal\Payments\Providers\PayPal\Wallet\WcGateway\Helper\PaymentMethodTitleEnricher;
use Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\WalletTestCase;
use WC_Order;

/**
 * What the enricher appends to a payment method title, over real orders and real filters.
 *
 * A `card` payment source on a PayPal gateway order is card funding in the PayPal button stack, which stays. The IDs of
 * gateways that no longer exist are literals, as their classes are gone.
 *
 * @group paypal-wallet
 */
class PaymentMethodTitleEnricherTest extends WalletTestCase {

	private const OPT_OUT_FILTER  = 'woocommerce_paypal_payments_enrich_payment_method_title';
	private const DETAIL_FILTER   = 'woocommerce_paypal_payments_payment_method_title_detail';
	private const ICON_FILTER     = 'woocommerce_paypal_payments_payment_method_title_icon';
	private const ENRICHED_FILTER = 'woocommerce_paypal_payments_enriched_payment_method_title';

	private const CARD_BUTTON_GATEWAY = 'ppcp-card-button-gateway';
	private const CREDIT_CARD_GATEWAY = 'ppcp-credit-card-gateway';
	private const APPLE_PAY_GATEWAY   = 'ppcp-applepay';
	private const GOOGLE_PAY_GATEWAY  = 'ppcp-googlepay';

	private const ASSET_BASE_URL = 'https://example.com/wp-content/plugins/woocommerce-paypal-payments/modules/ppcp-wc-gateway/assets/';

	/**
	 * The System Under Test.
	 *
	 * @var PaymentMethodTitleEnricher
	 */
	private $sut;

	/**
	 * Build the enricher over an asset getter that returns predictable URLs.
	 */
	public function setUp(): void {
		parent::setUp();

		$asset_getter = $this->mock( AssetGetter::class );
		$asset_getter->shouldReceive( 'get_static_asset_url' )->andReturnUsing(
			static function ( string $asset_name ): string {
				return self::ASSET_BASE_URL . $asset_name;
			}
		);

		$this->sut = new PaymentMethodTitleEnricher( $asset_getter );
	}

	/**
	 * @testdox Should append the payer email for a PayPal order.
	 */
	public function test_appends_payer_email_for_paypal(): void {
		$order = $this->make_order( PayPalGateway::ID, $this->paypal_meta() );

		$this->assertSame( 'PayPal (john@example.com)', $this->sut->enrich( 'PayPal', $order ) );
	}

	/**
	 * @testdox Should leave the title alone for a PayPal order without an email.
	 */
	public function test_paypal_without_email_is_unchanged(): void {
		$order = $this->make_order( PayPalGateway::ID, array( PayPalGateway::ORDER_PAYMENT_SOURCE_META_KEY => 'paypal' ) );

		$this->assertSame( 'PayPal', $this->sut->enrich( 'PayPal', $order ) );
	}

	/**
	 * @testdox Should append the card brand and last digits for card funding on a PayPal order.
	 */
	public function test_appends_card_details_for_card_funding_on_paypal_gateway(): void {
		$order = $this->make_order( PayPalGateway::ID, $this->card_meta( 'VISA', '1234' ) );

		$this->assertSame( 'PayPal (Visa ending in 1234)', $this->sut->enrich( 'PayPal', $order ) );
	}

	/**
	 * @testdox Should normalize the card brand $raw_brand to $expected_label.
	 * @dataProvider brand_provider
	 *
	 * @param string $raw_brand      The brand as PayPal sends it.
	 * @param string $expected_label The label shown.
	 */
	public function test_normalizes_card_brand( string $raw_brand, string $expected_label ): void {
		$order = $this->make_order( PayPalGateway::ID, $this->card_meta( $raw_brand, '0005' ) );

		$this->assertSame( "Card ($expected_label ending in 0005)", $this->sut->enrich( 'Card', $order ) );
	}

	/**
	 * Brand and expected label.
	 *
	 * @return array
	 */
	public function brand_provider(): array {
		return array(
			'visa'             => array( 'VISA', 'Visa' ),
			'mastercard'       => array( 'MASTERCARD', 'Mastercard' ),
			'amex'             => array( 'AMEX', 'American Express' ),
			'american_express' => array( 'AMERICAN_EXPRESS', 'American Express' ),
			'unknown'          => array( 'FOO_BAR', 'Foo bar' ),
		);
	}

	/**
	 * @testdox Should leave the title alone when only the card brand is known.
	 */
	public function test_partial_card_data_is_unchanged(): void {
		$order = $this->make_order(
			PayPalGateway::ID,
			array(
				PayPalGateway::ORDER_PAYMENT_SOURCE_META_KEY => 'card',
				PayPalGateway::ORDER_CARD_BRAND_META_KEY => 'VISA',
			)
		);

		$this->assertSame( 'PayPal', $this->sut->enrich( 'PayPal', $order ) );
	}

	/**
	 * @testdox Should leave the title alone when a card order has no card meta.
	 */
	public function test_missing_card_meta_is_unchanged(): void {
		$order = $this->make_order( PayPalGateway::ID, array( PayPalGateway::ORDER_PAYMENT_SOURCE_META_KEY => 'card' ) );

		$this->assertSame( 'PayPal', $this->sut->enrich( 'PayPal', $order ) );
	}

	/**
	 * @testdox Should leave the title alone for a gateway the enricher does not support.
	 */
	public function test_unsupported_gateway_is_unchanged(): void {
		$order = $this->make_order( self::CARD_BUTTON_GATEWAY, $this->card_meta( 'VISA', '1234' ) );

		$this->assertSame( 'Debit & Credit Cards', $this->sut->enrich( 'Debit & Credit Cards', $order ) );
	}

	/**
	 * The card gateway is not one of the enricher's supported gateways.
	 *
	 * @testdox Should leave the title of a ppcp-credit-card-gateway order unchanged and build no detail for it (card).
	 */
	public function test_credit_card_gateway_order_is_unchanged(): void {
		$calls = $this->spy_filter( self::DETAIL_FILTER, 'Visa ending in 1234' );
		$order = $this->make_order( self::CREDIT_CARD_GATEWAY, $this->card_meta( 'VISA', '1234' ) );

		$this->assertSame( 'Debit & Credit Cards', $this->sut->enrich( 'Debit & Credit Cards', $order ) );
		$this->assertCount( 0, $calls );
	}

	/**
	 * @testdox Should leave the title alone when the opt-out filter disables enrichment.
	 */
	public function test_opt_out_filter_disables_enrichment(): void {
		add_filter( self::OPT_OUT_FILTER, '__return_false' );
		$order = $this->make_order( PayPalGateway::ID, $this->paypal_meta() );

		$this->assertSame( 'PayPal', $this->sut->enrich( 'PayPal', $order ) );
	}

	/**
	 * @testdox Should not append the detail twice.
	 */
	public function test_does_not_append_detail_twice(): void {
		$order            = $this->make_order( PayPalGateway::ID, $this->card_meta( 'VISA', '1234' ) );
		$already_enriched = 'PayPal (Visa ending in 1234)';

		$this->assertSame( $already_enriched, $this->sut->enrich( $already_enriched, $order ) );
	}

	/**
	 * @testdox Should pass the built detail and the order to the detail filter and append what it returns.
	 */
	public function test_detail_filter_receives_built_detail_and_appends_filtered_value(): void {
		$order = $this->make_order( PayPalGateway::ID, $this->paypal_meta() );
		$calls = $this->spy_filter( self::DETAIL_FILTER, 'Verified: john@example.com' );

		$this->assertSame( 'PayPal (Verified: john@example.com)', $this->sut->enrich( 'PayPal', $order ) );
		$this->assertCount( 1, $calls );
		$this->assertSame( 'john@example.com', $calls[0][0] );
		$this->assertSame( $order, $calls[0][1] );
	}

	/**
	 * @testdox Should leave the title alone when the detail filter returns an empty string.
	 */
	public function test_empty_detail_filter_return_value_suppresses_append(): void {
		$order = $this->make_order( PayPalGateway::ID, $this->paypal_meta() );
		$calls = $this->spy_filter( self::DETAIL_FILTER, '' );

		$this->assertSame( 'PayPal', $this->sut->enrich( 'PayPal', $order ) );
		$this->assertCount( 1, $calls );
		$this->assertSame( 'john@example.com', $calls[0][0] );
	}

	/**
	 * @testdox Should let the detail filter supply a detail when the payment source has none.
	 */
	public function test_detail_filter_can_supply_detail_when_source_has_none(): void {
		$order = $this->make_order( PayPalGateway::ID, array( PayPalGateway::ORDER_PAYMENT_SOURCE_META_KEY => 'venmo' ) );
		$calls = $this->spy_filter( self::DETAIL_FILTER, '@johndoe' );

		$this->assertSame( 'PayPal (@johndoe)', $this->sut->enrich( 'PayPal', $order ) );
		$this->assertSame( '', $calls[0][0] );
		$this->assertSame( $order, $calls[0][1] );
	}

	/**
	 * @testdox Should let the detail filter supply a detail for partial card data.
	 */
	public function test_detail_filter_can_supply_detail_for_partial_card_data(): void {
		$order = $this->make_order(
			PayPalGateway::ID,
			array(
				PayPalGateway::ORDER_PAYMENT_SOURCE_META_KEY => 'card',
				PayPalGateway::ORDER_CARD_BRAND_META_KEY => 'VISA',
			)
		);
		$calls = $this->spy_filter( self::DETAIL_FILTER, 'Card on file' );

		$this->assertSame( 'PayPal (Card on file)', $this->sut->enrich( 'PayPal', $order ) );
		$this->assertSame( '', $calls[0][0] );
	}

	/**
	 * @testdox Should not fire the detail filter for an unsupported gateway.
	 */
	public function test_detail_filter_never_fires_for_unsupported_gateway(): void {
		$order = $this->make_order( self::CARD_BUTTON_GATEWAY, $this->card_meta( 'VISA', '1234' ) );
		$calls = $this->spy_filter( self::DETAIL_FILTER );

		$this->assertSame( 'Debit & Credit Cards', $this->sut->enrich( 'Debit & Credit Cards', $order ) );
		$this->assertCount( 0, $calls );
	}

	/**
	 * @testdox Should not fire the detail filter when enrichment is opted out.
	 */
	public function test_detail_filter_never_fires_when_opted_out(): void {
		$order   = $this->make_order( PayPalGateway::ID, $this->paypal_meta() );
		$opt_out = $this->spy_filter( self::OPT_OUT_FILTER, false );
		$detail  = $this->spy_filter( self::DETAIL_FILTER );

		$this->assertSame( 'PayPal', $this->sut->enrich( 'PayPal', $order ) );
		$this->assertCount( 1, $opt_out );
		$this->assertTrue( $opt_out[0][0] );
		$this->assertSame( $order, $opt_out[0][1] );
		$this->assertCount( 0, $detail );
	}

	/**
	 * @testdox Should compare the duplicate-append guard against the filtered detail.
	 */
	public function test_dedupe_guard_compares_against_filtered_detail(): void {
		$order            = $this->make_order( PayPalGateway::ID, $this->card_meta( 'VISA', '1234' ) );
		$already_enriched = 'PayPal (Visa •••• 1234)';
		$calls            = $this->spy_filter( self::DETAIL_FILTER, 'Visa •••• 1234' );

		$this->assertSame( $already_enriched, $this->sut->enrich( $already_enriched, $order ) );
		$this->assertCount( 1, $calls );
	}

	/**
	 * @testdox Should append a filtered detail that differs from what the title already contains.
	 */
	public function test_filtered_detail_is_appended_when_it_differs_from_title_content(): void {
		$order = $this->make_order( PayPalGateway::ID, $this->paypal_meta() );
		$this->spy_filter( self::DETAIL_FILTER, 'Verified' );

		$this->assertSame( 'PayPal (john@example.com) (Verified)', $this->sut->enrich( 'PayPal (john@example.com)', $order ) );
	}

	/**
	 * @testdox Should cast the detail filter's return value to a string.
	 * @dataProvider detail_filter_cast_provider
	 *
	 * @param mixed  $filtered_detail What the filter returns.
	 * @param string $expected_title  The title that results.
	 */
	public function test_detail_filter_return_value_is_cast_to_string( $filtered_detail, string $expected_title ): void {
		$order = $this->make_order( PayPalGateway::ID, $this->paypal_meta() );
		$this->spy_filter( self::DETAIL_FILTER, $filtered_detail );

		$this->assertSame( $expected_title, $this->sut->enrich( 'PayPal', $order ) );
	}

	/**
	 * Filter return values and the resulting title.
	 *
	 * @return array
	 */
	public function detail_filter_cast_provider(): array {
		return array(
			'integer detail is cast to its string representation'   => array( 12345, 'PayPal (12345)' ),
			'null detail casts to an empty string, title unchanged' => array( null, 'PayPal' ),
			'false detail casts to an empty string, title unchanged' => array( false, 'PayPal' ),
		);
	}

	/**
	 * @testdox Should use the enriched-title filter's return value verbatim.
	 */
	public function test_enriched_title_filter_return_value_is_used_verbatim(): void {
		$order = $this->make_order( PayPalGateway::ID, $this->paypal_meta() );
		$this->spy_filter( self::ENRICHED_FILTER, 'PayPal — john@example.com' );

		$this->assertSame( 'PayPal — john@example.com', $this->sut->enrich( 'PayPal', $order ) );
	}

	/**
	 * @testdox Should pass the assembled title, original title, detail and order to the enriched-title filter.
	 */
	public function test_enriched_title_filter_receives_assembled_title_original_title_detail_and_order(): void {
		$order = $this->make_order( PayPalGateway::ID, $this->paypal_meta() );
		$calls = $this->spy_filter( self::ENRICHED_FILTER );

		$this->assertSame( 'PayPal (john@example.com)', $this->sut->enrich( 'PayPal', $order ) );
		$this->assertCount( 1, $calls );
		$this->assertSame( 'PayPal (john@example.com)', $calls[0][0] );
		$this->assertSame( 'PayPal', $calls[0][1] );
		$this->assertSame( 'john@example.com', $calls[0][2] );
		$this->assertSame( $order, $calls[0][3] );
	}

	/**
	 * @testdox Should not fire the enriched-title filter when enrichment is opted out.
	 */
	public function test_enriched_title_filter_never_fires_when_opted_out(): void {
		$order    = $this->make_order( PayPalGateway::ID, $this->paypal_meta() );
		$enriched = $this->spy_filter( self::ENRICHED_FILTER );
		$this->spy_filter( self::OPT_OUT_FILTER, false );

		$this->assertSame( 'PayPal', $this->sut->enrich( 'PayPal', $order ) );
		$this->assertCount( 0, $enriched );
	}

	/**
	 * @testdox Should not fire the enriched-title filter when no detail is appended: $scenario.
	 * @dataProvider enriched_title_never_fires_provider
	 *
	 * @param string $scenario The scenario name.
	 * @param string $gateway  The order's payment method.
	 * @param array  $meta     The order meta.
	 * @param string $title    The incoming title.
	 */
	public function test_enriched_title_filter_never_fires_on_early_return( string $scenario, string $gateway, array $meta, string $title ): void {
		unset( $scenario );
		$order = $this->make_order( $gateway, $meta );
		$calls = $this->spy_filter( self::ENRICHED_FILTER );

		$this->assertSame( $title, $this->sut->enrich( $title, $order ) );
		$this->assertCount( 0, $calls );
	}

	/**
	 * Early-return scenarios.
	 *
	 * @return array
	 */
	public function enriched_title_never_fires_provider(): array {
		return array(
			'unsupported gateway'                     => array(
				'unsupported gateway',
				self::CARD_BUTTON_GATEWAY,
				$this->card_meta( 'VISA', '1234' ),
				'Debit & Credit Cards',
			),
			'card order with no card meta'            => array(
				'card order with no card meta',
				PayPalGateway::ID,
				array( PayPalGateway::ORDER_PAYMENT_SOURCE_META_KEY => 'card' ),
				'PayPal',
			),
			'title already contains the built detail' => array(
				'title already contains the built detail',
				PayPalGateway::ID,
				$this->card_meta( 'VISA', '1234' ),
				'PayPal (Visa ending in 1234)',
			),
		);
	}

	/**
	 * @testdox Should return an empty title when the enriched-title filter returns an empty string.
	 */
	public function test_enriched_title_filter_returning_empty_string_yields_empty_string(): void {
		$order = $this->make_order( PayPalGateway::ID, $this->paypal_meta() );
		$this->spy_filter( self::ENRICHED_FILTER, '' );

		$this->assertSame( '', $this->sut->enrich( 'PayPal', $order ) );
	}

	/**
	 * @testdox Should build the enriched title from the filtered detail.
	 */
	public function test_enriched_title_filter_receives_title_built_from_filtered_detail(): void {
		$order = $this->make_order( PayPalGateway::ID, $this->paypal_meta() );
		$this->spy_filter( self::DETAIL_FILTER, 'B' );
		$calls = $this->spy_filter( self::ENRICHED_FILTER );

		$this->assertSame( 'PayPal (B)', $this->sut->enrich( 'PayPal', $order ) );
		$this->assertSame( array( 'PayPal (B)', 'PayPal', 'B' ), array_slice( $calls[0], 0, 3 ) );
	}

	/**
	 * @testdox Should resolve the bundled icon for the card brand $brand.
	 * @dataProvider mapped_card_brand_provider
	 *
	 * @param string $brand         The brand.
	 * @param string $expected_file The icon file name without extension.
	 */
	public function test_get_icon_url_resolves_mapped_card_brand( string $brand, string $expected_file ): void {
		$this->assertSame( $this->icon_url( $expected_file ), $this->sut->get_icon_url( 'card', $brand ) );
	}

	/**
	 * Mapped brands and their icon files.
	 *
	 * @return array
	 */
	public function mapped_card_brand_provider(): array {
		return array(
			'visa'                                     => array( 'VISA', 'visa' ),
			'lower-case visa normalizes to upper case' => array( 'visa', 'visa' ),
			'mastercard'                               => array( 'MASTERCARD', 'mastercard' ),
			'amex'                                     => array( 'AMEX', 'amex' ),
			'american_express aliases to amex'         => array( 'AMERICAN_EXPRESS', 'amex' ),
			'discover'                                 => array( 'DISCOVER', 'discover' ),
			'jcb'                                      => array( 'JCB', 'jcb' ),
			'elo'                                      => array( 'ELO', 'elo' ),
			'hiper'                                    => array( 'HIPER', 'hiper' ),
		);
	}

	/**
	 * @testdox Should return an empty icon URL for the card brand "$brand" that has no bundled icon.
	 * @dataProvider unmapped_card_brand_provider
	 *
	 * @param string $brand The brand.
	 */
	public function test_get_icon_url_returns_empty_string_for_unmapped_card_brand( string $brand ): void {
		$this->assertSame( '', $this->sut->get_icon_url( 'card', $brand ) );
	}

	/**
	 * Brands without a bundled icon.
	 *
	 * @return array
	 */
	public function unmapped_card_brand_provider(): array {
		return array(
			'diners'        => array( 'DINERS' ),
			'maestro'       => array( 'MAESTRO' ),
			'solo'          => array( 'SOLO' ),
			'switch'        => array( 'SWITCH' ),
			'unionpay'      => array( 'UNIONPAY' ),
			'unknown brand' => array( 'FOO_BAR' ),
			'empty brand'   => array( '' ),
		);
	}

	/**
	 * @testdox Should resolve a source with its own logo to that logo and an unsupported source to nothing.
	 */
	public function test_get_icon_url_resolves_sources_with_their_own_logo(): void {
		$this->assertSame( $this->icon_url( 'paypal' ), $this->sut->get_icon_url( 'paypal', '' ) );
		$this->assertSame( $this->icon_url( 'venmo' ), $this->sut->get_icon_url( 'venmo', '' ) );
		$this->assertSame( '', $this->sut->get_icon_url( 'bancontact', '' ) );
	}

	/**
	 * @testdox Should prefer the source's own icon over the card brand.
	 */
	public function test_get_icon_url_source_map_wins_over_card_brand(): void {
		$this->assertSame( $this->icon_url( 'paypal' ), $this->sut->get_icon_url( 'paypal', 'VISA' ) );
	}

	/**
	 * @testdox Should enrich exactly as before when no callback is registered on the icon filter.
	 */
	public function test_enrich_is_unchanged_when_no_icon_filter_callback_is_registered(): void {
		$order = $this->make_order( PayPalGateway::ID, $this->paypal_meta() );

		$this->assertSame( 'PayPal (john@example.com)', $this->sut->enrich( 'PayPal', $order ) );
	}

	/**
	 * @testdox Should prepend the icon filter's markup to the detail with a single space.
	 */
	public function test_icon_filter_markup_is_prepended_to_detail(): void {
		$order = $this->make_order( PayPalGateway::ID, $this->paypal_meta() );
		$this->spy_filter( self::ICON_FILTER, '<img src="x.svg">' );

		$this->assertSame( 'PayPal (<img src="x.svg"> john@example.com)', $this->sut->enrich( 'PayPal', $order ) );
	}

	/**
	 * @testdox Should pass the icon URL, source, empty brand and order to the icon filter for a PayPal order.
	 */
	public function test_icon_filter_receives_all_arguments_for_paypal_order(): void {
		$order = $this->make_order( PayPalGateway::ID, $this->paypal_meta() );
		$calls = $this->spy_filter( self::ICON_FILTER, '' );

		$this->assertSame( 'PayPal (john@example.com)', $this->sut->enrich( 'PayPal', $order ) );
		$this->assertCount( 1, $calls );
		$this->assertSame( array( '', $this->icon_url( 'paypal' ), 'paypal', '' ), array_slice( $calls[0], 0, 4 ) );
		$this->assertSame( $order, $calls[0][4] );
	}

	/**
	 * @testdox Should pass the icon URL, card source, brand and order to the icon filter for card funding.
	 */
	public function test_icon_filter_receives_all_arguments_for_card_funding_order(): void {
		$order = $this->make_order( PayPalGateway::ID, $this->card_meta( 'VISA', '1234' ) );
		$calls = $this->spy_filter( self::ICON_FILTER, '' );

		$this->assertSame( 'PayPal (Visa ending in 1234)', $this->sut->enrich( 'PayPal', $order ) );
		$this->assertSame( array( '', $this->icon_url( 'visa' ), 'card', 'VISA' ), array_slice( $calls[0], 0, 4 ) );
		$this->assertSame( $order, $calls[0][4] );
	}

	/**
	 * @testdox Should pass the raw brand and an empty icon URL to the icon filter when the brand has no icon.
	 */
	public function test_icon_filter_receives_empty_url_for_unmapped_brand(): void {
		$order = $this->make_order( PayPalGateway::ID, $this->card_meta( 'MAESTRO', '1234' ) );
		$calls = $this->spy_filter( self::ICON_FILTER, '' );

		$this->assertSame( 'PayPal (Maestro ending in 1234)', $this->sut->enrich( 'PayPal', $order ) );
		$this->assertSame( array( '', '', 'card', 'MAESTRO' ), array_slice( $calls[0], 0, 4 ) );
	}

	/**
	 * @testdox Should leave the detail unprefixed when the icon filter returns an empty string.
	 */
	public function test_icon_filter_returning_empty_string_leaves_detail_unprefixed(): void {
		$order = $this->make_order( PayPalGateway::ID, $this->paypal_meta() );
		$this->spy_filter( self::ICON_FILTER, '' );

		$this->assertSame( 'PayPal (john@example.com)', $this->sut->enrich( 'PayPal', $order ) );
	}

	/**
	 * @testdox Should not fire the icon filter when enrichment is opted out.
	 */
	public function test_icon_filter_never_fires_when_opted_out(): void {
		$order = $this->make_order( PayPalGateway::ID, $this->paypal_meta() );
		$this->spy_filter( self::OPT_OUT_FILTER, false );
		$icon = $this->spy_filter( self::ICON_FILTER );

		$this->assertSame( 'PayPal', $this->sut->enrich( 'PayPal', $order ) );
		$this->assertCount( 0, $icon );
	}

	/**
	 * @testdox Should not fire the icon filter for an unsupported gateway.
	 */
	public function test_icon_filter_never_fires_for_unsupported_gateway(): void {
		$order = $this->make_order( self::CARD_BUTTON_GATEWAY, $this->card_meta( 'VISA', '1234' ) );
		$icon  = $this->spy_filter( self::ICON_FILTER );

		$this->assertSame( 'Debit & Credit Cards', $this->sut->enrich( 'Debit & Credit Cards', $order ) );
		$this->assertCount( 0, $icon );
	}

	/**
	 * @testdox Should not fire the icon filter when there is no detail to prefix.
	 */
	public function test_icon_filter_never_fires_when_detail_is_empty(): void {
		$order = $this->make_order( PayPalGateway::ID, array( PayPalGateway::ORDER_PAYMENT_SOURCE_META_KEY => 'card' ) );
		$icon  = $this->spy_filter( self::ICON_FILTER );

		$this->assertSame( 'PayPal', $this->sut->enrich( 'PayPal', $order ) );
		$this->assertCount( 0, $icon );
	}

	/**
	 * @testdox Should short-circuit on a duplicate detail before the icon filter fires.
	 */
	public function test_icon_filter_never_fires_on_dedupe_hit(): void {
		$order            = $this->make_order( PayPalGateway::ID, $this->card_meta( 'VISA', '1234' ) );
		$icon             = $this->spy_filter( self::ICON_FILTER );
		$already_enriched = 'PayPal (Visa ending in 1234)';

		$this->assertSame( $already_enriched, $this->sut->enrich( $already_enriched, $order ) );
		$this->assertCount( 0, $icon );
	}

	/**
	 * @testdox Should not prepend the icon twice when a title is enriched again.
	 */
	public function test_no_double_prepend_on_re_enrichment_with_icon(): void {
		$order            = $this->make_order( PayPalGateway::ID, $this->card_meta( 'VISA', '1234' ) );
		$icon_markup      = '<img src="' . $this->icon_url( 'visa' ) . '">';
		$already_enriched = "PayPal ($icon_markup Visa ending in 1234)";
		$icon             = $this->spy_filter( self::ICON_FILTER, $icon_markup );

		$this->assertSame( $already_enriched, $this->sut->enrich( $already_enriched, $order ) );
		$this->assertCount( 0, $icon );
	}

	/**
	 * @testdox Should hand the enriched-title filter the icon-prefixed detail.
	 */
	public function test_enriched_title_filter_receives_icon_prefixed_detail(): void {
		$order = $this->make_order( PayPalGateway::ID, $this->paypal_meta() );
		$this->spy_filter( self::DETAIL_FILTER, 'B' );
		$this->spy_filter( self::ICON_FILTER, '<img src="i.svg">' );
		$calls = $this->spy_filter( self::ENRICHED_FILTER );

		$this->assertSame( 'PayPal (<img src="i.svg"> B)', $this->sut->enrich( 'PayPal', $order ) );
		$this->assertSame( array( 'PayPal (<img src="i.svg"> B)', 'PayPal', '<img src="i.svg"> B' ), array_slice( $calls[0], 0, 3 ) );
		$this->assertSame( $order, $calls[0][3] );
	}

	/**
	 * @testdox Should cast the icon filter's return value to a string.
	 * @dataProvider icon_filter_cast_provider
	 *
	 * @param mixed  $filtered_icon  What the filter returns.
	 * @param string $expected_title The title that results.
	 */
	public function test_icon_filter_return_value_is_cast_to_string( $filtered_icon, string $expected_title ): void {
		$order = $this->make_order( PayPalGateway::ID, $this->paypal_meta() );
		$this->spy_filter( self::ICON_FILTER, $filtered_icon );

		$this->assertSame( $expected_title, $this->sut->enrich( 'PayPal', $order ) );
	}

	/**
	 * Filter return values and the resulting title.
	 *
	 * @return array
	 */
	public function icon_filter_cast_provider(): array {
		return array(
			'integer icon markup is cast to its string representation'    => array( 12345, 'PayPal (12345 john@example.com)' ),
			'null icon casts to an empty string, detail stays unprefixed'  => array( null, 'PayPal (john@example.com)' ),
			'false icon casts to an empty string, detail stays unprefixed' => array( false, 'PayPal (john@example.com)' ),
		);
	}

	/**
	 * The enricher supports neither Apple Pay nor Google Pay: no gateway ID and no payment source.
	 *
	 * @testdox Should leave the title of a $gateway order unchanged and build no detail for it.
	 * @dataProvider dropped_wallet_gateway_provider
	 *
	 * @param string $gateway The gateway ID.
	 */
	public function test_dropped_wallet_gateway_order_is_unchanged( string $gateway ): void {
		$calls = $this->spy_filter( self::DETAIL_FILTER, 'Mastercard ending in 5678' );
		$order = $this->make_order( $gateway, $this->card_meta( 'MASTERCARD', '5678', 'apple_pay' ) );

		$this->assertSame( 'Wallet', $this->sut->enrich( 'Wallet', $order ) );
		$this->assertCount( 0, $calls );
	}

	/**
	 * @return array<string, array{0: string}>
	 */
	public function dropped_wallet_gateway_provider(): array {
		return array(
			'ppcp-applepay'  => array( self::APPLE_PAY_GATEWAY ),
			'ppcp-googlepay' => array( self::GOOGLE_PAY_GATEWAY ),
		);
	}

	/**
	 * @testdox Should append no card details for a wallet payment source on a PayPal order: $source.
	 * @dataProvider dropped_wallet_source_provider
	 *
	 * @param string $source The payment source name.
	 */
	public function test_wallet_payment_source_on_paypal_gateway_adds_no_card_details( string $source ): void {
		$order = $this->make_order( PayPalGateway::ID, $this->card_meta( 'MASTERCARD', '5678', $source ) );

		$this->assertSame( 'PayPal', $this->sut->enrich( 'PayPal', $order ) );
	}

	/**
	 * @return array<string, array{0: string}>
	 */
	public function dropped_wallet_source_provider(): array {
		return array(
			'apple_pay'  => array( 'apple_pay' ),
			'google_pay' => array( 'google_pay' ),
		);
	}

	/**
	 * Meta of a PayPal order with a payer email.
	 *
	 * @return array<string, string>
	 */
	private function paypal_meta(): array {
		return array(
			PayPalGateway::ORDER_PAYMENT_SOURCE_META_KEY => 'paypal',
			PayPalGateway::ORDER_PAYER_EMAIL_META_KEY    => 'john@example.com',
		);
	}

	/**
	 * Meta of an order paid with a card source.
	 *
	 * @param string $brand       The raw brand.
	 * @param string $last_digits The last four digits.
	 * @param string $source      The payment source.
	 * @return array<string, string>
	 */
	private function card_meta( string $brand, string $last_digits, string $source = 'card' ): array {
		return array(
			PayPalGateway::ORDER_PAYMENT_SOURCE_META_KEY   => $source,
			PayPalGateway::ORDER_CARD_BRAND_META_KEY       => $brand,
			PayPalGateway::ORDER_CARD_LAST_DIGITS_META_KEY => $last_digits,
		);
	}

	/**
	 * The expected URL of a bundled icon, matching the asset getter double of setUp().
	 *
	 * @param string $file The icon file name without extension.
	 * @return string
	 */
	private function icon_url( string $file ): string {
		return self::ASSET_BASE_URL . "images/$file.svg";
	}

	/**
	 * An unsaved order with the given payment method and meta.
	 *
	 * @param string                $gateway The order's payment method ID.
	 * @param array<string, string> $meta    Meta key to value.
	 * @return WC_Order
	 */
	private function make_order( string $gateway, array $meta ): WC_Order {
		$order = new WC_Order();
		$order->set_payment_method( $gateway );
		foreach ( $meta as $key => $value ) {
			$order->update_meta_data( $key, $value );
		}

		return $order;
	}
}
