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

## Payload, который шлёт Laravel

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
2. **Учётка Google.** В обеих нодах Google Sheets («Очистить лист», «Записать строки»)
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
случайный путь, но для надёжности стоит включить *Header Auth* на ноде Webhook и слать тот же
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
и шлёт POST в вебхук n8n. Организация в тексте — **ОРС**, не «Академия».

## Payload, который шлёт Laravel

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
