#!/bin/sh
set -eu

cd /var/www/html
mkdir -p storage/app/public storage/app/private storage/framework/cache/data \
    storage/framework/sessions storage/framework/views storage/logs bootstrap/cache
chown www-data:www-data storage/app storage/framework storage/logs bootstrap/cache

php artisan migrate --force
php artisan db:seed --force
php artisan storage:link --force

exec /usr/bin/supervisord -n -c /etc/supervisor/supervisord.conf
