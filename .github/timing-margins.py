"""Report how much headroom the wall-clock deadline test had — and fail if it
did not run at all.

CloudflarePurgeDeadlineWallClockTest asserts that a purge bounded by an N
second budget returns in less than N + 2.0 seconds, measured on the real
clock through real cURL. That slack is a property of the machine, not of the
code, so on a shared CI runner it can erode without anything changing in the
extension. A test that only ever speaks by going red is one people learn to
ignore; this prints the margin on every run, and warns while the run is still
green, so the erosion is visible before it becomes a flaky gate.

This script is also the *third* of three guards, and the only one that covers
its case. The suite can lose its real-cURL coverage three ways:

  skipped  — the socket cannot be opened, or ext-curl is missing.
             Caught by failOnSkipped in phpunit.xml.dist.
  empty    — the bootstrap or the testsuite path stops finding anything.
             Caught by failOnEmptyTestSuite, which fires only at ZERO tests.
  missing  — the test file is deleted, renamed, or its class renamed, and the
             other 51 tests still pass. Caught by NOTHING else: PHPUnit
             reports `OK (51 tests, 148 assertions)` and exits 0. Verified by
             deleting the file. That is what the non-zero exit below is for.

A legitimate rename of the class trips it too — verified: renaming both file
and class leaves PHPUnit reporting a full `OK (53 tests, 160 assertions)` and
exit 0, with this script the only thing that notices. If you are renaming it
on purpose, update CLASS below in the same commit.

Times come from PHPUnit's JUnit log, which includes setUp and tearDown, so
the reported elapsed is an upper bound on what the test itself measured.

KNOWN LIMIT (a follow-up, not a fix): the escalation has no memory.
Annotations are per-run and nothing aggregates them, so a gradual slowdown
stays invisible until a single run crosses the threshold — by which point
most of the slack is already gone, and a warning notifies no one. Treat it as
a late tripwire, not the early-warning system the wording above suggests.
"""

import re
import sys
import xml.etree.ElementTree as ET

# Must track CloudflarePurgeDeadlineWallClockTest::testTheLadderReturnsWithinItsBudget.
CLASS = "CloudflarePurgeDeadlineWallClockTest"
CEILING_SLACK = 2.0
# Warn once the run has used this fraction of its slack.
WARN_AT = 0.6
# The test itself asserts elapsed > budget * 0.75, so a JUnit time below that
# cannot have come from a passing measurement.
FLOOR_FRACTION = 0.75

BUDGET = re.compile(r'"([0-9.]+)s budget"')


def main(path: str) -> int:
    root = ET.parse(path).getroot()
    measured, unmeasured = [], []

    for case in root.iter("testcase"):
        if case.get("class") != CLASS:
            continue
        found = BUDGET.search(case.get("name", ""))
        if not found:
            continue
        budget = float(found.group(1))
        # A skipped or errored case carries a JUnit time of ~0, which the
        # margin arithmetic would otherwise render as the most reassuring
        # line this script can print — "2.60s spare" for a run in which the
        # measurement does not exist. It is not a measurement; say so.
        for tag in ("skipped", "error"):
            if case.find(tag) is not None:
                unmeasured.append(f"{budget}s budget: {tag}, no measurement taken")
                break
        else:
            measured.append((budget, float(case.get("time", "0"))))

    for note in unmeasured:
        print(f"::warning::{note}")

    if not measured:
        # The blocking case. Everything above this line assumes the test ran;
        # if the JUnit log has no measurable case for it, the suite lost its
        # only real-request coverage and every other check stayed green.
        print(
            f"::error::{CLASS} produced no timing measurement in {path} — "
            "the suite's only real-cURL coverage did not run"
        )
        return 1

    for budget, elapsed in measured:
        ceiling = budget + CEILING_SLACK
        used = (elapsed - budget) / CEILING_SLACK
        line = (
            f"{budget}s budget: {elapsed:.2f}s elapsed, ceiling {ceiling:.2f}s, "
            f"{ceiling - elapsed:.2f}s spare ({used * 100:.0f}% of slack used)"
        )
        if elapsed < budget * FLOOR_FRACTION:
            # Faster than the test's own lower-bound assertion allows, so
            # something other than the deadline ended the attempt — a proxy
            # answering for the loopback socket does exactly this.
            print(f"::warning::{line} — implausibly fast, not a real measurement")
        elif used >= WARN_AT:
            print(f"::warning::{line}")
        else:
            print(f"::notice::{line}")
    return 0


if __name__ == "__main__":
    sys.exit(main(sys.argv[1]))
