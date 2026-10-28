# Environnement de développement : PHP 8.2 comme en production, serveur intégré de PHP.
FROM php:8.2-cli

RUN apt-get update \
    && apt-get install -y --no-install-recommends git unzip libicu-dev \
    && docker-php-ext-install intl \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app
COPY . .
RUN composer install --no-interaction --no-progress

EXPOSE 8000
CMD ["php", "-S", "0.0.0.0:8000", "-t", "public"]
