# 37 · Primeros pasos (guía de integración)

Guía para emitir tu primer comprobante de principio a fin (D4 de la auditoría):
credenciales → beta → primera factura → consultar estado → descargar
PDF/XML/CDR → webhooks. Al final, los SDKs oficiales (`sdk/`).

> **Base URL**: `https://TU-DOMINIO/api/v1`
> **Autenticación**: cabeceras `X-Api-Key` y `X-Api-Secret` en **todas** las
> peticiones. No se aceptan por query string.

---

## 1. Obtén tus credenciales

En el panel de tu empresa: sección **Credenciales** → *Generar credenciales*.
Obtendrás `X-Api-Key` y `X-Api-Secret`. **El secreto solo se muestra al
generarlo**; guárdalo seguro.

Prueba que autenticas bien:
```bash
curl https://TU-DOMINIO/api/v1/clientes \
  -H "X-Api-Key: TU_API_KEY" \
  -H "X-Api-Secret: TU_API_SECRET" \
  -H "Accept: application/json"
```

---

## 2. Empieza en BETA (pruebas)

Configura tu empresa en entorno **beta** (en el panel). En beta puedes emitir sin
afectar nada real y sin licencia. Cuando todo funcione, cambia a **producción**
(requiere licencia y certificado digital válido).

---

## 3. Emite tu primera factura

`POST /api/v1/facturas`. La emisión es **asíncrona**: la API valida, crea el
comprobante, lo **encola** para SUNAT y responde rápido con estado `enviado`.

**Campos mínimos**: `serie` (empieza con F), `fecha_emision`, `cliente` y al
menos un `item`. Los totales (IGV, etc.) se **calculan solos** si no los envías.

```bash
curl -X POST https://TU-DOMINIO/api/v1/facturas \
  -H "X-Api-Key: TU_API_KEY" \
  -H "X-Api-Secret: TU_API_SECRET" \
  -H "Content-Type: application/json" \
  -H "Accept: application/json" \
  -H "Idempotency-Key: $(uuidgen)" \
  -d '{
    "serie": "F001",
    "fecha_emision": "2026-10-03",
    "tipo_moneda": "PEN",
    "forma_pago": "Contado",
    "cliente": {
      "tipo_doc": "6",
      "num_doc": "20123456789",
      "razon_social": "ACME SAC",
      "direccion": "Av. Siempre Viva 123"
    },
    "items": [
      {
        "descripcion": "Servicio de consultoría",
        "unidad": "NIU",
        "cantidad": 1,
        "precio_unitario": 118.00,
        "tip_afe_igv": "10",
        "porcentaje_igv": 18
      }
    ]
  }'
```

> `Idempotency-Key` (recomendado): un valor único por factura. Si reintentas por
> un corte de red, la API **no** duplica el comprobante (ver doc 36 §3).
> `precio_unitario` incluye IGV; el sistema separa la base y el IGV.
> Para emitir en diferido, envía `"enviar_automatico": false` y luego llama a
> `POST /facturas/{id}/enviar`.

**Respuesta (201)** — nota `datos.id` y `datos.sunat.estado`:
```json
{
  "estado": "exito",
  "mensaje": "Factura creada y encolada para envío a SUNAT.",
  "datos": {
    "id": 123,
    "serie": "F001", "correlativo": 45, "numero_completo": "F001-45",
    "totales": { "gravadas": 100.0, "igv": 18.0, "total": 118.0 },
    "sunat": { "estado": "enviado", "codigo": null, "descripcion": null, "hash_cpe": null },
    "archivos": { "xml": null, "cdr": null, "pdf": null }
  }
}
```

---

## 4. Consulta el estado

SUNAT responde en segundo plano. Consulta el comprobante hasta que
`sunat.estado` sea `aceptado` o `rechazado`:

```bash
curl https://TU-DOMINIO/api/v1/facturas/123 \
  -H "X-Api-Key: TU_API_KEY" -H "X-Api-Secret: TU_API_SECRET" -H "Accept: application/json"
```
Estados posibles: `pendiente → enviado → aceptado | rechazado` (ver doc 36 §4).
Un `rechazado` es error de datos (corrige y reemite); `aceptado` con código
≥4000 es "aceptado con observaciones".

> **Mejor que hacer *polling***: configura un **webhook** (paso 6) y te avisamos.

---

## 5. Descarga PDF / XML / CDR

Disponibles cuando el comprobante fue aceptado:
```bash
# PDF (formato: a4 | a5 | ticket-80 | ticket-58)
curl "https://TU-DOMINIO/api/v1/facturas/123/pdf?formato=a4" \
  -H "X-Api-Key: TU_API_KEY" -H "X-Api-Secret: TU_API_SECRET" -o factura.pdf
# XML firmado
curl https://TU-DOMINIO/api/v1/facturas/123/xml \
  -H "X-Api-Key: TU_API_KEY" -H "X-Api-Secret: TU_API_SECRET" -o factura.xml
# CDR (zip de respuesta de SUNAT)
curl https://TU-DOMINIO/api/v1/facturas/123/cdr \
  -H "X-Api-Key: TU_API_KEY" -H "X-Api-Secret: TU_API_SECRET" -o R-factura.zip
```

---

## 6. Webhooks (recomendado)

Configura `url_webhook` (`PUT /empresa`) en tu empresa. Cada cambio de estado te llega por POST:
```json
{
  "event": "document.sent",
  "document": {
    "id": 123, "tipo_documento": "01", "numero_completo": "F001-45",
    "sunat_status": "aceptado", "sunat_code": "0", "sunat_description": "...", "hash_cpe": "..."
  },
  "timestamp": "2026-10-03T10:21:05-05:00"
}
```
Eventos: `document.sent`, `document.rejected`, `document.status_updated`,
`document.timeout_sunat` (y equivalentes `summary.*`, `voided.*`, etc.).

**Verifica la firma** (cabecera `X-Signature: sha256=<hmac>`) antes de confiar:
```php
$esperado = 'sha256='.hash_hmac('sha256', $cuerpoCrudo, $tuApiSecret);
if (! hash_equals($esperado, $_SERVER['HTTP_X_SIGNATURE'] ?? '')) {
    http_response_code(401); exit;
}
```
Responde `2xx` rápido; reintentamos 3 veces (backoff) si fallas.

---

## 7. Otros útiles
- **Buscar RUC/DNI**: `GET /buscar-documento?tipo=6&numero=20123456789`.
- **Consultar CPE en SUNAT**: `GET /consultar-cpe` (ver campos en el OpenAPI).
- **Consultar CDR**: `POST /consultar-cdr` `{tipo_documento, serie, correlativo}`.
- **Clientes**: `GET/POST /clientes`, `GET/PUT/DELETE /clientes/{id}`.
- **Disponibilidad de SUNAT**: `GET /sunat/estado`.
- **Boletas / notas / guías / anulaciones**: mismos patrones que facturas
  (`/boletas`, `/notas-credito`, `/notas-debito`, `/guias-remision`,
  `/anulaciones`). Las boletas usan serie que empieza con **B**.

Documentación interactiva completa: **`/docs`** (OpenAPI). Catálogo de errores:
**doc 36**.

---

## 8. SDKs oficiales

En `sdk/` del repositorio hay clientes listos para usar:

### PHP (`sdk/php`)
```php
use JorgeChavez\ApiSunat\Client;

$api = new Client('TU_API_KEY', 'TU_API_SECRET', 'https://TU-DOMINIO');

$res = $api->crearFactura([
    'serie' => 'F001',
    'fecha_emision' => '2026-10-03',
    'cliente' => ['tipo_doc' => '6', 'num_doc' => '20123456789', 'razon_social' => 'ACME SAC'],
    'items' => [[
        'descripcion' => 'Servicio', 'unidad' => 'NIU',
        'cantidad' => 1, 'precio_unitario' => 118.0,
        'tip_afe_igv' => '10', 'porcentaje_igv' => 18,
    ]],
], Client::nuevaIdempotencyKey());

$id = $res['datos']['id'];
echo $api->estadoComprobante('facturas', $id);        // "enviado"
file_put_contents('f.pdf', $api->descargarPdf('facturas', $id));
```

### TypeScript (`sdk/ts`)
```ts
import { Client } from '@jorge-chavez/api-sunat-sdk';

const api = new Client({ apiKey: 'TU_API_KEY', apiSecret: 'TU_API_SECRET', baseUrl: 'https://TU-DOMINIO' });

const res = await api.crearFactura({
  serie: 'F001',
  fecha_emision: '2026-10-03',
  cliente: { tipo_doc: '6', num_doc: '20123456789', razon_social: 'ACME SAC' },
  items: [{ descripcion: 'Servicio', unidad: 'NIU', cantidad: 1, precio_unitario: 118.0, tip_afe_igv: '10', porcentaje_igv: 18 }],
}, Client.nuevaIdempotencyKey());

const id = (res.datos as any).id;
console.log(await api.estadoComprobante('facturas', id)); // "enviado"
```
