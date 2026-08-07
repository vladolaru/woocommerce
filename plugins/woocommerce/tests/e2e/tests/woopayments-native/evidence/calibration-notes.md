# WooPayments pilot calibration notes

Durable, public-safe record of calibration blockers and ledger revisions
that the evidence JSON schema cannot carry. Newest entries first.

## 2026-08-07 — guest-save family smoke

Stable reference: `calibration-notes:2026-08-07:guest-save-family-smoke`.

Second family executed under the bucket-A family-smoke acceptance: the
guest-save UI absence family, one contract. The row reopens from its
native-readiness deferral and takes the lower-layer disposition, with
`tests/woopayments-native/shopper/saved-methods.spec.ts` as the approved family
smoke target and the native token-service PHPUnit coverage retained as
lower-layer evidence.

The packet's unlock decision asked for approved target/owner, native
readiness, a provider-free guest fixture, a semantic Core save-control
adapter, zero-dispatch and local-record counters, a fresh anonymous context,
and proof that Card stays operable while credential persistence is
unavailable. All are satisfied by the smoke. The single provider-free test
establishes its own run-stable product and customer idempotently over REST,
asserts the store preconditions the absence claim depends on (gateway
enabled, saved cards enabled, guest checkout permitted), then proves both
directions on the blocks checkout: a logged-in customer sees exactly the
semantic save control (the positive control that keeps the absence oracle
non-vacuous), and a fresh anonymous guest gets a visible, mounted card payment
surface with zero accessible save controls, zero persistence-implying copy,
zero Store API checkout dispatches, and unchanged order and token
cardinalities. The context-restoration and quarantine machinery the packet
described is deliberately replaced, by owner decision, with idempotent setup
and context teardown, because the standing native store is dedicated to this
programme and disposable.

The oracle was mutation-checked in both directions before closure: forcing
the blocks save-control eligibility open for guests fails the zero-count
assertion with a received count of one, and disabling saved cards fails
loudly at the REST precondition rather than letting the absence pass
vacuously.

## 2026-08-07 — switcher family smoke

Stable reference: `calibration-notes:2026-08-07:switcher-family-smoke`.

The programme owner settled the bucket-A acceptance decision on family-level UI
smokes: partial contracts whose core logic is already proven natively receive
one thin browser smoke per feature family rather than one migrated test per
row. The shopper currency-switcher family is the first family executed under
that decision. Its four contracts — switcher visibility on the storefront, and
currency switching at the product, cart, and checkout pages — reopen from their
native-readiness deferral and take the lower-layer disposition, with
`tests/woopayments-native/shopper/multi-currency.spec.ts` as the approved
family smoke target and the native multi-currency frontend-prices PHPUnit
coverage retained as lower-layer evidence.

The deferral packets' unlock decisions asked for an approved target and owner,
native readiness, a deterministic product/rate/placement graph, and semantic
per-surface assertions. All are satisfied by the smoke; the byte-exact raw
snapshot and restoration machinery those decisions also described is
deliberately replaced, by owner decision, with idempotent REST setup, because
the standing native store is dedicated to this programme and disposable. The
smoke establishes its own state on every run: a manual EUR rate of 0.80 with
rounding and charm pinned to zero, enabled currencies reduced to exactly USD
and EUR, the native switcher block placed in both the theme header and the
WooCommerce checkout-header template parts, and one run-stable USD 10.00
virtual product, so USD 10.00 converts to exactly EUR 8.00 on every surface.

The smoke asserts in the test body per context — one keyboard-operable
Currency combobox visible and unique on the shop page, conversion proven at
the product, cart, and checkout surfaces, and the EUR selection persisting
across a query-free request — replacing the client suite's afterEach-only
oracle. The native REST surface can only re-assert availability for a currency
that is already available (both multi-currency routes validate against
available currencies, and this store caches no provider rates), so the run
fails loudly at its precondition if the store ever loses the family state
rather than passing vacuously; this was verified by mutation before closure.

The disabled-side contract (switcher absent when multi-currency is disabled)
is not part of this family smoke and remains deferred in its packet.

Stable reference: `calibration-notes:2026-08-06:duplicate-contract-retirement`.

The ledger owner granted retirement authority for the twelve contracts gated on
`ambiguous-decision:PILOT-RETIREMENT-AUTHORITY`, resolving that gate. Each retired
contract names the retained contract that already carries it, recorded in
`evidence/duplicate-contract-retirement.json`.

The twelve divide into duplicate release-zip project instances of the retained
basic-project smokes, card-variant duplicates where the parameterized 3DS and 3DS2
iterations repeat a card-agnostic action already proven by the retained basic
iteration, the legacy Storefront sidebar switcher whose shopper-visible behaviour
the retained shopper switcher contract proves, and the standalone editor
authentication setup row, which is a harness fixture with no independent
WooPayments outcome.

A thirteenth contract originally proposed for retirement, the non-WooPayments
gateway add-payment-method isolation row, was retained instead: its client test
never exercised the titled behaviour, so the contract had never been proven
anywhere. It is now covered natively by
`WooPaymentsAddPaymentMethodIsolationTest`.

Retirement carries no reviewed source bundle and no verification run, so these
rows close through the retirement branch of the migration evidence schema rather
than by manufacturing closure reviews.

## 2026-08-06 — card protection execution budget

Stable reference: `calibration-notes:2026-08-06:card-protection-execution-budget`.

The ledger owner authorized exactly two provider executions for this slice,
rather than the single execution the previous slice carried.

The first re-proves the generalized strict-false basic-card scenario. Its
closure was re-established on 2026-08-05 at bundle SHA-256
`60fdf437783b3d2dbf5d935140bcfa8f5527de515bf51df2bcb51ab4c053cd91`, but its
provider verification was retained from the 2026-08-04 run, which exercised the
pre-generalization bytes. Twelve fresh role reviews approved the current
bundle, and the checkout, provider and record readers are byte-identical, so
the closure was defensible; it was nevertheless a closure whose live proof came
from other bytes. This execution rebinds it to a run of its own bytes.

The second executes the protected basic-card contract, whose only prior
execution stopped before any mutation.

The previous execution was lost to an approval-fixture field-name error: two
required capabilities were written to a non-schema field while the real
allowlist kept three values, and the run failed at the first missing capability
after the budget was spent. Two harness changes now make that class of error
cheap. The approval parser rejects any field outside its schema, so a
misspelled key fails at parse time. A capability preflight asserts the entire
required set against the approval before a provider interval opens, and reports
every missing capability at once.

The approved capability set for both executions is `product/payment`,
`basic-card`, `basic-card-entry`, `card-testing-protection-setting`, and
`classic-checkout-page`. Each execution is a single invocation with one worker,
zero retries, and no replay, under one exclusively owned listener.

## 2026-08-05 — false basic-card closure scheduled for current bytes

The native protection-false basic-card contract is reopened from
`closed / supported` to `implemented / not-assessed` before changing either
pinned source file. Its current closure bundle SHA-256 is
`80bfb8c4f8007723ee9947d157a5bbc41d2f99494bb7179781788f94bb74e892`.
The selected protection-true slice must generalize the shared
`card-payment.ts` scenario and re-express the false composition through the new
definition, so the retained closure must be reviewed and rebound to the future
current bytes.

The overlapping saved-token files `drivers/checkout.ts`,
`provider-evidence.ts`, and `record-evidence.ts` remain byte-identical. The
false contract keeps its own immutable provider execution and receives fresh
provider-free regression proof plus current-bundle reviews; it does not borrow
the selected protection-true payment and no second provider payment is
authorized. If that evidence cannot truthfully support current-byte reclosure,
the false row will be deferred rather than rerun.

No scenario, pilot, driver, or evidence-reader byte changed in this back-edge.
No browser, listener, store, account, option, session, cart, product, order,
provider, lock, journal, or quarantine state changed. The selected true row
remains `specified`; the other four owner-authorized card rows remain deferred
and untouched.

## 2026-08-05 — native protected basic-card prerequisites calibrated

Stable reference:
`calibration-notes:2026-08-05:native-protected-basic-card-prerequisites-calibrated`.

The owner decision dated 2026-08-04 authorizes the exact native protected
basic-card contract, the accepted `card-payment.ts` target, and owner
`autonomous-run:pilot-calibration`. The standing-store Card, test-mode, USD,
callback, account, provider-writer, and exclusive-listener readiness recorded
on 2026-08-03 remains current at WooCommerce commit
`f606e403bcd97ecbe2b46926d96a0743fb2e484c`, rolling read-only WooPayments
reference `265e275802e9d8cadefe61c68c492911d14a1534`, and rolling read-only
WooCommerce Subscriptions reference
`0887151df977fd7e0454c55b2b49984c545df94a`.

Fresh read-only calibration found an authoritative account cache whose
effective card-testing-protection state is strict false, no force-override
option, and no pre-existing marker-owned Classic checkout page. The repository
already provides the bounded resource-lock, restoration-journal, submission-
journal, quarantine, raw WordPress option, WooCommerce session, native fraud-
service, Classic gateway bridge, order, and provider-evidence primitives needed
to implement an exact raw-state controller and one-shot Classic adapter. The
current environment has no active provider listener and no active lock,
restoration journal, submission attempt, or quarantine entry. One provider
writer and one exclusively owned listener remain authorized for the later
single selected-row execution.

This calibration establishes pre-implementation availability under the
required reopen-first lifecycle. It does not claim that the strict-true
controller, Classic driver, selected payment, immutable evidence, restoration,
or reviews already exist; those remain mandatory before the row can advance
beyond `specified`. No browser or provider mutation ran, and no store,
account, protection, gateway, session, cart, product, order, listener, lock,
journal, or quarantine state changed.

## 2026-08-03 — native standing store calibrated

Stable reference: `calibration-notes:2026-08-03:native-standing-store-calibrated`.

The calibrated WooCommerce commit is
`4a96a82c76e113bb7a47aa4052beeb51ce965c4a`. Its rolling, read-only
WooPayments reference is `265e275802e9d8cadefe61c68c492911d14a1534`,
and its rolling, read-only WooCommerce Subscriptions reference is
`0887151df977fd7e0454c55b2b49984c545df94a`. There is no pinned
Subscriptions provisioner artifact; the same clone is mounted by design. The
ledger SHA-256 is
`285080c37e06ab087d83d0865b8c99bbd188e67325ccffdbeb53be511e4a2402`.

The public-safe identity triple is site URL
`http://store8889.localhost:8889`, WPCOM blog ID `4`, and account identity
`redacted:account:sha256:3e5fe6b30db44a9921dd5141bca73e4a1dc99add811541b56c65df558f5eb578`.
Store doctor confirmed the exact blog, domain, and token match with the current
adapter loaded. Core-native onboarding adopted the already-dedicated non-live
test-drive account without replacement or reset. Runtime ownership is native
and enabled, the standalone client is inactive, the kill switch is false, the
gateway is enabled in test mode, and Card is enabled.

The callback probe confirmed the exact blog `4` and store, Jetpack capability
authentication, and registered, reachable, successful delivery with
`provider_write=false`. The repository's real
`assertRuntimeReady(... requireCallback:true)` passed against the
independent private identity. Public-safe private artifact references are:

- `redacted:runtime-status:sha256:9d3e31177d68a8b4ddd4ce6eb8a8406b47387a0a9184a2ebe1c6272bf8122bdb`
- `redacted:callback-probe:sha256:b7809b0a8efd7a42ade37661dca634555887130134a4dd0d670b5800f1c1b5e8`
- `redacted:readiness-assertion:sha256:4cee6e6c92ba77c388923c7839a9a6c2e8698a6c30ceaef697a8c8facd85e754`
- `redacted:owned-listener-record:sha256:f96865031dd695dbdf3361c96e9d34e3d756f47794c4b2325491ba37d26ffa3e`
- `redacted:final-zero-listener-scan:sha256:23d2b94ccc5b4c8d15d4e281f78d87cd8df13a65959f9a5641bf832dc4c6ef38`

A pre-existing listener was stopped by exact identity under dated authority.
One owned, PID-recorded wrapper and direct child remained identity-stable
through the callback proof. The owned listener was then stopped, and immediate
and delayed global scans both found zero listeners.

No checkout, payment, or provider mutation ran. No account was reset or
deleted, no shared environment was reset or reseeded, the `:8082` standalone
store received no write, no other repository was changed, and no ledger row
transitioned. The ledger remains exactly `2 closed / 179 deferred`. Browser
inspection reported one non-blocking missing WooCommerce Subscriptions
`build/admin.css` local build artifact; native UI, state, and proofs remained
complete, and the read-only clone was untouched. No Core-native defect was
found.

## 2026-08-02 — historical default-token provider evidence deferred

The dedicated historical default-token transition was implemented at commit
`0ddb1576e51c174b2034a1068e08dac7f0c27edf` and invoked exactly once as run
`historical-default-20260802`, with one worker and zero retries. It used the
approved deterministic WooPayments 10.5.0 seed with transport SHA-256
`899fea3b8594b6823a9454572404bf3736d0c4ad4713e4a60abd28575488f178`
and canonical tar SHA-256
`8c4cbfe257f23ab19bfe0cca4c4de5b1f76c1d3658048847e72ab55009583b1b`.
The retained test reached `1 passed`; disposable teardown completed; the
run-owned listener observed 17 of 17 HTTP 200 deliveries; and no transition
lease, journal, or quarantine residue remained. The public-safe run reference
is `redacted:transition-log:sha256:2629999777465229b21805f519085a26c960c855983615c4f0d4e3d91b924c23`.

Closure is nevertheless deferred. A separately owned listener appeared after
the exact single-listener preflight and remained active during part of the
provider interval, so exclusive webhook delivery attribution cannot be
established. The run-owned listener was stopped by exact ownership; the
separately owned process was left untouched. The provider mutation was not
rerun. The exact row is deferred as `blocked-environment` under
`PILOT-PROVIDER-LISTENER-EXCLUSIVITY`; the smallest unlock is a fresh
authorized migration run with one exclusively owned listener maintained for
the full provider interval.

## 2026-08-01 — saved-token closures re-established under narrow driver bundles

The authorized run `slice1-reclose-20260801222519` executed exactly once and
passed its one test with one worker and zero retries. Three fresh closure
reviews in the `code`, `e2e-tests`, and `reliability` roles approved the exact
six-file behavior bundle at source SHA-256
`ae55a768a61a02394d068d0e26d82c21a65a37fa5e954cde218be27b402ed54e`.
The run used the approved deterministic WooPayments 10.5.0 seed with transport
SHA-256
`899fea3b8594b6823a9454572404bf3736d0c4ad4713e4a60abd28575488f178`
and canonical tar SHA-256
`8c4cbfe257f23ab19bfe0cca4c4de5b1f76c1d3658048847e72ab55009583b1b`.

The exact saved cards were cleaned up, ephemeral teardown completed, and no
active lock, journal, or quarantine residue remained. All 21 listener
deliveries returned HTTP 200. The Task-owned Transact listener was then
stopped, and no listener process remains. The retained public-safe run
reference is `redacted:transition-log:sha256:39edea9135787e7079b60a70e6053a46b47ab420520e84466238a68e9ad4c332`.
Exactly the Classic and Blocks basic saved-token rows were reclosed; no other
contract changed state.

## 2026-08-01 — deterministic transition seed baseline replaced

The explicit user authorization `Replace the missing baseline` approves the
deterministic replacement for the unavailable transition seed. The new
compressed transport SHA-256 is
`899fea3b8594b6823a9454572404bf3736d0c4ad4713e4a60abd28575488f178`,
which supersedes the prior transport hash
`dc69d2cbb1ad73ce54ed585a3d8aebfb1085cd4dd59fb6a55e954309dcb9b186`.
Its uncompressed canonical tar SHA-256 is
`8c4cbfe257f23ab19bfe0cca4c4de5b1f76c1d3658048847e72ab55009583b1b`
under archive profile `git-sha1-fixed-pax+gzip-n9-v1`.

The immutable payload remains WooPayments 10.5.0 from source commit
`a1f755fc903966387f8629f78f75976ac8d2016e`. Its Composer lock SHA-256 is
`d345f41ee68fc121f00f6f7ca713fd0cb0c2764e844b3f102c9e12eefa216e8e`
with 15 production packages. Its frontend lock SHA-256 is
`6e279cfadb1851486976f67a72a11bc9ea36fa62c7f74d31b4d0d73c006b34b1`.
The four production bundle hashes are:

- `dist/index.js`: `065744d76e24ef28d1cb824301ef22d0816426d7a3f0fbe2e549c210b2e78ed7`
- `dist/index.css`: `f9e9ac58624842d627efe38d95710beaf17bae7c760d0c2736b396c3d208266a`
- `dist/checkout.js`: `4257da2777c531a375cf579ceb0ae9f69eee7d6157783ac9aa8a93fec054c17c`
- `dist/blocks-checkout.js`: `0ec1ee938fe1727eb3be3bdc6768faf0465c4585f66f40f12909f2c08bd33d57`

The observed six-value build toolchain was Composer
`Composer version 2.9.5 2026-01-29 11:40:53`, Git `git version 2.54.0`,
gzip `Apple gzip 479`, Node `v20.11.1`, npm `10.2.4`, and zlib
`1.2.13.1-motley-5daffc7`. Two independent builds produced byte-identical
archives and byte-identical schema-2 manifests. Independent checks reproduced
both hashes, lock and bundle hashes, package count, canonical plugin entry,
read-only modes, and absence of Git metadata and `node_modules`. The real
provisioner's read-only `plan` accepted the first pair without mutating its
empty workspace. Task 10 and its provider-backed transition scenario have not
run yet.

## 2026-08-01 — Slice 1 deferred on seed hash mismatch

The automated provider preflight rebuilt the pinned WooPayments 10.5.0 seed
from commit `a1f755fc903966387f8629f78f75976ac8d2016e` after the prior temporary
artifacts were absent. The tracked builder produced archive SHA-256
`168464e960225fcc570176a64d6e741fe9e3c5a5f64049e329812629c116a3a1`,
which differs from the required recorded hash
`dc69d2cbb1ad73ce54ed585a3d8aebfb1085cd4dd59fb6a55e954309dcb9b186`.
The manifest self-consistently binds the new archive and the approved version
and commit, but exact artifact provenance cannot be assumed across the hash
mismatch. No provider scenario ran and no Transact listener was changed.

The affected scope is Slice 1 Tasks 10–11: the single transition run and the
two saved-token re-closures remain blocked; completed Slice 0 work is
unaffected. The smallest unlock is to reproduce or explain the recorded seed
hash from the tracked builder and pinned toolchain, or explicitly approve a
new immutable seed baseline after comparing artifact contents and build
provenance. Then restart Task 9 from a clean tracked state.

## 2026-07-31 — driver decomposition and bundle policy

The pilot fixture was decomposed into a provider-write session plus
per-feature driver modules under `utils/woopayments-native/drivers/`.
Closure evidence bundles now attest the target spec plus its transitive
static relative behavior imports and exports (drivers and oracle readers);
controller infrastructure (locks, journal, quarantine, session, readiness)
is attested by the controller unit suite instead of per-closure. The validator
enforces bundle coverage for every verified or closed row. Editing a driver
reopens only the closures that statically import or re-export it; editing
infrastructure reopens none but must keep the controller suite green.

## 2026-07-30 — saved-token closures reopened after capture safety fix

The shared fixture changed after the single transition calibration. Manual
capture now retains a durable attempt from before Apply through exact provider
proof and quarantines ambiguity. The saved-token behavior remains implemented,
but current-byte closure needs a separately authorized transition run and fresh
reviews.

## 2026-07-30 — saved-token closures re-established

One serialized, zero-retry transition run re-established the Classic
and Blocks saved-token closures against the immutable WooPayments 10.5.0
seed. Schema-v2 row-scoped evidence binds the passing run and three fresh
`code`, `e2e-tests`, and `reliability` approvals to the retained source
bundle at commit `5f68bfd468e30a121fc1cde550636b21f0a8dfd2`.

## 2026-07-29 — saved-token closures reopened

The two saved-token contracts (classic and Blocks saved-card payment)
were reopened from `closed` to `implemented`. Their evidence attested
the pilot source bundle at an earlier commit, while later commits
changed the fixture and spec; a closure must attest the currently
retained bytes. The pilots remain implemented. They re-close after one
clean transition run and fresh closure reviews under the row-scoped,
current-byte evidence schema.

## Standing blockers

- Standing-native runtime readiness failed its read-only preflight
  before any standing-store native pilot ran. Manual capture and the
  other partial contracts therefore remain open (`specified`), which is
  the intended fail-closed outcome, not a regression.
- Provider-backed Playwright projects have no external CI worker
  allocation yet. Provider and transition pilots run locally only, one
  worker, zero retries.
