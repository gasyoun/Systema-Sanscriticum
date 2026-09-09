_Created: 09-09-2026 · Last updated: 09-09-2026_

# H4440: кнопка «Отправить как есть» — под каждым черновиком, админ-детур отменён (OxAlpha z-ai/glm-5.3-flash, 09-09-2026)

Рулинг MG 09-09-2026 («не надо каждый раз лазить в админку. Цены почти везде универсальные, их не так много, чтобы человеку перепроверять каждое высказывание» + вопрос-батарея «Кнопка для всех трёх»): деньги, доступ и сертификат больше не лишают куратора кнопки. Явное ослабление рулинга A1 (H3999) — зафиксировано в теле [H4440](https://github.com/gasyoun/Uprava/blob/main/handoffs/H4440-OxAlpha_Systema-Sanscriticum_draft-only-tap-unblock-mg-0909_09.09.26.md).

- **Tap-дорожка** ([SupportHintSendButton](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Services/Support/SupportHintSendButton.php)): блок отказа `isDraftOnly()` снят; отправка draft_only-черновика по кнопке идёт тем же `deliver()` — клейм `TelegramSendGuard`, привязанный студент, возраст ≤7 дней, аудит `hint_send_tapped` с `tapped_by_telegram_id` — всё без изменений.
- **Композер хинта** ([SupportDmAutoReply](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Services/Support/SupportDmAutoReply.php)): кнопка рендерится под каждым черновиком; строка «Черновик требует проверки — кнопки под ним нет» убрана. Маркер `draft_only` в `facts`/`send_policy` остаётся (резолвер метит, в аудите видно; «резолвер МЕТИТ, отправитель РЕШАЕТ» теперь буквально).
- **Админ-очередь** («Пользователи» → «Очередь черновиков») остаётся местом правки текста перед отправкой — по желанию, не обязательный маршрут.
- Тесты: инвертированы два A1-теста — [SupportHintSendButtonTest](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/tests/Feature/Support/SupportHintSendButtonTest.php) `draft_only … sends_on_the_telegram_tap` (уходит студенту, статус accepted, колбэк «Отправлено») и [SupportFactDraftOnlyFenceTest](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/tests/Feature/Support/SupportFactDraftOnlyFenceTest.php) `hint_carries_the_send_button` (клавиатура присутствует). Верификатор: `php artisan test tests/Feature/Support` — 444 passed, 1 documented skip. Pint clean.
- Не тронуто: авто-отправка без человека по-прежнему невозможна (R3-дорожки H4404 — отдельный лейн, флаги OFF); расхождение суммы — по-прежнему ни ответа, ни черновика (follow-up финансовому лиду).

_Dr. Mārcis Gasūns_
