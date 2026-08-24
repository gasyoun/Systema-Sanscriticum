# Очередь деплоя — для Ивана

_Создано: 08-07-2026 · Обновлено: 23-08-2026 (№81 H3314 — закат мобильных токенов 90 дней после деплоя, throttle ON; №80 H3247 `CRM_TRIAL_BOOKING` stay OFF; №79 H3233 `SUPPORT_DM_AUTO_REPLY` ON) (H2758 №77 `HINDI_YOUTUBE_NOVA3_DRILLS` stay OFF; H2762 Kochergina next-step/CTA A/B flags stay OFF; №73 H2444 `HINDI_ATTACHMENT_DRILLS` ON; №76 H2731 sidecar 1723 applied; №75 H2446 `HINDI_TG_CURATED_PRACTICE` stay OFF; №74 H2445 `HINDI_MY_SRS_DECK` stay OFF; H2645+H2644 клуб: `CLUB_MEMBERSHIP` к 28-08, порядок трёх флагов; №72 H2485 `CRM_SALES_FORECAST` ON; №71 H2443 `HINDI_TRANSCRIPT_DRILLS` ON; №70 H2441 `HINDI_PROGRAMME_PLAYLIST` ON; H2493 Grammar Lab G2 flags stay OFF; H2484 lifecycle flag OFF as №69; H2483 CRM 360 flag OFF as №68; H2482 VisualDCS flags stay OFF; №65 H2110 «Старт чтения» — флаг `KOSHA_READER`; H1947 «войти как» — флаг; H2085 silent-grant flags; H2017 PayPal/invoice ON; H2014 session; авто-деплой жив)_

### ✅ Предохранитель 30-07 СНЯТ — авто-деплой снова работает (31-07-2026)

Сбой 30-07 18:56Z (exit 124 в `npm ci && npm run build`) закрыт: предохранитель
`storage/auto_deploy.disabled` снят до 09:00Z 31-07, крон возобновил выкладки
(09:00Z → `24e28049` v1.80.7, 09:30Z → `d6494dc6` v1.80.8), а в 09:47Z обертка
`/usr/local/sbin/systema-auto-deploy-run.sh` прогнана вручную по SSH — прод на
`a4ff4325` = голова `main` ([v1.80.9](https://github.com/gasyoun/Systema-Sanscriticum/releases/tag/v1.80.9)).
Смоук 200, `guards:verify` чист, Horizon перезапущен; три зеленых деплоя подряд,
таймаут не повторился. Свидетельства и разбор —
[issue #945 (закрыт)](https://github.com/gasyoun/Systema-Sanscriticum/issues/945).
Если exit 124 повторится на npm-тяжелой выкладке — крутилка `SYSTEMA_AUTO_DEPLOY_MAX`
([docs/server-resource-guards.md §8](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/server-resource-guards.md)).
Релизы 1.80.4–1.80.9 живые; ниже в очереди остаются только пункты с решением/флагом/внешним шагом.

**30-07-2026 (H1933, решение MG): код теперь деплоится САМ — root-крон на проде
каждые 30 минут выкатывает `origin/main` через `deploy.sh`.** Ручной шаг
«прогнать deploy.sh» из этой очереди ушел; человеку остаются только пункты с
решением/флагом/внешним шагом (⚙️ финдир, `@DECIDE MG`, Точка/BotFather и т.п.) —
их авто-деплой не трогает. Если авто-деплой споткнется, он сам откатит код (когда
в выкладке нет миграций), поставит предохранитель `storage/auto_deploy.disabled`
и поднимет тревогу в Telegram; разбор — [docs/server-resource-guards.md §8](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/server-resource-guards.md).

**21-07-2026: MG подтвердил в чате — Иван прогнал деплой на проде сегодня.** Базовый
шаг (№1, `php artisan migrate` + `sudo bash deploy.sh`) и все пункты, не требующие
отдельного флага/финдир-решения/внешнего шага, перенесены в архив ниже. Пункты, где
активация зависит от финдира, `@DECIDE MG` или внешнего действия (Точка/BotFather/
Яндекс.Вебмастер), остаются в очереди — код на проде, но решение/флаг не подтверждены
отдельно; гадать по ним нельзя (см. H788).


Всё, что **влито в `main`, но еще не выкачено на прод**, с точными командами для
сервера. Прод — **root-VPS (Ubuntu, Beget)**. Код из `main` выкатывает **авто-деплой** (H1933, каждые 30 мин); человеку — только флаги/разовые artisan/внешние шаги. При сбое авто-деплоя — `storage/auto_deploy.disabled` + TG-тревога ([docs/server-resource-guards.md §8](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/server-resource-guards.md)). Ручной fallback: `sudo bash deploy.sh`. _Исправлено 10-07-2026 (H478): прежняя формулировка «по FTP, без SSH» неверна — SSH/root есть._
Это список-передача для Ивана: что и в каком порядке запустить.

**Обозначения**
- 🚀 **Приоритет** — после этого деплоя можно запускать проверку/сверку на реальных данных или следующий этап. Делать в первую очередь.
- ⚙️ флаг — нужна правка `.env` **и** согласование финдира перед включением (влияет на деньги).
- ⏱ планировщик — нужна запись в cron/планировщике сервера, а не разовая команда.

> Systema — одно Laravel-приложение: **одна команда `php artisan migrate` применяет
> все накопленные миграции сразу**. Запускается один раз, затем — шаги `.env` / backfill
> / планировщик ниже.
>
> После любой правки `.env`, если конфиг закэширован, сбросить кэш:
> `php artisan config:clear` (иначе флаги не подхватятся).

### H3312 — superadmin email вычищен из кода: прод .env получает ADMIN_EMAIL (fail-closed)

Личный email суперадмина больше не захардкожен в коде ([PR #1988](https://github.com/gasyoun/Systema-Sanscriticum/pull/1988)): Horizon gate, получатель backup-уведомлений и `services.admin.email` теперь читают единый `ADMIN_EMAIL`; пусто = все три функции отключены (Horizon deny + backup notify skip, с warning в логе, без крашей). До выката PR на прод:

1. `.env` прода добавить: `ADMIN_EMAIL=<личный адрес суперадмина>` — **значение в git не пишем**; взять тот же адрес, что был в `app/Providers/HorizonServiceProvider.php` до H3312 (история git), т.к. Horizon-доступ у этого адреса должен сохраниться. Внимание: раньше backup-письма уходили на ДРУГОЙ личный адрес (`config/backup.php` до H3312) — после правки оба канола получают один и тот же адрес из `ADMIN_EMAIL`.
2. После правки `.env`: `php artisan config:clear`, затем обычный деплой.
3. Smoke: вход под суперадмином → `/horizon` открывается; под обычным студентом → по-прежнему нет. Если `ADMIN_EMAIL` пуст — `/horizon` 403 для всех + в логе warning `viewHorizon denied`, backup-уведомления skip (`ADMIN_EMAIL is not configured`) — это ожидаемое fail-closed поведение, не авария.
4. Откат: убрать `ADMIN_EMAIL` из `.env` — безопасно, все ветки fail-closed.

### H3311 — конфиг-закалка: прод .env перед деплоем (Secure-cookie / TRUSTED_PROXIES / CORS_ALLOWED_ORIGINS)

Код инертен к same-origin фронту и локальной разработке, но прод обязан выставить три ключа в `.env` **до** следующего `deploy.sh` (новый шаг предсброса `php artisan deploy:config-preflight` проверяет первый жёстко):

1. `.env` прода добавить/проверить:
   - `SESSION_SECURE_COOKIE=true` (или оставить незаданной — код теперь дефолтит true; явное `false` = деплой заблокирован);
   - `TRUSTED_PROXIES=127.0.0.1` — адрес nginx/LB, с которого реально приходит трафик (иначе warning + клиентские IP во всех ip()-зависимых местах станут адресом прокси);
   - `CORS_ALLOWED_ORIGINS=https://samskrtam.ru,https://samskrte.ru` (+ staging-домен при необходимости; пусто = cross-origin запрещён).
2. После правки: `php artisan config:cache`, затем `sudo bash deploy.sh` — предсброс должен ответить `deploy:config-preflight OK`.
3. Smoke: логин студента и чекаут проходят (кука с Secure на https), `/api/public/schedule` отвечает same-origin без CORS-ошибок.
4. Стоп: если smoke красный по кукам — `SESSION_SECURE_COOKIE=false` только как аварийный откат с пониманием риска (http-only трафик видит куку).

### H3310 — гигиена публичного диска (архивы сертификатов + CSV импорта) — план миграции на прод

После выката кода новые записи уже пишут в `local`; на сервере остаются legacy-файлы в `storage/app/public/archives` (групповые ZIP сертификатов, собранные до миграции). После деплоя:

1. `php artisan archives:move-public-to-local --dry-run` — показать план: сколько ZIP уедет.
2. `php artisan archives:move-public-to-local` — идемпотентный перенос в приватный `storage/app/archives`, опустевший каталог удаляется сам. Прямые ссылки `/storage/archives/...` начинают отдавать 404, скачивание остаётся только через staff-маршрут `/force-download/{file}` (кнопка «Скачать ZIP» в колокольчике Filament).
3. Smoke: не-стаффу `GET /force-download/<файл>` → 403/редирект на логин; стаффу → скачивание; старый прямой URL `GET /storage/archives/<имя>.zip` → 404.
4. Cron `archives:cleanup` правки не требует — чистит и новый каталог, и остатки legacy по одному порогу.

### H3308 — приватизация контента уроков — прогон миграции на проде

Код инертен для новых записей (они уже пишут в `local`), но существующие файлы лежат в `storage/app/public` и статически раздавались из `/storage`. После деплоя:

1. `php artisan lessons:privatize-gated-assets` — dry-run: покажет, сколько файлов уйдёт (transcripts/, lesson-materials/, homework-prompts/; `lectures/` не трогает).
2. `php artisan lessons:privatize-gated-assets --apply` — копия на private + удаление публичных оригиналов после сверки размера.
3. Smoke: анонимный `GET /storage/transcripts/lesson-<N>.json` → 404; студент с оплаченным курсом открывает урок → стенограмма и материалы грузятся через `/c/{slug}/u/{id}/...`.

### H3298 — SMTP 554 / E-channel — @DECIDE MG (вариант B/C), потом paste-kit

Диагноз: [docs/DIAG_SYSTEMA_SMTP_554_H3298_22-08-2026.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/DIAG_SYSTEMA_SMTP_554_H3298_22-08-2026.md). Бесплатный ящик `rusamskrtam@yandex.ru` ловит `554 spam` на чеках покупок (11 в августе); samskrte.ru без SPF/DMARC/MX.

1. MG выбирает B (Yandex 360 на samskrte.ru) или C (UniOne/SendPulse) → DNS-записи по паст-киту из диага.
2. Сервер `.env`: новые MAIL_USERNAME/MAIL_FROM_ADDRESS/пароль → `php artisan config:cache`.
3. Smoke E из диага §4 (`SENT_OK` + письмо получено) → retry 12 потерянных чеков (`queue:retry` по списку id).
4. Стоп: при отказе от смены — ничего не трогать, вариант A осознанно принят.

### H3247/H3248 — trial Deal CRM — ✅ ОБА ФЛАГА ВКЛЮЧЕНЫ 24-08-2026

**Статус (24-08-2026):** `CRM_TRIAL_BOOKING=true` + `CRM_TRIAL_WIDGET_PUBLIC=true` в прод `.env`, config:cache пересобран. Смок OxAlpha PASS: tinker-заявка через `TrialBookingService::bookFree` на реальном ближайшем пробнике создала Lead+Deal (`kind=trial`, `booked`), повтор идемпотентен, User/гранты/группы не создавались (Rank 4), тестовые строки удалены — ноль остатков. Публичная кнопка «Записаться» на `/widgets/schedule` жива по отдельному рулингу MG 24-08 («оставить ON»). Откат: оба ключа `false` + `config:cache`.

### H3445 — гео-город посетителя: драйвер MaxMind GeoLite2 (локальная база)

Рулинг MG 24-08-2026: провайдер города = **MaxMind GeoLite2 локально** (Cloudflare отклонён как заграничный процессор; ip-api.com исключён лицензионно). Код драйвера `'maxmind'` в `VisitorGeoResolver` + команда `support:geo-update-maxmind` (еженедельно вс 04:40). Включение — ПОСЛЕ правки политики приватности (бриф H1234, C(i)):

1. В `.env`: `MAXMIND_ACCOUNT_ID=<id>`, `MAXMIND_LICENSE_KEY=<ключ с maxmind.com>` (бесплатная регистрация).
2. `php artisan support:geo-update-maxmind --dry-run` → затем без флага. База ляжет в `storage/app/geo/GeoLite2-City.mmdb`.
3. Правка текста политики приватности (раздел данных: «гео-город анонимного посетителя, резолв локально, IP не передаётся третьим лицам») → подтверждение MG.
4. Только тогда: `SUPPORT_GEO_DRIVER=maxmind`, `SUPPORT_VISITOR_GEO=true` (+ presence `SUPPORT_VISITOR_PRESENCE=true` отдельно).
5. Стоп: `SUPPORT_GEO_DRIVER=null`, флаги `false`. База остаётся на диске безвредно.

### №81 — H3314 — закат мобильных Sanctum-токенов (90 дней) + per-credential login throttle

Деплой активирует 90-дневное окно `sanctum.expiration` (`SANCTUM_TOKEN_EXPIRATION=129600`) и per-credential login throttle (`LOGIN_THROTTLE_*`, порог 5/60 сек, аварийный выключатель `LOGIN_THROTTLE_ENABLED=true` — дефолт ON). **Коммуникация мобильным пользователям:** после деплоя каждый существующий мобильный токен умрёт не позднее чем через 90 дней от его создания (токены старше 90 дней — сразу при деплое); приложение само повторно логинится по email+паролю, действий от студента не требуется, но поддержка должна знать про возможные «меня выкинуло из приложения». Новые токены получают явный `expires_at`; ежедневная команда `tokens:prune-expired` (03:20 MSK) подчищает мёртвые строки.

1. После деплоя проверить: `php artisan tokens:prune-expired --hours=1` (сухой прогон счётчиком), логин в мобильном API `/api/v1/auth/login` → 200 с `expires_at` ≈ +90 дней.
2. Мониторинг: рост 429 на `/login`, `/shop/login`, `/api/v1/auth/login` первые сутки (легитимные студенты при NAT/опечатках) — при массовых ложных блокировках: `LOGIN_THROTTLE_ENABLED=false` + `config:cache`.
3. Откат окна токенов (только по решению MG): `SANCTUM_TOKEN_EXPIRATION=` пусто в `.env` не отключает окно — ставить большое значение или править дефолт; throttle выключается флагом из п.2.

Док: [docs/PLAN_SYSTEMA_MOYKLASS_TRIAL_BOOKING_2026H2.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/PLAN_SYSTEMA_MOYKLASS_TRIAL_BOOKING_2026H2.md).

### H2762 — next-step + CTA A/B on Kochergina — флаги ОСТАЮТСЯ OFF

Код инертный, пока оба флага выключены. Это не денежный грант: только витринная подпись и полоска «после этого» на одной карточке Кочергиной.

1. Когда нужен 30-дневный прогон: `CATALOG_NEXT_STEP=true` и/или `FLAGSHIP_CTA_AB=true`, плюс `FLAGSHIP_EXPERIMENT_STARTED_AT=2026-08-15` (дата включения), затем `php artisan config:clear`.
2. Смоук: `/online` — полоска только на карточке Кочергиной; `/k/grammatika-po-kocerginoi-gr61` — hero CTA с `data-cta-ab`; другие курсы без изменений.
3. Счёт: `php artisan shop:flagship-experiments --days=7`.
4. Стоп: тонкий n или поломка вёрстки → оба флага `false`. Победителя не выкатывать.

Док: [docs/FLAGSHIP_EXPERIMENTS_H2762.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/FLAGSHIP_EXPERIMENTS_H2762.md).

### ✅ H2645+H2644 — клуб ВКЛЮЧЁН на проде 16-08-2026 (шаги 1 и 4 сделаны; 2 и 3 открыты)

**Сделано 16-08-2026 по прямому указанию MG (Opus 5 `claude-opus-5`, H2886):**
`CLUB_MEMBERSHIP=true` в прод-`.env` (строка 356) + `php artisan config:cache`.
`/klub` отдаёт **200** со всеми тремя ценами и чекаутами `/checkout/5038|5039|5040`,
кнопка «Клуб» на `/online` живая, `membership:rehearse` — все шаги PASS.
Полка набрана: #274 Логика (2024) `block_1` · #343 Бюллер гр.27 `block_1` ·
#397 Гимн Гуру стотрам (2024) `full`.
Ничего не создано: членств 0, платежей по курсу 444 — 0, грантов бесплатного
уровня 0. `MEMBERSHIP_CANCELLATION` и `MEMBERSHIP_FREE_TIER` **остаются OFF**.

> **Календарь опережён на 12 дней.** Планом страница жила с 28-08 (пост 31-08,
> запуск 01-09) — сейчас она публична с 16-08. Если это не входило в замысел,
> откат одной строкой: убрать `CLUB_MEMBERSHIP=true` из `.env` и
> `php artisan config:cache`; бэкап `.env.bak-h2886-20260816-115203` лежит рядом.
> Откат безопасен — членств нет, отменять нечего.

Остаётся человеку: пункт 2 (`MEMBERSHIP_CANCELLATION`) и пункт 3
(`MEMBERSHIP_FREE_TIER`, **последним**, после живых оплат) — плюс один живой
чекаут для проверки денежного пути.

#### Исходная инструкция (сохранена как справка)

Код обоих контуров на проде авто-деплоем ([PR #1656](https://github.com/gasyoun/Systema-Sanscriticum/pull/1656) —
жизненный цикл членства, [PR #1700](https://github.com/gasyoun/Systema-Sanscriticum/pull/1700) — лендинг + прайсинг).
Курс `club` (id 444) и три тарифа (5038 месяц ₽1 500 / 5039 квартал ₽4 000 / 5040 год ₽15 000)
уже заведены в Filament 13-08. Деньги — включение согласует MG (⚙️).

Порядок включения (календарь запуска: страница живёт с 28-08, пост 31-08, запуск 01-09):

1. ✅ **СДЕЛАНО 16-08-2026:** в `.env` прода `CLUB_MEMBERSHIP=true`, затем
   `php artisan config:cache` (конфиг на проде закэширован — авто-деплой делает
   `config:cache`, поэтому `config:clear` оставил бы приложение без кэша до
   следующей выкладки). После этого `/klub` и кнопка «Клуб» на `/online` живые,
   оплата клубного тарифа создаёт членство. Проверка: `php artisan membership:rehearse`
   (сквозная репетиция, пункт «d» приёмки H2644) + открыть `https://samskrte.ru/klub`.
2. ✅ **СДЕЛАНО 16-08-2026 12:26 MSK по указанию MG** (Opus 5 `claude-opus-5`):
   `MEMBERSHIP_CANCELLATION=true` (строка 357) + `php artisan config:cache` —
   кнопка «Не продлевать» в кабинете; страница `/klub` подхватила формулировку.
   Проверка: маршруты `POST membership/cancel` и `POST membership/resume` появились
   в `route:list` (при выключенном флаге их там не было — это и есть тест
   «routes are 404 while the flag is off»). Оба POST и под авторизацией, то есть
   гостю не видны. Бэкап: `.env.bak-cancel-20260816-122626`.
3. **Последним, после проверки клуба на живых оплатах:** `MEMBERSHIP_FREE_TIER=true` —
   месячные бесплатные гранты 350 уснувшим (D6 вариант «а», кампания H2566);
   строка «Скоро» на `/klub` сама станет настоящим временем.
4. ✅ **СДЕЛАНО 16-08-2026.** Полка записей — **ЯВНЫМ списком; голый `--apply` не работает**
   (замерено на проде 16-08-2026, [H2865](https://github.com/gasyoun/Uprava/blob/main/handoffs/H2865-Opus_Systema-Sanscriticum_28aug-integrated-launch-gate_16.08.26.md)):
   `php artisan membership:club-catalogue --course=<id|slug> --course=… --apply`.
   Авто-подбор предлагает **ноль** курсов — `Course::sellsRecordings()` требует
   одновременно `COURSE_RECORDINGS_SALES` (на проде OFF) и `is_completed=true`
   (0 из 100 активных курсов). Без полки клубный каталог **пуст**, шаг 3
   `membership:rehearse` = FAIL, и купивший за ₽1 500 не получает ничего.
   Какие записи входят в клуб — решение человека (живой курс стоит ₽6 000).
   Разбор и точная последовательность: [docs/LAUNCH_GATE_28_08_2026.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/LAUNCH_GATE_28_08_2026.md).

### H2017 — PayPal diaspora + счёт юрлицу **✅ на проде** (31-07-2026)

Код: [PR #969](https://github.com/gasyoun/Systema-Sanscriticum/pull/969) merged.
Флаги на проде (не в git):

- `PAYPAL_CLAIM_ENABLED=true`
- `PAYPAL_ME_LINK=https://www.paypal.com/paypalme/gasyoun`
- `PAYPAL_RECIPIENT=gasyoun@gmail.com`
- `COMPANY_INVOICE_ENABLED=true` + `BILLING_*` (ИП Гасунс Марцис / ИНН 540861224623 / р/с Точка)

Смоук: `/paypal/{tariff}` и `/invoice/{tariff}` → 200. Tochka: sbp+card, cashbox `digitalKassaTochka`.
ККТ (своя) — **не** в этой выкладке. Доки: [TOCHKA audit](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/TOCHKA_PAYMENT_METHODS_AUDIT_2026-07-31.md).

---

### ⚙️ H2482 — native VisualDCS surfaces — флаги ОСТАЮТСЯ OFF

Код: три независимых флага `VISUALDCS_VERB` / `VISUALDCS_NOMINAL` / `VISUALDCS_PASSAGE`
(все default false). После merge:

1. `php artisan migrate` — таблицы `visualdcs_releases`, `external_learning_progress`.
2. Импорт пина (sibling VisualDCS или fixtures):  
   `php artisan visualdcs:import C:/Users/user/Documents/GitHub/VisualDCS/visual/contracts/v1`  
   Пин H2499 keep #110 = `vdcs-learner-v1-20260809` (hashes in `tests/fixtures/visualdcs/published-v1-pin.json`).  
   (на проде — только после решения включить; до этого каталог пуст, маршруты 404).
3. Флаги **не включать** в этом деплое. Активация — отдельное решение после
   7/14/30-дневного baseline в [docs/VISUALDCS_LEARNER_BASELINE_2026.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/VISUALDCS_LEARNER_BASELINE_2026.md).
4. Откат одной поверхности: снять её env-флаг + `config:clear`. Откат релиза:  
   `php artisan visualdcs:rollback`.

Не трогает цены, Payment, identity.

---

### ⚙️ H2493 — Grammar Lab G2 explorer — флаги ОСТАЮТСЯ OFF

Код: `GRAMMAR_LAB` и `GRAMMAR_LAB_SEMANTIC` (оба default false). После merge:

1. `php artisan migrate` — таблицы `grammar_topics`, `grammar_topic_sources`,
   `grammar_topic_versions`, `grammar_exercises`, `grammar_bookmarks`,
   `grammar_topic_views`, `grammar_lab_entitlements`, `grammar_lab_imports`.
2. Импорт пина G1 (уже в репо под `resources/data/grammar_lab/`):  
   `php artisan grammar-lab:sync --skip-copy`  
   (или `grammar-lab:sync` с sibling SanskritGrammar). На проде до включения
   флага маршруты 404, импорт можно отложить.
3. Флаги **не включать** в этом деплое. `GRAMMAR_LAB=true` — отдельное решение
   G4 / человека. `GRAMMAR_LAB_SEMANTIC` не включать, пока G1 `semantic_ready`
   ложен (сейчас `charngram-hash-v1`).
4. Откат: снять env + `config:cache`. Таблицы аддитивные.

Не создаёт платежей и не включает подписку. Доступ — `GrammarLabAccess::canUse()`.

---

### ⚙️ H2085 — silent-grant gaps (hold ≠ paid · empty groups) — **после human-merge PR**

Код авто-деплоится с `main`; границы оплаты теперь являются безусловными
инвариантами, без прод-флагов. Перед merge проверить, что каждый продаваемый
курс имеет ≥1 группу в Filament «Группы». `TOCHKA_WEBHOOK_GUARD` должен
оставаться включённым (default `true`; prod не должен переопределять `false`).

Memo: [docs/H2085_MONEY_SILENT_GRANT_GAPS_DECISION_01-08-2026.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/H2085_MONEY_SILENT_GRANT_GAPS_DECISION_01-08-2026.md).
Smoke after deploy: hold JWT must leave payment `pending`; captured/completed with groups grants;
course without groups → 500 until groups attached.

---

### 🚀 Вход: 419 «Page Expired» — SESSION lifetime **✅ на проде** (H2014, 31-07-2026)

**Было.** Преподаватель не входила (жалоба 29-07): `SESSION_LIFETIME=120` + редкие
заходы → CSRF 419. Мягкий 419 ([PR #778](https://github.com/gasyoun/Systema-Sanscriticum/pull/778)/[#779](https://github.com/gasyoun/Systema-Sanscriticum/pull/779))
на проде с 28-07.

**Проверено 31-07 (H2014, live SSH):** прод `.env` уже `SESSION_LIFETIME=1440`,
`config('session.lifetime')` = 1440. Пункт «поднять lifetime» **снят**.

**Опциональный остаток (не блокер):**
- телеметрия CSRF: `storage/logs/csrf-mismatch.log`, `csrf:mismatch-digest` 04:25
  (нужен `schedule:run`);
- смок: `/login` после длинной вкладки без 419;
- graceful 419 на каждом web POST (ветка `feat/h1771-csrf-419-graceful-default`
  ещё не в `main`).

---

### H1794 — cabinet probe hardening (после деплоя) — ✅ pulse env 30-07-2026

1. ✅ `php artisan migrate` — таблица `cabinet_probe_runs` (если ещё не на проде — входит в общий migrate)
2. Smoke student (если ещё нет):
   - `TEST_STUDENT_EMAIL=smoke-student@samskrte.ru`
   - `TEST_STUDENT_PASSWORD=<secret>`
   - `php artisan users:ensure-test-student`
3. ✅ **Внешний pulse — Better Stack Uptime** (не healthchecks.io):
   - period **5 min** → `HEARTBEAT_PING_URL=https://uptime.betterstack.com/api/v1/heartbeat/<TOKEN>`
   - period **15 min**, grace ~20 → `CABINET_PROBE_PING_URL=…`
   - Inventory + samskrtam + Cologne: [docs/UPTIME_BETTERSTACK_MONITORING.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/UPTIME_BETTERSTACK_MONITORING.md) · issue [#891](https://github.com/gasyoun/Systema-Sanscriticum/issues/891)
4. TG: `CABINET_PROBE_TELEGRAM_CHAT_ID` (critical; default admin+rusamskrtam already on prod)
   optional soft: `CABINET_PROBE_TELEGRAM_SOFT_CHAT_ID` · soft cooldown: `CABINET_PROBE_TELEGRAM_SOFT_COOLDOWN`
5. `php artisan config:clear && php artisan cabinet:probe` (после любой смены env)
6. Filament → «Здоровье кабинета» (группа Система) — история прогонов
7. **Не делаем:** auto-restart fpm, Playwright, public status page (handoff non-goals)

---

### ⚙️ Off-site backup Yandex.Disk — **human-secret** (H2014 probe 31-07-2026)

Локальные бэкапы **живые** (`backup:list`: disk `local` healthy, 7 zip). Disk
`yandex_disk` — **Unauthorized** (WebDAV 401).

1. В аккаунте Яндекса создать **пароль приложения** (не основной пароль).
2. Прод `.env`: `YANDEX_DISK_LOGIN=…`, `YANDEX_DISK_APP_PASSWORD=…`
   (опц. `YANDEX_DISK_BACKUP_PATH`, default `/Backups/systema-sanscriticum`).
3. `php artisan config:clear && php artisan backup:run --only-to-disk=yandex_disk`
4. `php artisan backup:list` — `yandex_disk` Reachable ✅.

См. [`docs/OPTIMISATION_BACKLOG_2026H2.md`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/OPTIMISATION_BACKLOG_2026H2.md) §3.

---

### 🚀 H1990 — Bühler grammar-tables → SRS деколода ([PR #1073](https://github.com/gasyoun/Systema-Sanscriticum/pull/1073))

Код доедет авто-деплоем. Один разовый artisan-импорт после деплоя (идемпотентно,
можно перезапускать):

1. `php artisan srs:import-buhler-paradigms --dry-run` — сверить: 1 колода, 78 карточек.
2. `php artisan srs:import-buhler-paradigms` — применить.
3. Проверить `/koloda/buhler-paradigm-drills` отдаёт 200 (при `SRS_ENABLED=true`).

### 🚀 Разовый бэкфилл штампа записи урока ([PR #871](https://github.com/gasyoun/Systema-Sanscriticum/pull/871), issue [#868](https://github.com/gasyoun/Systema-Sanscriticum/issues/868))

Колонка `lessons.recording_attached_at` на проде заполнена у ~5 из ~1667 уроков с
записью (~99.7% NULL). Авто-открытие ДЗ видит NULL как «записи нет» →
`homework:auto-open` пишет «0 из 0». Код команды в `main` (доезжает авто-деплоем).

1. Dry-run: `php artisan lessons:backfill-recording-stamp` (без записи).
2. Сверить отчёт (сколько восстановится из `lesson_date`).
3. Применить: `php artisan lessons:backfill-recording-stamp --apply`.
4. **Не шлёт уведомления** и **не открывает ДЗ** сама; охват `homework:auto-open`
   по-прежнему режется `HOMEWORK_AUTO_OPEN_COURSES` + `textbook_lesson` 1–5 (см. №63).
5. Опц.: `--course=<slug>` / `--limit=N`.

### 🚀 Arzamas-лонгриды — import + publish ([PR #720](https://github.com/gasyoun/Systema-Sanscriticum/pull/720)/[#728](https://github.com/gasyoun/Systema-Sanscriticum/pull/728))

Код в `main`. На сервере, **порядок важен**:

1. `php artisan materials:import-pwg-arzamas --publish`
2. `php artisan materials:import-kossovich-arzamas --publish`
3. Ещё раз `php artisan materials:import-pwg-arzamas` (живая ссылка гл. 16 → второй материал)
4. Смоук: <https://samskrte.ru/s/peterburgskiy-slovar-pwg>,
   <https://samskrte.ru/s/rossiya-i-sanskritskiy-slovar>,
   карточки на <https://samskrte.ru/online/materialy>

### ⚙️ Гибридный кабинет Phase 4 — флаг (GO 29-07-2026; [PR #673](https://github.com/gasyoun/Systema-Sanscriticum/pull/673)/[#678](https://github.com/gasyoun/Systema-Sanscriticum/pull/678)/[#679](https://github.com/gasyoun/Systema-Sanscriticum/pull/679))

Код инертен при `CABINET_HYBRID=false`. Baseline ≥14 дней с 21-07.

1. `php artisan cabinet:hybrid-readiness` + `php artisan cabinet:baseline --days=14`
2. Staging walkthrough — [`docs/CABINET_HYBRID_PHASE4_RELEASE_PACK_2026.md`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/CABINET_HYBRID_PHASE4_RELEASE_PACK_2026.md)
3. `CABINET_HYBRID=true` → `php artisan config:clear` (или `config:cache`)
4. Смоук: студент → «Сегодня» + job-nav; `/library` `/progress` `/access` = 200
5. Откат: `CABINET_HYBRID=false` → `config:clear`. Post-flip: `cabinet:baseline --days=7` / `--days=14`

### ⚙️ H1947 — режим просмотра за пользователя («войти как») — флаг ([PR #1040](https://github.com/gasyoun/Systema-Sanscriticum/pull/1040))

Супер-админ смотрит кабинет студента, панель куратора или панель преподавателя, не меняя `users.role`.
Код **инертен** при `STAFF_IMPERSONATION=false`: маршруты `/impersonate/*` отдают
404, кнопок в «Студентах» нет, журнал скрыт. Миграция `impersonation_audits`
аддитивна и приезжает штатным авто-деплоем.

1. `STAFF_IMPERSONATION=true` в `.env` → `php artisan config:cache`
2. Смоук: «Студенты» → 👁 у студента → кабинет открылся под ним, сверху плашка →
   «Выйти из режима» → вы снова супер-админ
3. Смоук куратора: 🪪 у пользователя с ролью `manager` → в панели **не видно**
   админ-онли разделов → «Вернуться в супер-админ»
4. Проверить журнал: «Пользователи» → «Входы за пользователя» — по строке на вход и
   проставленный «Выход»
5. Откат: `STAFF_IMPERSONATION=false` → `config:cache` (открытый режим закроется сам
   на первом же запросе)

Инструкция для человека — [`docs/admin-manual.md`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/admin-manual.md) §5.4.
Денежные записи в режиме запрещены (403) — это не баг, а предохранитель.

---

## Systema-Sanscriticum (samskrte.ru) — финансы

Весь план A→C→B→D + реверс возврата влит; финдир подтвердил готовность всех
четырёх пунктов 31-07-2026 (чат) — backfill/сверка/проверка выполнены тем же
проходом (Sonnet 5 `claude-sonnet-5`), см. пометки ✅ ниже.

| № | Что (PR) | Команды на проде | Тип | Что разблокирует |
|---|---|---|---|---|
| 67 | H2448 FAQ RAG suggester | `FAQ_RAG_SUGGESTER=false` (default OFF — leave OFF). Optional later enable only after curator trial: `true` + `config:cache`. No migration. Parent `SUPPORT_ANSWER_SUGGESTER` unchanged. | pending |
| 68 | **H2483 CRM Wave 1 — карточка клиента 360** | После авто-деплоя код инертен (`CRM_CUSTOMER_360=false`). Включение (human): `CRM_CUSTOMER_360=true` в `.env` → `php artisan config:clear`. Страница `/admin/customer-360` (admin/manager). Деньги/доступ только читаются. Смоук: открыть студента с оплатой и заявкой, проверить следующее действие и ссылку на владельца. Откат: флаг `false`. | ⚙️ флаг (без денег) |
| 69 | **H2484 CRM Wave 2 — lifecycle-черновики** | После авто-деплоя код инертен (`CRM_LIFECYCLE_AUTOMATION=false`). Dry-run без флага: `php artisan crm:lifecycle-prepare --json`. Включение (human): `CRM_LIFECYCLE_AUTOMATION=true` + `php artisan config:clear`. Готовит только draft Campaign; отправка писем по-прежнему `EMAIL_CAMPAIGNS` + кнопка «Отправить». Смоук: `--json` показывает eligible/excluded/suppressed/recovery/deduplicated; `--apply` не диспатчит mail. Откат: флаг `false`. | ⚙️ флаг (без денег) |
| 70 | **H2441 плейлист «Мой хинди»** | ✅ **включено 14-08-2026.** `HINDI_PROGRAMME_PLAYLIST=true` + `config:cache`. Probe user 6494: 36 accessible (20+4+12). In-process GET `/dvaram/programme/hindi` as 6494 → 200, 36 items. Guest → 302. Откат: флаг `false` + `config:cache`. | ⚙️ флаг (без денег) |
| 71 | **H2443 упражнения из расшифровки хинди** | ⛔ **выключено 20-08-2026 (H3206).** `HINDI_TRANSCRIPT_DRILLS=false` + `config:cache`. Студентам мусорные cloze из Whisper/Deepgram не показываем. Преподаватель видит черновик: `/admin/hindi-agent-drills` + блок на «Мой хинди». Откат (не делать без Костиной): `true` + `config:cache`. | ⚙️ флаг (без денег) |
| 72 | **H2485 CRM Wave 3 — прогноз продаж** | ✅ **включено 14-08-2026.** `CRM_SALES_FORECAST=true` + `config:cache`. Prod HEAD `f416e312`. `php artisan config:show features.crm_sales_forecast` → true. Route `admin/sales-forecast` registered; guest GET → 302 login. Live report (12:54): 3 open deals / 18 000 ₽ / weighted 2 700 ₽; last-30d actuals 117 / 574 825 ₽; 30/90d backtest unavailable. Откат: флаг `false` + `config:cache`. | ⚙️ флаг (без денег) |
| 73 | **H2444 упражнения из файлов занятия хинди** | ⛔ **выключено 20-08-2026 (H3206).** `HINDI_ATTACHMENT_DRILLS=false` + `config:cache`. ISBN/pro-hindi.ru cloze с раздатки 1723 студентам не отдаём. Раздатку по-прежнему можно открыть с занятия. Черновик: `/admin/hindi-agent-drills`. | ⚙️ флаг (без денег) |
| 74 | **H2445 приватная колода «Мой хинди»** | После авто-деплоя код инертен (`HINDI_MY_SRS_DECK=false`). Включение (human): `HINDI_MY_SRS_DECK=true` + `php artisan config:cache`. Кнопки «в колоду» на `/dvaram/programme/hindi` и `/c/{slug}/u/{id}/drills`. Не включает `SRS_ENABLED`, не пишет Hindi Core. Смоук: entitled user POST item_id → private `my-hindi`; повтор → duplicate; guest → 302. Откат: флаг `false` + `config:cache`. | ⚙️ флаг (без денег) |
| 75 | **H2446 отобранная практика из чата хинди** | После авто-деплоя код инертен (`HINDI_TG_CURATED_PRACTICE=false`). Включение (human): `HINDI_TG_CURATED_PRACTICE=true` + `config:cache`. Страница `/dvaram/programme/hindi/chat-practice` + ссылка в «Мой хинди». Не вызывает Telegram. Смоук: `php artisan hindi:tg-practice-probe --json` (10 accepted, answers `***`); entitled GET → 200 / 10 items; guest → 302. Откат: флаг `false` + `config:cache`. | ⚙️ флаг (без денег) |
| 76 | **H2731 sidecar PDF хинди (занятие 1723)** | ✅ **применено 14-08-2026.** `hindi:pdf-sidecar 1723 --apply` написал sibling `.txt` (8 страниц, `gs-txtwrite`, 19 444 байт). Флаг H2444 включён отдельно (№73). Откат sidecar: удалить `storage/app/public/homework-prompts/Практическая_грамматика_хинди_Уровень_a1.txt`. | разовый artisan | H2444 видит текст раздатки 1723 |
| 78 | **H3206 словарь Костиной → упражнения** | После авто-деплоя включить: `HINDI_DICTIONARY_DRILLS=true` + `config:cache`. Студент: GET `/dvaram/programme/hindi/vocab` (оплаченный хинди) → 200, M1 показывает आदाब. Гость → 302. Преподаватель: `/admin/hindi-agent-drills`. Откат: `false` + `config:cache`. Не включает H2443/H2444. | ⚙️ флаг (без денег) |
| 81 | **H3280 календарь выплат по неделям** | ✅ **включено 21-08-2026.** `TEACHER_WEEKLY_PAYOUT_CALENDAR=true` + `config:cache`. `config:show features.teacher_weekly_payout_calendar` → true. Guest GET `/admin/teacher-weekly-payout-calendar` → 302 login. `php artisan teacher-payouts:week-calendar` → `money_tables_moved=no`. PayPal cover всегда «откройте PayPal». Откат: `false` + `config:cache`. | ⚙️ флаг (чтение, не проводка) |
| 80 | **H3280 Точка ClosingAvailable на Зарплатах** | После деплоя инертен (`TOCHKA_BALANCE_ON_SALARIES=false`). Включение: `true` + `config:cache`. Смоук: `/admin/teacher-salaries` у бухгалтера показывает «Точка, к трате»; `php artisan tochka:balance --json` → `money_tables_moved:false`. Откат: `false` + `config:cache`. Недельный календарь H3280 — отдельно. | ⚙️ флаг (чтение банка, не проводка) |
| 79 | **H3233 B: автоответ простых в личке саппорта** | ✅ **включено 21-08-2026.** HEAD `506f8c2a`. `php artisan config:show features.support_dm_auto_reply` → true. A/B/C с фактами LMS уходит студенту pending→синк; D/без фактов — подсказка на `ADMIN_TELEGRAM_ID`. Откат A: `SUPPORT_DM_AUTO_REPLY=false` + `config:cache`. Счётчик 🍎/gasuns на исходящих синка работает и при OFF. | ⚙️ флаг (без денег) |
| 77 | **H2758 YouTube re-ASR drills for students** | После авто-деплоя код инертен (`HINDI_YOUTUBE_NOVA3_DRILLS=false`). **Не включать**, пока преподаватель хинди не скажет, что черновик на `/dvaram/programme/hindi` можно показывать студентам. Включение: `HINDI_YOUTUBE_NOVA3_DRILLS=true` + `php artisan config:cache`. Откат: `false` + `config:cache`. Zoom/n8n расшифровки (без `metadata.source`) не затрагивает. | ⚙️ флаг (без денег) | студенты видят упражнения из YouTube-расшифровки |
| 2 | **Признание выручки по начислению** ([PR #370](https://github.com/gasyoun/Systema-Sanscriticum/pull/370)) | ✅ **выполнено 31-07-2026.** `php artisan revenue:backfill-schedule` прогнан (было 7944 строк → стало 7962; всего платежей 9117, образуют выручку 7914). Сверка на реальном платеже (id 13612, курс «Грамматика по Кочергиной гр.60»): 7 строк признания, сумма 10.00 ₽ = сумме платежа — сходится. | backfill | 🚀 реальный ОПиУ по начислению + сверка отложенной выручки |
| 3 | **Реверс остатка при возврате** ([PR #376](https://github.com/gasyoun/Systema-Sanscriticum/pull/376)) | ✅ **флаг уже `true` на проде, backfill прогнан 31-07-2026** (тот же прогон, что и №2 — `revenue:backfill-schedule` применяет обе логики разом). Сверка на реальном возврате: **привязанных возвратов (`refund_of_payment_id`) в проде пока 0** — честно нулевая проверка, не дефект (см. changelog H2003); механизм готов подхватить первый реальный возврат. | ⚙️ флаг + backfill | обработка возвратов в выручке |
| 4 | **Контроль дебиторки** ([PR #365](https://github.com/gasyoun/Systema-Sanscriticum/pull/365)) | ✅ **проверено 31-07-2026.** Пороги — консервативные дефолты кода (`revenue_share=0.5`, `illiquid_after_days=14`, `max_installment_share=30%`, `max_concurrent=50`), финдир их не переопределил отдельными значениями. `receivables:check` подтверждён в `schedule:list` (ежедневно 04:00). Прогон на реальных данных: 6 800 ₽ дебиторки из 231 016.5 ₽ 30-дневной выручки (~3 %) — «под контролем». | ⚙️ флаг + ⏱ | 🚀 настройка порогов на реальной дебиторке |
| 5 | **Фонды прибыли + KPI** ([PR #373](https://github.com/gasyoun/Systema-Sanscriticum/pull/373)) | ✅ **проверено 31-07-2026.** Доли — дефолты 60/20/10/10 (не переопределены). `finance:kpi-digest` подтверждён в `schedule:list` (по понедельникам 09:00). `--dry` прогон: все 4 карточки (A/B/C/D) зелёные — LTV/CAC 3.4, прибыль месяца 320 002 ₽ (маржа 52.7%), дебиторка 3% порога, резервный фонд покрывает 1100% кассой; уведомление не отправлялось (`--dry`). | ⏱ | недельный KPI-дайджест финдиру |
| 59 | **Атрибуция возвратов по ссылке в зачёте докупки** (H1405 C2, [PR #695](https://github.com/gasyoun/Systema-Sanscriticum/pull/695), MERGED) | миграций нет — только новый флаг. Когда финдир готов: `UPGRADE_CREDIT_REFUND_LINK=true` в `.env` → `php artisan config:clear` | ⚙️ флаг (деньги) | закрывает повторную потерю суммы возврата: ветка целого блока в `Tariff::upgradeRefundsForUser` видела только Расход-строки с покрывающим диапазоном, а админ-форма обнуляет диапазон при выборе «Расход» — реальный возврат за половину блока не уменьшал зачёт при докупке целого; когда флаг ВКЛ, возврат, привязанный через `refund_of_payment_id` к оплаченной половине, тоже уменьшает зачёт. **По умолчанию OFF — до включения поведение байт-в-байт прежнее** |
| 62 | **`payments.first_paid_at` + бэкфилл — закрытие слепого пятна resurrection-guard'а** (H1645, H1405 C3, [PR — см. changelog]) | (1) `php artisan migrate` (аддитивная nullable `payments.first_paid_at`, входит в общий прогон); (2) **сразу после миграции**, до любого нового трафика вебхука — dry-run: `php artisan payments:backfill-first-paid-at` (без записи, только отчёт: сколько бэкфилится + сколько останется без следа); (3) сверить отчёт — счётчик «остаток без следа» это ожидаемая, документированная потеря (платежи, оплаченные и полностью отменённые ДО 08-06-2026, не оставившие ни одной строки в `payment_audits`); (4) применить: `php artisan payments:backfill-first-paid-at --apply`; (5) `hasPriorPaidTransition()` работает автоматически — новый код уже проверяет `first_paid_at` first, никакого флага включать не нужно (see [money-access-core-manual.md §9 C3](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/money-access-core-manual.md)) | миграция + разовый backfill (деньги, no flag) | закрывает H1405 C3: resurrection-guard (`TOCHKA_WEBHOOK_GUARD`) теперь видит платежи, оплаченные-и-отменённые до 08-06-2026 (`payment_audits` не существовал) И платежи, созданные уже-оплаченными через `withoutEvents` (silent `PromiseFulfillment`, access-only siblings, conditional grants) — раньше не видел ни то, ни другое; **поведенческое изменение только при `TOCHKA_WEBHOOK_GUARD=true`** (существующий флаг №44, уже управляется финдиром) |

## Systema-Sanscriticum — SEO

| № | Что (PR) | Команды на проде | Тип | Что разблокирует |
|---|---|---|---|---|
| 6 | **SEO P2 Wave-1 — индексация** ([PR #374](https://github.com/gasyoun/Systema-Sanscriticum/pull/374)) | выкатить текущий `main` → включить `index_enabled` по списку «curated core» → отправить URL-слов в Яндекс.Вебмастер | флаг + внешнее | 🚀 замер индексации Wave-1 (нужны живые страницы + обход) |

## Systema-Sanscriticum — прочее (словарь, операционка)

| № | Что (PR) | Команды на проде | Тип | Что разблокирует |
|---|---|---|---|---|
| 7 | **Корпусная Sa→Ru глосса на `/slovar`** ([PR #372](https://github.com/gasyoun/Systema-Sanscriticum/pull/372)) | по желанию: `SLOVAR_ENRICHMENT=true` в `.env` → `php artisan config:clear` | флаг (дисплей, без денег) | обогащение страниц `/slovar/{slug}` глоссой; **по умолчанию OFF — до включения `/slovar` как раньше** |
| 19 | **Дашборд наблюдаемости саппорта + учет расходов на LLM** ([PR #469](https://github.com/gasyoun/Systema-Sanscriticum/pull/469) дашборд W3.3, [PR #476](https://github.com/gasyoun/Systema-Sanscriticum/pull/476) стоимость LLM — оба MERGED) | миграций нет — входит в обычный `sudo bash deploy.sh`; чтобы страница появилась в админке: `SUPPORT_OBSERVABILITY=true` в `.env` → `php artisan config:clear` (по умолчанию OFF, как `ATTENDANCE_DASHBOARD`). Цены OpenRouter уже зашиты дефолтами в `config/services.php`; переопределять `OPENROUTER_PRICE_DEEPSEEK_CHAT_PROMPT`/`..._COMPLETION` в `.env` нужно только если тариф изменится | флаг (дисплей/ops, без денег на стороне студента) | 🚀 живой дашборд: здоровье сессии, лаг синка, доля доставки, объём **и стоимость** LLM-обращений на реальных данных (нужен активный `schedule:run` из T1) — замена ручного мониторинга ops-метрик |
| 20 | **FAQ-суггестер v2 — LLM-черновики D/E/F (цена/доступ/материалы)** (H816 PR 1) | входит в общий `php artisan migrate` (аддитивная `marketing_settings.support_ai_daily_cap`, nullable); включение — те же рубильники, что у v1 A/B/C **плюс** LLM: (1) `SUPPORT_ANSWER_SUGGESTER=true` + `SUPPORT_AI_ASSIST=true` в `.env` → `php artisan config:clear`; (2) `OPENROUTER_API_KEY` уже задан (тот же ключ, что у ИИ-куратора/ассиста — если бот работает, ключ есть); (3) админ-тумблер `support_answer_suggester_enabled` включён в `MarketingSetting` (Filament); опц. дневной предел LLM-вызовов — поле `support_ai_daily_cap` (пусто → дефолт 100, `0` → без предела); (4) приватность ЛС: сырой текст импортированного Telegram-ЛС уходит во внешний LLM только при `SUPPORT_AI_INCLUDE_TELEGRAM=true` (по умолчанию OFF — черновик строится из одних фактов LMS) | миграция + флаги + LLM-расход | авто-черновик куратору на вопросы про **оплату/цену/тарифы (D, 7.4% FAQ), доступ/группу (E), материалы/ДЗ/сертификаты (F)**; бот НЕ отвечает сам — только pending-черновик в Helpdesk. **Всё OFF по умолчанию** — до включения флагов ничего не меняется |
| 24 | **Импорт демо-SRS-колоды из kosha** ([PR #519](https://github.com/gasyoun/Systema-Sanscriticum/pull/519), MERGED) | по желанию (демо): `KOSHA_SRS=true` в `.env` → `php artisan config:clear` → `php artisan srs:import-kosha-b1-demo` — создаёт одну системную Saraswati-SRS-колоду из вендорного фида `resources/data/kosha_srs_deck_b1_demo.json` (наши производные данные, НЕ живая зависимость от kosha) | флаг + разовая artisan-команда | демо-колода для SRS-тренажёра (Rung B1); **по умолчанию OFF — при выключенном флаге команда ничего не пишет и завершается с предупреждением; SRS-движок в целом всё ещё за `SRS_ENABLED=false`** |
| 26 | **RQ4-исследование: харнесс + консент + админ-статистика** ([PR #536](https://github.com/gasyoun/Systema-Sanscriticum/pull/536) харнесс, [PR #540](https://github.com/gasyoun/Systema-Sanscriticum/pull/540) админ-дашборд, [PR #539](https://github.com/gasyoun/Systema-Sanscriticum/pull/539) текст согласия, [PR #588](https://github.com/gasyoun/Systema-Sanscriticum/pull/588) сверка преflight + откат-контракт — все MERGED, релиз [v1.16.0](https://github.com/gasyoun/Systema-Sanscriticum/releases/tag/v1.16.0)) | **MG ruled GO 18-07-2026** ([H1261](https://github.com/gasyoun/Uprava/blob/main/handoffs/archive/H1261-Sonnet_Systema-Sanscriticum_rq4-study-go-live_18.07.26.md)) — preflight done 19-07 (11/11 `--filter=Rq4` tests green, code confirmed present). Отслеживание для Ивана: [issue #599](https://github.com/gasyoun/Systema-Sanscriticum/issues/599) (assignee — команды продублированы там же). Входит в общий `php artisan migrate` (аддитивные таблицы `rq4_participants`/`rq4_responses`); включение: `RQ4_STUDY=true` в `.env` → `php artisan config:clear` (по умолчанию OFF, `config('features.rq4_study')`); планировщик `rq4:send-retention-reminders` (ежедневно 09:00) уже прописан в `Kernel.php`, сработает сам после деплоя, если серверный cron вызывает `schedule:run` (та же зависимость, что №4/5/13/16/21); текст согласия УТВЕРЖДЁН MG (без правок) — отдельного шага нет; после включения флага админ-дашборд `/admin/rq4-study-dashboard` (admin/super_admin) показывает набор/сплит рук A/B/фазы диагностики. **Откат при сбое** (миграция упала / дубли участников / планировщик молчит / утечка когорты 28-08): `RQ4_STUDY=false` в `.env` → `php artisan config:clear` — набор мгновенно закрывается (route 404), уже собранные `rq4_participants`/`rq4_responses` НЕ трогать (данные исследования, не откатывать миграцией). **Смок-тест после включения** (одноразовый тестовый аккаунт, не из марафонской когорты 28-08): `/rq4-study` открывает согласие → анкету → распределение по руке; `/admin/rq4-study-dashboard` показывает нового участника | миграция + флаг + планировщик | 🚀 запуск RQ4-исследования (on-ramp-first vs Talmud-first) — набор реальных участников и замер на живых данных; **всё OFF по умолчанию — до включения флага `RQ4_STUDY` ничего не видно** |
| 27 | **Комм-пакет марафона 28-08 — лендинг/письма/TG-посты** ([PR #544](https://github.com/gasyoun/Systema-Sanscriticum/pull/544); расписание [PR #872](https://github.com/gasyoun/Systema-Sanscriticum/pull/872)) | **После деплоя кода (вариант A по умолчанию на `/online/konsultaciya`):** (1) `php artisan marathon:apply-landing-copy a` — upsert `LandingPage` слага `konsultaciya-po-onlayn-kursam`; позже B: `MARATHON_LANDING_COPY_VARIANT=b` + `config:clear` + `php artisan marathon:apply-landing-copy b`; (2) TG-посты: магнит-бот (`MarketingSetting.tg_bot_*`, обычно `@samskrte`) **админ канала** @samskrte. Посты 1/2/3 (анонс 14-08, старт 28-08, evergreen с 04-09) **уже в `Kernel::schedule()`** ([PR #872](https://github.com/gasyoun/Systema-Sanscriticum/pull/872), идемпотентно) — при `schedule:run` + админстве бота уходят сами; dry-run/ручной: `php artisan marathon:publish-channel-posts --post=1` / `--live`. Посты 4–5 вручную; (3) письма — всё ещё ESP [#504](https://github.com/gasyoun/Systema-Sanscriticum/issues/504) / №37; (4) `{testimonial}` / пост 5 — только с `MARATHON_TESTIMONIAL` | деплой кода + 2 artisan-команды + права бота в канале | продающий контур когорты 28-08: A live first, B second window |
| 27a | **Письма марафона — код-путь готов (H1148)** ([PR — см. changelog]) | пять `Marathon*Mail` + шаблоны + тест влиты; **отправка сознательно не подключена** — блокер теперь ТОЛЬКО ESP-гейт (H1147: вендор + аккаунт + прод-секрет), не авторство и не код | код, деплой не требует действий | зависимость №27 (3): письма из черновиков стали готовыми к подключению Mailable |
| 31 | **Гео/город посетителя веб-чата — Jivo-паритет S1, Pillar 1** (H1196, [PR #557](https://github.com/gasyoun/Systema-Sanscriticum/pull/557)) | входит в общий `php artisan migrate` (аддитивные `support_conversations.visitor_ip/city/region/country/geo_resolved_at` + `entry_url`/`referrer`, все nullable); `entry_url`/`referrer` пишутся сразу после деплоя БЕЗ флага. Чтобы куратор видел ГОРОД: (1) выбрать провайдера — `SUPPORT_GEO_DRIVER=cloudflare` (если сайт за Cloudflare — ноль внешних вызовов) или `ipapi` (ip-api.com, но НЕкоммерческая лицензия + HTTP-only) в `.env`; (2) `SUPPORT_VISITOR_GEO=true`; (3) `php artisan config:clear`; нужен работающий воркер очереди (`ResolveVisitorGeoJob` идёт через ту же очередь, что Horizon). Провайдер города — @DECIDE MG (рекоменд. MaxMind GeoLite2 локально — драйвер-стаб). 📋 **Ruling-ready бриф: [docs/BRIEF_PRESENCE_152FZ_GEO_PROVIDER_ADJUDICATION_2026-07.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/BRIEF_PRESENCE_152FZ_GEO_PROVIDER_ADJUDICATION_2026-07.md) (H1234)** — `ipapi` лицензионно негоден (живая проверка) + противоречит §1.5 политики; MaxMind-драйвера пока нет | миграция + флаг (дисплей, без денег) | куратор видит «из какого города пишет посетитель» + страницу входа — визитор-слой Jivo; **по умолчанию OFF / драйвер `null` — до включения город не запрашивается, пишутся только IP/страница входа** |
| 32 | **Проактивный монитор посетителей + оператор пишет первым — Jivo-паритет S2, Pillar 2** (H1197) | входит в общий `php artisan migrate` (новая таблица `support_visitor_presences`, аддитивно). Чтобы включить: (1) `SUPPORT_VISITOR_PRESENCE=true` в `.env`; (2) `php artisan config:clear`. Город в списке появится, только если ещё и `support_visitor_geo` включён с драйвером ≠ null (см. №31). Нужен рабочий воркер очереди (гео-джоба идёт через Horizon) и работающий cron `schedule:run` (выметание устаревших строк каждые 5 мин). **@DECIDE MG — юридический sign-off 152-ФЗ:** presence отслеживает анонимного посетителя (город + поведение на сайте) — включать сознательно, с согласием (cookie-баннер уже есть); IP наружу оператору не светим. 📋 **Ruling-ready бриф: [docs/BRIEF_PRESENCE_152FZ_GEO_PROVIDER_ADJUDICATION_2026-07.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/BRIEF_PRESENCE_152FZ_GEO_PROVIDER_ADJUDICATION_2026-07.md) (H1234)** — политика §5.4 покрывает IP/referrer/время, но не гео-город/поведенческий мониторинг/категорию «посетитель»; точные вопросы юристу внутри | миграция + флаг (без денег) | куратор видит живой список посетителей на сайте сейчас и может **написать первым** (страница «Посетители онлайн» в админке) — второй уникальный столп Jivo; **по умолчанию OFF — до включения presence не пишется, страница скрыта, виджет как прежде** |
| 46 | **In-video resume — «продолжить с HH:MM»** (H1450, Anton ops-gaps W2) | входит в общий `php artisan migrate` (аддитивные `lesson_views.last_position_seconds`/`max_position_seconds`/`video_duration_seconds`, все nullable — ничего для старых строк не меняет). Включение: `VIDEO_RESUME=true` в `.env` → `php artisan config:clear`. Работает для YouTube и RuTube (единственные хосты, реально рендерящиеся в плеере урока сегодня) — сохраняет позицию через уже существующий `POST /api/heartbeat`, не требует нового вебхука/эндпоинта. Адаптеры для VK/Kinescope/Vimeo лежат в `public/js/video-resume.js` про запас (переиспользует W3 Kinescope-пилот), но пока не подключены ни к одному реальному плееру — деградируют в no-op | миграция + флаг (без денег) | студент видит баннер «продолжить с HH:MM» при повторном открытии урока; **по умолчанию OFF — до включения флага плеер ведёт себя ровно как раньше, heartbeat не шлёт позицию** |
| 48 | **Kinescope pilot — один флагманский курс** (H1451, Anton ops-gaps W3) | миграций нет. **После выката код инертен** (`KINESCOPE_PILOT=false`). Активация (human): (1) аккаунт Kinescope + выбрать `Course.id` флагмана; (2) у уроков пилота прописать `video_url` = `https://kinescope.io/...`; (3) `KINESCOPE_PILOT_COURSE_ID=<id>` + `KINESCOPE_PILOT=true` в `.env` → `php artisan config:clear`; (4) опц. `VIDEO_RESUME=true` (№46) чтобы проверить native resume через W2 SDK-адаптер; (5) сверка на 1–2 уроках + обновить [docs/KINESCOPE_PILOT_COMPARISON_2026.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/KINESCOPE_PILOT_COMPARISON_2026.md) живыми цифрами. **Каталог не мигрировать** (D4). | флаг + course id (без денег) | Kinescope как first-class host только на одном курсе; **по умолчанию OFF — остальные курсы/хосты без изменений** |
| 49 | **n8n lecture content engine — Wave 1: ContentCandidate + ranked-span clips** (H1547, [docs/PLAN_SYSTEMA_N8N_LECTURE_CONTENT_ENGINE_2026H2.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/PLAN_SYSTEMA_N8N_LECTURE_CONTENT_ENGINE_2026H2.md)) | входит в общий `php artisan migrate` (аддитивная `content_candidates`, nullable FKs). **После выката код инертен**: `CONTENT_FROM_LECTURES=false` (LessonObserver не диспатчит нарезку) — но `ContentCandidateSync` мирроринг LectureClip→ContentCandidate работает ВСЕГДА (дешёвая идемпотентная запись, не публикация). Активация: (1) убедиться, что №47 (`CLIP_MARKETING_ENABLED`) уже включён и n8n-пайплайн живой — Wave 1 диспатчит через тот же `DispatchLectureClipExtractionJob`, теперь top-5 ranked spans вместо всего пака; (2) `CONTENT_FROM_LECTURES=true` в `.env` → `php artisan config:clear`; (3) `CONTENT_CLIP_RANK_N` опционально переопределяет top-N (по умолчанию 5); (4) первый дневник-тест — опубликовать одну лекцию с транскриптом, проверить, что диспатчится ровно 1 раз (идемпотентность по наличию `LectureClip` строк); (5) Filament «Контент-кандидаты» (видим только при флаге) — куратор смотрит зеркало клипов, помечает свободные | миграция + флаг (без денег) | Wave 1 of 5 (social/faq/article/study — waves 2–5, отдельные handoffs H1548-H1551); **по умолчанию OFF — до флага диспатч не идёт, только идемпотентный clip→candidate mirror** |
| 50 | **VK/ORS content calendar — Wave 1: slots + Filament** (H1564, [docs/PLAN_SYSTEMA_VK_ORS_CONTENT_CALENDAR_2026H2.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/PLAN_SYSTEMA_VK_ORS_CONTENT_CALENDAR_2026H2.md)) | входит в общий php artisan migrate (аддитивные content_calendar_slots + content_candidates.calendar_slot_id). **После выката код инертен**: CONTENT_CALENDAR_ENABLED=false — Filament «Календарь контента» скрыт. Активация (staging): (1) CSV из IndologyScholars vk-ors/data/processed/ → storage/app/vk_ors/ или php artisan content:import-vk-ors --path=<dir> --force; (2) CONTENT_CALENDAR_ENABLED=true → php artisan config:clear; (3) php artisan content:seed-month YYYY-MM (≥20 слотов); (4) **human Filament smoke**: Маркетинг → Календарь контента, Keep/Cancel; (5) CONTENT_CALENDAR_AUTOPILOT остаётся false до H1568. | миграция + флаг (без денег) | месячный календарь ВК; **по умолчанию OFF** |
| 51 | **n8n lecture content engine — Wave 2: social pilot (VK wall + TG mirror) + email scaffold** (H1548, тот же PLAN, D13/D14) | входит в общий `php artisan migrate` (без новых таблиц — расширяет `content_candidates` из №49). **После выката код инертен**: `CONTENT_AUTO_PUBLISH_PILOT=false` → `PublishSocialPostJob` ранний возврат; `CONTENT_EMAIL_ONESHOT=false` → `SendContentOneShotMailJob` ранний возврат. Curator-flow: отметить клип бесплатным (№49) → `ContentCandidateObserver` авто-черновит social_post → куратор жмёт «Принять» в Filament «Контент-кандидаты» → джоба ставится в очередь (сама себя гасит флагом). Активация пилота: (1) №49 уже включён; (2) новый n8n-вебхук `docs/n8n/` (по образцу `lecture-clip-extract`) — принимает `{action: social_post, text_vk, text_tg, vk_video_id, vk_owner_id}`, публикует `wall.post` с видео + зеркалит в ТГ-канал; (3) `N8N_SOCIAL_POST_WEBHOOK`/`N8N_SOCIAL_POST_SECRET` в `.env`; (4) `CONTENT_AUTO_PUBLISH_PILOT=true` → `php artisan config:clear`; (5) dry-run на одном принятом черновике, сверить пост в ВК+ТГ. Email-дайджест (август, зависит от живого SMTP #504/H1449): `php artisan content:compose-weekly-digest` → куратор принимает `email_blast` в Filament → `CONTENT_EMAIL_ONESHOT=true` шлёт подписчикам `newsletter_subscribed_at` (H324) | флаг × 2 + новый n8n-вебхук (без денег) | Wave 2 of 5 (FAQ/article/study — waves 3–5, H1549-H1551); **по умолчанию OFF — принятие черновика в Filament никогда не публикует само по себе, джобы гасят себя флагом** |
| 53 | **n8n lecture content engine — Wave 3: FAQ drafts from lecture transcripts** (H1549, тот же PLAN, D3) | миграций нет (type=`faq_draft` уже в `content_candidates` из №49). **После выката код инертен**, пока `CONTENT_FROM_LECTURES=false` (LessonObserver не черновит FAQ). Accept в Filament **сразу** дописывает `resources/knowledge/faq_from_lectures.md` (sibling ORS-export `faq.md` — его не трогаем) и помечает кандидата `published` — это и есть knowledge-publish; публичного auto-publish FAQ нет. Активация: (1) №49 (`CONTENT_FROM_LECTURES=true`) уже включён; (2) `php artisan content:compose-faq-drafts {lesson_id}` на одной лекции со стенограммой (или дождаться publish-триггера); (3) куратор правит/принимает `faq_draft` в «Контент-кандидаты»; (4) смоук: файл `faq_from_lectures.md` на проде содержит `###` блок + `BotKnowledgeBase` подхватывает (кэш 10 мин). Опц. `CONTENT_LECTURE_FAQ_PATH` для нестандартного пути | флаг из №49 + artisan (без денег) | Wave 3 of 5 (article/study — H1550–H1551); **черновики только при `CONTENT_FROM_LECTURES`; Accept = knowledge write, без отдельного publish-флага** |
| 54 | **n8n lecture content engine — Wave 4: long-form article drafts** (H1550, тот же PLAN, D15/D19) | миграций нет (type=`article` уже в `content_candidates` из №49). **После выката код инертен**, пока `CONTENT_FROM_LECTURES=false` (LessonObserver не черновит статьи). `ArticleDraftGenerator` + artisan `content:compose-article-drafts` / `--weekly`; body capped (MAX_BODY_CHARS + ratio vs transcript); QuotePolicy on quote; draft-only Filament — **нет auto-publish** для article (Accept = editorial only). Активация: (1) №49 (`CONTENT_FROM_LECTURES=true`); (2) `php artisan content:compose-article-drafts {lesson_id}` или `--weekly`; (3) куратор правит type=`article` в «Контент-кандидаты»; (4) опц. экспорт markdown через generator `toMarkdown`. Body может кормить August `email_blast` (W2 mailer) — send only when `CONTENT_EMAIL_ONESHOT` (№51) | флаг из №49 + artisan (без денег) | Wave 4 of 5 (study — H1551); **черновики только при `CONTENT_FROM_LECTURES`; full transcript never in body** |
| 55 | **n8n lecture content engine — Wave 5: student study artifacts** (H1551, тот же PLAN) | миграций нет (type=`study_artifact` уже в `content_candidates` из №49). **После выката код инертен**, пока `CONTENT_FROM_LECTURES=false` (LessonObserver не черновит study). `StudyArtifactGenerator` + artisan `content:compose-study-artifacts`; 3 кандидата на урок (summary / card_seeds / homework), channel `staff_study`; Accept = editorial only — **нет pilot auto-publish** (observer no-op; publish jobs type-guard only social_post/email_blast). Активация: (1) №49 (`CONTENT_FROM_LECTURES=true`); (2) `php artisan content:compose-study-artifacts {lesson_id}`; (3) куратор правит type=`study_artifact` в «Контент-кандидаты»; (4) опц. позже — student cabinet surface (out of W5). | флаг из №49 + artisan (без денег) | Wave 5 of 5 (final product PR of the engine); **staff-first; full transcript never in body; no public channels** |
| 56 | **VK/ORS content calendar — Wave 2: evergreen recycle** (H1565, тот же PLAN что №50) | миграций нет (переиспользует `content_calendar_slots`/`content_candidates` из №50). **После выката код инертен** — `content:fill-evergreen` ничего не делает без флага (или без `--force-flag` в CI). Активация: (1) №50 уже включён (`CONTENT_CALENDAR_ENABLED=true`, CSV импортированы, `content:seed-month` уже создал `draft` evergreen-слоты); (2) `php artisan content:fill-evergreen YYYY-MM` — заполняет пустые evergreen-слоты verbatim текстом топ-постов (лайки DESC, возраст ≥12 мес, тема ∈ {книга,словарь,pdf,текст}, дедуп ±6 мес), сразу переводит их в `scheduled` (skip-review D12 — evergreen не NEW copy); (3) **human Filament smoke**: Маркетинг → Календарь контента, проверить, что evergreen-слоты заполнены телом + датой публикации, Cancel работает; (4) `CONTENT_CALENDAR_AUTOPILOT` остаётся `false` до H1568 — реальной публикации в ВК ещё нет. | artisan-команда, флаг из №50 (без денег) | Wave 2 of 5 (Systema bridge/forward/autopilot — waves 3–5, H1566–H1568); **по умолчанию OFF — командой без флага/force-flag ничего не заполняется** |
| 57 | **VK/ORS content calendar — Wave 4: forward drafts** (H1567, тот же PLAN что №50) | миграций нет (переиспользует `content_calendar_slots`/`content_candidates` из №50). **После выката код инертен** — `content:fill-forward` ничего не делает без флага (или без `--force-flag` в CI). Активация: (1) №50 уже включён; (2) `php artisan content:fill-forward YYYY-MM` — заполняет пустые `forward`-слоты NEW-текстом (ротация 4 шаблонов: читательский клуб, слово дня из словаря, анонс ближайшего занятия, FAQ-заметка из безопасной секции «Новичкам»), переводит слот `empty → draft` (**никогда `scheduled`** — D12 skip-review, NEW copy ждёт ручного Keep); (3) **human Filament smoke**: Маркетинг → Календарь контента, куратор правит/принимает draft-слоты вручную (Keep → scheduled); (4) опц. `CONTENT_FORWARD_DRAFT_MAX_PER_RUN` (по умолчанию 10) — cost cap на число CuratorAi-вызовов за прогон. | artisan-команда, флаг из №50 (без денег) | Wave 4 of 5 (autopilot — wave 5, H1568); **по умолчанию OFF; NEW copy никогда не публикуется без ручного Keep** |
| 58 | **VK/ORS content calendar — Wave 3: Systema bridge** (H1566, тот же PLAN что №50) | миграций нет (переиспользует `content_calendar_slots`/`content_candidates` из №50). **После выката код инертен** — `content:bridge-systema` ничего не делает без флага (или без `--force-flag` в CI). Активация: (1) №50 уже включён (`CONTENT_CALENDAR_ENABLED=true`, `content:seed-month` уже создал пустые `clip_tease`-слоты); (2) `php artisan content:bridge-systema YYYY-MM` — заполняет пустые `clip_tease`-слоты свободными (`is_free=true`) `LectureClip`, ссылка на VK-видео в `meta.link` если `vk_owner_id`/`vk_video_id` уже проставлены n8n; для **текущего** месяца создаёт (идемпотентно) один `event`-слот, **переиспользуя существующий `MonthlyScheduleDigest`** (тот же сервис, что живой постер `schedule:post-monthly`) — те же курсы/расписание/текст, что и в живом посте, вместо повторного запроса к `Schedule` (иначе рисковали бы показать скрытый/неактивный курс). Для не-текущего месяца дайджест — no-op (ограничение самого `MonthlyScheduleDigest`, `Carbon::now()`). Оба сразу `scheduled` (skip-review D12 — Systema-сигналы не NEW copy). Только календарные строки, **ffmpeg/VK-аплоад по-прежнему делает n8n** (H1452) — бридж не вызывает VK API. (3) **human Filament smoke**: Маркетинг → Календарь контента, проверить, что clip_tease/event-слоты заполнены + Cancel работает; (4) `CONTENT_CALENDAR_AUTOPILOT` остаётся `false` до H1568 — реальной публикации в ВК ещё нет. **Дефолт-лог (D22):** по-занятийный `schedule_note` (change-tracking) и `faq_tease` (FAQ Accept) не подключены W3 — ни один DoD-критерий (C1/C2) их не требует, отдельный follow-up при необходимости. | artisan-команда, флаг из №50 (без денег) | Wave 3 of 5 (forward/autopilot — waves 4–5, H1567–H1568); **по умолчанию OFF — командой без флага/force-flag ничего не заполняется** |
| 60 | **VK/ORS content calendar — Wave 5: auto-pilot** (H1568, тот же PLAN что №50, closes the 5-wave series) | миграций нет (переиспользует `content_calendar_slots`/`content_candidates` из №50). **После выката код инертен** — `content:publish-due` (запланирована ежечасно в `Kernel.php`) молча no-op, пока `content_calendar_autopilot` OFF. Активация (staging first, **no live VK posts until reviewed**): (1) №50/56/57/58 уже включены, слоты доведены до `scheduled` (Keep вручную или skip-review Systema/evergreen); (2) импортировать n8n-воркфлоу [`docs/n8n/vk-calendar-post.workflow.json`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/n8n/vk-calendar-post.workflow.json), прописать `REPLACE_VK_GROUP_ID`/`REPLACE_VK_GROUP_TOKEN` (community-токен со скоупом `wall`); (3) `N8N_CALENDAR_POST_WEBHOOK`/`N8N_CALENDAR_POST_SECRET` в `.env`; (4) только после смоука на staging: `CONTENT_CALENDAR_AUTOPILOT=true` → `php artisan config:clear`; (5) ручной прогон одного тика `php artisan content:publish-due` на 1 due-слоте, сверить пост в ВК. Cancel-окно (24ч до `publish_at`) уже работало с W1 в Filament — эта волна добавляет только прямое юнит-покрытие границы. Неудачный ответ n8n не роняет слот в тишину — статус остаётся `scheduled` и повторится на следующем часовом тике. | artisan-команда + n8n import + `.env` + ⚙️ `CONTENT_CALENDAR_AUTOPILOT` (без денег) | 🚀 замыкает 5-волновую серию (H1564–H1568) — реальная авто-публикация в ВК; **по умолчанию OFF — до флага и настройки вебхука публикация не идёт** |
| 52 | **Гибридный кабинет R29 — Phase 4 flag flip** (H1582; код Phases 1–3: [PR #673](https://github.com/gasyoun/Systema-Sanscriticum/pull/673)/[#674](https://github.com/gasyoun/Systema-Sanscriticum/pull/674)/[#678](https://github.com/gasyoun/Systema-Sanscriticum/pull/678); pack [docs/CABINET_HYBRID_PHASE4_RELEASE_PACK_2026.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/CABINET_HYBRID_PHASE4_RELEASE_PACK_2026.md)) | миграций нет. **После выката код инертен** (`CABINET_HYBRID=false`). **Не включать до R20:** (1) baseline Phase 0 ≥14 дней с 21-07-2026 (№25) — `php artisan cabinet:hybrid-readiness` + `cabinet:baseline --days=14`; (2) staging walkthrough pack §3; (3) **MG `@DECIDE` GO**. Активация: `CABINET_HYBRID=true` в `.env` → `php artisan config:clear` (или `config:cache`). Смоук: студент → Сегодня с job-nav; `/library`/`/progress`/`/access` 200; recovery не ловит bare pending. **Откат:** `CABINET_HYBRID=false` → `config:clear`. Post-flip: `cabinet:baseline --days=7` / `--days=14` vs pre-flip snapshot | ⚙️ флаг (UI money-adjacent offer suppression; access/money core не трогает) | 🚀 студенческий кабинет R29 hybrid live; **по умолчанию OFF — legacy dashboard до флага** |
| 61 | **Online Sanskrit games Wave 1 — 5-play gate + CTA→register KPI + 3 P0 пака** (H1678, [PR — см. changelog]) | входит в общий `php artisan migrate` (аддитивный индекс `game_events(anon_id, created_at)` — только производительность, ничего не меняет по данным). Флагов нет — код и статика активны сразу после деплоя: гейт `/lila/*` расширен с 1 бесплатной игры на **5 на семейство тренажёра** (`localStorage` ключ `sgx_plays_v2`, без миграции возвращающихся посетителей со старого ключа — им начинают идти свежие 5 игр, ожидаемо мягче прежнего); три новых пака сразу доступны (`/lila/sort/vowel-length/`, `/lila/match/iast-cyrillic/`, `/lila/match/kochergina-l1/`); `games:funnel --days=N` теперь показывает строку «CTA -> регистрация» (использует существующий `authenticated`-флаг, `user_id`-колонки нет и не будет — приватный контракт H1360/152-ФЗ не менялся). Смок-тест рекомендован: `games:funnel --days=1`, открыть три новых пака | миграция (индекс), без флага | 🚀 Wave 1 воронки бесплатных тренажёров: шире бесплатный доступ (5 игр/семейство) + первое измерение play→register KPI (цель ≥15% при выборке ≥50 CTA-кликов, D6); P1-паки (H1679) и SRS-онбординг (H1680) — отдельные будущие handoffs, здесь не включены |

| 33 | **Веб-чат: контекстное приветствие + FAQ-суггестер для гостей — Jivo-паритет S3** ([PR #559](https://github.com/gasyoun/Systema-Sanscriticum/pull/559), MERGED) | входит в общий `php artisan migrate` (аддитивная `support_answer_suggestions.user_id` nullable); **нового флага нет** — работает под существующим `SUPPORT_ANSWER_SUGGESTER=true` (`features.support_answer_suggester`, деплой-конфиг) + админ-тумблер `support_answer_suggester_enabled` в `MarketingSetting` (Filament), по умолчанию OFF → `php artisan config:clear`. Контекстное приветствие по странице входа — клиентское, без флага, работает сразу после выката | миграция + код (флаг существующий) | гостю на странице курса/оплаты — приветствие по контексту; куратору — черновик-подсказка по цене (категория D, публичные тарифы, без LLM) на гостевые треды; **бот не отвечает сам**. Пока `support_answer_suggester` OFF — суггестер молчит |
| 34 | **Веб-чат: захват лида (телефон/почта) + оффлайн-копирайт — Jivo-паритет S4** ([PR #562](https://github.com/gasyoun/Systema-Sanscriticum/pull/562), MERGED) | входит в общий `php artisan migrate` (аддитивная `support_conversations.lead_captured_at` nullable); включение: `SUPPORT_LEAD_CAPTURE=true` (`features.support_lead_capture`) в `.env` → `php artisan config:clear`; деловые часы для оффлайн-копирайта — `config/support_hours.php`. Контакты остаются необязательными (отправку не блокируют ни онлайн, ни офлайн) | миграция + флаг (без денег) | необязательные телефон/почта в веб-виджете → Lead-строка с UTM (реюз newsletter-паттерна H324, дедуп по email, идемпотентно на тред); вне деловых часов — оффлайн-копирайт «оставьте e-mail». **По умолчанию OFF — до флага поля контактов скрыты** |
| 37 | **ESP транспорт + `mail:preflight` — гейт #504** (H1147) | 🚀 после `sudo bash deploy.sh` (тянет `composer install`, подхватит `symfony/mailgun-mailer`/`symfony/postmark-mailer`): (1) **сначала** — человеческое решение: выбрать ESP-вендора (Unisender/SendGrid/Mailgun-класс), завести аккаунт, получить SPF+DKIM+DMARC на домен отправителя (см. [docs/mail-esp.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/mail-esp.md)) — это `@DECIDE MG`, ни один агент не может завести аккаунт/держать секрет; (2) прописать ключи `.env` по выбранному варианту (см. таблицу в `docs/mail-esp.md`) → `php artisan config:clear`; (3) `php artisan mail:preflight` — должен выйти с кодом 0 (иначе прод всё ещё унаследовал `mailpit`/placeholder-отправителя — команда назовёт причину); (4) `php artisan mail:preflight --send=<свой-адрес>` — один реальный отправленный имейл, подтверждает доставку в реальный ящик, а не только конфиг | код + `.env`-ключи (флагов/миграций нет) | 🚀 разблокирует [#504](https://github.com/gasyoun/Systema-Sanscriticum/issues/504) (студентка не может сбросить пароль — почта проглатывается `mailpit`) и очередь `mailing` из №27a (H1148, пять писем марафона уже влиты и ждут именно этого гейта) |
| 38 | **Денежная страховка чекаута — 5 флагов + аудит-команда** ([PR #574](https://github.com/gasyoun/Systema-Sanscriticum/pull/574) неактивный тариф, [PR #576](https://github.com/gasyoun/Systema-Sanscriticum/pull/576) блокировка реф-кошелька, [PR #579](https://github.com/gasyoun/Systema-Sanscriticum/pull/579) реверс депозита, [PR #581](https://github.com/gasyoun/Systema-Sanscriticum/pull/581) ёмкость промокодов, [PR #582](https://github.com/gasyoun/Systema-Sanscriticum/pull/582) аудит целостности — все MERGED) | входит в общий `php artisan migrate` (аддитивная `payments.payment_link_expires_at`, nullable). **Все пять флагов OFF по умолчанию — после деплоя на проде НИЧЕГО не меняется**, пока каждый не включён вручную. Включать по одному, с согласованием финдира и сверкой на реальном платеже, каждый раз `php artisan config:clear` после правки `.env`: (1) `CHECKOUT_INACTIVE_TARIFF_GUARD=true` — выключенный тариф отдаёт 404 до создания платежа и банковской ссылки; (2) `CHECKOUT_REFERRAL_CREDIT_LOCK=true` — списание реферального кредита под row-lock (защита от двойного списания); (3) `CHECKOUT_DEPOSIT_REVERSAL=true` — при переходе оплаты в failed/canceled депозитный зачёт возвращается (LIFO); (4) `CHECKOUT_PROMO_RESERVATIONS=true` — жёсткая ёмкость промокодов (живой pending держит слот до истечения ссылки Точки); (5) `CHECKOUT_INTEGRITY_SAFE_REPAIRS=true` — разрешает единственную авто-правку команде ниже. **Диагностика без флагов** (только чтение, безопасно в любой момент): `php artisan payments:audit-checkout-integrity` — покажет расхождения; с `--apply-safe` пересчитает `promo.used_count` из оплаченных платежей (требует флага 5). Отрицательные кошельки, исторические депозиты и legacy-pending команда не трогает никогда | миграция + ⚙️ 5 флагов (деньги) | защита денежного контура чекаута: продажа по выключенному тарифу, гонка реферального кошелька, невозвращённый депозит при отмене, перерасход промокода сверх лимита; **пока флаги OFF — только новая колонка в БД, поведение прежнее** |
| 64 | **Memrise 6679375 «Продлёнка» → SRS + teacher authoring (H1993 / H2049)** | После авто-деплоя PR: (1) `php artisan srs:import-memrise database/seeders/data/memrise_6679375 --dry-run` → `php artisan srs:import-memrise database/seeders/data/memrise_6679375` (idempotent; 10 decks / 166 cards); (2) user id **5834** Трефилова: `role=teacher`, `teacher_id=7` (уже Teacher #7, 7 курсов); (3) smoke: Filament `/admin` под ней → **Уроки** + **SRS — колоды/карточки**. `SRS_ENABLED` уже true на проде. | seed + artisan + user role | 🚀 контент «Продлёнки» в SRS; Елена правит уроки и карточки сама |
| 39 | **SRS-колода «Корни санскрита по частотности»** (H1280, D4) | миграций нет (использует существующие `srs_*`-таблицы, H211); разовая команда после выката: `php artisan db:seed --class="Database\\Seeders\\SrsRootFrequencyDeckSeeder"` — сеет 570 карточек корней (порядок вставки = ранг по частотности DCS) + логирует 59 корней без RU-глоссы в вывод команды (не молча отбрасывает). Идемпотентно — повторный прогон обновляет карточки на месте, не дублирует. Колода видна студентам только при уже существующем `SRS_ENABLED=true` (см. №30, по умолчанию OFF — R-6 baseline protection) | код + разовая artisan-команда (миграций/флагов нет) | новая системная колода в существующем SRS-тренажёре; **пока `SRS_ENABLED=false` — ничего не меняется на проде**, сеятель можно прогнать заранее без риска для R20-baseline |
| 41 | **Telegram Track C — интеграция `@zapisi_ORSbot`** (H164, D7–D11) | входит в общий `php artisan migrate` (аддитивные `marketing_settings.zapisi_*` + новая `zapisi_class_schedules`). Дальше — только `.env`/админка, не код: (1) отключить privacy mode боту через [@BotFather](https://t.me/BotFather) (D8b); (2) в админке (Marketing settings) заполнить username/token/webhook-секрет/chat id бота — chat id узнаётся через `php artisan telegram-harvest:peers` (нужен уже настроенный Track B — `TELEGRAM_SUPPORT_*`); (3) добавить обнаруженный chat id в `TELEGRAM_HARVEST_PEERS` и зарегистрировать вебхук `https://<домен>/api/webhooks/telegram-zapisi` у Telegram (`setWebhook`); (4) `TELEGRAM_ZAPISI_BOT_ENABLED=true` в `.env` → `php artisan config:clear`; (5) один раз `php artisan telegram-harvest:roster <chat_id>` — снимок состава чата (D9) | миграция + ⚙️ флаг + `.env`-ключи + внешний шаг (@BotFather/webhook) | дашборд-вкладка для бот-чата бронирования занятий (состав + сообщения/медиа + расписание напоминаний); **пока флаг OFF — вебхук отвечает, но `zapisi:send-reminders` ничего не шлёт** |
| 44 | **Защита денежного вебхука Точки — журнал доставок + гуарды воскрешения/суммы** (H1359, [PR — см. changelog]) | входит в общий `php artisan migrate` (аддитивная `payment_webhook_events`, только запись). **Флаг OFF по умолчанию — после деплоя поведение вебхука прежнее**, но журнал доставок пишется всегда (аддитивно). Включать с согласованием финдира: `TOCHKA_WEBHOOK_GUARD=true` в `.env` → `php artisan config:clear` — тогда включаются три отказа: (a) повтор доставки (`event_hash`) → 200-no-op; (b) success для оплаченного-и-затем-отменённого платежа → отказ (нет воскрешения доступа/депозита/промо/реферала); (c) сумма банка, расходящаяся с суммой заказа сверх `CHECKOUT_WEBHOOK_AMOUNT_TOLERANCE` (дефолт 1.00 ₽) → отказ. **Диагностика без флага** (только чтение, безопасно в любой момент): `php artisan payments:audit-checkout-integrity` теперь показывает блок «Rejected webhook deliveries» | миграция + ⚙️ флаг (деньги) | закрывает воскрешение отменённого платежа повторным/переигранным success-вебхуком (двойная выдача доступа, повторный зачёт депозита, двойное списание промо-слота, повторная реф-награда) + выдачу при расхождении суммы; **пока флаг OFF — только новая таблица-журнал, поведение прежнее** |
| 47 | **Lecture clip marketing pipeline — Wave 4 (H1452)** — инструкция для Ивана: [issue #666](https://github.com/gasyoun/Systema-Sanscriticum/issues/666) ([PR — after merge]) | входит в общий `php artisan migrate` (аддитивная `lecture_clips`). **После выката код инертен**: `CLIP_MARKETING_ENABLED=false` → job early-return, callback 404, Filament resource hidden. Активация (staging first, **no live VK posts until reviewed**): (1) import n8n workflow [`docs/n8n/lecture-clip-extract.workflow.json`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/n8n/lecture-clip-extract.workflow.json) and wire real ffmpeg worker + VK Video/Clips upload (community token with Video/Wall scopes — human-owned app); (2) set `N8N_CLIP_EXTRACT_WEBHOOK`, `N8N_CLIP_EXTRACT_SECRET`, `N8N_CLIP_CALLBACK_SECRET` in Laravel `.env` (same callback secret in n8n env); (3) migrate if not applied; (4) dry-run one published lesson with AI transcript via Filament «Нарезать лекцию» or `DispatchLectureClipExtractionJob`; (5) staff mark ~3 `is_free` in «Клипы лекций»; (6) only then `CLIP_MARKETING_ENABLED=true` → `php artisan config:clear`. Editorial free-3 policy is a human content decision. | миграция + n8n import + `.env` + ⚙️ `CLIP_MARKETING_ENABLED` | 🚀 Anton-style self-feeding short clips from existing lecture AI timecodes; **flag OFF by default — until flipped, only the table + dead routes exist** |
| 45 | **Transactional email revival + homegrown campaign engine — Wave 1 (H1449)** ([PR — after merge]) | входит в общий `php artisan migrate` (аддитивные `suppressed_emails` + `campaigns` + `campaign_recipients`). **После выката на прод код инертный**: `EMAIL_CAMPAIGNS=false` (дефолт) → `CampaignResource` скрыт, `/e/o`/`/e/c` 404, `CampaignSender` early-return; throttle + `SuppressedEmail` guards работают на *весь* исходящий mail. Активация (staging first, no bulk live send until SPF/DKIM proven): (1) mailbox SMTP per D6 — set `MAIL_MAILER=smtp` + `MAIL_HOST`/`PORT`/`ENCRYPTION`/`USERNAME`/`PASSWORD` + `MAIL_FROM_*` for mail.ru or Yandex 360 (placeholders in `.env.example`, never commit real creds); (2) SPF + DKIM + DMARC on the sending domain; (3) optional `MAIL_THROTTLE_PER_MINUTE=30` (default already 30); (4) optional bounce scan: `MAIL_BOUNCE_SCAN_ENABLED=true` + `MAIL_BOUNCE_IMAP_HOST`/`USERNAME`/`PASSWORD` (scheduled `mail:scan-bounces` hourly — no-op until enabled; needs `ext-imap`); (5) run migrations if not already applied; (6) only after a staging test segment succeeds: `EMAIL_CAMPAIGNS=true` → `php artisan config:clear` (or `config:cache`). **No live bulk send from agents — first segment is a human activation step.** Supersedes the ESP-only path of №37 for campaigns; №37 remains valid if a human later overrides D6 to Postmark/Mailgun-as-relay (transport-agnostic engine). | миграция + `.env` mailbox + ⚙️ `EMAIL_CAMPAIGNS` | 🚀 revival of transactional mail over real mailbox SMTP + Anton-style trackable campaigns + догон (resend to non-openers); **flag OFF by default — until flipped, only migrations + global send guards apply** |

| 65 | **«Старт чтения» — чтение пакетами внутри кабинета** (H2110, [PR #1078](https://github.com/gasyoun/Systema-Sanscriticum/pull/1078), MERGED; релиз [1.85.0](https://github.com/gasyoun/Systema-Sanscriticum/pull/1079)) | **Прогонять на проде нечего:** код доезжает авто-деплоем сам (см. врезку от 30-07-2026), данные пакета лежат в репозитории (`resources/data/cohort_start_chteniya/`), миграций нет. Когда пойдёт когорта — один флаг: `KOSHA_READER=true` в `.env` → `php artisan config:clear`. Проверить: `/dvaram/reading` у купившего когорту отдаёт список пакетов, у не купившего — 404 | ⚙️ флаг | студент когорты открывает `hitopadesa-0` внутри кабинета (`/dvaram/reading`, `/dvaram/reading/{slug}`). **Пока `KOSHA_READER` не выставлен — прод инертен, оба маршрута 404.** Доступ и после флага остаётся адресным: гейт — `features.kosha_reader` **И** `StartChteniyaCohort::hasEntitlement`, так что залогиненный студент без покупки когорты тоже получает 404 |
| 63 | **Автооткрытие приёма ДЗ по занятиям 1–5 Кочергиной — волна 1** (H1764, [PR #788](https://github.com/gasyoun/Systema-Sanscriticum/pull/788), MERGED) | `php artisan migrate` (новые колонки в `lessons`). Дальше — включение пилота, по шагам: (1) в админке проставить `textbook_lesson` = 1…5 на пяти уроках курса Кочергиной — ~~**это делает человек, без этого автомат не видит уроков**~~ **устарело с 18-08-2026 (H3068): пустой `textbook_lesson` больше не глушит автомат**, урок открывается с отсылочной формулировкой, а админам уходит напоминание проставить номер. Проставлять по-прежнему стоит — от этого зависит, подставится ли текст упражнений; (2) `HOMEWORK_AUTO_OPEN_COURSES=<slug курса>` в `.env` (по умолчанию пусто = охват нулевой) → `php artisan config:clear`; (3) сверить `php artisan homework:auto-open --dry-run` — в списке должны быть эти уроки и верное время; (4) прогнать без флага и проверить, что пуш пришёл один раз. Опционально `KOCHERGINA_SOURCE_PATH` — путь к [SanskritGrammar](https://github.com/gasyoun/SanskritGrammar) на сервере; если клона там нет, открытие **не ломается**, условие будет отсылочным («все упражнения к Занятию N») вместо текста упражнений | миграция + ⚙️ конфиг + ⏱ планировщик | приём ДЗ открывается сам на следующий день после урока в 09:00 МСК. **Пока `HOMEWORK_AUTO_OPEN_COURSES` пуст и `textbook_lesson` не проставлен — поведение байт-в-байт прежнее** (преподаватель щёлкает `homework_enabled` руками). Часовой слот `homework:auto-open` идёт тем же серверным cron `schedule:run`, что №4/№5 — отдельной записи в cron не нужно |
| 66 | **Предупреждение о занятии заранее** (H2317 — не приду / не уверен / опоздаю / уйду раньше) | входит в общий `php artisan migrate` (аддитивная `schedule_attendance_notices`). **После выката код инертен:** `ATTENDANCE_NOTICES=false` (дефолт) → кнопки на `/calendar` скрыты, фразы в TG/VK не перехватываются как self-service (обычный AI/чат), ростер показывает колонку «Предупредил» пустой. Включение: `ATTENDANCE_NOTICES=true` в `.env` → `php artisan config:clear`. Проверить: студент на `/calendar` видит 4 кнопки; «не смогу на урок» в ЛС бота пишет статус; в админке на занятии колонка «Предупредил»; кто заранее сказал «не смогу», не получает `classes:notify-absent` | миграция + ⚙️ `ATTENDANCE_NOTICES` | студент предупреждает куратора из кабинета или Telegram (ЛС / чат группы). **Пока флаг OFF — прод без UI и без записи intent** |

**Про планировщик (№4, №5):** команды `receivables:check` (ежедневно) и
`finance:kpi-digest` (пн 09:00) уже прописаны в расписании приложения; сработают сами,
если серверный cron раз в минуту вызывает `php artisan schedule:run` (подтверждено T1 —
эта запись есть). №8/№9 (`groups:notify-forming-shortfall`/`goals:record-checkins`) уже в
архиве под тем же cron.

---

## CI/CD-деплой — настройка ключа доступа (H1046, разово)

Опция A: GitHub Actions по SSH запускает тот же `sudo bash deploy.sh`,
что ты гонял руками — [`.github/workflows/deploy.yml`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/.github/workflows/deploy.yml)
уже в `main`, но без ключа/пользователя он просто копит "Waiting"-раны
без вреда. Подробный чек-лист: [`docs/deploy.md` §CI/CD](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/deploy.md#cicd--github-actions--ssh--deploysh-h1046).

| № | Вопрос / действие | Что нужно от Ивана | Зачем |
|---|---|---|---|
| D1 | **Завести деплой-пользователя на проде.** `adduser deploy` (не root), сгенерировать ОТДЕЛЬНУЮ пару ключей ed25519 под это назначение, публичный — в `/home/deploy/.ssh/authorized_keys`; узкий `sudoers`: `deploy ALL=(root) NOPASSWD: /var/www/html/deploy.sh` (только этот скрипт, не общий root). | ~15 минут, root-доступ. Приватный ключ — MG (уйдет в GitHub Environment secret, нигде больше не хранится). | Замыкает CI/CD-путь — каждый мердж в `main` становится деплоем без ожидания твоей доступности; агенты по-прежнему прод-кредов не держат (ключ живет только в GitHub Environment secret с approval-гейтом MG на каждый прогон). |

---

## Telegram — вопросы и действия оператору (масштабирование, Phase 0)

Контекст: ты (Иван) запустил userbot на сервере — импорт чата «Отдел заботы» работает
(MTProto-сессия + очередь). План масштабирования — [ROADMAP_TELEGRAM_SCALING_2026_2027.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/ROADMAP_TELEGRAM_SCALING_2026_2027.md).
Прежде чем масштабировать, нужно закрыть одну развязку — вопрос T1 ниже.

| № | Вопрос / действие | Что нужно от Ивана | Зачем |
|---|---|---|---|
| T1 | **Единый раннер MTProto-синка.** | Ответь: (1) твой cron/supervisor запускает `php artisan telegram-support:sync` (команду Laravel) или **отдельный** самостоятельный MadelineProto-скрипт? (2) Стоит ли `TELEGRAM_SUPPORT_ENABLED=true` в `.env` — при нём планировщик Laravel сам запускает `telegram-support:sync` **каждую минуту** (см. `app/Console/Kernel.php`). **Решение MG:** канонический раннер — **твой cron запускает именно Laravel-команду `telegram-support:sync`**; отдельный самостоятельный скрипт держать не нужно. Конкретно это значит одну crontab-строку (не отдельный демон/systemd-юнит — подробности: [`docs/deploy.md`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/deploy.md#планировщик-schedulerun--раннер-для-всех-scheduled-команд)): `* * * * * cd /var/www/html && php artisan schedule:run >> /dev/null 2>&1`. Если этой строки нет в crontab — **вообще ни одна** scheduled-команда (дебиторка, Telegram-синк, здоровье сессии T5, марафон, напоминания...) не сработает, не только Telegram. | Если ОДНОВРЕМЕННО работают твой отдельный скрипт **и** планировщик Laravel на одной сессии → Telegram выдаёт `AUTH_RESTART` (разлогин, нужен повторный вход с кодом). Развязка разблокирует масштабирование. |
| T2 | **Харвестер санскрит-групп и саппорт-синк — одна сессия.** | НИКОГДА не запускать `telegram-harvest:sync` одновременно с `telegram-support:sync` — они делят одну MTProto-сессию. | Защита живой сессии от `AUTH_RESTART`. |
| T3 | **Секрет вебхука — код на `main`, нужен только твой шаг.** [`VerifyTelegramBotWebhook`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Http/Middleware/VerifyTelegramBotWebhook.php)/[`VerifyVkBotWebhook`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Http/Middleware/VerifyVkBotWebhook.php) уже fail-closed и задеплоены (узел P0.2). 1) Сгенерировать секреты (`openssl rand -hex 32`, по одному на TG/VK) и задать в `.env`: `TELEGRAM_BOT_WEBHOOK_SECRET=...` + `VK_CALLBACK_SECRET=...` → `php artisan config:clear`. 2) **Telegram**: `curl "https://api.telegram.org/bot<STUDENT_TELEGRAM_BOT_TOKEN>/setWebhook?url=https://<домен>/api/telegram/webhook&secret_token=<TELEGRAM_BOT_WEBHOOK_SECRET>"`. 3) **VK**: тот же секрет — в настройках группы → «Работа с API» → Callback API → «Секретный ключ». Между шагом 1 и шагами 2–3 вебхуки на проде отвечают 403 (TG/VK ретраят доставку, окно в пару минут безболезненно) — не затягивать. Подробный чек-лист (тот же фикс, независимо описан 02-07-2026): [`docs/deploy-checklist-audit-fixes.md`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/deploy-checklist-audit-fixes.md). | Защита кабинетного вебхука перед ростом трафика (P0.2 узел [Telegram-scaling implementation map](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/IMPLEMENTATION_MAP_TELEGRAM_SCALING_2026_2027.md)). |
| T4 | **Авто-постинг ссылки на занятие в чат группы — код на `main`, нужны ДВА твоих шага (узел W1.1).** [`classes:post-group-link`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Console/Commands/PostClassLinkToGroupChat.php) уже задеплоена + запланирована (каждые 5 минут). Она молчит, пока не выполнены ВСЕ ТРИ условия: (1) env-рубильник `CLASS_LINK_AUTOPOST=true` в `.env` (деплой-уровень, аварийное выключение — по умолчанию OFF) → `php artisan config:clear`; (2) админ-тумблер «Авто-постинг ссылки» включён в `MarketingSetting` (Filament); (3) у КАЖДОЙ группы, которой это нужно, заполнено поле `telegram_chat_id` (Filament → Группы → карточка группы → «Telegram chat_id группы», формат `-1001234567890`) — без него для этой группы просто ничего не постится (не ошибка, тихий no-op, дедуп не срабатывает — как только chat_id заполнят, следующее занятие в окне пришлёт ссылку). Куратор Group-резурса заполняет chat_id по группам постепенно, не разом. | W1.1 узел [Telegram-scaling implementation map](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/IMPLEMENTATION_MAP_TELEGRAM_SCALING_2026_2027.md) — снимает категорию A вопросов («где ссылка на занятие») из нагрузки саппорта. |
| T5 | **Здоровье userbot-сессии под супервизией — код на `main`, твой шаг = ничего доп. (узел W3.1).** [`telegram-support:healthcheck`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Console/Commands/CheckTelegramSupportSessionHealth.php) уже задеплоена + запланирована (каждые 15 минут, под общим `schedule:run` — см. T1). Не отдельный демон/systemd-юнит: решение MG D2 (§4 [telegram-userbot-inventory.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/telegram-userbot-inventory.md)) — единственный раннер синка и есть этот. Если синк протух (нет успешного прохода дольше `TELEGRAM_SUPPORT_STALE_AFTER_MINUTES`, дефолт 15 мин, тот же порог, что дашборд «Наблюдаемость») или последний проход упал ошибкой — админам (`super_admin`/`admin`) прилетает уведомление в колокольчик Filament со ссылкой на дашборд наблюдаемости. Работает автоматически, как только T1 закрыт (нужен `schedule:run` в crontab) и `TELEGRAM_SUPPORT_ENABLED=true`. | W3.1 узел [Telegram-scaling implementation map](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/IMPLEMENTATION_MAP_TELEGRAM_SCALING_2026_2027.md) — замена ручного мониторинга «жива ли сессия» дежурным алертом. |

> Ответы на T1 можно просто написать MG — он передаст в план. Пункт уходит из списка,
> когда развязка закрыта.

---

## ORS-FAQ (воронка курса) — другой репозиторий

| № | Что | Команды на проде | Тип | Что разблокирует |
|---|---|---|---|---|
| 10 | **Инструментация LTV-бота** | запустить бота (сейчас не запущен) — чтобы триггер лесенки и лог показов давали реальные события | запуск бота | 🚀 реальный причинный сигнал LTV (код готов на синтетике, не хватает только живого бота) |

---

## ✅ Выполнено (архив)

- **H1836 GC-C3 `CRM_FOLLOW_UP_TASKS=true`** (H2014, 31-07-2026) — coupling с уже включённым `CRM_COCKPIT=true` + `CRM_PIPELINE_BOARD=true`; migrate `follow_up_tasks` Ran; `WorkQueueReport::counts()['follow_ups']` отвечает; **`CRM_REMINDERS` не трогали**
- **`SESSION_LIFETIME=1440` на проде** (H2014 probe 31-07-2026) — 419-lifetime residual closed
- **SMTP prod не mailpit** (H2014 probe): `MAIL_MAILER=smtp` + Yandex SMTP, `mail:preflight` OK, Horizon queue `mailing` в supervisors — issue [#504](https://github.com/gasyoun/Systema-Sanscriticum/issues/504) root-cause «mailpit» **устарел** (остаток: SPF/DKIM / real `--send` smoke)
- **Авто-деплой каждые 30 мин** ([PR #870](https://github.com/gasyoun/Systema-Sanscriticum/pull/870), [PR #875](https://github.com/gasyoun/Systema-Sanscriticum/pull/875), [PR #880](https://github.com/gasyoun/Systema-Sanscriticum/pull/880)) — 30-07-2026 (root-cron `systema-auto-deploy-run.sh`)
- **S10 support web rollups** (`SUPPORT_WEB_ROLLUPS=true`, [PR #866](https://github.com/gasyoun/Systema-Sanscriticum/pull/866)) — 30-07-2026 (config:cache; coverage 46/46 и 41/41 vs `chat_messages`)
- **Шаблоны сообщений для кураторов + audit** ([PR #867](https://github.com/gasyoun/Systema-Sanscriticum/pull/867)) — 30-07-2026 (migrate + `CRM_COCKPIT=true` + config:cache в той же SSH-сессии)
- **Вход 419 soft message** ([PR #778](https://github.com/gasyoun/Systema-Sanscriticum/pull/778)/[#779](https://github.com/gasyoun/Systema-Sanscriticum/pull/779), v1.59.1) — 28-07-2026 (`sudo bash deploy.sh`)
- **№1 Все накопленные миграции** — подтверждено 21-07-2026 (MG — Иван прогнал `php artisan migrate` на проде, покрывает веб-чат S1–S5/GC-B3/`cta_subject`/чекаут-миграции и все аддитивные миграции строк №8/9/11/21/23/25/28/29/30/35/36/40/42/43 ниже)
- **№8 Набор в группы — уведомления о недоборе** ([PR #386](https://github.com/gasyoun/Systema-Sanscriticum/pull/386)) — подтверждено 21-07-2026 (входит в миграцию №1, планировщик `groups:notify-forming-shortfall` под подтверждённым cron `schedule:run`)
- **№9 Чек-ины по целям делегирования** ([PR #380](https://github.com/gasyoun/Systema-Sanscriticum/pull/380)) — подтверждено 21-07-2026 (входит в миграцию №1, планировщик `goals:record-checkins` под подтверждённым cron)
- **№11 «Оплачено до» + дедлайн след. платежа** ([PR #393](https://github.com/gasyoun/Systema-Sanscriticum/pull/393)) — подтверждено 21-07-2026 (код без миграции/флага; смок-тест карточки на реальной оплате рекомендован, не блокирует)
- **№21 Планировщик анонсов `scheduled_at`** (H816 PR 2) — подтверждено 21-07-2026 (входит в миграцию №1, `announcements:dispatch-due` уже в `Kernel::schedule()`)
- **№22 Ростер-бот `/группа` и `/кто`** (H816 PR 3) — подтверждено 21-07-2026 (код без миграции/флага)
- **№23 Laravel 10→12 + spatie/laravel-backup 9→10** ([PR #505](https://github.com/gasyoun/Systema-Sanscriticum/pull/505)/[#513](https://github.com/gasyoun/Systema-Sanscriticum/pull/513)) — подтверждено 21-07-2026 (закрывает H862 Dependabot HIGH+MODERATE)
- **№25 Baseline-телеметрия кабинета — R20 Phase 0** ([PR #534](https://github.com/gasyoun/Systema-Sanscriticum/pull/534)) — подтверждено 21-07-2026 (🚀 ≥2-недельный baseline-таймер для гибридного кабинета пошёл с сегодня; смок-тест `cabinet:baseline --days=1` рекомендован)
- **№28 Цифры доказательства из конфига + падеж CTA** ([PR #546](https://github.com/gasyoun/Systema-Sanscriticum/pull/546)) — подтверждено 21-07-2026 (входит в миграцию №1; `TRUST_GRADUATES_COUNT` остаётся не задан — плитка «учеников» скрыта, задать только после подтверждённого числа)
- **№29 GC-B3 шов WebinarProvider — алиасы meeting_\*** ([PR #549](https://github.com/gasyoun/Systema-Sanscriticum/pull/549)) — подтверждено 21-07-2026 (входит в миграцию №1, поведение Zoom-драйвера неизменно)
- **№30 `config/srs.php` default → false — R-6 baseline protection** (H1145) — подтверждено 21-07-2026 (код без миграции; SRS снова выключена по умолчанию, защищает №25-baseline)
- **№35 Бейджинг каналов VK/TG-бота в едином инбоксе — S5** ([PR #565](https://github.com/gasyoun/Systema-Sanscriticum/pull/565)) — подтверждено 21-07-2026 (входит в миграцию №1, без флага)
- **№36 «Жизненные правила для санскритологов» — блок конструктора лендингов** ([PR #569](https://github.com/gasyoun/Systema-Sanscriticum/pull/569)) — подтверждено 21-07-2026 (код без миграции/флага; вставка блока на конкретный лендинг — отдельный ручной шаг MG)
- **№40 Тренажёр «Лигатуры по частотности»** (H1281, D6) — подтверждено 21-07-2026 (статика, без миграции/флага, `/lila/ligatures/` доступен)
- **№42 Лестница эскалации напоминаний должникам** (H1289) — подтверждено 21-07-2026 (входит в миграцию №1, без флага; ⚠️ куратору с кастомным `debt_reminder_text` — заполнить стадии 2–4 отдельно, дефолт из [money-dunning-escalation-ladder.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/copy/money-dunning-escalation-ladder.md))
- **№43 Воронка бесплатных тренажёров — телеметрия `game_events`** (H1360, [PR #622](https://github.com/gasyoun/Systema-Sanscriticum/pull/622)) — подтверждено 21-07-2026 (🚀 входит в миграцию №1 + статика; смок-тест `games:funnel --days=1` рекомендован)
- **№12 Марафон Phase 1 — лендинг + захват** ([PR #407](https://github.com/gasyoun/Systema-Sanscriticum/pull/407)) — подтверждено 17-07-2026 (MG — активация марафона на проде: `php artisan migrate` + запись `LandingPage` создана; брендированный текст лендинга — отдельный ручной шаг №27)
- **№13 Марафон Phase 2 — дрип Day 1/2 через Telegram** ([PR #410](https://github.com/gasyoun/Systema-Sanscriticum/pull/410)) — подтверждено 17-07-2026 (MG — TG-бот `@samskrte` настроен (`tg_bot_username`/`tg_bot_token`), планировщик `marathon:deliver-due` под подтверждённым cron `schedule:run`)
- **№14 Марафон Phase 4 — платный трек ₽500 checkout** ([PR #415](https://github.com/gasyoun/Systema-Sanscriticum/pull/415)) — подтверждено 17-07-2026 (MG — гейтвей Точка (Tochka) подтверждён)
- **№15 Марафон Phase 3b — тап-выбор Day 1/2 + сбор вопроса** ([PR #421](https://github.com/gasyoun/Systema-Sanscriticum/pull/421)) — подтверждено 17-07-2026 (MG — аддитивные миграции применены общим `php artisan migrate`)
- **№16 Марафон Phase 5 — живая консультация Дня 3 + запись** ([чек-лист активации](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/MARATHON_ACTIVATION_CHECKLIST.md)) — подтверждено 17-07-2026 (MG — `Schedule` Дня 3 создан + `MARATHON_SCHEDULE_ID` в `.env`; `zoom_recording_url` проставляется после эфира)
- **№17 Марафон Phase 6 — тёплый хвост Дни 4-16** ([PR #434](https://github.com/gasyoun/Systema-Sanscriticum/pull/434)/[#435](https://github.com/gasyoun/Systema-Sanscriticum/pull/435)) — подтверждено 17-07-2026 (MG — аддитивная миграция применена, `marathon:deliver-warm-tail` под подтверждённым cron `schedule:run`)
- **№18 Живой веб-чат поддержки — весь виджет, включая Reverb live-push** ([PR #432](https://github.com/gasyoun/Systema-Sanscriticum/pull/432)/[#461](https://github.com/gasyoun/Systema-Sanscriticum/pull/461)/[#463](https://github.com/gasyoun/Systema-Sanscriticum/pull/463) транспорт+гость, [#468](https://github.com/gasyoun/Systema-Sanscriticum/pull/468) пузырь на витрине, [#470](https://github.com/gasyoun/Systema-Sanscriticum/pull/470) гость в операторском инбоксе + живой ответ, все MERGED) — подтверждено 13-07-2026 внешней пробой: WebSocket-рукопожатие на `wss://samskrte.ru` вернуло `HTTP/1.1 101 Switching Protocols` + `X-Powered-By: Laravel Reverb` + `pusher:connection_established`; виджет-пузырь живой на главной (`#scw-root`, корректные `data-post-url`/`data-history-url`); собранный JS-бандл содержит тот же REVERB-ключ, что принял живой сокет.

---

_Публичная копия для Ивана (Systema/ORS-FAQ — публичные репозитории). Внутренний источник — приватный GTD. Код: авто-деплой H1933 (каждые 30 мин); эта очередь — только решения/флаги/разовые artisan/внешние шаги. Обновлено 31-07-2026 (H2014): CRM follow-up flag ON, SESSION/mail facts corrected, Yandex.Disk off-site still human-secret. Пункт уходит в архив только с подтверждением._

_Dr. Mārcis Gasūns_
