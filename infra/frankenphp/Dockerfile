FROM dunglas/frankenphp:1.4-php8.4-alpine

RUN install-php-extensions \
    bcmath \
    gd \
    intl \
    ldap \
    opcache \
    pcntl \
    pdo_pgsql \
    pgsql \
    posix \
    redis \
    sodium \
    zip

WORKDIR /app

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader --no-scripts

COPY . .
COPY infra/frankenphp/Caddyfile /etc/caddy/Caddyfile

RUN php artisan package:discover --ansi \
    && chown -R www-data:www-data /app/storage /app/bootstrap/cache

EXPOSE 80 443 443/udp

CMD ["frankenphp", "run", "--config", "/etc/caddy/Caddyfile"]
