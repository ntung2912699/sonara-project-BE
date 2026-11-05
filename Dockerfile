FROM php:8.3-apache-bookworm

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        ffmpeg \
        libcurl4-openssl-dev \
        libicu-dev \
        libonig-dev \
        libpq-dev \
        libzip-dev \
        supervisor \
        unzip \
    && docker-php-ext-install -j"$(nproc)" bcmath curl fileinfo intl mbstring opcache pcntl pdo_pgsql zip \
    && a2enmod headers rewrite \
    && sed -i 's/Listen 80/Listen 10000/' /etc/apache2/ports.conf \
    && rm -f /etc/apache2/sites-enabled/000-default.conf \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html
COPY . /var/www/html/

RUN composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader \
    && mkdir -p storage/framework/cache/data storage/framework/sessions storage/framework/views \
        storage/logs storage/app/public storage/app/private bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache

COPY deployment/apache-vhost.conf /etc/apache2/sites-available/sonora.conf
COPY deployment/uploads.ini /usr/local/etc/php/conf.d/uploads.ini
COPY deployment/supervisord.conf /etc/supervisor/conf.d/sonora.conf
COPY deployment/entrypoint.sh /usr/local/bin/sonora-entrypoint

RUN chmod +x /usr/local/bin/sonora-entrypoint \
    && a2ensite sonora.conf

EXPOSE 10000
ENTRYPOINT ["/usr/local/bin/sonora-entrypoint"]
