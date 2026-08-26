# Apache with mod_php, not because it is fashionable but because it is the same
# shape as the shared hosting this has to run on. One image, one process.
FROM php:8.3-apache

# Passed by CI from the git tag, so the running app knows its own version.
ARG VERSION=0.1.0-dev
ENV PHAROS_VERSION=${VERSION}

# unzip is not in the base image, and without it composer cannot unpack a single
# package from dist.
RUN apt-get update \
 && apt-get install -y --no-install-recommends unzip libzip-dev \
 && rm -rf /var/lib/apt/lists/* \
 && a2enmod rewrite \
 && docker-php-ext-install -j"$(nproc)" pdo_mysql opcache bcmath zip \
 && printf 'opcache.enable=1\nopcache.validate_timestamps=0\nopcache.memory_consumption=128\n' \
    > /usr/local/etc/php/conf.d/opcache.ini \
 && printf 'expose_php=Off\n' > /usr/local/etc/php/conf.d/hardening.ini

ENV APACHE_DOCUMENT_ROOT=/var/www/html/public
RUN sed -ri 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' \
      /etc/apache2/sites-available/*.conf /etc/apache2/apache2.conf

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
WORKDIR /var/www/html

# Dependencies first so a code change does not re-resolve the whole tree.
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-interaction

COPY . .
RUN composer dump-autoload --optimize --no-interaction \
 && chown -R www-data:www-data storage bootstrap/cache database

COPY docker/entrypoint.sh /usr/local/bin/entrypoint
RUN chmod +x /usr/local/bin/entrypoint

EXPOSE 80
ENTRYPOINT ["entrypoint"]
CMD ["apache2-foreground"]
