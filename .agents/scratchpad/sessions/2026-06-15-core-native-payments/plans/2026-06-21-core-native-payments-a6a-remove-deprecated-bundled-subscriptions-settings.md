---
session: 2026-06-15-core-native-payments
type: plan
by: codex
created: 2026-06-21 03:04
last_updated: 2026-06-21 03:33
status: held
stage: A6a
---

# A6a Remove Deprecated Bundled Subscriptions Settings

## Current Status

Held for release/default-on sequencing. The source-backed cleanup candidate remains valid for future A6 execution, but `review-a6-boundary-audit.md` returned `HOLD_FOR_RELEASE_DECISION` and `review-a5k-a6-cleanup-boundary.md` refined the reason for this specific slice: the deprecated bundled-subscriptions settings surface currently mirrors the standalone extension's disable-only `_wcpay_feature_subscriptions` contract, and the standalone extension still uses that option to load `subscriptions-core` / bundled subscriptions behavior when it owns runtime. While the branch remains fail-closed/default-off and plugin rollback/coexistence is part of the A5 safety model, removing this Core surface would remove a merchant-facing deprecation/disable path and leave stale plugin-owned state meaningful on rollback. This plan is preserved so the future slice does not need to be rediscovered, but it is not active implementation.

`review-a6-bucket-d-source-audit.md` independently confirms this as the first safe Bucket-D cleanup slice once sequencing allows A6 product cleanup. `review-a6-facade-callers-audit.md` independently rules out facade/runtime cleanup as the first slice because those surfaces are either absent, core-wide, still live, or preserved compatibility contracts.

## Goal

Remove the remaining deprecated WooPayments-bundled subscriptions settings contract and UI from native WooPayments settings. Preserve Bucket-C WooCommerce Subscriptions renewals and preserve the Stripe Billing legacy data-safety guard. Do not delete merchant data, do not remove local harness infrastructure, and do not make any WPCOM changes.

## Source-Backed Scope

- Remove active native settings reads/writes for `_wcpay_feature_subscriptions` from `WooPaymentsSettingsService`.
- Remove the native settings response fields `is_wcpay_subscriptions_enabled`, `is_wcpay_subscriptions_eligible`, and `is_subscriptions_plugin_active`.
- Remove the frontend settings data action/selectors/hook for the deprecated bundled-subscriptions toggle.
- Remove the disabled `Enable Subscriptions with WooPayments` advanced settings control and its deprecation copy from the native settings page.
- Keep `NativeWooPaymentsGateway::scheduled_subscription_payment()` and all WC Subscriptions gateway integration behavior.
- Keep `WooPaymentsLegacySubscriptionsGuard` and retired `invoice.*` alarm handling.
- Leave legacy options/meta in place; the slice stops depending on deprecated flags but does not destroy merchant data.

## RED First

1. Update `WooPaymentsSettingsServiceTest` to assert the native settings contract no longer exposes the bundled-subscriptions fields.
2. Add/adjust backend coverage so stale `is_wcpay_subscriptions_enabled` update input is ignored and does not mutate an existing `_wcpay_feature_subscriptions` option.
3. Update settings data tests to assert no bundled-subscriptions action, selectors, or hook are exported.
4. Update settings page tests to assert the deprecated bundled-subscriptions control and copy are absent.

## Implementation

1. Remove the `_wcpay_feature_subscriptions` option constant, response fields, eligibility helper, and update write path from `WooPaymentsSettingsService`.
2. Remove `updateIsWCPaySubscriptionsEnabled`, `getIsWCPaySubscriptionsEnabled`, `getIsWCPaySubscriptionsEligible`, `getIsSubscriptionsPluginActive`, and `useWCPaySubscriptions`.
3. Remove the settings-page import, state wiring, and Advanced section checkbox/copy for bundled WooPayments subscriptions.
4. Clean up tests/mocks for removed exports while keeping existing Stripe Billing absence and Bucket-C renewal tests intact.

## Verification Gates

- Focused PHP: `pnpm --filter=@woocommerce/plugin-woocommerce test:php:env -- --filter WooPaymentsSettingsServiceTest`
- Focused JS: `pnpm test:js -- settings-data.test.ts settings-page.test.tsx --runInBand` from `plugins/woocommerce/client/admin`
- Bucket-C regression: run the existing focused gateway renewal coverage that includes tokenized WC Subscriptions renewals after the removal.
- Static checks for touched files: PHP syntax, changed-file PHPCS, production PHPStan for touched PHP production classes, exact-file ESLint for touched TS/TSX, admin type lint, and admin bundle build if frontend source changes require it.
- Changelog validation and branch lint before any commit.

## Review And Closeout

Before closeout, reconcile the A6 read-only audit agents and record any findings. If they surface a source-backed blocker that changes this slice, stop and adjust before final verification. Close the plan only after staging-log/spec-baseline/implementation-log carry the final evidence and limitations.
