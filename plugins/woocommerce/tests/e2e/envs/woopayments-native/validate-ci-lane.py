#!/usr/bin/env python3
import pathlib
import posixpath
import sys

import yaml


PERFORMANCE_STEP_NAME = "Measure native payments performance subset"
PERFORMANCE_HARNESS_PATH = (
    "plugins/woocommerce/tests/e2e/envs/woopayments-native/perf-compare.sh"
)
PERFORMANCE_HARNESS_BASENAME = "perf-compare.sh"
PERFORMANCE_COMMAND = (
    f"bash {PERFORMANCE_HARNESS_PATH} "
    '--mode ci --store-url "$E2E_WOOPAYMENTS_NATIVE_STORE_URL" '
    '--wp-env-config "$E2E_WOOPAYMENTS_WP_ENV_CONFIG" --wp-env-service cli '
    '--output "$RUNNER_TEMP/woopayments-native-perf.tsv"'
)
PERFORMANCE_ARTIFACT_PATH = "${{ runner.temp }}/woopayments-native-perf.tsv"
PERFORMANCE_ARTIFACT_UPLOAD_ACTION = (
    "actions/upload-artifact@043fb46d1a93c77aae656e7c1c64a875d1fc6a0a"
)


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
if "E2E_WOOPAYMENTS_DIAGNOSTICS_DIR" in environment:
    fail("diagnostics directory must not be set at job level")
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

readonly_steps = [
    step
    for step in job.get("steps", [])
    if isinstance(step, dict) and "test:e2e:with-env" in step.get("run", "")
]
if len(readonly_steps) != 1:
    fail("secretless readonly project must run exactly once")
performance_steps = [
    step
    for step in steps
    if isinstance(step, dict) and step.get("name") == PERFORMANCE_STEP_NAME
]
if len(performance_steps) != 1:
    fail("performance subset must run exactly once")
performance_step = performance_steps[0]
if performance_step.get("run") != PERFORMANCE_COMMAND:
    fail("performance subset command must match exactly")
performance_harness_steps = [
    step
    for step in steps
    if isinstance(step, dict)
    and any(
        posixpath.basename(posixpath.normpath(token.strip("\"'")))
        == PERFORMANCE_HARNESS_BASENAME
        for token in step.get("run", "").split()
    )
]
if len(performance_harness_steps) != 1:
    fail("performance harness must run exactly once")
if "continue-on-error" in performance_step:
    fail("performance subset must not continue on error")
if "if" in performance_step:
    fail("performance subset must not have an if condition")
fixture_index = steps.index(fixture_steps[0])
performance_index = steps.index(performance_step)
readonly_index = steps.index(readonly_steps[0])
if not fixture_index < performance_index < readonly_index:
    fail("performance subset must run after the fixture and before Playwright")
if performance_index != fixture_index + 1:
    fail("performance subset must immediately follow provider fixture")
diagnostics_directory = "${{ runner.temp }}/woopayments-native-diagnostics"
for label, step in (
    ("readonly project", readonly_steps[0]),
    ("fixture audit", audit_steps[0]),
):
    step_environment = step.get("env", {})
    if step_environment.get("E2E_WOOPAYMENTS_DIAGNOSTICS_DIR") != diagnostics_directory:
        fail(f"{label} must receive its diagnostics path from runner temp")

artifact_upload_steps = [
    step
    for step in steps
    if isinstance(step, dict)
    and step.get("name") == "Upload Playwright results and fixture audit"
]
if len(artifact_upload_steps) != 1:
    fail("performance artifact upload step must run exactly once")
artifact_upload_step = artifact_upload_steps[0]
if artifact_upload_step.get("uses") != PERFORMANCE_ARTIFACT_UPLOAD_ACTION:
    fail("performance artifact upload must use the pinned upload action")
if artifact_upload_step.get("if") != "${{ always() }}":
    fail("performance artifact upload must use always()")
artifact_paths = artifact_upload_step.get("with", {}).get("path", "").splitlines()
if PERFORMANCE_ARTIFACT_PATH not in artifact_paths:
    fail("performance table must be uploaded from runner temp")

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
