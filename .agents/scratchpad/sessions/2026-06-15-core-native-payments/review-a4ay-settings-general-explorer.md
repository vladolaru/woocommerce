---
session: 2026-06-15-core-native-payments
type: review
by: subagent:Maxwell the 6th
created: 2026-06-20 22:59
target: A4 follow-up General settings polish inventory
reconciles:
  - review-a4au-settings-inventory.md
  - supervisor-prompt-2026-06-18-2344-N12.md
status: final
---

# A4 General Settings Explorer Findings

## Scope

Read-only source-backed parity check for native WooPayments settings General/section polish after A4av, A4aw, and A4ax. The native Core branch was compared with the read-only WooPayments client reference. No product/source files were edited and no WPCOM or remote sandbox access occurred. A subagent originally wrote this report into a separate scratchpad session; this artifact preserves the useful findings in the active Core native payments session.

## Closed Gaps

- Native settings sections and save bar are present and tested in `plugins/woocommerce/client/admin/client/woopayments/settings/settings-page.tsx` and `plugins/woocommerce/client/admin/client/woopayments/settings/test/settings-page.test.tsx`.
- Test-mode enable confirmation exists and is tested.
- Test-account switch-to-live notice/modal and legacy `wcpay:activate_payments` event bridge are present.
- Express, fraud, multi-currency, payment-method status docs/copy are substantially restored.
- Duplicate payment-method notices, dismissal persistence, suppression by row status notice, and focus return are covered.
- Save busy state and duplicate server-error suppression are covered.

## Remaining Source-Backed Gaps

- Switch-to-live notice/modal still lacks reference `ClickTooltip` behavior, learn-more/setup/exit tracking, and submitted busy state. Native code is in `plugins/woocommerce/client/admin/client/woopayments/settings/account-mode-notice.tsx`; reference behavior is in `/Users/vladolaru/Work/a8c/woocommerce-payments/client/components/sandbox-mode-switch-to-live-notice/`.
- General WooPayments enable/disable toggles immediately, without disable confirmation, checkbox help copy, or `wcpay_gateway_toggle` tracking. Native code is in `plugins/woocommerce/client/admin/client/woopayments/settings/settings-page.tsx`; reference behavior is in `/Users/vladolaru/Work/a8c/woocommerce-payments/client/settings/general-settings/enable-woopayments-checkbox.js`.
- Test-mode help text/tracking remains below reference parity: no test-card/Learn more links and no enable/disable/modal-exit events.
- Save telemetry for payment-request/express setting changes is missing. Native `SaveSettingsSection` saves settings but does not mirror reference `wcpay_payment_request_settings_change` telemetry.
- Loading parity is still a gap: native uses one spinner while the reference uses per-section skeletons.
- Low/medium section docs links remain missing for BNPL, Notifications, Transactions/manual capture, baseline Payouts docs, and Advanced section docs.

## Recommendation

Treat this as a later bounded A4 slice after the active fraud slice: "General telemetry and loading/docs polish." Keep the slice to General controls parity, switch-to-live tooltip/tracking/modal busy state, per-section loading skeletons, and the missing section docs links. If needed, split into General telemetry/tooltips first and skeleton/docs links second. Browser proof should cover activate-payments notice/modal, dev-mode warning, enable-disable confirmation, test-mode confirm/direct-disable, throttled loading skeletons, duplicate notice suppression/dismissal, and save busy/error/focus behavior.
