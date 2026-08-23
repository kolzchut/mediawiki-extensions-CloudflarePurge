"""Report how much headroom the wall-clock deadline test had.

CloudflarePurgeDeadlineWallClockTest asserts that a purge bounded by an N
second budget returns in less than N + 2.0 seconds, measured on the real
clock through real cURL. That slack is a property of the machine, not of the
code, so on a shared CI runner it can erode without anything changing in the
extension. A test that only ever speaks by going red is one people learn to
ignore; this prints the margin on every run, and warns while the run is still
green, so the erosion is visible before it becomes a flaky gate.

Times come from PHPUnit's JUnit log, which includes setUp and tearDown, so
the reported elapsed is an upper bound on what the test itself measured.
"""

import re
import sys
import xml.etree.ElementTree as ET

# Must track CloudflarePurgeDeadlineWallClockTest::testTheLadderReturnsWithinItsBudget.
CEILING_SLACK = 2.0
# Warn once the run has used this fraction of its slack.
WARN_AT = 0.6

BUDGET = re.compile(r'"([0-9.]+)s budget"')


def main(path: str) -> int:
    root = ET.parse(path).getroot()
    rows = []
    for case in root.iter("testcase"):
        if case.get("class") != "CloudflarePurgeDeadlineWallClockTest":
            continue
        found = BUDGET.search(case.get("name", ""))
        if not found:
            continue
        budget = float(found.group(1))
        elapsed = float(case.get("time", "0"))
        rows.append((budget, elapsed, budget + CEILING_SLACK))

    if not rows:
        # Not a failure on its own — the unit job already fails on a skipped
        # or missing test — but say so rather than printing nothing.
        print("::warning::no wall-clock timing cases found in " + path)
        return 0

    for budget, elapsed, ceiling in rows:
        used = (elapsed - budget) / CEILING_SLACK
        line = (
            f"{budget}s budget: {elapsed:.2f}s elapsed, ceiling {ceiling:.2f}s, "
            f"{ceiling - elapsed:.2f}s spare ({used * 100:.0f}% of slack used)"
        )
        print(f"::warning::{line}" if used >= WARN_AT else f"::notice::{line}")
    return 0


if __name__ == "__main__":
    sys.exit(main(sys.argv[1]))
