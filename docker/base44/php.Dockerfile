# Image pengembangan Base44: PHP 8.4 + Composer, TANPA kode aplikasi di dalam
# image. Seluruh source di-bind-mount dari repo, dependensi dipasang saat
# container start (lihat docker-compose.base44.yml).
FROM php:8.4-cli

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        libpq-dev libonig-dev libzip-dev unzip git curl \
    && docker-php-ext-install -j"$(nproc)" pdo_pgsql mbstring bcmath \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app
