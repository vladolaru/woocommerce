# Core Native Payments B1t Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use
> $subagent-driven-development (recommended) or $executing-plans to implement
> this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a read-only native logger projection for multi-currency log calls.

**Architecture:** Create one focused projection service under
`src/Internal/MultiCurrency/Services/`. The service returns the preserved log
source, supported public log levels, runtime availability blockers, and log call
metadata from explicit inputs. This slice remains non-mutating: no
`wc_get_logger()` calls, no log writes, no WooPayments edit, and no WPCOM edit.

**Tech Stack:** WooCommerce core PHP, PHPUnit via `test:php:env`, WPCS,
PHPStan, markdownlint.

---

## Task 1: Add Logger Projection Tests

**Files:**

- Create:
  `plugins/woocommerce/tests/php/src/Internal/MultiCurrency/Services/MultiCurrencyLoggerProjectionServiceTest.php`

- [x] **Step 1: Write the failing test**

Add a `WC_Unit_Test_Case` covering these behaviors:

```php
$manifest = MultiCurrencyLoggerProjectionService::get_log_manifest(
    'debug',
    'Currency converted.',
    true
);

$this->assertSame( 'woopayments-multi-currency', $manifest['context']['source'] );
$this->assertSame( 'debug', $manifest['level'] );
$this->assertSame( 'Currency converted.', $manifest['message'] );
```

Also specify:

- `get_log_source()` returns `woopayments-multi-currency`.
- `get_supported_levels()` returns `debug`, `error`, and `notice`.
- `get_runtime_blockers()` returns `wc_logger_unavailable` when the caller says
  the WooCommerce logger is unavailable.
- `get_log_manifest()` returns `should_log => false` with
  `wc_logger_unavailable` when logger runtime is unavailable.
- `get_log_manifest()` returns `should_log => false` with `unsupported_level`
  for levels outside the public WooPayments logger methods.
- `get_debug_manifest()`, `get_error_manifest()`, and
  `get_notice_manifest()` project the preserved level names and source context.

- [x] **Step 2: Run test to verify it fails**

Run:

```bash
pnpm --filter='@woocommerce/plugin-woocommerce' test:php:env -- --filter MultiCurrencyLoggerProjectionServiceTest
```

Expected: fail because `MultiCurrencyLoggerProjectionService` does not exist.

## Task 2: Implement Logger Projection Service

**Files:**

- Create:
  `plugins/woocommerce/src/Internal/MultiCurrency/Services/MultiCurrencyLoggerProjectionService.php`

- [x] **Step 1: Write minimal implementation**

Create `MultiCurrencyLoggerProjectionService` with:

```php
public static function get_log_source(): string {}

public static function get_supported_levels(): array {}

public static function get_runtime_blockers(
    bool $wc_logger_available
): array {}

public static function get_log_manifest(
    string $level,
    string $message,
    bool $wc_logger_available
): array {}

public static function get_debug_manifest(
    string $message,
    bool $wc_logger_available
): array {}

public static function get_error_manifest(
    string $message,
    bool $wc_logger_available
): array {}

public static function get_notice_manifest(
    string $message,
    bool $wc_logger_available
): array {}
```

Implementation requirements:

- Preserve WooPayments log source `woopayments-multi-currency`.
- Preserve the public WooPayments logger levels: `debug`, `error`, and
  `notice`.
- Accept logger runtime availability as an explicit input instead of checking
  `function_exists( 'wc_get_logger' )`.
- Return log call metadata only; do not call `wc_get_logger()` or write logs.

- [x] **Step 2: Run focused test to verify it passes**

Run the Task 1 command again.

Expected: pass.

## Task 3: Add Changelog And Verification

**Files:**

- Create:
  `plugins/woocommerce/changelog/add-native-payments-b1t-multi-currency-logger-projection`

- [x] **Step 1: Add changelog entry**

Use this changelog content:

```text
Significance: minor
Type: add

Add native payments multi-currency logger projection.
```

- [x] **Step 2: Run regression and static checks**

Run:

```bash
pnpm --filter='@woocommerce/plugin-woocommerce' test:php:env -- --filter 'MultiCurrencyLoggerProjectionServiceTest|MultiCurrencyAdminNoteProjectionServiceTest|MultiCurrencyAdminNoticeProjectionServiceTest|MultiCurrencyUserSettingsProjectionServiceTest|MultiCurrencyStorefrontProjectionServiceTest|MultiCurrencySettingsProjectionServiceTest|MultiCurrencyAsyncPriceProjectionServiceTest|MultiCurrencyCompatibilityProjectionServiceTest|MultiCurrencyRestProjectionServiceTest|MultiCurrencySwitcherProjectionServiceTest|MultiCurrencyTrackingOrderCountProjectionServiceTest|MultiCurrencyTrackingProjectionServiceTest|MultiCurrencyAnalyticsSqlProjectionServiceTest|MultiCurrencyAnalyticsProjectionServiceTest|MultiCurrencyFrontendProjectionServiceTest|MultiCurrencyGeolocationServiceTest|MultiCurrencyShadowModeTest|MultiCurrencyShadowComparisonTest|MultiCurrencySurfaceDifferTest|MultiCurrencyPriceProjectionServiceTest|MultiCurrencyStateTest|MultiCurrencyStateBuilderTest|MultiCurrencyCurrencyTest|MultiCurrencyPriceCalculatorTest|MultiCurrencyDatabaseCacheTest|MultiCurrencyLocalizationServiceTest|MultiCurrencySettingsServiceTest|CurrencyRateProviderRegistryTest|WooPaymentsCurrencyRateProviderTest|MultiCurrencyRateServiceTest|MultiCurrencyRuntimeArbiterTest|MultiCurrencyDomainMapTest|NativePaymentsRuntimeArbiterTest'
```

Run from `plugins/woocommerce`:

```bash
composer exec -- phpstan analyse $(find src/Internal/MultiCurrency -name '*.php' | sort) --memory-limit=2G
composer exec -- phpcs -s src/Internal/MultiCurrency tests/php/src/Internal/MultiCurrency
```

Run from the repo root:

```bash
pnpm --filter='@woocommerce/plugin-woocommerce' lint:php:changes
markdownlint .agents/scratchpad/sessions/2026-06-15-core-native-payments/plans/2026-06-16-core-native-payments-b1t.md .agents/scratchpad/sessions/2026-06-15-core-native-payments/implementation-log.md
git diff --cached --check
git diff --check
pnpm --filter='@woocommerce/plugin-woocommerce' lint:php:changes:staged
```

- [x] **Step 3: Commit**

Stage only core/changelog files, not local plan/log artifacts, then commit:

```bash
git add plugins/woocommerce/src/Internal/MultiCurrency/Services/MultiCurrencyLoggerProjectionService.php plugins/woocommerce/tests/php/src/Internal/MultiCurrency/Services/MultiCurrencyLoggerProjectionServiceTest.php plugins/woocommerce/changelog/add-native-payments-b1t-multi-currency-logger-projection
git commit -m "feat(payments): add native payments B1t multi-currency logger projection"
```

Self-review:

- B1t covers log source, supported levels, runtime blockers, and log call
  metadata.
- No WPCOM or WooPayments client file is modified.
- No logger instances are fetched and no log entries are written.
