---
session: 2026-06-22-woopayments-core-merge-parity
type: analysis
by: subagent:parity-I
created: 2026-06-22 22:55
target: MC compatibility
status: final
---

# Parity analysis I — Multi-Currency compatibility perf hot paths

REGRESSION-vs-PARITY analysis for the WooPayments → WooCommerce-core merge.

- CORE: `/Users/vladolaru/Work/a8c/woocommerce-develop-2/plugins/woocommerce/src/Internal/MultiCurrency/`
- CLIENT (oracle): `/Users/vladolaru/Work/a8c/woocommerce-payments` v10.8.0 (develop), `includes/multi-currency/Compatibility/`

Note on layout: the CORE compatibility controllers do NOT live under a `Compatibility/` subfolder; they sit flat in `src/Internal/MultiCurrency/` (e.g. `MultiCurrencyBookingsCompatibilityController.php`, `MultiCurrencySubscriptionsCompatibilityController.php`). The client equivalents are `includes/multi-currency/Compatibility/WooCommerceBookings.php` and `.../WooCommerceSubscriptions.php`. The client's shared backtrace helper is `includes/multi-currency/Utils.php::is_call_in_backtrace()`.

---

## Finding 1 (f35bc174, HIGH) — `is_call_in_backtrace()` per price-filter backtrace cost

**VERDICT: PARITY** (with a localized IMPROVEMENT in the Subscriptions controller only). Confidence: HIGH.

### The hot path

CORE `MultiCurrencyBookingsCompatibilityController::is_call_in_backtrace()` runs a full backtrace summary on every call:

`MultiCurrencyBookingsCompatibilityController.php:295-305`
```php
protected function is_call_in_backtrace( array $calls ): bool {
    $backtrace = wp_debug_backtrace_summary( null, 0, false ); // phpcs:ignore ...
    foreach ( $calls as $call ) {
        if ( in_array( $call, $backtrace, true ) ) {
            return true;
        }
    }
    return false;
}
```

This is invoked from the price filters: `get_price()` calls it up to 3 times per `woocommerce_product_get_*` invocation (lines 158, 177-178, 187), and `should_convert_product_price()` calls it (line 221). No memoization.

### The client oracle

The client uses the IDENTICAL pattern — a single shared `Utils` helper with the same `wp_debug_backtrace_summary( null, 0, false )` call and the same linear `in_array` scan, no memoization:

`includes/multi-currency/Utils.php:23-31`
```php
public function is_call_in_backtrace( array $calls ): bool {
    $backtrace = wp_debug_backtrace_summary( null, 0, false ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions
    foreach ( $calls as $call ) {
        if ( in_array( $call, $backtrace, true ) ) {
            return true;
        }
    }
    return false;
}
```

Client `WooCommerceBookings::get_price()` calls `$this->utils->is_call_in_backtrace([...])` 2-3 times per price filter (`includes/multi-currency/Compatibility/WooCommerceBookings.php:98, 108, 142`), exactly mirroring CORE. The client takes a backtrace summary on every `woocommerce_product_get_price`-family hook invocation, with no caching.

**Conclusion:** The expensive `wp_debug_backtrace_summary()`-on-every-price-filter pattern is faithfully ported from the client. The client never memoized it; CORE did not introduce the cost and did not drop a cache that existed. This is the client's own design being carried forward → PARITY, not a regression.

### Nuance — the Subscriptions controller is actually IMPROVED over the client

The Bookings controller (the file the finding cites at line 302) is byte-for-byte equivalent to the client helper. But the sibling `MultiCurrencySubscriptionsCompatibilityController` re-implements its own `is_call_in_backtrace()` differently and more efficiently than the client's `Utils`:

`MultiCurrencySubscriptionsCompatibilityController.php:593-611`
```php
protected function is_call_in_backtrace( array $expected_calls ): bool {
    $expected_lookup = array_fill_keys( $expected_calls, true );
    foreach ( debug_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS ) as $call ) { // phpcs:ignore ...
        if ( empty( $call['function'] ) ) {
            continue;
        }
        $call_string = isset( $call['class'] )
            ? (string) $call['class'] . (string) ( $call['type'] ?? '::' ) . (string) $call['function']
            : (string) $call['function'];
        if ( isset( $expected_lookup[ $call_string ] ) ) {
            return true;
        }
    }
    return false;
}
```

This avoids `wp_debug_backtrace_summary()`'s string-building of the entire stack and uses an O(1) hash lookup (`array_fill_keys` + `isset`) instead of the client's O(n*m) `in_array` scan per expected call. It also early-returns on the first matching frame. So for the Subscriptions hot path specifically, CORE is marginally faster than the client. The Bookings/Deposits/FedEx/UPS/Points controllers, however, retain the client's `wp_debug_backtrace_summary()` approach (PARITY).

**Net:** Per-price-filter backtrace cost is PARITY (no memoization in either codebase, matching the client by design). No regression. Confidence HIGH — both helpers read in full, identical algorithm in the Bookings path.

---

## Finding 2 (e0e84777, HIGH) — `get_subscription_type_from_cart()` cart+session traversal per price filter, no per-request cache

**VERDICT: PARITY.** Confidence: HIGH.

### The hot path

CORE `get_subscription_type_from_cart()` traverses cart contents then session cart on every call, with no per-request cache:

`MultiCurrencySubscriptionsCompatibilityController.php:502-532`
```php
protected function get_subscription_type_from_cart( string $type ): ?array {
    if ( ! in_array( $type, self::SUBSCRIPTION_TYPES, true ) || ! function_exists( 'WC' ) ) {
        return null;
    }
    $woocommerce      = WC();
    $subscription_key = 'subscription_' . $type;
    $cart             = $woocommerce->cart;
    if ( $cart instanceof \WC_Cart && is_array( $cart->cart_contents ) ) {
        foreach ( $cart->cart_contents as $cart_item ) {
            if ( is_array( $cart_item ) && isset( $cart_item[ $subscription_key ] ) ) {
                return $cart_item;
            }
        }
    }
    $session = $woocommerce->session;
    if ( $session instanceof \WC_Session ) {
        $session_cart = $session->get( 'cart' );
        if ( is_array( $session_cart ) ) {
            foreach ( $session_cart as $cart_item ) {
                if ( is_array( $cart_item ) && isset( $cart_item[ $subscription_key ] ) ) {
                    return $cart_item;
                }
            }
        }
    }
    return null;
}
```

It is called repeatedly per price filter: `should_convert_product_price()` calls it twice (lines 299-300, for 'renewal' and 'resubscribe'), `should_convert_coupon_amount()` once (line 318), `has_subscription_cart_context()` loops all 3 types (lines 689-693), `maybe_disable_mixed_cart()` once (line 456), `get_subscription_product_signup_fee()` once (line 357), and `override_selected_currency()` loops all 3 types (line 249). No memoization of the result across calls within a request.

### The client oracle

The client `WooCommerceSubscriptions::get_subscription_type_from_cart()` does the SAME cart-then-session traversal with the SAME no-cache design:

`includes/multi-currency/Compatibility/WooCommerceSubscriptions.php:413-441`
```php
private function get_subscription_type_from_cart( $type ) {
    if ( ! in_array( $type, self::SUBSCRIPTION_TYPES, true ) ) {
        return false;
    }
    $subscription_type = 'subscription_' . $type;
    if ( isset( WC()->cart ) && is_array( WC()->cart->cart_contents ) && ! empty( WC()->cart->cart_contents ) ) {
        foreach ( WC()->cart->cart_contents as $cart_item ) {
            if ( isset( $cart_item[ $subscription_type ] ) ) {
                return $cart_item;
            }
        }
    }
    if ( isset( WC()->session ) && is_array( WC()->session->get( 'cart' ) ) && ! empty( WC()->session->get( 'cart' ) ) ) {
        foreach ( WC()->session->get( 'cart' ) as $cart_item ) {
            if ( isset( $cart_item[ $subscription_type ] ) ) {
                return $cart_item;
            }
        }
    }
    return false;
}
```

The client calls it identically often per filter — `should_convert_product_price()` calls it twice (`WooCommerceSubscriptions.php:227, 240`), `should_convert_coupon_amount()` once (line 274), `should_disable_currency_switching()` calls it 3 times (lines 306-308), `maybe_disable_mixed_cart()` once (line 158), `get_subscription_product_signup_fee()` once (line 108), `override_selected_currency()` loops all 3 types (line 187). The client has NO per-request cache of the cart-subscription-type lookup. (Minor note: the client calls `WC()->session->get('cart')` twice per session-branch entry — once in the `is_array(...)` guard and once in the `foreach` — whereas CORE caches it once into `$session_cart`. That makes CORE marginally cheaper in the session branch, not more expensive.)

**Conclusion:** Both client and CORE recompute the cart+session traversal on every price filter with no memoization. CORE faithfully ported the client's behavior, with a tiny incidental improvement (single `$session->get('cart')` read vs. the client's double read). The hot-path cost is identical in shape → PARITY, not a regression.

Confidence HIGH — both methods read in full; algorithm, traversal order (cart then session), call frequency, and absence of caching all match.

---

## Summary

| id | classification | confidence | client file:line | reason |
|----|---------------|------------|------------------|--------|
| f35bc174 | PARITY (Bookings/Deposits/FedEx/UPS/Points); IMPROVED (Subscriptions only) | HIGH | `includes/multi-currency/Utils.php:23-31` | Client `Utils::is_call_in_backtrace()` runs `wp_debug_backtrace_summary(null,0,false)` on every call with no memoization; CORE Bookings controller ports it verbatim. CORE Subscriptions controller re-implements with `debug_backtrace(IGNORE_ARGS)` + O(1) hash lookup — faster than client. |
| e0e84777 | PARITY | HIGH | `includes/multi-currency/Compatibility/WooCommerceSubscriptions.php:413-441` | Client `get_subscription_type_from_cart()` traverses cart then session per call with no cache; called up to 2-3x per filter just like CORE. CORE ported it faithfully (and reads `$session->get('cart')` once vs. client's twice — incidentally cheaper). No regression. |
