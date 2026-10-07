import { expect, test } from '@playwright/test';

import { wpCLI, wpEvalJson } from '../../../utils/cli';

// The native multi-currency facade this probe requires (`WCPay\MultiCurrency\MultiCurrency`)
// only declares itself once the store has opted into WooCommerce's independent multi-currency
// feature, which defaults to off. That is store configuration, not part of what this probe
// asserts, so it is set up here and restored after, the way `checkout.spec.ts` handles its own
// settings through `updateIfNeeded`/`resetValue`.
const MULTI_CURRENCY_FEATURE_OPTION =
	'woocommerce_feature_multi_currency_enabled';
const MULTI_CURRENCY_FEATURE_ABSENT_MARKER =
	'__woopayments_native_e2e_multi_currency_feature_absent__';

let initialMultiCurrencyFeatureValue: string;

test.beforeAll( async () => {
	initialMultiCurrencyFeatureValue = (
		await wpCLI( [
			'wp',
			'eval',
			`echo get_option( '${ MULTI_CURRENCY_FEATURE_OPTION }', '${ MULTI_CURRENCY_FEATURE_ABSENT_MARKER }' );`,
		] )
	).stdout.trim();
	await wpCLI( [
		'wp',
		'option',
		'update',
		MULTI_CURRENCY_FEATURE_OPTION,
		'yes',
	] );
} );

test.afterAll( async () => {
	if (
		initialMultiCurrencyFeatureValue ===
		MULTI_CURRENCY_FEATURE_ABSENT_MARKER
	) {
		await wpCLI( [
			'wp',
			'option',
			'delete',
			MULTI_CURRENCY_FEATURE_OPTION,
		] );
	} else {
		await wpCLI( [
			'wp',
			'option',
			'update',
			MULTI_CURRENCY_FEATURE_OPTION,
			initialMultiCurrencyFeatureValue,
		] );
	}
} );

const COMMON_PROBE_PHP = String.raw`
$wcpay_extension_profile = get_option( 'e2e_woopayments_extension_compat_profile', array() );
if ( ! is_array( $wcpay_extension_profile ) || ! isset( $wcpay_extension_profile['pin_set'], $wcpay_extension_profile['versions'] ) || ! is_array( $wcpay_extension_profile['versions'] ) ) {
	throw new RuntimeException( 'The WooPayments extension compatibility profile marker is missing.' );
}
$wcpay_extension_version = static function ( string $extension ) use ( $wcpay_extension_profile ): string {
	$version = $wcpay_extension_profile['versions'][ $extension ] ?? '';
	if ( ! is_string( $version ) || '' === $version ) {
		throw new RuntimeException( sprintf( 'The extension compatibility profile has no %s version.', $extension ) );
	}
	return $version;
};
$wcpay_invoke_private = static function ( object $target, string $method, array $arguments = array() ) {
	$reflection = new ReflectionMethod( $target, $method );
	$reflection->setAccessible( true );
	return $reflection->invokeArgs( $target, $arguments );
};
`;

/**
 * Run a provider-free extension integration probe through the shared
 * `wpEvalJson` runner, with the profile's PHP prelude prepended and its
 * settings-feature flag env var set ahead of bootstrap.
 */
function runExtensionCompatProbe< Result >(
	phpBody: string
): Promise< Result > {
	return wpEvalJson< Result >( `${ COMMON_PROBE_PHP }\n${ phpBody }`, [
		'PCP_SETTINGS_ENABLED=1',
	] );
}

test.skip(
	process.env.E2E_WOOPAYMENTS_EXTENSION_COMPAT !== 'true',
	'The external-extension compatibility profile is opt-in.'
);

/**
 * Deposits hides the itemized lines of the product-page express payload while
 * the cart holds a deposit (`wcpay_payment_request_hide_itemization`, which
 * reads the cart), and converts a fixed deposit into the shopper's currency
 * through the `WCPay\MultiCurrency\MultiCurrency` facade.
 *
 * The payload is read inside `woocommerce_after_add_to_cart_form` with the
 * product global set, the only product-page context
 * `WooPaymentsExpressCheckoutService::get_product_for_product_page()` resolves
 * under WP-CLI, and the probe fails if the payload comes back empty. The same
 * product added with the deposit declined is the positive control: its
 * payload must keep the line items. The deposit is added through
 * `WC_Cart::add_to_cart()` with the posted `wc_deposit_option` field the
 * Deposits product form sends, so Deposits computes the line's deposit itself,
 * and the cart total is read after `calculate_totals()`.
 */
test( '@woopayments-extension-compat Deposits hides express line items and converts a fixed deposit once', async () => {
	const result = await runExtensionCompatProbe< {
		version: string;
		control: {
			isDeposit: boolean;
			displayItemLabels: string[] | null;
			totalPresent: boolean;
		};
		deposit: {
			isDeposit: boolean;
			displayItemsPresent: boolean;
			totalPresent: boolean;
			lineDepositAmount: number;
			cartTotal: number;
		};
	} >( String.raw`
if ( ! class_exists( 'WC_Deposits_Product_Manager' ) || ! class_exists( 'WC_Deposits_Cart_Manager' ) || ! class_exists( 'WCPay\\MultiCurrency\\MultiCurrency' ) ) {
	throw new RuntimeException( 'WooCommerce Deposits or the native multi-currency facade is not active.' );
}

$product = new WC_Product_Simple();
$product->set_name( 'Extension compatibility deposit' );
$product->set_regular_price( '100' );
$product->set_price( '100' );
// Virtual and untaxed, so the cart total is the deposit alone: no shipping or tax line can move it.
$product->set_virtual( true );
$product->set_tax_status( 'none' );
$product->update_meta_data( '_wc_deposit_enabled', 'optional' );
$product->update_meta_data( '_wc_deposit_type', 'fixed' );
$product->update_meta_data( '_wc_deposit_amount', '10' );
$product->save();
$missing_option = new stdClass();
$rate_options   = array(
	'woocommerce_currency'                    => get_option( 'woocommerce_currency', $missing_option ),
	'wcpay_multi_currency_enabled_currencies' => get_option( 'wcpay_multi_currency_enabled_currencies', $missing_option ),
	'wcpay_multi_currency_exchange_rate_eur' => get_option( 'wcpay_multi_currency_exchange_rate_eur', $missing_option ),
	'wcpay_multi_currency_manual_rate_eur'   => get_option( 'wcpay_multi_currency_manual_rate_eur', $missing_option ),
);

try {
	update_option( 'woocommerce_currency', 'USD' );
	update_option( 'wcpay_multi_currency_enabled_currencies', array( 'EUR' ) );
	update_option( 'wcpay_multi_currency_exchange_rate_eur', 'manual' );
	update_option( 'wcpay_multi_currency_manual_rate_eur', '0.8' );
	add_filter( 'wcpay_multi_currency_override_selected_currency', static fn() => 'EUR', PHP_INT_MAX );
	$state_builder = wc_get_container()->get( Automattic\WooCommerce\Internal\MultiCurrency\Services\MultiCurrencyStateBuilderFactory::class )->create();
	$state_builder->reset();

	wc_load_cart();
	$cart = WC()->cart;
	if ( ! $cart instanceof WC_Cart ) {
		throw new RuntimeException( 'The cart could not be loaded.' );
	}
	$express = wc_get_container()->get( Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsExpressCheckoutService::class );

	// Empty the cart, then add the product the way the Deposits product form posts it.
	$add_to_cart = static function ( string $deposit_option ) use ( $cart, $product ): array {
		$cart->empty_cart();
		$_POST['wc_deposit_option'] = $deposit_option;
		try {
			$cart_item_key = $cart->add_to_cart( $product->get_id(), 1 );
		} finally {
			unset( $_POST['wc_deposit_option'] );
		}
		if ( ! is_string( $cart_item_key ) || '' === $cart_item_key ) {
			throw new RuntimeException( 'The cart refused the product: ' . wp_json_encode( wc_get_notices() ) );
		}
		$cart->calculate_totals();
		return $cart->get_cart_item( $cart_item_key );
	};

	// The product-page payload, read where the product page renders the express buttons.
	$product_page_payload = static function () use ( $express, $product, $wcpay_invoke_private ): array {
		$previous_product   = $GLOBALS['product'] ?? null;
		$GLOBALS['product'] = $product;
		$payload            = null;
		$capture            = static function () use ( &$payload, $express, $wcpay_invoke_private ): void {
			$payload = $wcpay_invoke_private( $express, 'get_product_data' );
		};
		add_action( 'woocommerce_after_add_to_cart_form', $capture, PHP_INT_MAX );
		ob_start();
		try {
			do_action( 'woocommerce_after_add_to_cart_form' );
		} finally {
			ob_end_clean();
			remove_action( 'woocommerce_after_add_to_cart_form', $capture, PHP_INT_MAX );
			$GLOBALS['product'] = $previous_product;
		}
		if ( ! is_array( $payload ) || array() === $payload ) {
			throw new RuntimeException( 'The product-page express payload is empty, so the probe did not reach a product-page context.' );
		}
		return $payload;
	};

	$control_item    = $add_to_cart( 'no' );
	$control_payload = $product_page_payload();
	$deposit_item    = $add_to_cart( 'yes' );
	$deposit_payload = $product_page_payload();

	return array(
		'version' => $wcpay_extension_version( 'deposits' ),
		'control' => array(
			'isDeposit'         => ! empty( $control_item['is_deposit'] ),
			'displayItemLabels' => isset( $control_payload['displayItems'] ) && is_array( $control_payload['displayItems'] ) ? array_values( array_map( static fn( $item ) => (string) ( $item['label'] ?? '' ), $control_payload['displayItems'] ) ) : null,
			'totalPresent'      => isset( $control_payload['total'] ),
		),
		'deposit' => array(
			'isDeposit'           => ! empty( $deposit_item['is_deposit'] ),
			'displayItemsPresent' => array_key_exists( 'displayItems', $deposit_payload ),
			'totalPresent'        => isset( $deposit_payload['total'] ),
			'lineDepositAmount'   => (float) ( $deposit_item['deposit_amount'] ?? -1 ),
			'cartTotal'           => (float) $cart->get_total( 'edit' ),
		),
	);
} finally {
	if ( WC()->cart instanceof WC_Cart ) {
		WC()->cart->empty_cart();
	}
	if ( WC()->session instanceof WC_Session_Handler ) {
		WC()->session->destroy_session();
	}
	foreach ( $rate_options as $option_name => $option_value ) {
		if ( $missing_option === $option_value ) {
			delete_option( $option_name );
		} else {
			update_option( $option_name, $option_value );
		}
	}
	wp_delete_post( $product->get_id(), true );
}
` );

	expect( [ '2.2.9', '2.4.7' ] ).toContain( result.version );

	// Positive control: with the deposit declined the cart holds no deposit,
	// and the product-page payload keeps its line items.
	expect( result.control.isDeposit ).toBe( false );
	expect( result.control.totalPresent ).toBe( true );
	expect( result.control.displayItemLabels ).toEqual( [
		'Extension compatibility deposit',
	] );

	// With a deposit in the cart, the same payload drops only its line items.
	expect( result.deposit.isDeposit ).toBe( true );
	expect( result.deposit.totalPresent ).toBe( true );
	expect( result.deposit.displayItemsPresent ).toBe( false );

	// The fixed 10 USD deposit at the 0.8 EUR rate: 8 EUR, on the cart line
	// Deposits computed and in the cart total. Not converted (10) and not
	// converted twice (6.4).
	expect( result.deposit.lineDepositAmount ).toBeCloseTo( 8, 8 );
	expect( result.deposit.cartTotal ).toBeCloseTo( 8, 8 );
} );
