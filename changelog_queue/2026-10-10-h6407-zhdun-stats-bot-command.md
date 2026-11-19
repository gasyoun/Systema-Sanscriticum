# Ждун-бот: /zhdun-stats — кто за какой курс, только кураторский чат (H6407, GLM 5.3 `account:zai-individual-coding-plan/GLM-5.3-Flash`, ZCode; 10-10-2026)

_Created: 10-10-2026 · Last updated: 10-10-2026_

WaitlistBotService (@samskrte_bot) получил команду `/zhdun-stats` ([PR #3152](https://github.com/gasyoun/Systema-Sanscriticum/pull/3152), squash `3dab3df3`): по каждой активной строке course_waitlist_items (та же выборка, что у /zhdun и сайта — без closed/scheduled, порядок витрины) — курс, голоса/кворум и каждый голосовавший: имя из users, дата голоса (d.m.Y), slot_preference («любое время» для null). Свыше ~3800 символов ответ режется на несколько сообщений по целым курсам.

Гейт — конвенция CuratorNotifier: `services.telegram.curators_chat_id` (env TELEGRAM_CURATORS_CHAT_ID). Совпадение chat_id — полный ответ; чужой чат или пустой конфиг — вежливый отказ без каких-либо данных (ни имён, ни счётчиков); в приветственном меню команда не афишируется.

Проверки: `phpunit --filter=WaitlistBot` 10/10 зелёные на ветке и 13/13 на составном main после H6408 (#3153 трогал тот же файл); Pint PASS. Деплой на .92 (`deploy.sh`, `adebfaf9 → 3dab3df3`, смоуки 200/cabinet OK). Live-проба на проде 10-10: суммы совпали со срезом хэндоффа — 37 голосов, топ «Руководство по Бюлеру» 4 / 10 (строк в списке 26 против 16 на утреннем срезе — ряды добавлены за день, суммы те же); реальный sendMessage в кураторский чат `-1003753660659` ушёл без ошибок в логе; не-админ-проба (chat 4242, транспорт заглушен) — ровно строка отказа, leak-check CLEAN.

_к.ф.н. М.Ю. Гасунс_
