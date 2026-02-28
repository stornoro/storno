#!/bin/bash
# Start the unified Java services (validator + PDF + signature) on a single port.
#
# Usage: ./start-java-services.sh [port]
# Default port: 8082

set -e

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
PROJECT_DIR="$(cd "$SCRIPT_DIR/../.." && pwd)"
PORT="${1:-8082}"
PID_FILE="$SCRIPT_DIR/java-services.pid"

# Locate Java
if [ -n "${JAVA_HOME:-}" ] && [ -x "$JAVA_HOME/bin/java" ]; then
    JAVA="$JAVA_HOME/bin/java"
elif [ -x "/opt/homebrew/opt/openjdk@17/bin/java" ]; then
    JAVA="/opt/homebrew/opt/openjdk@17/bin/java"
elif [ -x "/opt/homebrew/opt/openjdk/bin/java" ]; then
    JAVA="/opt/homebrew/opt/openjdk/bin/java"
elif [ -x "/usr/bin/java" ]; then
    JAVA="/usr/bin/java"
elif java -version &>/dev/null 2>&1; then
    JAVA="java"
else
    echo "ERROR: Java not found. Install Java 17+"
    exit 1
fi

# Build classpath
CP="$PROJECT_DIR/resources/validator/ROeFacturaValidator.jar"
CP="$CP:$PROJECT_DIR/tools/pdf-generator/dist/generareFactura.jar"
CP="$CP:$PROJECT_DIR/tools/pdf-generator/dist/lib/*"
CP="$CP:$PROJECT_DIR/tools/pdf-generator/dist/"
CP="$CP:$PROJECT_DIR/tools/signature-verifier/verifsignature.jar"
CP="$CP:$SCRIPT_DIR"

# Compile if needed
if [ ! -f "$SCRIPT_DIR/JavaServiceServer.class" ] || \
   [ "$SCRIPT_DIR/JavaServiceServer.java" -nt "$SCRIPT_DIR/JavaServiceServer.class" ]; then
    echo "Compiling JavaServiceServer.java..."
    "$JAVA"c -cp "$CP" "$SCRIPT_DIR/JavaServiceServer.java"
fi

# Stop existing instance
if [ -f "$PID_FILE" ]; then
    OLD_PID=$(cat "$PID_FILE")
    if kill -0 "$OLD_PID" 2>/dev/null; then
        echo "Stopping existing service (PID $OLD_PID)..."
        kill "$OLD_PID"
        sleep 1
    fi
    rm -f "$PID_FILE"
fi

# JVM flags (union of all three services' requirements)
JAVA_OPTS=(
    --add-opens java.base/java.lang=ALL-UNNAMED
    --add-opens java.base/java.lang.reflect=ALL-UNNAMED
    --add-opens java.base/java.io=ALL-UNNAMED
    --add-opens java.base/java.util=ALL-UNNAMED
    --add-opens java.base/sun.nio.ch=ALL-UNNAMED
    --add-opens java.base/java.net=ALL-UNNAMED
    --add-opens java.base/java.security=ALL-UNNAMED
    --add-opens java.xml/com.sun.org.apache.xml.internal.security=ALL-UNNAMED
    --add-opens java.xml/com.sun.org.apache.xml.internal.security.utils=ALL-UNNAMED
)

cd "$PROJECT_DIR"
"$JAVA" "${JAVA_OPTS[@]}" \
    -Djava.awt.headless=true \
    -Dschema.dir="$PROJECT_DIR/resources" \
    -Xms128m -Xmx512m \
    -cp "$CP" \
    JavaServiceServer "$PORT" &

JAVA_PID=$!
echo "$JAVA_PID" > "$PID_FILE"

# Wait for startup
for i in $(seq 1 15); do
    if curl -s "http://127.0.0.1:$PORT/health" > /dev/null 2>&1; then
        echo "Java services started (PID $JAVA_PID, port $PORT)"
        exit 0
    fi
    sleep 0.5
done

echo "ERROR: Java services failed to start"
kill "$JAVA_PID" 2>/dev/null
rm -f "$PID_FILE"
exit 1
