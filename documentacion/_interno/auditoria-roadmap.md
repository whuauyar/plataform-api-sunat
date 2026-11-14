# 32 · Auditoría: robustez, escalabilidad, seguridad y experiencia de integración

Revisión de qué falta para que la API sea **robusta, escalable, segura** y fácil
de implementar para todos los que la integren. Incluye lo que YA está bien y las
brechas priorizadas (Alta / Media / Baja) con el archivo/área y una recomendación.

> Prioridad = impacto × riesgo. "Alta" = hacerlo pronto.

---

## 0. Lo que YA está sólido (no tocar, solo mantener)
- **Auth por empresa**: `X-Api-Key` + `X-Api-Secret`, comparación *timing-safe*
  (`ResolveTenant`), credenciales ocultas en respuestas.
- **Rate limiting por plan** (`AppServiceProvider` limiter `api`): free 30 / pro
  120 / business 300 req/min, por tenant, con bypass interno.
- **CORS** (`config/cors.php`), **healthcheck** `/up`, **CI** (lint + tests).
- **Colas resilientes**: Redis, cola `sunat` dedicada, 20 reintentos con backoff,
  **circuit breaker**, idempotencia de envío + `WithoutOverlapping`, recuperación
  por CDR (doc 30), recuperación de atascados (`sunat:reintentar-pendientes`).
- **Webhooks** con reintentos (3 + backoff), **RBAC**, **licencia**,
  **particionado** mensual, **PDF/SIRE/GRE**, **storage privado + S3-ready**
  (docs 30 y 31).

---

## 1. SEGURIDAD

| # | Brecha | Prioridad | Dónde |
|---|--------|-----------|-------|
| S1 | **`api_secret` guardado en CLARO** en BD (no está en los casts `encrypted`) | **Alta** | `Tenant` casts |
| S2 | **Webhooks sin firma** (no hay HMAC) → el receptor no puede verificar origen | **Alta** | `NotifyWebhookJob` |
| S3 | **Auth por query string** (`?api_key=&api_secret=`) → se filtra en logs/URLs | Media | `ResolveTenant:15-16` |
| S4 | **Sin headers de seguridad** (HSTS, X-Content-Type-Options, X-Frame-Options; ocultar X-Powered-By) | Media | middleware |
| S5 | **Sin auditoría** de acciones sensibles (anular, regenerar credenciales, cambiar config) | Media | — |
| S6 | Sin **scopes/permiso por API key** ni expiración/rotación programada | Baja | — |

**Recomendaciones:**
- **S1**: hashear el `api_secret` (ej. SHA-256) y comparar el hash; mostrarlo solo
  una vez al generarlo. O, mínimo, `'api_secret' => 'encrypted'`. Así un volcado
  de BD no expone las credenciales de todos los clientes.
- **S2**: firmar el webhook con `hash_hmac('sha256', payload, secret_del_tenant)`
  en una cabecera `X-Signature`, y documentar cómo verificarla. Es estándar
  (Stripe/GitHub) y lo esperan los integradores serios.
- **S3**: aceptar credenciales SOLO por cabecera; quitar el fallback por query
  string (o dejarlo solo para descargas firmadas temporales).
- **S4**: middleware de *security headers* global.

---

## 2. ROBUSTEZ

| # | Brecha | Prioridad | Dónde |
|---|--------|-----------|-------|
| R1 | **Sin `Idempotency-Key` a nivel API** → un reintento del cliente tras timeout puede crear un comprobante DUPLICADO | **Alta** | POST /facturas, /boletas, /notas, /guias |
| R2 | **Jobs fallidos sin alerta/monitor** (`failed_jobs` existe, pero nadie avisa ni reintenta) | Media | colas |
| R3 | Envelope de error consistente pero **sin catálogo de códigos** propio documentado | Media | respuestas API |

**Recomendaciones:**
- **R1 (clave para facturación)**: soportar cabecera `Idempotency-Key`. Guardar
  (tenant + key + hash del body) → respuesta; si llega la misma key, devolver la
  MISMA respuesta sin volver a emitir. Evita dobles emisiones por reintentos de
  red (el problema de "40 jobs" visto antes nace de esta clase de reintentos).
- **R2**: comando/health que cuente `failed_jobs` y alerte; política de reintento.
- **R3**: catálogo de códigos de error de la API (no de SUNAT) con significado y
  acción sugerida, enlazado desde la doc.

---

## 3. ESCALABILIDAD

| # | Brecha | Prioridad | Dónde |
|---|--------|-----------|-------|
| E1 | **Horizon no instalado** (el doc 26 lo asume) → sin panel de colas ni balanceo/auto-scaling de workers | Media | colas |
| E2 | **Revisar índices** en columnas de filtro caliente (`tenant_id`+`sunat_status`, `fecha_emision`, `serie`+`correlativo`) | Media | migraciones |
| E3 | **Read replica** soportada en config pero sin usar para lecturas pesadas (reportes/dashboard) | Baja | `config/database.php` |
| E4 | **Caché** de consultas pesadas (dashboard, catálogos) ampliable | Baja | — |

**Recomendaciones:**
- **E1**: instalar **Laravel Horizon** (ya usan Redis) para visibilidad, métricas
  y balanceo de las colas `sunat`/`webhooks`/`default`.
- **E2**: auditar `EXPLAIN` de las consultas del listado/exportación y del
  dashboard; agregar índices compuestos donde falten.
- La app ya es **stateless** si caché/colas/sesión están en Redis central (doc 31)
  → escalar horizontalmente es viable.

---

## 4. EXPERIENCIA DE INTEGRACIÓN (DX) — "que ayude a todos los que van a implementar"

| # | Brecha | Prioridad | Dónde |
|---|--------|-----------|-------|
| D1 | **Sin OpenAPI/Swagger** (hay Postman, pero no spec interactiva ni generable) | **Alta** | docs |
| D2 | **Webhooks sin firma ni doc de verificación** (ver S2) | Alta | — |
| D3 | **Sin SDKs oficiales** (existe la skill `api-sunat-pro` que genera cliente; formalizar) | Media | — |
| D4 | **Sandbox/beta** existe (`environment=beta`) pero poco documentado para el integrador | Media | docs |
| D5 | **Catálogo de códigos de error** de la API (ver R3) | Media | docs |
| D6 | Sin **página de estado**/changelog público ni política de deprecación | Baja | — |

**Recomendaciones:**
- **D1**: generar **OpenAPI 3** (ej. `scribe` o `l5-swagger`) con "Try it out".
  Es lo que más acelera a quien integra; la colección Postman complementa.
- **D3**: publicar SDKs (PHP/TS como mínimo); la skill ya genera el cliente base.
- **D4**: guía "Primeros pasos" (crear empresa → credenciales → beta → primera
  factura → consultar estado → descargar PDF/XML/CDR → webhooks).

---

## 5. OBSERVABILIDAD / OPERACIÓN

| # | Brecha | Prioridad | Dónde |
|---|--------|-----------|-------|
| O1 | **Backups automatizados** de BD y del bucket/archivos | **Alta** | infra |
| O2 | **Sin monitoreo de errores** (Sentry/Bugsnag) | Media | app |
| O3 | **Healthcheck superficial** (`/up`): no verifica BD/Redis/SUNAT/almacenamiento | Media | `spatie/laravel-health` |
| O4 | **Alertas** (colas atascadas, SUNAT caído, disco lleno, certificados por vencer) | Media | — |
| O5 | Métricas/paneles (Prometheus/Grafana) | Baja | — |

**Extra útil para facturación:** aviso de **certificado digital por vencer** (el
.p12 caduca y rompe la emisión) — alta relación valor/esfuerzo.

---

## Estado
- ✅ **HECHO** (tanda 1): **R1 Idempotency-Key** (middleware `idempotency` +
  tabla `idempotency_keys`), **S1 api_secret cifrado en reposo** (cast
  `encrypted` + migración) y **S2 webhooks firmados** (HMAC-SHA256,
  `X-Signature`).
- ✅ **HECHO** (tanda 2): **S4 security headers** (`SecurityHeaders` middleware
  global: nosniff, X-Frame-Options SAMEORIGIN, Referrer-Policy, HSTS en HTTPS) y
  **S3 auth solo por cabecera** (`ResolveTenant` ya no acepta `?api_key=`).
- ✅ **HECHO** (tanda 3): **D1 OpenAPI/Swagger** con **Scribe**
  (`knuckleswtf/scribe`, dev). Config en `config/scribe.php`:
  - Solo rutas `api/v1/*`; salida `static` en `public/docs/`
    (HTML + `openapi.yaml`), servida directo (se despliega por git).
  - Auth por cabecera `X-Api-Key` (+ `X-Api-Secret` añadido vía `StaticData`);
    sin fallback por query string.
  - **Response calls DESACTIVADAS** (`only: []`) para no pegarle a SUNAT ni
    crear datos al generar.
  - Postman desactivado (ya existe la colección propia).
  - `base_url` por `SCRIBE_BASE_URL` (define la URL real y regenera en el VPS).
  - Regenerar: `php artisan scribe:generate`. Docs en `/docs`, spec en
    `/docs/openapi.yaml`.
  - 68 endpoints documentados.

- ✅ **HECHO** (tanda 4): **O1 backups** (`spatie/laravel-backup`: BD +
  `storage/app/private` + `.env`, retención, `backup:monitor`, destino S3-ready)
  y **O4 alertas** por **webhook** (`OpsAlert`, esquiva el SMTP bloqueado):
  `certificados:verificar --alertar`, `ops:jobs-fallidos`, `ops:salud` (BD,
  Redis, disco y circuit breaker de SUNAT). Todo en el scheduler. Detalle en el
  **doc 33**.

- ✅ **HECHO** (tanda 5): **E1 Horizon** (supervisores sunat/otros, dashboard
  solo-admin, requiere Redis), **E2 índices** (huecos de reportes: cod_local,
  payment/vencimiento, destinatario) y **O2 Sentry** (enganchado en
  `withExceptions`, no-op sin DSN). Detalle en el **doc 35**.

- ✅ **HECHO** (tanda 6): **D3 SDKs** (PHP y TypeScript en `sdk/`),
  **D4 guía de primeros pasos** (doc 37) y **D5/R3 catálogo de errores**
  (doc 36). Con esto se cierra el roadmap de la auditoría.
- ✅ **HECHO** (extra): **O3 health endpoint** `GET /api/health` (público, para
  monitores), con chequeos compartidos (`HealthChecks`) con `ops:salud`. 200 si
  la API opera, 503 si un componente propio está caído. Detalle en doc 33.
- ✅ **HECHO** (extra): **S5 auditoría de acciones sensibles** (tabla
  `audit_logs` + `AuditLog::registrar()` + `ops:auditoria`). Instrumentadas:
  regenerar credenciales, actualizar empresa, certificado, eliminar empresa,
  anular, y gestión de equipo/usuarios. Además se corrigió un bug: regenerar
  credenciales por API rompía la autenticación. Detalle en doc 38.

Tests: `tests/Feature/Seguridad/IdempotenciaSecretoWebhookTest.php`,
`tests/Feature/Ops/AlertasOpsTest.php`.

## Roadmap sugerido (orden por impacto)
1. ✅ **R1 Idempotency-Key** + **S1 api_secret cifrado** + **S2 firmar webhooks**.
2. ✅ **S3 auth solo por header** + **S4 security headers** + **D1 OpenAPI (Scribe)**.
3. ✅ **O1 backups** + **O4 alertas** (jobs fallidos, cert por vencer, SUNAT, disco).
4. ✅ **E1 Horizon** + **E2 índices** + **O2 Sentry**.
5. ✅ **D3 SDKs** (PHP/TS) + **D4 guía de inicio** + **D5/R3 catálogo de errores**.
4. **E1 Horizon** + **E2 índices** + **O2 Sentry**.
5. **D3 SDKs** + **D4 guía de inicio** + **D5/R3 catálogo de errores**.

> Nada de esto es bloqueante hoy: la API funciona y es segura en lo esencial
> (auth, rate limit, colas, storage privado). Esto es para llevarla de "funciona"
> a "lista para muchos integradores en producción con tranquilidad".
