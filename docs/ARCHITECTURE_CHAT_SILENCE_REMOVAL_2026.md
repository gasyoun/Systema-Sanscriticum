# Исключение из учебного TG-чата: молчит + урок закрыт

_Created: 10-11-2026 · Last updated: 10-11-2026_

Opus 5.5 (`claude-opus-5-5`). Бот **предлагает**, админ **жмёт**, банит
**Почтальон** (@zapisi_ORSbot). Обратный путь (разбан, возврат) ручной.

**Команда:** `php artisan chats:silence-removal-propose [--dry-run] [--all] [--reveal]`
**Выключатель:** `CHAT_SILENCE_REMOVAL_ENABLED` (по умолчанию `false`)
**Смежное:** [реестр H2746 (долг + взнос)](ARCHITECTURE_SYSTEMA_COURSE_DEBT_CHAT_REINSTATEMENT_LEDGER_2026.md)

---

## 1. Правило

Студента предлагают исключить из чата, когда сходится **всё сразу**:

| # | Условие | Откуда |
|---|---|---|
| 1 | он есть в чате | ростер юзербота `{store}/roster/{chat}.json` (`telegram-harvest:roster-groups`, раз в час) |
| 2 | аккаунт однозначно сопоставлен с учёткой | [`TelegramAccountResolver`](../app/Services/Telegram/TelegramAccountResolver.php): `users.telegram_id`, затем контакт лички |
| 3 | не персонал | `users.role` пуст или `student`, нет `is_admin` / `teacher_id` |
| 4 | **молчит ≥ 7 дней** | корпус вебхука Почтальона `{store}/corpus/{chat}/{дата}.jsonl`, поле `telegram_user_id` |
| 5 | в группе дольше 7 дней | `group_user.created_at` |
| 6 | **кабинет закрывает ему последний прошедший платный урок** курса группы (урок старше 3 дней) | [`AccessDiagnosticsService`](../app/Services/AccessDiagnosticsService.php): `ownedKeys()` + `isLessonAccessible()`, т. е. гейт кабинета |
| 7 | закрытость — не поломка данных | `hasPaidCoverageMissingKey()`: оплата блока есть, а ключа нет — не трогаем |
| 8 | ещё не исключён | `chat_silence_removals.status = removed` |

**Почему не «Должники».** Расчёт долга (`DebtorsReport`) признан неточным;
условие 6 спрашивает ровно то, что видит студент: открыт ли ему урок.

**Почему не одно молчание.** На 10-11-2026 по ростерам 24 чатов 310
участников; за 7 дней писали 48 (15%), за 30 — 109. Одно молчание дало бы
~260 предложений, включая оплативших тихих студентов и преподавателей. В паре
с условием 6 — около 20.

**Чат пропускается целиком**, если снимок ростера старше
`TELEGRAM_HARVEST_ROSTER_STALE_AFTER_HOURS` или за окно молчания в чат не
писал **никто**: сломанный вебхук неотличим от тишины.

Охват ограничен сопоставлением: из 310 участников 204 не привязаны ни к одной
учётке. Правилу они не видны — это безопасно, но такой человек не попадёт в
предложения, пока не привяжет Telegram.

## 2. Поток

1. `chats:silence-removal-propose` (ежедневно 11:20, только при включённом
   выключателе) пишет строки `proposed` и шлёт админам (`ADMIN_TELEGRAM_ID`,
   основной бот) одно сообщение на студента: чаты, дата последнего сообщения,
   закрытый урок, ссылка на карточку. Кнопки `csr:<user>` «Исключить» и
   `css:<user>` «Не трогать».
2. Нажатие → `TelegramWebhookController::handleCallbackQuery` →
   [`ChatSilenceRemovalService::handleCallback`](../app/Services/Discipline/ChatSilenceRemovalService.php).
   Решать вправе только admin / super_admin (как кнопка кика в «Должниках»).
3. «Исключить»: строки атомарно забираются (`proposed → executing`, второй
   клик получает «Уже решено»), правило **пересчитывается на момент нажатия**,
   по каждому проходящему чату — `ZapisiChatMemberService::kick()` (hard ban).
   Исход в строке: `removed` / `failed` (текст отказа Telegram) / `dismissed`
   (заплатил или написал, пока ждали). Сообщение с кнопками правится в итог.
4. `removed` попадает в журнал банов дашборда «Записи (бот)»
   ([`ZapisiBanJournal`](../app/Services/Telegram/ZapisiBanJournal.php)) —
   там же разбан, который переводит строку в `unbanned`.
5. Пока строка `removed`, `ChatJoinRequestApprover` не одобряет заявку этого
   студента в этот чат, даже после оплаты.

## 3. Кулдауны

| Исход | Когда предложим снова |
|---|---|
| не нажали | через `CHAT_SILENCE_REMOVAL_PROPOSE_REPEAT_DAYS` (7) |
| «Не трогать» | через `CHAT_SILENCE_REMOVAL_SNOOZE_DAYS` (14) |
| Telegram отказал | через 7 дней |
| не доставлено ни одному админу | на следующем прогоне |
| исключён | никогда, пока не разбанен |

## 4. Запуск

```bash
php artisan chats:silence-removal-propose --dry-run        # обезличенно, ничего не пишет
php artisan chats:silence-removal-propose --dry-run --all  # плюс причины отсева
```

После просмотра сухого прогона — `CHAT_SILENCE_REMOVAL_ENABLED=true` в `.env`
прода. Пороги: `CHAT_SILENCE_REMOVAL_SILENCE_DAYS`,
`CHAT_SILENCE_REMOVAL_ACCESS_GRACE_DAYS`.

Тесты: [`ChatSilenceRemovalTest`](../tests/Feature/ChatSilenceRemovalTest.php).

_Dr. Mārcis Gasūns_
