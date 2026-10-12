"""Compare this run's wall-clock margins against the workflow's own history,
and warn while most of the slack is still there.

timing-margins.py prints one annotation per measured budget on every run
("1.6s budget: 1.60s elapsed, ceiling 3.60s, ..."). Taken one run at a time
that is a late tripwire: it escalates only once a single run has used 60% of
the 2.0s slack, and nothing compares a run to last month's. But GitHub keeps
those annotations on each job's check run, long after the logs expire — so
the history this needs already exists. This script reads it back:

  history  — the timing annotations of the last HISTORY_RUNS push runs of
             ci.yml on kolzchut-main (merged code only: a PR cannot write
             into the baseline that judges it, and there is nothing to
             upload, store, expire or race).
  current  — the timing annotations of the run this job belongs to, PR or
             push, read the same way once the test jobs have finished.

Each series is one PHP job x one budget. For each it compares the median of
the last RECENT samples (current included) with the median of up to BASELINE
samples before them, and calls it EROSION when either

  drift — the recent median is DRIFT_AT of the slack above the baseline
          (0.3s): a step change or a slow slide, caught at about a quarter of
          the way to the old 60% tripwire; or
  level — the recent median has used LEVEL_AT of the slack (0.6s) whatever
          the history says: half of the old tripwire, and a slide too slow to
          ever show as drift still trips it.

Medians, so a single noisy run moves neither side. A series with fewer than
RECENT + BASELINE_MIN samples is reported as too short to judge drift.

WHO HEARS ABOUT IT. On a push to kolzchut-main, EROSION (or a history that
cannot be read, or a run whose test jobs passed yet left no measurement)
exits 1, which fails this job and sends GitHub's ordinary failed-run mail to
whoever merged — the same channel every other red on this branch uses. On a
pull request it is a ::warning and a job-summary table only: runner drift is
not the PR author's doing, and a red they cannot fix is how a check gets
ignored.

Nothing here is a schedule: the default branch is the upstream mirror, so a
cron trigger would never run this workflow. History therefore accrues at the
rate code is merged, which is the rate a regression could arrive at too.
"""

import argparse
import json
import os
import re
import statistics
import sys
import urllib.request
from concurrent.futures import ThreadPoolExecutor

SLACK = 2.0          # must match CEILING_SLACK in timing-margins.py
RECENT = 3           # samples in the "now" median, current run included
BASELINE = 20        # at most this many samples before them form the baseline
BASELINE_MIN = 5     # fewer than this and drift is not judged
DRIFT_AT = 0.15      # fraction of slack the recent median may rise over baseline
LEVEL_AT = 0.30      # fraction of slack the recent median may use outright
HISTORY_RUNS = 25    # push runs read back
WORKFLOW_FILE = "ci.yml"
BRANCH = "kolzchut-main"

# Written by timing-margins.py. test_timing_trend.py feeds that script's real
# output through this pattern, so a reworded line fails a test rather than
# quietly emptying every series.
LINE = re.compile(r"^([0-9.]+)s budget: ([0-9.]+)s elapsed, ceiling ")
JOB = re.compile(r"^PHP (\S+)")


def parse_annotation(message: str):
    """(budget, elapsed) from one timing-margins line, or None if the line is
    not a usable measurement (unmeasured, implausibly fast, unrelated)."""
    first = message.strip().splitlines()[0] if message.strip() else ""
    found = LINE.match(first)
    if not found or "implausibly fast" in first:
        return None
    return float(found.group(1)), float(found.group(2))


def series_key(job_name: str, budget: float):
    found = JOB.match(job_name)
    return f"PHP {found.group(1)} / {budget}s" if found else None


def used(sample) -> float:
    return (sample["elapsed"] - sample["budget"]) / SLACK


def judge(samples):
    """samples: one series, oldest first. Returns a dict describing it."""
    values = [used(s) for s in samples]
    out = {"n": len(values), "latest": values[-1] if values else None,
           "recent": None, "baseline": None, "verdict": "no-data", "why": ""}
    if len(values) < RECENT:
        out["verdict"] = "short"
        out["why"] = f"{len(values)} sample(s); need {RECENT} to judge level"
        return out
    recent = statistics.median(values[-RECENT:])
    out["recent"] = recent
    before = values[:-RECENT][-BASELINE:]
    reasons = []
    if recent >= LEVEL_AT:
        reasons.append(f"recent median uses {recent:.0%} of slack (limit {LEVEL_AT:.0%})")
    if len(before) >= BASELINE_MIN:
        baseline = statistics.median(before)
        out["baseline"] = baseline
        if recent - baseline >= DRIFT_AT:
            reasons.append(
                f"recent median {recent:.0%} is {recent - baseline:.0%} of slack above "
                f"the {len(before)}-run baseline {baseline:.0%} (limit {DRIFT_AT:.0%})"
            )
    if reasons:
        out["verdict"], out["why"] = "EROSION", "; ".join(reasons)
    elif out["baseline"] is None:
        out["verdict"] = "short"
        out["why"] = f"{len(before)} baseline sample(s); need {BASELINE_MIN} to judge drift"
    else:
        out["verdict"] = "ok"
    return out


def analyse(history, current, event, summary_path=None):
    """history/current: lists of {"series", "budget", "elapsed", "run"},
    history oldest first. Returns the exit code."""
    failures = []
    if not current:
        failures.append("this run left no timing measurement to compare")
        print("::warning::timing trend: no timing annotations found for this run's test jobs")

    keys = sorted({s["series"] for s in history} | {s["series"] for s in current})
    rows = []
    for key in keys:
        series = [s for s in history if s["series"] == key] + \
                 [s for s in current if s["series"] == key]
        verdict = judge(series)
        rows.append((key, verdict))
        if verdict["verdict"] == "EROSION":
            failures.append(f"{key}: {verdict['why']}")
            print(f"::warning::timing trend EROSION, {key}: {verdict['why']}")
        elif verdict["verdict"] == "short":
            print(f"::notice::timing trend, {key}: history too short ({verdict['why']})")
        else:
            print(f"::notice::timing trend, {key}: ok — recent {verdict['recent']:.0%}, "
                  f"baseline {verdict['baseline']:.0%} of slack used")

    if summary_path:
        with open(summary_path, "a", encoding="utf-8") as fh:
            fh.write("### Wall-clock margin trend\n\n")
            fh.write(f"History: {len({s['run'] for s in history})} push run(s) on "
                     f"`{BRANCH}`. Erosion = recent median (last {RECENT}) "
                     f"≥ {LEVEL_AT:.0%} of the {SLACK}s slack, or ≥ {DRIFT_AT:.0%} "
                     f"above the baseline median.\n\n")
            fh.write("| Series | Samples | Latest | Recent median | Baseline median | Verdict |\n")
            fh.write("|---|---|---|---|---|---|\n")
            pct = lambda v: "–" if v is None else f"{v:.0%}"  # noqa: E731
            for key, v in rows:
                fh.write(f"| {key} | {v['n']} | {pct(v['latest'])} | {pct(v['recent'])} | "
                         f"{pct(v['baseline'])} | {v['verdict']} |\n")
            if not rows:
                fh.write("| – | 0 | – | – | – | no data |\n")

    if failures and event == "push":
        print("::error::timing trend: " + " | ".join(failures))
        return 1
    return 0


# ---------------------------------------------------------------- GitHub API

def _get(url, token):
    req = urllib.request.Request(url, headers={
        "Accept": "application/vnd.github+json",
        "Authorization": f"Bearer {token}",
        "X-GitHub-Api-Version": "2022-11-28",
    })
    with urllib.request.urlopen(req, timeout=30) as resp:
        return json.load(resp)


def samples_for_run(api, repo, run, token):
    """Every usable timing measurement a run's PHP jobs annotated."""
    jobs = _get(f"{api}/repos/{repo}/actions/runs/{run['id']}/jobs?per_page=100", token)["jobs"]
    out = []
    for job in jobs:
        if not JOB.match(job["name"]) or job.get("status") != "completed":
            continue
        notes = _get(f"{api}/repos/{repo}/check-runs/{job['id']}/annotations?per_page=100", token)
        for note in notes:
            parsed = parse_annotation(note.get("message", ""))
            if parsed is None:
                continue
            budget, elapsed = parsed
            out.append({"series": series_key(job["name"], budget), "budget": budget,
                        "elapsed": elapsed, "run": run["id"],
                        "created": run.get("created_at", "")})
    return out


def fetch(repo, run_id, token, api="https://api.github.com"):
    listing = _get(
        f"{api}/repos/{repo}/actions/workflows/{WORKFLOW_FILE}/runs"
        f"?branch={BRANCH}&event=push&status=completed&per_page={HISTORY_RUNS}", token)
    runs = [r for r in listing["workflow_runs"] if r["id"] != run_id]
    runs.sort(key=lambda r: r["created_at"])
    # ~4 small requests per run; issued concurrently so 25 runs cost seconds.
    with ThreadPoolExecutor(max_workers=8) as pool:
        per_run = list(pool.map(lambda r: samples_for_run(api, repo, r, token), runs))
    history = [s for samples in per_run for s in samples]
    current = samples_for_run(api, repo, {"id": run_id}, token)
    return history, current


def main(argv=None) -> int:
    ap = argparse.ArgumentParser(description=__doc__.splitlines()[0])
    ap.add_argument("--samples", help="read {history, current} from this JSON "
                    "instead of the API (tests, local replays)")
    ap.add_argument("--dump", help="write the fetched {history, current} here")
    args = ap.parse_args(argv)

    event = os.environ.get("GITHUB_EVENT_NAME", "")
    summary = os.environ.get("GITHUB_STEP_SUMMARY")
    if args.samples:
        with open(args.samples, encoding="utf-8") as fh:
            data = json.load(fh)
        history, current = data["history"], data["current"]
    else:
        try:
            history, current = fetch(os.environ["GITHUB_REPOSITORY"],
                                     int(os.environ["GITHUB_RUN_ID"]),
                                     os.environ["GITHUB_TOKEN"])
        except Exception as exc:  # noqa: BLE001 — any failure means "no verdict"
            print(f"::warning::timing trend: could not read the run history: {exc}")
            return 1 if event == "push" else 0
    if args.dump:
        with open(args.dump, "w", encoding="utf-8") as fh:
            json.dump({"history": history, "current": current}, fh, indent=1)
    return analyse(history, current, event, summary)


if __name__ == "__main__":
    sys.exit(main())
