# n8n: выгрузка расписания в Google Таблицу

Воркфлоу `schedule-sheet-sync.workflow.json` принимает снимок расписания из Laravel
(кнопка «Выгрузить в Google Таблицу» в разделе «Расписание») и **полностью перезаписывает**
лист. Направление строго одностороннее: БД → таблица.

## Схема

```
Webhook (POST)  →  Очистить лист  →  Собрать строки (Code)  →  Записать строки
```

Цепочка линейная, поэтому очистка гарантированно завершается до записи. Нода `Собрать строки`
читает `body.schedules` напрямую из ноды `Webhook`, поэтому данные переживают шаг очистки.

## Payload, который шлет Laravel

`App\Filament\Resources\ScheduleResource\Pages\ListSchedules::syncToSheet()` отправляет:

```json
{
  "action": "sheet_sync",
  "generated_at": "24.05.2026 15:30",
  "schedules": [
    { "id": 1, "date": "24.05.2026", "weekday": "понедельник",
      "time": "10:00 – 11:30", "title": "...", "group": "Все",
      "course": "...", "link": "..." }
  ]
}
```

## Настройка после импорта

1. **Импорт.** В n8n: *Workflows → Import from File* → выберите `schedule-sheet-sync.workflow.json`.
2. **Учетка Google.** В обеих нодах Google Sheets («Очистить лист», «Записать строки»)
   выберите свои Google Sheets credentials (плейсхолдер `REPLACE_WITH_YOUR_CREDENTIAL_ID`).
3. **Таблица.** В обеих нодах вставьте ссылку на таблицу вместо `ВСТАВЬТЕ_ССЫЛКУ_НА_ТАБЛИЦУ`.
   Имя листа по умолчанию — `Расписание`; поменяйте, если у вас другое.
4. **Шапка листа.** В первой строке листа должны стоять ровно эти заголовки (auto-map
   сопоставляет их с ключами строк):

   ```
   № | Дата | День недели | Время | Занятие | Группа | Курс | Ссылка
   ```

   `Очистить лист` стоит с `keepFirstRow = true`, так что шапка не стирается.
5. **Активируйте** воркфлоу и скопируйте *Production* URL вебхука.
6. **Laravel.** Пропишите URL в `.env`:

   ```
   N8N_SCHEDULE_SHEET_WEBHOOK=https://<ваш-n8n>/webhook/schedule-sheet-sync
   ```

## Безопасность

Сейчас вебхук без аутентификации — кто угодно с URL может перезаписать лист. URL содержит
случайный путь, но для надежности стоит включить *Header Auth* на ноде Webhook и слать тот же
секрет из `syncToSheet()` (заголовок в `Http::withHeaders([...])`).

---

# n8n: ежемесячный пост «сейчас идут курсы» в ВК и Telegram

Воркфлоу `monthly-schedule-post.workflow.json` принимает готовый пост из Laravel и
публикует его в сообщество ВКонтакте (с картинкой на стене) и в Telegram-канал
(фото с подписью).

## Кто инициирует

Laravel — команда `schedule:post-monthly` (планировщик: **1-е число месяца, 10:00 МСК**,
см. `app/Console/Kernel.php`) или кнопка **«📣 Опубликовать в соцсети»** в разделе
«Расписание». Команда собирает идущие в этом месяце курсы (`MonthlyScheduleDigest`),
рендерит JPG (Blade → DomPDF → Imagick, как сертификаты) в `storage/app/public/monthly/`
и шлет POST в вебхук n8n. Организация в тексте — **ОРС**, не «Академия».

## Payload, который шлет Laravel

```json
{
  "action": "monthly_schedule_post",
  "generated_at": "01.07.2026 10:00",
  "text_tg": "<b>📅 …</b>\n• <b>Курс</b> — Препод\n  🗓 Воскресенье 14:00 (МСК)\n  🔗 https://…",
  "text_vk": "📅 …\n• Курс — Препод\n  🗓 Воскресенье 14:00 (МСК)\n  https://…",
  "image_url": "https://<домен>/storage/monthly/schedule-2026-07.jpg",
  "courses": [ { "title": "…", "teacher": "…", "schedule": "…", "url": "…" } ]
}
```

`image_url` может быть `null`, если на сервере нет imagick/ghostscript — тогда постим
только текст (для Telegram это `sendMessage` вместо `sendPhoto`).

## Схема воркфлоу

```
Webhook ─┬─► Telegram: sendPhoto (канал)
         └─► VK: getWallUploadServer → скачать картинку → upload photo
                 → saveWallPhoto → wall.post
```

## Настройка после импорта

1. **Импорт** `monthly-schedule-post.workflow.json` (Workflows → Import from File).
2. **Telegram**: в ноде `Telegram: sendPhoto` заменить `REPLACE_TG_BOT_TOKEN` (токен бота,
   который **админ канала**) и `@REPLACE_TG_CHANNEL` (юзернейм/ID канала).
3. **VK**: во всех `VK: *` нодах заменить `REPLACE_VK_GROUP_ID` (числовой ID сообщества)
   и `REPLACE_VK_GROUP_TOKEN` (community access token с правами `photos`+`wall`).
   В `wall.post` `owner_id` = `-<GROUP_ID>` (минус уже стоит в шаблоне).
4. **Активировать**, скопировать *Production* URL вебхука.
5. **Laravel** `.env`:
   ```
   N8N_MONTHLY_SCHEDULE_WEBHOOK=https://<ваш-n8n>/webhook/monthly-schedule-post
   N8N_MONTHLY_SCHEDULE_SECRET=<любой_секрет>
   ```
   Затем `php artisan config:cache`. Секрет уходит в заголовке `X-Webhook-Secret` —
   на ноде Webhook включите *Header Auth* с тем же значением.

## Проверка

Кнопка «📣 Опубликовать в соцсети» в разделе «Расписание» (ручной прогон) или
`php artisan schedule:post-monthly --dry` (показать текст без отправки).

> ⚠️ Цепочка загрузки фото в ВК (multipart `formBinaryData`) чувствительна к версии нод
> n8n — после импорта прогоните воркфлоу один раз и при необходимости поправьте поле
> бинарного аплоада. Telegram-ветка работает «из коробки».

---

# n8n: нарезка клипов лекций → VK Video/Clips (H1452 Wave 4)

Воркфлоу [`lecture-clip-extract.workflow.json`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/n8n/lecture-clip-extract.workflow.json)
принимает из Laravel job `DispatchLectureClipExtractionJob` спаны, уже вычисленные
`ClipSpanPlanner` из **существующих** AI-таймкодов (без пересчёта границ), режет
ffmpeg, грузит фрагменты в VK Video и callback'ом пишет `LectureClip` в Laravel.

**Где выполняется тяжёлое.** Нарезка и заливка идут одной SSH-командой на том же
хосте, где живёт `yt-dlp` ZOOM-сценария (кред `n8n`, каталог `/data/clips`):
исходник качается **один раз на лекцию**, каждый спан режется `ffmpeg -c copy`
(без перекодирования), заливается в VK прямо с хоста и тут же удаляется. Через
n8n гигабайты не гоняются вовсе — наружу выходит только JSON с id ролика.

## Payload Laravel → n8n

```json
{
  "action": "clip_lecture",
  "lesson_id": 42,
  "lesson_title": "…",
  "video_url": "…",
  "youtube_url": "…",
  "rutube_url": "…",
  "spans": [
    { "start_seconds": 0, "end_seconds": 90, "title": "Тема — фрагмент 1: …" }
  ],
  "callback_url": "https://<домен>/api/webhooks/lecture-clip-callback"
}
```

Заголовок `X-Webhook-Secret` = `N8N_CLIP_EXTRACT_SECRET`.

## Callback n8n → Laravel

`POST {callback_url}` с `X-Webhook-Secret: N8N_CLIP_CALLBACK_SECRET` и телом:

```json
{
  "lesson_id": 42,
  "clips": [
    {
      "start_seconds": 0,
      "end_seconds": 90,
      "title": "…",
      "vk_video_id": "456239017",
      "vk_owner_id": "-12345"
    }
  ]
}
```

Флаг `CLIP_MARKETING_ENABLED=false` → Laravel callback 404; job early-return.
**Никогда не коммитьте живые VK-токены** — поэтому токен и id сообщества берутся
из env n8n, а не лежат в JSON.

## Настройка после импорта

1. Import `lecture-clip-extract.workflow.json`.
2. В нодах `Нарезать и залить в VK` и `Убрать исходник` проверить, что подставился
   SSH-кред `n8n` (тот же, что у «Скачиваем аудио» в ZOOM-сценарии). На хосте нужны
   `yt-dlp`, `ffmpeg`, `curl`, `python3`.
3. Env n8n:
   - `VK_ACCESS_TOKEN` — community-токен с правом **`video`** (одних `photos`+`wall`,
     как у кросспостинга, не хватит: заливка идёт через `video.save`);
   - `VK_VIDEO_GROUP_ID` — числовой id сообщества, куда льём клипы;
   - `N8N_CLIP_CALLBACK_SECRET` = тот же, что в Laravel `.env`.

   Нода «Собрать команду» падает с внятным сообщением, если токен или id не заданы, —
   молча залить «в никуда» она не может.
4. Activate, copy Production webhook URL.
5. Laravel `.env`:
   ```
   CLIP_MARKETING_ENABLED=false
   N8N_CLIP_EXTRACT_WEBHOOK=https://<n8n>/webhook/lecture-clip-extract
   N8N_CLIP_EXTRACT_SECRET=<secret>
   N8N_CLIP_CALLBACK_SECRET=<secret>
   ```
   `php artisan config:cache`. Flip `CLIP_MARKETING_ENABLED=true` only after a
   staging dry-run (see `DEPLOY_QUEUE.md` №47).

> **Не переключайте ноду Webhook в «When Last Node Finishes».** Она стоит в
> режиме **«Immediately» (`onReceived`)** намеренно: нарезка ffmpeg + аплоад в VK
> идут асинхронно (минуты), а результат возвращается ОТДЕЛЬНЫМ callback'ом
> (n8n → Laravel, см. выше). `DispatchLectureClipExtractionJob` зовёт вебхук с
> `Http::timeout(15)` и `tries=3` — в режиме «last node» запрос всегда отваливался
> бы по таймауту, джоба ретраилась трижды, и n8n резал бы и грузил в ВК по три
> копии каждого клипа.

---

# n8n: VK content calendar auto-pilot (H1568, Wave 5)

Воркфлоу [`vk-calendar-post.workflow.json`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/n8n/vk-calendar-post.workflow.json)
принимает один готовый пост из Laravel и публикует его на стене сообщества
ВКонтакте (текст, без картинки — VK-only per D10, TG-зеркала здесь нет в
отличие от social_post W2).

## Кто инициирует

Laravel — команда `content:publish-due` (планировщик: **ежечасно**, см.
`app/Console/Kernel.php`), реализация в `App\Services\Content\CalendarPublishService`.
Каждый час выбирает все `content_calendar_slots` со статусом `scheduled` и
`publish_at <= now()`, шлёт POST на вебхук ПО ОДНОМУ на слот. Прод-инертно,
пока `features.content_calendar_autopilot` (`CONTENT_CALENDAR_AUTOPILOT`) OFF
(дефолт) — команда делает `no-op`, HTTP не уходит.

## Payload, который шлёт Laravel

```json
{
  "action": "vk_calendar_post",
  "calendar_slot_id": 42,
  "slot_type": "evergreen",
  "text_vk": "…",
  "source_kind": "vk_ors_post",
  "source_ref": "wall-88831040_12345",
  "publish_at": "2026-08-01T09:00:00+00:00"
}
```

Заголовок `X-Webhook-Secret` = `N8N_CALENDAR_POST_SECRET`.

## Успех / отказ

`wall.post` успешен → Laravel помечает слот `published` и любой связанный
`ContentCandidate` тоже `published`. Не-2xx ответ → слот **остаётся**
`scheduled` и будет повторён на следующем часовом тике — тихого дропа нет.

## Настройка после импорта

1. **Импорт** `vk-calendar-post.workflow.json` (Workflows → Import from File).
2. **VK**: в ноде `VK: wall.post` заменить `REPLACE_VK_GROUP_ID` (числовой ID
   сообщества) и `REPLACE_VK_GROUP_TOKEN` (community access token с правом
   `wall`). `owner_id` = `-<GROUP_ID>` (минус уже стоит в шаблоне).
3. **Активировать**, скопировать *Production* URL вебхука.
4. **Laravel** `.env`:
   ```
   N8N_CALENDAR_POST_WEBHOOK=https://<ваш-n8n>/webhook/vk-calendar-post
   N8N_CALENDAR_POST_SECRET=<любой_секрет>
   ```
   `php artisan config:cache`. Секрет — в заголовке `X-Webhook-Secret`, на
   ноде Webhook включите *Header Auth* с тем же значением.
5. Только после смоука на staging: `CONTENT_CALENDAR_AUTOPILOT=true` →
   `php artisan config:clear`. Ручной прогон одного тика:
   `php artisan content:publish-due`.

---

# Клипы лекций (эксплуатация)

Пошаговая **установка** n8n/ffmpeg/VK для Ивана: [issue #666](https://github.com/gasyoun/Systema-Sanscriticum/issues/666).

**Как пользоваться после включения** (админ + оператор): 
[docs/MANUAL_N8N_LECTURE_CLIPS_OPERATOR_RU.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/MANUAL_N8N_LECTURE_CLIPS_OPERATOR_RU.md).

JSON воркфлоу: lecture-clip-extract.workflow.json (импорт → Active → секреты в Laravel N8N_CLIP_*).

