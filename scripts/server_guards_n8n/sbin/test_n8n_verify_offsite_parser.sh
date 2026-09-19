#!/bin/bash
# test_n8n_verify_offsite_parser.sh — регрессия H5080-F1 (19-09-2026).
#
# Баг: verify брал «новейший» off-site снимок как ПЕРВЫЙ элемент JSON:
#   restic … snapshots --tag n8n --latest 1 --json | grep -o '"time"…' | head -1
# Но `--latest 1` группирует снимки по (host, paths), а путь у каждого ночного
# архива свой (/var/backups/n8n/n8n-<ts>.tar.gz) — то есть КАЖДЫЙ снимок
# образует свою группу, и JSON содержит их все. `head -1` отдавал самый СТАРЫЙ
# (25-08) и рисовал ложный critical «новейший off-site снимок 595 ч назад» при
# 26 живых ежедневных копиях. Парсер обязан брать МАКСИМУМ времени.
#
# Тест извлекает конвейер ИЗ САМОГО verify-скрипта (не дублирует копию, которая
# разошлась бы с оригиналом) и гоняет его на фикстуре: первый элемент массива —
# старый, новейший — в середине/конце, но не первый.
set -uo pipefail

HERE=$(cd "$(dirname "$0")" && pwd)
VERIFY="$HERE/../../server_guards_n8n_verify.sh"

line=$(grep -m1 -F "grep -o '\"time\"" "$VERIFY" || true)
if [ -z "$line" ]; then
  echo "FAIL: в $VERIFY не найден парсер времени снимка (grep -o '\"time\"…')"
  exit 1
fi
parser="${line#*| }"   # срезаем ведущие пробелы и первый конвейерный разделитель
parser="${parser%)}"   # срезаем закрывающую скобку подстановки, если есть

FIXTURE='[{"time":"2026-08-25T14:26:51.8Z","tags":["n8n"]},{"time":"2026-09-14T03:22:00.1Z"},{"time":"2026-09-19T03:17:58.2Z"},{"time":"2026-09-01T00:00:00Z"}]'
got=$(printf '%s' "$FIXTURE" | bash -c "$parser")
want="2026-09-19T03:17:58.2Z"
if [ "$got" != "$want" ]; then
  echo "FAIL: парсер вернул '$got', ожидался новейший '$want'"
  echo "      конвейер из verify-скрипта: $parser"
  exit 1
fi

# Негативный контроль: пустой JSON — пустой результат, а не мусор.
got=$(printf '%s' '[]' | bash -c "$parser")
if [ -n "$got" ]; then
  echo "FAIL: на пустом JSON получено '$got'"
  exit 1
fi

echo "ok: парсер новейшего off-site снимка берёт максимум времени (H5080-F1)"
