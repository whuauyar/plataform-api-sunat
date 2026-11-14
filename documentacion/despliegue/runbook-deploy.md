# 34 · Nota de despliegue — Robustez, Escalabilidad y Observabilidad

Runbook para desplegar en el VPS todo lo de la auditoría: **backups + alertas**
(doc 33), **Horizon + índices + Sentry** (doc 35) y el **health endpoint** (doc 33).
Incluye el `migrate` de las tandas de seguridad por si quedó pendiente. Separado
por los dos entornos de producción (A: Hetzner/MySQL, B: Docker/Postgres).

---

## 0. Base (ambos entornos)
```bash
git pull
composer install --no-dev --optimize-autoloader
php artisan migrate --force   # idempotency_keys, cifrado de api_secret, índices de reportes
php artisan config:cache
```
> `composer install` trae **Horizon** y **Sentry**. En Linux `ext-pcntl`/`ext-posix`
> existen, así que Horizon instala sin problema (el `--ignore-platform-req` era
> solo para Windows en desarrollo).

---

## 1. Variables en el `.env`
```env
# --- Alertas (Slack / Discord / n8n). Vacío = solo al log ---
OPS_ALERT_WEBHOOK_URL=https://hooks.slack.com/services/XXXX
OPS_ALERT_LABEL="Jorge Chavez API SUNAT"

# --- Backups ---
BACKUP_DISK=s3                 # 's3' recomendado; 'local' si aún no hay bucket
BACKUP_ARCHIVE_PASSWORD=pon-una-clave-larga-aqui   # cifra el .zip (incluye .env)

# --- Colas / Horizon (REQUIERE Redis) ---
QUEUE_CONNECTION=redis         # Horizon no opera con 'database'
HORIZON_PATH=horizon

# --- Sentry (opcional; vacío = desactivado) ---
SENTRY_LARAVEL_DSN=            # DSN del proyecto en sentry.io
SENTRY_TRACES_SAMPLE_RATE=0.1
SENTRY_SEND_DEFAULT_PII=false
```
> Si `BACKUP_DISK=s3`, deben estar configuradas las `AWS_*` (las mismas del storage).

Tras tocar el `.env`:
```bash
php artisan config:cache
```

---

## 2. Binario de volcado de BD (imprescindible para `backup:run`)

**Entorno A — Hetzner (MySQL, bare-metal):**
```bash
mysqldump --version   # si responde, ya está. Si no:
sudo apt-get update && sudo apt-get install -y mysql-client
```

**Entorno B — Docker (Postgres):** añadir el cliente a la imagen de `api-sunat`.
En su `Dockerfile`:
```dockerfile
RUN apt-get update && apt-get install -y postgresql-client && rm -rf /var/lib/apt/lists/*
```
Reconstruir:
```bash
docker compose build api-sunat && docker compose up -d
```

---

## 3. Horizon (colas) — reemplaza a `queue:work`

Requiere `QUEUE_CONNECTION=redis` (paso 1). Publica los assets del dashboard:
```bash
php artisan horizon:publish
```

**Entorno A — Supervisor** (en vez del `queue:work` anterior):
```ini
[program:api-sunat-horizon]
command=php /home/deploy/plataform-api-sunat/artisan horizon
directory=/home/deploy/plataform-api-sunat
autostart=true
autorestart=true
user=deploy
stopwaitsecs=3600
```
```bash
sudo supervisorctl reread && sudo supervisorctl update && sudo supervisorctl start api-sunat-horizon
```

**Entorno B — Docker** (el servicio de worker pasa a correr `horizon`):
```yaml
  api-sunat-worker:
    image: <misma imagen de api-sunat>
    command: php artisan horizon     # antes: queue:work --queue=sunat,webhooks,...
    env_file: ./docker/api-sunat.env
    restart: unless-stopped
```
En cada deploy, reinicia los workers para tomar el código nuevo:
```bash
php artisan horizon:terminate        # Horizon rearranca solo (Supervisor/restart)
```
Dashboard: **`/horizon`** (solo administradores; protegido por el gate `viewHorizon`).

---

## 4. El scheduler debe estar corriendo

**Entorno A — cron** (`crontab -e`, una línea):
```
* * * * * cd ~/plataform-api-sunat && php artisan schedule:run >> /dev/null 2>&1
```

**Entorno B — Docker** (servicio dedicado en `docker-compose.yml`):
```yaml
  api-sunat-scheduler:
    image: <misma imagen de api-sunat>
    command: php artisan schedule:work
    env_file: ./docker/api-sunat.env
    restart: unless-stopped
```
```bash
docker compose up -d api-sunat-scheduler
```

---

## 5. Health endpoint + monitoreo

`GET /api/health` (público) verifica BD/Redis/disco/almacenamiento/SUNAT.
Responde **200** si la API opera, **503** si un componente propio cae.
```bash
curl -i https://TU-DOMINIO/api/health
```
En **uptime-kuma** (ya presente en el entorno B): monitor HTTP(s) a
`https://TU-DOMINIO/api/health`, considerar "up" solo con código **200**.

---

## 6. Sentry

Si pusiste `SENTRY_LARAVEL_DSN`, confirma que reporta:
```bash
php artisan sentry:test
```
(Sin DSN, Sentry es un *no-op*; no afecta nada.)

---

## 7. Prueba rápida (todo junto)
```bash
php artisan ops:salud                 # BD, Redis, disco, SUNAT → "Todo saludable"
php artisan backup:run --only-db      # debe terminar en "Backup completed!"
php artisan backup:list               # confirma que aparece el backup
php artisan ops:jobs-fallidos         # cuántos jobs fallidos hay
curl -i https://TU-DOMINIO/api/health # 200 + estado "ok"
```
**Prueba de humo del webhook** (que llegue una alerta real): baja temporalmente el
umbral de disco y corre la salud con `--alertar`:
```bash
OPS_DISCO_MIN_LIBRE_PCT=99 php artisan ops:salud --alertar
```
Debe llegar el mensaje al webhook. Luego deja `OPS_DISCO_MIN_LIBRE_PCT=10` otra vez.

---

## 8. Qué queda corriendo solo (nada más que hacer)
| Tarea | Cuándo |
|---|---|
| `backup:clean` / `backup:run` / `backup:monitor` | 01:00 / 01:30 / 09:00 |
| `certificados:verificar --alertar` | diario 08:00 |
| `ops:jobs-fallidos --alertar` | cada hora |
| `ops:salud --alertar` | cada 10 min |

Todas las entradas viven en `routes/console.php`. Las colas las gestiona Horizon
(dashboard en `/horizon`).

---

## ⚠️ Recordatorios del proyecto
- **Nunca** `git clean -fd` en el VPS (borra el working tree).
- Entorno A: ante 404 con git "up to date", el working tree está sucio →
  revisar que no haya ediciones a mano a conservar y `git reset --hard origin/main`.
- Horizon **requiere Redis**; si `QUEUE_CONNECTION` sigue en `database`, las colas
  no se procesan por Horizon.
- Rotar las credenciales que se compartieron por chat en sesiones anteriores
  (client_secret y la contraseña del certificado).
