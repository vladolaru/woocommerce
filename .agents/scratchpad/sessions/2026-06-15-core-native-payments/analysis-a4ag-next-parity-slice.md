---
session: 2026-06-15-core-native-payments
type: analysis
by: codex
created: 2026-06-20 04:34
last_updated: 2026-06-20 05:15
reconciles:
  - analysis-a4af-settings-woopay-residual-parity.md
  - spec-conformance-baseline.md
  - staging-log.md
  - supervisor-prompt-2026-06-18-2344-N12.md
status: draft
---

# A4ag Next Parity Slice Selection

> **Prompt:** "Continue working toward the active thread goal."

## Starting State

A4af is committed as `7faed3e4fe` plus changelog `6870a9caec`, and the product tree is clean. Native admin readiness remains fail-closed under N12. The current source-backed residual clusters recorded after A4af are native money-detail refund/capture/fraud-review action parity, manual-capture enable confirmation plus failed-save focus in settings, and express overview copy/legal/Amazon availability/duplicate-detection/live-preview gaps.

## Selection Criteria

The next A4 slice should move N12 materially closer to a full merchant-facing parity gate, not just produce an easy passing check. It should be broad enough to avoid low-throughput fragmentation, but still bounded enough to close with TDD, browser/log proof, and source-backed review. Money-moving UI actions need stricter financial/runtime verification than copy/settings gaps, so the slice must include fail-closed backend behavior and avoid mutating target money state in browser proof unless the path is deterministic and restorable.

## Active Research

Parallel read-only explorers are checking the three residual clusters against current native and reference sources. Their findings will be source-verified here before the A4ag plan is written.

## Explorer Findings

Banach checked money-detail parity. The source-backed result is that native detail lacks refund actions, detail-level capture/cancel actions, and fraud-review approve/block actions. The capture/cancel and fraud-review pieces can reuse the existing guarded native authorizations endpoints, while refunds still need a new native REST contract around order ownership, remaining refundable amount, and idempotency. Banach recommended not bundling refunds with a capture/cancel slice.

McClintock checked the settings residuals. The source-backed result is that native manual capture enables immediately, while the reference opens a confirmation modal before enabling and disables immediately without a modal. Native failed-save behavior shows notices but does not scroll/focus the first server-error field. Native also emits both the generic save error and the raw `server_error` notice even when field-level `details` exist.

Franklin checked express overview parity. The source-backed result is that native express overview descriptions are plain strings, so the reference legal links cannot render. Native Amazon Pay only checks availability by method id, while the reference gates actionability from payment-method status. Native duplicate detection lacks the reference synthetic `apple_pay_google_pay` cluster. The live Stripe Elements preview gap is real, but depends on Stripe JS and browser wallet behavior, so it should stay separate from the overview parity slice.

## Source Verification

Native `ExpressCheckoutOverviewRow.description` is typed as `string` and rendered inside a plain paragraph in `plugins/woocommerce/client/admin/client/woopayments/settings/settings-page.tsx`, which confirms the legal-link rendering gap. The same section checks Amazon Pay with `available_payment_method_ids.includes( 'amazon_pay' )` and sets the row `disabled` state to `false`, so status-driven actionability is absent there.

Native manual capture uses a direct `CheckboxControl` `onChange` that calls `setIsManualCaptureEnabled( Boolean( value ) )`. The reference `manual-capture-control.tsx` opens a modal only when enabling, and confirms before setting manual capture to true. This is a direct behavioral gap.

Native save handling awaits `saveSettings()` and only sets `Settings saved.` or `Error saving settings.` status text. The reference has `scrollToFirstFieldError`, mapping detail keys to `<setting-key-with-hyphens>-input`, scrolling the first field into view, respecting reduced motion, and focusing with `preventScroll`. Native field IDs are incomplete for that convention.

Native duplicate detection maps normal payment-method ids and keywords, but not the reference `apple_pay_google_pay` synthetic cluster. The reference `Duplicates_Detection_Service::search_for_payment_request_buttons()` adds `apple_pay_google_pay` when another enabled gateway exposes Apple Pay / Google Pay / payment-request behavior, and the reference Apple/Google overview item consumes that key.

## A4ag Decision

A4ag will be a settings merchant-parity bundle:

- Add manual-capture enable confirmation while preserving immediate disable behavior.
- Add first server-error scroll/focus after a failed save, with deterministic input ids for statement/support fields.
- Suppress the duplicate raw `server_error` notice when field-level `details` are present.
- Upgrade express overview descriptions to render reference legal/read-more links for WooPay, Apple/Google Pay, Link, and Amazon Pay.
- Make Amazon Pay overview actionability/status notice follow the same status-driven behavior as the richer payment-method rows.
- Add the synthetic `apple_pay_google_pay` duplicate cluster and render the duplicate notice on the Apple/Google express row.

Deferred from A4ag:

- Refund modal parity, because it needs a new money-moving REST contract and dedicated safety proof.
- Detail-level capture/cancel and fraud-review actions, because they are a coherent money-detail slice with existing guarded endpoints.
- Live Stripe Elements preview, because it depends on Stripe JS/browser wallet behavior and needs its own test strategy.

## Closeout Result

A4ag implemented the selected settings bundle in one product slice. Native settings now opens a confirmation modal before enabling manual capture, keeps disabling immediate, focuses the first known field-level server error after a failed save, suppresses duplicate raw `server_error` notices when field details are present, renders the reference express legal/read-more links, uses status-driven Amazon Pay overview notices, and supports the synthetic Apple Pay / Google Pay duplicate cluster through a provider-level declaration seam plus payment-request heuristics.

The duplicate detection architecture was corrected during review: the broad generic `express_checkout_enabled` fallback was removed, and third-party integrations now have an explicit `woocommerce_native_woopayments_gateway_duplicate_payment_method_ids` filter to declare native duplicate method ids such as `card` or `apple_pay_google_pay`. This keeps the provider-specific payment-method path reusable for future WooPayments-provided methods without coupling the generic settings domain to concrete extension internals.

Deferred residuals remain unchanged: refund modal parity, detail-level capture/cancel and fraud-review action parity, live Stripe Elements preview, and the final accumulated A4/N12 parity gate remain open. Native admin readiness remains fail-closed.
