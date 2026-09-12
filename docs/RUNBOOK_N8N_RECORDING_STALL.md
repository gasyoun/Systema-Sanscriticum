# RUNBOOK — n8n ZOOM 1.4: записи не появились / n8n упал

_Created: 24-08-2026 · Last updated: 12-09-2026_

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
| exec `success`, но записи нет | С H3952 такой прогон почти всегда означает Play B. Сбой per-account fresh-link теперь роняет прогон в `error` с маркером `H3952_*` — см. §3.1 |
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

## 3.1 Двухаккаунтный Zoom: per-account fresh-link и его вердикты (класс 29-08-2026, закрыт 02-09-2026)

Встречи приходят с ДВУХ Zoom-аккаунтов: Account_1 `K4GUTP_JTCqAflA6QsKq6A` (Кочергина 434/435) и Account_2 `ZEyCQs4nTWScjXNaTy6K5g` (Летний интенсив 438, Грамматика 402/351, Продленка 436…). Реестр аккаунтов живёт в ноде `Code in JavaScript1` (`ZOOM_ACCOUNTS`), свитч «Аккаунт fresh-link» разводит прогон на «Свежая ссылка (Цыди)» (`47UA7kp1sAv9NCe3`) и «Свежая ссылка (Гасунс)» (`Zoom ОРС`, `XJbFogXztImsSVaX`); незнакомый account_id идёт в «Неизвестный аккаунт (pass-through)» и получает громкий TG-алерт.

**Скоуп cred доказан замером 02-09-2026** (`GET /v2/meetings/{id}/recordings` каждым cred по очереди):

| meeting | Zoom Цыди (Account_1) | Zoom ОРС (Account_2) |
|---|---|---|
| 82540974104 | **200, 3 файла** | 404 `3301` |
| 85859602899 | 404 `3301` | **200, 2 файла** |
| 89072673024 | **200, 3 файла** | 404 `3301` |
| 87947840623 | 404 `3301` | 404 `3301` (запись уже удалена) |

То есть встречу видно РОВНО с её аккаунта — один общий cred структурно не мог обслужить оба, и это ровно тот отказ, который 29-08 выглядел как «вебхук не пришёл».

### Что шипнуто (H3687 29-08 + H3952 02-09)

- **H3687** — сам per-account fresh-link: реестр, два свитча, пары нод «Свежая ссылка (…)» / «DOWNLOAD свежая (…)», TG-алерт на незнакомый аккаунт.
- **H3952** — вердикты и ассерты (бэкап `/root/wf_backup_pre_h3952_02-09-2026.json`). `neverError:true` на обеих нодах «Свежая ссылка (…)» **оставлен сознательно**: 3301 не должен ронять прогон, который ещё может доехать по подписанному ≤24 ч URL. Вместо снятия флага за ним стоят три вещи:
  1. **«Диагноз fresh-link»** — классифицирует исход fresh-link и пропускает payload дальше без изменений; при деградации шлёт `⚠️ Fresh-link деградировал` в ops-пульс. Раньше сбой fresh-link не оставлял вообще никакого следа: прогон зелёный, запись приезжает по подписанному URL, сломанный cred всплывает через несколько дней на replay.
  2. **Ассерт «Стоп: запись не получена»** на false-ветке гарда «Есть запись?» и на error-выходе «DOWNLOAD свежая (…)»: HEAD по вебхук-токену → «Вердикт fresh-link» → TG → `throw`. Ветка гарда раньше была тупиком (exec `success`, урок не создан).
  3. **Ассерт «Стоп: replay невозможен»** после «TG: replay недоступен» — этот узел был терминальным и завершал прогон `success` при недоставленной записи.

### Вердикты и что по ним делать

Маркер лежит в тексте ошибки упавшего exec; `recordings:gap-watch` печатает его строкой `↳ вердикт:` плюс строку `↳ вебхук-токен:`.

| Маркер | Что это | Действие |
|---|---|---|
| `H3952_CREDENTIAL_FETCH_FAILURE` | Zoom отказал по авторизации (124/401/403/1001), **или** 3301 при ЖИВОМ вебхук-токене — запись есть в облаке, её не видит cred | чинить OAuth-cred аккаунта; запись достать вручную (Play B) |
| `H3952_WEBHOOK_MISSING` | fresh-link без ошибки и без файлов, токен мёртв/отсутствует | класс «записи нет / не готова» — это НЕ сбой cred |
| `H3952_UNDECIDABLE_3301` | 3301 без живого токена | по логам «чужой аккаунт» и «записи не было» **неразличимы** (3301 у Zoom двусмысленен) — смотреть облако Zoom этого аккаунта глазами. Помечено провалом намеренно: ложная тревога дешевле тихого успеха |
| `H3952_ACCOUNT_UNREGISTERED` | account_id вне реестра | завести cred + строку в `ZOOM_ACCOUNTS` + пару нод + правила в двух свитчах |
| `H3952_REPLAY_IMPOSSIBLE` | подписанная ссылка истекла, аккаунт вне реестра | свежую ссылку взять неоткуда — доставлять вручную |

**Живой вебхук-токен — главное доказательство.** HEAD по нему 2xx/3xx = запись в облаке жива, значит виноват cred, а не пропавший вебхук. Это делает и воркфлоу (нода «HEAD вебхук-токен»), и `gap-watch` (`N8nZoomExecutionProbe::webhookTokenState`).

**Проверено на живом проде 02-09-2026:** exec **2314** (Account_2 → «Свежая ссылка (Гасунс)») и exec **2315** (Account_1 → «Свежая ссылка (Цыди)») — оба `error`, оба с `H3952_UNDECIDABLE_3301`, оба с двумя TG-алертами; ни YouTube, ни Rutube, ни создание урока не выполнялись. До H3952 такой прогон завершался `success`. Здоровая половина — штатные прод-прогоны обоих аккаунтов: 2222/2140 (Цыди) и 2156/2152/2027 (Гасунс), все `success`.

**Остаточное ограничение:** replay **позже 24 ч** возможен только пока fresh-link аккаунта отвечает; если запись уже удалена из облака (как 87947840623), её не вернуть ничем — Play B руками.

**Ещё три грабли того же дня:**

1. `POST /executions/{id}/retry` играет **СТАРЫЙ снапшот воркфлоу** (exec 1920=retryOf 1826 прошёл по до-фиксовому графу с обходным ребром в DOWNLOAD). После любой правки воркфлоу ретрай старых exec бесполезен — свежий прогон только через повтор вебхука: извлечь `body` из runData ZOOM-узла упавшего exec (без BOM!), затем с .91:
   `curl -X POST http://127.0.0.1:5678/webhook/86446208-6c7a-432f-bcaa-f4d9536b3f55 -H 'Host: context-ai.ru' -H 'Content-Type: application/json' --data-binary @body.json` (HTTP 200 «OK»; дубли возможны ТОЛЬКО если exec успел залить YT/Rutube — сверить runData).
2. Public API `GET /executions` **отстаёт от sqlite**: свежие running/waiting exec могут отсутствовать в выдаче (1926–1930 пропали), строка «last exec» в алерте gap-watch может быть устаревшей. Истина — sqlite-фолбэк из §8.
3. PowerShell: `[System.IO.File]::WriteAllText(..., [System.Text.Encoding]::UTF8)` пишет **BOM** → n8n отвечает `422 Unexpected token '\ufeff'`. Писать через `UTF8Encoding($false)`.

## 3.2 Merge dead-end: обложка не найдена → exec зелёный, хвост пропущен (класс 10-09-2026)

«Обложка найдена?» ищет `name = '<дата старта>.jpg'` в папке курса (drive_folder_id из листа). Пусто → «Обложки нет — пропуск» → Merge (mode=combine; вход 0 приходит только с ветки обложки: `Add a playlist item (Hindi)` → `ЗАГРУЗКА НА РУТУБ`) → Merge не срабатывает → n8n завершает exec SUCCESS, молча пропустив `ЗАГРУЗКА НА РУТУБ1`/плейлист/Rutube2/субтитры/DeepSeek/`СОЗДАЁМ УРОК В АДМИНКЕ1`/финальный TG. Симптом: exec `success` за минуты, в runData < ~30 нод, последний узел Merge; при этом YouTube уже залит — полный реплей вебхука = дубль (`Upload a video (Hindi)` без дедупа). Прецеденты полного пути: exec 2723 (64 ноды), 2356 (59 нод); мёртвый пример — 2754 (28 нод). Разбор: [INCIDENT_N8N_ENOSPC_HINDI_COVER_MERGE_DEADEND_10-09-2026.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/INCIDENT_N8N_ENOSPC_HINDI_COVER_MERGE_DEADEND_10-09-2026.md). Ремонт графа — H4513; до него — только ручная доставка (Play B).

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

### 6.1 Диск .91 полон (ENOSPC) — класс 10-09-2026

Симптом: exec падает с `ENOSPC: no space left on device` на DOWNLOAD/HEAD-нодах; вердикт при этом может быть ложным `H3952_WEBHOOK_MISSING` (HEAD-ошибка = нет statusCode = «токен мёртв» в VERDICT_JS; честный `H3952_INFRASTRUCTURE_FAILURE` — H4513). Разбор: [INCIDENT_N8N_ENOSPC_HINDI_COVER_MERGE_DEADEND_10-09-2026.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/INCIDENT_N8N_ENOSPC_HINDI_COVER_MERGE_DEADEND_10-09-2026.md).

1. `df -h /` на .91 — корень 49G; `du -xh -d1 / | sort -rh | head`. Заполнители: `/srv/restic/systema` (репо-хаб бэкап-эстейта, **НЕ чистить руками** — retention ведёт `restic-forget --prune` с .92 daily 05:00 UTC), `/srv/restore-tmp` (остатки restore-тестов — удалять если старше пары дней и есть `.done`), `/var/log/journal` (лимитировать `journalctl --vacuum-size=200M`).
2. При 0 свободных n8n не пишет ни binary, ни sqlite — любой реплей повторит ENOSPC. Сначала место, потом реплей.
3. Быстрое освобождение без решения MG (безопасный набор): `rm -rf /srv/restore-tmp`, `journalctl --vacuum-size=200M`. Рестик-репо, `/var/backups`, docker-слои — только через MG/@DECIDE.
4. `docker system df` и `docker ps` — контейнеры не рестартовать (danger-fact .91), только смотреть.
5. После чистки — реплей вебхука из упавшего exec по §3.1 п.1; помни: в Hindi-ветке при не найденной обложке см. §3.2 (Merge dead-end).

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


## 9. Хирургический реплей хвоста + `n8n execute` в контейнере (класс 12-09-2026)

Когда полный ретрай запрещён (§7), а после упавшей ноды остался длинный хвост — соберите repair-воркфлоу и гоняйте через CLI. Проверено на exec 2880 (12-09): хвост выполнен целиком, урок 1951 создан, TG доставлен, ~46 мин общий прогон.

**`n8n execute` в контейнере:**
- Падает сразу: «n8n Task Broker's port 5679 is already in use» — CLI-инстанс поднимает свой task-broker на порту главного процесса.
- Лечение: `docker exec -e N8N_RUNNERS_BROKER_PORT=5699 n8n-n8n-1 n8n execute --id <id>` (переменная из `@n8n/config`, `runners.config.js`). JS-runner регистрируется, исполнение пишется в общий sqlite; Wait-ноды резюмит главный инстанс по `waitTill` — CLI может завершиться, докатит главный процесс.

**Сборка repair-воркфлоу:**
1. Из runData упавшего exec (sqlite `execution_data`; zlib + флэттен-формат: строковые цифры = ссылки в контейнер массива, разыменование ОДНОКРАТНОЕ — повторное разыменование литералов-цифр даёт IndexError) берутся успешные выходы апстримных нод.
2. Граф: `manualTrigger` → Code-stub-ноды (имена = имена апстримных нод, каждая отдаёт записанные items — выражения `$('X')` хвоста резолвятся по именам) → настоящие хвостовые ноды verbatim (креды резолвятся на том же инстансе).
3. На упавшей LLM/AI-ноде выставить `retryOnFail: true, maxTries: 2-3, waitBetweenTries: 60000` — таймаут-класс (§3) лечится ретраем.
4. Кормилец AI-ноды (нода, чей выход содержит промпт-поля, напр. `transcript`) — ПОСЛЕДНИЙ stub в цепочке.
5. Импорт: `docker exec n8n-n8n-1 n8n import:workflow --input=/data/repair.json` — в JSON нужен явный `"id"`, иначе NOT NULL constraint failed.
6. Финал: удалить repair-воркфлоу (`DELETE /api/v1/workflows/{id}`; ключу `oxalpha-ops` не хватает `workflow:delete` — 403, тогда оставить inactive, имя с префиксом `DONE-DELETE-ME …`).

_Заметка 12-09: сабваркфлоу собирался и исполнялся агентом (OxAlpha) — playbook воспроизводим скриптом (builder в логах сессии, /root/repair2880/ на .91)._

_Dr. Mārcis Gasūns_
