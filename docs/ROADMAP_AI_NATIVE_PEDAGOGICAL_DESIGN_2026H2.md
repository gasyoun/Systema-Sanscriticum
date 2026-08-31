# Roadmap — AI-native педагогическое проектирование, 2026H2

_Created: 12-08-2026 · Last updated: 31-08-2026_

> **Truth-pass 30-08-2026 (Fable 5 `claude-fable-5`, `/ask` H3760):** статус W0–W1 сверен и верен;
> единственный неотгруженный на тот момент W1-артефакт — линтер — заминчен как
> [H3763 (Sonnet 5, 🟡2 medium)](https://github.com/gasyoun/Uprava/blob/main/handoffs/H3763-Sonnet_Systema-Sanscriticum_pedagogy-model-linter-l1-l6_30.08.26.md)
> и **выполнен 31-08-2026** — W1 полностью закрыт.
> W2 Q3 (оценка ИИ-поверхностей по модели) — именованный кандидат следующей волны жатвы
> ([ROADMAP слой](https://github.com/gasyoun/Uprava/blob/main/docs/ROADMAP_UPRAVA_ASK_CLAUDE_SYSTEMA_ROADMAP_MINT_2026-08.md)).

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

## W1 — Модель проверяема · ✅ сделано 31-08-2026 (H3763)

Полный план: [IMPLEMENTATION_AI_NATIVE_PEDAGOGICAL_DESIGN_W1.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/IMPLEMENTATION_AI_NATIVE_PEDAGOGICAL_DESIGN_W1.md)

- ✅ Пробы P0.1–P0.4 выполнены на проде, результаты — числами в таблице [VERIFICATION](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/VERIFICATION_AI_NATIVE_PEDAGOGICAL_DESIGN.md).
- ✅ Слой следов приведён в соответствие с фактом: SRS понижен с «след результата по ступеням» до «след учебной активности», в схему добавлен измеренный блок `trace_reality`.
- ✅ Обнаруженные дефициты данных выписаны отдельным списком (три штуки).
- ✅ Линтер [`tools/lint_pedagogy_model.py`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/tools/lint_pedagogy_model.py) (L1–L6) — написан 31-08-2026 (H3763, Sonnet 5); первый полный прогон над committed-схемой: `L1-L6 clean, 0 findings` (L5/L6 прогнаны впервые); self-test 17/17 зелёный.

**Выход достигнут частично:** известно, на какие вопросы данные Systema способны ответить.
Ответ отрезвляющий — контур ДЗ отвечает, SRS и формальные результаты почти нет.

**Риск R7 реализовался наполовину.** Данные ответили на P0.1 и не ответили на P0.3/P0.4 —
поэтому Q2 в нынешней формулировке неисполним и подлежит переопределению, а не формальному
прогону. Это ровно тот исход, ради честной регистрации которого P0 и ставился первым.

---

## W2 — Модель проверена

- P1–P5 по пилотным нормам; вакуумные пробы закрываются как `INCONCLUSIVE — vacuous`, не как `PASS`.
- Q1 — ручная разметка выборки `HomeworkComment` по F1–F4, вслепую к назначенным классам. Главная проба всей работы.
- Q2 — соответствие `class_by_rung` фактической последовательности освоения. **Требует переопределения до запуска:** P0.3/P0.4 показали, что данных уровня ступени нет; в нынешней формулировке проба неисполнима.
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
