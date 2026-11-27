# Архитектура: школа-агенты, департамент I «Платформа и SRE» — фаза-1 (H6111)

_Created: 25-11-2026 · Last updated: 25-11-2026_

Исполнение handoff [H6111-OxAlpha_…school-agents-dept-i-platform-sre](https://github.com/gasyoun/Veche/blob/main/handoffs/H6111-OxAlpha_Systema-Sanscriticum_school-agents-dept-i-platform-sre_05.10.26.md), эпик [E026](https://github.com/gasyoun/Uprava/blob/main/handoffs/epics/E026-Uprava_school-agents-program_05.10.26.md).

Ростеры и решения:

- Ростер v1 (роли департамента I): [FLEET_SCHOOL_AGENTS_92_ROSTER_GRILL_04-10-2026](https://github.com/gasyoun/Uprava/blob/main/docs/FLEET_SCHOOL_AGENTS_92_ROSTER_GRILL_04-10-2026.md), раздел «I. Платформа и SRE школы (10)», ID 81–90.
- Ростер v2 (волны и гейты): [FLEET_SCHOOL_AGENTS_92_ROSTER_V2_2026-10-05](https://github.com/gasyoun/Uprava/blob/main/docs/FLEET_SCHOOL_AGENTS_92_ROSTER_V2_2026-10-05.md).
- Диспозиция 92 ID: [SCHOOL_AGENTS_92ID_DISPOSITION_2026-10-05](https://github.com/gasyoun/Uprava/blob/main/reports/SCHOOL_AGENTS_92ID_DISPOSITION_2026-10-05.md).
- Карта детей Hermes 24: [HERMES_GLM_24_HANDOFFS_2026-10-06](https://github.com/gasyoun/Uprava/blob/main/reports/HERMES_GLM_24_HANDOFFS_2026-10-06.md).
- Инвариант прод-записи: H6084 — ничего из ростера не пишет в прод школы без гейта волны.

## 1. Вердикт фазы-1 и место квоты (Q2)

Ростер v2 §«Волны»: кандидаты фазы-1 — блок B+C+D (32 роли) на квоту 25; контент/комьюнити/аналитика и E–J идут W4+ «за квотой фазы-1, отдельно по визе». Из департамента I в квоту фазы-1 входит **ровно одна роль**: `access-provisioner` (ID 85) — канонический маппинг W1 (errata H6142: C34→85; диспозиция, строка 85: «кандидат первой пятёрки-семёрки W1»). Её лимитное разрешение уже в квотной машине: [PR #4499](https://github.com/gasyoun/Systema-Sanscriticum/pull/4499) (H6103) заложил 5 ролей / 12 единиц в общую квоту 25.

Остальные 9 ролей I — W4+ по отдельной визе MG. Отдельное исключение — `pii-guard` (ID 84): это **фаза 0**, «обязателен раньше всех продающих агентов» (ростер v1), т.е. не волна, а гейт-слой, чья реализация уже доставлена общими детьми (§4).

⇒ Фаза-1 департамента I — это **подготовка плюс обслуженная W1-полоса одной роли**: гейто-упаковка 10 ролей, карта переиспользования, нулевой прод-след; W1-ребёнок ID 85 (H6189) доставлен и стоит с флагом OFF. Ни одна I-роль не получает в этой фазе ни флага запуска, ни планировщика, ни исходящих сообщений. Любой будущий запуск I-ролей — W4+ по визе MG со всеми гейтами Q3–Q9.

Изменений в ростере это не вносит — фиксирует следствие на уровне репозитория.

## 2. Роли департамента I и estate-поверхности

| # | Роль | Тип | Существующая поверхность в estate (prior art — не строить заново) |
|---|---|---|---|
| I1 | bot-warden (81) | ⚙️ | здоровье 4 TG-ботов: `app/Console/Commands/TelegramPollStudentUpdates.php`, `tests/Feature/SyncTelegramWebhooksTest.php`, `tests/Feature/TelegramWebhookCertificateTest.php`, `app/Console/Concerns/SchedulesTelegram.php`, `app/Console/Concerns/LocksMadelineSession.php` — поллеры/сессии детерминированы, конфликты уже локами |
| I2 | webhook-sentinel (82) | ⚙️ | релей 103.112.71.201: дубли/потери/лаги — webhook-контуры того же poll-стека + `tochka_webhook_guard` (`config/features.php:864`, default **true** — guard, не агент) |
| I3 | wp-publisher (83) | ⚙️ | публикация WP из агентов контента (из estate-скилла `ors-wp-publish`); в репо — конвейер anons: `app/Services/Anons/AdapterRegistry.php`, `app/Services/Anons/PublicationManifest.php`; сам WP samskrtam.ru живёт вне репо, агент-обёртки нет |
| I4 | pii-guard (84) | ⚙️ | 152-ФЗ слой: `app/Services/Support/SupportStudentPiiMasker.php` (реализация H6144) + сквозная проверка границ TG/web H6182 — designated child evidence (§4), второй маскер не строится |
| I5 | access-provisioner (85) | ⚙️ | выдача/отзыв доступов: `app/Models/LessonAccessGrant.php`, `grant_access_fail_closed` (`config/features.php:943`, OFF), `access_self_service` (1423, OFF), контур `Payment::PAID_STATUSES`/тарифов; W1-ребёнок H6189 доставлен, флаг `hermes_access_diagnostics` (`config/features.php:2183`) — **OFF** |
| I6 | recording-vault (86) | ⚙️ | хранилище записей, доступ по оплате, срок жизни: `app/Models/RecordingGapAlert.php`, `course_recordings_sales` (562, OFF), `membership_recording_shadow/pilot/enforce` (1636–1638, все OFF) |
| I7 | backup-driller (87) | ⚙️ | бэкапы + restore-drills: [docs/PLAYBOOK_DR_BACKUP_VERIFICATION_09-10-2026](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/PLAYBOOK_DR_BACKUP_VERIFICATION_09-10-2026.md), [docs/RESTORE_DRILL_COLD_2026-08-24](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/RESTORE_DRILL_COLD_2026-08-24.md), uptime-guardrails лейн; сегодняшние дриллы — люди |
| I8 | incident-first-responder (88) | 🧠 | первый ответ на инцидент: [docs/INCIDENT_LEDGER_SYSTEMA_UPTIME](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/INCIDENT_LEDGER_SYSTEMA_UPTIME.md) + estate-скилл `sos` (прод-инциденты samskrte-хостов); статус студентам — только как черновик через human-send (§3, Q3/Q9) |
| I9 | secret-rotator (89) | ⚙️ | ротация ключей (боты, Senler, Tochka) с простоем-нулем: [docs/ARCHITECTURE_SYSTEMA_N8N_SERVER_OPS](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/ARCHITECTURE_SYSTEMA_N8N_SERVER_OPS.md), env-контракт estate `prepared-env-credentials`; **V2: проект ротации, не автономная ротация** |
| I10 | license-auditor (90) | ⚙️ | права на материалы уроков, атрибуции (из estate-скилла `publish-safety-check`): `docs/materials/*/ASSETS.md`, `app/Services/Anons/PublicationManifest.php` (манифест публикаций) |

Все поверхности уже обслуживаются людьми/скриптами; агент-фаза каждой роли стартует только после визы W4+ (кроме I5 — W1, и I4 — фаза 0) и гейтов ниже.

## 3. Гейто-упаковка (Q3–Q9) — как применяется к департаменту I

| Гейт | Правило (решения 05-10) | Для I в фазе-1 |
|---|---|---|
| Q2 квота | фаза-1 = 25 ролей (W1 7 + W2 18) | Из I в квоте только ID 85 (W1, разрешение #4499); остальные 9 — W4+ по визе |
| Q3 запись в прод | suggester: агент готовит — человек отправляет; первые 30 дней human-send | 0 прод-письма до гейта волны (H6084). I8-статусы студентам и любые I-исходящие — только через human-send-слой H6183 (флаг OFF); H6189 (I5) по design шлёт ноль — диагностика + предложение |
| Q5 pii-guard | фаза 0: маскинг до любого LLM-вызова; без обёртки роль не запускается (152-ФЗ, фенс H2641) | I4 — **владелец гейта**: реализация H6144 + сквозная проверка H6182 (PASS, PR #3944) уже лежат в origin/main; новую обёртку не строим, департаменты-потребители берут готовую |
| Q6 скидки | полный запрет автономии, решает MG | Запрещено без оговорок для всех I-ролей; I8 в инцидентных шаблонах с упоминанием оплат — только предлагать |
| Q7 токены | killgate-слой H5867 + лимиты по ролям | Для всех 🧠/🔄 I-ролей (сегодня только I8): слой H5867 + per-role лимиты из квотной машины H6174 (`config/school_role_quota.php`, `enabled=false`, excluded 8/39/42) |
| Q8 учебка | золотые наборы + выборка MG | I-роли учебный контент не производят — golden-cut I не нужен; W1-набор H6181 обслуживает только первую волну и не заменяет D/J-наборы учебки |
| Q9 голос | tone-гайд + редколлегия скиллов; litredaktor-пасс отменён | Исходящие I (в основном I8): черновик по tone-гайду → редколлегия → human-send |
| Q13 KPI | скорость первого ответа + доля закрытых без человека; NPS-стража | Применяется к I-ролям с внешними ответами (I8) при визе; инфраструктурные I1/I2/I7 KPI внешних ответов не имеют |
| Q4 канал | TG + веб сразу | Для I это в основном инфраструктура обоих каналов (I1/I2); ответные роли при визе — черновики в TG + web через очередь H6183 |

## 4. Карта переиспользования — общая техника доставлена, дублей не строим (пакет Hermes 06-10)

Все четыре выделенных результата для этого зонтика — **designated children, приняты как evidence, повторно не реализуются**:

| Общая техника | Готовность | Ссылка |
|---|---|---|
| Квотные лимиты по школьным ролям (Q2/Q7) | H6174 — PR [4150](https://github.com/gasyoun/Systema-Sanscriticum/pull/4150) merged, verifier PASS 14-11 (16 tests / 137 assertions) | [H6174](https://github.com/gasyoun/Uprava/blob/main/handoffs/archive/H6174-GLM_Systema-Sanscriticum_hermes-school-role-quota-enforcement_06.10.26.md) |
| PII на границах LLM (Q5) — гейт I4 | H6182 — PR [3944](https://github.com/gasyoun/Systema-Sanscriticum/pull/3944) merged `c2609f0a`, verifier PASS 13-11 (6 tests / 41 assertions, fail-closed) | [H6182](https://github.com/gasyoun/Uprava/blob/main/handoffs/archive/H6182-GLM_Systema-Sanscriticum_hermes-school-llm-boundary-pii-proof_06.10.26.md) |
| Проверяемый human-send TG+web (Q3) | H6183 — PR [3190](https://github.com/gasyoun/Systema-Sanscriticum/pull/3190) merged, HumanSendTest 8/8, `hermes_human_send` OFF | [H6183](https://github.com/gasyoun/Uprava/blob/main/handoffs/archive/H6183-GLM_Systema-Sanscriticum_hermes-tg-web-human-send-evidence_06.10.26.md) |
| W1 доступы, ID 85 (роль I5) | H6189 — DELIVERED: диагностика + предложение исправления, флаг `hermes_access_diagnostics` OFF; [reports/H6189_ACCEPTANCE_2026-10-06](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/reports/H6189_ACCEPTANCE_2026-10-06.md) | [H6189](https://github.com/gasyoun/Uprava/blob/main/handoffs/archive/H6189-GLM_Systema-Sanscriticum_hermes-w1-access-role85_06.10.26.md) |
| Task-level killgate (Q7) | H5867 реализация; H6173 интеграционная проверка | [H6173](https://github.com/gasyoun/Uprava/blob/main/handoffs/archive/H6173-GLM_Uprava_hermes-task-killgate-integration-proof_06.10.26.md) |
| Golden-набор первой волны | H6181 — только W1, не D/J-наборы учебки | [H6181](https://github.com/gasyoun/Uprava/blob/main/handoffs/archive/H6181-GLM_Systema-Sanscriticum_hermes-w1-synthetic-golden-cases_06.10.26.md) |
| Маршрутизация обращений | H6190 — общий слой, не создаёт I-канонических ID | [H6190](https://github.com/gasyoun/Uprava/blob/main/handoffs/archive/H6190-GLM_Systema-Sanscriticum_hermes-w1-shared-routing-layer_06.10.26.md) |
| Измерительная программа 90 дней | H6120 — claimed, владение не переносится; H6176–H6180/H6191 закрыты на согласование границ с ним | [H6120](https://github.com/gasyoun/Uprava/blob/main/handoffs/H6120-GLM_Uprava_school-agents-90-day-measurement_05.10.26.md) |

## 5. Нулевой прод-след фазы-1 (центза 25-11-2026)

- Флаги agent-запуска ролей I по слагам (`bot_warden|webhook_sentinel|wp_publisher|pii_guard|access_provisioner|recording_vault|backup_driller|incident_first|secret_rotator|license_auditor`) в `config/features.php`: **0**.
- Флаги-соприкосновения — все дефолт OFF: `hermes_access_diagnostics` (2183, I5-ребёнок H6189), `support_ai_include_telegram` (74), `telegram_zapisi_bot` (1007), `course_recordings_sales` (562), `membership_recording_shadow/pilot/enforce` (1636–1638, I6), `grant_access_fail_closed` (943), `access_self_service` (1423, I5), `tochka_webhook_guard` (864, default true — guard, не агент).
- Новых Schedule-заданий, LLM-вызовов, команд: **0** — этот PR меняет ровно 1 файл (документ §1–§6).
- Исходящих сообщений агентом: **0** (Q3/Q9 не активированы; H6084; `hermes_human_send` OFF дефолтом).

## 6. Границы

- I-роли не наследуют msi-online; сам gate зонтика E026 этим дополнением не меняется (scope-заметка H6111 от 06-10).
- Границы 90-дневной измерительной программы — согласование внутри claimed H6120; новые дети не наследуют его 90-дневное владение.
- I9 secret-rotator — по ростеру V2 «проект ротации, не автономная ротация»: любая реальная ротация ключей с простоем-нулем требует отдельного проекта и визы, этот документ её не санкционирует.
- Любое расширение после первых семи W1-ролей требует двух недель без Sev-инцидентов **и** доказанного снижения человеческой нагрузки (уточнение 05-10); I7/I8 — как раз стража этого гейта, их собственный запуск тоже идёт после измерения.
- 0 прод-письма департамента I до гейта волны — инвариант H6084, не меняется этой фазой.

## Delivery

- **Changed:** этот документ (департаментный контур H6111); ничего в коде, флагах, планировщиках.
- **Unchanged:** все 10 I-ролей — подготовка (I5 — W1-ребёнок H6189 доставлен с флагом OFF, I4 — гейт фазы 0 доставлен H6144/H6182); прода и исходящих сообщений 0; Hermes/W1 не тронуты.
- **Checks:** центзы §5 — agent-флаги I = 0, соприкасающиеся флаги дефолт OFF, новые планировщики/LLM-вызовы = 0, diff = 1 doc-файл.
- **Risks:** drift между документом и ростером при новой визе MG; лечится ссылкой на источник и центзой при каждом визе. I8 (incident-статусы) — самая близкая к прод-письму роль: до визы не существует ни флага, ни черновика.
- **Inspect:** `grep -n -iE "bot_warden|webhook_sentinel|wp_publisher|pii_guard|access_provisioner|recording_vault|backup_driller|incident_first|secret_rotator|license_auditor" config/features.php` (= 0); `grep -n "hermes_access_diagnostics\|membership_recording_\|tochka_webhook_guard" config/features.php`; этот документ.

_Гасунс_ · H6111 · OxAlpha (MiMo v2.6 Flash, ollama-cloud/mimo-v2.6-flash)
