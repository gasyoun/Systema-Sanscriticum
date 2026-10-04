# Плейбук: запуск курса с нуля + self-serve как есть (боты, сайт)

_Created: 04-10-2026 · Last updated: 04-10-2026_ · [метадок](PLAYBOOK_COURSE_LAUNCH_FROM_ZERO_2026-10.meta.md)

Обоснование: единого плейбука не было — запуск «Традиций толкования упанишад» 04-10-2026 собрал всё в одном проходе ([H5864](https://github.com/gasyoun/Uprava/blob/main/handoffs/archive/H5864-OxAlpha_Systema-Sanscriticum_webinar-upanishady-5okt-prod-prep_04.10.26.md), [H5910](https://github.com/gasyoun/Uprava/blob/main/handoffs/archive/H5910-OxAlpha_Systema-Sanscriticum_leads-email-integrity-guard_04.10.26.md)). Всё ниже проверено на проде; смежные спеки: [access-self-service-spec.md](access-self-service-spec.md) (кабинет), [debtor-self-service-spec.md](debtor-self-service-spec.md) (долги).

## Карта самообслуживания (кто что делает сам)

| Этап | Канал | Человек нужен? |
|---|---|---|
| Запись на вебинар/вводное | форма лендинга → thank-you → бот | нет |
| Ссылка на эфир + подарок | `@samskrte_bot` шлёт мгновенно (подпись магнита) | нет |
| Оплата курса | страница курса → чекаут (карта Точка / PayPal / счёт юрлицу) | нет |
| Доступ после оплаты | автоматически, сразу | нет |
| Записи занятий | личный кабинет + `@zapisi_ORSbot` | нет |
| Уведомления (домашки, сертификаты, дожимы) | `@samskrtamru_bot` (кабинетный, привязка `/telegram/connect`) | нет |
| Бот-куратор | в кабинетном боте | нет |
| Голосование за будущие группы (ждун) | `/zhdun` в `@samskrte_bot` + [samskrte.ru/online/zhdun](https://samskrte.ru/online/zhdun) | нет |
| Куратор | `@rusamskrtam` — **только экстренные случаи** (рулинг MG 04-10-2026) | да |

Роли ботов: `@samskrte_bot` — лидовый/магазинный (магниты, статусы, ждун); `@samskrtamru_bot` — кабинетный (уведомления, бот-куратор); `@zapisi_ORSbot` — записи. Вебхуки: входной узел 103.112.71.201 (ssh -R релей, штатный) → прод; магнитный вебхук — `/api/webhooks/telegram-magnet` (глобальный бот: allowed_updates = message + callback_query).

## Запуск курса с нуля — чек-лист

### 1. Упаковка в Filament (прод-админка)
- [ ] Course: title, slug, преподаватель, `lessons_count`/`hours_count` **равные правде** (они на витрине), level, format.
- [ ] Тарифы: **блоки — по умолчанию всегда 4** (рулинг MG 04-10) × цена + опционально «Весь курс целиком» (как у потока-2025: 4×4800 + 16500). Чекауты (`/checkout/{id}`) создаются сами.
- [ ] Описание курса заполнить сразу — NULL рендерит заглушку «скоро появится». `meta_description` — для витрины/SEO.
- [ ] `trial_schedule_id` НЕ ставить для бесплатных вводных: trial-флоу платный (`TrialController` 403 при `trial_price<=0`). Бесплатное вводное = лендинг (ниже).

### 2. Лендинг вводного/вебинара (таблица `landing_pages`, Filament)
- [ ] Контент — клон структуры существующего лендинга (10 блоков: hero с формой, topics, instructor, format, FAQ, отзывы).
- [ ] `webinar_date` — оживляет сайдвайв-баннер на всём сайте (аптайм-механика `App\Support\NextIntroSession`, кэш 120 c; после правок `NextIntroSession::flushCache()`). Дата берётся только из данных — код не выдумывает.
- [ ] `webinar_url` — публичная ссылка (стрим YouTube), `lead_magnet_caption` — с той же ссылкой: бот отдаёт магнит и адрес эфера одним сообщением.
- [ ] Проверка: баннер на главной показывает «Ближайшее — дата», кнопка «Записаться»; тестовая заявка → thank-you → кнопка бота с deep-link.

### 3. Заявки и боты
- [ ] Заявки падают в `leads` (Filament-конвейер, статусы, подавления). Ничего настраивать не нужно — глобальный бот из `marketing_settings` (`@samskrte_bot`).
- [ ] Свой бот на лендинг — опция (`landing_bots`), по умолчанию не нужна.
- [ ] Ждун (курс будущий): `CourseWaitlistItem` в Filament (is_listed, статус collecting, min_payers) — голосование сразу работает на сайте и в боте (`/zhdun`).
- [ ] Защита от мусора включена на уровне БД: CHECK `users_email_valid` / `leads_email_valid`; на путях приёма — правило `App\Rules\HouseEmail`. «Нет email» = `NULL`/`''` (leads) или `*@no-email.com` + `SuppressedEmail` (users).

### 4. Рассылка по базе (если нужна)
- [ ] Флаг `EMAIL_CAMPAIGNS=true` включён (04-10-2026). Кампания в Filament `/admin/campaigns`: сегмент (напр. `tg_unbound_payers` — платившие без Telegram), текст, отправка. Подавления и трекинг кликов/открытий — автоматом.
- [ ] После ЛЮБОГО флипа флага/деплоя — `php artisan queue:restart` (или horizon:terminate): воркеры, не увидевшие новый конфиг, молча пропускают письма.

### 5. Приём оплат
- [ ] Живёт сам: чекауты и автодоступ (Tochka, `PaymentController`), PayPal для заграницы (`features.paypal_subscriptions` + ручной клейм `PaypalClaimController`), счёт юрлицу (`config/billing.php` company_invoice), чеки ККТ (H2017). Проверка перед анонсом: открыть `/checkout/{tariff_id}` — 200 и форма `payment/create`.

### 6. Финальный смок перед анонсом
- [ ] Страница курса: описание, счётчики, ровно ожидаемое число чекаут-кнопок, баннер с датой.
- [ ] Тестовая заявка на лендинге: бот прислал магнит со ссылкой.
- [ ] Тестовый заказ до шага оплаты (без списания).
- [ ] Пост в канале: ссылки без «голых» UTM (шаблон — [ORS-FAQ Telegram_templates](https://github.com/gasyoun/ORS-FAQ/blob/main/Telegram_templates.md)).

## Что НЕ самосервис (рулинги)
- 4 блока по умолчанию — константа упаковки (MG 04-10).
- Zoom-комната вводных — создаётся человеком (Иван) либо куратор раздаёт публичный стрим; автопостинг ссылок в чаты — флаг `class_link_autopost_enabled`, до включения вручную.
- Импорты учеников — только с плейсхолдерами `import-{id}@no-email.com` + suppression (мусор в email не пройдёт CHECK).

_Гасунс_
