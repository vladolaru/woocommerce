---
session: 2026-06-15-core-native-payments
type: readme
by: codex
created: 2026-06-18 22:09
last_updated: 2026-06-18 22:40
reconciles:
  - analysis-a5a-instant-deposit-reference.md
status: final
---

# WCPay Instant Deposit Reminder Cutover

> **Prompt:** "Read-only source investigation. Context: repo /Users/vladolaru/Work/a8c/woocommerce-develop-2 is the native WooCommerce Core worktree; reference plugin clone is /Users/vladolaru/Work/a8c/woocommerce-payments. Do not edit files. Do not access WPCOM sandbox or make network calls.
>
> Question: For the legacy WooPayments Action Scheduler hook `wcpay_instant_deposit_reminder`, what exact behavior does the reference plugin preserve, and what is the smallest native-core behavior that would be parity-safe for cutover? Please source-verify from reference plugin files/tests and native files if useful.
>
> Return a concise report with:
> - reference registration/scheduling/callback files and lines,
> - callback behavior and dependencies/classes it calls,
> - existing native code equivalents or missing pieces,
> - tests in reference that define behavior,
> - recommendation for native implementation scope and any risks.
>
> Do not summarize from memory; ground findings in file paths/line numbers."

## What this session did

Source investigation for the legacy WooPayments Action Scheduler hook `wcpay_instant_deposit_reminder`, comparing the WooPayments plugin reference checkout with native WooCommerce Core cutover code. Findings are in `analysis-a5a-instant-deposit-reference.md`.

## Status

- `analysis-a5a-instant-deposit-reference.md`: final
