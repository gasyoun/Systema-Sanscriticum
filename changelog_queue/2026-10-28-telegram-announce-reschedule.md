# Telegram: разовый перенос занятия обычным сообщением (28-10-2026)

- В `ProcessTelegramZapisiUpdate` добавлен детерминированный разбор явных сообщений о разовом переносе: дата/день недели, необязательное новое время, исходная дата по времени Telegram-сообщения. Без нового времени сохраняются прежние начало и длительность; серия не сдвигается.
- Полностью разобранное сообщение от связанного преподавателя/staff применяется сразу через `ScheduleMover`; неоднозначное получает просьбу подтвердить формат и ничего не меняет. Флаг и allowlist групп fail-closed; старая ветка отмены сохраняется и не дублирует перенос.
- Добавлены `telegram_schedule_reschedules`: уникальная идемпотентность по чату+сообщению, старые/новые даты, actor, статус и час Undo. Квитанция уходит ответом на исходное сообщение; Undo только автору/staff, не затирает более позднюю правку.
- `ScheduleMover::reschedule` теперь обновляет даты внутри title/description, сохраняя номер занятия и сбрасывая lifecycle напоминаний.
- Решения MG: [decision record](../reports/GRILL_TELEGRAM_CLASS_RESCHEDULE_2026-10-27.md). Пилот и replay message 93: [runbook](../docs/RUNBOOK_TELEGRAM_ANNOUNCE_RESCHEDULE_2026-10-28.md).
- Проверка: `AnnounceRescheduleTest` — 18 тестов / 54 assertions; включает golden message 93, ACL, оба гейта, ambiguity, same-day past, collision, replay, cancellation isolation, Undo/expiry/later-edit.
