# Письма студентам: буква «ё» убрана из шаблонов писем, Mail-классов и уведомлений (H5234, Fable 5.1 `claude-fable-5-1`, 21-09-2026)

Продолжение H5206 (PR #2750 правил только повторное приглашение через 48 ч). [Редакционный гид, §8](https://github.com/gasyoun/Uprava/blob/main/docs/SAMSKRTE_SAMSKRTAM_EDITORIAL_STYLE_GUIDE_2026.md) (редакция 02-09-2026) запрещает «ё» в любом русском тексте, включая комментарии в коде.

- 25 файлов, 42 замены «ё/Ё» → «е/Е» байт-безопасным скриптом, только буква — ни одного другого символа в diff: [`resources/views/emails/`](https://github.com/gasyoun/Systema-Sanscriticum/tree/main/resources/views/emails) (5 файлов: подтверждение покупки, старт сезона, анкета студента, два письма по переводу преподавателю), [`app/Mail/`](https://github.com/gasyoun/Systema-Sanscriticum/tree/main/app/Mail) (19 файлов, включая README.md), [`app/Notifications/BackupNotifiable.php`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Notifications/BackupNotifiable.php).
- [`PurchaseOnboardingSequenceTest.php`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/tests/Feature/Mail/PurchaseOnboardingSequenceTest.php): пиновая строка «всё еще нет» переехала вместе с текстом письма; прежнее исключение «всё» из проверки на «ё» (правило D13 первой волны) снято — теперь письма проверяются на полное отсутствие буквы.
- Исключений (имена собственные, чужие цитаты) в этих трех папках не нашлось. Вне объема остаются `resources/views` (171 файл) и `app/Console/Commands` (170 файлов) — решение о широкой чистке за человеком.
- Миграций нет, поведение писем не меняется, ни одно письмо не отправлялось.

_Гасунс_
