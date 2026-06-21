---
session: 2026-06-15-core-native-payments
type: analysis
by: codex
created: 2026-06-19 07:13
target: A4p native PM promotions and spotlight parity
reconciles:
  - supervisor-prompt-2026-06-18-2344-N12.md
  - analysis-a4o-admin-shell-parity.md
  - spec-conformance-baseline.md
status: draft
---

# A4p PM Promotions And Spotlight Parity

> **Prompt:** "Add a task at the bottom of your current task list that, once you are fully done with A5c, I want you to re-open A4 because there is work to be done for feature parity. Read and follow this .agents/scratchpad/sessions/2026-06-15-core-native-payments/supervisor-prompt-2026-06-18-2344-N12.md"

N12 remains active after A5c. A4 is reopened until native admin surfaces pass merchant reachability, functional/visual parity, copy/content parity, SCSS fidelity, and the widened exit gate against the reference store. A4o closed the provider-enabled submenu/badge shell, but PM promotions remain a source-backed feature-parity gap.

## Source-Backed Gap

Native Core currently emits the settings field `pm_promotions` as an empty array in `plugins/woocommerce/src/Internal/Payments/Providers/WooPayments/WooPaymentsSettingsService.php`, and there is no native PM promotions API client method, provider service, REST route, data store, spotlight component, or row badge integration. This means native admin can neither fetch promotions nor render the reference spotlight/banner or badge UI.

The reference WooPayments client uses a flat PM promotions contract:

- Store: `wc/payments/pmPromotions`.
- REST: `GET /wc/v3/payments/pm-promotions`, `POST /wc/v3/payments/pm-promotions/{id}/activate`, and `POST /wc/v3/payments/pm-promotions/{id}/dismiss`.
- Response: a flat array of promotion objects with required fields `id`, `promo_id`, `payment_method`, `payment_method_title`, `type`, `title`, `description`, `cta_label`, `tc_url`, `tc_label`; `type` is `spotlight` or `badge`; optional fields are `badge_text`, `badge_type`, `footnote`, and `image`.
- Upstream platform path: `payment_method_promotions`, preserving request hooks `wcpay_get_pm_promotions_request` and `wcpay_activate_pm_promotion_request`.
- Backend filtering: only users with `manage_woocommerce`; skip invalid PMs, already-enabled PMs, dismissed promos, PMs with active discounts, and later `promo_id`s for the same PM; normalize method titles, CTA, terms labels, HTML, and URLs.
- Activation: call upstream activation, mark dismissed, enable/sync the payment method, clear promotions/account cache, and record Tracks. Settings-save also activates visible promotions before enabling a promoted method.
- Dismissal: local `_wcpay_pm_promotion_dismissals` option update, memo reset, Tracks.

Reference UI behavior:

- `SpotlightPromotion` selects the first `type === 'spotlight'`, renders nothing while loading/empty/no spotlight, records client Tracks events, opens terms only for `http:`/`https:` URLs, activates/dismisses through the store, and feeds data into the shared focus-managed Spotlight component.
- Spotlight mounts in settings, overview, deposits/payouts, transactions, disputes, and documents. Native should mount it on the native surfaces that exist today: settings, overview, payouts, transactions, and disputes. Documents remain a separate Reports/Documents native UI/API slice.
- Payment method rows select only badge promotions matching the row PM. Account-fee discounts take precedence over badge promotions. Badge promotions use title as chip text, description as tooltip copy, terms URL/label when present, and badge type for styling.

## Slice Decision

A4p should implement PM promotions as one holistic native parity slice instead of scattering it across settings/dashboard chunks. The clean boundary is provider-level: `WooPaymentsApiClient` handles platform transport, a new PM promotions service owns filtering/cache/dismissals/activation, a native REST controller exposes the preserved `/wc/v3/payments/pm-promotions` contract, and the admin client consumes that contract through a dedicated promotions store and UI components.

This slice should not revive deprecated marketplace/promotions admin scripts or plugin-era standalone route ownership. The route belongs under the Core Payments REST namespace (`/wc/v3/payments/...`), matching the existing native settings, transactions, disputes, deposits, and capital route family.

## Architecture Notes

The PM promotions service should keep business rules out of the raw REST controller and out of the API client. It should depend on the native account/settings/API boundaries and expose a small application service API: `get_visible_promotions()`, `activate_promotion( id )`, `dismiss_promotion( id )`, and `maybe_activate_promotion_for_payment_method( method_id )`.

The native settings service should receive the PM promotions service as an optional DI dependency. This avoids direct container pulls in the common settings path, keeps tests injectable, and lets settings save activate promotions before persisting newly enabled payment methods. If the service is unavailable, native should fail closed to an empty promotions list rather than crashing the settings screen.

The frontend should keep promotions bundled with WooPayments admin chunks. The data store and Spotlight component live under `client/admin/client/woopayments/promotions/`; settings/dashboard chunks import them only where WooPayments native surfaces render. This keeps WooPayments-specific frontend logic and styling out of global/admin-wide bundles.

## Gates

TDD targets:

- `WooPaymentsApiClientTest`: platform GET/POST paths and legacy request hooks for PM promotions.
- New `WooPaymentsPmPromotionsServiceTest`: response filtering, normalization, active-discount precedence, dismissal storage, activation ordering, cache/account clear behavior where native boundaries expose it, and settings-save implicit activation.
- REST controller coverage under the existing WooPayments settings/admin REST tests or a focused controller test: routes register only under native runtime, permissions require `manage_woocommerce`, ID validation rejects unsafe IDs, responses stay flat/legacy-compatible.
- JS store tests: resolver path, invalid response handling, activate/dismiss POST paths, notice behavior, and resolution invalidation.
- UI tests: Spotlight renders/tracks/opens safe terms/activates/dismisses; rows show badge promotions only when no discount badge is present; overview/settings mount the spotlight.

Browser/harness gates:

- Seed a deterministic visible spotlight and badge promotion in target store state through real options/transients or a local probe without changing production logic.
- Use Playwriter to compare settings and overview target rendering against reference expectations for spotlight/badge behavior.
- Run the widened A4 admin-surface gate and update it to include PM promotions route/store/spotlight coverage.
- Run focused PHP/JS tests, changed-file lint/static checks, bundle/perf judgement for WooPayments admin chunks, log scans, review agents, and session docs.

## Boundaries

No WPCOM code changes, no WPCOM sandbox access, no plugin/reference edits, no push. Do not delete merchant promotion dismissal data. Do not add deprecated Stripe Billing or deprecated marketplace/admin promotion surfaces. Keep native admin readiness fail-closed until the widened N12 exit gate passes.
