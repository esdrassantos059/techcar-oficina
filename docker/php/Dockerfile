FROM php:8.3-cli

RUN apt-get update && apt-get install -y \ 
    git curl zip unzip libpq-dev libzip-dev \ 
    && docker-php-ext-install pdo pdo_pgsql zip 

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www

CMD ["php", "artisan", "serve", "--host=0.0.0.0", "--port=8000"]