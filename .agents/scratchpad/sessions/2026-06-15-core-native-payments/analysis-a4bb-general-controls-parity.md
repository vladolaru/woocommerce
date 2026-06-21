---
session: 2026-06-15-core-native-payments
type: analysis
by: codex
created: 2026-06-21 01:03
last_updated: 2026-06-21 01:06
target: A4bb General settings controls parity
reconciles:
  - review-a4ay-settings-general-explorer.md
  - review-a4au-settings-inventory.md
  - review-a4bb-general-controls-source-check.md
  - supervisor-prompt-2026-06-18-2344-N12.md
status: draft
---

# A4bb General Settings Controls Parity

> **Prompt:** "ok. continue"
> **Prompt:** "Remember to constantly record your progress so it survives compactions and you don't redo your steps after a compaction."

## Current Selection

After closing A4ba, the next coherent reopened-A4/N12 slice is General/account-mode controls parity. This keeps scope wider than a single tiny control while staying inside one merchant-facing settings surface: switch-to-live notice/modal fidelity, WooPayments enable/disable confirmation/help/tracking, test-mode help/tracking/confirmation behavior, and the adjacent settings save telemetry if source verification keeps it tied to the same save section.

## Source Anchors

- Native `plugins/woocommerce/client/admin/client/woopayments/settings/account-mode-notice.tsx` already renders test/sandbox notices, opens a setup-live modal for test accounts, and bridges `wcpay:activate_payments`.
- Native `plugins/woocommerce/client/admin/client/woopayments/settings/settings-page.tsx` already renders the General section, test-mode confirmation modal, `SettingsBusyState`, and a local `SaveSettingsSection`.
- Native focused tests in `plugins/woocommerce/client/admin/client/woopayments/settings/test/settings-page.test.tsx` already cover test-mode confirmation, switch-to-live notice/modal, dev-mode-disabled test-mode checkbox, duplicate notice behavior, saving errors, and fraud tour flows.
- Reference `client/components/sandbox-mode-switch-to-live-notice/index.tsx` adds `ClickTooltip` learn-more affordances and Tracks events around setup-live modal open and learn-more clicks for test, sandbox, and dev-mode variants.
- Reference `client/components/sandbox-mode-switch-to-live-notice/modal/index.tsx` records setup-live primary CTA and modal exit events and carries a submitted/busy state.
- Reference `client/settings/general-settings/enable-woopayments-checkbox.js` records `wcpay_gateway_toggle` when the gateway is toggled and includes confirmation behavior before disabling WooPayments.
- Reference `client/settings/general-settings/index.js` records `wcpay_settings_setup_live_payments_click`, `wcpay_test_mode_disabled`, `wcpay_test_mode_enabled`, `wcpay_test_mode_modal_exit`, and setup-live modal exit events.
- Reference `client/settings/save-settings-section/index.js` records `wcpay_payment_request_settings_change` when express/payment-request settings change during save; native `SaveSettingsSection` has no corresponding Tracks event yet.

## Initial Gap Disposition

- In scope for A4bb: account-mode notice tooltip/learn-more events, setup-live modal CTA/exit/busy behavior, test-mode enable/disable events and modal-exit event, WooPayments gateway toggle confirmation/help/tracking, and save-section express/payment-request telemetry if it can be added without broad express settings rewrites.
- Out of scope for A4bb unless tests expose coupling: per-section loading skeletons, low-priority docs-link polish across BNPL/Notifications/Transactions/Payouts/Advanced, and broad visual hoisting of the whole reference settings manager.
- WPCOM remains off limits. Reference reads are local-only from `/Users/vladolaru/Work/a8c/woocommerce-payments`; no WPCOM sandbox access, no WPCOM code changes, no Stripe CLI use, no push, and no trunk work.

## Verified Source Snapshot

- Native `settings-page.tsx` already has `TestModeConfirmationModal` and a General section, but it does not import `recordEvent`. Enabling test mode opens the modal and then updates the setting, disabling test mode updates the setting immediately, and neither transition records the reference Tracks events.
- Native test-mode help copy is simplified. It lacks the reference non-dev `test card numbers` link and `Learn more` link, and the dev-mode help lacks the reference WordPress-environment link, `WCPAY_DEV_MODE` instruction, and testing documentation link.
- Native WooPayments enablement currently toggles `setIsWCPayEnabled( Boolean( value ) )` directly. It lacks the reference `wcpay_gateway_toggle` events and does not ask for confirmation before disabling the provider.
- Reference disable behavior lives in `client/settings/general-settings/enable-woopayments-checkbox.js` plus `client/disable-confirmation-modal/index.js`. The reference modal dynamically lists affected enabled payment methods: enabled standard payment methods except Link, Apple Pay / Google Pay when payment-request is enabled, Amazon Pay when enabled and feature-available, Link when selected, and WooPay when enabled.
- Native already has the data needed for a native dynamic affected-method list inside `settings-page.tsx`: enabled method IDs, available payment method IDs, payment-request enabled, WooPay enabled, Amazon Pay enabled, feature flags from settings, and native payment-method definitions. Native should not pull the whole reference illustration/icon stack into this slice.
- Native `account-mode-notice.tsx` already fetches account mode, renders test/sandbox notices, and bridges `wcpay:activate_payments`, but the event bridge does not record `wcpay_settings_setup_live_payments_click`, the notice button does not record the reference setup-live modal-open event, and the setup-live modal uses an `href` primary action with no submitted/busy state or close/submit Tracks events.
- Reference `SandboxModeSwitchToLiveNotice` records `wcpay_setup_live_payments_modal_open`, `wcpay_overview_sandbox_mode_learn_more_clicked`, `wcpay_onboarding_flow_setup_live_payments`, and `wcpay_setup_live_payments_modal_exit`. A4bb will prioritize the setup-live open/submit/exit path; tooltip learn-more events remain included only if they can be added cleanly without widening into a full custom tooltip component.
- Native `SaveSettingsSection` already has `useGetSettings()` and local initial-state tracking for WooPay feedback. Payment-request save telemetry is possible in the same component by tracking initial `is_payment_request_enabled`, but it should stay secondary to the General/account-mode parity work.

## Open Checks

- Boole the 6th source-check report is reconciled in `review-a4bb-general-controls-source-check.md`. It found no critical gaps, confirmed the same high-priority missing provider toggle, setup-live, and test-mode parity, and recommended keeping only the reference `wcpay_payment_request_settings_change` event in A4bb rather than widening into express-detail settings work.
- Implementation should start with RED tests in `settings-page.test.tsx`; product files remain untouched at this point.
