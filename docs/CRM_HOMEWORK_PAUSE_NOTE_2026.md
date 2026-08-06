# CRM: пауза по ДЗ — примечание куратора (H2320)

_Created: 06-08-2026 · Last updated: 06-08-2026_

## Правило

Статус «временно не сдаю ДЗ / застой / больничный / догоню» живёт в
**`users.note` (Примечание куратора)** на карточке студента — **не** в
`HomeworkSubmission.status` (там только Черновик / На проверке / На доработку /
Принято).

## A — агент (standing)

Если в сессии есть **id / @username / /s/{id}** + текст статуса про ДЗ/жизнь:

1. Найти `User` (prod tinker или Filament).
2. **Append** (не затирать) строку в `note` с датой.
3. **Не спрашивать** «отразить ли в CRM».
4. ДЗ-статус не менять.

Шаблон:

```
YYYY-MM-DD: пауза по ДЗ. Не давить, check-in ~+14д. Контекст: <группа/слот если есть>. Источник: <ЛС/чат>.
```

## B — продукт (auto)

`App\Services\Support\HomeworkPauseNoteRecorder` на входящих:

| Канал | Хук |
|---|---|
| Веб-чат кабинета | `StudentChatService::recordIncoming` |
| TG support userbot | `TelegramSupportSyncService` (linked user) |
| TG bot кабинета | `TelegramWebhookController::processStudentQuestion` |
| VK bot | `ProcessVkBotMessage` |

Условие: **homework_cue AND life_cue** (см. `config/support.php` →
`homework_pause_note`). Идемпотентность: маркер
`[auto-hw-pause:YYYY-MM-DD]` — не чаще 1 раза в календарный день.

Флаг: `SUPPORT_HOMEWORK_PAUSE_NOTE` (default **true**).

Опционально analytics: seeder
`HomeworkPauseTopicRuleSeeder` → category `homework_pause` для daily rollup.

## Ограничения

- Сообщение, которое **не попало** в helpdesk/sync (другой ЛС-аккаунт, privacy),
  auto **не увидит** — тогда только A (агент по пингу человека).
- Не пишет topic на операционный `SupportConversation` — только `users.note`
  (+ optional rollup rule).

_Dr. Mārcis Gasūns_
