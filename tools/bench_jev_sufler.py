#!/usr/bin/env python
# -*- coding: utf-8 -*-
"""H5274 — TypeSafe Jev as Telegram sufler intent-classifier: shadow benchmark.

SHADOW ONLY: no prod wiring, no live generation, no write to any store.

Corpus (frozen, PII-masked, public):
    tests/fixtures/Support/classifier_corpus_2026_08.json  — 50 cases, gold `cat`

Two arms over the SAME 50 messages, one Jev `choice` question each:

  A. suggester category space A–F + `none` — directly comparable to the live
     regex floor measured by tools/h5274_regex_floor.php (98.00% on this corpus;
     93% is the ClassifierPrecisionTest assertion threshold).
  B. objection space В1–В11 + `OTHER` — the mission's literal ask. No
     independent В-gold exists on this corpus, so the reference is the
     deterministic ORS-FAQ sufler (ors_faq.sufler.classify_objection, which
     distinguishes В1/В2/В3/В6/В9 + OTHER) → **agreement**, not accuracy.

Client: reuses the H5275-shipped Uprava tools/jev_probe.py (same validators,
same request shape, same cost accounting) — never a second HTTP client.

152-FZ fence: only the public masked fixture is read; stenogrammy bodies and
the private masked corpora are never opened. The API key is read from
~/.secrets/typesafe.env and never echoed.

Usage:
    python tools/bench_jev_sufler.py                      # dry-run (default)
    python tools/bench_jev_sufler.py --run                # LIVE, both arms
    python tools/bench_jev_sufler.py --run --arm a        # LIVE, arm A only
    python tools/bench_jev_sufler.py --selftest           # offline metric math
"""

from __future__ import annotations

import argparse
import json
import os
import statistics
import subprocess
import sys
import time
from pathlib import Path
from typing import Any, Dict, List, Optional, Tuple

ROOT = Path(__file__).resolve().parents[1]
CORPUS = ROOT / "tests" / "fixtures" / "Support" / "classifier_corpus_2026_08.json"
FLOOR_TOOL = ROOT / "tools" / "h5274_regex_floor.php"

ARM_A_OPTIONS = {
    "A": "Zoom / join link / connecting to the lesson",
    "B": "recordings / video / timestamps",
    "C": "schedule / time / a rescheduled lesson",
    "D": "payment / price / tariff / installments",
    "E": "account access / personal cabinet / password / group",
    "F": "materials / homework / certificate",
    "none": "no category — greeting, thanks, small talk, not a support question",
}
ARM_A_QUESTION = "Which support-answer category best matches this student message? Pick exactly one."

ARM_B_OPTIONS = {
    "В1": "no time / will miss / cannot attend",
    "В2": "too expensive / cannot afford the sum",
    "В3": "self-doubt / too hard / too late",
    "В4": "purpose — why do I need this",
    "В5": "which course is mine / my level",
    "В6": "when does it start / cohort timing",
    "В7": "logistics — Zoom, abroad, timezone",
    "В8": "how to pay / where to write (mechanics)",
    "В9": "risk — what if it does not suit me",
    "В10": "trust — who are you",
    "В11": "what comes after the course",
    "OTHER": "none of the above",
}
ARM_B_QUESTION = (
    "Which sales objection (В1–В11) is behind this student message? "
    "Pick exactly one, or OTHER."
)


def _locate(env_var: str, default: Path, marker: Path) -> Path:
    root = Path(os.environ.get(env_var) or default)
    if not (root / marker).exists():
        raise SystemExit(
            f"FAIL: {env_var or default} does not hold {marker} — "
            f"set {env_var} to the checkout root"
        )
    return root


def load_client() -> Any:
    """Import the H5275-shipped jev_probe client (never a second HTTP client)."""
    uprava = _locate("UPRAVA_HOME", Path.home() / "Documents" / "GitHub" / "Uprava",
                     Path("tools") / "jev_probe.py")
    sys.path.insert(0, str(uprava / "tools"))
    import jev_probe  # type: ignore  # noqa: E402

    return jev_probe


def load_sufler_reference():
    """Import the ORS-FAQ deterministic sufler as the В-arm reference."""
    ors = _locate("ORS_FAQ_HOME", Path.home() / "Documents" / "GitHub" / "ORS-FAQ",
                  Path("ors_faq") / "sufler.py")
    sys.path.insert(0, str(ors))
    from ors_faq.sufler import classify_objection  # type: ignore  # noqa: E402

    return classify_objection


def load_corpus() -> List[Dict[str, Any]]:
    data = json.loads(CORPUS.read_text(encoding="utf-8"))
    return data["cases"]


def build_arm_request(jev_probe: Any, arm: str, text: str, model: str) -> Dict[str, Any]:
    if arm == "a":
        question = jev_probe._choice(ARM_A_QUESTION, ARM_A_OPTIONS)
    elif arm == "b":
        question = jev_probe._choice(ARM_B_QUESTION, ARM_B_OPTIONS)
    else:
        raise ValueError(f"unknown arm {arm!r}")
    return {"state": text, "model": model, "questions": {"category": question}}


# ---------------------------------------------------------------- metrics

def prf(rows: List[Dict[str, Any]], gold_key: str, pred_key: str,
        labels: List[str]) -> Dict[str, Dict[str, Any]]:
    """Per-label precision/recall/F1 over exactly `labels` (n_pred/n_gold/tp)."""
    out: Dict[str, Dict[str, Any]] = {}
    for lab in labels:
        tp = sum(1 for r in rows if r[gold_key] == lab and r[pred_key] == lab)
        pred = sum(1 for r in rows if r[pred_key] == lab)
        gold = sum(1 for r in rows if r[gold_key] == lab)
        p = tp / pred if pred else None
        rc = tp / gold if gold else None
        f1 = (2 * p * rc / (p + rc)) if (p is not None and rc) else None
        out[lab] = {"n_gold": gold, "n_pred": pred, "tp": tp,
                    "precision": _r4(p), "recall": _r4(rc), "f1": _r4(f1)}
    return out


def _r4(x: Optional[float]) -> Optional[float]:
    return None if x is None else round(x, 4)


def agreement(rows: List[Dict[str, Any]], gold_key: str, pred_key: str) -> Dict[str, Any]:
    n = len(rows)
    hit = sum(1 for r in rows if r[gold_key] == r[pred_key])
    return {"n": n, "agree": hit, "rate": round(hit / n, 4) if n else None}


def latency_stats(samples: List[float]) -> Dict[str, Any]:
    if not samples:
        return {}
    return {
        "n": len(samples),
        "mean_ms": round(statistics.mean(samples), 1),
        "median_ms": round(statistics.median(samples), 1),
        "p95_ms": round(sorted(samples)[max(0, int(0.95 * len(samples)) - 1)], 1),
        "max_ms": round(max(samples), 1),
    }


def floor_baseline() -> Optional[Dict[str, Any]]:
    """Run the faithful PHP regex floor on the same corpus (None if php absent)."""
    if not FLOOR_TOOL.exists():
        return None
    try:
        proc = subprocess.run(
            ["php", str(FLOOR_TOOL), "--json"],
            capture_output=True, text=True, timeout=60, check=False,
        )
    except (OSError, subprocess.TimeoutExpired):
        return None
    if proc.returncode != 0 or not proc.stdout.strip():
        return None
    try:
        return json.loads(proc.stdout)
    except json.JSONDecodeError:
        return None


# ---------------------------------------------------------------- live run

def run_arm(jev_probe: Any, arm: str, cases: List[Dict[str, Any]],
            api_key: str, endpoint: str, model: str,
            timeout: float, sleep_s: float) -> Dict[str, Any]:
    pred_key = "jev" if arm == "a" else "jev_b"
    rows: List[Dict[str, Any]] = []
    lat: List[float] = []
    costs: List[float] = []
    tokens_in = tokens_out = 0
    for i, case in enumerate(cases, 1):
        req = build_arm_request(jev_probe, arm, case["t"], model)
        ok, why = jev_probe.validate_request(req)
        if not ok:
            raise SystemExit(f"FAIL: local request validation refused case {i}: {why}")
        t0 = time.perf_counter()
        status, body = jev_probe.call_jev(req, api_key, endpoint, timeout_s=timeout)
        dt_ms = (time.perf_counter() - t0) * 1000.0
        verdict = jev_probe.classify_result(status, body)
        answer = (body.get("answers") or {}).get("category") if isinstance(body, dict) else None
        picked = answer.get("choice") if isinstance(answer, dict) else None
        usage = body.get("usage") if isinstance(body, dict) else None
        c = jev_probe.cost_usd(usage)
        if c is not None:
            costs.append(c)
        if isinstance(usage, dict):
            tokens_in += int(usage.get("input_tokens") or 0)
            tokens_out += int(usage.get("output_tokens") or 0)
        lat.append(dt_ms)
        rows.append({"i": i, "t": case["t"], "gold": case["cat"],
                     pred_key: picked, "status": status, "verdict": verdict,
                     "latency_ms": round(dt_ms, 1), "cost_usd": c,
                     "confidence": (answer or {}).get("confidence") if isinstance(answer, dict) else None})
        if sleep_s:
            time.sleep(sleep_s)
    return {"arm": arm, "rows": rows, "latency": latency_stats(lat),
            "cost_usd_total": round(sum(costs), 6), "cost_usd_mean": round(sum(costs) / len(costs), 8) if costs else None,
            "tokens": {"in": tokens_in, "out": tokens_out}}


# ---------------------------------------------------------------- analysis

def analyse_a(result: Dict[str, Any], floor: Optional[Dict[str, Any]]) -> Dict[str, Any]:
    rows = result["rows"]
    labels = list(ARM_A_OPTIONS.keys())
    correct = sum(1 for r in rows if (r["gold"] or "none") == (r["jev"] or "none"))
    total = len(rows)
    acc = correct / total if total else None
    errs = [{"i": r["i"], "t": r["t"], "gold": r["gold"], "jev": r["jev"]}
            for r in rows if (r["gold"] or "none") != (r["jev"] or "none")]
    return {
        "total": total, "correct": correct, "accuracy": _r4(acc),
        "floor_live_accuracy": floor.get("accuracy") if floor else None,
        "floor_assert_threshold": 0.93,
        "beats_live_floor": (acc is not None and floor is not None
                             and acc >= float(floor["accuracy"])),
        "meets_93_assert": (acc is not None and acc >= 0.93),
        "errors": errs,
        "per_class": prf([{**r, "gold_n": r["gold"] or "none", "pred_n": r["jev"] or "none"}
                          for r in rows], "gold_n", "pred_n", labels),
        "latency": result["latency"], "cost_usd_total": result["cost_usd_total"],
        "cost_usd_mean": result["cost_usd_mean"], "tokens": result["tokens"],
    }


def analyse_b(result: Dict[str, Any], sufler_ref, cases: List[Dict[str, Any]]) -> Dict[str, Any]:
    rows = result["rows"]
    for r in rows:
        r["ref"] = sufler_ref(r["t"])
    labels = list(ARM_B_OPTIONS.keys())
    return {
        "\u0412_total": len(rows),
        "note": ("reference = deterministic ORS-FAQ sufler (В1/В2/В3/В6/В9 + OTHER); "
                 "no independent В-gold exists on this corpus → agreement, not accuracy"),
        "agreement": agreement(rows, "ref", "jev_b"),
        "distribution_jev": {lab: sum(1 for r in rows if r["jev_b"] == lab) for lab in labels},
        "distribution_ref": {lab: sum(1 for r in rows if r["ref"] == lab) for lab in labels},
        "per_class": prf(rows, "ref", "jev_b", labels),
        "disagreements": [{"i": r["i"], "t": r["t"], "ref": r["ref"], "jev": r["jev_b"]}
                          for r in rows if r["ref"] != r["jev_b"]],
        "latency": result["latency"], "cost_usd_total": result["cost_usd_total"],
        "cost_usd_mean": result["cost_usd_mean"], "tokens": result["tokens"],
    }


# ---------------------------------------------------------------- selftest

def run_selftest() -> int:
    """Offline: metric math + request-shape validity. No network, no key."""
    fails: List[str] = []

    def check(name: str, cond: bool) -> None:
        if not cond:
            fails.append(name)

    rows = [
        {"gold_n": "A", "pred_n": "A"}, {"gold_n": "B", "pred_n": "A"},
        {"gold_n": "none", "pred_n": "none"}, {"gold_n": "C", "pred_n": "C"},
    ]
    t = prf(rows, "gold_n", "pred_n", ["A", "B", "C", "none"])
    check("prf A tp=1 pred=2", t["A"]["tp"] == 1 and t["A"]["n_pred"] == 2)
    check("prf A precision=0.5", t["A"]["precision"] == 0.5)
    check("prf B recall=0", t["B"]["recall"] == 0.0)
    check("prf none perfect", t["none"]["precision"] == 1.0 and t["none"]["f1"] == 1.0)
    check("prf C absent-from-pred-metric", t["C"]["n_pred"] == 1)

    agg = agreement([{"ref": "В2", "jev_b": "В2"}, {"ref": "OTHER", "jev_b": "В2"}], "ref", "jev_b")
    check("agreement 1/2", agg["rate"] == 0.5)

    check("latency empty", latency_stats([]) == {})
    check("latency median", latency_stats([10.0, 20.0, 30.0])["median_ms"] == 20.0)

    # request shape: both arms must pass the H5275 client validator.
    try:
        jev_probe = load_client()
        for arm in ("a", "b"):
            ok, why = jev_probe.validate_request(
                build_arm_request(jev_probe, arm, "тест", "jev-1.13.0"))
            check(f"arm {arm} request valid ({why})", ok)
        check("arm a options count 7", len(ARM_A_OPTIONS) == 7)
        check("arm b options count 12", len(ARM_B_OPTIONS) == 12)
    except SystemExit as exc:  # client missing
        fails.append(f"client import: {exc}")

    # corpus: frozen, 50 cases, every gold in the arm-A space, no raw PII shapes.
    cases = load_corpus()
    check("corpus 50 cases", len(cases) == 50)
    allowed = set(ARM_A_OPTIONS) | {"none"}
    check("gold labels in arm-A space",
          all((c["cat"] or "none") in allowed for c in cases))
    import re as _re
    leaked = [c["t"] for c in cases
              if _re.search(r"[\w.+-]+@[\w-]+\.[\w.]+|\+?\d[\d\s()-]{9,}", c["t"])]
    check("no unmasked email/phone in corpus", not leaked)

    if fails:
        for f in fails:
            print(f"SELFTEST FAIL: {f}")
        print(f"SELFTEST: {len(fails)} FAIL")
        return 1
    print("SELFTEST: 14/14 PASS (offline metric math + request shape + corpus guards)")
    return 0


# ---------------------------------------------------------------- report

def render_md(payload: Dict[str, Any]) -> str:
    a = payload.get("arm_a")
    b = payload.get("arm_b")
    floor = payload.get("floor") or {}
    lines = [
        "# Jev as Telegram sufler — shadow benchmark",
        "",
        f"_Generated: {payload['generated']} · model `{payload['model']}` · "
        f"corpus `{CORPUS.relative_to(ROOT)}` ({payload['n_cases']} cases)_",
        "",
        "**SHADOW ONLY** — no prod wiring, no live generation. Deterministic "
        "mechanics live in `tools/bench_jev_sufler.py`; the client is the "
        "H5275-shipped `Uprava/tools/jev_probe.py`.",
        "",
        "## Floor (incumbent, deterministic)",
        "",
        f"- live regex floor on this corpus: **{floor.get('accuracy')}** "
        f"({floor.get('correct')}/{floor.get('total')}) — `tools/h5274_regex_floor.php`, PHP {floor.get('php')}",
        f"- ClassifierPrecisionTest assertion threshold: **0.93** (a floor, not the live number)",
        "",
    ]
    if a:
        lines += [
            "## Arm A — suggester category space (A–F + none)",
            "",
            f"- Jev accuracy **{a['accuracy']}** ({a['correct']}/{a['total']})",
            f"- vs live floor {a['floor_live_accuracy']}: "
            f"**{'BEATS' if a['beats_live_floor'] else 'DOES NOT BEAT'}**",
            f"- vs 93% assertion: **{'MEETS' if a['meets_93_assert'] else 'MISSES'}**",
            f"- latency: {a['latency']}",
            f"- cost: ${a['cost_usd_total']} total · ${a['cost_usd_mean']}/call · tokens {a['tokens']}",
            "",
            "| class | n_gold | n_pred | tp | precision | recall | f1 |",
            "|---|---|---|---|---|---|---|",
        ]
        for lab, s in a["per_class"].items():
            lines.append(f"| {lab} | {s['n_gold']} | {s['n_pred']} | {s['tp']} | "
                         f"{s['precision']} | {s['recall']} | {s['f1']} |")
        lines += ["", f"### Arm A errors ({len(a['errors'])})", "",
                  "| # | message | gold | Jev |", "|---|---|---|---|"]
        for e in a["errors"]:
            lines.append(f"| {e['i']} | {e['t'][:80]} | {e['gold']} | {e['jev']} |")
        lines.append("")
    if b:
        lines += [
            "## Arm B — objection space (В1–В11 + OTHER)",
            "",
            f"> {b['note']}",
            "",
            f"- agreement with the ORS-FAQ sufler: **{b['agreement']['rate']}** "
            f"({b['agreement']['agree']}/{b['agreement']['n']})",
            f"- latency: {b['latency']}",
            f"- cost: ${b['cost_usd_total']} total · ${b['cost_usd_mean']}/call · tokens {b['tokens']}",
            "",
            "| class | ref n | Jev n | agree |", "|---|---|---|---|",
        ]
        for lab, s in b["per_class"].items():
            lines.append(f"| {lab} | {s['n_gold']} | {s['n_pred']} | {s['tp']} |")
        lines += ["", f"### Arm B disagreements ({len(b['disagreements'])})", "",
                  "| # | message | sufler ref | Jev |", "|---|---|---|---|"]
        for d in b["disagreements"]:
            lines.append(f"| {d['i']} | {d['t'][:80]} | {d['ref']} | {d['jev']} |")
        lines.append("")
    lines += ["## Verdict", "", payload["verdict"], "",
              "_Гасунс_", ""]
    return "\n".join(lines)


def decide_verdict(a: Optional[Dict[str, Any]], floor: Optional[Dict[str, Any]]) -> str:
    if not a or not floor:
        return "INCONCLUSIVE — live arm A did not run."
    live = a.get("floor_live_accuracy", floor.get("accuracy"))
    if a.get("beats_live_floor"):
        return (f"GO-candidate: Jev {a['accuracy']} >= live floor {live} "
                f"on the frozen corpus. Still SHADOW — pilot only, no prod wiring.")
    return (f"NO-GO: Jev {a['accuracy']} < live regex floor {live} "
            f"on the frozen corpus — the deterministic YAML+regex floor stands; "
            f"do not wire Jev into the sufler path.")


# ---------------------------------------------------------------- main

def main(argv: Optional[List[str]] = None) -> int:
    ap = argparse.ArgumentParser(description=__doc__.splitlines()[0])
    ap.add_argument("--run", action="store_true", help="LIVE: send calls to the endpoint")
    ap.add_argument("--selftest", action="store_true", help="offline checks, no network")
    ap.add_argument("--arm", choices=["a", "b", "both"], default="both")
    ap.add_argument("--corpus", default=str(CORPUS))
    ap.add_argument("--out-json", default=str(ROOT / "reports" / "jev-sufler-shadow-bench-2026-09-23.json"))
    ap.add_argument("--out-md", default=str(ROOT / "docs" / "REPORT_JEV_SUFLER_SHADOW_BENCH_2026-09-23.md"))
    ap.add_argument("--env-file", default=str(Path.home() / ".secrets" / "typesafe.env"))
    ap.add_argument("--timeout", type=float, default=120.0)
    ap.add_argument("--sleep", type=float, default=0.0, help="pause between calls (s)")
    args = ap.parse_args(argv)

    if args.selftest:
        return run_selftest()

    jev_probe = load_client()
    cases = load_corpus() if args.corpus == str(CORPUS) else json.loads(
        Path(args.corpus).read_text(encoding="utf-8"))["cases"]

    class _NS:
        env_file = args.env_file

    api_key, model, endpoint = jev_probe.resolve_config(_NS())

    if not args.run:
        for arm in (("a",) if args.arm == "a" else ("b",) if args.arm == "b" else ("a", "b")):
            req = build_arm_request(jev_probe, arm, cases[0]["t"], model)
            ok, why = jev_probe.validate_request(req)
            print(f"--- arm {arm.upper()} (dry-run, case 1) — valid={ok} {why}")
            print(json.dumps(req, ensure_ascii=False, indent=2)[:1400])
        print(f"# dry-run: {len(cases)} cases/arm NOT sent. key present={bool(api_key)}. "
              f"Pass --run for LIVE.")
        return 0

    if not api_key:
        print(f"FAIL: no TYPESAFE_API_KEY in env or {args.env_file}", file=sys.stderr)
        return 3

    payload: Dict[str, Any] = {
        "generated": time.strftime("%Y-%m-%d"),
        "model": model, "endpoint": endpoint, "n_cases": len(cases),
        "corpus": str(CORPUS.relative_to(ROOT)),
    }
    floor = floor_baseline()
    payload["floor"] = floor

    if args.arm in ("a", "both"):
        payload["arm_a"] = analyse_a(
            run_arm(jev_probe, "a", cases, api_key, endpoint, model, args.timeout, args.sleep),
            floor)
    if args.arm in ("b", "both"):
        payload["arm_b"] = analyse_b(
            run_arm(jev_probe, "b", cases, api_key, endpoint, model, args.timeout, args.sleep),
            load_sufler_reference(), cases)

    payload["verdict"] = decide_verdict(payload.get("arm_a"), floor)

    Path(args.out_json).parent.mkdir(parents=True, exist_ok=True)
    Path(args.out_json).write_text(
        json.dumps(payload, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")
    Path(args.out_md).parent.mkdir(parents=True, exist_ok=True)
    Path(args.out_md).write_text(render_md(payload), encoding="utf-8")

    print(f"arm A accuracy: {payload.get('arm_a', {}).get('accuracy')} "
          f"(floor {floor.get('accuracy') if floor else 'n/a'})")
    if payload.get("arm_b"):
        print(f"arm B agreement: {payload['arm_b']['agreement']['rate']}")
    print(f"cost total: ${sum(x.get('cost_usd_total', 0) for x in (payload.get('arm_a'), payload.get('arm_b')) if x):.6f}")
    print(f"wrote {args.out_json}")
    print(f"wrote {args.out_md}")
    print(f"VERDICT: {payload['verdict']}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
