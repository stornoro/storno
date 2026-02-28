#!/usr/bin/env bash
#
# System backup wrapper script for Storno.ro.
#
# Usage:
#   bin/backup.sh                          # Interactive backup to var/backups/
#   bin/backup.sh --output /path/to.zip    # Custom output path
#   bin/backup.sh --no-files               # Database only
#   bin/backup.sh --dry-run                # Preview only
#
# Cron example (daily backup at 2 AM, keep last 30 days):
#   0 2 * * * /path/to/backend/bin/backup.sh --output /path/to/backups/system-backup-$(date +\%Y-\%m-\%d).zip 2>&1 | logger -t storno-backup
#

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_DIR="$(dirname "$SCRIPT_DIR")"

# Default environment
APP_ENV="${APP_ENV:-prod}"

# Colors for terminal output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

echo -e "${GREEN}Storno.ro System Backup${NC}"
echo "─────────────────────────"
echo "Project: ${PROJECT_DIR}"
echo "Environment: ${APP_ENV}"
echo ""

# Detect PHP binary
PHP_BIN="${PHP_BIN:-$(which php 2>/dev/null || echo 'php')}"

# Run the Symfony command
"${PHP_BIN}" "${PROJECT_DIR}/bin/console" app:backup:system "$@" --env="${APP_ENV}"

EXIT_CODE=$?

if [ $EXIT_CODE -eq 0 ]; then
    echo ""
    echo -e "${GREEN}Backup completed successfully.${NC}"
else
    echo ""
    echo -e "${RED}Backup failed (exit code: ${EXIT_CODE}).${NC}"
fi

exit $EXIT_CODE
