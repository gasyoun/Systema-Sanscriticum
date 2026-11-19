# RUNBOOK — восстановление MadelineProto-сессии юзербота

_Created: 05-10-2026 · Last updated: 05-10-2026_

**Аудитория:** ops/агенты. **Симптом-класс:** harvest/support/roster лейны .92 падают на
MTProto-вызовах; «session busy»; `AUTH_RESTART`; flood-wait; peer-db ошибки.
**Источники:** [deploy.md §lock-дисциплина](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/deploy.md),
[telegram-userbot-inventory §Cache::lock/§4.2](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/telegram-userbot-inventory.md),
[SERVER_SOFT_ALERT_PLAYBOOK строки 15-09](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/SERVER_SOFT_ALERT_PLAYBOOK.md),
H4879 (getPwrChat flood fix), H4461 (roster watchdog), H5818 (login-процедура).

## Жёсткие инварианты (читать до любых действий)

1. **Одна сессия, ноль вторых демонов.** Все живые потребители (`telegram-support:sync`
   everyMinute, `telegram-harvest:sync`, roster-джобы) сериализуются
   `Cache::lock('madeline-session', 900)`. Второй самостоятельный MadelineProto-демон
   на той же сессии НЕ держим — риск `AUTH_RESTART` (deploy.md).
2. **Нельзя «подождать 15 минут» насильно:** TTL замка 900 с — застрявший держатель
   освобождает замок сам; сбивать замок руками = гонка двух инициализаций сессии.

## Симптом → диагноз → шаги

| Симптом | Диагноз | Шаги |
|---|---|---|
| `telegram-harvest:peers` → `session_busy (another MadelineProto command holds the session)` | штатная сериализация: замок держит support:sync/daemon или зависший sync | 1) `pgrep -af 'telegram' ` на .92 — кто держит; 2) если держатель жив (daemon/support:sync) — просто повторить через 2–5 мин; 3) если процесс-держатель мёртв, а замок висит — ждать TTL 900 с, НЕ ломать |
| `FLOOD_WAIT_X` в логах Telegram-вызовов | Telegram-рейтлимит | Пауза ≥ X секунд на этом peer'е; массовые догоняющие проходы разносить по времени; не рестартить сессию — она не виновата |
| `getPwrChat failed: This peer is not present in the internal peer database` (пачка WARNING) | известный доброкачественный класс с 15-09 (H4879): мёртвый/недоступный peer в ростере | Ничего. Классифицируется per-peer в `pwrRoster`, детали в канале `telegram_harvest`, по одной summary-строке на прогон. Тревога только если **синк перестал писать** raw (см. след. строку) |
| raw-стор не растёт: `storage/app/telegram-harvest/raw/corpus/**` без новых файлов при живом кроне | sync падает до записи (сессия/сеть/watchdog) | 1) последний лог `telegram_harvest` канала; 2) прогон вручную `php artisan telegram-harvest:sync --json` (увидит конкретную ошибку); 3) roster-джобы имеют watchdog `roster_timeout_seconds` и пишут ростеры по мере снятия (H4461) — обрыв прохода не обнуляет сделанное |
| `AUTH_RESTART` / сессия реально умерла (unauthorized) | сессионный файл невалиден | Интерактивный релогин: `php artisan telegram-support:login --account=<аккаунт>` (телефон+код+2FA — код читает человек, агенту код не передавать в чат; процедура H5818). После логина строка поднимается сама; harvest подхватит ту же сессию |

## Проверка после восстановления

```bash
php artisan telegram-harvest:sync --peer=@samskrte --json
# ожидание: {"status":"ok","harvested":N,"stored":N,...,"failed":0}
php artisan telegram-support:daemon --status   # если поднят
tail -5 storage/logs/laravel-$(date +%F).log | grep -i 'flood\|peer database'
```

## Эскалация

Повторный `AUTH_RESTART` за сутки, невозможность логина (код не приходит), или сессия
падает быстрее чем раз в неделю — MG (решение о перерегистрации аккаунта юзербота).

_Гасунс_
