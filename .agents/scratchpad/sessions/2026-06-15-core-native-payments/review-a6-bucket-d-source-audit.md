---
session: 2026-06-15-core-native-payments
type: review
by: subagent:codex-a6-bucket-d-source-auditor
created: 2026-06-21 03:01
tool: woocommerce-code-review
target: A6 Bucket-D tracked source cleanup candidates
reconciles:
  - analysis-a6-replanned-cleanup-readiness.md
last_updated: 2026-06-21 03:06
status: final
---

# A6 Bucket-D Source Audit

> **Prompt:** "Read-only A6 Bucket-D source audit for WooCommerce Core native WooPayments merge.
>
> Workspace: /Users/vladolaru/Work/a8c/woocommerce-develop-2 on branch exp/core-native-payments.
> Session docs: .agents/scratchpad/sessions/2026-06-15-core-native-payments.
>
> Constraints:
> - Do not edit product code.
> - Do not access WPCOM sandbox or modify WPCOM. Local source reads only.
> - Do not push or commit.
> - Do not lint .agents.
> - Keep scratchpad prose unwrapped.
>
> Task:
> Identify tracked WooCommerce Core code/assets/tests that still look like A6 Bucket-D cleanup candidates after A5i. Focus on deprecated/duplicated WooPayments surfaces from the canonical plan and bc-manifest:
> - WCPay native Stripe Billing engine remnants, subscriptions-core remnants, invoice/subscription/product-sync meta writers that should be retired, while preserving WC Subscriptions integration.
> - WC cross-version compat branches that only existed for plugin cross-version support.
> - vendored/duplicated libraries or duplicated JS/runtime assets now redundant in core.
> - one-shot migration runners that should not remain after A5 unless still needed for data safety.
>
> Read at least:
> - /Users/vladolaru/Work/a8c/ai-prompts/goals/woopayments-merge/follow-up/implementation-plan.md A6 and Bucket D sections.
> - /Users/vladolaru/Work/a8c/ai-prompts/goals/woopayments-merge/follow-up/bc-manifest.md Bucket D sections.
> - Current source under plugins/woocommerce/src/Internal/Payments and client WooPayments paths.
>
> Output:
> Write .agents/scratchpad/sessions/2026-06-15-core-native-payments/review-a6-bucket-d-source-audit.md with scratchpad frontmatter (session exact, type: review, by: subagent:<your name>, created current local time, status: final). Include a verdict: CLEAR_FIRST_SLICE / NO_SAFE_CODE_CLEANUP / NEED_MORE_EVIDENCE. For each candidate, cite exact current source paths and explain whether it is safe to remove now, unsafe until release/default-on, or already removed. Keep it concise and source-backed."

## Working Notes

Canonical criteria read:

- `/Users/vladolaru/Work/a8c/ai-prompts/goals/woopayments-merge/follow-up/implementation-plan.md` A6/Workstream D says A6 drops WC cross-version compat, one-shot migration runners after effects are ensured at A5, god-class indirection/facades after internal callers are migrated, vendored/duplicated libs, and the WCPay-native Stripe Billing subscriptions engine with its product/price sync meta only after the RULE 0 data-safety check.
- `/Users/vladolaru/Work/a8c/ai-prompts/goals/woopayments-merge/follow-up/bc-manifest.md` Bucket D says the drop set is `includes/subscriptions/`, vendored `vendor/woocommerce/subscriptions-core/`, `_wcpay_feature_subscriptions`, `_wcpay_feature_stripe_billing`, subscription-migration hooks, WCPay/Stripe-Billing product+price sync meta, WC cross-version compat, JS duplication, god-class indirection, and dead migrations. The preserve set is the standalone WooCommerce Subscriptions gateway integration: supports, token renewals, change-payment-method, and failed-renewal auth emails.

Source findings recorded before final verdict:

- Already removed: no tracked Core files match `includes/subscriptions`, `vendor/woocommerce/subscriptions-core`, `subscriptions-core`, `class-wc-payments-subscription`, `class-wc-payments-product-service`, `class-wc-payments-invoice-service`, `subscriptions-migrator`, `subscriptions-disabler`, or `subscriptions-empty-state`. Whole-plugin greps also found no `wcpay_schedule_subscription_migrations`, `wcpay_migrate_subscription`, `wcpay_migrate_subscription_retry`, or Stripe Billing migration REST/action exports outside negative tests.
- Already removed as writers/runners: whole-plugin grep for `_wcpay_product_id*`, `_wcpay_product_price_id*`, and the Stripe Billing migration actions found no tracked writers. The remaining `_wcpay_subscription_id`/invoice/subscription-discount keys appear only in `plugins/woocommerce/src/Internal/Payments/Providers/WooPayments/Subscriptions/WooPaymentsLegacySubscriptionsGuard.php` and related cutover tests, as marker readers.
- Preserve, not Bucket-D cleanup: standalone WC Subscriptions integration is present in `plugins/woocommerce/src/Internal/Payments/NativeWooPaymentsGateway.php`: supports are added when subscriptions support is available, renewal/failing-payment hooks are registered, and failed-renewal authentication email classes are registered. Tests explicitly assert deprecated Stripe Billing flags do not control support/renewal behavior in `plugins/woocommerce/tests/php/src/Internal/Payments/NativeWooPaymentsGatewayTest.php`.
- Clear cleanup-looking residue: the native settings contract and settings page still carry the deprecated WooPayments Subscriptions feature flag surface: `WooPaymentsSettingsService` exposes `is_wcpay_subscriptions_enabled`/`is_wcpay_subscriptions_eligible` and can update `_wcpay_feature_subscriptions`; the admin data store exports `updateIsWCPaySubscriptionsEnabled`, selectors, and `useWCPaySubscriptions`; `settings-page.tsx` renders an "Enable Subscriptions with WooPayments" checkbox with deprecated-copy guidance. This is separate from the preserved WC Subscriptions gateway integration and separate from the Stripe Billing data-safety guard.
- Unsafe until default-on/data-safety: `WooPaymentsLegacySubscriptionsGuard` reads legacy Stripe Billing markers and `WooPaymentsCutoverController` blocks soft and mandatory cutover when they exist. This is the manifest's migration guard, not a removable engine remnant before production cutover/default-on evidence.
- Unsafe until A5/default-on evidence: `WooPaymentsCanceledAuthorizationFeeRemediationService` remains registered from `plugins/woocommerce/includes/class-woocommerce.php` and owns preserved `wcpay_remediate_canceled_authorization_fees`, dry-run, and affected-orders hooks. The A6 plan says delete one-shot runners after effects are ensured-applied at A5; local source alone does not prove production data is complete.
- Needs external/schema evidence: `WooPaymentsOperationalQueueService` still sends `stripe_billing_enabled => false` in store setup sync, but that payload goes to the WooPayments compatibility/setup API. Local source cannot prove downstream consumers no longer expect the key.
- Needs broader API decision: native admin/list request objects still register legacy `WCPay\Core\Server\Request\*` aliases and run preserved request filters. The manifest says request-layer filters are internal provider transport and can be redesigned, but current source/tests intentionally preserve them for money-movement/admin list routes. That is not a safe first deletion without a targeted API/filter contract decision.
- Not a vendored-lib cleanup: no tracked native WooPayments `vendor`, `dist`, `build`, `runtime`, `node_modules`, or `subscriptions-core` paths exist. Classic assets under `plugins/woocommerce/client/legacy/js/frontend/woopayments-*` and `plugins/woocommerce/client/legacy/css/woopayments-*` are registered by `WC_Frontend_Scripts`; block assets under `plugins/woocommerce/client/blocks/assets/js/extensions/payment-methods/woopayments/**` are registered by `src/Blocks/Payments/Integrations/WooPayments.php`. They serve different checkout runtimes and are not safe duplicate removals from source evidence alone.

## Verdict

CLEAR_FIRST_SLICE.

The first safe A6 Bucket-D cleanup slice is the deprecated WooPayments Subscriptions settings surface, not the cutover/data-safety guards. Remove the native settings contract/UI/store/tests for the old `_wcpay_feature_subscriptions` control while preserving the standalone WC Subscriptions gateway integration and preserving Stripe Billing marker guards until default-on/release data-safety evidence exists.

## Candidates

| Candidate | Current source evidence | Disposition |
|---|---|---|
| Deprecated WooPayments Subscriptions settings flag/UI | `plugins/woocommerce/src/Internal/Payments/Providers/WooPayments/WooPaymentsSettingsService.php:29` defines `WCPAY_SUBSCRIPTIONS_FLAG_OPTION`; `:279-281` exposes `is_wcpay_subscriptions_enabled`, eligibility, and plugin-active state; `:591-593` can write `_wcpay_feature_subscriptions` to `0`; `plugins/woocommerce/client/admin/client/woopayments/settings/data/actions.ts:149-152`, `hooks.ts:170-183`, and `selectors.ts:197-206` export the admin data surface; `plugins/woocommerce/client/admin/client/woopayments/settings/settings-page.tsx:2129-2214` renders the deprecated "Enable Subscriptions with WooPayments" checkbox. | Safe first slice. This is the dropped native WooPayments subscriptions-engine flag surface, not the preserved WC Subscriptions integration. Remove the admin UI/data contract and backend setting read/write; update `WooPaymentsSettingsServiceTest.php`, `settings-page.test.tsx`, and `settings-data.test.ts` accordingly. Do not remove WC Subscriptions support in `NativeWooPaymentsGateway.php`. |
| WC Subscriptions gateway integration | `plugins/woocommerce/src/Internal/Payments/NativeWooPaymentsGateway.php:541-547` detects WC Subscriptions/Core; `:938-967` adds subscription supports; `:974-991` registers scheduled-renewal and failing-payment hooks; `:1003-1015` registers failed-renewal authentication emails. `plugins/woocommerce/tests/php/src/Internal/Payments/NativeWooPaymentsGatewayTest.php:184-214` and `:390-422` assert deprecated Stripe Billing flags do not control native subscription support/renewal behavior. | Preserve. This is Bucket C, not Bucket D. Do not remove as part of A6 cleanup. |
| Stripe Billing engine, product/price sync writers, migration runners | Whole-plugin tracked-source scan found no `includes/subscriptions`, `vendor/woocommerce/subscriptions-core`, `subscriptions-core`, `class-wc-payments-subscription`, `class-wc-payments-product-service`, `class-wc-payments-invoice-service`, `subscriptions-migrator`, `subscriptions-disabler`, `subscriptions-empty-state`, `_wcpay_product_id*`, `_wcpay_product_price_id*`, `wcpay_schedule_subscription_migrations`, `wcpay_migrate_subscription`, or `wcpay_migrate_subscription_retry`. Negative tests remain in `plugins/woocommerce/client/admin/client/woopayments/settings/test/settings-data.test.ts:311-330` and `plugins/woocommerce/tests/php/src/Internal/Payments/Providers/WooPayments/WooPaymentsSettingsServiceTest.php:343-346`. | Already removed. No product-code cleanup here beyond keeping/adjusting negative tests as needed after the settings-flag cleanup. |
| Stripe Billing legacy marker guard | `plugins/woocommerce/src/Internal/Payments/Providers/WooPayments/Subscriptions/WooPaymentsLegacySubscriptionsGuard.php:35-44` enumerates legacy subscription/invoice marker keys and `:55-67` answers whether cutover would strand data. `plugins/woocommerce/src/Internal/Payments/Providers/WooPayments/WooPaymentsCutoverController.php:451-455` adds `legacy_stripe_billing_subscriptions_present`; `:723-729` signposts migration. Tests at `plugins/woocommerce/tests/php/src/Internal/Payments/Providers/WooPayments/WooPaymentsCutoverControllerTest.php:676-798` assert soft and mandatory cutover block on those markers. | Unsafe to remove now. This is the bc-manifest migration guard. Remove only after default-on/release data-safety evidence proves no live legacy Stripe Billing path can be stranded, or after a replacement migration/guard exists. |
| Retired Stripe Billing invoice webhook alarm | `plugins/woocommerce/src/Internal/Payments/Providers/WooPayments/WooPaymentsEventIngestor.php:50-54` lists retired invoice events; `:198-202` alarms and returns; `:762-785` logs the retired-event alarm. | Unsafe to remove now. It is not an engine implementation; it is a fail-closed alarm if Stripe Billing traffic reaches native. Revisit after production proves no such traffic reaches native webhooks. |
| Canceled-authorization fee remediation one-shot runner | `plugins/woocommerce/includes/class-woocommerce.php:454` registers `WooPaymentsCanceledAuthorizationFeeRemediationService`. `plugins/woocommerce/src/Internal/Payments/Providers/WooPayments/WooPaymentsCanceledAuthorizationFeeRemediationService.php:64-78` preserves the action hooks and `:556-591` schedules live remediation. `WooPaymentsCutoverController.php:339-344` refuses plugin deactivation when scheduling is unavailable. | Unsafe until A5/default-on data safety. A6 says delete one-shot runners only after effects are ensured-applied; local source does not prove production completion. Keep until cutover evidence says no affected orders remain or the live job has completed safely. |
| WooPayments extension-version coexistence branch | `plugins/woocommerce/src/Internal/Admin/Settings/PaymentsProviders/WooPayments/WooPaymentsService.php:35` sets minimum extension version `9.3.0`; `plugins/woocommerce/src/Internal/Payments/Providers/WooPayments/WooPaymentsLegacyRuntime.php:378-388` compares the active extension; `plugins/woocommerce/src/Internal/Admin/Settings/PaymentsProviders/WooPayments.php:123-135` and `WooPaymentsService.php:1812-1819` use it to gate native in-context onboarding. | Unsafe before mandatory cutover/default-on. This is plugin coexistence support while the standalone extension can still own runtime. It becomes a later cleanup candidate once plugin-active coexistence is no longer supported. |
| Legacy request-object aliases and preserved request filters | `plugins/woocommerce/src/Internal/Payments/Providers/WooPayments/WooPaymentsPaginatedListRequest.php:42-53`, `WooPaymentsTransactionsListRequest.php:31-38`, and `Api/WooPaymentsApiRequest.php:57-65` alias native request objects to `WCPay\Core\Server\Request\*`. `plugins/woocommerce/src/Internal/Payments/Providers/WooPayments/WooPaymentsTransactionsRestController.php:379-390` and `WooPaymentsDisputesRestController.php:340-345` apply preserved request filters. | Needs more evidence, not first slice. The manifest says Request-layer filters are internal transport and redesignable, but current source/tests intentionally preserve them for surviving admin money-movement routes. Remove only after an explicit API/filter contract decision. |
| Store setup compatibility payload `stripe_billing_enabled => false` | `plugins/woocommerce/src/Internal/Payments/Providers/WooPayments/WooPaymentsOperationalQueueService.php:802-804` sends `stripe_billing_enabled => false` in the store setup snapshot; `:823-824` still reports WC Subscriptions active/version. | Needs external/schema evidence. Local source cannot prove the WooPayments compatibility/setup API or downstream consumers no longer expect this field. Do not remove in a source-only A6 pass. |
| Vendored/duplicated libraries and JS/runtime assets | No tracked native WooPayments `vendor`, `dist`, `build`, `runtime`, `node_modules`, or `subscriptions-core` paths found. Classic assets are `plugins/woocommerce/client/legacy/js/frontend/woopayments-checkout.js`, `woopayments-express-checkout.js`, `woopayments-woopay.js`, and matching SCSS, registered by `plugins/woocommerce/includes/class-wc-frontend-scripts.php:274-283`, `:403-407`, and `:472-489`. Block assets are under `plugins/woocommerce/client/blocks/assets/js/extensions/payment-methods/woopayments/**`, registered by `plugins/woocommerce/src/Blocks/Payments/Integrations/WooPayments.php:44-49` and `:157-186`. | No safe cleanup from source evidence. These are separate classic and block checkout surfaces, not redundant vendored runtime. |

## First Slice Boundary

Remove only the deprecated WooPayments Subscriptions settings surface:

- Backend: stop exposing/updating `is_wcpay_subscriptions_enabled`, `is_wcpay_subscriptions_eligible`, and `is_subscriptions_plugin_active` from `WooPaymentsSettingsService` if no other native admin surface needs them.
- Frontend: remove `updateIsWCPaySubscriptionsEnabled`, `getIsWCPaySubscriptionsEnabled`, `getIsWCPaySubscriptionsEligible`, `getIsSubscriptionsPluginActive`, `useWCPaySubscriptions`, and the deprecated checkbox block from `AdvancedSettingsSection`.
- Tests: update settings service/admin data/page tests that assert the old fields/UI, while retaining tests that protect WC Subscriptions integration and Stripe Billing cutover guards.

Explicitly out of scope for this first slice: `WooPaymentsLegacySubscriptionsGuard`, `WooPaymentsCutoverController` marker blocking, `WooPaymentsCanceledAuthorizationFeeRemediationService`, retired invoice event alarms, WC Subscriptions gateway supports/hooks/emails, and classic/block checkout assets.
