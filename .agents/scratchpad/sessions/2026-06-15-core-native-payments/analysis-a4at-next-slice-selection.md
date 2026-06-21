---
session: 2026-06-15-core-native-payments
type: analysis
by: codex
created: 2026-06-20 19:22
target: A4 reopened parity slice after A5g
reconciles:
  - README.md
  - implementation-log.md
  - staging-log.md
  - spec-conformance-baseline.md
  - supervisor-prompt-2026-06-17-1311.md
  - supervisor-prompt-2026-06-18-2344-N12.md
status: draft
last_updated: 2026-06-20 19:35
---

# A4at Next Slice Selection

## Context

A5g closed with product commits `eea3dc14e1` and `20f5128a71`, a clean tracked worktree, and a fail-closed native rollout default plus multisite runtime proof. The standing user direction after that cutover sequence is to reopen A4 for N12 parity work before moving toward final default-on or A6 cleanup.

## Source-Backed Findings

The admin navigation foundation is present: `WooPaymentsAdminNavigationController` registers native WooPayments submenu entries under the Core Payments parent, uses Settings > Payments provider paths, exposes route availability through shared settings, and preserves disputes and uncaptured-transaction badges.

The route/reachability residuals are real and source-backed. Native legacy redirects currently cover overview, payouts/deposits, transactions, reports, disputes, card readers, loans, documents, and settings, but not `/payments/connect`, `/payments/onboarding`, `/payments/onboarding/kyc`, `/payments/fraud-protection`, `/payments/multi-currency-setup`, or `/payments/additional-payment-methods`. The active multi-currency admin note still points at `admin.php?page=wc-admin&path=/payments/multi-currency-setup` instead of a native Settings > Payments route.

The gateway-enabled menu/redirect gate needs a careful decision rather than a mechanical change. Native currently suppresses persistent menu entries and legacy redirects when the gateway is disabled, while the reference plugin's WC Admin menu is account-state gated rather than gateway-enabled gated. This can affect merchants who have an account but temporarily disable the payment method and still need settings or account routes reachable. The slice should either fix that behavior or record a source-backed reason to keep it.

The setup-required top-level badge is not equivalent in native. The reference plugin can badge the top-level Payments menu after an activation delay or when connection/account state needs attention. Native currently has route-specific disputes and uncaptured-transaction badges only. Because native WooPayments now lives under Core Payments instead of a separate top-level plugin menu, this needs a product/architecture decision: port the attention signal to the provider submenu/onboarding item, or explicitly document it as superseded by Core provider/status UI.

The settings-parity explorer found additional settings-section gaps. Its recommendation to implement Stripe Billing/subscriptions settings parity conflicts with the settled N9/H31 decision: Stripe Billing is Bucket D/deprecated, must not be ported into Core, and invoice/subscription engine surfaces should retire behind data-safety guards. Treat Stripe Billing UI as intentionally not the next path unless a source-backed guard bug is found.

## Decision

Next canonical slice: **A4at Provider Route Reachability Parity**.

This slice reopens A4 in the direction the user asked for: native WooPayments-specific routes sit under WooCommerce Settings > Payments, old plugin-era deep links funnel cleanly into that model, and merchant-facing navigation remains reachable without resurrecting deprecated Stripe Billing. It is more urgent than remaining settings copy/polish because broken links and missing route aliases violate reachability and can strand merchants from existing notices, docs, bookmarks, and provider controls.

## Intended Scope

1. Add native legacy alias coverage for the missing `/payments/*` routes and preserve safe query parameters.
2. Update the multi-currency admin-note action URL to a native Settings > Payments route.
3. Decide and test the gateway-disabled reachability contract for settings/onboarding legacy aliases and persistent menu entries.
4. Resolve the setup-required badge gap either by implementing an appropriate native Core Payments signal or recording a tested, source-backed disposition if Core provider UI supersedes it.
5. Run focused unit tests, source checks, and a Playwriter/browser reachability check on the target store; compare reference route behavior where relevant.

## Out Of Scope

Do not port Stripe Billing settings, invoice event handling, or the legacy subscriptions engine. Do not edit WPCOM. Do not refactor the broader Settings > Payments provider list beyond the minimum route seam needed for WooPayments-specific subroutes.

## Implementation Checkpoint

The implementation follows the selected route-reachability path. RED tests failed on the expected missing aliases, stale admin-note URL, gateway-disabled admin reachability gaps, and connected-account setup-alias dead-end. The current GREEN implementation adds the missing aliases, maps multi-currency and additional-payment-methods aliases to native settings sections, resolves setup aliases dynamically to onboarding/overview/settings based on route availability, makes admin route/menu reachability account-state based while keeping `gatewayEnabled` as data, and updates the multi-currency note to the native Settings > Payments URL. Focused PHPUnit now passes for `WooPaymentsAdminNavigationControllerTest|MultiCurrencyAdminNoteProjectionServiceTest` with 53 tests and 284 assertions.

Playwriter target evidence `data/a4at-route-reachability-browser.json` passed all six route aliases on the current connected target account, with no unexpected browser logs. Target `debug.log` was 0 bytes and strict recent Docker-log scans found no PHP/WP diagnostics or actual 5xx status fields.

Setup-required badge disposition: do not resurrect the plugin-era top-level badge mechanically. Native WooPayments does not own a separate top-level plugin menu, and source shows the Core provider/account settings surfaces expose setup/onboarding URLs plus readiness copy such as "Payments need attention." A Core Payments parent/provider badge can be revisited as a separate product decision if future not-ready browser proof shows no attention signal, but it is not required to close this route-reachability parity slice.
