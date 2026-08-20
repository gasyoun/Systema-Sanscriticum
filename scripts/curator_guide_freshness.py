#!/usr/bin/env python3
"""Предупреждение о протухшем гиде куратора (H3213, волна 2).

Если в pull request менялись файлы, влияющие на то, ЧТО ВИДИТ куратор в
панели, а docs/CURATOR_ADMIN_GUIDE_RU.md не тронут — печатаем предупреждение.

Предупреждение, а не блокировка. Скрипт ВСЕГДА завершается кодом 0.

Использование:
    git diff --name-only <base>...<head> | python scripts/curator_guide_freshness.py
    python scripts/curator_guide_freshness.py <файл> [<файл> ...]
"""

from __future__ import annotations

import fnmatch
import sys

sys.stdout.reconfigure(encoding="utf-8")
sys.stderr.reconfigure(encoding="utf-8")

GUIDE = "docs/CURATOR_ADMIN_GUIDE_RU.md"

WATCHED = (
    "app/Filament/Pages/Helpdesk.php",
    "app/Filament/Pages/CuratorGuide.php",
    "app/Filament/Pages/CabinetMasteryQuiz.php",
    "app/Filament/Pages/Dashboard.php",
    "app/Filament/Pages/TelegramSupportAnalytics.php",
    "app/Filament/Resources/UserResource*",
    "app/Filament/Resources/AccessAttemptResource*",
    "app/Filament/Resources/LeadResource*",
    "app/Filament/Resources/PaymentResource.php",
    "app/Filament/Resources/WaitlistEntryResource*",
    "app/Filament/Resources/IntakeResource*",
    "app/Filament/Clusters/TelegramSupport.php",
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
        print("curator-guide-freshness: изменённых файлов не передано — пропускаю.")
        return 0

    hits = touched(files)

    if not hits:
        print("curator-guide-freshness: наблюдаемые файлы не затронуты.")
        return 0

    guide_changed = any(path.replace("\\", "/") == GUIDE for path in files)

    if guide_changed:
        print(f"curator-guide-freshness: наблюдаемые файлы затронуты, {GUIDE} тоже — порядок.")
        return 0

    listing = "\n".join(f"  - {path}" for path in hits)
    print(
        f"::warning file={GUIDE}::"
        f"Изменены файлы, влияющие на то, что видит куратор, "
        f"а {GUIDE} не обновлён. Проверьте, не устарел ли текст."
    )
    print(f"curator-guide-freshness: задеты наблюдаемые файлы, {GUIDE} без изменений:")
    print(listing)
    print("Это предупреждение, а не ошибка: правка могла быть чисто внутренней.")

    return 0


if __name__ == "__main__":
    raise SystemExit(main())
