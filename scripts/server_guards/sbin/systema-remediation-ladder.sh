#!/bin/bash
# systema-remediation-ladder.sh — лестница восстановления (W3c, D8/D9).
#
# MANAGED FILE — ставится scripts/server_guards_apply.sh из
# scripts/server_guards/sbin/systema-remediation-ladder.sh. ОДИН файл на ОБЕ
# машины: .92 берёт его своим манифестом, .91 — строкой ../server_guards/... из
# своего. Разница между машинами вся сидит в значениях (LADDER_TARGETS: на .92
# это systemd-юниты, на .91 — docker-контейнеры), а не в двух копиях логики.
# Править копию в репозитории, не на сервере.
#
# ЗАЧЕМ. Сторож, который умеет только кричать, превращает падение в зависание:
# ночью кричать некому. Лестница даёт машине право на несколько ЗАРАНЕЕ
# ОГОВОРЁННЫХ действий и — это важнее — жёсткие пределы этого права.
#
# КОНТРАКТ (docs/ARCHITECTURE_SYSTEMA_SERVER_UPTIME_GUARDRAILS.md §3.3).
# Четыре ступени, каждая требует ПРОВАЛА предыдущей, каждая пишет в журнал
# ДО того, как действует:
#   R1  записать (всегда)
#   R2  перезапустить юнит/контейнер — только те, что лежат; смок за 60 с
#   R3  вычистить состаренный мусор в /tmp — только по правилу возраста
#   R4  перезапуск LXC через Proxmox API — ИНЕРТЕН, пока нет PROXMOX_API_TOKEN
#
# ПОЧЕМУ R4 ИНЕРТЕН, И ЭТО НЕ НЕДОДЕЛКА. Хост Proxmox — не наш (D4, задача
# Артёма), и грант на API запрошен отдельным пунктом плана (P1). Дорожка
# написана и проверена на заглушке, но выключена: включение — одна строка в
# env-файле, не правка кода. Агент НЕ ИМЕЕТ ПРАВА обойти отсутствующий токен,
# придумав себе доступ к хосту другим путём: это нарушение ограды D4 и ровно
# то, ради чего существует P1.
#
# СДЕРЖАННОСТЬ — ЭТО И ЕСТЬ СУТЬ (H2149, тот же урок этажом ниже).
# Перезапускать по кругу по-настоящему сломанный сервис — худший из исходов:
# настоящая поломка начинает выглядеть «морганием» и прячется.
#   • счётчик попыток лежит в СВОЁМ файле — то, что чистят (/tmp), не может
#     быть одновременно и счётчиком, иначе R3 обнуляет память о самом себе;
#   • обнуляет счётчик РОВНО ОДНО событие — чистый успех;
#   • когда счётчик исчерпан, лестница ГРОМКО говорит «сдаюсь» и не действует.
#     Молча — никогда.
#
# ПОЧЕМУ ТАЙМЕР, А НЕ КРОН. Сторож не должен разделять судьбу того, что
# сторожит. 18-08-2026 cron.service упёрся в общий потолок 2 ГиБ и задушил ВСЕ
# кроновые задачи разом (H3121, строка №10 журнала инцидентов: MTTD 7 ч 33 мин,
# guard = none). Лестница, живущая в том же cgroup, была бы задушена ровно той
# аварией, которую обязана чинить. Свой таймер = свой cgroup = своя судьба.
#
# Использование (от root):
#   systema-remediation-ladder.sh                 # рабочий заход (из таймера)
#   systema-remediation-ladder.sh --dry-run       # ничего не делать, только сказать
#   systema-remediation-ladder.sh --stub-unhealthy  # считать смок красным (для проверки)
#   systema-remediation-ladder.sh --status        # показать счётчик и выйти
set -uo pipefail

TARGETS="@@LADDER_TARGETS@@"
SMOKE_URL="@@LADDER_SMOKE_URL@@"
SMOKE_EXPECT="@@LADDER_SMOKE_EXPECT@@"
SMOKE_WAIT="@@LADDER_SMOKE_WAIT_SECONDS@@"
MAX_ATTEMPTS="@@LADDER_MAX_ATTEMPTS@@"
TMP_AGE_DAYS="@@LADDER_TMP_AGE_DAYS@@"
CT_ID="@@LADDER_CT_ID@@"
BOX="@@LADDER_BOX_NAME@@"

# ── Пути. Переопределяются ТОЛЬКО в самопроверке ─────────────────────────────
# test_systema_remediation_ladder.sh обязан прогонять НАСТОЯЩИЙ скрипт, а не
# свою копию логики, иначе проверка проверяет не то, что поедет на прод. Но
# писать в /var/log и удалять в /tmp он не должен. Отсюда переопределения,
# запертые за SYSTEMA_LADDER_SELFTEST=1: в рабочем заходе переменная не задана,
# и пути остаются жёстко прибитыми. Открытая настройка TMP_ROOT была бы дырой
# в ограде «ничего вне /tmp» — а ограда тут важнее удобства.
SELFTEST="${SYSTEMA_LADDER_SELFTEST:-0}"
if [ "$SELFTEST" = 1 ]; then
  STATE_DIR="${SYSTEMA_LADDER_STATE_DIR:-/var/lib/systema-guards}"
  LOG="${SYSTEMA_LADDER_LOG:-/var/log/systema-remediation-ladder.log}"
  LEDGER="${SYSTEMA_LADDER_LEDGER:-/var/log/systema-remediation-ledger.ndjson}"
  ALERT_BIN="${SYSTEMA_LADDER_ALERT_BIN:-/usr/local/sbin/systema-backup/backup_alert.py}"
  TMP_ROOT="${SYSTEMA_LADDER_TMP_ROOT:-/tmp}"
else
  STATE_DIR=/var/lib/systema-guards
  LOG=/var/log/systema-remediation-ladder.log
  LEDGER=/var/log/systema-remediation-ledger.ndjson
  ALERT_BIN=/usr/local/sbin/systema-backup/backup_alert.py
  TMP_ROOT=/tmp
fi
# Счётчик — В СВОЁМ файле и СОЗНАТЕЛЬНО не в /tmp: R3 чистит /tmp, и счётчик,
# лежащий там, стирал бы сам себя ровно в тот момент, когда важнее всего помнить.
COUNTER="$STATE_DIR/remediation-attempts"
LAST_OK="$STATE_DIR/remediation-last-ok"

DRY_RUN=0
STUB_UNHEALTHY=0
while [ $# -gt 0 ]; do
  case "$1" in
    --dry-run|-n) DRY_RUN=1 ;;
    --stub-unhealthy) STUB_UNHEALTHY=1 ;;
    --status) STATUS_ONLY=1 ;;
    -h|--help) sed -n '2,60p' "${BASH_SOURCE[0]}"; exit 0 ;;
    *) echo "Неизвестный аргумент: $1" >&2; exit 2 ;;
  esac
  shift
done
STATUS_ONLY="${STATUS_ONLY:-0}"

mkdir -p "$STATE_DIR" 2>/dev/null || true

TS() { date -u '+%Y-%m-%dT%H:%M:%SZ'; }
log() { printf '%s %s\n' "$(TS)" "$*" >> "$LOG"; printf '%s %s\n' "$(TS)" "$*"; }

# Каждое действие проходит через act(): сначала строка в журнал, потом само
# действие. Обратный порядок означал бы, что о действии, убившем машину, узнать
# уже неоткуда — а именно такие и приходится разбирать.
act() { # act <человеческое описание> <команда...>
  local what="$1"; shift
  if [ "$DRY_RUN" = 1 ]; then
    log "DRY-RUN would: $what"
    return 0
  fi
  log "ACT: $what"
  "$@"
}

ledger() { # ledger <rung> <verdict> <note>
  printf '{"ts":"%s","box":"%s","rung":"%s","verdict":"%s","attempt":%s,"note":"%s"}\n' \
    "$(TS)" "$BOX" "$1" "$2" "$(attempts)" "${3//\"/\'}" >> "$LEDGER" 2>/dev/null || true
}

escalate() { # escalate <LEVEL> <subject> <body>
  log "ESCALATE [$1] $2 — $3"
  # Репетиция не будит людей. Без этой оговорки прогон --dry-run (в том числе
  # штатная репетиция учений D13) слал бы в Telegram НАСТОЯЩУЮ тревогу об
  # аварии, которой нет; несколько таких — и на сообщения лестницы перестанут
  # смотреть. Тревога, которой не верят, хуже отсутствующей.
  if [ "$DRY_RUN" = 1 ]; then
    log "DRY-RUN would escalate to humans (сообщение НЕ отправлено)"
    return 0
  fi
  if [ -x "$ALERT_BIN" ]; then
    # Доставка — best-effort и НИКОГДА не роняет лестницу: durable-запись это
    # строка в логе выше, Telegram лишь удобство. Тот же контракт, что у
    # backup_alert.py, поэтому он и переиспользован, а не написан заново.
    "$ALERT_BIN" "$1" "remediation-ladder" "$2" "$3" >/dev/null 2>&1 || true
  else
    log "ESCALATE SINK MISSING: $ALERT_BIN отсутствует — сообщение осталось только в логе"
  fi
}

attempts() { cat "$COUNTER" 2>/dev/null | tr -dc '0-9' | head -c 4 || true; }
attempts_n() { local a; a=$(attempts); printf '%s' "${a:-0}"; }
bump_attempts() { printf '%s' "$(( $(attempts_n) + 1 ))" > "$COUNTER"; }
# ЕДИНСТВЕННОЕ событие, обнуляющее счётчик. Не таймер, не сутки, не перезагрузка:
# только доказанный чистый успех. Иначе «полежало-починилось-полежало» никогда
# не дойдёт до потолка и лестница будет вечно дёргать сломанное.
reset_attempts() { printf '0' > "$COUNTER"; TS > "$LAST_OK"; }

smoke() { # 0 = здоров
  [ "$STUB_UNHEALTHY" = 1 ] && return 1
  local code out
  # Та же ловушка, что в systema-peer-probe.sh: `curl … || printf '000'` при
  # недоступном хосте склеивает вывод -w и запасное значение в «000000».
  # Вердикт от этого не менялся, но строка в журнале врала.
  out=$(curl -s -o /dev/null -w '%{http_code}' --max-time 10 "$SMOKE_URL" 2>/dev/null)
  code="${out:-000}"
  case ",$SMOKE_EXPECT," in *",$code,"*) return 0 ;; esac
  log "smoke red: $SMOKE_URL -> $code"
  return 1
}

# Здоровье одной цели. Возвращает 0, если цель на месте.
target_healthy() { # target_healthy <kind:name>
  local kind="${1%%:*}" name="${1#*:}"
  case "$kind" in
    unit)   systemctl is-active --quiet "$name" ;;
    docker) [ "$(docker inspect -f '{{.State.Running}}' "$name" 2>/dev/null)" = true ] ;;
    *)      log "манифест целей: неизвестный вид «$kind» в «$1» — пропущено"; return 0 ;;
  esac
}

restart_target() { # restart_target <kind:name>
  local kind="${1%%:*}" name="${1#*:}"
  case "$kind" in
    unit)   act "R2 restart unit $name" systemctl restart "$name" ;;
    docker) act "R2 restart container $name" docker restart "$name" ;;
  esac
}

down_targets() {
  local t out=""
  IFS=',' read -ra _T <<< "$TARGETS"
  for t in "${_T[@]}"; do
    [ -n "$t" ] || continue
    target_healthy "$t" || out="$out$t "
  done
  printf '%s' "$out"
}

if [ "$STATUS_ONLY" = 1 ]; then
  printf 'box=%s attempts=%s/%s last_ok=%s\n' \
    "$BOX" "$(attempts_n)" "$MAX_ATTEMPTS" "$(cat "$LAST_OK" 2>/dev/null || echo never)"
  printf 'targets=%s\n' "$TARGETS"
  printf 'R4: PROXMOX_API_TOKEN %s\n' "${PROXMOX_API_TOKEN:+задан}${PROXMOX_API_TOKEN:-НЕ ЗАДАН (ступень инертна)}"
  exit 0
fi

# ── Вход: всё ли хорошо ──────────────────────────────────────────────────────
DOWN="$(down_targets)"
if smoke && [ -z "$DOWN" ]; then
  prev="$(attempts_n)"
  reset_attempts
  [ "$prev" != 0 ] && log "RECOVERED: чистый успех, счётчик $prev -> 0" && ledger R1 recovered "clean success after $prev attempts"
  exit 0
fi

# ── R1 — записать. Всегда, до любого действия ────────────────────────────────
log "R1 unhealthy: smoke=$(smoke && echo green || echo red) down=[${DOWN:-none}] attempts=$(attempts_n)/$MAX_ATTEMPTS"
ledger R1 observed "down=[${DOWN:-none}]"

# ── Потолок попыток. Сдаёмся ГРОМКО и НЕ действуем ───────────────────────────
if [ "$(attempts_n)" -ge "$MAX_ATTEMPTS" ]; then
  log "GAVE UP: $(attempts_n) попыток подряд без чистого успеха — лестница НЕ действует"
  ledger R1 gave-up "attempts exhausted, no action taken"
  escalate ERROR "лестница сдалась на $BOX" \
    "$(attempts_n) попыток подряд не дали чистого успеха. Цели: [${DOWN:-none}]. Смок: $SMOKE_URL. Дальше — руками: docs/SERVER_INCIDENT_MANUAL.md"
  exit 3
fi

[ "$DRY_RUN" = 1 ] || bump_attempts

# ── R2 — перезапустить только то, что лежит ──────────────────────────────────
# Ворота контракта: перезапускаем ЦЕЛИ, которые действительно не активны. Если
# смок красный, а все цели живы, значит болит не то, что лестница умеет чинить,
# и дёргать здоровые сервисы «на всякий случай» — вред, а не помощь.
if [ -n "$DOWN" ]; then
  for t in $DOWN; do restart_target "$t"; done
  log "R2 ждём до ${SMOKE_WAIT}с восстановления смока"
  waited=0
  while [ "$waited" -lt "$SMOKE_WAIT" ]; do
    sleep 5; waited=$((waited + 5))
    if smoke && [ -z "$(down_targets)" ]; then
      log "R2 SUCCESS: восстановилось за ${waited}с"
      ledger R2 success "restarted [$DOWN], recovered in ${waited}s"
      [ "$DRY_RUN" = 1 ] || reset_attempts
      escalate INFO "R2 починил $BOX" "Перезапущено: $DOWN. Смок зелёный через ${waited}с."
      exit 0
    fi
    [ "$DRY_RUN" = 1 ] && break
  done
  log "R2 FAILED: смок не восстановился за ${SMOKE_WAIT}с"
  ledger R2 failed "restart did not recover within ${SMOKE_WAIT}s"
else
  log "R2 SKIPPED: все цели активны — перезапускать нечего (болит не здесь)"
  ledger R2 skipped "all targets active"
fi

# ── R3 — вычистить состаренный мусор в /tmp ──────────────────────────────────
# Ограда жёсткая и НЕ обсуждается: только /tmp, только по возрасту, -xdev чтобы
# не уехать на другую файловую систему по подмонтированному каталогу. Ничего
# вне /tmp лестница не удаляет НИКОГДА (ограда волны, PLAN §4).
# Правило возраста — то же, что у tmpfiles.d на обеих машинах, а не своё:
# два разных правила старения на одной машине означали бы, что никто не знает,
# какое из них сработало.
CAND=$(find "$TMP_ROOT" -mindepth 1 -xdev -mtime "+$TMP_AGE_DAYS" 2>/dev/null | head -200)
if [ -n "$CAND" ]; then
  n=$(printf '%s\n' "$CAND" | wc -l)
  log "R3 кандидатов на удаление в /tmp старше ${TMP_AGE_DAYS}д: $n"
  printf '%s\n' "$CAND" | head -20 | sed 's/^/    /' >> "$LOG"
  act "R3 удалить $n состаренных объектов в /tmp" \
    find "$TMP_ROOT" -mindepth 1 -xdev -mtime "+$TMP_AGE_DAYS" -delete
  if smoke && [ -z "$(down_targets)" ]; then
    log "R3 SUCCESS: смок зелёный после чистки"
    ledger R3 success "cleared $n aged /tmp entries"
    [ "$DRY_RUN" = 1 ] || reset_attempts
    escalate INFO "R3 починил $BOX" "Вычищено $n состаренных объектов в /tmp, смок зелёный."
    exit 0
  fi
  log "R3 FAILED: чистка не помогла"
  ledger R3 failed "cleared $n entries, still unhealthy"
else
  log "R3 SKIPPED: в /tmp нечего старить (нет объектов старше ${TMP_AGE_DAYS}д)"
  ledger R3 skipped "no aged /tmp entries"
fi

# ── R4 — перезапуск контейнера. ИНЕРТЕН без токена ───────────────────────────
if [ -z "${PROXMOX_API_TOKEN:-}" ]; then
  # Ровно та строка, которую требует приёмка волны: сказать, что СДЕЛАЛ БЫ,
  # и не сделать. Зелёной лампочки над невыданным грантом здесь не будет.
  log "R4 INERT: would restart ct $CT_ID (PROXMOX_API_TOKEN не задан) — эскалация человеку"
  ledger R4 inert "would restart ct $CT_ID; no token, no action"
  escalate ERROR "нужен человек: $BOX не поднялся сам" \
    "R2 и R3 не помогли. Следующая ступень — перезапуск контейнера ct $CT_ID через Proxmox API, но PROXMOX_API_TOKEN не выдан (P1 плана, хост у Артёма), поэтому лестница НЕ ДЕЙСТВУЕТ. Требуется ручной разбор: docs/SERVER_INCIDENT_MANUAL.md"
  exit 4
fi

# Токен есть — но ступень всё равно ходит через act(), то есть под --dry-run
# по-прежнему только говорит. День, когда грант появится, не должен быть
# одновременно днём первого непроверенного действия.
log "R4 ARMED: PROXMOX_API_TOKEN задан"
ledger R4 armed "token present, restarting ct $CT_ID"
act "R4 restart ct $CT_ID через Proxmox API" \
  curl -sS --max-time 30 -X POST \
    -H "Authorization: PVEAPIToken=${PROXMOX_API_TOKEN}" \
    "${PROXMOX_API_URL:-https://127.0.0.1:8006/api2/json}/nodes/${PROXMOX_NODE:-pve}/lxc/${CT_ID}/status/reboot"
escalate ERROR "R4 перезапустил контейнер $BOX" "ct $CT_ID отправлен в reboot после провала R2 и R3."
exit 5
