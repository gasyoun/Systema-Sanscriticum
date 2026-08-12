# Roadmap — AI-native педагогическое проектирование, 2026H2

_Created: 12-08-2026 · Last updated: 12-08-2026_

Последовательность работ по системной модели образовательного проектирования Systema.
Модель: [ARCHITECTURE_AI_NATIVE_PEDAGOGICAL_DESIGN_MODEL.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/ARCHITECTURE_AI_NATIVE_PEDAGOGICAL_DESIGN_MODEL.md)
· риски: [RISKS_AI_NATIVE_PEDAGOGICAL_DESIGN.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/RISKS_AI_NATIVE_PEDAGOGICAL_DESIGN.md)

**Общая логика волн.** Сначала модель становится проверяемой (W0–W1), затем проверяется
(W2), затем — и только затем — влияет на продукт (W3). Порядок обратный обычному: как
правило продуктовое решение принимается первым, а обоснование дописывается после. Здесь
это запрещено конструкцией.

---

## W0 — Модель существует · ✅ сделано 12-08-2026

| Пункт | Артефакт |
|---|---|
| Атом, слои, правило делегирования | [ARCHITECTURE](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/ARCHITECTURE_AI_NATIVE_PEDAGOGICAL_DESIGN_MODEL.md) |
| Слой оценивания расщеплён на F1–F4 | там же, §4 |
| Санскритский слой: режимы отказа + привязка к A0–C2 | там же, §5 |
| Машиночитаемое ядро + валидатор | [schema/](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/schema/ai_native_pedagogy_model.yaml) |
| Пробы описаны | [VERIFICATION](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/VERIFICATION_AI_NATIVE_PEDAGOGICAL_DESIGN.md) |
| Риски названы | [RISKS](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/RISKS_AI_NATIVE_PEDAGOGICAL_DESIGN.md) |

**Чего в W0 нет:** ни одного числа. Всё, что здесь написано, — конструкция, а не знание.

---

## W1 — Модель проверяема

Полный план: [IMPLEMENTATION_AI_NATIVE_PEDAGOGICAL_DESIGN_W1.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/IMPLEMENTATION_AI_NATIVE_PEDAGOGICAL_DESIGN_W1.md)

- Линтер `tools/lint_pedagogy_model.py` (L1–L6), зелёный на YAML.
- Пробы P0.1–P0.4 выполнены, результаты — числами в таблице.
- Слой следов приведён в соответствие с фактом; неподтверждённые `trace` убраны.
- Обнаруженные дефициты данных выписаны отдельным списком.

**Выход:** известно, на какие вопросы данные Systema способны ответить, а на какие нет.
**Возможный честный исход:** данные не отвечают почти ни на что (риск R7) — тогда W2
переопределяется, а не выполняется формально.

---

## W2 — Модель проверена

- P1–P5 по пилотным нормам; вакуумные пробы закрываются как `INCONCLUSIVE — vacuous`, не как `PASS`.
- Q1 — ручная разметка выборки `HomeworkComment` по F1–F4, вслепую к назначенным классам. Главная проба всей работы.
- Q2 — соответствие `class_by_rung` фактической последовательности освоения.
- Q3 — существующие ИИ-поверхности оценены по модели (описание и оценка, без изменений).
- Схема обновлена: подтверждённые нормы теряют `pilot: true`, опровергнутые удаляются с записью в журнал ревизий.

**Выход:** модель, у которой часть утверждений подкреплена числами, а часть отброшена.
Отброшенные утверждения — такой же результат, как подтверждённые.

---

## W3 — Модель влияет на решения

Начинается только после W2 и только по подтверждённым нормам.

- **Разрешение противоречия R3** — открытый NLP-слой против защиты конститутивной операции. Это решение принимает человек; модель лишь предъявляет развилку и её цену.
- Правила делегирования в кабинете преподавателя — если и когда схема будет читаться кодом.
- Пересмотр состава ИИ-опор по ступеням A0–C2 в
  [SANSKRIT_HUB_LEARNER_PROGRESSION_A0_C2.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/SANSKRIT_HUB_LEARNER_PROGRESSION_A0_C2.md).
- Возврат: подтверждённые режимы отказа санскритского слоя — в
  [SANSKRIT_HUB_ASSET_PEDAGOGY_INDEX.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/SANSKRIT_HUB_ASSET_PEDAGOGY_INDEX.md)
  как ограничения активов.

**W3 не запланирован по срокам намеренно** — его содержание определяется тем, что выживет
в W2.

---

## Что вне дорожной карты

- Код в Systema в объёме W0–W2 (кроме линтера в `tools/`).
- Изменения в работающих ИИ-фичах support и suggestion-контура.
- Сбор новых данных о студентах, опросы, эксперименты с живыми когортами.
- Публикационный трек: статья не пишется, в
  [ARTICLES.md](https://github.com/gasyoun/Uprava/blob/main/ARTICLES.md) не заводится.
  Если модель выживет в W2 с числами, вопрос о публикации ставится заново — как новое
  решение, а не как продолжение этого.

_Dr. Mārcis Gasūns_
