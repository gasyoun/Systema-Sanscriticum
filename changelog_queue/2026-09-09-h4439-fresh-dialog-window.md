_Created: 09-09-2026 · Last updated: 09-09-2026_

# H4439: окно свежести — messages.getDialogs вместо getDialogIds; первый вопрос нового студента больше не теряется (OxAlpha z-ai/glm-5.3-flash, 09-09-2026)

Доктрина MG 09-09-2026 «doubt everything, widen and deepen» на фиксе H4416 вскрыла корень глубже: аутедж 31-08…08-09 был не только «порядок, далёкий от свежести», а конкретный артефакт пагинации.

- **Механика беспорядка** (vendor/danog/madelineproto/src/Wrappers/DialogHandler.php, getFullDialogsInternal): страницы `messages.getDialogs` идут date-desc, но вставляются `foreach (array_reverse(...))` — каждая страница задом наперёд. Итог: `getDialogIds()[0]` ≈ 100-й по свежести диалог, `slice(0, 20)` опрашивал ранги 100..81; свежий DM сидел на позиции ~99 — вне окна навсегда. Живой замер на проде (08-09) это подтверждал (топ-30 — июльские чаты), сорс-чтение 09-09 объяснило почему.
- **Двойная слепота новичков:** первый вопрос бренд-нового студента не попадал ни в минутный конвейер (его нет в БД — H4416-союз слеп), ни в ночной catch-up (он тоже по БД), ни в MP-окно (артефакт пагинации) — самое ценное сообщение воронки терялось молча.
- **Фикс** ([TelegramSupportSyncService::freshDialogPeers](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Services/TelegramSupport/TelegramSupportSyncService.php)): третий источник союза — один RPC `messages.getDialogs(limit: dialog_limit)` (ответ по спецификации date-desc), peer'ы нормализуются в chat-id-форму (+user_id / -100{channel_id} / -{chat_id}) для корректного дедупа с allowlist. Побочно: каждая минута теперь платит 1 RPC вместо полной пагинации 3199 диалогов (~32 RPC) в старом getDialogIds.
- **Fake** ([FakeMadelineProtoClient](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/tests/Feature/Support/Doubles/FakeMadelineProtoClient.php)): `messages->getDialogs` + статические `$dialogs` (фикстура date-desc) и `$getDialogsCalls`; дефолт строится из ключей `$histories` (совместимость старых тестов); `getDialogIds` из конвейера убран.
- Тесты: [TelegramSupportPeerWindowTest](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/tests/Feature/Support/TelegramSupportPeerWindowTest.php) 6/6 — новый кейс «бренд-новый чат = ранг 1 окна, опрашивается первым же заходом, сообщение не теряется» + пин на messages.getDialogs; tearDown сбрасывает статик-фикстуры (кросс-файловое загрязнение найдено и закрыто). Верификатор: `php artisan test tests/Feature/Support tests/Feature/MadelineSyncGuardsTest.php` — 454 passed, 2 documented skip. Pint clean.
- Деплой: ожидается живая проверка `peers_polled` в логе первого захода.

_Dr. Mārcis Gasūns_
