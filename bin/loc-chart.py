#!/usr/bin/env python3
"""Draws the lines-of-code chart for the project site.

Usage: bin/loc-chart.py OUTPUT.png [MAIN_REF]

Reads the git history of MAIN_REF (default origin/main) and every remote
branch, and the open pull requests through `gh`. Needs the full history
(`fetch-depth: 0` in CI) and GH_TOKEN for `gh` in CI.

What it draws, per type (PHP code, PHP tests, Markdown, other):
- Solid: net lines (added - deleted) on main, stepping up when the merge that
  brought a commit in landed on main. Lock files and vendor/ are left out.
- Striped, above each type: the same type proposed on a branch but not yet on
  main. A commit grows the stripe when its subject first appears on any
  branch and shrinks it when the commit with that subject lands on main.
  Commits of open pull requests that are not on main stay striped.
- Dashed, after the cutoff: a straight line from each type's merged total to
  the estimate below. Once the estimate's end date has passed, only the real
  data is drawn.
"""
import collections
import datetime as dt
import json
import re
import subprocess
import sys

import matplotlib

matplotlib.use("Agg")
import matplotlib.dates as md  # noqa: E402
import matplotlib.pyplot as plt  # noqa: E402
import matplotlib.ticker as mt  # noqa: E402
from matplotlib.colors import to_rgb  # noqa: E402
from matplotlib.patches import Patch  # noqa: E402

# --- Configuration --------------------------------------------------------

# Estimated final lines per type for the IVK release scope, made on 2026-11-13
# from docs/architecture/release-plan.md and the remaining burn-up slices.
ESTIMATE = {"code": 48600, "tests": 61700, "md": 5500, "other": 5600}
ESTIMATE_END = dt.datetime(2026, 11, 24, 23, 59)

TYPES = ["code", "tests", "md", "other"]
LABELS = ["PHP-kód", "PHP-tesztek", "Markdown", "Egyéb"]
LABEL_STRIPED = "Ugyanez más ágakon (még nincs jóváhagyva)"
LABEL_Y = "Sorok száma"
LABEL_CURRENT = "Jelenleg összesen: {}"
LABEL_ESTIMATE = "Becsült összesen: {}"
MONTHS = ["jan.", "febr.", "márc.", "ápr.", "máj.", "jún.",
          "júl.", "aug.", "szept.", "okt.", "nov.", "dec."]
COLORS = ["#2f6fdb", "#7fb3f5", "#e0a030", "#aaaaaa"]
HATCH = "////////"


def git(*args):
    return subprocess.run(["git", *args], capture_output=True, text=True, check=True).stdout


def kind(path):
    """The chart type of a file, or None when it is not counted."""
    if path.endswith(("composer.lock", "package-lock.json")) or "vendor/" in path:
        return None
    ext = path.rsplit(".", 1)[-1]
    if ext == "php":
        return "tests" if "/tests/" in path or path.startswith("tests/") else "code"
    return "md" if ext == "md" else "other"


def commits(*revs):
    """[(sha, committer time, subject, Counter of net lines per type)] for non-merge commits."""
    out = git("log", *revs, "--no-merges", "--no-renames", "--numstat", "--format=@%H|%ct|%s")
    rows = []
    for line in out.splitlines():
        if line.startswith("@"):
            sha, t, subject = line[1:].split("|", 2)
            rows.append((sha, int(t), subject, collections.Counter()))
        elif line.strip():
            added, deleted, path = line.split("\t")
            k = kind(path)
            if added != "-" and k:
                rows[-1][3][k] += int(added) - int(deleted)
    return rows


def landing_times(main):
    """When each commit reached main: the time of the first-parent merge that brought it in."""
    land = {}
    for line in git("log", main, "--first-parent", "--format=%H %ct %P").splitlines():
        sha, t, *parents = line.split()
        land.setdefault(sha, int(t))
        if len(parents) == 2:
            for c in git("rev-list", "--no-merges", f"{parents[0]}..{parents[1]}").split():
                land[c] = int(t)
    return land


def open_pr_heads():
    out = subprocess.run(
        ["gh", "pr", "list", "--state", "open", "--limit", "200",
         "--json", "headRefName,isCrossRepository"],
        capture_output=True, text=True, check=True).stdout
    return [f"origin/{p['headRefName']}" for p in json.loads(out) if not p["isCrossRepository"]]


def patch_ids(*revs):
    shas = git("rev-list", "--no-merges", *revs)
    diff = subprocess.run(["git", "diff-tree", "--stdin", "-p", "--no-renames", "-r"],
                          input=shas, capture_output=True, text=True, check=True).stdout
    out = subprocess.run(["git", "patch-id", "--stable"], input=diff,
                         capture_output=True, text=True, check=True).stdout
    return {line.split()[1]: line.split()[0] for line in out.splitlines()}


def events(main):
    """Time-ordered (time, Counter, striped) changes to the chart."""
    norm = lambda s: re.sub(r"^(WIP:?\s*|fixup! )", "", s)  # noqa: E731
    land = landing_times(main)
    main_commits = [(land.get(sha, t), subject, c) for sha, t, subject, c in commits(main)]

    first = {}  # earliest time a subject appeared on any branch
    for line in git("log", "--remotes", "--no-merges", "--format=%ct|%s").splitlines():
        t, subject = line.split("|", 1)
        first[norm(subject)] = min(first.get(norm(subject), 10**12), int(t))

    out, seen = [], set()
    for t, subject, c in main_commits:
        out.append((t, c, False))
        if subject in seen or first.get(subject, t) >= t:
            continue
        seen.add(subject)
        out.append((first[subject], c, True))
        out.append((t, collections.Counter({k: -v for k, v in c.items()}), True))

    # Open pull requests: their commits whose patch and subject are not on main.
    heads = open_pr_heads()
    if heads:
        on_main = set(patch_ids(main).values())
        main_subjects = {s for _, s, _ in main_commits}
        done = set()
        for sha, pid in patch_ids(*heads, "--not", main).items():
            if pid in on_main or pid in done:
                continue
            done.add(pid)
            _, t, subject, c = commits("--no-walk", sha)[0]
            if norm(subject) not in main_subjects:
                out.append((first.get(norm(subject), t), c, True))
    return sorted(out, key=lambda e: e[0])


def series(evs, now):
    """Step series: each value holds until the instant it changes."""
    main, wip = collections.Counter(), collections.Counter()
    xs, ys = [], {(k, w): [] for k in TYPES for w in (False, True)}
    groups = collections.OrderedDict()
    for t, c, w in evs:
        groups.setdefault(t, []).append((c, w))

    def push(x):
        xs.append(x)
        for k in TYPES:
            ys[(k, False)].append(max(main[k], 0))
            ys[(k, True)].append(max(wip[k], 0))

    for t, items in groups.items():
        if xs:
            push(dt.datetime.fromtimestamp(t) - dt.timedelta(seconds=1))
        for c, w in items:
            (wip if w else main).update(c)
        push(dt.datetime.fromtimestamp(t))
    push(max(now, xs[-1] + dt.timedelta(minutes=1)))
    return xs, ys, main, wip


def draw(xs, ys, main, wip, now, path):
    hun = lambda n: f"{int(round(n)):,}".replace(",", " ")  # noqa: E731
    hud = lambda d: f"{MONTHS[d.month - 1]}\u00a0{d.day}."  # noqa: E731
    tint = lambda c, f: tuple(f + (1 - f) * v for v in to_rgb(c))  # noqa: E731
    dark = lambda c, f=.8: tuple(f * v for v in to_rgb(c))  # noqa: E731

    plt.rcParams.update({"font.size": 10, "axes.edgecolor": "#999", "xtick.color": "#555",
                         "ytick.color": "#555", "axes.labelcolor": "#444", "hatch.linewidth": 1.0})
    fig, ax = plt.subplots(figsize=(13, 6.8))

    stack, colors = [], []
    for k, t in enumerate(TYPES):
        stack += [ys[(t, False)], ys[(t, True)]]
        colors += [COLORS[k], COLORS[k]]
    for i, poly in enumerate(ax.stackplot(xs, stack, colors=colors, lw=0)):
        if i % 2:
            poly.set_hatch(HATCH)
            poly.set_edgecolor(tint(colors[i], .6))
            poly.set_linewidth(0)

    ax.legend(handles=[Patch(fc=COLORS[k], label=LABELS[k]) for k in range(4)]
              + [Patch(fc="#777", ec="#ddd", hatch=HATCH, label=LABEL_STRIPED)],
              loc="upper left", bbox_to_anchor=(0, .97), frameon=False, fontsize=9.5, handlelength=1.6)
    ax.set_ylabel(LABEL_Y)

    x0 = xs[-1]
    merged = [main[t] for t in TYPES]
    tops, acc = [], 0  # each type's merged (solid) top at the cutoff
    for k, t in enumerate(TYPES):
        acc += merged[k]
        tops.append(acc)
        acc += wip[t]
    stack_top = acc

    ymax = stack_top
    x_end = x0 + dt.timedelta(days=1)
    if now < ESTIMATE_END:
        x1 = ESTIMATE_END
        target = [ESTIMATE[t] for t in TYPES]
        lo_start = lo_end = 0
        ends, end = [], 0
        for k in range(4):
            end += target[k]
            ends.append(end)
            ax.fill_between([x0, x1], [lo_start, lo_end], [tops[k], end], color=COLORS[k], alpha=.22, lw=0)
            ax.plot([x0, x1], [tops[k], end], ls=(0, (5, 3)), color=dark(COLORS[k]), lw=1.5)
            lo_start, lo_end = tops[k], end
            ax.plot([x1], [end], "o", ms=4, color=dark(COLORS[k]), zorder=5)
            ax.annotate(f"~{hun(target[k])}", xy=(x1, end), xytext=(x1 - dt.timedelta(hours=16), end),
                        ha="right", va="center", fontsize=9, color=dark(COLORS[k], .6),
                        bbox=dict(boxstyle="round,pad=.25,rounding_size=.5", fc="white",
                                  ec=dark(COLORS[k]), lw=1, ls=(0, (3, 2))),
                        arrowprops=dict(arrowstyle="-", color=dark(COLORS[k]), lw=.8, shrinkA=0, shrinkB=3),
                        zorder=6)
        ax.annotate(LABEL_ESTIMATE.format(hun(end)), xy=(x1, end), xytext=(-8, 12),
                    textcoords="offset points", fontsize=10.5, ha="right", color="#222")
        ax.axvspan(x0, x1, color="#888", alpha=.05, lw=0, zorder=0)
        ymax = max(ymax, end)
        x_end = x1 + dt.timedelta(hours=12)

    ax.axvline(x0, color="#444", lw=.9)
    ax.text(x0, .985, " " + hud(x0) + x0.strftime("\u00a0%H:%M"), transform=ax.get_xaxis_transform(),
            ha="left", va="top", fontsize=9, color="#444")
    ypos = list(tops)
    for k in range(1, 4):  # nudged only when two would touch
        ypos[k] = max(ypos[k], ypos[k - 1] + ymax * .03)
    for k in range(4):
        ax.plot([x0], [tops[k]], "o", ms=4, color=dark(COLORS[k]), zorder=5)
        ax.annotate(hun(merged[k]), xy=(x0, tops[k]), xytext=(x0 + dt.timedelta(hours=16), ypos[k]),
                    ha="left", va="center", fontsize=9, weight="bold",
                    color="white" if k == 0 else "#1d1d1d",
                    bbox=dict(boxstyle="round,pad=.25,rounding_size=.5", fc=COLORS[k], ec=dark(COLORS[k]), lw=1),
                    arrowprops=dict(arrowstyle="-", color=dark(COLORS[k]), lw=.8, shrinkA=0, shrinkB=3),
                    zorder=6)
    ax.annotate(LABEL_CURRENT.format(hun(sum(merged))), xy=(x0, stack_top), xytext=(-8, 12),
                textcoords="offset points", fontsize=10.5, ha="right", color="#222")

    ax.set_xlim(xs[0] - dt.timedelta(days=.5), x_end)
    ax.set_ylim(0, ymax * 1.1)
    span = (x_end - xs[0]).days
    ax.xaxis.set_major_locator(md.DayLocator(interval=max(1, -(-span // 12))))
    ax.xaxis.set_minor_locator(md.DayLocator())
    ax.xaxis.set_major_formatter(mt.FuncFormatter(lambda v, _: hud(md.num2date(v))))
    ax.yaxis.set_major_formatter(mt.FuncFormatter(lambda v, _: hun(v)))
    ax.set_axisbelow(True)
    ax.grid(which="major", color="#e3e3e3", lw=.8)
    ax.grid(which="minor", axis="x", color="#efefef", lw=.6)
    for side in ("top", "right"):
        ax.spines[side].set_visible(False)
    ax.tick_params(length=3)
    fig.tight_layout()
    fig.savefig(path, dpi=130)


def main():
    if len(sys.argv) not in (2, 3):
        sys.exit(__doc__)
    path = sys.argv[1]
    ref = sys.argv[2] if len(sys.argv) == 3 else "origin/main"
    now = dt.datetime.now().replace(microsecond=0)
    xs, ys, merged, wip = series(events(ref), now)
    draw(xs, ys, merged, wip, now, path)


if __name__ == "__main__":
    main()
