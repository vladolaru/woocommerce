---
session: 2026-06-15-core-native-payments
type: analysis
by: codex
created: 2026-06-20 20:33
target: A4av payment-method availability guidance parity
reconciles:
  - review-a4au-settings-inventory.md
last_updated: 2026-06-20 21:01
status: final
---

# A4av Payment-Method Guidance Parity

## Trigger

A4au closed the post-A4at accumulated gate and Hegel the 6th identified payment-method availability guidance as the highest-priority remaining source-backed settings gap. Kant the 6th then verified the exact reference behavior in the WooPayments client, and Zeno the 6th verified the native insertion points. This slice restores the shared payment-method guidance contract in native WooPayments settings without broadening into unrelated settings polish.

## Source-Backed Gap

Native `plugins/woocommerce/client/admin/client/woopayments/settings/payment-methods-list.tsx` already owns the availability helper and row notices, but it currently has simplified guidance for `inactive`, `pending`, `pending_verification`, and `rejected` states. It also has no missing-currency warning because `plugins/woocommerce/client/admin/client/woopayments/settings/payment-method-definitions.ts` does not carry the `currencies` field that the reference plugin projects into `window.wooPaymentsPaymentMethodDefinitions`.

Reference behavior verified by Kant the 6th:

- `inactive`: disabled, chip `More information needed`, warning notice with `Learn more`; BNPL methods use the buy-now-pay-later contact-support docs URL while other methods use the additional-payment-methods docs URL.
- `pending`: disabled, chip `Approval pending`; Alipay and WeChat Pay use the delayed-approval copy and `#approval-delays` docs link, while other methods keep the generic pending approval copy.
- `pending_verification`: disabled, chip `Pending verification`, warning notice with a `Payments overview` link. Native should point that link at the Core Settings > Payments provider route `/woopayments/overview`, not the plugin-era `/payments/overview`.
- `rejected`: disabled, chip `Rejected`, error notice with external `Contact support` link.
- Missing currency: when multi-currency is off, the method is not `card`, the method is currently enabled, and the store currency is not supported by that method, the row stays actionable and shows the missing-currency warning. The single-currency copy is `%1$s requires the %2$s currency. Add %2$s to your store to offer this payment method.` and the multi-currency copy joins supported currencies with `or`.

Zeno the 6th verified native backend settings already expose `store_currency` and `is_multi_currency_enabled`, and native settings sections can pass those values into `WooPaymentsPaymentMethodsList`.

## Design

Keep the logic in the shared payment-method list rather than creating a separate WooPay or checkout abstraction. Add `currencies` to the native payment method definition model, with account-country-aware arrays for Alipay and WeChat Pay and fixed arrays for the remaining methods from the reference definitions. Then extend `getPaymentMethodAvailability()` with a small options object carrying enabled methods, store currency, multi-currency state, and the native Overview route URL.

This preserves the existing component boundary: `settings-page.tsx` derives store state and passes props; `payment-methods-list.tsx` decides row availability; `payment-method-definitions.ts` supplies provider definition data. The change should not alter bundle ownership or load new assets.

Accessibility correction: row checkboxes currently describe only the method description and status chip. Since the missing-currency notice has no chip, give availability notices stable ids and include them in `aria-describedby` whenever present.

## Verification Plan

Use TDD with focused Jest coverage in `settings-page.test.tsx` for delayed-approval, generic pending, pending verification with native Overview link, rejected contact-support link, missing-currency warning, and the negative missing-currency case when the method is not enabled. Then run focused settings-page Jest, exact-file ESLint, admin TypeScript lint, admin bundle build, `git diff --check -- . ':!.agents'`, and at least a live browser smoke of the target native settings page to catch obvious render/runtime regressions.

## Constraints

No WPCOM sandbox access, no WPCOM code changes, no Stripe CLI use expected, no push, no trunk work, no scratchpad linting, and no edits under `.agents` should be included in product lint commands.

## Final Status

A4av restored native WooPayments payment-method availability guidance parity in the shared settings row implementation. The final source diff adds supported currency metadata, account-country-aware Alipay/WeChat Pay currency helpers, JCB JPY-only handling, BNPL-specific inactive docs links, delayed-approval guidance for Alipay/WeChat Pay, native Overview links for pending verification, Contact support links for rejected methods, missing-currency warnings when multi-currency is off, and explicit `aria-describedby` / `spokenMessage` support for availability notices.

Final verification passed:

- Focused Jest `pnpm test:js -- settings-page.test.tsx --runInBand`: 63 tests passed after the BNPL inactive docs regression was added.
- Exact-file ESLint for the four touched settings files passed.
- `pnpm lint:lang:types` passed.
- `pnpm build:project:bundle` compiled successfully with only existing webpack cache serialization warnings.
- Playwriter target settings smoke passed at `data/a4av-payment-method-guidance/target-settings-smoke.json`; screenshot evidence is `data/a4av-payment-method-guidance/target-settings-smoke.png`.
- Target `debug.log` stayed 0 bytes, and recent target WordPress/CLI Docker log scans found no PHP/WP notices, warnings, deprecations, fatals, uncaught exceptions, database errors, stack traces, or actual 5xx markers.
- `pnpm --filter=@woocommerce/plugin-woocommerce changelog validate`, `git diff --check -- . ':!.agents'`, and branch `lint:changes:branch` passed. Known non-blocking noise was Composer/PHP 8.4 vendor deprecations, Changelogger `E_STRICT` deprecations, branch-wide ignored-file JS warnings, and webpack cache serialization warnings.

Review reconciliation: focused code re-review approved with no critical/high/medium findings; JS test review found one medium coverage gap for BNPL inactive docs, fixed with direct Affirm coverage; final a11y re-review approved with no critical/high/medium findings.
