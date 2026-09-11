# MA-11 — Plugin-active WooPayments settings screen · HYBRID (A + D)

The core-native bundle must not break the WooPayments extension's settings screen while the separate plugin still owns runtime behavior.

## Fixtures

- Target store has the separate WooPayments plugin active.
- Native WooPayments code is present in core, but the arbiter reports plugin ownership.
- Visit the plugin's canonical `woocommerce_payments` settings screen.

## Layer A

An agent must open `wp-admin/admin.php?page=wc-settings&tab=checkout&section=woocommerce_payments` on the plugin-active target and compare against the reference store. The page must not blank, must not crash from duplicate `wc/payments/settings` stores, and must keep the plugin settings screen usable.

## Layer D

Fail-closed checks:

- Confirm the target is plugin-active before the browser step.
- Bind fresh-request arbiter evidence showing plugin ownership while staged and the snapshot-derived owner after restoration.
- Confirm the final page contains the WooPayments settings app or classic plugin settings form.
- Confirm console output has no duplicate-store fatal path.

## Verdict

PASS. Runner-verified reference and staged-target evidence shows the authenticated standalone-plugin settings app at the canonical URL with plugin assets and a single selectable `wc/payments/settings` store. Neither store loaded native settings assets or recorded duplicate-store errors, fatal console errors, or failed responses. The target fixture was restored to native ownership with the standalone plugin inactive after capture.

Evidence: `evidence/coverage-production/MA-11/20260716T115707Z-ma11-final2/supervisor/runs/20260716T115814Z-35960-partial/`.

Any browser-proven blank settings screen, React crash, or native hijack of the `woocommerce_payments` section is FAIL. Missing, stale, role-swapped, contradictory, unbound, or incompletely restored evidence is BLOCKED.
