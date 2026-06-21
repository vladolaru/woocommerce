---
session: 2026-06-15-core-native-payments
type: plan
by: codex
created: 2026-06-19 05:46
last_updated: 2026-06-19 05:46
status: draft
reconciles:
  - supervisor-prompt-2026-06-18-2344-N12.md
  - staging-log.md
---

# A4n Settings Residual Parity Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use $subagent-driven-development (recommended) or $executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Close the next high-leverage WooPayments settings parity gaps under N12: the test/sandbox account switch-to-live notice, payout bank-account/failure state, page-level save busy state, and adjacent payout schedule copy/date-label parity.

**Architecture:** Keep the settings route owned by WooCommerce Settings > Payments and keep the WooPayments settings bundle lazy-loaded. Reuse the existing native settings store for saved settings, the existing native account summary for account mode/setup URL, and the existing deposits overview endpoint for payout bank-account state; do not add placeholder PM-promotion UI without the source-backed service/API contract.

**Tech Stack:** React/TypeScript, `@wordpress/components`, `@wordpress/api-fetch`, WooCommerce admin client routing, Jest/React Testing Library, WooCommerce Core scoped SCSS, Playwriter for browser parity checks.

---

## File Map

- Modify `plugins/woocommerce/client/admin/client/woopayments/settings/settings-page.tsx`: wire account-mode notice, payout bank-account block, monthly ordinal labels, seven-day waiting copy, and `SettingsBusyState` into the settings page.
- Modify `plugins/woocommerce/client/admin/client/woopayments/settings/test/settings-page.test.tsx`: add RED/GREEN coverage for the notice/modal, event listener, payout bank-account/failure state, monthly labels, and page-level busy state.
- Modify `plugins/woocommerce/client/admin/client/woopayments/settings/style.scss`: add WooPayments-scoped styles for the notice/modal, payout bank-account block, failed-payout notice, and busy-state opacity/cursor.
- Create `plugins/woocommerce/client/admin/client/woopayments/settings/account-mode-notice.tsx`: source-backed test/sandbox account notice plus native setup-live modal/event handling.
- Create `plugins/woocommerce/client/admin/client/woopayments/settings/payout-bank-account.tsx`: deposits overview fetch plus reference-aligned payout bank-account management/failure copy.
- Create `plugins/woocommerce/client/admin/client/woopayments/settings/settings-busy-state.tsx`: small accessibility wrapper mirroring the reference `FormBusyState` behavior.
- Modify `plugins/woocommerce/client/admin/client/woopayments/admin/overview/components/payouts-overview-card.tsx` only if the failed-payout notice can be cleanly shared without coupling settings to overview card internals.
- Update `implementation-log.md`, `staging-log.md`, and `spec-conformance-baseline.md` with the A4n result and any deliberately deferred PM-promotion source-backed work.
- Add a WooCommerce Core changelog entry after production changes.

## Task 1: RED Settings Parity Tests

**Files:**
- Modify `plugins/woocommerce/client/admin/client/woopayments/settings/test/settings-page.test.tsx`

- [ ] **Step 1: Add account API fixture defaults.**

Set the existing `mockApiFetch` default to return a native account summary for `WOOPAYMENTS_ACCOUNT_SETTINGS_PATH` and a deposits overview for `/wc/v3/payments/deposits/overview-all`:

```ts
mockApiFetch.mockImplementation( ( options: { path?: string } ) => {
	if ( options.path === '/wc-admin/settings/payments/woopayments/account' ) {
		return Promise.resolve( {
			account: {
				id: 'acct_test',
				mode: 'test',
				default_currency: 'usd',
				connected: true,
				working: true,
				can_process_payments: true,
				test_mode: true,
				test_drive: false,
				sandbox: false,
				live: true,
			},
			urls: {
				setup: 'admin.php?page=wc-settings&tab=checkout&path=/woopayments/onboarding',
			},
		} );
	}

	if ( options.path === '/wc/v3/payments/deposits/overview-all' ) {
		return Promise.resolve( {
			account: {
				account_link: 'https://connect.stripe.test/account',
				default_currency: 'usd',
				default_external_accounts: [
					{ currency: 'usd', status: 'enabled' },
				],
			},
		} );
	}

	return Promise.resolve( {} );
} );
```

- [ ] **Step 2: Add RED account-mode notice tests.**

Add tests asserting that a connected non-live test-drive account renders the reference warning copy and `Activate payments` button, dispatching `wcpay:activate_payments` opens the `Activate payments on your store` modal, and clicking the modal CTA changes `window.location.href` to the native setup URL with `from=wcpay-setup-live-payments`.

Run:

```bash
pnpm --filter=@woocommerce/admin-library test:js -- client/woopayments/settings/test/settings-page.test.tsx --runInBand
```

Expected: FAIL because native settings currently has no account-mode notice or modal.

- [ ] **Step 3: Add RED payout bank-account tests.**

Add tests asserting that the Payouts section includes `Payout schedule`, `Payout bank account`, `Manage and update your bank account information to receive payouts.`, and a `Manage in Stripe` external link when the overview has no errored external account; add a second test where `default_external_accounts` contains `{ currency: 'usd', status: 'errored' }` and assert `Payouts are currently paused because a recent payout failed.` plus `update your bank account details`.

Run the same settings-page test command. Expected: FAIL because the bank-account block is absent.

- [ ] **Step 4: Add RED save-busy and payout-copy tests.**

Add tests asserting that `isSaving: true` puts `aria-busy="true"` on the settings busy wrapper and renders the polite screen-reader `Saving…` status. Add tests asserting the waiting-period copy includes `standard 7-day waiting period` and monthly payout options include ordinal labels such as `1st`, `2nd`, and `3rd`.

Run the same settings-page test command. Expected: FAIL for the busy wrapper, seven-day copy, and ordinal labels.

## Task 2: GREEN Account-Mode Notice

**Files:**
- Create `plugins/woocommerce/client/admin/client/woopayments/settings/account-mode-notice.tsx`
- Modify `plugins/woocommerce/client/admin/client/woopayments/settings/settings-page.tsx`
- Modify `plugins/woocommerce/client/admin/client/woopayments/settings/style.scss`

- [ ] **Step 1: Implement `AccountModeNotice`.**

Fetch `getWooPaymentsAccountSettings()` on mount. Render nothing for live accounts, missing accounts, disconnected accounts, or invalid responses. For connected non-live test-drive accounts, render the reference warning copy with `You are using a test account.`, `Provide additional details about your business so you can begin accepting real payments.`, `Learn more`, and an `Activate payments` button unless dev mode is enabled. For connected non-live sandbox accounts, render `You are using a sandbox test account.` and the reset/setup guidance without an activate CTA.

- [ ] **Step 2: Implement the setup-live modal and event bridge.**

Listen for `wcpay:activate_payments` while the component is mounted and open a modal titled `Activate payments on your store`. Preserve the reference body copy: `Before continuing, please make sure that you're aware of the following:`, `Your test account will be deactivated, but your transactions can be found in your order history.`, `To use WooPayments, you will need to verify your business details.`, and `In order to receive payouts, you will need to provide your bank details.`. The CTA is `Activate payments` and redirects to the native setup URL with `from=wcpay-setup-live-payments`; if no setup URL exists, do not render the CTA.

- [ ] **Step 3: Wire the notice into `GeneralSettingsSection`.**

Render `<AccountModeNotice isDevModeEnabled={ isDevModeEnabled } />` before the enable/test-mode controls so the account-state warning appears at the top of General settings.

- [ ] **Step 4: Add scoped styles.**

Use `.woopayments-settings-account-mode-notice` and `.woopayments-settings-setup-live-modal` selectors only. Keep the styles in the settings bundle and avoid global selectors.

- [ ] **Step 5: Run focused tests.**

```bash
pnpm --filter=@woocommerce/admin-library test:js -- client/woopayments/settings/test/settings-page.test.tsx --runInBand
```

Expected: account-mode tests pass.

## Task 3: GREEN Payout Bank Account and Payout Copy

**Files:**
- Create `plugins/woocommerce/client/admin/client/woopayments/settings/payout-bank-account.tsx`
- Modify `plugins/woocommerce/client/admin/client/woopayments/settings/settings-page.tsx`
- Modify `plugins/woocommerce/client/admin/client/woopayments/settings/style.scss`

- [ ] **Step 1: Implement `PayoutBankAccount`.**

Fetch `getWooPaymentsDepositsOverview()` on mount. If any `default_external_accounts` entry has `status === 'errored'`, render the failed-payout notice `Payouts are currently paused because a recent payout failed.` with `update your bank account details` linked to `account_link` plus `from=WCPAY_PAYOUTS&source=wcpay-payout-failure-notice`. Otherwise render `Manage and update your bank account information to receive payouts.` and `Manage in Stripe` when `account_link` is a string. On fetch failure, keep the bank-account heading and management copy without an external link; do not block the whole settings page.

- [ ] **Step 2: Wire Payout subheadings and bank-account block.**

Inside `PayoutsSettingsSection`, add `FieldGroup` boundaries or heading structure matching reference intent: `Payout schedule` above schedule controls/notices and `Payout bank account` above `<PayoutBankAccount />`.

- [ ] **Step 3: Restore monthly ordinal labels.**

Add a local helper equivalent to the reference `getDepositMonthlyAnchorLabel()` for 1-28 plus `Last day of the month`. For English locales, return `1st`, `2nd`, `3rd`, `4th` … `28th`; preserve the existing `Last day of the month` label for value `31`.

- [ ] **Step 4: Restore seven-day waiting-period copy.**

When the account is inside the initial waiting period, use `Payout scheduling becomes available after the standard 7-day waiting period for new accounts is complete.` to match the reference merchant guidance, regardless of the account delay-days setting shown in the section description.

- [ ] **Step 5: Run focused tests.**

```bash
pnpm --filter=@woocommerce/admin-library test:js -- client/woopayments/settings/test/settings-page.test.tsx --runInBand
```

Expected: payout bank-account and payout copy tests pass.

## Task 4: GREEN Page-Level Save Busy State

**Files:**
- Create `plugins/woocommerce/client/admin/client/woopayments/settings/settings-busy-state.tsx`
- Modify `plugins/woocommerce/client/admin/client/woopayments/settings/settings-page.tsx`
- Modify `plugins/woocommerce/client/admin/client/woopayments/settings/style.scss`

- [ ] **Step 1: Implement `SettingsBusyState`.**

Create a wrapper with `aria-busy={ isBusy }`, a first-child `role="status"` and `aria-live="polite"` screen-reader text that renders `Saving…` while busy, and a busy class that dims content without disabling controls or removing them from tab order.

- [ ] **Step 2: Wrap loaded settings content.**

In `WooPaymentsSettingsPage`, wrap the loaded settings sections and save bar in `<SettingsBusyState isBusy={ isSaving }>` so the whole form announces saving, while the save button remains busy/disabled as before.

- [ ] **Step 3: Run focused tests.**

```bash
pnpm --filter=@woocommerce/admin-library test:js -- client/woopayments/settings/test/settings-page.test.tsx --runInBand
```

Expected: save-busy tests pass.

## Task 5: Gates, Browser Parity, and Documentation

**Files:**
- Modify `plugins/woocommerce/changelog/*`
- Modify `.agents/scratchpad/sessions/2026-06-15-core-native-payments/implementation-log.md`
- Modify `.agents/scratchpad/sessions/2026-06-15-core-native-payments/staging-log.md`
- Modify `.agents/scratchpad/sessions/2026-06-15-core-native-payments/spec-conformance-baseline.md`

- [ ] **Step 1: Run focused and static gates.**

Run the settings test plus relevant static gates:

```bash
pnpm --filter=@woocommerce/admin-library test:js -- client/woopayments/settings/test/settings-page.test.tsx --runInBand
pnpm --filter=@woocommerce/admin-library lint:js -- client/admin/client/woopayments/settings/settings-page.tsx client/admin/client/woopayments/settings/account-mode-notice.tsx client/admin/client/woopayments/settings/payout-bank-account.tsx client/admin/client/woopayments/settings/settings-busy-state.tsx
pnpm --filter=@woocommerce/admin-library lint:style -- client/admin/client/woopayments/settings/style.scss
pnpm --filter=@woocommerce/admin-library build:ts
```

- [ ] **Step 2: Run the settings bundle build.**

Run the existing admin/settings build command used by prior A4 slices. Record the command and result in `implementation-log.md`.

- [ ] **Step 3: Run Playwriter browser parity checks.**

Compare reference `http://localhost:8082/wp-admin/admin.php?page=wc-settings&tab=checkout&section=woocommerce_payments` and target `http://store8889.localhost:8889/wp-admin/admin.php?page=wc-settings&tab=checkout&path=/woopayments/settings`. Check the General test-account notice/modal, Payout schedule/bank-account grouping, failed-payout notice where locally triggerable, monthly labels, and save busy announcement/state. Capture screenshots only if needed for evidence.

- [ ] **Step 4: Run harness and local runtime log gates.**

Run the restored local harness with progress output and scan both WC store Docker logs for PHP notices, warnings, 400/500, and React/runtime errors. Treat new warnings as blockers.

- [ ] **Step 5: Record results and commit locally.**

Update the session docs with the A4n scope, evidence, PM-promotion deferral, and N12 status. Add a Core changelog entry. Commit only the completed logical change locally on the feature branch and do not push.

## Deliberate Deferred Item

Source-backed PM promotions are not part of A4n. The reference promotion UI depends on the WooPayments PM promotions service, REST controller, cache/dismissal state, activation hooks, and settings-save side effects. Native currently returns `pm_promotions: []`. A later A4 parity slice must either port that backend contract cleanly into Core-owned provider abstractions or record a deliberate product-approved disposition; A4n must not mask the gap with static frontend badges.

