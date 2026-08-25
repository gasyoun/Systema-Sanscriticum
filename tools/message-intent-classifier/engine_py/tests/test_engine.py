# -*- coding: utf-8 -*-
import sys
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parents[2]))

from engine_py.classifier import classify, normalize_text  # noqa: E402
from engine_py.loader import PLANES, RuleError, load_package  # noqa: E402

REPO = Path(__file__).resolve().parents[2]


def test_normalize_lower_and_yo():
    assert normalize_text("Ёлка ЁЖИК") == "елка ежик"


def test_normalize_whitespace_fold():
    assert normalize_text("  a\t\n b   c ") == "a b c"


def test_normalize_empty():
    assert normalize_text(None) == ""
    assert normalize_text("   ") == ""


def test_live_ruleset_loads():
    ruleset = load_package(REPO)
    for plane in PLANES:
        assert plane in ruleset.rules_by_plane


def test_priority_order_first_match_wins():
    ruleset = load_package(REPO)
    # «ссылку на расписание» — узкая рука schedule (p20) раньше zoom-широкой руки «ссылк» (p40)
    out = classify(ruleset, "Пришлите ссылку на расписание группы")
    assert out["topic"]["category"] == "schedule"
    # «нет доступа к материалам» — access_login (p70) раньше materials_content (p80)
    out = classify(ruleset, "Нет доступа к материалам второго модуля")
    assert out["topic"]["category"] == "access_login"


def test_negation_blocks_whole_rule():
    ruleset = load_package(REPO)
    # каноническая негативная пара: запись vs техподдержка
    positive = classify(ruleset, "Где скачать ссылку на запись занятия?")
    negative = classify(ruleset, "Ссылка на запись не открывается — это к техподдержке?")
    assert positive["topic"]["category"] == "recording_access"
    assert negative["topic"]["category"] == "tech_issue"


def test_planes_are_independent():
    ruleset = load_package(REPO)
    out = classify(ruleset, "Сколько стоит курс по санскриту?")
    assert out["topic"]["category"] == "payment_billing"
    assert out["intent"]["category"] == "price_query"
    assert out["objection"]["category"] == "b2_price"
    assert out["meta"] is None


def test_reason_carries_pattern():
    ruleset = load_package(REPO)
    out = classify(ruleset, "Где посмотреть видеозаписи уроков?")
    reason = out["topic"]["reason"]
    assert reason.startswith("keyword:")
    pattern = reason.split(":", 1)[1]
    assert pattern in {p.pattern for p in ruleset.rules_by_plane["topic"][0].patterns}


def test_empty_text_all_null():
    ruleset = load_package(REPO)
    out = classify(ruleset, "")
    assert out == {plane: None for plane in PLANES}


def test_unknown_rule_key_rejected(tmp_path):
    bad = tmp_path / "rules"
    bad.mkdir()
    (bad / "meta.yaml").write_text(
        "rules:\n  - plane: meta\n    category: urgent\n    priority: 1\n    patterns:\n      - 'x'\n    bogus: 1\n",
        encoding="utf-8",
    )
    tax = tmp_path / "taxonomy"
    tax.mkdir()
    (tax / "meta.yaml").write_text(
        "plane: meta\ncategories:\n  - key: urgent\n    title: u\n    description: d\n",
        encoding="utf-8",
    )
    import pytest

    with pytest.raises(RuleError):
        load_package(tmp_path)


def test_forbidden_regex_token_rejected(tmp_path):
    import pytest

    bad = tmp_path / "rules"
    bad.mkdir()
    (bad / "meta.yaml").write_text(
        "rules:\n  - plane: meta\n    category: urgent\n    priority: 1\n    patterns:\n      - 'join\\\\b'\n",
        encoding="utf-8",
    )
    tax = tmp_path / "taxonomy"
    tax.mkdir()
    (tax / "meta.yaml").write_text(
        "plane: meta\ncategories:\n  - key: urgent\n    title: u\n    description: d\n",
        encoding="utf-8",
    )
    with pytest.raises(RuleError):
        load_package(tmp_path)


def test_category_must_exist_in_taxonomy(tmp_path):
    import pytest

    (tmp_path / "rules").mkdir()
    (tmp_path / "rules" / "meta.yaml").write_text(
        "rules:\n  - plane: meta\n    category: nope\n    priority: 1\n    patterns:\n      - 'x'\n",
        encoding="utf-8",
    )
    (tmp_path / "taxonomy").mkdir()
    (tmp_path / "taxonomy" / "meta.yaml").write_text(
        "plane: meta\ncategories:\n  - key: urgent\n    title: u\n    description: d\n",
        encoding="utf-8",
    )
    with pytest.raises(RuleError):
        load_package(tmp_path)
