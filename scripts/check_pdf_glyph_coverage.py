#!/usr/bin/env python3
"""Проверка, что каждый символ документа есть в шрифте сборки PDF (H2501).

Зачем. PDF руководства преподавателя собирается Dompdf шрифтом DejaVu Sans.
Символ, которого в шрифте нет, не даёт ни ошибки, ни предупреждения — он молча
превращается в пустой прямоугольник. Ровно этот отказ и назван главным риском
приёмки: пометки обратимости («[Безопасно]» и соседние) — это та часть текста,
ради которой всё делается, и потерять её в PDF беззвучно недопустимо.

«Посмотреть глазами по собранному PDF» проверяет одну сборку и одного
проверяющего. Этот скрипт проверяет ВЕСЬ текст против cmap самого шрифта, то
есть отвечает на вопрос механически и одинаково в любой сессии.

Использование:
    python scripts/check_pdf_glyph_coverage.py [файл.md ...]

По умолчанию берётся docs/TEACHER_CABINET_GUIDE_RU.md. Выход 0 — все символы
покрыты, 1 — найден непокрытый символ.
"""

from __future__ import annotations

import struct
import sys
from pathlib import Path

sys.stdout.reconfigure(encoding="utf-8")
sys.stderr.reconfigure(encoding="utf-8")

ROOT = Path(__file__).resolve().parent.parent
DEFAULT_DOC = ROOT / "docs" / "TEACHER_CABINET_GUIDE_RU.md"
FONT_DIR = ROOT / "vendor" / "dompdf" / "dompdf" / "lib" / "fonts"
FONTS = ("DejaVuSans.ttf", "DejaVuSans-Bold.ttf")

# Символы разметки, до шрифта не доходящие: они съедаются разбором Markdown.
MARKDOWN_ONLY = set("#*_`|[]()<>\\")


def read_cmap(path: Path) -> set[int]:
    """Множество кодовых точек, покрытых шрифтом (форматы cmap 4 и 12)."""
    data = path.read_bytes()
    num_tables = struct.unpack(">H", data[4:6])[0]
    cmap_offset = None

    for i in range(num_tables):
        entry = 12 + i * 16
        tag = data[entry : entry + 4]
        if tag == b"cmap":
            cmap_offset = struct.unpack(">I", data[entry + 8 : entry + 12])[0]
            break

    if cmap_offset is None:
        raise SystemExit(f"{path.name}: таблица cmap не найдена")

    n_subtables = struct.unpack(">H", data[cmap_offset + 2 : cmap_offset + 4])[0]
    covered: set[int] = set()

    for i in range(n_subtables):
        rec = cmap_offset + 4 + i * 8
        sub_offset = cmap_offset + struct.unpack(">I", data[rec + 4 : rec + 8])[0]
        fmt = struct.unpack(">H", data[sub_offset : sub_offset + 2])[0]

        if fmt == 4:
            seg_x2 = struct.unpack(">H", data[sub_offset + 6 : sub_offset + 8])[0]
            segs = seg_x2 // 2
            ends_at = sub_offset + 14
            starts_at = ends_at + seg_x2 + 2
            for s in range(segs):
                end = struct.unpack(">H", data[ends_at + s * 2 : ends_at + s * 2 + 2])[0]
                start = struct.unpack(">H", data[starts_at + s * 2 : starts_at + s * 2 + 2])[0]
                if start == 0xFFFF:
                    continue
                covered.update(range(start, end + 1))

        elif fmt == 12:
            n_groups = struct.unpack(">I", data[sub_offset + 12 : sub_offset + 16])[0]
            for g in range(n_groups):
                base = sub_offset + 16 + g * 12
                start, end, _ = struct.unpack(">III", data[base : base + 12])
                covered.update(range(start, end + 1))

    return covered


def main() -> int:
    docs = [Path(a) for a in sys.argv[1:]] or [DEFAULT_DOC]

    coverage: set[int] | None = None
    for name in FONTS:
        font = FONT_DIR / name
        if not font.is_file():
            print(f"Шрифт не найден: {font} — сначала composer install.")
            return 1
        found = read_cmap(font)
        coverage = found if coverage is None else (coverage & found)

    assert coverage is not None
    print(f"Покрытие шрифта (пересечение {len(FONTS)} начертаний): {len(coverage)} кодовых точек")

    failed = False

    for doc in docs:
        if not doc.is_file():
            print(f"Документ не найден: {doc}")
            failed = True
            continue

        text = doc.read_text(encoding="utf-8")
        missing = sorted(
            {
                ch
                for ch in set(text)
                if ch not in MARKDOWN_ONLY
                and not ch.isspace()
                and ord(ch) not in coverage
            }
        )

        if missing:
            failed = True
            print(f"{doc}: НЕ ПОКРЫТО шрифтом — в PDF станет пустым прямоугольником:")
            for ch in missing:
                print(f"  U+{ord(ch):04X}  {ch!r}")
        else:
            uniq = len({ch for ch in text if not ch.isspace()})
            print(f"{doc}: покрыты все {uniq} различных символов.")

    return 1 if failed else 0


if __name__ == "__main__":
    raise SystemExit(main())
