#!/usr/bin/env python3
"""
pdf_to_jpg.py — конвертирует страницы PDF в JPG (150 dpi).

Требует: pip install pymupdf

Использование:
  python pdf_to_jpg.py

Ищет все .pdf в папке pdf/ рядом со скриптом.
Для filename.pdf создаёт XX_01.jpg, XX_02.jpg, ...
где XX — первые два символа имени файла.
"""

import sys
from pathlib import Path

try:
    import fitz  # pymupdf
except ImportError:
    print('Ошибка: установи библиотеку командой:  pip install pymupdf')
    sys.exit(1)

DPI = 150
ZOOM = DPI / 72  # pymupdf работает в 72 dpi по умолчанию


def convert_pdf(pdf_path: Path):
    prefix = pdf_path.stem[:2]
    folder = pdf_path.parent

    doc = fitz.open(str(pdf_path))
    mat = fitz.Matrix(ZOOM, ZOOM)

    for i, page in enumerate(doc, 1):
        pix = page.get_pixmap(matrix=mat)
        out_path = folder / f'{prefix}_{i:02d}.jpg'
        pix.save(str(out_path))

    print(f'  ✓ {len(doc)} страниц → {prefix}_01.jpg … {prefix}_{len(doc):02d}.jpg')
    doc.close()


def main():
    folder = Path(__file__).parent / 'pdf'
    if not folder.is_dir():
        print(f'Ошибка: папка не найдена: {folder}')
        return

    pdfs = sorted(folder.glob('*.pdf'))
    if not pdfs:
        print('PDF файлы не найдены.')
        return

    for pdf_path in pdfs:
        print(f'[+] {pdf_path.name}')
        convert_pdf(pdf_path)

    print('\nГотово.')


if __name__ == '__main__':
    main()
