# H4663: аудит внешнего периметра 14-09 — remediation F1/F3/F4/F6/F7/F8/F10/F11 + durable audit doc (OxAlpha z-ai/glm-5.3-flash, 14-09-2026)

- **Durable doc:** [docs/AUDIT_EXTERNAL_PERIMETER_14-09-2026.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/AUDIT_EXTERNAL_PERIMETER_14-09-2026.md) — 15 находок с live-пробами (порты/nftables/fail2ban/заголовки/CORS); оси: порты, аутентификация, сессии, IDOR, роли, CORS.
- **F3 виджеты:** `CourseEarningsChart`+`StudentStatsOverview` → `RoleGate::finance()`, `DebtorsTotalWidget` → `RoleGate::any(ADMIN, MANAGER)` (гейт страницы «Должники») — раньше три виджета дашборда были единственными без `canView()`: куратор видел школьную выручку/LTV/сумму долгов. Тест `DashboardWidgetRoleGateTest` (2/2, 15 assertions).
- **F4 throttle:** `throttle:30,1` на `/api/sync-lessons`, `/api/lessons/from-zoom`, `/api/lessons/{lesson}/transcript` (перебор `X-Secret-Key` больше не на скорости сети); `throttle:60,1` на `/api/webhooks/tochka`, `/webhooks/paypal-subscriptions`, `/webhooks/zoom` (RS256/JWT-проверка без ограничений = дешёвый DoS).
- **F10 API-401:** неавторизованный запрос на `api/*` без `Accept: application/json` отвечает 401 JSON вместо 302 на HTML `/login` (`Authenticate::redirectTo`).
- **F8 logout-all:** `POST /api/v1/auth/logout-all` — отзыв ВСЕХ мобильных Sanctum-токенов (украденный токен больше не живёт до 90 дней после logout на одном устройстве).
- **F11 /horizon:** гость → 404 (путь не раскрывается), залогиненный не-админ → 403 (email-канон H3312 сохранён).
- **F7 CSP:** глобальный `Content-Security-Policy-Report-Only` в web-группе (AppServiceProvider) — наблюдение без блокировки; ужесточение после разбора репортов.
- **F1 LOG_LEVEL:** `.env.example` дефолт `debug` → `warning` (прод-правка `.env` — @DO в Uprava GTD, отдельным окном с `config:cache`).
- **F6 nginx-фолбэк:** security-заголовки с прода перенесены в `ops/migrate/templates/nginx-samskrte.conf` (пересборка бокса без снапшота больше не теряет эшелон; HSTS не добавлен — в фолбэке нет 443).
- **F9 User fillable:** осознанно НЕ изменён (162 тест-файла + seeder опираются на factory-create с role); компенсирующий контроль описан в audit doc §F9, маркер-комментарий в модели.
- Верификация: `php -l` на всех изменённых файлах; Pint clean; целевые наборы MobileApiTest 5/5, TochkaWebhook+WebhookSecurityAudit+CourseSplitGroups 38/38, AccountantAccessTest 6/6, DashboardWidgetRoleGateTest 2/2.
- Остатки (Uprava GTD, тот же проход): F2 kosha-vhost @DECIDE; F5 /force-download IDOR-редизайн @DO; прод `LOG_LEVEL=warning` @DO; CSP-репорт-разбор @DO.
_Dr. Mārcis Gasūns_