# RUNBOOK — запуск новой учебной группы (end-to-end)

_Created: 14-11-2026 · Last updated: 14-11-2026_

> Эталонный прогон: **гр.64 «Грамматика по Кочергиной»** (субботы 11:00 МСК, старт 14-11-2026) —
> курс 463, группа 157, zoom 84208866339, чат `-1004442261158`. Все шаги исполнены по этому
> runbook за один заход. Ближайшие родственники: курс 452 / группа 150 (гр.63, вторник 08:00).
> Заметка исполнителя в [.ai_state.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/.ai_state.md) (14-11-2026).

## 0. Входные данные (запросить у MG до старта)

| Что | Пример (гр.64) |
|---|---|
| Название + номер группы | «Грамматика по Кочергиной гр.64» |
| Расписание | еженедельно по субботам 11:00 МСК, занятие 120 мин |
| Первый урок | 14.11.2026 |
| Zoom-аккаунт хоста | «зум Анатолия» = Account_1 (`K4GUTP_…`), cred «Zoom Цыди» `47UA7kp1sAv9NCe3` в n8n на `.91` |
| Курс-образец | предыдущая группа этого же курса (курс 452 / группа 150) |
| TG-чат учеников | уже создан MG, invite-линк известен |
| Модель оплаты | обычная (чекауты) или B2B (студия платит за всех → handoff H8245/H8250) |

## 1. Zoom recurring-встреча

Cred лежат в n8n sqlite (`.91`, таблица `credentials_entity`), расшифровка — паттерн
[`scripts/n8n_zoom_per_account_probe.js`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/scripts/n8n_zoom_per_account_probe.js):
хост читает blob → `docker exec n8n-n8n-1` decrypt (crypto-js из
`/usr/local/lib/node_modules/n8n/node_modules/.pnpm/crypto-js@*/…`) под `N8N_ENCRYPTION_KEY`
→ `refresh_token` grant **в памяти** (ничего не пишем обратно).

```
POST https://api.zoom.us/v2/users/me/meetings
{
  "topic": "Грамматика <День> <Время> (NN)",
  "type": 3,                          // recurring без фиксированного времени — дом-стандарт
  "timezone": "Europe/Moscow",
  "settings": {
    "join_before_host": true,
    "waiting_room": false,
    "auto_recording": "cloud"         // ОБЯЗАТЕЛЬНО: труба записей живёт на recording.completed
  }
}
```

Записать: `id` (meeting_id), `join_url`, **`password`** (passcode — нужен для захода с телефонов).
Settings-паритет с существующими группами: `join_before_host=true, waiting_room=false,
auto_recording=cloud, approval_type=2, audio=both`.

## 2. Prod DB: курс + группа + расписание (`.92`, `/var/www/html`)

PHP-скрипт с бутстрапом (`require vendor/autoload.php` + `bootstrap/app.php` + Kernel::bootstrap),
idempotency-guard по `Course::where('title',…)`:

- `Course`: `title`, **`slug` (обязателен — автогенерации НЕТ, иначе SQL 1364;**
  паттерн `grammatika-po-kocerginoi-grNN`), `format`/`teacher_id`/`lessons_count`/
  `hours_count`/`level` — mirror курса-образца; `zoom_link = join_url` — мутатор
  `setZoomLinkAttribute` сам выведет `zoom_meeting_id`; `is_visible=1, is_active=1`.
- `Group::create([name, status='forming', telegram_join_link, min_size=8])`.
- Расписание — [`ScheduleGenerator`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Services/Schedule/ScheduleGenerator.php)
  + `GeneratorConfig(groupId, courseId, title, startDate=<первый урок>, startTime='11:00',
  durationMinutes=120, totalLessons=60, startNumber=1, startLessonIndex=1,
  weekdays=[Carbon::SATURDAY], template='… (#{N}, {DATE})', link=<join_url>, preserve=false).
  Генератор сам глушит observers (n8n не дёргается на 60 строк) и рендерит
  `Грамматика по Кочергиной гр.64 (#1, 14.11.26)` через
  [`TemplateRenderer`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Services/Schedule/TemplateRenderer.php).
- Pivot **`course_group`** (course_id, group_id) — без него group grant не даёт доступ к курсу.
- Timestamps в DB — Europe/Moscow (app timezone), расписание пишем локальным МСК-временем.

## 3. TG-чат → напоминания «Скоро занятие»

- **chat_id — критичный шаг.** НОВЫЕ чаты НЕ попадают в `telegram_support_chats` (sync не
  увидел), и `checkChatInviteLink` у бота отвечает 404. Надёжный источник — корпус zapisi-бота
  на `.92` (бот должен быть участником чата, запись появляется после первого сообщения):

  ```sh
  python3 - <<'EOF'
  import json, os, glob
  base='/var/www/html/storage/app/telegram-harvest/raw/corpus'
  for d in sorted(glob.glob(base+'/*'), key=os.path.getmtime, reverse=True)[:15]:
      fs=sorted(glob.glob(d+'/*.jsonl'))
      if fs: print(os.path.basename(d), json.loads(open(fs[-1]).readlines()[-1]).get('peer_title'))
  EOF
  ```

- Механизм напоминаний автоматический, код писать не нужно:
  [`zapisi:remind-classes`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Console/Commands/RemindZapisiClasses.php)
  каждые 5 мин, флаг `telegram_zapisi_bot` (прод ON), lead 60 мин
  (`MarketingSetting.zapisi_reminder_lead_minutes`). Слот-группировка и дедупы
  (TelegramSendGuard, `zapisi_reminded_at` ↔ `group_link_posted_at`) уже в системе.
- Чек-лист перед первым окном T-60: `schedules.link` не пуст (no-link guard молча пропустит
  и `report()`-ит), `zapisi_reminded_at` NULL, `groups.telegram_chat_id` выставлен, бот в чате.

## 4. Приветственное сообщение → чат

Отправка от @zapisi_ORSbot (`sendMessage`, token — `MarketingSetting.zapisi_bot_token`,
encrypted). Состав (эталон — msg 11 чата гр.64 от 14-11-2026):

- приветствие «Намо намах!», расписание (день/время МСК, длительность, дата первого занятия);
- zoom-ссылка; **отдельно Meeting ID и passcode** — с телефона ссылка может не подхватить pwd;
- «кто за что отвечает»: занятия — преподаватель; организация/доступы/записи — куратор школы
  (пишите в этот чат); тех-проблемы (звук, вход) — @ivanmolochko;
- учебник; ссылка на кабинет (`https://samskrte.ru/login`);
- строка про оплату (обычная модель — ссылка на оплату; B2B — «оплату централизованно вносит
  студия, от вас ничего платить не нужно»).

## 5. Календарь

`.ics` с `VTIMEZONE Europe/Moscow` + `RRULE:FREQ=WEEKLY;BYDAY=<DAY>;COUNT=60` →
`sendDocument` в чат + подпись-инструкция «Google Календарь → Настройки → Импорт».
Персональный webcal-фид студента (`/calendar/feed/{user}/{token}.ics`) включается сам
после создания кабинета — отдельно настраивать не нужно.

## 6. Личные кабинеты

`User::create(name, email, password=Hash::make(Str::random(16)))` + вставка в **`group_user`**
(user_id, group_id) + (если ещё нет) **`course_group`**. Доступ к материалам = group grant —
тот же path, что у предыдущих групп; проверка эталона: `group_user` курса-образца.
Пароли random и нигде не сохраняются агентом — вход ученику куратор выдаёт magic-ссылкой
из карточки («Выдать новую ссылку»). Email-дедуп: существующий `users.email` → переиспользовать
карточку, не создавать дубль.

## 7. Анонс в публичный канал

«ОПОВЕЩЕНИЯ САНСКРИТЯН» = chat_id `-1001762848803` (@zapisi_ORSbot — может постить).
Только факты: название, день/время МСК, преподаватель, учебник, «вопросы и запись: @rusamskrtam».
**Никогда** не публиковать zoom-ссылку и invite-ссылку закрытого чата (публичная поверхность).

## 8. Оплата

- Обычная модель: тарифы/чекаут — существующий путь, ничего дополнительно.
- B2B (студия/организация платит за группу целиком): тариф-ключ `b2b_group`, платёж несёт
  `group_id` — контур **H8245** (money fence: PR + ревью MG, авто-merge запрещён) + **H8250**
  (напоминания плательщику, флаг `B2B_PAYER_REMINDERS`, DEPLOY_QUEUE №95). Кабинеты и group
  grant заводятся сразу — фактический платёж заводится в Filament, когда пройдёт.

## 9. Материалы

Уроки курса (`lessons`) заводятся **после** занятия (запись + домашка
«Выполните все упражнения к этому занятию учебника Кочергиной», `homework_enabled=1`)
по образцу предыдущей группы. До первого занятия в системе заводить нечего —
ученикам нужен бумажный учебник. После занятия №1: создать урок, проверить,
что запись доехала по трубе (n8n ZOOM 1.4,Account реестр в `Code in JavaScript1`).

## Готчи (собранные на эталонном прогоне)

- `Course.slug` без значения = SQL 1364 — автогенерации нет.
- chat_id нового чата искать в corpus zapisi-бота, не в `telegram_support_chats`.
- `checkChatInviteLink` у бота — 404 «Not Found», если бот не член чата: не индикатор битой ссылки.
- Основное дерево Systema часто в смешанном WIP — изменения only в worktree + PR
  ([/worktree-claim](https://github.com/gasyoun/claude-config/blob/main/commands/worktree-claim.md)).
- Локальные часы машин флота расходятся с продом — время замерять по серверу.

## Чек-лист приёмки (после прогона)

- [ ] `SELECT COUNT(*) FROM schedules WHERE group_id=<N>` = плановое число, min/max start верны
- [ ] `courses.zoom_meeting_id` = zoom id, `zoom_link` = join_url
- [ ] `groups.telegram_chat_id` = chat_id чата учеников
- [ ] приветствие + .ics доставлены в чат (message_id зафиксирован)
- [ ] анонс в «Оповещениях» без приватных ссылок
- [ ] кабинеты: `group_user` count = числу учеников, `course_group` связка есть
- [ ] окно T-60: напоминание ушло (или слот-причина объяснена)

_Dr. Mārcis Gasūns_
