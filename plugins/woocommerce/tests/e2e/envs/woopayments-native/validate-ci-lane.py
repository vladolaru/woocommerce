#!/usr/bin/env python3
import pathlib
import sys

import yaml


def fail(message: str) -> None:
    raise SystemExit(f"WooPayments native CI contract failed: {message}")


workflow = pathlib.Path(sys.argv[1] if len(sys.argv) > 1 else ".github/workflows/ci.yml")
document = yaml.load(workflow.read_text(encoding="utf-8"), Loader=yaml.BaseLoader)
jobs = document.get("jobs", {})
job = jobs.get("woopayments-native-secretless")
if not isinstance(job, dict):
    fail("missing woopayments-native-secretless job")
serialized = yaml.safe_dump(job, sort_keys=True)
if "continue-on-error" in serialized:
    fail("lane must be blocking")
if "secrets." in serialized:
    fail("lane must not consume repository or provider secrets")
environment = job.get("env", {})
if environment.get("E2E_WOOPAYMENTS_NATIVE") != "true":
    fail("native runtime opt-in must be explicit")
if environment.get("E2E_WOOPAYMENTS_NATIVE_FIXTURE") != "true":
    fail("secretless fixture opt-in must be explicit")
if environment.get("E2E_WOOPAYMENTS_WP_ENV_CONFIG") != ".wp-env.e2e.json":
    fail("lane must select the E2E wp-env config explicitly")
runs = "\n".join(
    step.get("run", "") for step in job.get("steps", []) if isinstance(step, dict)
)
for required in (
    "wp-env:e2e start",
    "fresh-activation-smoke.sh",
    "woopayments-native-readonly",
    "woopayments-native-extension-compat",
    "--project=woopayments-native-ci-profile-skips",
    "assert-ci-fixture-clean.sh",
    "bundle-size-gate.sh capture",
    "bundle-size-gate.sh compare",
    "woocommerce-payments/releases/download/10.8.0",
    "sha256sum --check",
):
    if required not in runs:
        fail(f"missing blocking command: {required}")
for forbidden in (
    "wp-env start",
    "install-ci-fixture.sh default",
    "install-ci-fixture.sh e2e",
    "--project=woopayments-native-provider",
):
    if forbidden in runs:
        fail(f"wp-env commands must use the single explicit E2E config: {forbidden}")

smoke_steps = [
    step
    for step in job.get("steps", [])
    if isinstance(step, dict) and "fresh-activation-smoke.sh" in step.get("run", "")
]
if len(smoke_steps) != 1:
    fail("fresh activation must run exactly once")
smoke_run = smoke_steps[0].get("run", "")
if "install-ci-fixture.sh" in smoke_run:
    fail("connected-account fixture must not be installed before fresh activation")
fixture_steps = [
    step
    for step in job.get("steps", [])
    if isinstance(step, dict) and "install-ci-fixture.sh" in step.get("run", "")
]
if len(fixture_steps) != 1:
    fail("provider fixture must be installed exactly once after activation")
steps = job.get("steps", [])
if steps.index(fixture_steps[0]) <= steps.index(smoke_steps[0]):
    fail("provider fixture must be installed after fresh activation")

audit_steps = [
    step
    for step in job.get("steps", [])
    if isinstance(step, dict) and "assert-ci-fixture-clean.sh" in step.get("run", "")
]
if len(audit_steps) != 1 or audit_steps[0].get("if") != "${{ always() }}":
    fail("fixture audit must run exactly once with always() after Playwright")
if "test:e2e:with-env" in audit_steps[0].get("run", ""):
    fail("fixture audit must not share Playwright's fail-fast shell step")

evaluation = jobs.get("evaluate-project-jobs", {})
needs = evaluation.get("needs", [])
if "woopayments-native-secretless" not in needs:
    fail("Evaluate Project Job Statuses does not depend on the lane")
evaluation_runs = "\n".join(
    step.get("run", "")
    for step in evaluation.get("steps", [])
    if isinstance(step, dict)
)
if "needs.woopayments-native-secretless.result" not in evaluation_runs:
    fail("aggregate status does not fail on the lane result")

print("WooPayments native CI workflow contract passed.")
