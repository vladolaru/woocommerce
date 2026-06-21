# Core Native Payments B1s Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use
> $subagent-driven-development (recommended) or $executing-plans to implement
> this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a read-only native WC Admin inbox note projection for the
multi-currency availability note.

**Architecture:** Create one focused projection service under
`src/Internal/MultiCurrency/Services/`. The service returns admin hook
metadata, note metadata, add-note eligibility diagnostics, and delete-note
metadata from explicit inputs. This slice remains non-mutating: no
`Automattic\WooCommerce\Admin\Notes\Note` instantiation, no note saves or
deletes, no Ajax/global reads, no WooPayments edit, and no WPCOM edit.

**Tech Stack:** WooCommerce core PHP, PHPUnit via `test:php:env`, WPCS,
PHPStan, markdownlint.

---

## Task 1: Add Admin Note Projection Tests

**Files:**

- Create:
  `plugins/woocommerce/tests/php/src/Internal/MultiCurrency/Services/MultiCurrencyAdminNoteProjectionServiceTest.php`

- [x] **Step 1: Write the failing test**

Add a `WC_Unit_Test_Case` covering these behaviors:

```php
$manifest = MultiCurrencyAdminNoteProjectionService::get_note_manifest();

$this->assertSame( 'wc-payments-notes-multi-currency-available', $manifest['name'] );
$this->assertSame( 'Sell worldwide in multiple currencies', $manifest['title'] );
$this->assertSame( 'info', $manifest['type'] );
```

Also specify:

- `get_hook_manifest( true )` returns an `admin_init` action with callback
  `add_woo_admin_notes` and priority `10`; `get_hook_manifest( false )`
  returns no actions.
- `get_note_manifest()` preserves WooPayments note title, content, source
  `woocommerce-payments`, setup URL, action name, action label, action status
  `unactioned`, and primary flag.
- `supports_wc_admin_notes()` is true for `4.4.0` and higher, and false for
  lower versions or an empty version string.
- `get_add_note_manifest()` returns blockers for Ajax requests, unsupported WC
  versions, disconnected providers, and notes that cannot be added.
- `get_add_note_manifest()` returns `should_add => true` and the note manifest
  when all explicit inputs pass.
- `get_delete_note_manifest()` returns `should_delete => true` and the note name
  for supported WC versions, otherwise an unsupported-version blocker.

- [x] **Step 2: Run test to verify it fails**

Run:

```bash
pnpm --filter='@woocommerce/plugin-woocommerce' test:php:env -- --filter MultiCurrencyAdminNoteProjectionServiceTest
```

Expected: fail because `MultiCurrencyAdminNoteProjectionService` does not
exist.

## Task 2: Implement Admin Note Projection Service

**Files:**

- Create:
  `plugins/woocommerce/src/Internal/MultiCurrency/Services/MultiCurrencyAdminNoteProjectionService.php`

- [x] **Step 1: Write minimal implementation**

Create `MultiCurrencyAdminNoteProjectionService` with:

```php
public static function get_hook_manifest(
    bool $is_admin
): array {}

public static function get_note_manifest(): array {}

public static function supports_wc_admin_notes(
    string $wc_version
): bool {}

public static function get_add_note_manifest(
    bool $is_ajax,
    string $wc_version,
    bool $provider_connected,
    bool $can_be_added
): array {}

public static function get_delete_note_manifest(
    string $wc_version
): array {}
```

Implementation requirements:

- Preserve WooPayments note name, source, title, content, action name, action
  label, setup URL, status, and primary flag.
- Preserve the WC Admin version threshold of `4.4.0`.
- Accept Ajax state, WC version, provider connectivity, and note availability as
  explicit inputs.
- Return stable blocker keys:
  `ajax_request`, `unsupported_wc_version`, `provider_not_connected`, and
  `note_cannot_be_added`.
- Return metadata only; do not instantiate `Note`, call `save()`, call
  `possibly_delete_note()`, read globals, or register hooks.
- Use the WooCommerce text domain for projected core copy while preserving the
  existing sentence-case wording.

- [x] **Step 2: Run focused test to verify it passes**

Run the Task 1 command again.

Expected: pass.

## Task 3: Add Changelog And Verification

**Files:**

- Create:
  `plugins/woocommerce/changelog/add-native-payments-b1s-multi-currency-admin-note-projection`

- [x] **Step 1: Add changelog entry**

Use this changelog content:

```text
Significance: minor
Type: add

Add native payments multi-currency admin note projection.
```

- [x] **Step 2: Run regression and static checks**

Run:

```bash
pnpm --filter='@woocommerce/plugin-woocommerce' test:php:env -- --filter 'MultiCurrencyAdminNoteProjectionServiceTest|MultiCurrencyAdminNoticeProjectionServiceTest|MultiCurrencyUserSettingsProjectionServiceTest|MultiCurrencyStorefrontProjectionServiceTest|MultiCurrencySettingsProjectionServiceTest|MultiCurrencyAsyncPriceProjectionServiceTest|MultiCurrencyCompatibilityProjectionServiceTest|MultiCurrencyRestProjectionServiceTest|MultiCurrencySwitcherProjectionServiceTest|MultiCurrencyTrackingOrderCountProjectionServiceTest|MultiCurrencyTrackingProjectionServiceTest|MultiCurrencyAnalyticsSqlProjectionServiceTest|MultiCurrencyAnalyticsProjectionServiceTest|MultiCurrencyFrontendProjectionServiceTest|MultiCurrencyGeolocationServiceTest|MultiCurrencyShadowModeTest|MultiCurrencyShadowComparisonTest|MultiCurrencySurfaceDifferTest|MultiCurrencyPriceProjectionServiceTest|MultiCurrencyStateTest|MultiCurrencyStateBuilderTest|MultiCurrencyCurrencyTest|MultiCurrencyPriceCalculatorTest|MultiCurrencyDatabaseCacheTest|MultiCurrencyLocalizationServiceTest|MultiCurrencySettingsServiceTest|CurrencyRateProviderRegistryTest|WooPaymentsCurrencyRateProviderTest|MultiCurrencyRateServiceTest|MultiCurrencyRuntimeArbiterTest|MultiCurrencyDomainMapTest|NativePaymentsRuntimeArbiterTest'
```

Run from `plugins/woocommerce`:

```bash
composer exec -- phpstan analyse $(find src/Internal/MultiCurrency -name '*.php' | sort) --memory-limit=2G
composer exec -- phpcs -s src/Internal/MultiCurrency tests/php/src/Internal/MultiCurrency
```

Run from the repo root:

```bash
pnpm --filter='@woocommerce/plugin-woocommerce' lint:php:changes
markdownlint .agents/scratchpad/sessions/2026-06-15-core-native-payments/plans/2026-06-16-core-native-payments-b1s.md .agents/scratchpad/sessions/2026-06-15-core-native-payments/implementation-log.md
git diff --cached --check
git diff --check
pnpm --filter='@woocommerce/plugin-woocommerce' lint:php:changes:staged
```

- [x] **Step 3: Commit**

Stage only core/changelog files, not local plan/log artifacts, then commit:

```bash
git add plugins/woocommerce/src/Internal/MultiCurrency/Services/MultiCurrencyAdminNoteProjectionService.php plugins/woocommerce/tests/php/src/Internal/MultiCurrency/Services/MultiCurrencyAdminNoteProjectionServiceTest.php plugins/woocommerce/changelog/add-native-payments-b1s-multi-currency-admin-note-projection
git commit -m "feat(payments): add native payments B1s multi-currency admin note projection"
```

Self-review:

- B1s covers admin hook metadata, note metadata, add-note eligibility, delete
  metadata, and WC version threshold behavior.
- No WPCOM or WooPayments client file is modified.
- No hooks, globals, notes, options, sessions, logs, or order data are mutated.
