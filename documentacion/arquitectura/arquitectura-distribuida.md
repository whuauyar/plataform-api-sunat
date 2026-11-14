# 31 · Arquitectura distribuida: DB en otro servidor y archivos en otro servidor

Investigación de qué se necesita para correr la API con la **base de datos en un
servidor aparte** y los **archivos (XML/CDR/PDF/logos) en otro servidor**, y qué
falta en el código. Incluye el estado actual, los huecos y los pasos concretos.

---

## Resumen ejecutivo

| Pieza | ¿Listo para servidor aparte? | Qué hacer |
|---|---|---|
| **Base de datos** | ✅ Sí (por env) | Apuntar `DB_HOST/...` al servidor remoto; abrir firewall; SSL |
| **Caché / colas / sesión** | ✅ Sí, si se centraliza | Usar Redis o DB central (no driver `file`) |
| **Archivos (comprobantes)** | 🟡 Capa lista, faltan 3 huecos | S3/MinIO + resolver dependencias de ruta local (abajo) |
| **Logos/favicones** | 🟡 | Igual que archivos; además el PDF los lee por ruta local |
| **Certificados** | 🟡 | Guardar por disco (no ruta absoluta) |

> Regla de oro: para repartir en varios servidores, **nada de estado en disco
> local del nodo de la app** (archivos, sesiones, caché, colas). Todo a un
> servicio compartido (DB, Redis, S3).

---

## 1. Base de datos en otro servidor ✅

Ya es 100% configurable por entorno (`config/database.php`). Solo el `.env`:

```env
DB_CONNECTION=pgsql           # o mysql
DB_HOST=10.0.0.5              # IP/host del servidor de BD
DB_PORT=5432
DB_DATABASE=api_sunat
DB_USERNAME=api_sunat
DB_PASSWORD=********
# TLS:
DB_SSLMODE=require            # pgsql
# MYSQL_ATTR_SSL_CA=/ruta/ca.pem   # mysql
```

- Soporta **MySQL y PostgreSQL** con TLS.
- Hay bloque para **réplica de lectura** (read/write split) si se necesita escalar
  lecturas: configurar `read`/`write` en la conexión.
- **Migraciones**: se corren desde el nodo de la app (`php artisan migrate`); la
  BD remota solo necesita conectividad y permisos.

**Gotchas:**
- **Latencia**: cada query es un viaje de red. Mantener app y BD en la misma
  región/VPC. Evitar N+1.
- **Motor**: producción usa **PostgreSQL**; cuidado con diferencias MySQL↔PG ya
  conocidas (`whereJsonContains`, ver [anulaciones](../emision/anulaciones.md)).
- **Firewall/acceso**: abrir el puerto solo a la IP del nodo de la app.

---

## 2. Caché, colas y sesión (si hay varios nodos de app)

Por defecto `CACHE_STORE=database` y `QUEUE_CONNECTION=database`; producción usa
**Redis** (ver [escalamiento-horizon](../despliegue/escalamiento-horizon.md)). Si la app corre en **más de un
servidor**, estos backends deben ser **compartidos** (Redis o DB en un servidor
central), nunca `file`/`array` locales:

```env
CACHE_STORE=redis
QUEUE_CONNECTION=redis
SESSION_DRIVER=redis          # o database
REDIS_HOST=10.0.0.6
```

- El **worker de colas** puede vivir en otro nodo mientras vea el mismo Redis/DB.
- La **sesión** del panel debe ser compartida (redis/database) o el login se
  pierde entre nodos.

---

## 3. Archivos en otro servidor 🟡

### Estado actual
Los comprobantes (XML/CDR/PDF) ya pasan por un único punto:
- **Escritura/lectura**: helper `App\Support\Storage\Comprobantes` (doc 30).
- **Disco configurable**: `config/comprobantes.php` → `COMPROBANTES_DISK`.
- Está definido el disco **`s3`** en `config/filesystems.php` (AWS/DO Spaces/MinIO).

Para guardar en otro servidor, lo recomendado es **S3-compatible** (AWS S3,
DigitalOcean Spaces o **MinIO** autohospedado en el servidor de archivos). Un
NFS/SMB montado “como local” también funciona pero es más frágil (latencia,
permisos, locks).

Config para apuntar a un servidor de archivos (ej. MinIO):
```env
COMPROBANTES_DISK=s3
AWS_ACCESS_KEY_ID=...
AWS_SECRET_ACCESS_KEY=...
AWS_DEFAULT_REGION=us-east-1
AWS_BUCKET=comprobantes
AWS_ENDPOINT=https://files.midominio.com     # el otro servidor (MinIO)
AWS_USE_PATH_STYLE_ENDPOINT=true             # MinIO suele requerirlo
```

### Huecos de ruta local — RESUELTOS ✅

Ya no quedan dependencias de ruta de archivo local; todo lee **contenido** por el
disco, así que funciona con storage local y remoto (S3/MinIO):

1. **Export ZIP** — `ExportController` ahora usa
   `$zip->addFromString($nombre, Comprobantes::get($path))` (contenido por disco,
   sin ruta local).
2. **Logo en PDFs** — `DocumentDataMapper::getLogoBase64` y
   `ReportPdfService::getLogoBase64` leen el logo por `Storage::disk('public')`
   (contenido + `mimeType`) y lo incrustan como data URI base64 (dompdf no
   descarga URLs).
3. **Certificado** — el panel admin guarda `certificate_path` **relativo** (las
   demás vías ya lo hacían vía `storeCertificate`); `getCertificateContent()` lo
   lee por disco. (`getCertificatePath` —ruta absoluta— no se usa para leer.)
4. **SIRE** — `ProcessPropuestaJob` baja el ZIP a un temporal
   (`tempnam`) y lo procesa, en vez de asumir ruta local del disco.

> Resultado: subiendo el `local`/`comprobantes`/`public` a S3-compatible, las
> descargas, el export y los PDFs (con logo) funcionan sin tocar más código.

### Logos/favicones
Hoy en disco `public` (se sirven por URL). Para otro servidor: disco `public` a S3
con `url` pública del bucket, y resolver el punto (2) del PDF.

---

## 4. Arquitectura recomendada

```
            ┌──────────────┐        ┌──────────────┐
  Internet →│  App (web +  │──SQL──→│  BD (Postgres)│  servidor 2
            │   API, N nodos)│       └──────────────┘
            │              │──────→ ┌──────────────┐
            │   workers     │  obj  │  Archivos     │  servidor 3
            └──────┬───────┘  S3    │  (MinIO/S3)   │
                   │                └──────────────┘
                   └──Redis──→ ┌──────────────┐
                               │ Redis (caché, │  servidor 4 (o junto a BD)
                               │ colas, sesión)│
                               └──────────────┘
```
Variante simple: App + workers en un servidor, BD en otro, archivos en otro,
Redis junto a la BD. Lo importante es que **todo el estado compartido** (BD,
Redis, archivos) sea alcanzable por todos los nodos de app/worker.

---

## 5. Pasos concretos

1. **BD remota**: setear `DB_*` en el `.env` del nodo de app; `php artisan migrate`.
2. **Redis central**: `CACHE_STORE/QUEUE_CONNECTION/SESSION_DRIVER=redis`, `REDIS_HOST`.
3. **Archivos**:
   - Corto plazo (sin tocar código): dejar comprobantes en disco local/NFS.
   - Para S3/MinIO (ya soportado):
     1. Configurar el disco S3 (`AWS_*` + endpoint) en el `.env`.
     2. `php artisan comprobantes:migrar-a-s3 --dry-run` (revisar) y luego
        `php artisan comprobantes:migrar-a-s3` (copia XML/CDR/PDF existentes al
        bucket desde local/público; idempotente).
     3. Poner `COMPROBANTES_DISK=s3` → los nuevos escritos van a S3.
     4. (Opcional) `comprobantes:migrar-a-s3 --borrar-origen` para limpiar los
        archivos locales/públicos ya copiados.

   El comando también migra, con flags: `--incluir-certificados` (certificados
   .pem, al destino privado) y `--incluir-logos` (logos/favicones de empresa y
   plataforma, con visibilidad pública; usar `--destino-publico=<disco>` si el
   bucket de logos es distinto). Para que las URL de logos funcionen, el disco
   `public` debe apuntar a ese bucket con su `url` pública.
4. Verificar descargas (XML/CDR/PDF, export, PDFs con logo) tras el cambio.

---

## 6. Otros faltantes / recomendaciones (generales)
- **Backups** separados de BD y del bucket de archivos.
- **Salud/observabilidad**: healthcheck de BD/Redis/almacenamiento.
- **Secretos** por entorno (no en repo); rotación de credenciales S3.
- **Certificados .p12**: cifrados en reposo (ya se cifra `certificate_password`);
  si van a S3, cifrado del bucket.

## Hecho en esta tanda
- `config/comprobantes.php` + `COMPROBANTES_DISK` (disco de comprobantes
  seleccionable por env).
- `Comprobantes` helper lee el disco desde config (listo para apuntar a S3).
