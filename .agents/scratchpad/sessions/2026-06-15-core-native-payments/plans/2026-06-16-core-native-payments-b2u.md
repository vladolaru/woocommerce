# Core Native Payments B2u Analytics Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use
> $subagent-driven-development (recommended) or $executing-plans to implement
> this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Register the native multi-currency analytics PHP hook surface when
core owns multi-currency.

**Architecture:** Add a runtime-gated
`MultiCurrencyAnalyticsController` that delegates transformations to the B1
analytics projection services. Keep settings/admin-client asset migration out of
this slice because core does not yet contain the WooPayments
`dist/multi-currency` settings assets.

**Tech Stack:** WooCommerce core PHP, WordPress hooks, WooCommerce analytics
filters, PHPUnit via `pnpm --filter='@woocommerce/plugin-woocommerce'
test:php:env`, PHPStan, PHPCS.

---

## File Structure

- Create:
  `plugins/woocommerce/src/Internal/MultiCurrency/MultiCurrencyAnalyticsController.php`
    - Runtime-gated hook registration.
    - Handler methods for order stats, query args, SQL clauses, and cache
      disabling.
    - Bounded `EXISTS` query for the heavy SQL-filter gate.
- Create:
  `plugins/woocommerce/tests/php/src/Internal/MultiCurrency/MultiCurrencyAnalyticsControllerTest.php`
    - Focused controller tests for registration gates and handler integration.
- Modify:
  `plugins/woocommerce/src/Internal/MultiCurrency/MultiCurrencyRuntimeRegistry.php`
    - Add an `analytics` hook group to the native registration manifest.
- Modify:
  `plugins/woocommerce/tests/php/src/Internal/MultiCurrency/MultiCurrencyRuntimeRegistryTest.php`
    - Assert the analytics hook group and preserved filter names.
- Modify:
  `plugins/woocommerce/includes/class-woocommerce.php`
    - Register the controller from WooCommerce boot.
- Create:
  `plugins/woocommerce/changelog/add-native-payments-b2u-multi-currency-analytics`
    - Patch changelog entry for the native analytics hook surface.

## Task 1: Write The RED Tests

- [ ] Add
  `plugins/woocommerce/tests/php/src/Internal/MultiCurrency/MultiCurrencyAnalyticsControllerTest.php`
  with tests for these behaviors:

```php
public function test_does_not_register_analytics_hooks_when_plugin_owns_runtime(): void;
public function test_registers_baseline_analytics_hooks_when_core_owns_runtime(): void;
public function test_registers_sql_hooks_only_for_rest_requests_with_multi_currency_orders(): void;
public function test_registers_selected_currency_sql_hooks_only_for_non_default_currency(): void;
public function test_disables_analytics_cache_in_dev_mode(): void;
public function test_applies_customer_currency_request_args(): void;
public function test_updates_order_stats_data_through_projection(): void;
public function test_respects_sql_clause_disable_and_extension_filters(): void;
```

- [ ] Extend
  `plugins/woocommerce/tests/php/src/Internal/MultiCurrency/MultiCurrencyRuntimeRegistryTest.php`
  so the core hook-group key list includes `analytics`, and add a focused
  assertion for these analytics hooks:

```php
array(
    'woocommerce_analytics_report_should_use_cache',
    'woocommerce_analytics_update_order_stats_data',
    'woocommerce_analytics_orders_query_args',
    'woocommerce_analytics_orders_stats_query_args',
    'woocommerce_analytics_clauses_select',
    'woocommerce_analytics_clauses_join',
    'woocommerce_analytics_clauses_where_orders_subquery',
    'woocommerce_analytics_clauses_where_orders_stats_total',
    'woocommerce_analytics_clauses_where_orders_stats_interval',
    'woocommerce_analytics_clauses_select_orders_subquery',
    'woocommerce_analytics_clauses_select_orders_stats_total',
);
```

- [ ] Run the focused RED command:

```bash
pnpm --filter='@woocommerce/plugin-woocommerce' test:php:env -- --filter 'MultiCurrencyAnalyticsControllerTest|MultiCurrencyRuntimeRegistryTest'
```

Expected: `MultiCurrencyAnalyticsControllerTest` fails because
`MultiCurrencyAnalyticsController` does not exist, and the registry test fails
because the `analytics` group is not yet present.

## Task 2: Add The Analytics Controller

- [ ] Create
  `plugins/woocommerce/src/Internal/MultiCurrency/MultiCurrencyAnalyticsController.php`.

Implementation shape:

```php
final public function init( MultiCurrencyRuntimeArbiter $arbiter ): void {
    $this->arbiter = $arbiter;
}

public function register() {
    if ( ! $this->arbiter->should_core_register() ) {
        return;
    }

    if ( $this->is_dev_mode() ) {
        $this->add_filter_once(
            'woocommerce_analytics_report_should_use_cache',
            array( $this, 'handle_woocommerce_analytics_report_should_use_cache' )
        );
    }

    $this->add_filter_once(
        'woocommerce_analytics_update_order_stats_data',
        array( $this, 'handle_woocommerce_analytics_update_order_stats_data' ),
        99999,
        2
    );
    $this->add_filter_once(
        'woocommerce_analytics_orders_query_args',
        array( $this, 'handle_woocommerce_analytics_orders_query_args' )
    );
    $this->add_filter_once(
        'woocommerce_analytics_orders_stats_query_args',
        array( $this, 'handle_woocommerce_analytics_orders_query_args' )
    );

    if ( ! $this->is_rest_api_request() || ! $this->has_multi_currency_orders() ) {
        return;
    }

    $this->add_filter_once(
        'woocommerce_analytics_clauses_select',
        array( $this, 'handle_woocommerce_analytics_clauses_select' ),
        20,
        2
    );
    $this->add_filter_once(
        'woocommerce_analytics_clauses_join',
        array( $this, 'handle_woocommerce_analytics_clauses_join' ),
        20,
        2
    );
    $this->add_filter_once(
        'woocommerce_analytics_clauses_where_orders_subquery',
        array( $this, 'handle_woocommerce_analytics_clauses_where' )
    );
    $this->add_filter_once(
        'woocommerce_analytics_clauses_where_orders_stats_total',
        array( $this, 'handle_woocommerce_analytics_clauses_where' )
    );
    $this->add_filter_once(
        'woocommerce_analytics_clauses_where_orders_stats_interval',
        array( $this, 'handle_woocommerce_analytics_clauses_where' )
    );

    if ( $this->has_selected_non_default_currency() ) {
        $this->add_filter_once(
            'woocommerce_analytics_clauses_select_orders_subquery',
            array( $this, 'handle_woocommerce_analytics_clauses_select_orders' )
        );
        $this->add_filter_once(
            'woocommerce_analytics_clauses_select_orders_stats_total',
            array( $this, 'handle_woocommerce_analytics_clauses_select_orders' )
        );
    }
}
```

- [ ] Add test setters for resolvers so tests do not depend on global request
  state or database contents:

```php
public function set_dev_mode_resolver( callable $resolver ): void;
public function set_rest_request_resolver( callable $resolver ): void;
public function set_multi_currency_orders_resolver( callable $resolver ): void;
public function set_hpos_resolver( callable $resolver ): void;
public function set_request_args_resolver( callable $resolver ): void;
public function set_default_currency_resolver( callable $resolver ): void;
public function set_analytics_projection_service(
    MultiCurrencyAnalyticsProjectionService $service
): void;
public function set_sql_projection_service(
    MultiCurrencyAnalyticsSqlProjectionService $service
): void;
```

- [ ] Implement handlers by delegating to projection services:

```php
public function handle_woocommerce_analytics_report_should_use_cache( $args ): bool {
    return false;
}

public function handle_woocommerce_analytics_update_order_stats_data(
    array $args,
    $order
): array {
    return $order instanceof \WC_Order
        ? $this->get_analytics_projection_service()->update_order_stats_data(
            $args,
            $order
        )
        : $args;
}

public function handle_woocommerce_analytics_orders_query_args( array $args ): array {
    return $this->get_analytics_projection_service()->apply_customer_currency_args(
        $args,
        $this->get_request_args()
    );
}
```

- [ ] Implement SQL handlers with the preserved compatibility filters:

```php
if ( (bool) apply_filters( 'wcpay_multi_currency_disable_filter_select_clauses', false ) ) {
    return $clauses;
}

$clauses = $this->get_sql_projection_service()->project_select_clauses(
    $clauses,
    is_string( $context ) ? $context : null,
    $this->is_hpos_enabled()
);

return apply_filters( 'wcpay_multi_currency_filter_select_clauses', $clauses );
```

Repeat the same pattern for join, where, and selected-currency order SELECT
clauses using the WooPayments filter names:

- `wcpay_multi_currency_disable_filter_join_clauses`
- `wcpay_multi_currency_filter_join_clauses`
- `wcpay_multi_currency_disable_filter_where_clauses`
- `wcpay_multi_currency_filter_where_clauses`
- `wcpay_multi_currency_disable_filter_select_orders_clauses`
- `wcpay_multi_currency_filter_select_orders_clauses`

- [ ] Implement `has_multi_currency_orders()` with the WooPayments-compatible
  `EXISTS` query and no user input interpolation:

```php
if ( $this->is_hpos_enabled() ) {
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
```

## Task 3: Register The Controller And Manifest

- [ ] Add `analytics` to
  `MultiCurrencyRuntimeRegistry::get_core_hook_groups()` with a private
  `get_analytics_hook_group()` method.

- [ ] Add the controller bootstrap line in
  `plugins/woocommerce/includes/class-woocommerce.php` next to the other native
  multi-currency controllers:

```php
$container->get( Automattic\WooCommerce\Internal\MultiCurrency\MultiCurrencyAnalyticsController::class )->register();
```

- [ ] Add changelog file:

```text
Significance: patch
Type: fix
Comment: Add native multi-currency analytics hook registration.
```

## Task 4: Verify And Commit

- [ ] Run the focused GREEN command:

```bash
pnpm --filter='@woocommerce/plugin-woocommerce' test:php:env -- --filter 'MultiCurrencyAnalyticsControllerTest|MultiCurrencyRuntimeRegistryTest'
```

Expected: the new analytics controller tests and updated registry tests pass.

- [ ] Run the regression command:

```bash
pnpm --filter='@woocommerce/plugin-woocommerce' test:php:env -- --filter 'MultiCurrencyAnalyticsControllerTest|MultiCurrencyAnalyticsProjectionServiceTest|MultiCurrencyAnalyticsSqlProjectionServiceTest|MultiCurrencyRuntimeRegistryTest|MultiCurrencyRuntimeArbiterTest|NativePaymentsRuntimeArbiterTest'
```

Expected: all tests pass.

- [ ] Run PHPStan with a `$TMPDIR` config that keeps the project config and
  disables unmatched ignore reporting for this narrow file check:

```bash
composer exec -- phpstan analyse src/Internal/MultiCurrency/MultiCurrencyAnalyticsController.php --configuration "$TMPDIR/phpstan-b2u.neon" --memory-limit=2G
```

Expected: `[OK] No errors`.

- [ ] Run PHPCS:

```bash
composer exec -- phpcs src/Internal/MultiCurrency/MultiCurrencyAnalyticsController.php tests/php/src/Internal/MultiCurrency/MultiCurrencyAnalyticsControllerTest.php tests/php/src/Internal/MultiCurrency/MultiCurrencyRuntimeRegistryTest.php
```

Expected: no errors.

- [ ] Run branch/staged lint and whitespace checks:

```bash
pnpm --filter=@woocommerce/plugin-woocommerce lint:php:changes
markdownlint .agents/scratchpad/sessions/2026-06-15-core-native-payments/plans/2026-06-16-core-native-payments-b2u.md .agents/scratchpad/sessions/2026-06-15-core-native-payments/implementation-log.md
git diff --check
git diff --cached --check
pnpm --filter=@woocommerce/plugin-woocommerce lint:php:changes:staged
```

Expected: all commands pass.

- [ ] Stage only the B2u WooCommerce source, test, bootstrap, and changelog
  files. Do not stage `.agents/*`, `docs/superpowers/*`, or the external
  staging log.

- [ ] Commit:

```bash
git commit -m "fix(payments): add multi-currency analytics hooks"
```

## Self-Review

- Spec coverage: closes the pure-PHP native analytics hook surface from
  WooPayments without claiming settings/admin-client asset parity.
- Placeholder scan: no TODO/TBD steps remain.
- Type consistency: controller/test/registry class and method names match the
  planned file paths.
