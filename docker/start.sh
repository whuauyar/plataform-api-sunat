#!/bin/sh
set -e

echo "==> Optimizando autoload..."
composer dump-autoload --optimize --no-dev 2>/dev/null || true

echo "==> Limpiando cache previo..."
php artisan config:clear
php artisan cache:clear 2>/dev/null || true

# Migraciones: en un stack multi-contenedor (web + workers + scheduler) NO deben
# correrlas todos a la vez. Pon SKIP_MIGRATIONS=true en los workers/scheduler y
# deja que solo el servicio web migre — o, mejor, córrelas como paso de deploy
# (docker compose run --rm api-sunat php artisan migrate --force) y pon SKIP en todos.
# Un fallo de migración NO tumba el contenedor: queda sirviendo y se revisa aparte.
if [ "${SKIP_MIGRATIONS:-false}" = "true" ]; then
    echo "==> Migraciones OMITIDAS (SKIP_MIGRATIONS=true)."
else
    echo "==> Ejecutando migraciones..."
    php artisan migrate --force || echo "⚠ Las migraciones fallaron; el contenedor sigue arriba. Revisa y corre 'php artisan migrate --force'."
fi

echo "==> Cacheando config y rutas..."
php artisan config:cache
php artisan route:cache
php artisan view:cache

echo "==> Creando symlink storage..."
php artisan storage:link 2>/dev/null || true

echo "==> Iniciando servidor..."
# El servidor embebido de PHP atiende 1 petición a la vez; con varios workers
# (requiere ext-pcntl) maneja peticiones concurrentes y evita los 502 de nginx
# cuando el panel carga HTML + assets + XHR a la vez.
export PHP_CLI_SERVER_WORKERS="${PHP_CLI_SERVER_WORKERS:-10}"
exec php artisan serve --host=0.0.0.0 --port="${PORT:-8000}" --no-reload
