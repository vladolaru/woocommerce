---
session: 2026-06-15-core-native-payments
type: review
by: claude
created: 2026-06-18 23:44
last_updated: 2026-06-19 00:12
target: exp/core-native-payments — A4 native admin surfaces (functional + visual parity)
reconciles:
  - analysis-a4h-exit-gate.md
  - analysis-a4e-settings-page.md
  - analysis-a5a-cutover-preflight-closeout.md
  - spec-conformance-baseline.md
  - staging-log.md
status: draft
---

# N12 — Native admin surfaces: functional + visual parity is an A4 exit requirement, and an A5 cutover precondition

> Per the operating model, treat every file:line below as a starting point to verify against source, not as fact. The reference store is the parity oracle: reference = `/Users/vladolaru/Work/a8c/woocommerce-payments` (and the running reference admin at `:8082`); target = this checkout (`:8889`). This list is **non-exhaustive** — for each surface, run a direct reference-vs-target diff and treat anything this misses as in-scope too.

## Why this gates A5

A5a flipped `WooPaymentsCutoverController::FILTER_NATIVE_ADMIN_SURFACES_READY` to default `true` on the strength of the A4h route-architecture / single-registry / bundle gates. Those gates assert that routes *register*, render when navigated to by URL, share one `@wordpress/data` registry, and are bundled small. They do **not** assert two things a merchant actually experiences: (1) that the surfaces are **reachable** through persistent admin navigation, and (2) that each surface is **functionally and visually faithful** to the reference. Cutover deactivates the standalone plugin and makes the native admin the *only* WooPayments admin a merchant has. So until native admin meets the parity bar below, readiness must fail closed.

**Ask (safety-critical, do first):** return `FILTER_NATIVE_ADMIN_SURFACES_READY` to fail-closed (the `apply_filters` default goes back to `false`), and redefine "native admin surfaces ready" so it requires merchant-reachability + per-surface functional/visual parity verified by the widened exit gate (below) — not route architecture alone. A5 cutover stays blocked until then. This is scoped to the preflight readiness default only; it does not roll back the A5a operational-queue hooks or the A5b fee-remediation infrastructure, which can stay in place.

## The parity bar — three dimensions the current A4 gates do not cover

1. **Merchant-reachability.** Every native WooPayments admin surface must be reachable from persistent admin navigation, with the same capability gating (`manage_woocommerce`) and account-state variants (connected / rejected / under-review / not-connected) as the reference.
2. **Functional + visual fidelity.** Each surface and each section within it must be element-complete (every control, notice, link, badge, icon, tooltip, modal, sub-page the reference has) and styling-faithful to the reference. This includes the **same grouping and composition** of elements — section/card/fieldset boundaries, the clustering of related controls, hierarchy, and ordering — not just the same elements rearranged. Grouping carries meaning (it tells the merchant which settings belong together); a flattened or re-grouped layout is a parity miss even when every element is present.
3. **Copy + content fidelity.** The reference's inline copy and content-bearing UI text must be preserved, not dropped or paraphrased into terser placeholders during a rebuild: section headings, field **labels** and **descriptions/help text**, **badge** text, **notice/banner** copy (info / warning / error), **empty-state** and loading guidance, **tooltips**, **modal** body copy, and **CTA/button/link** labels. This text carries merchant information and guidance — onboarding prompts, fee explanations, dispute/payout guidance, risk warnings, what-happens-next instructions — and losing it removes the guidance even when the control technically works. Where copy must legitimately change for native context (Woo branding, removed Stripe Billing surface, plugin-specific phrasing), adapt it deliberately while preserving the informational content; do not silently drop it. New or adapted copy should follow the project copy guidelines (sentence case, etc.), but the default is faithful preservation of the reference's merchant-facing text.

## Recommended strategy: hoist & adapt the reference React + SCSS as units, don't reimplement

A4e hoisted the settings **data store** onto the Core registry (`settings/data/store.ts` via `createReduxStore` + `register`) — keep this; it satisfies single-registry. The settings **component tree and SCSS**, however, were reimplemented (one monolithic `settings/settings-page.tsx`, ~333 LOC of SCSS against the reference's ~1648 LOC across `client/settings/**`). The fidelity gaps below trace to that reimplementation: hand-rebuilt sections drop the icons, fees, tooltips, modals, sub-pages, notices, and styling that the reference components carry.

The lower-drift path — and likely the only way to reach real visual fidelity — is to **hoist the reference component subtrees + their SCSS as units and adapt the edges**, the same way the store was hoisted: register components against the native hooks already in `settings/data/hooks.ts`, replace `window.wcpaySettings` with the Core bootstrap payload, cut cross-store reach-ins (`wcpay/data/deposits`, `wcpay/data/pm-promotions`), exclude the Bucket-D Stripe Billing surface (N9), and adapt-or-no-op the plugin-runtime reach-ins (VAT modal, spotlight promotion, fraud-script enqueue). This applies equally to the two sub-SPAs the reference ships (`client/settings/express-checkout-settings/**` and `client/settings/fraud-protection/advanced-settings/**`). SCSS fidelity needs the reference build-time context (`@wordpress/base-styles` + the plugin `client/stylesheets/abstracts/` partials) brought into the Core settings bundle — this is also the open N7c "settings CSS" gap.

Verify per surface whether a clean hoist or a targeted rebuild is the right call; the point is fidelity-by-construction over field-by-field reconstruction.

## Per-surface / per-section remediation plan (non-exhaustive)

### Surface 1 — Admin navigation & menu (highest priority: surfaces are currently orphaned)

Native registers the `/woopayments/*` pages only as React Router routes inside the Settings → Payments SPA (`client/admin/client/woopayments/admin/routes.tsx`), and `PaymentsController::add_menu()` registers a single top-level "Payments" item that redirects to `wc-settings&tab=checkout` with no submenus. Overview/Payouts/Transactions/Disputes are reachable only by direct URL or in-app cross-links.

- Reference parity target: `includes/admin/class-wc-payments-admin.php` `add_payments_menu()` registers the Payments menu as a category with persistent submenu children — Overview, Payouts, Transactions, Disputes (+ conditional Reports, Card Readers, Capital/Loans, Documents), a Settings sub-item, reduced sets for rejected/under-review accounts, and an Onboarding entry when not connected; all `manage_woocommerce`-gated.
- Remediation: give the native admin surfaces persistent merchant navigation with matching reachability, capability gating, and account-state variants. Submenu items under the WP-admin "Payments" top-level menu are a natural home — the reference uses exactly that structure, and there is no conflict with the chosen architecture as long as those submenu items **deep-link to the canonical Settings → Payments sub-routes** (`wc-settings&tab=checkout&path=/woopayments/overview`, …). The constraint is route ownership, not the menu mechanism: do **not** re-register a separate top-level `/payments/*` page hierarchy as the menu targets — `analysis-a4g-remaining-admin-surfaces.md` designated the plugin-era `/payments/*` paths as compatibility-aliases-only, not the ownership path. An in-SPA persistent secondary nav (tabs/sidebar) *within* the Settings → Payments screen can complement the submenus so a merchant can move between Overview/Payouts/Disputes/Transactions once on any of them. The requirement is that every surface is reachable without typing a URL; the design choice (submenus, in-SPA nav, or both) is open, but the link targets must be the `/woopayments/*` sub-routes.
- Gate submenu presence on the **native WooPayments provider being enabled**. When the native provider is enabled, register the submenu items. When it is not, register no submenu items and keep the top-level "Payments" menu pointing at Settings → Payments (the current `PaymentsController::add_menu()` behavior). Confirm the correct native predicate for "provider enabled": the existing `is_woopayments_account_onboarded()` resolves through `WooPaymentsLegacyRuntime::is_loaded()` (standalone plugin active), which will **not** hold post-cutover, so the gate must use a native-runtime-aware condition instead — candidates to verify: `NativePaymentsRuntimeArbiter::is_native_runtime_enabled()`, `WooPaymentsProvider::can_process_payments()`, the gateway `enabled === 'yes'` state, and `is_account_onboarded_from_cache()`.
- Retain the reference menu **badging**, reimplemented cleanly. Reference `includes/admin/class-wc-payments-admin.php` runs three (invoked together around lines 623-626): a menu action badge (`add_menu_notification_badge()` — the "1" prompting setup action, with the 3-day-since-activation, `wcpay_menu_badge_hidden`, and force-show rules), a **Disputes-awaiting-response count** badge (`add_disputes_notification_badge()` → `get_disputes_awaiting_response_count()`, which also appends `filter=awaiting_response` to the Disputes submenu URL), and a **Transactions** badge (`add_transactions_notification_badge()`). Preserve the same merchant-facing badge behavior on the native submenus, but prefer a supported WC Admin badge/notification mechanism over the reference's direct global `$menu`/`$submenu` HTML mutation (the reference does this behind `phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited`). Badging should also respect the provider-enabled gate above — no badges when the submenus are absent.

### Surface 2 — Settings page, section by section

Reference composition: `client/settings/settings-manager/index.js`. Work each section to element + visual parity:

- **General** — enable toggle, test/sandbox-mode toggle with confirmation (present), **+ the sandbox/test-account "switch to live" notice** (reference `general-settings/` → `sandbox-mode-switch-to-live-notice/`; absent in native).
- **Payment methods** — reference `payment-methods-list/payment-method.tsx`: per-method brand icons, fee structure with `HoverTooltip` + `Pill` badges, descriptions, locked/actionable states, the activation modal, and the manual-capture-conflict `BannerNotice`. Native is a `CheckboxControl` + text-badge reimplementation — restore the rich list (hoist candidate).
- **Buy Now Pay Later** — reference ships a dedicated `buy-now-pay-later-section/` with richer presentation; native `BuyNowPayLaterSettingsSection` is reduced to plain toggles. Restore parity.
- **Express checkouts** — reference renders the section list with per-method **"Customize" buttons → dedicated settings sub-pages**: `section=woocommerce_payments&method=woopay`, `…&method=payment_request`, `…&method=amazon_pay` (`client/settings/index.js` mounts `<ExpressCheckoutSettings>`; `client/settings/express-checkout-settings/**`; Customize buttons via `getPaymentMethodSettingsUrl`). Native inlines flat toggles with no sub-pages and no payment-request live preview — add the sub-pages and Customize links (hoist candidate: the whole `express-checkout-settings` sub-SPA).
- **Transactions** — manual capture (filtering unsupported BNPL) + statement descriptor. Verify parity.
- **Payouts / Deposits** — reference shows the **payout bank account** block ("Manage in Stripe" external link / errored-account `DepositFailureNotice`) alongside schedule controls (`client/settings/deposits/index.js`). Native renders schedule controls only — add the bank-account display.
- **Notifications** — support email / phone. Verify parity.
- **Fraud protection** — reference is a **two-radio Basic/Advanced** control with per-level help text, a Basic-level help modal, an error notice, the onboarding tour, and a **"Configure" link → advanced fraud-protection sub-page** with rule cards (AVS, CVC, IP-address, order-items / purchase-price thresholds) at `client/settings/fraud-protection/advanced-settings/**`. Native is a single `SelectControl` (with a non-reference "standard" option) + a read-only "rules preserved" note. Restore the two-radio control + help/tour, add the advanced sub-page (the advanced sub-page was already tracked as deferred A4 work — fold it in here), and reconcile the level model to the reference Basic/Advanced.
- **Advanced** — multi-currency toggle (keep), debug/dev-mode, subscriptions toggle. Stripe Billing stays excluded (N9).
- **Save footer** — wrap the form in the reference `FormBusyState` during save (native only disables the button).
- **Cross-cutting in settings:** the reference notice framework (`InlineNotice` / `BannerNotice` with icons + dismissal persistence) vs native raw `Notice`; per-section loading skeletons (`LoadableSettingsSection`) vs a single full-page spinner; duplicate-payment-method notices (`DuplicatedPaymentMethodsContext`, absent in native); the VAT form modal (absent — adapt or consciously no-op).

### Surface 3 — Dashboard surfaces (overview, payouts, transactions, disputes, payout details, card readers, capital)

These are built and render, but inherit Surface 1's reachability gap and have not had a visual/UX parity pass. For each: confirm persistent-nav reachability, then run a reference-vs-target visual + functional diff (cards, columns, filters, empty/loading/error states, cross-links) and close the per-surface deltas.

### Cross-cutting — styling / SCSS fidelity

Bring the reference SCSS build context (`@wordpress/base-styles` + plugin `abstracts/` partials) into the Core settings/admin bundles so hoisted components render faithfully, and record the resulting bundle delta through the N7c bundle gate. Confirm Core-global styles are not contaminated by the WooPayments-scoped partials.

## Widen the A4 exit gate (the missing N10 dimensions)

Add to the A4 exit gate, alongside the existing route/registry/bundle assertions:

1. **Reachability assertion** — every native admin surface is reachable from persistent admin nav, with capability + account-state gating matching the reference.
2. **Per-surface / per-section functional + visual parity** — a browser side-by-side (reference `:8082` vs target `:8889`) that asserts, per section, the presence of every reference element (controls, notices, bank account, icons, fees, tooltips, radios, Customize sub-pages, modals), the **grouping/layout structure** (section/card/fieldset boundaries, clustering of related controls, hierarchy, ordering), and styling fidelity. The per-surface/section list above is the starting checklist; extend it from your own reference diff.
3. **Copy + content parity** — the same per-section side-by-side asserts that the reference's content-bearing text (labels, descriptions/help text, badge text, notice/banner copy, empty-state/loading guidance, tooltips, modal copy, CTA/link labels) is present and informationally equivalent in native, with deliberate, recorded exceptions for legitimately-changed copy (Woo branding, removed Stripe Billing, native phrasing). Missing or hollowed-out merchant guidance is a parity failure, not a cosmetic nit.
4. Only when all three pass (plus the existing architecture gates and the standing N5 stage-boundary gate) may `FILTER_NATIVE_ADMIN_SURFACES_READY` default true and A5 readiness be reconsidered.

## Boundaries

Do not reintroduce Stripe Billing (N9 retire-with-guard stands); do not delete merchant data; do not modify the standalone plugin or the reference checkout; preserve the single `@wordpress/data` registry (no parallel runtime) while hoisting; no push.
