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


class WorkflowAdmissionContextTests(unittest.TestCase):
    def run_validator_with_job_environment(
        self, value: str
    ) -> subprocess.CompletedProcess[str]:
        document = yaml.load(
            WORKFLOW.read_text(encoding="utf-8"), Loader=yaml.BaseLoader
        )
        document["jobs"]["woopayments-native-secretless"]["env"][
            "REGRESSION_PROBE"
        ] = value

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

    def test_rejects_runner_context_expression_spelling_variants(self) -> None:
        expressions = (
            "${{ runner.temp }}/diagnostics",
            "${{runner.temp}}/diagnostics",
            "${{   runner [ 'temp' ]   }}/diagnostics",
        )

        for expression in expressions:
            with self.subTest(expression=expression):
                result = self.run_validator_with_job_environment(expression)

                self.assertNotEqual(0, result.returncode)
                self.assertIn(
                    "job-level env REGRESSION_PROBE uses runner context unavailable "
                    "during workflow admission",
                    result.stderr,
                )

    def test_accepts_literal_runner_text_outside_an_expression(self) -> None:
        result = self.run_validator_with_job_environment(
            "runner.temp is literal documentation"
        )

        self.assertEqual(0, result.returncode, result.stderr)
        self.assertIn("WooPayments native CI workflow contract passed.", result.stdout)


if __name__ == "__main__":
    unittest.main()
