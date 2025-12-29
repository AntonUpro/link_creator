FROM php:8.3-fpm
RUN apt-get update && apt-get install -y zlib1g-dev g++ git libicu-dev zip libzip-dev zip libpq-dev libpng-dev libwebp-dev libxpm-dev \
    && docker-php-ext-install intl opcache pdo pgsql pdo_mysql pdo_pgsql gd \
    && pecl install apcu \
    && docker-php-ext-enable apcu \
    && docker-php-ext-configure zip \
    && docker-php-ext-install zip
WORKDIR /var/www/link_creator
RUN curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer
RUN curl -sS https://get.symfony.com/cli/installer | bash
USER www-data
