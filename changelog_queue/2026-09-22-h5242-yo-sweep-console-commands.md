# Консольные команды: буква «ё» убрана из app/Console/Commands (H5242, часть 2 из 2, Fable 5.1 `claude-fable-5-1`, 22-09-2026)

Вторая половина H5242 (часть 1 — `resources/views`). [Редакционный гид, §8](https://github.com/gasyoun/Uprava/blob/main/docs/SAMSKRTE_SAMSKRTAM_EDITORIAL_STYLE_GUIDE_2026.md) (редакция 02-09-2026) запрещает «ё» в любом русском тексте, включая комментарии в коде и вывод artisan-команд.

- 171 файл в [`app/Console/Commands/`](https://github.com/gasyoun/Systema-Sanscriticum/tree/main/app/Console/Commands), 818 замен «ё/Ё» → «е/Е» тем же байт-безопасным скриптом: каждый измененный файл выводится из HEAD чистой заменой буквы, ни одного другого символа в diff; `php -l` на всех 171 файлах чист.
- 13 пиновых строк в 6 тестах переехали вместе с выводом команд (`Двойной счет`, `курс не определен`, `отключен (is_enabled=0)`, `ВКЛЮЧЕН`, заголовки ведомости выплат и др.) — [`tests/Feature/`](https://github.com/gasyoun/Systema-Sanscriticum/tree/main/tests/Feature).
- Оставлены (8 вхождений в 3 файлах): «Артём/Артёма» (контакт хостинга, [`ProbeCabinetHealth.php`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Console/Commands/ProbeCabinetHealth.php)), «Бётлингк» (имя лексикографа в названии колоды, [`ImportSubhashitaAudioDeck.php`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Console/Commands/ImportSubhashitaAudioDeck.php)) и рабочая строка нормализатора `str_replace(['ё', 'Ё'], 'е', …)` в [`LinkTeacherUsers.php`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Console/Commands/LinkTeacherUsers.php) — это код, а не текст, менять его нельзя. Решение по именам за человеком.
- Миграций нет, поведение команд не меняется, ни одно письмо и ни один `--send` не запускались.

_Гасунс_
