#!/usr/bin/env python3
"""Предупреждение о протухшем гиде студента (H3212, волна 1).

Если в pull request менялись файлы, влияющие на то, ЧТО ВИДИТ студент в
кабинете, а docs/STUDENT_CABINET_GUIDE_RU.md не тронут — печатаем предупреждение.

Предупреждение, а не блокировка: правка бывает чисто внутренней.
Скрипт ВСЕГДА завершается кодом 0.

Наблюдаемые пути — docs/ARCHITECTURE_SYSTEMA_AUDIENCE_CABINET_GUIDES.md §6;
сюда дублируются осознанно (CI не читает markdown).

Использование:
    git diff --name-only <base>...<head> | python scripts/student_guide_freshness.py
    python scripts/student_guide_freshness.py <файл> [<файл> ...]
"""

from __future__ import annotations

import fnmatch
import sys

sys.stdout.reconfigure(encoding="utf-8")
sys.stderr.reconfigure(encoding="utf-8")

GUIDE = "docs/STUDENT_CABINET_GUIDE_RU.md"

WATCHED = (
    "resources/views/student/**",
    "app/Http/Controllers/StudentController.php",
    "app/Http/Controllers/SrsController.php",
    "resources/views/help/**",
    "routes/web.php",
)


def changed_files(argv: list[str]) -> list[str]:
    if argv:
        return [line.strip() for line in argv if line.strip()]
    return [line.strip() for line in sys.stdin.read().splitlines() if line.strip()]


def is_watched_web_route(path: str) -> bool:
    return path.replace("\\", "/") == "routes/web.php"


def touched(files: list[str]) -> list[str]:
    hits = []
    for path in files:
        normalized = path.replace("\\", "/")
        if is_watched_web_route(normalized):
            hits.append(normalized)
            continue
        if any(fnmatch.fnmatch(normalized, pattern) for pattern in WATCHED):
            hits.append(normalized)
    return sorted(set(hits))


def main() -> int:
    files = changed_files(sys.argv[1:])

    if not files:
        print("student-guide-freshness: изменённых файлов не передано — пропускаю.")
        return 0

    hits = touched(files)

    if not hits:
        print("student-guide-freshness: наблюдаемые файлы не затронуты.")
        return 0

    guide_changed = any(path.replace("\\", "/") == GUIDE for path in files)

    if guide_changed:
        print(f"student-guide-freshness: наблюдаемые файлы затронуты, {GUIDE} тоже — порядок.")
        return 0

    listing = "\n".join(f"  - {path}" for path in hits)
    print(
        f"::warning file={GUIDE}::"
        f"Изменены файлы, влияющие на то, что видит студент, "
        f"а {GUIDE} не обновлён. Проверьте, не устарел ли текст."
    )
    print(f"student-guide-freshness: задеты наблюдаемые файлы, {GUIDE} без изменений:")
    print(listing)
    print("Это предупреждение, а не ошибка: правка могла быть чисто внутренней.")

    return 0


if __name__ == "__main__":
    raise SystemExit(main())
