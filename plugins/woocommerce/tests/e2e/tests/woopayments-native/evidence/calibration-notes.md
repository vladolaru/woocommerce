# WooPayments pilot calibration notes

Durable, public-safe record of calibration blockers and ledger revisions
that the evidence JSON schema cannot carry. Newest entries first.

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
