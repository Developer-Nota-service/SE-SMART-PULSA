# Production image: Apache + PHP 8.4, with the React SPA served from public/app.
FROM node:22-slim AS web
WORKDIR /app/web
COPY web/package.json web/package-lock.json ./
RUN npm ci
COPY web/ ./
RUN npm run build

FROM php:8.4-apache

RUN apt-get update \
    && apt-get install -y --no-install-recommends libpq-dev libonig-dev libcurl4-openssl-dev unzip $PHPIZE_DEPS \
    && docker-php-ext-install -j"$(nproc)" pdo_pgsql mbstring curl bcmath pcntl opcache \
    && pecl install redis \
    && docker-php-ext-enable redis opcache \
    && apt-get purge -y --auto-remove $PHPIZE_DEPS \
    && a2enmod rewrite headers \
    && mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini" \
    && printf 'memory_limit=256M\nmax_execution_time=60\nupload_max_filesize=10M\npost_max_size=12M\nopcache.enable=1\nopcache.memory_consumption=128\nopcache.max_accelerated_files=20000\nopcache.validate_timestamps=0\n' > "$PHP_INI_DIR/conf.d/zz-production.ini" \
    && sed -ri 's/^StartServers .*/StartServers 2/; s/^MinSpareServers .*/MinSpareServers 2/; s/^MaxSpareServers .*/MaxSpareServers 5/; s/^MaxRequestWorkers .*/MaxRequestWorkers 10/' /etc/apache2/mods-available/mpm_prefork.conf \
    && rm -rf /var/lib/apt/lists/* /tmp/pear

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

ENV APACHE_DOCUMENT_ROOT=/var/www/html/public
RUN sed -ri -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' \
    /etc/apache2/sites-available/*.conf \
    /etc/apache2/apache2.conf \
    /etc/apache2/conf-available/*.conf

WORKDIR /var/www/html

COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-interaction --prefer-dist --optimize-autoloader

COPY . .
RUN composer dump-autoload --optimize --no-scripts \
    && mkdir -p storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache

RUN printf '<Directory /var/www/html/public>\n    AllowOverride All\n    Require all granted\n</Directory>\n' \
        > /etc/apache2/conf-available/laravel.conf \
    && a2enconf laravel

COPY --from=web /app/public/app ./public/app

COPY docker/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod 0755 /usr/local/bin/entrypoint.sh

EXPOSE 80
ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
CMD ["apache2-foreground"]
