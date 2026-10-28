FROM php:8.3-fpm-alpine
RUN apk add --no-cache oniguruma oniguruma-dev \
    && docker-php-ext-install pdo pdo_mysql mbstring \
    && apk del oniguruma-dev
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
COPY docker/php.ini /usr/local/etc/php/conf.d/marketing-os.ini

# Le dipendenze vivono fuori da /var/www/html, che in sviluppo è montata dal disco locale.
WORKDIR /opt/deps
COPY composer.json composer.lock* ./
RUN composer install --no-dev --no-interaction --no-progress --prefer-dist

RUN mkdir -p /var/www/storage && chown www-data:www-data /var/www/storage
WORKDIR /var/www/html
COPY --chown=www-data:www-data . /var/www/html
USER www-data
CMD ["php-fpm"]
