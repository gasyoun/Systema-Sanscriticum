_Created: 26-07-2026 · Last updated: 05-09-2026_

# Run Report: kos-arz-skt

## Input

- Source: `C:\Users\user\Documents\GitHub\Systema-Sanscriticum-h1696-605\docs\materials\kossovich-arzamas\SOURCE.md`
- Segment count: 72

## Segment Types

- heading: 16
- paragraph: 56

## Pipeline Status

| Step | Status | Artifact |
| --- | --- | --- |
| review | completed | 6 review file(s) |
| council | completed | council.json |
| revision | missing | revision.json |
| verification | missing | verification.json |

## Reviews

| Style | Status | Findings | Summary |
| --- | --- | --- | --- |
| elizarenkova-veda | completed | 7 | В тексте отсутствует IAST при первом упоминании ряда санскритских терминов и имён собственных, что затрудняет филологическую идентификацию. |
| lidova-commentary | completed | 4 | The document is a popular historical narrative that does not follow the Lidova commentary style: it lacks a research question framing the commentary tradition, does not discuss genre and authority chains in lexicography, and fails to analyze the function of dictionaries as commentary. |
| panini-traditional | completed | 0 | The document is a historical account of 19th-century Russian Sanskrit lexicography and does not contain grammatical exposition in the Paninian tradition. None of the segments present sūtra–vṛtti–example patterns, use vyākaraṇa terminology, or engage with indigenous commentary. The style checks are inapplicable. |
| toporov-etym | completed | 1 | В тексте отсутствует транслитерация IAST для санскритских форм при первом упоминании, что не соответствует требованиям стиля Топоров-этимология. |
| tronsky-readings | completed | 10 | Документ представляет собой популярный исторический очерк, а не специальную филологическую статью. Почти все утверждения, цитаты и статистические данные приводятся без ссылок на первичные источники или научный аппарат, что не соответствует требованиям стиля Tronsky-Readings. |
| zalizniak-method | completed | 2 | Text is factual and well-sourced, but two minor style deviations: amateur etymologies are listed without immediate critical framing, and accent marks are absent from proper names. |

## Findings

| Style | Severity | Span | Finding | Suggestion | Confidence |
| --- | --- | --- | --- | --- | --- |
| elizarenkova-veda | major | p006 | Первое упоминание «Ригведы» дано без IAST. | При первом упоминании добавить IAST: «Ригведа (*Ṛgveda*)». | 0.95 |
| elizarenkova-veda | major | p017 | Первое упоминание «Махабхараты» дано без IAST. | При первом упоминании добавить IAST: «Махабхарата (*Mahābhārata*)». | 0.95 |
| elizarenkova-veda | major | p017 | Упоминание драмы «Глиняная повозка» без оригинального санскритского названия и IAST. | Добавить оригинальное название с IAST: «Глиняная повозка» (*Mṛcchakaṭikā*). | 0.9 |
| elizarenkova-veda | major | p017 | Имена Сунда и Упасунда даны без IAST. | Добавить IAST: «Сунда и Упасунда (*Sunda*, *Upasunda*)». | 0.9 |
| elizarenkova-veda | major | p045 | Имя бога Вишну дано без IAST. | Добавить IAST: «Вишну (*Viṣṇu*)». | 0.95 |
| elizarenkova-veda | major | p045 | Имя бога смерти Яма дано без IAST. | Добавить IAST: «Яма (*Yama*)». | 0.95 |
| elizarenkova-veda | major | p045 | Санскритский корень «шубх» («блестеть») дан в русской транскрипции без IAST. | Дать корень в IAST: «шубх (*śubh*)». | 0.95 |
| lidova-commentary | major | p002 | The opening paragraph presents a curious fact about the Petersburg dictionary without situating it within the tradition of Sanskrit lexicography as a form of commentary. The style requires starting with a modern research question about the phenomenon's importance for understanding text, tradition, or culture, then connecting to the broader historical and cultural context of commentary. | Reframe the opening by posing a clear research question: e.g., 'How did the creation of the first major Sanskrit dictionary become a site of conflict between national scholarly traditions and what does this reveal about the role of commentary in shaping academic disciplines?' Then introduce the Petersburg dictionary as a case study in the tradition of lexicographic commentary, explaining its place in the genealogy of Sanskrit exegesis. | 0.9 |
| lidova-commentary | major | p003 | The segment promises a story about 'how the empire chose between German science and Russian dream' but does not frame this as an episode in the history of commentary genres. The Lidova style requires analyzing the genre(s) involved (here, the dictionary as a type of commentary) and their functions within a school or tradition. | Before launching into the historical narrative, discuss the genre of the bilingual dictionary in 19th-century philology. Address its dual role as a tool for access to the source text and as a vehicle for national school-building. Clarify how the competing dictionary projects represent different commentary traditions: one 'critical' and international, the other 'missionary' and nationally oriented. | 0.9 |
| lidova-commentary | major | p029 | Uvarov's intervention is presented as an anecdote about a 'Solomonic decision', but the style demands an analysis of the authority structures behind the competing dictionary projects. The chain of authority—from academic institutions to individual scholars—and its impact on the legitimacy of the resulting commentary is not examined. | Expand this episode into a discussion of the authority chain in 19th-century Sanskrit studies. Explain Uvarov's role as a patron and his vision of science above national quarrels, then contrast it with the academic authority of Bötlingk and the cultural authority claimed by the Slavophiles. Show how the decision to proceed in German ultimately consolidated a particular line of authoritative commentary. | 0.9 |
| lidova-commentary | major | p033 | The description of Kosovich's dictionary as 'missionary' and its design choices (transcription in Cyrillic, alphabetical order) is presented as a curiosity, but the style requires a thorough analysis of its intended function as commentary: how it was meant to serve non-specialist readers, to link Sanskrit to Slavic roots, and to create a new layer of tradition. | Analyze the function of Kosovich's dictionary as a form of cultural commentary. Discuss how its technical features (transcription, ordering) were not just practical decisions but aimed at performing a specific relationship between the ancient Indian text and the Russian linguistic identity. Contrast this with the 'critical' function of the Bötlingk–Roth dictionary and assess the consequences of each for the subsequent commentary tradition. | 0.9 |
| toporov-etym | minor | p045 | Санскритский глагол «шубх» (śubh) впервые вводится в тексте без транслитерации IAST. Согласно стилю, все санскритские формы должны приводиться в IAST при первом упоминании. | При первом упоминании санскритской лексемы добавьте форму в IAST в скобках, например: «шубх» (śubh). Для последовательности также рекомендуется указать IAST для других санскритских имён, таких как Вишну (Viṣṇu) и Яма (Yama). | 0.9 |
| tronsky-readings | major | p002 | Утверждение о странности ситуации с Петербургским словарём и отсутствии у русского читателя возможности пользоваться книгой без немецкого языка не подкреплено ссылкой на документальный источник (например, переписку, рецензии современников, архивные данные). | Привести указание на архивный документ или опубликованный источник (с указанием страниц), подтверждающий эту точку зрения. | 0.95 |
| tronsky-readings | major | p005 | Конкретные цифры оплаты (10 талеров за лист Роту, 5 – Веберу), запланированные сроки и последующие изменения тиража приведены без ссылки на протоколы Академии или иной первичный документ. | Сослаться на архивное дело или на монографию А. А. Вигасина с точным указанием страниц. | 0.9 |
| tronsky-readings | major | p006 | Прямая цитата из работы историка Алексея Вигасина не сопровождается библиографической ссылкой, что делает невозможной проверку источника. | Оформить цитату с точным указанием автора, названия работы, года издания и страницы. | 0.95 |
| tronsky-readings | major | p009 | Цитата из рецензии П. А. Плетнёва на перевод Жуковского дана без точного библиографического указания, что в специальной филологической работе обязательно. | Привести полную ссылку на рецензию Плетнёва: издание, год, том, страницы. | 0.9 |
| tronsky-readings | major | p019 | Прямые цитаты из писем Коссовича к Хомякову и Шевыреву приведены без архивных шифров или ссылок на публикации переписки. | Указать архивные единицы хранения или опубликованный сборник писем с номерами страниц. | 0.95 |
| tronsky-readings | major | p024 | Цитаты из высказываний И. И. Давыдова и академика Шёгрена не сопровождаются ссылками на протоколы заседаний Отделения или иные подтверждающие документы. | Сослаться на журналы заседаний Академии или на работу Вигасина с указанием конкретных страниц. | 0.9 |
| tronsky-readings | major | p033 | Рассказ Коссовича о том, как ему было поручено составление словаря, передан через его письмо Шевыреву, но без точной ссылки; приведены формулировки из обсуждений без документальной опоры. | Привести архивную легенду письма или опубликованный источник, а также сослаться на протоколы II Отделения. | 0.95 |
| tronsky-readings | major | p039 | Обширная цитата из отзыва Бётлингка не снабжена указанием на архивное дело или публикацию, откуда она взята. | Указать номер дела, листы или ссылку на научное издание, в котором опубликован отзыв. | 0.95 |
| tronsky-readings | major | p045 | Приводятся любительские этимологии Хомякова (Вишну – Вышний, шуба – шубх, хрен – «быть старым») без указания на их несоответствие принципам сравнительно-исторического языкознания, что в филологической статье требует критического комментария. | Добавить разбор с точки зрения исторической фонетики и семантики, показав, почему эти сближения несостоятельны, либо четко оговорить их ненаучный характер. | 0.85 |
| tronsky-readings | major | p061 | Статистические данные об оцифрованном словнике Коссовича (13 488 записей, 13 144 заглавных слова) приводятся без указания методики подсчета и ссылки на публикацию данных. | Привести ссылку на репозиторий проекта с указанием версии данных и даты обращения. | 0.9 |
| zalizniak-method | minor | p045 | The paragraph reproduces several popular-sound-based comparisons (Вишну–Вышний, Яма–яма, шуба–шубх, хрен–старый) without an immediate, explicit refutation. Although later sections mention criticism (Буслаев, Чернышевский), the delay leaves a window where a reader may temporarily treat these as plausible. This contradicts the requirement to resolutely suppress unscientific etymology on sight. | Add a concise disclaimer directly after the examples, e.g., «(Все эти сближения с научной точки зрения лишены оснований; подробную критику см. ниже)». | 0.7 |
| zalizniak-method | minor | p009 | The text omits stress marks on proper names and rare words (Коссович, Бётлингк, Хомяков, etc.), despite the style's emphasis on accentological precision. This may lead to mispronunciation and violates the instruction «Не игнорировать акцентологические параметры». | Add explicit stress marks to all proper names and potentially ambiguous words, e.g., Коссȯвич (or Коссови́ч), Бё́тлингк (though ё already indicates stress), Хомякóв. Consult biographical sources for correct accent placement. | 0.6 |

## Provider Log

| Task | Provider | Model | Status | Duration ms | Retries | Retry delay s |
| --- | --- | --- | --- | --- | --- | --- |
| review | deepseek | deepseek-v4-pro | completed | 258181 | 1 | 1.0 |
| review | deepseek | deepseek-v4-pro | completed | 71575 | 0 | 0.0 |
| review | deepseek | deepseek-v4-pro | completed | 18049 | 0 | 0.0 |
| review | deepseek | deepseek-v4-pro | completed | 57290 | 0 | 0.0 |
| review | deepseek | deepseek-v4-pro | completed | 65492 | 0 | 0.0 |
| review | deepseek | deepseek-v4-pro | completed | 56851 | 0 | 0.0 |
| council | deepseek | deepseek-v4-pro | completed | 199027 | 0 | 0.0 |

## Council

Status: `completed`

| Finding | Decision | Reason |
| --- | --- | --- |
| p002/lidova-commentary/finding-001 | accepted_with_modification | The reframing enhances the analytical rigor expected by the style without compromising the essayistic quality; source attribution is added separately. |
| p002/tronsky-readings/finding-001 | accepted_with_modification | Verifiability is non-negotiable; a reference to the fact table satisfies the requirement without burdening the narrative. |
| p003/lidova-commentary/finding-002 | accepted_with_modification | The addition of genre context fulfills the style's demand for analytic framing before descriptive narrative. |
| p005/tronsky-readings/finding-002 | accepted_with_modification | Financial details require a verifiable source; fact table entry suffices. |
| p006/elizarenkova-veda/finding-001 | accepted | IAST at first mention is a standard scholarly convention for Sanskrit terms. |
| p006/tronsky-readings/finding-003 | accepted_with_modification | Direct quotation requires a precise reference; fact table and page number provide this. |
| p009/tronsky-readings/finding-004 | accepted_with_modification | A bibliographic reference is required; either a footnote or a fact table link is acceptable. |
| p009/zalizniak-method/finding-002 | accepted_with_modification | Stress marks improve pronunciation guidance; those already indicated by ё are left unchanged. |
| p017/elizarenkova-veda/finding-002 | accepted | IAST at first mention. |
| p017/elizarenkova-veda/finding-003 | accepted | Original title with IAST is essential for scholarly identification. |
| p017/elizarenkova-veda/finding-004 | accepted | IAST at first mention. |
| p019/tronsky-readings/finding-005 | accepted_with_modification | Private letters require archival identification; fact table entries cover this. |
| p024/tronsky-readings/finding-006 | accepted_with_modification | Official statements must be traceable to meeting protocols. |
| p029/lidova-commentary/finding-003 | accepted_with_modification | Authority analysis adds necessary depth to the commentary genre study. |
| p033/lidova-commentary/finding-004 | accepted_with_modification | Functional analysis of the missionary dictionary aligns with the style's focus on commentary as cultural practice. |
| p033/tronsky-readings/finding-007 | accepted_with_modification | The source of Kosovich's account must be documented. |
| p039/tronsky-readings/finding-008 | accepted_with_modification | Boehtlingk's review is a key document; its provenance must be clear. |
| p045/elizarenkova-veda/finding-005 | accepted | IAST at first mention. |
| p045/elizarenkova-veda/finding-006 | accepted | IAST at first mention. |
| p045/elizarenkova-veda/finding-007 | accepted | IAST at first mention. |
| p045/toporov-etym/finding-001 | accepted | The IAST requirement is already satisfied by elizarenkova-veda findings; the present finding serves as reinforcement. |
| p045/tronsky-readings/finding-009 | accepted_with_modification | Combined immediate disclaimer with forward reference to critical analysis, satisfying both the demand for immediate warning and the need for thorough methodological critique. |
| p045/zalizniak-method/finding-001 | accepted_with_modification | The synthesized disclaimer meets the unambiguous rejection of pseudo-etymologies while providing the necessary scientific context. |
| p061/tronsky-readings/finding-010 | accepted_with_modification | Digital data require verifiable source and version information; repository reference is adequate. |

## Revision

No revision artifact yet.

## Verification

No verification artifact yet.

_Dr. Mārcis Gasūns_
