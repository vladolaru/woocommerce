# Native Payments B1f Multi-Currency Frontend Projection Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use $subagent-driven-development (recommended) or $executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add read-only native multi-currency frontend/config projections for B1 shadow parity without registering storefront hooks or REST routes.

**Architecture:** Keep B1f as a pure projection layer under `src/Internal/MultiCurrency/Services/`. The services reuse B1c state snapshots and B1a localization, expose WooPayments-compatible keys for future REST/filter owners, and avoid session/user/cart/order writes.

**Tech Stack:** WooCommerce Core PHP, PSR-4 internal services, WC CRUD/session APIs for read-only checks, PHPUnit via `pnpm --filter='@woocommerce/plugin-woocommerce' test:php:env`.

---

## File Map

- Create: `plugins/woocommerce/src/Internal/MultiCurrency/Services/MultiCurrencyGeolocationService.php`
    - Read-only country/currency selection matching WooPayments geolocation fallback.
- Create: `plugins/woocommerce/src/Internal/MultiCurrency/Services/MultiCurrencyFrontendProjectionService.php`
    - Read-only selected-currency formatting, cart-hash, store currencies/settings, and async public config projections.
- Create: `plugins/woocommerce/tests/php/src/Internal/MultiCurrency/Services/MultiCurrencyGeolocationServiceTest.php`
    - Unit coverage for bot exclusion, allowed-country fallback, and country-to-currency mapping.
- Create: `plugins/woocommerce/tests/php/src/Internal/MultiCurrency/Services/MultiCurrencyFrontendProjectionServiceTest.php`
    - Unit coverage for WooPayments-compatible projection payloads and read-only behavior.
- Create: `plugins/woocommerce/changelog/add-native-payments-b1f-multi-currency-frontend-projection`
    - WooCommerce changelog entry for the native multi-currency frontend projection slice.

## Task 1: Geolocation Projection

- [ ] **Step 1: Write the failing geolocation tests**

Add `MultiCurrencyGeolocationServiceTest` with tests for:

```php
public function test_returns_currency_from_customer_country(): void;
public function test_falls_back_to_default_country_when_customer_country_is_not_allowed(): void;
public function test_ignores_bot_user_agents(): void;
```

Use an injected geolocator callable to return country codes, a localization test double that maps `GB` to `GBP` and `US` to `USD`, `pre_option_woocommerce_default_country` filters for fallback, and temporary `HTTP_USER_AGENT` values for bot checks.

- [ ] **Step 2: Verify red**

Run:

```bash
pnpm --filter='@woocommerce/plugin-woocommerce' test:php:env -- --filter MultiCurrencyGeolocationServiceTest
```

Expected: fail because `MultiCurrencyGeolocationService` does not exist.

- [ ] **Step 3: Implement the minimal geolocation service**

Create `MultiCurrencyGeolocationService` with:

```php
public function get_currency_by_customer_location(): ?string;
public function get_country_by_customer_location(): string;
```

The service must skip user agents containing `bot`, `spider`, or `crawl`; validate the geolocated country against `WC()->countries->get_allowed_countries()`; fall back through `woocommerce_customer_default_location` and `woocommerce_default_country`; and never write options, sessions, or user meta.

- [ ] **Step 4: Verify green**

Run the same focused test command and expect PASS.

## Task 2: Frontend Formatting And Config Projection

- [ ] **Step 1: Write the failing frontend projection tests**

Add `MultiCurrencyFrontendProjectionServiceTest` with tests for:

```php
public function test_projects_selected_currency_formatting(): void;
public function test_cart_hash_includes_selected_currency_and_rate(): void;
public function test_projects_public_config_with_preserved_keys(): void;
public function test_public_config_uses_active_session_currency_without_writes(): void;
public function test_public_config_uses_geolocation_when_auto_currency_is_enabled(): void;
public function test_projects_store_currencies_and_settings(): void;
public function test_returns_single_currency_settings_for_available_currency(): void;
public function test_rejects_single_currency_settings_for_unavailable_currency(): void;
```

Use a state-builder test double that returns `USD`, `GBP`, and `JPY` currencies. Assert the exact WooPayments-compatible public config keys: `default_currency`, `selected_currency`, `charm_only_products`, and `currencies`, with per-currency `code`, `symbol`, `rate`, `decimals`, `decimal_sep`, `thousand_sep`, `symbol_pos`, `rounding`, and `charm`.

- [ ] **Step 2: Verify red**

Run:

```bash
pnpm --filter='@woocommerce/plugin-woocommerce' test:php:env -- --filter MultiCurrencyFrontendProjectionServiceTest
```

Expected: fail because `MultiCurrencyFrontendProjectionService` does not exist.

- [ ] **Step 3: Implement the minimal frontend projection service**

Create `MultiCurrencyFrontendProjectionService` with public read methods for:

```php
get_woocommerce_currency( ?string $order_currency = null ): string;
get_price_decimals( int $decimals, ?string $order_currency = null ): int;
get_price_decimal_separator( string $separator, ?string $order_currency = null ): string;
get_price_thousand_separator( string $separator, ?string $order_currency = null ): string;
get_woocommerce_price_format( string $format, ?string $order_currency = null ): string;
get_woocommerce_currency_pos( string $position, ?string $order_currency = null ): string;
add_currency_to_cart_hash( string $hash ): string;
get_store_currencies(): array;
get_single_currency_settings( string $currency_code ): array;
get_settings(): array;
get_public_config(): array;
is_cache_optimized_mode(): bool;
```

Keep the implementation read-only. Do not register filters, initialize sessions, set session values, update user meta, recalculate carts, update options, redirect URLs, or register REST routes.

- [ ] **Step 4: Verify green**

Run:

```bash
pnpm --filter='@woocommerce/plugin-woocommerce' test:php:env -- --filter 'MultiCurrencyFrontendProjectionServiceTest|MultiCurrencyGeolocationServiceTest'
```

Expected: PASS.

## Task 3: Integration Regression And Changelog

- [ ] **Step 1: Add focused regression coverage if container construction is needed**

If the services need container construction guarantees, add a test in the new projection test class using `wc_get_container()->get()`. Keep production bootstrap unchanged unless a real B1f owner needs boot-time registration.

- [ ] **Step 2: Add changelog**

Create:

```text
Significance: minor
Type: add
Comment: Add read-only native multi-currency frontend/config projections.
```

- [ ] **Step 3: Run B0-B1f regression**

Run:

```bash
pnpm --filter='@woocommerce/plugin-woocommerce' test:php:env -- --filter 'MultiCurrencyFrontendProjectionServiceTest|MultiCurrencyGeolocationServiceTest|MultiCurrencyShadowModeTest|MultiCurrencyShadowComparisonTest|MultiCurrencySurfaceDifferTest|MultiCurrencyPriceProjectionServiceTest|MultiCurrencyStateTest|MultiCurrencyStateBuilderTest|MultiCurrencyCurrencyTest|MultiCurrencyPriceCalculatorTest|MultiCurrencyDatabaseCacheTest|MultiCurrencyLocalizationServiceTest|MultiCurrencySettingsServiceTest|CurrencyRateProviderRegistryTest|WooPaymentsCurrencyRateProviderTest|MultiCurrencyRateServiceTest|MultiCurrencyRuntimeArbiterTest|MultiCurrencyDomainMapTest|NativePaymentsRuntimeArbiterTest'
```

Expected: PASS.

- [ ] **Step 4: Run static checks**

Run:

```bash
composer exec -- phpstan analyse $(find src/Internal/MultiCurrency -name '*.php' | sort) --memory-limit=2G
composer exec -- phpcs -s src/Internal/MultiCurrency tests/php/src/Internal/MultiCurrency
pnpm --filter='@woocommerce/plugin-woocommerce' lint:php:changes
git diff --check
markdownlint .agents/scratchpad/sessions/2026-06-15-core-native-payments/plans/2026-06-15-core-native-payments-b1f.md .agents/scratchpad/sessions/2026-06-15-core-native-payments/implementation-log.md
```

Expected: all checks pass. If directory PHPStan hits the known unmatched baseline ignore-pattern issue, rerun explicit-file PHPStan as shown and record that distinction.

- [ ] **Step 5: Commit**

Stage only WooCommerce production, test, and changelog files. Do not stage `.agents/*` or `docs/superpowers/*`.

Commit:

```bash
git commit -m "feat(payments): add native payments B1f multi-currency frontend projection"
```
