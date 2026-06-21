---
session: 2026-06-15-core-native-payments
type: plan
by: codex
created: 2026-06-19 22:26
target: A4aa native WooPayments settings contact and advanced-copy parity
reconciles:
  - ../supervisor-prompt-2026-06-18-2344-N12.md
  - ../analysis-a4aa-settings-contact-validation-parity.md
  - ../analysis-a4z-admin-exit-residuals.md
status: final
last_updated: 2026-06-19 23:16
---

# A4aa Settings Contact Validation Parity Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use $subagent-driven-development (recommended) or $executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Close the next source-backed WooPayments settings parity cluster: notifications email confirmation/validation, transaction support contact helper/validation copy, and advanced settings copy/dev-mode/subscriptions behavior.

**Architecture:** Keep WooPayments settings under the existing native Settings > Payments provider route and lazy settings chunk. Add small local helpers inside the settings module only where they make form validation and copy parity explicit; continue using the native settings data hooks and save action. Do not reintroduce Stripe Billing, do not depend on the standalone plugin runtime, and keep native admin cutover readiness fail-closed.

**Tech Stack:** React/TypeScript, `@wordpress/components`, `@wordpress/url` email validation if available, WooCommerce admin settings store hooks, Jest/React Testing Library, scoped WooPayments SCSS, Playwriter for browser parity checks.

---

## File Map

- Modify `plugins/woocommerce/client/admin/client/woopayments/settings/settings-page.tsx`: add notifications email warning/confirmation validation; restore transaction helper copy and support email/phone validation; restore advanced multi-currency, subscriptions, and debug-mode copy/behavior.
- Modify `plugins/woocommerce/client/admin/client/woopayments/settings/test/settings-page.test.tsx`: add RED/GREEN behavior tests for the parity gaps.
- Modify `plugins/woocommerce/client/admin/client/woopayments/settings/style.scss` only if needed for warning/error/help layout; keep styles scoped under `.woopayments-settings-page`.
- Modify `plugins/woocommerce/client/admin/client/settings-payments/test/register-provider-routes.test.tsx` if the route expectation still omits Reports/Documents from the native provider route family.
- Modify `tools/woopayments-merge/a4-admin-surface-gate.py` only if adding source-token assertions for the new parity behaviors is useful; do not make the harness suppress real bugs.
- Add one WooCommerce Core changelog entry after the production diff is green.
- Update `implementation-log.md`, `staging-log.md`, and `spec-conformance-baseline.md` after verification.

## Task 1: RED Settings Parity Tests

**Files:**
- Modify `plugins/woocommerce/client/admin/client/woopayments/settings/test/settings-page.test.tsx`

- [x] **Step 1: Add notification confirmation test.**

Add a test that renders `WooPaymentsSettingsPage`, finds the Account notifications section, verifies "Notifications email", "Provide an email address where you would like to receive communications about your WooPayments account.", and "Anyone with access to this email address will be treated as the account owner. Please verify the address carefully.", changes the email field from `owner@example.com` to `new-owner@example.com`, verifies the "Confirm email address" input appears, blurs it with a mismatched value, and expects "Email addresses do not match. Please re-enter your email address."

Run:

```bash
pnpm --filter=@woocommerce/admin-library test:js -- client/woopayments/settings/test/settings-page.test.tsx --runInBand
```

Expected RED: the warning copy, confirmation input, and mismatch error are absent.

- [x] **Step 2: Add notification email validation test.**

Add a test that changes the notifications email to `invalid`, blurs the input, and expects "Please enter a valid email address." to be exposed through a status region and `aria-invalid` on the email field.

Run the same focused Jest command.

Expected RED: native currently has no client-side notification email validation.

- [x] **Step 3: Add transaction helper and support validation tests.**

Add tests asserting the Transactions section includes the saved-card platform-storage copy "Card details are stored in our platform, not on your store.", the customer-statement helper "Edit the way your store name appears on your customers' bank statements.", and the customer-support helper "Provide contact information where customers can reach you for support." Add support-email validation by changing "Support email" to `not-email`, blurring it, and expecting "Please enter a valid email address." plus `aria-invalid`. Add support-phone empty validation by clearing "Support phone number" and expecting "Support phone number cannot be empty."

Run the same focused Jest command.

Expected RED: native currently uses simplified support inputs and copy.

- [x] **Step 4: Add advanced copy/dev/subscriptions tests.**

Add tests asserting the Advanced settings section shows "Allow customers to shop and pay in multiple currencies." with a "Learn more" link to `multi-currency-setup`, renders the deprecated subscriptions help "This feature is deprecated. Existing subscription renewals will continue to work, but creating or managing subscriptions is no longer available.", prevents enabling subscriptions when currently disabled, and renders dev-mode debug copy "Log error messages (defaulted on for test accounts)" with the checkbox checked and disabled when `useDevMode()` returns true.

Run the same focused Jest command.

Expected RED: native currently uses simplified advanced copy and does not enforce the reference subscriptions/dev-mode behavior.

## Task 2: GREEN Native Settings Behavior

**Files:**
- Modify `plugins/woocommerce/client/admin/client/woopayments/settings/settings-page.tsx`
- Modify `plugins/woocommerce/client/admin/client/woopayments/settings/style.scss` if needed

- [x] **Step 1: Add validation helpers.**

Inside `settings-page.tsx`, add a small `isValidEmailAddress( value: string )` helper. Prefer `isEmail` from `@wordpress/url` if the package exposes it in this build; otherwise use a small local check that only gates obvious invalid addresses for UI parity and leaves server validation authoritative. Do not add a new dependency.

- [x] **Step 2: Implement reference notifications behavior.**

Turn `NotificationsSettingsSection` into a stateful section that captures the initial communications email after render, detects changes, renders the warning notice, validates non-empty email format after blur, renders the confirm-email field when changed, shows mismatch error after confirm blur, and exposes `aria-invalid`/`aria-describedby` on invalid fields. Save gating can remain server-backed in this slice unless the existing native save flow already supports passing a section-valid flag without a broader refactor.

- [x] **Step 3: Implement transaction helper/support behavior.**

Update saved-card, statement, and support copy to preserve the reference informational content. Add support-email validation on blur and server-error precedence using `savingError.account_business_support_email` when present. Rename the phone label to "Support phone number", add the reference help copy, preserve the test-mode onboarding hint for `+1 0000000000`, and validate empty phone values with the reference error copy. Keep the existing plain `TextControl` for phone unless the reference lazy phone input can be hoisted cleanly without adding disproportionate bundle/runtime weight.

- [x] **Step 4: Implement advanced copy/dev/subscriptions behavior.**

Update multi-currency help to include the reference Learn more link. Update subscriptions so a currently disabled deprecated bundled-subscriptions toggle cannot be enabled and the help points merchants to WooCommerce Subscriptions; allow disabling when currently enabled. Update debug logging so dev mode forces the checkbox checked, disables it, and uses the reference dev-mode label/help.

- [x] **Step 5: Add minimal styles.**

If the added notices/errors need spacing, add scoped styles in `style.scss` under existing WooPayments settings class names. Do not add global styles or a new asset entry.

Run:

```bash
pnpm --filter=@woocommerce/admin-library test:js -- client/woopayments/settings/test/settings-page.test.tsx --runInBand
```

Expected GREEN: the new tests and existing settings tests pass.

## Task 3: Gates, Browser Proof, Reviews, And Commit

**Files:**
- Modify `plugins/woocommerce/client/admin/client/settings-payments/test/register-provider-routes.test.tsx` if source verification confirms the expectation is stale
- Modify `plugins/woocommerce/changelog/*`
- Modify scratchpad logs only under `.agents/scratchpad/sessions/2026-06-15-core-native-payments/`

- [x] **Step 1: Clean stale provider-route expectation if present.**

Verify whether `register-provider-routes.test.tsx` still expects the WooPayments route list to stop at `/woopayments/loans` even though `routes.tsx` registers Reports and Documents. If stale, update the test expectation only; do not change production route ownership. Run the focused route-registration test with the existing admin-library Jest command.

- [x] **Step 2: Run focused static/build gates.**

Run targeted lint/type/build gates for changed settings files. Candidate commands:

```bash
pnpm --filter=@woocommerce/admin-library lint:js -- client/woopayments/settings/settings-page.tsx client/woopayments/settings/test/settings-page.test.tsx
pnpm --filter=@woocommerce/admin-library lint:style -- client/woopayments/settings/style.scss
pnpm --filter=@woocommerce/admin-library ts:check
pnpm --filter=@woocommerce/plugin-woocommerce build:admin
git diff --check -- . ':!.agents'
```

If a command name differs locally, use the equivalent existing command from prior A4 slices and record the exact command/result.

- [x] **Step 3: Run A4 harness/source gate.**

Run the existing A4 admin surface gate and write evidence:

```bash
tools/woopayments-merge/a4-admin-surface-gate.py --repo /Users/vladolaru/Work/a8c/woocommerce-develop-2 --plugin-repo /Users/vladolaru/Work/a8c/woocommerce-payments --out .agents/scratchpad/sessions/2026-06-15-core-native-payments/data/a4aa-admin-surface-gate.json
```

Expected: PASS. If the gate fails, fix product code or source-backed assertions; do not weaken the harness.

- [x] **Step 4: Run Playwriter settings parity check.**

Use Playwriter against target `http://store8889.localhost:8889/wp-admin/admin.php?page=wc-settings&tab=checkout&path=/woopayments/settings` and reference `http://localhost:8082/wp-admin/admin.php?page=wc-settings&tab=checkout&section=woocommerce_payments` or the reference WooPayments settings route. Check the target has the notifications warning/confirm behavior, support email/phone errors, advanced copy/dev-mode behavior where local account flags permit, and no failed network responses. Capture screenshots/evidence in the session `data/` folder.

- [x] **Step 5: Run fresh log scans.**

Clear or snapshot relevant target/reference debug logs before the browser pass, then scan after for PHP/WP notices, warnings, deprecations, fatals, uncaught errors, stack traces, REST 4xx/5xx, and database errors. Do not ignore notices.

- [x] **Step 6: Run review gates.**

Dispatch at least an a11y/frontend review and a code-quality review over the A4aa diff. Fix any blocking findings and re-run focused gates.

- [x] **Step 7: Record and commit.**

Update `implementation-log.md`, `staging-log.md`, and `spec-conformance-baseline.md` with scope, evidence, residuals, and the fact that native admin readiness remains fail-closed. Add a WooCommerce changelog entry. Commit the logical A4aa product/changelog diff locally using Conventional Commits; do not push.

## Exit Criteria

A4aa is complete when the source-backed notifications, transaction support, and advanced settings parity behaviors are implemented with RED/GREEN tests; focused lint/type/build/A4 gate/browser/log/review gates pass; session docs record evidence and residuals; the product diff is committed locally; and `FILTER_NATIVE_ADMIN_SURFACES_READY` still fails closed until the broader A4/N12 exit gate passes.

## Closeout

A4aa is closed locally as `99453ab9fc` plus changelog `44c9640bac`, with git range `9c735d2cb0...44c9640bac`. The broader A4/N12 exit gate remains open and native admin readiness stays fail-closed.
