# План: карта денег школы и большая книга бухгалтера (2026H2)

_Created: 21-08-2026 · Last updated: 21-08-2026_

Шесть сценариев уже живут в кабинете. Этого мало: бухгалтер не видит, **как устроен весь денежный контур**, какие экраны её, какие — нет, и чем три похожие кнопки отличаются. Этот план кладёт карту как Часть 0 той же книги и дописывает пропущенные еженедельные работы. Не новая страница, не публичная памятка с фамилиями.

Слои:

- [ROADMAP_SYSTEMA_ACCOUNTANT_MONEY_MAP_2026H2.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/ROADMAP_SYSTEMA_ACCOUNTANT_MONEY_MAP_2026H2.md)
- [ARCHITECTURE_SYSTEMA_ACCOUNTANT_MONEY_MAP.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/ARCHITECTURE_SYSTEMA_ACCOUNTANT_MONEY_MAP.md)
- [IMPLEMENTATION_SYSTEMA_ACCOUNTANT_MONEY_MAP_W1.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/IMPLEMENTATION_SYSTEMA_ACCOUNTANT_MONEY_MAP_W1.md)
- [VERIFICATION_SYSTEMA_ACCOUNTANT_MONEY_MAP.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/VERIFICATION_SYSTEMA_ACCOUNTANT_MONEY_MAP.md)

Исполнение: волна 1 — один handoff (карта + недостающие сценарии в том же файле книги).

## 1. Что уже есть (аудит 21-08-2026)

| Факт | Значение |
|---|---|
| Книга в кабинете | [https://samskrte.ru/admin/accountant-guide](https://samskrte.ru/admin/accountant-guide) · [ACCOUNTANT_CABINET_GUIDE_RU.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/ACCOUNTANT_CABINET_GUIDE_RU.md) · [H3214 (Grok 4.6) — Wave 3: accountant operational book in /admin](https://github.com/gasyoun/Uprava/blob/main/handoffs/H3214-Grok_Systema-Sanscriticum_accountant-cabinet-guide-illustrated_21.08.26.md) |
| Шесть сценариев | проводка · зарплата · opex · штурвал · потоки · разметка |
| Живая очередь | [PayoutAttributionGuide](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Filament/Pages/PayoutAttributionGuide.php) — список из очереди, не из md |
| Публичный GitHub | [accountant-guide.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/accountant-guide.md) — карта меню. Правило 18-08-2026: указания сотруднику в кабинете |
| Нет в книге, есть в панели | взаимозачёт · история выплат · возврат/депозит · KPI делегирования · фонды прибыли · юнит-экономика |
| Вне LMS | банк, чек НПД, ИП — код их не знает |

**PARTIAL, не NEW.** Не переписывать шесть сценариев. Не трогать PayoutAttributionGuide. Строится зазор: Часть 0 (карта) + четыре недостающих еженедельных сценария + короткий блок «вне кабинета».

## 2. Решения

Интервью 21-08-2026: виджет раунда 1 человек закрыл без ответа. Ниже — помеченные рекомендации того раунда и дефолты раундов 2–5 (как в плане гидов аудиторий, когда круги приёмки пропущены).

| № | Развилка | Решение | Почему | Источник |
|---|---|---|---|---|
| 1 | Что такое «готово» | Карта + дописать пробелы, шесть сценариев оставить | Книга H3214 живая; дыра — карта мира и четыре работы | дефолт (виджет закрыт) |
| 2 | Что входит в «всё» | Три слоя: делает / читает / нет в роли | Иначе снова энциклопедия юнит-экономики | дефолт |
| 3 | Где карта | Часть 0 той же страницы `/admin/accountant-guide` + PNG в git (на карте нет ФИО и сумм) + PDF на стенде | В отличие от кадров живой очереди, карта без ПДн | дефолт |
| 4 | Читатель | Бухгалтер-оператор + полоса «решает владелец». Имя сотрудника только в кабинете, не в публичном GitHub | Правило 18-08-2026 | дефолт |
| 5 | Вне рамок | Должники, маркетинг, каталог, ДЗ, money-core — один ящик «не ищите». Без туториалов | Уже есть гид куратора и finance-manual | дефолт |
| 6 | Файл | Расширить [ACCOUNTANT_CABINET_GUIDE_RU.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/ACCOUNTANT_CABINET_GUIDE_RU.md) на месте | Второй пункт меню раздвоит вход | дефолт |
| 7 | Источник карты | HTML-постер в репо, скрин в `docs/screenshots/accountant-map/` | Текст на схеме должен быть точным — не image model | дефолт агента |
| 8 | Новые сценарии | взаимозачёт · история выплат · возврат · депозит/бронь. НПД/банк — «вне кабинета», не выдуманный экран | Это еженедельные дыры | дефолт агента |
| 9 | Юнит-экономика / лиды | Ящик слоя 2 «читаете», без how-to | Владелец цифр, не проводка | дефолт агента |
| 10 | Кадры новых сценариев | Как H3214: storage, не git, если на кадре живые суммы. Карта — исключение, в git | H3084 | дефолт агента |
| 11 | Приёмка | Карта в HTML книги · PNG открывается · 200 под finance() · 403 без роли · ноль живых ФИО/сумм выплат в md · шесть старых сценариев на месте | Иначе снова «выглядит нормально» | дефолт агента |
| 12 | Неясность | Помеченный дефолт + строка в журнале PR | Как гид преподавателя | дефолт агента |
| 13 | Стоп | Запись в `payments` / `teacher_payouts` · правка чужих RoleGate · копия живой очереди в md · фейковый PNG | Необратимый ущерб | дефолт агента |
| 14 | Коммит | PR→мерж без спроса; деплой Systema; смоук GET `/admin/accountant-guide` под finance | always-merge + deploy-without-reask | дефолт агента |
| 15 | Забор | PayoutAttributionGuide · TeacherGuide · вебхуки · схема БД · `canAccess` чужих страниц | Книга, не продукт денег | дефолт агента |

## 3. Контракт автономности

- **При неясности** — дефолт из таблицы, строка в журнале PR. Не спрашивать, не придумывать третий путь.
- **Не трогать:** (а) чужие `canAccess` / меню; (б) миграции; (в) код платежей и вебхуков; (г) [PayoutAttributionGuide](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Filament/Pages/PayoutAttributionGuide.php) кроме ссылки; (д) запись в продовые денежные таблицы.
- **Разрешено:** править `docs/ACCOUNTANT_CABINET_GUIDE_RU.md`, добавить HTML+PNG карты, поправить публичный `accountant-guide.md` одной строкой «открой карту в кабинете», coverage-тест на новые якоря, PDF-сборщик если он уже есть.
- **Остановиться** при любом стопе решения 13. Нет Chrome — карта как HTML+PNG из этого плана (файл уже снят), не генерировать image-model PNG.
- **Права на коммит:** handoff волны 1 существует → коммит, PR, мерж без запроса. Деплой Systema. Смоук: страница книги открывается, карта видна.
- **Данные:** в md только учебные `1000 ₽` и «Студент Учебный».

## 4. Что остаётся человеку (не блокирует волну 1)

Живой прогон бухгалтером после выкладки. Если никто не пройдёт — карта всё равно открывается в кабинете.

_Dr. Mārcis Gasūns_
