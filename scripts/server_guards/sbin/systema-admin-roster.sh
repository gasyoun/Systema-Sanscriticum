#!/bin/bash
# systema-admin-roster.sh — ежедневный страж дрейфа привилегированных пользователей (H4590).
#
# MANAGED FILE — ставится scripts/server_guards_apply.sh из
# scripts/server_guards/sbin/systema-admin-roster.sh. Править копию в
# репозитории, не на сервере: расхождение видно guards:verify.
#
# ЗАЧЕМ. Урок samskrtam95 (H4570): руж-админ `ova_wp` (administrator, planted
# 07-09) жил в базе 4 дня и был найден только ручным разбором. Здесь такой
# должен быть пойман на СЛЕДУЮЩИХ же сутках: суточный хэш привилегированного
# среза users, дрейф = тревога + ОБЕ состояния в бриф.
#
# AUTO-DETECT привилегии (в этой схеме НЕТ model_has_roles/roles): срезаем
# список колонок users; если есть is_admin — берём is_admin=1; если есть role —
# берём role из ROSTER_PRIV_ROLES (server_guards.conf). Нет ни той, ни другой
# колонки — операционная ошибка, НЕ «чисто».
#
# ЧТО ДЕЛАЕТ. Раз в сутки: tinker --execute читает срез (только SELECT),
# считает sha256 канонического текста «id|email|role|is_admin» по возрастанию
# id и сверяет с /var/lib/systema-guards/admin_roster.sha256. Совпало — GREEN
# строка в дайджест; не совпало — прежнее состояние уезжает в .prev.txt, новое
# становится эталоном, ОБА считаются в бриф (brief/admin_roster_latest.md),
# page_or_queue P1, exit 2. ZERO записей в живые данные приложения.
#
# Код возврата: 0 = дрейфа нет (или первичная фиксация), 2 = дрейф ростера,
# 1 = операционная ошибка (БД/tinker/колонки).
set -uo pipefail

APP_DIR="@@APP_DIR@@"
PRIV_ROLES="@@ROSTER_PRIV_ROLES@@"
STATE_DIR="@@ROSTER_STATE_DIR@@"
LOG=/var/log/systema-admin-roster.log
NAME=admin_roster

if [ -r /home/hermes/bin/lane_lib.sh ]; then
  # shellcheck source=/dev/null
  . /home/hermes/bin/lane_lib.sh
else
  BRIEF_DIR=/home/hermes/brief; mkdir -p "$BRIEF_DIR" 2>/dev/null || true
  hb_mark() { :; }
  page_or_queue() { echo "$(date -u '+%F %T') [$1] $2" >> "$BRIEF_DIR/pending_pages.txt" 2>/dev/null || true; }
fi

TS() { date -u '+%F %T'; }
log() { printf '%s %s\n' "$(TS)" "$*" >> "$LOG"; }

HASHF="$STATE_DIR/${NAME}.sha256"
CURF="$STATE_DIR/${NAME}.cur.txt"
PREVF="$STATE_DIR/${NAME}.prev.txt"

mkdir -p "$STATE_DIR" 2>/dev/null || true
TMP=$(mktemp) || exit 1
trap 'rm -f "$TMP"' EXIT

# PHP одним куском. Роли приходят ЧЕРЕЗ env (не в коде): числа/списки живут
# только в server_guards.conf. Обе механики привилегии — OR.
PHP='
$cols = Schema::getColumnListing("users");
$hasAdmin = in_array("is_admin", $cols, true);
$hasRole  = in_array("role", $cols, true);
if (!$hasAdmin && !$hasRole) { echo "OPERATIONAL-ERROR: ни is_admin, ни role в users нет\n"; exit(1); }
$roles = array_values(array_filter(array_map("trim", explode(",", (string) getenv("ROSTER_ROLES")))));
$q = DB::table("users")->select($hasAdmin && $hasRole ? ["id", "email", "is_admin", "role"] : ($hasAdmin ? ["id", "email", "is_admin"] : ["id", "email", "role"]));
$q->where(function ($w) use ($hasAdmin, $hasRole, $roles) {
    $touched = false;
    if ($hasAdmin) { $w->where("is_admin", 1); $touched = true; }
    if ($hasRole && count($roles) > 0) { $touched ? $w->orWhereIn("role", $roles) : $w->whereIn("role", $roles); }
});
$rows = $q->orderBy("id")->get();
foreach ($rows as $r) {
    echo $r->id."|".$r->email."|".($hasRole ? (string) $r->role : "")."|".($hasAdmin ? (int) $r->is_admin : "")."\n";
}
'

OUT=$(cd "$APP_DIR" && ROSTER_ROLES="$PRIV_ROLES" php artisan tinker --execute="$PHP" 2>/dev/null)
RC=$?
if [ "$RC" -ne 0 ] || printf '%s' "$OUT" | grep -q "OPERATIONAL-ERROR"; then
  reason=$(printf '%s' "$OUT" | grep "OPERATIONAL-ERROR" | head -1)
  {
    echo "Admin-roster .92 $(TS)Z"
    echo "WARN — срез не получен (operational): ${reason:-tinker rc=$RC}; прежнее состояние НЕ тронуто"
  } > "$BRIEF_DIR/${NAME}_latest.md"
  log "WARN operational rc=$RC ${reason:0:120}"
  hb_mark "$NAME" 1
  exit 1
fi

printf '%s\n' "$OUT" | sed '/^$/d' > "$TMP"
COUNT=$(wc -l < "$TMP")
NEW_HASH=$(sha256sum "$TMP" | cut -d' ' -f1)

# ── Первичная фиксация ───────────────────────────────────────────────────────
if [ ! -f "$HASHF" ]; then
  cp -f "$TMP" "$CURF"; printf '%s' "$NEW_HASH" > "$HASHF"
  {
    echo "Admin-roster .92 $(TS)Z"
    echo "GREEN — первичная фиксация: $COUNT привилегированных (id|email|role|is_admin), хэш записан"
  } > "$BRIEF_DIR/${NAME}_latest.md"
  log "OK baseline count=$COUNT hash=${NEW_HASH:0:12}"
  hb_mark "$NAME" 0
  exit 0
fi

# ── Сверка ───────────────────────────────────────────────────────────────────
OLD_HASH=$(cat "$HASHF" 2>/dev/null)
if [ "$NEW_HASH" = "$OLD_HASH" ]; then
  {
    echo "Admin-roster .92 $(TS)Z"
    echo "GREEN — $COUNT привилегированных, хэш неизменен (${NEW_HASH:0:12}…)"
  } > "$BRIEF_DIR/${NAME}_latest.md"
  log "OK count=$COUNT"
  hb_mark "$NAME" 0
  exit 0
fi

# ── Дрейф: логгируем ОБА состояния, новое становится эталоном ────────────────
OLD_COUNT=0; [ -f "$CURF" ] && OLD_COUNT=$(wc -l < "$CURF")
cp -f "$CURF" "$PREVF" 2>/dev/null || : > "$PREVF"
cp -f "$TMP" "$CURF"
printf '%s' "$NEW_HASH" > "$HASHF"
ADDED=$(comm -13 "$PREVF" "$CURF" | head -10)
REMOVED=$(comm -23 "$PREVF" "$CURF" | head -10)
{
  echo "Admin-roster .92 $(TS)Z"
  echo "FAIL — дрейф привилегированного ростера: было $OLD_COUNT → стало $COUNT (оба состояния: admin_roster.prev.txt / .cur.txt в $STATE_DIR)"
  [ -n "$ADDED" ] && { echo "  новые:"; printf '%s\n' "$ADDED" | sed 's/^/    + /'; }
  [ -n "$REMOVED" ] && { echo "  выбывшие:"; printf '%s\n' "$REMOVED" | sed 's/^/    - /'; }
  echo "  проверка вручную: php artisan tinker --execute=\"App\\Models\\User::count();\""
} > "$BRIEF_DIR/${NAME}_latest.md"
log "FAIL drift was=$OLD_COUNT now=$COUNT"
page_or_queue "P1" "admin-roster .92: дрейф привилегированных $OLD_COUNT→$COUNT (урок ova_wp: руж-админ ловится сутками, не неделями)"
hb_mark "$NAME" 2
exit 2
