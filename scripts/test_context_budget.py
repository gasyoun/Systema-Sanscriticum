#!/usr/bin/env python3
"""H2817 safety-string + token-budget gate for Systema always-on context.

Locks:
  * CLAUDE.md <= 3000 approximate tokens (chars/4)
  * required safety/routing strings present in CLAUDE.md
  * CLAUDE.md names what this repo is (Laravel LMS)
  * CLAUDE.md never tells the agent to read it end-to-end as the only path
  * AGENTS.md generated-block markers stay intact (no hand-edit of the fence)

A silent edit that drops watcher / money / deploy / soft-remediate, or that
re-inflates CLAUDE.md past the ceiling, fails this script.

Usage:
    python scripts/test_context_budget.py
"""
from __future__ import annotations

import sys
from pathlib import Path

sys.stdout.reconfigure(encoding="utf-8")
sys.stderr.reconfigure(encoding="utf-8")

ROOT = Path(__file__).resolve().parent.parent
CLAUDE_BUDGET = 3000

# Load-bearing fragments a session must see without opening a playbook.
SAFETY_CLAUDE = (
    "watcher-safe-commit",
    "money-contour",
    "deploy",
    "soft-alert",
    "ops:soft-remediate",
    "Laravel LMS",
    "worktree",
)

FAILS: list[str] = []


def check(name: str, cond: bool) -> None:
    print(f"  {'PASS' if cond else 'FAIL'} :: {name}")
    if not cond:
        FAILS.append(name)


def approx_tokens(text: str) -> int:
    return len(text) // 4


def test_token_budget() -> None:
    claude = (ROOT / "CLAUDE.md").read_text(encoding="utf-8")
    ct = approx_tokens(claude)
    print(f"  measure :: CLAUDE.md {len(claude)} chars ~{ct} tok")
    check(f"CLAUDE.md ~tokens {ct} <= {CLAUDE_BUDGET}", ct <= CLAUDE_BUDGET)
    check("CLAUDE.md is not silently empty", ct > 100)


def test_safety_strings() -> None:
    claude = (ROOT / "CLAUDE.md").read_text(encoding="utf-8")
    for needle in SAFETY_CLAUDE:
        check(f"CLAUDE.md has {needle!r}", needle in claude)


def test_routing() -> None:
    claude = (ROOT / "CLAUDE.md").read_text(encoding="utf-8")
    lower = claude.lower()
    check("CLAUDE.md does not say 'read it in full'",
          "read it in full" not in lower)
    check("CLAUDE.md tells the agent not to read end-to-end",
          "end-to-end" in lower)
    check("dated header present", "_Created:" in claude and "Last updated:" in claude)
    check("byline present", "Mārcis Gasūns" in claude)


def test_agents_fence() -> None:
    agents = (ROOT / "AGENTS.md").read_text(encoding="utf-8")
    check("AGENTS.md opens the generated-block fence",
          "[generated-block: repo-context" in agents)
    check("AGENTS.md closes the generated-block fence",
          "[/generated-block]" in agents)
    check("AGENTS.md does not say 'read it in full'",
          "read it in full" not in agents.lower())


def test_approx_helper() -> None:
    check("approx_tokens('abcd') == 1", approx_tokens("abcd") == 1)
    check("a 3001-token file would fail the budget",
          approx_tokens("x" * (4 * CLAUDE_BUDGET + 4)) > CLAUDE_BUDGET)


def main() -> int:
    print("Systema context-budget gate (H2817)")
    test_approx_helper()
    test_token_budget()
    test_safety_strings()
    test_routing()
    test_agents_fence()
    if FAILS:
        print(f"FAIL ({len(FAILS)}): " + "; ".join(FAILS))
        return 1
    print("OK")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
