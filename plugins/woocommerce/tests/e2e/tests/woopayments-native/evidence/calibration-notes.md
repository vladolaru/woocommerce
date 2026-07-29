# WooPayments pilot calibration notes

Durable, public-safe record of calibration blockers and ledger revisions
that the evidence JSON schema cannot carry. Newest entries first.

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
