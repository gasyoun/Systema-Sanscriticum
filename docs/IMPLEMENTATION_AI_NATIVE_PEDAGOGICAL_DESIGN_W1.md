# Реализация W1 — схема модели и её привязка к данным Systema

_Created: 12-08-2026 · Last updated: 31-08-2026_

**Область W1.** Довести машиночитаемый слой модели до состояния, в котором его можно
валидировать и в котором каждое утверждение указывает на реальную таблицу Systema.
**Кода приложения в W1 нет** — ни миграций, ни экранов, ни изменений в работающих
ИИ-фичах. Артефакты W1 — файлы в `docs/` и один скрипт-линтер.

Модель: [ARCHITECTURE_AI_NATIVE_PEDAGOGICAL_DESIGN_MODEL.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/ARCHITECTURE_AI_NATIVE_PEDAGOGICAL_DESIGN_MODEL.md) ·
пробы: [VERIFICATION_AI_NATIVE_PEDAGOGICAL_DESIGN.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/VERIFICATION_AI_NATIVE_PEDAGOGICAL_DESIGN.md)

---

## 1. Что уже сделано в этом проходе

| Артефакт | Файл | Состояние |
|---|---|---|
| Прозаическая модель | [ARCHITECTURE_AI_NATIVE_PEDAGOGICAL_DESIGN_MODEL.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/ARCHITECTURE_AI_NATIVE_PEDAGOGICAL_DESIGN_MODEL.md) | готово, v0.1 |
| Машиночитаемое ядро | [docs/schema/ai_native_pedagogy_model.yaml](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/schema/ai_native_pedagogy_model.yaml) | готово, 17 операций, 4 функции оценивания, 5 пилотных норм |
| Структурный валидатор | [docs/schema/ai_native_pedagogy_model.schema.json](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/schema/ai_native_pedagogy_model.schema.json) | готово, JSON Schema 2020-12 |
| Пробы на данных | [VERIFICATION_AI_NATIVE_PEDAGOGICAL_DESIGN.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/VERIFICATION_AI_NATIVE_PEDAGOGICAL_DESIGN.md) | описаны, не запускались |

---

## 2. Что делает линтер (единственный код W1)

JSON Schema проверяет форму. Смысловые инварианты модели она выразить не может — их
проверяет отдельный скрипт `tools/lint_pedagogy_model.py` (пишется в W1):

| Проверка | Инвариант | Почему не в JSON Schema |
|---|---|---|
| L1 | `ai_mode` операции допустим для её `class` на **каждой** ступени по `delegation_matrix` | Зависимость между двумя полями через третью таблицу |
| L2 | Каждая ступень в `class_by_rung` присутствует и в `ai_mode_by_rung`, если тот задан | Кросс-ключевое соответствие |
| L3 | Каждый `op.*` в `sanskrit_layer.failure_modes` существует в `operations` | Ссылочная целостность |
| L4 | Каждая модель в `trace` существует как файл в `app/Models/` | Требует доступа к репозиторию |
| L5 | Каждая `pilot_norms[].probe` существует как заголовок в VERIFICATION | Кросс-файловая ссылка |
| L6 | Ни одна норма не помечена не-пилотной без строки результата в таблице VERIFICATION | Именно этот инвариант не даёт непроверенному правилу тихо стать фактом |

**L6 — главный.** Остальные проверки ловят опечатки; L6 ловит подмену гипотезы выводом,
то есть ровно ту ошибку, ради предотвращения которой строится вся модель.

Линтер завершается ненулевым кодом при любом нарушении и не имеет ключа обхода для L6.

**Что уже прогнано разово 12-08-2026** (вне линтера, скриптом-однодневкой — durable-линтер
всё равно остаётся работой W1):

| Проверка | Результат |
|---|---|
| JSON Schema (структура) | 0 ошибок |
| L1 — `ai_mode` допустим для `class` на каждой ступени | 0 нарушений |
| L2 — ступени `class_by_rung` покрыты `ai_mode_by_rung` | 0 пропусков |
| L3 — ссылочная целостность `sanskrit_layer` → `operations` | 0 битых ссылок |
| L4 — 15 имён в `trace` существуют в `app/Models/` | все 15 найдены |
| L5, L6 | не прогонялись — требуют кросс-файлового разбора VERIFICATION |

### Первый прогон durable-линтера — 31-08-2026 (H3763, Sonnet 5)

`python tools/lint_pedagogy_model.py` над текущим состоянием
[ai_native_pedagogy_model.yaml](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/schema/ai_native_pedagogy_model.yaml):

```
lint_pedagogy_model: L1-L6 clean, 0 findings
```

Exit 0. L1–L4 подтверждают прежний ручной прогон (0 нарушений на каждой). **L5 и L6
прогнаны впервые:** L5 — все 5 `pilot_norms[].probe` (`VERIFICATION P1`…`P5`) находят
соответствующий заголовок `### P1 · N1 — …` … `### P5 · N5 — …` в VERIFICATION, 0
нарушений. L6 — вакуумно чист: все 5 норм всё ещё `pilot: true`, поэтому проверка
«норма не-пилотная без строки результата» не находит ни одной не-пилотной нормы, к
которой можно было бы её применить — это не «норма подтверждена», а «условие проверки
пока не наступило» (сама норма остаётся неподтверждённой, см. таблицу результатов P1–P5
в [VERIFICATION](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/VERIFICATION_AI_NATIVE_PEDAGOGICAL_DESIGN.md)).
Self-test: `python -m pytest tools/test_lint_pedagogy_model.py` — 17 passed.

L4 подтверждает только **существование** модели данных, не наличие в ней нужного
содержания — это разные вопросы, и второй решается в P0.

---

## 3. Привязка к данным — что надо установить до любых выводов

Слой следов в модели назван, но **не верифицирован**: перечисленные модели данных
существуют в `app/Models/`, однако то, что нужное содержание в них действительно есть, —
пока предположение. Это работа P0 в
[VERIFICATION](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/VERIFICATION_AI_NATIVE_PEDAGOGICAL_DESIGN.md),
и до её выполнения ни одна проба не имеет смысла.

| Контур | Модели | Что надо установить в P0 |
|---|---|---|
| ДЗ | [`HomeworkSubmission`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Models/HomeworkSubmission.php), [`HomeworkComment`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Models/HomeworkComment.php), [`HomeworkFile`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Models/HomeworkFile.php) | Содержательность комментариев; наличие признака происхождения |
| SRS | [`SrsReviewLog`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Models/SrsReviewLog.php), [`SrsReviewState`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Models/SrsReviewState.php), [`SrsCard`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Models/SrsCard.php) | Сопоставимость колод со ступенями A0–C2 |
| Результаты | [`ExamScore`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Models/ExamScore.php), [`Certificate`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Models/Certificate.php), [`LessonView`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Models/LessonView.php) | Гранулярность: операция или курс целиком |
| Работающий ИИ | [`SupportAiReplyEvent`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Models/SupportAiReplyEvent.php), [`SupportAnswerSuggestion`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Models/SupportAnswerSuggestion.php) | Есть ли пометка машинного происхождения на стороне студента |

---

## 4. Порядок работ W1

1. ✅ Написать `tools/lint_pedagogy_model.py` с проверками L1–L6, прогнать по YAML — 31-08-2026, H3763. 0 находок, чинить было нечего.
2. ✅ Выполнить P0.1–P0.4 (только чтение, агрегаты, без выгрузки персональных данных) — 12-08-2026, H2600.
3. ✅ Записать результаты P0 в таблицу VERIFICATION; всё, на что данные не отвечают, — отдельной строкой как GAP.
4. ✅ Пересмотреть слой следов в YAML по фактическому положению дел — добавлен блок `trace_reality` с измеренными числами, SRS понижен до следа активности.
5. ✅ Обновить журнал ревизий в метадоке модели (v0.2).

**Выход W1 считается достигнутым**, когда линтер зелёный, таблица результатов P0
заполнена числами, и в YAML не осталось ни одной ссылки на след, существование которого не
подтверждено. **Все пять пунктов закрыты 31-08-2026 (H3763) — W1 завершён.**

### Что P0 показал про сам план W1

Прогон дал результат, которого план не предусматривал: **три дефицита данных**, чинить
которые границы задачи запрещают.

| Дефицит | Что блокирует | Куда передан |
|---|---|---|
| Нет поля происхождения в `homework_comments` | N4 непроверяем в учебном контуре по построению | список дефицитов в [VERIFICATION](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/VERIFICATION_AI_NATIVE_PEDAGOGICAL_DESIGN.md) |
| 0 из 17 SRS-колод привязаны к курсу/уроку | Q2 и любая проба уровня ступени | там же |
| `exam_scores` пуст (вебхук n8n не приносит строк) | результат уровня операции | там же |

Первый дефицит особенно важен для порядка работ: **добавить признак происхождения нужно
до того, как в учебный контур придёт ИИ**, а не после — иначе первые машинные реплики
окажутся неотличимы от человеческих задним числом, и норма N4 станет непроверяемой уже не
по построению, а безвозвратно.

---

## 5. Чего W1 не делает

- Не запускает P1–P5 и Q1–Q3 — это W2.
- Не размечает выборку комментариев вручную — это W2, Q1.
- Не добавляет в Systema ни таблиц, ни полей, ни экранов, ни пометок происхождения ИИ-выхода. Если P0.2 покажет, что признака происхождения нет, это фиксируется как обнаруженный дефицит и передаётся отдельным решением, а не чинится здесь.

_Dr. Mārcis Gasūns_
