# План: каталог документации продукта в /admin (2026H2)

_Created: 21-08-2026 · Last updated: 21-08-2026_

Суперадмин и админ должны видеть **все живые книги кабинета** на одной странице, с поиском по заголовкам и прыжком в FAQ каждой книги. Книги уже написаны (H3212–H3214, гид преподавателя). Этот план не переписывает их: он собирает указатель, а волна 2 уплотняет Часть IV и добавляет проверки преподавателю и бухгалтеру.

Слои:

- [ROADMAP_SYSTEMA_PRODUCT_DOCS_CATALOG_2026H2.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/ROADMAP_SYSTEMA_PRODUCT_DOCS_CATALOG_2026H2.md) — волны
- [ARCHITECTURE_SYSTEMA_PRODUCT_DOCS_CATALOG.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/ARCHITECTURE_SYSTEMA_PRODUCT_DOCS_CATALOG.md) — модель, гейты, поиск
- [IMPLEMENTATION_SYSTEMA_PRODUCT_DOCS_CATALOG_W1.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/IMPLEMENTATION_SYSTEMA_PRODUCT_DOCS_CATALOG_W1.md) — шаги волны 1
- [VERIFICATION_SYSTEMA_PRODUCT_DOCS_CATALOG.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/VERIFICATION_SYSTEMA_PRODUCT_DOCS_CATALOG.md) — приёмка и риски

Исполнение: [H3243 (Grok 4.6) — Wave 1: superadmin/admin product documentation catalog at /admin/documentation](https://github.com/gasyoun/Uprava/blob/main/handoffs/H3243-Grok_Systema-Sanscriticum_product-docs-catalog_21.08.26.md), затем [H3244 (Grok 4.6) — Wave 2: per-book FAQ harvest and teacher/accountant cabinet-mastery banks](https://github.com/gasyoun/Uprava/blob/main/handoffs/H3244-Grok_Systema-Sanscriticum_product-docs-faq-mastery_21.08.26.md). `/go` волны 1 исполняет слой реализации, не этот индекс как «уже сделано». Слияние PR с планом **не** закрывает H3243.

## 1. Что уже есть (аудит 21-08-2026)

| Факт | Значение |
|---|---|
| Студент | `/dvaram/help`, [STUDENT_CABINET_GUIDE_RU.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/STUDENT_CABINET_GUIDE_RU.md), Часть IV = **7** вопросов |
| Куратор | `/admin/curator-guide`, [CURATOR_ADMIN_GUIDE_RU.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/CURATOR_ADMIN_GUIDE_RU.md), Часть IV = **9** |
| Бухгалтер | `/admin/accountant-guide`, [ACCOUNTANT_CABINET_GUIDE_RU.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/ACCOUNTANT_CABINET_GUIDE_RU.md), Часть IV = **7** |
| Преподаватель | `/admin/teacher-guide`, [TEACHER_CABINET_GUIDE_RU.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/TEACHER_CABINET_GUIDE_RU.md), FAQ = **9** |
| Точечная помощь | `/faq/dz` (публично), `/help/prana-balance` (кабинет) |
| Живая очередь, не книга | [PayoutAttributionGuide](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Filament/Pages/PayoutAttributionGuide.php) `/admin/payout-attribution-guide` |
| Другая полка | [AdminDocument](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Models/AdminDocument.php) «Важные файлы» — Google Sheets/Drive (H2570), не мануалы |
| Проверки | H3215: куратор `/admin/cabinet-mastery`, студент `/dvaram/proverka`. Преподаватель и бухгалтер — банков нет |
| Рендер книг | [MarkdownGuide](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Support/MarkdownGuide.php): путь константа, не из запроса |

## 2. Проверка на «не изобретать заново»

| Кусок | Что есть | Вердикт |
|---|---|---|
| Список внутренних файлов | AdminDocumentResource | **Не сливать.** Одна строка каталога ведёт на `/admin/admin-documents` |
| Рендер md | MarkdownGuide | **Не дублировать.** Каталог не рендерит книгу целиком — только поиск по файлу |
| FAQ в отдельном файле | нет; Часть IV уже в каждой книге | **Не заводить** `*_FAQ_RU.md` и не таблицу ProductDocFaq |
| Квиз | CabinetMastery + `cabinet_mastery_attempts` | **Расширить банки**, не второй движок |
| Поиск по корпусу | нет Scout/Meilisearch | **Не вводить.** Семь файлов читаются на запросе |
| `/ru-manual` | harvest feat-коммитов | **Не запускать** как замену каталогу |

**PARTIAL:** строится указатель + поиск + CRUD супер-админа + волна 2 FAQ/квиз. Книги и «Важные файлы» не переписываются.

## 3. Решения (интервью `/ask` 21-08-2026, пять кругов)

| № | Развилка | Решение | Почему | Источник |
|---|---|---|---|---|
| 1 | Состав каталога | Только живые книги кабинета + точечная помощь + payout-guide + строка на «Важные файлы». Не весь `docs/` | Инженерные PLAN/ARCHITECTURE — не аудитория админа | человек |
| 2 | Кто открывает страницу | Superadmin **и** admin (`RoleGate::adminOnly()`). Куратор/преподаватель/бухгалтер — нет | Как H2570, не «только владелец» | человек |
| 3 | Волна 1 | Каталог + поиск. FAQ harvest — волна 2 | Сначала найти книги | человек |
| 4 | Где живёт FAQ | Часть IV того же md; каталог прыгает на якорь | Один источник, без второго файла | человек |
| 5 | «Важные файлы» | Отдельный ресурс; в каталоге одна строка-ссылка | Две полки, две задачи | человек |
| 6 | Поиск/квиз в плане | Да: поиск волны 1, квиз teacher/accountant волны 2 | Круг 1, вариант C | человек |
| 7 | Список строк | Таблица БД + Filament Resource, не PHP-конфиг | Можно добавить URL без деплоя | человек |
| 8 | Что ищет поиск | Фильтр таблицы + заголовки и строки FAQ внутри md | Семь файлов, индекс не нужен | человек |
| 9 | Квиз | Ссылки на H3215; волна 2 добавляет банки teacher и accountant в тот же `cabinet_mastery.php` | Не второй движок, не автогенерация из FAQ | человек |
| 10 | Двухъярусность колонок | Путь git, дата коммита, покрытие кадров — только супер-админ. Книги админ видит те же | AdminDocument-стиль для метаданных, не для книг | человек |
| 11 | Строки, которые админ не может открыть | **Зафиксированный дефолт (круг 2A vs реальность `/dvaram`):** каталог уже `adminOnly`. Все **посеянные** книги видны админу и супер-админу. Фильтр `canAccess` назначения действует только на Filament-страницы сотрудников (если когда-нибудь появится accounting-only строка). Студенческие URL (`/dvaram/help`, `/faq/dz`, `/help/prana-balance`) всегда в списке — иначе админ не увидит главную книгу ученика | Admin не обязан быть `role=student` | дефолт агента на стыке 2A и маршрута |
| 12 | Навигация | Группа «Обучение», подпись «Документация», slug `documentation`. Resource CRUD **не** в меню | Рядом с гидами, не в «Управление» | человек |
| 13 | CRUD строк | Только супер-админ. Админ — чтение каталога. Сидер `firstOrCreate` по `slug`, заголовки человека не затирает. Посеянные `is_seeded` нельзя удалить | Как AdminDocumentSeeder | человек |
| 14 | Две поверхности | Страница `/admin/documentation` — для людей. Resource `/admin/product-docs` — CRUD, `shouldRegisterNavigation = false`, кнопка «Добавить» на странице только супер-админу | Таблица Filament ≠ книга | человек |
| 15 | Поиск по файлам | `source_path` из БД, чтение на запросе. Путь только под `docs/*.md` после `realpath`. Параметра пути из запроса нет | XSS-контракт MarkdownGuide | человек |
| 16 | Откуда FAQ волны 2 | Accepted helpdesk + тексты `why` из cabinet_mastery, дедуп с Частью IV, append. Без vote sheet | Живые вопросы, не выдумка | человек |
| 17 | Флаг | Нет. Гейт — RoleGate | Не деньги | человек |
| 18 | Забор кода | Watcher-safe. Не трогать платежи, вебхуки, entitlement, схему AdminDocument, Dusk/прозу TeacherGuide, существующие методы RoleGate, прод-данные | H3212 fence | человек |
| 19 | Приёмка волны 1 | Feature-тесты + смоук GET после деплоя. Playwright PNG каталога не должен | Таблица, не сценарий ученика | человек |
| 20 | Приёмка волны 2 | Часть IV не короче базы §1; новые Q без ФИО/сумм; два новых банка; старые квизы зелёные | Harvest — append | человек |
| 21 | Неясность | Помеченный дефолт + строка в журнале PR | Как гиды 21-08 | человек |
| 22 | Стоп | Деньги/вебхук/чужой RoleGate · слияние с таблицей admin_documents · путь md из запроса · ФИО/суммы в FAQ · watcher съел незакоммиченное. Нет Chrome — не стоп. Пустой harvest helpdesk → «0 новых FAQ», квизы из `why` всё равно | Не выдумывать ответы | человек |
| 23 | Коммит | Worktree, watcher-safe, PR, always-merge, деплой Systema, один смоук. Без money-contour маркера | always-merge + deploy-without-reask | человек |
| 24 | Русский | Grok 4.6 (`grok-4.6`). Claude не перезапускает | Grok-run non-Fable | дефолт агента |

## 4. Контракт автономности (дословно для исполнителя)

- **При неясности** — выбрать помеченный в этом индексе дефолт, выполнить, записать строку в журнал решений внутри PR. Не останавливаться, не спрашивать, не придумывать третий путь молча.
- **Не трогать:** платежи, вебхуки, выдачу доступа; схему и код AdminDocument; [TEACHER_CABINET_GUIDE_RU.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/TEACHER_CABINET_GUIDE_RU.md) сценарии (волна 2 может **дописать** Часть IV); Dusk преподавателя; существующие `canViewAny` / методы RoleGate; прод-данные.
- **Разрешено:** миграция `product_docs`, модель, сидер, Filament Page + скрытый Resource, хелпер поиска, тесты, CHANGELOG, одна строка «см. каталог» в шапках четырёх GUIDE. Волна 2 — append Части IV и новые ключи в `config/cabinet_mastery.php` + тонкие страницы квиза.
- **Остановиться и оставить PR черновиком** при любом стопе решения 22.
- **Права на коммит:** после мinta handoff волны 1 — коммит, PR, мерж, деплой без запроса. Смоук: GET `/admin/documentation` под сессией admin/superadmin (или 302 на логин, если смоук без сессии — тогда 200 на залогиненном стенде).
- **Путь markdown:** только колонка `source_path` посеянной/проверенной строки, нормализованная под `base_path('docs')`. Никогда из query/body.

## 5. Что остаётся человеку (не блокирует волны)

Живой проход админом по каталогу после выкладки — ловит подпись, которую тест не видит. Если никто не пройдёт — страница всё равно открывается. Это не блокер мержа.

_Dr. Mārcis Gasūns_
