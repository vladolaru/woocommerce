---
session: 2026-06-15-core-native-payments
type: plan
by: codex
created: 2026-06-21 00:26
target: A4ba advanced fraud UI parity
reconciles:
  - analysis-a4ba-advanced-fraud-ui-parity.md
  - review-a4az-fraud-residual-source-check.md
status: final
last_updated: 2026-06-21 01:02
---

# A4ba Advanced Fraud UI Parity Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use $subagent-driven-development (recommended) or $executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Restore merchant-facing advanced fraud configuration UI parity after A4az restored the backend ruleset contract. Keep the current native Core settings route and provider-owned data architecture; do not port private WooPayments plugin layout/components mechanically.

**Architecture:** Keep the implementation in the existing native advanced fraud route chunk. Extract small local helpers/components only when they reduce repetition inside this page. Use WP/Core components and native class names; do not introduce plugin-only dependencies such as `wcpay/components/loadable`, `FormBusyState`, `InlineNotice`, `CardBody`, or `SettingsLayout`.

**Tech Stack:** WooCommerce Core admin React/TypeScript, `@wordpress/components`, existing settings data hooks, focused Jest, ESLint, TypeScript, admin bundle build, Playwriter smoke/log proof.

---

## File Structure

- Modify `plugins/woocommerce/client/admin/client/woopayments/settings/fraud-protection/advanced/index.tsx`: restore links, allowed-countries notices, threshold detail controls/help/inline notices, and loading/busy presentation.
- Modify `plugins/woocommerce/client/admin/client/woopayments/settings/fraud-protection/advanced/style.scss`: style native advanced fraud skeletons, notices, threshold control groups, and footer busy presentation.
- Modify `plugins/woocommerce/client/admin/client/woopayments/settings/test/fraud-protection-advanced.test.tsx`: add RED/GREEN coverage for the restored merchant-facing details and loading state.
- If needed, update `plugins/woocommerce/client/admin/client/woopayments/settings/fraud-protection/advanced/types.ts` only for local type clarity. Avoid touching shared settings data plumbing unless tests prove it is necessary.

## Task 1: RED Loading And Busy Presentation Tests

- [x] Add focused tests proving the advanced route no longer renders only a plain loading string. Expected native behavior should be Core-owned but reference-equivalent: loading state exposes multiple rule-card placeholders/skeletons in a busy status region and does not render writable controls.
- [x] Add a save-busy test proving the footer/form exposes busy state beyond only the button label/spinner if the current implementation lacks it. Keep this scoped to visible native classes/ARIA rather than private reference `FormBusyState`.
- [x] Run `pnpm exec jest --config client/jest.config.js --runTestsByPath client/woopayments/settings/test/fraud-protection-advanced.test.tsx --runInBand` from `plugins/woocommerce/client/admin` and verify the new tests fail for the expected reasons.

## Task 2: RED Rule Detail Parity Tests

- [x] Add tests for AVS warning "selling locations" link to WooCommerce general settings when no configured selling location supports AVS.
- [x] Add tests for International IP Address copy links: "IP addresses" external explainer and "supported countries" WooCommerce general settings link.
- [x] Add tests for International IP Address allowed-countries notice for `specific` and/or `all_except` selling locations, including the blocked/screened copy changing with the filter action.
- [x] Add tests for IP Address Mismatch "IP address" external explainer link.
- [x] Add tests for Purchase Price Threshold `Limits`, currency-prefixed amount controls, "Leave blank for no limit" help, and inline empty-range / min-greater-than-max notices.
- [x] Add tests for Order Items Threshold `Limits`, "per order" labels, "Leave blank for no limit" help, integer input constraints, and inline empty-range / min-greater-than-max notices.
- [x] Run the focused Jest command and verify failures are aligned with missing UI parity, not fixture mistakes.

## Task 3: GREEN Native UI Implementation

- [x] Add local link helpers for the WooCommerce general settings URL and the IP-address explainer URL.
- [x] Add a native allowed-countries notice helper that reads `fraud_protection_allowed_countries` from the existing settings payload, uses `window.wcSettings.countries` labels where available, and presents the reference block/screen copy without depending on plugin globals.
- [x] Replace plain threshold `TextControl` groups with local threshold controls that include `Limits`, field help, integer guards for item counts, currency prefix/display for purchase price, and inline notices while editing.
- [x] Add native loading skeleton/placeholders and busy presentation that preserve the Core chunk boundary and do not load extra code unless this route is active.
- [x] Keep existing A4ay behavior intact: no controls on `"error"` sentinel, card impression Tracks events once per card, save event only after `saveSettings()` succeeds, beforeunload preservation, and focus on validation errors.

## Task 4: Focused Frontend Verification

- [x] Run focused Jest for `fraud-protection-advanced.test.tsx`.
- [x] Run exact-file ESLint for touched fraud advanced TS/TSX files.
- [x] Run admin TypeScript lint if any TS/TSX production code changes.
- [x] Run targeted Stylelint for touched SCSS.
- [x] Run `pnpm build:project:bundle` from `plugins/woocommerce/client/admin` and verify no unintended bundle split or broad settings payload regression.

## Task 5: Browser And Review Gates

- [x] Use Playwriter on `http://store8889.localhost:8889/wp-admin/admin.php?page=wc-settings&tab=checkout&path=/woopayments/settings/fraud-protection` and the advanced route to verify the real target page still renders and no console/network failures appear beyond the known JQMIGRATE/unload messages.
- [x] If deterministic browser manipulation of threshold inline notices is practical, drive one threshold empty-range or invalid-range interaction; otherwise state the limitation and rely on focused Jest/source proof.
- [x] Scan target `debug.log` and recent target Docker logs for PHP/WP diagnostics and actual 5xx statuses.
- [x] Request focused accessibility and frontend/API-contract reviews before commit. Add reliability review if loading/busy/error handling changes are broader than expected.
- [x] Add a WooCommerce changelog entry if product code changes ship.
- [x] Run changelog validation, `git diff --check -- . ':!.agents'`, and `pnpm --filter=@woocommerce/plugin-woocommerce lint:changes:branch`.
- [x] Commit source/tests and changelog locally on `exp/core-native-payments`; do not push.

## Carry-Forward Tasks

- [ ] After A5c-level checkpoints are revisited, keep A4 reopened per N12 until merchant reachability, functional parity, visual/styling parity, copy/content parity, and the widened A4 exit gate pass.
- [ ] After the appropriate closeout point, re-check N8 supervisor advisory before advancing cutover readiness.
