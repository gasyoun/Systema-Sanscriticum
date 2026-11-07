#!/usr/bin/env python3
"""H6851 — user-simulator eval: simulated-student dialogues for the support lane.

Multi-turn simulated-student harness (Anthropic "conversational agent eval" pattern,
gap 5 of docs/ASSESSMENT_ANTHROPIC_DEMYSTIFYING_EVALS_14-10-2026.md):

    persona script -> dialog -> scoring (goal resolution, turn budget, rubric)

Modes
-----
* Scripted persona mode (deterministic) — powers the committed selftest and the
  zero-spend stub pilot. Pure functions of inputs; byte-identical on every run.
* LLM persona mode (live) — spend-capped. Refuses to initialise unless BOTH
  USERSIM_LLM_ENABLED=1 and an explicit ceiling USERSIM_LLM_MAX_CALLS are set;
  counts every call against the ceiling. NOT exercised in this handoff.

152-FZ fence: personas are synthetic; utterances come from the ANONYMISED
fixture tests/fixtures/faq_rag_eval.json or hand-written generic text. A
fence_check() scans every payload/artifact for real-data shapes (emails,
phones, handles, URLs, long numeric ids) and must report zero hits.

Usage
-----
    python3 harness.py --selftest              # deterministic offline selftest
    python3 harness.py --pilot                 # scripted-stub pilot -> runs/
    python3 harness.py --target deflecting ... # negative-class runs
"""

from __future__ import annotations

import argparse
import hashlib
import json
import os
import re
import sys
from dataclasses import dataclass, field
from pathlib import Path
from typing import Optional

HERE = Path(__file__).resolve().parent
REPO = HERE.parent.parent
FIXTURE = REPO / "tests" / "fixtures" / "faq_rag_eval.json"
PERSONAS = HERE / "personas.json"

# ---------------------------------------------------------------------------
# Spend ceilings (named BEFORE any paid call — H6851 protocol). The scripted
# stub path spends 0.00 USD by construction; the LLM path is hard-capped here.
# ---------------------------------------------------------------------------
LLM_CEILING_USD = 0.50          # hard cap for any future live pilot
LLM_EST_COST_PER_CALL_USD = 0.02


# ---------------------------------------------------------------------------
# Persona engine
# ---------------------------------------------------------------------------
@dataclass
class Turn:
    utterance: str
    source: str = "synthetic"


@dataclass
class Persona:
    id: str
    name: str
    category: str
    goal_chunk: str
    goal_reachable: bool
    max_turns: int
    turns: list[Turn]
    unreachable_reason: str = ""

    @classmethod
    def from_dict(cls, d: dict) -> "Persona":
        return cls(
            id=d["id"],
            name=d["name"],
            category=d.get("category", "-"),
            goal_chunk=d["goal_chunk"],
            goal_reachable=bool(d["goal_reachable"]),
            max_turns=int(d["max_turns"]),
            turns=[Turn(t["utterance"], t.get("source", "synthetic")) for t in d["turns"]],
            unreachable_reason=d.get("unreachable_reason", ""),
        )


def load_personas(path: Path = PERSONAS) -> list[Persona]:
    data = json.loads(path.read_text(encoding="utf-8"))
    personas = [Persona.from_dict(p) for p in data["personas"]]
    if len(personas) < 5:
        raise ValueError(f"fence/contract: need >=5 personas, got {len(personas)}")
    return personas


# ---------------------------------------------------------------------------
# Target adapters
# ---------------------------------------------------------------------------
@dataclass
class Reply:
    text: str
    retrieved_chunk_ids: list[str] = field(default_factory=list)
    refusal: bool = False


class StubFaqTarget:
    """Deterministic stand-in for the support lane's FAQ answer surface.

    Lexical-overlap retrieval over the committed fixture's question ->
    expected_chunk_ids map (the same hand-checked gold pairs the lane's
    knowledge:eval scores). Below the floor it refuses (the fixture's
    refusal-card rule: no auto-answer without a confident match).
    """

    MATCH_FLOOR = 0.34

    def __init__(self, deflecting: bool = False):
        self.deflecting = deflecting
        items = json.loads(FIXTURE.read_text(encoding="utf-8"))["items"]
        self.index: list[tuple[frozenset[str], str]] = []
        for it in items:
            for chunk in it.get("expected_chunk_ids", []) or []:
                self.index.append((frozenset(_tokens(it["question"])), chunk))

    def respond(self, dialog: list[dict]) -> Reply:
        last_user = next(
            (m["text"] for m in reversed(dialog) if m["role"] == "user"), ""
        )
        if self.deflecting:
            return Reply(
                text="Спасибо за обращение! Мы скоро свяжемся с вами.",
                retrieved_chunk_ids=[],
                refusal=False,
            )
        q = frozenset(_tokens(last_user))
        if _per_student_fact(q):
            return Reply(
                text=(
                    "Такие данные (баланс, личное расписание, доступ) я не "
                    "вижу — это персональная информация, её подскажет куратор."
                ),
                retrieved_chunk_ids=[],
                refusal=True,
            )
        # Query-coverage scoring: share of the USER's content tokens found in
        # a gold question. Short/greeting utterances (<2 content tokens) fall
        # below the floor and refuse — the lane's refusal-card behaviour; a
        # bare «Здравствуйте!» must never "resolve" a goal (H6851 selftest 4b).
        best, best_chunk, best_score = None, "", 0.0
        if len(q) >= 2:
            for qs, chunk in self.index:
                if not qs:
                    continue
                score = len(qs & q) / len(q)
                if score > best_score:
                    best, best_chunk, best_score = qs, chunk, score
        if best_score >= self.MATCH_FLOOR and best_chunk:
            return Reply(
                text=f"Ответ в базе знаний, раздел «{best_chunk}».",
                retrieved_chunk_ids=[best_chunk],
                refusal=False,
            )
        return Reply(
            text="Не уверен, что понял вопрос — уточните, пожалуйста?",
            retrieved_chunk_ids=[],
            refusal=True,
        )


class LlmPersonaDriver:
    """LLM persona mode — live, spend-capped. Kept side-by-side with the
    scripted engine; every call is counted against the declared ceiling."""

    def __init__(self, max_calls: int):
        if os.environ.get("USERSIM_LLM_ENABLED") != "1":
            raise RuntimeError(
                "LLM persona mode is disabled: set USERSIM_LLM_ENABLED=1 and "
                "declare USERSIM_LLM_MAX_CALLS (protocol ceiling "
                f"{LLM_CEILING_USD} USD) before any paid call."
            )
        if max_calls * LLM_EST_COST_PER_CALL_USD > LLM_CEILING_USD:
            raise RuntimeError("declared calls exceed protocol spend ceiling")
        self.max_calls = max_calls
        self.calls = 0

    def next_utterance(self, dialog: list[dict]) -> str:  # pragma: no cover
        if self.calls >= self.max_calls:
            raise RuntimeError("LLM ceiling reached — stopping, never silent")
        self.calls += 1
        raise NotImplementedError(
            "wire the provider adapter here; ceiling machinery is ready"
        )


# ---------------------------------------------------------------------------
# Dialog runner + scoring
# ---------------------------------------------------------------------------
def run_dialog(persona: Persona, target: StubFaqTarget) -> dict:
    dialog: list[dict] = []
    resolved = False
    turns_used = 0
    transcript: list[dict] = []
    for turn in persona.turns:
        if turns_used >= persona.max_turns:
            break
        dialog.append({"role": "user", "text": turn.utterance})
        reply = target.respond(dialog)
        turns_used += 1
        dialog.append({"role": "target", "text": reply.text})
        transcript.append(
            {
                "turn": turns_used,
                "user": turn.utterance,
                "user_source": turn.source,
                "target": reply.text,
                "retrieved_chunk_ids": list(reply.retrieved_chunk_ids),
                "refusal": reply.refusal,
            }
        )
        if persona.goal_chunk in reply.retrieved_chunk_ids:
            resolved = True
            break

    had_remaining = turns_used < len(persona.turns)
    budget_tripped = (not resolved) and had_remaining
    rubric = {
        "acknowledged": all(t["target"] for t in transcript),
        "on_topic": bool(
            transcript
            and (transcript[-1]["retrieved_chunk_ids"] or transcript[-1]["refusal"])
        ),
        "resolved": resolved,
    }
    verdict = score_dialog(persona, resolved, turns_used, budget_tripped)
    return {
        "persona_id": persona.id,
        "goal_chunk": persona.goal_chunk,
        "goal_reachable": persona.goal_reachable,
        "max_turns": persona.max_turns,
        "turns_used": turns_used,
        "goal_resolved": resolved,
        "budget_tripped": budget_tripped,
        "rubric": rubric,
        "verdict": verdict,
        "transcript": transcript,
    }


def score_dialog(persona: Persona, resolved: bool, turns_used: int, budget_tripped: bool) -> str:
    """Verdicts: PASS | NOT_RESOLVED | BUDGET_TRIPPED | INCONCLUSIVE.

    Contract (H6851 negative class): an unreachable persona is excluded from
    the denominator and marked INCONCLUSIVE — never counted as a pass; an
    over-long dialog trips the turn-budget check; a deflecting target is
    flagged NOT-resolved (no rubber-stamping).
    """
    if not persona.goal_reachable:
        return "INCONCLUSIVE"
    if resolved:
        return "PASS"
    if budget_tripped:
        return "BUDGET_TRIPPED"
    return "NOT_RESOLVED"


DENOMINATOR_VERDICTS = {"PASS", "NOT_RESOLVED", "BUDGET_TRIPPED"}


def aggregate(results: list[dict]) -> dict:
    denom = [r for r in results if r["verdict"] in DENOMINATOR_VERDICTS]
    passes = [r for r in denom if r["verdict"] == "PASS"]
    return {
        "dialogs": len(results),
        "denominator": len(denom),
        "excluded_inconclusive": sum(
            1 for r in results if r["verdict"] == "INCONCLUSIVE"
        ),
        "passes": len(passes),
        "not_resolved": sum(1 for r in denom if r["verdict"] == "NOT_RESOLVED"),
        "budget_tripped": sum(1 for r in denom if r["verdict"] == "BUDGET_TRIPPED"),
        "pass_rate": round(len(passes) / len(denom), 3) if denom else None,
        "rubric_mean": round(
            sum(
                sum(r["rubric"].values()) / 3
                for r in denom
            ) / len(denom),
            3,
        )
        if denom
        else None,
    }


# ---------------------------------------------------------------------------
# 152-FZ fence
# ---------------------------------------------------------------------------
FENCE_PATTERNS = [
    (r"[\w.+-]+@[\w-]+\.[\w.]+", "email"),
    (r"(?:\+7|8)[\s(-]?\d{3}[\s)-]?\d{3}[\s-]?\d{2}[\s-]?\d{2}", "phone-ru"),
    (r"(?<![\w])@[A-Za-z_][A-Za-z0-9_]{3,}", "handle"),
    (r"https?://|t\.me/", "url"),
    (r"(?<!\d)\d{6,}(?!\d)", "long-numeric-id"),
]
# Only the scanner's own source file is exempt (it carries the fence regexes
# themselves — @-decorator false positives). No dir-wide exemptions: every
# other artifact (personas.json, transcripts, reports, protocol docs) is
# scanned for real-data shapes. Tightened per H6851 verifier finding.
FENCE_SELF = "harness.py"


def fence_violations(text: str, path: str = "") -> list[tuple[str, str]]:
    """Real-data shapes found in a payload/artifact."""
    if path and os.path.basename(path) == FENCE_SELF:
        return []
    hits = []
    for pat, kind in FENCE_PATTERNS:
        for m in re.finditer(pat, text):
            # provenance/source citations contain no data; URLs flagged anyway
            hits.append((kind, m.group(0)))
    return hits


# ---------------------------------------------------------------------------
# Pilot / selftest drivers
# ---------------------------------------------------------------------------
def canonical_payload(results: list[dict], meta: dict) -> str:
    return json.dumps({"meta": meta, "results": results}, ensure_ascii=False, sort_keys=True, indent=2)


def run_pilot(target: StubFaqTarget, personas: list[Persona], label: str) -> tuple[list[dict], dict]:
    results = [run_dialog(p, target) for p in personas]
    meta = {
        "label": label,
        "mode": "scripted-stub (deterministic, zero spend, 0.00 USD / ceiling 0.00 USD)",
        "target": "StubFaqTarget over tests/fixtures/faq_rag_eval.json gold pairs",
        "live_lane_pilot": "RESIDUAL — not run (see PILOT_PROTOCOL.md)",
        "fence_hits": fence_violations(canonical_payload(results, {})),
        "payload_sha256": hashlib.sha256(
            canonical_payload(results, {}).encode("utf-8")
        ).hexdigest(),
    }
    return results, meta


def write_run(results: list[dict], meta: dict, out_dir: Path) -> None:
    out_dir.mkdir(parents=True, exist_ok=True)
    (out_dir / "transcripts.jsonl").write_text(
        "\n".join(json.dumps(r, ensure_ascii=False, sort_keys=True) for r in results) + "\n",
        encoding="utf-8",
    )
    agg = aggregate(results)
    lines = [
        f"# H6851 pilot — {meta['label']}",
        "",
        f"- mode: {meta['mode']}",
        f"- target: {meta['target']}",
        f"- live-lane pilot: **{meta['live_lane_pilot']}**",
        f"- payload sha256: `{meta['payload_sha256']}`",
        f"- 152-FZ fence hits: {len(meta['fence_hits'])} (must be 0)",
        "",
        "| persona | turns | verdict | goal_resolved | budget_tripped |",
        "|---|---|---|---|---|",
    ]
    for r in results:
        lines.append(
            f"| {r['persona_id']} | {r['turns_used']}/{r['max_turns']} | "
            f"{r['verdict']} | {r['goal_resolved']} | {r['budget_tripped']} |"
        )
    lines += [
        "",
        f"Aggregate: {json.dumps(agg, ensure_ascii=False)}",
        "",
        "Denominator excludes INCONCLUSIVE (unreachable persona) per H6851 contract.",
    ]
    (out_dir / "report.md").write_text("\n".join(lines) + "\n", encoding="utf-8")


def selftest() -> int:
    failures: list[str] = []
    personas = load_personas()
    stub = StubFaqTarget()

    # 1. Reference solution: scripted stub resolves planted goals -> PASS.
    for p in [x for x in personas if x.goal_reachable and x.max_turns >= 2]:
        r = run_dialog(p, stub)
        if r["verdict"] != "PASS":
            failures.append(f"{p.id}: expected PASS, got {r['verdict']}")

    # 2. Negative: deflecting target must be NOT_RESOLVED (no rubber-stamping).
    r = run_dialog(personas[0], StubFaqTarget(deflecting=True))
    if r["verdict"] not in {"NOT_RESOLVED", "BUDGET_TRIPPED"} or r["goal_resolved"]:
        failures.append(f"deflecting: expected NOT-resolved, got {r['verdict']}")

    # 3. Negative: unreachable persona -> INCONCLUSIVE, excluded from denominator.
    unreachable = next(p for p in personas if not p.goal_reachable)
    r = run_dialog(unreachable, stub)
    agg_probe = aggregate([r])
    if r["verdict"] != "INCONCLUSIVE" or agg_probe["denominator"] != 0:
        failures.append(f"unreachable: expected INCONCLUSIVE/excluded, got {r['verdict']}")

    # 4. Negative: over-long dialog trips the turn-budget check.
    talker = next(p for p in personas if len(p.turns) > p.max_turns)
    r = run_dialog(talker, StubFaqTarget(deflecting=True))
    if not r["budget_tripped"] or r["verdict"] != "BUDGET_TRIPPED":
        failures.append(f"over-long: expected BUDGET_TRIPPED, got {r['verdict']}")
    if r["turns_used"] > talker.max_turns:
        failures.append("over-long: engine let dialog exceed max_turns")

    # 4b. Negative: a bare greeting must never resolve a goal (lexical
    # false-positive class caught in the first pilot dry run).
    r = run_dialog(talker, StubFaqTarget())
    if r["goal_resolved"] or r["verdict"] != "BUDGET_TRIPPED":
        failures.append(f"greeting: goal resolved={r['goal_resolved']}, verdict={r['verdict']}")

    # 5. Determinism: two cold runs byte-identical.
    res_a, meta_a = run_pilot(StubFaqTarget(), personas, "selftest-run-a")
    res_b, meta_b = run_pilot(StubFaqTarget(), personas, "selftest-run-b")
    if meta_a["payload_sha256"] != meta_b["payload_sha256"]:
        failures.append("determinism: payload hashes differ across runs")

    # 6. 152-FZ fence: zero real-data shapes in every committed artifact
    # (scanner source exempt; everything else genuinely scanned).
    scan_paths = [PERSONAS, HERE / "PILOT_PROTOCOL.md"]
    runs_dir = HERE / "runs" / "2026-10-20-pilot-stub"
    if runs_dir.is_dir():
        scan_paths += sorted(runs_dir.glob("*"))
    for path in scan_paths:
        hits = fence_violations(path.read_text(encoding="utf-8"), str(path))
        if hits:
            failures.append(f"fence: {path.name} hits={hits[:3]}")
    if res_a and meta_a["fence_hits"]:
        failures.append(f"fence: pilot payload hits={meta_a['fence_hits'][:3]}")

    # 7. LLM mode is spend-gated (must refuse to start silently).
    try:
        LlmPersonaDriver(max_calls=10)
        failures.append("llm-mode: initialised without USERSIM_LLM_ENABLED=1")
    except RuntimeError:
        pass

    if failures:
        for f in failures:
            print(f"FAIL {f}")
        print(f"SELFTEST FAIL ({len(failures)} failures)")
        return 1
    print(f"SELFTEST PASS — 8/8 checks green; pilot sha256={meta_a['payload_sha256'][:12]}")
    return 0


def main() -> int:
    ap = argparse.ArgumentParser(description=__doc__)
    ap.add_argument("--selftest", action="store_true")
    ap.add_argument("--pilot", action="store_true")
    ap.add_argument("--target", choices=["stub", "deflecting"], default="stub")
    ap.add_argument("--out", default=str(HERE / "runs" / "2026-10-20-pilot-stub"))
    args = ap.parse_args()

    if args.selftest:
        return selftest()
    if args.pilot:
        personas = load_personas()
        target = StubFaqTarget(deflecting=args.target == "deflecting")
        results, meta = run_pilot(target, personas, f"scripted-{args.target}")
        write_run(results, meta, Path(args.out))
        print(f"pilot written -> {args.out} (sha256={meta['payload_sha256'][:12]})")
        return 0
    ap.print_help()
    return 2


def _tokens(text: str) -> list[str]:
    return [t for t in re.split(r"[^\wа-яёА-ЯЁ]+", text.lower()) if len(t) > 2]


_PER_STUDENT_LEX = frozenset(
    ["баланс", "мне", "доплачивать", "мой", "моё", "мою"]
)


def _per_student_fact(q: frozenset[str]) -> bool:
    return bool(q & _PER_STUDENT_LEX)


if __name__ == "__main__":
    sys.exit(main())
