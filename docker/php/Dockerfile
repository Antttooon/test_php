FROM php:8.3-fpm-alpine

RUN docker-php-ext-install pdo pdo_mysql

COPY php.dev.ini /usr/local/etc/php/conf.d/99-dev.ini

WORKDIR /var/www/html
