---
session: 2026-06-15-core-native-payments
type: plan
by: codex
created: 2026-06-19 07:13
target: A4p native PM promotions and spotlight parity
reconciles:
  - analysis-a4p-pm-promotions-spotlight-parity.md
  - supervisor-prompt-2026-06-18-2344-N12.md
status: draft
---

# A4p Native PM Promotions And Spotlight Parity Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use $subagent-driven-development (recommended) or $executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Restore reference-equivalent WooPayments PM promotions support in native Core admin: source-backed promotions, activation/dismissal, settings row badges, spotlight UI, and gates.

**Architecture:** Keep promotion business rules in a provider-level service, not in raw REST or UI glue. `WooPaymentsApiClient` owns platform transport, `WooPaymentsPmPromotionsService` owns filtering/cache/dismissals/activation, the native REST route exposes `/wc/v3/payments/pm-promotions`, and WooPayments admin chunks consume a dedicated promotions store and scoped components.

**Tech Stack:** WooCommerce Core PHP services/DI, WordPress REST API, WooPayments native API transport, Jest/RTL, WordPress data controls, React/TypeScript, SCSS, Playwriter browser verification.

---

## File Map

- Modify `plugins/woocommerce/src/Internal/Payments/Providers/WooPayments/Api/WooPaymentsApiClient.php`: add `payment_method_promotions` transport methods and preserved legacy request hooks.
- Add `plugins/woocommerce/src/Internal/Payments/Providers/WooPayments/WooPaymentsPmPromotionsService.php`: fetch/cache/filter/normalize promotions, handle dismissals, activation, and settings-save implicit activation.
- Modify `plugins/woocommerce/src/Internal/Payments/Providers/WooPayments/WooPaymentsSettingsService.php`: expose source-backed `pm_promotions`, inject the promotions service, and activate visible promotions before enabling a newly selected PM.
- Add `plugins/woocommerce/src/Internal/Payments/Providers/WooPayments/WooPaymentsPmPromotionsRestController.php`: register native `/wc/v3/payments/pm-promotions` routes.
- Modify `plugins/woocommerce/includes/class-woocommerce.php`: register the new REST controller hook owner.
- Modify PHP tests under `plugins/woocommerce/tests/php/src/Internal/Payments/Providers/WooPayments/` and `plugins/woocommerce/tests/php/src/Internal/Admin/Settings/PaymentsProviders/WooPayments/`: cover transport, service, settings integration, and REST routes.
- Add `plugins/woocommerce/client/admin/client/woopayments/promotions/data/*`: native PM promotions store, actions, selectors, resolvers, hooks, and types.
- Add `plugins/woocommerce/client/admin/client/woopayments/promotions/spotlight/*`: native spotlight container/component and styles.
- Modify `plugins/woocommerce/client/admin/client/woopayments/settings/payment-methods-list.tsx`: render badge promotions when no discount badge exists.
- Modify `plugins/woocommerce/client/admin/client/woopayments/settings/settings-page.tsx`: pass PM promotions into settings lists and mount spotlight.
- Modify native dashboard entry points under `plugins/woocommerce/client/admin/client/woopayments/admin/`: mount spotlight on overview, payouts, transactions, and disputes routes that exist today.
- Modify focused JS tests under `plugins/woocommerce/client/admin/client/woopayments/**/test`: store, spotlight, row badge, and mount coverage.
- Modify ignored harness gate `tools/woopayments-merge/a4-admin-surface-gate.py`: assert PM promotions route/store/spotlight coverage.
- Add changelog `plugins/woocommerce/changelog/fix-native-payments-a4p-pm-promotions-parity`.
- Update session logs and baseline: `implementation-log.md`, `staging-log.md`, and `spec-conformance-baseline.md`.

## Task 1: Backend RED Contract Tests

**Files:**
- Modify `plugins/woocommerce/tests/php/src/Internal/Payments/Providers/WooPayments/Api/WooPaymentsApiClientTest.php`
- Add `plugins/woocommerce/tests/php/src/Internal/Payments/Providers/WooPayments/WooPaymentsPmPromotionsServiceTest.php`
- Modify `plugins/woocommerce/tests/php/src/Internal/Payments/Providers/WooPayments/WooPaymentsSettingsServiceTest.php`
- Modify or add REST controller tests under `plugins/woocommerce/tests/php/src/Internal/Admin/Settings/PaymentsProviders/WooPayments/`

- [ ] **Step 1: Add API client RED tests.**

Add tests proving native transport uses:

```php
$sut->get_pm_promotions( array( 'dismissals' => array( 'klarna-promo__spotlight' => 1781740800 ), 'locale' => 'en_US' ) );
// Expected path starts with /sites/123/wcpay/payment_method_promotions? and includes JSON-encoded dismissals, locale, and test_mode.

$sut->activate_pm_promotion( 'klarna-promo__spotlight' );
// Expected path /sites/123/wcpay/payment_method_promotions/klarna-promo__spotlight/activate and method POST.
```

Add legacy filter assertions for `wcpay_get_pm_promotions_request` and `wcpay_activate_pm_promotion_request`.

- [ ] **Step 2: Add service RED tests.**

Create `WooPaymentsPmPromotionsServiceTest` with fixtures covering:

```php
test_get_visible_promotions_filters_invalid_enabled_dismissed_discounted_and_duplicate_promo_ids()
test_get_visible_promotions_normalizes_titles_cta_terms_badge_type_and_html()
test_dismiss_promotion_stores_timestamp_and_hides_promotion()
test_activate_promotion_calls_platform_marks_dismissed_enables_method_and_clears_cache()
test_maybe_activate_promotion_for_payment_method_runs_before_settings_enable()
test_get_visible_promotions_returns_null_without_manage_woocommerce()
```

Use injectable test doubles for API client, account service, and gateway/settings persistence. Do not call WPCOM or Stripe.

- [ ] **Step 3: Add settings integration RED tests.**

Extend `WooPaymentsSettingsServiceTest` so `get_settings()` returns source-backed `pm_promotions` and `update_settings()` calls `maybe_activate_promotion_for_payment_method( 'klarna' )` before the updated `upe_enabled_payment_method_ids` makes Klarna enabled.

- [ ] **Step 4: Add REST RED tests.**

Add controller tests for:

```php
GET /wc/v3/payments/pm-promotions
POST /wc/v3/payments/pm-promotions/klarna-promo__spotlight/activate
POST /wc/v3/payments/pm-promotions/klarna-promo__spotlight/dismiss
```

Assert native runtime gating, `manage_woocommerce` permission, flat array response, `{ success: bool }` action responses, and ID validation rejecting path separators or percent-encoded separators.

- [ ] **Step 5: Run RED PHP tests.**

Run:

```bash
pnpm --filter='@woocommerce/plugin-woocommerce' test:php:env -- --filter 'WooPaymentsApiClientTest|WooPaymentsPmPromotionsServiceTest|WooPaymentsSettingsServiceTest|WooPaymentsRestControllerTest'
```

Expected: focused failures due to missing native methods/classes/routes or hard-coded empty `pm_promotions`.

## Task 2: Backend GREEN Implementation

**Files:**
- Modify `plugins/woocommerce/src/Internal/Payments/Providers/WooPayments/Api/WooPaymentsApiClient.php`
- Add `plugins/woocommerce/src/Internal/Payments/Providers/WooPayments/WooPaymentsPmPromotionsService.php`
- Modify `plugins/woocommerce/src/Internal/Payments/Providers/WooPayments/WooPaymentsSettingsService.php`
- Add `plugins/woocommerce/src/Internal/Payments/Providers/WooPayments/WooPaymentsPmPromotionsRestController.php`
- Modify `plugins/woocommerce/includes/class-woocommerce.php`

- [ ] **Step 1: Add transport methods.**

Add `PAYMENT_METHOD_PROMOTIONS_API = 'payment_method_promotions'`, `get_pm_promotions( array $store_context ): array`, and `activate_pm_promotion( string $id ): array`. Validate IDs with `^[a-zA-Z0-9_-]+$` before interpolation. Preserve legacy request hooks with `request_with_legacy_filter()` where possible; if raw response headers are needed for cache TTL, return a normalized body plus cache metadata from the service using the existing non-raw transport behavior and default TTL until a raw-response transport seam is explicitly needed.

- [ ] **Step 2: Implement provider service.**

`WooPaymentsPmPromotionsService` should:

- Constants: `PROMOTIONS_CACHE_KEY = 'wcpay_pm_promotions'`, `PROMOTION_DISMISSALS_OPTION = '_wcpay_pm_promotion_dismissals'`.
- `get_visible_promotions(): ?array` returns `null` without `manage_woocommerce`, memoizes per request, fetches cached/remote promotions, validates/filter/normalizes, and returns `array_values()` or `null`.
- Filter against enabled native payment methods, valid supported payment method IDs, local dismissals, active fee discounts, and first `promo_id` per PM.
- Sanitize IDs with `sanitize_key`, text with `sanitize_text_field`, URL fields with `esc_url_raw`, spotlight descriptions/footnotes with light HTML, badge descriptions with links only.
- `dismiss_promotion( string $id ): bool` requires a visible promotion, stores timestamp, resets memo, and records Tracks if available.
- `activate_promotion( string $id ): bool` requires a visible promotion, calls platform activation, dismisses, enables/syncs the method in the native settings option, clears cache, clears native account cache if the account service exposes it, and records success/failure Tracks.
- `maybe_activate_promotion_for_payment_method( string $payment_method_id ): bool` finds the visible promotion before settings updates make the PM enabled and activates it without duplicating the enable operation.

- [ ] **Step 3: Wire settings service.**

Inject `?WooPaymentsPmPromotionsService` into `WooPaymentsSettingsService::init()`. Return `$this->get_pm_promotions_service()->get_visible_promotions() ?? array()` for `pm_promotions`. In `update_settings()`, when `enabled_payment_method_ids` is present, diff the previous enabled IDs against requested IDs and call `maybe_activate_promotion_for_payment_method()` for newly enabled IDs before persisting the updated IDs.

- [ ] **Step 4: Add REST controller and bootstrap.**

Create `WooPaymentsPmPromotionsRestController implements RegisterHooksInterface`, matching native controller patterns: namespace `wc/v3`, native arbiter gate, `rest_api_init`, `check_permission()`, route ID validation, GET/POST callbacks, and `WP_Error` conversion. Register it from `class-woocommerce.php` with the other native hook-owning classes.

- [ ] **Step 5: Run GREEN PHP tests.**

Run the Task 1 command again. Expected: focused PHP tests pass without WPCOM access.

## Task 3: Frontend RED Store And UI Tests

**Files:**
- Add or modify tests under `plugins/woocommerce/client/admin/client/woopayments/promotions/test/`
- Modify `plugins/woocommerce/client/admin/client/woopayments/settings/test/settings-data.test.ts`
- Modify `plugins/woocommerce/client/admin/client/woopayments/settings/test/settings-page.test.tsx`
- Modify or add `plugins/woocommerce/client/admin/client/woopayments/settings/test/payment-methods-list.test.tsx`
- Modify dashboard tests under `plugins/woocommerce/client/admin/client/woopayments/admin/test/`

- [ ] **Step 1: Add PM promotions store RED tests.**

Assert:

```ts
getPmPromotions() resolves with API_FETCH path '/wc/v3/payments/pm-promotions'.
activatePmPromotion( 'klarna-promo__spotlight' ) POSTs '/wc/v3/payments/pm-promotions/klarna-promo__spotlight/activate'.
dismissPmPromotion( 'klarna-promo__spotlight' ) POSTs '/wc/v3/payments/pm-promotions/klarna-promo__spotlight/dismiss'.
Invalid resolver responses create the preserved error notice and do not set promotions.
```

- [ ] **Step 2: Add Spotlight RED tests.**

Port the reference behavior into native tests: renders first spotlight, hides while loading/empty/no spotlight, records `wcpay_payment_method_promotion_*` events, activates/dismisses by ID, opens only safe `http:`/`https:` terms URLs, and includes badge/footnote/image fields.

- [ ] **Step 3: Add row badge RED tests.**

Assert badge promotions render only for matching PMs and only when no active discount badge exists. Assert spotlight promotions do not render row badges and badge tooltip/terms copy is present.

- [ ] **Step 4: Add mount RED tests.**

Assert settings and overview pages render `SpotlightPromotion`. Add lightweight dashboard route tests for payouts, transactions, and disputes mount points where existing native pages exist. Do not add Documents mount until Documents exists natively.

- [ ] **Step 5: Run RED JS tests.**

Run focused Jest commands:

```bash
pnpm --filter='@woocommerce/admin-library' test:js -- client/woopayments/settings/test/settings-data.test.ts client/woopayments/settings/test/settings-page.test.tsx client/woopayments/admin/test/overview-page.test.tsx
```

Add the new test file paths once created. Expected: failures due to missing store/components/props/mounts.

## Task 4: Frontend GREEN Implementation

**Files:**
- Add `plugins/woocommerce/client/admin/client/woopayments/promotions/data/action-types.ts`
- Add `plugins/woocommerce/client/admin/client/woopayments/promotions/data/actions.ts`
- Add `plugins/woocommerce/client/admin/client/woopayments/promotions/data/constants.ts`
- Add `plugins/woocommerce/client/admin/client/woopayments/promotions/data/hooks.ts`
- Add `plugins/woocommerce/client/admin/client/woopayments/promotions/data/reducer.ts`
- Add `plugins/woocommerce/client/admin/client/woopayments/promotions/data/resolvers.ts`
- Add `plugins/woocommerce/client/admin/client/woopayments/promotions/data/selectors.ts`
- Add `plugins/woocommerce/client/admin/client/woopayments/promotions/data/store-name.ts`
- Add `plugins/woocommerce/client/admin/client/woopayments/promotions/data/store.ts`
- Add `plugins/woocommerce/client/admin/client/woopayments/promotions/types.ts`
- Add `plugins/woocommerce/client/admin/client/woopayments/promotions/spotlight.tsx`
- Add `plugins/woocommerce/client/admin/client/woopayments/promotions/spotlight-card.tsx` or similar focused presentational component
- Add/modify scoped SCSS under `plugins/woocommerce/client/admin/client/woopayments/promotions/`
- Modify settings/admin pages and `payment-methods-list.tsx`

- [ ] **Step 1: Implement native promotions store.**

Use store name `wc/payments/pmPromotions`, data-controls API fetch, notices matching reference copy adapted to WooCommerce text domain, flat response validation, and action invalidation/refetch after activate/dismiss. Export hooks `usePmPromotions()` and `usePmPromotionActions()`.

- [ ] **Step 2: Implement native spotlight UI.**

Build a scoped, accessible Spotlight card/dialog equivalent for native admin. Use native buttons and links, proper accessible names, focus-visible styling, safe terms URL handling, optional image with meaningful alt derived from title, optional badge/footnote, and Tracks via `@woocommerce/tracks`. Keep styles under WooPayments promotion classes.

- [ ] **Step 3: Implement row badge promotions.**

Add `PmPromotion` prop support to `WooPaymentsPaymentMethodsList` and rows. Select matching `type === 'badge'` promotion by payment method. Render badge promotion only when `getDiscountBadgeText()` is empty. Use title as chip text, description as tooltip/help, `tc_url`/`tc_label` as a terms link, and `badge_type` class modifiers.

- [ ] **Step 4: Mount spotlight on native surfaces.**

Mount `SpotlightPromotion` in settings, overview, payouts, transactions, and disputes. Keep it in the same lazy-loaded chunks as each WooPayments page so disabled/non-WooPayments merchants do not pay a global bundle cost. Do not mount Documents until native Documents exists.

- [ ] **Step 5: Run GREEN JS tests.**

Run Task 3 commands plus the new promotion-specific test files. Expected: focused JS tests pass.

## Task 5: Gates, Browser Proof, Reviews, Docs, Commit

**Files:**
- Modify `tools/woopayments-merge/a4-admin-surface-gate.py`
- Add `plugins/woocommerce/changelog/fix-native-payments-a4p-pm-promotions-parity`
- Update `.agents/scratchpad/sessions/2026-06-15-core-native-payments/implementation-log.md`
- Update `.agents/scratchpad/sessions/2026-06-15-core-native-payments/staging-log.md`
- Update `.agents/scratchpad/sessions/2026-06-15-core-native-payments/spec-conformance-baseline.md`

- [ ] **Step 1: Widen the ignored A4 gate.**

Add source assertions for:

```python
"pm-promotions-route": "/payments/pm-promotions",
"pm-promotions-store": "wc/payments/pmPromotions",
"pm-promotions-platform-api": "payment_method_promotions",
"spotlight-promotion": "SpotlightPromotion",
"badge-promotions": "badge_type",
"promotions-dismissals-option": "_wcpay_pm_promotion_dismissals",
```

Run the gate and write JSON output into session `data/`.

- [ ] **Step 2: Run focused static and test gates.**

Run focused PHP tests, focused JS tests, PHP syntax for touched production files, changed-file PHPCS, PHPStan on touched PHP production files, targeted JS lint/type checks if available for touched files, changelog validation, `git diff --check`, and branch lint. Do not lint scratchpad files.

- [ ] **Step 3: Run Playwriter browser parity proof.**

Seed target store with deterministic visible spotlight and badge promotions through real local options/transients or a local probe that does not change production logic. Verify target settings and overview render the spotlight; settings payment method row renders badge promotion; discount badge wins over promo badge. Compare against the reference store behavior and record any residual visual/copy deltas honestly.

- [ ] **Step 4: Run harness and log scans.**

Run `tools/woopayments-merge/verify.sh` if the two local stores are stable. Scan target/reference/WPCOM-local logs since the slice start timestamp for PHP notices, warnings, and fatals. Treat warnings/notices as bugs unless source-verified unrelated.

- [ ] **Step 5: Dispatch review agents and close findings.**

Use architecture/API-contract/performance/a11y/reliability review agents for this slice. Verify findings against source before acting. Fix real blockers before commit.

- [ ] **Step 6: Update docs and commit locally.**

Add changelog, update implementation/staging/baseline logs with exact evidence and residual N12 backlog, then commit local Core changes. Do not push. Do not modify WPCOM or the reference plugin.

## Task 6: Keep A4 Reopened After A5c For N12 Feature Parity

- [x] **Step 1: Preserve the post-A5c A4 reopen constraint.**

A5c is complete, and A4 remains reopened under N12. Continue carrying the remaining feature-parity backlog as A4 work before reconsidering A5 cutover readiness: dashboard/list/detail visual parity, Card Readers/Capital framing/styling, Reports/Documents native UI/API slices, broader copy/content parity, SCSS fidelity, and the widened final A4 exit gate. `FILTER_NATIVE_ADMIN_SURFACES_READY` must remain fail-closed until those pass.

## Closeout Criteria

A4p is complete only when native PM promotions are source-backed, activation/dismissal and settings-save implicit activation match the reference contract, settings row badges and SpotlightPromotion render on native-owned surfaces with scoped styling, focused PHP/JS/static/browser/harness gates pass, review findings are closed, session docs record evidence and remaining N12 backlog, and native admin readiness remains fail-closed.
