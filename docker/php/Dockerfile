# docker/php/Dockerfile
FROM php:8.3-fpm AS base

RUN apt-get update && apt-get install -y \
    git unzip libzip-dev libpng-dev libonig-dev libxml2-dev libpq-dev \
    && docker-php-ext-install pdo_pgsql zip gd mbstring exif pcntl bcmath opcache

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

FROM base AS dependencies
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist

FROM base AS development
COPY . .
COPY --from=dependencies /var/www/html/vendor ./vendor
RUN composer dump-autoload
CMD ["php-fpm"]

FROM base AS production
COPY . .
COPY --from=dependencies /var/www/html/vendor ./vendor
RUN composer dump-autoload --optimize
RUN php artisan config:cache && php artisan route:cache
CMD ["php-fpm"]
