---
session: 2026-06-15-core-native-payments
type: analysis
by: codex
created: 2026-06-19 18:54
target: A4y Task 1 dispute evidence helper/model parity
reconciles:
  - analysis-a4y-dispute-challenge-parity.md
  - plans/2026-06-19-core-native-payments-a4y-dispute-challenge-parity.md
status: draft
last_updated: 2026-06-19 18:54
---

# A4y Task 1 Helper Parity Notes

> **Prompt:** "Work in /Users/vladolaru/Work/a8c/woocommerce-develop-2 on branch exp/core-native-payments. You are not alone in the codebase: do not revert or overwrite unrelated edits, and adapt to existing changes. Do not touch WPCOM, remote sandboxes, the standalone WooPayments reference repo, or trunk. Do not push. Do not commit. Task: Implement A4y Task 1 helper/model parity for the native WooPayments dispute challenge wizard."

## Initial Scope

Task 1 is intentionally helper/model only. Product code writes stay scoped to `dispute-evidence-fields.ts`, the new `dispute-evidence-cover-letter.ts`, and the new focused Jest test. `dispute-evidence-form.tsx` and other UI files are out of scope.

## Working Notes

- Branch verified as `exp/core-native-payments`.
- User constraints prohibit WPCOM, remote sandbox, standalone WooPayments reference repo, trunk, push, and commit.
- The A4y plan requires product-type options, recommended document helper parity for six reference-covered reasons, `needsShipping()`, preservation of `buildEvidencePayload()`, deterministic cover-letter generation, and focused Jest coverage.
- RED confirmed with `pnpm --filter=@woocommerce/admin-library test:js --runTestsByPath client/woopayments/admin/test/dispute-evidence-fields.test.ts --runInBand`: Jest failed because `../money-movement/dispute-evidence-cover-letter` does not exist yet.
