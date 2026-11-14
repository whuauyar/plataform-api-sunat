# 02 — Inventario de la colección Postman

Archivo: `API FACTURACION SUNAT PERU ✅✅✅✅✅.postman_collection.json` (raíz,
13 594 líneas). **`base_url` = `https://sunat.kodevo.es/api/v1`**.

- **317 requests** en total, repartidos en **24 carpetas numeradas** (la 01 tiene
  3 subcarpetas: Sucursales, Series, Clientes).
- Muchos de los 317 son **variantes/ejemplos del mismo endpoint** (p. ej. la
  carpeta 04 tiene 20 `POST /facturas` distintos para distintos escenarios), por
  lo que se reducen a **~197 combinaciones método+path únicas**.
- Los 317 requests apuntan **todos a `/api/v1`**; ninguno usa los alias
  `/api/sunat`, `/api/bridge` ni `/api/health`.

## Estructura de carpetas

| # | Carpeta | Requests | Mapea a |
|---|---|---|---|
| 01 | Configuración inicial (+ Sucursales, Series, Clientes) | 12 + 20 | empresa, apiResource |
| 02 | Régimen tributario (variantes PUT /empresa) | 6 | `PUT /empresa` |
| 03 | Suscripción y planes | 7 | `SubscriptionController` |
| 04 | **Facturas (01)** — 20 ejemplos + CRUD/envío/pagos/pdf | 39 | `InvoiceController` |
| 05 | **Boletas (03)** | 22 | `BoletaController` |
| 06 | **Notas de Crédito (07)** | 15 | `CreditNoteController` |
| 07 | **Notas de Débito (08)** | 13 | `DebitNoteController` |
| 08 | Resumen Diario (RC) | 10 | `SummaryController` |
| 09 | Comunicación de Baja (RA/RR) | 11 | `VoidedController` |
| 10 | Guía Remisión Remitente (09) | 14 | `DispatchGuideController` |
| 11 | Guía Remisión Transportista (31) | 14 | `DispatchGuideController` |
| 12 | Envío Manual a SUNAT (9 tipos `/enviar`) | 9 | varios `enviar` |
| 13 | Actualizar documentos (PUT) | 6 | varios `update` |
| 14 | Consultar CPE/CDR (+ exportar-zip) | 6 | Consult/Cpe/Export |
| 15 | Panel de Control | 11 | `DashboardController` |
| 16 | Reportes | 9 | `ReportController` |
| 17 | SIRE (subcarpetas 0.Activación…7.Reconciliación) | 26 | `Sire*Controller` |
| 18 | Retenciones (20) | 13 | `RetentionController` |
| 19 | Percepciones (40) | 12 | `PerceptionController` |
| 20 | Reversión (RR) | 4 | `ReversionController` |
| 21 | Cotizaciones (interno) | 7 | `QuotationController` |
| 22 | Notas de Venta (interno) | 8 | `SaleNoteController` |
| 23 | Catálogos (productos + ubigeo) | 10 | Catalogo/Ubigeo |
| 24 | Liquidaciones de Compra (04) | 13 | `LiquidacionCompraController` |

## Documentación generada adicional

Además de Postman, el repo genera documentación **Scribe**:
- `public/docs/openapi.yaml` (≈463 KB) — especificación OpenAPI.
- `public/docs/index.html` (≈2 MB) — doc HTML navegable.
- `config/scribe.php` — configuración.

> La cobertura Postman↔código y las discrepancias están en
> [documento 04](04-matriz-postman-vs-codigo.md).
