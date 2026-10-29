FROM node:24-bookworm-slim@sha256:55fb5f5a527ea2c5c4a95f963e48ef90aa38c7b346054fdc8ee9957a2b55d205 AS frontend
WORKDIR /build
COPY package.json package-lock.json ./
RUN npm ci
COPY vite.config.js ./
COPY resources ./resources
RUN npm run build

FROM php:8.4-apache-bookworm@sha256:e653ff90d7f6e2e6f81eddb07c864f1eae224ccbf5143f82a44f25e2f110e320 AS app
RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        libicu-dev \
        libonig-dev \
        libsqlite3-dev \
        libzip-dev \
        libxml2-dev \
        unzip \
    && docker-php-ext-install -j$(nproc) pdo_sqlite mbstring intl zip opcache \
    && a2enmod rewrite headers \
    && rm -rf /var/lib/apt/lists/*
COPY --from=composer:2@sha256:b6dcd72ca98fa19d0e5b8476de179859704891fad06dd9e0b96833d4c430a5fc /usr/bin/composer /usr/local/bin/composer
WORKDIR /var/www/html
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-interaction --prefer-dist --optimize-autoloader
COPY . .
COPY --from=frontend /build/public/build ./public/build
COPY docker/apache.conf /etc/apache2/sites-available/000-default.conf
COPY docker/php.ini /usr/local/etc/php/conf.d/solotasks.ini
COPY docker/entrypoint.sh /usr/local/bin/solotasks-entrypoint
RUN mkdir -p \
        bootstrap/cache \
        storage/app/private \
        storage/framework/cache/data \
        storage/framework/sessions \
        storage/framework/views \
        storage/logs \
    && composer dump-autoload --no-dev --no-scripts --optimize \
    && php artisan package:discover --no-interaction \
    && chmod +x /usr/local/bin/solotasks-entrypoint \
    && chown -R www-data:www-data storage bootstrap/cache
EXPOSE 80
ENTRYPOINT ["solotasks-entrypoint"]
CMD ["apache2-foreground"]
