---
session: 2026-06-15-core-native-payments
type: analysis
by: codex
created: 2026-06-18 15:51
tool: subagent-driven-development
target: A4e native WooPayments settings page
reconciles:
  - supervisor-prompt-2026-06-18-1306-N11-held.md
  - analysis-a4-native-woopayments-admin.md
  - analysis-a4a-frontend-worker.md
  - analysis-a4d-money-movement.md
status: draft
last_updated: 2026-06-18 18:22
---

# A4e Settings Page Decision Analysis

## Trigger

The A4 dashboard slices now cover the native overview, payouts, transactions, and disputes surfaces. The remaining A4 merchant-admin gap is the full WooPayments settings page. N11 explicitly held a decision-point advisory for this moment: evaluate bulk-hoist-as-a-unit versus a from-scratch section-by-section rebuild, with minimal UI drift and one Core admin runtime as the success criteria.

## Source-Backed Findings

Two read-only subagents mapped the reference and native seams before any implementation work. The reference-side pass found that the WooPayments settings module is large enough that piecemeal rebuild is high-risk: 116 runtime JS/TS/SCSS files after excluding tests/snapshots, about 12.9k runtime LOC across `client/settings` plus `client/data/settings`. The biggest fidelity-sensitive areas are express checkout settings, fraud protection, payment methods, and advanced settings.

The reference settings page is built around the `wc/payments/settings` `@wordpress/data` store, registered by `woocommerce-payments/client/data/settings/store.js`. Components are hook-bound to that store (`useSettings`, enabled payment method selectors, manual capture hooks, etc.), so hoisting only the presentational components would break the load-bearing contract. The REST contract used by the store is compact and explicit: `GET /wc/v3/payments/settings`, `POST /wc/v3/payments/settings`, `POST /wc/v3/payments/settings/{option}`, and `POST /wc/v3/payments/file` for WooPay logo upload. The Stripe Billing migration endpoint and selectors are Bucket D and must stay excluded.

The reference entry (`client/settings/index.js`) mounts the settings manager into plugin-provided containers and reads `window.wcpaySettings` for bootstrap flags, account status, WooPay appearance/font rules, fraud flags, store currency, multi-currency toggle state, subscription availability, duplicate notice state, and related localized values. Some code mutates this global for WooPay disable feedback and fraud-tour dismissal. A Core port needs a native localized payload or bootstrap adapter, not a plugin runtime global dependency.

The native-side pass found that Core currently has only an A4a settings shell: `settings-payments-woopayments.tsx` renders `WooPaymentsAccountSettings`, backed by a read-only `/wc-admin/settings/payments/woopayments/account` summary endpoint. There is no native full settings API equivalent to `/wc/v3/payments/settings`. The provider-route seam is available and already used by A4 dashboard routes, but `/woopayments/settings` is not registered yet. The current generic provider Manage URL still points at `section=woocommerce_payments`; the route needs to funnel cleanly through the existing Payments settings orchestration without mixing `section=` and `path=` in one URL.

## Decision

A4e should hoist the reference settings module as a unit, not rebuild it section by section. The unit is the settings data store plus settings components plus settings SCSS, mounted on the Core admin `@wordpress/data` registry and Core payments settings route system. This is the lowest-drift path because the page is already composed from WordPress/WooCommerce admin components and its behavior is centered on a small store/REST contract rather than bespoke plugin infrastructure.

This is not a blind copy. The port must cut plugin-runtime edges during the hoist: replace `window.wcpaySettings` with Core-owned bootstrap data, remove Stripe Billing settings/migration surfaces, keep multi-currency as a toggle only, avoid importing the plugin data runtime, remove or adapt promotion/VAT/fraud-script side-effect dependencies, and use Core-native adjacent stores/endpoints for deposits, payment methods, WooPay preview, and assets where the full settings page needs them. Styling should be brought through the normal WooCommerce build workflow as a dedicated native settings bundle so fidelity is preserved without loading WooPayments-specific CSS outside the enabled native-provider settings surface.

## A4e Implementation Boundary

The first broad A4e slice should include the full settings-page foundation, not a narrow shell. The practical boundary is: native settings REST contract, native settings bootstrap data, a Core-registered settings store, a routed provider settings mount under Payments settings, and a hoisted initial settings manager with the Bucket-D Stripe Billing surface excluded. This is large enough to avoid low-throughput field-by-field work while still leaving independent write scopes for backend API, frontend store/build wiring, and component edge adaptation.

Provider-specific settings routes should sit as sub-routes of WooCommerce Settings > Payments. The WooPayments provider Manage button should land on the native settings route through existing provider metadata, while legacy `section=woocommerce_payments` can remain as a compatibility landing shell that redirects or renders the same native route without combining route styles.

## Risks To Gate

The largest correctness risk is backend settings parity: the native endpoint must preserve the reference settings schema, validation, defaults, and save side effects without reintroducing Stripe Billing or deleting merchant data. The largest frontend risk is accidental parallel runtime import from the plugin alias graph. The largest UX risk is partial styling or missing settings subsections after the hoist. The A4e gate must include browser comparison against the reference settings page, REST GET/POST parity probes, build/bundle accounting for the new native settings chunk, and a source-backed check that the hoisted store is registered once on the Core admin registry.

## Component Hoist Notes

The `SettingsManager` itself confirms that the hoist is not only a route shell: it composes General, Payment methods, BNPL, Express checkouts, Transactions, Payouts, Notifications, Fraud protection, Advanced settings, Save footer, VAT modal, and Spotlight promotion. The first implementation boundary should cut Stripe Billing outright, keep the multi-currency toggle only, and treat VAT modal plus Spotlight promotion as optional plugin-runtime reach-ins that need native adapters or no-op removal after source verification. The user-facing page must not silently drop the core sections, but optional promotional/collection modals should not block the native settings contract if they are not required for baseline merchant settings parity.

The reference `advanced-settings/index.js` chooses `StripeBillingSection` when Subscriptions is active and Stripe Billing is eligible; native should collapse this to the WC Subscriptions toggle/debug/multi-currency path because N9 retired Stripe Billing. The reference settings page also mutates `wcpaySettings.dismissedDuplicateNotices`, `wcpaySettings.fraudProtection.isWelcomeTourDismissed`, and `wcpaySettings.woopayLastDisableDate`; native code should route those through the settings store/options endpoint or through a narrow bootstrap compatibility shim contained inside the lazy settings chunk.

Implementation workers spawned for this slice: `019edad0-8b3c-7823-aaa1-0a1865881cb1` owns the backend settings service/controller/tests; `019edad0-c577-7cb3-89c4-b2454034d886` owns the frontend data store/bootstrap/route tests. The full settings-manager component hoist is intentionally sequenced after those two foundations land to avoid write conflicts.

## Frontend Settings Manager Implementation Notes

The native Core subtree already has the `wc/payments/settings` store and route wiring from the frontend foundation worker. The current replacement work should stay inside `plugins/woocommerce/client/admin/client/woopayments/settings/**` and avoid the backend/API files now modified in the worktree.

The first focused Jest test for `WooPaymentsSettingsPage` was added before production changes. The red run used `pnpm --dir plugins/woocommerce/client/admin test:js -- settings/test/settings-page.test.tsx --runInBand` and failed because the placeholder page did not render the expected `General` settings-manager section. The Stripe Billing absence assertion already passed against the placeholder, but it remains useful as a regression guard after the full surface is rendered.

The implementation boundary is a Core-owned adaptation rather than plugin-alias imports: compose the major settings sections against native hooks from `./data/hooks`, upload WooPay logo files directly to `/wc/v3/payments/file`, and keep plugin-only VAT/promotion/runtime edges out of the native page. Stripe Billing migration controls remain excluded; subscriptions stay as the existing WooPayments subscriptions toggle inside Advanced settings.

## A4e Review Findings

The accessibility/UX subagent `019edaf1-5ec2-7b72-8c05-ced9d170c6a2` reviewed the native settings page and returned seven source-backed issues. High-impact findings: the save live-region can keep saying “Settings saved.” after subsequent edits; the test-mode checkbox currently enables test mode without the reference confirmation step; payout schedule controls remain editable even when scheduling is unavailable; and the fraud protection section exposes raw advanced-rule JSON with silent invalid-JSON handling. Medium findings: repeated express-checkout location checkboxes need programmatic group context; payment method status badges are visual-only; WooPay logo upload needs clearer success/file feedback. These are accepted as in-scope A4e quality fixes before browser verification.

During local contract review, native availability was corrected to match the reference split: standard payment methods exclude Apple Pay, Google Pay, Amazon Pay, Link, and WooPay; Link and Amazon Pay are controlled from express checkout; WooPay remains a platform checkout setting rather than a UPE payment method. Tests now mock status data by Stripe capability key (`card_payments`, `link_payments`, `affirm_payments`, `amazon_pay_payments`) so method-ID/status-key drift is caught.

The ecosystem parity subagent `019edaf1-3384-7832-af59-78cdcdca7cca` reviewed the backend/frontend contract against the reference plugin and found ten additional A4e blockers/gaps. Accepted blockers: settings save should tolerate the data-control response shape; native save must trigger the store-setup sync handoff; available/enabled methods cannot be hardcoded to only card/link/Amazon/BNPL because regional methods must be preserved; manual capture must filter unsupported BNPL methods; preset fraud protection levels must save canonical preset rulesets instead of stale advanced rules; payout anchor updates must include the interval; express location sanitization must preserve Link; JP accounts must not offer daily payouts; WooPay logo upload must use `business_logo`; express checkout appearance enums/defaults must preserve `book`, `small`, `medium`, `large`, and `light-outline`.

The restored cross-store harness exposed a Bucket-E parity regression after the first browser/build pass: reference order 544 and target order 243 matched on status, notes, charge, fee, net, and most provider meta, but target missed `_wcpay_fraud_meta_box_type=["allow"]` and `_wcpay_fraud_outcome_status=["allow"]` for a successful card charge. Source tracing confirmed reference writes those keys from the order-service fraud-outcome branch and native only reads `intent.metadata.fraud_outcome`, so the native successful-card fallback is narrower than the reference runtime response path.

The performance review subagent `019edb02-9fdb-7bb2-8287-284df14d7cf6a2` found one accepted medium issue: native logo upload reads the entire temp file and base64 encodes it before server-side size validation. The frontend cap is 510,000 bytes, so the backend must enforce the same limit before `file_get_contents()` to avoid avoidable memory pressure from direct authenticated REST uploads.

The architecture review subagent `019edb02-6ad3-7150-a697-9ac1add2bc1e` found two accepted route/settings blockers. First, the native `/wc/v3/payments/settings*` and `/wc/v3/payments/file` routes currently register unconditionally; these must be gated by the native runtime arbiter so plugin-active stores do not get mixed native/plugin handlers. Second, test-mode and debug-log setting writes must preserve the WooPayments dev-mode guardrails and avoid mutating `test_mode`/`enable_logging` while `wcpay_dev_mode` is active.

The API contract review subagent `019edb02-83b9-79a0-8e9c-86fc4a489328` found three accepted contract gaps. `POST /wc/v3/payments/settings` needs REST args validation instead of silent service filtering for unsupported payment-method IDs and malformed settings; account/deposit response fields need to be sourced from cached account data with the reference-shaped `deposit_restrictions` string contract; and `WooPaymentsApiClient` must apply the public `wcpay_api_request_response` filter after transport so WCPay Dev Tools can handle local Transact Platform retry responses.

The follow-up performance review subagent `019edb19-94a8-7fd3-a20a-8df3e166662a` found two additional A4e issues under verification. First, `WooPaymentsSettingsService::request_unrequested_payment_methods()` loops over submitted method IDs and can call the remote capability API repeatedly for duplicate IDs; accepted fix is to dedupe and bound IDs in the service and expose `uniqueItems`/`maxItems` on the REST array schemas. Second, account-backed fields are now read from cached account data but still diffed against local gateway options before `update_account()`, so a full settings save with stale local options can cause unnecessary remote account writes; accepted fix is to diff account-backed fields against the same cached account projection used by `get_settings()`.

The follow-up API contract review subagent `019edb19-80b3-7671-9f4f-2c1698a84172` reported five possible contract regressions now under source verification. The file-read route claim (`GET /wc/v3/payments/file/{id}`, `/details`, `/content`) may be a real parity gap if surviving native surfaces still read uploaded logos or dispute evidence through those routes. The `deposit_schedule_monthly_anchor` nullable value and `account_business_support_address` keyed-address object validation claims look likely in-scope because they affect ordinary settings saves. The Stripe Billing schedule route and Stripe Billing response-field claims conflict with the settled N9 retirement direction and should not be accepted unless source verification shows a non-Stripe-Billing surviving client path still depends on them.

## A4e Follow-Up Disposition

The follow-up performance findings were accepted and fixed. Native settings now deduplicate and bound payment-method ID arrays at both service and REST-schema layers (`uniqueItems` and `maxItems`), so duplicate submitted IDs cannot trigger repeated remote capability requests. Account-backed settings are now diffed against the cached account projection returned by `get_settings()`, not stale local gateway options, avoiding unnecessary `update_account()` calls when the UI posts the full cached account shape.

The follow-up API schema findings were accepted and fixed. `deposit_schedule_monthly_anchor` now accepts `integer|null`, and `account_business_support_address` now accepts the keyed object shape the reference settings page sends. The generic typed-arg helper remains PHP 7.4-compatible by using a documented untyped parameter instead of a native union type.

The file-route finding was source-backed and in-scope. The reference file controller registers `POST /wc/v3/payments/file`, `GET /wc/v3/payments/file/{id}/details`, `GET /wc/v3/payments/file/{id}/content`, and public `GET /wc/v3/payments/file/{id}` for `business_logo` and `business_icon` purposes. Native settings already uploads WooPay logos through the preserved file endpoint, and native WooPay session data needs the configured `platform_checkout_store_logo` to become a REST file URL. Native now preserves the details/content/public-file routes, guards private file purposes with payment-gateway permissions, streams public logo/icon bytes inline, caches file purpose lookups for repeated logo loads, and sends `store_data.store_logo` as `get_rest_url( null, 'wc/v3/payments/file/{file_id}' )`.

The final focused API-contract review subagent `019edb31-b22f-7543-aa57-de7cb7a21b6c` requested three source-backed fixes before A4e can close. The generic `/wc/v3/payments/file` endpoint no longer enforces the WooPay logo 510 KB cap because the same route is used for dispute evidence and the reference only applies that smaller limit in the WooPay logo settings UI. The public file route now normalizes `resource_missing` to 404 and other retrieval failures to 500, matching the reference file controller's stable response contract. WooPay session `store_data.store_logo` now preserves the reference fallback to the theme custom logo and only overrides it when `platform_checkout_store_logo` is set. Follow-up API review confirmed those fixes and found one remaining source-backed upload-error contract drift; native now wraps provider-side Files API failures as `wcpay_evidence_file_upload_error` while preserving local validation errors, message, and status. Follow-up regressions cover all four contracts, and final read-only API-contract re-review approved with critical 0, high 0, medium 0.

The Stripe Billing route/field findings were rejected under N9 unless a surviving non-Stripe-Billing caller is found later. A4e keeps Stripe Billing migration UI/actions absent and does not reintroduce retired invoice-engine settings. The settled direction remains retire-with-guard, not port.

Final verification for the A4e settings slice passed locally on 2026-06-18. Focused PHP passed with 212 tests and 1142 assertions after the API-contract follow-up. Focused Jest passed with 3 suites and 16 tests. Explicit PHPCS, PHPStan, ESLint, Stylelint, admin `ts:check`, `git diff --check`, and `@woocommerce/admin-library` build passed. The restored cross-store harness passed 7/7 after the final follow-up with reference order 548 and target order 247, including widened charge/fee/net financial reconciliation. Playwriter verified the native settings sub-route, the generic Settings > Payments provider list, and the provider Manage button routing to `/woopayments/settings`; reference and target both label the provider row `Accept payments with Woo`. The target `debug.log` remained stale at `2026-06-18 10:27:24 UTC`, and Docker scans for the final post-harness window found no PHP notices, warnings, deprecations, fatals, uncaught errors, or stack traces. A4e source/tests were committed as `eb3944218d`, the changelog as `345e5a375b`, and a post-commit branch-lint cleanup for an earlier A4d short-ternary issue as `c304f2003a`. Final branch lint passed with PHP clean and only the known JS ignored-file warnings. A4e closeout range is `9d4f822988...c304f2003a`.
