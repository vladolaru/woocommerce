---
session: 2026-06-15-core-native-payments
type: plan
by: codex
created: 2026-06-17 05:40
target: B3af mobile and IPP REST continuity
reconciles:
  - ../analysis-mobile-ipp-rest-continuity.md
  - ../implementation-log.md
status: draft
---

# B3af Mobile and IPP REST Continuity

## Scope

Preserve the hard external WooPayments mobile and In-Person Payments REST contracts under native Core ownership. This chunk covers connection tokens, terminal order payment preparation/intent/capture, customer creation if still missing, terminal readers, reader charge summaries, receipt preview/generation, and terminal locations. It also keeps the Settings Payments conclusion explicit: the generic WooCommerce provider list must keep working through normal provider metadata, not a WooPayments-specific frontend bypass.

## Non-Goals

- Do not touch WPCOM code, WPCOM sandbox state, or the WooPayments plugin repo.
- Do not broaden Settings Payments frontend behavior beyond preserving provider metadata parity.
- Do not implement deprecated WooPayments welcome-page/admin surfaces.
- Do not change the harness to hide product bugs; harness changes are allowed only for honest coverage gaps and must stay local.

## Work Plan

1. Add RED contract coverage for native route registration and permissions under `wc/v3/payments/*`, using the existing WooCommerce REST test patterns and fake WooPayments API client transport.
2. Extend `WooPaymentsApiClient` with explicit terminal/mobile methods for connection tokens, terminal intents/preparation, terminal locations, terminal readers, reader charge summaries, receipt charge/intent lookups as needed, and customer creation if the native route is absent.
3. Add a native REST controller/service layer under `Internal\Payments\Providers\WooPayments` that registers only when the native runtime owns WooPayments, preserves the reference permission model (`manage_woocommerce`), maps validation and `WP_Error` codes to reference-compatible shapes, caches readers/locations under the preserved transient keys, and keeps API exceptions from leaking as fatals.
4. Add focused order helpers for terminal capture side effects: set WooPayments as the payment method, attach intent/charge metadata through existing native order primitives where possible, preserve `receipt_url`, preserve IPP channel metadata when present, reject refunded/mismatched/uncapturable orders, and complete terminal payments with the reference hook/filter behavior.
5. Add receipt preview/generation support sufficient to preserve mobile route shape and shopper/merchant-facing receipt HTML behavior, reusing existing Core/WooCommerce receipt primitives where available and adding a small native renderer only where no Core primitive exists.
6. Run focused PHPUnit RED/GREEN, PHPStan for production files, explicit PHPCS for touched PHP, changed PHP lint, `git diff --check`, WP-CLI route probes on the target store, browser sanity for Settings Payments and checkout surfaces, restored cross-store harness, strict Docker/debug-log scans, and subagent code review before commit.

## Gates

- PHPUnit: new native mobile/IPP controller and API client tests plus related gateway/order-data tests.
- Static: PHPStan for touched production PHP, explicit PHPCS for touched PHP, `pnpm --filter=@woocommerce/plugin-woocommerce lint:php:changes`, and `git diff --check`.
- Runtime: target WP-CLI authenticated REST probes for each route family, Settings Payments browser reload with providers POST 200, checkout test-mode surfaces still present, restored `tools/woopayments-merge/verify.sh` 7/7, and no fresh PHP/WP notices/warnings/fatals/deprecations in target/reference logs.
- Review: at least one adversarial subagent review before claiming the chunk done.

## Decision Notes

- This is one larger continuity chunk because the route families share the same controller permissions, API transport, transient cache, and order-side terminal payment behavior. Splitting too narrowly would increase repeated harness/browser cost and leave mobile clients half-preserved.
- The Settings Payments provider-list issue is treated as a Core native wiring regression unless current evidence proves otherwise. The correct fix pattern is exposing accurate native WooPayments provider/gateway capabilities through existing WooCommerce provider APIs.
