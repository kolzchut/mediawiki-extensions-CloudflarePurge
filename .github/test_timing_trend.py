"""Tests for timing-trend.py, run by CI's timing-trend job before it judges
anything, and locally with `python3 -m unittest discover -s .github`.

The two that matter most are the pair in TrendTest: a series that slides
slowly must be flagged while most of the slack is left, and a stable but
noisy one must never be. Either half alone proves nothing.
"""

import importlib.util
import io
import json
import os
import random
import subprocess
import sys
import tempfile
import unittest
from contextlib import redirect_stdout

HERE = os.path.dirname(os.path.abspath(__file__))


def _load(name):
    spec = importlib.util.spec_from_file_location(name.replace("-", "_"),
                                                  os.path.join(HERE, name + ".py"))
    mod = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(mod)
    return mod


trend = _load("timing-trend")
margins = _load("timing-margins")


def series(used_values, budget=0.6, key="PHP 8.3 / 0.6s"):
    return [{"series": key, "budget": budget, "elapsed": budget + u * trend.SLACK,
             "run": i} for i, u in enumerate(used_values)]


def first_alarm(values):
    """Index of the first run whose (history, current) judgement is EROSION."""
    for i in range(len(values)):
        if trend.judge(series(values[: i + 1]))["verdict"] == "EROSION":
            return i
    return None


class TrendTest(unittest.TestCase):
    def test_slow_erosion_warns_early(self):
        # 2% of the slack (40ms) lost per run, from a flat start: the fixed
        # 60% tripwire would first fire at run 30.
        values = [0.0] * 10 + [0.02 * k for k in range(1, 40)]
        alarm = first_alarm(values)
        self.assertIsNotNone(alarm)
        self.assertLess(values[alarm], 0.30, f"alarmed only at {values[alarm]:.0%}")
        self.assertLess(values[alarm], margins.WARN_AT / 2)

    def test_very_slow_erosion_still_caught_by_level(self):
        # 0.5% per run never builds DRIFT_AT over a 20-run baseline, so the
        # level rule has to catch it, still at half the old tripwire.
        values = [0.005 * k for k in range(200)]
        alarm = first_alarm(values)
        self.assertIsNotNone(alarm)
        self.assertLess(values[alarm], margins.WARN_AT)

    def test_step_change_warns(self):
        # A 0.4s jump: one elevated run is an outlier, the second one makes
        # it the median of the last three.
        values = [0.0] * 12 + [0.2] * 3
        self.assertEqual(first_alarm(values), 13)

    def test_stable_noisy_series_never_warns(self):
        rng = random.Random(1049)
        values = [max(-0.05, rng.gauss(0.02, 0.03)) for _ in range(200)]
        self.assertIsNone(first_alarm(values))

    def test_single_outlier_does_not_warn(self):
        values = [0.0] * 15 + [0.5] + [0.0] * 5
        self.assertIsNone(first_alarm(values))

    def test_real_history_shape_is_ok(self):
        # What every run so far has measured: exactly the budget.
        self.assertEqual(trend.judge(series([0.0] * 8))["verdict"], "ok")

    def test_short_history_is_reported_not_judged_on_drift(self):
        self.assertEqual(trend.judge(series([0.0, 0.2, 0.2, 0.2]))["verdict"], "short")
        self.assertEqual(trend.judge(series([0.0, 0.0]))["verdict"], "short")
        # ...but level needs no history.
        self.assertEqual(trend.judge(series([0.4, 0.4, 0.4]))["verdict"], "EROSION")


class AnalyseTest(unittest.TestCase):
    def run_analyse(self, history, current, event):
        with tempfile.NamedTemporaryFile("r", suffix=".md") as summary, \
                redirect_stdout(io.StringIO()) as out:
            code = trend.analyse(history, current, event, summary.name)
            return code, out.getvalue(), summary.read()

    def test_erosion_fails_push_but_only_warns_on_pr(self):
        values = [0.0] * 12 + [0.2] * 3
        history, current = series(values[:-1]), series(values[-1:])
        code, out, summary = self.run_analyse(history, current, "push")
        self.assertEqual(code, 1)
        self.assertIn("::error::", out)
        self.assertIn("EROSION", summary)
        code, out, _ = self.run_analyse(history, current, "pull_request")
        self.assertEqual(code, 0)
        self.assertIn("::warning::timing trend EROSION", out)

    def test_stable_passes(self):
        code, out, summary = self.run_analyse(series([0.0] * 10), series([0.0]), "push")
        self.assertEqual(code, 0, out)
        self.assertIn("| PHP 8.3 / 0.6s | 11 |", summary)

    def test_no_current_measurement_fails_push(self):
        code, _, _ = self.run_analyse(series([0.0] * 10), [], "push")
        self.assertEqual(code, 1)

    def test_samples_file_round_trip(self):
        with tempfile.NamedTemporaryFile("w", suffix=".json", delete=False) as fh:
            json.dump({"history": series([0.0] * 10), "current": series([0.0])}, fh)
        try:
            with redirect_stdout(io.StringIO()):
                self.assertEqual(trend.main(["--samples", fh.name]), 0)
        finally:
            os.unlink(fh.name)


JUNIT = """<?xml version="1.0"?>
<testsuites><testsuite name="unit">
<testcase class="CloudflarePurgeDeadlineWallClockTest"
  name="testTheLadderReturnsWithinItsBudget with data set &quot;0.6s budget&quot;" time="{a}"/>
<testcase class="CloudflarePurgeDeadlineWallClockTest"
  name="testTheLadderReturnsWithinItsBudget with data set &quot;1.6s budget&quot;" time="{b}"/>
</testsuite></testsuites>
"""


class ContractTest(unittest.TestCase):
    """The trend reads what timing-margins.py writes. Run the real script and
    parse its real output, so a reworded line fails here."""

    def annotations(self, a, b):
        with tempfile.NamedTemporaryFile("w", suffix=".xml", delete=False) as fh:
            fh.write(JUNIT.format(a=a, b=b))
        try:
            env = {k: v for k, v in os.environ.items() if k != "GITHUB_STEP_SUMMARY"}
            out = subprocess.run([sys.executable, os.path.join(HERE, "timing-margins.py"),
                                  fh.name], capture_output=True, text=True, env=env,
                                 check=True).stdout
        finally:
            os.unlink(fh.name)
        # GitHub stores the text after `::level::` as the annotation message.
        return [line.split("::", 2)[2] for line in out.splitlines() if line.startswith("::")]

    def test_margins_output_parses(self):
        parsed = [trend.parse_annotation(m) for m in self.annotations(0.71, 2.9)]
        self.assertEqual(parsed, [(0.6, 0.71), (1.6, 2.9)])

    def test_implausibly_fast_is_not_a_sample(self):
        parsed = [trend.parse_annotation(m) for m in self.annotations(0.1, 1.6)]
        self.assertEqual(parsed, [None, (1.6, 1.6)])

    def test_stored_annotation_text(self):
        # Verbatim from check run 112877513239 (run 37646205341).
        self.assertEqual(trend.parse_annotation(
            "1.6s budget: 1.60s elapsed, ceiling 3.60s, 2.00s spare (0% of slack used)"),
            (1.6, 1.6))
        self.assertIsNone(trend.parse_annotation("0.6s budget: skipped, no measurement taken"))

    def test_series_key(self):
        self.assertEqual(trend.series_key("PHP 8.1 (lint + tests; no phpcs/minus-x)", 0.6),
                         "PHP 8.1 / 0.6s")
        self.assertIsNone(trend.series_key("Timing trend", 0.6))

    def test_slack_matches(self):
        self.assertEqual(trend.SLACK, margins.CEILING_SLACK)


if __name__ == "__main__":
    unittest.main()
