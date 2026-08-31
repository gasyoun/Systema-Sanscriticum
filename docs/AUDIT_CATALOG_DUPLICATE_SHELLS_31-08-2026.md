# Аудит каталога: семьи курсов — потоки или дубли

_Created: 31-08-2026 · Last updated: 31-08-2026_

Отчёт команды `php artisan catalog:audit-families --markdown`. Только чтение: ни одной записи в `courses`/`tariffs` не сделано.

Семей 114: duplicate 4, streams 6, unique 104. Курсов 129. Ничего не изменено.

## Из-за чего сработал `duplicate`

- **2 семьи** — осевшая оболочка — член семьи без единой собственной строки данных. Чистка базы; разбирается `catalog:audit-shells`, который отдельно проверяет, не отнимет ли удаление у человека единственную запись на курс. Семьи: `karaki-po-panini`, `likbez-po-lingvistike`.
- **3 семьи** — **живой поток и его же запись, проданные отдельными строками каталога под одним номером потока.** Удалять нечего — у записи свои блоки, тарифы и оплаты; витрина и SEO при этом показывают одну программу дважды. Правка карточек, а не базы. Семьи: `astronomiia-dlia-astrologov`, `ioga-sutry-patandzali`, `likbez-po-lingvistike`.

## Вердикты

| Семья | Вердикт | Курсы семьи | Доказательства | Что делать |
|---|---|---|---|---|
| `astronomiia-dlia-astrologov` | ⛔ duplicate | 279 Астрономия для астрологов (1 поток, 2025) (`astronomiia-dlia-astrologov-1-potok-vesna-2025`, live, семья задана вручную)<br>345 Астрономия для астрологов (2 поток, 2025) (`astronomiia-dlia-astrologov-2-potok-osen-2025`, live, семья задана вручную)<br>418 Астрономия для астрологов (1 поток, весна 2025) в записи (`astronomiia-dlia-astrologov-1-potok-vesna-2025-v-zapisi`, recording, семья задана вручную) | 279: /k/astronomiia-dlia-astrologov-1-potok-vesna-2025 · блоков 4 · активных тарифов 5 (block_1, block_2, block_3, block_4, full) · оплат 366 · записано 106 · первый платёж 2025-03-07 · группы: Астрономия для астрологов (1 поток, весна 2025)<br>345: /k/astronomiia-dlia-astrologov-2-potok-osen-2025 · блоков 4 · активных тарифов 5 (block_1, block_2, block_3, block_4, full) · оплат 120 · записано 32 · первый платёж 2025-09-05 · группы: Астрономия для астрологов (2 поток, осень 2025)<br>418: /k/astronomiia-dlia-astrologov-1-potok-vesna-2025-v-zapisi · блоков 0 · активных тарифов 0 (—) · оплат 35 · записано 12 · первый платёж 2025-04-28 · группы: —<br>**Почему duplicate:** курсы 279, 418 неотличимы как потоки (общий ключ потока «001»): ни номер в названии, ни дата первого платежа их не разводят | разобрать вручную: свести витрину и SEO на один курс семьи, записи переносить только после `catalog:audit-shells` (он проверяет, не отнимет ли удаление у человека единственную запись) |
| `ioga-sutry-patandzali` | ⛔ duplicate | 327 Йога-сутры Патанджали (1 поток, 2025) в записи (`ioga-sutry-patandzali-v-zapisi-1-potok-2025`, live, семья задана вручную)<br>336 Йога-сутры Патанджали (2 поток, 2025) (`ioga-sutry-patandzali-2-potok-2025-2026`, live, семья задана вручную)<br>396 Йога-сутры Патанджали (1 поток, 2025) (`ioga-sutry-patandzali-1-potok-2025`, live, семья задана вручную) | 327: /k/ioga-sutry-patandzali-v-zapisi-1-potok-2025 · блоков 4 · активных тарифов 5 (block_1, block_2, block_3, block_4, full) · оплат 129 · записано 43 · первый платёж 2025-06-02 · группы: Йога-сутры Патанджали в записи (1 поток, 2025)<br>336: /k/ioga-sutry-patandzali-2-potok-2025-2026 · блоков 3 · активных тарифов 1 (full) · оплат 343 · записано 97 · первый платёж 2025-08-27 · группы: Йога-сутры Патанджали (2 поток, 2025-2026)<br>396: /k/ioga-sutry-patandzali-1-potok-2025 · блоков 4 · активных тарифов 1 (full) · оплат 789 · записано 226 · первый платёж 2024-07-03 · группы: Йога-сутры Патанджали (1 поток, 2025)<br>**Почему duplicate:** курсы 327, 396 неотличимы как потоки (общий ключ потока «001»): ни номер в названии, ни дата первого платежа их не разводят | разобрать вручную: свести витрину и SEO на один курс семьи, записи переносить только после `catalog:audit-shells` (он проверяет, не отнимет ли удаление у человека единственную запись) |
| `karaki-po-panini` | ⛔ duplicate | 335 Караки по Панини (2025) (`karaki-po-panini-2025`, live, семья задана вручную)<br>421 Караки по Панини 2025-2026 в записи (`karaki-po-panini-2025-2026-v-zapisi`, unknown, семья задана вручную) | 335: /k/karaki-po-panini-2025 · блоков 5 · активных тарифов 6 (block_1, block_2, block_3, block_4, block_5, full) · оплат 81 · записано 19 · первый платёж 2025-09-18 · группы: Караки по Панини 2025<br>421: /k/karaki-po-panini-2025-2026-v-zapisi · блоков 0 · активных тарифов 0 (—) · оплат 0 · записано 9 · первый платёж — · группы: —<br>**Почему duplicate:** курс 421 «Караки по Панини 2025-2026 в записи» — ни блоков, ни активных тарифов, ни оплат: самостоятельным потоком не является | разобрать вручную: свести витрину и SEO на один курс семьи, записи переносить только после `catalog:audit-shells` (он проверяет, не отнимет ли удаление у человека единственную запись) |
| `likbez-po-lingvistike` | ⛔ duplicate | 344 Ликбез по лингвистике (2 поток, 2025-2026) (`likbez-po-lingvistike-2-potok-2025-2026`, live, семья задана вручную)<br>394 Ликбез по лингвистике (2 поток, 2025-2026) в записи (`likbez-po-lingvistike-2-potok-2025-2026-v-zapisi`, live, семья задана вручную)<br>395 Ликбез по лингвистике (1 поток, 2024) (`likbez-po-lingvistike-1-potok-2024`, live, семья задана вручную)<br>430 Ликбез по лингвистике (2023) (`likbez-po-lingvistike-2023`, unknown, семья задана вручную) | 344: /k/likbez-po-lingvistike-2-potok-2025-2026 · блоков 4 · активных тарифов 5 (block_1, block_2, block_3, block_4, full) · оплат 36 · записано 9 · первый платёж 2025-09-24 · группы: Ликбез по лингвистике (2 поток, 2025-2026)<br>394: /k/likbez-po-lingvistike-2-potok-2025-2026-v-zapisi · блоков 1 · активных тарифов 0 (—) · оплат 1 · записано 1 · первый платёж 2025-12-11 · группы: Ликбез по лингвистике (2 поток, 2025-2026) в записи<br>395: /k/likbez-po-lingvistike-1-potok-2024 · блоков 8 · активных тарифов 9 (block_1, block_2, block_3, block_4, block_5, block_6, block_7, block_8, full) · оплат 186 · записано 27 · первый платёж 2024-09-04 · группы: —<br>430: /k/likbez-po-lingvistike-2023 · блоков 0 · активных тарифов 0 (—) · оплат 0 · записано 0 · первый платёж — · группы: —<br>**Почему duplicate:** курс 430 «Ликбез по лингвистике (2023)» — ни блоков, ни активных тарифов, ни оплат: самостоятельным потоком не является; курсы 344, 394 неотличимы как потоки (общий ключ потока «002»): ни номер в названии, ни дата первого платежа их не разводят | разобрать вручную: свести витрину и SEO на один курс семьи, записи переносить только после `catalog:audit-shells` (он проверяет, не отнимет ли удаление у человека единственную запись) |
| `kasmirskii-sivaizm` | ✅ streams | 332 Кашмирский шиваизм (1 поток, 2025) (`kasmirskii-sivaizm-2025`, live, семья задана вручную)<br>375 Кашмирский шиваизм (2 поток, 2026) (`kasmirskii-sivaizm-cast-2-2026`, live, семья задана вручную)<br>424 Кашмирский шиваизм 2025 в записи (`kasmirskii-sivaizm-2025-v-zapisi`, recording, семья задана вручную) | 332: /k/kasmirskii-sivaizm-2025 · блоков 4 · активных тарифов 5 (block_1, block_2, block_3, block_4, full) · оплат 156 · записано 52 · первый платёж 2025-09-05 · группы: Кашмирский шиваизм 2025<br>375: /k/kasmirskii-sivaizm-cast-2-2026 · блоков 4 · активных тарифов 5 (block_1, block_2, block_3, block_4, full) · оплат 93 · записано 28 · первый платёж 2026-02-11 · группы: Кашмирский шиваизм, часть 2 2026<br>424: /k/kasmirskii-sivaizm-2025-v-zapisi · блоков 0 · активных тарифов 0 (—) · оплат 17 · записано 5 · первый платёж 2026-02-06 · группы: — | — |
| `logika` | ✅ streams | 274 Логика (2024) (`logika-2024`, live, семья задана вручную)<br>427 Логика в записи (`logika-v-zapisi`, recording, семья задана вручную) | 274: /k/logika-2024 · блоков 4 · активных тарифов 5 (block_1, block_2, block_3, block_4, full) · оплат 93 · записано 25 · первый платёж 2024-09-27 · группы: Логика (2024)<br>427: /k/logika-v-zapisi · блоков 0 · активных тарифов 0 (—) · оплат 8 · записано 2 · первый платёж 2025-02-22 · группы: — | — |
| `napevnyi-sanskrit-gimn-guru-stotram` | ✅ streams | 331 Напевный санскрит - гимн Гуру стотрам (2025) (`napevnyi-sanskrit-gimn-guru-stotram-2025`, live, семья задана вручную)<br>397 Напевный санскрит - гимн Гуру стотрам (2024) (`napevnyi-sanskrit-gimn-guru-stotram-2024`, live, семья задана вручную) | 331: /k/napevnyi-sanskrit-gimn-guru-stotram-2025 · блоков 4 · активных тарифов 5 (block_1, block_2, block_3, block_4, full) · оплат 50 · записано 11 · первый платёж 2025-08-06 · группы: Напевный санскрит - гимн Гуру стотрам 2025<br>397: /k/napevnyi-sanskrit-gimn-guru-stotram-2024 · блоков 2 · активных тарифов 3 (block_1, block_2, full) · оплат 0 · записано 0 · первый платёж — · группы: Напевный санскрит - гимн Гуру стотрам 2024 | — |
| `osnovy-aiurvedy` | ✅ streams | 280 Основы Аюрведы (`osnovy-aiurvedy-ocno`, live, семья задана вручную)<br>403 Основы Аюрведы в записи (`osnovy-aiurvedy-v-zapisi`, recording, семья задана вручную) | 280: /k/osnovy-aiurvedy-ocno · блоков 100 · активных тарифов 100 (block_1, block_10, block_100, block_11, block_12, block_13, block_14, block_15, block_16, block_17, block_18, block_19, block_2, block_20, block_21, block_22, block_23, block_24, block_25, block_26, block_27, block_28, block_29, block_3, block_30, block_31, block_32, block_33, block_34, block_35, block_36, block_37, block_38, block_39, block_4, block_40, block_41, block_42, block_43, block_44, block_45, block_46, block_47, block_48, block_49, block_5, block_50, block_51, block_52, block_53, block_54, block_55, block_56, block_57, block_58, block_59, block_6, block_60, block_61, block_62, block_63, block_64, block_65, block_66, block_67, block_68, block_69, block_7, block_70, block_71, block_72, block_73, block_74, block_75, block_76, block_77, block_78, block_79, block_8, block_80, block_81, block_82, block_83, block_84, block_85, block_86, block_87, block_88, block_89, block_9, block_90, block_91, block_92, block_93, block_94, block_95, block_96, block_97, block_98, block_99) · оплат 393 · записано 27 · первый платёж 2024-01-01 · группы: Основы Аюрведы очно<br>403: /k/osnovy-aiurvedy-v-zapisi · блоков 0 · активных тарифов 0 (—) · оплат 366 · записано 29 · первый платёж 2023-10-27 · группы: — | — |
| `prodlenka-sanskrita` | ✅ streams | 323 Продленка санскрита (2025) (`prodlenka-sanskrita-2025`, live, семья задана вручную)<br>436 Продленка санскрита (2026) (`prodlenka-sanskrita-2026`, live, семья задана вручную) | 323: /k/prodlenka-sanskrita-2025 · блоков 3 · активных тарифов 4 (block_1, block_2, block_3, full) · оплат 45 · записано 17 · первый платёж 2025-06-07 · группы: Продленка санскрита 2025<br>436: /k/prodlenka-sanskrita-2026 · блоков 3 · активных тарифов 4 (block_1, block_2, block_3, full) · оплат 24 · записано 14 · первый платёж 2026-06-23 · группы: Продленка санскрита 2026 | — |
| `sintaksis-sanskrita` | ✅ streams | 266 Синтаксис санскрита (`sintaksis-sanskrita`, live, семья задана вручную)<br>425 Синтаксис санскрита в записи (`sintaksis-sanskrita-v-zapisi`, recording, семья задана вручную) | 266: /k/sintaksis-sanskrita · блоков 100 · активных тарифов 100 (block_1, block_10, block_100, block_11, block_12, block_13, block_14, block_15, block_16, block_17, block_18, block_19, block_2, block_20, block_21, block_22, block_23, block_24, block_25, block_26, block_27, block_28, block_29, block_3, block_30, block_31, block_32, block_33, block_34, block_35, block_36, block_37, block_38, block_39, block_4, block_40, block_41, block_42, block_43, block_44, block_45, block_46, block_47, block_48, block_49, block_5, block_50, block_51, block_52, block_53, block_54, block_55, block_56, block_57, block_58, block_59, block_6, block_60, block_61, block_62, block_63, block_64, block_65, block_66, block_67, block_68, block_69, block_7, block_70, block_71, block_72, block_73, block_74, block_75, block_76, block_77, block_78, block_79, block_8, block_80, block_81, block_82, block_83, block_84, block_85, block_86, block_87, block_88, block_89, block_9, block_90, block_91, block_92, block_93, block_94, block_95, block_96, block_97, block_98, block_99) · оплат 453 · записано 40 · первый платёж 2023-12-10 · группы: Синтаксис санскрита<br>425: /k/sintaksis-sanskrita-v-zapisi · блоков 0 · активных тарифов 0 (—) · оплат 7 · записано 4 · первый платёж 2024-11-02 · группы: — | — |

## Семьи из одного курса (вердикт `unique`)

Разбора не требуют; перечислены, чтобы охват отчёта был полным — каждый курс каталога попал ровно в одну строку.

- 268 — 1000 имен Вишну (`1000-imen-visnu`)
- 422 — Аюрведа Соболева в записи (не продаем) (`aiurveda-soboleva-v-zapisi-ne-prodaem`)
- 319 — Благоприятные зачины йогической традиции (2025) (`blagopriyatnyye-zachiny-yogicheskoy-traditsii-2025`)
- 439 — Чтение Айтарейи (2026) (`ctenie-aitareii-2026`)
- 379 — Депозит учащегося (`depozit-ucashhegosia`)
- 303 — Дети (2025) (`deti-2025`)
- 398 — Дети, младшая группа (2024) (`deti-mladsaia-gruppa-2024`)
- 423 — Дети старшая группа 2024 (`deti-starsaia-gruppa-2024`)
- 360 — Детский санскрит (2026), гр. 1 (`detskii-sanskrit-2025-gr-1`)
- 354 — Донат на развитие Общества Ревнителей Санскрита (`donat-na-razvitie-ors`)
- 371 — Дополнительные занятия по хинди (`dop-zaniatiia-po-xindi`)
- 358 — Электронный Эмено (`elektronnyi-emeno`)
- 308 — Ежедневные молитвы с Ушей Санкой, 1 часть (2024) (`ezednevnye-molitvy-s-usei-sankoi-1-e-v-zapisi`)
- 309 — Ежедневные молитвы с Ушей Санкой, 2 часть (2024) (`ezednevnye-molitvy-s-usei-sankoi-2-e-v-zapisi`)
- 310 — Ежедневные молитвы с Ушей Санкой, 3 часть (2024) (`ezednevnye-molitvy-s-usei-sankoi-3-e-v-zapisi`)
- 373 — География Индии (2026) (`geografiia-indii-2026`)
- 407 — Грамматика по Бюллеру гр.26 (`grammatika-po-biulleru-gr26`)
- 343 — Грамматика по Бюллеру гр.27 (`grammatika-po-biulleru-gr27`)
- 417 — Грамматика по Кочергиной гр.31 (`grammatika-po-kocerginoi-gr31`)
- 404 — Грамматика по Кочергиной гр.35 (`grammatika-po-kocerginoi-gr35`)
- 411 — Грамматика по Кочергиной гр.36 (`grammatika-po-kocerginoi-gr36`)
- 405 — Грамматика по Кочергиной гр.38 (`grammatika-po-kocerginoi-gr38`)
- 288 — Грамматика по Кочергиной гр.42 (`grammatika-po-kocerginoi-gr42`)
- 415 — Грамматика по Кочергиной гр.43 (`grammatika-po-kocerginoi-gr43`)
- 406 — Грамматика по Кочергиной гр.44 (`grammatika-po-kocerginoi-gr44`)
- 419 — Грамматика по Кочергиной гр.45 (`grammatika-po-kocerginoi-gr45`)
- 408 — Грамматика по Кочергиной гр.46 (`grammatika-po-kocerginoi-gr46`)
- 410 — Грамматика по Кочергиной гр.47 (`grammatika-po-kocerginoi-gr47`)
- 412 — Грамматика по Кочергиной гр.48 (`grammatika-po-kocerginoi-gr48`)
- 409 — Грамматика по Кочергиной гр.49 (`grammatika-po-kocerginoi-gr49`)
- 296 — Грамматика по Кочергиной гр.50 (`grammatika-po-kocerginoi-gr50`)
- 297 — Грамматика по Кочергиной гр.51 (`grammatika-po-kocerginoi-gr51`)
- 414 — Грамматика по Кочергиной гр.52 (`grammatika-po-kocerginoi-gr52`)
- 341 — Грамматика по Кочергиной гр.53 (`grammatika-po-kocerginoi-gr53`)
- 342 — Грамматика по Кочергиной гр.54 (`grammatika-po-kocerginoi-gr54`)
- 349 — Грамматика по Кочергиной гр.55 (`grammatika-po-kocerginoi-gr55`)
- 350 — Грамматика по Кочергиной гр.56 (`grammatika-po-kocerginoi-gr56`)
- 351 — Грамматика по Кочергиной гр.57 (`grammatika-po-kocerginoi-gr57`)
- 352 — Грамматика по Кочергиной гр.58 (`grammatika-po-kocerginoi-gr58`)
- 368 — Грамматика по Кочергиной гр.59 (`grammatika-po-kocerginoi-gr59`)
- 369 — Грамматика по Кочергиной гр.60 (`grammatika-po-kocerginoi-gr60`)
- 434 — Грамматика по Кочергиной гр.61 (`grammatika-po-kocerginoi-gr61`)
- 435 — Грамматика по Кочергиной гр.62 (`grammatika-po-kocerginoi-gr62`)
- 432 — Грамматика хинди №3 вторник 18:00 (2026) (`hindi-3-vt1800-2026`)
- 413 — Грамматика хинди гр. 1, пн (`grammatika-xindi-gr-1-pn`)
- 401 — Грамматика хинди гр. 1, среда 8:00 (2026) (`hindi-1-sr800-2026`)
- 416 — Грамматика хинди гр. 2, чт (`grammatika-xindi-gr-2-ct`)
- 402 — Грамматика хинди гр. 2, суббота 13:00 (2026) (`hindi-2-sb1300-2026`)
- 356 — Грамматика хинди гр. 3, пятница (2025) (`grammatika-xindi-gr-3-pt`)
- 357 — Грамматика хинди гр. 4, суббота, продолжающие (2025) (`hindi-gr4-sb1000-2026-pro`)
- 366 — Грамматика хинди гр. 5, вторник (2025) (`hindi-5-vt1500-2026`)
- 426 — Избранные главы из Бхагавадгиты в записи (`izbrannye-glavy-iz-bxagavadgity-v-zapisi`)
- 271 — Избранные главы из Бхагавадгиты 1 цикл (2024) (`izbrannye-glavy-iz-bxagavadgity-1-cikl`)
- 272 — Избранные главы из Бхагавадгиты 2 цикл (2024) (`izbrannye-glavy-iz-bxagavadgity-2-cikl`)
- 324 — Избранные главы из Бхагавадгиты 3 цикл (2025) (`izbrannye-glavy-iz-bxagavadgity-3-cikl`)
- 348 — Избранные главы из Бхагавадгиты 4 цикл (2025-2026) (`izbrannye-glavy-iz-bxagavadgity-4-cikl`)
- 372 — Каллиграфия (2026) (`kalligrafiia-2026`)
- 444 — Клуб (`club`)
- 438 — Летний интенсив по хинди (2026) (`letnii-intensiv-hindi-2026`)
- 337 — Ликбез по веданте (2025) (`likbez-po-vedante-2025`)
- 440 — Лингвистика в задачах с Еленой Трефиловой (2026) (`lingvistika-v-zadacax-s-elenoi-trefilovoi-2026`)
- 305 — Материалы в записи - плакаты (`materialy-v-zapisi-plakaty`)
- 334 — Медленное чтение Йога-васиштхи (`medlennoe-ctenie-ioga-vasistxi`)
- 339 — Мегхадута Калидасы 2025 (`megxaduta-kalidasy-2025`)
- 338 — Морфология Зализняка (2025-2026) (`morfologiia-zalizniaka-2025-2026`)
- 304 — Напевный санскрит - азы санскрита (`azy-sanskrita-v-zapisi`)
- 321 — Напевный санскрит - гимн 108 имен Шивы (2025) (`napevnyi-sanskrit-gimn-108-imen-sivy-2025`)
- 365 — Напевный санскрит - гимн 108 имен Сурьи (2026) (`gimn-suri-2026`)
- 364 — Напевный санскрит - гимн Бхагавадгиты (1 часть, 2026) (`gimn-bxagavadgity-2026-13-cast`)
- 399 — Напевный санскрит - гимн Бхагавадгиты (2 часть, 2026) (`napevnyi-bxagavadgita-2ch-2026`)
- 377 — Напевный санскрит - гимн Дурге (2023) (`napevnyi-sanskrit-gimn-durge-2023-v-zapisi`)
- 312 — Напевный санскрит - гимн Дханвантари (2024) (`napevnyi-sanskrit-gimn-dxanvantari-2024`)
- 318 — Напевный санскрит - гимн Гаятри (2025) (`napevnyi-sanskrit-gimn-gaiatri-2025`)
- 316 — Напевный санскрит - гимн Ганеше (2024) (`napevnyi-sanskrit-gimn-ganese-2024`)
- 320 — Напевный санскрит - гимн Кали (2025) (`napevnyi-sanskrit-gimn-kali-2025`)
- 380 — Напевный санскрит - гимн Нарасимхе (2023) (`napevnyi-sanskrit-gimn-narasimxe-2023`)
- 311 — Напевный санскрит - гимн Наваграхи (2023) (`napevnyi-sanskrit-gimn-navagraxi-v-zapisi`)
- 363 — Напевный санскрит - гимн «Прославление Бхайравы», изреченное Абхинавагуптой (2026) (`gimn-abxinavagupty-2026`)
- 307 — Напевный санскрит - гимн Шани (2023) (`napevnyi-sanskrit-gimn-sani-v-zapisi`)
- 317 — Напевный санскрит - гимн Шиве (2025) (`napevnyi-sanskrit-gimn-sive-2025`)
- 306 — Напевный санскрит - гимн Шивы (2022) (`napevnyi-sanskrit-gimn-sivy-2022-v-zapisi`)
- 433 — Йога-сутры Патанджали, воскресенье 10:00 (2026) (`napevnyi-sanskrit-gimn-sutr-patandzali-vskr-10-2026`)
- 381 — Йога-сутры Патанджали, вторник 15:00 (2026) (`napevnyi-sanskrit-gimn-sutr-patandzali-vt-15-2026`)
- 359 — Напевный санскрит - гимн Хануману (2023) (`napevnyi-sanskrit-gimn-xanumanu-2023`)
- 361 — Основы индийской философии (2026) (`osnovy-indiiskoi-filosofii-2026`)
- 322 — Осознанные сновидения - нидра (2025) (`osoznannye-snovideniia-nidra`)
- 400 — Открытые занятия и вебинары (`otkrytye-zaniatiia-i-vebinary`)
- 428 — Перевод басен гр. 35 (`perevod-basen-gr-35`)
- 420 — Повторная аюрведа в записи (`povtornaia-aiurveda-v-zapisi`)
- 386 — Прочие затраты (Технический) (`system-expenses`)
- 442 — Продленка хинди с Костиной (2026) (`prodlenka-hindi-s-kostinoi-2026`)
- 374 — Ранние санскритские рукописи (2026) (`rannie-sanskritskie-rukopisi-2026`)
- 429 — Разбор 108 имен Шивы в записи (`razbor-108-imen-sivy-v-zapisi`)
- 376 — Разбор Хануман Чалисы (2026) (`razbor-xanuman-calisy-2026`)
- 333 — Санскритская литература (2025) (`sanskritskaia-literatura-2025`)
- 362 — Саравали 2026 (`saravali-2026`)
- 269 — Сказания о Нале (`skazaniia-o-nale`)
- 437 — Созвон отдела Заботы (`sozvon-otdela-zaboty`)
- 443 — Старт чтения (`start-chteniya`)
- 278 — Традиции толкования упанишад (1 поток, 2025) (`tradicii-tolkovaniia-upanisad-1-potok-2025`)
- 284 — Ведический санскрит (`vediceskii-sanskrit`)
- 378 — Ведийская литература (2026) (`vediiskaia-literatura-2026`)
- 367 — Восточные календари (2026) (`vostocnye-kalendari-2026`)
- 431 — Затраты на ИП (`zatraty-na-ip`)

## Как читается вердикт

- **streams** — несколько строк `courses`, и каждая отличима как самостоятельный поток: у неё есть собственные данные (роль `live`/`recording`) и собственный ключ потока — номер из названия либо дата первого платежа. Законно; складывать потоки между собой в отчётности нельзя (семантика `App\Support\CourseCadence`).
- **duplicate** — хотя бы одна строка не отличима: либо у неё нет ни блоков, ни активных тарифов, ни оплат, либо две строки претендуют на один и тот же поток. Требует разбора человеком.
- **unique** — в семье одна строка.

Порог намеренно строгий в пользу `duplicate`: ложный `duplicate` стоит одного взгляда админа, ложный `streams` прячет дубль от витрины насовсем.

_Dr. Mārcis Gasūns_
