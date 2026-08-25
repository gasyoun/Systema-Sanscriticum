# План: self-serve Systema — детерминированный слой, три волны (2026H2)

_Created: 25-08-2026 · Last updated: 25-08-2026_

Цель: углубить и расширить self-serve школы так, чтобы входящие сообщения сортировались и
частично закрывались детерминированно (без LLM и без куратора), а поверхности самообслуживания
подняли конверсию без человеческих касаний. Метод `/ask`: все 19 решений приняты до старта,
ни одной висящей развилки; свежий агент исполняет волны по документам ниже без вопросов.

## Слои плана

| Документ | Что содержит |
|---|---|
| [ROADMAP_SYSTEMA_SELF_SERVE_2026H2.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/ROADMAP_SYSTEMA_SELF_SERVE_2026H2.md) | Волны В1→В2→В3, deliverables, не-цели |
| [ARCHITECTURE_MESSAGE_INTENT_CLASSIFIER_2026.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/ARCHITECTURE_MESSAGE_INTENT_CLASSIFIER_2026.md) | Пакет `message-intent-classifier`, таксономия, контракты |
| [IMPLEMENTATION_SELF_SERVE_WAVE1_2026H2.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/IMPLEMENTATION_SELF_SERVE_WAVE1_2026H2.md) | Пошаговая сборка волны 1, файлы, зависимости |
| [VERIFICATION_SELF_SERVE_CLASSIFIER_2026H2.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/VERIFICATION_SELF_SERVE_CLASSIFIER_2026H2.md) | Критерии приёмки, команды, реестр рисков |

## Принятые решения (интервью 25-08-2026, все рулинги MG)

| # | Развилка | Решение |
|---|---|---|
| 1 | Цель спана | Всё по волнам: поддержка → конверсия → аналитика |
| 2 | Каналы | Все текстовые (TG DM @rusamskrtam, FAQ-бот, Q&A-виджет, сайт-чат, VK) + входящая почта |
| 3 | Метрика готовности | Coverage ≥85% входящих @ precision ≥93% на категорию, видно ежедневно |
| 4 | Периметр | Гостевая регистрация — внутри спана; рекуррентные платежи, ESP на samskrtam.ru, донаты — вне |
| 5 | Движок | Общий переиспользуемый пакет (стиль SHARED_CODE), потребляют Systema и ORS-FAQ |
| 6 | Таксономия | Три независимые плоскости на сообщении: topic / objection (B1–B11) / intent + мета-теги |
| 7 | Источник правил | Git-first: YAML в репо пакета → сидирование `SupportTopicRule` при деплое; правки только PR |
| 8 | Исторические корпуса | Офлайн JSONL+MD-отчёты в репо; прод-БД историческими тегами не загрязняется |
| 9 | Дашборд | Расширение Filament-страницы telegram-support-analytics |
| 10 | Форма пакета | Data-пакет: YAML + референс Python-движок + тонкий PHP-лоадер + общий харнесс |
| 11 | Канон корпуса | Eval = замороженный экспорт 05-07-2026 (2621 диалог); train/adapt = снапшот 22-08-2026; PII-маскировка перед коммитом |
| 12 | Автоответы | Существующие ON-полосы не трогать; новые = env-флаг + гейт ≥93% + неделя ручного контроля |
| 13 | Почта | Код канала уже построен dark ([InboundEmailIngester](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Services/Support/InboundEmailIngester.php)); остаются только человеческие шаги (ящик zabota@, n8n-forwarder, флаг) |
| 14 | Гостевой вход | `/register` (e-mail+пароль) → автоматом Free-tier клуба + персистентная SRS-колода; за дефолт-OFF флагом |
| 15 | Приёмка | Команда + артефакт + гейт: ≥93% precision на категорию при достаточной выборке, ноль регрессий на замороженном eval |
| 16 | Фенс исполнителя | Не трогать: payments/Tochka/webhook-код, raw-PII сторы, `.env`; никаких ручных записей в прод-БД |
| 17 | Автономность | Неоднозначность → помеченный дефолт из этого плана + запись в лог; стоп-условия ниже; commit→PR→merge разрешён |
| 18 | Порядок волн | В1 бесплатно (классификатор+корпуса+метрики) → В2 поверхности → В3 лид-аналитика |
| 19 | Исполнитель | Все handoffs — OxAlpha, включая дизайн таксономии |

## Автономный контракт исполнителя (дословно)

- **На неоднозначности**: брать помеченный дефолт из этого плана и логировать решение в run-log;
  не парковать, не ждать человека.
- **Стоп-условия** (остановиться и отчитаться): precision <93% после цикла правок правил;
  подозрение на утечку PII в коммит; необходимость трогать деньги-контур вопреки фенсу.
- **Полномочия**: commit→PR→merge разрешён (репо always-merge); любые деньги-смежные изменения
  (регистрация, доступ, тарифы) — только за флагом с дефолтом OFF.
- **Фенс** (не трогать ни при каких условиях): [WebhookController](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Http/Controllers/WebhookController.php)
  и платёжный стек Tochka/PayPal; raw-PII сторы (`storage/app/telegram-harvest/raw`, немаскированные
  диалоги ORS); файлы `.env`; ручные записи в прод-БД.

## Handoffs волны 1

Минтятся пачкой 25-08-2026, все executor OxAlpha (см. [handoffs/README.md](https://github.com/gasyoun/Uprava/blob/main/handoffs/README.md)):
пакет+scaffold (hard), правила-наследие→YAML seed (medium), маскировка+заморозка снапшотов (medium),
офлайн-прогон корпусов (medium), Systema-интеграция seeder+Filament (hard).
Стартер каждого:

```
Read C:\Users\user\Documents\GitHub\Systema-Sanscriticum\docs\PLAN_SYSTEMA_SELF_SERVE_DETERMINISTIC_2026H2.md and execute it.
```

## Не-цели (подтверждены MG)

Рекуррентное списание (#998), ESP-проводка на samskrtam.ru (R11), донаты `INSTITUTE_DONATIONS_LIVE`,
подарочные сертификаты — отдельными планами, здесь не строятся.

_Dr. Mārcis Gasūns_
