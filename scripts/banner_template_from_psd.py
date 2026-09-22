#!/usr/bin/env python3
"""PSD плашки курса -> шаблон для «Плашек занятий» (background.png + template.json).

Разбор делается ОДИН раз на курс, на машине сотрудника; сервер PSD не читает.
Результат загружается в /admin/lesson-banners → «Новый шаблон».

    pip install "psd-tools[composite]" fonttools

    # 1. Посмотреть текстовые слои и их тексты:
    python scripts/banner_template_from_psd.py plashka.psd --list

    # 2. Собрать шаблон:
    python scripts/banner_template_from_psd.py plashka.psd \
        --date-layer "Дата" --number-layer "Номер" \
        --out out/grammar

Что делает:
  * фон = композит PSD со СКРЫТЫМИ слоями даты и номера;
  * для каждого из двух слоёв: рамка (bbox, расширенная под длинный текст),
    выравнивание абзаца, шрифт (PostScript-имя -> файл в --fonts-dir),
    кегль в пикселях с учётом трансформации слоя, цвет, трекинг, капс;
  * формат номера выводится из текста слоя: «Занятие 5» -> «Занятие {N}»;
  * номер вида «(14)» в квадратной рамке — это цифры в кружке (OpenType Fedra):
    в spec идёт "badge" (круг цвета слоя, цифры вырезаны), рендер рисует его сам;
  * --watermark-layer «40» — водяной номер ВНУТРИ стопки слоёв: фон собирается из
    слоёв под ним, всё выше — в прозрачный overlay.png; наложение и заливка слоя
    (Мягкий свет, 59 %) переносятся в spec;
    формат даты задаётся --date-format (isoFormat Carbon, ru), по умолчанию «D MMMM».

Кегль: при 72 ppi пункт PSD равен пикселю; рендер (GD/FreeType) пересчитывает сам.
"""

from __future__ import annotations

import argparse
import json
import os
import re
import shutil
import sys
from pathlib import Path

try:
    from psd_tools import PSDImage
except ImportError:  # pragma: no cover - подсказка оператору
    sys.exit('Нужен psd-tools: pip install "psd-tools[composite]" fonttools')

JUSTIFICATION = {0: "left", 1: "right", 2: "center"}

# Кегль цифр в кружке относительно диаметра — подобран по макетам ОРС
# («Введение», «Патанджали», хинди), где кружок 80–87 px.
BADGE_DIGIT_RATIO = 0.55


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


def default_font_dirs() -> list[Path]:
    """Системные шрифты Windows + установленные «только для меня» (там живут Fedra и пр.)."""
    dirs = [Path("C:/Windows/Fonts")]
    local = os.environ.get("LOCALAPPDATA")
    if local:
        dirs.append(Path(local) / "Microsoft" / "Windows" / "Fonts")
    return dirs


def font_file_map(fonts_dirs: list[Path]) -> dict[str, Path]:
    """PostScript-имя -> файл шрифта из каталогов (нужен fonttools). Первый найденный выигрывает."""
    try:
        from fontTools.ttLib import TTFont
    except ImportError:
        print("! fonttools не установлен — шрифты сопоставлю только по имени файла.", file=sys.stderr)
        return {}

    mapping: dict[str, Path] = {}
    for fonts_dir in fonts_dirs:
        if not fonts_dir.is_dir():
            continue
        for path in sorted(fonts_dir.iterdir()):
            if path.suffix.lower() not in {".ttf", ".otf"}:
                continue
            try:
                font = TTFont(str(path), fontNumber=0, lazy=True)
                name = font["name"].getDebugName(6)
            except Exception:  # битый файл шрифта не должен ронять разбор
                continue
            if name and name not in mapping:
                mapping[name] = path
    return mapping


def clean_font_name(ps_name: str, suffix: str) -> str:
    stem = re.sub(r"[^A-Za-z0-9._-]+", "-", ps_name).strip("-") or "font"
    return stem + suffix.lower()


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


def field_spec(layer, canvas_w: int, canvas_h: int, grow: float, fonts: dict[str, Path], obstacles: list[tuple[int, int, int, int]], badge_ok: bool = False) -> dict:
    style = style_of(layer)
    left, top, right, bottom = layer.bbox
    width, height = right - left, bottom - top
    align = justification_of(layer)

    # Рамку расширяем под текст длиннее образца («1» -> «12», «май» -> «сентября»)
    # в сторону, куда текст растёт при данном выравнивании; fit в рендере ужмёт,
    # если и этого не хватит.
    # Но не дальше соседнего видимого слоя на той же строке («(1)» рядом с «из 16»):
    # иначе «(12)» ляжет поверх статичного текста фона.
    gap = 8
    limit_left, limit_right = 0, canvas_w
    for o_left, o_top, o_right, o_bottom in obstacles:
        if o_bottom <= top or o_top >= bottom:
            continue  # не на этой строке
        if o_left >= right:
            limit_right = min(limit_right, o_left - gap)
        elif o_right <= left:
            limit_left = max(limit_left, o_right + gap)

    new_w = round(width * grow)
    if align == "left":
        x = left
        new_w = min(new_w, limit_right - left)
    elif align == "right":
        new_w = min(new_w, right - limit_left)
        x = right - new_w
    else:
        half = min((new_w - width) // 2, left - limit_left, limit_right - right)
        x = left - max(0, half)
        new_w = width + 2 * max(0, half)
    new_w = max(width, min(new_w, canvas_w))
    x = max(0, min(x, canvas_w - new_w))

    pad_y = round(height * 0.25)
    y = max(0, top - pad_y)
    h = min(canvas_h - y, height + 2 * pad_y)

    ps_name = font_postscript_name(layer, style)
    # Имя файла — из PostScript-имени, а не исходного файла: «CHARTERITC-BOLD (2).OTF»
    # сервер не примет (только латиница, цифры, «._ -»), а PostScript-имя всегда ASCII.
    font_file = clean_font_name(ps_name, fonts[ps_name].suffix if ps_name in fonts else ".ttf")
    if ps_name not in fonts:
        print(f"! Шрифт {ps_name!r}: файла в --fonts-dir не нашёл, в spec пишу {font_file!r} — загрузите файл шрифта вместе с шаблоном (имя файла = это имя).", file=sys.stderr)

    tracking_em = float(style.get("Tracking", 0)) / 1000.0  # PS: тысячные доли em
    size_px = float(style.get("FontSize", 32)) * font_scale(layer)
    text = (layer.text or "").strip()

    # Номер в кружке: «(14)» Fedra рисует OpenType-функцией как ⓮ — рамка слоя
    # квадратная. В spec — круг цвета слоя и только цифры, кегль по диаметру.
    if badge_ok and re.fullmatch(r"\(\d+\)", text) and abs(width - height) <= 0.15 * max(width, height):
        diameter = min(width, height)
        return {
            "x": int(left),
            "y": int(top),
            "w": int(width),
            "h": int(height),
            "align": "center",
            "font": font_file,
            "size_px": round(diameter * BADGE_DIGIT_RATIO, 2),
            "color": color_of(style),
            "tracking": 0,
            "uppercase": False,
            "fit": True,
            "badge": {"shape": "circle", "diameter": int(diameter)},
            "_sample": text,
            "_postscript": ps_name,
        }

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


def watermark_spec(layer, fonts: dict[str, Path]) -> dict:
    """Водяной номер: рамка = глифы слоя (может уходить за край), наложение и заливка слоя."""
    style = style_of(layer)
    left, top, right, bottom = layer.bbox
    ps_name = font_postscript_name(layer, style)
    fill = layer.tagged_blocks.get_data(b"iOpa") if layer.tagged_blocks else None
    opacity = (layer.opacity / 255.0) * ((fill if fill is not None else 255) / 255.0)
    blend = str(getattr(layer.blend_mode, "name", layer.blend_mode)).lower()
    if blend not in {"soft_light", "normal"}:
        print(f"! Наложение {blend!r} у {layer.name!r} не поддержано — рисую как «Мягкий свет».", file=sys.stderr)
        blend = "soft_light"
    text = (layer.text or "").strip()
    return {
        "source": "number",
        "layer": "under",
        "blend": blend,
        "opacity": round(opacity, 3),
        "x": int(left),
        "y": int(top),
        "w": int(right - left),
        "h": int(bottom - top),
        "align": justification_of(layer),
        "font": clean_font_name(ps_name, fonts[ps_name].suffix if ps_name in fonts else ".ttf"),
        "size_px": round(float(style.get("FontSize", 32)) * font_scale(layer), 2),
        "color": color_of(style),
        "tracking": 0,
        "uppercase": False,
        "fit": False,
        "format": number_format(text),
        "overview_text": "",
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
    parser.add_argument("--watermark-layer", action="append", default=[], help="слой водяного номера внутри стопки (можно несколько)")
    parser.add_argument("--fonts-dir", type=Path, action="append", help="где искать файлы шрифтов PSD; можно несколько раз (по умолчанию системные шрифты Windows + установленные для пользователя)")
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
    fonts = font_file_map(args.fonts_dir or default_font_dirs())

    def obstacles_for(target):
        return [
            tuple(layer.bbox)
            for layer in psd.descendants()
            if layer is not target
            and layer.kind == "type"
            and layer.is_visible()
            and layer.bbox != (0, 0, 0, 0)
        ]

    date = field_spec(date_layer, psd.width, psd.height, args.grow, fonts, obstacles_for(date_layer))
    date["format"] = args.date_format
    number = field_spec(number_layer, psd.width, psd.height, args.grow, fonts, obstacles_for(number_layer), badge_ok=True)
    if "badge" in number:
        number["format"] = args.number_format or "{N}"
        number["overview_text"] = ""
    else:
        number["format"] = args.number_format or number_format(number["_sample"])
        number["overview_text"] = args.overview_text

    watermarks = [find_layer(psd, name) for name in args.watermark_layer]
    extra = {}
    for i, layer in enumerate(watermarks):
        extra["watermark" if i == 0 else f"watermark_{i + 1}"] = watermark_spec(layer, fonts)

    if "{N}" not in number["format"]:
        sys.exit(f"Формат номера {number['format']!r} без {{N}}.")

    hidden = {id(date_layer), id(number_layer)} | {id(layer) for layer in watermarks}
    order = {id(layer): i for i, layer in enumerate(psd.descendants())}
    split = min((order[id(layer)] for layer in watermarks), default=None)

    def keep(layer, above):
        """above=None — все слои; False — только под водяным; True — только над ним."""
        if not layer.is_visible() or id(layer) in hidden:
            return False
        if above is None:
            return True
        return order[id(layer)] > split if above else order[id(layer)] < split

    below = None if split is None else False
    background = psd.composite(force=True, layer_filter=lambda layer: keep(layer, below))
    overlay = None if split is None else psd.composite(force=True, layer_filter=lambda layer: keep(layer, True))

    args.out.mkdir(parents=True, exist_ok=True)
    bg_path = args.out / "background.png"
    background.convert("RGB").save(bg_path, "PNG", optimize=True)
    if overlay is not None:
        overlay.convert("RGBA").save(args.out / "overlay.png", "PNG", optimize=True)

    spec = {
        "source_psd": args.psd.name,
        "size": [psd.width, psd.height],
        "fields": {"date": date, "number": number, **extra},
    }
    spec_path = args.out / "template.json"
    spec_path.write_text(json.dumps(spec, ensure_ascii=False, indent=2), encoding="utf-8")

    # Файлы шрифтов — рядом, в fonts/: их грузят в тот же «Новый шаблон».
    fonts_out = args.out / "fonts"
    for field in (date, number, *extra.values()):
        source = fonts.get(field["_postscript"])
        if source is not None:
            fonts_out.mkdir(exist_ok=True)
            shutil.copy2(source, fonts_out / field["font"])

    print(f"фон:   {bg_path}  ({psd.width}×{psd.height})")
    print(f"поля:  {spec_path}")
    if fonts_out.is_dir():
        print(f"шрифты: {fonts_out}  ({', '.join(sorted(p.name for p in fonts_out.iterdir()))})")
    if overlay is not None:
        print(f"верх:  {args.out / 'overlay.png'}  (загрузить в «Верхний слой»)")
    for name, field in (("дата", date), ("номер", number), *(("водяной", f) for f in extra.values())):
        print(f"  {name:6} {field['_sample']!r:24} -> {field['format']!r:18} шрифт {field['font']} {field['size_px']}px {field['color']} {field['align']}")
    print("Дальше: /admin/lesson-banners → «Новый шаблон» → фон + template.json + файлы шрифтов → «Превью».")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
