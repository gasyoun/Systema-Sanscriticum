#!/usr/bin/env python3
"""PK-72 exercise-checker hire-exam stand (H3397).

Frozen-sample exam harness for the virtual-office role "Проверяющий упражнений ПК-72".
pass^k >= 0.95 over N=10 real submissions in a row; verdicts are checked
mechanically against the frozen manifest, never against the candidate's words.

Subcommands:
  validate      schema + (if frozen) integrity check of the manifest
  freeze        lock the sample: hash every work/assignment/key file, stamp manifest
  run           grade the frozen series once, per-step state checks
  replay        recompute every recorded match from artifacts and prove N/N
  memo-scaffold emit the per-work verdict table for the hire memo
  selftest      end-to-end synthetic fixture (never touches real works)

Data guardrails: student personal data stays out of git. Works, assignments,
keys and analysis artifacts live under the gitignored hiring-quarantine/
directory; committed files carry submission ids only.
"""

from __future__ import annotations

import argparse
import hashlib
import json
import math
import subprocess
import sys
import tempfile
import time
from datetime import datetime, timezone
from pathlib import Path
from typing import NoReturn

REPO_ROOT = Path(__file__).resolve().parents[2]
MANIFEST_DEFAULT = Path("docs/hiring/pk72_exam_manifest.json")
RESULTS_DEFAULT = Path("docs/hiring/pk72_exam_results.tsv")
QUARANTINE = Path("hiring-quarantine")
ARTIFACTS = QUARANTINE / "artifacts"
TSV_COLUMNS = [
    "run_ts",
    "work_id",
    "lesson",
    "candidate_id",
    "candidate_verdict",
    "expected_verdict",
    "match",
    "analysis_artifact",
    "manifest_sha256",
]
STAND_ERROR = "STAND_ERROR"
EXIT_USAGE = 2
EXIT_MANIFEST = 3
EXIT_STAND_DEFECT = 4
EXIT_REPLAY_MISMATCH = 5


def fail(code: int, message: str) -> NoReturn:
    print(f"FATAL: {message}", file=sys.stderr)
    raise SystemExit(code)


def sha256_file(path: Path) -> str:
    digest = hashlib.sha256()
    with path.open("rb") as handle:
        for chunk in iter(lambda: handle.read(65536), b""):
            digest.update(chunk)
    return digest.hexdigest()


def manifest_root(manifest_path: Path) -> Path:
    return manifest_path.resolve().parents[2]


def canonical_hash(payload: dict) -> str:
    return hashlib.sha256(
        json.dumps(payload, sort_keys=True, ensure_ascii=False).encode("utf-8")
    ).hexdigest()


def load_manifest(path: Path) -> dict:
    if not path.exists():
        fail(EXIT_MANIFEST, f"manifest not found: {path}")
    try:
        manifest = json.loads(path.read_text(encoding="utf-8"))
    except json.JSONDecodeError as exc:
        fail(EXIT_MANIFEST, f"manifest is not valid JSON: {exc}")
    validate_schema(manifest, path)
    return manifest


def validate_schema(manifest: dict, path: Path) -> None:
    required_top = {"role", "pass_k", "series_n", "frozen_at", "manifest_sha256", "works"}
    missing = required_top - set(manifest)
    if missing:
        fail(EXIT_MANIFEST, f"{path}: missing keys {sorted(missing)}")
    if manifest["role"] != "pk72-exercise-checker":
        fail(EXIT_MANIFEST, f"{path}: unexpected role {manifest['role']!r}")
    if manifest["series_n"] <= 0 or not 0 < manifest["pass_k"] <= 1:
        fail(EXIT_MANIFEST, f"{path}: pass_k/series_n out of range")
    works = manifest["works"]
    if not isinstance(works, list) or not works:
        fail(EXIT_MANIFEST, f"{path}: works must be a non-empty list")
    seen_ids = set()
    per_work_required = {
        "id",
        "lesson",
        "assignment_ref",
        "work_files",
        "key_ref",
        "expected_verdict",
    }
    for index, work in enumerate(works):
        missing_work = per_work_required - set(work)
        if missing_work:
            fail(EXIT_MANIFEST, f"{path}: works[{index}] missing {sorted(missing_work)}")
        if work["id"] in seen_ids:
            fail(EXIT_MANIFEST, f"{path}: duplicate work id {work['id']!r}")
        seen_ids.add(work["id"])
        if work["expected_verdict"] not in {"pass", "fail"}:
            fail(
                EXIT_MANIFEST,
                f"{path}: works[{index}].expected_verdict must be 'pass' or 'fail'",
            )


def assert_frozen(manifest: dict, path: Path, root: Path) -> None:
    if not manifest.get("frozen_at"):
        fail(
            EXIT_MANIFEST,
            f"{path}: sample is NOT frozen; freeze it before any run "
            "(see docs/hiring/pk72_exam_manifest.example.json)",
        )
    verify_frozen_integrity(manifest, path, root)


def verify_frozen_integrity(manifest: dict, path: Path, root: Path = REPO_ROOT) -> None:
    body = {key: value for key, value in manifest.items() if key != "manifest_sha256"}
    recomputed = canonical_hash(body)
    if recomputed != manifest["manifest_sha256"]:
        fail(
            EXIT_MANIFEST,
            f"{path}: manifest_sha256 mismatch — the frozen sample was edited after freeze",
        )
    for work in manifest["works"]:
        for ref in [work["assignment_ref"], work["key_ref"], *work["work_files"]]:
            ref_path = root / ref
            if not ref_path.is_file():
                fail(EXIT_MANIFEST, f"{path}: frozen file missing on disk: {ref}")
            actual = sha256_file(ref_path)
            recorded = work["file_sha256"].get(ref)
            if recorded != actual:
                fail(
                    EXIT_MANIFEST,
                    f"{path}: sha256 drift for {ref} (frozen sample changed)",
                )


def required_matches(manifest: dict) -> int:
    return math.ceil(manifest["pass_k"] * manifest["series_n"])


def cmd_validate(args: argparse.Namespace) -> int:
    manifest = load_manifest(args.manifest)
    status = "FROZEN" if manifest.get("frozen_at") else "UNFROZEN"
    print(f"manifest: {args.manifest}")
    print(f"status:   {status}")
    print(f"works:    {len(manifest['works'])} (series_n={manifest['series_n']})")
    if manifest.get("frozen_at"):
        verify_frozen_integrity(manifest, args.manifest, manifest_root(args.manifest))
        print("integrity: all file hashes match the freeze")
    elif len(manifest["works"]) != manifest["series_n"]:
        print(
            f"note: works={len(manifest['works'])} != series_n={manifest['series_n']}; "
            "freeze requires exactly series_n works"
        )
    return 0


def cmd_freeze(args: argparse.Namespace) -> int:
    manifest = load_manifest(args.manifest)
    root = manifest_root(args.manifest)
    if manifest.get("frozen_at"):
        fail(EXIT_MANIFEST, f"{args.manifest}: already frozen at {manifest['frozen_at']}")
    if len(manifest["works"]) != manifest["series_n"]:
        fail(
            EXIT_MANIFEST,
            f"{args.manifest}: freeze requires exactly series_n="
            f"{manifest['series_n']} works, found {len(manifest['works'])}",
        )
    for work in manifest["works"]:
        refs = [work["assignment_ref"], work["key_ref"], *work["work_files"]]
        hashes: dict[str, str] = {}
        for ref in refs:
            ref_path = root / ref
            if not ref_path.is_file():
                fail(EXIT_MANIFEST, f"freeze: referenced file missing: {ref}")
            hashes[ref] = sha256_file(ref_path)
        work["file_sha256"] = hashes
        if not str(work.get("expected_rationale", "")).strip():
            fail(
                EXIT_MANIFEST,
                f"freeze: work {work['id']!r} needs expected_rationale "
                "(why the key implies this verdict)",
            )
    manifest["frozen_at"] = datetime.now(timezone.utc).strftime("%Y-%m-%dT%H:%M:%SZ")
    manifest["manifest_sha256"] = canonical_hash(
        {key: value for key, value in manifest.items() if key != "manifest_sha256"}
    )
    args.manifest.write_text(
        json.dumps(manifest, ensure_ascii=False, indent=2) + "\n", encoding="utf-8"
    )
    verify_frozen_integrity(manifest, args.manifest, root)
    print(f"frozen:  {args.manifest}")
    print(f"at:      {manifest['frozen_at']}")
    print(f"sha256:  {manifest['manifest_sha256']}")
    return 0


def read_tsv(path: Path) -> list[dict[str, str]]:
    if not path.exists():
        return []
    lines = path.read_text(encoding="utf-8").splitlines()
    if not lines:
        return []
    header = lines[0].split("\t")
    if header != TSV_COLUMNS:
        fail(EXIT_MANIFEST, f"{path}: unexpected TSV header {header}")
    rows = []
    for number, line in enumerate(lines[1:], start=2):
        values = line.split("\t")
        if len(values) != len(header):
            fail(EXIT_MANIFEST, f"{path}:{number}: malformed row")
        rows.append(dict(zip(header, values)))
    return rows


def append_row_with_state_check(path: Path, row: dict[str, str]) -> None:
    before = read_tsv(path)
    path.parent.mkdir(parents=True, exist_ok=True)
    is_new = not path.exists()
    with path.open("a", encoding="utf-8", newline="") as handle:
        if is_new:
            handle.write("\t".join(TSV_COLUMNS) + "\n")
        handle.write("\t".join(row[column] for column in TSV_COLUMNS) + "\n")
    after = read_tsv(path)
    if len(after) != len(before) + 1 or after[-1] != row:
        fail(
            EXIT_STAND_DEFECT,
            f"{path}: state-check failed — appended row did not land verbatim "
            "(results file changed underneath the stand)",
        )


def consecutive_stand_errors(rows: list[dict[str, str]], work_id: str) -> int:
    streak = 0
    for row in reversed(rows):
        if row["work_id"] != work_id:
            continue
        if row["candidate_verdict"] == STAND_ERROR:
            streak += 1
        else:
            break
    return streak


def build_candidate_command(template: str, work: dict, artifact: Path, root: Path) -> list[str]:
    assignment = str(root / work["assignment_ref"])
    files = [str(root / ref) for ref in work["work_files"]]
    expanded_artifact = str(artifact)
    for value in [assignment, expanded_artifact, *files]:
        if " " in value:
            fail(
                EXIT_STAND_DEFECT,
                f"path contains a space, unsupported by the candidate contract: {value}",
            )
    rendered = (
        template.replace("{artifact}", expanded_artifact)
        .replace("{assignment}", assignment)
        .replace("{work_files}", " ".join(files))
        .replace("{work_id}", work["id"])
    )
    parts = rendered.split()
    if not parts:
        fail(EXIT_STAND_DEFECT, "candidate command template expanded to nothing")
    return parts


def invoke_candidate(parts: list[str], timeout: int) -> str:
    try:
        completed = subprocess.run(
            parts,
            cwd=str(REPO_ROOT),
            capture_output=True,
            text=True,
            encoding="utf-8",
            errors="replace",
            timeout=timeout,
        )
    except subprocess.TimeoutExpired:
        fail(EXIT_STAND_DEFECT, f"candidate exceeded {timeout}s timeout")
    except OSError as exc:
        fail(EXIT_STAND_DEFECT, f"candidate command failed to start: {exc}")
    if completed.returncode != 0:
        fail(
            EXIT_STAND_DEFECT,
            f"candidate exited {completed.returncode}: {completed.stderr.strip()[:400]}",
        )
    return completed.stdout


def extract_json_object(text: str) -> dict | None:
    start = text.find("{")
    while start != -1:
        depth = 0
        for position in range(start, len(text)):
            if text[position] == "{":
                depth += 1
            elif text[position] == "}":
                depth -= 1
                if depth == 0:
                    try:
                        return json.loads(text[start : position + 1])
                    except json.JSONDecodeError:
                        break
        start = text.find("{", start + 1)
    return None


def parse_verdict(stdout: str, artifact: Path) -> tuple[str, str]:
    candidates = [stdout.strip()]
    if artifact.exists():
        candidates.append(artifact.read_text(encoding="utf-8", errors="replace"))
    for blob in candidates:
        payload = extract_json_object(blob)
        if payload is None:
            continue
        verdict = str(payload.get("verdict", "")).strip().lower()
        analysis = str(payload.get("analysis", "")).strip()
        if verdict not in {"pass", "fail"}:
            continue
        return verdict, analysis or "(empty analysis)"
    fail(
        EXIT_STAND_DEFECT,
        f"no usable JSON verdict {{verdict, analysis}} in candidate output "
        f"(stdout or {artifact.name})",
    )


def print_summary(matches: int, total: int, manifest: dict, results_path: Path) -> None:
    print("")
    print(f"series complete: {matches}/{total} verdicts matched the frozen keys")
    print(f"threshold:       pass^k>={manifest['pass_k']} over n={manifest['series_n']} in a row")
    if total == manifest["series_n"] and matches >= required_matches(manifest):
        print("VERDICT:         HIRE (reading contour, trial)")
    elif total == manifest["series_n"]:
        print("VERDICT:         NO-HIRE — error classes go into the verdict memo")
    else:
        print(f"VERDICT:         INCOMPLETE series (stand errors present); see {results_path}")


def cmd_run(args: argparse.Namespace) -> int:
    manifest = load_manifest(args.manifest)
    root = manifest_root(args.manifest)
    assert_frozen(manifest, args.manifest, root)
    results_path = args.results
    prior_rows = read_tsv(results_path)
    active_hash = manifest["manifest_sha256"]
    stale = [
        row
        for row in prior_rows
        if row["manifest_sha256"] == active_hash
        and row["candidate_id"] == args.candidate_id
        and row["candidate_verdict"] != STAND_ERROR
    ]
    if stale:
        fail(
            EXIT_MANIFEST,
            f"{results_path}: this frozen sample was already graded by candidate "
            f"{args.candidate_id!r} ({len(stale)} rows); a frozen sample runs once "
            "per candidate",
        )
    artifacts_dir = root / ARTIFACTS
    artifacts_dir.mkdir(parents=True, exist_ok=True)
    matches = 0
    total = 0
    run_ts = datetime.now(timezone.utc).strftime("%Y-%m-%dT%H:%M:%SZ")
    run_ts_file = run_ts.replace(":", "")
    for order, work in enumerate(manifest["works"], start=1):
        streak = consecutive_stand_errors(prior_rows, work["id"])
        if streak >= 2:
            fail(
                EXIT_STAND_DEFECT,
                f"STOP_STAND_DEFECT: work {work['id']} hit {streak} consecutive stand "
                "errors across attempts — fix the stand, do not blame the candidate",
            )
        artifact = artifacts_dir / f"{run_ts_file}_{order:02d}_{work['id']}.md"
        parts = build_candidate_command(args.candidate, work, artifact, root)
        print(f"[{order}/{len(manifest['works'])}] work {work['id']} ...")
        try:
            stdout = invoke_candidate(parts, args.timeout)
            verdict, analysis = parse_verdict(stdout, artifact)
        except SystemExit as exc:
            if exc.code != EXIT_STAND_DEFECT:
                raise
            verdict, analysis = STAND_ERROR, ""
            if not artifact.exists():
                artifact.write_text(
                    "# STAND_ERROR\n\nstand failure before any candidate analysis\n",
                    encoding="utf-8",
                )
        artifact.write_text(
            f"# Work {work['id']} — lesson {work['lesson']}\n\n"
            f"verdict: {verdict}\n\n{analysis}\n",
            encoding="utf-8",
        )
        if verdict == STAND_ERROR:
            match = "error"
        else:
            match = "true" if verdict == work["expected_verdict"] else "false"
        row = {
            "run_ts": run_ts,
            "work_id": work["id"],
            "lesson": work["lesson"],
            "candidate_id": args.candidate_id,
            "candidate_verdict": verdict,
            "expected_verdict": work["expected_verdict"],
            "match": match,
            "analysis_artifact": artifact.relative_to(root).as_posix(),
            "manifest_sha256": active_hash,
        }
        append_row_with_state_check(results_path, row)
        prior_rows = read_tsv(results_path)
        if match == "true":
            matches += 1
        if match != "error":
            total += 1
        print(f"    verdict={verdict} expected={work['expected_verdict']} match={match}")
        time.sleep(args.pace_seconds)
    print_summary(matches, total, manifest, results_path)
    return 0


def cmd_replay(args: argparse.Namespace) -> int:
    manifest = load_manifest(args.manifest)
    root = manifest_root(args.manifest)
    assert_frozen(manifest, args.manifest, root)
    rows = read_tsv(args.results)
    if not rows:
        fail(EXIT_REPLAY_MISMATCH, f"{args.results}: no results to replay")
    known_ids = {work["id"] for work in manifest["works"]}
    mismatches: list[int] = []
    tallies: dict[tuple[str, str], dict[str, int]] = {}
    for number, row in enumerate(rows, start=1):
        if row["work_id"] not in known_ids:
            fail(
                EXIT_REPLAY_MISMATCH,
                f"{args.results}:{number}: unknown work_id {row['work_id']}",
            )
        artifact = root / row["analysis_artifact"]
        if not artifact.exists():
            fail(EXIT_REPLAY_MISMATCH, f"{args.results}:{number}: missing artifact {artifact}")
        if row["candidate_verdict"] == STAND_ERROR:
            recomputed = STAND_ERROR
        else:
            blob = artifact.read_text(encoding="utf-8", errors="replace").lower()
            if "verdict: pass" in blob:
                recomputed = "pass"
            elif "verdict: fail" in blob:
                recomputed = "fail"
            else:
                fail(
                    EXIT_REPLAY_MISMATCH,
                    f"{args.results}:{number}: artifact lacks a verdict line: {artifact}",
                )
        if recomputed != row["candidate_verdict"]:
            mismatches.append(number)
        key = (row["candidate_id"], row["manifest_sha256"])
        tally = tallies.setdefault(key, {"match": 0, "graded": 0})
        if row["match"] == "true":
            tally["match"] += 1
        if row["match"] != "error":
            tally["graded"] += 1
    if mismatches:
        for number in mismatches:
            print(f"replay mismatch at row {number}")
        return EXIT_REPLAY_MISMATCH
    print(f"replay: all {len(rows)} rows reproduce from their artifacts")
    for (candidate_id, manifest_hash), tally in sorted(tallies.items()):
        label = "this manifest" if manifest_hash == manifest["manifest_sha256"] else "other sample"
        print(f"  {candidate_id}: {tally['match']}/{tally['graded']} matched ({label})")
    return 0


def cmd_memo_scaffold(args: argparse.Namespace) -> int:
    rows = read_tsv(args.results)
    if not rows:
        fail(EXIT_REPLAY_MISMATCH, f"{args.results}: no results to scaffold from")
    today = datetime.now(timezone.utc).strftime("%d-%m-%Y")
    print(f"# H3397 — вердикт экзамена «Проверяющий упражнений ПК-72» ({today})")
    print("")
    print("| # | Работа | Урок | Вердикт кандидата | Ожидание по ключу | Совпадение | Артефакт |")
    print("|---|---|---|---|---|---|---|")
    for number, row in enumerate(rows, start=1):
        print(
            f"| {number} | {row['work_id']} | {row['lesson']} | {row['candidate_verdict']} "
            f"| {row['expected_verdict']} | {row['match']} | `{row['analysis_artifact']}` |"
        )
    print("")
    print("_Драфт для вердикт-мемо H3397; финализирует человек._")
    return 0


FAKE_CANDIDATE_SOURCE = "\n".join(
    [
        "import json, sys",
        "out_index = sys.argv.index('--out')",
        "payload = json.dumps({'verdict': 'fail', 'analysis': 'fixture analysis'})",
        "print(payload)",
        "open(sys.argv[out_index + 1], 'w', encoding='utf-8').write(payload)",
    ]
)


def make_fixture(root: Path, count: int = 2) -> dict:
    quarantine = root / QUARANTINE
    docs = root / "docs/hiring"
    docs.mkdir(parents=True)
    works = []
    for index in range(1, count + 1):
        work_dir = quarantine / "works" / f"fixture-{index:03d}"
        work_dir.mkdir(parents=True)
        (work_dir / "answer.txt").write_text(f"fixture answer {index}\n", encoding="utf-8")
        assignment = quarantine / "assignments" / f"fixture-{index:03d}.md"
        assignment.parent.mkdir(parents=True, exist_ok=True)
        assignment.write_text(f"fixture assignment {index}\n", encoding="utf-8")
        key = quarantine / "keys" / f"fixture-{index:03d}.md"
        key.parent.mkdir(parents=True, exist_ok=True)
        key.write_text(f"fixture key {index}\n", encoding="utf-8")
        works.append(
            {
                "id": f"fixture-{index:03d}",
                "lesson": f"Занятие {index}",
                "assignment_ref": assignment.relative_to(root).as_posix(),
                "work_files": [(work_dir / "answer.txt").relative_to(root).as_posix()],
                "key_ref": key.relative_to(root).as_posix(),
                "expected_verdict": "pass" if index % 2 else "fail",
                "expected_rationale": "fixture rationale",
            }
        )
    manifest = {
        "role": "pk72-exercise-checker",
        "pass_k": 0.95,
        "series_n": count,
        "frozen_at": None,
        "manifest_sha256": "",
        "works": works,
    }
    manifest_path = docs / "pk72_exam_manifest.json"
    manifest_path.write_text(
        json.dumps(manifest, ensure_ascii=False, indent=2) + "\n", encoding="utf-8"
    )
    fake = root / "fake_candidate.py"
    fake.write_text(FAKE_CANDIDATE_SOURCE, encoding="utf-8")
    return {
        "root": root,
        "manifest": manifest_path,
        "results": docs / "pk72_exam_results.tsv",
        "candidate": f"{sys.executable} {fake} --out {{artifact}}",
    }


def cmd_selftest(_args: argparse.Namespace) -> int:
    with tempfile.TemporaryDirectory(prefix="pk72-exam-selftest-") as tmp:
        fixture = make_fixture(Path(tmp), count=2)
        ns = argparse.Namespace(manifest=fixture["manifest"], results=fixture["results"])
        print("selftest: validate unfrozen")
        assert cmd_validate(ns) == 0
        print("selftest: run refuses an unfrozen sample")
        run_ns = argparse.Namespace(
            **vars(ns),
            candidate=fixture["candidate"],
            candidate_id="fixture-candidate",
            timeout=60,
            pace_seconds=0,
        )
        try:
            cmd_run(run_ns)
        except SystemExit as exc:
            assert exc.code == EXIT_MANIFEST, exc.code
        else:
            raise AssertionError("run accepted an unfrozen manifest")
        print("selftest: freeze")
        assert cmd_freeze(ns) == 0
        frozen = json.loads(fixture["manifest"].read_text(encoding="utf-8"))
        assert frozen["frozen_at"] and frozen["manifest_sha256"]
        print("selftest: double freeze refused")
        try:
            cmd_freeze(ns)
        except SystemExit as exc:
            assert exc.code == EXIT_MANIFEST, exc.code
        else:
            raise AssertionError("double freeze allowed")
        print("selftest: tampered sample refused")
        first_work = frozen["works"][0]
        tampered_file = Path(tmp) / first_work["work_files"][0]
        original_bytes = tampered_file.read_bytes()
        tampered_file.write_bytes(original_bytes + b"tampered\n")
        try:
            cmd_run(run_ns)
        except SystemExit as exc:
            assert exc.code == EXIT_MANIFEST, exc.code
        else:
            raise AssertionError("tampered sample ran")
        tampered_file.write_bytes(original_bytes)
        print("selftest: run full series (candidate always answers fail)")
        assert cmd_run(run_ns) == 0
        rows = read_tsv(fixture["results"])
        assert len(rows) == len(frozen["works"]), rows
        for row in rows:
            want = "true" if row["expected_verdict"] == "fail" else "false"
            assert row["match"] == want, row
        print("selftest: second grading of the same frozen sample refused")
        try:
            cmd_run(run_ns)
        except SystemExit as exc:
            assert exc.code == EXIT_MANIFEST, exc.code
        else:
            raise AssertionError("double grading allowed")
        print("selftest: replay reproduces every row")
        assert cmd_replay(argparse.Namespace(**vars(ns))) == 0
        print("selftest: replay catches a tampered result row")
        lines = fixture["results"].read_text(encoding="utf-8").splitlines()
        fields = lines[-1].split("\t")
        verdict_column = TSV_COLUMNS.index("candidate_verdict")
        assert fields[verdict_column] == "fail", fields
        fields[verdict_column] = "pass"
        lines[-1] = "\t".join(fields)
        fixture["results"].write_text("\n".join(lines) + "\n", encoding="utf-8")
        try:
            replay_code = cmd_replay(argparse.Namespace(**vars(ns)))
        except SystemExit as exc:
            replay_code = exc.code
        assert replay_code == EXIT_REPLAY_MISMATCH, replay_code
        print("selftest: memo-scaffold emits the table")
        scaffold = io_stringio_memo(argparse.Namespace(**vars(ns)))
        assert "| 1 |" in scaffold and "`hiring-quarantine/artifacts/" in scaffold
    print("selftest: OK")
    return 0


def io_stringio_memo(args: argparse.Namespace) -> str:
    import contextlib
    import io

    buffer = io.StringIO()
    with contextlib.redirect_stdout(buffer):
        assert cmd_memo_scaffold(args) == 0
    return buffer.getvalue()


def build_parser() -> argparse.ArgumentParser:
    parser = argparse.ArgumentParser(
        description=__doc__,
        formatter_class=argparse.RawDescriptionHelpFormatter,
    )
    subparsers = parser.add_subparsers(dest="command", required=True)

    def add_common(target: argparse.ArgumentParser) -> None:
        target.add_argument(
            "--manifest",
            type=Path,
            default=REPO_ROOT / MANIFEST_DEFAULT,
            help=f"path to the exam manifest (default {MANIFEST_DEFAULT})",
        )

    def add_results(target: argparse.ArgumentParser) -> None:
        target.add_argument(
            "--results",
            type=Path,
            default=REPO_ROOT / RESULTS_DEFAULT,
            help=f"path to the results TSV (default {RESULTS_DEFAULT})",
        )

    validate_parser = subparsers.add_parser("validate", help="check manifest structure/integrity")
    add_common(validate_parser)
    validate_parser.set_defaults(func=cmd_validate)

    freeze_parser = subparsers.add_parser("freeze", help="hash and lock the sample")
    add_common(freeze_parser)
    freeze_parser.set_defaults(func=cmd_freeze)

    run_parser = subparsers.add_parser("run", help="grade the frozen series once")
    add_common(run_parser)
    add_results(run_parser)
    run_parser.add_argument(
        "--candidate",
        required=True,
        help=(
            "command template with placeholders {work_files} {assignment} "
            "{artifact} {work_id}; the candidate never receives the key"
        ),
    )
    run_parser.add_argument("--candidate-id", required=True, help="label recorded in the TSV")
    run_parser.add_argument("--timeout", type=int, default=600)
    run_parser.add_argument("--pace-seconds", type=float, default=0.0)
    run_parser.set_defaults(func=cmd_run)

    replay_parser = subparsers.add_parser("replay", help="prove recorded matches from artifacts")
    add_common(replay_parser)
    add_results(replay_parser)
    replay_parser.set_defaults(func=cmd_replay)

    memo_parser = subparsers.add_parser(
        "memo-scaffold", help="emit the per-work verdict table for the hire memo"
    )
    add_common(memo_parser)
    add_results(memo_parser)
    memo_parser.set_defaults(func=cmd_memo_scaffold)

    selftest_parser = subparsers.add_parser(
        "selftest", help="synthetic end-to-end test of the stand mechanics"
    )
    selftest_parser.set_defaults(func=cmd_selftest)
    return parser


def main(argv: list[str] | None = None) -> int:
    try:
        sys.stdout.reconfigure(encoding="utf-8", errors="replace")  # type: ignore[union-attr]
    except Exception:
        pass
    parser = build_parser()
    args = parser.parse_args(argv)
    return args.func(args)


if __name__ == "__main__":
    sys.exit(main())
