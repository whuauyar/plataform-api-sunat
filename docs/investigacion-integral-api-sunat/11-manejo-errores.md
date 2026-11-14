# 11 — Manejo de errores

## Envelope unificado de respuesta
Trait `app/Http/Traits/ApiResponse.php`:
`{ estado: 'exito'|'error', mensaje, datos, meta?, errores? }`. Helpers
`success/created/error/errorAccionable` (`:21-72`). `errorAccionable` añade
`codigo_error` + `siguiente_accion` (422 por defecto).

## Handler global
`bootstrap/app.php:75-168`. Sentry primero (no-op sin DSN). Mapeo por tipo de
excepción al envelope español:

| Excepción | HTTP | Cuerpo |
|---|---|---|
| ValidationException | 422 | `errores` por campo |
| ModelNotFound / NotFound | 404 | mensaje |
| Authentication | 401 | mensaje |
| Authorization | 403 | mensaje |
| MethodNotAllowed | 405 | mensaje |
| Throttle | 429 | mensaje |
| HttpException | status propio | mensaje |
| genérica | 500 | `excepcion/detalle/archivo` solo si `APP_DEBUG` |

> No existe `app/Exceptions/**`: los `codigo_error` se emiten inline (p. ej.
> `EmissionDecision::CODE_SUBSCRIPTION_EXPIRED`, `CODE_LIMIT_REACHED`;
> `CheckLicense` propaga `$check->code`).

## Errores por etapa

| Etapa | Error | Tratamiento |
|---|---|---|
| Autenticación | `X-Api-Key`/`Secret` inválidos | 401 (`ResolveTenant`) |
| Autenticación | secreto no descifra con APP_KEY | 401 accionable (regenerar), no 500 |
| Cupo / plan | suscripción vencida o cupo alcanzado | 403 / **429** con `mejora_plan` |
| Licencia (prod) | sin licencia válida | 403 con `codigo_error` (beta siempre pasa) |
| Validación | FormRequest | 422 con `errores` |
| Idempotencia | misma key + body distinto | 422; respuesta en curso → 409 |
| Certificado | PEM/PFX inválido o sin clave privada | `rechazado` + `CERT_ERROR`, sin reintento |
| Firma XML | `XmlSignException` | `rechazado` + `CERT_ERROR` |
| SUNAT — rechazo | CDR 1000-3999 | `rechazado`, permanente, webhook `document.rejected` |
| SUNAT — observación | CDR ≥ 4000 | `aceptado` con advertencia |
| SUNAT — temporal | 0/100/109/500/1033/2800, SoapFault, HTTP, NETWORK_ERROR | reintenta con backoff |
| SUNAT — timeout | reintentos agotados | `recoverFromCdr`; si no, `pendiente/SUNAT_TIMEOUT`, webhook `document.timeout_sunat` |
| SUNAT caído | circuit breaker abierto | libera el job con jitter, sin intentar |
| PDF | fallo de render (p. ej. webp/GD) | `try/catch` silencioso, no rompe el envío |

## Clasificación del CDR (regla central)
Por **código**, no por `isSuccess()` (`GreenterService.php:637-668`): Greenter puede
devolver `isSuccess()=true` con un CDR de rechazo. Ver tabla en
[documento 01](01-arquitectura.md).

## Recuperación y reparación
- **`recoverFromCdr`** (`SendDocumentToSunat.php:280-336`) y
  **`ReconcileFromCdrAction`**: consultan el CDR real antes de reenviar un
  `SUNAT_TIMEOUT` (nunca reenvían a ciegas).
- Comandos de reparación: `comprobantes:reparar-rechazos`,
  `bajas:reparar-anulaciones`, `bajas:reconsultar`, `sunat:reintentar-pendientes`,
  `sunat:conciliar-timeouts`, `sunat:plazo-envio`.

## Concurrencia y duplicidad
- **Correlativo:** `Serie::lockForUpdate` + `resolveCorrelativo` en transacción.
- **Idempotencia HTTP:** middleware `EnsureIdempotency` (tabla `idempotency_keys`,
  hash de método|path|body). 5xx no se guardan → permiten reintento.
- **Idempotencia de jobs:** no reenvían si el estado es `aceptado`/`anulado`.
- **WithoutOverlapping** por documento en todos los `Send*ToSunat`.

> ⚠ El alias `/sunat/*` **no** aplica idempotencia ni cupo (ver
> [documento 14](14-riesgos.md)).
