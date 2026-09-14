_Created: 14-09-2026 · Last updated: 14-09-2026_

# AUDIT_EXTERNAL_PERIMETER_14-09-2026 — внешний периметр samskrte.ru

Цель: `Systema-Sanscriticum` (Laravel 10.50, прод `samskrte.ru`, хост `samskrtam150` / `193.232.229.92`), рабочее дерево на коммите `52dd4c29` (13-09-2026) + live-пробы того же дня.
Метод: чтение кода (routes/config/app/ops) + живые пробы: HTTP `samskrte.ru`, SSH `root@193.232.229.92` (`ss -tlnp`, `nft list ruleset`, `sshd -T`, `fail2ban-client`, `nginx -T`, несекретные ключи `.env`). Секреты не печатались; stenogrammy не открывался; изменения на проде в момент аудита не вносились.
Связь с прошлым аудитом: [`AUDIT_PLAN.md`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/AUDIT_PLAN.md) (02-07-2026) — его критичные находки к 14-09 закрыты (см. §«Исправлено с 02-07»).
Исполнитель: OxAlpha (`opencode/z-ai/glm-5.3-flash`), handoff [H4663](https://github.com/gasyoun/Uprava/blob/main/handoffs/H4663-OxAlpha_Systema-Sanscriticum_external-perimeter-remediation_14.09.26.md).

## Итог (по шести запрошенным осям)

1. **Порты/сервисы** — ок: наружу только 22/80/443 (TCP) + UDP 41641/49483 (Tailscale), nftables default-deny; MariaDB/Redis/Ollama/Reverb/postfix только на loopback. Одно «лишнее» — второй публичный vhost kosha (F2).
2. **Аутентификация** — ок: IP-throttle + per-credential lockout + timing-equalize, nginx limit_req на admin/login, SSH key-only + fail2ban (915 банов).
3. **Сессии/токены** — базово правильно (regenerate, secure/httponly/lax); окна длинные: Sanctum 90 дней, нет «отозвать все устройства» (F8, исправлено в этой волне).
4. **IDOR/BOLA** — в выборке гейты на месте; остаток `/force-download` (F5, @DO).
5. **Роли/права** — 118/118 Filament-ресурсов и все Pages под гейтами; пробел был в 3 дашборд-виджетах (F3, исправлено).
6. **CORS** — deny-by-default (пустой allowlist, credentials=false), подтверждено live; отдельный nginx-map kosha-релея тоже жёсткий.

## Находки (от самых опасных)

### F1 — [ИСПРАВЛЕНО] Прод пишет логи на уровне debug
- Live `.env`: `LOG_LEVEL=debug`, `LOG_CHANNEL=daily`; дефолт репо `.env.example:11`.
- Риск: PII (email, IP, платёжные метаданные) копится в логах, которые бэкапятся off-site. Аудит перезаписи пароля пишет email (`User::logPasswordRewrite`).
- Исправление (код): `.env.example` дефолт `warning`. Исправление (прод): `LOG_LEVEL=warning` + `config:cache` — **@DO, см. GTD**.

### F2 — [@DECIDE] Второй публичный сайт на Tier-0 боксе (kosha + GitHub device-flow релей)
- Серверный nginx: vhost `kosha.193.232.229.92.sslip.io` (`root /var/www/html`, proxy `127.0.0.1:8001`, сниппет `gh-device-relay.conf`). В репо конфига нет — прод-локальный.
- Риск: лишняя публичная поверхность на боксе с деньгами и ПДн; имя в чужой DNS-зоне sslip.io; релей — исходящий хоп на `github.com` (ограничен двумя эндпоинтами, CORS-map жёсткий: `default ""`, allowlist `gasyoun.github.io`+localhost).
- Требует решения человека: нужен ли kosha на этом боксе/имени; если да — внести в perimeter-инвентарь.

### F3 — [ИСПРАВЛЕНО] Дашборд-виджеты без ролевого гейта
- [`CourseEarningsChart.php`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Filament/Widgets/CourseEarningsChart.php), [`StudentStatsOverview.php`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Filament/Widgets/StudentStatsOverview.php), [`DebtorsTotalWidget.php`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Filament/Widgets/DebtorsTotalWidget.php) — единственные из дашборд-набора без `canView()`; менеджер видел школьную выручку/LTV/сумму долгов, тогда как его страница продаж сужена до своих сделок (`RoleGate::managerSalesReport`).
- Исправление: `CourseEarningsChart`+`StudentStatsOverview` → `RoleGate::finance()` (админ+бухгалтер), `DebtorsTotalWidget` → `RoleGate::any(ADMIN, MANAGER)` — тот же гейт, что у страницы «Должники» (рулинг MG 07-09-2026 сохранён).

### F4 — [ИСПРАВЛЕНО] Секретные и вебхук-эндпоинты API без rate limit
- `routes/api.php`: `/sync-lessons`, `/lessons/from-zoom`, `/lessons/{lesson}/transcript` (секрет `X-Secret-Key`, fail-closed в `LessonController::guard`) — перебор секрета шёл на скорости сети; `/webhooks/tochka`, `/webhooks/paypal-subscriptions`, `/webhooks/zoom` — RS256/JWT-проверка на каждый запрос без ограничения.
- Исправление: `throttle:30,1` на lesson-sync-роуты, `throttle:60,1` на вебхуки. Внутригрупповой `throttle:api` (60/мин) остаётся как фон.

### F5 — [@DO] `/force-download/{file}`: staff-scoped IDOR-остаток
- `routes/web.php:937-955`: гейт `is_admin || is_lecture_editor || teacher_id`, имя архива предсказуемо (`certificates_group_{groupId}_{time()}.zip`).
- Риск: редактор лекций/преподаватель качает архив сертификатов чужой группы (ФИО студентов). Требует редизайна привязки архива к инициатору — отдельная строка GTD.

### F6 — [ИСПРАВЛЕНО] Фолбэк-шаблон nginx без security-заголовков
- [`ops/migrate/templates/nginx-samskrte.conf`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/ops/migrate/templates/nginx-samskrte.conf) поднимался без заголовков, тогда как живой прод отдаёт `Strict-Transport-Security`, `X-Frame-Options`, `X-Content-Type-Options`, `Referrer-Policy`, `Permissions-Policy`.
- Исправление: заголовки (кроме HSTS — в фолбэке нет 443) перенесены в шаблон.

### F7 — [ИСПРАВЛЕНО, Report-Only] Нет CSP
- Live-заголовки несут HSTS/XFO/nosniff/Referrer-Policy/Permissions-Policy, CSP отсутствует; точечный `frame-ancestors` только на виджете расписания.
- Исправление: глобальный `Content-Security-Policy-Report-Only` в web-группе (AppServiceProvider) — наблюдение без блокировки; ужесточение до живого CSP после разбора отчётов (GTD).

### F8 — [ИСПРАВЛЕНО] Токены: долгие окна, нет отзыва всех устройств
- Sanctum 90 дней (`config/sanctum.php:58`), `logout` гасил только текущий токен.
- Исправление: новый `POST /api/v1/auth/logout-all` → `tokens()->delete()`; кнопка в мобильном — на стороне клиента.

### F9 — [ЗАФИКСИРОВАНО как осознанное] Латентный mass-assignment в User
- `is_admin/role/teacher_id/is_lecture_editor/prana_balance` остаются в `$fillable`: 162 тестовых файла создают персонал через `User::factory()->create([...role...])`, `DatabaseSeeder` передаёт `role` в `firstOrCreate` — выведение сломало бы их все (проверено grep'ом в worktree).
- Компенсирующий контроль: единственный HTTP-путь к User.create/update — Filament UserResource (явные поля, adminOnly-гейты); эксплойт-пути сейчас нет (все `update($request->all())`-паттерны в app/ проверены — только импорт статей с внутренним `$data`).
- Файл комментария-маркера в `User::fillable` (H4663) + этот раздел = запись решения.

### F10 — [ИСПРАВЛЕНО] API-роуты без Accept: application/json отвечали 302 на HTML
- Live: `GET /api/v1/courses` → 302 на `/login`. Мобильный клиент без Accept-заголовка получал HTML-редирект вместо 401.
- Исправление: `Authenticate::redirectTo` — для `api/*` всегда null → 401 JSON.

### F11 — [ИСПРАВЛЕНО] `/horizon` 403 для гостя раскрывал путь
- Исправление: `Horizon::auth` — гость → 404, залогиненный не-админ → 403, админ-канон → панель. Гейт H3312 (email-канон, fail-closed) сохранён.

### F12 — [УЖЕ БЫЛО OK] /force-download и остальные админ-ручки
- `/admin/payments/{payment}/paypal-proof` (is_admin), `/admin/marathon/mantra-voice/{enrollment}` (adminOnly), `/admin/course-design/*` (admin+manager), `/admin/surveys/{slug}/export` (RoleGate::any(ADMIN, MANAGER) внутри контроллера), `/admin/finance-templates/{name}` (finance) — все проверены, гейты на месте.

### F13 — [OK] Публичные поверхности (live-пробы)
- `/admin`→302 на форму входа, `/editor`→302, `/horizon`→403→теперь 404, `/storage/`→403, `/.env`→403, `/.git/config`→403, логи/вендор/telescope→404, `/api/webhooks/tochka` GET→405. http→https 301.

### F14 — [OK/наблюдение] Периметр хоста (live .92)
- Наружу: TCP 22/80/443 + UDP 41641/49483 (tailscaled). `input policy drop`. Loopback-only: MariaDB 3306, Redis 6379, Ollama 11434, Reverb 8080, postfix 25, php-fpm 8000/8001, kosha 8001. `192.168.200.92:8002` — приватная сеть.
- SSH: `passwordauthentication no`, `permitrootlogin without-password`, fail2ban active (26979 failed / 915 banned).
- TLS: 1.2/1.3, сильный шифронабор, HSTS includeSubDomains.

### F15 — [OK] CORS (приложение)
- `config/cors.php`: allowlist из env, по умолчанию пусто = запрещён; `supports_credentials=false`. Live: evil-origin simple и preflight — без ACAO. `SANCTUM_STATEFUL_DOMAINS` не задан → дефолт с localhost инертен (`EnsureFrontendRequestsAreStateful` закомментирован в Kernel).
- `TRUSTED_PROXIES` не задан: сегодня корректно (nginx отдаёт реальный REMOTE_ADDR через FastCGI); при появлении LB/CDN — задать (предупреждение уже в config/security.php).

## Исправлено с 02-07-2026 (прошлый аудит)
Path traversal редактора лекций (реалтайм + `..`-запрет); fail-open вебхуки TG/VK → fail-closed; соц-авторизация не линкует по неподтверждённому email; в вебхуке Точки — сверка суммы, отказ по холду, запрет воскрешения отменённого платежа; сырой payload не логируется; docker-compose: DB на 127.0.0.1 без пустого root; хардкод личной почты из логики входа убран; guest-checkout на чужой email — ValidationException в трёх контроллерах; `/homework/file/{file}` — owner-or-staff.

## Что не проверяется кодом (нужен сервер/инфра)
- Значения прод-`.env` секретов и их ротация (Точка, боты, n8n, APP_KEY).
- n8n на .91 (`context-ai.ru`) — отдельная машина и периметр.
- NAT/Proxmox-фаервол перед боксом (что видно из интернета помимо 22/80/443).
- Доступ/хранение restic-снапшотов и логов с ПДн.
- CSP-репорты (появятся после деплоя этой волны).

_Гасунс_