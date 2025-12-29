#!/bin/bash

# Создаем папки после монтирования volume
mkdir -p /var/www/link_creator/public/uploads/qr-codes

# Настраиваем права
chown -R www-data:www-data /var/www/link_creator
chmod -R 775 /var/www/link_creator/public/uploads

# Выполняем оригинальную команду
exec "$@"
