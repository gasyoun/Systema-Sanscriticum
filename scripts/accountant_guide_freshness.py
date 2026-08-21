#!/usr/bin/env python3
"""Предупреждение о протухшей книге бухгалтера (H3214, волна 3).

Если в pull request менялись финэкраны, а docs/ACCOUNTANT_CABINET_GUIDE_RU.md
не тронут — печатаем предупреждение.

Предупреждение, а не блокировка. Скрипт ВСЕГДА завершается кодом 0.

Использование:
    git diff --name-only <base>...<head> | python scripts/accountant_guide_freshness.py
    python scripts/accountant_guide_freshness.py <файл> [<файл> ...]
"""

from __future__ import annotations

import fnmatch
import sys

sys.stdout.reconfigure(encoding="utf-8")
sys.stderr.reconfigure(encoding="utf-8")

GUIDE = "docs/ACCOUNTANT_CABINET_GUIDE_RU.md"

WATCHED = (
    "app/Filament/Pages/AccountantGuide.php",
    "app/Filament/Pages/PayoutAttributionGuide.php",
    "app/Filament/Pages/TeacherSalaries.php",
    "app/Filament/Pages/FinanceCockpit.php",
    "app/Filament/Pages/FinancePlanning.php",
    "app/Filament/Pages/CourseStreamComparison.php",
    "app/Filament/Pages/CourseBlockParticipants.php",
    "app/Filament/Resources/PaymentResource*",
    "app/Filament/Resources/TeacherPayoutResource*",
    "app/Filament/Resources/ExpenseResource*",
    "app/Filament/Resources/TeacherPayoutAttributionSuggestionResource*",
)


def changed_files(argv: list[str]) -> list[str]:
    if argv:
        return [line.strip() for line in argv if line.strip()]
    return [line.strip() for line in sys.stdin.read().splitlines() if line.strip()]


def touched(files: list[str]) -> list[str]:
    hits = []
    for path in files:
        normalized = path.replace("\\", "/")
        if any(fnmatch.fnmatch(normalized, pattern) for pattern in WATCHED):
            hits.append(normalized)
    return sorted(set(hits))


def main() -> int:
    files = changed_files(sys.argv[1:])

    if not files:
        print("accountant-guide-freshness: изменённых файлов не передано — пропускаю.")
        return 0

    hits = touched(files)

    if not hits:
        print("accountant-guide-freshness: наблюдаемые файлы не затронуты.")
        return 0

    guide_changed = any(path.replace("\\", "/") == GUIDE for path in files)

    if guide_changed:
        print(f"accountant-guide-freshness: наблюдаемые файлы затронуты, {GUIDE} тоже — порядок.")
        return 0

    listing = "\n".join(f"  - {path}" for path in hits)
    print(
        f"::warning file={GUIDE}::"
        f"Изменены файлы, влияющие на то, что видит бухгалтер, "
        f"а {GUIDE} не обновлён. Проверьте, не устарел ли текст."
    )
    print(f"accountant-guide-freshness: задеты наблюдаемые файлы, {GUIDE} без изменений:")
    print(listing)
    print("Это предупреждение, а не ошибка: правка могла быть чисто внутренней.")

    return 0


if __name__ == "__main__":
    raise SystemExit(main())
