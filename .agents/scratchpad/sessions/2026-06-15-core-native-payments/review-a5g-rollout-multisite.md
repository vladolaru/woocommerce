---
session: 2026-06-15-core-native-payments
type: review
by: codex
created: 2026-06-20 19:13
target: A5g rollout defaults and multisite runtime gate
reconciles:
  - plans/2026-06-20-core-native-payments-a5g-rollout-multisite-gate.md
status: final
---

# A5g Review Summary

Reliability reviewer `Raman the 6th` approved A5g with no critical, high, or medium findings. The review confirmed the native runtime and mandatory cutover defaults remain explicit false values, existing cutover preflight checks remain fail-closed across runtime, transport, platform connection, admin surface, event, queue, remediation, and legacy subscription guards, and `WC_ALLOW_MERGED_FEATURE_PLUGINS` still bypasses activation blocking and mandatory auto-deactivation for developer scenarios. Residual risk was correctly scoped: the multisite probe forces `woocommerce_native_payments_enabled` for the request, so it proves multisite runtime ownership and dual-runtime prevention, not default-off rollout state or WPCOM/account readiness.

Code reviewer `Mill the 6th` approved with no critical, high, or medium findings. Two residual risks were source-backed and fixed before closeout: the PHPUnit assertions now compare observed filter defaults to the explicit constants, and the multisite harness now writes/prints final pass only after cleanup succeeds. The regenerated rollup at `data/a5g-multisite-runtime/a5g-multisite-runtime-gate.json` has `status: pass`, `pass: true`, 28 passing phases, and no failures after cleanup.

Final review disposition: approved after the residual hardening fixes. No WPCOM sandbox access, WPCOM code changes, push, trunk work, or scratchpad linting occurred.
