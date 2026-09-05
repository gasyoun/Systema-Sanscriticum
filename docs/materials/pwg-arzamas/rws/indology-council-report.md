_Created: 26-07-2026 · Last updated: 05-09-2026_

# Run Report: pwg-arz-ind-final2

## Input

- Source: `C:\Users\user\Documents\GitHub\Systema-Sanscriticum-h1620-22532\docs\materials\pwg-arzamas\SOURCE.md`
- Segment count: 107

## Segment Types

- heading: 21
- paragraph: 86

## Pipeline Status

| Step | Status | Artifact |
| --- | --- | --- |
| review | completed | 7 review file(s) |
| council | completed | council.json |
| revision | completed | revision.json |
| verification | passed | verification.json |

## Reviews

| Style | Status | Findings | Summary |
| --- | --- | --- | --- |
| elizarenkova-veda | completed | 6 | Документ представляет собой научно-популярный очерк о Петербургском санскритско-немецком словаре, а не исследование по ведийской филологии. Большинство проверок стиля (отсутствие контекста гимна, необоснованная семантизация, анахронизмы) не релевантны. Однако выявлены пропуски IAST при первом упоминании ряда ключевых санскритских имён и названий, что формально нарушает требование стиля о парном указании (русская передача + IAST). |
| lidova-commentary | completed | 4 | Документ представляет собой научно-популярный очерк о Петербургском словаре, лишённый характерного для стиля Лидовой проблемно-ориентированного введения, жанровой рефлексии над комментарием как культурным феноменом, выстраивания цепочки авторитетов и анализа традиций устной/письменной передачи. Текст требует полной переработки в жанр историко-филологического комментария. |
| panini-traditional | completed | 0 | The reviewed document is a popular science narration about the history and significance of the Petersburg Sanskrit Dictionary (PWG). It does not contain grammatical exposition in the Paninian tradition, nor does it attempt to explicate sūtras, commentary layers, or native vyākaraṇa terminology. The occasional mentions of Pāṇini (p054, p055, p077) serve only as a reference point among other sources and do not constitute a grammatical discussion that would trigger the style requirements. No findings relevant to the panini-traditional style checks are present. |
| samasa-manual | completed | 0 | The document is a historical article about the Petersburg Sanskrit Dictionary (PWG), not a grammatical manual on samāsa. No samāsa-related content is present, so none of the style-specific checks (wrong samāsa type, missing vigraha, weak classification, missing IAST on first mention, missing example or exception) apply. The only incidental mention of 'самасы' in p061 is not within a grammatical exposition requiring IAST. |
| sanskrit-reader | completed | 0 | Документ представляет собой научно-популярную статью по истории Петербургского словаря, а не учебный разбор санскритского текста. Стиль «Санскрит-ридер» не применим к данному материалу, поэтому проверки на соответствие жанру (пословный разбор, анвая, различение слоёв комментария и пр.) не выявили нарушений. |
| smirnov-mahabharata | completed | 0 | Документ представляет собой научно-популярную статью о Петербургском словаре санскрита и не относится к жанру эпического перевода с аппаратом, для которого предназначен стиль «Смирнов-Махабхарата». Проверки стиля (разрушение стиха, неоговорённые пропуски, отсутствие примечаний к разночтениям, глоссарии реалий, смешение аппарата и комментария, нестандартная передача имён) не применимы. |
| toporov-etym | completed | 2 | The document contains an unsupported etymological claim and a missing IAST transliteration, inconsistent with the rigorous philological standards of the Toporov-etym style. |

## Findings

| Style | Severity | Span | Finding | Suggestion | Confidence |
| --- | --- | --- | --- | --- | --- |
| elizarenkova-veda | minor | p002 | Термин «Махабхарата» впервые вводится без IAST-транслитерации (Mahābhārata). | При первом упоминании дать форму в IAST: «Махабхарата (*Mahābhārata*)». | 0.95 |
| elizarenkova-veda | minor | p027 | Название «Яджурведа» дано без IAST (Yajurveda). | Указать IAST: «Яджурведа (*Yajurveda*)». | 0.95 |
| elizarenkova-veda | minor | p027 | «Тайттирия-самхита» вводится без IAST (Taittirīya-saṃhitā). | Добавить IAST: «Тайттирия-самхита (*Taittirīya-saṃhitā*)». | 0.95 |
| elizarenkova-veda | minor | p027 | «Шатапатха-брахмана» вводится без IAST (Śatapatha-brāhmaṇa). | Добавить IAST: «Шатапатха-брахмана (*Śatapatha-brāhmaṇa*)». | 0.95 |
| elizarenkova-veda | minor | p035 | Имя комментатора «Саяна» вводится без IAST (Sāyaṇa). | При первом упоминании добавить IAST: «Саяна (*Sāyaṇa*)». | 0.95 |
| elizarenkova-veda | minor | p054 | Имя грамматиста «Панини» дано без IAST (Pāṇini). | При первом упоминании указать IAST: «Панини (*Pāṇini*)». | 0.95 |
| lidova-commentary | major | p002 | Начальный абзац не соответствует композиционным требованиям стиля: отсутствует постановка современного исследовательского вопроса, не задан жанровый контекст (комментарий, традиция, авторитет), вместо этого дана нарративная завязка «Есть книги, которые страна знает в лицо…». | Перестроить введение: начать с вопроса о том, почему словарь может рассматриваться как форма комментария, определить понятие «словарь-комментарий» и его место в передаче традиции, затем перейти к историческому контексту создания Петербургского словаря. | 0.95 |
| lidova-commentary | major | p033 | Утверждается, что словарь «превращался в сплошной комментарий ко всей письменной традиции», однако не раскрыт культурно-исторический контекст этого превращения: не определён жанр словаря-комментария, не показана его связь с индийской комментаторской традицией (коши, бхашья), не охарактеризована функция такого «сплошного комментария» в сохранении и развитии знания. | Дать определение словаря как особого вида комментария, сопоставить его с традиционными формами индийской экзегезы (например, коша как мнемонический комментарий), показать, как переход от устной традиции к печатному словарю меняет функцию толкования. | 0.9 |
| lidova-commentary | major | p035 | Полемика с индийскими комментаторами и утверждение приоритета европейского филологического метода поданы без рассмотрения цепочки авторитетов: не объяснено, на чём основано право на толкование, как формируется комментаторский авторитет в индийской и западной традициях, почему возможен разрыв с традицией комментаторов. | Ввести понятие «авторитет комментатора», показать, как в индийской традиции комментарий (бхашья) наследует авторитет предшественников, и объяснить, что замена этого авторитета филологическим методом является сознательным переосмыслением самого института комментария. | 0.9 |
| lidova-commentary | major | p072 | Упоминание индийских словарей-кошей (kośa) не сопровождается анализом их функции как комментария: не раскрыта мнемоническая и дидактическая роль таких стихотворных перечней, их место в устной передаче и отличие от синтетического комментария-словаря нового времени. | Рассмотреть коши как особый жанр технического комментария, предназначенного для запоминания и устной передачи, сопоставить его с аналитическим типом комментария, реализованным в Петербургском словаре, и показать, как изменение носителя (от памяти к печатной странице) трансформирует комментаторскую функцию. | 0.85 |
| toporov-etym | major | p078 | The text asserts a genetic relationship between the Sanskrit negative prefix a- and Greek a-, Latin in-, German un- ('родня греческого «а-» в «атеизме», латинского in- и немецкого un-') without providing regular sound correspondences or citing authoritative sources. | Either supply the reconstructed PIE form (*n̥-) with established sound laws (e.g., Sievers-Edgerton, Grassmann's law) and references to standard etymological dictionaries, or remove the claim and discuss the prefix in purely descriptive terms. | 0.9 |
| toporov-etym | minor | p078 | The Sanskrit word for 'bodiless' is given as 'анАнга' in Cyrillic with non-standard capitalisation, whereas the style requires IAST transliteration on first mention. | Replace 'анАнга' with IAST 'anāṅga' (or 'an-aṅga' if hyphens are preferred) and ensure all Sanskrit lexemes are consistently presented in IAST throughout. | 0.9 |

## Provider Log

| Task | Provider | Model | Status | Duration ms | Retries | Retry delay s |
| --- | --- | --- | --- | --- | --- | --- |
| review | deepseek | deepseek-v4-pro | completed | 77478 | 0 | 0.0 |
| review | deepseek | deepseek-v4-pro | completed | 65877 | 0 | 0.0 |
| review | deepseek | deepseek-v4-pro | completed | 20716 | 0 | 0.0 |
| review | deepseek | deepseek-v4-pro | completed | 20036 | 0 | 0.0 |
| review | deepseek | deepseek-v4-pro | completed | 31071 | 0 | 0.0 |
| review | deepseek | deepseek-v4-pro | completed | 60515 | 0 | 0.0 |
| review | deepseek | deepseek-v4-pro | completed | 32569 | 0 | 0.0 |
| council | deepseek | deepseek-v4-pro | completed | 99346 | 0 | 0.0 |
| revision | deepseek | deepseek-v4-pro | completed | 353108 | 1 | 1.0 |
| verification | deepseek | deepseek-v4-pro | completed | 52967 | 0 | 0.0 |
| syntax_assessment | deepseek | deepseek-v4-pro | completed | 36091 | 0 | 0.0 |

## Council

Status: `completed`

| Finding | Decision | Reason |
| --- | --- | --- |
| elizarenkova-veda-finding-001 | accepted | Стандартное требование стиля о первом упоминании в IAST. |
| lidova-commentary-finding-001 | accepted_with_modification | Стиль Лидовой требует строгой композиции комментария, однако жанр популярной статьи допускает нарративное начало. Компромисс: добавляем тематическую подводку, не ломая зачин. |
| elizarenkova-veda-finding-002 | accepted | Стандартное требование стиля. |
| elizarenkova-veda-finding-003 | accepted | Стандартное требование стиля. |
| elizarenkova-veda-finding-004 | accepted | Стандартное требование стиля. |
| elizarenkova-veda-finding-005 | accepted | Стандартное требование стиля. |
| lidova-commentary-finding-003 | accepted_with_modification | Введение цепочки авторитетов усиливает филологическую глубину, но полный разбор нарушит стилистику очерка. Вставка одного предложения решает задачу. |
| elizarenkova-veda-finding-006 | accepted | Стандартное требование стиля. |
| lidova-commentary-finding-002 | accepted_with_modification | Определение словаря-комментария необходимо для стиля, но полный сопоставительный анализ избыточен. Краткая формулировка сохраняет научную корректность. |
| lidova-commentary-finding-004 | accepted_with_modification | Важно указать на функциональную разницу кош и печатного словаря, но без подробного комментария; достаточно вставки-уточнения. |
| toporov-etym-finding-001 | accepted_with_modification | Стиль Топорова требует этимологической обоснованности, но популярный формат диктует лаконичность. Добавляем *n̥- и указание на закономерные рефлексы без полного разбора. |
| toporov-etym-finding-002 | accepted | Исправление ошибки: IAST транслитерация для санскритского слова при первом упоминании. |

## Methodological Bias Audit
- **Bias Score**: 5/10
- **Primary Bias**: METHODOLOGICAL

**Critique**: The council exhibits a moderate methodological bias toward the elizarenkova-veda school, accepting its style rules as default without modification, while findings from other schools are subjected to heavier scrutiny and compromise. This asymmetry, combined with vague justifications, suggests an implicit elevation of one tradition’s norms as standard, undermining the impartial adjudication of competing philological methodologies.

| Severity | Issue | Recommendation |
| --- | --- | --- |
| warning | All six findings associated with the elizarenkova-veda school were accepted without modification, while findings from lidova-commentary and toporov-etym schools were consistently accepted with modification and required multi-school influence balancing. | Explicitly justify why elizarenkova-veda requirements are treated as non-negotiable 'standard style' while others require compromise, or re-evaluate the modification process for consistency across schools. |
| note | The recurring justification 'Стандартное требование стиля' is vague and assumes a single normative stylistic standard, potentially masking an unexamined preference for one school's conventions. | Specify the philological or methodological grounds on which the elizarenkova-veda style is deemed standard, and consider whether other traditions could claim equivalent authority. |
| note | The council may have ignored more nuanced or evaluate-level requirements from the elizarenkova-veda school, focusing only on apply-level rule applications, which could constitute a silence bias in favor of that school's simpler directives. | Audit the corpus of elizarenkova-veda guidelines to ensure that higher-level stylistic or methodological tenets are also being brought before the council. |

## Revision

- Status: `completed`
- Revised document: `runs/pwg-arz-ind-final2/revised.md`
- Diff: `revision.diff`
- Applied changes: 7
- Unresolved items: 0

## Verification

- Status: `passed`
- Passed checks: 1
- Warnings: 4

| Span | Message |
| --- | --- |
| p007 | Первое упоминание 'Панини' (в разделе 7) не сопровождается IAST-формой 'Pāṇini', что нарушает требование терминологического выбора на этот прогон: 'При первом упоминании всегда давать IAST-форму'. IAST-форма появляется только позже, в разделе 10. |
| p027 | Первое упоминание термина «сутра» без IAST в скобках: ожидается «сутра (sūtra)». |
| p061 | Первое упоминание термина «самаса» без IAST в скобках: ожидается «самаса (samāsa)». |
| p078 | Первое упоминание термина «упанишада» без IAST в скобках: ожидается «упанишада (upaniṣad)». |

## Scholarly Grounding (Citations)
- **Status**: `completed`
- **Verified Citations**: 0
- **Not in Bibliography**: 0


## Транслитерация санскрита (детерминированный линтер)

Схемы в тексте: iast

| Span | Тип | Сообщение |
| --- | --- | --- |
| p027 | missing_iast_on_first_mention | Первое упоминание термина «сутра» без IAST в скобках: ожидается «сутра (sūtra)». |
| p061 | missing_iast_on_first_mention | Первое упоминание термина «самаса» без IAST в скобках: ожидается «самаса (samāsa)». |
| p078 | missing_iast_on_first_mention | Первое упоминание термина «упанишада» без IAST в скобках: ожидается «упанишада (upaniṣad)». |

_Dr. Mārcis Gasūns_
