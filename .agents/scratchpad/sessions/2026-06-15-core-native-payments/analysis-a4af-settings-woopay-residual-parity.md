---
session: 2026-06-15-core-native-payments
type: analysis
by: codex
created: 2026-06-20 04:05
last_updated: 2026-06-20 04:33
reconciles:
  - supervisor-prompt-2026-06-18-2344-N12.md
  - analysis-a4ad-next-parity-slice.md
  - analysis-a4ae-payment-detail-dispute-action-parity.md
  - staging-log.md
status: complete
---

# A4af Settings and WooPay Residual Parity

> **Prompt:** "ok. continue"

## Working Question

A4ae closed the first payment-detail dispute decision slice but left the full A4/N12 admin parity gate fail-closed. The remaining recorded residual clusters are WooPay/settings parity and detail-level money actions. A4af evaluates which chunk moves the final native WooPayments admin state forward most safely, then implements the selected chunk with TDD and browser proof.

## Initial Source Read

Native already has more settings infrastructure than the older N12 snapshot described: `plugins/woocommerce/client/admin/client/woopayments/settings/express-checkout/express-checkout-settings.tsx` registers dedicated method pages for `woopay`, `payment_request`, and `amazon_pay`, and `settings-page.tsx` has Customize links for express checkout methods through the Core Settings > Payments provider route. That means A4af should not treat "missing express checkout sub-pages" as still wholly true without source verification.

Native WooPay settings are still a source-backed parity target. `plugins/woocommerce/client/admin/client/woopayments/settings/express-checkout/woopay-settings.tsx` contains enable/location/global-theme/message/logo controls and a preview, but the recorded A4ad residuals still call out WooPay disable-feedback modal and WooPay/Link legal copy. Those need direct reference comparison before code changes.

Detail-level refund/capture/fraud-review actions remain money-moving and require a stronger money-safety gate than a settings-only slice. Unless source review shows a small safe seam, settings/WooPay parity is the more appropriate next chunk because it restores merchant-facing configuration without initiating money movement.

## Pending Verification

Parallel read-only explorers are checking WooPay/express settings parity, broader settings sections, and the remaining money-detail actions. Their findings will be validated against source before A4af scope is locked.

## Source-Backed Scope Decision

A4af should restore the WooPay disable-feedback contract. The reference settings save footer keeps the initial `is_woopay_enabled` setting, waits for a successful save, and opens `client/settings/woopay-disable-feedback/index.js` only when WooPay changed from enabled to disabled and the last disable date is not within the last seven days. The modal is merchant-facing, contains the WooPay logo, and embeds the feedback survey at `https://woocommerce.survey.fm/woopay-disabled-merchants-feedback-triggered`.

The server side is part of the same contract. Reference `includes/class-wc-payment-gateway-wcpay.php::update_is_woopay_enabled()` records `platform_checkout_last_disable_date` when WooPay is disabled, while reference `includes/admin/class-wc-payments-admin.php::get_js_settings()` exposes that value as `woopayLastDisableDate`. Native `WooPaymentsSettingsService` currently maps `is_woopay_enabled` directly to `platform_checkout` through `LOCAL_SETTING_MAP`, so it does not record the disable date. Native settings also does not expose a `woopay_last_disable_date`/bootstrap equivalent to the React settings page, and `SaveSettingsSection` only shows a generic save status.

This is a coherent settings parity chunk with no money movement. It should be fixed as one slice: persist the last-disable date when native settings successfully disable WooPay, include the refreshed value in the settings contract, and show the feedback modal after a successful disable save when the reference throttle allows it. The modal should use Core-owned local UI and scoped assets/styles rather than plugin globals; the URL can remain the same reference survey URL because it is the merchant-facing feedback destination, not a runtime dependency on the plugin.

## A4af Boundaries

Do not broaden A4af into the full settings hoist, fraud advanced parity, payment-method row parity, or payment-detail refund/capture/fraud-review actions. Those remain A4/N12 follow-ups. Also do not port Stripe Billing, do not touch the standalone WooPayments plugin, do not access WPCOM, and do not flip native admin readiness.

## Subagent Findings During A4af

Curie the 5th completed a read-only money-detail action pass and confirmed the next highest-risk A4 residual after A4ae is native money-detail action parity: refund modal parity, detail-level capture/cancel controls, and fraud-review approve/block controls. The report is source-backed against native `transaction-details-page.tsx`, `WooPaymentsAuthorizationsRestController.php`, and the reference payment-details summary/refund-modal/action modules. Because A4af already has failing backend tests for the WooPay disable-feedback contract, the clean course is to finish A4af and then treat Curie's finding as the next stronger money-safety candidate rather than abandoning a red slice.

Carver the 5th completed a read-only broader settings pass and found that most broad settings sections are already native: General, Payment methods, BNPL, Express, Transactions, Payouts, Notifications, Fraud, Advanced, and the save footer. The remaining prioritized settings gaps are manual-capture enable confirmation, static payment-method metadata drift risk, first-error focus after failed save, WooPayments disable confirmation, deposit interval anchor defaults, and JP statement helper/count parity. Carver recommends a future bounded slice for manual-capture safety plus save-error focus. This is also recorded as a post-A4af A4 candidate, not part of the current WooPay disable-feedback contract.

Anscombe the 5th completed a read-only WooPay/express pass and confirmed A4af's direct target: native lacks the WooPay disable feedback modal and last-disable-date throttling that the reference implements through the save footer, modal, and gateway setting side effect. The same pass also records future express residuals: simplified overview row copy/legal links for WooPay, Apple/Google Pay, Link, and Amazon Pay; Amazon availability/actionability on the express overview row using existing native `payment_method_statuses`; missing Apple/Google synthetic duplicate detection; static general express preview instead of a live Elements preview; and per-method copy/link refinements. Anscombe recommends a future bounded frontend express-overview parity slice, leaving duplicate detection and live preview for deeper backend/config work.

## Implementation Checkpoint

Backend RED/GREEN is complete. `WooPaymentsSettingsServiceTest` first failed on the missing `woopay_last_disable_date` response field and missing `platform_checkout_last_disable_date` side effect. The implementation now exposes `woopay_last_disable_date`, records `platform_checkout_last_disable_date` only when WooPay transitions from enabled to disabled, and preserves an existing date when WooPay was already disabled. Focused PHPUnit passed with 22 tests and 226 assertions. Frontend RED tests and implementation are next.

## Final A4af Result

A4af is implemented and locally verified. The final backend contract returns `woopay_last_disable_date`, records `platform_checkout_last_disable_date` only when `is_woopay_enabled` disables a previously enabled WooPay setting, preserves prior disable dates when WooPay was already disabled, and does not write a disable date when WooPay is omitted from the update. The settings data store now merges the full POST response over submitted settings so server-derived fields, including `woopay_last_disable_date`, are current after a same-session remount.

The frontend now renders a scoped Core-owned `WooPayDisableFeedback` modal after a successful save changes WooPay from enabled to disabled and the seven-day throttle permits it. Failed saves do not open the modal, and recent `woopay_last_disable_date` values suppress it. The modal uses the existing WooPay logo SVG, `@wordpress/components` `Modal` and `Spinner`, a polite loading status, and the reference survey iframe URL.

Review residuals were addressed where source-backed and cheap: the store-staleness risk was fixed by merging response data, the omitted-parameter backend case now has focused coverage, and the midnight-boundary date assertion is hardened. The a11y review approved with only low residual risk around relying on WordPress Modal for Escape/focus-return behavior and conditional polite-status mounting.

Verification passed before commit: focused PHP `WooPaymentsSettingsServiceTest` passed with 23 tests and 231 assertions; focused admin Jest for `settings-data.test.ts` and `settings-page.test.tsx` passed with 54 tests; targeted ESLint, admin type lint, admin style lint, PHP syntax, changed-file PHPCS, production PHPStan, admin bundle build, changelog validation, `git diff --check -- . ':!.agents'`, and branch `lint:changes:branch` passed. Branch JS lint still emits only the known broad ignored-file warnings, and Composer/changelog tooling still emits existing PHP 8.4 vendor deprecation noise.

Playwriter browser proof on the target store loaded `http://store8889.localhost:8889/wp-admin/admin.php?page=wc-settings&tab=checkout&path=%2Fwoopayments%2Fsettings`, toggled WooPay off from an enabled backup state, saved, and observed the `WooPay feedback` modal with iframe `https://woocommerce.survey.fm/woopay-disabled-merchants-feedback-triggered`. Evidence is in `data/a4af-woopay-disable-feedback-browser.json` and `data/a4af-woopay-disable-feedback-modal.png`. The target option was restored afterward to `platform_checkout=yes` and `platform_checkout_last_disable_date=null`. Target `debug.log` stayed empty; the only target WC log entry after proof was a background WooCommerce Subscriptions dedicated-queue debug line.

A4af does not complete N12. Remaining source-backed A4 residual candidates are native money-detail refund/capture/fraud-review action parity, manual-capture enable confirmation plus failed-save first-field focus, and express overview copy/legal/Amazon availability/duplicate-detection/live-preview gaps. Native admin readiness remains fail-closed.

A4af is committed locally as `7faed3e4fe` (`fix(payments): show woopay disable feedback`) plus changelog `6870a9caec` (`chore(payments): add woopay feedback changelog`). Git range: `0ca1d25347...6870a9caec`. No push was attempted.
