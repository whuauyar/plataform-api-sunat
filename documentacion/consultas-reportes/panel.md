# 📊 Panel de Control (Dashboard)

> Base URL: `https://tu-api.com/api/v1/panel`
> 11 endpoints especializados de estadísticas y analítica para dashboards empresariales.

## 📑 Endpoints

| Método | Ruta | Descripción |
|--------|------|-------------|
| `GET` | `/panel/` | Vista completa del mes |
| `GET` | `/panel/indicadores` | KPIs: hoy/semana/mes/año + crecimiento |
| `GET` | `/panel/estado-sunat` | Breakdown SUNAT + rechazos recientes |
| `GET` | `/panel/cobranzas` | Aging de cuentas por cobrar |
| `GET` | `/panel/ventas-mensuales` | Gráfico 12 meses vs año anterior |
| `GET` | `/panel/por-sucursal` | Ranking por sucursal |
| `GET` | `/panel/por-moneda` | Desglose PEN/USD |
| `GET` | `/panel/clientes` | Top + nuevos + recurrentes |
| `GET` | `/panel/productos` | Top venta/cantidad + tipo IGV |
| `GET` | `/panel/documentos-recientes` | Feed últimos 20 |
| `GET` | `/panel/alertas` | Rechazos, vencimientos, series |

## 📑 Reportes complementarios

| Método | Ruta | Descripción |
|--------|------|-------------|
| `GET` | `/reportes/registro-ventas` | Registro de ventas (PLE) |
| `GET` | `/reportes/ventas-consolidado` | Consolidado por periodo |
| `GET` | `/reportes/notas` | NC/ND del periodo |
| `GET` | `/reportes/cobranzas` | Detalle cobranzas |
| `GET` | `/reportes/documentos-internos` | Cotizaciones + notas venta |
| `GET` | `/reportes/por-cliente` | Ventas agrupadas por cliente |
| `GET` | `/reportes/por-sucursal` | Ventas por sucursal |

---

## 1. `GET /panel/` — Vista completa del mes

Periodo flexible: por mes o rango.

### Query params

- `mes` — `YYYY-MM` (ej: `2026-04`)
- `desde` / `hasta` — rango personalizado en `yyyy-mm-dd` (alternativa a `mes`)
- Si no se envía nada: mes actual

```bash
curl "https://tu-api.com/api/v1/panel?mes=2026-04" \
  -H "X-Api-Key: {k}" -H "X-Api-Secret: {s}"
```

### Respuesta

```json
{
  "estado": "exito",
  "datos": {
    "periodo": {
      "desde": "2026-04-01",
      "hasta": "2026-04-30",
      "label": "Abril 2026"
    },
    "facturas": { "count": 45, "total": 85000.00 },
    "boletas": { "count": 128, "total": 12500.00 },
    "notas_credito": { "count": 3, "total": 1500.00 },
    "notas_debito": { "count": 1, "total": 200.00 },
    "notas_venta": { "count": 12, "total": 3400.00 },
    "grafico": [
      { "fecha": "2026-04-01", "ventas": 1200.00 },
      { "fecha": "2026-04-02", "ventas": 2300.00 }
    ],
    "top_productos": [
      { "nombre": "LAPTOP HP", "total": 44250.00 }
    ],
    "top_clientes": [
      { "nombre": "ACME SAC", "total": 25000.00, "documentos": 8 }
    ],
    "comparacion": {
      "ventas_actuales": 98400.00,
      "ventas_anteriores": 87200.00,
      "cambio_ventas_porcentaje": 12.8,
      "documentos_actuales": 185,
      "documentos_anteriores": 165,
      "cambio_documentos_porcentaje": 12.1
    }
  }
}
```

> Claves reales (`Api\V1\DashboardController@index`): `periodo` **no** trae `mes`; los conteos
> van en el nivel superior como `facturas`/`boletas`/`notas_credito`/`notas_debito`/`notas_venta`
> con `{count, total}` (no un objeto `resumen_mes` con `cantidad/monto/igv`); el gráfico es
> `grafico` (no `chart_diario`) con solo `{fecha, ventas}`; `top_productos` = `{nombre, total}`;
> `top_clientes` = `{nombre, total, documentos}`; y `comparacion` usa
> `ventas_actuales/ventas_anteriores/cambio_ventas_porcentaje` (+ los equivalentes de documentos).

---

## 2. `GET /panel/indicadores` — KPIs

Métricas financieras clave con comparaciones temporales.

```bash
curl https://tu-api.com/api/v1/panel/indicadores \
  -H "X-Api-Key: {k}" -H "X-Api-Secret: {s}"
```

### Respuesta

```json
{
  "estado": "exito",
  "datos": {
    "hoy":          { "documentos": 8,   "ventas": 3200.00,   "igv": 488.14,   "gratuitas": 0, "icbper": 0, "ticket_promedio": 400.00 },
    "ayer":         { "documentos": 10,  "ventas": 4100.00,   "igv": 625.42,   "gratuitas": 0, "icbper": 0, "ticket_promedio": 410.00 },
    "semana":       { "documentos": 45,  "ventas": 22500.00,  "igv": 3432.20,  "gratuitas": 0, "icbper": 0, "ticket_promedio": 500.00 },
    "mes_actual":   { "documentos": 173, "ventas": 98400.00,  "igv": 15010.17, "gratuitas": 0, "icbper": 0, "ticket_promedio": 568.79 },
    "mes_anterior": { "documentos": 165, "ventas": 87200.00,  "igv": 13301.69, "gratuitas": 0, "icbper": 0, "ticket_promedio": 528.48 },
    "anio_actual":  { "documentos": 820, "ventas": 450000.00, "igv": 68644.07, "gratuitas": 0, "icbper": 0, "ticket_promedio": 548.78 },
    "anio_anterior":{ "documentos": 780, "ventas": 420000.00, "igv": 64067.80, "gratuitas": 0, "icbper": 0, "ticket_promedio": 538.46 },
    "crecimiento": {
      "vs_ayer": -22.0,
      "vs_mes_anterior": 12.8,
      "vs_anio_anterior": 7.1
    }
  }
}
```

> La clave del rango semanal es `semana` (no `semana_actual`), y cada rango devuelve
> `{documentos, ventas, igv, gratuitas, icbper, ticket_promedio}` (no `{cantidad, monto}`).
> Estos rangos son fijos (hoy/ayer/semana/mes/año); **ignoran** el parámetro `?mes=`.

---

## 3. `GET /panel/estado-sunat` — Breakdown SUNAT

Monitoreo en tiempo real del estado de los envíos a SUNAT.

```bash
curl https://tu-api.com/api/v1/panel/estado-sunat \
  -H "X-Api-Key: {k}" -H "X-Api-Secret: {s}"
```

### Respuesta

```json
{
  "estado": "exito",
  "datos": {
    "periodo": { "desde": "2026-04-01", "hasta": "2026-04-30" },
    "total_documentos": 630,
    "por_estado": {
      "pendiente": 10,
      "enviado": 18,
      "aceptado": 592,
      "rechazado": 4,
      "anulado": 6
    },
    "por_tipo": {
      "facturas":      { "aceptado": 120, "enviado": 5, "pendiente": 2, "rechazado": 3, "anulado": 1 },
      "boletas":       { "aceptado": 450, "enviado": 12, "pendiente": 8, "rechazado": 1, "anulado": 5 },
      "notas_credito": { "aceptado": 18, "enviado": 1 },
      "notas_debito":  { "aceptado": 4 }
    },
    "observados": 2,
    "tasa_aceptacion": 94.0,
    "ultimos_rechazos": [
      {
        "tipo": "Factura",
        "numero": "F001-00000300",
        "codigo_sunat": "2325",
        "motivo": "El documento electrónico ingresado ha sido alterado",
        "fecha": "2026-04-18"
      }
    ]
  }
}
```

> Diferencias vs. versiones anteriores del doc: `por_tipo` se indexa por `facturas`/`boletas`/
> `notas_credito`/`notas_debito` (no `01_facturas`…) y solo trae los estados presentes;
> `observados` es un **entero** (conteo de aceptados con `sunat_code` 3xxx), no un arreglo;
> cada rechazo trae `{tipo, numero, codigo_sunat, motivo, fecha}` (el `numero` viene con el
> correlativo a 8 dígitos). También se incluyen `periodo` y `total_documentos`.

---

## 4. `GET /panel/cobranzas` — Aging

Análisis de cuentas por cobrar con envejecimiento.

```bash
curl https://tu-api.com/api/v1/panel/cobranzas \
  -H "X-Api-Key: {k}" -H "X-Api-Secret: {s}"
```

### Respuesta

```json
{
  "estado": "exito",
  "datos": {
    "resumen": {
      "pagado":    { "documentos": 150, "monto_total": 85000.00, "monto_pagado": 85000.00, "saldo": 0.00 },
      "parcial":   { "documentos": 12,  "monto_total": 15000.00, "monto_pagado": 8000.00,  "saldo": 7000.00 },
      "pendiente": { "documentos": 25,  "monto_total": 32000.00, "monto_pagado": 0.00,     "saldo": 32000.00 }
    },
    "aging": {
      "por_vencer": 18000.00,
      "vencido_0_30": 8500.00,
      "vencido_31_60": 3000.00,
      "vencido_61_90": 1500.00,
      "vencido_mas_90": 0.00,
      "documentos_con_saldo": 25
    },
    "top_deudores": [
      {
        "ruc": "20555666777",
        "cliente": "ACME SAC",
        "documentos": 3,
        "saldo": 8500.00
      }
    ]
  }
}
```

> `resumen` se indexa por estado de pago (`pagado`/`parcial`/`pendiente`), cada uno con
> `{documentos, monto_total, monto_pagado, saldo}`. El `aging` son **montos escalares** (saldo
> por tramo), no objetos `{cantidad, monto}`, y agrega `documentos_con_saldo`. `top_deudores`
> usa `{ruc, cliente, documentos, saldo}` (no `num_doc/razon_social/docs_pendientes/dias_mora_max`).

---

## 5. `GET /panel/ventas-mensuales` — Chart 12 meses vs YoY

```bash
curl https://tu-api.com/api/v1/panel/ventas-mensuales \
  -H "X-Api-Key: {k}" -H "X-Api-Secret: {s}"
```

### Respuesta

```json
{
  "estado": "exito",
  "datos": {
    "meses": [
      { "mes": "2025-05", "mes_label": "Mayo 2025", "ventas": 75000.00, "ventas_anio_anterior": 68000.00 },
      { "mes": "2025-06", "mes_label": "Junio 2025", "ventas": 82000.00, "ventas_anio_anterior": 71000.00 }
    ],
    "total_12_meses": 1024800.00,
    "total_12_meses_anterior": 940000.00,
    "crecimiento_porcentaje": 9.0,
    "promedio_mensual": 85400.00
  }
}
```

> Cada mes usa `{mes (YYYY-MM), mes_label, ventas, ventas_anio_anterior}` (no `actual/anterior/
> cantidad_*`). Los totales son `total_12_meses`, `total_12_meses_anterior`,
> `crecimiento_porcentaje` y `promedio_mensual` (no `total_anio`).

---

## 6. `GET /panel/por-sucursal` — Ranking

```bash
curl https://tu-api.com/api/v1/panel/por-sucursal \
  -H "X-Api-Key: {k}" -H "X-Api-Secret: {s}"
```

### Respuesta

```json
{
  "estado": "exito",
  "datos": {
    "periodo": { "desde": "2026-04-01", "hasta": "2026-04-30" },
    "total_general": 98400.00,
    "sucursales": [
      {
        "cod_local": "0000",
        "nombre": "Sede Principal",
        "facturas": { "cantidad": 45, "monto": 65000.00 },
        "boletas": { "cantidad": 135, "monto": 0.00 },
        "total": 65000.00,
        "share_porcentaje": 66.1
      },
      {
        "cod_local": "0001",
        "nombre": "Sucursal Norte",
        "facturas": { "cantidad": 20, "monto": 20900.00 },
        "boletas": { "cantidad": 75, "monto": 12500.00 },
        "total": 33400.00,
        "share_porcentaje": 33.9
      }
    ]
  }
}
```

> La respuesta es un objeto `{periodo, total_general, sucursales[]}` (no un arreglo plano).
> Cada sucursal usa `{cod_local, nombre, facturas{cantidad,monto}, boletas{cantidad,monto},
> total, share_porcentaje}` — se identifica por `cod_local`, no por `sucursal_id`.

---

## 7. `GET /panel/por-moneda`

```bash
curl https://tu-api.com/api/v1/panel/por-moneda \
  -H "X-Api-Key: {k}" -H "X-Api-Secret: {s}"
```

### Respuesta

```json
{
  "estado": "exito",
  "datos": {
    "periodo": { "desde": "2026-04-01", "hasta": "2026-04-30" },
    "monedas": [
      { "moneda": "PEN", "documentos": 265, "total": 85000.00, "igv": 12960.00 },
      { "moneda": "USD", "documentos": 10, "total": 3500.00, "igv": 534.00 }
    ]
  }
}
```

> `monedas` es un **arreglo** de `{moneda, documentos, total, igv}` (no un objeto con claves
> `PEN`/`USD`). Solo aparecen las monedas con documentos en el periodo.

---

## 8. `GET /panel/clientes`

```bash
curl https://tu-api.com/api/v1/panel/clientes \
  -H "X-Api-Key: {k}" -H "X-Api-Secret: {s}"
```

### Respuesta

```json
{
  "estado": "exito",
  "datos": {
    "periodo": { "desde": "2026-04-01", "hasta": "2026-04-30" },
    "total_registrados": 137,
    "clientes_unicos_periodo": 92,
    "clientes_nuevos": [
      { "ruc": "20123456789", "nombre": "NUEVO SAC", "primera_compra": "2026-04-05" }
    ],
    "clientes_recurrentes": 85,
    "top_20": [
      { "ruc": "20555666777", "tipo_documento": "6", "nombre": "ACME SAC", "documentos": 12, "total": 35000.00 }
    ]
  }
}
```

> `clientes_recurrentes` es un **entero** (conteo), no un objeto. `top_20` usa
> `{ruc, tipo_documento, nombre, documentos, total}`. Se incluyen `periodo`,
> `total_registrados` y `clientes_unicos_periodo`.

---

## 9. `GET /panel/productos`

```bash
curl https://tu-api.com/api/v1/panel/productos \
  -H "X-Api-Key: {k}" -H "X-Api-Secret: {s}"
```

### Respuesta

```json
{
  "estado": "exito",
  "datos": {
    "periodo": { "desde": "2026-04-01", "hasta": "2026-04-30" },
    "top_por_venta": [
      { "codigo": "P001", "descripcion": "LAPTOP HP PAVILION 15", "cantidad": 15, "total": 44250.00 }
    ],
    "top_por_cantidad": [
      { "codigo": "P002", "descripcion": "MOUSE LOGITECH", "cantidad": 230, "total": 13570.00 }
    ],
    "por_tipo_igv": [
      { "codigo": "10", "descripcion": "Gravado - Operación Onerosa", "items": 420, "total": 88000.00 },
      { "codigo": "20", "descripcion": "Exonerado - Operación Onerosa", "items": 35, "total": 2500.00 }
    ]
  }
}
```

> Cada producto usa `{codigo, descripcion, cantidad, total}` (no `cantidad_vendida/monto_total`);
> `por_tipo_igv` usa `{codigo, descripcion, items, total}` (la clave es `codigo`, no `tip_afe_igv`).

---

## 10. `GET /panel/documentos-recientes`

```bash
curl https://tu-api.com/api/v1/panel/documentos-recientes \
  -H "X-Api-Key: {k}" -H "X-Api-Secret: {s}"
```

### Respuesta

```json
{
  "estado": "exito",
  "datos": {
    "documentos": [
      {
        "tipo": "factura",
        "id": 123,
        "numero": "F001-00000123",
        "fecha_emision": "2026-04-18",
        "cliente_documento": "20555666777",
        "cliente": "ACME SAC",
        "moneda": "PEN",
        "total": 7052.86,
        "estado_sunat": "aceptado",
        "emitido_en": "2026-04-18T14:30:00-05:00"
      }
    ]
  }
}
```

Devuelve los últimos 20 documentos emitidos (union de todas las tablas), o hasta
`?limit=` (máximo 100). La respuesta es un objeto `{ "documentos": [...] }`; cada ítem usa
`{tipo, id, numero, fecha_emision, cliente_documento, cliente, moneda, total, estado_sunat, emitido_en}`.

---

## 11. `GET /panel/alertas`

Alertas operativas críticas.

```bash
curl https://tu-api.com/api/v1/panel/alertas \
  -H "X-Api-Key: {k}" -H "X-Api-Secret: {s}"
```

### Respuesta

```json
{
  "estado": "exito",
  "datos": {
    "rechazados_7_dias": { "cantidad": 3 },
    "vencen_proximos_7_dias": { "cantidad": 5, "monto": 8500.00 },
    "vencidos": { "cantidad": 12, "monto": 15000.00 },
    "pendientes_envio_sunat": { "cantidad": 8 },
    "series_por_agotar": [
      { "tipo_documento": "01", "serie": "F001", "correlativo": 90500000 }
    ]
  }
}
```

> Los bloques son solo contadores: `rechazados_7_dias` y `pendientes_envio_sunat` traen únicamente
> `{cantidad}`; `vencen_proximos_7_dias` y `vencidos` traen `{cantidad, monto}` (no incluyen un
> arreglo `docs`). `series_por_agotar` son filas crudas `{tipo_documento, serie, correlativo}`
> (series con correlativo > 90 000 000); no hay campos `restantes`/`alerta`.

---

## 📊 Reportes complementarios

> **Parámetros comunes** (validados por `ReportRequest`): `fecha_desde` y
> `fecha_hasta` son **obligatorios** (`Y-m-d`, rango máx. 366 días). Opcionales:
> `sucursal_id`, `serie`, `client_num_doc`, `estado_sunat`
> (`pendiente|enviado|aceptado|rechazado`), `estado_pago`
> (`pendiente|parcial|pagado`), `tipo_moneda` (`PEN|USD`), `agrupacion`
> (`dia|semana|mes`), `vencido` (bool) y `formato` (**`json`** por defecto, o `pdf`).

### `GET /reportes/registro-ventas`

Reporte estilo PLE (Programa Libros Electrónicos) para el período.

```bash
# JSON
curl "https://tu-api.com/api/v1/reportes/registro-ventas?fecha_desde=2026-04-01&fecha_hasta=2026-04-30" \
  -H "X-Api-Key: {k}" -H "X-Api-Secret: {s}"

# PDF
curl "https://tu-api.com/api/v1/reportes/registro-ventas?fecha_desde=2026-04-01&fecha_hasta=2026-04-30&formato=pdf" \
  -H "X-Api-Key: {k}" -H "X-Api-Secret: {s}" -o registro-ventas.pdf
```

### `GET /reportes/ventas-consolidado`

Totales consolidados del período (acepta `tipo_moneda`, `sucursal_id`).

### `GET /reportes/notas`

Lista NC/ND del periodo con relación al documento afectado.

### `GET /reportes/cobranzas`

Detalle de cuentas por cobrar con aging.

### `GET /reportes/documentos-internos`

Cotizaciones + Notas de venta (no SUNAT).

### `GET /reportes/por-cliente`

**Query:** `client_num_doc` (documento del cliente), más los obligatorios `fecha_desde` y
`fecha_hasta` (`ReportRequest`). Los nombres `cliente_doc`/`desde`/`hasta` **no existen** y
devuelven 422 / "filtro requerido".

### `GET /reportes/por-sucursal`

Breakdown detallado por sucursal.

---

## 🎯 Uso típico en una UI

```javascript
// Dashboard principal — carga en paralelo
Promise.all([
  fetch('/panel/indicadores', { headers }),
  fetch('/panel/ventas-mensuales', { headers }),
  fetch('/panel/estado-sunat', { headers }),
  fetch('/panel/alertas', { headers }),
  fetch('/panel/documentos-recientes', { headers }),
]).then(renderDashboard);

// Sección específica
fetch('/panel/cobranzas', { headers }).then(renderAging);
fetch('/panel/por-sucursal', { headers }).then(renderRanking);
```

---

## ⚙️ Notas de performance

- Todos los endpoints del panel usan **raw SQL optimizado** (UNION ALL entre tablas de docs)
- Se aprovechan índices compuestos en `(tenant_id, fecha_emision)` y `(tenant_id, sunat_status)`
- Algunas respuestas están limitadas a Top 20 para mantener tiempos de respuesta <200ms
- Para periodos muy grandes (>100k docs) considerar usar `/reportes/*` que retornan archivos

---

## 🔗 Relacionados

- Indicadores financieros profundos → [`04-Facturas.md`](../emision/facturas.md) + agregaciones manuales
- Análisis de compras (RCE) → [`17-Sire.md`](../sire/sire.md) módulo SIRE
- Consultas CPE puntuales → [`14-Consultar-CPE.md`](consultar-cpe.md)
