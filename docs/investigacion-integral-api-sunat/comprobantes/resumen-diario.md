# Resumen Diario de boletas (RC)

**Qué es:** documento-proceso que **informa o anula boletas (03) en lote** ante
SUNAT. No es un comprobante en sí; agrupa boletas de una fecha. Envío **asíncrono
por ticket** (SOAP summary).

## Endpoints — `SummaryController`
`routes/api.php:149-154`: `GET resumenes`, `POST resumenes`, `{id}/estado|xml|cdr`,
`POST {id}/enviar`.

## Dos modos de uso (`SummaryController:75`)
1. **Informar:** envía las boletas en estado `pendiente` de una `fecha_resumen`.
2. **Anular:** `anular:[{id, motivo}]` para dar de baja boletas ya aceptadas.

## Validación (`StoreSummaryRequest`)
`fecha_resumen` requerida; mismo día o hasta **7 días** atrás
(`SummaryController:81-88`). Anti-duplicado: boletas ya incluidas en un resumen en
vuelo o aceptado se excluyen (evita SUNAT 2282). En anulación valida el plazo de 7
días, NC asociada y duplicado.

## Correlativo
**Por fecha de envío**, con lock + índice único. Identificador `RC-{fechaEnvio}-NNN`
(`generateCorrelativo:395-407`).

## Flujo — `SendSummaryToSunat` → `SummaryBuilder`
`fecGeneracion` = ReferenceDate, `fecResumen` = IssueDate → `send` devuelve ticket
→ guarda XML → marca boletas `enviado`/`anulado` → `CheckSummaryTicketStatus::dispatch->delay(15s)`.

## Ticket / estado — `CheckSummaryTicketStatus`
Códigos 0/98/187 = en proceso → reintenta. Define el estado final de las boletas
según el resultado; una anulación rechazada devuelve la boleta a `aceptado`; no
degrada boletas ya aceptadas ante un resumen duplicado rechazado. Guarda CDR.

> **No genera PDF del resumen** (sí re-genera el PDF de las boletas aceptadas).
