# WooPayments pilot calibration notes

Durable, public-safe record of calibration blockers and ledger revisions
that the evidence JSON schema cannot carry. Newest entries first.

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
