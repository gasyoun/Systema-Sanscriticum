# RUNBOOK — n8n ZOOM 1.4: записи не появились / n8n упал

_Created: 24-08-2026 · Last updated: 24-08-2026_

**Audience:** дежурный агент / оператор. Один документ на два вопроса: «записи нет» и «n8n лежит».
**Хосты:** n8n `root@193.232.229.91` (docker compose `/opt/n8n`, контейнер `n8n-n8n-1`, restart=unless-stopped); Laravel `root@193.232.229.92`.
**Live-воркфлоу:** `ZOOM 1.4 (Final) + АДМИНКА ТЕСТ`, id [`1EIqqNzMl5NNIxST`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/INCIDENT_N8N_ZOOM_RECORDING_JAM_20-08-2026.md). Неактивный близнец `MtN1h7FdF3JTmrse` — не трогать.
**Постмортемы-первоисточники:** [INCIDENT_N8N_ZOOM_RECORDING_JAM_20-08-2026.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/INCIDENT_N8N_ZOOM_RECORDING_JAM_20-08-2026.md) §Resolution · [SERVER_SOFT_ALERT_PLAYBOOK.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/SERVER_SOFT_ALERT_PLAYBOOK.md) строки 18–20-08 и 23-08.

## 0. Что «норма» по времени (и когда детект начинается)

1. Урок закончился → Zoom готовит cloud-запись → вебхук приходит через ~0,5–2 ч.
2. Полный прогон воркфлоу ~2 ч 20 мин (скачать MP4 → YouTube+Rutube → таймкоды DeepSeek → урок в кабинете → пост в TG-группу).
3. Штатное окно появления записи после начала урока ≈ 3–4 ч. **SLA-контроль двухступенчатый**:
   - **погодинный stale-тик** (`:41` каждого часа): сегодняшний слот, начатый ≥ 4 ч назад ([`RECORDING_GAP_STALE_HOURS`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/config/recording_gap.php)), без записи → алерт в админский пульс + отделу заботы **в тот же день** (MG 24-08; kill-switch `RECORDING_GAP_STALE_ENABLED`);
   - **утренний проход 08:00**: полный охват вчерашнего дня (вечерние уроки, чей SLA истекает ночью).

Урок 12:00 без записи к ~16:00–17:00 уже тревожит — ждать утра не требуется.

## 1. Быстрая триаж-лестница (по алерту или вопросу куратора)

Шаг 0 — картина целиком (на .92):

```
cd /var/www/html && php artisan recordings:gap-watch --dry
```

Таблица гэпов + строка последнего exec live-воркфлоу. Дальше по статусу:

| Что видно | Играть |
|---|---|
| «Пробелов записей нет» | Ничего. Алерт был ложным/уже закрыт |
| exec `success`, но записи нет | Play B (вебхук не пришёл / join не сошёлся) |
| exec `error`, упал до скачивания (`ZOOM`, `Switch*`, `Code*`, `Respond*`, `Get row(s) in sheet*`) | **Play A-1 — безопасный ретрай** |
| exec `error` с `402 credits` / `403 Terms Of Service` на `AI Agent1` | Play A-2 (модель OpenRouter) |
| exec `error` после `DOWNLOAD`/`Upload a video`/`ЗАГРУЗКА НА РУТУБ*`/Telegram | Play A-3 — вручную, resume с `AI Agent1` |
| Новых exec после урока вообще нет | Play B или Play C |

## 2. Play A-1 — раннее падение (штатный случай, безопасно автоматизировать)

Падение ДО всякой загрузки ⇒ полный повтор не может задвоить YouTube/Rutube. Ретрай-плечо включено на проде (MG 24-08):

```
cd /var/www/html && php artisan recordings:gap-watch --retry-failed --date=<сегодня YYYY-MM-DD>
```

Окно ретрая по умолчанию — вчера; для сегодняшнего урока передавайте `--date`. Команда сама отбракует небезопасные/уже отретраенные/имеющие успешного потомка и напечатает вердикт по каждому exec; тот же текст уйдёт в TG. Проверка через ~2,5 ч: урок с `youtube_url`/`rutube_url` появился в кабинете, пост в учебном чате есть.

Лимиты/геймы: `RECORDING_GAP_RETRY_FAILED_ENABLED=true` · `RECORDING_GAP_RETRY_MAX_PER_RUN=5` · cache-маркер 30 дней против повторного ретрая того же exec.

## 3. Play A-2 — OpenRouter: кредиты/TOS

Признак в ошибке exec: `402 credits` / `403 Terms Of Service` на ноде `AI Agent1`.

**Прогноз остатка** (ежедневно 09:20 МСК, [`openrouter.php`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/config/openrouter.php)): команда `php artisan openrouter:balance-check --dry` печатает остаток, скользящий расход/день по собственным снапшотам ([`openrouter_balance_snapshots`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/app/Models/OpenrouterBalanceSnapshot.php), базовая линия ≥7 дней), дату исчерпания; за 14 дней до нуля шлёт TG с суммой пополнения на год ×1.25. Kill-switch `OPENROUTER_BALANCE_CHECK_ENABLED`. Требует account-ключ в прод-`.env`: `OPENROUTER_API_KEY=sk-or-v1-…` (dashboard → Keys; именно account-ключ, не provisioned).

1. На .91 открыть воркфлоу `1EIqqNzMl5NNIxST`, нода **OpenRouter Chat Model1**: если модель снова `anthropic/*` — переключить на `deepseek/deepseek-v4-pro` (рецепт 20-08; standalone `таймкоды` уже на нём).
2. Прогон возобновить из UI n8n: открыть упавший exec → **Resume from `AI Agent1`**. НЕ перезапускать с вебхука.

## 4. Play A-3 — позднее падение (что-то уже залито)

Любой exec, чей `runData` трогает `DOWNLOAD`, `Upload a video`, `ЗАГРУЗКА НА РУТУБ*`, Telegram-ноды: полный ретрай = риск дублей YouTube/Rutube. Только руками:

1. UI n8n → Executions → упавший exec → посмотреть последний узел.
2. Resume с `AI Agent1` (или ближайшего невыполненного шага).
3. Если дубль всё же случился — удалить лишний ролик руками на YouTube/Rutube и перепривязать ссылку в Filament.

## 5. Play B — вебхук не пришёл / join не сошёлся

Признак: exec `success` без записи, или exec'ов нет вовсе при живом n8n.

1. Проверить на .91, что контейнер и воркфлоу живы: `docker ps` (n8n-n8n-1 Up), воркфлоу `active=true`.
2. Join-ключ Laravel: `schedules.start` + `course_id` ↔ `lessons.lesson_date` + `course_id`; `lessons.group_id` бывает NULL — это норма.
3. Zoom прислал запись, но вебхук потерян: скачать MP4 по ссылке из облака Zoom и прикрепить урок через Filament («бэклог вставляется в Filament» — путь 18–20-08). Вебхук Zoom повторно НЕ переигрывать.

## 6. Play C — n8n хост (.91)

1. SSH мёртв целиком → **host-down лестница**: только Артём (`@t3t3r1n`); Proxmox-доступа у агента нет, рестарт-лейна нет. Не долбить.
2. SSH жив, контейнер упал: `cd /opt/n8n && docker compose up -d` (restart=unless-stopped обычно сам поднимает; проверить `docker ps` и что воркфлоу `active=true`).
3. После восстановления n8n пропущенные вебхуки сами не доиграют → для каждого пропущенного урока Play B п.3 (Filament).
4. Сеть наружу: egress идёт через privoxy(:8118) → `socks-nl.service` (ssh -D, Restart=always). Проверка: `systemctl status privoxy socks-nl`, тестовый curl к googleapis через прокси. Секундные ямы в этой цепочке — известный класс 23-08; лечится бэкоффом нод (уже стоит 5×60с) и Play A-1.

## 7. Никогда (дубли дороже задержки)

1. Не перезапускать `ZOOM 1.4` с первого узла/вебхука при частично выполненном exec.
2. Не ретраить поздние падения автоматически.
3. Не деактивировать live-воркфлоу ради правок — правки через API/UI точечно, бэкап JSON перед PUT.
4. Не чистить `execution_data`/старые exec руками — они источник verdict'ов ретрай-плеча.

## 8. Мониторинг-фон (работает само)

- 08:00 Europe/Moscow: `recordings:gap-watch` — гэпы → админский пульс + отдел заботы (`RECORDING_GAP_CARE_TELEGRAM_CHAT_ID=-1002079934542`, копия помечена «[Отдел заботы]»). Получатели пульса — `RECORDING_GAP_TELEGRAM_CHAT_ID`; НЕ оставляйте пустым: fallback на список `CABINET_PROBE_TELEGRAM_CHAT_ID` шлёт один алерт во все личные чаты списка (H3557).
- Дедуп — строка в таблице `recording_gap_alerts` (отпечаток набора пробелов, окно 36 ч), переживает `cache:clear` автодеплоев. До H3557 ключ жил в Redis: ~20 деплоев 25-08-2026 сбрасывали его, и hourly `--stale` проходы отправляли тот же алерт заново. Имя группы в строке алерта — кликабельная t.me-ссылка (`App\Support\TelegramGroupLink`).
- Успешная отправка = exit 0; FAILURE остался только для `--dry` и «пробелы есть, но в TG не ушло».
- REST-плечо сторожа читает последние exec через `N8N_API_KEY` (skip-soft при недоступности — таблица всё равно печатается).
- Fallback чтения на .91: `sqlite3 /opt/n8n/storage/database.sqlite "SELECT id,status,startedAt FROM execution_entity WHERE workflowId='1EIqqNzMl5NNIxST' ORDER BY startedAt DESC LIMIT 3;"`

_Dr. Mārcis Gasūns_
