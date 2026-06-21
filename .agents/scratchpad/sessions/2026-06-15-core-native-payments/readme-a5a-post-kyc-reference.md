---
session: 2026-06-15-core-native-payments
type: readme
by: codex
created: 2026-06-18 22:09
last_updated: 2026-06-18 22:40
reconciles:
  - analysis-a5a-post-kyc-reference.md
status: final
---

# WCPay Post KYC Email Hook

> **Prompt:** "Read-only source investigation. Context: repo /Users/vladolaru/Work/a8c/woocommerce-develop-2 is the native WooCommerce Core worktree; reference plugin clone is /Users/vladolaru/Work/a8c/woocommerce-payments. Do not edit files. Do not access WPCOM sandbox or make network calls.
>
> Question: For the legacy WooPayments Action Scheduler hook `wcpay_post_kyc_activation_email_send`, what exact behavior does the reference plugin preserve, and what is the smallest native-core behavior that would be parity-safe for cutover? Please source-verify from reference plugin files/tests and native files if useful.
>
> Return a concise report with:
> - reference registration/scheduling/callback files and lines,
> - callback behavior and dependencies/classes/templates it calls,
> - option/meta/transient/Tracks side effects,
> - existing native code equivalents or missing pieces,
> - tests in reference that define behavior,
> - recommendation for native implementation scope and any risks.
>
> Do not summarize from memory; ground findings in file paths/line numbers."

## What this session did

Verified the legacy WooPayments post-KYC activation email Action Scheduler hook in the reference plugin and compared it with the current native WooCommerce Core WooPayments cutover/operational queue code. Findings are captured in `analysis-a5a-post-kyc-reference.md`.

## Status

- `analysis-a5a-post-kyc-reference.md`: final
