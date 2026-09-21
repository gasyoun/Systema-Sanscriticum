# Повторное приглашение через 48 ч: буква «ё» убрана из письма, Telegram-текста и консольных строк (H5206, Fable 5.1 `claude-fable-5-1`, 21-09-2026)

Находка верификатора H5039 по H5022: [редакционный гид, §8](https://github.com/gasyoun/Uprava/blob/main/docs/SAMSKRTE_SAMSKRTAM_EDITORIAL_STYLE_GUIDE_2026.md) запрещает букву «ё» в любом русском тексте, включая письма и комментарии в коде, а копия повторного приглашения её содержала.

- [`reinvite-48h.blade.php`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/resources/views/emails/onboarding/reinvite-48h.blade.php) — 1 замена («в своем темпе»); [`SendPaidNeverLoginReinvite.php`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Console/Commands/SendPaidNeverLoginReinvite.php) — 12 замен: Telegram-текст, консольные строки («еще не приглашались», «Еще не созрели»), опция `--report` и комментарии. Смысл и ведущий аргумент «записи занятий и свой темп» не тронуты.
- [`SendPaidNeverLoginReinviteTest.php`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/tests/Feature/SendPaidNeverLoginReinviteTest.php) — 2 замены: тест пинил подстроку «ещё не приглашались: 1» из консольного вывода, без правки он бы покраснел; второй случай — комментарий.
- Миграций нет, поведение команды не меняется.

_Гасунс_
