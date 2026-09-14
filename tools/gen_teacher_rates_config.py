#!/usr/bin/env python3
"""H3532/S2 — generate Systema config/teacher_rates.php from Uprava data/teacher_rate_timelines.json.

Usage:
    python3 tools/gen_teacher_rates_config.py /path/to/teacher_rate_timelines.json > config/teacher_rates.php

Data-driven parts (from the mined timeline JSON, H3531):
  * percent rate periods with per-period bank slice (92% era vs no-slice era)
  * direct-payment deduction timelines (Лейтан → Новикова 1400→1680→1920)
  * block price observations
  * fixed payments collapsed into recurrence periods:
      1 occurrence            -> one-shot period (from .. from+31d)
      2+ same amount          -> open-ended period from the first date

Static parts (H3532 brief constants; anchor sums cited inline):
  * staff rows Ильюшина ~30 000 (ритм 18+12) / Горбаченко 29 325 / Кравченко база 17 030 + премии строками
  * contractor Холовченко €200–300
  * НПД −6% payout-step flags for Костина/Уша (самозанятые, ruling 27-07)
  * channel/lane per recipient (evidence-cited defaults)

The output is a generated artifact — never hand-edit it; regenerate instead.
"""

import hashlib
import json
import sys
from datetime import date, timedelta

# name in chat -> stable slug (generator-owned mapping)
SLUGS = {
    "Лейтан": "leytan",
    "Трефилова": "trefilova",
    "Костина": "kostina",
    "Уша": "usha",
    "Толчельников": "tolchelnikov",
    "Дружинин": "druzhinin",
    "Литвиненко": "litvinenko",
    "Ворошилов": "voroshilov",
    "Леонов": "leonov",
    "Клебанов": "klebanov",
    "Леонченко": "leonchenko",
    "Гасунс": "gasuns",
    "Горностаева": "gornostaeva",
    "Щербак": "shcherbak",
    "Пахомов": "pakhomov",
    "Емельянов": "emelyanov",
    "Парибок": "paribok",
    "Лундышева": "lundysheva",
    "Кравченко": "kravchenko",
    "Ильюшина": "ilyushina",
    "Кузнецова": "kuznetsova",
    "Григорьева": "grigoreva",
}

# channel/lane per recipient — evidence-cited marked defaults (PLAN рулинги #6/#8, якоря A2/A4/A6)
CHANNELS = {
    "leytan": ("paypal_mg", "EUR", "A1/A7: «€ по курсу дня», PayPal MG"),
    "kostina": ("paypal_mg", "EUR", "A2 paid_note: «286 евро PayPal»"),
    "trefilova": ("tochka_ip_gasuns", "RUB", "A6 paid_note: «лист июнь… ИП Гасунс»"),
    "kravchenko": ("tochka_ip_gasuns", "RUB", "A4 paid_note: «записи Марии… ИП Гасунса»"),
    "voroshilov": ("tochka_maria", "RUB", "A3 paid_note: лист март, оплата Марии"),
}

DEFAULT_CHANNEL = ("tochka_maria", "RUB", "дефолт без прямого доказательства канала в чате ⚠️")

STAFF_STATIC = """    'staff' => [
        // Штат (не преподаватели LMS): суммы из брифа H3532 / реестра ставок, ритм — по платёжным записям Марии.
        'ilyushina' => [
            // Ильюшина М. М. = Поликарпова с 31-03-2026 (alias обязателен, PLAN §6).
            // ~30 000 ₽/мес ритмом 18+12: записи Марии 3448(18 000 @10-13-е) + 3485/3563(12 000 @24-29-е); шлёт сама с ИП (Точка).
            'name' => 'Ильюшина М. М. (Поликарпова)',
            'aliases' => ['Ильюшина', 'Поликарпова', 'Мария'],
            'channel' => 'self_ip',
            'lane' => 'RUB',
            'tranches' => [
                ['day' => 12, 'amount_rub' => 18000.0],
                ['day' => 26, 'amount_rub' => 12000.0],
            ],
            'monthly_rub' => 30000.0,
        ],
        'gorbachenko' => [
            // Горбаченко (поддержка, 🍎): 29 325 ₽/мес — бриф H3532.
            'name' => 'Горбаченко',
            'aliases' => ['Горбаченко'],
            'channel' => 'tochka_maria',
            'lane' => 'RUB',
            'monthly_rub' => 29325.0,
            'pay_day' => 5,
        ],
        'kravchenko' => [
            // Кравченко Иван: база 17 030 ₽/мес (якорь A4, записи 09.02/10.03) + премии отдельными строками
            // (10 650, 10 000, 5 320, 17 021 — записи Марии фев–апр 2026); фондовые 28 000 ₽ — контекст, не трата.
            'name' => 'Кравченко Иван',
            'aliases' => ['Кравченко', 'Иван'],
            'channel' => 'tochka_ip_gasuns',
            'lane' => 'RUB',
            'monthly_rub' => 17030.0,
            'pay_day' => 9,
            'premiums_observed_rub' => [10650.0, 10000.0, 5320.0, 17021.0],
        ],
    ],

    'contractors' => [
        'kholovchenko' => [
            // Подрядчик Холовченко: €200–300/мес — бриф H3532 (в safe_withdrawal встречается написание «Головченко»).
            'name' => 'Холовченко',
            'aliases' => ['Холовченко', 'Головченко'],
            'channel' => 'paypal_mg',
            'lane' => 'EUR',
            'fee_eur_min' => 200.0,
            'fee_eur_max' => 300.0,
            'pay_day' => 15,
        ],
    ],
"""

NPD_STATIC = """    // НПД −6 % самозанятых — отдельный шаг выплаты (зачёт до выплаты, ruling 27-07),
    // НЕ внутри брутто-расчёта (ARCHITECTURE контракт №3). Пометка в панели, гейт бэктеста — брутто.
    'npd' => [
        'kostina' => ['pct' => 6.0],
        'usha' => ['pct' => 6.0],
    ],
"""


def php_str(s):
    return "'" + str(s).replace("\\", "\\\\").replace("'", "\\'") + "'"


def php_float(v):
    f = float(v)
    if f == int(f):
        return f"{int(f)}.0"
    return repr(f)


def php_list_floats(vals):
    return "[" + ", ".join(php_float(v) for v in vals) + "]"


def collapse_deductions(obs):
    """deduction_obs -> per (who, amount) first-date period.

    A later period for the same 'who' closes the previous one (amount evolution
    Новикова 1400→1680→1920); the last one stays open-ended.
    """
    seen = {}
    for o in sorted(obs, key=lambda x: x["date"]):
        key = (o["who"], float(o["amount"]))
        if key not in seen:
            seen[key] = o["date"]

    entries = [
        {"who": who, "amount_rub": amount, "from": frm}
        for (who, amount), frm in sorted(seen.items(), key=lambda kv: kv[1])
    ]
    starts_by_who = {}
    for e in entries:
        starts_by_who.setdefault(e["who"], []).append(e["from"])

    out = []
    for e in entries:
        later = [s for s in starts_by_who[e["who"]] if s > e["from"]]
        to = None
        if later:
            d = date.fromisoformat(min(later))
            to = (d - timedelta(days=1)).isoformat()
        out.append({**e, "to": to})
    return out


def collapse_fixed(payments):
    """fixed_payments -> recurrence periods.

    Same-amount runs stay open until the first date of the next different amount;
    a lone payment with nothing after it is a one-shot period [from .. from+31d].
    """
    runs = []  # [(amount, [dates])] in chronological order by first date
    for p in sorted(payments, key=lambda x: x["date"]):
        amt = float(p["amount_rub"])
        if runs and runs[-1][0] == amt:
            runs[-1][1].append(p["date"])
        else:
            runs.append((amt, [p["date"]]))

    periods = []
    for i, (amt, dates) in enumerate(runs):
        start = dates[0]
        if i + 1 < len(runs):
            nxt_first = runs[i + 1][1][0]
            earlier = [d for d in dates if d < nxt_first]
            end = max(earlier) if earlier else None
            if end is None:
                continue  # run entirely swallowed by the next period
            end_open = False
        elif len(dates) >= 2:
            end = None
            end_open = True
        else:
            d = date.fromisoformat(start)
            end = (d + timedelta(days=31)).isoformat()
            end_open = False
        periods.append({"value_rub": amt, "from": start, "to": end})

    # drop any period whose window is fully covered by a later-starting period
    cleaned = []
    for per in periods:
        if any(
            other["from"] > per["from"]
            and (per["to"] is not None and other["from"] <= per["to"])
            and other["value_rub"] != per["value_rub"]
            and per["from"] <= (other["from"])
            and _covers(other, per)
            for other in periods
        ):
            continue
        cleaned.append(per)
    return cleaned


def _covers(wider, narrower):
    """True when `wider` period fully covers `narrower`'s window."""
    wider_end = wider["to"] or "9999-99-99"
    narrower_end = narrower["to"] or "9999-99-99"
    return wider["from"] <= narrower["from"] and wider_end >= narrower_end


def emit_recipient(slug, teacher, channel, lane, ch_note):
    lines = []
    lines.append(f"        {php_str(slug)} => [")
    lines.append(f"            'name' => {php_str(teacher.get('display', slug))},")
    aliases = list(teacher.get("aliases", []))
    alias_php = "[" + ", ".join(php_str(a) for a in aliases) + "]"
    lines.append(f"            'aliases' => {alias_php},")
    lines.append(f"            'channel' => {php_str(channel)}, // {ch_note}")
    lines.append(f"            'lane' => {php_str(lane)},")
    lines.append("            'rate_periods' => [")
    for p in teacher.get("rate_periods", []):
        slice_php = php_float(p["bank_slice_pct"]) if p.get("bank_slice_pct") is not None else "null"
        to_php = php_str(p["to"]) if p.get("to") else "null"
        lines.append(
            "                ['kind' => 'percent', "
            f"'value_pct' => {php_float(p['value'])}, "
            f"'bank_slice_pct' => {slice_php}, "
            f"'from' => {php_str(p['from'])}, "
            f"'to' => {to_php}],"
        )
    for fp in collapse_fixed(teacher.get("fixed_payments", [])):
        to_php = php_str(fp["to"]) if fp["to"] else "null"
        lines.append(
            "                ['kind' => 'fixed_monthly', "
            f"'value_rub' => {php_float(fp['value_rub'])}, "
            f"'from' => {php_str(fp['from'])}, "
            f"'to' => {to_php}],"
        )
    lines.append("            ],")
    deds = collapse_deductions(teacher.get("deduction_obs", []))
    lines.append("            'direct_deductions' => [")
    for d in deds:
        to_php = php_str(d["to"]) if d["to"] else "null"
        lines.append(
            f"                ['who' => {php_str(d['who'])}, 'amount_rub' => {php_float(d['amount_rub'])}, 'from' => {php_str(d['from'])}, 'to' => {to_php}],"
        )
    lines.append("            ],")
    prices = teacher.get("block_price_obs", [])
    if prices:
        uniq = {}
        for b in prices:
            uniq[float(b["price"])] = b["date"]
        pairs = ", ".join(f"['price_rub' => {php_float(k)}, 'seen_at' => {php_str(v)}]" for k, v in uniq.items())
        lines.append(f"            'block_price_obs' => [{pairs}], // наблюдения цен блоков из расчётов")
    lines.append("        ],")
    return "\n".join(lines)


def main():
    if len(sys.argv) < 2:
        print("usage: gen_teacher_rates_config.py <teacher_rate_timelines.json>", file=sys.stderr)
        return 1
    raw = open(sys.argv[1], "rb").read()
    data = json.loads(raw.decode("utf-8"))
    digest = hashlib.sha256(raw).hexdigest()[:12]
    meta = data.get("meta", {})

    out = []
    out.append("<?php")
    out.append("")
    out.append("// Generated from Uprava data/teacher_rate_timelines.json @ sha256:" + digest)
    out.append("// by tools/gen_teacher_rates_config.py (H3531 mine -> H3532/S2 artifact). DO NOT HAND-EDIT.")
    out.append("// source_chat: " + meta.get("source_chat", "") + " · miner: " + str(meta.get("miner", "")) + " · handoff: " + str(meta.get("handoff", "")))
    out.append("//")
    out.append("// Канон «на руки»: (Σ поступлений периода ₽ × bank_slice%) × ставка(t) − прямые вычеты ± перерасчёты;")
    out.append("// для EUR-получателей конвертация по курсу на дату выплаты; НПД −6 % — отдельная пометка шага выплаты.")
    out.append("// Слайс банка применяется ТОЛЬКО когда период несёт bank_slice_pct (эра до окт-2025 — без среза).")
    out.append("")
    out.append("return [")
    out.append("    'meta' => [")
    out.append(f"        'source_hash' => {php_str(digest)},")
    out.append(f"        'generated_at' => {php_str(meta.get('generated_at', ''))},")
    out.append(f"        'handoff' => {php_str('H3532')},")
    out.append("    ],")
    out.append("")
    out.append("    'canon' => [")
    out.append(f"        'formula' => {php_str('(поступления ₽ × 92%) × ставка(t)')},")
    out.append(f"        'bank_slice_pct_reference' => {php_float(92)},")
    out.append(f"        'fx_eur_rub_fallback' => {php_float(90.1127)}, // исторический курс (реестр); живой курс — finance_snapshots type fx_eur_rub")
    out.append("    ],")
    out.append("")
    out.append("    'recipients' => [")
    for name in data.get("timeline_order", []):
        teacher = data.get("teachers", {}).get(name)
        slug = SLUGS.get(name)
        if not teacher or not slug or slug in ("kravchenko", "ilyushina"):
            continue
        if not teacher.get("rate_periods") and not teacher.get("fixed_payments"):
            continue
        channel, lane, note = CHANNELS.get(slug, DEFAULT_CHANNEL)
        out.append(emit_recipient(slug, teacher, channel, lane, note))
    out.append("    ],")
    out.append("")
    out.append(STAFF_STATIC.rstrip())
    out.append("")
    out.append(NPD_STATIC.rstrip())
    out.append("];")
    out.append("")

    sys.stdout.write("\n".join(out))
    return 0


if __name__ == "__main__":
    sys.exit(main())
