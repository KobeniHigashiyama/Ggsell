FROM php:8.5-fpm

# pdo_pgsql is required because the reliability model relies on Postgres features
# such as FOR UPDATE, SKIP LOCKED, partial indexes, and jsonb. pcntl lets queue
# workers handle signals correctly.
RUN apt-get update \
    && apt-get install -y --no-install-recommends git unzip libpq-dev libzip-dev postgresql-client \
    && docker-php-ext-install -j"$(nproc)" pdo_pgsql pcntl bcmath zip \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

RUN printf 'memory_limit=512M\n' > /usr/local/etc/php/conf.d/zz-app.ini

# One FPM worker handles one request at a time. The pool must be wider than the
# race test concurrency of 50, or the FPM queue would hide actual contention on
# the order row.
RUN printf '[www]\npm = static\npm.max_children = 64\n' > /usr/local/etc/php-fpm.d/zz-pool.conf

WORKDIR /app

ENV COMPOSER_ALLOW_SUPERUSER=1 \
    COMPOSER_MEMORY_LIMIT=-1
