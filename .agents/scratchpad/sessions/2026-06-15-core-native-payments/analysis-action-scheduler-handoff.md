---
session: 2026-06-15-core-native-payments
type: analysis
by: codex
created: 2026-06-17 03:21
tool: systematic-debugging
target: WooPayments Action Scheduler handoff
reconciles:
  - implementation-log.md
  - staging-log.md
status: draft
---

# Action Scheduler Handoff Analysis

> **Prompt:** "Ok. You remove the test harness earlier by mistake. Now it is back in tools/woopayments-merge, gitignored so you can leave it there. Backtrack to all the gates you skipped because they relied on the harness, re-run those gates and fix everything you find in each. Then proceed with the plan until completion and don't skip verification gates anymore"

> **Prompt:** "Remember that under no circumstance you are to access my WPCOM sandbox or make WPCOM repo code changes. You need to work locally with the exception of using the Stripe CLI to ONLY read things about the WooPayments account state. You have everything you need to test e2e locally: the two WC stores wp-env environments, properly wired to the local WPCOM environment (using wpcom-local). You can use the browser, CLI access, create local probes."

> **Prompt:** "Both local env were working e2e before you started working. So if anything breaks is because of your changes. Do not ignore WP notices or warnings because they may point to something off."

> **Prompt:** "Also, why isn't http://store8889.localhost:8889/wp-admin/admin.php?page=wc-settings&tab=checkout loading with the providers list (including WooPayments) like http://localhost:8082/wp-admin/admin.php?page=wc-settings&tab=checkout is? Are you absolutely sure it is a limitation of the http://store8889.localhost:8889/ local env or is it something we broke? The frontend UX of the WooCommerce > Settings > Payments page should not be affected by our changes - it needs to work as it used to work because it is not WooPayments specific (WooPayments is a provider just like any other, even if now it is a WC native provider). Think deeply about how we wire that in in a proper way."

## Findings

- `wcpay_webhook_fetch_events`, `wcpay_webhook_process_event`, `wcpay_track_new_order`, and `wcpay_track_update_order` are already native-owned after B3ac.
- The next bounded operational queue handoff should cover `wcpay_store_setup_sync`, `wcpay_update_saved_payment_method`, `wcpay_add_fee_breakdown_to_order_notes`, and `wcpay_update_compatibility_data`. These map cleanly to existing native transport/account/order services plus small API client extensions.
- The reference store setup sync posts to `/sites/{blog}/wcpay/accounts/store_setup` with a `snapshot` body and onboarding test-mode context. The snapshot includes gateway enabled/test-mode state, available/enabled/disabled payment methods and mapped capabilities, saved cards/manual capture/logging settings, express checkout/WooPay settings, multi-currency and Stripe Billing flags, plugin/core version data, WordPress setup data, and WooCommerce setup data.
- The reference compatibility sync posts `compatibility_data` to `/sites/{blog}/wcpay/compatibility`. The payload is small and self-contained: WooPayments version, WooCommerce version, WooCommerce permalink settings and page permalinks, active theme, active plugins, and public post-type publish counts.
- The reference saved-payment-method job applies the job's test-mode context through `wcpay_test_mode`, reads the order, builds billing details from the order, and posts them to `/sites/{blog}/wcpay/payment_methods/{payment_method_id}` as `billing_details`.
- The reference fee-breakdown job applies the job's test-mode context through `wcpay_test_mode`, fetches `/sites/{blog}/wcpay/timeline/{intent_id}`, selects the `captured` event, renders fee details into an order note, and saves the order. Native Core already has duplicated fee-note rendering logic from payment-intent/webhook paths, so B3ad should centralize that logic rather than duplicating it again.
- `wcpay_instant_deposit_reminder` depends on WooPayments-specific inbox note classes/assets that are not present in Core. `wcpay_post_kyc_activation_email_send` depends on WooPayments-specific email classes and templates that are not present in Core. Registering empty native handlers for those hooks would conceal lost merchant-facing work by letting Action Scheduler complete the actions without executing their intended side effects.
- The Settings > Payments provider list regression has already been traced to Core-native WooPayments wiring, not to the target local environment. B3ad must keep the generic settings provider route/store path intact and verify that the provider list still renders after queue changes.
