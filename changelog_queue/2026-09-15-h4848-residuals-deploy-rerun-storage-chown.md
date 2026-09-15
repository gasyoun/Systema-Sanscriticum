# H4848-остатки 0A63+0A64: обёртка перезапускает deploy.sh в том же цикле; перепись и раздача root-овых файлов в `storage/` (OxAlpha `z-ai/glm-5.3-flash`, 15-09-2026)

Два агентских `@DO`-ряда из верификации H4848, закрытые одним проходом.

## 0A63 — правка `deploy.sh` больше не ждёт следующего прогона

Канарейка H4848 доказала: прогон, *привозящий* правку `deploy.sh`, исполняет
ещё **старую** версию — `bash` держит открытый дескриптор прежнего inode через
`git pull` (окно 05:21:31–05:24:04 UTC, до 716 root-овых вьюх; следующий прогон
с фиксом на диске — ноль). Свойство было задокументировано в
[docs/deploy.md](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/deploy.md)
как «ожидаемое», теперь оно закрыто по построению:

- [`systema-auto-deploy-run.sh`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/scripts/server_guards/sbin/systema-auto-deploy-run.sh)
  после успешного (0/75) и здорового деплоя проверяет, трогал ли диапазон
  `git diff --name-only LOCAL REMOTE -- deploy.sh`; если трогал — **перезапускает
  `deploy.sh` вторым прогоном** в том же цикле (на диске уже новый файл).
- Перед перезапуском — **тест формы `bash -n`**: синтаксически сломанный новый
  скрипт не исполняется (громкий `WARN`, без прогона).
- Провал второго прогона при чистом health → громкий `WARN` в
  `auto_deploy.log`, предохранитель НЕ ставится, отката НЕТ: первый прогон
  только что доказал health'ом, что код жив, а `fail_deploy` гнал бы тот же
  подозрительный скрипт в режиме `--rollback`. Провал с грязным health →
  жёсткий предохранитель и человек.
- Свойство rc=75 (`#1143`) сохранено: оба прогона допускают 0/75, успех в
  любой форме сбрасывает счётчик авто-повторов H2149.
- Хвост обёртки перекомпонован: единая health-гарда после {0,75}, затем
  re-run-блок, затем `OK` / `OK-WITH-GUARDS-DRIFT`, сброс счётчика, `exit 0`.

Тесты: [`test_systema_auto_deploy_run.sh`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/scripts/server_guards/sbin/test_systema_auto_deploy_run.sh)
расширен с 6 до 11 секций (фейковый `git` управляется
`DIFF_TOUCHES_DEPLOY_SH`, фейковый `deploy.sh` считает вызовы и умеет
самопорчу для проверки `bash -n`): 8 — re-run срабатывает (2 вызова), 9 —
провал re-run при чистом health без предохранителя и без отката, 10 — без
касания `deploy.sh` re-run нет, 11 — сломанный новый скрипт блокируется тестом
формы. Секции 1–7 проходят без изменений (поведение по умолчанию не тронуто).

## 0A64 — перепись `find storage -type f ! -user www-data` и раздача по классам

Живой cenзус на проде (15-09-2026): **543** файла не-`www-data` (ряд в GTD
говорил «536» — с тех пор класс `srs/subhashita` прирос 224 файлами orphan-uid).
Разбор и вердикты:

| Класс | Файлов | Состояние | Вердикт |
|---|---|---|---|
| `storage/app/server_guards/` | 3 | `root:www-data` 644/664 — зеркало root-крона H1941 + бейслайны гардов | **оставить** — по конструкции (пишет сам обёртка/apply) |
| `telegram-support/marcisgasuns/session.madeline` | 7 | живой root-воркер (PID 1502338) пишет прямо сейчас | **оставить сейчас** — chown под живого root-писателя бесполезен и ломает IPC; отдельный residual-ряд |
| `storage/framework/auto-deploy.lock` | 1 | lock-файл root-крона, пересоздаётся | **оставить** — механика обёртки |
| `app/public/transcripts/lesson-*.json` | 89 | `root:root 640` от root-прогона импорта 14–16.08 — **www-data их не читает** | **chown** |
| `app/public/srs/anki_454628379/` | 202 | `root:root 640` от импорта 30.07 (jpg/mp3 колоды) — не читаются | **chown** |
| `app/public/srs/subhashita/` (+2 родителя) | 224 | `502:root 644` — uid 502 не существует в `/etc/passwd` (orphan-uid от заливки 14.09); читаются, но не управляемы приложением | **chown** |
| `storage/logs/` | 8 | `laravel.log` (остаток `single`, root-прогон 09.09), `auto_deploy*.log`/`deploys.log` (root-крон `>>`), разовые артефакты | **chown** — root-редиректы продолжают писать в www-data-файлы без ограничений |
| `app/private/survey-invites/*.json` | 5 | root-прогоны artisan 07–08.09 | **chown** |
| `homework-prompts/`, `manuals/`, `app/never-logged-in.csv`, `app/safe_withdrawal_snapshot.php` | 4 | root-разовые выгрузки, `640` не читается приложением | **chown** |

Исполнено: `find storage -type f ! -user www-data ! -path '*server_guards*'
! -path '*marcisgasuns*' ! -path 'storage/framework/auto-deploy.lock' -exec
chown www-data:www-data {} +` → повторный cenзус: **11** файлов (ровно три
leave-класса), probe от www-data зелёный, главная 200, `/admin` 302.

Корень класса — разовые root-прогоны artisan/импортов продолжают минтовать
root-овые файлы (transcripts, anki, survey-invites, `laravel.log`): residual —
выполнять такие прогоны от `www-data` (`runuser -u www-data --`), следующий
cenзус — при очередном окне сопровождения.
