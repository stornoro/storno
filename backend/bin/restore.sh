#!/usr/bin/env bash
#
# System restore wrapper script for Storno.ro.
#
# Usage:
#   bin/restore.sh /path/to/backup.zip                   # Interactive restore
#   bin/restore.sh /path/to/backup.zip --force            # Skip confirmation
#   bin/restore.sh /path/to/backup.zip --dry-run          # Preview only
#   bin/restore.sh /path/to/backup.zip --no-files         # Database only
#
# CAUTION: This will REPLACE your current database and files!
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

echo -e "${YELLOW}Storno.ro System Restore${NC}"
echo "─────────────────────────────"
echo "Project: ${PROJECT_DIR}"
echo "Environment: ${APP_ENV}"
echo ""

if [ $# -lt 1 ]; then
    echo -e "${RED}Error: Backup file path is required.${NC}"
    echo ""
    echo "Usage: $0 <backup-file.zip> [options]"
    echo ""
    echo "Options:"
    echo "  --dry-run     Preview backup contents without restoring"
    echo "  --force       Skip confirmation prompt"
    echo "  --no-files    Skip restoring files (database only)"
    echo "  --no-db       Skip restoring database (files only)"
    exit 1
fi

BACKUP_FILE="$1"

if [ ! -f "$BACKUP_FILE" ]; then
    echo -e "${RED}Error: File not found: ${BACKUP_FILE}${NC}"
    exit 1
fi

# Detect PHP binary
PHP_BIN="${PHP_BIN:-$(which php 2>/dev/null || echo 'php')}"

# Run the Symfony command
"${PHP_BIN}" "${PROJECT_DIR}/bin/console" app:backup:restore-system "$@" --env="${APP_ENV}"

EXIT_CODE=$?

if [ $EXIT_CODE -eq 0 ]; then
    echo ""
    echo -e "${GREEN}Restore completed successfully.${NC}"
    echo -e "${YELLOW}Remember to clear cache: php bin/console cache:clear${NC}"
else
    echo ""
    echo -e "${RED}Restore failed (exit code: ${EXIT_CODE}).${NC}"
fi

exit $EXIT_CODE
