# Despliegue en un VPS con Docker — API SUNAT

Guía paso a paso para poner **API SUNAT** en producción con **Docker** en un VPS,
usando el `Dockerfile` que ya trae el proyecto. Incluye un `docker-compose.yml`
listo para copiar (app web + worker Horizon + scheduler + MySQL + Redis +
Caddy con HTTPS automático).

---

## Cómo está hecho el proyecto (lo que debes saber antes)

Analizando el `Dockerfile` y `docker/start.sh`:

- **Imagen única** basada en `php:8.3-fpm-alpine` con todas las extensiones que
  usa la API (`pdo_mysql`, `pgsql`, `gd` con **WebP**, `soap`, `dom`, `bcmath`,
  `intl`, `mbstring`, `opcache`, `pcntl`, `redis`).
- El frontend (Vite/React) se **compila dentro de la imagen** (`npm run build`),
  igual que el `vendor` de Composer. → **Cualquier cambio de código o de UI
  exige reconstruir la imagen** (`docker compose build`).
- El contenedor **no usa php-fpm**: `start.sh` arranca `php artisan serve` en
  `0.0.0.0:8000` con `PHP_CLI_SERVER_WORKERS=10` (varios workers para no dar 502).
  → Por eso ponemos **Caddy delante** como reverse proxy + HTTPS.
- `start.sh` corre **migraciones** al arrancar, salvo que pongas
  `SKIP_MIGRATIONS=true`. En un stack multi-contenedor, **solo el web migra**;
  el worker y el scheduler omiten migraciones.
- **Horizon y el scheduler NO están en el arranque por defecto**: se corren como
  **contenedores aparte reutilizando la misma imagen**, cambiando el comando.
  Un solo `php artisan horizon` ya procesa **todas** las colas (`sunat`,
  `webhooks`, `mail`, `sire-*`, `default`) según `config/horizon.php`.
- Los **certificados** se guardan como archivos en
  `storage/app/certificates/{ruc}/cert.pem`, y los PDF/XML/CDR generados también
  cuelgan de `storage/app`. → **`storage/app` debe ir en un volumen persistente**
  o se pierden los certificados en cada rebuild.

Con eso, el diseño del compose es:

| Servicio    | Imagen            | Comando                  | Migraciones | Rol                         |
|-------------|-------------------|--------------------------|-------------|-----------------------------|
| `app`       | build (Dockerfile)| `/start.sh` (por defecto)| **Sí**      | Web (artisan serve :8000)   |
| `worker`    | misma imagen      | `php artisan horizon`    | No (SKIP)   | Colas / reintentos SUNAT    |
| `scheduler` | misma imagen      | `php artisan schedule:work` | No (SKIP)| Tareas programadas          |
| `db`        | `mysql:8.0`       | —                        | —           | Base de datos               |
| `redis`     | `redis:7-alpine`  | —                        | —           | Colas + caché (Horizon)     |
| `caddy`     | `caddy:2-alpine`  | —                        | —           | Reverse proxy + HTTPS auto  |

---

## 0. Requisitos

- VPS con **Ubuntu 22.04/24.04** (2 vCPU / 4 GB RAM recomendado).
- Acceso **SSH**.
- Un **dominio** apuntando (registro **A**) a la IP del VPS (p. ej.
  `api.tu-dominio.com`). Caddy necesita el dominio resuelto para emitir el
  certificado TLS.
- Puertos **80 y 443** abiertos.

---

## 1. Instalar Docker en el VPS

```bash
# Script oficial de Docker
curl -fsSL https://get.docker.com | sudo sh

# (Opcional) usar docker sin sudo
sudo usermod -aG docker $USER
# cierra sesión y vuelve a entrar para aplicar el grupo

docker --version
docker compose version
```

### Firewall

```bash
sudo ufw allow OpenSSH
sudo ufw allow 80
sudo ufw allow 443
sudo ufw enable
```

---

## 2. Clonar el proyecto

```bash
sudo mkdir -p /opt/apisunat && sudo chown -R $USER:$USER /opt/apisunat
cd /opt/apisunat
git clone https://github.com/yorchavez9/plataform-api-sunat.git .
```

---

## 3. Crear el `.env`

```bash
cp .env.example .env
nano .env
```

Ajusta estos valores. **Importante:** dentro de Docker, los hosts de BD y Redis
son los **nombres de los servicios** (`db`, `redis`), no `127.0.0.1`:

```ini
APP_NAME="API SUNAT"
APP_ENV=production
APP_DEBUG=false
APP_URL=https://api.tu-dominio.com
APP_TIMEZONE=America/Lima
APP_LOCALE=es

# Se genera en el paso 5 (déjalo vacío por ahora)
APP_KEY=

# --- Base de datos (host = nombre del servicio 'db') ---
DB_CONNECTION=mysql
DB_HOST=db
DB_PORT=3306
DB_DATABASE=apisunat
DB_USERNAME=apisunat
DB_PASSWORD=pon_una_clave_fuerte
# Solo para el contenedor MySQL (lo lee docker compose, no Laravel):
DB_ROOT_PASSWORD=otra_clave_root_fuerte

# --- Colas con Horizon → OBLIGATORIO redis (host = servicio 'redis') ---
QUEUE_CONNECTION=redis
CACHE_STORE=redis
SESSION_DRIVER=database
REDIS_CLIENT=phpredis
REDIS_HOST=redis
REDIS_PORT=6379
REDIS_PASSWORD=null

# --- Correo (ver nota sobre SMTP más abajo) ---
MAIL_MAILER=smtp
MAIL_HOST=...
MAIL_PORT=587
MAIL_USERNAME=...
MAIL_PASSWORD=...
MAIL_FROM_ADDRESS=no-reply@tu-dominio.com
```

---

## 4. Crear `docker-compose.yml` y `Caddyfile`

En la raíz del proyecto (`/opt/apisunat`), crea **`docker-compose.yml`**:

```yaml
services:
  app:
    build: .
    image: apisunat:latest
    restart: unless-stopped
    env_file: .env
    volumes:
      - app_storage:/app/storage/app
    depends_on:
      db:
        condition: service_healthy
      redis:
        condition: service_started
    expose:
      - "8000"

  worker:
    image: apisunat:latest
    restart: unless-stopped
    env_file: .env
    environment:
      SKIP_MIGRATIONS: "true"
    command: php artisan horizon
    volumes:
      - app_storage:/app/storage/app
    depends_on:
      app:
        condition: service_healthy
      redis:
        condition: service_started

  scheduler:
    image: apisunat:latest
    restart: unless-stopped
    env_file: .env
    environment:
      SKIP_MIGRATIONS: "true"
    command: php artisan schedule:work
    volumes:
      - app_storage:/app/storage/app
    depends_on:
      app:
        condition: service_healthy

  db:
    image: mysql:8.0
    restart: unless-stopped
    environment:
      MYSQL_DATABASE: ${DB_DATABASE}
      MYSQL_USER: ${DB_USERNAME}
      MYSQL_PASSWORD: ${DB_PASSWORD}
      MYSQL_ROOT_PASSWORD: ${DB_ROOT_PASSWORD}
    volumes:
      - db_data:/var/lib/mysql
    healthcheck:
      test: ["CMD", "mysqladmin", "ping", "-h", "localhost", "-p${DB_ROOT_PASSWORD}"]
      interval: 10s
      timeout: 5s
      retries: 10

  redis:
    image: redis:7-alpine
    restart: unless-stopped
    volumes:
      - redis_data:/data

  caddy:
    image: caddy:2-alpine
    restart: unless-stopped
    ports:
      - "80:80"
      - "443:443"
    volumes:
      - ./Caddyfile:/etc/caddy/Caddyfile
      - caddy_data:/data
      - caddy_config:/config
    depends_on:
      - app

volumes:
  app_storage:
  db_data:
  redis_data:
  caddy_data:
  caddy_config:
```

Y crea **`Caddyfile`** (reemplaza el dominio):

```caddy
api.tu-dominio.com {
    reverse_proxy app:8000
}
```

> Caddy obtiene y renueva el certificado HTTPS **automáticamente** vía Let's
> Encrypt — no necesitas certbot.

---

## 5. Construir, generar APP_KEY y levantar

```bash
cd /opt/apisunat

# 1) Construir la imagen (compila vendor + frontend). Tarda unos minutos.
docker compose build

# 2) Generar la APP_KEY y escribirla en el .env
docker compose run --rm app php artisan key:generate --force --show
#   → copia el valor 'base64:...' que imprime y pégalo en APP_KEY del .env
nano .env

# 3) Levantar todo
docker compose up -d

# 4) Ver el estado (espera a que 'app' quede healthy)
docker compose ps
```

> El servicio `app` corre las migraciones al arrancar (`start.sh`). Si prefieres
> correrlas como paso explícito de deploy:
> `docker compose run --rm app php artisan migrate --force`.

Mira los logs del arranque:

```bash
docker compose logs -f app       # web + migraciones
docker compose logs -f worker    # Horizon
```

---

## 6. Verificación

```bash
# Salud de la app (dentro del contenedor)
docker compose exec app php artisan kodevo:doctor

# ¿Horizon activo?
docker compose exec worker php artisan horizon:status
```

Abre `https://api.tu-dominio.com` en el navegador (Caddy ya debería servir con
HTTPS). El dashboard de Horizon está en `/horizon` (login de admin).

---

## 7. Actualizar la API (despliegues siguientes)

Como vendor y frontend van **horneados en la imagen**, cualquier cambio de
código o UI necesita **rebuild**:

```bash
cd /opt/apisunat
git pull origin main
docker compose build
docker compose up -d        # recrea los contenedores con la imagen nueva
```

> Las migraciones nuevas las aplica `app` al recrearse. Para controlarlo a mano:
> `docker compose run --rm app php artisan migrate --force` antes del `up`.

### Si tras el `up` ves `could not translate host name "db"`

Es un problema de DNS interno de Docker cuando un contenedor quedó con la IP
vieja de otro recreado. Se arregla forzando la recreación:

```bash
docker compose up -d --force-recreate
```

---

## 8. Backups de la base de datos

El contenedor MySQL guarda sus datos en el volumen `db_data`. Un volcado
periódico recomendado:

```bash
# Backup manual
docker compose exec db sh -c 'exec mysqldump -u root -p"$MYSQL_ROOT_PASSWORD" apisunat' > backup_$(date +%F).sql

# Restaurar
cat backup_2026-01-01.sql | docker compose exec -T db sh -c 'exec mysql -u root -p"$MYSQL_ROOT_PASSWORD" apisunat'
```

> El comando `backup:run` del scheduler también corre dentro del contenedor;
> para que suba a almacenamiento externo, configúralo en `config/backup.php`.

---

## 9. Notas de certificados, correo y SUNAT

- **Certificados (.pfx / .pem):** se suben **desde el panel** (no por `.env`).
  Se guardan en `storage/app/certificates/...`, que está en el volumen
  `app_storage` → **persisten entre rebuilds**. Compartimos ese volumen con el
  `worker` para que pueda firmar/enviar.
- **SMTP bloqueado:** muchos VPS **bloquean los puertos de salida 25/465/587**.
  Si los correos no salen: pide al proveedor desbloquear el **587**, o usa un
  servicio de correo transaccional por **API/HTTP**.
- **Entorno SUNAT (beta/producción):** se configura **por empresa** en el panel.
  Prueba primero en **beta** antes de emitir real.

---

## 10. Comandos útiles

```bash
docker compose ps                         # estado de los servicios
docker compose logs -f app                # logs en vivo del web
docker compose exec app sh                # shell dentro del contenedor web
docker compose exec app php artisan ...   # cualquier comando artisan
docker compose restart worker             # reiniciar Horizon
docker compose down                       # detener todo (conserva volúmenes)
docker compose down -v                    # ⚠ detener y BORRAR volúmenes (datos!)
```

---

### Resumen del orden

1. Instalar Docker + firewall → 2. Clonar → 3. `.env` (hosts `db`/`redis`,
`QUEUE_CONNECTION=redis`) → 4. `docker-compose.yml` + `Caddyfile` →
5. `build` + `key:generate` + `up -d` → 6. `kodevo:doctor` →
7. Actualizar = `git pull` + `build` + `up -d`.

> ¿VPS sin Docker? Ver la guía hermana **`DEPLOY-VPS.md`** (Nginx + PHP-FPM
> nativo + Horizon por systemd).
