# Школа-агенты: департамент C «Учебные операции» — реестр фазы-1 + роли 27/28 в тени (H6103, OxAlpha, GLM 5.3; 18-11-2026)

_Created: 18-11-2026 · Last updated: 18-11-2026_

Реестр департамента C ростера 92 — [config/school_learning_ops.php](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/config/school_learning_ops.php): все 12 канонических ролей §C (ID 23–34, ростер v1), волна (23 → W1, остальные → W2 по карте [SCHOOL_AGENTS_W2_READONLY_SURFACE](https://github.com/gasyoun/Uprava/blob/main/docs/SCHOOL_AGENTS_W2_READONLY_SURFACE_2026-10-05.md) §1: 7+18=25 квота фазы-1 Q2), read-only таблицы по строкам, гейты Q3/Q5/Q6/Q7/Q9 построчно (проводка гейтов — правило карты §4.8), статусы implemented/pending. Роль 23 `schedule-keeper` закрыта ребёнком H6187 и не дублируется (правило зонтика H6103: принимать evidence детей).

Первая реализованная пара W2-жителей департамента — детерминированные suggester-сервисы без LLM:

- Роль 27 `deadline-butler` ([DeadlineButlerService](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Services/Support/Hermes/LearningOps/DeadlineButlerService.php)): мягкие напоминания о домашних заданиях по тону школы; дедлайны честные — в `lessons` нет даты сдачи, есть только закрытие приёма `homework_closed_at`, черновик сроков не выдумывает; закрытый приём без сдачи уходит в `requires_human_verification` (вопрос человеку, не давление на студента); ни одного LLM-вызова, ни одной записи.
- Роль 28 `progress-tracker` ([ProgressTrackerService](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Services/Support/Hermes/LearningOps/ProgressTrackerService.php)): недельный дайджест прохождения группы куратору; завершаемость только по `lesson_user.is_completed` (H3764 — `lesson_views.is_completed` не заполняется, севшая туда метрика покажет честный ноль); красный флаг «0 пройденных при стаже 14+ дней» (порог в реестре, не хардкод в сервисе).

Оба флага `HERMES_DEADLINE_BUTLER` / `HERMES_PROGRESS_TRACKER` OFF по умолчанию: гейт волны W2 («2 недели без Sev-инцидентов на W1», ростер V2) не снят, W1 не запущен — прод-писем нет, исходящие только через human-send H6183 (Q3 suggester). Q6: в черновиках нет денег/скидок; Q7: deterministic = ноль токенов квоты (killgate H5867 и машина квот H6174 — общие слои, не дублируются). Приёмка — [H6103HermesLearningOpsTest](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/tests/Feature/Support/H6103HermesLearningOpsTest.php): золотые черновики обеих ролей, OFF-дефолты, read-only инварианты (счётчики таблиц до/после), Q6-регекс на деньги, соответствие реестра канону §C.

_Dr. Mārcis Gasūns_
