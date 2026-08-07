# Библиотека ответов поддержки — регистровый проход, категории A–F (H1876)

_Created: 31-07-2026 · Last updated: 07-08-2026_

Регистровый проход по библиотеке ответов поддержки, ограниченный **категориями
FAQ-суггестера A–F** — по [H1876](https://github.com/gasyoun/Uprava/blob/main/handoffs/H1876-Fable_Systema-Sanscriticum_support-reply-library-ru-register-a-f_29.07.26.md).
Эталон регистра — контракт голоса revenue-copy волны:
[ARCHITECTURE_SYSTEMA_REVENUE_COPY_VOICE_CONTRACT.md](https://github.com/gasyoun/Uprava/blob/main/docs/ARCHITECTURE_SYSTEMA_REVENUE_COPY_VOICE_CONTRACT.md)
и общие строки
[_shared_strings.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/copy/_shared_strings.md).
Исполнитель: Fable 5 (`claude-fable-5`).

## Границы: почему A–F, а не A–I

Исходная формулировка задачи говорила «A–I», но верификатор 27-07-2026 сузил её:
**категориями суггестера являются только A–F** (см. `RULES` в
[SupportAnswerSuggester.php](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Services/Support/SupportAnswerSuggester.php)).
Классы G–I таксономии дефлекшена — это операционные поверхности, а не тексты
ответов: G — долги (бот-команда `/долги <группа>`, тикет S4), H — ростер
«группа → куратор → студенты» (S6), I — self-service доступа (S8). Суггестер по
ним черновиков не строит, ответных текстов в репозитории у них нет — **G–I этим
проходом не тронуты, сознательно и по сужению верификатора, а не по недосмотру.**
Расширять проход обратно до A–I нельзя: сужение — проверенный вывод.

## Где живут ответы A–F

| Категория | Что это | Где текст ответа | Что сделано |
|---|---|---|---|
| A — Zoom/ссылка | черновик из фактов LMS | [SupportAnswerFactResolver.php](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Services/Support/SupportAnswerFactResolver.php) `resolveZoom` | уже в регистре — подтверждено, без правок |
| B — записи | черновик из фактов LMS | там же, `resolveRecording` | уже в регистре — подтверждено, без правок |
| C — расписание | черновик из фактов LMS | там же, `resolveSchedule` | уже в регистре — подтверждено, без правок |
| D — оплата (гость) | публичные тарифы | там же, `resolvePublicPricing` | «с учётом» → «с учетом» (правило D13, без «ё») |
| D/E/F — LLM-черновики | системный промпт формулировщика | [SupportLlmDraftComposer.php](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Services/Support/SupportLlmDraftComposer.php) `systemPrompt` | добавлен регистровый блок: «вы» со строчной, без эмодзи/восклицаний/срочности/уменьшительных/англицизмов, «ё» только в «всё» |
| D/E/F — привязываемые шаблоны (S9) | канреплаи `MessageTemplate` | [MessageTemplateSeeder.php](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/database/seeders/MessageTemplateSeeder.php) | добавлены три заготовки в домашнем регистре; сеются **непривязанными** — привязку к категории суггестера делает оператор в админке (S9/H1838), сидер поведение прода не меняет |
| поддержка · общий канреплай | «приняли в работу» | там же | «вернёмся с ответом. Спасибо за терпение!» → «уже разбираемся. Обычно отвечаем в течение рабочего дня.» (без «ё» и восклицания; конкретика скорости ответа — строка 3 из `_shared_strings.md`) |
| E1/E2/E3/E4 · D2/D3 · F2/F3 · A/C · TG · G | узкие канреплаи по темам TG | тот же seeder + [SUPPORT_CANREPLY_TEMPLATES_REVISION_2026-08.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/copy/SUPPORT_CANREPLY_TEMPLATES_REVISION_2026-08.md) | **H2339:** magic-link (E2), forgot-password (E1), post-pay пароль (E3), тех-вход (E4), оплата/чек (D2/D3), ДЗ/запись (F2/F3), Zoom/расписание (A/C), Telegram, перевод группы — по census ~2 мес prod TG |

Живые тексты категорий D/E/F, уже привязанные куратором в проде
(`message_templates.suggester_category`), в репозитории не лежат — их регистр
правится в админке по этому же контракту; заготовки сидера дают эталон.

## Применённые правила регистра

1. Обращение на «вы» со строчной; приветствие в письмах — «Намасте, {name}!» (маркер школы, не заменяется).
2. Тон спокойный, взрослый, конкретный: срок или следующее действие вместо заверений («Обычно отвечаем в течение рабочего дня» вместо «Спасибо за терпение!»).
3. Без эмодзи, восклицаний, нагнетания срочности, уменьшительных и англицизмов.
4. Орфография D13: новые тексты без «ё» («расчет», «с учетом»); единственное исключение — «всё» там, где без «ё» читалось бы «все».
5. Денежный страх — первым и явно: «не платите повторно: мы проверим платеж и откроем доступ» (шаблон D).
6. Фактические клеймы проверены по репозиторию: нечувствительность email к регистру/пробелам — [onboarding-student.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/onboarding-student.md); записи и материалы в разделе «Уроки» — формулировка `resolveRecording`.

## Что сохранено дословно

- Вся плейсхолдерная механика: `{name}`, `{course}`, `{block}`, `{pay_link}` и
  интерполяции PHP (`{$lines}`, `{$title}`, `{$url}` и т. п.) не тронуты —
  требование handoff «templating/variable syntax preserved exactly».
- Поведение кода: ни одна ветка логики не изменена; правки — только строки
  текстов, промпт и docblock-комментарии.

_Dr. Mārcis Gasūns_
