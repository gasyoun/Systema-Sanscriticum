# Шаблоны страниц: буква «ё» убрана из resources/views (H5242, часть 1 из 2, Fable 5.1 `claude-fable-5-1`, 22-09-2026)

Продолжение H5234 (PR #2758 чистил только письма, Mail-классы и уведомления). [Редакционный гид, §8](https://github.com/gasyoun/Uprava/blob/main/docs/SAMSKRTE_SAMSKRTAM_EDITORIAL_STYLE_GUIDE_2026.md) (редакция 02-09-2026) запрещает «ё» в любом русском тексте, включая комментарии в коде.

- 165 файлов в [`resources/views/`](https://github.com/gasyoun/Systema-Sanscriticum/tree/main/resources/views), 518 замен «ё/Ё» → «е/Е» тем же байт-безопасным скриптом, что и в H5234: каждый измененный файл выводится из HEAD чистой заменой буквы, ни одного другого символа в diff.
- 13 пиновых строк в 10 тестах переехали вместе с текстом страниц (`Голос учтен`, `Курс уже идет`, `Погасить все`, `На сегодня все`, `Автоплатеж ежемесячно` и др.) — [`tests/Feature/`](https://github.com/gasyoun/Systema-Sanscriticum/tree/main/tests/Feature).
- Оставлены (имена собственные, 6 вхождений в 4 файлах): «Артём/Артёму» ([`uptime.blade.php`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/resources/views/uptime.blade.php), контакт хостинга), «Кёльн»/«Кёльнские» (CDSL, [`slovar-cdsl-links.blade.php`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/resources/views/partials/slovar-cdsl-links.blade.php), [`slovar/show.blade.php`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/resources/views/slovar/show.blade.php)), «Кишинёв» (подпись часового пояса, [`timezone-settings.blade.php`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/resources/views/components/timezone-settings.blade.php)). Решение по ним за человеком.
- Миграций нет, поведение не меняется. Часть 2 — `app/Console/Commands` — отдельным PR.

_Гасунс_
