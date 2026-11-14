# Guía completa de uso — API de Facturación Electrónica SUNAT

Guía práctica para integrar y consumir la API de principio a fin. Para el detalle
técnico interno, ver los documentos numerados de esta carpeta.

---

## 1. Conceptos básicos

- **Base URL:** `https://TU-DOMINIO/api/v1` (en la colección Postman:
  `https://sunat.kodevo.es/api/v1`).
- **Formato:** JSON. La API fuerza `Accept: application/json`.
- **Multiempresa:** cada empresa (tenant) tiene sus credenciales.
- **Emisión asíncrona:** al emitir, la API responde de inmediato con el comprobante
  en estado `pendiente`/`enviado`; el envío real a SUNAT ocurre en segundo plano.
  Consulta el estado después.

## 2. Autenticación

Todas las rutas (salvo registro/planes/recuperación) requieren **dos cabeceras**:

```
X-Api-Key: <tu api_key>
X-Api-Secret: <tu api_secret>
Content-Type: application/json
```

- Obtén/renueva las credenciales en el panel o vía `POST /v1/credenciales/regenerar`.
- El `api_secret` se muestra **una sola vez**: guárdalo.
- Credenciales inválidas → `401`.

## 3. Envelope de respuesta

```json
{ "estado": "exito" | "error",
  "mensaje": "…",
  "datos": { … },
  "errores": { "campo": ["…"] }   // solo en errores de validación (422)
}
```

| HTTP | Situación |
|---|---|
| 200/201 | OK |
| 401 | Credenciales inválidas |
| 403 | Licencia / suscripción vencida |
| 422 | Validación (`errores` por campo) |
| 429 | Límite de plan o rate limit |
| 409 | Idempotencia en curso |

## 4. Idempotencia (evitar duplicados)

En `POST/PUT/PATCH/DELETE` envía una cabecera única por operación:

```
Idempotency-Key: <uuid propio>
```

Reintentar con la misma key devuelve la misma respuesta (no duplica). Útil ante
timeouts de red.

## 5. Antes de emitir: configuración mínima

1. **Certificado digital** (.pfx) y **Clave SOL**: se cargan en el panel (o
   `POST /v1/empresa/certificado`). No se envían por la API de emisión.
2. **Series:** registra tus series (`apiResource /v1/series`, o
   `POST /v1/series/init-defaults`). Factura `F###`, boleta `B###`, etc.
3. **Sucursales** (`/v1/sucursales`) y **clientes** (`/v1/clientes`) opcionales.
4. **Entorno:** beta para pruebas, producción para emitir real (se configura por
   empresa en el panel).

## 6. Emitir una factura

`POST /v1/facturas`

```json
{
  "serie": "F001",
  "fecha_emision": "2026-03-04",
  "tipo_moneda": "PEN",
  "cliente": { "tipo_doc": "6", "num_doc": "20000000002", "razon_social": "EMPRESA X SAC" },
  "items": [
    { "descripcion": "PROD 1", "unidad": "NIU", "cantidad": 2, "precio_unitario": 118, "tip_afe_igv": "10" }
  ]
}
```

- `precio_unitario` se interpreta **con IGV** para `tip_afe_igv=10`; la API deriva el
  valor sin IGV y calcula los totales (puedes enviarlos tú, pero entonces se usan tal
  cual — ver [doc 07](07-calculos-matematicos-tributarios.md)).
- `enviar_automatico` (default `true`): `false` deja el comprobante en `pendiente`
  para enviarlo luego con `POST /v1/facturas/{id}/enviar`.
- **Factura exige receptor con RUC** (`tipo_doc: "6"`). Para consumidor final usa
  **boleta**.

Respuesta: el comprobante con `id`, `numero` (ej. `F001-00000001`) y `sunat_status`.

## 7. Emitir otros comprobantes

| Comprobante | Endpoint | Claves |
|---|---|---|
| **Boleta** | `POST /v1/boletas` | serie `B###`; DNI o sin doc (≤ S/700); `solo_registro` para resumen |
| **Nota de crédito** | `POST /v1/notas-credito` | `doc_afectado_*` + `cod_motivo` (Cat 09) |
| **Nota de débito** | `POST /v1/notas-debito` | `cod_motivo` (Cat 10); motivo 13 = penalidad inafecta |
| **Guía remitente** | `POST /v1/guias-remision` | traslado; motivo/modalidad |
| **Guía transportista** | `POST /v1/guias-remision-transportista` | datos de vehículo/conductor |
| **Resumen diario** | `POST /v1/resumenes` | boletas `pendiente` de una fecha, o `anular:[...]` |
| **Comunicación de baja** | `POST /v1/anulaciones` | anula 01/07/08 aceptados (7 días) |
| **Retención (20)** | `POST /v1/retenciones` | serie `R###`, régimen Cat 23 |
| **Percepción (40)** | `POST /v1/percepciones` | serie `P###`, régimen Cat 22 |
| **Liquidación de compra** | `POST /v1/liquidaciones-compra` | serie `L###`, vendedor sin RUC |
| **Reversión (20/40)** | `POST /v1/reversiones` | revierte retención/percepción |

Detalle de cada uno en [comprobantes/](comprobantes/).

## 8. Consultar estado y descargar archivos

- **Estado:** `GET /v1/facturas/{id}` (campo `sunat_status`):
  `pendiente → enviado → aceptado | rechazado`. Timeout temporal → `pendiente` con
  `SUNAT_TIMEOUT` (reintenta solo).
- **XML firmado:** `GET /v1/facturas/{id}/xml`
- **CDR (respuesta SUNAT):** `GET /v1/facturas/{id}/cdr`
- **PDF:** `GET /v1/facturas/{id}/pdf?format=a4|a5|ticket-80|ticket-58`
- **Reenviar:** `POST /v1/facturas/{id}/reenviar`
- **Consulta directa a SUNAT:** `POST /v1/consultar-cdr`, `GET /v1/consultar-cpe`.

(Reemplaza `facturas` por `boletas`, `notas-credito`, etc. según el tipo.)

## 9. Pagos (cuentas por cobrar)
`POST /v1/facturas/{id}/pagos` con `{ "monto": 50, "fecha": "2026-04-19", "metodo": "efectivo" }`.
Consulta con `GET …/pagos`, elimina con `DELETE …/pagos/{paymentId}`. También en
boletas, liquidaciones y notas de venta.

## 10. Catálogos y utilidades

- **Producto SUNAT (UNSPSC):** `GET /v1/catalogos/producto-sunat?q=texto` y
  drill-down `segmentos/familias/clases/productos`.
- **Ubigeo:** `GET /v1/catalogos/ubigeo/regiones|provincias|distritos`.
- **Buscar RUC/DNI:** `GET /v1/buscar-documento`.
- Los códigos de afectación, motivos, monedas, etc. están en
  [documento 10](10-catalogos-sunat.md).

## 11. Cálculo de importes (lo esencial)

- **Gravado (10):** envías `precio_unitario` con IGV; la API saca el valor sin IGV
  (`precio/1.18`) y el IGV. Total = valor venta + IGV.
- **Exonerado (20) / Inafecto (30) / Exportación (40):** `precio_unitario` sin IGV;
  IGV = 0.
- **Gratuitas (11-16, 21, 31-36):** no suman al total a pagar; van en nodos aparte.
- **ISC:** envía `porcentaje_isc`; la API ajusta la base del IGV.
- **ICBPER (bolsas):** envía `factor_icbper`; monto fijo por unidad.
- **Detracción / percepción / anticipos / descuentos:** campos de cabecera opcionales.

Ejemplos numéricos verificados en
[documento 07](07-calculos-matematicos-tributarios.md).

## 12. Buenas prácticas de integración

1. Usa **`Idempotency-Key`** en cada emisión para evitar duplicados por reintento.
2. **Prueba en beta** antes de producción (certificado y RUC de prueba).
3. Tras emitir, **haz polling** del `sunat_status` (o usa webhooks si están
   configurados) hasta `aceptado`/`rechazado`.
4. Guarda el **`id`** que devuelve la API: es tu referencia para estado, XML, CDR y PDF.
5. Respeta el **plazo de envío** (3 días para factura/notas) para no emitir
   extemporáneo.
6. Maneja `429` (límite de plan) y `SUNAT_TIMEOUT` (la API reintenta sola;
   no re-emitas otro comprobante).
7. No envíes totales a mano salvo que necesites valores exactos SUNAT: si los
   envías, la API los usa sin recalcular.

## 13. Errores frecuentes

| Síntoma | Causa probable |
|---|---|
| 401 | `X-Api-Key`/`X-Api-Secret` incorrectos, o secreto recuperado por el flujo de recuperación (ver [doc 14](14-riesgos.md) R1) |
| 422 `cliente.num_doc` | Factura sin RUC válido (usa boleta para DNI) |
| 429 | Cupo del plan agotado → sube de plan |
| `rechazado` | CDR 1000-3999: revisa el motivo en el CDR/respuesta |
| Queda en `enviado` | No hay worker de colas corriendo (Horizon/queue) |
| 403 | Licencia/suscripción (solo producción) |

---

> Documentación técnica completa: [índice](README.md). Para desplegar la API en un
> servidor, ver `DEPLOY-VPS.md` / `DEPLOY-DOCKER.md` en la raíz del proyecto.
