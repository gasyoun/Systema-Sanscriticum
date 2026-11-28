# Архитектура: школа-агенты, департамент A «Маркетинг и лидогенерация» — фаза-1 (H6100)

_Created: 07-11-2026 · Last updated: 27-11-2026_

Исполнение handoff [H6100-OxAlpha_…school-agents-dept-a-marketing](https://github.com/gasyoun/Uprava/blob/main/handoffs/H6100-OxAlpha_Systema-Sanscriticum_school-agents-dept-a-marketing_05.10.26.md), эпик [E026](https://github.com/gasyoun/Uprava/blob/main/handoffs/epics/E026-Uprava_school-agents-program_05.10.26.md).

Ростеры и решения:

- Ростер v1 (роли департамента A): [FLEET_SCHOOL_AGENTS_92_ROSTER_GRILL_04-10-2026](https://github.com/gasyoun/Uprava/blob/main/docs/FLEET_SCHOOL_AGENTS_92_ROSTER_GRILL_04-10-2026.md), раздел «A. Маркетинг и лидогенерация (12)».
- Ростер v2 (волны и гейты): [FLEET_SCHOOL_AGENTS_92_ROSTER_V2_2026-10-05](https://github.com/gasyoun/Uprava/blob/main/docs/FLEET_SCHOOL_AGENTS_92_ROSTER_V2_2026-10-05.md).
- Диспозиция 92 ID: [SCHOOL_AGENTS_92ID_DISPOSITION_2026-10-05](https://github.com/gasyoun/Uprava/blob/main/reports/SCHOOL_AGENTS_92ID_DISPOSITION_2026-10-05.md).
- Карта детей Hermes 24: [HERMES_GLM_24_HANDOFFS_2026-10-06](https://github.com/gasyoun/Uprava/blob/main/reports/HERMES_GLM_24_HANDOFFS_2026-10-06.md).
- Инвариант прод-записи: H6084 — ничего из ростера не пишет в прод школы без гейта волны.

> **Верификация 27-11-2026.** Документ впервые доставлен 07-11-2026 (PR [#3946](https://github.com/gasyoun/Systema-Sanscriticum/pull/3946)). Настоящий проход — сверка с текущим `origin/main` и закрытие H6100: выводы §1–§4 подтверждены, ценза §5 переснята на 27-11, добавлены границы H6176–H6180/H6191 и проверка на dual-run. Вердикт и scope не меняются.

## 1. Вердикт фазы-1 и место квоты (Q2)

Ростер v2 §«Волны»: кандидаты фазы-1 — блок B+C+D (32 роли) на квоту 25; контент/комьюнити/аналитика идут W4+ «за квотой фазы-1, отдельно по визе». Департамент A в состав фазы-1 квоты **не входит**.

⇒ Фаза-1 департамента A — это **подготовка, а не запуск**: гейто-упаковка ролей, карта переиспользования, нулевой прод-след. Ни одна из 12 ролей A не получает флаг запуска, планировщика или исходящих сообщений в этой фазе. Любой будущий запуск A-роли — W4+ по отдельной визе MG со всеми гейтами Q3–Q9 и, где уместно, за общую квоту 25 (Q2) только по явному решению MG.

Изменений в ростере это не вносит — фиксирует его следствие на уровне репозитория: фаза-1 департамента A = подготовка, прод-запуск W4+ по визе.

## 2. Роли департамента A и estate-поверхности

| # | Роль (ID) | Тип | Существующая поверхность в estate (prior art — не строить заново) |
|---|---|---|---|
| A1 | content-planner (1) | 🧠 | [docs/ANONS_PUBLISHING_V2](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/ANONS_PUBLISHING_V2.md), контент-календарь потока |
| A2 | post-writer (2) | 🧠 | anons-редактура, mg-post-edit lessons; голос MG |
| A3 | seo-article-writer (3) | 🔄 | [docs/ARCHITECTURE_SYSTEMA_SAMSKRTE_SEO_H2](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/ARCHITECTURE_SYSTEMA_SAMSKRTE_SEO_H2.md), WP-конвейер |
| A4 | senler-segmenter (4) | ⚙️ | VK/Senler ручная лейна (send-API Senler отсутствует, #4253) |
| A5 | tg-ads-optimizer (5) | 🧠 | TG-ads кабинет (доступы — MG) |
| A6 | metrika-analyst (6) | ⚙️ | metrika-goal-map, SLI-4 |
| A7 | attribution-auditor (7) | ⚙️ | `app/Support/AcquisitionAttribution.php`, anons-attribution H5934 |
| A8 | lead-scorer (8) | 🔄 | **excluded** (kill-candidate V2, H6090 §1.9); заблокирован и в ростре, и в `config/school_role_quota.php` → `excluded_role_ids`. Поверхность `leads`/`InferLeadSources` обслуживается людьми; агент-активация запрещена |
| A9 | referral-loop (9) | ⚙️ | [docs/ARCHITECTURE_SYSTEMA_REFERRAL_PROGRAM_MECHANICS_2026](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/ARCHITECTURE_SYSTEMA_REFERRAL_PROGRAM_MECHANICS_2026.md) |
| A10 | webinar-funnel-agent (10) | ⚙️ | `LinkWebinarParticipants`, `DeliverWebinarRecordings`, webinar playbook |
| A11 | retarget-segmenter (11) | ⚙️ | сегменты «смотрел-не-купил» поверх `leads`/`marketing_settings` |
| A12 | market-scout (12) | 🧠 | venue-scout-паттерн, spider MCP |

Все поверхности уже обслуживаются людьми/скриптами; агент-фаза каждой роли стартует только после визы W4+ и гейтов ниже. Kill-candidate A8 (ID 8) не активируется ни при какой визе без отдельного решения MG.

## 3. Гейто-упаковка (Q3–Q9) — как применяется к департаменту A

| Гейт | Правило (решения 05-10) | Для A в фазе-1 |
|---|---|---|
| Q3 запись в прод | suggester: агент готовит — человек отправляет; первые 30 дней human-send | Ни одно исходящее A-письмо не существует до визы; потом — через human-send-слой H6183 (`config/features.php:2183`, дефолт OFF). **0 прод-писем до гейта волны** (H6084) |
| Q5 pii-guard | фаза 0: маскинг до любого LLM-вызова; без обёртки роль не запускается (152-ФЗ, фенс H2641) | Обязателен для A8 (lead_signup — персональные данные), A11 (поведение лидов), A4 (VK-контакты); готовый маскер: `app/Services/Support/SupportStudentPiiMasker.php` (H6144/H6182, fail-closed) |
| Q6 скидки | полный запрет автономии, решает MG | Запрещено без оговорок для всех A-ролей |
| Q7 токены | killgate-слой H5867 + лимиты по ролям | Для всех 🧠/🔄 ролей A — слой H5867 + per-role лимиты из квотной машины H6174; allowances A не введены (§5), «без новых трат», платного fallback нет |
| Q8 учебка | золотые наборы + выборка MG (gold-adjudicate-паттерн) | W1 golden-набор H6181 обслуживает **только** первую волну и **не заменяет D/J-наборы учебки**; для A нужен отдельный golden-cut, без которого W4+ не стартует |
| Q9 голос | tone-гайд + редколлегия скиллов; обязательный litredaktor-пасс отменён | Исходящие A: черновик по tone-гайду → редколлегия скиллов → human-send. Ни одной публикации агентом |
| Q13 KPI | скорость первого ответа + доля закрытых без человека; NPS-стража | Применяется к ролям с внешними ответами (A2/A10 — черновики) при визе W4+; «доля закрытых без человека» = human-send-статистика, не автономная отправка |
| Q4 канал | TG + веб сразу | Для A-исходящих: черновики в TG-лейне + web-черновики через очередь H6183 (web); в фазе-1 ни того, ни другого агент не пишет |

## 4. Карта переиспользования — общая техника доставлена, дублей не строим (пакет Hermes 06-10)

Лежит в этом репозитории (origin/main), повторно не реализуется:

| Общая техника | Готовность | Ссылка |
|---|---|---|
| Task-level killgate | реализация H5867; интеграционная проверка H6173 | [H6173](https://github.com/gasyoun/Uprava/blob/main/handoffs/H6173-GLM_Uprava_hermes-task-killgate-integration-proof_06.10.26.md) |
| Подписочная квота по школьным ролям | H6174, `config/school_role_quota.php` + `SchoolRoleQuotaService` | [H6174](https://github.com/gasyoun/Uprava/blob/main/handoffs/H6174-GLM_Systema-Sanscriticum_hermes-school-role-quota-enforcement_06.10.26.md) |
| PII-маскинг на границах LLM | H6144 реализация, H6182 проверка TG+web | [H6182](https://github.com/gasyoun/Uprava/blob/main/handoffs/H6182-GLM_Systema-Sanscriticum_hermes-school-llm-boundary-pii-proof_06.10.26.md) |
| Human-send (TG + web, human actor proof) | H6183, флаг `HERMES_HUMAN_SEND` (`config/features.php:2183`) дефолт OFF | `app/Services/Support/HumanSend/SupportHumanSendService.php` |
| Золотой набор первой волны (6 ролей + маршрутизация) | H6181 — только W1, не D/J-наборы учебки и не A-cut | [H6181](https://github.com/gasyoun/Uprava/blob/main/handoffs/H6181-GLM_Systema-Sanscriticum_hermes-w1-synthetic-golden-cases_06.10.26.md) |
| Маршрутизация обращений | H6190 — общий слой поддержки, не создаёт A-канонических ID | [H6190](https://github.com/gasyoun/Uprava/blob/main/handoffs/H6190-GLM_Systema-Sanscriticum_hermes-w1-shared-routing-layer_06.10.26.md) |
| Измерительная программа 90 дней | H6120 — claimed, владение не переносится | [H6120](https://github.com/gasyoun/Uprava/blob/main/handoffs/H6120-GLM_Uprava_school-agents-90-day-measurement_05.10.26.md) |

Общие инструменты и проверки департамента берутся у детей из карты Hermes ([HERMES_GLM_24_HANDOFFS_2026-10-06](https://github.com/gasyoun/Uprava/blob/main/reports/HERMES_GLM_24_HANDOFFS_2026-10-06.md)); в пакете 06-10 **собственных новых ролей департамента A нет** — повторная реализация запрещена, исходный scope 12 ролей сохранён.

## 5. Нулевой прод-след фазы-1 (ценз 27-11-2026)

Все команды выполнены в корне этого worktree против текущего `origin/main` (`7a83dbe1`):

- `grep -nE "marketing_segments|content_planner|post_writer|seo_article|senler|tg_ads|metrika|attribution_auditor|lead_scorer|referral_loop|webinar_funnel|retarget|market_scout|hermes_" config/features.php` → **6 строк**: `marketing_segments` (1193, H1637, не агент) и ровно 5 `hermes_*` (2169 `schedule_keeper`, 2183 `human_send`, 2196 `faq_rag`, 2212 `access_diagnostics`, 2225 `payment_reconciler`) — роли W1 поддержки. **Ни одного флага запуска A-ролей.**
- `grep -nE "hermes_" config/features.php | grep -v "env(.*false)"` → **нет вывода** (все 5 флагов `env(…, false)`).
- `config/school_role_quota.php`: `enabled=false` (29); `allowances` (86–93) содержат 5 строк фазы-1 из H6103 — `first-contact-247` (13), `onboarding-greeter` (19), `access-provisioner` (85), `faq-rag` (20), `payment-reconciler` (65), «Total 12 units within Q2 quota of 25». **Ни одной A-роли в allowances нет**; `excluded_role_ids` содержит `lead-scorer` (ID 8) — A8 заблокирован. В этом PR файл не изменяется.
- Детерминированные CRM-планировщики (`leads:remind-followup` 08:00, `leads:infer-source` 03:35, `app/Console/Concerns/SchedulesOvernightAndCrm.php:224/233`) — утилиты estate, обслуживаются людьми; это поверхности для будущих A7/A8/A11. **Агент-обёрток над ними не добавлено**: ни одного нового Schedule-задания, ни одного LLM-вызова.
- Команды-консьюмеры (`InferLeadSources`, `LinkWebinarParticipants`, `RemindLeadsForFollowup`, `DeliverWebinarRecordings`, `app/Support/AcquisitionAttribution`) — существующие детерминированные утилиты; изменений нет.
- Исходящих сообщений и публикаций агентом: **0** (Q3/Q9 не активированы, human-send OFF дефолтом, 0 прод-писем до гейта волны — H6084).

## 6. Границы

- A-роли не наследуют msi-online; сам gate зонтика E026 этим дополнением не меняется (scope-заметка H6100 от 06-10).
- Границы 90-дневной измерительной программы — согласование внутри claimed H6120; новые дети не наследуют его 90-дневное владение.
- Golden-набор W1 (H6181) обслуживает только первую волну; **не заменяет D/J-наборы учебки** и не является A-cut — департаменту A нужен отдельный golden-cut перед визой W4+.
- **H6176–H6180 и H6191 закрыты на согласование границ с claimed H6120; их 90-дневное владение не переносится.** Настоящий документ эти границы не переопределяет и не наследует.
- Stenogrammy: локальное чтение разрешено (H8050), публикация/экспорт тел и списков путей в публичную поверхность закрыты; A-роли не должны выносить ПДн в публичные черновики без маскера фазы 0.
- Любое расширение после W1 требует двух недель без Sev-инцидентов **и** доказанного снижения человеческой нагрузки (уточнение 05-10); это относится и к A-ролям на их W4+.
- **Dual-run проверка 27-11:** единственная ветка с префиксом `h6100` — `origin/h6100-drain`; competing-ветки, правящей департамент A или квотные allowances, нет. Dual-run residual нет; чужой live-claim не снимался. Предыдущая доставка — PR [#3946](https://github.com/gasyoun/Systema-Sanscriticum/pull/3946) (07-11).

## Delivery

- **Changed:** этот документ (департаментный контур H6100): обновлён ценз §5 на 27-11, уточнён статус A8 (excluded), исправлены номера строк `config/features.php`, добавлены границы H6176–H6180/H6191 и dual-run проверка. Ничего в коде, флагах, планировщиках, квотных числах.
- **Unchanged:** все 12 A-ролей остаются в подготовке (A8 — excluded); прод, исходящих сообщений и LLM-вызовов 0; `config/school_role_quota.php`, Hermes/W1, `config/features.php` не тронуты; чужой claim не снят.
- **Checks:** цензы §5 — `grep -nE …` → 6 строк, все A-флагов 0; `hermes_*` → 5, все `env(…, false)`; `school_role_quota.php` → без A-allowances, `lead-scorer` в excluded; `git show --stat` PR → ровно 1 doc-файл.
- **Risks:** drift между документом и ростером при новой визе MG — лечится ссылкой на источник и повторным цензом при каждом W4+; риск принять W1 golden за A-cut — явно запрещено в §3/§6; риск утечки ПДн в маркетинговые черновики — задан pii-guard фазы 0.
- **Inspect:** `git show --stat` этого PR; этот документ §5 (ценз) и §6 (границы/dual-run); `grep -n "marketing_segments\|hermes_" config/features.php`; `grep -n "lead-scorer\|first-contact-247" config/school_role_quota.php`.

_к.ф.н. М.Ю. Гасунс_ · H6100 · OxAlpha (MiMo v2.6 Flash, ollama-cloud/mimo-v2.6-flash)
