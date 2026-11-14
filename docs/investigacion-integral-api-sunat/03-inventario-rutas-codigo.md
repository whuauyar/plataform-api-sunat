# 03 — Inventario de rutas del código (`routes/api.php`)

**203 registros `Route::`** (incluye 3 `apiResource`), que `route:list` expande a
**198 endpoints v1** + health + bridge + alias sunat + debug. `base_url` de la
colección Postman = `https://sunat.kodevo.es/api/v1`.

## Grupos de middleware

| Grupo | Middleware | Evidencia |
|---|---|---|
| Públicas | `throttle:api` | `api.php:35,38` |
| Protegidas v1 | `resolve.tenant, idempotency, throttle:api, log.api, usage.headers` | `api.php:46` |
| Emisores (POST) | + `license, check.limit:sunat` | p.ej. `:70,85,100,111` |
| Internos (cotización/nota venta) | + `check.limit:internal` | `:231,239` |
| Alias `/sunat/*` | **solo** `resolve.tenant, throttle:api` | `:407` |
| `/bridge/*` | sin grupo (protegido por `X-Bridge-Key` en controlador) | `:401-403` |

## Rutas públicas (sin tenant) — `api.php:35-43`

| Método | Path | Controlador | Línea |
|---|---|---|---|
| GET | /api/health | HealthController | 35 |
| POST | /v1/registro | RegisterController@store | 39 |
| GET | /v1/planes | SubscriptionController@plans | 40 |
| POST | /v1/credenciales/recuperar | CredentialRecoveryController@solicitar | 41 |
| POST | /v1/credenciales/recuperar/verificar | CredentialRecoveryController@verificar | 42 |

## Emisión y gestión de comprobantes (grupo protegido)

### Facturas (01) — `InvoiceController` — `:70-82`
`POST facturas` (+license,limit) · `POST facturas/masivo` (máx 100) · `GET facturas` ·
`GET {id}` · `PUT {id}` · `GET {id}/xml` · `/cdr` · `/pdf` · `POST {id}/reenviar` ·
`/enviar` · `POST {id}/pagos` · `GET {id}/pagos` · `DELETE {id}/pagos/{paymentId}`
(pagos → `PaymentController`).

### Boletas (03) — `BoletaController` — `:85-97`
`POST boletas` · `GET` · `GET {id}` · `PUT {id}` · **`DELETE {id}`** (único con
borrado) · `xml` · `cdr` · `pdf` · `reenviar` · `enviar` · pagos store/index/destroy.

### Notas de crédito (07) — `CreditNoteController` — `:100-108`
`POST` · `GET` index · `show` · `xml` · `cdr` · `pdf` · `PUT` · `reenviar` · `enviar`.
**Sin `DELETE`** (una NC aceptada se anula por comunicación de baja).

### Notas de débito (08) — `DebitNoteController` — `:111-119`
Idéntico patrón que NC.

### Guías de remisión (09/31) — `DispatchGuideController` — `:135-146`
`POST guias-remision` · index · show · PUT · `pdf` · `xml` · `cdr` · `estado` ·
`POST enviar` · **`POST guias-remision-transportista`** (atajo que fuerza tipo 31).

### Resúmenes (RC) — `SummaryController` — `:149-154`
`GET` index · `POST` store · `estado` · `xml` · `cdr` · `POST enviar`.

### Anulaciones / baja (RA) — `VoidedController` — `:157-163`
`POST anulaciones` · index · show · `estado` · `xml` · `cdr` · `POST enviar`.
(+ atajos `POST facturas/{id}/anular`, `boletas/{id}/anular` en el alias.)

### Retenciones (20) — `RetentionController` — `:166-172`
`POST` · index · show · **`pdf`** · `xml` · `cdr` · `POST enviar`.

### Percepciones (40) — `PerceptionController` — `:175-180`
`POST` · index · show · `xml` · `cdr` · `POST enviar`. **Sin ruta `pdf`** (asimetría con retención).

### Liquidación de compra (04) — `LiquidacionCompraController` — `:183-195`
`POST` · index · **`reporte-retenciones`** · show · `xml` · `cdr` · `pdf` · `enviar` ·
`reenviar` · **`reversion`** · pagos store/index/destroy.

### Reversión (RR) — `ReversionController@store` — `POST reversiones` `:198`

## Consultas y monitoreo
`GET sunat/estado` (closure CircuitBreaker, `:49`) · `POST consultar-cdr`
(`ConsultController@cdrStatus`, `:201`) · `GET consultar-cpe`
(`CpeConsultaController`, `:204`) · `GET buscar-documento` (`:207`).

## Catálogos — `:122-132`
`producto-sunat`: search · segmentos · familias · clases · productos · `{codigo}`
(`CatalogoProductoController`). `ubigeo`: regiones · provincias · distritos
(`UbigeoController`).

## Empresa (perfil) — `TenantController` — `:210-216`
`GET empresa` · `PUT` · `DELETE` · `POST logo` · `POST certificado` ·
`GET credenciales` · `POST credenciales/regenerar`.

## Recursos CRUD (`apiResource`) — `:219-226`
`sucursales` (`SucursalController`) · `clientes` (`ClientController`) · `series`
(`SerieController`) + `POST series/init-defaults`.

## Documentos internos (NO SUNAT)
Cotizaciones (`QuotationController`, `:231-236`, +`check.limit:internal`),
Notas de venta (`SaleNoteController`, `:239-246`).

## Panel / Dashboard — `DashboardController` (prefijo `panel`) — `:249-261`
index · indicadores · estado-sunat · cobranzas · ventas-mensuales · por-sucursal ·
por-moneda · clientes · productos · documentos-recientes · alertas.

## Export y Reportes
`GET comprobantes/exportar-zip` (`ExportController`, `:264`). Reportes
(`ReportController`, `:267-273`): registro-ventas · ventas-consolidado · notas ·
cobranzas · documentos-internos · por-cliente · por-sucursal.

## SIRE — grupo `sire` — `:276-325`
Activación (`SireActivacionController`), periodos, RCE (`SireRceController`:
constancia/propuesta/resumen/aceptar/preliminar), uploads TUS
(`SireUploadController`), ajustes posteriores `/{variant}` (`SireAjustesController`),
comprobantes, reconciliación, tickets. ~26 endpoints.

## Suscripción — `SubscriptionController` — `:329-334`
show · store · cambiar-plan · cancelar · pagos · uso.

## Fuera de `/v1` (no documentados en Postman)
- `GET /api/debug/lookup` — solo `APP_DEBUG=true` (`:339`).
- Bridge SaaS (`X-Bridge-Key`): `POST bridge/auth`, `provision`, `DELETE tenant` (`:401-403`).
- **Alias `/api/sunat/*`** (`:407-425`): `configuracion` GET/POST · facturas/boletas
  index/store/pdf/xml/anular. **Reusa controladores pero con stack de middleware
  reducido** (ver [documento 14](14-riesgos.md), hallazgo del alias sin gates).

> Inventario de endpoints del panel web (`routes/web.php`) en
> [documento 05](05-controladores-servicios.md).
