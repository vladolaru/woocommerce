# MO-06 Refund Failure Coverage Design

## Purpose

Wire the existing `MO-06` deterministic contract into the WooPayments critical
flows harness. The flow must prove that a real provider rejection is surfaced
without changing the local order or provider refund state, and that reference
and native produce equivalent observable results.

This is harness-only work. It does not include a product fix. A reproducible
functional difference is an honest `FAIL`, not a reason to weaken the oracle.

## Scope

The implementation will:

- exercise one reference order and one native order through Layer D;
- create a fresh captured USD 25 test charge in each store;
- fully refund each charge through the ordinary WooCommerce refund path;
- attempt exactly one additional USD 5 refund through the active gateway;
- compare pre-attempt and post-attempt local and provider state;
- require a merchant-visible failure signal and structured diagnostics;
- archive normalized, run-bound evidence for each store;
- make `run.sh` independently verify the evidence manifest before accepting a
  verdict; and
- update the coverage documentation only after a live runner-accepted result.

The implementation will not:

- change WooPayments reference or native product code;
- use browser automation;
- access a remote WPCOM sandbox;
- reset, reseed, prune, or otherwise clean Docker data;
- manufacture a network or proxy error;
- pre-exhaust the provider charge outside WooCommerce;
- retry the over-refund after an ambiguous result; or
- claim to exercise order-screen transient-refund cleanup. The contract permits
  a direct `process_refund()` call, which starts after any order-screen refund
  record would have been created.

## Chosen Failure Condition

Each store gets a fresh captured order. The harness first performs a normal full
refund with `wc_create_refund()` and `refund_payment => true`, using the existing
deterministic refund driver. Once both local and provider evidence prove that the
charge is fully refunded, the harness invokes the store's active gateway once:

```php
$gateway->process_refund( $order_id, 5.00, $run_bound_reason );
```

The already-refunded charge is a provider-owned, deterministic rejection
condition. It exercises the real gateway and provider adapters without a
synthetic transport fault. It also avoids the race and local-state ambiguity
that would follow a direct provider refund performed before WooCommerce knows
about it.

## Safety and Idempotency

The mutation budget is fixed per store:

1. Create one fresh captured USD 25 charge.
2. Create one normal full refund.
3. Make one rejected USD 5 gateway refund attempt.

Every run uses new order and charge identifiers and a reason containing the
current runner identity. Old fully refunded orders are never reused.

Before a money action, the flow must prove the expected plugin owner, connected
test mode, run context, gateway, currency, amount, order, and charge identity.
If any prerequisite is missing or contradictory, the flow stops as `BLOCKED`.

The over-refund action is never retried. If its process result or transport
outcome is ambiguous, the harness performs read-only local and provider
reconciliation, records the ambiguity, and stops as `BLOCKED`. It must not infer
that a retry is safe.

Evidence must not contain credentials, authorization headers, or raw secrets.
Provider reconciliation uses read-only requests and records only the normalized
fields needed by the oracle.

## Components

### Store Orchestrator

Add `flows/MO-06-refund-failure-handling.sh` as the Layer D entry point. It will:

- source `lib/common.sh` for environment and evidence guards;
- use `tools/woopayments-merge/flow-drive.sh charge --deterministic` to create
  the captured fixture;
- use the same driver with `refund --deterministic --type full` for the ordinary
  full-refund fixture;
- capture a pre-attempt snapshot;
- invoke the single direct gateway attempt;
- capture a post-attempt snapshot and bounded diagnostics;
- ask the evidence tool to evaluate the store contract; and
- emit a store manifest at the runner-prescribed archive path.

The top-level runner already invokes deterministic scripts once per requested
store. The script remains store-scoped so `--store ref`, `--store target`, and
`--store both` have the same mutation and evidence semantics.

The archive uses the existing per-flow convention:

```text
ref-fixture.json              target-fixture.json
ref-pre.json                  target-pre.json
ref-attempt.json              target-attempt.json
ref-post.json                 target-post.json
ref-log-scan.json             target-log-scan.json
ref-execution.json            target-execution.json
ref-manifest.json             target-manifest.json
comparison.json               # only when both current-run stores are available
```

### WordPress Runtime Driver

Add
`flows/class-woopaymentscriticalflowsmo06driver.php` with explicit read-only
snapshot and one-shot attempt actions. The driver will normalize plugin-specific
runtime differences behind a common output shape.

The snapshot action will report:

- runtime owner, gateway class, test-mode status, and connection readiness;
- order ID, charge ID, currency, total, status, and payment method;
- local refund record IDs, count, total, statuses, and reason text;
- relevant order-note IDs, timestamps, types, and normalized content;
- provider charge amount, amount refunded, refunded state, and refund IDs.

The attempt action will:

- revalidate the order, charge, gateway, run context, and full-refund baseline;
- invoke `process_refund()` once with USD 5 and a unique run-bound reason;
- capture whether the return is `WP_Error`, `false`, `true`, or another type;
- normalize the error code and message without hiding the raw type;
- record action cardinality and start/end timestamps; and
- catch and report thrown exceptions as a functional failure signal while
  allowing the shell and runner to complete evidence production.

The driver will not create or delete local refund posts around the direct
attempt. This makes any post-attempt local mutation attributable to the gateway
path under test.

### Evidence Evaluator

Add `flows/mo06-evidence.py` for normalization, store-contract evaluation,
cross-store comparison, manifest creation, and manifest verification. Keeping
the oracle outside the mutation driver lets `run.sh` independently re-evaluate
archived facts instead of trusting a shell exit code.

Per store, the evaluator will bind:

- the current run stamp, run scope, flow ID, and store;
- the fixture, pre-attempt, attempt, post-attempt, diagnostic, and execution
  files;
- byte and payload SHA-256 digests for every verdict source;
- exact expected artifact names with no unbound extras;
- the deterministic status and exit code; and
- a manifest payload digest.

Manifest verification will reject symlinks, path escapes, missing or extra
artifacts, malformed JSON, unknown fields, stale run bindings, wrong flow or
store bindings, status/exit contradictions, digest changes, and semantic
results that do not follow from the bound evidence.

### Runner Integration

Extend `run.sh` with an `MO-06` verifier and deterministic manifest acceptance
branch following the existing `MO-02` and `MO-03` patterns. The runner may
record `PASS`, `FAIL`, or `BLOCKED` only when the manifest belongs to the current
invocation and the verifier reproduces its verdict. Otherwise the runner records
`BLOCKED` and explains which trust boundary failed.

No result may be upgraded from `FAIL` or `BLOCKED` merely because the flow script
exited successfully or an older archive contains passing evidence.

## Evidence Flow

The data flow for each store is:

```text
runner identity and log marker
  -> captured charge fixture
  -> ordinary full-refund fixture
  -> pre-attempt local/provider snapshot
  -> exactly one direct USD 5 gateway attempt
  -> post-attempt local/provider snapshot and diagnostics
  -> normalized store contract result
  -> current-invocation manifest
  -> independent runner verification
```

For `--store both`, the runner invokes reference first. The target invocation
then requires the reference artifacts from the same run stamp and scope, creates
`comparison.json`, and binds both stores plus the comparison into the target
manifest. Cross-store parity is a separate assertion: two internally valid but
observably different outcomes do not pass parity. A single-store run evaluates
only that store and cannot claim parity.

## Oracle

### Required Baseline

Before the rejected attempt, each store must prove:

- the intended plugin owns the WooPayments gateway;
- the order is paid through that gateway and remains bound to its charge;
- local order status is `refunded`;
- local refunded total equals the full order total;
- exactly one successful full-refund record exists for this fixture;
- provider amount refunded equals the captured amount;
- provider state says the charge is fully refunded; and
- note, refund, provider, and diagnostic baselines are complete.

An incomplete baseline is `BLOCKED`, because an unchanged post-state would not
prove the failure path.

### Required Failure Result

The direct attempt must return `WP_Error` or `false`. A `WP_Error` must have a
non-empty code and message that identify a refund failure. `true`, an empty or
generic result, or a thrown exception is `FAIL` because the exercised product
did not satisfy the contract.

### Required Untouched State

The post-attempt snapshot must match the baseline for:

- order status;
- local refund IDs, count, statuses, and total;
- provider refund IDs, count, amount refunded, and fully-refunded state; and
- the order-to-charge binding.

Any local or provider drift is `FAIL`, including a phantom refund record, an
inflated refund total, a second provider refund, or a duplicate status
transition.

### Required Merchant and Diagnostic Signals

The note delta must contain a new failed-refund note attributable to the current
attempt and must not contain a new successful-refund note. The evidence must
also contain a structured provider failure signal from the result, order note,
or bounded WooCommerce logger evidence.

The marker-bounded WordPress debug diagnostics must contain no uncaught
exception, PHP fatal, warning, or notice attributable to the flow. The provider
rejection itself is expected and is not required to appear in `debug.log` when
the gateway correctly records it through its own logger or order note.

A missing failed-refund note, a new success note, swallowed error, uncaught
exception, or fatal diagnostic is `FAIL`.

### Cross-Store Parity

Reference and native must have the same normalized result category, compatible
failure semantics, unchanged local/provider projections, failed-note behavior,
and absence of success notes or fatal diagnostics. Exact provider request IDs
and human wording may differ; the semantic categories may not.

## Verdict Rules

A store-level `PASS` requires every baseline, failure-result,
state-consistency, merchant-signal, diagnostic, and provenance assertion for
that store to pass. The overall dual-store coverage result additionally requires
cross-store parity to pass. A single-store `PASS` is useful diagnostic evidence,
but cannot move the matrix row to `PASS` by itself.

`FAIL` means the complete flow was exercised and the observed product behavior
violated the contract. Examples include an accepted second refund, weak error
propagation, local or provider drift, a missing failed-refund note, a new success
note, a fatal, or a reference/native semantic difference.

`BLOCKED` is reserved for missing prerequisites or untrustworthy evidence:
identity failure, disconnected test mode, fixture failure, incomplete provider
reconciliation, ambiguous attempt completion, broken log boundaries, or invalid
manifest provenance.

The current matrix row remains `PENDING` when live evidence produces a functional
`FAIL`; the ledgers and archive record the production gap. It moves to `PASS`
only after both stores and parity pass with runner-accepted evidence.

## Tests and Verification

Implementation will follow test-first sequencing.

Add focused MO-06 evidence tests in `test-mo06-evidence.py` and extend
`test-runner.py` for runner integration. Test fixtures will cover:

- distinct reference and native identity probes and WordPress homes;
- valid current-invocation manifests for `PASS`, `FAIL`, and `BLOCKED`;
- wrong schema, run stamp, scope, flow, store, status, exit code, or digest;
- missing, extra, malformed, symlinked, and path-escaping artifacts;
- incomplete ownership, identifier, full-refund, note, or provider baselines;
- action cardinality other than exactly one;
- `WP_Error`, `false`, `true`, empty errors, and thrown exceptions;
- changed local refund IDs, counts, totals, statuses, or order status;
- changed provider refund IDs, counts, totals, or refunded state;
- a missing failure note or an added success note;
- missing provider diagnostics or an unexpected fatal; and
- matching and mismatching cross-store semantics.

Static and focused verification will include:

```bash
bash -n tools/woopayments-critical-flows/flows/MO-06-refund-failure-handling.sh
php -l tools/woopayments-critical-flows/flows/class-woopaymentscriticalflowsmo06driver.php
python3 -m py_compile tools/woopayments-critical-flows/flows/mo06-evidence.py
python3 tools/woopayments-critical-flows/test-mo06-evidence.py
python3 tools/woopayments-critical-flows/test-runner.py
```

Live verification will run the filtered flow through the authoritative runner:

```bash
tools/woopayments-critical-flows/run.sh \
  --store both \
  --layer deterministic \
  --flow MO-06-refund-failure-handling
```

Because runner and evidence tooling change, the full harness self-test is also
required:

```bash
tools/woopayments-critical-flows/run-self-tests.sh
```

Finally, run Markdown lint, `git diff --check`, and an independent code-review
subagent. Address all critical and major findings before committing the logical
MO-06 implementation package.

## Documentation and Handoff

After live runner verification:

- record the exact archive path and manifest SHA-256 digest;
- update the README and coverage ledgers with the observed per-store and parity
  verdicts;
- change `matrix.tsv` only if the strict `PASS` rule is satisfied;
- document any functional `FAIL` as a production work item without changing
  product code in this package;
- quote the final full-scope `run.sh` result required by the harness objective;
  and
- report the implementation commit range.

The independent review and final handoff must assess the evidence boundary, not
only the executable code. A green script exit without a current, verified
manifest is not accepted coverage.
