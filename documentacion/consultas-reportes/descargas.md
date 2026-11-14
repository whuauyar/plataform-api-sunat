# Descargas de XML, PDF y CDR

Rutas para descargar los archivos de los comprobantes: **individuales** (uno por comprobante) y **masiva** (un ZIP por rango de fechas).

- **Base URL:** `/api/v1`
- **Autenticación:** cabeceras `X-Api-Key` + `X-Api-Secret` (middleware `resolve.tenant`).
- Todas las rutas de descarga son **GET**.
- Inventario completo de rutas: ver [`../rutas.md`](../rutas.md).

---

## 1. Descarga masiva (ZIP)

Genera un `.zip` con los archivos XML y/o PDF de todos los comprobantes en un rango de fechas. Es la **única** ruta de descarga masiva.

```
GET /api/v1/comprobantes/exportar-zip
```

Controlador: `ExportController@zip`. La respuesta es un `.zip` descargable (no JSON); si no hay archivos para los criterios, devuelve **404** en JSON.

### Parámetros (query string)

| Parámetro | Requerido | Valores | Default | Descripción |
|-----------|-----------|---------|---------|-------------|
| `fecha_desde` | **Sí** | `Y-m-d` | — | Inicio del rango (00:00:00). |
| `fecha_hasta` | **Sí** | `Y-m-d` | — | Fin del rango (23:59:59). |
| `tipo` | No | `xml` \| `pdf` \| `ambos` | `xml` | Qué archivo incluir. |
| `documentos` | No | `facturas,boletas,notas-credito,notas-debito` | todos | Tipos a incluir (coma). |
| `sucursal_id` | No | int | — | Filtra por sucursal. |
| `estado` | No | `aceptado` \| `pendiente` \| `rechazado` \| `todos` | `todos` | Filtra por `sunat_status`. |

### Reglas y límites
- Rango máximo: **366 días**; `fecha_desde` ≤ `fecha_hasta`.
- Estructura interna: `{tipo}/{YYYY-MM}/{SERIE}-{CORRELATIVO}.{ext}` (ej. `facturas/2026-09/F001-000123.xml`).
- Nombre de descarga: `comprobantes_{RUC}_{desde}_{hasta}_{tipo}.zip`.
- Solo se incluyen documentos cuyo archivo ya está generado en disco; los que no, se omiten en silencio.
- **404** si no hay archivos (sin documentos en rango, todos `pendiente` sin XML, o archivos ya no en disco).

> ⚠️ **Limitaciones:** (1) **no incluye CDR** (`tipo` solo acepta `xml`/`pdf`/`ambos`); el CDR se baja individualmente. (2) Solo cubre **facturas, boletas, notas de crédito y notas de débito** — no guías, resúmenes, retenciones ni percepciones.

### Ejemplos

```bash
# XMLs de todo el mes
curl -X GET "https://tu-api.com/api/v1/comprobantes/exportar-zip?fecha_desde=2026-05-01&fecha_hasta=2026-05-31" \
  -H "X-Api-Key: {api_key}" -H "X-Api-Secret: {api_secret}" --output comprobantes_mayo.zip

# Solo facturas y boletas aceptadas
curl -X GET "https://tu-api.com/api/v1/comprobantes/exportar-zip?fecha_desde=2026-05-01&fecha_hasta=2026-05-31&documentos=facturas,boletas&estado=aceptado" \
  -H "X-Api-Key: {api_key}" -H "X-Api-Secret: {api_secret}" --output aceptadas.zip

# XML + PDF de una sucursal
curl -X GET "https://tu-api.com/api/v1/comprobantes/exportar-zip?fecha_desde=2026-05-01&fecha_hasta=2026-05-31&tipo=ambos&sucursal_id=2" \
  -H "X-Api-Key: {api_key}" -H "X-Api-Secret: {api_secret}" --output sucursal2.zip
```

**En Postman:** método `GET`, URL `{{base_url}}/comprobantes/exportar-zip`, los parámetros como query params, y usar **Send and Download** (flecha junto a Send) para guardar el ZIP.

### Errores frecuentes
- **422** — faltan `fecha_desde`/`fecha_hasta`, rango > 366 días, o `tipo` inválido.
- **404** — no hay archivos para los criterios (ver reglas arriba).

### Recomendaciones
- Para contabilidad/SIRE: `documentos=facturas&estado=aceptado` (solo válidos ante SUNAT).
- Para respaldos mensuales: descargá el 1.º del mes siguiente, cuando todo está procesado.
- Para muchos meses: preferí descargas por mes para evitar ZIPs enormes.
- Los PDF solo entran al ZIP si fueron generados antes (se generan on-demand al pedir `/{tipo}/{id}/pdf` y se cachean).

---

## 2. Descargas individuales (por comprobante)

Cada comprobante se descarga por su `{id}`. La respuesta es el archivo binario (`Content-Disposition: attachment`; el PDF va `inline`). Devuelven **404** si el archivo aún no existe (p. ej. CDR antes de que SUNAT lo devuelva).

| Documento | Tipo SUNAT | XML | CDR | PDF |
|-----------|:---------:|:---:|:---:|:---:|
| Facturas | 01 | `GET /facturas/{id}/xml` | `GET /facturas/{id}/cdr` | `GET /facturas/{id}/pdf` |
| Boletas | 03 | `GET /boletas/{id}/xml` | `GET /boletas/{id}/cdr` | `GET /boletas/{id}/pdf` |
| Notas de crédito | 07 | `GET /notas-credito/{id}/xml` | `GET /notas-credito/{id}/cdr` | `GET /notas-credito/{id}/pdf` |
| Notas de débito | 08 | `GET /notas-debito/{id}/xml` | `GET /notas-debito/{id}/cdr` | `GET /notas-debito/{id}/pdf` |
| Guías de remisión | 09/31 | `GET /guias-remision/{id}/xml` | `GET /guias-remision/{id}/cdr` | `GET /guias-remision/{id}/pdf` |
| Resúmenes diarios | RC | `GET /resumenes/{id}/xml` | `GET /resumenes/{id}/cdr` | — |
| Retenciones | 20 | `GET /retenciones/{id}/xml` | `GET /retenciones/{id}/cdr` | `GET /retenciones/{id}/pdf` |
| Percepciones | 40 | `GET /percepciones/{id}/xml` | `GET /percepciones/{id}/cdr` | — |
| Cotizaciones | interno | — | — | `GET /cotizaciones/{id}/pdf` |
| Notas de venta | interno | — | — | `GET /notas-venta/{id}/pdf` |

### Notas por tipo
- **Guías de remisión:** además del CDR, el estado se consulta con `GET /guias-remision/{id}/estado` (SUNAT las procesa por API REST con ticket).
- **Resúmenes y percepciones:** no generan PDF.
- **Cotizaciones y notas de venta:** documentos internos (no van a SUNAT), solo PDF.

### Content-Type de la respuesta
| Archivo | Content-Type | Disposition |
|---------|--------------|-------------|
| XML | `application/xml` | `attachment` — `{SERIE}-{CORRELATIVO}.xml` |
| CDR | `application/zip` | `attachment` — `R-{SERIE}-{CORRELATIVO}.zip` |
| PDF | `application/pdf` | `inline` — `{SERIE}-{CORRELATIVO}.pdf` |

### PDF: formato configurable
Acepta `?formato=` (o `?format=`): `a4` (default), `a5`, `ticket-80`, `ticket-58`. Formato inválido → **422**.

```bash
GET /api/v1/boletas/123/pdf?formato=ticket-80
```

---

## 3. Alias simplificados (`/api/sunat`)

Prefijo espejo con las descargas más usadas (misma autenticación):

```
GET /api/sunat/facturas/{id}/pdf   ·   GET /api/sunat/facturas/{id}/xml
GET /api/sunat/boletas/{id}/pdf    ·   GET /api/sunat/boletas/{id}/xml
```

> El alias **no** expone CDR ni descarga masiva; para eso usá las rutas `/api/v1`.

---

## Resumen: ¿hay descarga masiva?

| Formato | Masivo | Individual |
|---|---|---|
| XML | ✅ `exportar-zip?tipo=xml` | ✅ `/{tipo}/{id}/xml` |
| PDF | ✅ `exportar-zip?tipo=pdf` | ✅ `/{tipo}/{id}/pdf` |
| XML+PDF | ✅ `exportar-zip?tipo=ambos` | — |
| CDR | ❌ **No existe** | ✅ `/{tipo}/{id}/cdr` |
