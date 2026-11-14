FROM php:8.3-fpm-alpine AS base

# Dependencias del sistema
RUN apk add --no-cache \
    postgresql-client \
    libpq-dev \
    libzip-dev \
    libxml2-dev \
    libpng-dev \
    libjpeg-turbo-dev \
    libwebp-dev \
    freetype-dev \
    icu-dev \
    oniguruma-dev \
    zip \
    unzip \
    git \
    curl \
    bash \
    openssl

# Extensiones PHP necesarias
RUN docker-php-ext-configure gd --with-freetype --with-jpeg --with-webp \
    && docker-php-ext-install \
        pdo_mysql \
        mysqli \
        pdo_pgsql \
        pgsql \
        zip \
        soap \
        dom \
        gd \
        bcmath \
        intl \
        mbstring \
        opcache \
        pcntl

# Extensión Redis (phpredis) via PECL
RUN apk add --no-cache --virtual .build-deps $PHPIZE_DEPS \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && apk del .build-deps

# Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app

# Instalar dependencias PHP (sin scripts para que no falle sin APP_KEY)
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --ignore-platform-reqs

# Copiar código fuente
COPY . .

# Autoload optimizado
RUN composer dump-autoload --optimize

# Compilar el frontend (Vite/React/Tailwind) DENTRO de la imagen.
# Se hace en ESTE stage (no en uno Node aislado) porque Wayfinder necesita
# php + artisan durante el build, y aqui ya estan php y el vendor de composer.
# El .env es temporal solo para que artisan pueda bootear durante el build;
# en runtime docker-compose monta el .env real encima, y aqui se elimina.
# .env.example esta excluido por .dockerignore (.env.*), asi que se crea un
# .env minimo inline solo para el build; en runtime compose monta el real.
RUN apk add --no-cache nodejs npm \
    && printf 'APP_NAME=API\nAPP_ENV=local\nAPP_KEY=\nAPP_DEBUG=true\n' > .env \
    && php artisan key:generate --force \
    && npm ci \
    && npm run build \
    && rm -rf node_modules .env

# Permisos de storage y cache
RUN mkdir -p storage/app/public \
              storage/framework/sessions \
              storage/framework/views \
              storage/framework/cache/data \
              storage/logs \
              storage/certificates \
              bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache \
    && chmod -R 775 storage bootstrap/cache

# Script de arranque
COPY docker/start.sh /start.sh
RUN chmod +x /start.sh

EXPOSE 8000

# Healthcheck: Docker marca el contenedor 'healthy' solo cuando la app ya responde.
# start-period cubre el arranque (migraciones/cache) sin reportar falso 'unhealthy'.
HEALTHCHECK --interval=15s --timeout=5s --start-period=40s --retries=3 \
    CMD curl -fsS http://localhost:8000/up || exit 1

CMD ["/start.sh"]
