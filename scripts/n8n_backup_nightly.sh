#!/usr/bin/env bash
# H7690 — nightly disaster-recovery pack for the n8n stack on vps91 (context-ai.ru)
# Created: 07-11-2026
# H7832 — hardening: partial-archive retention + remote checksum proof
#   * tar writes to a .part- staging name and is atomically renamed only after
#     tar -tzf sanity passes; a kill / ENOSPC mid-write can therefore never
#     leave a file that retention would count as the newest good archive.
#   * every verified archive gets a .sha256 sidecar; retention verifies before
#     counting (sidecar match, or tar -tzf probe for legacy sidecar-less files)
#     and removes corrupt/partial files instead of evicting the oldest GOOD one.
#   * the off-box copy is proven by size + fresh mtime AND a far-side sha256
#     comparison before any remote pruning happens.
#
# Nightly (default): one small tar with everything a rebuild needs — compose files,
# .env (N8N_ENCRYPTION_KEY lives there; without it credentials never decrypt again),
# caddy/, patches/, entrypoint + tg-webhook scripts, and a CONSISTENT sqlite snapshot
# taken with `sqlite3 .backup` while prod keeps running. storage/binaryData and this
# backups/ directory are never inside the nightly tar.
#
# Weekly (--full): same tar plus storage/binaryData, linked in rather than copied so
# the box never holds two copies of it. Weekly tar is pushed and then removed locally.
#
# After every run the fresh tar is pushed off-box to duck (US, geo-separated) and the
# copy is PROVEN by size + fresh-mtime + sha256 probe on the far side — logs are not trusted.
#
# Retention: 14 good archives, locally and on the off-box (never the one just written).
# Restore procedure: docs/runbooks/n8n-vps91-migration.md (Systema-Sanscriticum).
set -euo pipefail

BASE=/opt/n8n
BK="$BASE/backups"
LOG=/var/log/n8n-backup.log
OFF_PRIMARY="root@147.189.174.159"   # duck, US — geo-separated
OFF_FALLBACK="root@193.232.229.92"   # .92 — SAME SPb DC as .91: a copy, not a DR site
OFF_KEY="$HOME/.ssh/id_n8n_backup_duck"
OFF_DIR="/root/n8n-backups"
KEEP=14
MAX_NIGHTLY_MB=500
MIN_FREE_MB=2048

FULL=0
[ "${1:-}" = "--full" ] && FULL=1

log() { printf '%s %s\n' "$(date -u +%FT%TZ)" "$*" | tee -a "$LOG" >&2; }
die() { log "FATAL: $*"; exit 1; }
free_mb() { df -Pm "$1" | awk 'NR==2 {print $4}'; }

mkdir -p "$BK"
exec 9>"$BK/.backup.lock"
flock -n 9 || die "another backup run holds $BK/.backup.lock"

DAY=$(date +%Y%m%d)
NAME="n8n-migration-$DAY.tar.gz"
[ -e "$BK/$NAME" ] && NAME="n8n-migration-$DAY-$(date +%H%M%S).tar.gz"
OUT="$BK/$NAME"
TMP_OUT=""   # staging name during tar; renamed to $OUT only after integrity passes

STAGE=$(mktemp -d "$BK/.stage.XXXXXX")
# H7832: the trap must also kill a partial staging archive on EVERY failure path —
# a $OUT with the final name is only ever created by the atomic mv after verification.
cleanup() { rm -rf "$STAGE"; [ -z "$TMP_OUT" ] || rm -f -- "$TMP_OUT"; }
trap cleanup EXIT

AVAIL=$(free_mb "$BK")
[ "$AVAIL" -ge "$MIN_FREE_MB" ] || die "only ${AVAIL}MB free on $BK (< ${MIN_FREE_MB}MB guard); refusing to write"
log "start name=$NAME mode=$([ "$FULL" = 1 ] && echo full || echo nightly) free=${AVAIL}MB"

# --- (a) consistent DB snapshot; prod containers stay up --------------------
[ -f "$BASE/storage/database.sqlite" ] || die "storage/database.sqlite missing"
# .backup uses SQLite's online-backup API and is WAL-safe against a live writer.
sqlite3 "$BASE/storage/database.sqlite" ".backup '$STAGE/database.sqlite'"
[ "$(sqlite3 "$STAGE/database.sqlite" 'PRAGMA quick_check;')" = ok ] || die "DB snapshot failed quick_check"
log "db snapshot ok: $(du -h "$STAGE/database.sqlite" | cut -f1)"

# --- (b) stage exactly what a rebuild needs, nothing else -------------------
mkdir -p "$STAGE/caddy" "$STAGE/patches" "$STAGE/db"
cp -p "$BASE/docker-compose.yml" "$STAGE/"
[ -f "$BASE/docker-compose.override.yml" ] && cp -p "$BASE/docker-compose.override.yml" "$STAGE/"
cp -p "$BASE/.env" "$STAGE/.env"
[ -f "$BASE/caddy/Caddyfile" ] && cp -p "$BASE/caddy/Caddyfile" "$STAGE/caddy/"
[ -d "$BASE/patches" ] && cp -p "$BASE"/patches/* "$STAGE/patches/" 2>/dev/null || true
for f in entrypoint-with-env-patch.sh tg-webhook-reregister.sh tg-webhook-guard-check.sh tg91.crt; do
  # tg91.crt = PUBLIC half of the self-signed cert duck's relay presents; setWebhook
  # needs it re-uploaded after any migration. Its private key lives only on duck
  # (/etc/nginx/ssl/tg91.key) — see the runbook's §7 caveat.
  [ -f "$BASE/$f" ] && cp -p "$BASE/$f" "$STAGE/"
done
# n8n runtime config dir (tiny; carries paths/umask state). NOT binaryData.
[ -d "$BASE/storage/config" ] && cp -a "$BASE/storage/config" "$STAGE/storage-config"
mv "$STAGE/database.sqlite" "$STAGE/db/database.sqlite"

if [ "$FULL" = 1 ]; then
  [ -d "$BASE/storage/storage" ] || die "full run: storage/storage missing"
  BD_MB=$(du -sm "$BASE/storage/storage" | cut -f1)
  # only the compressed output is written, so the box never holds a second copy
  [ "$AVAIL" -ge $(( BD_MB / 2 + 1024 )) ] || die "full run: ${AVAIL}MB free, needs ~$(( BD_MB / 2 + 1024 ))MB"
  ln -s "$BASE/storage/storage" "$STAGE/binaryData"
  log "full run: binaryData linked, ${BD_MB}MB uncompressed"
fi

{
  echo "created_utc=$(date -u +%FT%TZ)"
  echo "host=$(hostname) ip=$(hostname -I | awk '{print $1}')"
  echo "mode=$([ "$FULL" = 1 ] && echo full || echo nightly)"
  echo "n8n_image=$(grep -m1 -o 'n8nio/n8n:[^ ]*' "$BASE/docker-compose.yml" || true)"
  echo "caddy_image=$(grep -m1 -o 'caddy:[0-9][^ ]*' "$BASE/docker-compose.override.yml" 2>/dev/null || echo unset)"
  echo "db_snapshot_sha256=$(sha256sum "$STAGE/db/database.sqlite" | cut -d' ' -f1)"
  echo "restore=docs/runbooks/n8n-vps91-migration.md"
} > "$STAGE/MANIFEST.txt"

# -h: follow the binaryData link (full mode); binaryData/backups/ never in nightly.
# H7832: tar targets the .part staging name — a final-name file never exists until
# the integrity checks below pass and the atomic rename runs.
TMP_OUT="$BK/.part-$NAME"
tar -C "$STAGE" -czhf "$TMP_OUT" .
SIZE=$(stat -c %s "$TMP_OUT"); SIZE_MB=$((SIZE/1024/1024))
log "tar written (staging) $TMP_OUT ${SIZE_MB}MB"
if [ "$FULL" = 0 ] && [ "$SIZE_MB" -gt "$MAX_NIGHTLY_MB" ]; then
  log "WARN: nightly tar ${SIZE_MB}MB exceeds ${MAX_NIGHTLY_MB}MB budget"
fi
LIST="$STAGE/.listing"
tar -tzf "$TMP_OUT" > "$LIST"
grep -qx './.env' "$LIST"              || die "tar sanity: .env missing"
grep -qx './db/database.sqlite' "$LIST" || die "tar sanity: db snapshot missing"
grep -qx './docker-compose.yml' "$LIST" || die "tar sanity: docker-compose.yml missing"
[ "$FULL" = 1 ] && { grep -qx './binaryData/' "$LIST" || die "tar sanity: binaryData missing in full run"; }

# H7832: checksum before the archive earns its final name; sidecar travels with it
# so retention and restore-time verification never need to unpack the tar.
SHA=$(sha256sum "$TMP_OUT" | cut -d' ' -f1)
mv -f -- "$TMP_OUT" "$OUT"
printf '%s  %s\n' "$SHA" "$NAME" > "$OUT.sha256"
TMP_OUT=""   # renamed cleanly; nothing left for the trap to remove
log "archive verified + atomically named $OUT sha256=$SHA"

# --- retention: KEEP good archives, never the one just written --------------
# H7832: verify BEFORE counting — a corrupt/partial file is removed as suspect
# (it must not occupy a KEEP slot and push out the oldest good archive), not kept
# as "newest by mtime". Files without a sidecar (pre-H7832 archives) fall back to
# a full tar -tzf integrity probe.
prune_local() {
  local good=0 f base verdict
  while IFS= read -r f; do
    base=$(basename "$f")
    if [ "$f" = "$OUT" ]; then log "retention: keep just-written $base"; continue; fi
    verdict=good
    if [ -f "$BK/$base.sha256" ]; then
      (cd "$BK" && sha256sum -c "$base.sha256" >/dev/null 2>&1) || verdict=suspect
    elif ! tar -tzf "$f" >/dev/null 2>&1; then
      verdict=suspect
    fi
    if [ "$verdict" = suspect ]; then
      rm -f -- "$f" "$BK/$base.sha256"
      log "retention: REMOVED corrupt/partial $base"
      continue
    fi
    good=$((good+1))
    if [ "$good" -gt "$KEEP" ]; then
      rm -f -- "$f" "$BK/$base.sha256"
      log "retention: removed $base (keep=$KEEP)"
    fi
  done < <(ls -1t "$BK"/n8n-migration-*.tar.gz 2>/dev/null)
  # leftovers from a SIGKILLed run: staging partials and sidecars without archives
  rm -f -- "$BK"/.part-n8n-migration-*.tar.gz 2>/dev/null || true
  for s in "$BK"/n8n-migration-*.tar.gz.sha256; do
    [ -e "$s" ] || continue
    [ -e "${s%.sha256}" ] || { rm -f -- "$s"; log "retention: removed orphan sidecar $(basename "$s")"; }
  done
  return 0   # never let a non-matching glob turn into a set -e failure
}
prune_local

# --- push off-box and PROVE it (size + fresh mtime + sha256 on the far side) -
push() { # $1 host  $2 key (may be empty)  $3 label
  local host="$1" key="$2" label="$3" rsize rmtime age rsha
  local -a SSHK=()
  [ -n "$key" ] && SSHK=(-i "$key")
  ssh "${SSHK[@]}" -o BatchMode=yes -o ConnectTimeout=15 -o StrictHostKeyChecking=accept-new "$host" "mkdir -p $OFF_DIR" || return 1
  scp -q "${SSHK[@]}" -o BatchMode=yes -o ConnectTimeout=20 -o StrictHostKeyChecking=accept-new "$OUT" "$host:$OFF_DIR/" || return 1
  scp -q "${SSHK[@]}" -o BatchMode=yes -o ConnectTimeout=20 -o StrictHostKeyChecking=accept-new "$OUT.sha256" "$host:$OFF_DIR/" \
    || log "WARN offbox($label): sidecar copy failed (archive sha still proven below)"
  rsize=$(ssh "${SSHK[@]}" -o BatchMode=yes -o ConnectTimeout=15 "$host" \
      "stat -c '%s %Y' '$OFF_DIR/$NAME' 2>/dev/null") || return 1
  rsize=${rsize:-}
  [ -n "$rsize" ] || { log "offbox($label): file absent after copy"; return 1; }
  rmtime=${rsize#* }; rsize=${rsize%% *}
  [ "$rsize" = "$SIZE" ] || { log "offbox($label): size mismatch ${rsize} != $SIZE"; return 1; }
  age=$(( $(date +%s) - rmtime ))
  [ "$age" -le 900 ] || { log "offbox($label): copy mtime ${age}s old"; return 1; }
  # H7832: checksum proof before this host's retention prunes anything — a corrupt
  # same-size copy must never be accepted as the off-box backup.
  rsha=$(ssh "${SSHK[@]}" -o BatchMode=yes -o ConnectTimeout=15 "$host" \
      "sha256sum '$OFF_DIR/$NAME' 2>/dev/null" | cut -d' ' -f1) || rsha=""
  if [ -n "$rsha" ]; then
    [ "$rsha" = "$SHA" ] || { log "offbox($label): sha256 mismatch ${rsha:0:12}… != ${SHA:0:12}…"; return 1; }
    log "offbox($label) OK $host:$OFF_DIR/$NAME size=$rsize mtime_age=${age}s sha256=${SHA:0:12}…"
  else
    log "WARN offbox($label): remote sha256sum unavailable; proved by size+mtime only"
    log "offbox($label) OK $host:$OFF_DIR/$NAME size=$rsize mtime_age=${age}s"
  fi
  ssh "${SSHK[@]}" -o BatchMode=yes -o ConnectTimeout=15 "$host" \
    "ls -1t $OFF_DIR/n8n-migration-*.tar.gz 2>/dev/null | tail -n +$((KEEP+1)) | while read -r r; do [ \"\$r\" = \"$OFF_DIR/$NAME\" ] || { rm -f -- \"\$r\" \"\$r.sha256\"; }; done" || true
  return 0
}

PUSHED=0
for try in 1 2 3; do
  push "$OFF_PRIMARY" "$OFF_KEY" duck && { PUSHED=1; break; }
  log "push $try/3 to duck failed; backoff $((try*10))s"; sleep $((try*10))
done
if [ "$PUSHED" = 0 ]; then
  log "WARN: duck unreachable after 3 tries; falling back to .92 (SAME SPb DC as .91 — NOT geo-separated)"
  for try in 1 2 3; do
    push "$OFF_FALLBACK" "" 92 && { PUSHED=1; break; }
    log "push $try/3 to .92 failed; backoff $((try*10))s"; sleep $((try*10))
  done
fi
[ "$PUSHED" = 1 ] || die "no off-box copy landed (duck and .92 both failed)"

# weekly full archive is transient: it exists to get off-box, not to sit on a 89%-full disk
if [ "$FULL" = 1 ]; then rm -f -- "$OUT" "$OUT.sha256"; log "full archive removed locally after verified push"; fi
log "done name=$NAME size_mb=$SIZE_MB"
