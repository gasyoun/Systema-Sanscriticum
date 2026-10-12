# Решения грилла MG: видео из учебных чатов — авто-анонс в чаты курса, черновик MG, сторис

_Created: 12-10-2026 · Last updated: 12-10-2026_

Грилл `/grillme` 12-10-2026 по заказу MG: «Как сделать так, чтобы подобные видео (пример — [сообщение 1067](https://t.me/c/2917231272/1067)) через скилл анонс сразу попадали в телеграм-чаты из учебных чатов?». Домашняя работа Phase 0 — живые пробы прода `.92` (12-10-2026) и карта эстейта; ответы MG зафиксированы интерактивом в тот же проход. Исполнение — хендофф [H6546](https://github.com/gasyoun/Uprava/blob/main/handoffs/H6546-OxAlpha_Systema-Sanscriticum_tg-video-anons-lane_12.10.26.md) (OxAlpha, effort hard).

## Что показала домашняя работа (live-пробы, 12-10-2026)

- **Весь intake уже работает мгновенно.** Вебхук `@zapisi_ORSbot` (`/api/webhooks/telegram-zapisi` → [TelegramZapisiWebhookController](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Http/Controllers/Webhooks/TelegramZapisiWebhookController.php) → `ProcessTelegramZapisiUpdate` → HarvestStoreWriter) собрал видео-сообщение за **1 секунду**: сообщение 1067 чата `-1002917231272` «Понедельник 20:00 (53) | Чат учеников» (курс Эдгара Лейтана) — `has_media=true, media_type=video`, mp4 8,5 МБ от Ивана Молочко (`@ivanmolochko`, техсопровождение) 19:41 МСК; файл уже скачан в `storage/app/telegram-harvest/raw/zapisi_media/-1002917231272/2026-10-12/1067_video.mp4`. Не хватает только **реакции** — видео-сообщения обрабатываются как контент корпуса, но ничего не публикуют.
- **Сентябрьское «мёртвый чат» устарело.** Строка 15-09 в [SERVER_SOFT_ALERT_PLAYBOOK.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/SERVER_SOFT_ALERT_PLAYBOOK.md) (H4846) числила `-1002917231272` мёртвым (`400 chat not found`). Live-проба 12-10: `getChat` токеном Почтальона (MarketingSetting::zapisi_bot_token) отвечает `ok:true` — бот в чате, может и читать, и постить. `.env`-боты прода (ops, student/cabinet, business) этот чат не видят — отправитель копий только Почтальон. MTProto-сессия видит чат тоже (ростер `-1002917231272.json` обновлён 17:03 UTC, за минуты до пробы).
- **Видео-сторис уже поддержаны подсистемой**: [StoryPublisher::sendVideoStory](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Services/Stories/StoryPublisher.php) (`documentAttributeVideo`, peer «me», период 24 ч) + [TelegramStoryAdapter](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Services/Anons/Adapters/TelegramStoryAdapter.php) — отдельная MadelineProto-разработка не нужна, только живая проверка на тест-аккаунте.
- **Карта курс→чаты существует неявно**: у курса Лейтана 6 учебных чатов в карточках [Uprava/tools/welcome_post/cards/](https://github.com/gasyoun/Uprava/blob/main/tools/welcome_post/cards/); машиночитаемая канонизация — задача лейна H6546 (вывести из данных Systema — учебные группы с `telegram_chat_id`, источник `telegram-harvest:roster-groups` — и сверить с карточками).

## Решения MG (4 карты + дополнение)

| # | Вопрос | Решение MG |
|---|---|---|
| 1 | Куда видео дублируется сразу | **Чаты того же курса** (авто, роутинг по карте курс→чаты) **+ черновик MG со словом** для всего остального; дополнение MG: **пост в сторис — после заголовка MG** |
| 2 | Что постить | **Копия видео + подпись** (`copyMessage` без плашки Forwarded from); `/ga/`-ключи не нужны — не рекрутинг (H6329: домашки/записи вне anons-кампаний) |
| 3 | Кто разрешает публикацию | **Постоянная авторизация** — авто-пост при авторе из белого списка (MG, Иван, преподаватель курса); это scoped-перекрытие устава скилла anons «никогда не постит сам» — только для этого лейна и только учебные чаты курса |
| 4 | Фильтр «подобных» видео | **Все `video` от белого списка авторов** во всех учебных чатах; подпись не обязательна (пример MG — 1067 — без подписи) |

## Границы

- Публичный канал «Оповещения санскритян» **в скоуп не входит** (MG не выбрал): публичная публикация — по-прежнему явное слово и полный anons-конверт с `/ga/` (link gate H6087) и publish-safety-check (видео из чата учеников несёт персональный контекст).
- Сторис: фиче-флаг по умолчанию off; публикация стартует поставкой заголовка MG в ответ на черновик и идёт по правилам сторис-раздела скилла anons (plaque, короткий `/ga/`-ключ, верификация `getStoriesByID`).

## Исполнение

- Лейн целиком — хендофф [H6546](https://github.com/gasyoun/Uprava/blob/main/handoffs/H6546-OxAlpha_Systema-Sanscriticum_tg-video-anons-lane_12.10.26.md) (OxAlpha, effort hard, contract code): реакция в `ProcessTelegramZapisiUpdate`, белый список авторов, каноническая карта курс→чаты, копии `copyMessage`, черновик MG через ops-канал, сторис по заголовку поверх существующего `sendVideoStory`.
- Приёмка: CI-тесты + живая проба на проде после деплоя `.92` (тестовое видео от члена белого списка → копии в чатах курса за минуту + черновик MG).

_к.ф.н. М.Ю. Гасунс_
