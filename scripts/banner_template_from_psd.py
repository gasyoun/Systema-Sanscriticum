#!/usr/bin/env python3
"""PSD плашки курса -> шаблон для «Плашек занятий» (background.png + template.json).

Разбор делается ОДИН раз на курс, на машине сотрудника; сервер PSD не читает.
Результат загружается в /admin/lesson-banners → «Новый шаблон».

    pip install psd-tools fonttools

    # 1. Посмотреть текстовые слои и их тексты:
    python scripts/banner_template_from_psd.py plashka.psd --list

    # 2. Собрать шаблон:
    python scripts/banner_template_from_psd.py plashka.psd \
        --date-layer "Дата" --number-layer "Номер" \
        --fonts-dir resources/fonts/banners --out out/grammar

Что делает:
  * фон = композит PSD со СКРЫТЫМИ слоями даты и номера;
  * для каждого из двух слоёв: рамка (bbox, расширенная под длинный текст),
    выравнивание абзаца, шрифт (PostScript-имя -> файл в --fonts-dir),
    кегль в пикселях с учётом трансформации слоя, цвет, трекинг, капс;
  * формат номера выводится из текста слоя: «Занятие 5» -> «Занятие {N}»;
    формат даты задаётся --date-format (isoFormat Carbon, ru), по умолчанию «D MMMM».

Кегль: при 72 ppi пункт PSD равен пикселю; рендер (GD/FreeType) пересчитывает сам.
"""

from __future__ import annotations

import argparse
import json
import re
import sys
from pathlib import Path

try:
    from psd_tools import PSDImage
except ImportError:  # pragma: no cover - подсказка оператору
    sys.exit("Нужен psd-tools: pip install psd-tools fonttools")

JUSTIFICATION = {0: "left", 1: "right", 2: "center"}


def text_layers(psd):
    return [layer for layer in psd.descendants() if layer.kind == "type"]


def find_layer(psd, name: str):
    matches = [layer for layer in text_layers(psd) if layer.name == name]
    if not matches:
        names = ", ".join(repr(layer.name) for layer in text_layers(psd))
        sys.exit(f"Текстовый слой {name!r} не найден. Есть: {names}")
    if len(matches) > 1:
        sys.exit(f"Слоёв с именем {name!r} несколько ({len(matches)}) — переименуйте в PSD.")
    return matches[0]


def style_of(layer) -> dict:
    """Первый стиль-ран слоя (плашка = одна строка одним стилем)."""
    engine = layer.engine_dict
    runs = engine["StyleRun"]["RunArray"]
    return dict(runs[0]["StyleSheet"]["StyleSheetData"])


def justification_of(layer) -> str:
    try:
        props = layer.engine_dict["ParagraphRun"]["RunArray"][0]["ParagraphSheet"]["Properties"]
        return JUSTIFICATION.get(int(props.get("Justification", 0)), "left")
    except (KeyError, IndexError, TypeError):
        return "left"


def font_postscript_name(layer, style: dict) -> str:
    font_set = layer.resource_dict["FontSet"]
    index = int(style.get("Font", 0))
    return str(font_set[index]["Name"]).strip("'\"\x00")


def font_file_map(fonts_dir: Path | None) -> dict[str, str]:
    """PostScript-имя -> имя файла шрифта из каталога (нужен fonttools)."""
    if fonts_dir is None or not fonts_dir.is_dir():
        return {}
    try:
        from fontTools.ttLib import TTFont
    except ImportError:
        print("! fonttools не установлен — шрифты сопоставлю только по имени файла.", file=sys.stderr)
        return {}

    mapping: dict[str, str] = {}
    for path in sorted(fonts_dir.iterdir()):
        if path.suffix.lower() not in {".ttf", ".otf"}:
            continue
        try:
            font = TTFont(str(path), fontNumber=0, lazy=True)
            name = font["name"].getDebugName(6)
        except Exception:  # битый файл шрифта не должен ронять разбор
            continue
        if name:
            mapping[name] = path.name
    return mapping


def color_of(style: dict) -> str:
    try:
        values = list(style["FillColor"]["Values"])  # [a, r, g, b] 0..1
        r, g, b = (max(0, min(255, round(v * 255))) for v in values[1:4])
        return f"#{r:02X}{g:02X}{b:02X}"
    except (KeyError, TypeError, ValueError):
        return "#000000"


def font_scale(layer) -> float:
    """Масштаб текста трансформацией слоя (свободная трансформация в PS)."""
    try:
        xx, xy, yx, yy, _tx, _ty = layer.transform
        return float((abs(yy) ** 2 + abs(xy) ** 2) ** 0.5) or 1.0
    except (AttributeError, TypeError, ValueError):
        return 1.0


def is_upper(text: str) -> bool:
    letters = [c for c in text if c.isalpha()]
    return bool(letters) and all(c == c.upper() for c in letters)


def field_spec(layer, canvas_w: int, canvas_h: int, grow: float, fonts: dict[str, str]) -> dict:
    style = style_of(layer)
    left, top, right, bottom = layer.bbox
    width, height = right - left, bottom - top
    align = justification_of(layer)

    # Рамку расширяем под текст длиннее образца («1» -> «12», «май» -> «сентября»)
    # в сторону, куда текст растёт при данном выравнивании; fit в рендере ужмёт,
    # если и этого не хватит.
    new_w = min(canvas_w, round(width * grow))
    extra = new_w - width
    if align == "left":
        x = left
    elif align == "right":
        x = right - new_w
    else:
        x = left - extra // 2
    x = max(0, min(x, canvas_w - new_w))

    pad_y = round(height * 0.25)
    y = max(0, top - pad_y)
    h = min(canvas_h - y, height + 2 * pad_y)

    ps_name = font_postscript_name(layer, style)
    font_file = fonts.get(ps_name, f"{ps_name}.ttf")
    if ps_name not in fonts:
        print(f"! Шрифт {ps_name!r}: файла в --fonts-dir не нашёл, в spec пишу {font_file!r} — положите файл с этим именем.", file=sys.stderr)

    tracking_em = float(style.get("Tracking", 0)) / 1000.0  # PS: тысячные доли em
    size_px = float(style.get("FontSize", 32)) * font_scale(layer)
    text = (layer.text or "").strip()

    return {
        "x": int(x),
        "y": int(y),
        "w": int(new_w),
        "h": int(h),
        "align": align,
        "font": font_file,
        "size_px": round(size_px, 2),
        "color": color_of(style),
        "tracking": round(tracking_em * size_px, 2),
        "uppercase": bool(int(style.get("FontCaps", 0)) == 2 or is_upper(text)),
        "fit": True,
        "_sample": text,
        "_postscript": ps_name,
    }


def number_format(sample: str) -> str:
    """«Занятие 5» -> «Занятие {N}»; без цифр — «Занятие {N}» с предупреждением."""
    if re.search(r"\d+", sample):
        return re.sub(r"\d+", "{N}", sample, count=1)
    print(f"! В слое номера нет цифр ({sample!r}) — формат «Занятие {{N}}», поправьте --number-format.", file=sys.stderr)
    return "Занятие {N}"


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    parser.add_argument("psd", type=Path)
    parser.add_argument("--list", action="store_true", help="показать текстовые слои и выйти")
    parser.add_argument("--date-layer")
    parser.add_argument("--number-layer")
    parser.add_argument("--date-format", default="D MMMM", help="isoFormat Carbon (ru), напр. «D MMMM», «DD.MM», «D MMMM, dddd»")
    parser.add_argument("--number-format", help="с {N}; по умолчанию выводится из текста слоя")
    parser.add_argument("--overview-text", default="Обзорное занятие")
    parser.add_argument("--fonts-dir", type=Path, default=Path("resources/fonts/banners"))
    parser.add_argument("--grow", type=float, default=1.6, help="во сколько раз расширить рамку поля (по умолчанию 1.6)")
    parser.add_argument("--out", type=Path, default=Path("banner-template"))
    args = parser.parse_args()

    psd = PSDImage.open(args.psd)

    if args.list:
        print(f"{args.psd.name}: {psd.width}×{psd.height}")
        for layer in text_layers(psd):
            flag = "" if layer.is_visible() else "  (скрыт)"
            print(f"  {layer.name!r:32} bbox={layer.bbox}  текст={(layer.text or '').strip()!r}{flag}")
        return 0

    if not args.date_layer or not args.number_layer:
        parser.error("нужны --date-layer и --number-layer (имена смотрите через --list)")

    date_layer = find_layer(psd, args.date_layer)
    number_layer = find_layer(psd, args.number_layer)
    fonts = font_file_map(args.fonts_dir)

    date = field_spec(date_layer, psd.width, psd.height, args.grow, fonts)
    date["format"] = args.date_format
    number = field_spec(number_layer, psd.width, psd.height, args.grow, fonts)
    number["format"] = args.number_format or number_format(number["_sample"])
    number["overview_text"] = args.overview_text

    if "{N}" not in number["format"]:
        sys.exit(f"Формат номера {number['format']!r} без {{N}}.")

    hidden = {id(date_layer), id(number_layer)}
    background = psd.composite(
        force=True,
        layer_filter=lambda layer: layer.is_visible() and id(layer) not in hidden,
    )

    args.out.mkdir(parents=True, exist_ok=True)
    bg_path = args.out / "background.png"
    background.convert("RGB").save(bg_path, "PNG", optimize=True)

    spec = {
        "source_psd": args.psd.name,
        "size": [psd.width, psd.height],
        "fields": {"date": date, "number": number},
    }
    spec_path = args.out / "template.json"
    spec_path.write_text(json.dumps(spec, ensure_ascii=False, indent=2), encoding="utf-8")

    print(f"фон:   {bg_path}  ({psd.width}×{psd.height})")
    print(f"поля:  {spec_path}")
    for name, field in (("дата", date), ("номер", number)):
        print(f"  {name:6} {field['_sample']!r:24} -> {field['format']!r:18} шрифт {field['font']} {field['size_px']}px {field['color']} {field['align']}")
    print("Дальше: /admin/lesson-banners → «Новый шаблон» → фон + template.json → «Превью».")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
