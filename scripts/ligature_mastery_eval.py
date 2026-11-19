#!/usr/bin/env python3
"""ligature_mastery_eval.py — тройка метрик освоения лигатур + harm (H6394).

Одна команда пересчитывает ВСЕ числа из закоммиченного снапшота SRS-пилота
и контента лигатурной колоды — без БД, без сети:

    python3 scripts/ligature_mastery_eval.py            # таблица (human)
    python3 scripts/ligature_mastery_eval.py --json     # машиночитаемо

Спека (формулы, знаменатели, пороги): docs/SPEC_LIGATURE_MASTERY_METRICS_2026.md
Снапшот снимается: scripts/export_srs_pilot_snapshot.php (см. спеку, §Снапшот).

Выход: 0 — harm=0 (канарейка чиста); 2 — harm>0 (ложные канонические формы).
Критерий «освоено» (per MG ruling 10-10): M1 ≥ 0.90 И M2 ≥ 0.90 И M3 ≥ 0.
"""
from __future__ import annotations

import argparse
import json
import re
import sys
import unicodedata
from pathlib import Path

_here = Path(__file__).resolve().parent
REPO = _here if (_here / "composer.json").exists() else _here.parent
DEFAULT_SNAPSHOT = REPO / "scripts/data/srs_pilot_snapshot_2026-10-10.json"
DEFAULT_CONTENT = REPO / "public/lila/ligatures/data.js"

VIRAMA = "\u094d"
DEVA_CONS = "\u0915-\u0939"  # क..ह — согласные деванагари
ZWJ, ZWNJ = "\u200d", "\u200c"

CONSONANT_IAST = {
    "क": "k", "ख": "kh", "ग": "g", "घ": "gh", "ङ": "ṅ",
    "च": "c", "छ": "ch", "ज": "j", "झ": "jh", "ञ": "ñ",
    "ट": "ṭ", "ठ": "ṭh", "ड": "ḍ", "ढ": "ḍh", "ण": "ṇ",
    "त": "t", "थ": "th", "द": "d", "ध": "dh", "न": "n",
    "प": "p", "फ": "ph", "ब": "b", "भ": "bh", "म": "m",
    "य": "y", "र": "r", "ल": "l", "व": "v", "श": "ś",
    "ष": "ṣ", "स": "s", "ह": "h",
}

CONJUNCT_RE = re.compile(rf"^[{DEVA_CONS}](?:{VIRAMA}[{DEVA_CONS}])+$")


def parse_content_ligatures(path: Path) -> list[dict]:
    """Достаёт уникальные лигатуры из LIGATURE_BANDS (банды кумулятивны)."""
    src = path.read_text(encoding="utf-8")
    rows = re.findall(
        r'\{\s*rank:\s*(\d+),\s*devanagari:\s*"([^"]+)",\s*iast:\s*"([^"]+)"',
        src,
    )
    seen: dict[str, dict] = {}
    for rank, deva, iast in rows:
        if deva not in seen:
            seen[deva] = {"rank": int(rank), "devanagari": deva, "iast": iast}
    out = sorted(seen.values(), key=lambda r: r["rank"])
    if not out:
        raise SystemExit(f"no ligature rows parsed from {path}")
    return out


def canonical_violations(forms: list[dict]) -> list[dict]:
    """Ложные канонические формы: любая причина = вред (harm)."""
    bad = []
    for f in forms:
        s = f["devanagari"]
        reasons = []
        if s != unicodedata.normalize("NFC", s):
            reasons.append("not-NFC")
        for ch in s:
            if not ("\u0900" <= ch <= "\u097f"):
                reasons.append(f"non-devanagari U+{ord(ch):04X}")
                break
        if ZWJ in s or ZWNJ in s:
            reasons.append("invisible-joiner")
        if any(unicodedata.category(ch) in ("Zs", "Cc", "Cf") for ch in s):
            reasons.append("space/control-char")
        if not CONJUNCT_RE.match(s):
            reasons.append("not-C(vC)+-conjunct")
        else:
            cons = s.split(VIRAMA)
            rom = "".join(CONSONANT_IAST.get(c, "?") for c in cons)
            if rom != f["iast"].lower() or "?" in rom:
                reasons.append(f"iast-mismatch(join={rom} field={f['iast']})")
        if reasons:
            bad.append({"rank": f["rank"], "devanagari": s, "reasons": reasons})
    return bad


def acc(rows: list[dict]) -> tuple[float | None, int, int]:
    """(доля non-Again, числитель, знаменатель). Знаменатель 0 -> None."""
    if not rows:
        return None, 0, 0
    ok = sum(1 for r in rows if r["rating"] != 1)
    return ok / len(rows), ok, len(rows)


def fmt(x: float | None, num: int, den: int) -> str:
    return "N/A (знаменатель 0)" if x is None else f"{x:.3f}  [{num}/{den}]"


def main() -> int:
    ap = argparse.ArgumentParser(description=__doc__)
    ap.add_argument("--snapshot", type=Path, default=DEFAULT_SNAPSHOT)
    ap.add_argument("--content", type=Path, default=DEFAULT_CONTENT)
    ap.add_argument("--json", action="store_true", help="machine-readable output")
    args = ap.parse_args()

    snap = json.loads(args.snapshot.read_text(encoding="utf-8"))
    logs = snap["review_logs"]
    probes = snap.get("twins_probes", [])
    decks = {d["id"]: d for d in snap["decks"]}
    lig_deck_ids = {i for i, d in decks.items() if "ligature" in (d["slug"] or "").lower()}
    lig_card_ids = {c["id"] for c in snap.get("ligature_cards", [])}

    lig_logs = [l for l in logs if l["deck_id"] in lig_deck_ids or l["card_id"] in lig_card_ids]

    # --- M1: удержание на интервале >= 21 д ----------------------------------
    def m1(rows):
        elig = [r for r in rows if r["scheduled_days"] >= 21]
        return acc(elig)

    # --- M2: точность узнавания ----------------------------------------------
    def m2(rows):
        return acc(rows)

    # --- M3: дельта на близнецах (пробы близнец-дискриминации) ---------------
    tw = [p for p in probes if p.get("is_twins") is True]
    ct = [p for p in probes if p.get("is_twins") is False]
    a_tw, n_tw, d_tw = acc(tw)
    a_ct, n_ct, d_ct = acc(ct)
    m3 = None if a_tw is None or a_ct is None else a_tw - a_ct

    # --- harm: каноничность контента колоды -----------------------------------
    content = parse_content_ligatures(args.content)
    fronts = [
        {"rank": -(i + 1), "devanagari": c["front"], "iast": ""}
        for i, c in enumerate(snap.get("ligature_cards", []))
    ]
    viol = canonical_violations(content + fronts)
    harm = len(viol)

    # --- per-user «освоено» (лигатурный срез, пороги спеки) -------------------
    mastered = 0
    mastered_eligible_users = set()
    if lig_deck_ids or lig_card_ids:
        by_user: dict[str, list[dict]] = {}
        for l in lig_logs:
            by_user.setdefault(l["u"], []).append(l)
        for u, rows in sorted(by_user.items()):
            mastered_eligible_users.add(u)
            window = rows[-20:]
            v1, _, d1 = m1(rows)
            v2, _, d2 = m2(window)
            if d1 and d2 and v1 is not None and v2 is not None and v1 >= 0.90 and v2 >= 0.90 and (m3 is not None and m3 >= 0):
                mastered += 1

    def rel(p: Path) -> str:
        try:
            return str(p.resolve().relative_to(REPO))
        except ValueError:
            return str(p)

    result = {
        "meta": {
            "snapshot": rel(args.snapshot),
            "exported_at": snap["meta"]["exported_at"],
            "content_source": rel(args.content),
            "thresholds": {"m1_retention_21d": 0.90, "m2_recognition": 0.90, "m3_twins_delta": 0.0},
        },
        "m1_retention_21d": {
            "ligature": dict(zip(("value", "num", "den"), m1(lig_logs))),
            "pilot_all": dict(zip(("value", "num", "den"), m1(logs))),
        },
        "m2_recognition_accuracy": {
            "ligature": dict(zip(("value", "num", "den"), m2(lig_logs))),
            "pilot_all": dict(zip(("value", "num", "den"), m2(logs))),
        },
        "m3_twins_delta": {
            "value": m3,
            "twins": {"value": a_tw, "num": n_tw, "den": d_tw},
            "control": {"value": a_ct, "num": n_ct, "den": d_ct},
        },
        "harm_false_canonical_forms": {
            "count": harm,
            "denominator": len(content) + len(fronts),
            "violations": viol,
        },
        "ligature_mastered_users": {
            "count": mastered,
            "denominator": len(mastered_eligible_users),
            "note": "пользователей с хоть одной лигатурной карточкой; 0 -> нет колоды",
        },
    }

    if args.json:
        print(json.dumps(result, ensure_ascii=False, indent=2))
    else:
        m = result["meta"]
        print(f"LIGATURE MASTERY METRICS (H6394) — snapshot {m['exported_at']}")
        print(f"snapshot: {m['snapshot']}   content: {m['content_source']}")
        print()
        r1 = result["m1_retention_21d"]
        print(f"M1  удержание @интервал>=21д (доля non-Again; порог >= 0.90)")
        print(f"    лигатуры : {fmt(r1['ligature']['value'], r1['ligature']['num'], r1['ligature']['den'])}")
        print(f"    пилот-весь: {fmt(r1['pilot_all']['value'], r1['pilot_all']['num'], r1['pilot_all']['den'])}")
        r2 = result["m2_recognition_accuracy"]
        print(f"M2  точность узнавания (доля non-Again; порог >= 0.90)")
        print(f"    лигатуры : {fmt(r2['ligature']['value'], r2['ligature']['num'], r2['ligature']['den'])}")
        print(f"    пилот-весь: {fmt(r2['pilot_all']['value'], r2['pilot_all']['num'], r2['pilot_all']['den'])}")
        r3 = result["m3_twins_delta"]
        print(f"M3  дельта на близнецах (twins − контроль; порог >= 0)")
        print(f"    близнецы : {fmt(r3['twins']['value'], r3['twins']['num'], r3['twins']['den'])}")
        print(f"    контроль : {fmt(r3['control']['value'], r3['control']['num'], r3['control']['den'])}")
        print(f"    дельта   : {'N/A (нет twins-проб)' if r3['value'] is None else f'{r3['value']:+.3f}'}")
        h = result["harm_false_canonical_forms"]
        print(f"HARM  ложные канонические формы в контенте колоды (канарейка: 0)")
        print(f"    нарушений: {h['count']} из {h['denominator']} форм  ->  {'PASS' if h['count'] == 0 else 'FAIL'}")
        for v in h["violations"][:10]:
            print(f"      · rank {v['rank']}: {v['devanagari']} — {', '.join(v['reasons'])}")
        mu = result["ligature_mastered_users"]
        print(f"ОСВОЕНО (тройка выполнена): {mu['count']} из {mu['denominator']} лигатурных пользователей")
        print()
        print("Знаменатели: M1 — повторы с scheduled_days>=21; M2 — все повторы среза;")
        print("M3 — twins-пробы (снапшот twins_probes, режим H6393); harm — уникальные")
        print("формы контента + фронты карт лигатурных колод из снапшота.")

    return 0 if harm == 0 else 2


if __name__ == "__main__":
    sys.exit(main())

