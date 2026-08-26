#!/bin/bash
# systema-peer-probe.sh — взаимная проверка соседней машины (W3b, D7).
#
# MANAGED FILE — ставится scripts/server_guards_apply.sh из
# scripts/server_guards/sbin/systema-peer-probe.sh. ОДИН файл на ОБЕ машины:
# .92 берёт его из своего манифеста, .91 — строкой ../server_guards/... из
# своего. Значения (кого проверяем, чего ждём, насколько это страшно) живут
# в server_guards.conf / server_guards_n8n.conf и ТОЛЬКО там. Править копию в
# репозитории, не на сервере: расхождение видно проверкой.
#
# ЗАЧЕМ. Better Stack молчанием говорит, ЧТО что-то умерло, но не говорит, ЧТО
# именно: обе машины — гости одного хоста Proxmox, и «сайт не отвечает» одинаково
# выглядит и при падении контейнера, и при падении хоста, и при обрыве сети.
# Перекрёстная проверка отвечает на «что именно» за один такт: если .92 видит
# .91 живым, а снаружи .91 не виден — это сеть//Caddy, а не смерть контейнера.
#
# ГЛАВНОЕ ПРАВИЛО ЭТОЙ ВОЛНЫ, и причина, по которой сторож ходит ТАЙМЕРОМ, а не
# кроном: сторож не должен разделять судьбу того, что сторожит. 28-07-2026
# cabinet:probe стоял внутри зависшего schedule:run и молчал 79 минут; 18-08-2026
# cron.service упёрся в общий потолок памяти 2 ГиБ и задушил ВСЕ кроновые задачи
# разом (H3121). Свой таймер = свой cgroup = своя судьба.
#
# ЧТО ЗАМЕРЕНО 26-08-2026, а не предположено:
#   • Машины говорят по ЧАСТНОЙ сети: 192.168.200.91 <-> 192.168.200.92,
#     ping 0.05 мс. Публичный порт 22 между ними закрыт в обе стороны.
#   • .92 -> .91: http://192.168.200.91/ отдаёт 308 (Caddy редиректит на TLS),
#     https с публичным именем context-ai.ru и --resolve на частный адрес — 200.
#     n8n (5678) на частном интерфейсе НЕ слушает, только 127.0.0.1 — проверять
#     нужно Caddy, а не n8n напрямую.
#   • .91 -> .92: https://samskrte.ru/ с --resolve на 192.168.200.92 — 200.
#
# Почему --resolve, а не просто имя. На .92 context-ai.ru уже разрешается в
# ЧАСТНЫЙ 192.168.200.91 (split-horizon). Это удобно, но молчаливо: смена DNS
# увела бы проверку в публичный интернет, и «сосед жив» стало бы значить
# «жив публичный маршрут» — другой смысл при том же зелёном свете. --resolve
# прибивает маршрут гвоздём, и подмена DNS его не меняет.
set -uo pipefail

PEER_NAME="@@PEER_NAME@@"
PROBE_URL="@@PEER_PROBE_URL@@"
PROBE_RESOLVE="@@PEER_PROBE_RESOLVE@@"
PROBE_EXPECT="@@PEER_PROBE_EXPECT@@"
PROBE_TIMEOUT="@@PEER_PROBE_TIMEOUT@@"
PROBE_ATTEMPTS="@@PEER_PROBE_ATTEMPTS@@"
PROBE_SEVERITY="@@PEER_PROBE_SEVERITY@@"

LOG=/var/log/systema-peer-probe.log
# Секрет живёт ТОЛЬКО здесь и НИКОГДА в репозитории (ограда волны, PLAN §4).
# Файла может не быть — это не авария: сосед всё равно проверяется, в Better
# Stack просто ничего не уходит, и строка в логе об этом честно говорит.
ENVFILE=/etc/default/systema-peer-probe
PEER_PROBE_HEARTBEAT_URL=""
# shellcheck source=/dev/null
[ -r "$ENVFILE" ] && . "$ENVFILE"

TS() { date -u '+%Y-%m-%dT%H:%M:%SZ'; }
log() { printf '%s %s\n' "$(TS)" "$*" >> "$LOG"; }

# Один заход. Печатает код HTTP (000 = не доехали вовсе).
probe_once() {
  local args=(-s -o /dev/null -w '%{http_code}' --max-time "$PROBE_TIMEOUT") out
  [ -n "$PROBE_RESOLVE" ] && args+=(--resolve "$PROBE_RESOLVE")
  # НЕ `curl … || printf '000'`. При недоступном хосте curl печатает 000 через
  # -w И возвращает ненулевой код, то есть оба источника складывались и в
  # журнал уходило «code=000000» (поймано живым прогоном 26-08-2026, а не
  # чтением кода). Сравнение с PROBE_EXPECT при этом всё равно не совпадало и
  # сосед честно считался мёртвым — но строка в журнале врала, а разбирают
  # аварию именно по ней.
  out=$(curl "${args[@]}" "$PROBE_URL" 2>/dev/null)
  printf '%s' "${out:-000}"
}

# Одна неудачная попытка — это ещё не смерть соседа: перезапуск Caddy занимает
# секунды, и одиночный 000 на пятиминутном такте дал бы ложную тревогу. Отсюда
# PEER_PROBE_ATTEMPTS с паузой; «сосед мёртв» — только когда НИ ОДНА не прошла.
code=000
attempt=0
while [ "$attempt" -lt "$PROBE_ATTEMPTS" ]; do
  attempt=$((attempt + 1))
  code=$(probe_once)
  case ",$PROBE_EXPECT," in
    *",$code,"*) break ;;
  esac
  [ "$attempt" -lt "$PROBE_ATTEMPTS" ] && sleep 5
done

ok=0
case ",$PROBE_EXPECT," in
  *",$code,"*) ok=1 ;;
esac

# Пульс. Молчание ловит Better Stack; явный /fail — только когда мы ЗНАЕМ, что
# сосед не отвечает, и только если его недоступность на этой машине считается
# critical. На .92 недоступность .91 — warning (автоматика встала, продажи нет),
# на .91 недоступность .92 — critical (лежит сам продукт).
ping_heartbeat() { # ping_heartbeat <ok|fail> <text>
  [ -n "${PEER_PROBE_HEARTBEAT_URL:-}" ] || { log "heartbeat SKIP: $ENVFILE не задаёт PEER_PROBE_HEARTBEAT_URL"; return 0; }
  local url="$PEER_PROBE_HEARTBEAT_URL"
  [ "$1" = fail ] && url="${url%/}/fail"
  # -o /dev/null: тело ответа Better Stack нам не нужно, а в логе оно только
  # засоряло бы. URL не печатается НИКОГДА — в нём токен.
  curl -s -o /dev/null --max-time 10 -X POST --data "$2" "$url" 2>/dev/null || true
}

if [ "$ok" = 1 ]; then
  log "OK peer=$PEER_NAME code=$code attempts=$attempt url=$PROBE_URL"
  ping_heartbeat ok "peer $PEER_NAME ok ($code)"
  exit 0
fi

log "FAIL peer=$PEER_NAME code=$code attempts=$attempt severity=$PROBE_SEVERITY url=$PROBE_URL"
if [ "$PROBE_SEVERITY" = critical ]; then
  ping_heartbeat fail "peer $PEER_NAME unreachable: last code $code after $attempt attempts"
else
  # warning: пульс НЕ ронять. Иначе одна и та же авария поднимает два инцидента
  # (свой и соседский) и дежурный видит шум вместо адреса. Молчания хватит.
  ping_heartbeat ok "peer $PEER_NAME unreachable ($code) — severity warning, not failing own heartbeat"
fi
exit 0
