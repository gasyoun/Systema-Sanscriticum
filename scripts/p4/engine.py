#!/usr/bin/env python3
"""P4 readiness isolated-SQLite apply/verify engine (H7263 / H5446 preparation).

Never connects to production. Every write goes to a caller-supplied SQLite file.
"""

from __future__ import annotations

import sqlite3
from datetime import datetime, timezone
from typing import Any, Dict, Iterable, List, Optional

from model import canonical, fingerprint

SCHEMA = """
CREATE TABLE IF NOT EXISTS meta (key TEXT PRIMARY KEY, value TEXT NOT NULL);
CREATE TABLE IF NOT EXISTS runs (
    run_id TEXT PRIMARY KEY,
    fingerprint TEXT NOT NULL,
    status TEXT NOT NULL,
    started_at TEXT NOT NULL,
    finished_at TEXT,
    applied INTEGER NOT NULL DEFAULT 0
);
CREATE TABLE IF NOT EXISTS applied (key TEXT PRIMARY KEY, run_id TEXT NOT NULL, at TEXT NOT NULL);
CREATE TABLE IF NOT EXISTS movements (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    ext_id TEXT NOT NULL UNIQUE,
    kind TEXT NOT NULL,
    occurred_on TEXT,
    amount_kopecks INTEGER NOT NULL,
    currency TEXT NOT NULL,
    period TEXT NOT NULL,
    recipient TEXT,
    recipient_basis TEXT,
    recipient_evidence TEXT,
    evidence_key TEXT UNIQUE,
    student_ref TEXT,
    course_id INTEGER
);
CREATE TABLE IF NOT EXISTS obligations (
    ext_id TEXT PRIMARY KEY,
    student_ref TEXT,
    course_id INTEGER,
    amount_kopecks INTEGER NOT NULL,
    delivered INTEGER NOT NULL DEFAULT 0
);
CREATE TABLE IF NOT EXISTS allocations (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    movement_ext_id TEXT NOT NULL,
    obligation_ext_id TEXT NOT NULL,
    amount_kopecks INTEGER NOT NULL
);
CREATE TABLE IF NOT EXISTS opening_balances (
    account TEXT NOT NULL,
    currency TEXT NOT NULL,
    amount_kopecks INTEGER,
    basis TEXT,
    status TEXT,
    PRIMARY KEY (account, currency)
);
CREATE TABLE IF NOT EXISTS exceptions (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    code TEXT NOT NULL,
    ref TEXT,
    detail TEXT,
    admissible INTEGER NOT NULL DEFAULT 0
);
"""


class Refused(Exception):
    def __init__(self, code: str, detail: str):
        super().__init__("%s: %s" % (code, detail))
        self.code = code
        self.detail = detail


class Interrupted(Exception):
    pass


def connect(path: str) -> sqlite3.Connection:
    conn = sqlite3.connect(path)
    conn.row_factory = sqlite3.Row
    conn.executescript(SCHEMA)
    return conn


def now() -> str:
    return datetime.now(timezone.utc).isoformat(timespec="seconds")


def set_meta(conn: sqlite3.Connection, key: str, value: str) -> None:
    conn.execute("INSERT INTO meta(key,value) VALUES(?,?) ON CONFLICT(key) DO UPDATE SET value=excluded.value", (key, value))


def get_meta(conn: sqlite3.Connection, key: str) -> Optional[str]:
    row = conn.execute("SELECT value FROM meta WHERE key=?", (key,)).fetchone()
    return None if row is None else str(row["value"])


def _validate_manifest(manifest: Dict[str, Any]) -> str:
    payload = manifest.get("payload")
    if not isinstance(payload, dict) or "movements" not in payload:
        raise Refused("invalid_manifest", "payload.movements missing")
    actual = fingerprint(payload)
    claimed = (manifest.get("snapshot") or {}).get("fingerprint")
    if claimed != actual:
        raise Refused("input_changed_after_dry_run", "manifest payload no longer matches its recorded fingerprint")
    return actual


def _check_observed(manifest: Dict[str, Any], observed_ids: Optional[Iterable[int]]) -> None:
    if observed_ids is None:
        return
    known = {int(m["ext_id"].split(":")[1]) for m in manifest["payload"]["movements"]}
    new = sorted(set(int(i) for i in observed_ids) - known)
    if new:
        raise Refused(
            "new_payment_after_snapshot",
            "%d payment(s) exist in the source but not in the snapshot manifest, e.g. %s" % (len(new), new[:5]),
        )


def _insert_exception(conn: sqlite3.Connection, code: str, ref: str, detail: str, admissible: int = 0) -> None:
    conn.execute(
        "INSERT INTO exceptions(code,ref,detail,admissible) VALUES(?,?,?,?)",
        (code, ref, detail, admissible),
    )


def apply_manifest(
    db_path: str,
    manifest: Dict[str, Any],
    run_id: str,
    dry_run: bool = True,
    expected_fingerprint: Optional[str] = None,
    observed_ids: Optional[Iterable[int]] = None,
    fail_after: Optional[int] = None,
    batch_size: int = 10,
) -> Dict[str, Any]:
    """Apply the manifest to an isolated DB. dry_run=True never writes."""
    fp = _validate_manifest(manifest)
    if expected_fingerprint and expected_fingerprint != fp:
        raise Refused("input_changed_after_dry_run", "expected %s, manifest is %s" % (expected_fingerprint, fp))
    _check_observed(manifest, observed_ids)

    if dry_run:
        movements = manifest["payload"]["movements"]
        return {
            "mode": "dry_run",
            "run_id": run_id,
            "fingerprint": fp,
            "would_apply": len(movements),
            "would_exception": sum(1 for m in movements if m.get("recipient") is None or m.get("recipient_evidence") is None and m.get("period") == "B"),
            "writes": 0,
        }

    conn = connect(db_path)
    try:
        existing = conn.execute("SELECT status, fingerprint FROM runs WHERE run_id=?", (run_id,)).fetchone()
        if existing is not None and existing["status"] == "completed" and existing["fingerprint"] == fp:
            return {"mode": "replay", "run_id": run_id, "fingerprint": fp, "applied": _count(conn, "movements"), "writes": 0}
        if existing is None:
            conn.execute(
                "INSERT INTO runs(run_id,fingerprint,status,started_at) VALUES(?,?,?,?)",
                (run_id, fp, "running", now()),
            )
        else:
            conn.execute("UPDATE runs SET status='running' WHERE run_id=?", (run_id,))

        set_meta(conn, "snapshot_fingerprint", fp)
        applied = 0
        for m in manifest["payload"]["movements"]:
            key = str(m["ext_id"])
            if conn.execute("SELECT 1 FROM applied WHERE key=?", (key,)).fetchone():
                continue
            if fail_after is not None and applied >= fail_after:
                conn.commit()
                conn.execute("UPDATE runs SET status='interrupted', applied=? WHERE run_id=?", (applied, run_id))
                conn.commit()
                raise Interrupted("interrupted after %d movement(s)" % applied)

            recipient = m.get("recipient")
            period = m.get("period")
            evidence = m.get("recipient_evidence")
            if period == "B" and (recipient is None or not evidence):
                # Date is not evidence. The movement is quarantined, not guessed.
                _insert_exception(conn, "recipient_unproven", key, "D22 overlap: primary recipient evidence missing")
                conn.execute("INSERT OR IGNORE INTO applied(key,run_id,at) VALUES(?,?,?)", (key, run_id, now()))
                continue

            ekey = m.get("evidence_key")
            if ekey:
                dup = conn.execute("SELECT ext_id FROM movements WHERE evidence_key=?", (ekey,)).fetchone()
                if dup is not None:
                    _insert_exception(conn, "evidence_reused", key, "evidence already used by %s" % dup["ext_id"])
                    conn.execute("INSERT OR IGNORE INTO applied(key,run_id,at) VALUES(?,?,?)", (key, run_id, now()))
                    continue

            try:
                conn.execute(
                    "INSERT INTO movements(ext_id,kind,occurred_on,amount_kopecks,currency,period,recipient,recipient_basis,recipient_evidence,evidence_key,student_ref,course_id) "
                    "VALUES(?,?,?,?,?,?,?,?,?,?,?,?)",
                    (
                        key, m.get("kind") or "inflow", m.get("occurred_on"), int(m.get("amount_kopecks") or 0),
                        m.get("currency") or "RUB", period, recipient, m.get("recipient_basis"), evidence, ekey,
                        m.get("student_ref"), m.get("course_id"),
                    ),
                )
            except sqlite3.IntegrityError as exc:
                _insert_exception(conn, "unique_violation", key, str(exc))
                conn.execute("INSERT OR IGNORE INTO applied(key,run_id,at) VALUES(?,?,?)", (key, run_id, now()))
                continue

            if m.get("course_id") is not None and m.get("student_ref"):
                okey = "obligation:%s:%s" % (m["student_ref"], m["course_id"])
                conn.execute(
                    "INSERT OR IGNORE INTO obligations(ext_id,student_ref,course_id,amount_kopecks) VALUES(?,?,?,0)",
                    (okey, m["student_ref"], m["course_id"]),
                )
                conn.execute(
                    "INSERT INTO allocations(movement_ext_id,obligation_ext_id,amount_kopecks) VALUES(?,?,?)",
                    (key, okey, int(m.get("amount_kopecks") or 0)),
                )

            conn.execute("INSERT OR IGNORE INTO applied(key,run_id,at) VALUES(?,?,?)", (key, run_id, now()))
            applied += 1
            if applied % batch_size == 0:
                conn.commit()

        for b in manifest["payload"].get("opening_balances", []):
            conn.execute(
                "INSERT OR REPLACE INTO opening_balances(account,currency,amount_kopecks,basis,status) VALUES(?,?,?,?,?)",
                (b.get("account"), b.get("currency") or "RUB", b.get("amount_kopecks"), b.get("basis"), b.get("status")),
            )
        conn.execute("UPDATE runs SET status='completed', applied=?, finished_at=? WHERE run_id=?", (applied, now(), run_id))
        conn.commit()
        return {"mode": "apply", "run_id": run_id, "fingerprint": fp, "applied": applied, "writes": applied}
    finally:
        conn.close()


def _count(conn: sqlite3.Connection, table: str) -> int:
    return int(conn.execute("SELECT COUNT(*) c FROM %s" % table).fetchone()["c"])


def verify(db_path: str, manifest: Dict[str, Any]) -> Dict[str, Any]:
    conn = connect(db_path)
    try:
        stored = get_meta(conn, "snapshot_fingerprint")
        fp = fingerprint(manifest["payload"])
        checks: List[Dict[str, Any]] = []

        def check(name: str, ok: bool, detail: str = "") -> None:
            checks.append({"name": name, "ok": bool(ok), "detail": detail})

        check("fingerprint_matches", stored == fp, "stored=%s current=%s" % (stored, fp))

        # 1. Per-bucket controls (recipient/period/currency) — two compensating
        #    errors cannot hide behind a matching grand total. Expected values are
        #    re-derived from the manifest movements NOW, never trusted from the
        #    stale `controls` field a mutation would leave behind.
        expected: Dict[str, Dict[str, int]] = {}
        expected_total = 0
        expected_alloc = 0
        for m in manifest["payload"]["movements"]:
            key = "%s|%s|%s" % (m.get("recipient") or "unattributed", m.get("period"), m.get("currency"))
            expected.setdefault(key, {"rows": 0, "kopecks": 0})
            expected[key]["rows"] += 1
            expected[key]["kopecks"] += int(m.get("amount_kopecks") or 0)
            expected_total += int(m.get("amount_kopecks") or 0)
            if m.get("course_id") is not None and m.get("student_ref"):
                expected_alloc += int(m.get("amount_kopecks") or 0)
        actual_rows = conn.execute(
            "SELECT COALESCE(recipient,'unattributed') r, period p, currency c, COUNT(*) rows, SUM(amount_kopecks) k "
            "FROM movements GROUP BY r,p,c"
        ).fetchall()
        actual = {"%s|%s|%s" % (r["r"], r["p"], r["c"]): {"rows": int(r["rows"]), "kopecks": int(r["k"])} for r in actual_rows}
        actual_total = sum(v["kopecks"] for v in actual.values())
        check("grand_total_matches", expected_total == actual_total, "expected=%d actual=%d" % (expected_total, actual_total))
        bucket_ok = True
        diffs = []
        for key, exp in expected.items():
            act = actual.get(key, {"rows": 0, "kopecks": 0})
            if int(exp["rows"]) != act["rows"] or int(exp["kopecks"]) != act["kopecks"]:
                bucket_ok = False
                diffs.append({"bucket": key, "expected": exp, "actual": act})
        for key, act in actual.items():
            if key not in expected:
                bucket_ok = False
                diffs.append({"bucket": key, "expected": None, "actual": act})
        check("recipient_period_currency_buckets_match", bucket_ok, canonical(diffs))

        # 2. Obligation identity: allocations equal movement amounts for
        #    obligation-bearing rows.
        alloc = int(conn.execute("SELECT COALESCE(SUM(amount_kopecks),0) k FROM allocations").fetchone()["k"])
        check("obligation_allocations_match", alloc == expected_alloc, "expected=%d actual=%d" % (expected_alloc, alloc))

        # 3. Exceptions: typed is NOT admissible by default.
        ex = conn.execute("SELECT code, COUNT(*) c, SUM(admissible) a FROM exceptions GROUP BY code").fetchall()
        ex_rows = [{"code": r["code"], "count": int(r["c"]), "admissible": int(r["a"] or 0)} for r in ex]
        blocking = [e for e in ex_rows if e["code"] != "none" and e["admissible"] < e["count"]]
        check("no_non_admissible_exceptions", not blocking, canonical(blocking))

        # 4. Applied completeness: every manifest movement is applied or has a
        #    typed exception; nothing is silently missing.
        known = {str(m["ext_id"]) for m in manifest["payload"]["movements"]}
        applied_keys = {r["key"] for r in conn.execute("SELECT key FROM applied").fetchall()}
        missing = sorted(known - applied_keys)
        check("every_manifest_row_accounted_for", not missing, "missing=%s" % missing[:10])

        # 5. Unallocated residue is visible, never zeroed silently.
        residue = actual_total - alloc
        check("unallocated_residue_visible", True, "unallocated_kopecks=%d" % residue)

        verdict = "PASS" if all(c["ok"] for c in checks) else "FAIL"
        return {"verdict": verdict, "checks": checks, "exceptions": ex_rows, "unallocated_kopecks": residue}
    finally:
        conn.close()


# ---------------------------------------------------------------------------
# CLI
# ---------------------------------------------------------------------------
