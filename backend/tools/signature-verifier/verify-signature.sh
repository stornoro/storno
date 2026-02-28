#!/usr/bin/env bash
#
# Verify an ANAF detached XML signature against an invoice XML.
# Usage: verify-signature.sh <invoice.xml> <signature.xml>
# Outputs "VALID" or "INVALID" on line 1, full message on line 2.
#
set -euo pipefail

if [ $# -lt 2 ]; then
    echo "Usage: $0 <invoice.xml> <signature.xml>" >&2
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

CP="$SCRIPT_DIR/verifsignature.jar:$SCRIPT_DIR/"

# Required for BouncyCastle / Santurio on Java 17+
JAVA_OPTS=(
    --add-opens java.base/java.lang=ALL-UNNAMED
    --add-opens java.base/java.lang.reflect=ALL-UNNAMED
    --add-opens java.base/java.io=ALL-UNNAMED
    --add-opens java.base/java.util=ALL-UNNAMED
    --add-opens java.base/java.security=ALL-UNNAMED
    --add-opens java.xml/com.sun.org.apache.xml.internal.security=ALL-UNNAMED
    --add-opens java.xml/com.sun.org.apache.xml.internal.security.utils=ALL-UNNAMED
)

exec "$JAVA" "${JAVA_OPTS[@]}" -cp "$CP" -Djava.awt.headless=true VerifyCli "$1" "$2"
