#!/bin/bash
# n8n-backup-run.sh — ночной дамп данных n8n с .91: база, учётки, рабочие потоки.
#
# Замер 25-08-2026 (H3182, волна 2), с которого этот файл начался: у .91 НЕ БЫЛО
# резервной копии собственных данных. Ни одной. Restic-репозиторий
# /srv/restic/systema, живущий на этой же машине, — ПРИЁМНИК для .92 (снимки
# хоста samskrtam150, теги systema/samudra), а не копия .91. В /opt/n8n/backups
# лежали разовые ручные дампы: новейший 24-08 15:26, 1.2 ГиБ разнородных файлов,
# расписания нет. Живая база — 166 МиБ.
#
# ЧТО ЭТОТ СКРИПТ ДЕЛАЕТ С ЖИВОЙ БАЗОЙ: только читает. `sqlite3 -readonly`
# открывает файл в режиме только для чтения, `.backup` снимает согласованный
# снимок без длинной блокировки. Ограда волны (PLAN §4, D19) запрещает мутировать
# базу n8n — здесь её нет даже в возможности: соединение read-only.
#
# СЕКРЕТЫ: credentials.json копируется как НЕПРОЗРАЧНЫЙ БЛОБ. Его содержимое
# никогда не читается, не печатается, не логируется и не попадает в вывод. Тот
# же запрет, что в PLAN §4: агент не читает значения секретов.
#
# MANAGED FILE — ставится scripts/server_guards_apply.sh --profile n8n из
# scripts/server_guards_n8n/sbin/n8n-backup-run.sh. Править копию в репозитории.
set -euo pipefail

N8N_DIR='@@N8N_DIR@@'
DEST='@@N8N_BACKUP_DIR@@'
KEEP='@@N8N_BACKUP_KEEP@@'
MIN_MB='@@N8N_BACKUP_MIN_MB@@'
OFFSITE_REPO='@@N8N_BACKUP_OFFSITE_REPO@@'
OFFSITE_PASS='@@N8N_BACKUP_OFFSITE_PASSFILE@@'
LIVE_DB="$N8N_DIR/storage/database.sqlite"

STAMP=$(date -u '+%Y%m%dT%H%M%SZ')
WORK="$DEST/.work-$STAMP"
LOG=/var/log/n8n-backup.log

say() { printf '%s %s\n' "$(date -u '+%Y-%m-%dT%H:%M:%SZ')" "$*" | tee -a "$LOG"; }
die() { say "FAIL $*"; rm -rf "$WORK"; exit 1; }

mkdir -p "$DEST" "$WORK"
chmod 700 "$DEST"

# ── 1. База: согласованный снимок через read-only соединение ────────────────
[ -f "$LIVE_DB" ] || die "нет живой базы $LIVE_DB"
sqlite3 -readonly "$LIVE_DB" ".backup '$WORK/database.sqlite'" \
  || die "sqlite3 .backup не снял снимок"

# ── 2. Проверка ЦЕЛОСТНОСТИ снимка, а не только его существования ───────────
# Возраст без содержимого — зелёная лампочка над пустым сейфом (тот же урок, что
# дали два обрезка по 11.7 МиБ на yandex_disk у .92, H3181).
INTEG=$(sqlite3 -readonly "$WORK/database.sqlite" 'PRAGMA integrity_check;' 2>&1 | head -1)
[ "$INTEG" = "ok" ] || die "снимок не прошёл integrity_check: $INTEG"

LIVE_WF=$(sqlite3 -readonly "$LIVE_DB" 'SELECT count(*) FROM workflow_entity;' 2>/dev/null || echo -1)
DUMP_WF=$(sqlite3 -readonly "$WORK/database.sqlite" 'SELECT count(*) FROM workflow_entity;' 2>/dev/null || echo -2)
[ "$LIVE_WF" = "$DUMP_WF" ] || die "workflow_entity расходится: живая=$LIVE_WF дамп=$DUMP_WF"
say "ok  снимок базы: integrity_check=ok, workflow_entity=$DUMP_WF (совпало с живой)"

# ── 3. Учётки и потоки — как непрозрачные блобы ─────────────────────────────
for blob in credentials.json workflows.json; do
  [ -f "$N8N_DIR/$blob" ] && cp -p "$N8N_DIR/$blob" "$WORK/$blob"
done
[ -f "$N8N_DIR/docker-compose.yml" ] && cp -p "$N8N_DIR/docker-compose.yml" "$WORK/docker-compose.yml"
[ -d "$N8N_DIR/caddy" ] && cp -rp "$N8N_DIR/caddy" "$WORK/caddy"

# ── 4. Один архив ───────────────────────────────────────────────────────────
ARCHIVE="$DEST/n8n-$STAMP.tar.gz"
tar -czf "$ARCHIVE" -C "$WORK" . || die "tar не собрал архив"
chmod 600 "$ARCHIVE"
rm -rf "$WORK"

SIZE_MB=$(( $(stat -c '%s' "$ARCHIVE") / 1048576 ))
[ "$SIZE_MB" -ge "$MIN_MB" ] || die "архив $SIZE_MB МиБ меньше пола правдоподобия $MIN_MB МиБ — похоже на обрезок"
say "ok  архив $ARCHIVE ($SIZE_MB МиБ)"

# ── 5. Off-site ─────────────────────────────────────────────────────────────
# ЧЕСТНО: пока OFFSITE_REPO пуст, копия ЛОКАЛЬНАЯ и делит судьбу машины, которую
# защищает. Это НЕ резервная копия в том смысле, ради которого волна затевалась,
# и скрипт обязан говорить это вслух, а не молчать. Почему не подключено само:
# у .91 нет широкого SSH-пути к .92 — единственное существующее ребро доверия
# (systema-drill-tunnel, H3178) намеренно УЗКОЕ, ключ ограничен пробросом порта
# MariaDB. Расширять намеренно суженный ключ — решение человека, не агента.
if [ -n "$OFFSITE_REPO" ]; then
  restic -r "$OFFSITE_REPO" --password-file "$OFFSITE_PASS" backup "$ARCHIVE" --tag n8n \
    && say "ok  off-site: $OFFSITE_REPO" \
    || die "off-site push не прошёл: $OFFSITE_REPO"
  # Ретенция НА УДАЛЁННОЙ стороне. Без неё репозиторий растёт вечно и однажды
  # заполнит .92 — то есть бэкап .91 уронил бы прод samskrte.ru. --prune делает
  # место реально освобождённым, а не просто помеченным.
  restic -r "$OFFSITE_REPO" --password-file "$OFFSITE_PASS" \
      forget --tag n8n --keep-daily '@@N8N_OFFSITE_KEEP_DAILY@@' --prune >/dev/null 2>&1 \
    && say "ok  off-site ретенция: keep-daily @@N8N_OFFSITE_KEEP_DAILY@@" \
    || say "WARN off-site forget/prune не прошёл — место на .92 не освобождено"
else
  say "WARN off-site назначение НЕ задано (N8N_BACKUP_OFFSITE_REPO пуст) — копия локальная и делит судьбу машины"
fi

# ── 6. Ротация ──────────────────────────────────────────────────────────────
ls -1t "$DEST"/n8n-*.tar.gz 2>/dev/null | tail -n +$((KEEP + 1)) | while read -r old; do
  rm -f "$old" && say "ok  ротация: удалён $old"
done

say "ok  готово"
