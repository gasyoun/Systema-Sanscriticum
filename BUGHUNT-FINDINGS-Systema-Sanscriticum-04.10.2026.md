# BUGHUNT-FINDINGS — Systema-Sanscriticum — 04.10.2026

_Created: 04-10-2026 · Last updated: 04-10-2026_

Ночная охота за багами (MG ruling 26-09-2026: nightly, one repo, auto-fix HIGH). Read-only фаза по коду репо: `app/`, `routes/`, `tools/`, `scripts/`, `config/`, `database/`, JS-сёрфейсы; `vendor/`, `node_modules/`, lockfiles и данные исключены. Pin: origin/main `09cbb51b` (H5945-эпоха).

- Elapsed: ~40 мин wall (hunt ~33 мин + landing).
- Вердикт: **HIGH — 0. MEDIUM — 2. LOW — 0 (ничего не стоит строки).** Автофикс-фаза (HIGH code) не запускалась — фиксов нет; HIGH credential/infra тоже нет — GTD-строка не минтилась.
- Репо в целом исключительно хорошо укреплено: каждая обысканная бага-класс либо закрыта комментарием-происхождением (H071/H1359/H4672/H5081), либо покрыта собственными гейтами репо.

## Findings (ranked)

### MEDIUM-1 — TOCTOU-гонка анти-дубля: проверка `pending` вне транзакции

- Где: [DebtPaymentController.php:272-282](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Http/Controllers/DebtPaymentController.php#L272) (и тот же shape в [PaymentController.php:232](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Http/Controllers/PaymentController.php#L232) / [:258](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Http/Controllers/PaymentController.php#L258)).
- Суть: `hasRecentPending`/`hasRecentPendingForUser` делается `->exists()` ДО транзакции, а `Payment::create` — внутри отдельной последующей транзакции без повторной проверки и без блокировки строки юзера. Два параллельных запроса (двойной клик, ретрай до commit) оба проходят проверку → два pending-платежа → студент может получить две ссылки и оплатить дважды.
- Evidence: прочитан код; `DB::transaction` начинается на строке 304 уже после `exists()` на 272; сравнение с флагманом `PaymentController` показало тот же порядок (проверка → транзакция). Живой гонки-эксплуата не ставил (продуцирование двойной оплаты на проде недопустимо) — это статический вывод, честно помечен MEDIUM, а не HIGH.
- Почему не HIGH: двойная оплата требует ручной оплаты обеих ссылок; вебхук H1359 сверяет суммы, `failed`-наблюдатель возвращает прану, доступ идемпотентен по блокам; возврат денег — штатный путь. Это признанный в доме best-effort (комментарий на 269-271 прямо называет сценарий «двойной клик / back»).
- Рецепт (когда возьмут в работу): перенести проверку внутрь транзакции с `lockForUpdate` на строке `users` (как в `PranaService::spend`) или уникальный partial-index на живой pending per user+course.

### MEDIUM-2 — `SESSION_SECURE_COOKIE=false` закреплён в `.env.example` (residual 0CV, H5046)

- Где: [.env.example:33](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/.env.example#L33).
- Суть: код по умолчанию ставит Secure=true (H3311, комментарий на строке 29), но `.env.example` явно пинит `false` — любой новый стенд, скопированный из примера, поднимается без Secure-cookie. Residual 0CV из security-audit run-1 (H5046) всё ещё открыт.
- Почему MEDIUM, не HIGH: это документ-пример, не прод-.env; инфра-резидуал, автофикс по правилам ночи запрещён (credential/infra). Заводить дубль GTD-строки не стал — 0CV уже числится в реестре; это обновление статуса, не новая находка.

## Проверено и зелёное (live evidence, каждый прогон — в этой сессии)

- **Compile-гейты по всему не-вендорному коду**: `python3 -m py_compile` — 200 py-файлов `tools/`+`scripts/` exit 0; `bash -n` по всем sh `tools/`, `scripts/`, `ops/` — чисто; `php -l` по выборке `app/Console/Commands` + `app/Services/Payments` — чисто; `node --check` по всем `public/js/*.js` + `resources/js/**` — чисто (вендорные ассеты `public/js/awcodes`, `public/js/saade` вне скоупа).
- **Secrets-свип**: git grep по шаблонам хардкод-ключей/токенов/паролей в app/routes/config/tools/scripts — 0 находок; tracked-дерево не содержит `.env`, ключей и pem (только `.example`-файлы и существующий гейт `WebhookSecretsPreflight`). SSH-упоминания `root@193.232.229.*` — только в ранбуках/логах как адреса хостов, без секретов.
- **Config-wiring**: программная сверка namespace'ов `config()` против файлов `config/` — все определены; первичные «хиты» (`curator`, `features.x`) оказались ссылками из docs/, не кодом.
- **Деньги/доступ**: Tochka-вебхук — RS256-подпись + идемпотентность по sha256-JWT + unique-index guard ([WebhookController.php](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Http/Controllers/WebhookController.php)); центовая арифметика во всех `app/Services/Payments/*` — целочисленная с guard'ами (включая [PaypalClaimAmountCheck.php:80](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Services/Payments/PaypalClaimAmountCheck.php#L80), отсекающий деление на ноль для реалистичных цен); `PranaService::spend` — `lockForUpdate` внутри транзакции; `GiftCertificateService::redeem` — `lockForUpdate` + статусный гейт (двойного погашения нет); `DepositController` — account-takeover закрыт (гость с существующим email получает отказ, комментарий 117-121).
- **Prior-art резидуал 0CU (H5046) — подтверждённо закрыт**: [JoinClassController.php](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Http/Controllers/JoinClassController.php) перепроверяет доступ в момент клика даже по валидной подписи (H5081, строки 21, 42-56) — анонимный перебор `/class/N/join` не выдаёт Zoom-ссылку.
- **XSS-класс (raw Blade-вывод)**: все `{!! !!}` в student/shop/auth-вьюхах трассируются до санитизированных или доверенных источников: `description_html` — аксессор с `RichHtml::sanitize` на записи и чтении ([Course.php:117-131](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Models/Course.php#L117)); гид кабинета рендерит константный репо-путь ([StudentCabinetGuideController.php:15](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Http/Controllers/StudentCabinetGuideController.php#L15)); `json_encode`-вставки — схемы/конфиг.
- **Cron/guards**: `scripts/server_guards/cron/root.crontab` — синтаксис выражений корректен, все задачи из инцидента 11-09 в управляемом источнике; guard-скрипты имеют собственные тесты `test_*.sh` и flock-защиту от наложения.
- **Маршрутный контракт**: `PromoController::show` существует ([routes/web.php:19](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/routes/web.php#L19), [PromoController.php](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Http/Controllers/PromoController.php)) — первичный «пробел» оказался ложным срабатыванием моего регекса, не багом.

## Фенс-соблюдение

stenogrammy/ не открывался; общее main-дерево не редактировалось (всё через worktree `Systema-Sanscriticum-hbughunt0410-14711` от origin/main); force-push/история — нет; голосов и решений за MG — нет. MEDIUM-находки не правились кодом (правило ночи: только HIGH code).

_Гасунс_
