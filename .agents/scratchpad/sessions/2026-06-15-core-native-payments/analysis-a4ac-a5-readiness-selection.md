---
session: 2026-06-15-core-native-payments
type: analysis
by: codex
created: 2026-06-20 00:45
target: A4ab-to-A5 readiness selection
reconciles:
  - analysis-a4ab-admin-exit-gate.md
  - supervisor-prompt-2026-06-17-1311.md
  - supervisor-prompt-2026-06-18-2344-N12.md
  - staging-log.md
  - spec-conformance-baseline.md
status: draft
last_updated: 2026-06-20 00:52
---

# A4ab To A5 Readiness Selection

## Trigger

The A4ab widened native admin browser/runtime gate is committed and the user asked to continue. The next step is to decide whether the current source-backed baseline supports an A5 readiness slice, or whether A4 needs another product/gate-hardening slice first.

## Current Baseline

A4ab is the latest committed product baseline: it fixed the Overview route heading, embedded settings heading hierarchy, and early multi-currency analytics currency resolution; the stricter Playwriter route matrix passed with 57 checks and 0 failures; target `debug.log` stayed empty; source/chunk gate measured native admin chunks at `197638` raw / `53642` gzip. A4ab explicitly did not flip `FILTER_NATIVE_ADMIN_SURFACES_READY`.

The remaining decision is not to reopen stale N12 missing-route findings. A4i through A4ab have source-backed entries for admin navigation, settings sections, express/fraud subpages, menu badges, PM promotions, overview/dashboard, money movement lists/details, Card Readers, Capital, Documents, Reports, dispute challenge, and the widened browser/runtime route matrix.

## Selection Questions

- Does the current code still keep native admin readiness fail-closed, and what exact preflight failures remain before cutover can be considered?
- Does the A4ab evidence satisfy the A4/N10 route-architecture, single-registry, and bundle-delta exit obligations, or does a source-backed A4ac gate-hardening slice need to land first?
- Do the current source and docs expose any non-stale A4 parity blocker after A4ab that must be implemented before an A5 readiness plan?

## Work Plan

I will inspect the current cutover/readiness source locally and dispatch independent reviewers for the A4 exit/N10 posture and A5 cutover-preflight risk. Any finding must be source-verified before it becomes product work. If the answer is "A5 readiness can start," I will write a just-in-time A5 readiness plan before touching code. If the answer is "A4ac still required," I will plan that slice instead.

## Interim Source Findings

- `WooPaymentsCutoverController::get_preflight_failures()` still keeps native admin readiness fail-closed by default: `apply_filters( FILTER_NATIVE_ADMIN_SURFACES_READY, false )` adds `native_admin_surfaces_unavailable` unless a later slice explicitly flips or filters it. The same method re-adds platform connection failures after the broad preflight filter and re-adds `legacy_stripe_billing_subscriptions_present` after the filter if the legacy marker guard detects data, so those two blocker classes cannot be hidden by a general filter.
- Provider-event and operational-queue blockers are now source-cleared by default: `WooPaymentsEventIngestor::KNOWN_UNHANDLED_EVENT_TYPES` is an empty array, and `WooPaymentsCutoverController::DEFAULT_PENDING_OPERATIONAL_QUEUE_HOOKS` is an empty array. Current source also registers the historically missing `wcpay_instant_deposit_reminder` and `wcpay_post_kyc_activation_email_send` hooks in `WooPaymentsOperationalQueueService::register()` when native owns runtime, with tests asserting registration.
- Stripe Billing retirement appears source-backed at the cutover boundary: `NativeWooPaymentsGatewayTest` asserts deprecated `_wcpay_feature_subscriptions` and `_wcpay_feature_stripe_billing` no longer add `gateway_scheduled_payments`, tokenized scheduled renewals still run, and `WooPaymentsEventIngestor` logs an alarm for retired `invoice.*` events instead of silently falling through. `WooPaymentsLegacySubscriptionsGuard` detects subscription, migrated subscription, billing invoice, pending invoice, discount, and migration markers across `shop_subscription` and `shop_order` in HPOS and CPT storage. `WooPaymentsCutoverControllerTest` covers live markers, migrated variants, invoice order markers, HPOS markers, clean-store allow, signposted merchant copy, and mandatory auto-deactivation blocking.
- A4 route ownership is source-enforced in two places. `woopayments/admin/routes.tsx` registers the native admin surfaces as `/woopayments/*` provider subroutes through `registerSettingsPaymentsProviderRoute`, and `register-provider-routes.test.tsx` asserts the full native route list and rejects `/payments/*` paths. `WooPaymentsAdminNavigationController` owns the persistent WP-admin submenu and maps plugin-era `/payments/*` requests to canonical Settings > Payments URLs only when native runtime and provider/account state allow it.
- The current source/chunk gate covers the N10 technical shape: expected lazy native admin chunk names, no plugin-era provider route paths, forbidden private registry tokens (`createRegistry`, `RegistryProvider`, `useRegistry`) in the native settings/admin source tree, persistent admin navigation route coverage, and aggregate native/admin plugin baseline bytes. A4ab added the browser/runtime route matrix on top of that.

## Review Verdicts

- Architecture review (`Bernoulli the 5th`) found no source-backed A4ac blocker. It approved the route-ownership write-back, single-registry/no-duplicated-admin-runtime evidence, and A4ab bundle delta as sufficient to start an A5 readiness slice. The reviewer reran the A4 source gate and measured native chunks at `197685` raw / `53668` gzip versus reference plugin admin baseline `905253` raw / `217657` gzip.
- Reliability review (`Aristotle the 5th`) found no current source-backed A5 product defect in the cutover readiness paths. It confirmed the only current source-backed cutover blocker is the intentional `FILTER_NATIVE_ADMIN_SURFACES_READY` default false; provider events, queue hooks, legacy Stripe Billing guard, canceled-authorization fee remediation, WPCOM/platform readiness, owner user-token, and V1 transport continuity are represented in source/tests.

## Selection

Proceed with a focused A5d native admin readiness slice rather than another A4 product backfill. The slice should TDD the readiness default change, preserve all hard fail-closed blockers, rerun A4ab source/browser/log evidence, rerun A5 local WPCOM/user-token/transport probes, and record the final stage-boundary evidence before committing. Mandatory cutover remains default-off; this slice only lets the already guarded preflight stop failing solely on `native_admin_surfaces_unavailable`.
