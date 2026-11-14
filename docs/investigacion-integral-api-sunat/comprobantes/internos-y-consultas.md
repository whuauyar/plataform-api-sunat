# Documentos internos (Nota de venta, Cotización) y Consultas

## Documentos internos — NO van a SUNAT

**Confirmado:** no existe Job, `sunat_status`, builder XML ni firma para estos
documentos. Son modelos internos (`SaleNote` / `Quotation` sobre la tabla
`internal_documents`), con middleware `check.limit:internal`. Solo persistencia +
PDF + cálculo con `DocumentCalculationService`.

### Nota de venta — `SaleNoteController`
`routes/api.php:239-246`. **Qué es:** comprobante interno de venta (no tributario)
para control propio del negocio. `store/index/show/update/pdf` + **pagos**
(`PaymentController`). Estados de pago: pendiente/parcial/pagado. Editable salvo
`anulada`.

### Cotización — `QuotationController`
`routes/api.php:231-236`. **Qué es:** proforma/presupuesto previo a la venta.
`store/index/show/update/updateStatus/pdf` + `convertir` (desde el panel web, a
factura/boleta). Sin pagos.

## Consultas a SUNAT

### Consultar CDR — `ConsultController@cdrStatus`
`POST /v1/consultar-cdr` (`:201`). Valida `tipo in:01,03,07,08`, serie, correlativo
→ `ConsultCdrAction` → `GreenterService::getStatus("{ruc}-{tipo}-{serie}-{num}",
endpoint consulta_cdr)`. Devuelve estado/CDR por SOAP. **Base de la recuperación
de comprobantes en `SUNAT_TIMEOUT`.**

### Consultar CPE (Consulta Integrada) — `CpeConsultaController`
`GET /v1/consultar-cpe` (`:204`). Consulta el estado de un comprobante vía **REST
OAuth2** (`SunatCpeConsultaService`). Requiere `client_id/client_secret` del
tenant; token cacheado. Devuelve `estado_cp` / `estado_ruc` / `cond_domi`. **Solo
producción** (sin beta); maneja 401. Tipos `in:01,03,04,07,08,R1,R7`.

### Buscar documento (RUC/DNI) — `ConsultController@lookupDocument`
`GET /v1/buscar-documento` (`:207`). Busca el cliente en la BD local y, si no está,
consulta una API externa de RUC/DNI (`DocumentLookupService`). Útil para autollenar
datos del receptor.
