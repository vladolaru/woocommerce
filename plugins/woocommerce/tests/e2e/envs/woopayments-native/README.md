# Secretless CI provider fixture

The `woopayments-native-secretless` CI job runs the built-in WooPayments against `ci-provider-fixture.php`, a must-use plugin that answers every request the store sends to `public-api.wordpress.com` and fails closed on the rest. It needs no Stripe or WordPress.com credentials.

- `ci-provider-fixture.php` is the fixture: the Jetpack identity, the request interception, the account and switch state machine, and the audit route.
- `ci-provider-fixture-recorded.json` holds real platform responses, keyed by `METHOD route`. The fixture serves them as they are.
- `install-ci-fixture.sh` copies both files into the store and prepares its state. `assert-ci-fixture-clean.sh` audits the run: no unknown request reached the fixture, the required routes were asked for, and every physical option the fixture touched is back at its original value.
- `ci-provider-fixture-test.php` and `ci-provider-fixture-test-wp-error.php` test the fixture standalone. Run `php ci-provider-fixture-test.php` from this folder.

## Rule: recorded shapes, never invented

A route the admin or onboarding screens render is answered from a recorded platform response, never from a hand-written body. A recorded response keeps every key the platform sent. Only store-specific values are replaced (see Sanitizing). The state machine keeps the account, the settings writes, the Jetpack identity and the audit baseline; the recorded data seeds it.

## Recorded routes

The responses were recorded from a connected US test-mode store (a test account on the local platform) with read-only requests. The sources below are dated 2026-10-20, except the rows marked as recorded earlier. "Option" means the answer was read from the store's own cache option, which holds the platform answer (`wcpay_account_data` and the others are the runtime's database cache). "API client" means the answer was captured once through the runtime's API client on the same store, with the query shown.

| Route | Source | Notes |
|---|---|---|
| `GET public/payment_methods/recommended` | Option `wcpay_recommended_payment_methods` | The 14 entries a US account gets, each with title, description, priority, type, category and icon. |
| `GET public/onboarding/fields_data` | Option `wcpay_onboarding_fields_data` | 38 countries with their business types and structures, 15 industry groups, and the industry-to-MCC map, without the locale key the store adds when it caches. |
| `GET public/accounts/fraud_services` | Option `wcpay_fraud_services_data` | `{"stripe": []}`. |
| `GET public/woopay/compatibility` | API client | The platform serves this route for every site, so the path has no site ID. The recorded store has no WooPay country list, so `available_countries` is empty. |
| `GET public/incentives` | API client, with the context of a connected store | The platform answers 204 with no body and `cache-for: 86400`. This is the one answer that is not in the JSON file: the fixture returns the same status and header. A new store with no WooPayments gets a welcome incentive instead; the fixture store is connected, so it does not. |
| `GET accounts`, fields `fees`, `account_details`, `deposits`, `is_documents_enabled`, `card_present_eligible`, `has_card_readers_available` | Option `wcpay_account_data` | Stored under `GET accounts (excerpt)`. The fixture merges these fields into its own account. The other account fields stay hand-written. |
| `GET transactions`, `GET transactions/summary` | API client, `date_after=2026-09-30 13:03:50` and `date_before=2026-09-30 13:04:00` | One real transaction, the charge whose payment, charge and timeline are also recorded, so its details page opens. The summary is the platform's answer to the same query, so the footer agrees with the row. |
| `GET deposits`, `GET deposits/summary` | API client, `date_after=2026-07-10` and `date_before=2026-07-10` | Four real payouts, all paid, one of them the recorded payout. The platform reports no pending payout for this account. The fixture filters rows by `status_is` and recomputes the summary count and total from the rows. |
| `GET deposits/overview-all` | API client | Real balance, schedule and bank account. The last payout is the recorded payout, so its details page opens. |
| `GET disputes`, `GET disputes/summary` | API client, `created_after=2026-10-18 08:40:00` and `created_before=2026-10-18 09:00:00` | Two real disputes that need a response, and the platform's summary for the same query. |
| `GET intentions/{id}`, `GET charges/{id}`, `GET timeline/{id}`, `GET deposits/{id}`, `GET disputes/{id}`, `GET documents`, `GET documents/summary` | Recorded earlier from the same store | One payment, one charge, one payout, one dispute and empty documents. |

### Sanitizing

- The store's site URL and the order description that carries it become the fixture account's own: `https://example.test` and `example.test blog_id 777`.
- Customer names and emails in the transaction and dispute rows become the recorded charge's shopper: `Test Shopper` and `customer@example.com`.
- The account ID in the dispute rows becomes `acct_native_ci`.
- The overview's last payout is replaced by the recorded payout, so the id resolves.
- Order numbers, bank details (Stripe's test bank, last four `6789`), card details and amounts are the platform's own test data and stay.

### Known effects of the recorded data

- The recorded account's balance is negative (its test disputes), so the Overview shows "Payouts may be interrupted while your WooPayments balance remains negative".
- The recorded account has Documents off (`is_documents_enabled` false) and card readers on. The Payments menu has no Documents item and a Card Readers item; the Card Readers page fails closed (see below).
- The dispute rows are due on 2026-10-26. After that date their "Respond by" cell reads as overdue; no case asserts it.
- The transaction row's order number does not exist in the fixture store, so the Order # column reads "N/A", as it does on any store without that order.

### Size

`ci-provider-fixture-recorded.json` is about 151 KB; the onboarding fields data alone is about 72 KB.

## Fail-closed routes

No CI case exercises these. They are not served; a request is logged as an unknown request and makes the audit fail. Do not add a case unless a CI case needs one, and then record it from the platform.

| Route | Reason |
|---|---|
| `POST intentions`, `intentions/{id}/capture`, `cancel`, `prepare_terminal_payment`, `GET intentions`, other `GET intentions/{id}`, other `GET charges/{id}`, `GET timeline/{other id}` | Payments need a real platform; the fixture is read-only plus settings. |
| `POST refunds`, `GET refunds` | Refunds need a real charge. |
| `POST customers`, `POST setup_intents`, `payment_methods` routes | Checkout and saved cards need a real platform. |
| `capital/*`, `POST accounts/capital_links` | Capital needs a lender offer; no case opens it. |
| `terminal/*`, `reader-charges/summary` | Card readers need a real device. The Card Readers page is reachable from the menu and shows the failed request. |
| `POST transactions/download`, `deposits/download`, `disputes/download`, `GET .../download/{id}` | Exports start a platform job and email the merchant. |
| `GET documents/{id}`, `POST vat`, `GET vat/{number}` | Document download and VAT need a real VAT-supported account. |
| `POST files`, `GET files/{id}`, `GET files/{id}/contents` | File uploads need a real platform. |
| `POST accounts/login_links`, `POST links` | Stripe login links need a real account. |
| `POST onboarding/init`, `POST onboarding/embedded`, `POST onboarding/embedded/finalize` | Onboarding needs Stripe's KYC. |
| `POST accounts/delete` | Reached by the "Activate payments" button on a connected fixture store, which first resets the test account; no CI case clicks it. |
| `POST accounts/tos_agreements`, `POST accounts/capabilities` | Account lifecycle writes; no case. |
| `GET transactions/{id}`, `GET transactions/search`, other `GET deposits/{id}`, `POST deposits`, other `GET disputes/{id}`, `POST disputes/{id}`, `POST disputes/{id}/close` | Lookups and writes for records the fixture does not hold. |
| `POST payment_method_domains`, `POST address-autocomplete-token`, `POST tracking/order`, `POST tracking/link-session`, `POST webhook/failed_events`, `POST payment_method_promotions/{id}/activate` | Best-effort or user-triggered calls no case makes. |
| `POST accounts/embedded/session` | Deliberate: the platform's own answer when Stripe cannot create a session, HTTP 500 `wcpay_server_error`, so the Overview's Stripe notifications banner takes its failure path. |

## Hand-written answers that stay

These routes are not on screens the recorded data changes, or no screen renders their fields. They are minimal valid answers: `GET authorizations`, `GET authorizations/summary`, `GET fraud_outcomes/status/block`, `GET disputes/status_counts`, `GET payment_method_promotions`, `GET transact/currency/rates`, `GET tracking/info`, `GET fraud_ruleset`, `POST fraud_ruleset`, `POST accounts`, `POST accounts/store_setup`, `POST compatibility`, `POST accounts/platform_checkout`, and the account's identity, capability, currency and branding fields.

## Cases that read recorded values

Each case asserts a value the recorded data sets, under the fixture only:

| Case | Value |
|---|---|
| `overview-transactions.spec.ts`, transactions list | The one row: Charge, $24.68, fees $1.02, net $23.66, and the footer summary fees. |
| `payouts-list.spec.ts`, payout history | Four paid payouts by id, with the type and bank columns filled. |
| `payouts-disputes-smoke.spec.ts`, disputes list | Two disputes needing a response, by id and amount. |
| `admin-page-loads.spec.ts`, payment details | The recorded charge and its timeline; the Documents routes are absent for the recorded US account. |
| `test-mode-notice.spec.ts`, payout details route | A recorded payout id. |
