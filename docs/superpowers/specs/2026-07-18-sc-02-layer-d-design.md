# SC-02 Blocks Card Checkout Layer-D Design

## Purpose

Wire the existing `SC-02` deterministic contract into the WooPayments critical
flows harness. The flow must prove that a fresh card payment submitted through
the public WooCommerce Store API produces the same paid-order and provider
outcome on the reference WooPayments plugin and native target.

This is harness-only work. It does not change WooCommerce or WooPayments
production code. A reproducible native difference is an honest `FAIL`, not a
reason to weaken the request path, assertions, or verdict.

## Scope

The implementation will:

- create a fresh anonymous Cart-Token session for each store;
- use the public `/wc/store/v1/cart/*` and `/wc/store/v1/checkout` HTTP routes;
- add one fixed `test-lab-beaker-001` fixture at quantity one;
- submit `woocommerce_payments` with the deterministic Stripe test Payment
  Method `pm_card_visa`;
- prove the resulting order was created through the Store API;
- reconcile the exact order, PaymentIntent, and Charge;
- compare normalized reference and target outcomes;
- archive privacy-minimized, run-bound, authenticated evidence;
- make `run.sh` independently validate each accepted manifest; and
- update the coverage matrix only after a live both-store runner result earns
  the status.

The implementation will not:

- call the gateway directly as a substitute for Store API checkout;
- create an authenticated customer or saved payment token;
- duplicate the accepted Layer-A browser interaction and screenshots;
- persist Cart Tokens, cookies, order keys, client secrets, raw addresses, or
  complete checkout request or response bodies;
- access a remote WordPress.com sandbox;
- reset, delete, reseed, prune, or recreate local environment data;
- retry a checkout after an ambiguous payment result; or
- modify product or payment production code.

## Why the Guest Store API Path

`SC-02` requires Blocks/Store API payment behavior, not customer-account or
saved-card behavior. A fresh guest Cart Token exercises the required public
route, Store API payment-data translation, active WooPayments gateway, order
persistence, and provider adapters without adding login credentials, customer
creation, provider customer mappings, or token cleanup.

The rejected alternatives are:

- an authenticated HTTP client, which adds account and cookie state without
  strengthening the route or payment assertions; and
- a full browser replay, which duplicates Layer A and introduces presentation
  and selector failures into the deterministic layer.

## Mandatory Environment Preflight

Before creating either cart, preserve the objective's local-only cold-start
contract:

- reference and target HTTP endpoints respond;
- WP-CLI reaches both isolated containers and reports distinct WordPress homes;
- the reference runtime owner is `plugin` and the target owner is `native`;
- the approved target container guard passes;
- both gateways are connected in test mode;
- `stripe charges list --limit 1` succeeds without recording credentials;
- the local WordPress.com environment and required Transact services are ready;
  and
- the local event listener is alive.

Any missing or contradictory prerequisite is `BLOCKED` before mutation. The
workflow may start or repair the existing dedicated environments and listeners,
but must not reset or recreate their data.

## Safety and Mutation Budget

Each store receives at most one fresh checkout attempt for a runner invocation:

1. Create one anonymous Cart-Token session.
2. Add one expected product at quantity one.
3. Set the fixed local fixture billing and shipping address.
4. Select one deterministic zero-cost shipping rate.
5. Submit one `pm_card_visa` Store API checkout.

The run token is a non-secret value derived from the runner identity and store.
It is placed in `customer_note` and later used only to corroborate the exact
response order. The authoritative order lookup remains the positive `order_id`
returned by the checkout response; the collector never selects the newest
order.

The checkout is never retried. If HTTP completion, response parsing, or payment
completion is ambiguous, the harness performs read-only reconciliation for the
reported order when possible and records `BLOCKED`. It does not assume a retry
is safe.

A complete, structured Store API rejection is not ambiguous. It is an honest
product `FAIL`, even when the error response has no order ID. The passing path
still requires exact-order reconciliation; a failed request cannot earn `PASS`
from an order found through a secondary lookup.

## Components

### Store Orchestrator

Add `flows/SC-02-blocks-card-checkout.sh` as the Layer-D entry point. It will:

- source `lib/common.sh` for store commands, identity, archive, and log guards;
- capture a signed preflight state for the current store;
- invoke the Store API client exactly once;
- capture a signed post-checkout state for the returned order ID;
- ask the evidence tool to evaluate the store contract;
- assert marker-bounded logs independently;
- compare both stores during the target invocation;
- create the store execution record and manifest; and
- return only `0`, `1`, or `3` for `PASS`, `FAIL`, or `BLOCKED`.

The flow remains store-scoped because `run.sh` invokes deterministic scripts
once per requested store. A both-store run executes reference first, so the
target invocation can require and compare current-run reference artifacts.

### Store API Client

Add `flows/sc02-store-api.py` as a focused HTTP client. Its only mutation mode
will execute this sequence against the configured local origin:

1. `GET /wp-json/wc/store/v1/cart` and capture the response Cart Token in memory.
2. `POST /wp-json/wc/store/v1/cart/add-item` with product ID and quantity one.
3. `POST /wp-json/wc/store/v1/cart/update-customer` with the fixed test address.
4. Select the sole zero-cost available rate with
   `POST /wp-json/wc/store/v1/cart/select-shipping-rate`.
5. Re-read the cart and validate the exact product, quantity, total, currency,
   selected shipping rate, and available gateway.
6. `POST /wp-json/wc/store/v1/checkout` with the address, run-bound customer
   note, top-level `payment_method: woocommerce_payments`, and:

   ```json
   {
     "payment_data": [
       {
         "key": "wcpay-payment-method",
         "value": "pm_card_visa"
       }
     ]
   }
   ```

The client will accept only `http` or `https` URLs whose hostname is
`localhost`, `127.0.0.1`, or ends in `.localhost`. Redirects must remain on the
configured origin. Every request has a bounded timeout. Every mutation must
carry the same non-empty Cart Token, and every response must be valid JSON with
the expected status and minimal schema.

The client will refuse:

- a missing, changed, or persisted Cart Token;
- a non-local or changed origin;
- an unexpected redirect;
- pre-existing or extra cart items;
- a missing, unavailable, ambiguous, or non-zero shipping rate;
- a product, quantity, total, or currency mismatch;
- an unavailable `woocommerce_payments` gateway;
- an invalid response schema or missing positive order ID; and
- a checkout response without `payment_status: success` and order status
  `processing` or `completed`.

The client emits a single normalized JSON transcript. It records method, path,
status, selected non-secret request facts, submitted payment-data key names, and
bounded response projections. Each projection receives a reproducible payload
SHA-256 digest and a domain-separated HMAC before the raw response is discarded.
The client does not emit headers or values capable of authorizing another
request.

Only `wcpay-payment-method` is submitted because it is the shared required
Store API field consumed by both runtimes. Browser-only Stripe Elements,
fingerprint, and fraud-token production remains covered by the accepted Layer-A
package; Layer D neither fabricates those values nor claims to re-prove them.

### WordPress State Collector

Add `flows/class-woopaymentscriticalflowssc02driver.php` with `preflight` and
`post` modes. Invoke it through the existing keyed WP-CLI helper so its output is
bound to the runner context.

Preflight will report:

- runtime owner, gateway class, gateway availability, test mode, and connection
  readiness;
- fixed SKU, product ID, price, purchasability, stock state, and store currency;
- connected account ID projection; and
- a bounded debug-log marker.

Post will accept only the exact store, run stamp, run token, product ID, and
checkout response order ID. It will report:

- order ID, status, created-via value, gateway, total, currency, paid timestamp,
  transaction ID, `_intent_id`, and `_charge_id`;
- customer note and normalized line-item product, SKU, quantity, and total;
- a bounded provider PaymentIntent projection containing ID, object, status,
  amount, currency, latest Charge, and Payment Method;
- a bounded provider Charge projection containing ID, object, paid/status,
  amount, amount captured, currency, PaymentIntent, and Payment Method; and
- marker-bounded diagnostic facts needed by the flow oracle.

Provider observations are read-only and use the existing local test credential
inside WordPress. The collector must never emit that credential, an
Authorization header, a client secret, or an unbounded provider object.

### Evidence Evaluator

Add `flows/sc02-evidence.py` for artifact validation, store-contract evaluation,
cross-store comparison, execution records, manifest construction, and manifest
verification. Keeping the oracle outside the HTTP and WordPress mutation paths
lets the runner reproduce the verdict from archived facts.

The tool will use the existing `CRITICAL_FLOWS_RUN_CONTEXT_KEY` with separate
HMAC domains for:

- Store API transcripts;
- WordPress preflight and post captures;
- normalized store results;
- cross-store comparisons;
- execution records; and
- final manifests.

The private key is inherited through the environment and never written or
passed in an argument. Evidence records contain only its SHA-256 fingerprint
and their domain-separated HMAC. Plain SHA-256 payload and file digests remain
useful bindings, but cannot replace the authenticated verdict fields.

Manifest verification will reject:

- symlinks, path escapes, missing artifacts, or unexpected artifacts;
- malformed JSON, duplicate JSON keys, unknown fields, or wrong schemas;
- stale or swapped run, scope, flow, store, or key-fingerprint bindings;
- status and exit-code contradictions;
- file, payload, or HMAC changes;
- a result not reproducible from its bound transcript and state; and
- a manifest whose authenticated final verdict does not match its execution
  record and independent log verdict.

### Runner Integration

Extend `run.sh` with an `SC-02` bound-manifest validator following the existing
`MO-02`, `MO-03`, and `MA-01` acceptance branches. The runner may accept a
store's `PASS`, `FAIL`, or `BLOCKED` only when the manifest:

- belongs to the current runner invocation;
- binds the expected store and artifact set;
- authenticates with the in-memory run-context key;
- reproduces the script's status and exit code; and
- independently recomputes the same semantic verdict.

Otherwise the runner records `BLOCKED`, regardless of the script's printed text
or exit status. No older archive can satisfy a current run.

## Evidence Archive

The current run directory will contain:

```text
SC-02-blocks-card-checkout/
├── ref-preflight.json
├── ref-http.json
├── ref-post.json
├── ref-log-scan.json
├── ref-result.json
├── ref-execution.json
├── ref-manifest.json
├── target-preflight.json
├── target-http.json
├── target-post.json
├── target-log-scan.json
├── target-result.json
├── target-execution.json
├── target-manifest.json
└── comparison.json
```

A passing reference manifest binds only the reference packet. A passing target
manifest in a both-store run binds both complete store packets and
`comparison.json`. A single-store run can prove that store's contract, but
cannot claim reference-target parity or move the maintained matrix row to
`PASS`.

Passing manifests require the complete artifact set shown above for their
scope. `FAIL` and `BLOCKED` manifests bind the complete prefix produced before
the terminal condition plus the result, log scan, execution record, and
manifest. The evaluator defines the allowed artifact set for every terminal
stage; missing evidence from a completed stage or an unbound extra file is
invalid rather than silently ignored.

## Store API Data Flow

The flow for each store is:

```text
runner identity and private run context
  -> signed WordPress preflight and log marker
  -> fresh anonymous Cart Token
  -> add one fixed product
  -> set fixed address and zero-cost shipping
  -> validate exact cart and available WooPayments gateway
  -> submit one Store API checkout with pm_card_visa
  -> normalized HTTP transcript
  -> exact-order WordPress and provider reconciliation
  -> independent bounded-log assertion
  -> authenticated store result, execution record, and manifest
  -> runner manifest verification
```

The target invocation adds:

```text
validated current-run reference packet
  + validated current-run target packet
  -> normalized parity comparison
  -> target manifest binding both stores and the comparison
```

## Oracle

### Required Preflight

Before HTTP mutation, each store must prove:

- the expected runtime owns the WooPayments gateway;
- the gateway is available, connected, and in test mode;
- the fixed product exists, is purchasable and in stock, and costs USD 25.00;
- store currency is USD; and
- the connected local provider account can be observed.

An incomplete or contradictory prerequisite is `BLOCKED` because the checkout
would not test the intended product surface.

### Required HTTP Result

The normalized transcript must prove:

- every request stayed on the configured local origin;
- one fresh Cart Token carried the entire sequence;
- the final cart contained exactly one expected product at quantity one;
- the selected shipping rate cost zero;
- the final cart total was USD 25.00;
- `woocommerce_payments` was available and submitted;
- the only payment-data key was `wcpay-payment-method`;
- checkout returned HTTP 200 with a positive order ID;
- payment status was `success`; and
- returned order status was `processing` or `completed`.

A complete HTTP response that rejects the payment or violates one of these
business assertions is `FAIL`. Transport ambiguity, invalid JSON, or missing
identity needed for safe reconciliation is `BLOCKED`.

### Required Order Result

The exact response order must prove:

- status is `processing` or `completed`;
- `created_via` is exactly `store-api`;
- payment method is exactly `woocommerce_payments`;
- customer note equals the current store's run token;
- customer ID is zero;
- there is one expected SKU at quantity one and line total USD 25.00;
- order total and currency are USD 25.00;
- the paid timestamp is present;
- transaction ID equals `_intent_id` and matches the PaymentIntent shape; and
- `_charge_id` is present and matches the Charge shape.

An observed order that violates these assertions is `FAIL`. Failure to load the
exact reported order or collect trustworthy state is `BLOCKED`.

### Required Provider Result

The bounded provider projections must prove:

- PaymentIntent status is `succeeded`;
- PaymentIntent amount and currency are 2500 and `usd`;
- its latest Charge equals the order `_charge_id`;
- Charge is paid or succeeded;
- Charge amount and amount captured are 2500;
- Charge currency is `usd`;
- Charge PaymentIntent equals the order `_intent_id`; and
- both provider objects agree on Payment Method identity.

A complete provider state that contradicts the order is `FAIL`. Missing or
ambiguous provider observation is `BLOCKED`.

### Required Diagnostics

The marker-bounded log window must not contain an attributable PHP fatal,
warning, notice, uncaught exception, or harness provenance error. A product
diagnostic violation is `FAIL`; a broken marker or unauthenticated log packet is
`BLOCKED`.

### Cross-Store Parity

Reference and target must agree on:

- normalized HTTP and payment result categories;
- Store API attribution and guest-customer behavior;
- gateway, product, quantity, amount, currency, and paid status;
- intent and Charge success categories and linkage shape; and
- clean diagnostic outcome.

Opaque IDs, timestamps, local URLs, response digests, and fixture run tokens
must differ where expected and are not compared for equality. Reusing a store,
Cart Token, order ID, intent ID, or Charge ID across roles is `BLOCKED` as an
identity/provenance contradiction, not parity.

## Verdict Rules

A store-level `PASS` requires every preflight, HTTP, order, provider,
diagnostic, and provenance assertion for that store. A dual-store `PASS` also
requires the authenticated parity comparison.

`FAIL` means the intended checkout completed far enough to observe trustworthy
product behavior that violated the contract. Examples include a payment
rejection, wrong cart or order total, missing Store API attribution, missing
payment metadata, provider mismatch, or reference/native semantic difference.

`BLOCKED` is reserved for missing prerequisites, unsafe ambiguity, or
untrustworthy evidence. Examples include identity failure, disconnected test
mode, unavailable fixture, ambiguous shipping, transport failure with unknown
payment outcome, unavailable provider reconciliation, broken log boundaries,
or invalid manifest authentication.

The maintained matrix row remains `PENDING` when the live result is a functional
`FAIL` or prerequisite `BLOCKED`. It moves to `PASS` only after both stores and
parity pass through runner-accepted current-run manifests.

## Tests and Verification

Implementation will proceed test-first.

Add focused tests for the Store API client and evidence evaluator, then extend
`test-runner.py` for runner integration. Fixtures will cover:

- valid distinct reference and target identities and homes;
- valid Cart-Token request sequencing and local-origin enforcement;
- missing, changed, leaked, or reused Cart Tokens;
- redirects to another origin and non-local configured URLs;
- extra cart items, wrong product or quantity, and wrong total or currency;
- zero, one, and multiple zero-cost shipping rates;
- missing WooPayments availability or wrong submitted payment-data keys;
- success, product rejection, malformed JSON, timeout, and ambiguous checkout
  responses;
- exact response order binding and run-token correlation;
- wrong guest customer, created-via, gateway, line item, status, or paid state;
- missing or mismatched intent and Charge metadata;
- provider amount, currency, status, linkage, and Payment Method differences;
- diagnostic `PASS`, `FAIL`, and `BLOCKED` boundaries;
- matching and mismatching cross-store projections;
- valid `PASS`, `FAIL`, and `BLOCKED` manifests;
- stale, swapped, relabeled, malformed, symlinked, path-escaping, missing, and
  extra artifacts;
- modified semantic fields with recomputed plain hashes; and
- attempted `FAIL` or `BLOCKED` to `PASS` rewrites without the run-context key.

Static and focused verification will include:

```bash
bash -n tools/woopayments-critical-flows/flows/SC-02-blocks-card-checkout.sh
php -l tools/woopayments-critical-flows/flows/class-woopaymentscriticalflowssc02driver.php
python3 -m py_compile tools/woopayments-critical-flows/flows/sc02-store-api.py
python3 -m py_compile tools/woopayments-critical-flows/flows/sc02-evidence.py
python3 tools/woopayments-critical-flows/test-sc02-store-api.py
python3 tools/woopayments-critical-flows/test-sc02-evidence.py
python3 tools/woopayments-critical-flows/test-runner.py
```

The behavioral proof will run through the authoritative runner on both stores:

```bash
WOOPAYMENTS_APPROVED_TARGET_CONTAINER="${WOOPAYMENTS_APPROVED_TARGET_CONTAINER}" \
tools/woopayments-critical-flows/run.sh \
  --store both \
  --layer deterministic \
  --flow SC-02-blocks-card-checkout \
  --ref-url http://localhost:8082 \
  --target-url http://store8889.localhost:8889
```

Because runner and evidence tooling change, the complete harness self-test is
required:

```bash
tools/woopayments-merge/run-self-tests.sh
```

Finally, run Markdown lint, PHP coding standards for the new collector,
`git diff --check`, secret-pattern checks, and an independent code-review
subagent. Address all critical and important findings before committing the
logical SC-02 implementation package.

## Documentation and Handoff

After live runner verification:

- record the exact archive path and validated manifest SHA-256 digests;
- record reference, target, and parity verdicts in the scratch session log;
- update `tools/woopayments-critical-flows/README.md` and `matrix.tsv` together
  only if a both-store `PASS` earns the row;
- record any honest product `FAIL` as a production punch-list item with its
  exact assertion and evidence path;
- run the complete self-test after all tooling edits;
- dispatch and address the required independent review; and
- commit one logical harness implementation change and report its git range.
