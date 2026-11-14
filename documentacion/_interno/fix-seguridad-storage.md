# 30 · Estados SUNAT, recuperación por CDR, CDR de guías y seguridad del storage

Correcciones a partir de reportes de clientes. Causa raíz, fix y pendientes.

---

## 1. Estado mal marcado: 3xxx se tomaba como "aceptado" (URGENTE) ✅

**Síntoma:** comprobantes quedaban `aceptado` aunque SUNAT los rechazó
(ej. códigos 3127, 3265).

**Causa raíz:** el código trataba **cualquier `3xxx`** como "observación →
aceptado". En SUNAT los rangos son: `0`=aceptado, **`1000–3999`=RECHAZO**,
**`4000+`=observación** (aceptado con advertencias). Solo 4000+ es aceptado.

**Fix:** la detección de observación pasa de `empieza con '3'` a **`código >= 4000`**
en los tres puntos donde estaba:
- `GreenterService::send` (CDR de BillResult y rama de SOAP fault).
- `SendDocumentToSunat` (rama de error).
Los `2xxx/3xxx` ahora caen a `markAsRejected` (que ya manejaba bien los 2xxx).

---

## 2. "Aceptado pero quedó pendiente" + reintentos que se multiplican (URGENTE) ✅

**Síntoma:** una boleta aceptada por SUNAT (CDR código 0) quedó `pendiente`
con `SUNAT_TIMEOUT`; al reenviarla, SUNAT respondía Bad Request (ya la tenía) y
cada fallo generaba más jobs (llegó a ~40 de la misma boleta).

**Causa raíz:** cuando la respuesta de SUNAT se pierde por timeout, el sistema
no sabía que el comprobante **sí** fue aceptado; lo dejaba pendiente y lo
reenviaba, acumulando jobs.

**Fix (`SendDocumentToSunat`):**
- **Recuperación por CDR (`recoverFromCdr`):** antes de reenviar un documento en
  `pendiente/SUNAT_TIMEOUT`, y al agotar los reintentos, se **consulta el CDR en
  SUNAT**. Si ya fue procesado, se fija el estado real (aceptado / observado →
  `aceptado` y se guarda el CDR; `1000–3999` → `rechazado`) en lugar de reenviar.
- **Idempotencia:** un documento ya `aceptado`/`anulado` no se reenvía (guard).
- **`WithoutOverlapping` por documento:** evita dos envíos simultáneos del mismo
  comprobante (no más jobs duplicados).

> Con esto, un comprobante aceptado pese al timeout se autocorrige a `aceptado` y
> no se generan reintentos sobre algo que SUNAT ya tiene.

---

## 3. CDR de guías: GET /guias-remision/{id}/cdr daba 404 ✅

**Causa:** el controlador de guías exponía `xml` y `pdf`, pero **no `cdr`**.

**Fix:** se agrega `DispatchGuideController::cdr` y la ruta
`GET /api/v1/guias-remision/{id}/cdr` (sirve `cdr_path` desde storage, igual que
el XML). Útil porque el CDR de la GRE trae el enlace `descargaqr` del QR.

---

## 4. Seguridad del storage (URGENTE) — estado y plan

**Hallazgos:**
- **Certificados: ya están en disco PRIVADO** (`Storage::disk('local')` =
  `storage/app/private/tenants/{id}/certs/cert.pem`). **No** hay certificados en
  la carpeta pública. (Se confirma: la preocupación por certificados públicos no
  aplica al código actual.)
- **XML/CDR/PDF: se guardan en `storage/app/public`** y quedaban accesibles por
  `/storage/...` sin autenticación. **Mitigado** con el bloqueo nginx del cliente.
- Se **eliminaron** los métodos `getXmlUrl/getCdrUrl/getPdfUrl` (devolvían URL
  pública de comprobantes; no se usaban) — vector latente removido. Las descargas
  van SOLO por endpoints autenticados (`get*Content` + API key / sesión).

**Migración a disco privado — IMPLEMENTADA ✅**

- Nuevo disco privado **`comprobantes`** (`storage/app/private`, sin URL) en
  `config/filesystems.php`.
- Helper central **`App\Support\Storage\Comprobantes`** (put/get/exists/download/
  path/delete): **escribe siempre en privado** y **lee con fallback a `public`**
  para los archivos antiguos aún no movidos (la migración no rompe descargas).
- Todos los `Storage::disk('public')` de **documentos** (XML/CDR/PDF) se
  reemplazaron por el helper: `DocumentStorageService`, jobs `Send*ToSunat` /
  `Check*TicketStatus` / `CreateSummaryAction`, y lecturas/descargas en los
  controllers (Perception, Retention, Summary, Voided, Export, Historial,
  Resumen, EmpresaComprobante). **Los logos/favicones siguen en `public`** (se
  sirven por URL a propósito).
- `TenantController` ahora también limpia los documentos del disco privado al
  eliminar una empresa.

**Deploy (una sola vez):** mover los archivos existentes de público a privado:
```
php artisan comprobantes:mover-a-privado --dry-run   # verifica
php artisan comprobantes:mover-a-privado             # mueve (copia a privado y borra de público)
```
Es idempotente. Tras moverlos, los XML/CDR/PDF ya no quedan accesibles por
`/storage/...`; se descargan solo por los endpoints autenticados. El bloqueo
nginx puede permanecer como defensa en profundidad.

---

## Tests
- `tests/Feature/Sunat/EnvioEstadoTest.php`: idempotencia (no reenvía aceptado) y
  reintento que no toca `pendiente`.
- `tests/Feature/Api/V1/GuiaCdrDownloadTest.php`: ruta `guias-remision/{id}/cdr`
  registrada y apuntando al método `cdr`.

## Postman
Se agregó **"Descargar CDR"** en las carpetas de Guía Remisión (09 y 31) y se
amplió la descripción del ejemplo de boleta "solo registro".
