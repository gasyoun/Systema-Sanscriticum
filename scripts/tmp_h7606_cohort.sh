#!/bin/bash
# H7606 cohort runner: 8 red filter classes, one pass, failure summaries to log.
set -u
cd "$(dirname "$0")/.." || exit 1
LOG=tests/_h7606_cohort.log
: > "$LOG"

run() {
  local label="$1"; shift
  echo "=== $label: $*" >> "$LOG"
  php -d memory_limit=2G vendor/bin/phpunit "$@" --testdox 2>&1 \
    | grep -E "^(Tests:|OK|FAILURES|ERRORS|Time)|✕|- (it|test)|Failed asserting|Expected|received|Exception|RuntimeException|Error:" \
    >> "$LOG"
  echo "" >> "$LOG"
}

run G1_RevenueSchedule   --filter=RevenueScheduleTest
run G3_ReferralProgram   --filter=ReferralProgram
run G4_Srs               --filter=Srs
run G42_Marathon         --filter=Marathon
run G55_Segment          --filter=SegmentTest
run G56_HybridPhase      --filter=HybridPhase
run G57_StudentUnblock   --filter=StudentUnblockAccessTest
run G67_CourseDebt       --filter=CourseDebtChatRemoval

grep -cE "^=== " "$LOG"
