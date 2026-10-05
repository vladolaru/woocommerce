<?php
/**
 * Stand-in for the parts of WooCommerce Subscriptions that the renewal path of the PayPal wallet calls, which core's
 * test suite does not load.
 *
 * @package Automattic\WooCommerce\Tests\Internal\Payments\Providers\PayPal\Wallet\Doubles
 */

declare( strict_types = 1 );

// The class and the functions it stands in for are global, as in the plugin, so this file has no namespace.

// phpcs:disable SlevomatCodingStandard.Files.TypeNameMatchesFileName.NoMatchBetweenTypeNameAndFileName -- A global class, as the plugin's own.
/**
 * The data behind the declared functions. Global functions and classes live as long as the PHP process, so the
 * functions answer from the registry of this class, and a test that fills it empties it again with reset() in
 * tearDown(). The WC_Subscription class holds no state.
 *
 * Nothing but this class is declared when WooCommerce Subscriptions is loaded: use in_effect() to skip a test then.
 * WC_Subscriptions, the main class of the plugin, is not declared, so SubscriptionHelper::plugin_is_active() stays
 * false, and wcs_order_contains_subscription() is not declared either, so SubscriptionHelper::has_subscription() keeps
 * answering false.
 */
final class WcSubscriptionsDouble {

	/**
	 * The subscriptions wcs_get_subscription() finds, by ID.
	 *
	 * @var array<int, WC_Order>
	 */
	private static array $subscriptions = array();

	/**
	 * The subscriptions of each renewal order, by renewal order ID and then by subscription ID.
	 *
	 * @var array<int, array<int, WC_Order>>
	 */
	private static array $renewals = array();

	/**
	 * Whether the functions of this file are the ones in use, and not those of the real plugin.
	 *
	 * @return bool
	 */
	public static function in_effect(): bool {
		foreach ( array( 'wcs_get_subscription', 'wcs_get_subscriptions_for_renewal_order', 'wcs_order_contains_renewal' ) as $function_name ) {
			if ( ! function_exists( $function_name ) || ( new ReflectionFunction( $function_name ) )->getFileName() !== __FILE__ ) {
				return false;
			}
		}

		return class_exists( 'WC_Subscription' ) && ( new ReflectionClass( 'WC_Subscription' ) )->getFileName() === __FILE__;
	}

	/**
	 * Make wcs_get_subscription() find the subscription by its ID.
	 *
	 * @param WC_Order $subscription A saved order or a WC_Subscription.
	 */
	public static function register_subscription( WC_Order $subscription ): void {
		self::$subscriptions[ $subscription->get_id() ] = $subscription;
	}

	/**
	 * Make the order a renewal order of the given subscriptions.
	 *
	 * @param WC_Order   $renewal_order The renewal order.
	 * @param WC_Order[] $subscriptions Its subscriptions.
	 */
	public static function register_renewal( WC_Order $renewal_order, array $subscriptions ): void {
		self::$renewals[ $renewal_order->get_id() ] = array();
		foreach ( $subscriptions as $subscription ) {
			self::$renewals[ $renewal_order->get_id() ][ $subscription->get_id() ] = $subscription;
		}
	}

	/**
	 * The subscription with the ID, or false.
	 *
	 * @param int $subscription_id The ID.
	 * @return WC_Order|false
	 */
	public static function subscription( int $subscription_id ) {
		return self::$subscriptions[ $subscription_id ] ?? false;
	}

	/**
	 * The subscriptions of a renewal order, by ID, none for an order that is not a renewal.
	 *
	 * @param int $order_id The order ID.
	 * @return array<int, WC_Order>
	 */
	public static function subscriptions_for_renewal( int $order_id ): array {
		return self::$renewals[ $order_id ] ?? array();
	}

	/**
	 * Forget every subscription and renewal.
	 */
	public static function reset(): void {
		self::$subscriptions = array();
		self::$renewals      = array();
	}
}
// phpcs:enable SlevomatCodingStandard.Files.TypeNameMatchesFileName.NoMatchBetweenTypeNameAndFileName

// No autoload: the autoloader maps WC_Subscription to this file, so class_exists() would include it again and redeclare WcSubscriptionsDouble.
if ( ! class_exists( 'WC_Subscription', false ) ) {
	/**
	 * A subscription: an order, as in WooCommerce Subscriptions, without the subscription behaviour.
	 */
	class WC_Subscription extends WC_Order {} // phpcs:ignore Generic.Files.OneObjectStructurePerFile.MultipleFound, Squiz.Classes.ClassFileName.NoMatch, Squiz.Classes.ValidClassName.NotCamelCaps, SlevomatCodingStandard.Files.TypeNameMatchesFileName.NoMatchBetweenTypeNameAndFileName -- Named after the plugin class it stands in for.
}

if ( ! function_exists( 'wcs_get_subscription' ) ) {
	/**
	 * The subscription the test registered under the ID, or false.
	 *
	 * @param mixed $the_subscription A subscription ID.
	 * @return WC_Order|false
	 */
	function wcs_get_subscription( $the_subscription ) { // phpcs:ignore Universal.Files.SeparateFunctionsFromOO.Mixed -- A stand-in for a plugin function.
		return WcSubscriptionsDouble::subscription( (int) $the_subscription );
	}
}

if ( ! function_exists( 'wcs_get_subscriptions_for_renewal_order' ) ) {
	/**
	 * The subscriptions the test registered for the renewal order, by subscription ID.
	 *
	 * @param WC_Order|int $order A renewal order or its ID.
	 * @return array<int, WC_Order>
	 */
	function wcs_get_subscriptions_for_renewal_order( $order ) { // phpcs:ignore Universal.Files.SeparateFunctionsFromOO.Mixed -- A stand-in for a plugin function.
		return WcSubscriptionsDouble::subscriptions_for_renewal( $order instanceof WC_Order ? $order->get_id() : (int) $order );
	}
}

if ( ! function_exists( 'wcs_order_contains_renewal' ) ) {
	/**
	 * Whether the order is a renewal order the test registered.
	 *
	 * @param WC_Order|int $order An order or its ID.
	 * @return bool
	 */
	function wcs_order_contains_renewal( $order ) { // phpcs:ignore Universal.Files.SeparateFunctionsFromOO.Mixed -- A stand-in for a plugin function.
		return array() !== WcSubscriptionsDouble::subscriptions_for_renewal( $order instanceof WC_Order ? $order->get_id() : (int) $order );
	}
}
