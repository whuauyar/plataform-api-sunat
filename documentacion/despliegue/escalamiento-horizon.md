# Escalamiento: Redis, workers, Horizon, índices y Sentry

Guía para pasar de ~50 comprobantes/min (cola `database`, 2 workers) a **miles/min**
con equidad entre clientes, más observabilidad (Horizon/Sentry) e índices de reportes.
Aplica sobre un VPS ya desplegado (ver [`vps-desde-cero.md`](./vps-desde-cero.md)).

> **Qué cambió en el código (ya commiteado):**
> - Colas dedicadas: `sunat` (envíos), `webhooks`, `mail`, `default`. Un flood de
>   facturas ya **no** atrasa correos ni webhooks.
> - Equidad por tenant: rate limiter `sunat-tenant` — cada RUC drena a su ritmo
>   (`SUNAT_PER_TENANT_PER_MINUTE`, default 120/min).
> - `predis/predis` agregado (cliente Redis en PHP).

---

## 1. Redis en el VPS

```bash
sudo apt update && sudo apt install -y redis-server
sudo systemctl enable --now redis-server
redis-cli ping        # → PONG
```

En `/etc/redis/redis.conf` (evita quedarse sin RAM y descartar jobs):

```conf
maxmemory 512mb
maxmemory-policy noeviction     # NO descartar jobs de la cola
```

```bash
sudo systemctl restart redis-server
```

## 2. `.env`

```ini
QUEUE_CONNECTION=redis          # antes: database
CACHE_STORE=redis               # rate limiter por tenant exacto y rápido
REDIS_CLIENT=predis
REDIS_HOST=127.0.0.1
REDIS_PORT=6379
REDIS_PASSWORD=null
SUNAT_PER_TENANT_PER_MINUTE=120 # máx comprobantes/min por RUC
```

```bash
php artisan config:clear && php artisan config:cache
```

> Los jobs en la tabla `jobs` (driver database) **no** migran solos a Redis. Drená
> la cola vieja ANTES de cambiar: `php artisan queue:work database --stop-when-empty`.

---

## 3. Workers — Opción A: Supervisor (simple)

Dos pools que aíslan prioridades. `/etc/supervisor/conf.d/api-pro-worker.conf`:

```ini
; Pool 1 — ENVÍOS A SUNAT (alto volumen). Escala numprocs según CPU/RAM.
[program:api-pro-sunat]
process_name=%(program_name)s_%(process_num)02d
command=php /home/deploy/plataform-api-sunat/artisan queue:work redis --queue=sunat --sleep=1 --tries=20 --max-time=3600 --max-jobs=500
autostart=true
autorestart=true
stopasgroup=true
killasgroup=true
user=deploy
numprocs=10
redirect_stderr=true
stdout_logfile=/home/deploy/plataform-api-sunat/storage/logs/worker-sunat.log
stopwaitsecs=3600

; Pool 2 — webhooks, correos y tareas varias (no compiten con SUNAT).
[program:api-pro-otros]
process_name=%(program_name)s_%(process_num)02d
command=php /home/deploy/plataform-api-sunat/artisan queue:work redis --queue=webhooks,mail,sire-process,sire-heavy,sire-poll,default --sleep=3 --tries=3 --max-time=3600
autostart=true
autorestart=true
stopasgroup=true
killasgroup=true
user=deploy
numprocs=3
redirect_stderr=true
stdout_logfile=/home/deploy/plataform-api-sunat/storage/logs/worker-otros.log
stopwaitsecs=120
```

```bash
sudo supervisorctl reread && sudo supervisorctl update && sudo supervisorctl status
```

**Dimensionar `numprocs`:** cada worker ≈ 1 conexión concurrente a SUNAT y ~40-80 MB RAM.
VPS 4 GB → ~10 workers SUNAT; 8-16 GB → 20-40. El límite real lo pone **SUNAT por RUC** (§6).

---

## 4. Workers — Opción B: Laravel Horizon (recomendado)

Horizon añade dashboard en vivo, auto-balanceo y métricas. **Requiere Redis**
(`QUEUE_CONNECTION=redis`) y `ext-pcntl`/`ext-posix` (existen en Linux, no en Windows).

```bash
composer install --no-dev          # trae horizon en Linux
php artisan horizon:publish         # publica los assets del dashboard
```

`config/horizon.php` ya define dos supervisores: **`sunat`** (cola crítica, rate-limited)
y **`otros`** (webhooks, mail, las colas SIRE `sire-process`/`sire-heavy`/`sire-poll`, y default).
Los `timeout` (85/60s) quedan por debajo del
`retry_after` (90s) de la conexión redis para no reprocesar un job vivo; cada job define
su propio `$tries` (ej. `SendDocumentToSunat` = 20).

Supervisor corre **un solo** proceso `horizon` (él gestiona los workers):

```ini
[program:api-pro-horizon]
process_name=%(program_name)s
command=php /home/deploy/plataform-api-sunat/artisan horizon
autostart=true
autorestart=true
user=deploy
redirect_stderr=true
stdout_logfile=/home/deploy/plataform-api-sunat/storage/logs/horizon.log
stopwaitsecs=3600
```

En **Docker** (entorno B): un servicio con `command: php artisan horizon` que reemplaza
al `queue:work`.

- **Dashboard** en `https://tudominio/horizon`, protegido por `web` + `auth` + gate
  `viewHorizon` (`HorizonServiceProvider`): **solo admin activo** (`isSuperAdmin() || is_admin`),
  porque permite reintentar y borrar jobs.
- En cada deploy: `php artisan horizon:terminate` (Supervisor lo reinicia con el código nuevo).
- En Windows (dev) Horizon no corre; para `composer install`:
  `--ignore-platform-req=ext-pcntl --ignore-platform-req=ext-posix`.

---

## 5. Índices de base de datos (reportes)

Las tablas de comprobantes (`invoices`, `boletas`, `credit_notes`, `debit_notes`,
`dispatch_guides`, `summaries`, `voided_documents`) ya tienen índices base
`(tenant_id, sunat_status)`, `(tenant_id, fecha_emision)`, `(tenant_id, client_num_doc)`,
`(tenant_id, created_at)` y únicos `(tenant_id, serie, correlativo)`.

La migración `2026_10_03_130000_add_report_indexes.php` (idempotente) completa los de reportes:
- `(tenant_id, cod_local, fecha_emision)` → reportes por sucursal.
- `(tenant_id, payment_status, fecha_vencimiento)` → cuentas por cobrar.
- `dispatch_guides (tenant_id, destinatario_num_doc)` → guías por destinatario.

```bash
php artisan migrate --force
```

> Estas tablas ya **no** están particionadas (la antigua `documents` se dividió por
> tipo); tienen PK simple, así que admiten índices compuestos sin restricciones.

---

## 6. El límite REAL: SUNAT por RUC

Aunque tengas 50 workers, **SUNAT limita por RUC**. No se pueden empujar 1000
facturas/min de un mismo RUC de forma síncrona:
- El rate limiter `sunat-tenant` (120/min por RUC) evita saturar a SUNAT y reparte los
  workers entre todos los tenants.
- **Boletas de alto volumen → resumen diario** (`SummaryController`): en vez de enviar
  cada boleta 1×1, se acumulan y se mandan en UN resumen (~99% menos llamadas).

Ajustá `SUNAT_PER_TENANT_PER_MINUTE` según lo que SUNAT tolere en tu caso.

---

## 7. Sentry (monitoreo de errores, opcional)

`sentry/sentry-laravel` reporta excepciones no controladas (enganchado en
`bootstrap/app.php` dentro de `withExceptions`). **Seguro por defecto**: si
`SENTRY_LARAVEL_DSN` está vacío, es un *no-op*.

```env
SENTRY_LARAVEL_DSN=            # DSN del proyecto (vacío = off)
SENTRY_TRACES_SAMPLE_RATE=0.1  # muestreo de performance (bajo en prod)
SENTRY_SEND_DEFAULT_PII=false  # no enviar IP/cookies/datos personales
```

Probar: `php artisan sentry:test` (con el DSN configurado).

---

## 8. Verificación

```bash
php artisan about --only=drivers | grep Queue   # → redis
redis-cli llen queues:sunat                      # profundidad de la cola sunat
sudo supervisorctl status                        # workers/horizon RUNNING
tail -f storage/logs/worker-sunat.log            # (o horizon.log)
```
