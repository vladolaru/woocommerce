#!/usr/bin/env python3
import pathlib
import subprocess
import sys
import tempfile
import unittest

import yaml


SCRIPT_DIRECTORY = pathlib.Path(__file__).resolve().parent
VALIDATOR = SCRIPT_DIRECTORY / "validate-ci-lane.py"
WORKFLOW = SCRIPT_DIRECTORY.parents[5] / ".github" / "workflows" / "ci.yml"
PERFORMANCE_STEP_NAME = "Measure native payments performance subset"
PERFORMANCE_COMMAND = (
    "bash plugins/woocommerce/tests/e2e/envs/woopayments-native/perf-compare.sh "
    '--mode ci --store-url "$E2E_WOOPAYMENTS_NATIVE_STORE_URL" '
    '--wp-env-config "$E2E_WOOPAYMENTS_WP_ENV_CONFIG" --wp-env-service cli '
    '--output "$RUNNER_TEMP/woopayments-native-perf.tsv"'
)
PERFORMANCE_ARTIFACT_PATH = "${{ runner.temp }}/woopayments-native-perf.tsv"


class DiagnosticsPlacementTests(unittest.TestCase):
    def workflow_document(self) -> dict:
        return yaml.load(
            WORKFLOW.read_text(encoding="utf-8"), Loader=yaml.BaseLoader
        )

    def run_validator(self, document: dict) -> subprocess.CompletedProcess[str]:
        with tempfile.NamedTemporaryFile(
            mode="w", suffix=".yml", encoding="utf-8"
        ) as workflow:
            yaml.safe_dump(document, workflow, sort_keys=False)
            workflow.flush()
            return subprocess.run(
                [sys.executable, str(VALIDATOR), workflow.name],
                check=False,
                capture_output=True,
                text=True,
            )

    def test_rejects_any_job_level_diagnostics_binding(self) -> None:
        values = (
            "/literal/diagnostics",
            "${{ (runner).temp }}/diagnostics",
        )

        for value in values:
            with self.subTest(value=value):
                document = self.workflow_document()
                document["jobs"]["woopayments-native-secretless"]["env"][
                    "E2E_WOOPAYMENTS_DIAGNOSTICS_DIR"
                ] = value
                result = self.run_validator(document)

                self.assertNotEqual(0, result.returncode)
                self.assertIn(
                    "diagnostics directory must not be set at job level",
                    result.stderr,
                )

    def test_requires_exact_diagnostics_binding_on_both_consumers(self) -> None:
        mutations = (
            (
                "test:e2e:with-env",
                "${{ (runner).temp }}/woopayments-native-diagnostics",
                "readonly project",
            ),
            (
                "assert-ci-fixture-clean.sh",
                "/literal/woopayments-native-diagnostics",
                "fixture audit",
            ),
        )

        for command, value, label in mutations:
            with self.subTest(label=label):
                document = self.workflow_document()
                job = document["jobs"]["woopayments-native-secretless"]
                step = next(
                    item for item in job["steps"] if command in item.get("run", "")
                )
                step["env"]["E2E_WOOPAYMENTS_DIAGNOSTICS_DIR"] = value
                result = self.run_validator(document)

                self.assertNotEqual(0, result.returncode)
                self.assertIn(
                    f"{label} must receive its diagnostics path from runner temp",
                    result.stderr,
                )

    def test_ignores_unrelated_literal_and_expression_values(self) -> None:
        values = (
            "runner.temp is literal documentation",
            "${{ 'runner.temp is literal documentation' }}",
            "${{ github.event.runner.temp }}",
        )

        for value in values:
            with self.subTest(value=value):
                document = self.workflow_document()
                document["jobs"]["woopayments-native-secretless"]["env"][
                    "REGRESSION_PROBE"
                ] = value
                result = self.run_validator(document)

                self.assertEqual(0, result.returncode, result.stderr)
                self.assertIn(
                    "WooPayments native CI workflow contract passed.", result.stdout
                )


class PerformanceSubsetTests(unittest.TestCase):
    def workflow_document(self) -> dict:
        return yaml.load(
            WORKFLOW.read_text(encoding="utf-8"), Loader=yaml.BaseLoader
        )

    def run_validator(self, document: dict) -> subprocess.CompletedProcess[str]:
        with tempfile.NamedTemporaryFile(
            mode="w", suffix=".yml", encoding="utf-8"
        ) as workflow:
            yaml.safe_dump(document, workflow, sort_keys=False)
            workflow.flush()
            return subprocess.run(
                [sys.executable, str(VALIDATOR), workflow.name],
                check=False,
                capture_output=True,
                text=True,
            )

    def job(self, document: dict) -> dict:
        return document["jobs"]["woopayments-native-secretless"]

    def performance_step(self, document: dict) -> dict:
        for step in self.job(document)["steps"]:
            if step.get("name") == PERFORMANCE_STEP_NAME:
                return step

        fixture_index = next(
            index
            for index, step in enumerate(self.job(document)["steps"])
            if step.get("name") == "Install secretless provider fixture"
        )
        step = {"name": PERFORMANCE_STEP_NAME, "run": PERFORMANCE_COMMAND}
        self.job(document)["steps"].insert(fixture_index + 1, step)
        return step

    def artifact_upload_step(self, document: dict) -> dict:
        return next(
            step
            for step in self.job(document)["steps"]
            if step.get("name") == "Upload Playwright results and fixture audit"
        )

    def assert_rejected(self, document: dict, message: str) -> None:
        result = self.run_validator(document)

        self.assertNotEqual(0, result.returncode)
        self.assertIn(message, result.stderr)

    def test_requires_the_performance_step(self) -> None:
        document = self.workflow_document()
        job = self.job(document)
        job["steps"] = [
            step
            for step in job["steps"]
            if step.get("name") != PERFORMANCE_STEP_NAME
        ]

        self.assert_rejected(document, "performance subset must run exactly once")

    def test_rejects_duplicate_performance_harness_invocation(self) -> None:
        document = self.workflow_document()
        job = self.job(document)
        readonly_index = next(
            index
            for index, step in enumerate(job["steps"])
            if step.get("name") == "Run secretless readonly project"
        )
        job["steps"].insert(
            readonly_index,
            {
                "name": "Duplicate native payments performance subset",
                "run": PERFORMANCE_COMMAND,
            },
        )

        self.assert_rejected(document, "performance harness must run exactly once")

    def test_rejects_equivalent_duplicate_performance_harness_invocation(self) -> None:
        document = self.workflow_document()
        job = self.job(document)
        readonly_index = next(
            index
            for index, step in enumerate(job["steps"])
            if step.get("name") == "Run secretless readonly project"
        )
        job["steps"].insert(
            readonly_index,
            {
                "name": "Equivalent native payments performance subset",
                "run": (
                    "bash plugins/woocommerce/tests/e2e/envs/woopayments-native/"
                    "./perf-compare.sh --mode ci "
                    '--store-url "$E2E_WOOPAYMENTS_NATIVE_STORE_URL" '
                    '--wp-env-config "$E2E_WOOPAYMENTS_WP_ENV_CONFIG" '
                    '--wp-env-service cli '
                    '--output "$RUNNER_TEMP/woopayments-native-perf.tsv"'
                ),
            },
        )

        self.assert_rejected(document, "performance harness must run exactly once")

    def test_rejects_workspace_alias_duplicate_performance_harness_invocation(self) -> None:
        document = self.workflow_document()
        job = self.job(document)
        readonly_index = next(
            index
            for index, step in enumerate(job["steps"])
            if step.get("name") == "Run secretless readonly project"
        )
        job["steps"].insert(
            readonly_index,
            {
                "name": "Workspace native payments performance subset",
                "run": (
                    'bash "$GITHUB_WORKSPACE/plugins/woocommerce/tests/e2e/envs/'
                    'woopayments-native/perf-compare.sh" --mode ci '
                    '--store-url "$E2E_WOOPAYMENTS_NATIVE_STORE_URL" '
                    '--wp-env-config "$E2E_WOOPAYMENTS_WP_ENV_CONFIG" '
                    '--wp-env-service cli '
                    '--output "$RUNNER_TEMP/woopayments-native-perf.tsv"'
                ),
            },
        )

        self.assert_rejected(document, "performance harness must run exactly once")

    def test_rejects_malformed_performance_command(self) -> None:
        document = self.workflow_document()
        step = self.performance_step(document)
        step["run"] = PERFORMANCE_COMMAND.replace("--mode ci", "--mode local")

        self.assert_rejected(document, "performance subset command must match exactly")

    def test_requires_performance_step_after_fixture_and_before_readonly_project(self) -> None:
        document = self.workflow_document()
        step = self.performance_step(document)
        job = self.job(document)
        job["steps"].remove(step)
        readonly_index = next(
            index
            for index, candidate in enumerate(job["steps"])
            if candidate.get("name") == "Run secretless readonly project"
        )
        job["steps"].insert(readonly_index + 1, step)

        self.assert_rejected(document, "performance subset must run after the fixture and before Playwright")

    def test_requires_performance_step_immediately_after_fixture(self) -> None:
        document = self.workflow_document()
        job = self.job(document)
        fixture_index = next(
            index
            for index, step in enumerate(job["steps"])
            if step.get("name") == "Install secretless provider fixture"
        )
        job["steps"].insert(
            fixture_index + 1,
            {"name": "Intervening step", "run": "true"},
        )

        self.assert_rejected(
            document,
            "performance subset must immediately follow provider fixture",
        )

    def test_rejects_performance_step_bypass(self) -> None:
        document = self.workflow_document()
        step = self.performance_step(document)
        step["if"] = "${{ always() }}"

        self.assert_rejected(document, "performance subset must not have an if condition")

    def test_rejects_non_runner_temp_performance_output(self) -> None:
        document = self.workflow_document()
        step = self.performance_step(document)
        step["run"] = PERFORMANCE_COMMAND.replace(
            "$RUNNER_TEMP/woopayments-native-perf.tsv",
            "/tmp/woopayments-native-perf.tsv",
        )

        self.assert_rejected(document, "performance subset command must match exactly")

    def test_requires_performance_artifact_path(self) -> None:
        document = self.workflow_document()
        self.performance_step(document)
        artifact_upload = self.artifact_upload_step(document)
        artifact_upload["with"]["path"] = "plugins/woocommerce/tests/e2e/test-results"

        self.assert_rejected(document, "performance table must be uploaded from runner temp")

    def test_requires_unconditional_performance_artifact_upload(self) -> None:
        document = self.workflow_document()
        self.performance_step(document)
        self.artifact_upload_step(document)["if"] = "${{ success() }}"

        self.assert_rejected(document, "performance artifact upload must use always()")

    def test_requires_pinned_performance_artifact_uploader(self) -> None:
        document = self.workflow_document()
        self.performance_step(document)
        self.artifact_upload_step(document)["uses"] = (
            "actions/download-artifact@043fb46d1a93c77aae656e7c1c64a875d1fc6a0a"
        )

        self.assert_rejected(
            document,
            "performance artifact upload must use the pinned upload action",
        )


if __name__ == "__main__":
    unittest.main()
