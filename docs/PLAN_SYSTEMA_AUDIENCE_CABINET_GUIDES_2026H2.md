# План: иллюстрированные мануалы студента, куратора и бухгалтера (2026H2)

_Created: 21-08-2026 · Last updated: 21-08-2026_

Три аудитории до сих пор получают непрочитанные GitHub-файлы без кадров. Этот план делает из них то, что уже сработало у преподавателя: сценарии впереди, кадр на шаг, страница в том интерфейсе, где человек работает.

Слои:

- [ROADMAP_SYSTEMA_AUDIENCE_CABINET_GUIDES_2026H2.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/ROADMAP_SYSTEMA_AUDIENCE_CABINET_GUIDES_2026H2.md) — волны и что чем разблокируется
- [ARCHITECTURE_SYSTEMA_AUDIENCE_CABINET_GUIDES.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/ARCHITECTURE_SYSTEMA_AUDIENCE_CABINET_GUIDES.md) — источники, каналы, Playwright, storage vs git
- [IMPLEMENTATION_SYSTEMA_AUDIENCE_CABINET_GUIDES_W1.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/IMPLEMENTATION_SYSTEMA_AUDIENCE_CABINET_GUIDES_W1.md) — пошаговая сборка волны 1 (студент)
- [VERIFICATION_SYSTEMA_AUDIENCE_CABINET_GUIDES.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/VERIFICATION_SYSTEMA_AUDIENCE_CABINET_GUIDES.md) — приёмка и риски

Исполнение: [H3212 (Grok 4.6) — Wave 1: illustrated student cabinet guide in /dvaram with Playwright screenshots](https://github.com/gasyoun/Uprava/blob/main/handoffs/H3212-Grok_Systema-Sanscriticum_student-cabinet-guide-illustrated_21.08.26.md), затем [H3213 (Grok 4.6) — Wave 2: full curator/admin handbook in /admin replacing admin-manual delta](https://github.com/gasyoun/Uprava/blob/main/handoffs/H3213-Grok_Systema-Sanscriticum_curator-admin-guide-illustrated_21.08.26.md), затем [H3214 (Grok 4.6) — Wave 3: accountant operational book in /admin, screenshots from storage not git](https://github.com/gasyoun/Uprava/blob/main/handoffs/H3214-Grok_Systema-Sanscriticum_accountant-cabinet-guide-illustrated_21.08.26.md).

`/go` волны 1 исполняет слой реализации, не этот индекс как «уже сделано». Слияние PR с планом **не** закрывает H3212.

## 1. Что уже есть (аудит 21-08-2026)

| Факт | Значение |
|---|---|
| Студент, командная карта | [docs/student-manual.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/student-manual.md) (~27 КБ), аудитория — куратор/разработчик; скриншотов нет |
| Студент, первые 5 минут | [docs/onboarding-student.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/onboarding-student.md) + чеклист в кабинете (`OnboardingChecklist`) |
| Точечная помощь | `/faq/dz` (ДЗ без входа), `/help/prana-balance` (прана), [MANUALS_TO_UI_CONTENT_AUDIT_2026.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/MANUALS_TO_UI_CONTENT_AUDIT_2026.md) |
| Куратор | Отдельного гида нет. [docs/admin-manual.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/admin-manual.md) — дельта июня–июля 2026, не полное руководство |
| Бухгалтер | [docs/accountant-guide.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/accountant-guide.md) (~52 КБ, без кадров) + [docs/finance-manual.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/finance-manual.md) |
| Уже в кабинете | [PayoutAttributionGuide](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Filament/Pages/PayoutAttributionGuide.php) (`/admin/payout-attribution-guide`) — живая очередь, не GitHub |
| Рабочий образец | [docs/TEACHER_CABINET_GUIDE_RU.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/TEACHER_CABINET_GUIDE_RU.md) → страница `/admin/teacher-guide` + PDF + Dusk-кадры |
| Правило 18-08-2026 | Указания сотруднику живут в кабинете. Публичный репозиторий не несёт фамилии, суммы, живые скриншоты админки (H3084) |
| Браузерный стек | Dusk закоммичен (гид препода). Playwright есть как MCP и [scripts/mobile_viewport_audit.mjs](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/scripts/mobile_viewport_audit.mjs), в `package.json` пакета нет |

## 2. Проверка на «не изобретать заново»

| Кусок | Что есть | Вердикт |
|---|---|---|
| Три канала (md + страница + PDF) | Гид преподавателя, H2501/H2502 | **Переиспользовать модель**, не копировать Dusk |
| Рендер Markdown в панели | [TeacherGuide](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Filament/Pages/TeacherGuide.php): константный путь, `Str::markdown`, перепись `src` | **Вынести** в `App\Support\MarkdownGuide`. TeacherGuide в волне 1 **не рефакторить** |
| Сборка PDF | [docs/build-teacher-guide.php](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/build-teacher-guide.php) | **Скопировать-адаптировать** в `docs/build-student-guide.php`; учительский сборщик не ломать |
| Свежесть CI | [scripts/teacher_guide_freshness.py](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/scripts/teacher_guide_freshness.py) | **Скопировать** под student/curator watch-list |
| Кадры 1440/390 | VisualDCS Dusk-пара | **Плотность та же**, движок — Playwright |
| Инструкция рядом с живым списком | PayoutAttributionGuide | **Оставить**, из книги бухгалтера — ссылка, не дубль |
| «Где должен жить ответ» | MANUALS_TO_UI §1–4 | **Правила текста** переиспользовать. Отдельный гид студенту — решение человека, не отмена сниппетов в UI |
| `/ru-manual` | harvest feat-коммитов в RU-мануал | **Не запускать** как замену этому плану: он пишет энциклопедию без кадров и без страницы в кабинете |

**PARTIAL, не NEW:** строится только зазор — три аудитории × сценарии × Playwright × страница в продукте × разный контур кадров (git vs storage).

## 3. Решения

Интервью 21-08-2026, три круга (цель, архитектура, реализация). Круги приёмки и автономности человек пропустил — там стоят помеченные дефолты, в таблице это сказано.

| № | Развилка | Решение | Почему | Источник |
|---|---|---|---|---|
| 1 | Где студенческий мануал | Страница «Как пользоваться» в `/dvaram` | Ученик не открывает GitHub | человек |
| 2 | Что такое мануал куратора | Полный справочник вместо месячной дельты | `admin-manual.md` никто не читает как книгу | человек |
| 3 | Куда операционка бухгалтера | Одна книга в `/admin` | Правило 18-08-2026; GitHub уже не сработал | человек |
| 4 | Волна 1 | Студент | Tier 0 | человек |
| 5 | Куда куратор открывает справочник | md + страница в `/admin`, как у преподавателя | Тот же канал, что уже живой | человек |
| 6 | Движок кадров | Playwright-скрипт в репо, не Dusk | Явный выбор; Dusk преподавателя не трогаем | человек |
| 7 | PNG бухгалтера | Не в git: storage на стенде/проде | Денежные экраны; H3084 | человек |
| 8 | Рендерер | Один `MarkdownGuide` + тонкие страницы | Путь не из запроса | человек |
| 9 | Плотность кадров | Кадр на шаг сценария; студент 1440 и 390 | «Везде скриншоты» | человек |
| 10 | PDF | Всем трём | Можно переслать; бухгалтерский PDF не в публичный репо | человек |
| 11 | Кто снимает Playwright | `scripts/capture-guide-screenshots.mjs` + `npm -D playwright` | Повторяемая команда, не сессия агента | человек |
| 12 | PNG студента | `docs/screenshots/student-guide/` в git, фикстура | Витрина продукта, не зарплаты | человек |
| 13 | Файл куратора | Новый `CURATOR_ADMIN_GUIDE_RU.md`; `admin-manual.md` — редирект | Не смешивать историческую дельту с живым гидом | человек |
| 14 | Старый `student-manual.md` | Оставить командной картой, в шапке ссылка на `/dvaram/help` | Разные аудитории | человек |
| 15 | Где гоняется съёмка | Локально/стенд, не против прод-студентов | H3084 | человек |
| 16 | Спутники куратора | Влить тексты | Один документ, который открывают | человек |
| 17 | Объём «влить» для должников | Сценарии куратора из debtors-manual; сам файл остаётся каноном массовых админ-операций. Magic-link и zabota-bot — целиком | Полный debtors-manual раздует гид куратора разделами, которых он не видит | дефолт агента (круг 4 пропущен) |
| 18 | Приёмка волны 1 | Перепись шагов + PNG 1440/390 + 200 студенту / 403 гостю + PDF с картинками + ноль имён классов | Иначе снова «выглядит нормально» | дефолт агента |
| 19 | Живой читатель | После мержа, не блокер | Иначе сессия не безлюдная | дефолт агента |
| 20 | Свежесть | CI-предупреждение, не fail | Как teacher-guide | дефолт агента |
| 21 | Кто пишет русский | Grok 4.6 (`grok-4.6`) по контракту `/ru-manual`: шаги, без имён классов, результат а не механика. Claude не перезапускает | Политика Grok-run non-Fable | дефолт агента |
| 22 | Неясность в ходе работы | Помеченный дефолт + строка в журнале PR | Как гид преподавателя | дефолт агента |
| 23 | Стоп | Запись в прод · правка существующих RoleGate · PDF без картинок после 3 попыток · конфликт в тех же файлах · нет Chrome → текст+манифест, кадры отдельным шагом | Не выдумывать PNG | дефолт агента |
| 24 | Коммит | PR→мерж без спроса; деплой Systema; одно смоук «страница открылась». Денежные флаги не трогать | always-merge + deploy-without-reask | дефолт агента |
| 25 | Забор | Существующие `canViewAny`/`RoleGate` · схема БД · текст гида преподавателя · код платежей/вебхуков · прод-данные. PayoutAttributionGuide не ломать | Необратимый ущерб вне задачи документа | дефолт агента |

## 4. Контракт автономности (дословно для исполнителя)

- **При неясности** — выбрать помеченный в этом индексе дефолт, выполнить, записать строку в журнал решений внутри PR. Не останавливаться, не спрашивать, не придумывать третий путь молча.
- **Не трогать:** (а) `canViewAny` / `shouldRegisterNavigation` / `navigationGroup` существующих ресурсов; (б) миграции и схему; (в) [docs/TEACHER_CABINET_GUIDE_RU.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/TEACHER_CABINET_GUIDE_RU.md) и [TeacherGuide](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Filament/Pages/TeacherGuide.php) как работающий продукт; (г) код платежей, вебхуков, выдачи доступа; (д) запись в продовые данные.
- **Разрешено** добавить страницу `/dvaram/help`, `MarkdownGuide`, Playwright-скрипт, новые md, PDF-сборщик студента, freshness-скрипт, тесты покрытия. На волнах 2–3 — новые Filament-страницы с **собственным** `canAccess()`, без правки чужих гейтов.
- **Остановиться и оставить PR черновиком** при любом стопе решения 23. Нет Chrome на машине — не стоп для текста: коммитим гид и манифест, покрытие кадров помечаем в журнале, не генерируем фейковые PNG.
- **Права на коммит:** H3212 уже существует, поэтому коммит, PR и мерж — без запроса. Деплой после мержа волны 1 — известный путь Systema, одно смоук GET `/dvaram/help` под студентом (или 302/401 гостю, если смоук без сессии — тогда 200 на залогиненном стенде).
- **Границы данных:** съёмка только на фикстурах. Бухгалтерские кадры волны 3 не попадают в git.

## 5. Что остаётся человеку (не блокирует волну 1)

Живой прогон учеником после выкладки — ловит непонятность, которую тест не видит. Если никто не пройдёт — гид всё равно открывается в кабинете. Это не блокер мержа.

_Dr. Mārcis Gasūns_
