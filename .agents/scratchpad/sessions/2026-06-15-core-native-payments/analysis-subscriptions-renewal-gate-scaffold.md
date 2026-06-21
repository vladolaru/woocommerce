---
session: 2026-06-15-core-native-payments
type: analysis
by: codex
created: 2026-06-17 16:24
target: tools/woopayments-merge/subscriptions-renewal-gate.sh
reconciles:
  - review-agent-findings.md
  - plans/2026-06-17-core-native-payments-n7-verification-coverage.md
status: final
last_updated: 2026-06-17 16:31
---

# Subscriptions Renewal Gate Scaffold

> **Prompt:** "Implement a first-pass Bucket-C WC Subscriptions conformance gate scaffold in disjoint new files under tools/woopayments-merge only."

## Source Findings Applied

Bohr's persisted finding in `review-agent-findings.md` establishes the first-pass shape: native and reference WooPayments both need Subscriptions support and the scheduled renewal/failing payment-method hooks; the renewal driver should call `WC_Subscriptions_Manager::process_renewal()`, then unschedule pending renewal only after an order exists, then call `WC_Subscriptions_Payment_Gateways::gateway_scheduled_subscription_payment()`, then read `$subscription->get_last_order( 'all', 'renewal' )`.

The scaffold must not generate subscriptions through CLI because that would skip browser tokenization and can suppress email behavior. It should require explicit browser-created subscription IDs for reference and target, fail non-zero when absent, block real email transport with `woocommerce_mail_callback`, and capture comparable email evidence through `woocommerce_mail_callback_params`.

## Implementation Intent

Create a Bash gate wrapper with `preflight` and `compare` modes, plus a PHP `eval-file` driver. Preflight will verify WC Subscriptions, WooPayments plugin/native state, gateway support flags, and hook registration on both supplied WP commands. Compare mode will run the PHP driver against explicit subscription IDs on both stores, normalize volatile facts, and diff reference versus target JSON.

## Implementation Evidence

Created `tools/woopayments-merge/subscriptions-renewal-gate.sh` and `tools/woopayments-merge/subscriptions-renewal-drive.php` only. Updated `tools/woopayments-merge/HARNESS.md` with a short Subscriptions renewal scaffold section and corrected the target state from separate WooPayments plugin active to inactive/native-owned.

Verification run:

- Initial RED behavior check: `subscriptions-renewal-gate.sh compare --ref 'wp' --target 'wp'` did not produce the required browser-created subscription ID message before the script existed.
- GREEN behavior check: `subscriptions-renewal-gate.sh compare --ref 'wp' --target 'wp'` exits non-zero with `browser-created subscription IDs are required`.
- `bash -n tools/woopayments-merge/subscriptions-renewal-gate.sh` passed.
- `php -l tools/woopayments-merge/subscriptions-renewal-drive.php` passed.
- `php tools/woopayments-merge/subscriptions-renewal-drive.php normalize /nonexistent` fails closed with `Cannot read JSON file for normalization.`
- `markdownlint --fix tools/woopayments-merge/HARNESS.md && markdownlint tools/woopayments-merge/HARNESS.md` passed after escaping pre-existing table pipes in touched rows.
- Local preflight passed with `--ref 'docker exec -i wcpay_wp_default wp --allow-root'` and `--target 'docker exec -i 24860d14de30dc62f7b324ebef10b5fb-cli-1 wp --allow-root --user=1'`.
