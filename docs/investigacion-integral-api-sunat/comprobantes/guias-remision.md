# Guías de remisión electrónicas (Remitente 09 / Transportista 31)

**Qué es:** documento que sustenta el **traslado de bienes**. La **GRR (09)** la
emite el remitente (dueño de la carga); la **GRT (31)** la empresa de transporte.
Usan la **API REST GRE con OAuth2 (token)**, NO el WS SOAP clásico. Envío
**asíncrono por ticket**.

## Endpoints — `DispatchGuideController`
`routes/api.php:135-146`: `POST guias-remision`, atajo **`POST
guias-remision-transportista`** (fuerza tipo 31), `GET/PUT {id}`, `{id}/pdf|xml|cdr|estado`,
`POST {id}/enviar`.

## Validación (`StoreDispatchGuideRequest`)
Serie 4, correlativo opcional, destinatario Cat 06, motivo `cod_traslado` (Cat 20)
y `mod_traslado` (Cat 18) `required_unless tipo=31`. Reglas duras en `withValidator`
(`:200-387`):
- **GRT (31):** exige `remitente` (≠ RUC del tenant), `doc_relacionado` obligatorio
  (y `ruc_emisor` si relaciona 09/31), vehículo y conductor (`:218-287`).
- **GRR (09):** modalidad 01 (transporte público) exige transportista tercero y,
  desde 2026-06-01, `fecha_entrega_transportista`; modalidad 02 (privado) exige
  vehículo+conductor; reglas por motivo (`:289-386`).

## Flujo real (asíncrono por ticket) — `SendDispatchGuideToSunat` → `GreenterService::sendDespatch`
1. `DespatchBuilder` arma el objeto Greenter.
2. **Post-proceso del XML antes de firmar** (`GreenterService.php:294-321`):
   - quita `<cbc:CompanyID/>` vacío (evita observación 4391);
   - GRR+modalidad 01: inyecta `LoadingTransportEvent/OccurrenceDate`;
   - **GRT:** `stripGrtMotivoModalidad` quita HandlingCode/TransportModeCode;
     inyecta `CarrierParty`+`TransportMeans`, `LogisticsOperatorParty`
     (subcontratador), `OriginatorCustomerParty` (pagador del flete), `DespatchParty`
     (remitente).
3. Firma `SignedXml`, envía por REST (`$api->sendXml`), con reintento si el token
   no existe (purga cache y renueva).
4. Devuelve **ticket** → `CheckTicketStatus::dispatch(...)->delay(15s)` consulta el
   estado GRE (`getGreStatus`).

> **Credenciales GRE:** se resuelven en orden `client_id → sire_client_id → config`
> (`GreenterService.php:200-208`). Un 401 `unauthorized_client` indica credenciales
> GRE inválidas (ver memoria `gre-sire-credentials`).

## Estados / CDR / PDF
`checkStatus` consulta en vivo si hay ticket (`DispatchGuideController.php:221-298`);
responde 202 si aún no hay ticket. **Genera PDF** (auto en el Job; endpoint `/pdf`).

## GRR vs GRT (diferencias)

| Aspecto | GRR (09) | GRT (31) |
|---|---|---|
| Emisor | remitente (dueño de la carga) | empresa de transporte |
| Motivo/modalidad de traslado | obligatorios | omitidos (post-proceso los quita) |
| Transportista | tercero (mod 01) o propio (mod 02) | el propio emisor |
| Nodos inyectados | LoadingTransportEvent (mod 01) | CarrierParty, subcontratador, pagador |

> **Beta:** la respuesta avisa `grt_beta_no_soportado` si el tenant está en beta,
> pero **no bloquea** la emisión (el XML de GRT se corrige por post-proceso sobre
> la plantilla Greenter; ver memoria `gre-transportista-beta`).
