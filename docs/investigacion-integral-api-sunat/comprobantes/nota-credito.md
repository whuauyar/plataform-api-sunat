# Nota de crédito electrónica (Tipo 07)

**Qué es:** documento que **modifica (reduce)** un comprobante ya emitido:
anulaciones, descuentos, devoluciones, correcciones. Referencia obligatoria al
documento afectado. Envío SOAP síncrono por CDR.

## Endpoints — `CreditNoteController`
`routes/api.php:100-108`: `POST notas-credito`, `GET` index/show, `xml`, `cdr`,
`pdf`, `PUT`, `POST reenviar/enviar`. **Sin `DELETE`** (una NC aceptada se anula
por comunicación de baja).

## Campos (`StoreCreditNoteRequest`)
Obligatorios **adicionales** frente a factura:
- **`doc_afectado_tipo`** (`in:01,03,12`, `:48`)
- **`doc_afectado_serie`** (size 4) y **`doc_afectado_correlativo`**
- **`cod_motivo`** (Cat 09, `:51`) y **`des_motivo`** (max 250)

`serie` solo `size:4` (sin regex de prefijo). **No** lleva forma_pago/cuotas/
detracción/percepción/anticipos. Sí admite `descuentos_globales` (para motivo 04).

## Motivos (Catálogo 09)
`01` Anulación · `02` Anulación por error en RUC · `03` Corrección descripción ·
`04` Descuento global · `05` Descuento por ítem · `06` Devolución · `07`
Bonificación · `08` Disminución del valor · `09` Otros. (Ver [doc 10](../10-catalogos-sunat.md).)

## Reglas de negocio (`withValidator` + `ValidatesNotaCreditoMonto`)

1. **Importe NC ≤ documento afectado** (`ValidatesNotaCreditoMonto`): aplica solo
   si el documento afectado **fue emitido por esta API** (lo busca en BD) y es de
   la **misma moneda**. **Diferencia NC sobre factura vs sobre boleta:**
   - Factura (01): tolerancia **0** (estricto `≤`).
   - Boleta (03): tolerancia **+1.0** (`:66-76`).
   Si el documento no existe localmente o es de distinta moneda, no valida (lo
   hace SUNAT).
2. **NRUS:** solo NC contra boletas (03); prohibido contra 01/12 (`:125-131`).
3. `validarSerieRegistrada('07')`, correlativo manual, cod_producto.

## Procesamiento — `CreateCreditNoteAction` → `NoteBuilder`
Serie '07' con lock; persiste `doc_afectado_*`, `cod_motivo`, `des_motivo`.
`NoteBuilder::build`: `setTipoDoc('07')`, `setTipDocAfectado` +
`setNumDocfectado(serie-correlativo)`, `setCodMotivo/setDesMotivo`, montos >0,
ítems (mismo `buildItem` sin percepción/detracción/anticipos), descuentos
globales. **No modela forma de pago ni cuotas** (correcto para notas).

## NC sobre factura vs sobre boleta
Misma estructura; la única diferencia funcional es la **tolerancia de importe**
(0 para factura, +1.0 para boleta) en `ValidatesNotaCreditoMonto`.

## Firma, envío, estados
Job `SendDocumentToSunat` tipo '07' → `buildNote` → SOAP. Clasificación por CDR
(doc 11). `getTipoDocumento()='07'`; casts solo `guias`/`contrato_colaboracion`.

## Ejemplo
Ver [documento 08](../08-ejemplos-json-xml.md#nota-de-credito).
