#!/bin/bash
# n8n-restore-drill.sh — доказать, что АРХИВ восстанавливается, а не только что
# он существует.
#
# Зачем отдельный скрипт, если n8n-backup-run.sh уже сверяет workflow_entity.
# Потому что он сверяет СНИМОК ДО упаковки. Между снимком и архивом есть tar и
# gzip, и ровно там живёт класс отказов «файл есть, открыть нельзя»: обрезанная
# загрузка, недописанный gzip, архив с нулём внутри. Проверка возраста и даже
# размера этого не ловит — её ловит только распаковка и запрос к базе.
# На .92 этот урок стоил двух обрезков по 11.7 МиБ, которые `backup:monitor`
# называл здоровым назначением, потому что смотрел на дату (H3181).
#
# Ничего не восстанавливает В ЖИВУЮ систему: распаковывает во временный каталог,
# спрашивает и убирает за собой. Живой базы касается только чтением.
# Секреты: credentials.json проверяется на НЕПУСТОТУ, содержимое не читается.
#
# MANAGED FILE — ставится scripts/server_guards_apply.sh --profile n8n.
set -uo pipefail

DEST='@@N8N_BACKUP_DIR@@'
LIVE_DB='@@N8N_DIR@@/storage/database.sqlite'
WORK=$(mktemp -d /tmp/n8n-restore-drill.XXXXXX)
trap 'rm -rf "$WORK"' EXIT
RC=0

ARCHIVE=$(ls -1t "$DEST"/n8n-*.tar.gz 2>/dev/null | head -1)
if [ -z "$ARCHIVE" ]; then
  echo "✖ нет ни одного архива в $DEST"
  exit 1
fi
echo "архив: $ARCHIVE ($(( $(stat -c '%s' "$ARCHIVE") / 1048576 )) МиБ, $(date -u -d "@$(stat -c '%Y' "$ARCHIVE")" '+%Y-%m-%dT%H:%M:%SZ'))"

if ! tar -xzf "$ARCHIVE" -C "$WORK" 2>/dev/null; then
  echo "✖ архив не распаковывается — это и есть класс отказа, ради которого дрилл существует"
  exit 1
fi
echo "ok  архив распаковался"

DB="$WORK/database.sqlite"
[ -f "$DB" ] || { echo "✖ в архиве нет database.sqlite"; exit 1; }

INTEG=$(sqlite3 -readonly "$DB" 'PRAGMA integrity_check;' 2>&1 | head -1)
[ "$INTEG" = ok ] && echo "ok  integrity_check=ok" || { echo "✖ integrity_check=$INTEG"; RC=1; }

for q in "SELECT count(*) FROM workflow_entity;|workflow_entity" \
         "SELECT count(*) FROM workflow_entity WHERE active=1;|active workflows" \
         "SELECT count(*) FROM credentials_entity;|credentials_entity"; do
  sql=${q%%|*}; label=${q#*|}
  r=$(sqlite3 -readonly "$DB"      "$sql" 2>/dev/null || echo ERR)
  l=$(sqlite3 -readonly "$LIVE_DB" "$sql" 2>/dev/null || echo ERR)
  if [ "$r" = "$l" ] && [ "$r" != ERR ]; then
    echo "ok  $label: восстановлено=$r, живое=$l"
  else
    echo "✖ $label РАСХОДИТСЯ: восстановлено=$r, живое=$l"
    RC=1
  fi
done

# Содержимое НЕ читается — только факт непустоты.
if [ -s "$WORK/credentials.json" ]; then
  echo "ok  credentials.json в архиве непуст ($(stat -c '%s' "$WORK/credentials.json") байт, содержимое не читалось)"
else
  echo "warn credentials.json отсутствует или пуст в архиве"
fi

[ "$RC" = 0 ] && echo "✔ дрилл восстановления пройден" || echo "✖ дрилл восстановления НЕ пройден"
exit $RC
