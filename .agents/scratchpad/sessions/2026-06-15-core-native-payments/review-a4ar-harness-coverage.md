---
session: 2026-06-15-core-native-payments
type: review
by: codex
created: 2026-06-20 17:30
target: A4ar A4aq incomplete coverage harness
last_updated: 2026-06-20 17:46
reconciles:
  - analysis-a4ar-a4aq-incomplete-coverage.md
  - plans/2026-06-20-core-native-payments-a4ar-a4aq-incomplete-coverage.md
  - review-agent-findings.md
status: final
---

# A4ar Harness Coverage Review

> **Prompt:** "Remember to constantly record your progress so it survives compactions and you don't redo your steps after a compaction."

## Initial Review Findings

The first A4ar review pass after `data/a4ar-full-3` returned `REQUEST_CHANGES`. Reliability review found that optional account restore could pass without proving the exact snapshot was restored, WP eval-file cleanup had no timeout, and severe reference diagnostics could be downgraded into a passing limitation. Code review found that optional-admin unavailable-route closure could blanket-clear unexpected protected routes, refund/capture perf fixture validation did not require real provider IDs, and restore evidence only checked exit code.

## Fix Disposition

The ignored local harness now has regression coverage in `tools/woopayments-merge/test-a4ar-harness-regressions.py` for the review-fixed risks: strict optional-admin unavailable set matching, required optional available-route evidence, exact restore flag comparison, real refund/capture provider IDs, reference severe-log failure, and WP eval-file timeout handling.

`a4-account-scenario.php` now rereads the account option after restore, compares the restored option to the snapshot, fails on mismatch, and emits `restored_snapshot_exact: true` only for exact restore success.

`a4aq-accumulated-gate.py` now bounds WP eval-file calls, requires exact optional-admin unavailable-route matching against Documents/Card Readers/Capital desktop/mobile, requires optional available-route proof for every expected optional check, validates restored owned flags against the pre-apply snapshot, requires refund and capture perf fixtures to carry real `pi_` and `ch_` provider IDs with valid order state, and treats severe reference diagnostics as failures while leaving non-severe reference deprecations as limitations.

## Evidence

Regression and syntax checks passed before the final aggregate: `python3 tools/woopayments-merge/test-a4ar-harness-regressions.py`, `python3 -m py_compile` for the Python harness files, `php -l` for the two PHP drivers, `node --check` for both Playwriter browser gates, and `bash -n` for `perf-surface-gate.sh`.

Focused review-fix evidence passed. `data/a4ar-reviewfix-perf-smoke-1/a4aq-accumulated-gate.json` passed with stricter fixture validation. `data/a4ar-reviewfix-browser-smoke-2/a4aq-accumulated-gate.json` passed with no failures and no incomplete checks, including exact account restore proof.

The full post-review aggregate `data/a4ar-reviewfix-full-1/a4aq-accumulated-gate.json` passed with no failures and no incomplete checks. It covered admin source, Playwriter state, base admin browser, target-only optional-admin browser, checkout browser, bundle reference/target/compare, perf fixtures/reference/target/compare, and log scan. Browser evidence was complete: 57 base admin results, 7 optional-admin results, and 24 checkout results, all with zero failures. Final perf fixtures were reference process/refund/capture orders `589`/`590`/`591` and target process/refund/capture orders `288`/`289`/`290`; refund and capture preflight evidence includes real `pi_` and `ch_` identifiers on both stores. Target logs were clean. Reference logs had five non-severe existing WooPayments plugin `credit_card_form` deprecations and no severe diagnostics.

## Current Review State

Reliability re-review approved with no critical/high/medium findings. Code re-review found one high blocker: `a4-account-scenario.php` snapshotted only the option value and existence, then restored with `update_option( ..., false )`. Because optional admin runs before perf, the harness could rewrite the target account option's autoload flag to `false` before the perf probe records `wcpay_account_data` autoload state, masking an autoload regression.

The autoload blocker is now fixed. `test_account_restore_preserves_autoload_state` first failed against the old behavior, then passed after `a4-account-scenario.php` started snapshotting the option `autoload` column, restoring it exactly via `$wpdb->update()`, clearing `alloptions`, and comparing autoload in the exact restore assertion. The regression script now passes 8/8. Syntax checks also passed for the changed Python/PHP/JS/shell harness files.

Final post-autoload-fix evidence is `data/a4ar-autoloadfix-full-1/a4aq-accumulated-gate.json`: all 13 checks passed with no failures and no incomplete checks. Target/reference `wcpay_account_data` autoload values were both `off` before the full rerun, target remained `off` immediately after optional-admin restore and before perf, and both stores remained `off` after the run. Final perf fixtures were reference `592`/`593`/`594` and target `291`/`292`/`293` for process/refund/capture, with real `pi_` and `ch_` IDs on refund/capture. Target logs were clean; reference logs had five existing non-severe `credit_card_form` deprecations and no severe diagnostics.

Focused code re-review approved the autoload fix with no critical/high/medium findings. Final A4ar review disposition is approved.
