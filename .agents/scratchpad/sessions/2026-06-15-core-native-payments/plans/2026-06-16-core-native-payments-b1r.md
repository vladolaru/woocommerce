# Core Native Payments B1r Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use
> $subagent-driven-development (recommended) or $executing-plans to implement
> this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a read-only native admin notice projection for the
multi-currency manual-rate store-currency-changed warning.

**Architecture:** Create one focused projection service under
`src/Internal/MultiCurrency/Services/`. The service returns admin notice hook
metadata, notice metadata, notice markup, and dismiss intent from explicit
inputs. This slice remains non-mutating: no `add_action()`, no `$_GET` reads,
no nonce verification, no `current_user_can()`, no `wp_die()`, no option
updates, no logging, no WooPayments edit, and no WPCOM edit.

**Tech Stack:** WooCommerce core PHP, PHPUnit via `test:php:env`, WPCS,
PHPStan, markdownlint.

---

## Task 1: Add Admin Notice Projection Tests

**Files:**

- Create:
  `plugins/woocommerce/tests/php/src/Internal/MultiCurrency/Services/MultiCurrencyAdminNoticeProjectionServiceTest.php`

- [x] **Step 1: Write the failing test**

Add a `WC_Unit_Test_Case` covering these behaviors:

```php
$manifest = MultiCurrencyAdminNoticeProjectionService::get_hook_manifest();

$this->assertSame(
    array(
        array(
            'hook'     => 'admin_notices',
            'callback' => 'admin_notices',
            'priority' => 10,
        ),
        array(
            'hook'     => 'wp_loaded',
            'callback' => 'hide_notices',
            'priority' => 10,
        ),
    ),
    $manifest['actions']
);
```

Also specify:

- `get_manual_rate_notice()` returns a `currency_changed` notice only when the
  manual-rate currencies input is a non-empty array.
- The notice uses class `notice notice-warning`, is dismissible, and preserves
  the sentence-case message
  "The store currency was recently changed. The following currencies are set to
  manual rates and may need updates: %s".
- `get_notices_for_user()` returns no notices when the caller cannot manage
  WooCommerce.
- `get_notice_markup()` renders the notice wrapper, optional dismiss link, and
  KSES-filtered message without calling `wp_nonce_url()`.
- `get_hide_notice_intent()` returns a no-op for missing query args.
- `get_hide_notice_intent()` returns errors for invalid nonce, forbidden user,
  and unsupported notice key.
- `get_hide_notice_intent()` returns
  `array( 'should_hide' => true, 'option_name' => 'wcpay_multi_currency_show_store_currency_changed_notice', 'option_value' => 'no', 'error' => null )`
  when the explicit query, nonce-valid flag, and capability flag are valid.

- [x] **Step 2: Run test to verify it fails**

Run:

```bash
pnpm --filter='@woocommerce/plugin-woocommerce' test:php:env -- --filter MultiCurrencyAdminNoticeProjectionServiceTest
```

Expected: fail because `MultiCurrencyAdminNoticeProjectionService` does not
exist.

## Task 2: Implement Admin Notice Projection Service

**Files:**

- Create:
  `plugins/woocommerce/src/Internal/MultiCurrency/Services/MultiCurrencyAdminNoticeProjectionService.php`

- [x] **Step 1: Write minimal implementation**

Create `MultiCurrencyAdminNoticeProjectionService` with:

```php
public static function get_hook_manifest(): array {}

public static function get_manual_rate_notice(
    $manual_rate_currencies
): ?array {}

public static function get_notices_for_user(
    bool $can_manage_woocommerce,
    $manual_rate_currencies
): array {}

public static function get_notice_markup(
    array $notice,
    string $dismiss_url = ''
): string {}

public static function get_hide_notice_intent(
    array $query,
    bool $nonce_valid,
    bool $can_manage_woocommerce
): array {}
```

Implementation requirements:

- Preserve WooPayments hook names, callback markers, default priorities, query
  arg names, nonce action/name metadata, notice key, option name, dismiss option
  value, CSS class, dismiss-link class/style, and merchant-facing message.
- Accept manual-rate currencies, user capability, nonce validity, and query
  arguments as explicit inputs.
- Return metadata, markup, and intent arrays only; do not register hooks, read
  globals, verify nonces, call `wp_die()`, or update options.
- Use the WooCommerce text domain for projected core copy.

- [x] **Step 2: Run focused test to verify it passes**

Run the Task 1 command again.

Expected: pass.

## Task 3: Add Changelog And Verification

**Files:**

- Create:
  `plugins/woocommerce/changelog/add-native-payments-b1r-multi-currency-admin-notice-projection`

- [x] **Step 1: Add changelog entry**

Use this changelog content:

```text
Significance: minor
Type: add

Add native payments multi-currency admin notice projection.
```

- [x] **Step 2: Run regression and static checks**

Run:

```bash
pnpm --filter='@woocommerce/plugin-woocommerce' test:php:env -- --filter 'MultiCurrencyAdminNoticeProjectionServiceTest|MultiCurrencyUserSettingsProjectionServiceTest|MultiCurrencyStorefrontProjectionServiceTest|MultiCurrencySettingsProjectionServiceTest|MultiCurrencyAsyncPriceProjectionServiceTest|MultiCurrencyCompatibilityProjectionServiceTest|MultiCurrencyRestProjectionServiceTest|MultiCurrencySwitcherProjectionServiceTest|MultiCurrencyTrackingOrderCountProjectionServiceTest|MultiCurrencyTrackingProjectionServiceTest|MultiCurrencyAnalyticsSqlProjectionServiceTest|MultiCurrencyAnalyticsProjectionServiceTest|MultiCurrencyFrontendProjectionServiceTest|MultiCurrencyGeolocationServiceTest|MultiCurrencyShadowModeTest|MultiCurrencyShadowComparisonTest|MultiCurrencySurfaceDifferTest|MultiCurrencyPriceProjectionServiceTest|MultiCurrencyStateTest|MultiCurrencyStateBuilderTest|MultiCurrencyCurrencyTest|MultiCurrencyPriceCalculatorTest|MultiCurrencyDatabaseCacheTest|MultiCurrencyLocalizationServiceTest|MultiCurrencySettingsServiceTest|CurrencyRateProviderRegistryTest|WooPaymentsCurrencyRateProviderTest|MultiCurrencyRateServiceTest|MultiCurrencyRuntimeArbiterTest|MultiCurrencyDomainMapTest|NativePaymentsRuntimeArbiterTest'
```

Run from `plugins/woocommerce`:

```bash
composer exec -- phpstan analyse $(find src/Internal/MultiCurrency -name '*.php' | sort) --memory-limit=2G
composer exec -- phpcs -s src/Internal/MultiCurrency tests/php/src/Internal/MultiCurrency
```

Run from the repo root:

```bash
pnpm --filter='@woocommerce/plugin-woocommerce' lint:php:changes
markdownlint .agents/scratchpad/sessions/2026-06-15-core-native-payments/plans/2026-06-16-core-native-payments-b1r.md .agents/scratchpad/sessions/2026-06-15-core-native-payments/implementation-log.md
git diff --cached --check
git diff --check
pnpm --filter='@woocommerce/plugin-woocommerce' lint:php:changes:staged
```

- [x] **Step 3: Commit**

Stage only core/changelog files, not local plan/log artifacts, then commit:

```bash
git add plugins/woocommerce/src/Internal/MultiCurrency/Services/MultiCurrencyAdminNoticeProjectionService.php plugins/woocommerce/tests/php/src/Internal/MultiCurrency/Services/MultiCurrencyAdminNoticeProjectionServiceTest.php plugins/woocommerce/changelog/add-native-payments-b1r-multi-currency-admin-notice-projection
git commit -m "feat(payments): add native payments B1r multi-currency admin notice projection"
```

Self-review:

- B1r covers admin notice hook metadata, manual-rate notice metadata, notice
  markup, dismiss-link metadata, and sanitized hide intent.
- No WPCOM or WooPayments client file is modified.
- No hooks, request globals, nonces, capabilities, `wp_die()`, options, logs,
  sessions, or order data are mutated.
