---
session: 2026-06-22-woopayments-core-merge-parity
type: analysis
by: subagent:parity-H
created: 2026-06-22 22:55
target: MC core
status: final
---

# Parity analysis H — Multi-Currency CORE perf/SQL

REGRESSION-vs-PARITY analysis for the WooPayments → WooCommerce-core merge.

- CORE: `/Users/vladolaru/Work/a8c/woocommerce-develop-2/plugins/woocommerce/src/Internal/MultiCurrency/`
- CLIENT (oracle): `/Users/vladolaru/Work/a8c/woocommerce-payments` v10.8.0 (develop), MC under `includes/multi-currency/`

The CORE Multi-Currency subsystem was ported from the client's `includes/multi-currency/`. The client's `Analytics.php` and `MultiCurrency.php` are the direct ancestors of the three CORE files under review. All three findings are pre-existing client behavior carried into core verbatim.

---

## Finding 1 — `has_multi_currency_orders()` uncached SELECT EXISTS (HIGH, 1062b791)

**Verdict: PARITY** (confidence: very high)

CORE `MultiCurrencyAnalyticsController.php:428-456` runs an uncached `SELECT EXISTS(...)` table scan against `wc_orders_meta` (HPOS) or `postmeta` (legacy), keyed on `meta_key = '_wcpay_multi_currency_order_exchange_rate'`.

The client does the identical thing. `Analytics.php:565-590`:

```php
private function has_multi_currency_orders() {
	global $wpdb;

	// Using full SQL instead of variables to keep WPCS happy.
	if ( $this->is_cot_enabled() ) {
		$result = $wpdb->get_var(
			"SELECT EXISTS(
				SELECT 1
				FROM {$wpdb->prefix}wc_orders_meta
				WHERE meta_key = '_wcpay_multi_currency_order_exchange_rate'
				LIMIT 1)
			AS count;"
		);
	} else {
		$result = $wpdb->get_var(
			"SELECT EXISTS(
				SELECT 1
				FROM {$wpdb->postmeta}
				WHERE meta_key = '_wcpay_multi_currency_order_exchange_rate'
				LIMIT 1)
			AS count;"
		);
	}

	return intval( $result ) === 1;
}
```

The client query is byte-for-byte the same SQL (HPOS branch on `wc_orders_meta`, legacy branch on `$wpdb->postmeta`, `SELECT EXISTS(... LIMIT 1) AS count`). **No transient, no static memo, no `wp_cache` wrapper** anywhere in the client method or its caller.

Caller behavior also matches. Client `Analytics.php:89`:

```php
if ( ! WC()->is_rest_api_request() || ! $this->has_multi_currency_orders() ) {
	return;
}
```

The client invokes `has_multi_currency_orders()` once during `init_hooks()`/init (gated by `is_rest_api_request()`), exactly as core gates the call behind its `is_rest_api_request()` check. The client result is not cached at the call site either — it relies on the per-request init running once. Core preserves the same "run once per relevant request, no persistent cache" shape.

The HIGH severity is a legitimate standing perf concern (uncached `EXISTS` per analytics REST request), but it is **not introduced by the merge** — core inherited the exact client behavior. Not a regression.

---

## Finding 2 — N+1 `get_option()` per enabled currency in `build()` (HIGH, f42b6e68)

**Verdict: PARITY** (confidence: very high)

CORE `Services/MultiCurrencyStateBuilder.php` reads per-currency options inside the enabled-currency loop:
- `apply_currency_settings()` (line 348-358): `get_option( _price_charm_$id )`, `get_option( _price_rounding_$id )`
- `create_enabled_currency()` → `uses_manual_rate()` (line 339-341): `get_option( _exchange_rate_$code )`
- `create_enabled_currency()` (line 175): `$this->rate_service->get_rate()` for the manual rate

So per enabled currency core issues up to 4 option reads (rate type, manual rate, charm, rounding). `build()`'s loop is at lines 125-138.

The client does the identical N+1 read. `MultiCurrency.php:1669-1685` (`initialize_enabled_currencies()`):

```php
foreach ( $enabled_currencies as $enabled_currency ) {
	// Get the charm and rounding for each enabled currency and add the currencies to the object property.
	$currency = clone $enabled_currency;
	$charm    = get_option( $this->id . '_price_charm_' . $currency->get_id(), 0.00 );
	$rounding = get_option( $this->id . '_price_rounding_' . $currency->get_id(), $currency->get_is_zero_decimal() ? '100' : '1.00' );
	$currency->set_charm( $charm );
	$currency->set_rounding( $rounding );

	// If the currency is set to be manual, set the rate to the stored manual rate.
	$type = get_option( $this->id . '_exchange_rate_' . $currency->get_id(), 'automatic' );
	if ( 'manual' === $type ) {
		$manual_rate = get_option( $this->id . '_manual_rate_' . $currency->get_id(), $currency->get_rate() );
		$currency->set_rate( $manual_rate );
	}

	$this->enabled_currencies[ $currency->get_code() ] = $currency;
}
```

This is exactly the same per-currency `get_option()` pattern — same four option keys (`_price_charm_`, `_price_rounding_`, `_exchange_rate_`, `_manual_rate_`), same per-iteration reads, **no batching, no autoload-bundled read, no `wp_load_alloptions`-style prefetch**. Core's split across `apply_currency_settings()` / `uses_manual_rate()` / `create_enabled_currency()` is a structural refactor of the same logic, not a behavior change.

Note (IMPROVED, not regression): core actually *reduces* per-request cost relative to the client by **memoizing the whole assembled state**. `build()` returns `$this->cached_state` if already built (line 96-98), and the class docstring (lines 80-90) describes a request-memoized shared `MultiCurrencyState`. The client's `initialize_enabled_currencies()` is invoked from init and the resulting `enabled_currencies` array is held on the instance, so it is effectively also once-per-request — parity on count, with core's explicit `reset()`/memo contract being a modest improvement. The N+1 itself (options not batched) is identical client behavior, so this is **PARITY** on the flagged concern, not a regression.

---

## Finding 3 — analytics WHERE clauses use `esc_sql()` + sprintf not `prepare()` (MED, b6ab58da)

**Verdict: PARITY, with one core micro-improvement** (confidence: very high)

CORE `Services/MultiCurrencyAnalyticsSqlProjectionService.php:114-137` (`project_where_clauses()`):
- `currency_is` IN-list: `sprintf( "'%s'", implode( "', '", array_map( 'esc_sql', $currency_is ) ) )` then interpolated (line 121-122)
- `currency_is_not` NOT IN-list: same `esc_sql` + `sprintf` (line 127-128)
- single `currency`: `"AND {$currency_field} = '" . esc_sql( $currency ) . "'"` (line 133)

The client's `Analytics.php:373-416` (`filter_where_clauses()`) is the direct ancestor:

```php
$currency_args = $this->get_customer_currency_args_from_request();
if ( ! empty( $currency_args['currency_is'] ) ) {
	$currency_is = sprintf( "'%s'", implode( "', '", array_map( 'esc_sql', $currency_args['currency_is'] ) ) );
	$clauses[]   = "AND {$currency_field} IN ({$currency_is})";
}

if ( ! empty( $currency_args['currency_is_not'] ) ) {
	$currency_is_not = sprintf( "'%s'", implode( "', '", array_map( 'esc_sql', $currency_args['currency_is_not'] ) ) );
	$clauses[]       = "AND {$currency_field} NOT IN ({$currency_is_not})";
}

if ( ! empty( $currency_args['currency'] ) ) {
	global $wpdb;
	$expression = "AND {$currency_field} = '%s'";
	$clauses[]  = $wpdb->prepare( $expression, $currency_args['currency'] ); //phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
}
```

The two IN-list branches are **identical** to core — same `esc_sql` + `sprintf` + interpolation. Both rely on `esc_sql` (not `prepare`) for the IN-lists because building a variable-length `IN (...)` placeholder list with `prepare()` is awkward; this is a conventional WPCS-accepted pattern and the values are pre-sanitized (`sanitize_text_field` in `get_customer_currency_args_from_request()` client-side / `sanitize_currency_list()` core-side). PARITY for the IN/NOT IN branches.

**Divergence on the single-`currency` branch:** the client uses `$wpdb->prepare( "AND {$currency_field} = '%s'", $currency )` (line 403-406), whereas core dropped `prepare()` in favor of `"= '" . esc_sql( $currency ) . "'"` (line 133). This is a *less* defensive choice than the client's — core swapped a `prepare()` call for manual `esc_sql` concatenation. Both are safe in practice (core additionally runs `sanitize_text_field( wp_unslash( ... ) )` on the value at line 131 before escaping), so there's no injection regression, but it is a small **step away from the client's stricter `prepare()` usage** on this one clause. Net: PARITY on the IN-lists; a minor, safe stylistic regression (prepare → esc_sql) on the single-currency equality clause. Not a security regression.

---

## Summary

| id | classification | confidence | client anchor | reason |
|----|---------------|-----------|---------------|--------|
| 1062b791 | PARITY | very high | `Analytics.php:565-590` | Client `has_multi_currency_orders()` runs the identical uncached `SELECT EXISTS` (no transient/static); caller `Analytics.php:89` is also uncached. |
| f42b6e68 | PARITY | very high | `MultiCurrency.php:1669-1685` | Client `initialize_enabled_currencies()` reads the same 4 per-currency options in a loop, unbatched; core adds request-memoization on top (slight improvement). |
| b6ab58da | PARITY | very high | `Analytics.php:393-406` | IN/NOT-IN branches identical (`esc_sql`+sprintf). Core swapped client's `$wpdb->prepare()` for `esc_sql()` only on the single-currency `=` clause — safe, minor stylistic step back. |
