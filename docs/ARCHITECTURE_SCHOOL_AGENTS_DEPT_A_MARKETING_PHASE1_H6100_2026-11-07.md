# Архитектура: школа-агенты, департамент A «Маркетинг и лидогенерация» — фаза-1 (H6100)

_Created: 07-11-2026 · Last updated: 07-11-2026_

Исполнение handoff [H6100-OxAlpha_…school-agents-dept-a-marketing](https://github.com/gasyoun/Uprava/blob/main/handoffs/H6100-OxAlpha_Systema-Sanscriticum_school-agents-dept-a-marketing_05.10.26.md), эпик [E026](https://github.com/gasyoun/Uprava/blob/main/handoffs/epics/E026-Uprava_school-agents-program_05.10.26.md).

Ростеры и решения:

- Ростер v1 (роли департамента A): [FLEET_SCHOOL_AGENTS_92_ROSTER_GRILL_04-10-2026](https://github.com/gasyoun/Uprava/blob/main/docs/FLEET_SCHOOL_AGENTS_92_ROSTER_GRILL_04-10-2026.md), раздел «A. Маркетинг и лидогенерация (12)».
- Ростер v2 (волны и гейты): [FLEET_SCHOOL_AGENTS_92_ROSTER_V2_2026-10-05](https://github.com/gasyoun/Uprava/blob/main/docs/FLEET_SCHOOL_AGENTS_92_ROSTER_V2_2026-10-05.md).
- Диспозиция 92 ID: [SCHOOL_AGENTS_92ID_DISPOSITION_2026-10-05](https://github.com/gasyoun/Uprava/blob/main/reports/SCHOOL_AGENTS_92ID_DISPOSITION_2026-10-05.md).
- Карта детей Hermes 24: [HERMES_GLM_24_HANDOFFS_2026-10-06](https://github.com/gasyoun/Uprava/blob/main/reports/HERMES_GLM_24_HANDOFFS_2026-10-06.md).
- Инвариант прод-записи: H6084 — ничего из ростера не пишет в прод школы без гейта волны.

## 1. Вердикт фазы-1 и место квоты (Q2)

Ростер v2 §«Волны»: кандидаты фазы-1 — блок B+C+D (32 роли) на квоту 25; контент/комьюнити/аналитика идут W4+ «за квотой фазы-1, отдельно по визе». Департамент A в состав фазы-1 квоты **не входит**.

⇒ Фаза-1 департамента A — это **подготовка, а не запуск**: гейто-упаковка ролей, карта переиспользования, нулевой прод-след. Ни одна из 12 ролей A не получает флаг запуска, планировщика или исходящих сообщений в этой фазе. Любой будущий запуск A-роли — W4+ по отдельной визе MG со всеми гейтами Q3–Q9 и, где уместно, за общую квоту 25 (Q2) только по явному решению MGI.

Изменений в ростере это не вносит — фиксирует его следствие на уровне репозитория: фаза-1 департамента A = подготовка, прод-запуск W4+ по визе.

## 2. Роли департамента A и estate-поверхности

| # | Роль | Тип | Существующая поверхность в estate (prior art — не строить заново) |
|---|---|---|---|
| A1 | content-planner | 🧠 | [docs/ANONS_PUBLISHING_V2](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/ANONS_PUBLISHING_V2.md), контент-календарь потока |
| A2 | post-writer | 🧠 | anons-редктура, mg-post-edit lessons; голос MG |
| A3 | seo-article-writer | 🔄 | [docs/ARCHITECTURE_SYSTEMA_SAMSKRTE_SEO_H2](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/ARCHITECTURE_SYSTEMA_SAMSKRTE_SEO_H2.md), WP-конвейер |
| A4 | senler-segmenter | ⚙️ | VK/Senler ручная лейна (send-API Senler отсутствует, #4253) |
| A5 | tg-ads-optimizer | 🧠 | TG-ads кабинет (доступы — MG) |
| A6 | metrika-analyst | ⚙️ | metrika-goal-map, SLI-4 |
| A7 | attribution-auditor | ⚙️ | `app/Support/AcquisitionAttribution.php`, anons-attribution H5934 |
| A8 | lead-scorer | 🔄 | Filament-конвейер `leads`, `InferLeadSources` (см. [docs/PLAYBOOK_COURSE_LAUNCH_FROM_ZERO_2026-10](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/PLAYBOOK_COURSE_LAUNCH_FROM_ZERO_2026-10.md)) |
| A9 | referral-loop | ⚙️ | [docs/ARCHITECTURE_SYSTEMA_REFERRAL_PROGRAM_MECHANICS_2026](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/ARCHITECTURE_SYSTEMA_REFERRAL_PROGRAM_MECHANICS_2026.md) |
| A10 | webinar-funnel-agent | ⚙️ | `LinkWebinarParticipants`, `DeliverWebinarRecordings`, webinar playbook |
| A11 | retarget-segmenter | ⚙️ | сегменты «смотрел-не-купил» поверх `leads`/`marketing_settings` |
| A12 | market-scout | 🧠 | venue-scout-паттерн, spider MCP |

Все поверхности уже обслуживаются людьми/скриптами; агент-фаза каждой роли стартует только после виза W4+ и гейтов ниже.

## 3. Гейто-упаковка (Q3–Q9) — как применяется к департаменту A

| Гейт | Правило (решения 05-10) | Для A в фазе-1 |
|---|---|---|
| Q3 запись в прод | suggester: агент готовит — человек отправляет; первые 30 дней human-send | Ни одно исходящее A-письмо не существует до визы; потом — через human-send-слой H6183 |
| Q5 pii-guard | фаза 0: маскинг до любого LLM-вызова; без обёртки роль не запускается (152-ФЗ, фенс H2641) | Обязателен для A8 (lead_signup — персональные данные), A11 (поведение лидов), A4 (VK-контакты); готовый маскер: `app/Services/Support/SupportStudentPiiMasker.php` |
| Q6 скидки | полный запрет автономии, решает MG | Запрещено без оговорок для всех A-ролей |
| Q7 токены | killgate-слой H5867 + лимиты по ролям | Для всех 🧠/🔄 ролей A — слой H5867 + per-role лимиты из квотной машины H6174 |
| Q8 учебка | золотые наборы + выборка MG | W1 golden-набор H6181 обслуживает только первую волну поддержки; для A нужен отдельный golden-cut (не наследуется) |
| Q9 голос | tone-гайд + редколлегия скиллов; litredaktor-пасс отменён | Исходящие A: черновик по tone-гайду → редколлегия → human-send |
| Q13 KPI | скорость первого ответа + доля закрытых без человека; NPS-стража | Применяется к ролям с внешними ответами (A2/A10 — черновики) при визе W4+ |
| Q4 канал | TG + веб сразу | Для A-исходящих: черновики в TG-лейне + web-черновики через очередь H6183 (web) |

## 4. Карта переиспользования — общая техника доставлена, дублей не строим (пакет Hermes 06-10)

Лежит в этом репозитории (origin/main на 07-11-2026), повторно не реализуется:

| Общая техника | Готовность | Ссылка |
|---|---|---|
| Task-level killgate | реализация H5867; интеграционная проверка H6173 | [H6173](https://github.com/gasyoun/Uprava/blob/main/handoffs/H6173-GLM_Uprava_hermes-task-killgate-integration-proof_06.10.26.md) |
| Подписочная квота по школьным ролям | H6174 | [H6174](https://github.com/gasyoun/Uprava/blob/main/handoffs/H6174-GLM_Systema-Sanscriticum_hermes-school-role-quota-enforcement_06.10.26.md) |
| PII-маскинг на границах LLM | H6144 реализация, H6182 проверка TG+web | [H6182](https://github.com/gasyoun/Uprava/blob/main/handoffs/H6182-GLM_Systema-Sanscriticum_hermes-school-llm-boundary-pii-proof_06.10.26.md) |
| Human-send (TG + web, human actor proof) | H6183, флаг `HERMES_HUMAN_SEND` дефолт OFF | `config/features.php:2033`, `app/Services/Support/HumanSend/SupportHumanSendService.php` |
| Золотой набор первой волны (6 ролей + маршрутизация) | H6181 — обслуживает только первую волну, не D/J-наборы учебки | [H6181](https://github.com/gasyoun/Uprava/blob/main/handoffs/H6181-GLM_Systema-Sanscriticum_hermes-w1-synthetic-golden-cases_06.10.26.md) |
| Маршрутизация обращений | H6190 — общий слой поддержки, не создаёт A-канонических ID | [H6190](https://github.com/gasyoun/Uprava/blob/main/handoffs/H6190-GLM_Systema-Sanscriticum_hermes-w1-shared-routing-layer_06.10.26.md) |
| Измерительная программа 90 дней | H6120 — claimed, владение не переносится | [H6120](https://github.com/gasyoun/Uprava/blob/main/handoffs/H6120-GLM_Uprava_school-agents-90-day-measurement_05.10.26.md) |

## 5. Нулевой прод-след фазы-1 (центза 07-11-2026)

- Флаги agent-запуска ролей A в `config/features.php`: **0**. Известные флаги-омонимы: `marketing_segments` (features.php:1096, GC-A1 сегментный движок H1637, дефолт OFF, read-only Filament-мост, не агент); `hermes_schedule_keeper` (2019) и `hermes_human_send` (2033) — флаги поддержки W1, оба дефолт OFF, к департаменту A не относятся.
- Существующие deterministic-планировщики CRM-контура (`leads:infer-source` 03:35, `leads:remind-followup` 08:00 и т.д. в `SchedulesOvernightAndCrm.php`) — утилиты estate, их сегодня обслуживают люди; это ровно те поверхности, на которых завтра стартуют A7/A8/A11. **Агент-обёрток над ними в фазе-1 не добавлено**: ни одного нового Schedule-задания, ни одного LLM-вызова в этом PR.
- Команды-консьюмеры (`InferLeadSources`, `LinkWebinarParticipants`, `RemindLeadsForFollowup`, `DeliverWebinarRecordings`, `DeliverDueLeadMagnets`, `DetectPayoutAttributions`, `app/Support/AcquisitionAttribution`) — существующие deterministic-утилиты; изменений нет.
- Исходящих сообщений агентом: 0 (Q3/Q9 не активированы; human-send-слой OFF дефолтом).

## 6. Границы

- A-роли не наследуют msi-online; сам gate зонтика E026 этим дополнением не меняется (scope-заметка H6100 от 06-10).
- Границы 90-дневной измерительной программы — согласование внутри claimed H6120; новые дети не наследуют его 90-дневное владение.
- Любое расширение после W1 требует двух недель без Sev-инцидентов **и** доказанного снижения человеческой нагрузки (уточнение 05-10); это относится и к A-ролям на их W4+.

## Delivery

- **Changed:** этот документ (департаментный контур H6100); ничего в коде, флагах, планировщиках.
- **Unchanged:** все 12 A-ролей — подготовка; прода и исходящих сообщений 0; Hermes/поддержка W1 не тронута.
- **Checks:** центзы §5 — agent-флаги A = 0, новые планировщики/LLM-вызовы = 0, hermes/marketing-флаги дефолт OFF.
- **Risks:** drift между этим документом и ростером при новой визе MG; лечится ссылкой на источник и центзой при каждом W4+ визе.
- **Inspect:** `grep -n "marketing_segments\|hermes_" config/features.php`; `grep -n "Agent\|agent" app/Console/Concerns/SchedulesOvernightAndCrm.php | grep -i marketing`; `grep -rn "marketing" app/Console/Concerns/*.php | grep -v -i "H1637\|H5021\|H3916"`; этот документ.

_Гасунс_ · H6100 · OxAlpha (opencode/glm-5.3-flash-free)
