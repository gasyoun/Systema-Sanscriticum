# Волна 2 — `.91` приведена к паритету предохранителей (H3182)

_Created: 25-08-2026 · Last updated: 25-08-2026_

Исполнитель: Claude Code, **Opus 5** (`claude-opus-5`). Спека:
[H3182](https://github.com/gasyoun/Uprava/blob/main/handoffs/H3182-Opus_Systema-Sanscriticum_uptime-w2-n8n-host-guard-parity_19.08.26.md).
Стек плана: [PLAN](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/PLAN_SYSTEMA_SERVER_UPTIME_GUARDRAILS_2026H2.md) ·
[ROADMAP](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/ROADMAP_SYSTEMA_SERVER_UPTIME_GUARDRAILS_2026H2.md) ·
[VERIFICATION](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/docs/VERIFICATION_SYSTEMA_SERVER_UPTIME_GUARDRAILS.md).

## Главный результат: своп сошёл со 100 %

Замер снят до и после удаления `/tmp/hindi_audio` (7.3 ГиБ, 84 файла, не тронут
с 15-08-2026 — то есть за пределом правила старения; D18b прямо называет такие
записи регенерируемыми и разрешёнными к удалению).

| Величина | До | После |
|---|---|---|
| Память занята | 6560 МиБ | **1114 МиБ** |
| Доступно | 1631 МиБ | **7077 МиБ** |
| `shared` (tmpfs) | 5609 МиБ | 195 МиБ |
| **Своп** | **2047/2048 МиБ = 100 %** | **16 МиБ = 0.8 %** |
| `/tmp` занят | 7.5 ГиБ | 196 МиБ |

Это и есть критерий приёмки «swap must come off 100 %». Машина, которая шесть
дней стояла с досуха выбранным свопом, больше в нём не сидит.

## Спайк S3 — потолки выбраны ПО ЗАМЕРУ

Спека называла порядок незыблемым: сначала измерить, потом ставить `mem_limit`.
Мгновенный снимок `docker stats` для этого не годится — он показывает точку, а
нужен потолок. Взяты собственные счётчики ядра (cgroup v2):

| Контейнер | `memory.peak` | окно | `pids.peak` | `oom_kill` | рестартов |
|---|---|---|---|---|---|
| `n8n-n8n-1` | **1278 МиБ** | сутки (поднят 24-08 11:57) | 27 | 0 | 0 |
| `n8n-caddy-1` | **77 МиБ** | четыре недели (поднят 25-07) | 26 | 0 | 0 |

Отсюда `N8N_MEMORY_LIMIT=3G` (≈2.4× суточного пика) и
`CADDY_MEMORY_LIMIT=512M` (≈6.6× четырёхнедельного). Это не тесная посадка:
смысл потолка — не дать одному контейнеру забрать все 8 ГиБ, а не экономить.

**`--memory-swap` равен `--memory` намеренно.** Docker отказывается менять
`--memory` без `--memory-swap` («Memory limit should be smaller than already set
memoryswap limit» — получено на живой машине). Равенство означает «контейнеру
своп не положен»: разогнавшийся процесс умрёт внутри своей cgroup, а не утащит
машину в свопинг. Именно свопинг, а не нехватка памяти как таковая, дал класс
аварий 24-07 и 28-07 на `.92`.

## Приёмка по строкам VERIFICATION §1 Wave 2

| Строка | Итог | Доказательство |
|---|---|---|
| `earlyoom` на `.91` | ✅ | юнит активен, `/etc/default/earlyoom` из манифеста, список `--avoid` свой (dockerd/containerd/sshd/caddy/node), `--prefer` — ASR-класс (python/ffmpeg/whisper) |
| Дрилл `earlyoom` | ⏸ **человек** | требует присутствия (D13) — см. «Что осталось» |
| `/tmp` cap `.91` | ⚠️ **невозможен изнутри** | `findmnt` → `uid=100000` (idmap LXC), `df` → 126 ГиБ = половина памяти ХОСТА. Строка спеки «`size=2G`» невыполнима из гостя; сторона Proxmox, P5. `hindi_audio` удалён, своп сошёл — вторая половина строки выполнена |
| Потолки контейнеров | ✅ | `n8n` memory=3G pids=256, `caddy` memory=512M pids=128, применены `docker update` **без пересоздания** |
| Healthchecks | ⏸ | `health=none`; docker не умеет менять healthcheck у живого контейнера. Подготовлен в `docker-compose.override.yml`, вступит в силу при следующем законном пересоздании |
| Дрилл healthcheck | ⏸ **человек** | требует пересоздания + присутствия |
| Caddy закреплён | ⏸ | пин `caddy:2.11.4@sha256:af5fdcd7…` снят с живого образа и записан в override; на работающем контейнере вступит в силу при пересоздании |
| Потолки логов | ✅ | `/etc/docker/daemon.json` (его не было вовсе): `max-size=50m`, `max-file=5`, `live-restore=true` |
| Резервная копия n8n | ⚠️ **локально да, off-site нет** | архив 31 МиБ, возраст 0 ч, таймер `n8n-backup.timer` активен. Off-site не подключён — единственный незакрытый critical, см. ниже |
| Дрилл восстановления | ✅ | `n8n-restore-drill.sh`: архив распакован, `integrity_check=ok`, `workflow_entity` 79=79, активных 8=8, `credentials_entity` 54=54 |
| `verify` для `.91` | ✅ | `server_guards_n8n_verify.sh`, 37 проверок; **читается с `.92`** — см. ниже |

## Как `.92` узнаёт, что `.91` под предохранителями (W2d)

Прямой SSH между машинами по публичным адресам закрыт в обе стороны, а
существующий ключ `restic-push` намеренно ограничен (`restrict,
command="internal-sftp"`) — команду по нему выполнить нельзя. Заводить второе
ребро доверия между двумя продовыми машинами ради проверки — плохой размен,
поэтому `.91` сама публикует статус:

```
# на .91, ежечасно (n8n-guards-verify.timer)
server_guards_n8n_verify.sh --publish   # -> /srv/restic/status/n8n-guards.json

# с .92, УЖЕ выданным ключом, нового доверия не требуется
sftp -i /root/.ssh/id_restic_push restic-push@192.168.200.91:/status/n8n-guards.json /tmp/
```

`/srv/restic` — в точности `ChrootDirectory` пользователя `restic-push`, поэтому
файл виден как `/status/n8n-guards.json`. Проверено живьём 25-08-2026: `.92`
получает JSON со `status`, счётчиками и списком находок. Свежесть самого файла —
часть проверки: протухший статус означает, что проверка перестала ходить.

## Ошибка, которую поймал собственный предохранитель

Первый прогон бэкапа **упал**, и это лучшее, что он мог сделать. Порог
правдоподобия стоял `100 МиБ`, выведенный из размера живой базы (166 МиБ), — но
проверка сравнивала его со **сжатым** архивом (31 МиБ). Порог из несжатого
размера против сжатого файла — сравнение бессмысленное, и оно упало громко,
а не пропустило молча. Исправлено на 15 МиБ (≈половина замеренных 31).
Настоящая защита от «архива ни о чём» — не размер, а проверка содержимого:
`integrity_check` плюс сверка `workflow_entity` с живой базой.

## Что построено

Профиль, а не форк. `scripts/server_guards_apply.sh` получил `--profile
app|n8n`: разделы, осмысленные только для `.92` (crontab прикладного
пользователя, авто-деплой, инвариант memory-cap, пул php-fpm, supervisor,
демон MadelineProto), под профилем `n8n` пропускаются, и симметрично наоборот.
Регрессия проверена на `.92`: `--dry-run` даёт `0 файлов разошлись`, все
app-разделы отработали как прежде.

- [`scripts/server_guards_n8n.conf`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/scripts/server_guards_n8n.conf) — единственное место для чисел `.91`
- [`scripts/server_guards_n8n/manifest.psv`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/scripts/server_guards_n8n/manifest.psv) — 17 управляемых файлов, один список на applier и verify
- [`scripts/server_guards_n8n_verify.sh`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/scripts/server_guards_n8n_verify.sh) — громкая проверка + `--publish`
- `sbin/`: `n8n-backup-run.sh`, `n8n-restore-drill.sh`, `n8n-container-limits.sh`, `memwatch-n8n.sh`
- `systemd/`: `n8n-backup.{service,timer}`, `n8n-guards-verify.{service,timer}`, `n8n-container-limits.service`, `memwatch.{service,timer}`
- `docker/`: `daemon.json`, `docker-compose.override.yml`

Выкладка на `.91` — `/opt/systema-guards` (клона репозитория на машине нет и не
заводился; H3177/H3178 выкладывались так же).

## Что осталось — и почему это не агентская работа

1. **Off-site назначение бэкапа (critical).** Замерено, а не предположено:
   машины говорят по частной сети (`192.168.200.91` ↔ `192.168.200.92`), путь
   `.91 → .92` на уровне сети РАБОТАЕТ (`Permission denied (publickey)`, то есть
   не хватает только авторизованного ключа). Оба маршрута упираются в секрет,
   который агенту трогать запрещено (PLAN §4). **Важно:** `.91` и `.92` — гости
   ОДНОГО хоста Proxmox, поэтому копия на `.92` переживёт потерю контейнера, но
   не потерю железа; предпочтителен S3 (H3175, ключ PutObject-only).
   Что сделать человеку — пошагово в секции off-site
   [`server_guards_n8n.conf`](https://github.com/gasyoun/Systema-Sanscriticum/blob/main/scripts/server_guards_n8n.conf).
2. **Два дрилла D13** (`earlyoom` и healthcheck) — требуют присутствия человека.
3. **Пересоздание контейнеров** — вводит healthcheck и пин Caddy; законный шаг
   человека, ограда волны запрещает агенту `docker compose up`.
4. **Потолок `/tmp`** — сторона хоста Proxmox (P5, Артём), та же стена, что на `.92`.

_Dr. Mārcis Gasūns_
