# 🧾 Liquidación de Compra (Tipo 04)

> Base URL: `https://tu-api.com/api/v1`
> Comprobante electrónico UBL `SelfBilledInvoice` 2.1 (versión 2.0). Serie: `L___` (empieza con **L**).

La liquidación de compra la emite un comprador afecto al Impuesto a la Renta de 3.ª categoría cuando adquiere bienes a un **proveedor persona natural sin RUC** (productores/acopiadores primarios de productos agropecuarios, pesca artesanal, minería aurífera, etc.). El emisor **retiene el IGV y el Impuesto a la Renta** y le paga al vendedor el **neto**.

## 📑 Endpoints

| Método | Ruta | Descripción |
|--------|------|-------------|
| `POST` | `/liquidaciones-compra` | Crear / emitir |
| `GET` | `/liquidaciones-compra` | Listar (filtros) |
| `GET` | `/liquidaciones-compra/{id}` | Ver |
| `POST` | `/liquidaciones-compra/{id}/enviar` | Enviar a SUNAT (modo manual) |
| `POST` | `/liquidaciones-compra/{id}/reenviar` | Reenviar (pendiente o rechazada) |
| `GET` | `/liquidaciones-compra/{id}/xml` | XML firmado |
| `GET` | `/liquidaciones-compra/{id}/cdr` | CDR de SUNAT |
| `GET` | `/liquidaciones-compra/{id}/pdf` | PDF (`?formato=a4\|a5\|ticket-80\|ticket-58`) |
| `POST` | `/liquidaciones-compra/{id}/pagos` | Registrar pago al vendedor |
| `GET` | `/liquidaciones-compra/{id}/pagos` | Listar pagos |
| `DELETE` | `/liquidaciones-compra/{id}/pagos/{paymentId}` | Eliminar pago |
| `POST` | `/liquidaciones-compra/{id}/reversion` | Revertir (Resumen de reversiones RR) |
| `GET` | `/liquidaciones-compra/reporte-retenciones?periodo=AAAA-MM` | Reporte de IGV y renta retenidos |

Autenticación: headers `X-Api-Key` y `X-Api-Secret` (igual que el resto de la API). Multiempresa: cada petición solo ve y emite las liquidaciones de su propia empresa.

---

## 🎯 Conceptos

- **Vendedor** = proveedor persona natural **sin RUC**: `tipo_doc` `1` (DNI, 8 dígitos) o `4` (carné de extranjería). En el XML es el `AccountingSupplierParty`; el **emisor** (tu empresa, con RUC) es el `AccountingCustomerParty`.
- **Tipo de operación** (`tipo_operacion`): `0501` general · `0503` compra de oro (retención 4 %) · `0502` anticipos (no disponible aún).
- **Retención de renta**: 1.5 % general; **4 %** para oro (0503); **0 %** si el importe de la operación (sin tributos) es ≤ S/ 700, o para productos primarios agropecuarios exonerados hasta el 31/12/2026.
- **IGV**: 18 % sobre las operaciones gravadas (`tip_afe_igv` `10`). También admite `20` exonerado y `30` inafecto.
- **Neto a pagar al vendedor** = valor de venta − retención de renta.

---

## 📤 Crear (`POST /liquidaciones-compra`)

```json
{
  "serie": "L001",
  "fecha_emision": "2026-10-27",
  "tipo_operacion": "0501",
  "enviar_automatico": true,
  "vendedor": {
    "tipo_doc": "1",
    "num_doc": "12345678",
    "nombre": "JUAN PEREZ AGRICULTOR",
    "direccion": "CASERIO LOS ANGELES S/N",
    "ubigeo": "150101",
    "tipo_domicilio": "01"
  },
  "lugar": { "direccion": "AV. ACOPIO 500, LIMA", "ubigeo": "150101", "tipo": "01" },
  "items": [
    {
      "descripcion": "Cafe pergamino seco",
      "cantidad": 100,
      "valor_unitario": 10,
      "unidad": "KGM",
      "cod_producto_sunat": "10101500",
      "tip_afe_igv": "10"
    }
  ],
  "guias": [{ "tipo": "09", "numero": "T001-123" }],
  "placa_vehiculo": "ABC123",
  "observacion": "Compra de café a productor"
}
```

Campos obligatorios: `serie`, `fecha_emision`, `vendedor.*` (tipo_doc, num_doc, nombre, direccion, ubigeo, tipo_domicilio), `lugar.*` (direccion, ubigeo, tipo), `items[].descripcion`, `items[].cantidad`, `items[].valor_unitario`, `items[].cod_producto_sunat`. Opcionales: `correlativo` (autoincrementa si se omite), `tipo_operacion` (`0501`), `productos_agropecuarios`, `guias`, `placa_vehiculo`, `observacion`, `enviar_automatico` (default `true`), `items[].codigo`, `items[].unidad` (default `KGM`), `items[].tip_afe_igv` (default `10`), `items[].oro` (obligatorio en 0503).

Oro (`tipo_operacion = 0503`), cada ítem exige:
```json
"oro": { "codigo_concesion": "...", "ley_mineral": 99.9, "naturaleza_mineral": "ORO", "nombre_derecho_minero": "..." }
```

### Respuesta `201`
```json
{
  "estado": "exito",
  "mensaje": "Liquidación de compra creada y encolada para envío a SUNAT.",
  "datos": {
    "id": 1, "tipo_documento": "04", "serie": "L001", "correlativo": 1,
    "numero_completo": "L001-1",
    "totales": {
      "gravadas": 1000, "igv": 180, "valor_venta": 1000,
      "porcentaje_renta": 1.5, "retencion_renta": 15, "neto_a_pagar": 985
    },
    "sunat": { "estado": "enviado", "codigo": null },
    "items": [ { "descripcion": "Cafe pergamino seco", "valor_venta": 1000, "igv": 180, "renta": 15, "precio_unitario": 11.8 } ]
  }
}
```

Con `enviar_automatico: false` la liquidación queda en estado `pendiente`; para enviarla luego: `POST /liquidaciones-compra/{id}/enviar`.

---

## ✅ Validaciones principales

| Regla | Mensaje / código SUNAT |
|-------|------------------------|
| Serie registrada y activa (tipo 04) | 422 |
| NRUS no puede emitir | 422 (SUNAT 2463) |
| `vendedor.tipo_doc` solo `1` o `4` | 422 |
| DNI de 8 dígitos / CE alfanumérico ≤ 15 | 422 (SUNAT 2802) |
| Fecha de emisión = hoy o día siguiente | 422 (SUNAT 2108 / 2329) |
| `cod_producto_sunat` de 8 dígitos válido (UNSPSC) | 422 |
| Compra de oro (0503) exige datos de concesión | 422 (catálogo 55) |
| Compras al vendedor en el año ≤ 75 UIT | 422 (SUNAT 2451) |

Estados (`sunat.estado`): `pendiente` → `enviado` → `aceptado` / `rechazado`.

---

## 💰 Pagos al vendedor

`POST /liquidaciones-compra/{id}/pagos` con `{ "metodo": "efectivo", "monto": 500, "referencia": "OP-001" }` (o `{ "pagos": [ ... ] }`). El total de referencia es el **neto a pagar** (`mto_imp_neto`). Devuelve `estado_pago` (pendiente/parcial/pagado) y `monto_pagado`.

## ↩️ Reversión (RR)

`POST /liquidaciones-compra/{id}/reversion` con `{ "causal": "producto", "motivo": "..." }`. Genera un **Resumen de reversiones (RR)** y deja la liquidación en `anulacion_en_proceso` hasta la respuesta de SUNAT (luego `anulado`). Requisitos: CDR **aceptado**, **sin pagos** registrados, dentro del **5.º día** calendario siguiente a la emisión. `causal`: `vendedor_nombre`, `vendedor_documento` o `producto`. El correlativo de la liquidación revertida **no se reutiliza**.

## 📊 Reporte de retenciones

`GET /liquidaciones-compra/reporte-retenciones?periodo=2026-10` devuelve, por cada liquidación aceptada del periodo, el IGV retenido y la renta retenida, con totales para el Formulario Virtual 617.

---

## 🧩 Diferencias con otros comprobantes

- No lleva cliente con RUC: el vendedor es persona natural sin RUC y va como **proveedor** (`AccountingSupplierParty`).
- El XML es **`SelfBilledInvoice`** (no `Invoice`); la raíz, el orden de las partes y el tributo de renta (3000 `IR`/`TOX`) son propios de este comprobante.
- Retiene **renta** además de IGV; el documento muestra el **neto a pagar**, no un total a cobrar.
