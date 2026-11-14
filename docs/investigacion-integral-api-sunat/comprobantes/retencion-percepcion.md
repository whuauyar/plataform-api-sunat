# Comprobante de Retención (20) y de Percepción (40)

Ambos son comprobantes de **regímenes de pago adelantado del IGV**. Envío SOAP
**síncrono por CDR** (sin ticket), por el endpoint de retención/“otros CPE”.

## Retención (20) — `RetentionController`
**Qué es:** el **agente de retención** retiene un % del pago a su proveedor y se lo
entrega a SUNAT.

- **Endpoints** (`routes/api.php:166-172`): `POST`, index, show, **`pdf`**, `xml`,
  `cdr`, `POST enviar`.
- **Validación** (`StoreRetentionRequest`): serie `^R[A-Z0-9]{3}$`, `regimen`
  (Cat 23: 01=3 %, 02=6 %), documentos relacionados `in:01,03,12` **con pagos**,
  tasa 0-100.
- **Flujo:** `CreateRetentionAction` → `SendRetentionToSunat` → `RetentionBuilder`
  → `send` → marca `aceptado`/`rechazado` directo (sin ticket). Guarda XML/CDR en
  `{ruc}/retentions/`. **Genera PDF.**

## Percepción (40) — `PerceptionController`
**Qué es:** el **agente de percepción** cobra un % adicional al cliente en la venta
y lo entrega a SUNAT.

- **Endpoints** (`routes/api.php:175-180`): `POST`, index, show, `xml`, `cdr`,
  `POST enviar`. **Sin ruta `pdf`** (asimetría con retención — ver
  [documento 13](../13-inconsistencias.md)).
- **Validación** (`StorePerceptionRequest`): serie `^P[A-Z0-9]{3}$`, `regimen`
  (Cat 22: 01=2 %, 02=1 %, 03=0.5 %), con cobros.
- **Flujo:** `SendPerceptionToSunat` → `PerceptionBuilder` → síncrono por CDR.

## Retención vs Percepción

| Aspecto | Retención (20) | Percepción (40) |
|---|---|---|
| Quién paga a SUNAT | se **retiene** al proveedor | se **cobra de más** al cliente | 
| Catálogo de régimen | 23 (3 %/6 %) | 22 (2 %/1 %/0.5 %) |
| Serie | `R###` | `P###` |
| Builder | `RetentionBuilder` | `PerceptionBuilder` |
| PDF | sí | **no** |

> La percepción también puede ir **embebida en la factura/boleta** (campo
> `percepcion` con leyenda 2000), además de como comprobante 40 independiente.
