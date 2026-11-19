#!/usr/bin/env python3
"""H6305 — deterministic morphology lesson-pack packager (portable preview).

Selects 20 corpus-attested drill items from kosha's morphology-curriculum
lesson 1 (a-stems: m_a / n_a / f_A) and packages them into the existing
lyw-pack-v1 / lyw-quiz-v1 schema consumed by LessonPackRepository +
LessonPackController (H3521 reader). No content is generated: prompt, options
and answer are copied VERBATIM from the pinned kosha drill rows — answers
equal source answers by construction, verified by --verify.

Selection rule (deterministic, no RNG):
  1. curriculum rows with lesson == 1 (all carry n_attested_cells >= 1,
     asserted); their lemma_iast set is the lesson-1 join key;
  2. drills.json items joined on lemma_iast, pools per type (fill, match)
     sorted by (rank, id);
  3. per type, take the first 10 items whose (lemma_iast, cell_label) pair
     is unseen in that type AND whose lemma has no item yet in that type —
     so the 20 items cover the 10 most-core lesson-1 lemmas, each drilled
     both directions (produce the form / identify the cell).
Syncretism (e.g. artham = m-acc = n-nom/acc) is preserved as-is: it is a
property of the attested source, not edited here.

Outputs into --out (default resources/data/lesson_packs_morphology/zan1/base):
  manifest.json, quizzes.json  — generated
  personalized_text.md, views/mindmap.mmd — AUTHORED files, only checked
      (every item id and prompt must appear in the text; --verify fails else)
Plus --snapshot (default resources/data/lesson_packs_morphology/
source_snapshot.json): the verbatim pinned source rows for independent
verification without a kosha checkout.

Usage:
  python3 scripts/build_morphology_lessonpack.py --kosha <path> [--out <dir>] \
      [--snapshot <file>] [--verify]
"""

from __future__ import annotations

import argparse
import csv
import hashlib
import json
import subprocess
import sys
from pathlib import Path

N_PER_TYPE = 10
TEACHER_SAMPLE = 5
PACK_SCHEMA = "lyw-pack-v1"
QUIZ_SCHEMA = "lyw-quiz-v1"
LICENSE = (
    "kosha morphology-curriculum / morphology-drills, CC BY-SA 4.0 "
    "(credit Dr. Marcis Gasuns; upstream DCS by Oliver Hellwig / DCS)"
)
SELECTION_RULE = (
    "curriculum lesson==1 rows (all n_attested_cells>=1); drills joined on "
    "lemma_iast; per type (fill,match) sorted by (rank,id) take first 10 "
    "items with unseen (lemma_iast,cell_label) AND unseen lemma in that type"
)

SECTION_BY_MODEL = {
    "m_a": ("masc-a-stems", "Мужской род: a-основы (-aḥ)"),
    "n_a": ("neut-a-stems", "Средний род: a-основы (-am)"),
    "f_A": ("fem-a-stems", "Женский род: ā-основы (-ā)"),
}


def sha256(path: Path) -> str:
    h = hashlib.sha256()
    with path.open("rb") as fh:
        for chunk in iter(lambda: fh.read(1 << 16), b""):
            h.update(chunk)
    return h.hexdigest()


def write_json(path: Path, payload: dict) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    path.write_text(
        json.dumps(payload, ensure_ascii=False, indent=2) + "\n", encoding="utf-8"
    )


def load_sources(kosha: Path) -> tuple[list[dict], list[dict], dict[str, list[dict]]]:
    curriculum_path = kosha / "data/morphology/morphology_curriculum.tsv"
    drills_path = kosha / "data/morphology/drills.json"
    with curriculum_path.open(encoding="utf-8") as fh:
        curriculum = list(csv.DictReader(fh, delimiter="\t"))
    lesson1 = [r for r in curriculum if r["lesson"] == "1"]
    bad = [r for r in lesson1 if int(r["n_attested_cells"]) < 1]
    if bad:
        raise SystemExit(f"unattested curriculum rows in lesson 1: {bad[:3]}")
    by_lemma: dict[str, list[dict]] = {}
    for row in lesson1:
        by_lemma.setdefault(row["lemma_iast"], []).append(row)

    drills = json.loads(drills_path.read_text(encoding="utf-8"))["items"]
    joined = [i for i in drills if i.get("lemma_iast") in by_lemma]
    return lesson1, joined, by_lemma


def select_items(joined: list[dict]) -> list[dict]:
    picked: list[dict] = []
    for item_type in ("fill", "match"):
        pool = sorted(
            (i for i in joined if i["type"] == item_type),
            key=lambda i: (i["rank"], i["id"]),
        )
        seen_cells: set[tuple[str, str]] = set()
        seen_lemmas: set[str] = set()
        for item in pool:
            cell_key = (item["lemma_iast"], item["cell_label"])
            if cell_key in seen_cells or item["lemma_iast"] in seen_lemmas:
                continue
            seen_cells.add(cell_key)
            seen_lemmas.add(item["lemma_iast"])
            picked.append(item)
            if len([p for p in picked if p["type"] == item_type]) == N_PER_TYPE:
                break
    total = sum(1 for _ in picked)
    if total != 2 * N_PER_TYPE:
        raise SystemExit(f"selection produced {total} items, expected {2 * N_PER_TYPE}")
    return picked


def primary_model(by_lemma: dict[str, list[dict]], lemma: str) -> str:
    # First curriculum row (TSV order = rank order within lesson) for the lemma.
    return by_lemma[lemma][0]["model"]


def build_pack(picked: list[dict], by_lemma: dict[str, list[dict]], meta: dict) -> dict:
    items, trace = [], {}
    sections: dict[str, dict] = {}

    for item in picked:
        options = list(item["choices"])
        answer_index = options.index(item["answer"])
        items.append(
            {
                "id": item["id"],
                "type": "mcq",
                "concept": [item["lemma_iast"], item["cell_label"]],
                "prompt": item["prompt"],
                "options": options,
                "answer_index": answer_index,
            }
        )
        trace[item["id"]] = {
            "source_dataset": item["source_dataset"],
            "source_row_id": item["id"],
            "attested": True,
            "evidence": item["evidence"],
            "answer_equals_source": item["answer"],
            "corpus_count": item["corpus_count"],
            "curriculum_rows": [
                {
                    "rank": r["rank"],
                    "lemma_slp1": r["lemma_slp1"],
                    "lemma_iast": r["lemma_iast"],
                    "model": r["model"],
                    "bucket": r["bucket"],
                    "core_rank": int(r["core_rank"]),
                    "n_attested_cells": int(r["n_attested_cells"]),
                    "corpus_count": r["corpus_count"],
                    "lesson": int(r["lesson"]),
                }
                for r in by_lemma[item["lemma_iast"]]
            ],
        }
        sec_key, sec_title = SECTION_BY_MODEL[primary_model(by_lemma, item["lemma_iast"])]
        sec = sections.setdefault(
            sec_key,
            {"id": sec_key, "title": sec_title, "source_concepts": [], "embedded_questions": []},
        )
        if item["lemma_iast"] not in sec["source_concepts"]:
            sec["source_concepts"].append(item["lemma_iast"])
        sec["embedded_questions"].append(item["id"])

    manifest = {
        "schema": PACK_SCHEMA,
        "zan": 1,
        "profile": {"level": "base", "interest": "base"},
        "source": {
            "kosha_commit": meta["kosha_commit"],
            "morphology_curriculum_sha256": meta["curriculum_sha"],
            "drills_sha256": meta["drills_sha"],
            "lesson_marker": "Морфология · урок 1 (a-основы)",
            "attested": True,
            "license": LICENSE,
            "selection_rule": SELECTION_RULE,
        },
        "sections": list(sections.values()),
        "views": {"mindmap": "views/mindmap.mmd"},
        "quizzes": "quizzes.json",
        "mnemonics": meta["mnemonics"],
        "teacher_sample": [i["id"] for i in picked[:TEACHER_SAMPLE]],
        "generated": {"date": meta["date"], "session": "H6305-GLM"},
    }
    quizzes = {
        "schema": QUIZ_SCHEMA,
        "items": items,
        "source_trace": trace,
        "license": LICENSE,
    }
    return {"manifest": manifest, "quizzes": quizzes}


def snapshot(picked: list[dict], by_lemma: dict[str, list[dict]], meta: dict) -> dict:
    return {
        "kosha_commit": meta["kosha_commit"],
        "morphology_curriculum_sha256": meta["curriculum_sha"],
        "drills_sha256": meta["drills_sha"],
        "selection_rule": SELECTION_RULE,
        "license": LICENSE,
        "rows": [
            {
                "drill_row_verbatim": item,
                "curriculum_rows_lesson1": by_lemma[item["lemma_iast"]],
            }
            for item in picked
        ],
    }


def check_authored(out: Path, quizzes: dict) -> list[str]:
    problems = []
    text_path = out / "personalized_text.md"
    mindmap_path = out / "views/mindmap.mmd"
    if not text_path.is_file():
        problems.append(f"missing authored file: {text_path}")
    if not mindmap_path.is_file():
        problems.append(f"missing authored file: {mindmap_path}")
    if problems:
        return problems
    text = text_path.read_text(encoding="utf-8")
    for item in quizzes["items"]:
        if item["id"] not in text:
            problems.append(f"item id not embedded in text: {item['id']}")
        if item["prompt"] not in text:
            problems.append(f"prompt not embedded verbatim in text: {item['id']}")
    return problems


def main() -> int:
    ap = argparse.ArgumentParser()
    ap.add_argument("--kosha", required=True)
    ap.add_argument(
        "--out",
        default="resources/data/lesson_packs_morphology/zan1/base",
    )
    ap.add_argument(
        "--snapshot",
        default="resources/data/lesson_packs_morphology/source_snapshot.json",
    )
    ap.add_argument("--date", default="2026-10-11")
    ap.add_argument("--verify", action="store_true")
    ap.add_argument(
        "--bootstrap",
        action="store_true",
        help="first run: write generated JSONs before the authored text exists",
    )
    args = ap.parse_args()

    kosha = Path(args.kosha).resolve()
    out = Path(args.out)
    snap_path = Path(args.snapshot)

    commit = subprocess.run(
        ["git", "-C", str(kosha), "rev-parse", "HEAD"],
        capture_output=True, text=True, check=True,
    ).stdout.strip()
    meta = {
        "kosha_commit": commit,
        "curriculum_sha": sha256(kosha / "data/morphology/morphology_curriculum.tsv"),
        "drills_sha": sha256(kosha / "data/morphology/drills.json"),
        "date": args.date,
        "mnemonics": [
            {
                "for": "neuter syncretism",
                "sentence": "У среднего рода именительный и винительный всегда одинаковы: artham — это и «(тот) предмет», и «(в/на) предмет» — контекст решает.",
            },
            {
                "for": "m_a -aḥ",
                "sentence": "Мужской a-стем оканчивается на -aḥ в именительном: dharmaḥ, arthaḥ — «ḥ» на конце слова сигнализирует мужской подлежащий.",
            },
            {
                "for": "-e endings",
                "sentence": "На -e кончаются и locative singular (arthe «в смысле»), и nominative dual (arthe «два смысла») — число падежа подскажет контекст.",
            },
        ],
    }

    lesson1, joined, by_lemma = load_sources(kosha)
    picked = select_items(joined)
    pack = build_pack(picked, by_lemma, meta)

    problems = check_authored(out, pack["quizzes"]) if not args.bootstrap else []
    if problems:
        for p in problems:
            print("AUTHORED-FILE PROBLEM:", p, file=sys.stderr)
        return 2

    if args.verify:
        ok = True
        for name, payload in (
            ("manifest.json", pack["manifest"]),
            ("quizzes.json", pack["quizzes"]),
        ):
            committed = json.loads((out / name).read_text(encoding="utf-8"))
            if committed != payload:
                print(f"VERIFY FAIL: {name} differs from deterministic rebuild", file=sys.stderr)
                ok = False
        committed_snap = json.loads(snap_path.read_text(encoding="utf-8"))
        if committed_snap != snapshot(picked, by_lemma, meta):
            print("VERIFY FAIL: source_snapshot.json differs", file=sys.stderr)
            ok = False
        # answers equal source answers — byte check against pinned snapshot rows
        rows = {r["drill_row_verbatim"]["id"]: r["drill_row_verbatim"] for r in committed_snap["rows"]}
        for item in pack["quizzes"]["items"]:
            src = rows[item["id"]]
            if item["options"] != src["choices"] or item["options"][item["answer_index"]] != src["answer"]:
                print(f"VERIFY FAIL: answer drift for {item['id']}", file=sys.stderr)
                ok = False
        print("VERIFY:", "PASS" if ok else "FAIL",
              f"(kosha {commit[:12]}, {len(picked)} items)")
        return 0 if ok else 1

    write_json(out / "manifest.json", pack["manifest"])
    write_json(out / "quizzes.json", pack["quizzes"])
    write_json(snap_path, snapshot(picked, by_lemma, meta))
    print(
        f"packed {len(picked)} items -> {out} (kosha {commit[:12]}, "
        f"curriculum sha {meta['curriculum_sha'][:12]}, drills sha {meta['drills_sha'][:12]})"
    )
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
