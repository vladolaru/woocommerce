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


if __name__ == "__main__":
    unittest.main()
