# -*- coding: utf-8 -*-
"""Golden-vector contract: both engines must reproduce vectors/golden.json exactly.

The Python side of the parity gate (PHP mirror: php/MessageClassifier/tests).
"""

import json
import sys
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parents[2]))

from engine_py.classifier import classify  # noqa: E402
from engine_py.loader import PLANES, load_package  # noqa: E402

REPO = Path(__file__).resolve().parents[2]
GOLDEN = REPO / "vectors" / "golden.json"


def _load_golden():
    return json.loads(GOLDEN.read_text(encoding="utf-8"))


def test_golden_count_at_least_60():
    doc = _load_golden()
    assert len(doc["vectors"]) >= 60


def test_golden_ids_unique():
    doc = _load_golden()
    ids = [v["id"] for v in doc["vectors"]]
    assert len(ids) == len(set(ids))


def test_negation_pair_present():
    ids = {v["id"] for v in _load_golden()["vectors"]}
    assert {"g-neg-a", "g-neg-b"} <= ids


def test_every_vector_reproduces_exactly():
    ruleset = load_package(REPO)
    doc = _load_golden()
    failures = []
    for vector in doc["vectors"]:
        got = classify(ruleset, vector["text"])
        expect = vector.get("expect", {})
        for plane in PLANES:
            want = expect.get(plane)
            have = got[plane]
            if want is None:
                if have is not None:
                    failures.append(f"{vector['id']}: plane={plane} expected null, got {have}")
            elif have != want:
                failures.append(f"{vector['id']}: plane={plane} expected {want}, got {have}")
    assert not failures, "\n".join(failures[:20])


def test_fired_categories_covered_at_least_four_times():
    doc = _load_golden()
    counts = {}
    reserved = {"topic": {"other_support"}}
    for vector in doc["vectors"]:
        for plane, tag in vector.get("expect", {}).items():
            if tag["category"] in reserved.get(plane, set()):
                continue
            key = (plane, tag["category"])
            counts[key] = counts.get(key, 0) + 1
    thin = [k for k, c in counts.items() if c < 4]
    assert not thin, f"categories with <4 golden vectors: {thin}"
