_Created: 17-11-2026 · Last updated: 17-11-2026_

# Чек-лист «Изменение money-webhook: подпись и идемпотентность» (H8396)

Advisory-чеклист для ЛЮБОГО изменения вебхук-поверхности (роут, контроллер, middleware, секрет, повторный роутинг n8n). R13: money-критичные провайдеры — глубокая проверка, нотификационные транспорты — лёгкая общая секция. Составлено 17-11-2026, handoff [H8396](https://github.com/gasyoun/Uprava/blob/main/handoffs/H8396-OxAlpha_Systema-Sanscriticum_checklist-gap-census-top5_17.11.26.md); исполнитель GLM 5.3 Flash (`zai-coding-plan/GLM-5.3-Flash`, OpenCode).

## Карта поверхности (origin/main 17-11-2026)

| Провайдер | Роут | Подпись | Идемпотентность |
|---|---|---|---|
| **Tochka (деньги)** | `POST /webhooks/tochka` | `verify.tochka`, fail-closed | хеш JWT каждого ределивери |
| **PayPal (деньги)** | `POST /webhooks/paypal-subscriptions` | `PaypalWebhookSignatureVerifier::verify()` | `PaymentWebhookEvent.event_hash` |
| **Exam scores (деньги-класс)** | `POST /api/webhooks/exam-scores` | `verify.n8n.examscores` | `PaymentWebhookEvent.event_hash` |
| Telegram | `POST /telegram/webhook` | нет | `TelegramSendGuard::claimUpdate(update_id)` |
| VK | `POST /vk-webhook` | confirmation code (только setup) | нет явного дедупа ⚠️ |
| MAX | `POST /webhooks/max-magnet/{secret}` | `verify.max.magnet` | очередь `ProcessMaxMagnetUpdate` + n8n |
| Zoom | `POST /webhooks/zoom` | `x-zm-signature` HMAC v0 | `AttendanceRecorder` (`schedule_id`+`participant_uuid`) |
| VK Magnet | `POST /webhooks/vk-magnet` | confirmation code | нет явного дедупа ⚠️ |
| Telegram Magnet | `POST /webhooks/telegram-magnet` | `verify.tg.magnet` | `TelegramSendGuard::claimUpdate` |
| Lecture clip | `POST /webhooks/lecture-clip-callback` | нет (гейт `features.clip_marketing`) | `firstOrCreate` (lesson_id+start+end) |

## Глубокая проверка — money-критичные (Tochka, PayPal, exam-scores)

1. [ ] Подпись осталась fail-closed: невалидная подпись → отказ до любого изменения состояния (4xx, лог, ноль записей).
2. [ ] Идемпотентность сохранена: у повторной доставки тот же `event_hash`/JWT-хеш не создаёт вторую запись/второе списание.
3. [ ] Replay-тест: один и тот же payload отправлен дважды → эффект ровно один (проверить на стенде, не на проде).
4. [ ] Смена секрета: новый секрет задокументирован в ротации, старый отозван; секрет не попал в коммит/лог.
5. [ ] Обработчик идемпотентен к ПОРЯДКУ событий (повтор оплат после сбоя доставки не ломает статус `Payment::PAID_STATUSES`).
6. [ ] Алертинг на failures вебхука жив (ошибка подписи/обработки видна не только в логах).
7. [ ] Изменение состояния денег (статусы оплат, выплаты) тестируется на scratch-стенде по паттерну [CHECKLIST_TEACHER_PAYOUT_ROUTE_WALK_H5635](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/CHECKLIST_TEACHER_PAYOUT_ROUTE_WALK_H5635_02-10-2026.md) — прод не трогается до ревью.

## Лёгкая секция — нотификационные транспорты (Telegram, VK, MAX, Zoom, магниты, lecture-clip)

- [ ] Дедуп не сломан: `claimUpdate` по-прежнему атомарно забирает `update_id`; Zoom-ключ `schedule_id`+`participant_uuid` не разряжен.
- [ ] Секрет-в-роуте (`max-magnet/{secret}`) ротируется вместе с изменением; старый URL отозван у источника.
- [ ] Feature-гейты (`features.clip_marketing`, `telegram_zapisi_bot`) проверены на ожидаемое состояние до включения поверхности.
- [ ] Лёгкий replay-тест: дубль события не даёт дубль сообщения пользователю.

## Известные остатки (зафиксированы в GTD, H8396)

- ⚠️ VK и VK Magnet: нет явного per-event дедупа в контроллерах — повторная доставка даёт повторную обработку. Остаток заведён в [GTD_NEXT_ACTIONS.md](https://github.com/gasyoun/Uprava/blob/main/GTD_NEXT_ACTIONS.md) как engineering-строка; до починки при изменениях этих роутов пункт «replay-тест» обязателен.

_к.ф.н. М.Ю. Гасунс_
