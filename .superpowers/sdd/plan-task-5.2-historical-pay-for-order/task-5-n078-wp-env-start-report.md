# N-078 wp-env Start Diagnostics

Last updated: 2026-09-21 19:20 EEST

## Scope

Changed only the transition provisioner, its focused shell test, and focused fake wp-env/Docker adapters. No real wp-env, browser, provider, or WPCOM activity ran.

## Review-correction RED evidence

The independent review correctly identified that a boolean config check retried on unsafe identities, normal shell redirection could follow a dangling diagnostic-artifact symlink, and Compose enumeration status was ignored. The revised focused suite first failed untraced with `Transition did not preserve the first start status (got 0).`: the existing guard missed the pinned wp-env project identity, ran a second start, and converted the intended terminal `45` into success.

## GREEN behavior

- Each diagnostic command retains combined stdout and stderr through `set -C` no-clobber redirection, after rejecting existing files and dangling symlinks; the resulting artifact must be a regular `0600` file.
- The exact project path comes from the pinned `@wordpress/env` config implementation. Its home is canonically normalized, preventing `/var` and `/private/var` from becoming different Compose identities.
- The retry guard is tri-state: only a proven missing project, `wordpress-latest`, or `wp-config.php` permits one retry; symlink, non-directory, or unresolvable identity is terminal without another start.
- Terminal failures preserve exact `45` (first-only) and `46` (second attempt) statuses. Docker logs run only after successful Compose enumeration finds one exact regular Compose file under the exact project; absent, ambiguous, failed-enumeration, or unsafe ancestor cases make no Docker request.
- The focused fake adapters use the pinned `@wordpress/env` config loader rather than recreating the provisioner path algorithm.
- No readiness wait was added because this test-only work produced no retained live evidence that wp-env exits before its WordPress entrypoint.

## Verification

- The full untraced focused suite reached `provision-transition-store-real.sh reference fixture tests passed.` and exited 0 in a persistent shell session.
- Regression coverage includes exact preserved `45` and `46` statuses, safely absent `wordpress-latest`, absent and ambiguous Compose files, enumeration failure, symlinked `wordpress-latest`, and overwrite plus dangling-symlink inputs for both start and WordPress-log artifacts.
- `bash -n` exited 0 for `provision-transition-store-real.sh`, `provision-transition-store-real.test.sh`, `fake-transition-pnpm.sh`, and `fake-transition-docker.sh`.
- `git diff --check` exited 0.

## Concerns

No live start failure has yet supplied evidence for a readiness wait. Replay row 164 with the retained diagnostics and add no wait unless those artifacts prove the CLI returned before the exact WordPress entrypoint completed. The diagnostic-artifact pre-existence injection is restricted to `E2E_TRANSITION_TEST_MODE=1` and exists only to cover atomic creation before a command launches.
