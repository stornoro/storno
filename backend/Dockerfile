# ── Stage 1: Composer dependencies ───────────────────────────────
FROM php:8.2-cli-alpine AS vendor
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
RUN apk add --no-cache git unzip

WORKDIR /app
COPY composer.json composer.lock symfony.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --ignore-platform-reqs

COPY . .
RUN composer dump-autoload --optimize

# ── Stage 2: Compile unified Java service ────────────────────────
FROM eclipse-temurin:17-jdk-alpine AS java-build

WORKDIR /build
COPY resources/validator/ROeFacturaValidator.jar validator/
COPY tools/pdf-generator/dist/ pdf/dist/
COPY tools/signature-verifier/verifsignature.jar signature/
COPY tools/java-services/JavaServiceServer.java .

RUN javac -cp "validator/ROeFacturaValidator.jar:pdf/dist/generareFactura.jar:pdf/dist/lib/*:pdf/dist/:signature/verifsignature.jar" \
    JavaServiceServer.java

# ── Stage 3: wkhtmltopdf (pre-built for Alpine) ──────────────────
FROM surnet/alpine-wkhtmltopdf:3.20.3-0.12.6-small AS wkhtmltopdf

# ── Stage 4: Production image ────────────────────────────────────
FROM php:8.2-fpm-alpine AS runtime

# System deps
RUN apk add --no-cache \
    nginx \
    supervisor \
    bash \
    icu-libs \
    libzip \
    libpng \
    libjpeg-turbo \
    freetype \
    oniguruma \
    openjdk17-jre \
    curl \
    fontconfig \
    ttf-freefont \
    libstdc++ \
    libx11 \
    libxrender \
    libxext \
    && apk add --no-cache --virtual .build-deps \
    icu-dev \
    libzip-dev \
    libpng-dev \
    libjpeg-turbo-dev \
    freetype-dev \
    oniguruma-dev \
    linux-headers \
    $PHPIZE_DEPS \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j$(nproc) \
    pdo_mysql \
    intl \
    opcache \
    zip \
    gd \
    mbstring \
    bcmath \
    sockets \
    && pecl install redis apcu \
    && docker-php-ext-enable redis apcu \
    && apk del .build-deps

# wkhtmltopdf binary (for HTML→PDF via KnpSnappy)
COPY --from=wkhtmltopdf /bin/wkhtmltopdf /usr/bin/wkhtmltopdf

# PHP config
RUN mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"
COPY deploy/docker/php.ini /usr/local/etc/php/conf.d/99-storno.ini
COPY deploy/docker/php-fpm.conf /usr/local/etc/php-fpm.d/zz-storno.conf
COPY deploy/docker/nginx.conf /etc/nginx/http.d/default.conf
COPY deploy/docker/supervisord.conf /etc/supervisord.conf
COPY deploy/docker/entrypoint.sh /entrypoint.sh
RUN chmod +x /entrypoint.sh

WORKDIR /app

# Copy app + vendor
COPY --from=vendor /app .

# Copy compiled unified Java service
COPY --from=java-build /build/JavaServiceServer.class tools/java-services/

# Copy .env.example as .env defaults — Symfony needs all vars declared.
# Real values come from Docker environment and override these.
COPY .env.example .env
RUN sed -i 's/^APP_ENV=.*/APP_ENV=prod/' .env

# Ensure PHP-FPM (www-data) can read the app
RUN mkdir -p var/cache var/log var/jwt config/jwt storage tools/pdf-generator/tmp \
    && chown -R www-data:www-data var config/jwt storage tools/pdf-generator/tmp \
    && chmod -R o+r . \
    && find . -type d -exec chmod o+x {} \;

# Generate JWT keys if they don't exist (overridable via volume mount)
RUN APP_ENV=prod php bin/console lexik:jwt:generate-keypair --skip-if-exists 2>/dev/null || true

EXPOSE 80

ENTRYPOINT ["/entrypoint.sh"]
CMD ["/usr/bin/supervisord", "-c", "/etc/supervisord.conf"]
