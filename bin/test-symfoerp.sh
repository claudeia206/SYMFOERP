#!/usr/bin/env bash
set -uo pipefail
ROOT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT_DIR"
TARGET_CONTAINER="symfoerp-symfony"
TARGET_IMAGE=""
while [[ "$#" -gt 0 ]]; do
  case "$1" in
    --container) TARGET_CONTAINER="${2:?Missing container name}"; shift 2 ;;
    --image) TARGET_IMAGE="${2:?Missing image name}"; shift 2 ;;
    *) echo "Usage: bash bin/test-symfoerp.sh [--container NAME | --image IMAGE]" >&2; exit 2 ;;
  esac
done
command -v docker >/dev/null 2>&1 || { echo "Docker is required." >&2; exit 2; }
REPORT_DIR="$ROOT_DIR/var/test-reports"
mkdir -p "$REPORT_DIR"
SUMMARY="$REPORT_DIR/test-report.md"
LOG_DIR="$REPORT_DIR/logs"
mkdir -p "$LOG_DIR"
PASS_COUNT=0
FAIL_COUNT=0
TOTAL=0
{
  echo "# SYMFOERP — Rapport des tests"
  echo
  echo "- Date : $(date -u '+%Y-%m-%d %H:%M:%S UTC')"
  echo
  echo "| Test | Résultat |"
  echo "|---|---:|"
} > "$SUMMARY"
shopt -s nullglob
TEST_FILES=(tests/*_smoke.php)
if [[ "${#TEST_FILES[@]}" -eq 0 ]]; then echo "No smoke tests found." >&2; exit 2; fi
for path in "${TEST_FILES[@]}"; do
  name="$(basename "$path" .php)"
  log="$LOG_DIR/$name.log"
  TOTAL=$((TOTAL + 1))
  echo "==> $name"
  status=0
  if [[ -n "$TARGET_IMAGE" ]]; then
    docker run --rm "$TARGET_IMAGE" php "/app/tests/$name.php" >"$log" 2>&1 || status=$?
  else
    docker exec --workdir /app "$TARGET_CONTAINER" php "/app/tests/$name.php" >"$log" 2>&1 || status=$?
  fi
  if [[ "$status" -eq 0 ]]; then
    echo "PASS"
    echo "| `$name` | PASS |" >> "$SUMMARY"
    PASS_COUNT=$((PASS_COUNT + 1))
  else
    echo "FAIL — $log"
    cat "$log"
    echo "| `$name` | FAIL |" >> "$SUMMARY"
    FAIL_COUNT=$((FAIL_COUNT + 1))
  fi
done
{
  echo
  echo "**Bilan : $PASS_COUNT/$TOTAL réussis — $FAIL_COUNT échec(s).**"
  echo
  echo "Journaux détaillés : `var/test-reports/logs/`"
} >> "$SUMMARY"
echo
echo "Rapport : $SUMMARY"
echo "Journaux : $LOG_DIR/"
echo "Bilan : $PASS_COUNT/$TOTAL réussis — $FAIL_COUNT échec(s)"
[[ "$FAIL_COUNT" -eq 0 ]]
