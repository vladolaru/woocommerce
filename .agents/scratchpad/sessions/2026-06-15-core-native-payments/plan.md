---
session: 2026-06-15-core-native-payments
type: plan
by: codex
created: 2026-06-15 19:09
status: draft
---

# A1 Plan

> **Prompt:** "Produce a just-in-time task plan for A1, then implement A1->A6 in order."

The durable A1 implementation plan is:

`docs/superpowers/plans/2026-06-15-core-native-payments-a1.md`

Decision captured there: A1 uses true same-store read-only shadow output. The
initial native-computed subject is the persisted payment surface projection from
`OrderPaymentStore`; lifecycle and processing shadow parity strengthen in A2/A3.
