<?php
/**
 * Local checkout fixture driver for the A4 checkout browser gate.
 *
 * This runs inside a local WordPress store via WP-CLI. It ensures the shared
 * checkout/product fixture exists and prints route overrides that work with
 * plain permalinks and non-empty cart setup.
 *
 * @package WooCommerce\Tools\WooPaymentsMerge
 */

$role = (string) ( $args[0] ?? '' );

if ( ! in_array( $role, array( 'target', 'reference' ), true ) ) {
	WP_CLI::error( 'Usage: wp eval-file a4-checkout-fixture-state.php <target|reference>' );
}

if ( ! class_exists( 'WC_Product_Simple' ) || ! function_exists( 'wc_get_product_id_by_sku' ) ) {
	WP_CLI::error( 'WooCommerce product APIs are unavailable.' );
}

$product_slug = 'h20-ece-probe-product';
$product_sku  = 'h20-ece-probe-product';
$product_id   = wc_get_product_id_by_sku( $product_sku );
$product      = $product_id > 0 ? wc_get_product( $product_id ) : false;

if ( ! $product instanceof WC_Product_Simple ) {
	$existing = get_page_by_path( $product_slug, OBJECT, 'product' );
	$product  = $existing instanceof WP_Post ? wc_get_product( $existing->ID ) : false;
}

if ( ! $product instanceof WC_Product_Simple ) {
	$product = new WC_Product_Simple();
}

$product->set_name( 'H20 ECE Probe Product' );
$product->set_slug( $product_slug );
$product->set_sku( $product_sku );
$product->set_status( 'publish' );
$product->set_catalog_visibility( 'visible' );
$product->set_regular_price( '12.00' );
$product->set_price( '12.00' );
$product->set_virtual( true );
$product->set_tax_status( 'none' );
$product->set_manage_stock( false );
$product->set_stock_status( 'instock' );
$product_id = $product->save();

$checkout_id  = function_exists( 'wc_get_page_id' ) ? (int) wc_get_page_id( 'checkout' ) : 0;
$cart_id      = function_exists( 'wc_get_page_id' ) ? (int) wc_get_page_id( 'cart' ) : 0;
$myaccount_id = function_exists( 'wc_get_page_id' ) ? (int) wc_get_page_id( 'myaccount' ) : 0;

$classic_slug = 'target' === $role ? 'codex-classic-checkout' : 'codex-reference-classic-checkout';
$classic_page = get_page_by_path( $classic_slug, OBJECT, 'page' );
if ( ! $classic_page instanceof WP_Post ) {
	$classic_id = wp_insert_post(
		array(
			'post_title'   => 'reference' === $role ? 'Codex Reference Classic Checkout' : 'Codex Classic Checkout',
			'post_name'    => $classic_slug,
			'post_status'  => 'publish',
			'post_type'    => 'page',
			'post_content' => '[woocommerce_checkout]',
		),
		true
	);
	if ( is_wp_error( $classic_id ) ) {
		WP_CLI::error( 'Could not create classic checkout fixture page: ' . $classic_id->get_error_message() );
	}
} else {
	$classic_id = (int) $classic_page->ID;
}

if ( $checkout_id <= 0 || $cart_id <= 0 || $myaccount_id <= 0 || $product_id <= 0 || $classic_id <= 0 ) {
	WP_CLI::error( 'Checkout fixture prerequisites are incomplete.' );
}

/**
 * Build a plain-permalink page route.
 *
 * @param int                 $page_id Page ID.
 * @param array<string,mixed> $query   Extra query args.
 * @return string
 */
function a4_checkout_fixture_page_route( int $page_id, array $query ): string {
	return '/?' . http_build_query( array_merge( array( 'page_id' => $page_id ), $query ), '', '&', PHP_QUERY_RFC3986 );
}

$cart_query = array(
	'add-to-cart' => $product_id,
	'quantity'    => 1,
);

$routes = array(
	'blocksCheckoutCard'      => a4_checkout_fixture_page_route( $checkout_id, array_merge( $cart_query, array( 'a4aq' => 'blocks-card' ) ) ),
	'blocksCheckoutExpress'   => a4_checkout_fixture_page_route( $checkout_id, array_merge( $cart_query, array( 'a4aq' => 'blocks-express' ) ) ),
	'blocksCartExpress'       => a4_checkout_fixture_page_route( $cart_id, array_merge( $cart_query, array( 'a4aq' => 'blocks-cart-express' ) ) ),
	'classicCheckoutCard'     => a4_checkout_fixture_page_route( $classic_id, array_merge( $cart_query, array( 'a4aq' => 'classic-card' ) ) ),
	'classicAddPaymentMethod' => a4_checkout_fixture_page_route( $myaccount_id, array( 'add-payment-method' => 1, 'a4aq' => 'classic-add-payment-method' ) ),
	'productExpress'          => '/?' . http_build_query( array( 'product' => $product_slug, 'a4aq' => 'product-express' ), '', '&', PHP_QUERY_RFC3986 ),
);

if ( 'reference' === $role ) {
	$routes = array_combine(
		array_map(
			static function ( string $key ): string {
				return 'reference' . strtoupper( substr( $key, 0, 1 ) ) . substr( $key, 1 );
			},
			array_keys( $routes )
		),
		array_values( $routes )
	);
}

WP_CLI::line(
	wp_json_encode(
		array(
			'role'           => $role,
			'product_id'     => $product_id,
			'product_slug'   => $product_slug,
			'checkout_id'    => $checkout_id,
			'cart_id'        => $cart_id,
			'myaccount_id'   => $myaccount_id,
			'classic_id'     => $classic_id,
			'routes'         => $routes,
			'permalink_mode' => 'plain-compatible',
		)
	)
);
