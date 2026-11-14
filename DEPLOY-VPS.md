# Despliegue en un VPS (sin Docker) — API SUNAT

Guía paso a paso para poner **API SUNAT** en producción sobre un VPS normal
(Ubuntu 22.04 / 24.04 LTS) con **Nginx + PHP 8.3-FPM + MySQL + Redis + Horizon**.

> Stack real del proyecto: Laravel 12 · PHP 8.3 · Inertia/React (Vite) ·
> colas con **Horizon** (`sunat`, `webhooks`, `mail`, `default`) · scheduler de
> Laravel · PDF con dompdf (requiere **GD con WebP** para los logos) · firma de
> comprobantes con Greenter (requiere `openssl`/`dom`/`soap`).

Todo lo que va con `sudo` se ejecuta en la terminal SSH del VPS como root o un
usuario con sudo. Reemplaza `tu-dominio.com`, contraseñas y rutas por las tuyas.

---

## 0. Requisitos previos

- Un VPS con **Ubuntu 22.04/24.04 LTS** (2 vCPU / 4 GB RAM es un buen punto de partida).
- Acceso **SSH** (`ssh root@IP_DEL_VPS`).
- Un **dominio** (o subdominio) apuntando con un registro **A** a la IP del VPS
  (p. ej. `api.tu-dominio.com` → `203.0.113.10`).
- El repositorio accesible por **git** (HTTPS con token, o una **deploy key** SSH).

---

## 1. Preparar el servidor

```bash
# Actualizar el sistema
sudo apt update && sudo apt upgrade -y

# Zona horaria (importante para fechas de emisión y scheduler)
sudo timedatectl set-timezone America/Lima

# Utilidades básicas
sudo apt install -y git unzip curl ca-certificates software-properties-common
```

### Firewall

```bash
sudo ufw allow OpenSSH
sudo ufw allow 'Nginx Full'   # 80 y 443
sudo ufw enable
sudo ufw status
```

---

## 2. Instalar PHP 8.3 y extensiones

```bash
# Repositorio de PHP (Ondřej Surý)
sudo add-apt-repository ppa:ondrej/php -y
sudo apt update

# PHP 8.3 + extensiones que usa la API
sudo apt install -y \
  php8.3-fpm php8.3-cli php8.3-common \
  php8.3-mysql php8.3-pgsql \
  php8.3-redis php8.3-gd php8.3-zip php8.3-soap \
  php8.3-mbstring php8.3-xml php8.3-curl php8.3-bcmath php8.3-intl php8.3-gmp
```

> `php8.3-gd` en Ubuntu ya viene compilado **con soporte WebP**, así que los
> logos `.webp` en los PDF funcionan sin recompilar (a diferencia del build de
> Docker en Alpine). `pcntl`/`posix` (que Horizon necesita) están incluidas en
> PHP de Linux por defecto — en Windows no existen, pero aquí sí.

Verifica:

```bash
php -v
php -m | grep -iE "pdo_mysql|redis|gd|soap|zip|bcmath|pcntl|posix|openssl"
```

### Ajustes de PHP recomendados (producción)

Edita `/etc/php/8.3/fpm/php.ini`:

```ini
memory_limit = 512M
upload_max_filesize = 20M
post_max_size = 25M
max_execution_time = 120
```

```bash
sudo systemctl restart php8.3-fpm
```

---

## 3. Instalar Nginx

```bash
sudo apt install -y nginx
sudo systemctl enable --now nginx
```

---

## 4. Instalar MySQL y crear la base de datos

```bash
sudo apt install -y mysql-server
sudo mysql_secure_installation   # define contraseña de root y responde "Y" a lo razonable
```

Crea la base de datos y un usuario dedicado (NO uses root para la app):

```bash
sudo mysql
```

```sql
CREATE DATABASE apisunat CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'apisunat'@'127.0.0.1' IDENTIFIED BY 'CAMBIA_ESTA_CLAVE';
GRANT ALL PRIVILEGES ON apisunat.* TO 'apisunat'@'127.0.0.1';
FLUSH PRIVILEGES;
EXIT;
```

> ¿Prefieres PostgreSQL? Instala `postgresql`, crea la BD/usuario y en el `.env`
> usa el bloque `pgsql`. El código soporta ambos.

---

## 5. Instalar Redis (para Horizon y caché)

```bash
sudo apt install -y redis-server
sudo systemctl enable --now redis-server
redis-cli ping   # debe responder: PONG
```

---

## 6. Instalar Composer y Node.js

```bash
# Composer (global)
curl -sS https://getcomposer.org/installer | php
sudo mv composer.phar /usr/local/bin/composer
composer --version

# Node.js 22 LTS (para compilar el frontend con Vite)
curl -fsSL https://deb.nodesource.com/setup_22.x | sudo -E bash -
sudo apt install -y nodejs
node -v && npm -v
```

---

## 7. Clonar el proyecto

```bash
# Carpeta estándar para apps web
sudo mkdir -p /var/www
sudo chown -R $USER:$USER /var/www
cd /var/www

# Clona tu repositorio (ajusta la URL)
git clone https://github.com/yorchavez9/plataform-api-sunat.git apisunat
cd apisunat
```

---

## 8. Instalar dependencias y compilar el frontend

```bash
cd /var/www/apisunat

# Dependencias PHP de producción (sin dev)
composer install --no-dev --optimize-autoloader

# Dependencias JS + build de producción (genera public/build)
npm ci
npm run build
```

> En Linux `composer install` no necesita flags de plataforma: `pcntl`/`posix`
> existen de verdad.

---

## 9. Configurar el entorno (`.env`)

```bash
cp .env.example .env
nano .env
```

Valores clave para **producción** (ajusta a tu caso):

```ini
APP_NAME="API SUNAT"
APP_ENV=production
APP_DEBUG=false
APP_URL=https://api.tu-dominio.com
APP_TIMEZONE=America/Lima
APP_LOCALE=es

# Base de datos
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=apisunat
DB_USERNAME=apisunat
DB_PASSWORD=CAMBIA_ESTA_CLAVE

# Colas con Horizon → OBLIGATORIO redis (no 'database')
QUEUE_CONNECTION=redis
CACHE_STORE=redis
SESSION_DRIVER=database
REDIS_CLIENT=phpredis
REDIS_HOST=127.0.0.1
REDIS_PORT=6379
REDIS_PASSWORD=null

# Correo (ver nota sobre SMTP bloqueado más abajo)
MAIL_MAILER=smtp
MAIL_HOST=...
MAIL_PORT=587
MAIL_USERNAME=...
MAIL_PASSWORD=...
MAIL_FROM_ADDRESS=no-reply@tu-dominio.com
```

Genera la clave de la app y prepara la base de datos:

```bash
php artisan key:generate
php artisan migrate --force        # --force: no pide confirmación en producción
php artisan storage:link
```

> ⚠️ `migrate --force` ejecuta las migraciones en producción. Es seguro para el
> primer despliegue; en actualizaciones revisa siempre qué migraciones entran.

Cachés de producción (más velocidad):

```bash
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

> Cada vez que cambies el `.env` debes volver a correr `php artisan config:cache`
> (o `config:clear`), porque con la config cacheada el `.env` ya no se relee.

---

## 10. Permisos de archivos

Nginx/PHP-FPM corren como `www-data`; necesita escribir en `storage` y `bootstrap/cache`:

```bash
sudo chown -R www-data:www-data /var/www/apisunat
sudo find /var/www/apisunat -type d -exec chmod 755 {} \;
sudo find /var/www/apisunat -type f -exec chmod 644 {} \;
sudo chmod -R 775 /var/www/apisunat/storage /var/www/apisunat/bootstrap/cache
```

---

## 11. Configurar Nginx

Crea `/etc/nginx/sites-available/apisunat`:

```nginx
server {
    listen 80;
    server_name api.tu-dominio.com;

    root /var/www/apisunat/public;
    index index.php;

    charset utf-8;
    client_max_body_size 25M;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        fastcgi_pass unix:/run/php/php8.3-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        include fastcgi_params;
    }

    location ~ /\.(?!well-known).* {
        deny all;
    }
}
```

Activa el sitio y recarga:

```bash
sudo ln -s /etc/nginx/sites-available/apisunat /etc/nginx/sites-enabled/
sudo rm -f /etc/nginx/sites-enabled/default
sudo nginx -t
sudo systemctl reload nginx
```

---

## 12. HTTPS con Let's Encrypt (Certbot)

```bash
sudo apt install -y certbot python3-certbot-nginx
sudo certbot --nginx -d api.tu-dominio.com
```

Certbot edita el server block para el puerto 443 y configura la renovación
automática. Verifica:

```bash
sudo certbot renew --dry-run
```

---

## 13. Colas en producción: Horizon como servicio

Horizon procesa las colas (`sunat`, `webhooks`, `mail`, `default`) y reintentos
de SUNAT. Lo dejamos como servicio **systemd** para que arranque solo y se
reinicie si cae.

Crea `/etc/systemd/system/horizon.service`:

```ini
[Unit]
Description=Laravel Horizon (API SUNAT)
After=network.target redis-server.service mysql.service

[Service]
User=www-data
Group=www-data
Restart=always
RestartSec=3
WorkingDirectory=/var/www/apisunat
ExecStart=/usr/bin/php /var/www/apisunat/artisan horizon

[Install]
WantedBy=multi-user.target
```

```bash
sudo systemctl daemon-reload
sudo systemctl enable --now horizon
sudo systemctl status horizon
```

> Tras cada despliegue con cambios de código hay que reiniciar los workers:
> `php artisan horizon:terminate` (Horizon se reinicia solo por systemd con el
> código nuevo).

---

## 14. Scheduler (tareas programadas) por cron

El scheduler dispara backups, reintentos SUNAT, avisos de plazo de envío,
conciliación de timeouts, verificación de certificados, etc.

```bash
sudo crontab -u www-data -e
```

Agrega **una** línea:

```cron
* * * * * cd /var/www/apisunat && php artisan schedule:run >> /dev/null 2>&1
```

> Con esa sola entrada, Laravel ejecuta internamente todas las tareas en su hora
> (no agregues una línea por comando).

---

## 15. Certificados SUNAT, credenciales y correo

- **Certificados (.pfx):** cada empresa sube su certificado y Clave SOL **desde el
  panel**, no por `.env`. No copies certificados a mano al servidor.
  Verifica su estado con:
  ```bash
  php artisan certificados:verificar
  ```
- **SMTP bloqueado:** muchos proveedores de VPS **bloquean los puertos de salida
  25/465/587** por defecto para evitar spam. Si los correos no salen:
  1. Pide al proveedor que te **desbloquee el puerto** (587 TLS suele ser el más
     fácil), **o**
  2. Usa un **servicio de correo transaccional por API/HTTP** (no afectado por el
     bloqueo de puertos SMTP).
- **Entorno SUNAT (beta/producción):** se configura **por empresa** en el panel,
  no globalmente. Prueba primero en **beta** antes de emitir real.

---

## 16. Verificación final

```bash
cd /var/www/apisunat

# Diagnóstico integral de la instalación (PHP, extensiones, BD, migraciones,
# caché, colas, certificados, conectividad SUNAT, licencia)
php artisan kodevo:doctor
```

Abre `https://api.tu-dominio.com` en el navegador y entra al panel. El
dashboard de Horizon está en `/horizon` (protegido por login de admin).

---

## 17. Actualizar la API (despliegues siguientes)

Guarda este bloque como `deploy.sh` en la raíz del proyecto para reusarlo:

```bash
#!/usr/bin/env bash
set -e
cd /var/www/apisunat

php artisan down || true                 # modo mantenimiento

git pull origin main
composer install --no-dev --optimize-autoloader
npm ci && npm run build                  # solo si cambió el frontend

php artisan migrate --force
php artisan config:cache
php artisan route:cache
php artisan view:cache

php artisan horizon:terminate            # recarga workers con el código nuevo
php artisan up                           # salir de mantenimiento
echo "Deploy OK"
```

```bash
chmod +x deploy.sh
./deploy.sh
```

---

## 18. Buenas prácticas de seguridad

- `APP_DEBUG=false` y `APP_ENV=production` **siempre** en producción.
- Usuario de BD **dedicado** (no root), contraseña fuerte.
- No expongas MySQL/Redis a internet (déjalos en `127.0.0.1`).
- Mantén los **backups** activos (`backup:run` ya está en el scheduler) y
  verifica que `mysqldump` esté disponible.
- Actualiza el sistema periódicamente (`apt upgrade`).
- Haz el primer flujo de emisión en **SUNAT beta** antes de pasar a real.

---

### Resumen del orden

1. Servidor + firewall → 2. PHP 8.3 + extensiones → 3. Nginx → 4. MySQL →
5. Redis → 6. Composer + Node → 7. Clonar → 8. `composer install` + `npm build` →
9. `.env` + `key:generate` + `migrate` + `storage:link` + cachés →
10. Permisos → 11. Nginx vhost → 12. HTTPS → 13. Horizon (systemd) →
14. Cron scheduler → 15. Certificados/SMTP → 16. `kodevo:doctor` →
17. Script de actualización.
