# 01 — Arquitectura

API REST multiempresa (multi-tenant) de facturación electrónica SUNAT, sobre
**Laravel 12 + Inertia/React**, autoalojada. La emisión es **asíncrona**: la
respuesta HTTP se devuelve antes de que SUNAT confirme; el envío real ocurre en
una cola.

## Capas (de la petición a SUNAT)

```
HTTP (JSON)
  │
  ├─ routes/api.php  (prefijo /v1)
  │     middleware: resolve.tenant → idempotency → throttle:api → log.api → usage.headers
  │     en emisores: + license + check.limit:sunat
  │
  ├─ Controller        app/Http/Controllers/Api/V1/*Controller.php   (valida vía FormRequest)
  │
  ├─ FormRequest       app/Http/Requests/Api/V1/Store*Request.php    (reglas + withValidator)
  │
  ├─ Action            app/Actions/Documents/Create*Action.php
  │     · DB::transaction
  │     · Serie::lockForUpdate + resolveCorrelativo   (correlativo seguro ante concurrencia)
  │     · ClientResolverService                        (busca/crea cliente)
  │     · DocumentCalculationService                   (items + totales → documento 07)
  │     · Model::create (+ items)
  │     · event(DocumentCreated) + PlanService::incrementUsage
  │     · if enviar_automatico → SendDocumentToSunat::dispatch(...) + status 'enviado'
  │
  ├─ Job (cola 'sunat') app/Jobs/SendDocumentToSunat.php (y Send*ToSunat.php por tipo)
  │     · guard idempotencia (no reenvía aceptado/anulado)
  │     · GreenterService::send / sendDespatch / sendRawBill
  │          - XmlBuilderResolver → Builder (Greenter/lite, UBL 2.1)
  │          - post-procesos de XML (GRE, notas sin referencia, contrato colaboración)
  │          - firma SignedXml con el certificado PEM del tenant
  │          - envío SOAP (facturas/notas) o REST OAuth2 (GRE)
  │     · clasificación de CDR POR CÓDIGO (no por isSuccess)
  │     · persistencia de estado + XML firmado + CDR
  │     · PDF async (si pdf.auto_generate) + webhook
  │
  └─ Respuesta JSON   envelope { estado, mensaje, datos, errores? }  (trait ApiResponse)
```

> Evidencia del flujo de factura: `app/Actions/Documents/CreateInvoiceAction.php:27-123`
> (transacción, lock de serie `:30-36`, cálculo `:47-48`, creación `:58-101`,
> dispatch + estado `:112-115`).

## Componentes principales

| Capa | Archivos | Rol |
|---|---|---|
| Rutas API | `routes/api.php` (203 rutas) | Endpoints `/v1` |
| Rutas panel | `routes/web.php` | Panel Inertia (emite y gestiona) |
| Autenticación tenant | `app/Http/Middleware/` (`resolve.tenant`) | Resuelve empresa por credenciales |
| Validación | `app/Http/Requests/Api/V1/Store*Request.php` | Reglas + reglas de negocio (`withValidator`) |
| Orquestación | `app/Actions/Documents/Create*Action.php` | Transacción, serie, cálculo, persistencia, dispatch |
| Cálculo | `app/Services/DocumentCalculationService.php`, `TaxRateService.php` | Totales e IGV (doc 07) |
| XML + firma + envío | `app/Services/Greenter/GreenterService.php` + `Builders/*` | UBL 2.1, SignedXml, SOAP/REST |
| Colas | `app/Jobs/Send*ToSunat.php`, `Check*TicketStatus.php` | Envío/polling asíncrono |
| Almacenamiento | `app/Services/Storage/DocumentStorageService.php` | XML/CDR/PDF/certificados en disco `local` |
| PDF | `app/Services/Pdf/*` (dompdf) | A4/A5/ticket; requiere GD con WebP para logos |
| Catálogos | `config/sunat_catalogs.php`, `app/Rules/SunatCatalog.php` | Códigos SUNAT (doc 10) |
| Modelos | `app/Models/{Invoice,Boleta,CreditNote,DebitNote,...}.php` | Entidades + casts JSON |

## Dos canales de envío a SUNAT

1. **SOAP (WS clásico)** — factura, boleta, NC, ND (síncrono por CDR), resumen y
   baja (asíncrono por **ticket**), retención/percepción/liquidación (síncrono).
2. **REST OAuth2 (API GRE)** — guías de remisión (remitente 09 y transportista 31),
   con token; el XML se ajusta por post-proceso sobre la plantilla Greenter.

## Emisión síncrona vs asíncrona

- **Por CDR (síncrono dentro del Job):** factura, boleta, NC, ND, retención,
  percepción, liquidación de compra → SUNAT devuelve el CDR en la misma llamada.
- **Por ticket (asíncrono en dos pasos):** resumen diario (RC), comunicación de
  baja (RA), reversión (RR), guías GRE → se obtiene un **ticket** y un segundo
  Job (`Check*TicketStatus`) consulta el resultado tras un *delay*.

## Estados de un comprobante (`sunat_status`)

`pendiente` → `enviado` → `aceptado` | `rechazado`
(+ `anulacion_en_proceso` → `anulado`; timeout → vuelve a `pendiente` con
`sunat_code='SUNAT_TIMEOUT'`). El CHECK de PostgreSQL es el guardián de los
valores (`2026_05_14_000001_add_anulacion_en_proceso_to_sunat_status.php:15`).
Detalle y transiciones por tipo en [documento 09](09-flujos-procesamiento.md).

## Clasificación del CDR (regla central)

Se interpreta por **código de respuesta**, no por `isSuccess()`
(`GreenterService.php:637-668`):

| Código | Significado | Estado |
|---|---|---|
| `0` | Aceptado | `aceptado` |
| `≥ 4000` | Aceptado **con observaciones** | `aceptado` |
| `1000–3999` | **Rechazo** | `rechazado` |
| `0/100/109/500/1033/2800`, SoapFault, HTTP, NETWORK_ERROR | Temporal | reintenta; si agota → `SUNAT_TIMEOUT` |
| `98` | En proceso (ticket) | reintenta polling |

Ver [documento 11](11-manejo-errores.md).
