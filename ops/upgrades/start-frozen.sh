#!/usr/bin/env bash
# The application and composer.lock are baked into this reviewed image.
# No package resolution, download, or restore from a shared storage backup occurs at boot.
set -euo pipefail
cd /var/www/html
install -d -o www-data -g www-data -m 775 storage/logs storage/framework/cache/data storage/framework/sessions storage/framework/views bootstrap/cache
touch storage/logs/laravel.log
chown www-data:www-data storage/logs/laravel.log
chmod 664 storage/logs/laravel.log
/var/www/startup/create_env.sh
/usr/local/bin/wait-for-it.sh "${DB_HOST:-mysql}:${DB_PORT:-3306}" -t 60 --strict
php artisan storage:link
php artisan optimize:clear --except cache
php artisan mixpost:update-migration-timestamps
php artisan migrate --force
php artisan mixpost:upgrade-database --force
php artisan mixpost:clear-settings-cache
php artisan mixpost:clear-services-cache
php artisan optimize --except cache
chown -R www-data:www-data storage/logs storage/framework bootstrap/cache
service cron start
exec /usr/bin/supervisord -n -c /etc/supervisor/supervisord.conf
