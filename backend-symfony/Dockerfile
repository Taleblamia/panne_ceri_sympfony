FROM php:8.3-cli

RUN apt-get update && apt-get install -y \
    git unzip libzip-dev libsqlite3-dev \
    && docker-php-ext-install pdo pdo_sqlite zip \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app/backend-symfony

ENV APP_ENV=prod \
    APP_SECRET=docker_build_secret \
    BACKEND_URL=http://localhost:8001 \
    FRONTEND_URL=http://localhost:8001 \
    ADMIN_PASSWORD=docker_build_password \
    ALADIN_URL=https://www.appigs.univ-avignon.fr/extranet/interv_ext.html \
    MAILER_DSN=null://null \
    MAIL_FROM=no-reply@example.com

COPY backend-symfony/ /app/backend-symfony/
COPY frontend-twig/ /app/frontend-twig/

RUN composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader \
    && mkdir -p var \
    && touch database.db

EXPOSE 8001

CMD ["php", "-S", "0.0.0.0:8001", "-t", "public"]
