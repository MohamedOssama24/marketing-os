FROM php:8.3-fpm-alpine
RUN apk add --no-cache oniguruma oniguruma-dev \
    && docker-php-ext-install pdo pdo_mysql mbstring \
    && apk del oniguruma-dev
WORKDIR /var/www/html
COPY --chown=www-data:www-data . /var/www/html
USER www-data
CMD ["php-fpm"]
