# H6915 — видео-лейн канальных постов: kind=video + sendVideo

Канальный anons-издатель научился нести видео: кадр манифеста `kind=video`
с `media_path` (ABSOLUTE, читаемый, ≤50 МБ — fail-closed валидация до
публикации) уходит в Telegram `sendVideo` с caption + parse_mode=HTML,
без выжигания CTA-плашки (ссылка живёт в подписи; CTA-текст обязателен
только для фото). Превышение лимита Bot API 50 МБ и отсутствующий файл —
понятный отказ, слот НЕ закрывается. Видео не ходит в фото-альбомы
(sendMediaGroup остаётся photo-only). Очередь story_posts: строка шапки
`| Медиа | /abs/video.mp4 |` делает пакет видео (`stories:import-queue`),
`stories:publish-due` издаёт kind=video с квитанцией telegram_message_id +
t.me permalink в журнале; повторный прогон того же source_key/
publication_key создаёт ноль новых постов. Попутно закрыты два латентных
бага telegram_post-лейна: `MarketingSetting::magnetBotToken()` не
существовал (фатал при первом живом прогоне) и сервис передавал
`artifact_path`, тогда как адаптеры читают `asset_path` — photo-лейн тоже
работал только на фейках. Тесты: `AnonsVideoLaneTest` (7) +
`StoriesPublishDueTest` +4 + `StoriesImportQueueTest` +1, Pint чист.
Живой приёмочный прогон 22-10-2026: пост
[t.me/samskrte/665](https://t.me/samskrte/665) из очереди (receipt в
журнале), re-run без дубля, 51 МБ отказ до загрузки. PR #3432 (f8e32afd).
