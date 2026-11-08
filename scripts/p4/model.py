#!/usr/bin/env python3
"""P4 readiness manifest model (H7263 / H5446 preparation).

Report-only: builds a manifest from a read-only snapshot and computes the
independent controls. See scripts/p4/README.md for why this is a reference
model, not the production migration.
"""

from __future__ import annotations

import hashlib
import json
from typing import Any, Dict, Iterable, List, Optional, Sequence, Tuple

PERIODS: Tuple[Tuple[str, str, str, Tuple[str, ...]], ...] = (
    ("A", "2025-08-08", "2025-12-22", ("ip_maria",)),
    ("B", "2025-12-23", "2026-02-27", ("ip_maria", "ip_marcis")),
    ("C", "2026-02-28", "9999-12-31", ("ip_marcis",)),
)

OUTFLOW_TARIFFS = ("Расход", "salary_payout")

#: A material anomaly is at least this large, or belongs to a named invariant
#: class. The default is replaced by build_manifest() with P99.5 of the actual
#: in-boundary inflow, floored at 100_00 kopecks (1 000 RUB); see criterion().
DEFAULT_LARGE_KOPECKS = 100_00


def canonical(value: Any) -> str:
    return json.dumps(value, sort_keys=True, separators=(",", ":"), ensure_ascii=False)


def fingerprint(payload: Any) -> str:
    return "sha256:" + hashlib.sha256(canonical(payload).encode("utf-8")).hexdigest()


def period_of(day: Optional[str]) -> str:
    if not day:
        return "no_date"
    if day < PERIODS[0][1]:
        return "pre_ip"
    for code, start, end, _entities in PERIODS:
        if start <= day <= end:
            return code
    return "C"


def entities_of(period: str) -> Tuple[str, ...]:
    for code, _start, _end, entities in PERIODS:
        if code == period:
            return entities
    return ()


def recipient_for(period: str) -> Tuple[Optional[str], str]:
    """Period-level attribution is admissible only with ONE active entity.

    Returns (recipient, basis). In the D22 overlap (period B) two entities were
    active at once, so a date can never select the recipient: the result is
    (None, "primary_evidence_required").
    """
    entities = entities_of(period)
    if len(entities) == 1:
        return entities[0], "period_single_entity"
    return None, "primary_evidence_required"


def kopeck(value: Any) -> int:
    if value in (None, ""):
        return 0
    return int(round(float(value)))


def percentile(values: Sequence[int], q: float) -> int:
    if not values:
        return 0
    ordered = sorted(values)
    idx = min(len(ordered) - 1, max(0, int(q * (len(ordered) - 1))))
    return ordered[idx]


# ---------------------------------------------------------------------------
# Manifest construction (dry-run only)
# ---------------------------------------------------------------------------


def _derive_evidence_key(row: Dict[str, Any], split_keys: Dict[str, List[int]]) -> Optional[str]:
    kind = row.get("txn_kind") or "empty"
    txn = row.get("txn_ref")
    if not txn or kind in ("import_placeholder", "grant_placeholder"):
        # Importer labels and access-materialisation placeholders are NOT proof
        # of payment (P3 contract). Access grants are generated per payment.
        return None
    if txn in split_keys and len(split_keys[txn]) > 1:
        return "%s#%s" % (txn, row.get("tariff") or "unknown")
    return txn


def build_manifest(
    snapshot: Dict[str, Any],
    p1: Optional[Dict[str, Any]] = None,
    large_kopecks: Optional[int] = None,
) -> Dict[str, Any]:
    payments = snapshot.get("payments", [])
    p1 = p1 or {}
    anomaly_ids: Dict[int, List[str]] = {}
    for name, payload in (p1.get("anomalies") or {}).items():
        for pid in (payload or {}).get("payment_ids", []) or []:
            anomaly_ids.setdefault(int(pid), []).append(name)

    in_boundary: List[Dict[str, Any]] = []
    excluded: Dict[str, Dict[str, Any]] = {}

    def add_excluded(name: str, row: Dict[str, Any]) -> None:
        bucket = excluded.setdefault(name, {"class": name, "rows": 0, "kopecks": 0})
        bucket["rows"] += 1
        bucket["kopecks"] += kopeck(row.get("amount_kopecks"))

    # Same family population as P1: paid, not a refund, positive, not an
    # expense/payout, not a zero-amount conditional access row.
    for row in payments:
        status = row.get("status")
        amount = kopeck(row.get("amount_kopecks"))
        if status not in ("paid", "success"):
            add_excluded("not_paid", row)
            continue
        if row.get("refund_of"):
            add_excluded("refund_rows", row)
            continue
        if row.get("tariff") in OUTFLOW_TARIFFS:
            add_excluded("out_of_scope_expense_or_payout", row)
            continue
        if amount <= 0:
            add_excluded("nonpositive_receipt", row)
            continue
        if row.get("is_conditional"):
            add_excluded("conditional_zero_access", row)
            continue
        period = period_of(row.get("date"))
        if period == "pre_ip":
            add_excluded("pre_ip_history", row)
            continue
        in_boundary.append(row)

    if large_kopecks is None:
        amounts = [kopeck(r.get("amount_kopecks")) for r in in_boundary]
        large_kopecks = max(DEFAULT_LARGE_KOPECKS, percentile(amounts, 0.995))

    # One payment split into two block rows shares a transaction key; keep the
    # split explicit so it is not mistaken for a repeated payment.
    by_txn: Dict[str, List[int]] = {}
    for row in in_boundary:
        txn = row.get("txn_ref")
        if txn:
            by_txn.setdefault(txn, []).append(int(row["id"]))
    split_keys = {k: v for k, v in by_txn.items() if len(v) > 1}

    selected: List[Dict[str, Any]] = []
    for row in in_boundary:
        reasons: List[str] = []
        amount = kopeck(row.get("amount_kopecks"))
        if amount >= large_kopecks:
            reasons.append("amount_ge_%d_kopecks" % large_kopecks)
        if int(row["id"]) in anomaly_ids:
            # `evidence_reused` is an interpretation to resolve, not a reason to
            # migrate a row: importer labels and one-payment splits are excluded
            # as non-evidence, not selected as anomalies.
            reasons.extend([a for a in anomaly_ids[int(row["id"])] if a != "evidence_reused"])
        if row.get("tariff") == "deposit":
            reasons.append("deposit_held")
        if (row.get("received_account") or "school") != "school":
            reasons.append("direct_teacher_receipt")
        if not reasons:
            continue
        period = period_of(row.get("date"))
        recipient, basis = recipient_for(period)
        evidence = _derive_evidence_key(row, split_keys)
        selected.append(
            {
                "ext_id": "payment:%d" % int(row["id"]),
                "kind": "inflow",
                "occurred_on": row.get("date"),
                "amount_kopecks": amount,
                "currency": "RUB",
                "period": period,
                "recipient": recipient,
                "recipient_basis": basis,
                "recipient_evidence": None,
                "evidence_key": evidence,
                "reasons": sorted(set(reasons)),
                "student_ref": row.get("user_ref"),
                "course_id": row.get("course_id"),
            }
        )

    selected_ids = {int(m["ext_id"].split(":")[1]) for m in selected}
    closed = [r for r in in_boundary if int(r["id"]) not in selected_ids]
    if closed:
        excluded["closed_ordinary_history_d3"] = {
            "class": "closed_ordinary_history_d3",
            "rows": len(closed),
            "kopecks": sum(kopeck(r.get("amount_kopecks")) for r in closed),
            "reason": "D3: ordinary closed history is not reconstructed; only opening balances, open debts and material anomalies migrate",
        }

    debts = snapshot.get("debts") or []
    debt_summary = snapshot.get("debts_summary") or {}
    opening = [
        {
            "account": "student_funds_residue",
            "currency": "RUB",
            "amount_kopecks": int(((p1.get("totals") or {}).get("plan_residue_kopecks") or 0)),
            "basis": "P1 backfill plan residue (legacy money minus allocations)",
            "status": "unverified_recipient",
        },
        {
            "account": "open_debts_receivable",
            "currency": "RUB",
            "amount_kopecks": int(debt_summary.get("total_kopecks") or sum(kopeck(d.get("amount_kopecks")) for d in debts)),
            "basis": "DebtorsReport at snapshot; %d pairs, %d with missing tariffs" % (
                int(debt_summary.get("pairs") or len(debts)),
                int(debt_summary.get("missing_tariff_pairs") or 0),
            ),
            "status": "computed_current_recipient_unverified",
        },
        {
            "account": "cash_per_entity",
            "currency": "RUB",
            "amount_kopecks": None,
            "basis": "no bank balance import; bank_statement_credits covers 2026-08-01..2026-09-28 only",
            "status": "missing_source",
        },
        {
            "account": "teacher_payables",
            "currency": "RUB",
            "amount_kopecks": None,
            "basis": "P2 has 0 versioned terms; 105/105 legacy payouts unresolved_rate",
            "status": "unverified",
        },
    ]

    controls = compute_controls(selected, excluded, opening)

    payload = {
        "periods": [
            {
                "code": code,
                "from": start,
                "to": end,
                "active_entities": list(entities),
                "attribution_rule": "period_single_entity" if len(entities) == 1 else "primary_evidence_required",
            }
            for code, start, end, entities in PERIODS
        ],
        "boundary": {
            "history_from": PERIODS[0][1],
            "history_to": snapshot.get("generated_at", "")[:10],
            "cutover_as_of": snapshot.get("generated_at", "")[:10],
        },
        "large_anomaly_criterion": {
            "kopecks": large_kopecks,
            "rule": "amount >= max(1000 RUB, P99.5 of in-boundary inflow) OR named invariant anomaly",
        },
        "movements": selected,
        "opening_balances": opening,
        "excluded": [excluded[k] for k in sorted(excluded)],
        "debts": {
            "pairs": int(debt_summary.get("pairs") or len(debts)),
            "total_kopecks": int(debt_summary.get("total_kopecks") or 0),
            "missing_tariff_pairs": int(debt_summary.get("missing_tariff_pairs") or 0),
            "recipient": "unverified_at_cutover",
        },
        "teacher_terms": {
            "versioned_terms": 0,
            "legacy_payouts": len(snapshot.get("payouts") or []),
            "status": "unverified_legacy_sources",
        },
    }

    manifest = {
        "schema": "p4-manifest/1",
        "snapshot": {
            "taken_at": snapshot.get("generated_at"),
            "fingerprint": fingerprint(payload),
        },
        "payload": payload,
        "controls": controls,
    }
    return manifest


def compute_controls(
    movements: Iterable[Dict[str, Any]],
    excluded: Dict[str, Dict[str, Any]],
    opening: Iterable[Dict[str, Any]],
) -> Dict[str, Any]:
    buckets: Dict[str, Dict[str, int]] = {}
    total = 0
    per_obligation: Dict[str, int] = {}
    for m in movements:
        key = "%s|%s|%s" % (m.get("recipient") or "unattributed", m.get("period"), m.get("currency"))
        buckets.setdefault(key, {"rows": 0, "kopecks": 0})
        buckets[key]["rows"] += 1
        buckets[key]["kopecks"] += int(m.get("amount_kopecks") or 0)
        total += int(m.get("amount_kopecks") or 0)
        if m.get("course_id") is not None and m.get("student_ref"):
            okey = "%s|%s" % (m["student_ref"], m["course_id"])
            per_obligation[okey] = per_obligation.get(okey, 0) + int(m.get("amount_kopecks") or 0)

    excluded_total = sum(int(v.get("kopecks") or 0) for v in excluded.values())
    excluded_rows = sum(int(v.get("rows") or 0) for v in excluded.values())
    return {
        "by_recipient_period_currency": buckets,
        "movements_total_kopecks": total,
        "movements_rows": sum(v["rows"] for v in buckets.values()),
        "by_obligation_expected": per_obligation,
        "excluded": {
            "rows": excluded_rows,
            "kopecks": excluded_total,
            "classes": {k: int(v.get("rows") or 0) for k, v in excluded.items()},
        },
        "opening_balances": list(opening),
    }


# ---------------------------------------------------------------------------
# Isolated apply/verify engine
# ---------------------------------------------------------------------------
