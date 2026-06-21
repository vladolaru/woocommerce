# Core Native Payments B1k Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use
> $subagent-driven-development (recommended) or $executing-plans to implement
> this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a read-only native multi-currency switcher projection that
matches WooPayments widget/block markup behavior without registering runtime
surfaces.

**Architecture:** Create one focused projection service under
`src/Internal/MultiCurrency/Services/` that consumes
`MultiCurrencyStateBuilder`, renders widget and dynamic block markup from
supplied state/query data, and derives optional flag labels without copying
WooPayments source tables. Keep this as projection only: no widget registration,
block registration, script enqueueing, request mutation, WPCOM edit, or
WooPayments client edit.

**Tech Stack:** WooCommerce core PHP, PHPUnit via `test:php:env`, WPCS,
PHPStan, markdownlint.

---

## Task 1: Add Switcher Projection Tests

**Files:**

- Create:
  `plugins/woocommerce/tests/php/src/Internal/MultiCurrency/Services/MultiCurrencySwitcherProjectionServiceTest.php`

- [ ] **Step 1: Write the failing test**

Add a `WC_Unit_Test_Case` that constructs a fake
`MultiCurrencyStateBuilder`, uses real `MultiCurrencyCurrency` value objects,
and specifies these behaviors:

```php
$markup = $sut->get_widget_markup(
    array( 'title' => 'Shop currency', 'symbol' => true, 'flag' => true ),
    array( 'before_widget' => '<section>', 'after_widget' => '</section>' ),
    array( 's' => 'shirts', 'currency' => 'EUR' )
);

$this->assertStringContainsString( '<section>', $markup );
$this->assertStringContainsString( 'aria-label="Shop currency"', $markup );
$this->assertStringContainsString( 'name="s" value="shirts"', $markup );
$this->assertStringNotContainsString( 'name="currency"', $markup );
$this->assertStringContainsString( '🇬🇧 £ GBP', $markup );
$this->assertStringContainsString( 'value="GBP" selected', $markup );
```

Also specify:

- The block projection wraps the select in `currency-switcher-holder`.
- Block styles are escaped and include expected font, border, and colors.
- Empty title still gives the select an accessible name of `Currency`.
- Switching-disabled or single-currency state returns an empty string.

- [ ] **Step 2: Run test to verify it fails**

Run:

```bash
pnpm --filter='@woocommerce/plugin-woocommerce' test:php:env -- --filter MultiCurrencySwitcherProjectionServiceTest
```

Expected: fail because `MultiCurrencySwitcherProjectionService` does not exist.

## Task 2: Implement Switcher Projection Service

**Files:**

- Create:
  `plugins/woocommerce/src/Internal/MultiCurrency/Services/MultiCurrencySwitcherProjectionService.php`

- [ ] **Step 1: Write minimal implementation**

Create `MultiCurrencySwitcherProjectionService` with:

```php
public function __construct( MultiCurrencyStateBuilder $state_builder ) {}

public function get_widget_markup(
    array $instance = array(),
    array $args = array(),
    array $query_args = array(),
    bool $switching_disabled = false
): string {}

public function get_block_markup(
    array $block_attributes = array(),
    array $query_args = array(),
    bool $switching_disabled = false
): string {}
```

Implementation requirements:

- Use `wp_parse_args()` for widget settings and wrapper defaults.
- Use `apply_filters( 'widget_title', ... )` for widget title parity.
- Render `<form>`, hidden query inputs except `currency`, and
  `class="js-woopayments-currency-switcher"`.
- Render enabled currencies from `MultiCurrencyState`.
- Use the selected currency for the selected `<option>`.
- Preserve symbol/flag label ordering: flag, symbol, code.
- Derive flags from currency code exceptions and ISO regional indicators.
- Add `aria-label` to widget and block selects.
- Escape all dynamic attributes and text.
- Return an empty string for disabled switching or one enabled currency.

- [ ] **Step 2: Run focused test to verify it passes**

Run the Task 1 command again.

Expected: pass.

## Task 3: Add Changelog And Verification

**Files:**

- Create:
  `plugins/woocommerce/changelog/add-native-payments-b1k-multi-currency-switcher-projection`

- [ ] **Step 1: Add changelog entry**

Use this changelog content:

```text
Significance: minor
Type: add

Add native payments multi-currency switcher projection.
```

- [ ] **Step 2: Run regression and static checks**

Run:

```bash
pnpm --filter='@woocommerce/plugin-woocommerce' test:php:env -- --filter 'MultiCurrencySwitcherProjectionServiceTest|MultiCurrencyTrackingOrderCountProjectionServiceTest|MultiCurrencyTrackingProjectionServiceTest|MultiCurrencyAnalyticsSqlProjectionServiceTest|MultiCurrencyAnalyticsProjectionServiceTest|MultiCurrencyFrontendProjectionServiceTest|MultiCurrencyGeolocationServiceTest|MultiCurrencyShadowModeTest|MultiCurrencyShadowComparisonTest|MultiCurrencySurfaceDifferTest|MultiCurrencyPriceProjectionServiceTest|MultiCurrencyStateTest|MultiCurrencyStateBuilderTest|MultiCurrencyCurrencyTest|MultiCurrencyPriceCalculatorTest|MultiCurrencyDatabaseCacheTest|MultiCurrencyLocalizationServiceTest|MultiCurrencySettingsServiceTest|CurrencyRateProviderRegistryTest|WooPaymentsCurrencyRateProviderTest|MultiCurrencyRateServiceTest|MultiCurrencyRuntimeArbiterTest|MultiCurrencyDomainMapTest|NativePaymentsRuntimeArbiterTest'
```

Run from `plugins/woocommerce`:

```bash
composer exec -- phpstan analyse $(find src/Internal/MultiCurrency -name '*.php' | sort) --memory-limit=2G
composer exec -- phpcs -s src/Internal/MultiCurrency tests/php/src/Internal/MultiCurrency
```

Run from the repo root:

```bash
pnpm --filter='@woocommerce/plugin-woocommerce' lint:php:changes
markdownlint .agents/scratchpad/sessions/2026-06-15-core-native-payments/plans/2026-06-16-core-native-payments-b1k.md .agents/scratchpad/sessions/2026-06-15-core-native-payments/implementation-log.md
```

- [ ] **Step 3: Commit**

Stage only core/changelog files, not local plan/log artifacts, then commit:

```bash
git add plugins/woocommerce/src/Internal/MultiCurrency/Services/MultiCurrencySwitcherProjectionService.php plugins/woocommerce/tests/php/src/Internal/MultiCurrency/Services/MultiCurrencySwitcherProjectionServiceTest.php plugins/woocommerce/changelog/add-native-payments-b1k-multi-currency-switcher-projection
git commit -m "feat(payments): add native payments B1k multi-currency switcher projection"
```

Self-review:

- B1k covers widget markup, block markup, query preservation, option labels,
  disabled/single-currency empty output, and accessible select names.
- No WPCOM or WooPayments client file is modified.
- No runtime registration or mutating request behavior is introduced.
