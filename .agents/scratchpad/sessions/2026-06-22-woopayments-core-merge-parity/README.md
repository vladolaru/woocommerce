---
session: 2026-06-22-woopayments-core-merge-parity
type: readme
by: claude
created: 2026-06-22 22:52
tool: pirategoat-tools:full-code-review (follow-up)
target: exp/core-native-payments (WooPayments → WC core merge)
reconciles:
  - /tmp/branch-review-Users-vladolaru-Work-a8c-woocommerce-develop-2--exp-core-native-payments-/review-findings.json
---

# WooPayments core-merge parity check

> **Prompt:** "For each of these check the matching WooPayments client code paths to determine if it is a regression introduced by the WooPayments into WC core work on this branch or is the same issue present in the current WooPayments extension too. I want the core merge to be regressions free and preferably do better than the client. But we need to maintain backward compatibility. Use subagents."

## Context

A chunked multi-agent code review of `exp/core-native-payments` (the native WooPayments + Multi-Currency merge into WooCommerce core) produced **45 verified findings**. That review ran under a **degraded host** — the upstream WooPayments plugin source was not available — so parity-vs-extension judgements were deferred.

This session resolves that gap. The WooPayments **client/extension** is checked out locally at `~/Work/a8c/woocommerce-payments` (**v10.8.0**, branch `develop`) and is used as the oracle. For each finding we locate the matching client code path and classify it:

- **REGRESSION** — the bug is new in core; the client handles it correctly (or lacks the vulnerable construct). Core is *worse* than the client. → Must fix for a regression-free merge.
- **PARITY** — the same issue exists in the client too (faithfully ported). Not a regression, but core is not better either. → Fix opportunistically; "do better than the client".
- **IMPROVED** — core already does better than the client (client has the bug, core fixed it). → Confirm; no action.
- **NEW-IN-CORE** — net-new functionality with no client equivalent (e.g. the cutover/arbiter/order-payment-lock machinery). Judge on its own merits; no parity baseline.
- **BC-RISK** — touches a public surface (hook, filter, class, constant, REST shape) the client or wider ecosystem may depend on. → Backward-compatibility concern.

## Batches (subagent → data file)

| Batch | Subsystem | Findings | Artifact |
|-------|-----------|----------|----------|
| A | Webhooks & event processing | 53de2d4b, 339eeb0c, 0b11bf8a | `data/parity-A-webhooks.md` |
| B | Payment core: charge/refund/checkout/setupintent | 7c43c8da, bfcb6ce5, 47644832 | `data/parity-B-payment-core.md` |
| C | WooPay session | c17bc8f3, 5076c7dc | `data/parity-C-woopay-session.md` |
| D | Blocks checkout JS XSS | 195a39ae | `data/parity-D-blocks-xss.md` |
| E | API client (request building) | 0175d50d, 6022b9aa, bd8596d0, 54b15aba, d3111113, 147606e9 | `data/parity-E-api-client.md` |
| F1 | REST: account session + capital | 6ea1b4a8, 3eece18f, 24a6f21d, 4be7ee7c | `data/parity-F1-rest-account-capital.md` |
| F2 | REST: mobile + file + timezone | 1a302332, 8965abd1, 048c849b, 8ad0a91a | `data/parity-F2-rest-mobile-file.md` |
| G | Settings + customer service | 6a3e12e5, deb2d8c9, a9e93194, 61a68295, 7522b93d | `data/parity-G-settings-customer.md` |
| H | Multi-currency core perf/SQL | 1062b791, f42b6e68, b6ab58da | `data/parity-H-mc-core.md` |
| I | Multi-currency compatibility perf | f35bc174, e0e84777 | `data/parity-I-mc-compat.md` |
| J | BC: removed public surfaces | c49baad5, 9d771cef | `data/parity-J-bc.md` |
| K | React admin a11y | 90565c18, 106f2823, 99309eb5, bccc465d, 901cb561 | `data/parity-K-a11y.md` |
| L | Test quality | 23405cc0, 11adbec5, 3593fad3, 5bd7b33b, 7c7309ac | `data/parity-L-tests.md` |

## Outputs

- `parity.md` — synthesized classification of all 45 findings with the regression list, the "do better than client" list, and BC risks.
- `data/parity-*.md` — per-batch detail with client `file:line` evidence.
- `plan.md` — phased, TDD, bite-sized remediation plan addressing all 45 findings while preserving backward compatibility (reconciles `parity.md`).
