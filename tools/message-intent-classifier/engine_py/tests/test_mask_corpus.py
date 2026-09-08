"""Tests for tools/mask_corpus.py — synthetic fixtures only (no real data)."""

import json
import sys
from pathlib import Path

import pytest

TOOLS_DIR = Path(__file__).resolve().parents[2] / "tools"
sys.path.insert(0, str(TOOLS_DIR))

import mask_corpus  # noqa: E402

NAMES = ["Иван Петров", "Мария"]


def make_patterns():
    return mask_corpus.build_patterns(NAMES)


def write_dialog(dirpath: Path, dialog_id: str, lines):
    p = dirpath / f"dialog_{dialog_id}.txt"
    p.write_text("\n".join(lines) + "\n", encoding="utf-8")
    return p


@pytest.fixture
def corpus_dir(tmp_path):
    d = tmp_path / "raw_corpus"
    d.mkdir()
    write_dialog(d, "101", [
        "[2026-03-01] УЧЕНИК: Здравствуйте! Сколько стоит курс?",
        "[2026-03-01] КУРАТОР: Добрый день! Пишите на ivan@mail.ru или",
        "+7 900 123-45-67, сайт https://samskrtam.ru/faq и тг @curator_bot.",
        "[2026-03-02] УЧЕНИК: Оплатил картой 4111111111111111, чек ниже.",
        "",
        "вторя строка продолжения",
    ])
    write_dialog(d, "102", [
        "[2026-07-05] УЧЕНИК: Меня зовут Мария, хочу на йогу.",
    ])
    # file without parseable lines -> zero messages, must not crash
    (d / "dialog_empty.txt").write_text("мусор без формата\n", encoding="utf-8")
    return d


def test_mask_text_replaces_all_classes():
    masked, hit = mask_corpus.mask_text(
        "пишите на ivan@mail.ru, +7 900 123-45-67, @handle_name, "
        "https://example.ru/x, карта 4111111111111111",
        make_patterns())
    assert "ivan@mail.ru" not in masked
    assert "+7 900" not in masked
    assert "@handle_name" not in masked
    assert "https://" not in masked
    assert "4111111111111111" not in masked
    for ph in ("[EMAIL]", "[PHONE]", "[TG_HANDLE]", "[URL]", "[NUMBER]"):
        assert ph in masked
    assert set(hit) == {"email", "phone", "handle", "url", "digits"}


def test_mask_names_case_insensitive_word_bounded():
    masked, hit = mask_corpus.mask_text(
        "Меня зовут Мария, а не Марианна и не марияленд.",
        make_patterns())
    assert "[NAME]" in masked
    assert "Мария," not in masked
    assert "Марианна" in masked          # longer word untouched
    assert "марияленд" in masked         # glued token untouched
    assert "name" in hit


def test_parse_dialog_folds_multiline_and_maps_direction(corpus_dir):
    rows = list(mask_corpus.parse_dialog(corpus_dir / "dialog_101.txt"))
    assert len(rows) == 3
    assert [r["msg_id"] for r in rows] == [1, 2, 3]
    assert [r["direction"] for r in rows] == [
        "student", "curator", "student"]
    assert rows[1]["text"].endswith("тг @curator_bot.")
    assert rows[2]["text"] == (
        "Оплатил картой 4111111111111111, чек ниже.\n"
        "вторя строка продолжения")


def test_mask_then_validate_zero_hits(corpus_dir, tmp_path):
    out = tmp_path / "snap-masked.jsonl"
    names = tmp_path / "names.txt"
    names.write_text("\n".join(NAMES) + "\n", encoding="utf-8")
    assert mask_corpus.main(["mask", "--src", str(corpus_dir),
                             "--out", str(out), "--names", str(names)]) == 0
    lines = out.read_text(encoding="utf-8").splitlines()
    assert len(lines) == 4
    row = json.loads(lines[0])
    assert set(row) == set(mask_corpus.REQUIRED_KEYS)
    assert row["text_masked"] == "Здравствуйте! Сколько стоит курс?"
    assert mask_corpus.main(["validate", "--in", str(out),
                             "--names", str(names)]) == 0


def test_validate_stops_on_leaked_pii(tmp_path):
    bad = tmp_path / "bad.jsonl"
    bad.write_text(json.dumps({
        "dialog_id": "9", "msg_id": 1, "direction": "student",
        "date": "2026-07-05", "text_masked": "звоните +7 900 111-22-33"},
        ensure_ascii=False) + "\n", encoding="utf-8")
    assert mask_corpus.main(["validate", "--in", str(bad)]) == 1


def test_validate_catches_missing_keys_and_bad_date(tmp_path):
    bad = tmp_path / "bad.jsonl"
    bad.write_text(
        json.dumps({"dialog_id": "1", "direction": "student",
                    "date": "05.07.2026", "text_masked": "ок"}) + "\n",
        encoding="utf-8")
    assert mask_corpus.main(["validate", "--in", str(bad)]) == 1


def test_sample_checklist_shape(corpus_dir, tmp_path):
    out = tmp_path / "snap.jsonl"
    checklist = tmp_path / "checklist.md"
    mask_corpus.main(["mask", "--src", str(corpus_dir), "--out", str(out)])
    mask_corpus.main(["sample", "--in", str(out), "--out", str(checklist),
                      "--n", "3"])
    text = checklist.read_text(encoding="utf-8")
    assert text.count("- [ ]") == 3
    assert "STOP condition" in text
    assert "Sign-off" in text
    assert "Verdict" in text


def test_census_counts(corpus_dir, capsys):
    assert mask_corpus.main(["census", "--src", str(corpus_dir)]) == 0
    captured = capsys.readouterr().out
    assert "dialog files: 3" in captured
    assert "messages total: 4" in captured
