# Boleta de venta electrónica (Tipo 03)

**Qué es:** comprobante para consumidores finales (receptor sin RUC, típicamente
DNI o sin documento). No da crédito fiscal. Puede enviarse individualmente o
informarse por **Resumen Diario (RC)**.

## Endpoints — `BoletaController`
`routes/api.php:85-97`. Mismo set que factura **más**:
- **`DELETE /v1/boletas/{id}`** → `destroy`: único comprobante con borrado, y solo
  si `sunat_status ∈ {pendiente, rechazado}` y sin `hash_cpe` (`:194-211`).
- Flags de body: **`solo_registro`** (default false → queda `pendiente` para
  resumen diario) y `enviar_automatico` (default true).

## Campos (`StoreBoletaRequest`)
Idénticos a factura **salvo la serie: regex `^B[A-Z0-9]{3}$`** (`:31`). Mismo
resto (cliente, items, detracción, percepción, anticipos, descuentos, leyendas,
pagos).

## Reglas de negocio específicas (`withValidator:162-205`)

1. **Boleta > S/700 exige documento de identidad:** si `total > 700` y
   `cliente.tipo_doc='0'` → error (`:182-187`). Distinción clave frente a factura.
2. **Consistencia de documento:** DNI (`tipo_doc=1`) = 8 dígitos; RUC (`6`) = 11
   (`:192-198`). **Sí admite DNI** y varios tipos (a diferencia de factura).
3. `forma_pago=Credito ⇒ cuotas` obligatorias.
4. `validarSerieRegistrada('03')`, correlativo manual, cod_producto, tipoOperacion0112.

## Procesamiento interno
`CreateBoletaAction`: igual flujo que factura. **Diferencia NRUS**: `tipo_operacion`
default `0113` (Venta Interna-NRUS) si el régimen es restringido; si no, `0101`
(`:68`). Reutiliza el **mismo `InvoiceBuilder`** (el Job usa `buildInvoice` para
'01' y '03'). `solo_registro` → no encola (queda para resumen); `enviar_automatico`
→ encola + `enviado`.

## Factura vs Boleta (diferencias)

| Aspecto | Factura 01 | Boleta 03 |
|---|---|---|
| Prefijo de serie | `F###` | `B###` |
| Receptor | RUC obligatorio (6) | DNI permitido; umbral S/700 |
| NRUS | prohibida | permitida (`tipo_operacion 0113`) |
| Borrado (`destroy`) | no | sí (si pendiente/rechazado) |
| Resumen diario | no aplica | `solo_registro` → RC |
| Builder | `InvoiceBuilder` | `InvoiceBuilder` (compartido) |

## Firma, envío, estados y modelo
Igual que [factura](factura.md) (mismo Job, builder y clasificación de CDR).
`Boleta.php::getTipoDocumento()='03'`; mismos casts que `Invoice`.

> **Nota de diseño:** como la boleta comparte `InvoiceBuilder`, el builder acepta
> nodos (detracción/percepción/anticipos/exportación) que SUNAT podría observar
> para tipo 03; la validación de FormRequest no los bloquea (ver
> [documento 13](../13-inconsistencias.md)).

## Ejemplo
Ver [documento 08](../08-ejemplos-json-xml.md#boleta).
