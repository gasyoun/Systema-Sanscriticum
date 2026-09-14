#!/usr/bin/env bash
# MANAGED FILE — scripts/server_guards_apply.sh --profile n8n.
# systema-nft-armed-apply.sh — хореография отката для волны 4a (H3184).
#
# ЭТО И ЕСТЬ ПОСТАВКА H3184. Сам ruleset — тривиален; невосстановимой волну
# делает порядок действий, и он здесь зашит в код, а не оставлен памяти
# оператора в три часа ночи.
#
# Порядок (не обсуждается):
#   1. arm     — поставить `at`-задание, которое вернёт policy accept. СНАЧАЛА.
#   2. apply   — применить default-deny. Отказывается работать без взведённого отката.
#   3. verify  — доказать НОВОЙ сессией + кросс-пробой с .92.
#   4. disarm  — снять `at`-задание. Отказывается работать, пока verify не прошёл.
#
# Если что-то неоднозначно — НЕ снимайте задание. Слитый ruleset
# восстанавливается; lockout на машине без консоли — нет.
#
# Использование (на .91, от root, ЧЕЛОВЕК У ПУЛЬТА):
#   bash systema-nft-armed-apply.sh arm [минуты]     # по умолчанию 10
#   bash systema-nft-armed-apply.sh apply /etc/nftables.d/systema-default-deny.nft
#   bash systema-nft-armed-apply.sh verify
#   bash systema-nft-armed-apply.sh disarm
#   bash systema-nft-armed-apply.sh status
#
# Коды выхода: 0 успех · 1 отказ по предохранителю · 2 ошибка использования

set -uo pipefail

STATE_DIR=/run/systema-nft-armed
ARM_MARKER="$STATE_DIR/at-job-id"
VERIFY_MARKER="$STATE_DIR/verified"
PEER_IP=192.168.200.92
PEER_PROBE_HOST=samskrte.ru

RED=$(printf '\033[1;31m'); GRN=$(printf '\033[1;32m')
YLW=$(printf '\033[1;33m'); OFF=$(printf '\033[0m')
die()  { printf '%sОТКАЗ%s  %s\n' "$RED" "$OFF" "$*" >&2; exit 1; }
ok()   { printf '%sok%s     %s\n' "$GRN" "$OFF" "$*"; }
warn() { printf '%sвнимание%s %s\n' "$YLW" "$OFF" "$*"; }

[ "$(id -u)" = 0 ] || die "нужен root"
mkdir -p "$STATE_DIR"

cmd_arm() {
  local mins="${1:-10}"
  command -v at >/dev/null 2>&1 || die "нет команды at — откат нечем взводить. apt install at"
  systemctl is-active --quiet atd || die "atd не запущен — задание не сработает. systemctl start atd"
  [ -f "$ARM_MARKER" ] && die "откат уже взведён (задание $(cat "$ARM_MARKER")). Сначала disarm или дайте ему сработать."

  # Задание возвращает input в accept, НЕ трогая Docker'ские таблицы.
  local job_id
  job_id=$(printf '%s\n' 'nft add chain inet filter input "{ type filter hook input priority filter; policy accept; }"' \
    | at now + "$mins" minutes 2>&1 | sed -n 's/^job \([0-9]\+\).*/\1/p')
  [ -n "$job_id" ] || die "не удалось поставить at-задание"
  printf '%s\n' "$job_id" > "$ARM_MARKER"
  rm -f "$VERIFY_MARKER"

  ok "откат взведён: задание $job_id, сработает через $mins мин"
  printf '\n--- atq ---\n'; atq
  printf '\n--- тело задания %s (прочитайте его глазами) ---\n' "$job_id"; at -c "$job_id" | tail -20
  printf '\nТеперь и только теперь: apply\n'
}

cmd_apply() {
  local rules="${1:-/etc/nftables.d/systema-default-deny.nft}"
  [ -f "$ARM_MARKER" ] || die "откат НЕ взведён. Сначала arm. Это единственный предохранитель волны."
  [ -f "$rules" ] || die "нет файла правил: $rules"
  atq | grep -q "^$(cat "$ARM_MARKER")\b" || die "задание $(cat "$ARM_MARKER") исчезло из очереди — откат не вооружён. Повторите arm."

  nft -c -f "$rules" || die "ruleset не проходит проверку синтаксиса — ничего не применено"
  ok "синтаксис ruleset корректен"

  nft -f "$rules" || die "применение не удалось"
  ok "default-deny применён"
  printf '\n--- nft list table inet filter ---\n'; nft list table inet filter
  printf '\n%sСЕЙЧАС: откройте НОВУЮ SSH-сессию с другой машины. Текущая ничего не доказывает.%s\n' "$YLW" "$OFF"
  printf 'Затем: verify\n'
}

cmd_verify() {
  local fails=0

  # Шаг 3 — новая сессия. Established-соединения переживают правила, режущие
  # новые: именно так отказ прячется до момента, когда оператор отключится.
  local new_sessions
  new_sessions=$(ss -tn state established "( sport = :22 )" 2>/dev/null | tail -n +2 | wc -l)
  if [ "$new_sessions" -ge 2 ]; then
    ok "на 22 порту $new_sessions сессий — вторая сессия открылась"
  else
    warn "видно только $new_sessions сессию на 22 — откройте НОВУЮ и повторите verify"
    fails=$((fails+1))
  fi

  # Шаг 4 — кросс-проба с .92. Она доказывает, что машина ОБСЛУЖИВАЕТ, а не что
  # SSH отвечает: публичный 22 между машинами закрыт в обе стороны, проба идёт
  # по приватной сети HTTPS с --resolve. Шаги 3 и 4 не заменяют друг друга.
  if curl -sS -o /dev/null -w '%{http_code}' --max-time 10 \
       --resolve "$PEER_PROBE_HOST:443:$PEER_IP" "https://$PEER_PROBE_HOST/" 2>/dev/null | grep -q '^[23]'; then
    ok "кросс-проба до $PEER_PROBE_HOST через $PEER_IP отвечает"
  else
    warn "кросс-проба не прошла — это НЕ повод снимать откат"
    fails=$((fails+1))
  fi

  # Запасной путь: Tailscale должен пережить default-deny (правило iifname tailscale0).
  if command -v tailscale >/dev/null 2>&1 && tailscale status >/dev/null 2>&1; then
    ok "tailscale жив — второй путь восстановления на месте"
  else
    warn "tailscale не отвечает — единственный запасной путь потерян, откат НЕ снимать"
    fails=$((fails+1))
  fi

  if [ "$fails" -eq 0 ]; then
    touch "$VERIFY_MARKER"
    ok "все проверки прошли — можно disarm"
    return 0
  fi
  die "$fails проверк(и) не прошли. Ничего не снимайте: дайте at-заданию сработать."
}

cmd_disarm() {
  [ -f "$ARM_MARKER" ] || die "нечего снимать"
  [ -f "$VERIFY_MARKER" ] || die "verify не проходил. Снимать откат до доказательства — это и есть тот самый отказ."
  local job_id; job_id=$(cat "$ARM_MARKER")
  atrm "$job_id" 2>/dev/null || warn "atrm $job_id не сработал — проверьте atq вручную"
  rm -f "$ARM_MARKER" "$VERIFY_MARKER"
  ok "откат снят (задание $job_id). Волна 4a закрыта."
  printf '\nОстаётся: продвинуть ruleset в /etc/nftables.conf, чтобы он пережил перезагрузку.\n'
}

cmd_status() {
  if [ -f "$ARM_MARKER" ]; then ok "откат взведён: задание $(cat "$ARM_MARKER")"; atq; else warn "откат НЕ взведён"; fi
  [ -f "$VERIFY_MARKER" ] && ok "verify пройден" || warn "verify не пройден"
  printf '\n--- политика input ---\n'
  nft list chain inet filter input 2>/dev/null | head -3
}

case "${1:-}" in
  arm)    shift; cmd_arm "$@" ;;
  apply)  shift; cmd_apply "$@" ;;
  verify) cmd_verify ;;
  disarm) cmd_disarm ;;
  status) cmd_status ;;
  *) sed -n '2,30p' "${BASH_SOURCE[0]}"; exit 2 ;;
esac
