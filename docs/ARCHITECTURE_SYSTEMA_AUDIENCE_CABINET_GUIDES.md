# Архитектура: иллюстрированные мануалы трёх аудиторий

_Created: 21-08-2026 · Last updated: 21-08-2026_

Слой «как устроено» для [PLAN_SYSTEMA_AUDIENCE_CABINET_GUIDES_2026H2.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/PLAN_SYSTEMA_AUDIENCE_CABINET_GUIDES_2026H2.md). Порядок шагов волны 1 — в [IMPLEMENTATION_SYSTEMA_AUDIENCE_CABINET_GUIDES_W1.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/IMPLEMENTATION_SYSTEMA_AUDIENCE_CABINET_GUIDES_W1.md).

## 1. Один источник на аудиторию, разные каналы

| Аудитория | Источник | Страница | PDF | PNG |
|---|---|---|---|---|
| Студент | `docs/STUDENT_CABINET_GUIDE_RU.md` | `/dvaram/help` (Blade, auth student) | `docs/build-student-guide.php` | git: `docs/screenshots/student-guide/` |
| Куратор | `docs/CURATOR_ADMIN_GUIDE_RU.md` | Filament `/admin/curator-guide` | сборщик по образцу препода | git, фикстура; денежные кропы по забору |
| Бухгалтер | `docs/ACCOUNTANT_CABINET_GUIDE_RU.md` | Filament `/admin/accountant-guide` | только на стенде | `storage/app/guide-shots/accountant/`, не git |

Ни один канал не держит своей копии текста. Правка `.md` меняет страницу и PDF.

Командные файлы, которые **не** становятся продуктом для человека:

- [student-manual.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/student-manual.md) — карта для агента/куратора-технаря
- [admin-manual.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/admin-manual.md) — после волны 2 редирект
- [accountant-guide.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/accountant-guide.md) — после волны 3 карта + «открой в кабинете»
- [onboarding-student.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/onboarding-student.md) — чеклист первых 5 минут, не заменяется гидом

## 2. Рендерер

Класс `App\Support\MarkdownGuide`:

1. Читает файл по **константному** пути. Параметра из запроса нет — иначе XSS на неэкранированном HTML (тот же контракт, что у TeacherGuide).
2. `Str::markdown()`.
3. Подставляет `src` картинок: для git-кадров — raw GitHub, как TeacherGuide; для бухгалтера — URL защищённого маршрута, который стримит файл из `storage` под тем же `RoleGate::finance()`.
4. Файла нет — `null`, страница показывает одну строку, не 500.

Filament-страницы волны 2/3 и студенческий контроллер — тонкие: `canAccess` + вызов `MarkdownGuide::html(self::SOURCE)`.

TeacherGuide в волне 1 не переводим на этот класс: забор решения 25.

## 3. Разметка источника

Как у преподавателя, потому что PDF идёт через Dompdf / DejaVu Sans:

- `##` раздел оглавления, `###` сценарий. Четвёртый уровень не используем.
- Шаги нумерованным списком. У куратора/бухгалтера — текстовые бейджи `**[Безопасно]**` / `**[Видно студенту]**` / `**[Необратимо]**` (эмодзи в PDF пустые).
- У студента бейджи обратимости не нужны: ученик не ломает чужие данные. Вместо них — одна строка «что получится».
- Кадр: относительный путь `screenshots/student-guide/<slug>-1440.png` сразу под шагом. Телефон — тот же slug с `-390`.
- HTML в `.md` запрещён. Выноски на картинке не делаем в волне 1 (Dusk/Playwright их не рисуют). Если шаг без кадра нечитаем — это дефект сценария, не повод клеить стрелки.

## 4. Сценарии студента (волна 1, часть I)

Семь штук, по частоте, не по вкладкам:

1. Войти в кабинет (email заказа, соцвход, magic-ссылка от куратора, «запомнить меня»)
2. Открыть следующий урок
3. Сдать домашнее задание (форма, статусы, неверный файл) — ссылка на уже живой `/faq/dz`
4. Найти слово в словаре
5. Оплатить долг или взнос по рассрочке
6. Почему урок закрыт
7. Позвать куратора в чате

Часть II — справочник вкладок (курсы, оплаты, долги, прана, колода, лила, профиль). Прана decay ссылается на `/help/prana-balance`, не дублирует.

Часть III — «что нельзя сломать»: пароль на чужом компьютере, remember-me.

Часть IV — FAQ из onboarding, без внутренних имён.

Названия кнопок — дословно как в интерфейсе.

## 5. Playwright, не Dusk

Dusk остаётся у преподавателя и VisualDCS. Второй браузерный стек — сознательное решение 6.

```
scripts/capture-guide-screenshots.mjs
  --guide student
  --base-url http://127.0.0.1:8000
```

Вход: JSON-манифест `docs/generated/student_guide_shots.json` — список `{slug, path, wait, auth: "student"}`. Auth: логин фикстурного студента (фабрика, не прод). Ширины 1440 и 390. Пишет PNG в `docs/screenshots/student-guide/`.

Бухгалтерский профиль того же скрипта пишет в `storage/app/guide-shots/accountant/` и в git ничего не кладёт.

`@playwright/mcp` и pixelbrowse — глаза агента на приёмке, не пайплайн кадров.

Зависимость: `npm i -D playwright` и `npx playwright install chromium` один раз на машине. В CI GitHub Actions Playwright **не** заводим (решение 15).

## 6. Наблюдаемые пути для freshness (студент)

Дублируется в скрипте, CI markdown не читает:

- `resources/views/student/**`
- `app/Http/Controllers/StudentController.php`
- `app/Http/Controllers/SrsController.php`
- `resources/views/help/**`
- `routes/web.php` (только если менялись `/dvaram` или `/help`)

Предупреждение, если эти пути в diff PR, а `docs/STUDENT_CABINET_GUIDE_RU.md` нет. Код выхода всегда 0.

## 7. Безопасность кадров

| Вид | Git | Почему |
|---|---|---|
| Кабинет студента, фикстура «Анна Учебная» | да | Нет живых ФИО и платежей |
| Админка куратора, фикстура | да, без колонок сумм долга | Как teacher-guide |
| Финэкраны бухгалтера | нет | H3084; даже фикстура показывает внутренности выплат |

Публичный репозиторий остаётся PUBLIC. Проверка перед коммитом PNG: нет реального email, нет сумм > учебных 1000 ₽ в имени файла, нет `storage/app/guide-shots/`.

## 8. Связь с уже живыми help-страницами

Гид не подменяет `/faq/dz` и `/help/prana-balance`. Он на них ссылается. Новый URL — один: `/dvaram/help`. Старые не ломаем.

_Dr. Mārcis Gasūns_
