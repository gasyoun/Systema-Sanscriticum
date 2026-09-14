#!/usr/bin/env python
# -*- coding: utf-8 -*-
"""Chatito-style alias-driven deterministic synthetic-utterance generator (H4609).

Every category of taxonomy v2 gets positive templates (alias slots rendered by
a seeded PRNG -> byte-identical output on every run) and negative probes
(utterances that must NOT land in a given category). Positives are frozen with
expect computed by the REFERENCE engine (same convention as the original
vectors/golden.json freeze); the generator hard-fails if an invariant breaks.

SYNTHETIC LABEL: every emitted utterance carries "synthetic": true and lives
under reports/ + the syn-* id block of vectors/golden.json. The synthetic
corpus NEVER counts toward the real-corpus precision gate (masked snapshots
under corpora/, freeze protocol in corpora/README.md) — it is smoke coverage
for new/changed planes and categories only.

Usage:
  python tools/gen_synthetic_golden.py --check            # verify invariants
  python tools/gen_synthetic_golden.py --write-corpus     # (re)write reports/synthetic-golden-corpus.jsonl
  python tools/gen_synthetic_golden.py --update-golden    # merge syn-* block + additive refresh into vectors/golden.json
"""

from __future__ import annotations

import argparse
import json
import random
import sys
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parents[1]))

from engine_py.classifier import classify  # noqa: E402
from engine_py.loader import PLANES, load_package  # noqa: E402

REPO = Path(__file__).resolve().parents[1]
GOLDEN = REPO / "vectors" / "golden.json"
CORPUS = REPO / "reports" / "synthetic-golden-corpus.jsonl"
SYN_PREFIX = "syn-"
SEED = 20260913

# Aliases (Chatito-style): slot -> variants. Rendering is deterministic.
ALIASES: dict[str, list[str]] = {
    "polite": ["Пожалуйста", "Подскажите", "Будьте добры", "Здравствуйте"],
    "polite_body": ["пожалуйста, подскажите", "будьте добры, подскажите"],
    "course_noun": ["курс по санскриту", "обучение", "базовый курс"],
    "money": ["оплатить", "оплату", "тариф", "цену", "стоимость"],
    "deposit_word": ["депозит", "вступительный взнос", "внести депозит"],
    "pause_word": ["паузу", "обучение", "занятия"],
}

# Positive templates: utterance -> expected (plane, category).
POSITIVES: list[dict] = [
    # --- topic children (H4609) ---
    {"plane": "topic", "category": "refund", "templates": [
        "Как оформить возврат средств за {course_noun}?",
        "Хочу вернуть деньги за курс, с чего начать оформление?",
        "Скажите, {polite_body}, как запросить возврат оплаты за обучение?",
        "Возможен ли возврат денег за оплаченный курс?",
    ]},
    {"plane": "topic", "category": "pause", "templates": [
        "Можно взять паузу в обучении на месяц?",
        "Как поставить занятия на паузу, {polite_body}?",
        "Хочу приостановить обучение из-за работы.",
        "Возможна ли заморозка курса на время отпуска?",
    ]},
    {"plane": "topic", "category": "deposit", "templates": [
        "Нужно ли вносить депозит до старта {course_noun}?",
        "Какой размер вступительного взноса?",
        "{polite}, куда внести депозит за курс?",
        "Взнос за обучение оплачивается отдельно или входит в стоимость?",
    ]},
    {"plane": "topic", "category": "installment", "templates": [
        "Можно оплатить курс в рассрочку?",
        "Можно ли разбить платёж за {course_noun} на части?",
        "Возможна ли оплата частями за {course_noun}?",
        "{polite}, можно ли оплачивать частями, без полной суммы сразу?",
    ]},
    {"plane": "topic", "category": "access_window", "templates": [
        "Сколько времени будут доступны записи занятий?",
        "Какой срок доступа к записям курса?",
        "Записи доступны навсегда или окно ограничено?",
        "Будут ли записи доступны после окончания обучения?",
    ]},
    # --- funnel_stage ---
    {"plane": "funnel_stage", "category": "course", "templates": [
        "Сколько стоит {course_noun} и как записаться?",
        "{polite}, как оплатить курс и есть ли скидки?",
        "Когда стартует ближайшая группа, хочу записаться.",
        "Какой тариф на обучение и нужна ли предоплата?",
    ]},
    {"plane": "funnel_stage", "category": "consultation", "templates": [
        "Я пропустила занятия, где посмотреть записи урока?",
        "Хочу взять паузу в обучении, с кем это обсудить?",
        "Как оформить возврат средств за курс?",
        "В каком видео можно пересмотреть лекцию по Гите?",
    ]},
    {"plane": "funnel_stage", "category": "serve_only", "templates": [
        "Не могу найти ссылку на зум, {polite_body}.",
        "Платформа виснет на середине урока.",
        "Где найти методичку второго модуля?",
        "Кабинет не открывается после ввода пароля.",
    ]},
    # --- escalation ---
    {"plane": "escalation", "category": "churn_risk", "templates": [
        "Хочу вернуть деньги и не продолжать обучение.",
        "Как отменить подписку и не платить дальше?",
        "Не хочу продолжать курс, что с оплатой?",
        "Как отписаться от курса полностью?",
    ]},
    {"plane": "escalation", "category": "frustrated", "templates": [
        "Опять не работает ссылка, сколько можно!",
        "Никто не отвечает уже неделю, это игнор?",
        "Второй раз пишу про одну и ту же ошибку.",
        "Когда уже починят кабинет, надоело!",
    ]},
    {"plane": "escalation", "category": "calm", "templates": [
        "{polite}, подскажите, когда занятие?",
        "Здравствуйте, буду благодарна за помощь с расписанием.",
        "Добрый день, позовите, пожалуйста, куратора.",
        "Спасибо! Осталось понять, как войти на занятие.",
    ]},
    # --- resolution ---
    {"plane": "resolution", "category": "needs_human", "templates": [
        "Как оплатить курс и можно ли вернуть деньги?",
        "Кабинет не открывается, пароль не подходит.",
        "Хочу обсудить тариф и паузу в оплате.",
        "Нет доступа к оплате, нужен человек.",
    ]},
    {"plane": "resolution", "category": "faq_hit", "templates": [
        "Где найти методичку и конспекты к урокам?",
        "{polite}, как получить сертификат по окончании?",
        "Домашнее задание первого урока где смотреть?",
        "Какие учебники нужны для курса?",
    ]},
    {"plane": "resolution", "category": "auto_answerable", "templates": [
        "Когда будет следующее занятие и по какому расписанию?",
        "Пришлите, {polite_body}, ссылку на зум.",
        "Где посмотреть видеозаписи прошлых уроков?",
        "Во сколько начинается урок в субботу?",
    ]},
]

# Negative probes: utterance + (plane, category) that must NOT fire.
NEGATIVES: list[dict] = [
    {"text": "Возможен возврат, если курс не подойдёт?", "plane": "topic", "category": "refund"},
    {"text": "Верните деньги за оплаченный блок.", "plane": "topic", "category": "refund"},
    {"text": "Какая цена курса и есть ли рассрочка?", "plane": "topic", "category": "installment"},
    {"text": "А есть скидки или рассрочка платежа?", "plane": "topic", "category": "installment"},
    {"text": "Есть ли предоплата за второй блок?", "plane": "topic", "category": "deposit"},
    {"text": "Записи не открываются в кабинете.", "plane": "topic", "category": "access_window"},
    {"text": "Нет доступа к материалам второго модуля.", "plane": "topic", "category": "access_window"},
    {"text": "Ссылка на запись не работает, напишите в техподдержку.", "plane": "funnel_stage", "category": "consultation"},
    {"text": "Как отписаться от вашей рассылки?", "plane": "escalation", "category": "churn_risk"},
    {"text": "Спасибо, всё получилось!", "plane": "escalation", "category": "frustrated"},
    {"text": "Где посмотреть видеозаписи уроков?", "plane": "resolution", "category": "needs_human"},
    {"text": "Сколько стоит курс по санскриту?", "plane": "resolution", "category": "auto_answerable"},
]


def render(template: str, rng: random.Random) -> str:
    out = template
    while "{" in out:
        head, rest = out.split("{", 1)
        slot, rest = rest.split("}", 1)
        out = head + rng.choice(sorted(ALIASES[slot])) + rest
    return out


def build_vectors() -> list[dict]:
    ruleset = load_package(REPO)
    rng = random.Random(SEED)
    vectors: list[dict] = []
    index = 0
    for spec in POSITIVES:
        for template in sorted(spec["templates"]):
            text = render(template, rng)
            got = classify(ruleset, text)
            tag = got[spec["plane"]]
            if tag is None or tag["category"] != spec["category"]:
                raise SystemExit(
                    f"POSITIVE invariant broken: {text!r} -> "
                    f"{spec['plane']}={tag}, expected {spec['category']}"
                )
            index += 1
            vectors.append({
                "id": f"{SYN_PREFIX}p-{index:03d}",
                "text": text,
                "synthetic": True,
                # Full non-null expect, same contract as the frozen block;
                # the intended-category invariant is asserted above.
                "expect": {p: got[p] for p in PLANES if got[p] is not None},
            })
    for spec in sorted(NEGATIVES, key=lambda s: s["text"]):
        got = classify(ruleset, spec["text"])
        tag = got[spec["plane"]]
        if tag is not None and tag["category"] == spec["category"]:
            raise SystemExit(
                f"NEGATIVE invariant broken: {spec['text']!r} unexpectedly "
                f"classified {spec['plane']}={spec['category']}"
            )
        index += 1
        vectors.append({
            "id": f"{SYN_PREFIX}n-{index:03d}",
            "text": spec["text"],
            "synthetic": True,
            "expect": {p: got[p] for p in PLANES if got[p] is not None},
        })
    return vectors


def write_corpus(vectors: list[dict]) -> None:
    CORPUS.parent.mkdir(parents=True, exist_ok=True)
    with open(CORPUS, "w", encoding="utf-8") as fh:
        for v in vectors:
            gold = {p: tags["category"] for p, tags in v["expect"].items()}
            fh.write(json.dumps({
                "id": v["id"], "text": v["text"], "synthetic": True, "gold": gold,
            }, ensure_ascii=False) + "\n")
    print(f"corpus written: {CORPUS} ({len(vectors)} synthetic rows)")


def update_golden(vectors: list[dict]) -> None:
    doc = json.loads(GOLDEN.read_text(encoding="utf-8"))
    kept = [v for v in doc["vectors"] if not v["id"].startswith(SYN_PREFIX)]
    if len(kept) == len(doc["vectors"]):
        # First merge: additive refresh of existing vectors' expects (new planes
        # only — existing-plane expectations must stay byte-identical).
        ruleset = load_package(REPO)
        refreshed = []
        for v in kept:
            got = classify(ruleset, v["text"])
            expect = dict(v.get("expect", {}))
            for plane in PLANES:
                if plane in expect:
                    if expect[plane] != got[plane]:
                        raise SystemExit(
                            f"GOLDEN FLIP on existing plane: {v['id']} plane={plane} "
                            f"expected {expect[plane]}, got {got[plane]} — STOP, fix rules"
                        )
                elif got[plane] is not None:
                    expect[plane] = got[plane]
            refreshed.append({**v, "expect": expect})
        kept = refreshed
    doc["vectors"] = kept + vectors
    doc["synthetic_block"] = {
        "generator": "tools/gen_synthetic_golden.py",
        "seed": SEED,
        "count": len(vectors),
        "note": "synthetic-labeled smoke coverage; never counts toward the real-corpus precision gate",
    }
    doc["note"] += " H4609: +плоскости funnel_stage/escalation/resolution, +дети topic (refund/pause/deposit/installment/access_window), +synthetic-блок syn-* (генератор tools/gen_synthetic_golden.py, seed 20260913)."
    GOLDEN.write_text(json.dumps(doc, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")
    print(f"golden updated: {GOLDEN} ({len(kept)} frozen + {len(vectors)} synthetic)")


# Categories introduced by taxonomy v2 (H4609): the synthetic corpus alone
# must give each of them >= 4 golden positives (test_fired_categories_
# covered_at_least_four_times). Incidental cross-plane fires on LEGACY
# categories are absorbed by the frozen block's existing coverage.
V2_CATEGORIES: set[tuple[str, str]] = {
    ("topic", "refund"), ("topic", "pause"), ("topic", "deposit"),
    ("topic", "installment"), ("topic", "access_window"),
    ("funnel_stage", "course"), ("funnel_stage", "consultation"),
    ("funnel_stage", "serve_only"),
    ("escalation", "calm"), ("escalation", "frustrated"),
    ("escalation", "churn_risk"),
    ("resolution", "auto_answerable"), ("resolution", "faq_hit"),
    ("resolution", "needs_human"),
}


def main(argv: list[str] | None = None) -> int:
    parser = argparse.ArgumentParser(prog="gen_synthetic_golden")
    parser.add_argument("--check", action="store_true", help="verify invariants only")
    parser.add_argument("--write-corpus", action="store_true", help="write reports/synthetic-golden-corpus.jsonl")
    parser.add_argument("--update-golden", action="store_true", help="merge into vectors/golden.json")
    args = parser.parse_args(argv)
    vectors = build_vectors()
    counts: dict[tuple[str, str], int] = {}
    for v in vectors:
        for plane, tag in v["expect"].items():
            key = (plane, tag["category"])
            if key in V2_CATEGORIES:
                counts[key] = counts.get(key, 0) + 1
    missing = sorted(f"{p}/{c}" for p, c in V2_CATEGORIES - set(counts))
    thin = sorted(f"{p}/{c}={n}" for (p, c), n in counts.items() if n < 4)
    if missing or thin:
        raise SystemExit(f"v2 categories with <4 synthetic positives: {missing + thin}")
    print(f"invariants OK: {len(vectors)} synthetic vectors, min per-category coverage 4")
    if args.write_corpus:
        write_corpus(vectors)
    if args.update_golden:
        update_golden(vectors)
    if not (args.check or args.write_corpus or args.update_golden):
        parser.print_help()
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
