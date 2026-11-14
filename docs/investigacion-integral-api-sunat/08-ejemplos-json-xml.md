# 08 — Ejemplos JSON y XML

Ejemplos basados en estructuras **reales** de la colección Postman y los
FormRequests. Autenticación: cabeceras `X-Api-Key` + `X-Api-Secret`.

<a id="factura"></a>
## Factura (01)

### A. Escenario
Una empresa vende 2 unidades de un producto a S/118 c/u (precio **con IGV**) a otra
empresa con RUC. Operación gravada estándar (18 %).

### B/C. JSON de solicitud — `POST /v1/facturas`
(ejemplo real de la colección: “Factura … modo offline”)

```json
{
  "serie": "F001",
  "fecha_emision": "2026-03-04",
  "tipo_operacion": "0101",
  "tipo_moneda": "PEN",
  "enviar_automatico": false,
  "cliente": {
    "tipo_doc": "6",
    "num_doc": "20000000002",
    "razon_social": "EMPRESA X SAC"
  },
  "items": [
    {
      "codigo": "P001",
      "descripcion": "PROD 1",
      "unidad": "NIU",
      "cantidad": 2,
      "precio_unitario": 118,
      "tip_afe_igv": "10"
    }
  ]
}
```

### D. Campo por campo
| Campo | Regla | Significado |
|---|---|---|
| `serie` | `^F[A-Z0-9]{3}$` | Serie de factura registrada (F001) |
| `fecha_emision` | date | Fecha de emisión (zona Lima) |
| `tipo_operacion` | Cat 51, def `0101` | Venta interna |
| `tipo_moneda` | PEN/USD/EUR | Moneda |
| `enviar_automatico` | bool, def true | `false` → queda `pendiente` (no encola) |
| `cliente.tipo_doc` | Cat 06; factura exige `6` | RUC |
| `cliente.num_doc` | 11 díg (prefijo 10/15/17/20) | RUC del receptor |
| `items[].unidad` | Cat 03 | Unidad de medida (NIU) |
| `items[].cantidad` | > 0 | Cantidad |
| `items[].precio_unitario` | ≥ 0 | Precio **con IGV** |
| `items[].tip_afe_igv` | Cat 07 | `10` gravado |

### E. Cálculos (verificado con el código real — ver [doc 12](12-pruebas-resultados.md))
```
valor unitario = 118 / 1.18         = 100.00
valor de venta = 100 × 2            = 200.00
IGV            = (118×2) − 200      = 36.00
mto_imp_venta  = 200 + 36           = 236.00
leyenda        = "DOSCIENTOS TREINTA Y SEIS CON 00/100 SOLES"
```

### F. Procesamiento interno
`InvoiceController@store` → `StoreInvoiceRequest` → `CreateInvoiceAction::execute`
(serie con lock, cliente, `DocumentCalculationService`, `Invoice::create`) → (si
`enviar_automatico`) `SendDocumentToSunat` → `InvoiceBuilder` → firma → SOAP.

### G. XML UBL 2.1 (representativo, simplificado)
```xml
<Invoice xmlns="urn:oasis:names:specification:ubl:schema:xsd:Invoice-2">
  <cbc:UBLVersionID>2.1</cbc:UBLVersionID>
  <cbc:ID>F001-00000001</cbc:ID>
  <cbc:IssueDate>2026-03-04</cbc:IssueDate>
  <cbc:InvoiceTypeCode listID="0101">01</cbc:InvoiceTypeCode>   <!-- 01=factura, 0101=venta interna -->
  <cbc:DocumentCurrencyCode>PEN</cbc:DocumentCurrencyCode>
  <cac:AccountingSupplierParty>…RUC emisor…</cac:AccountingSupplierParty>
  <cac:AccountingCustomerParty>…RUC 20000000002…</cac:AccountingCustomerParty>
  <cac:TaxTotal>
    <cbc:TaxAmount currencyID="PEN">36.00</cbc:TaxAmount>
    <cac:TaxSubtotal>
      <cbc:TaxableAmount currencyID="PEN">200.00</cbc:TaxableAmount>
      <cbc:TaxAmount currencyID="PEN">36.00</cbc:TaxAmount>
      <cac:TaxCategory><cbc:Percent>18</cbc:Percent>
        <cac:TaxScheme><cbc:ID>1000</cbc:ID><cbc:Name>IGV</cbc:Name></cac:TaxScheme>
      </cac:TaxCategory>
    </cac:TaxSubtotal>
  </cac:TaxTotal>
  <cac:LegalMonetaryTotal>
    <cbc:LineExtensionAmount currencyID="PEN">200.00</cbc:LineExtensionAmount>
    <cbc:TaxInclusiveAmount currencyID="PEN">236.00</cbc:TaxInclusiveAmount>
    <cbc:PayableAmount currencyID="PEN">236.00</cbc:PayableAmount>
  </cac:LegalMonetaryTotal>
  <cac:InvoiceLine>
    <cbc:ID>1</cbc:ID>
    <cbc:InvoicedQuantity unitCode="NIU">2</cbc:InvoicedQuantity>
    <cbc:LineExtensionAmount currencyID="PEN">200.00</cbc:LineExtensionAmount>
    <cac:Price><cbc:PriceAmount currencyID="PEN">100.00</cbc:PriceAmount></cac:Price>
    <cac:Item><cbc:Description>PROD 1</cbc:Description></cac:Item>
  </cac:InvoiceLine>
  <!-- ds:Signature la inserta SignedXml con el certificado del emisor -->
</Invoice>
```
Nodos clave: `InvoiceTypeCode` (tipo+operación), `TaxTotal/TaxSubtotal` (IGV 1000,
Percent 18), `LegalMonetaryTotal` (LineExtension=valor venta, TaxInclusive/Payable=
total), `InvoiceLine` (Price=valor unitario sin IGV).

### H. Firma y envío
El XML sin firmar pasa por post-procesos y luego `SignedXml::setCertificate(PEM del
tenant)` inserta `ds:Signature`. Se envía por SOAP (`See::sendXml`).

### I. Respuesta (envelope)
```json
// Éxito
{ "estado": "exito", "mensaje": "Factura registrada",
  "datos": { "id": 123, "numero": "F001-00000001", "sunat_status": "pendiente", "mto_imp_venta": 236.00 } }
// Error de validación (422)
{ "estado": "error", "mensaje": "Los datos no son válidos",
  "errores": { "cliente.num_doc": ["El RUC debe tener 11 dígitos"] } }
```

### J. PDF
Tras la aceptación (o bajo demanda en `GET /{id}/pdf`), `PdfGeneratorService` genera
el PDF (A4/A5/ticket) con `DocumentDataMapper` + plantillas Blade (emisor, receptor,
ítems, totales, leyenda, QR). Requiere GD con WebP para logos `.webp`.

<a id="boleta"></a>
## Boleta (03)
Igual que la factura pero `serie: "B001"`, `cliente.tipo_doc: "1"` (DNI, 8 díg) o
`"0"` (sin documento, solo si total ≤ S/700). Puede llevar `"solo_registro": true`
para informarse luego por Resumen Diario.

<a id="nota-de-credito"></a>
## Nota de crédito (07)
```json
{
  "serie": "FC01",
  "fecha_emision": "2026-03-05",
  "doc_afectado_tipo": "01",
  "doc_afectado_serie": "F001",
  "doc_afectado_correlativo": "1",
  "cod_motivo": "01",
  "des_motivo": "ANULACION DE LA OPERACION",
  "tipo_moneda": "PEN",
  "cliente": { "tipo_doc": "6", "num_doc": "20000000002", "razon_social": "EMPRESA X SAC" },
  "items": [ { "descripcion": "PROD 1", "unidad": "NIU", "cantidad": 2, "precio_unitario": 118, "tip_afe_igv": "10" } ]
}
```
`cod_motivo` del **Catálogo 09**. El importe no puede exceder el documento afectado
(tolerancia 0 para factura, +1.0 para boleta). XML: `CreditNote` con
`BillingReference` al documento afectado + `DiscrepancyResponse` (motivo).

<a id="nota-de-debito"></a>
## Nota de débito (08)
Igual estructura, pero `cod_motivo` del **Catálogo 10**. Para **motivo 13
(penalidades)** se omiten los `doc_afectado_*` y el XML no lleva `BillingReference`;
la operación debe ser **inafecta** (IGV = 0).

> Más ejemplos de cálculo (ISC, ICBPER, gratuitas, descuentos) en
> [documento 07](07-calculos-matematicos-tributarios.md#5-ejemplos-numéricos-soles-verificados-contra-el-código).
