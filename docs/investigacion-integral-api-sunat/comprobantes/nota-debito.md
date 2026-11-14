# Nota de débito electrónica (Tipo 08)

**Qué es:** documento que **incrementa** el importe de un comprobante ya emitido:
intereses por mora, aumentos de valor, penalidades. Envío SOAP síncrono por CDR.

## Endpoints — `DebitNoteController`
`routes/api.php:111-119`: idéntico patrón a NC (`POST`, index/show, xml/cdr/pdf,
PUT, enviar/reenviar). Sin `DELETE`.

## Campos (`StoreDebitNoteRequest`)
Como NC, pero:
- **`cod_motivo` = Catálogo 10** (`:50`).
- **`doc_afectado_*` opcionales SOLO para motivo 13 (Penalidades)** vía
  `required_unless:cod_motivo,13` (`:47-49`).
- `des_motivo` requerido.

## Motivos (Catálogo 10)
`01` Intereses por mora · `02` Aumento del valor · `03` Otros conceptos · `04-08`
Ajustes · `09` Otros · `11` Ajustes exportación · `12` Ajustes IVAP · **`13`
Penalidades** (RS 048-2026). (Ver [doc 10](../10-catalogos-sunat.md).)

## Reglas de negocio específicas (`withValidator:107-138`)

1. **Motivo 13 (Penalidades) ⇒ solo operaciones inafectas del IGV/IVAP**
   (ERR-3507): la suma `mto_igv + mto_ivap + igv de ítems` debe ser 0, si no →
   error (`:116-127`). **Regla exclusiva de ND.**
2. **Motivo 13 ⇒ documento afectado opcional:** el builder omite la
   `BillingReference` y `GreenterService::stripEmptyNoteReference` elimina el nodo
   vacío antes de firmar (`NoteBuilder:49-52` + `GreenterService:595-605`).
3. **NRUS:** solo ND contra boletas (03) (`:129-136`).
4. `validarSerieRegistrada('08')`, correlativo manual, cod_producto.
   **No hereda `ValidatesNotaCreditoMonto`** (la cota de importe solo aplica a NC).

## Procesamiento — `CreateDebitNoteAction` → `NoteBuilder`
Serie '08' con lock; `doc_afectado_*` persistidos con `?? null` (nullable para
penalidades). **Mismo `NoteBuilder` que NC** (distingue por `tipo_documento`).
Job tipo '08' → `buildNote`.

## NC vs ND (diferencias)

| Aspecto | NC (07) | ND (08) |
|---|---|---|
| Efecto | reduce el importe | incrementa el importe |
| Catálogo de motivo | 09 | 10 |
| Doc. afectado | **siempre** (01,03,12) | obligatorio salvo motivo 13 |
| Cota de importe | sí (≤ documento) | no |
| Regla especial | — | motivo 13 exige IGV=0 |
| Builder / endpoint SUNAT | `NoteBuilder` / `fe` | `NoteBuilder` / `fe` |

## Ejemplo
Ver [documento 08](../08-ejemplos-json-xml.md#nota-de-debito).
