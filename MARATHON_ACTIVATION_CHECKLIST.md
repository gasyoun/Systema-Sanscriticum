_Created: 10-07-2026 · Last updated: 05-09-2026_

# Марафон «Консультация по онлайн-курсам ОРС» — чек-лист активации на проде

_Создано: 10-07-2026 · Обновлено: 16-08-2026_

> **Свежая сверка с продом 16-08-2026 ([H2865](https://github.com/gasyoun/Uprava/blob/main/handoffs/H2865-Opus_Systema-Sanscriticum_28aug-integrated-launch-gate_16.08.26.md)):
> пункты 1–6 уже ВЫПОЛНЕНЫ на проде, открыт только пункт 7 (после эфира).**
> Построчные доказательства и вердикт по каждому шлюзу — в
> [docs/LAUNCH_GATE_28_08_2026.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/LAUNCH_GATE_28_08_2026.md).
> Этот файл остаётся описанием того, ЧТО делает каждый шаг; актуальное состояние — там.

Все 6 фаз H440 влиты в `main` ([H446](https://github.com/gasyoun/Uprava/blob/main/handoffs/archive/H446-Sonnet_Systema-Sanscriticum_marathon-diagnostic-phase1-landing-capture_10.07.26.md)/[H464](https://github.com/gasyoun/Uprava/blob/main/handoffs/archive/H464-Sonnet_Systema-Sanscriticum_marathon-diagnostic-phase2-drip-engine_10.07.26.md)/[H483](https://github.com/gasyoun/Uprava/blob/main/handoffs/archive/H483-Sonnet_Systema-Sanscriticum_marathon-diagnostic-phase3b-tap-choice-ui_10.07.26.md)/[H471](https://github.com/gasyoun/Uprava/blob/main/handoffs/archive/H471-Sonnet_Systema-Sanscriticum_marathon-diagnostic-phase4-paid-track-checkout_10.07.26.md)/[H487](https://github.com/gasyoun/Uprava/blob/main/handoffs/archive/H487-Sonnet_Systema-Sanscriticum_marathon-diagnostic-phase5-live-consultation_10.07.26.md)/[H489](https://github.com/gasyoun/Uprava/blob/main/handoffs/archive/H489-Sonnet_Systema-Sanscriticum_marathon-diagnostic-phase6-warm-tail_10.07.26.md)), код-комплит. Это единый чек-лист активации, консолидирующий [`DEPLOY_QUEUE.md`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/DEPLOY_QUEUE.md) пункты №12–17 в один документ — прогнать по порядку перед первым запуском когорты **28-08-2026**.

⚠️ **Устарело с 30-07-2026 (H1933): код деплоится САМ** — root-крон на проде каждые
30 минут выкатывает `origin/main` через `deploy.sh`. Прежняя формулировка «деплой делает
человек, у агента нет доступа» **неверна дважды**: ручной шаг ушёл, и SSH/root у агента
есть (`root@193.232.229.92`, исправлено ещё в H478). Человеку остаются только флаги,
разовые artisan-команды и внешние шаги (Точка/BotFather/Filament). Прод — root-VPS
(Ubuntu, Beget); ручной fallback при сбое авто-деплоя — `sudo bash deploy.sh`.

---

## 1. Миграция (база для всего остального)

```bash
php artisan migrate
```

Подтягивает аддитивные колонки всех 6 фаз: `marathon_enrollments.{paid_at, day1_engaged_at, day2_engaged_at, consultation_booked_at, recording_sent_at, warm_tail_last_day_sent}`. Без этого шага ничего ниже не заработает.

## 2. Лендинг (Filament → LandingPage)

Создать запись со слагом из `MARATHON_LANDING_SLUG` (дефолт `konsultaciya-po-onlayn-kursam`). Без этой строки `/online/konsultaciya` рендерится (не 500), но без брендинга лендинга — страница уже рабочая и без нее, можно отложить, но не рекомендуется для боевого запуска.

## 3. Telegram-бот (Filament → Marketing Settings)

Заполнить `tg_bot_username`/`tg_bot_token` для бота `@samskrte`. **Обязательно** — без этого `magnet_token`-диплинк в письме/на странице ведет на несуществующего бота, и весь дрип-движок (Day 1/2/3) молчит.

## 4. Точка (Tochka) — платежный гейтвей

Проверить `TOCHKA_API_TOKEN`/`TOCHKA_CUSTOMER_CODE` и т.д. в `.env`. Тот же гейтвей, что уже используют курсы/депозит/пробное — вероятно, уже настроен, но подтвердить перед тем как полагаться на чекаут ₽500 «с проверкой». Без него кнопка «Оплатить» отдает «Сервис оплаты временно недоступен» вместо реальной ошибки конфигурации.

## 5. Живая консультация Дня 3 (Filament → Расписание)

Создать ОДНУ запись `Schedule`: заголовок, дата/время, Zoom-ссылка. **Без привязки к курсу/группе** (поле `course_id`/`group_id` оставить пустым — тот же паттерн, что `Course::trialSchedule()`). Скопировать ID записи.

```bash
# в .env:
MARATHON_SCHEDULE_ID=<id записи Schedule>
php artisan config:clear
```

**Обязательный шаг** — без него Day 3 молча не отправляется (не ошибка, просто `marathon:deliver-due` тихо пропускает блок Дня 3, пока `schedule_id` не настроен).

## 6. Планировщик (cron)

Все три команды марафона (`marathon:deliver-due`, `marathon:deliver-recording`, `marathon:deliver-warm-tail`) уже прописаны в `Kernel::schedule()` с интервалом 15 минут — запустятся сами, если на сервере есть запись cron, вызывающая `schedule:run` раз в минуту:

```bash
* * * * * php artisan schedule:run >> /dev/null 2>&1
```

Если эта запись уже есть (обслуживает и другие джобы — `receivables:check`, `finance:kpi-digest`, `groups:notify-forming-shortfall` и т.д.), отдельно для марафона ничего заводить не нужно.

## 7. После живого эфира Дня 3

Вернуться в ту же запись `Schedule` (Filament → Расписание) и проставить `zoom_recording_url`. Это единственный триггер `marathon:deliver-recording` — команда сама разошлет ссылку на запись обоим трекам (платному и бесплатному), идемпотентно, ничего больше делать не нужно.

---

## Проверка после активации

- [ ] `/online/konsultaciya` открывается и не отдает 500
- [ ] Регистрация создает `Lead` + `MarathonEnrollment`, кнопка «Продолжить в Telegram» ведет на реального бота
- [ ] Бот действительно шлет День 1 через ~15 минут после `/start` (или раньше, если `day0_started_at` уже сдвинут для теста)
- [ ] Оплата ₽500 «с проверкой» доходит до Точки и возвращается на `payment.success`
- [ ] Страница «Вопросы марафона (День 3)» в админке (Filament, только чтение) показывает тестовый `day2_question`
- [ ] После проставления `zoom_recording_url` на тестовой `Schedule`-записи приходит сообщение с записью

## Что сознательно НЕ построено (отдельные будущие handoff'ы)

- Промокод ₽1000 на первый курс — авто-выдачи нет, `PromoCode`-механизм реальный, но не подключен к этому событию (см. [H471](https://github.com/gasyoun/Uprava/blob/main/handoffs/archive/H471-Sonnet_Systema-Sanscriticum_marathon-diagnostic-phase4-paid-track-checkout_10.07.26.md) «Why this scope»).
- Зачет ₽500 во флагманский курс — `Tariff::upgradeCreditForUser` завязан на `Tariff`-контейнмент, у марафонского платежа `Tariff` нет.
- Интерактивные inline-кнопки в самом Telegram (сейчас тап-выбор — веб-страница по ссылке, не callback в боте) — сознательное решение, не трогать общий webhook-путь (см. H483/H487 «Why this scope»).

---

_Источник фаз: [`Uprava/custdev/MARATHON_DIAGNOSTIC_2026.md`](https://github.com/gasyoun/Uprava/blob/main/custdev/MARATHON_DIAGNOSTIC_2026.md) · полный билд-план [H440](https://github.com/gasyoun/Uprava/blob/main/handoffs/archive/H440-Sonnet_Systema-Sanscriticum_marathon_diagnostic_3day_09.07.26.md)._

_Dr. Mārcis Gasūns_
