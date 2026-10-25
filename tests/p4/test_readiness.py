#!/usr/bin/env python3
"""Isolated-DB tests for the P4 readiness harness (H7263 preparation).

Every test runs against a temporary SQLite file. No production connection, no
real data: fixtures are synthetic payments with kopecks only.
"""

from __future__ import annotations

import copy
import importlib.util
import json
import os
import sys
import tempfile
import unittest

ROOT = os.path.abspath(os.path.join(os.path.dirname(__file__), "..", ".."))
MODULE_PATH = os.path.join(ROOT, "scripts", "p4", "readiness.py")
spec = importlib.util.spec_from_file_location("p4_readiness", MODULE_PATH)
p4 = importlib.util.module_from_spec(spec)
sys.modules["p4_readiness"] = p4
spec.loader.exec_module(p4)  # type: ignore[union-attr]


def payment(pid, day, kopecks, *, user="u1", course=10, tariff="block_1", status="paid",
            txn=None, txn_kind="normal", refund_of=None, received="school", conditional=False):
    return {
        "id": pid, "date": day, "amount_kopecks": kopecks, "status": status,
        "user_ref": user, "course_id": course, "tariff": tariff, "txn_ref": txn,
        "txn_kind": txn_kind, "refund_of": refund_of, "received_account": received,
        "is_conditional": conditional,
    }


def snapshot(payments, debts=None, payouts=None):
    return {
        "generated_at": "2026-10-25T12:00:00+03:00",
        "payments": payments,
        "debts": debts or [],
        "debts_summary": {
            "pairs": len(debts or []),
            "total_kopecks": sum(d.get("amount_kopecks", 0) for d in (debts or [])),
            "missing_tariff_pairs": 0,
        },
        "payouts": payouts or [],
    }


def clean_snapshot():
    return snapshot([
        payment(1, "2025-09-01", 800_000, txn="txn-a"),
        payment(2, "2026-03-01", 800_000, txn="txn-c", user="u2"),
    ])


def manifest_for(snap, large=100_000):
    return p4.build_manifest(snap, large_kopecks=large)


def resign(manifest):
    """Recompute the fingerprint after a deliberate mutation (simulates a new dry run)."""
    manifest["snapshot"]["fingerprint"] = p4.fingerprint(manifest["payload"])
    return manifest


class Base(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        self.db = os.path.join(self.tmp.name, "p4.sqlite")

    def tearDown(self):
        self.tmp.cleanup()

    def applied(self, manifest, run_id="r1", **kwargs):
        return p4.apply_manifest(self.db, manifest, run_id=run_id, dry_run=False, **kwargs)


class TestPlan(Base):
    def test_fingerprint_is_stable(self):
        snap = clean_snapshot()
        a = manifest_for(snap)
        b = manifest_for(copy.deepcopy(snap))
        self.assertEqual(a["snapshot"]["fingerprint"], b["snapshot"]["fingerprint"])

    def test_pre_ip_and_expense_are_excluded_with_reasons(self):
        snap = snapshot([
            payment(1, "2024-01-01", 500_00, txn="old"),
            payment(2, "2025-09-01", 800_000, txn="a"),
            payment(3, "2026-03-01", -2_000_000, tariff="salary_payout", txn="s"),
        ])
        m = manifest_for(snap)
        classes = {e["class"] for e in m["payload"]["excluded"]}
        self.assertIn("pre_ip_history", classes)
        self.assertIn("out_of_scope_expense_or_payout", classes)
        self.assertEqual([x["ext_id"] for x in m["payload"]["movements"]], ["payment:2"])

    def test_import_label_is_not_evidence(self):
        snap = snapshot([payment(1, "2025-09-01", 800_000, txn="imp", txn_kind="import_placeholder")])
        m = manifest_for(snap)
        self.assertIsNone(m["payload"]["movements"][0]["evidence_key"])

    def test_one_payment_split_gets_distinct_evidence_keys(self):
        snap = snapshot([
            payment(1, "2026-03-01", 800_000, txn="split", tariff="block_1"),
            payment(2, "2026-03-01", 800_000, txn="split", tariff="block_2"),
        ])
        m = manifest_for(snap)
        keys = [x["evidence_key"] for x in m["payload"]["movements"]]
        self.assertEqual(keys, ["split#block_1", "split#block_2"])

    def test_overlap_recipient_is_unproven_not_guessed(self):
        snap = snapshot([payment(1, "2026-01-10", 800_000, txn="b")])
        m = manifest_for(snap)
        row = m["payload"]["movements"][0]
        self.assertIsNone(row["recipient"])
        self.assertEqual(row["recipient_basis"], "primary_evidence_required")


class TestRecipientControls(Base):
    def test_clean_manifest_passes(self):
        m = manifest_for(clean_snapshot())
        self.applied(m)
        result = p4.verify(self.db, m)
        self.assertEqual(result["verdict"], "PASS", json.dumps(result, ensure_ascii=False))

    def test_recipient_substitution_detected(self):
        m = manifest_for(clean_snapshot())
        self.applied(m)
        # Swap the recipient of the first movement; the grand total is unchanged.
        for row in m["payload"]["movements"]:
            if row["period"] == "A":
                row["recipient"] = "ip_marcis"
        resign(m)
        result = p4.verify(self.db, m)
        self.assertEqual(result["verdict"], "FAIL")
        names = {c["name"]: c for c in result["checks"]}
        self.assertTrue(names["grand_total_matches"]["ok"])
        self.assertFalse(names["recipient_period_currency_buckets_match"]["ok"])

    def test_mutually_compensating_errors_detected(self):
        m = manifest_for(clean_snapshot())
        self.applied(m)
        # Two swaps of equal size cancel in the grand total but not per bucket.
        a = [r for r in m["payload"]["movements"] if r["period"] == "A"][0]
        c = [r for r in m["payload"]["movements"] if r["period"] == "C"][0]
        a["recipient"], c["recipient"] = "ip_marcis", "ip_maria"
        resign(m)
        result = p4.verify(self.db, m)
        self.assertEqual(result["verdict"], "FAIL")
        names = {x["name"]: x for x in result["checks"]}
        self.assertTrue(names["grand_total_matches"]["ok"])
        self.assertFalse(names["recipient_period_currency_buckets_match"]["ok"])


class TestEvidenceAndInput(Base):
    def test_repeated_evidence_is_typed_exception_not_second_movement(self):
        snap = snapshot([
            payment(1, "2025-09-01", 800_000, txn="dup"),
            payment(2, "2025-09-02", 800_000, txn="dup", user="u2"),
        ])
        m = manifest_for(snap)
        self.applied(m)
        result = p4.verify(self.db, m)
        self.assertEqual(result["verdict"], "FAIL")
        self.assertTrue(any(e["code"] == "evidence_reused" for e in result["exceptions"]))
        import sqlite3
        conn = sqlite3.connect(self.db)
        self.assertEqual(conn.execute("SELECT COUNT(*) FROM movements").fetchone()[0], 1)

    def test_input_change_after_dry_run_refused(self):
        m = manifest_for(clean_snapshot())
        old = m["snapshot"]["fingerprint"]
        m["payload"]["movements"][0]["amount_kopecks"] += 1
        resign(m)  # a changed input that claims to be a fresh dry run
        with self.assertRaises(p4.Refused) as ctx:
            self.applied(m, expected_fingerprint=old)
        self.assertEqual(ctx.exception.code, "input_changed_after_dry_run")

    def test_untouched_manifest_cannot_be_silently_edited(self):
        m = manifest_for(clean_snapshot())
        m["payload"]["movements"][0]["amount_kopecks"] += 1
        with self.assertRaises(p4.Refused) as ctx:
            self.applied(m)
        self.assertEqual(ctx.exception.code, "input_changed_after_dry_run")

    def test_new_payment_between_snapshot_and_apply_refused(self):
        m = manifest_for(clean_snapshot())
        with self.assertRaises(p4.Refused) as ctx:
            self.applied(m, observed_ids=[1, 2, 999])
        self.assertEqual(ctx.exception.code, "new_payment_after_snapshot")

    def test_typed_exception_is_not_admissible_by_default(self):
        snap = snapshot([payment(1, "2026-01-10", 800_000, txn="b")])
        m = manifest_for(snap)
        self.applied(m)
        result = p4.verify(self.db, m)
        self.assertEqual(result["verdict"], "FAIL")
        self.assertTrue(any(e["code"] == "recipient_unproven" and e["admissible"] == 0 for e in result["exceptions"]))


class TestIdempotencyAndResume(Base):
    def test_rerun_is_a_replay(self):
        m = manifest_for(clean_snapshot())
        first = self.applied(m)
        second = self.applied(m)
        self.assertEqual(first["applied"], 2)
        self.assertEqual(second["mode"], "replay")
        import sqlite3
        conn = sqlite3.connect(self.db)
        self.assertEqual(conn.execute("SELECT COUNT(*) FROM movements").fetchone()[0], 2)

    def test_interrupted_apply_resumes_exactly_once(self):
        snap = snapshot([
            payment(1, "2025-09-01", 800_000, txn="a1"),
            payment(2, "2025-09-02", 800_000, txn="a2", user="u2"),
            payment(3, "2026-03-01", 800_000, txn="c1", user="u3"),
        ])
        m = manifest_for(snap)
        with self.assertRaises(p4.Interrupted):
            self.applied(m, fail_after=2)
        resumed = self.applied(m)
        self.assertEqual(resumed["applied"], 1)  # only the remainder
        result = p4.verify(self.db, m)
        self.assertEqual(result["verdict"], "PASS", json.dumps(result, ensure_ascii=False))
        import sqlite3
        conn = sqlite3.connect(self.db)
        self.assertEqual(conn.execute("SELECT COUNT(*) FROM movements").fetchone()[0], 3)
        self.assertEqual(conn.execute("SELECT COUNT(*) FROM movements GROUP BY ext_id HAVING COUNT(*)>1").fetchone(), None)


if __name__ == "__main__":
    unittest.main(verbosity=2)
