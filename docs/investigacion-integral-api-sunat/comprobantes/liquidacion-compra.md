# Liquidación de compra electrónica (Tipo 04)

**Qué es:** **autofactura** (`SelfBilledInvoice`) que emite el **comprador** cuando
adquiere a un vendedor **persona natural sin RUC** (productores agropecuarios,
recicladores, etc.). El comprador declara y retiene. Envío **síncrono por CDR**
(como factura, vía `billService`).

## Endpoints — `LiquidacionCompraController`
`routes/api.php:183-195`: `POST`, index, `reporte-retenciones`, show, `xml|cdr|pdf`,
`enviar|reenviar`, **`reversion`**, pagos store/index/destroy.

## Validación (`StoreLiquidacionCompraRequest`)
- Serie `^L[A-Z0-9]{3}$`; moneda **solo PEN**.
- **Vendedor:** tipo `1` (DNI, 8 díg) o `4` (CE) — `in:1,4`.
- `cod_producto_sunat` 8 díg obligatorio; afectación restringida a `in:10,20,30`.
- **NRUS no puede emitir** (SUNAT 2463).
- Plazo: hoy o día siguiente; **tope 75 UIT/vendedor/año**.
- Tipo de operación propio `in:0501,0502,0503` (0502 bloqueado; 0503 oro exige
  datos de concesión Cat 55).

## Cálculo — `LiquidacionCompraCalculator`
IGV por ítem gravado + **retención de renta** según tasa: oro 4 %, agro exento,
≤ S/700 sin retención, general 1.5 % (`tasaRenta:24-41`). **Neto = subtotal − IGV
− renta** (`:116`). (El cálculo de IGV reutiliza `DocumentCalculationService`.)

## XML — `LiquidacionCompraXmlBuilder`
UBL **SelfBilledInvoice-2.1 construido a mano** (Greenter no lo modela): el
comprador (`AccountingCustomerParty`) va **antes** del vendedor; la renta se
declara como tributo `3000/IR` **informativo** que no suma al `TaxAmount`; oro con
Cat 55; placeholder `#GREENTER-SIGN`. Envío por `GreenterService::sendRawBill`
(firma + clasifica por código). Un CDR aceptado graba `cdr_fecha` (base del plazo
de reversión). **Genera PDF.**

## Reversión de liquidación
`LiquidacionCompraController::reversion` valida CDR aceptado, plazo (config
`reversion_dias`=5) y ausencia de pagos; crea un `VoidedDocument` identificador
`RR-` con detalle tipo '04' y despacha `SendReversionToSunat`.

> **Nota (R4, revisado):** la liquidación de compra **se anula por reversión**
> según SUNAT (no por comunicación de baja), así que el mecanismo del código es
> correcto. Queda por **validar en beta** que SUNAT acepta el ResumenReversión con
> tipoDoc `04`, y confirmar el **plazo** (código 5 días vs normativa 7). Ver
> [documento 14](../14-riesgos.md) R4.
