# Архитектура: школа-агенты, департамент J «Санскрит-ядро» (2 роли) — фаза-1 (H6112)

_Created: 25-11-2026 · Last updated: 26-11-2026_

Исполнение handoff [H6112-OxAlpha_…school-agents-dept-j-sanskrit-core](https://github.com/gasyoun/Uprava/blob/main/handoffs/H6112-OxAlpha_Systema-Sanscriticum_school-agents-dept-j-sanskrit-core_05.10.26.md), эпик [E026](https://github.com/gasyoun/Uprava/blob/main/handoffs/epics/E026-Uprava_school-agents-program_05.10.26.md).

Ростеры и решения:

- Ростер v1 (роли департамента J): [FLEET_SCHOOL_AGENTS_92_ROSTER_GRILL_04-10-2026](https://github.com/gasyoun/Uprava/blob/main/docs/FLEET_SCHOOL_AGENTS_92_ROSTER_GRILL_04-10-2026.md), раздел «J. Санскрит-ядро (2)» — роли 91–92.
- Ростер v2 (волны и гейты): [FLEET_SCHOOL_AGENTS_92_ROSTER_V2_2026-10-05](https://github.com/gasyoun/Uprava/blob/main/docs/FLEET_SCHOOL_AGENTS_92_ROSTER_V2_2026-10-05.md) — W4+: «контент/комьюнити/аналитика/E–J — за квотой фазы-1, отдельно по визе».
- Диспозиция 92 ID: [SCHOOL_AGENTS_92ID_DISPOSITION_2026-10-05](https://github.com/gasyoun/Uprava/blob/main/reports/SCHOOL_AGENTS_92ID_DISPOSITION_2026-10-05.md) — ID 91/92, профиль УЧ, «не запущено».
- Карта детей Hermes 24: [HERMES_GLM_24_HANDOFFS_2026-10-06](https://github.com/gasyoun/Uprava/blob/main/reports/HERMES_GLM_24_HANDOFFS_2026-10-06.md).
- Инвариант прод-записи: H6084 — ничего из ростера не пишет в прод школы без гейта волны.
- Prior art департаментных контуров (тот же паттерн, разные юниты, `--allow-dup` против topic-overlap H6100 подтверждён): [департамент A, H6100](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/ARCHITECTURE_SCHOOL_AGENTS_DEPT_A_MARKETING_PHASE1_H6100_2026-11-07.md), [департамент D, H6105](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/ARCHITECTURE_SCHOOL_AGENTS_DEPT_D_CHECKING_FEEDBACK_PHASE1_H6105_2026-11-24.md).

## 1. Вердикт фазы-1 и место квоты (Q2)

Ростер v2 §«Волны»: кандидаты фазы-1 — блок B+C+D (32 роли) на квоту 25; департамент J (2 роли, ID 91–92) в этот блок **не входит** и отнесён к W4+: «за квотой фазы-1, отдельно по визе» (решение MG по итогам W1–W3). По диспозиции обе роли J — профиль УЧ, статус «не запущено», волны 3 (ID 91) и 2 (ID 92) в нумерации v1; волна v2 для J назначается отдельной визой и до неё не запускаются.

⇒ Фаза-1 департамента J — **подготовка, а не запуск и не захват 25 слотов**: гейто-упаковка двух ролей, карта переиспользования, нулевой прод-след. Ни одна J-роль не получает флаг запуска, планировщика, LLM-вызова или исходящего сообщения. «Роли фазы-1 в общую квоту 25 (Q2)» из миссии H6112 читается как требование гейто-готовности; фактическое включение в квоту — только по визе MG после W1–W3 и после честного замера токенов.

**Квотные числа для J не вводятся.** Правило [config/school_role_quota.php](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/config/school_role_quota.php) (H6174): allowances — только измеренные; неизмеренная квота = TBD и log-only, прод-числа не выдумываются. J вне квоты фазы-1 и не имеет измерения ⇒ в этом изменении **нет ни одной новой строки allowances**, ни одного нового LLM-маршрута.

## 2. Роли департамента J и estate-поверхности

| # | Роль (ID) | Тип | Волна (диспозиция) | Статус | Существующая поверхность в estate (prior art — не строить заново) |
|---|---|---|---|---|---|
| J1 | upanishad-quote-butler (91) | 🧠 | 3 | УЧ, не запущено | Корпуса Упанишад в [SamudraManthanam `web/corpus_builder/jsonl/`](https://github.com/gasyoun/SamudraManthanam/tree/main/web/corpus_builder/jsonl) (`jabala-up.jsonl`, `mun-up.jsonl` и др.), `web/app/popular_terms.py` и word-of-day-тесты; учебный контур — [CAMPAIGN_RECORD_UP26_UPANISHADY_WEBINAR_04-10-2026](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/CAMPAIGN_RECORD_UP26_UPANISHADY_WEBINAR_04-10-2026.md). Локусы и цитаты уже обслуживаются людьми/детерминированными сборками; агент-обёртка не добавляется |
| J2 | taught-vs-corpus-kantha (92) | ⚙️ | 2 | УЧ, не запущено | Набор [kosha `vocab-curriculum`](https://github.com/gasyoun/kosha/blob/main/data/manifest/datasets.json) (id `vocab-curriculum`, CC BY-SA 4.0, 6 667 строк, H947) с потребителем «Uprava stenogrammy-teaching-glossary crosswalk (H4813 Pair 13 taught-vs-corpus axis, 19-09-2026)»; техника — skill `crosswalk-build` (`form_key()`, tiered matching, license check, kosha manifest). Ось «что учим vs что в корпусе» (1 513 лемм Pair 13) уже построена; дубль-кроссволк не строится |

Обе поверхности уже существуют и обслуживаются человеком; «агент-фаза» роли стартует только после визы W4+ и гейтов §3. Исключённых ролей в J нет (kill-candidates 8/39/42 — другие департаменты). Локальные поверхности того же prior art (не дубли): контракт `reading_pack_v1.sentences[].locus` (`SubhashitaReadingPackAdapter`, `ReadingPackController`, замороженные фиды `nala_subhashita`/`hitopadesa`) для J1; `SanskritGlossary`/`sa_ru_glossary.json` (DCS-attested) и teaching-glossary — для J2.

## 3. Гейто-упаковка (Q2–Q9) — как применяется к департаменту J

| Гейт | Правило (решения 05-10) | Для J в фазе-1 |
|---|---|---|
| Q2 квота/волны | фаза-1 = 25 агентов, запуск 5–7/нед | J — W4+, вне квоты 25; включение только по визе MG по итогам W1–W3. Квотные allowances J — TBD до Measurements ⇒ log-only, без новых трат |
| Q3 запись в прод | suggester: агент готовит — человек отправляет; первые 30 дней human-send | Любой локус/цитата (J1) или отчёт кроссволка (J2) — только черновик до визы, затем human-send-слой H6183. **0 прод-писем до гейта волны** (H6084); публикация в канал/на сайт агентом запрещена |
| Q5 pii-guard | фаза 0: маскинг до любого LLM-вызова; без обёртки роль не запускается (152-ФЗ, фенс H2641) | J2 работает на оси stenogrammy-teaching-glossary: локальное чтение стенограмм разрешено, но публикация/экспорт тел и списков путей в публичную поверхность запрещены; до LLM-вызова — маскер `app/Services/Support/SupportStudentPiiMasker.php`, fail-closed (H6144/H6182). J1: даже публичные цитаты идут под обёрткой, если в запросе/черновике есть ПДн студента или куратора. «Чистых от PII» ролей в J нет |
| Q6 скидки | полный запрет автономии, решает MG | J-роли не касаются денег: ни скидки, ни рассрочки, ни баланса; только предложить, решить MG. Никакой скидочной автономии (запрет без оговорок) |
| Q7 токены | killgate-слой H5867 + лимиты по ролям | Для J1 (🧠) — H5867 + per-role лимиты H6174; для J2 (⚙️) — детерминированный контур, LLM-вызовов нет. J-allowances неизмерены ⇒ log-only, «без новых трат», подписочная квота z.ai; новый платный fallback не вводится |
| Q8 учебка | золотые наборы + выборка MG (gold-adjudicate-паттерн) | Golden-набор W1 H6181 обслуживает **только** первую волну поддержки и **не заменяет J/D-наборы учебки**. J нужен отдельный golden-cut: для J1 — локусы/цитаты сверены с источником, для J2 — ручная проверка построенного кроссволка на golden-строках оси 1 513 лемм; расхождение агента с эталоном = роль не запускается. Выборка MG обязательна |
| Q9 голос | tone-гайд + редколлегия скиллов; обязательный litredaktor-пасс отменён | Исходящие локусы/отчёты J — черновик по tone-гайду → редколлегия скиллов (litredaktor — инструмент, не гейт) → human-send. Ни одной публикации агентом |
| Q13 KPI | скорость первого ответа + доля закрытых без человека; NPS-стража | Измеряется при запуске W4+; «доля закрытых без человека» = human-send-статистика, не автономная отправка, учёт через `DraftReviewMinutesLedger.php`. До визы KPI-базлайны не снимаются (нет потока) |
| Q4 канал | TG + веб сразу | При запуске — TG-лейн и web-поверхность (кабинет/страница курса) параллельно; в фазе-1 ни того, ни другого агент не пишет |

## 4. Карта переиспользования — общая техника уже доставлена, дублей не строим (пакет Hermes 06-10)

Лежит в estate (origin/main на 25-11-2026), повторно не реализуется:

| Общая техника | Готовность | Ссылка |
|---|---|---|
| Task-level killgate | реализация H5867; интеграционная проверка H6173 | [H6173](https://github.com/gasyoun/Uprava/blob/main/handoffs/H6173-GLM_Uprava_hermes-task-killgate-integration-proof_06.10.26.md) |
| Подписочная квота по школьным ролям | H6174, `config/school_role_quota.php` + `SchoolRoleQuotaService` | [H6174](https://github.com/gasyoun/Uprava/blob/main/handoffs/H6174-GLM_Systema-Sanscriticum_hermes-school-role-quota-enforcement_06.10.26.md) |
| PII-маскинг на границах LLM | H6144 реализация, H6182 проверка TG+web | [H6182](https://github.com/gasyoun/Uprava/blob/main/handoffs/H6182-GLM_Systema-Sanscriticum_hermes-school-llm-boundary-pii-proof_06.10.26.md) |
| Human-send (TG + web, human actor proof) | H6183, флаг `HERMES_HUMAN_SEND` дефолт OFF | `config/features.php:2154`, `app/Services/Support/HumanSend/SupportHumanSendService.php` |
| Золотой набор первой волны (6 ролей + маршрутизация) | H6181 — только W1, **не заменяет J/D-наборы учебки** | [H6181](https://github.com/gasyoun/Uprava/blob/main/handoffs/H6181-GLM_Systema-Sanscriticum_hermes-w1-synthetic-golden-cases_06.10.26.md) |
| Маршрутизация обращений | H6190 — общий слой поддержки, не создаёт J-канонических ID | [H6190](https://github.com/gasyoun/Uprava/blob/main/handoffs/H6190-GLM_Systema-Sanscriticum_hermes-w1-shared-routing-layer_06.10.26.md) |
| Измерительная программа 90 дней | H6120 — claimed, владение не переносится | [H6120](https://github.com/gasyoun/Uprava/blob/main/handoffs/H6120-GLM_Uprava_school-agents-90-day-measurement_05.10.26.md) |
| Корпус Цитат/Упанишад и частотный словарь | SamudraManthanam `web/corpus_builder/jsonl/`, kosha `vocab-curriculum` (H947) / Pair 13 (H4813) | §2 этой таблицы; skill `crosswalk-build` |

Общие инструменты и проверки департамента берутся у детей из карты Hermes ([HERMES_GLM_24_HANDOFFS_2026-10-06](https://github.com/gasyoun/Uprava/blob/main/reports/HERMES_GLM_24_HANDOFFS_2026-10-06.md)); в пакете 06-10 **собственных новых ролей департамента J нет** — повторная реализация запрещена, исходный scope двух ролей сохранён.

## 5. Нулевой прод-след фазы-1 (ценз 25-11-2026)

- Флаги agent-запуска ролей J в `config/features.php`: **0**. Ценз: `grep -nE "upanishad|quote_butler|kantha|taught_vs_corpus|sanskrit_core" config/features.php` → нет совпадений (exit 1).
- Известные омонимы (не агенты J): `kosha_srs` / `subhashita_srs` / `hindi_my_srs_deck` — детерминированные SRS-утилиты estate; `support_answer_suggester` и `student_agent` — существующие ограниченные LLM-контуры поддержки/клуба, оба дефолт OFF, к департаменту J не относятся.
- Флаги `hermes_*` (2140–2196): ровно 5 — `HERMES_SCHEDULE_KEEPER`, `HERMES_HUMAN_SEND`, `HERMES_FAQ_RAG`, `HERMES_ACCESS_DIAGNOSTICS`, `HERMES_PAYMENT_RECONCILIER` — роли W1 поддержки, все `env(…, false)`, к J не относятся.
- Корпуса SamudraManthanam, `vocab-curriculum`, кроссволк Pair 13, word-of-day — существующие данные/движки, обслуживаются людьми. **Агент-обёрток над ними в фазе-1 не добавлено**: ни одного нового Schedule-задания, ни одного LLM-вызова, ни одного нового флага.
- `config/school_role_quota.php`: **без изменений** в этом PR — новых allowances J не введено (§1), diff PR = ровно один doc-файл.
- Исходящих сообщений и публикаций агентом: 0 (Q3/Q9 не активированы, human-send OFF дефолтом, 0 прод-писем до гейта волны — H6084).

**Dual-run residual (update 26-11-2026):** ветка `origin/h6112-sanskrit-core-roles` (`1b273bbf`, 24-11) правит `config/school_role_quota.php` — allowances `sanskrit-core-reader`=2 / `sanskrit-core-exporter`=1, «Total 14»; нет PR, merge, измерения или визы W4+, имена не совпадают с каноном 91/92. Ветка **не evidence** и в PR не входит; удаление — человеческий шаг, GTD `@DO` заведена 26-11-2026. Два независимых прохода 25-11 (`h6112-drain` и `h6112-dept-j-sanskrit-core-architecture`, commit `a0c0c3d8`) дали один документ; финальный текст объединяет их prior-art (внешние Samudra/kosha-корпуса и локальные локус/DCS-поверхности), force-push не применялся, чужой live-claim не снимался.

## 6. Границы

- J-роли не наследуют msi-online; gate зонтика E026 этим дополнением не меняется (scope-заметка H6112 от 06-10).
- Границы 90-дневной измерительной программы — согласование внутри claimed H6120; новые дети не наследуют его 90-дневное владение.
- Golden-набор W1 (H6181) обслуживает только первую волну; **не заменяет J/D-наборы учебки** — департаменту J нужен отдельный golden-cut, без которого W4+ не стартует.
- Stenogrammy: локальное чтение разрешено, публикация/экспорт тел и списков путей — закрыты; кроссволк J2 не должен выносить ПДн в публичную поверхность.
- Департамент J не разделяет topic-overlap с H6100 (департамент A) и H6105 (департамент D): разные юниты ростера, `--allow-dup` против наложения подтверждён условиями H6112.
- Любое расширение после первых семи требует двух недель без Sev-инцидентов **и** доказанного снижения человеческой нагрузки (уточнение 05-10); это относится и к J-ролям на их визе W4+.
- H6176–H6180/H6191 закрыты на согласование границ с claimed H6120; этот документ границы не переопределяет.

## Delivery

- **Changed:** этот документ (департаментный контур H6112: 2 роли, гейты Q2–Q9/Q13/Q4, карта переиспользования, нулевой прод-след). Ничего в коде, флагах, планировщиках, квотных числах.
- **Unchanged:** роли 91/92 остаются «не запущено»; прода, исходящих сообщений и LLM-вызовов 0; SamudraManthanam-корпуса, kosha `vocab-curriculum`, Pair 13, Hermes-W1, `config/school_role_quota.php` не тронуты; чужой claim не снят.
- **Checks:** ценз §5 — `grep -nE "upanishad|quote_butler|kantha|taught_vs_corpus|sanskrit_core" config/features.php` → exit 1; `grep -n "hermes_" config/features.php` → 5 флагов, все `env(..., false)`; `git show --stat` PR → ровно 1 doc-файл.
- **Risks:** подмена канонических ID 91/92 именами из не-merged ветки — в §5 явно помечено как residual; риск принять W1 golden за J-набор учебки — в §4/§6 явно запрещено; риск утечки ПДн из стенограммного кроссволка — в §3/§6 задан pii-guard фазы 0 и запрет экспорта.
- **Inspect:** `git show --stat` этого PR; этот документ §1 (вердикт квоты), §5 (ценз и dual-run residual); `grep` из §5.

_к.ф.н. М.Ю. Гасунс_ · H6112 · OxAlpha (MiMo v2.6 Flash, ollama-cloud/mimo-v2.6-flash)
