# RUNBOOK — канонический рецепт включения/выключения флага

_Created: 05-10-2026 · Last updated: 05-10-2026_

**Аудитория:** ops/агенты. **Когда:** любой продуктовый флаг `.env` (примеры:
`TELEGRAM_BUSINESS_BOT_ENABLED`, `TELEGRAM_CABINET_LOGIN`, `promise_suggestion_detection_enabled`).
**Почему отдельным ранбуком:** один и тот же рецепт собирался заново в каждом проходе
(Business-enable 17-09, interest-form H5066, webchat H5450, hints-digest H5452); гочи
«Horizon до config:cache» и «CSRF 419 на curl-смоуках» нигде не канонизированы.
**Источники:** [RUNBOOK_TELEGRAM_BUSINESS_ENABLE](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/RUNBOOK_TELEGRAM_BUSINESS_ENABLE_2026-09-17.md),
[docs/ENVIRONMENT_VARIABLES.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/ENVIRONMENT_VARIABLES.md).

## Рецепт (прод samskrte.ru = .92, `/var/www/html`)

```bash
cd /var/www/html

# 1. Флаг в .env (только целевая строка; .env правит человек/владелец или по визе MG)
#    ФЛАГ=true  (включение) / ФЛАГ=false (выключение/откат)

# 2. Перекэшировать конфиг
php artisan config:cache

# 3. Перезагрузить php-fpm (nginx отдаёт через fpm-pool)
systemctl reload php*-fpm*

# 4. ПЕРЕЗАПУСТИТЬ Horizon-воркеры — ПОСЛЕ config:cache, не до!
php artisan horizon:terminate
```

## Два гочи (каждый уже ловили)

1. **Horizon-воркеры, стартовавшие ДО `config:cache`, молча игнорят флаг** — они держат
   старый закэшированный конфиг в памяти. Поэтому `horizon:terminate` строго ПОСЛЕ
   шага 2; воркеры поднимутся супервизором уже с новым конфигом. Порядок «наоборот»
   выглядит рабочим на смоук-HTTP (fpm уже перечитал) и ломает именно очередь.
2. **CSRF 419 на curl-смоуках** — POST-маршруты Laravel без CSRF-токена честно отвечают
   419. Это не признак сломанного флага. Смоук делать по GET-поверхностям
   (страница/эндпоинт флага) или на уровне `php artisan tinker` (дёрнуть сервис
   напрямую), а не curl-POST.

## Смоук после включения

```bash
# GET-поверхность за флагом отвечает 200 (не 404 = флаг не подхвачен):
curl -s -o /dev/null -w '%{http_code}\n' https://samskrte.ru/<поверхность-флага>

# очередь жива после horizon:terminate:
php artisan horizon:status
```

## Откат

Те же шаги 1–4 с `ФЛАГ=false`; смоук — поверхность отвечает 404/деб, очередь жива.

## Реестр флагов

Канонический список — [docs/ENVIRONMENT_VARIABLES.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/ENVIRONMENT_VARIABLES.md);
после нового флага — дописать туда же (PR-ом, не руками на проде).

_Гасунс_
