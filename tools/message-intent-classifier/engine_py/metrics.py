"""Per-category precision/recall metrics shared by the harness and tests."""

from __future__ import annotations

from typing import Iterable, Sequence

from .classifier import classify
from .loader import PLANES, RuleSet


def _empty_stats() -> dict:
    return {"tp": 0, "fp": 0, "fn": 0}


def evaluate(
    ruleset: RuleSet,
    records: Iterable[dict],
    planes: Sequence[str] = PLANES,
) -> dict:
    """Evaluate predictions against gold labels.

    Each record: {"id": ..., "text": ..., "gold": {plane: category|null, ...}}.
    Records without "gold" still count toward coverage denominators but never
    toward precision/recall counts.
    """
    stats: dict[str, dict[str, dict]] = {plane: {} for plane in planes}
    total = 0
    categorized: dict[str, int] = {plane: 0 for plane in planes}
    uncategorized: list[dict] = []

    for record in records:
        total += 1
        text = record.get("text", "")
        gold = record.get("gold") or {}
        prediction = classify(ruleset, text)
        hit_any = False
        for plane in planes:
            predicted = prediction.get(plane)
            bucket = stats[plane]
            if predicted is not None:
                hit_any = True
                categorized[plane] += 1
                entry = bucket.setdefault(predicted["category"], _empty_stats())
                entry["tp"] += 0  # keep key present even without gold
            label = gold.get(plane)
            if label is None:
                continue
            g_entry = bucket.setdefault(label, _empty_stats())
            if predicted is not None and predicted["category"] == label:
                g_entry["tp"] += 1
            elif predicted is not None:
                bucket.setdefault(predicted["category"], _empty_stats())["fp"] += 1
                g_entry["fn"] += 1
            else:
                g_entry["fn"] += 1
        if not hit_any:
            uncategorized.append({"id": record.get("id"), "text": text})

    per_plane: dict[str, dict] = {}
    for plane in planes:
        rows = {}
        for category, entry in sorted(stats[plane].items()):
            tp, fp, fn = entry["tp"], entry["fp"], entry["fn"]
            precision = tp / (tp + fp) if (tp + fp) else None
            recall = tp / (tp + fn) if (tp + fn) else None
            f1 = (
                2 * precision * recall / (precision + recall)
                if precision is not None and recall is not None and (precision + recall)
                else None
            )
            rows[category] = {
                "tp": tp,
                "fp": fp,
                "fn": fn,
                "n_gold": tp + fn,
                "n_pred": tp + fp,
                "precision": round(precision, 4) if precision is not None else None,
                "recall": round(recall, 4) if recall is not None else None,
                "f1": round(f1, 4) if f1 is not None else None,
            }
        denom = total or 1
        per_plane[plane] = {
            "categories": rows,
            "coverage": round(categorized[plane] / denom, 4),
            "total": total,
        }
    return {"planes": per_plane, "uncategorized": uncategorized}
