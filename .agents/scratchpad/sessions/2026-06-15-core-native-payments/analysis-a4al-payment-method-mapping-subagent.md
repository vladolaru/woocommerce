---
session: 2026-06-15-core-native-payments
type: analysis
by: subagent:Dewey the 5th
created: 2026-06-20 08:23
target: A4al payment detail mapping
reconciles:
  - analysis-a4al-payment-detail-residual-parity.md
status: final
last_updated: 2026-06-20 08:30
---

# A4al Payment Detail Mapping

## Prompt

> **Prompt:** "Read-only source mapping for A4al. You are working in /Users/vladolaru/Work/a8c/woocommerce-develop-2 on branch exp/core-native-payments. Do not edit files, do not access WPCOM sandbox, do not push. Reference WooPayments client is read-only at /Users/vladolaru/Work/a8c/woocommerce-payments. Task: map payment-detail payment-method detail variants beyond card/card_present/interac_present. Compare reference components/formatters for non-card methods and wallet/billing details against current native transaction-detail-sections implementation. Identify what can be implemented from current charge/payment_intent payload fields without backend changes and what would need backend/platform changes. Return source-backed findings with file:line references and a concise recommended bounded scope. Do not include speculative claims without source evidence."

## Findings

### Current Native Data Path

- The native detail client fetches charge details from `/wc/v3/payments/charges/{chargeId}` and payment intent details from `/wc/v3/payments/payment_intents/{paymentIntentId}` in `plugins/woocommerce/client/admin/client/woopayments/admin/money-movement/data.ts:69-85`.
- The detail page normalizes a direct charge, `intent.charge`, or `intent.charges.data[0]` into `WooPaymentsTransaction` via `normalizeCharge()` and `normalizePaymentIntent()`. That normalization preserves `billing_details`, `payment_method`, and `payment_method_details` from the selected charge in `plugins/woocommerce/client/admin/client/woopayments/admin/money-movement/transaction-details-page.tsx:124-125` and `plugins/woocommerce/client/admin/client/woopayments/admin/money-movement/transaction-details-page.tsx:313-381`.
- The TypeScript shape explicitly allows non-card method detail objects through an index signature on `WooPaymentsPaymentMethodDetails`, while only card/card_present/interac_present are named fields: `plugins/woocommerce/client/admin/client/woopayments/admin/money-movement/types.ts:214-220`.
- The native REST detail controller forwards the platform charge and payment-intent responses through `WooPaymentsApiClient` and `WooPaymentsMoneyMovementOrderService`: routes are registered in `plugins/woocommerce/src/Internal/Payments/Providers/WooPayments/WooPaymentsPaymentDetailsRestController.php:101-104`; `get_charge()` and `get_payment_intent()` call the API client and then enrich in `plugins/woocommerce/src/Internal/Payments/Providers/WooPayments/WooPaymentsPaymentDetailsRestController.php:124-149`; the API client itself fetches `intentions/{id}` and `charges/{id}` in `plugins/woocommerce/src/Internal/Payments/Providers/WooPayments/Api/WooPaymentsApiClient.php:373-380` and `plugins/woocommerce/src/Internal/Payments/Providers/WooPayments/Api/WooPaymentsApiClient.php:704-715`.
- Backend enrichment adds order context and formatted addresses; it does not enumerate or transform payment-method detail fields in the inspected detail path. `enrich_charge_response()` delegates to `add_detail_order_info()` in `plugins/woocommerce/src/Internal/Payments/Providers/WooPayments/WooPaymentsMoneyMovementOrderService.php:113-121`; payment intents enrich embedded charges in `plugins/woocommerce/src/Internal/Payments/Providers/WooPayments/WooPaymentsMoneyMovementOrderService.php:123-145`; formatted billing address is added from `billing_details.address` in `plugins/woocommerce/src/Internal/Payments/Providers/WooPayments/WooPaymentsMoneyMovementOrderService.php:731-760`.

### Current Native Rendering

- The summary payment-method label has special handling only for `card`, `card_present`, and `interac_present`; all other types fall back to `formatLabel(method.type)` in `plugins/woocommerce/client/admin/client/woopayments/admin/money-movement/transaction-detail-sections.tsx:167-195`.
- The detail section treats only `card`, `card_present`, and `interac_present` as card methods in `plugins/woocommerce/client/admin/client/woopayments/admin/money-movement/transaction-detail-sections.tsx:197-198`.
- For non-card methods, the native detail section currently renders only `Type`, `ID`, `Owner`, and `Owner email` in `plugins/woocommerce/client/admin/client/woopayments/admin/money-movement/transaction-detail-sections.tsx:520-548`.
- For card methods, the native detail section renders Number, Expires, Type, ID, Owner, Owner email, Address, Origin, CVC check, Street check, and Postal code check in `plugins/woocommerce/client/admin/client/woopayments/admin/money-movement/transaction-detail-sections.tsx:551-621`.
- The native address renderer can already display either structured `billing_details.address` or `billing_details.formatted_address` in `plugins/woocommerce/client/admin/client/woopayments/admin/money-movement/transaction-detail-sections.tsx:246-274`.

### Reference Payment Method Coverage

- The reference full payment-method detail card is selected by a `detailsComponentMap` for `affirm`, `alipay`, `afterpay_clearpay`, `amazon_pay`, `au_becs_debit`, `bancontact`, `card`, `card_present`, `eps`, `giropay`, `grabpay`, `ideal`, `klarna`, `p24`, `sepa_debit`, `sofort`, `multibanco`, and `wechat_pay` in `/Users/vladolaru/Work/a8c/woocommerce-payments/client/payment-details/payment-method/index.js:31-50`.
- The reference gracefully returns `null` for malformed or unrecognized payment method types in `/Users/vladolaru/Work/a8c/woocommerce-payments/client/payment-details/payment-method/index.js:52-65`.
- The reference generic/base renderer for `affirm`, `alipay`, `afterpay_clearpay`, `grabpay`, `multibanco`, and `wechat_pay` reads only `charge.payment_method`, `charge.billing_details.name`, `charge.billing_details.email`, and `charge.billing_details.formatted_address`, then renders ID, Owner, Owner email, and Address in `/Users/vladolaru/Work/a8c/woocommerce-payments/client/payment-details/payment-method/base-payment-method-details/index.tsx:20-30` and `/Users/vladolaru/Work/a8c/woocommerce-payments/client/payment-details/payment-method/base-payment-method-details/index.tsx:56-95`.
- `au_becs_debit` reads `bsb_number` and `last4` from `payment_method_details.au_becs_debit`, plus billing owner/email/address; it renders BSB, Account, ID, Owner, Owner email, and Address in `/Users/vladolaru/Work/a8c/woocommerce-payments/client/payment-details/payment-method/becs/index.js:19-37` and `/Users/vladolaru/Work/a8c/woocommerce-payments/client/payment-details/payment-method/becs/index.js:64-115`.
- `sepa_debit` reads `last4` and `country` from `payment_method_details.sepa_debit`, plus billing owner/email/address; it renders IBAN, ID, Owner, Owner email, Address, and Origin in `/Users/vladolaru/Work/a8c/woocommerce-payments/client/payment-details/payment-method/sepa/index.js:19-40` and `/Users/vladolaru/Work/a8c/woocommerce-payments/client/payment-details/payment-method/sepa/index.js:67-118`.
- `bancontact` reads `bank_name`, `bic`, and `verified_name` from `payment_method_details.bancontact`, plus billing owner/email/address; it renders Bank name, BIC, ID, Verified name, Owner, Owner email, and Address in `/Users/vladolaru/Work/a8c/woocommerce-payments/client/payment-details/payment-method/bancontact/index.js:19-38` and `/Users/vladolaru/Work/a8c/woocommerce-payments/client/payment-details/payment-method/bancontact/index.js:66-125`.
- `giropay` reads `bank_name` and `bic` from `payment_method_details.giropay`, plus billing owner/email/address; it renders Bank name, BIC, ID, Owner, Owner email, and Address in `/Users/vladolaru/Work/a8c/woocommerce-payments/client/payment-details/payment-method/giropay/index.js:19-33` and `/Users/vladolaru/Work/a8c/woocommerce-payments/client/payment-details/payment-method/giropay/index.js:59-110`.
- `ideal` reads `bank`, `bic`, `country`, `iban_last4`, and `verified_name` from `payment_method_details.ideal`, plus billing owner/email/address; it renders ID, Bank name, BIC, IBAN, Verified name, Owner, Owner email, and Address in `/Users/vladolaru/Work/a8c/woocommerce-payments/client/payment-details/payment-method/ideal/index.js:19-44`, `/Users/vladolaru/Work/a8c/woocommerce-payments/client/payment-details/payment-method/ideal/index.js:81-119`, and `/Users/vladolaru/Work/a8c/woocommerce-payments/client/payment-details/payment-method/ideal/index.js:120-147`.
- `eps` reads `bank` and `verified_name` from `payment_method_details.eps`, plus billing owner/email/address; it renders Bank name, ID, Verified name, Owner, Owner email, and Address in `/Users/vladolaru/Work/a8c/woocommerce-payments/client/payment-details/payment-method/eps/index.js:19-38` and `/Users/vladolaru/Work/a8c/woocommerce-payments/client/payment-details/payment-method/eps/index.js:64-116`.
- `sofort` reads `bank_code`, `bank_name`, `bic`, `country`, `iban_last4`, and `verified_name` from `payment_method_details.sofort`, plus billing owner/email/address; it renders ID, Bank code, Bank name, BIC, IBAN, Verified name, Owner, Owner email, Address, and Origin in `/Users/vladolaru/Work/a8c/woocommerce-payments/client/payment-details/payment-method/sofort/index.js:19-46`, `/Users/vladolaru/Work/a8c/woocommerce-payments/client/payment-details/payment-method/sofort/index.js:87-123`, and `/Users/vladolaru/Work/a8c/woocommerce-payments/client/payment-details/payment-method/sofort/index.js:126-167`.
- `p24` reads `bank`, `reference`, and `verified_name` from `payment_method_details.p24`, plus billing owner/email/address; it renders Bank name, Reference, ID, Verified name, Owner, Owner email, and Address in `/Users/vladolaru/Work/a8c/woocommerce-payments/client/payment-details/payment-method/p24/index.js:19-42` and `/Users/vladolaru/Work/a8c/woocommerce-payments/client/payment-details/payment-method/p24/index.js:76-135`.
- `amazon_pay` reads `transaction_id` from `payment_method_details.amazon_pay`, plus billing owner/email/address; it renders Amazon Transaction ID, ID, Owner, Owner email, and Address in `/Users/vladolaru/Work/a8c/woocommerce-payments/client/payment-details/payment-method/amazon-pay/index.js:19-33` and `/Users/vladolaru/Work/a8c/woocommerce-payments/client/payment-details/payment-method/amazon-pay/index.js:54-101`.
- `klarna` reads `payment_method_category` and `preferred_locale` from `payment_method_details.klarna`, plus billing owner/email/address; it renders ID, Category, Preferred Locale, Owner, Owner email, and Address in `/Users/vladolaru/Work/a8c/woocommerce-payments/client/payment-details/payment-method/klarna/index.js:19-47` and `/Users/vladolaru/Work/a8c/woocommerce-payments/client/payment-details/payment-method/klarna/index.js:76-127`.

### Wallet and Summary Reference

- The reference compact summary component uses the typed details object at `payment[payment.type]` and displays `last4` for card, BECS, SEPA, card_present, and interac_present; P24 bank list name; giropay bank code; IBAN last4 for bancontact/ideal/eps/sofort; and Amazon Pay funding card last4 in `/Users/vladolaru/Work/a8c/woocommerce-payments/client/components/payment-method-details/index.tsx:32-83`.
- The same reference component renders wallet icons for `amazon_pay` directly and for `payment[payment.type].wallet.type` for other methods in `/Users/vladolaru/Work/a8c/woocommerce-payments/client/components/payment-method-details/index.tsx:85-120`.
- It selects a card/brand class from `paymentMethod.network`, `paymentMethod.brand`, `paymentMethod.funding.card.brand`, or `payment.type`, and suppresses the duplicate brand sprite for Amazon Pay without funding card in `/Users/vladolaru/Work/a8c/woocommerce-payments/client/components/payment-method-details/index.tsx:126-168`.
- The reference tests cover Amazon Pay with and without `amazon_pay.funding.card`, and a `link` type without a type-specific object, in `/Users/vladolaru/Work/a8c/woocommerce-payments/client/components/payment-method-details/__tests__/index.test.js:124-164`.

### Implementable Without Backend Changes

This is limited to fields already present in the native charge/payment-intent payload at runtime. The current native source proves those payload fields are forwarded and normalized, but it does not prove the platform returns every method-specific field for every historical transaction.

- Add Address to the current non-card generic renderer using `transaction.billing_details` and the existing `getAddressLines()` helper. The required data path already exists in native types and normalization: `types.ts:167-181`, `transaction-details-page.tsx:332-339`, and `transaction-detail-sections.tsx:246-274`.
- Implement frontend renderers for the reference non-card methods from `transaction.payment_method_details[method.type]` when that object exists: `au_becs_debit`, `sepa_debit`, `bancontact`, `giropay`, `ideal`, `eps`, `sofort`, `p24`, `amazon_pay`, and `klarna`. Native `WooPaymentsPaymentMethodDetails` permits these dynamic keys at `types.ts:214-220`, and the detail endpoints forward platform charge/intent responses as described above.
- Preserve the existing non-card fallback for missing or unrecognized method-specific objects: Type, ID, Owner, Owner email, plus Address. This matches the reference pattern of graceful degradation for unrecognized types in `client/payment-details/payment-method/index.js:52-65` while improving current native coverage.
- Add text-only wallet/funding rows when fields are already present, such as `method.card.wallet.type` or `method.amazon_pay.funding.card.last4`. The reference source proves those fields are consumed for wallet/funding presentation, but native does not currently have the reference `paymentMethodsMap`/brand icon path in the inspected money-movement code.

### Needs Backend or Platform Changes

- Any method-specific row whose source field is absent from the returned charge/payment-intent payload needs backend/platform work before the frontend can render it. Examples: `payment_method_details.bancontact.bank_name`, `payment_method_details.ideal.iban_last4`, `payment_method_details.p24.reference`, `payment_method_details.amazon_pay.transaction_id`, and `payment_method_details.klarna.payment_method_category`. The native frontend can read dynamic keys, but cannot synthesize missing platform fields from the current normalized transaction shape.
- Exact reference wallet icon parity requires frontend asset/mapping parity with the WooPayments client reference (`paymentMethodsMap`, tooltip labels, and brand CSS/classes), or an equivalent native mapping. That is not a platform/backend requirement when wallet data is present, but it is more than just field formatting in `transaction-detail-sections.tsx`.
- Do not claim backend support for non-card payload field completeness from current source alone. The branch source shows direct forwarding of platform detail responses and formatted-address enrichment, but no native backend code that expands or backfills the non-card method-specific objects.

### Recommended Bounded Scope

Implement a frontend-only renderer in `transaction-detail-sections.tsx` that:

1. Adds Address to the existing non-card fallback.
2. Adds method-specific rows for `au_becs_debit`, `sepa_debit`, `bancontact`, `giropay`, `ideal`, `eps`, `sofort`, `p24`, `amazon_pay`, and `klarna`, reading only fields already present under `transaction.payment_method_details[method.type]`.
3. Uses `Dash` for missing field values and keeps the current generic fallback for unrecognized method types.
4. Adds focused frontend tests with fixture transactions containing those fields. Treat any absent real platform field as a backend/platform follow-up, not as a blocker for the frontend renderer.
