# Reversión (RR)

**Qué es:** documento-proceso que **revierte (anula) retenciones (20) y percepciones
(40)** ya aceptadas. Es el equivalente a la comunicación de baja pero para los
comprobantes 20/40. Usa el **mismo modelo `VoidedDocument`** con identificador `RR-`.
Envío **asíncrono por ticket**.

## Endpoint — `ReversionController@store`
`POST /v1/reversiones` (`routes/api.php:198`). Valida `tipo in:20,40`, serie size 4,
motivo; que los documentos existan y estén `aceptado`; y duplicado RR.

## Flujo — `SendReversionToSunat` → `ReversionBuilder`
Construye un objeto Greenter `Reversion` y lo **envía por el endpoint de retención**
(“otros CPE”), porque `resolveSee` detecta la clase `Reversion`
(`GreenterService.php:982-985`). Su ticket se consulta en ese mismo endpoint
(`CheckVoidedTicketStatus`). `VoidedController@enviar` detecta el prefijo `RR-`
para reencolar como reversión.

## Caminos de reversión (nota)
Existen **dos rutas** de reversión en el código:
1. El endpoint API `POST /reversiones` (`ReversionController`), que usa validación
   anti-duplicado con `whereJsonContains` (no fiable en PostgreSQL).
2. `VoidedService::crearReversion` (filtrado en PHP, robusto) — **no se usa** desde
   la API.

Ver [documento 13](../13-inconsistencias.md).

## Reversión de liquidación de compra (04)
`LiquidacionCompraController::reversion` también crea un `VoidedDocument` `RR-` con
detalle tipo '04' y usa `SendReversionToSunat` (`modelMap` ya incluye '04'). Según
la normativa SUNAT, la liquidación **sí se anula por reversión**, así que el
mecanismo es el correcto; queda por **validar en beta** que el ResumenReversión con
tipoDoc `04` es aceptado y confirmar el plazo. Ver [documento 14](../14-riesgos.md) R4.
