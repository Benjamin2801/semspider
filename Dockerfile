# Environnement de développement : PHP 8.2, version minimale du projet, serveur intégré de PHP.
FROM php:8.2-cli

RUN apt-get update \
    && apt-get install -y --no-install-recommends git unzip libicu-dev \
    && docker-php-ext-install intl \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app

# Dépendances d'abord : elles ne se réinstallent que si composer.json ou composer.lock changent.
COPY composer.json composer.lock ./
RUN composer install --no-interaction --no-progress --no-plugins --no-scripts --no-autoloader

COPY . .
RUN composer install --no-interaction --no-progress

EXPOSE 8000
CMD ["php", "-S", "0.0.0.0:8000", "-t", "public"]
