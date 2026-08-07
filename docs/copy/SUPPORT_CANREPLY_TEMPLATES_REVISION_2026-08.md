# Ревизия канреплаев поддержки — 2 месяца Telegram + magic-link

_Created: 07-08-2026 · Last updated: 07-08-2026_

Ревизия библиотеки `MessageTemplate` (категория **support**) по живым
входящим Telegram-сообщениям prod (`telegram_support_messages`,
`direction=incoming`, `sent_at` ≥ 2026-06-07) и по ленте топиков
(`support_topic_assignments` × `support_daily_rollups`).  
Исполнитель: Grok 4.5 (`grok-4.5`). Handoff:
[H2339](https://github.com/gasyoun/Uprava/blob/main/handoffs/H2339-Grok_Systema-Sanscriticum_support-canreply-templates-2m-revision_07.08.26.md).

Связанные: [MANUAL_CURATOR_MAGIC_LOGIN_LINK_RU.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/MANUAL_CURATOR_MAGIC_LOGIN_LINK_RU.md) ·
[support-reply-library-ru-register-a-f.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/copy/support-reply-library-ru-register-a-f.md) ·
[MessageTemplateSeeder.php](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/database/seeders/MessageTemplateSeeder.php).

---

## 1. Метод

| Источник | Окно | Что смотрели |
|---|---|---|
| `telegram_support_messages` | ~2 мес по `sent_at` | **1089** incoming с текстом |
| Keyword-прокси по тексту | то же | частоты тем (не взаимоисключающие) |
| `support_topic_assignments` | conversation_date ≥ 2026-06-07 | **1017** меток (456 uncategorized) |
| `message_templates` prod | снимок 07-08-2026 | **3** строки (только D/E/F суггестера) |
| Seeder repo (до H2339) | — | ack + D/E/F + lead + dozhim + reactivation |

Ограничения: keyword-счётчики **шумят** (бот-напоминания Zoom/группы, «спасибо»,
пересылки чеков). Выводы — по **соотношению тем + ручным сэмплам**, не по
«точности до одного сообщения».

---

## 2. Картина входящих (~2 мес)

### 2.1 Topic assignments (именованные)

| Категория | n | Доля среди named |
|---|---:|---:|
| access | 189 | ~34% |
| payment | 148 | ~26% |
| schedule | 139 | ~25% |
| technical | 61 | ~11% |
| materials | 14 | ~2% |
| refund | 8 | ~1% |
| certificate | 2 | менее 1% |
| uncategorized | 456 | — |

### 2.2 Keyword-прокси (incoming, не exclusive)

| Тема | n | Смысл для шаблонов |
|---|---:|---|
| payment | 165 | Куда платить, чек, «оплатил», старая ссылка |
| thank_you | 154 | Не шаблон |
| group (шум) | 66 | Часть — бот «группа N»; часть — состав/перевод |
| cabinet | 49 | ЛК, «не даёт попасть», ДЗ в кабинете |
| password / login | 39 | Пароль, forgot, «после оплаты пароль на почте?» |
| telegram / bot | 35 | Привязка, уведомления |
| access_course | 31 | Доступ к курсу / лекциям |
| homework | 29 | Сдача/проверка ДЗ |
| schedule | 24 | Когда старт, расписание |
| recording | 19 | Запись занятия |
| materials | 17 | Конспекты / файлы |
| course_info | 13 | «Хочу на…», интерес к курсу |
| zoom | 13 | Ссылка (часто уже в боте) |
| technical | 12 | Ошибки сайта / VPN |
| promo | 10 | Скидки |
| debt | 8 | Долг / следующий блок |
| teacher_contact | 8 | Телефон / связь с преподом |
| abroad_pay | 3 | Зарубежная оплата |
| certificate | 1 | Редко |
| refund_freeze | 0 | В keyword-срезе почти нет |

### 2.3 Характерные сэмплы (редакция)

- «Оплату сделала… **Пароль ко входу пришел на почту?**»
- «**Не могу зайти на сайт в лк уже 4 день**… разные браузеры»
- «ссылка заставила сбросить пароль… **ссылку на оплату не нашла**»
- «**как получить доступ к личному кабинету**»
- «Куда можно **оплатить** курс?» / «Оплатить без регистрации?»
- «Не дает попасть в кабинет или **залить домашку**?»
- «**образцовая домашка**» / «кто не залил домашку»
- «буду смотреть **запись**»
- Чеки: «Отправляю чек» / «Теперь сюда не надо чеки присылать?»

---

## 3. Gap-анализ (было → нужно)

### Уже было (достаточно / частично)

| Что | Где | Замечание |
|---|---|---|
| D — оплата/тарифы (общий) | Seeder + prod «Суггестер D» | Слишком общий: нет «чек получен», нет «куда платить» |
| E — доступ/кабинет (общий) | Seeder + prod «Суггестер E» | Нет magic-link, нет forgot-password пошагово |
| F — материалы/ДЗ (общий) | Seeder + prod «Суггестер F» | Нет «как сдать ДЗ», нет «где запись» |
| Приняли в работу | Seeder | Ок |
| Лид / дожим / реактивация | Seeder | Не support-канреплай; не трогали |
| Zoom/schedule/recording facts | SupportAnswerFactResolver A/B/C | Суггестер; **канреплая для ручной отправки не было** |
| Текст magic-link | MANUAL §7 | **Только markdown**, не `MessageTemplate` |

### Добавлено в seeder (H2339)

| Title | Зачем (из данных) | Плейсхолдеры |
|---|---|---|
| **E1 — вход по email (forgot-password)** | password 39 + cabinet 49; первый шаг self-service | `{name}` `{email}` |
| **E2 — magic-link вход** | MANUAL §7 + кейсы «не знает пароль» / Yahoo | `{name}` `{email}` `{login_link}`* |
| **E3 — после оплаты: пароль на почте** | прямой сэмпл post-pay | `{name}` `{email}` |
| **E4 — не пускает в кабинет (тех)** | «4 дня не захожу», technical | `{name}` `{email}` |
| **D2 — куда оплатить / ссылка** | payment 165 top | `{name}` `{course}` `{pay_link}` |
| **D3 — чек получен, доступ откроем** | поток чеков + shared string «пара минут» | `{name}` `{email}` |
| **F2 — как сдать ДЗ** | homework 29 + «залить домашку» | `{name}` |
| **F3 — где запись урока** | recording 19 | `{name}` |
| **C — расписание / когда старт** | schedule 139 topic / 24 kw | `{name}` |
| **A — Zoom / ссылка на занятие** | schedule+zoom; ручной fallback | `{name}` |
| **TG — привязка Telegram** | bot 35; CRM часто без telegram_id | `{name}` |
| **G — перевод в другую группу** | group-тема; операционный ack | `{name}` |

\* `{login_link}` **не** авто-подставляется (одноразовая выдача из
«Разблокировать»); куратор вставляет URL вручную. `{email}` — из карточки
(`MessagePlaceholders`, H2339); `*@no-email.com` → пусто.

### Не добавляли (сознательно)

| Тема | Почему |
|---|---|
| Спасибо / small talk | Не канреплай |
| Телефон преподавателя / личные контакты | Риск PII; только вручную |
| Зарубежная оплата (PayPal/крипта) | n≈3; есть money-copy; шаблон позже если вырастет |
| Возврат / заморозка | n низкий; нужна юридическая формула, не болванка |
| Сертификат | n=1–2; хватает F |
| Образцовая «домашка» / педагогика | Не support-runbook |
| Долги / следующий блок | Уже debtor-reminder + finance manuals |
| Промокод / скидка | n=10; лучше из CRM-акции, не общий текст |

---

## 4. Как катить на prod

1. Задеплоить ветку H2339.
2. На prod: `php artisan db:seed --class=MessageTemplateSeeder`  
   (идемпотентно: `firstOrCreate` по `title` — существующие D/E/F **не** затирает).
3. Куратор: **Сообщения → Шаблоны** — найти «Поддержка · E2…», при ответе
   подставить magic-link из «Разблокировать».
4. Опционально: в админке привязать E1/E2 к `suggester_category=E`, D2/D3 к `D`,
   F2/F3 к `F` (S9) — seeder **не** ставит category сам (как D/E/F H1876).

---

## 5. Регистр

Как [support-reply-library-ru-register-a-f.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/copy/support-reply-library-ru-register-a-f.md):
«вы» со строчной; «Намасте, {name}!» как маркер школы; без эмодзи и срочности;
«ё» только в «всё»; денежный страх — «не платите повторно» (D2/D3).

---

## 6. Следующая ревизия

Через ~3 месяца или после волны magic-link usage: пересчитать keyword + topic
assignments; если abroad_pay / refund / certificate вырастут >20 — добавить
узкие шаблоны; если E2/E1 usage в audit >0 — не дублировать в MANUAL.

_Dr. Mārcis Gasūns_
