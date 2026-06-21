---
session: 2026-06-15-core-native-payments
type: readme
by: subagent:Dewey the 5th
created: 2026-06-20 08:23
last_updated: 2026-06-20 08:30
reconciles:
  - analysis-a4al-payment-detail-residual-parity.md
status: final
---

# A4al Payment Detail Mapping

> **Prompt:** "Read-only source mapping for A4al. You are working in /Users/vladolaru/Work/a8c/woocommerce-develop-2 on branch exp/core-native-payments. Do not edit files, do not access WPCOM sandbox, do not push. Reference WooPayments client is read-only at /Users/vladolaru/Work/a8c/woocommerce-payments. Task: map payment-detail payment-method detail variants beyond card/card_present/interac_present. Compare reference components/formatters for non-card methods and wallet/billing details against current native transaction-detail-sections implementation. Identify what can be implemented from current charge/payment_intent payload fields without backend changes and what would need backend/platform changes. Return source-backed findings with file:line references and a concise recommended bounded scope. Do not include speculative claims without source evidence."

## What this session did

Mapped WooPayments reference payment-method detail renderers against the native transaction detail implementation and classified frontend-only versus backend/platform-dependent work.

## Arc

1. Created the scratchpad session before presenting findings.
2. Read the native money-movement detail frontend and REST detail path.
3. Read the WooPayments reference payment-method components and wallet summary component.
4. Wrote source-backed findings in `analysis-a4al-payment-method-mapping-subagent.md`.

## Status

- `analysis-a4al-payment-method-mapping-subagent.md`: final
