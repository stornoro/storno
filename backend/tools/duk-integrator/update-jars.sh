#!/bin/bash
# Download/update DUKIntegrator JARs from ANAF's official distribution.
#
# Usage: ./update-jars.sh [--type D394] [--type D300] ...
#   Without --type flags, downloads the core framework + all known types.
#
# Run manually when ANAF publishes new versions.

set -e

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
JARS_DIR="$SCRIPT_DIR"

# ANAF's versiuni.xml lists current JAR versions
VERSIUNI_URL="http://static.anaf.ro/static/10/Anaf/update5/versiuni.xml"
DUK_BASE_URL="http://static.anaf.ro/static/10/Anaf/Declaratii_R/AplicatiiDUK"

# Core framework JARs (always needed)
CORE_JARS=(
    "DUKIntegrator.jar"
    "DecValidation.jar"
    "Validator.jar"
    "DecPdf.jar"
    "iText-5.0.4.jar"
    "bcprov-jdk15-145.jar"
    "bcmail-jdk15-145.jar"
)

# Per-type JARs: type -> (ValidatorJar PdfJar)
declare -A TYPE_JARS
TYPE_JARS[D394]="D394Validator.jar D394Pdf.jar"
TYPE_JARS[D300]="D300Validator.jar D300Pdf.jar"
TYPE_JARS[D100]="D100Validator.jar D100Pdf.jar"
TYPE_JARS[D112]="D112Validator.jar D112Pdf.jar"
TYPE_JARS[D390]="D390Validator.jar D390Pdf.jar"
TYPE_JARS[D101]="D101Validator.jar D101Pdf.jar"
TYPE_JARS[D120]="D120Validator.jar D120Pdf.jar"
TYPE_JARS[D205]="D205Validator.jar D205Pdf.jar"

# Parse arguments
TYPES=()
while [[ $# -gt 0 ]]; do
    case "$1" in
        --type)
            shift
            TYPES+=("$1")
            shift
            ;;
        *)
            echo "Unknown argument: $1"
            exit 1
            ;;
    esac
done

# Default: all types
if [ ${#TYPES[@]} -eq 0 ]; then
    TYPES=("${!TYPE_JARS[@]}")
fi

echo "=== DUKIntegrator JAR Updater ==="
echo "Target directory: $JARS_DIR"
echo ""

# Download core JARs
echo "── Downloading core framework JARs ──"
for jar in "${CORE_JARS[@]}"; do
    echo -n "  $jar ... "
    if curl -sfL -o "$JARS_DIR/$jar" "$DUK_BASE_URL/$jar" 2>/dev/null; then
        echo "OK ($(du -h "$JARS_DIR/$jar" | cut -f1 | xargs))"
    else
        echo "FAILED (may not exist at $DUK_BASE_URL/$jar)"
    fi
done

# Download per-type JARs
echo ""
echo "── Downloading declaration type JARs ──"
for type in "${TYPES[@]}"; do
    jars="${TYPE_JARS[$type]}"
    if [ -z "$jars" ]; then
        echo "  WARNING: Unknown type $type"
        continue
    fi
    echo "  Type: $type"
    for jar in $jars; do
        echo -n "    $jar ... "
        if curl -sfL -o "$JARS_DIR/$jar" "$DUK_BASE_URL/$jar" 2>/dev/null; then
            echo "OK ($(du -h "$JARS_DIR/$jar" | cut -f1 | xargs))"
        else
            echo "FAILED"
        fi
    done
done

echo ""
echo "── Checking versiuni.xml for version info ──"
VERSIUNI=$(curl -sf "$VERSIUNI_URL" 2>/dev/null || echo "")
if [ -n "$VERSIUNI" ]; then
    echo "$VERSIUNI" > "$JARS_DIR/versiuni.xml"
    echo "  Saved to versiuni.xml"
else
    echo "  WARNING: Could not fetch versiuni.xml"
fi

echo ""
echo "Done. JARs in $JARS_DIR:"
ls -lh "$JARS_DIR"/*.jar 2>/dev/null || echo "  No JARs found."
