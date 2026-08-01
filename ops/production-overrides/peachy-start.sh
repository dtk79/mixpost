#!/bin/bash
set -euo pipefail

ensure_laravel_log_permissions() {
  install -d -o www-data -g www-data -m 775 /var/www/html/storage/logs
  touch /var/www/html/storage/logs/laravel.log
  chown www-data:www-data /var/www/html/storage/logs/laravel.log
  chmod 664 /var/www/html/storage/logs/laravel.log
}

# The image starts as root and runs Artisan during boot. Pre-create Laravel's
# log for PHP-FPM so those commands cannot leave a root-only file behind.
ensure_laravel_log_permissions

exec /usr/local/bin/start.sh
