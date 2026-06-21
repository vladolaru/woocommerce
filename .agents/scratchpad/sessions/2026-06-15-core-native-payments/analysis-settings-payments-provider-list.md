---
session: 2026-06-15-core-native-payments
type: analysis
by: codex
created: 2026-06-17 00:47
tool: systematic-debugging
target: WooCommerce Settings Payments provider list
reconciles:
  - README.md
  - implementation-log.md
  - staging-log.md
status: draft
last_updated: 2026-06-17 08:39
---

# Settings Payments Provider List Analysis

> **Prompt:** "Also, why isn't http://store8889.localhost:8889/wp-admin/admin.php?page=wc-settings&tab=checkout loading with the providers list (including WooPayments) like http://localhost:8082/wp-admin/admin.php?page=wc-settings&tab=checkout is? Are you absolutely sure it is a limitation of the http://store8889.localhost:8889/ local env or is it something we broke? The frontend UX of the WooCommerce > Settings > Payments page should not be affected by our changes - it needs to work as it used to work because it is not WooPayments specific (WooPayments is a provider just like any other, even if now it is a WC native provider). Think deeply about how we wire that in in a proper way."

## Investigation Frame

The target store must render the generic WooCommerce > Settings > Payments provider list with WooPayments included as one provider. This is not a WooPayments-only surface, so native WooPayments ownership must plug into the existing provider infrastructure rather than bypassing, replacing, or poisoning the generic provider list.

Current root-cause question: is the target page failing because the store environment cannot support the provider list, or because this branch changed provider identity/readiness/bootstrap data in a way that breaks the generic settings page?

## Evidence Log

- 2026-06-17 00:47: Started focused systematic-debugging pass. No fix selected yet.
- 2026-06-17 01:33: Conclusion: this was something we broke in native Core wiring, not a target local-env limitation. The native Settings provider payload had three product gaps after the plugin was removed from ownership: onboarding fields still depended on plugin REST routes and the wrong WPCOM request shape, native WooPayments had no `get_recommended_payment_methods()` provider contract, and plugin-absent fields missed `__locale` plus `available_countries`. The proper wiring is to keep Settings Payments on the existing generic provider REST/UI flow and make native WooPayments satisfy the same provider/onboarding contracts through Core-owned services. Runtime proof now shows target provider POST 200 with WooPayments present once, no business-verification errors, field keys matching reference, and browser-rendered provider rows. Checkout proof shows target Blocks keeps the Test Mode badge and test-card guidance, while target classic keeps the same test-card guidance as reference classic.
- 2026-06-17 08:15: Rechecked the exact target URL fresh in Chrome: `http://store8889.localhost:8889/wp-admin/admin.php?page=wc-settings&tab=checkout&codex_provider_recheck=1781670001`. The page rendered the generic Settings Payments provider list with `Payment providers`, business-location selector, `Accept payments with Woo`, `Test account`, `Official`, recurring-payments affordance, `Manage`, `PayPal Payments`, `Install`, `Take offline payments`, and `More payment options`. The Settings Payments provider REST request was `POST /wp-json/wc-admin/settings/payments/providers?_locale=user` with body `{"location":"GB"}` and status 200; saved as `data/settings-payments-providers-target-b3ah-recheck.network-response`. Browser console had no errors; only the existing deprecated `List` warning. Target Settings assets loaded from the normal WooCommerce build path, including `wp-admin-scripts/settings-embed.js` and `chunks/settings-payments-main.js`, all 200. The reference store fresh URL `http://localhost:8082/wp-admin/admin.php?page=wc-settings&tab=checkout&codex_provider_ref_recheck=1781670001` rendered the same core list semantics and returned the same endpoint shape; saved as `data/settings-payments-providers-reference-b3ah-recheck.network-response`. The expected difference is provider provenance: target WooPayments has `plugin.slug=woocommerce`, while reference has `plugin.slug=woocommerce-payments`; the provider id, title, state, native onboarding type, onboarding href shape, management href shape, and suggestion IDs match. Reference also includes reference-only custom MU gateways not present on the target store, so provider counts differ for that environmental reason. Current conclusion: the original failure was a branch regression that has since been fixed; current `store8889.localhost:8889` is not intrinsically unable to load this page, and the current branch keeps the Settings Payments UX on the generic provider pipeline.
- 2026-06-17 08:39: Rechecked target and reference after the B3ah WooPay session changes. Target fresh URL `http://store8889.localhost:8889/wp-admin/admin.php?page=wc-settings&tab=checkout&codex_b3ah_settings_recheck=1781670999` rendered the same generic provider list with WooPayments, PayPal, offline methods, and More payment options. The target provider POST returned 200 and is saved as `data/settings-payments-providers-target-b3ah-post-woopay-session.network-response`; reference fresh URL `http://localhost:8082/wp-admin/admin.php?page=wc-settings&tab=checkout&codex_b3ah_ref_settings_recheck=1781670999` also returned 200 and is saved as `data/settings-payments-providers-reference-b3ah-post-woopay-session.network-response`. Both pages loaded Core `settings-embed.js` and `settings-payments-main.js`, and both consoles only showed the pre-existing deprecated `List` warning. The target payload still matches the reference contract for WooPayments provider id, title, state, native onboarding type, recommended payment methods, and Settings links, with the expected Core-owned `plugin.slug=woocommerce` difference. While comparing checkout session wiring, I found a separate B3ah regression risk: the reference WooPay checkout config key is `initWooPayNonce`, but the new native bridge exposed `woopayInitNonce`. That does not explain the Settings Payments provider-list page, but it would break reference WooPay frontend code once the Core-owned WooPay assets consume the config.
