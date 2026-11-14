# Despliegue, Licencia y Certificado

Guía práctica para poner el sistema a funcionar **en cada entorno** (local, VPS, Docker) y resolver los dos candados que más confusión generan al pasar a **producción**:

1. **Licencia** — obligatoria para emitir a SUNAT en *producción* (en *beta* no se pide).
2. **Certificado digital** — cada empresa firma con el suyo; vive en `storage/`, que **no** viaja por git.

> **Regla de oro:** `beta` funciona sin licencia y sirve para probar todo. El candado de licencia y la firma con certificado solo se sienten cuando una empresa está en **producción**.

---

## 0. Variables `.env` relevantes

```env
# --- App ---
APP_ENV=production                 # production | local
APP_URL=https://tu-dominio.com     # dominio real de esta instalación

# --- Licencia (la entrega el proveedor al comprar) ---
LICENSE_ENABLED=true               # en producción SIEMPRE true (false solo aplica en local)
LICENSE_SERVER_URL=https://agenda.kodevo.es   # servidor donde se creó la licencia
LICENSE_KEY=kdv_live_xxxxxxxxxxxxxxxxxxxxxxxxx
LICENSE_SECRET=xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx
LICENSE_DOMAIN=https://tu-dominio.com          # opcional; por defecto usa APP_URL
LICENSE_GRACE_DAYS=7               # días que sigue operando si el server de licencias cae
```

- El **certificado** NO se pone en el `.env`: se sube por **Configuración → Certificado Digital** en el panel (o se coloca en `storage/`), porque contiene la **clave privada**.

---

## 1. Despliegue LOCAL (desarrollo)

```bash
composer install
npm install
cp .env.example .env
php artisan key:generate
php artisan migrate --seed
npm run dev            # Vite/HMR
php artisan serve      # http://127.0.0.1:8000
```

Licencia y certificado en local:
- Pon `APP_ENV=local`. Con `LICENSE_ENABLED=false` **solo en local** el candado se ignora (en un servidor real se ignora ese `false`).
- Para probar emisión usa una empresa en **beta**: no requiere licencia ni certificado propio (puedes cargar el certificado **demo** de SUNAT desde Configuración).

### 1.1 Instalación local para un cliente (sin correr el worker a mano)

En una PC de cliente (Laragon/Windows) NO conviene pedirle que mantenga abierto
`php artisan queue:work`. Para eso hay un instalador de un solo clic:

```
instalar-local.bat      (doble clic, una sola vez)
desinstalar-local.bat   (para revertir la tarea programada)
```

`instalar-local.bat` hace tres cosas:

1. Pone `QUEUE_CONNECTION=sync` en el `.env` (respaldo en `.env.bak`). Con esto
   **las facturas, boletas y notas se envían a SUNAT al instante**, dentro de la
   misma petición HTTP, sin necesidad de ningún worker.
2. Registra una tarea programada de Windows (`JorgeChavezApiSunat-Scheduler`) que
   corre `php artisan schedule:run` **cada minuto, oculta**. Esto cubre lo único
   que en `sync` no es inmediato: cerrar los **tickets de resúmenes y anulaciones**
   (`summaries:poll-pending`) y **recuperar comprobantes atascados**
   (`sunat:reintentar-pendientes`).
3. Drena cualquier job que hubiera quedado en la tabla `jobs` de una instalación
   previa con driver `database`.

El cliente nunca abre una terminal. Detecta `php.exe` automáticamente (PATH o
`C:\laragon\bin\php\...`). Si no puede registrar la tarea, ejecutar el `.bat`
**como Administrador**.

> Alternativa (mantener `QUEUE_CONNECTION=database`): registrar `queue:work` como
> servicio/tarea de Windows en 2do plano. Es más frágil (si el worker se cae, deja
> de procesar). Para local, `sync` + la tarea del scheduler es más simple y robusto.

---

## 2. Despliegue en VPS (sin Docker)

Guía detallada de servidor (Nginx + MySQL + SSL + worker + cron): [`20-Despliegue-VPS.md`](vps-desde-cero.md). Para **actualizar** una instalación ya montada existe el script `deploy-vps.sh` (git pull + limpiar/cachear).

Puesta a producción (resumen):

```bash
# como usuario deploy, en la raíz del proyecto
git pull origin main
composer install --no-dev --optimize-autoloader
npm ci && npm run build
php artisan migrate --force

# IMPORTANTE: editar .env con LICENSE_* y APP_ENV=production, luego:
php artisan config:clear && php artisan cache:clear
php artisan config:cache && php artisan route:cache && php artisan view:cache

# Activar la licencia (una sola vez por instalación) — ver §4
php artisan license:activate
php artisan license:status
```

> Si tocas el `.env` **después** de `config:cache`, corre `config:clear` de nuevo o los cambios no se leen.

---

## 3. Despliegue con Docker

El proyecto trae `Dockerfile` (PHP 8.3-fpm-alpine) y `docker/start.sh` (migra, cachea y sirve). Build y run:

```bash
docker build -t api-pro .
docker run -d --name api-pro -p 8000:8000 --env-file .env \
  -v api_pro_storage:/app/storage \
  api-pro
```

Puntos críticos en Docker:

1. **Persistir `storage/`** con un volumen (`-v api_pro_storage:/app/storage`). Ahí viven **certificados, XML, CDR y PDF**. Sin volumen, se pierden al recrear el contenedor y las empresas volverían a quedar *SIN CERT*.
2. **Variables de licencia** por `--env-file .env` (o `-e LICENSE_KEY=... -e LICENSE_SECRET=...`).
3. **Activar la licencia dentro del contenedor** (una vez):
   ```bash
   docker exec -it api-pro php artisan license:activate
   docker exec -it api-pro php artisan license:status
   ```
4. `start.sh` ya corre `migrate --force` y cachea config/rutas en cada arranque; no necesitas hacerlo a mano.
5. Al **reconstruir la imagen** puede cambiar la huella de la instalación (fingerprint/machine_id) y consumir un cupo de activación. Si el plan permite 1 instalación, libera la activación anterior en el panel del proveedor antes de reactivar (ver §4.4).

---

## 4. Activación de la licencia (el error más común)

### 4.1 Síntoma
Al emitir en **producción** SUNAT devuelve:

```json
"sunat": {
  "estado": "rechazado",
  "codigo": "CERT_ERROR",
  "descripcion": "Emisión no disponible: Esta instalación no está activada. Activa la licencia primero."
}
```

> **Crear la licencia en el panel del proveedor NO la activa en el servidor del cliente.** Son dos pasos: emitir la licencia (proveedor) y **activar la instalación** (cliente, con `license:activate`).

### 4.2 Cómo activar

**Opción A — desde el PANEL (recomendada):** el cliente entra a
**Administración → Licencia** (`/admin/licencia`, solo super admin/admin), pega la
**Clave** y el **Secreto** de su licencia y pulsa **Activar**. Las credenciales se
guardan en la base de datos (el secreto **cifrado**) y pisan a las de `.env`, así
que **regenerar el `.env` ya no borra la licencia** (evita la "trampa" de §4.4).
La página muestra el estado en vivo (activa / gracia / no activa) y la instalación
(machine id). La activación queda registrada en la **bitácora** (`licencia.activada`).

**Opción B — por terminal** (equivalente), con `LICENSE_KEY`/`LICENSE_SECRET` en `.env`:
```bash
php artisan config:clear && php artisan cache:clear
php artisan license:activate      # registra dominio + machine_id + fingerprint
php artisan license:status        # debe quedar OK/activa
```

`license:activate` imprime el **código y mensaje exacto** del servidor — úsalo para diagnosticar.

> Internamente ambas opciones hacen lo mismo (registran la instalación en el
> servidor). La diferencia es dónde quedan las credenciales: el panel las guarda
> en BD (persistentes); la terminal depende de que vivan en `.env`.

### 4.3 Qué envía la activación
`POST {LICENSE_SERVER_URL}/api/v1/licencias/activar` con cabeceras `X-License-Key` / `X-License-Secret` y cuerpo `{ machine_id, domain, app_version, fingerprint }`. El servidor registra esa instalación contra la licencia (hasta el máximo del plan).

### 4.4 Si `license:activate` falla
| Causa | Cómo se ve | Solución |
|---|---|---|
| **Server URL equivocado** | "no encontrada" / "inválida" | `LICENSE_SERVER_URL` debe apuntar al servidor donde creaste la licencia (`https://agenda.kodevo.es` por defecto). |
| **Config cacheada** | "Faltan LICENSE_KEY/SECRET" pese a estar en `.env` | `php artisan config:clear` (habían hecho `config:cache` antes de poner las variables). |
| **Dominio no coincide** | "dominio no autorizado" | El host de `APP_URL`/`LICENSE_DOMAIN` debe ser el mismo que autorizaste en el panel. |
| **Cupo de instalaciones lleno** | "máximo de instalaciones" | El plan permite N copias; libera una activación anterior en el panel o sube de plan. |
| **Server caído / sin red** | `sin_conexion` | Reintenta; hay `LICENSE_GRACE_DAYS` de gracia desde la última validación buena. |

### 4.5 Mientras tanto
La empresa puede operar en **beta** (sin licencia) para no frenar las pruebas. El candado solo aplica a empresas en `produccion`.

---

## 5. Certificado digital

Cada empresa firma sus comprobantes con **su** certificado `.p12/.pfx`. Es obligatorio incluso para que SUNAT acepte en beta.

- **Se sube desde el panel**: Configuración → Certificado Digital (archivo + contraseña). El sistema lo **valida, convierte a PEM** y guarda la clave cifrada.
- **No viaja por git**: vive en `storage/app/private/…` (contiene la clave privada). En cada servidor/entorno nuevo hay que **subirlo una vez**.
- **Beta**: usa el **certificado demo** de SUNAT ("CERTIFICADO PARA DEMOSTRACIÓN").
- **Producción**: el certificado real de tu certificadora.

Comando de verificación (en cualquier servidor):

```bash
php artisan certificados:verificar
```

Errores típicos:
- **`SIN CERT / No hay archivo cargado`** → no se subió el certificado en ESE servidor. Súbelo por Configuración.
- **Existe el archivo pero no firma (permisos)** → si lo copiaste como `root`, el proceso web (deploy/www-data) no puede leerlo:
  ```bash
  chown -R deploy:deploy storage/app/private/tenants/<id>/certs
  chmod ug+rw storage/app/private/tenants/<id>/certs/cert.pem
  ```

> Aviso en el panel: *"El certificado digital y el usuario secundario SOL pueden tardar hasta 24 horas en activarse en SUNAT tras registrarlos."*

---

## 6. Checklist de "puesta en producción"

- [ ] `.env`: `APP_ENV=production`, `APP_URL`, DB, `LICENSE_KEY/SECRET`, `LICENSE_SERVER_URL`.
- [ ] `php artisan migrate --force`.
- [ ] `config:clear && cache:clear` → `config:cache && route:cache && view:cache`.
- [ ] `php artisan license:activate` → `license:status` OK.
- [ ] Subir el **certificado** de cada empresa por Configuración (o dejar la empresa en **beta** para probar).
- [ ] `php artisan certificados:verificar` → puede firmar.
- [ ] Worker de colas + scheduler corriendo (ver [`20-Despliegue-VPS.md`](vps-desde-cero.md)).
- [ ] (Docker) `storage/` en un **volumen** persistente.
