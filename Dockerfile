FROM php:8.3-cli-alpine

COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer

RUN echo 'memory_limit=2G' > $PHP_INI_DIR/conf.d/memory-limit.ini

WORKDIR /app
COPY . /app

RUN COMPOSER_ALLOW_SUPERUSER=1 composer install

ENTRYPOINT ["/app/bin/console"]
