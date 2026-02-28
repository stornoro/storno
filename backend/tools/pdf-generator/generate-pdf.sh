#!/usr/bin/env bash
#
# Generate a PDF from a UBL XML invoice using GenFactura/JasperReports.
# Usage: generate-pdf.sh <input.xml>
# Outputs the generated PDF path to stdout on success.
#
set -euo pipefail

if [ $# -lt 1 ]; then
    echo "Usage: $0 <input.xml>" >&2
    exit 1
fi

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"

# Locate Java (check well-known paths before system PATH to avoid macOS stub)
if [ -n "${JAVA_HOME:-}" ] && [ -x "$JAVA_HOME/bin/java" ]; then
    JAVA="$JAVA_HOME/bin/java"
elif [ -x "/opt/homebrew/opt/openjdk@17/bin/java" ]; then
    JAVA="/opt/homebrew/opt/openjdk@17/bin/java"
elif [ -x "/opt/homebrew/opt/openjdk/bin/java" ]; then
    JAVA="/opt/homebrew/opt/openjdk/bin/java"
elif [ -x "/usr/lib/jvm/java-17-openjdk-amd64/bin/java" ]; then
    JAVA="/usr/lib/jvm/java-17-openjdk-amd64/bin/java"
elif java -version &>/dev/null 2>&1; then
    JAVA="java"
else
    echo "Error: Java not found. Install Java 8+ or set JAVA_HOME." >&2
    exit 1
fi

CP="$SCRIPT_DIR/dist/generareFactura.jar:$SCRIPT_DIR/dist/lib/*:$SCRIPT_DIR/dist/"

# Required for Groovy 2.x / JasperReports 5.x on Java 17+
JAVA_OPTS=(
    --add-opens java.base/java.lang=ALL-UNNAMED
    --add-opens java.base/java.lang.reflect=ALL-UNNAMED
    --add-opens java.base/java.io=ALL-UNNAMED
    --add-opens java.base/java.util=ALL-UNNAMED
    --add-opens java.base/sun.nio.ch=ALL-UNNAMED
    --add-opens java.base/java.net=ALL-UNNAMED
    --add-opens java.desktop/javax.swing.text=ALL-UNNAMED
)

exec "$JAVA" "${JAVA_OPTS[@]}" -cp "$CP" -Djava.awt.headless=true PdfCli "$1"
