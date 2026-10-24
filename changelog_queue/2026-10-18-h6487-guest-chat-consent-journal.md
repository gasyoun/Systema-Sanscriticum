# Гостевое согласие чат-виджета лежит в журнале consents, а не в localStorage (H6487)

F1 аудита 152-ФЗ ([docs/AUDIT_152FZ_SAMSKRTE_2026-10-11.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/AUDIT_152FZ_SAMSKRTE_2026-10-11.md)):
согласие гостя в `support-chat-widget` запоминалось только в localStorage —
очистка браузера стирала единственное доказательство получения согласия
(152-ФЗ ст. 9 ч. 3: доказывать получение согласия обязан оператор).

Серверная запись в `consents` появилась ещё в волне fz152 (PR #3124), но
поведение браузера ей противоречило: ключ `scw_pd_consent_v1` ставился ДО
подтверждения записи, поэтому гость с «согласием» в браузере больше никогда не
присылал галочку — и строки в журнале не появлялось.

Что изменено (только контур согласия):

- `PublicChatController::store()` возвращает `consent_recorded` — только гостю,
  приславшему галочку; ответы остальных клиентов прежние построчно;
- `ConsentRecorder::fromForm()` возвращает строку согласия на ПДн (или null),
  поэтому сбой журнала больше не выглядит как успех;
- виджет прячет галочку и ставит localStorage только при
  `consent_recorded === true`, а ключ поднят до `scw_pd_consent_v2`: браузерная
  память v1 (согласие, не попавшее в журнал) переспрашивается один раз.

Журнал append-only: `updated_at` в схеме нет, повторная галочка ДОБАВЛЯЕТ
строку (первая не переписывается); имя, телефон и текст обращения в строку не
попадают, `email` — идентификатор субъекта, nullable.

Миграций нет: `consents` уже несёт `doc_version`, `ip_address`, `user_agent` —
новых колонок не понадобилось, существующие строки не затрагиваются.

Пин: [`tests/Feature/Consent/GuestChatConsentJournalTest.php`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/tests/Feature/Consent/GuestChatConsentJournalTest.php)
(9 тестов). Смежные наборы: 73/73 (PublicChatEndpoint, Fz152Consent,
SupportWebchatAutoReply, SupportVisitorGeo, ChatMessageBroadcast, HelpdeskGuestChat),
папка Consent 41/41, `tests/Feature/Support` 771/771 (1 skipped).
