#!/bin/sh
# Download / refresh the ANAF DUKIntegrator toolchain from ANAF's own manifest.
#
#   ./update-jars.sh                 core jars + the default form set (see DEFAULT_TYPES)
#   ./update-jars.sh D212 C168       core jars + only these forms
#   ./update-jars.sh --all           core jars + every form listed in versiuni.xml (~170)
#   ./update-jars.sh --check         print which local jars are outdated, download nothing
#
# POSIX sh on purpose: runs unchanged in the Alpine build stage, in the
# production container (called by `app:anaf:update-validators`) and on macOS.
# Every URL comes from http://static.anaf.ro/static/10/Anaf/update5/versiuni.xml,
# the same manifest DUKIntegrator's own updater reads, so the jars are exactly
# the ones ANAF's portal validates with.

set -eu

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
JARS_DIR="${DUK_JARS_DIR:-$SCRIPT_DIR}"
VERSIUNI_URL="${DUK_VERSIUNI_URL:-http://static.anaf.ro/static/10/Anaf/update5/versiuni.xml}"
# Forms Storno can generate or validate out of the box. Keep in sync with App\Enum\DeclarationType
# plus the forms handled by the declaration assistant (C168, D177, D700).
DEFAULT_TYPES="${DUK_TYPES:-D100 D101 D106 D112 D120 D130 D177 D180 D205 D208 D212 D300 D301 D311 D390 D392 D393 D394 D700 C168}"

MODE="types"
TYPES=""
for arg in "$@"; do
    case "$arg" in
        --all) MODE="all" ;;
        --check) MODE="check" ;;
        --type) ;;                       # legacy flag, the value that follows is taken as a type
        -*) echo "Unknown argument: $arg" >&2; exit 1 ;;
        *) TYPES="$TYPES $arg" ;;
    esac
done
[ -n "$(echo "$TYPES" | tr -d ' ')" ] || TYPES="$DEFAULT_TYPES"

mkdir -p "$JARS_DIR"
MANIFEST="$JARS_DIR/versiuni.xml"
TMP_MANIFEST="$MANIFEST.tmp"
if ! curl -sfL --max-time 60 -o "$TMP_MANIFEST" "$VERSIUNI_URL"; then
    echo "ERROR: cannot fetch $VERSIUNI_URL" >&2
    rm -f "$TMP_MANIFEST"
    exit 1
fi
mv "$TMP_MANIFEST" "$MANIFEST"

# versiuni.xml is one element per line in practice, but do not rely on it:
# flatten, then pull the URLs out of the <integrator> block and each form block.
FLAT="$(tr -d '\r\n\t' < "$MANIFEST")"

core_urls() {
    echo "$FLAT" | sed -n 's|.*<integrator>\(.*\)</integrator>.*|\1|p' \
        | grep -o 'http[^<]*\.jar' | sort -u
}

form_urls() {   # $1 = form code
    echo "$FLAT" | sed -n "s|.*<$1>\(.*\)</$1>.*|\1|p" \
        | grep -o '<[JP]URL>[^<]*' | sed 's/<[JP]URL>//'
}

all_forms() {
    echo "$FLAT" | sed -n 's|.*<declaratii>\(.*\)</declaratii>.*|\1|p' \
        | grep -o '<[A-Z][A-Z0-9]*>' | tr -d '<>' | grep -v '^JURL$\|^PURL$\|^DURL$\|^versiuneJ$\|^versiuneP$' | sort -u
}

fetch() {       # $1 = url ; downloads only when the remote file differs in size or is missing locally
    name="$(basename "$1")"
    dest="$JARS_DIR/$name"
    remote_size="$(curl -sIL --max-time 30 "$1" | tr -d '\r' | awk 'tolower($1)=="content-length:"{s=$2} END{print s+0}')"
    local_size=0
    [ -f "$dest" ] && local_size="$(wc -c < "$dest" | tr -d ' ')"
    if [ "$remote_size" -gt 0 ] && [ "$remote_size" -eq "$local_size" ]; then
        printf '  %-28s up to date\n' "$name"
        return 0
    fi
    if [ "$MODE" = "check" ]; then
        printf '  %-28s OUTDATED (local %s, remote %s)\n' "$name" "$local_size" "$remote_size"
        CHANGED=1
        return 0
    fi
    if curl -sfL --max-time 120 -o "$dest.tmp" "$1"; then
        mv "$dest.tmp" "$dest"
        printf '  %-28s downloaded (%s bytes)\n' "$name" "$(wc -c < "$dest" | tr -d ' ')"
        CHANGED=1
    else
        rm -f "$dest.tmp"
        printf '  %-28s FAILED (%s)\n' "$name" "$1" >&2
        FAILED=1
    fi
}

CHANGED=0
FAILED=0
echo "== DUKIntegrator core ($VERSIUNI_URL)"
for u in $(core_urls); do fetch "$u"; done

[ "$MODE" = "all" ] && TYPES="$(all_forms | tr '\n' ' ')"
echo "== Forms:$TYPES"
for t in $TYPES; do
    urls="$(form_urls "$t")"
    if [ -z "$urls" ]; then
        echo "  $t: not in versiuni.xml" >&2
        FAILED=1
        continue
    fi
    for u in $urls; do fetch "$u"; done
done

# DUKIntegrator caches the versions it knows in this file; drop it so the new jars are used.
rm -f "$JARS_DIR/config/versiuniCurente.txt" 2>/dev/null || true

if [ "$MODE" = "check" ]; then
    [ "$CHANGED" -eq 1 ] && exit 3 || exit 0
fi
[ "$FAILED" -eq 0 ] || exit 2
[ "$CHANGED" -eq 1 ] && exit 3 || exit 0
