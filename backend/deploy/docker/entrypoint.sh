#!/bin/sh
set -e

# Fix ownership of mounted volumes (they mount as root)
chown -R www-data:www-data /app/var /app/config/jwt /app/storage 2>/dev/null || true

# Generate JWT keys if missing, or regenerate if passphrase changed
if [ ! -f /app/config/jwt/private.pem ]; then
    php bin/console lexik:jwt:generate-keypair 2>/dev/null || true
elif ! php -r "openssl_pkey_get_private(file_get_contents('/app/config/jwt/private.pem'), getenv('JWT_PASSPHRASE')) or exit(1);" 2>/dev/null; then
    php bin/console lexik:jwt:generate-keypair --overwrite 2>/dev/null || true
fi

# Clear stale cache and warmup with real env vars
php bin/console cache:clear --no-warmup 2>/dev/null || true
php bin/console cache:warmup 2>/dev/null || true

# Reset OPcache so PHP-FPM picks up any new/changed source files
php -r "opcache_reset();" 2>/dev/null || true

# Fix ownership of freshly generated cache
chown -R www-data:www-data /app/var 2>/dev/null || true

exec "$@"
