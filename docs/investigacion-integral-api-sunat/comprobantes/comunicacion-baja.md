# Comunicación de Baja (RA)

**Qué es:** documento-proceso que **anula facturas, NC y ND (01/07/08) ya
aceptadas** por SUNAT. Las **boletas (03) NO van por RA** (se anulan por Resumen
Diario). Envío **asíncrono por ticket**.

## Endpoints — `VoidedController`
`routes/api.php:157-163`: `POST anulaciones`, `GET` index/show, `{id}/estado|xml|cdr`,
`POST {id}/enviar`. Atajos: `POST facturas/{id}/anular` y `boletas/{id}/anular`
(este último rechazado para 03) → mismo `VoidedController@store`.

## Lógica — `VoidedService`
- Valida existencia, estado `aceptado`, **plazo de 7 días**, que una factura no
  tenga NC asociada, y duplicado RA.
- **Boletas 03 bloqueadas** (`VoidedController:39-51`, `VoidedService:48-52`).
- Identificador `RA-{fechaCom}-NNN`.
- `MODELOS` solo mapea 01/03/07/08.

## Flujo — `SendVoidedToSunat` → `VoidedBuilder`
Genera XML de baja → ticket → `CheckVoidedTicketStatus`. Estado final de los
originales (`updateOriginalDocuments:125-164`): aceptada → `anulado` siempre;
rechazada → revierte solo los que estaban en `anulacion_en_proceso`.

> **No genera PDF.**

## Nota (anti-duplicado en PostgreSQL)
El chequeo de duplicado usa filtrado **en PHP** (`existeVoidedConDetalle:310-330`)
en vez de `whereJsonContains`, porque el operador `@>` de Postgres no es fiable
para escalar dentro de arrays JSON. (El endpoint `/reversiones` NO usa este camino
robusto — ver [documento 13](../13-inconsistencias.md).)
