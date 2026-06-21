---
session: 2026-06-15-core-native-payments
type: analysis
by: codex
created: 2026-06-19 01:00
target: exp/core-native-payments — A4j settings payment methods parity
reconciles:
  - supervisor-prompt-2026-06-18-2344-N12.md
  - analysis-a4i-admin-navigation-parity.md
status: final
last_updated: 2026-06-19 01:41
---

# A4j Settings Payment Methods Parity

> **Prompt:** "Add a task at the bottom of your current task list that, once you are fully done with A5c, I want you to re-open A4 because there is work to be done for feature parity. Read and follow this .agents/scratchpad/sessions/2026-06-15-core-native-payments/supervisor-prompt-2026-06-18-2344-N12.md"

## Initial Source Comparison

N12 explicitly keeps A4 open after A5c and blocks A5 readiness until native admin surfaces pass reachability, functional/visual fidelity, and copy/content fidelity. A4i closed the reachability part for persistent WP admin navigation, but the settings surface remains materially below the reference.

The next coherent settings chunk is payment methods plus Buy Now Pay Later because the reference uses the same `PaymentMethodsList` and `PaymentMethod` row composition for both sections. Native currently renders both with the same simplified `PaymentMethodToggle` in `plugins/woocommerce/client/admin/client/woopayments/settings/settings-page.tsx`, so one shared native component can close two N12 gaps at once without touching the unrelated express checkout, deposits, or fraud sub-pages yet.

Reference parity targets are `client/settings/payment-methods-section/index.js`, `client/settings/buy-now-pay-later-section/index.js`, `client/settings/payment-methods-list/index.js`, and `client/settings/payment-methods-list/payment-method.tsx` in `/Users/vladolaru/Work/a8c/woocommerce-payments`. Those components provide rich list rows with payment method icons, card-brand logos, required card locking, activation modal for unrequested capabilities with requirements, fee pills and tooltips, promotional/discount badges, duplicate-payment-method notices, per-method availability notices, and the manual-capture conflict banner.

Native currently has the route/store foundation from A4e: hooks for available method IDs, enabled methods, selected/unselected updates, method statuses, manual capture, and settings save state. It does not yet have a native payment-methods map with labels/descriptions/icons/Stripe capability keys, native fee formatting and fee payload typing, duplicate gateway notices, PM promotions, or the activation modal UI. These need native adapters or explicit deferrals recorded as parity blockers; they should not be papered over with simplified rows.

The native backend already does more than the current UI exposes. `WooPaymentsSettingsService::get_available_payment_method_ids()` derives available methods from cached account `fees` when present, `get_payment_method_statuses()` derives capability statuses and requirements from cached account data, `update_settings()` filters manual-capture-incompatible methods before save, and `request_unrequested_payment_methods()` requests unrequested Stripe capabilities for newly enabled methods. This supports the reference activation-modal behavior without a new frontend API call. It does not yet return structured `account_fees`, dismissed duplicate notices, duplicate gateway IDs, or PM promotions, so fee pills, duplicate notices, and promotional badges cannot honestly be claimed complete until the payload is widened.

Core already includes payment-method and card assets under `plugins/woocommerce/assets/images/payment-methods*`, `plugins/woocommerce/assets/images/icons/credit-cards/`, and `plugins/woocommerce/assets/images/payment_methods/72x72/`. A4j should use those Core-owned assets through the existing admin build instead of depending on the standalone plugin's `wooPaymentsPaymentMethodDefinitions` global.

The first A4j implementation should split the current monolithic settings payment-method UI into native components for method definitions, rich list rows, activation modal, manual-capture banner, and shared section rendering. The scope is intentionally payment methods plus BNPL; express checkout customization sub-pages, payout bank-account block, fraud-protection sub-page, general switch-to-live notice, `FormBusyState`, and notice framework remain later N12 settings chunks.

Native seam exploration from Meitner the 3rd independently confirmed that Core already has the single `wc/payments/settings` store and `/wc/v3/payments/settings` GET/POST contract for method IDs/statuses, manual capture, express locations, descriptors, payout schedule, notifications, fraud settings, debug, multi-currency, and subscriptions. It also confirmed that rich settings parity must avoid resurrecting the standalone `wcpay/data/settings` store or `window.wcpaySettings` as the source of truth. Missing richer reference fields include fees, duplicate notice state, PM promotions, and some bootstrap context; those need native projections before their UI can be claimed complete.

Reference exploration from Laplace the 3rd recommended a broader main-settings parity chunk spanning shared row/notice primitives, payment methods + BNPL, express overview links, deposits bank account, fraud main card, sandbox switch-to-live notice, and save busy-state. For implementation throughput, A4j keeps the first patch to the shared payment-method/BNPL primitives because those rows are a reusable foundation and avoid prematurely pulling express subpage previews, fraud advanced internals, VAT modal, and plugin data stores into the native settings chunk. The broader list remains the reopened A4 backlog after A4j.

Boundaries remain unchanged: no Stripe Billing UI, no WPCOM code changes or sandbox access, no standalone WooPayments client changes, no provider-specific assets loaded globally, and no separate `@wordpress/data` registry.

## Closeout Findings

A4j implemented the shared settings payment-method and BNPL row primitives natively without reintroducing the WooPayments plugin store or globals. The final source adds a native payment-method definition map, a shared `WooPaymentsPaymentMethodsList`, activation modal, status/availability handling, card-brand logo strip, card required locking, manual-capture warning and disabled-state behavior, and scoped settings-bundle SCSS. The component uses the existing `wc/payments/settings` store and `/wc/v3/payments/settings` contract, plus Core-owned assets under `plugins/woocommerce/assets/`.

The reference source check confirmed the important row anatomy: the checkbox label is hidden visually, while the visible row title/description/icon/card-logo content lives in the row body. The first native CSS pass duplicated the method label visually; this was corrected before browser verification by visually hiding the `CheckboxControl` label and tightening the row grid to checkbox/icon/body columns.

Focused RED/GREEN coverage now proves the card required row with card logos including Cartes Bancaires, manual-capture conflict banner and disabled incompatible rows with row-level disabled reasons, activation modal before enabling an unrequested method with requirements, account-country-specific Cash App Afterpay/Clearpay settings branding, and inactive/more-information notices. The live target account reports relevant payment-method statuses as `active`, so the activation modal branch could not be honestly driven in the browser without mutating fixtures; that path remains covered by the focused component test.

Review fixes after the first local green line addressed four source-backed gaps: the manual-capture banner is non-dismissible to avoid focus loss, manual-capture-disabled rows expose an `Unavailable with manual capture` chip associated through `aria-describedby`, Afterpay/Clearpay settings definitions now follow the reference account-country mapping (`Cash App Afterpay` for US, `Clearpay` for GB, `Afterpay` otherwise) with Core-owned SVG assets, and the card-brand strip now includes Cartes Bancaires.

Browser parity evidence: Playwriter loaded the native route `http://store8889.localhost:8889/wp-admin/admin.php?page=wc-settings&tab=checkout&path=%2Fwoopayments%2Fsettings`, confirmed the card row snapshot with `Credit / Debit Cards`, `(Required)`, and Visa/Mastercard/American Express/Discover/Diners/JCB/UnionPay/Cartes Bancaires logos, confirmed the US account row renders `Cash App Afterpay`, and showed no native app console errors or warnings. The only browser log was Chrome's existing permissions-policy `unload` message. The target web access log showed the new Core Cartes Bancaires and Cash App Afterpay SVG assets returning 200. A reference-store check at `http://localhost:8082/wp-admin/admin.php?page=wc-settings&tab=checkout&section=woocommerce_payments` confirmed matching row anatomy and also reminded us that reference-only fee pills, duplicate notices, and promotion badges still require native payload widening before parity can be claimed for those extras.

Focused a11y and reference-integrity re-review agents approved the review fixes with no findings. A4j is committed as source/assets/tests/styles `42846f540a` and changelog `47527b39fc`, with git range `1400d54ed9...47527b39fc`.

Residual A4 settings parity blockers remain explicit: fee pills/tooltips, duplicate-payment-method notices, PM promotion/discount badges, full express checkout customize subpages and live preview, payout bank-account block, fraud Basic/Advanced main/subpage, sandbox switch-to-live notice, and save busy-state polish. A4j closes the shared payment-method/BNPL row foundation only; A4 remains open and A5 admin readiness remains fail-closed.
