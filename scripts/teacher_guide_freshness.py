#!/usr/bin/env python3
"""Предупреждение о протухшем руководстве преподавателя (H2501, волна 1).

Если в pull request менялись файлы, влияющие на то, ЧТО ВИДИТ преподаватель в
панели, а docs/TEACHER_CABINET_GUIDE_RU.md не тронут — печатаем предупреждение
с перечнем задевших файлов.

Предупреждение, а не блокировка (решение R11): правка бывает чисто внутренней
(переименование метода, рефакторинг) и текста не требует. Блок в таком случае
заставлял бы дописывать в руководство пустую строку ради зелёного CI — то есть
портить документ ради проверки. Поэтому скрипт ВСЕГДА завершается кодом 0.

Наблюдаемые пути перечислены в
docs/ARCHITECTURE_SYSTEMA_TEACHER_CABINET_GUIDE.md §6 — правится там, дублируется
сюда осознанно (CI не читает markdown).

Использование:
    git diff --name-only <base>...<head> | python scripts/teacher_guide_freshness.py
    python scripts/teacher_guide_freshness.py <файл> [<файл> ...]
"""

from __future__ import annotations

import fnmatch
import sys

sys.stdout.reconfigure(encoding="utf-8")
sys.stderr.reconfigure(encoding="utf-8")

GUIDE = "docs/TEACHER_CABINET_GUIDE_RU.md"

WATCHED = (
    "app/Filament/Pages/Teacher*.php",
    "app/Filament/Pages/MutualSettlements.php",
    "app/Filament/Pages/AttendanceDashboard.php",
    "app/Filament/Pages/CalendarPage.php",
    "app/Filament/Pages/LessonMaterials.php",
    "app/Filament/Resources/LessonResource*",
    "app/Filament/Resources/CourseResource*",
    "app/Filament/Resources/HomeworkSubmissionResource*",
    "app/Filament/Resources/ScheduleResource*",
    "app/Filament/Resources/CertificateResource*",
    "app/Filament/Resources/StudentGroupResource*",
    "app/Filament/Widgets/DisciplineByGroupWidget.php",
    "app/Filament/Widgets/ScheduleCalendarWidget.php",
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
        print("teacher-guide-freshness: изменённых файлов не передано — пропускаю.")
        return 0

    hits = touched(files)

    if not hits:
        print("teacher-guide-freshness: наблюдаемые файлы не затронуты.")
        return 0

    guide_changed = any(path.replace("\\", "/") == GUIDE for path in files)

    if guide_changed:
        print(f"teacher-guide-freshness: наблюдаемые файлы затронуты, {GUIDE} тоже — порядок.")
        return 0

    listing = "\n".join(f"  - {path}" for path in hits)
    print(
        f"::warning file={GUIDE}::"
        f"Изменены файлы, влияющие на то, что видит преподаватель, "
        f"а {GUIDE} не обновлён. Проверьте, не устарел ли текст."
    )
    print(f"teacher-guide-freshness: задеты наблюдаемые файлы, {GUIDE} без изменений:")
    print(listing)
    print("Это предупреждение, а не ошибка: правка могла быть чисто внутренней.")

    return 0


if __name__ == "__main__":
    raise SystemExit(main())
