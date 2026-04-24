"""
Скрипт для извлечения слов из указателей географических и этнических названий.
Сканирует текущую папку, обрабатывает все .txt файлы (UTF-8).

Поддерживает два формата входных строк:
  1. Уже очищенные слова:       "Аванти"
  2. Строки с номерами страниц: "Аванти 130, 206, 654"
  3. Строки-ссылки:             "андхраки см. андхры"

Результат — уникальные слова/словосочетания — сохраняется в _out.txt

Использование:
    python3 extract_index_words.py
"""

import re
import sys
from pathlib import Path


def extract_entries_from_text(text: str) -> list[str]:
    entries = []
    seen = set()

    for line in text.splitlines():
        line = line.strip()
        if not line:
            continue

        # Формат 1: строка с номерами страниц — берём только левую часть
        m = re.match(
            r"^([А-ЯЁа-яё][А-ЯЁа-яё\s\(\)\-\.]*?)\s+\d[\d,;\s\-\.]*$",
            line
        )
        if m:
            name = m.group(1).strip()
            if len(re.findall(r"[А-ЯЁа-яё]", name)) >= 2 and name not in seen:
                seen.add(name)
                entries.append(name)
            continue

        # Формат 2: строка-ссылка "xxx см. yyy" — берём левую часть
        m2 = re.match(
            r"^([А-ЯЁа-яё][А-ЯЁа-яё\s\(\)\-\.]*?)\s+см\.",
            line
        )
        if m2:
            name = m2.group(1).strip()
            if len(re.findall(r"[А-ЯЁа-яё]", name)) >= 2 and name not in seen:
                seen.add(name)
                entries.append(name)
            continue

        # Формат 3: уже чистое слово/словосочетание (только кириллица + скобки/дефисы)
        if re.match(r"^[А-ЯЁа-яё][А-ЯЁа-яё\s\(\)\-\.]*$", line):
            name = line
            if len(re.findall(r"[А-ЯЁа-яё]", name)) >= 2 and name not in seen:
                seen.add(name)
                entries.append(name)

    return entries


def main():
    folder = Path(".")
    txt_files = sorted(f for f in folder.glob("*.txt") if f.name != "_out.txt")

    if not txt_files:
        print("Файлы .txt не найдены в текущей папке.")
        sys.exit(0)

    all_entries = []
    seen_global = set()

    for txt_file in txt_files:
        print(f"Обрабатываю: {txt_file.name}")
        text = txt_file.read_text(encoding="utf-8", errors="replace")
        entries = extract_entries_from_text(text)

        added = 0
        for e in entries:
            if e not in seen_global:
                seen_global.add(e)
                all_entries.append(e)
                added += 1

        print(f"  → найдено: {len(entries)}, новых уникальных: {added}")

    out_path = folder / "_out.txt"
    out_path.write_text("\n".join(all_entries) + "\n", encoding="utf-8")
    print(f"\nВсего уникальных записей: {len(all_entries)}")
    print(f"Сохранено в: {out_path.resolve()}")


if __name__ == "__main__":
    main()
