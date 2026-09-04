#!/usr/bin/env bash
#
# Build a self-contained PKCS#11 toolchain for storno-agent on macOS:
#   openssl (3.x LTS, keeps ENGINE support), libp11 (pkcs11 engine),
#   curl (linked against that OpenSSL) and OpenSC (pkcs11-tool, optional).
#
# Why: vendor token middleware is sometimes x86_64-only (Longmai mToken
# CryptoID standard driver) and a PKCS#11 module can only be loaded by tools of
# the same CPU architecture. Apple's curl/openssl have no engine support, and
# installing an Intel Homebrew needs sudo. This builds everything into
#   ~/.storno-agent/toolchain-<arch>
# with no sudo and no system changes; delete the folder to uninstall.
#
# Usage: build-pkcs11-toolchain.sh [x86_64|arm64]   (default: x86_64)
#
set -euo pipefail

ARCH="${1:-x86_64}"
case "$ARCH" in x86_64|arm64) ;; *) echo "arch must be x86_64 or arm64"; exit 1;; esac

OPENSSL_VER="${OPENSSL_VER:-3.5.8}"
LIBP11_VER="${LIBP11_VER:-0.4.20}"
CURL_VER="${CURL_VER:-8.22.0}"
OPENSC_VER="${OPENSC_VER:-0.27.1}"

PREFIX="${PREFIX:-$HOME/.storno-agent/toolchain-$ARCH}"
WORK="${WORK:-${TMPDIR:-/tmp}/storno-toolchain-$ARCH}"
JOBS="$(sysctl -n hw.ncpu 2>/dev/null || echo 4)"
HOST_TRIPLE="$ARCH-apple-darwin"

export CC="clang -arch $ARCH"
export CXX="clang++ -arch $ARCH"
export CFLAGS="-O2"
export LDFLAGS="-Wl,-rpath,$PREFIX/lib"
export PKG_CONFIG_PATH="$PREFIX/lib/pkgconfig"
export MACOSX_DEPLOYMENT_TARGET="${MACOSX_DEPLOYMENT_TARGET:-12.0}"

mkdir -p "$PREFIX" "$WORK"
cd "$WORK"

log() { echo "=== $*"; }
fetch() { # url file
  [ -f "$2" ] || curl -fsSL --retry 3 -o "$2" "$1"
}

# ── OpenSSL ─────────────────────────────────────────────────────────
if [ ! -x "$PREFIX/bin/openssl" ]; then
  log "openssl $OPENSSL_VER"
  fetch "https://github.com/openssl/openssl/releases/download/openssl-$OPENSSL_VER/openssl-$OPENSSL_VER.tar.gz" "openssl-$OPENSSL_VER.tar.gz"
  rm -rf "openssl-$OPENSSL_VER" && tar xzf "openssl-$OPENSSL_VER.tar.gz"
  pushd "openssl-$OPENSSL_VER" >/dev/null
  target="darwin64-$ARCH-cc"
  ./Configure "$target" --prefix="$PREFIX" --openssldir="$PREFIX/ssl" --libdir=lib \
    shared no-tests no-docs no-apps-dummy 2>/dev/null \
    || ./Configure "$target" --prefix="$PREFIX" --openssldir="$PREFIX/ssl" --libdir=lib shared no-tests no-docs
  make -j"$JOBS" >/dev/null
  make install_sw >/dev/null
  popd >/dev/null
else
  log "openssl already built"
fi

# ── libp11 (pkcs11 engine) ──────────────────────────────────────────
if [ ! -f "$PREFIX/lib/engines-3/pkcs11.dylib" ]; then
  log "libp11 $LIBP11_VER"
  fetch "https://github.com/OpenSC/libp11/releases/download/libp11-$LIBP11_VER/libp11-$LIBP11_VER.tar.gz" "libp11-$LIBP11_VER.tar.gz"
  rm -rf "libp11-$LIBP11_VER" && tar xzf "libp11-$LIBP11_VER.tar.gz"
  pushd "libp11-$LIBP11_VER" >/dev/null
  ./configure --host="$HOST_TRIPLE" --prefix="$PREFIX" \
    --with-enginesdir="$PREFIX/lib/engines-3" \
    --with-modulesdir="$PREFIX/lib/ossl-modules" \
    OPENSSL_CFLAGS="-I$PREFIX/include" OPENSSL_LIBS="-L$PREFIX/lib -lcrypto" >/dev/null
  make -j"$JOBS" >/dev/null
  make install >/dev/null
  popd >/dev/null
else
  log "libp11 already built"
fi

# ── curl ────────────────────────────────────────────────────────────
if [ ! -x "$PREFIX/bin/curl" ]; then
  log "curl $CURL_VER"
  fetch "https://curl.se/download/curl-$CURL_VER.tar.gz" "curl-$CURL_VER.tar.gz"
  rm -rf "curl-$CURL_VER" && tar xzf "curl-$CURL_VER.tar.gz"
  pushd "curl-$CURL_VER" >/dev/null
  ./configure --host="$HOST_TRIPLE" --prefix="$PREFIX" \
    --with-openssl="$PREFIX" --with-ca-bundle=/etc/ssl/cert.pem \
    --without-libpsl --without-brotli --without-zstd --without-nghttp2 --without-libidn2 \
    --disable-ldap --disable-ldaps --disable-manual --disable-docs \
    --enable-static=no >/dev/null
  make -j"$JOBS" >/dev/null
  make install >/dev/null
  popd >/dev/null
else
  log "curl already built"
fi

# ── OpenSC (pkcs11-tool), optional ──────────────────────────────────
if [ ! -x "$PREFIX/bin/pkcs11-tool" ]; then
  log "opensc $OPENSC_VER (optional)"
  if fetch "https://github.com/OpenSC/OpenSC/releases/download/$OPENSC_VER/opensc-$OPENSC_VER.tar.gz" "opensc-$OPENSC_VER.tar.gz"; then
    rm -rf "opensc-$OPENSC_VER" && tar xzf "opensc-$OPENSC_VER.tar.gz"
    pushd "opensc-$OPENSC_VER" >/dev/null
    if ./configure --host="$HOST_TRIPLE" --prefix="$PREFIX" --sysconfdir="$PREFIX/etc" \
         --enable-pcsc --disable-doc --disable-man --disable-notify --disable-tests \
         OPENSSL_CFLAGS="-I$PREFIX/include" OPENSSL_LIBS="-L$PREFIX/lib -lcrypto -lssl" >/dev/null \
       && make -j"$JOBS" >/dev/null && make install >/dev/null; then
      :
    else
      echo "opensc build failed (pkcs11-tool will be unavailable; certificate listing falls back to the placeholder)"
    fi
    popd >/dev/null
  fi
else
  log "opensc already built"
fi

log "verify"
file "$PREFIX/bin/curl" | sed 's/^/  /'
"$PREFIX/bin/curl" --version | head -1 | sed 's/^/  /'
OPENSSL_ENGINES="$PREFIX/lib/engines-3" "$PREFIX/bin/openssl" engine -t pkcs11 | sed 's/^/  /'
[ -x "$PREFIX/bin/pkcs11-tool" ] && echo "  pkcs11-tool: $PREFIX/bin/pkcs11-tool"
log "DONE $PREFIX"
