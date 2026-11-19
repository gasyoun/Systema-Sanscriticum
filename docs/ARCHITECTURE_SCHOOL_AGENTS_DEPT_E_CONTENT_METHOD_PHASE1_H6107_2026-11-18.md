# Архитектура: школа-агенты, департамент E «Контент и методика» — фаза-1 (H6107)

_Created: 18-11-2026 · Last updated: 18-11-2026_

Исполнение handoff [H6107-OxAlpha_…school-agents-dept-e-content-method](https://github.com/gasyoun/Uprava/blob/main/handoffs/H6107-OxAlpha_Systema-Sanscriticum_school-agents-dept-e-content-method_05.10.26.md), эпик [E026](https://github.com/gasyoun/Uprava/blob/main/handoffs/epics/E026-Uprava_school-agents-program_05.10.26.md).

Ростеры и решения:

- Ростер v1 (роли департамента E): [FLEET_SCHOOL_AGENTS_92_ROSTER_GRILL_04-10-2026](https://github.com/gasyoun/Uprava/blob/main/docs/FLEET_SCHOOL_AGENTS_92_ROSTER_GRILL_04-10-2026.md), раздел «E. Контент и методика (10)».
- Ростер v2 (волны и гейты): [FLEET_SCHOOL_AGENTS_92_ROSTER_V2_2026-10-05](https://github.com/gasyoun/Uprava/blob/main/docs/FLEET_SCHOOL_AGENTS_92_ROSTER_V2_2026-10-05.md).
- Диспозиция 92 ID: [SCHOOL_AGENTS_92ID_DISPOSITION_2026-10-05](https://github.com/gasyoun/Uprava/blob/main/reports/SCHOOL_AGENTS_92ID_DISPOSITION_2026-10-05.md).
- Карта детей Hermes 24: [HERMES_GLM_24_HANDOFFS_2026-10-06](https://github.com/gasyoun/Uprava/blob/main/reports/HERMES_GLM_24_HANDOFFS_2026-10-06.md).
- Инвариант прод-записи: H6084 — ничего из ростера не пишет в прод школы без гейта волны.
- Prior art департаментного контура: [ARCHITECTURE_SCHOOL_AGENTS_DEPT_A_MARKETING_PHASE1_H6100](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/ARCHITECTURE_SCHOOL_AGENTS_DEPT_A_MARKETING_PHASE1_H6100_2026-11-07.md) (департамент A, тот же паттерн).

## 1. Вердикт фазы-1 и место квоты (Q2)

Ростер v2 §«Волны»: кандидаты фазы-1 — блок B+C+D (32 роли) на квоту 25; контент/комьюнити/аналитика идут W4+ «за квотой фазы-1, отдельно по визе». По диспозиции все 10 ролей E — профиль УЧ, волны 2–3, в состав квоты фазы-1 **не входят**.

⇒ Фаза-1 департамента E — это **подготовка, а не запуск**: гейто-упаковка ролей, карта переиспользования, нулевой прод-след. Ни одна из 10 ролей E не получает флаг запуска, планировщика, LLM-вызова или исходящих сообщений в этой фазе. Любой будущий запуск E-роли — W4+ по отдельной визе MG со всеми гейтами Q3–Q9; W3-составляющие ролей (учебка-движки Q10 как ядро волны 3) стартуют только после сбора золотых наборов и работающей выборки MG.

Изменений в ростере это не вносит — фиксирует его следствие на уровне репозитория: фаза-1 департамента E = подготовка, прод-запуск W4+ по визе.

## 2. Роли департамента E и estate-поверхности

| # | Роль | Тип | Существующая поверхность в estate (prior art — не строить заново) |
|---|---|---|---|
| E1 | curriculum-architect (45) | 🧠 | [docs/SANSKRIT_HUB_LEARNER_PROGRESSION_A0_C2](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/SANSKRIT_HUB_LEARNER_PROGRESSION_A0_C2.md) (рунги A0–C2), `Course`/`CourseBlock`/`Lesson`, skill methodolog |
| E2 | lesson-plan-writer (46) | 🧠 | `Lesson` + материалы уроков, skills methodolog/methodichka; планы — по методичке школы |
| E3 | exercise-forge (47) | 🧠 | `public/lila` (движки cloze/match/sort/table), `CourseQuiz*`, `SrsDeck`/`SrsCard`, `app/Services/Srs/*` (DistractorSampler, LessonFlashCardsSync), skill anki-srs-import ([MANUAL_AGENT_ANKI_SRS_IMPORT](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/MANUAL_AGENT_ANKI_SRS_IMPORT.md)) |
| E4 | reading-grader (48) | 🧠 | skill reader-layer (kosha), `/reading/kosha-demo` вендорный статический фид (config/features.php:564), [PRODUCT_START_CHTENIYA_AKRO_STYLE_2026](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/PRODUCT_START_CHTENIYA_AKRO_STYLE_2026.md) |
| E5 | glossary-keeper (49) | ⚙️ | `TeachingGlossaryCourse`/`TeachingGlossaryTerm`, `resources/data/sa_ru_glossary.json` (DCS-attested, собственный); права — только свободные слои Cologne/kosha |
| E6 | translation-editor (50) | 🧠 | skills klammeruebersetzung/sanskrit-parse; переводческие поверхности SanskritGrammar/CommentaryStrategies — вне этого репозитория |
| E7 | litredaktor-pass (51) | 🧠 | skill litredaktor; **обязательный пасс отменён решением Q9** («недостаточно умный») — роль остаётся в ростере как один из инструментов редколлегии, не как гейт |
| E8 | lecture-cutter (52) | 🔄 | `LectureClip` + `DispatchLectureClipExtractionJob`, `ContentCandidate`/`ContentCandidateSync` (флаг `content_from_lectures`, Wave 1 of 5), `clip_marketing` (n8n/ffmpeg нарезка, config/features.php:1015) |
| E9 | subtitle-agent (53) | ⚙️ | skill whisper-dictation; транскрипт-prior art: `HindiTranscriptDrillExtractor`; записями владеет contour `app/Services/Recordings/*` |
| E10 | slide-maker (54) | ⚙️ | skills viz-page/diagram-design, шрифт Charis (mg-brand-font), `CourseDesignAsset`/`LessonBannerTemplate` как шаблон-поверхности |

Все поверхности уже обслуживаются людьми/скриптами; агент-фаза каждой роли стартует только после визы W4+ и гейтов ниже.

## 3. Гейто-упаковка (Q3–Q9) — как применяется к департаменту E

| Гейт | Правило (решения 05-10) | Для E в фазе-1 |
|---|---|---|
| Q3 запись в прод | suggester: агент готовит — человек отправляет; первые 30 дней human-send | Ни один исходящий E-материал (пост, урок, глоссарий-публикация) не существует до визы; потом — через human-send-слой H6183. Публикация контента в ВК/ORS-календарь — отдельные флаги (`content_calendar_autopilot`), не агент-лейна |
| Q5 pii-guard | фаза 0: маскинг до любого LLM-вызова; без обёртки роль не запускается (152-ФЗ, фенс H2641) | Обязателен там, где материал привязан к студенту: персональные колоды E3 (личные ошибки), черновики по реальным домашкам; готовый маскер: `app/Services/Support/SupportStudentPiiMasker.php`. Чисто корпусные роли (E4, E5 на свободных слоях) — без студенческих данных, но обёртка обязательна для любого LLM-вызова по правилу фазы 0 |
| Q6 скидки | полный запрет автономии, решает MG | Запрещено без оговорок для всех E-ролей (контент-роли не касаются денег вовсе; гейт фиксируется для полноты) |
| Q7 токены | killgate-слой H5867 + лимиты по ролям | Для всех 🧠/🔄 ролей E — слой H5867 + per-role лимиты из квотной машины H6174; генеративные роли E (E1–E3, E6) — главные потребители квоты департамента |
| Q8 учебка | золотые наборы + выборка MG (gold-adjudicate-паттерн) | Центральный гейт департамента: учебный контент (планы, упражнения, градуированные тексты) допускается к студенту только после golden-набора E и выборки MG. W1 golden-набор H6181 обслуживает только первую волну поддержки и **не заменяет** E/D/J-наборы учебки — для E нужен отдельный golden-cut |
| Q9 голос | tone-гайд + редколлегия скиллов; обязательный litredaktor-пасс отменён | Исходящие/публикуемые E-материалы: черновик по tone-гайду → редколлегия скиллов (litredaktor — один из инструментов, не гейт) → human-send |
| Q13 KPI | скорость первого ответа + доля закрытых без человека; NPS-стража | Применяется при визе W4+ к ролям с внешним студенческим следованием (E1–E3 черновики для кураторов/преподавателей) |
| Q4 канал | TG + веб сразу | E-контент публиковать параллельно: TG-лейна + web-поверхности (кабинет, /koloda, /reading) |

## 4. Карта переиспользования — общая техника доставлена, дублей не строим (пакет Hermes 06-10)

Лежит в этом репозитории (origin/main на 18-11-2026), повторно не реализуется:

| Общая техника | Готовность | Ссылка |
|---|---|---|
| Task-level killgate | реализация H5867; интеграционная проверка H6173 | [H6173](https://github.com/gasyoun/Uprava/blob/main/handoffs/H6173-GLM_Uprava_hermes-task-killgate-integration-proof_06.10.26.md) |
| Подписочная квота по школьным ролям | H6174 | [H6174](https://github.com/gasyoun/Uprava/blob/main/handoffs/H6174-GLM_Systema-Sanscriticum_hermes-school-role-quota-enforcement_06.10.26.md) |
| PII-маскинг на границах LLM | H6144 реализация, H6182 проверка TG+web | [H6182](https://github.com/gasyoun/Uprava/blob/main/handoffs/H6182-GLM_Systema-Sanscriticum_hermes-school-llm-boundary-pii-proof_06.10.26.md) |
| Human-send (TG + web, human actor proof) | H6183, флаг `HERMES_HUMAN_SEND` дефолт OFF | `config/features.php:2069`, `app/Services/Support/HumanSend/SupportHumanSendService.php` |
| Золотой набор первой волны (6 ролей + маршрутизация) | H6181 — обслуживает только первую волну, не E/D/J-наборы учебки | [H6181](https://github.com/gasyoun/Uprava/blob/main/handoffs/H6181-GLM_Systema-Sanscriticum_hermes-w1-synthetic-golden-cases_06.10.26.md) |
| Маршрутизация обращений | H6190 — общий слой поддержки, не создаёт E-канонических ID | [H6190](https://github.com/gasyoun/Uprava/blob/main/handoffs/H6190-GLM_Systema-Sanscriticum_hermes-w1-shared-routing-layer_06.10.26.md) |
| Измерительная программа 90 дней | H6120 — claimed, владение не переносится | [H6120](https://github.com/gasyoun/Uprava/blob/main/handoffs/H6120-GLM_Uprava_school-agents-90-day-measurement_05.10.26.md) |

## 5. Нулевой прод-след фазы-1 (ценз 18-11-2026)

- Флаги agent-запуска ролей E в `config/features.php`: **0**. Известные флаги-омонимы: `teaching_glossary` (1554, deterministic `teaching-glossary:import` из зарегистрированного агрегата, дефолт OFF, не агент); контент-флаги — deterministic-утилиты estate, не школьные агенты: `content_from_lectures` (1026, LectureClip→ContentCandidate, Wave 1 of 5), `clip_marketing` (1015, n8n/ffmpeg нарезка), `content_calendar` (1035), `content_calendar_autopilot` (1041) — все дефолт OFF. Флаги `hermes_*` (2055–2111) — роли поддержки W1, все дефолт OFF, к департаменту E не относятся.
- SRS-контур (`app/Services/Srs/*`, `/koloda`) — существующие utilities, обслуживаются людьми; движки `public/lila` — статические упражнения. **Агент-обёрток над ними в фазе-1 не добавлено**: ни одного нового Schedule-задания, ни одного LLM-вызова, ни одного нового флага в этом PR.
- Глоссарии (`TeachingGlossary*`, `sa_ru_glossary.json`) — существующие таблицы/фиды; изменений нет.
- Исходящих сообщений и публикаций агентом: 0 (Q3/Q9 не активированы; human-send-слой OFF дефолтом).

## 6. Границы

- E-роли не наследуют msi-online; сам gate зонтика E026 этим дополнением не меняется (scope-заметка H6107 от 06-10).
- Границы 90-дневной измерительной программы — согласование внутри claimed H6120; новые дети не наследуют его 90-дневное владение.
- Любое расширение после W1 требует двух недель без Sev-инцидентов **и** доказанного снижения человеческой нагрузки (уточнение 05-10); это относится и к E-ролям на их W4+.
- Правовая дисциплина E5 (glossary-keeper): сверка только со свободными слоями Cologne/kosha; лицензионно-гейтованные слои — через license-gated-ingest, не копированием.
- Роль E7 (litredaktor-pass) не восстанавливает отменённый обязательный пасс: Q9 заменяет его на tone-гайд + редколлегию.

## Delivery

- **Changed:** этот документ (департаментный контур H6107); ничего в коде, флагах, планировщиках.
- **Unchanged:** все 10 E-ролей — подготовка; прода, исходящих сообщений и LLM-вызовов 0; SRS/koloda/lila/глоссарии/Hermes-W1 не тронуты.
- **Checks:** центзы §5 — agent-флаги E = 0 (`rg -n "curriculum|exercise|glossary|subtitle|slide" config/features.php` → 0 agent-флагов), контент-флаги `content_from_lectures`/`clip_marketing`/`content_calendar*` дефолт OFF, `hermes_*` дефолт OFF; новых Schedule-заданий/LLM-вызовов в этом PR нет (diff = 1 doc-файл).
- **Risks:** drift между этим документом и ростером при новой визе MG; лечится ссылкой на источник и центзой при каждом W4+ визе. Риск подмены: контент-флаги estate (`content_from_lectures`) не объявляются агентными ролями E8 — они утилиты, решение о запуске роли отдельно.
- **Inspect:** `rg -n "content_from_lectures|clip_marketing|content_calendar" config/features.php`; `rg -n "hermes_" config/features.php | head`; `git show --stat` этого PR (ровно один doc-файл); этот документ §5.

_Гасунс_ · H6107 · OxAlpha (opencode/glm)
