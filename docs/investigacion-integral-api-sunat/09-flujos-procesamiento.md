# 09 — Flujos de procesamiento

## Flujo general de emisión (factura/boleta/NC/ND)

```
Cliente ──POST /v1/{tipo}──▶ [resolve.tenant → idempotency → throttle → log → usage]
                              [license → check.limit:sunat]
   │
   ▼  Controller@store ──▶ FormRequest (reglas + withValidator)
   │
   ▼  Create{Tipo}Action::execute  (DB::transaction)
   │     1. Serie::lockForUpdate + resolveCorrelativo
   │     2. ClientResolver::resolve
   │     3. calculateItems + calculateTotals        (doc 07)
   │     4. Model::create (+ items)  sunat_status='pendiente'
   │     5. event(DocumentCreated) + PlanService::incrementUsage
   │     6. enviar_automatico ? dispatch(SendDocumentToSunat) + 'enviado' : queda 'pendiente'
   │
   ▼  Respuesta JSON { estado:'exito', datos:{...} }   ◀── (SÍNCRONO: vuelve ya)
   
   ... en la cola 'sunat' (ASÍNCRONO) ...
   
   SendDocumentToSunat::handle
     guard idempotencia (aceptado/anulado → return)
     GreenterService::send → build XML → post-proceso → SignedXml → SOAP
     CDR por código:  0/≥4000 → 'aceptado' (+hash, XML, CDR, PDF async, webhook)
                      1000-3999 → 'rechazado' (+webhook)
                      temporal/red → reintenta (backoff); agota → recoverFromCdr → SUNAT_TIMEOUT
```

## Síncrono por CDR (sin ticket)
Factura 01, boleta 03, NC 07, ND 08, retención 20, percepción 40, liquidación 04.
El Job obtiene el CDR en la misma llamada SOAP y fija el estado final.

## Asíncrono por ticket (dos pasos)
Resumen RC, baja RA, reversión RR, guías GRE (09/31).

```
Send{X}ToSunat ──▶ SUNAT devuelve TICKET ──▶ guarda estado 'enviado'/parcial
      │
      ▼ Check{X}TicketStatus::dispatch->delay(15s)
         consulta el ticket:
           en proceso (0/98/187) → reintenta el polling
           resuelto → fija estado final (aceptado/rechazado/anulado) + CDR
```

## Flujos específicos

### Factura / Boleta
`store` → Action → `SendDocumentToSunat` → CDR → `aceptado`/`rechazado`. Boleta
puede ir con `solo_registro` (queda `pendiente` para el **Resumen Diario**).

### Nota de crédito / débito
Igual que factura pero con `doc_afectado_*` + `cod_motivo`. ND motivo 13
(penalidades) omite la referencia (post-proceso `stripEmptyNoteReference`).

### Guías de remisión (GRE)
`store` → `DespatchBuilder` → **post-proceso del XML** (GRR mod 01 / GRT) → firma →
REST con token → **ticket** → `CheckTicketStatus` (`getGreStatus`).

### Resumen diario
`store` (boletas pendientes de una fecha, o `anular:[...]`) → `SummaryBuilder` →
**ticket** → `CheckSummaryTicketStatus` → propaga estado a las boletas.

### Comunicación de baja
`store` (01/07/08 aceptados, plazo 7 días) → `VoidedBuilder` → **ticket** →
`CheckVoidedTicketStatus` → originales a `anulado`.

### Liquidación de compra
`store` → `LiquidacionCompraCalculator` (IGV + retención de renta) →
`LiquidacionCompraXmlBuilder` (SelfBilledInvoice manual) → `sendRawBill` → CDR.

### Retención / Percepción
`store` → builder → envío síncrono por el endpoint de retención/“otros CPE” → CDR.

### Consulta de estado
`POST consultar-cdr` → `ConsultCdrAction` → `getStatus` (SOAP). Base de
`recoverFromCdr` para recuperar comprobantes en `SUNAT_TIMEOUT`.

## Procesos síncronos vs asíncronos (resumen)
- **Síncrono (HTTP):** validación, cálculo, persistencia, respuesta. Devuelve de
  inmediato con el comprobante en `pendiente`/`enviado`.
- **Asíncrono (colas):** firma, envío a SUNAT, polling de ticket, PDF, webhooks.
