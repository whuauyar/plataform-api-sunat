# 07 — Cálculos matemáticos y tributarios

Toda la aritmética tributaria de los comprobantes vive en **dos servicios**:

- `app/Services/DocumentCalculationService.php` — cálculo por ítem y totales.
- `app/Services/TaxRateService.php` — resolución de la tasa de IGV vigente.

Se invocan desde la capa **Action** de cada comprobante, por ejemplo
`app/Actions/Documents/CreateInvoiceAction.php:47-48`:

```php
$calculatedItems = $this->calculator->calculateItems($data['items'], $tenant, $fechaEmision);
$totals          = $this->calculator->calculateTotals($calculatedItems, $data, $tenant, $fechaEmision);
```

Lo usan factura, boleta, NC, ND, liquidación y cotización
(`grep DocumentCalculationService app/Actions/Documents/*`).

> **Hallazgo transversal clave (overridable):** tanto a nivel de ítem como de
> totales, el motor usa el patrón `(float) ($data['campo'] ?? <calculado>)`. Es
> decir, **si el cliente envía el campo ya calculado, la API respeta su valor y
> NO lo recalcula**. Esto da flexibilidad (un integrador puede mandar los
> importes exactos que exige SUNAT) pero también es un riesgo: un total erróneo
> enviado por el cliente se acepta tal cual. Ver [documento 14](14-riesgos.md).

---

## 1. Resolución de la tasa de IGV (`TaxRateService`)

### 1.1 Tasa por defecto para gravado estándar — `defaultIgvRate()`
`TaxRateService.php:64-81`. Precedencia:

1. `tenant->igv_rate_override` si está definido (`:66-68`).
2. Régimen **RUS** → **0 %** (`:72-75`): el contribuyente paga cuota fija, no IGV por operación.
3. RER / MYPE / General → **18 %** (`:80`).

> La **Ley 31556** (restaurantes/hoteles MYPE: 10 % en 2022-2025, 10.5 % en 2026,
> 15 % en 2027, 18 % en 2028+) está tabulada en `TaxRateService.php:45-54`
> (`LEY_31556`) pero **ya no se aplica automáticamente por régimen**: su tasa se
> declara vía `igv_rate_override` por empresa. El schedule solo sirve para
> sugerir el valor en el panel (`tasaLey31556()`, `:89-92`).

### 1.2 Tasa efectiva por ítem — `rateForItem()`
`TaxRateService.php:122-142`. Prioridad:

1. `item['porcentaje_igv']` explícito → máxima prioridad (`:124-126`).
2. `tip_afe_igv = '17'` (IVAP) → **4 %** (`:131-133`).
3. `tip_afe_igv = '10'` o gratuita **gravada** (`11-16`) → `defaultIgvRate()` (`:136-138`).
4. Resto (exonerado/inafecto/exportación/gratuita inafecta) → **0 %** (`:141`).

### 1.3 Afectación por defecto — `defaultTipAfeIgv()`
`TaxRateService.php:105-108`: **'30' (Inafecto)** si el tenant es RUS, **'10'
(Gravado)** en los demás casos.

> **Nota IPM:** para SUNAT la tasa combinada (IGV+IPM) se emite en un solo
> `<cbc:Percent>` bajo `TaxTypeCode=1000`. No se declara el IPM como tributo
> separado (`TaxRateService.php:23-25`).

---

## 2. Cálculo por ítem — `calculateItems()`
`DocumentCalculationService.php:16-186`. Para cada ítem:

### 2.1 Derivación del valor unitario (precio → base sin IGV)
El cliente normalmente envía **`precio_unitario` CON IGV**. El motor deriva el
**valor unitario sin IGV** según `tip_afe_igv` (`:36-53`):

| `tip_afe_igv` | Fórmula de `valorUnitario` | Línea |
|---|---|---|
| `10` gravado (con ISC, sin `mto_valor_unitario`) | `round(precio / ((1+isc/100)(1+igv/100)), 10)` | `:39` |
| `10` gravado (sin ISC) | `round(precio / (1+igv/100), 10)` | `:41` |
| `17` IVAP | `round(precio / (1+igv/100), 10)` | `:44` |
| `20`/`30`/`40` exon/inaf/export | `valorUnitario = precio_unitario` (sin IGV incluido) | `:45-46` |
| gratuitas (`11-16`, `21`, `31-36`) | `valorUnitario = 0` | `:47-50` |

> Precisión: el valor unitario se redondea a **10 decimales** (SUNAT lo permite);
> los importes monetarios, a **2 decimales**.

### 2.2 Descuentos por línea (`cod_tipo` 00/01)
`:59-93`. Se aplican sobre la base **sin IGV**:

- `montoBase = round(valorUnitario × cantidad, 2)` (`:60,68`).
- Monto del descuento: por `porcentaje` (`factor = round(%/100, 6)`), por `factor`
  directo, o por `monto` fijo (del que se deriva el factor) (`:70-79`).
- Se acumula `descuentoBase` (sin IGV) y `descuentoConIgv = round(totalConIgvBruto × factor, 2)` (`:81-82`).
- `cod_tipo` 00 y 01 se tratan igual a nivel de ítem (ambos **reducen la base**);
  la distinción “no afecta base” solo aplica a descuentos **globales** `cod 03` (`:65-67`).

### 2.3 Valor de venta e IGV por afectación
`:95-127`:

| Afectación | `valorVenta` | `igv` | Línea |
|---|---|---|---|
| `10` gravado | `round(valorUnitario×cant − descBase, 2)` | `round(totalConIgv − valorVenta, 2)` donde `totalConIgv = round(precio×cant,2) − descConIgv` | `:95-99` |
| `20` exonerado | `round(valorUnitario×cant − descBase, 2)` | `0` | `:100-103` |
| `30`/`40` inaf/export | igual que exonerado | `0` | `:104-107` |
| `17` IVAP | `round(valorUnitario×cant − descBase, 2)` | `round(totalConIgv − valorVenta, 2)` | `:108-111` |
| gratuita **gravada** (`11-16`) | `round(valorGratuito×cant, 2)` | `item['igv'] ?? round(valorVenta×%/100, 2)` | `:112-116` |
| gratuita **inafecta/exon** (`21`,`31-36`) | `round(valorGratuito×cant, 2)` | `0` | `:117-122` |

> El IGV gravado se calcula como **`totalConIgv − valorVenta`** (no como
> `valorVenta × 18 %`) para evitar el “centavo” de redondeo que SUNAT rechaza.

### 2.4 ISC e ICBPER
- **ISC** auto desde `porcentaje_isc`: `isc = round(valorVenta × %isc/100, 2)` (`:138-140`).
  Si hay ISC, la **base del IGV** pasa a `valorVenta + ISC` y el IGV se recalcula
  `round(baseIgv × %igv/100, 2)` (`:148-150`).
- **ICBPER** (bolsas plásticas) auto desde `factor_icbper`:
  `icbper = round(factor_icbper × cantidad, 2)` (`:143-145`). Es un monto fijo por
  unidad (S/ por bolsa), no un porcentaje.
- **Base imponible de gratuitas inafectas/exon** (`21`,`31-36`): se fuerza
  `baseIgv = valorVenta` para evitar errores SUNAT 3272/3224 (`:156-160`).

### 2.5 Salida por ítem
`:164-182`. Cada ítem calculado expone: `mto_valor_unitario`, `mto_valor_venta`,
`mto_base_igv`, `porcentaje_igv`, `igv`, `tip_afe_igv`, `isc`, `icbper`,
`total_impuestos` (`igv+isc+icbper`), `mto_precio_unitario`, `descuento`.

---

## 3. Cálculo de totales — `calculateTotals()`
`DocumentCalculationService.php:188-327`.

### 3.1 Acumulación por tipo de operación (`:205-231`)
Según `tip_afe_igv` de cada ítem se suma a: `gravadas`+`totalIgv` (`10`),
`baseIvap`+`totalIvap` (`17`), `exoneradas` (`20`), `inafectas` (`30`),
`exportacion` (`40`), `gratuitas`+`igvGratuitas` (`11-16`,`21`,`31-36`). ISC e
ICBPER se acumulan aparte.

### 3.2 Anticipos (`:233-238`)
Se declaran como **PrepaidAmount** en el XML, **no** como `AllowanceCharge`
tipo 04 (evita la cascada SUNAT 3277→3291→3294). `total_anticipos` = suma de
`anticipos[].monto`.

### 3.3 Descuentos globales (`:240-280`)
- `cod_tipo` 02 → afecta **gravadas** y **recalcula el IGV** a la tasa efectiva
  `igvRate = totalIgv/gravadas` (o la del régimen si no hay gravadas) (`:274-280`).
- `cod_tipo` 03 → “no afecta base”: reduce el total vía `sumDescuentosNoBase` (`:266-268`).
- La base del porcentaje depende del `cod_tipo` (`:248,255`).

### 3.4 Totales finales (`:282-326`)
Todos se fuerzan **no negativos** (`max(0, round(·,2))`, SUNAT rechaza negativos):

```
total_impuestos = totalIgv + totalIvap + totalIsc + totalIcbper
valor_venta     = gravadas + exoneradas + inafectas + exportacion + baseIvap
sub_total       = valor_venta + total_impuestos
mto_imp_venta   = max(0, sub_total − total_anticipos − sumDescuentosNoBase)
```

> **Las operaciones gratuitas NO entran en `valor_venta` ni en `mto_imp_venta`**
> (van en `mto_oper_gratuitas` y `mto_igv_gratuitas` por separado). Correcto:
> los bienes gratuitos no incrementan el importe a pagar.

### 3.5 Importe a cobrar / pendiente
`mto_imp_venta` es el total del comprobante. El **pendiente de cobro** y el
`monto_pagado` se gestionan aparte (módulo de pagos, columna `monto_pagado`),
no en este servicio.

---

## 4. Leyenda (monto en letras) — `generateLeyenda()`
`:329-399`. Convierte el total a texto: entero a palabras + `CON NN/100` +
`SOLES`/`DOLARES AMERICANOS`. Soporta millones, miles, especiales (10-15),
“DIECI/VEINTI”, “CIEN/CIENTO”. Ej.: `236.00 PEN` →
`DOSCIENTOS TREINTA Y SEIS CON 00/100 SOLES`.

---

## 5. Ejemplos numéricos (soles, verificados contra el código)

### Ejemplo A — Factura gravada simple
Entrada: 1 ítem, `cantidad=2`, `precio_unitario=118.00` (incluye IGV 18 %),
`tip_afe_igv=10`, régimen general.

| Paso | Cálculo | Resultado |
|---|---|---|
| Valor unitario | `118 / 1.18` | `100.0000000000` |
| Valor de venta | `100 × 2` | `200.00` |
| Total con IGV | `round(118×2, 2)` | `236.00` |
| IGV | `236.00 − 200.00` | `36.00` |
| **Totales** | gravadas 200, IGV 36, total impuestos 36 | **mto_imp_venta = 236.00** |
| Leyenda | — | `DOSCIENTOS TREINTA Y SEIS CON 00/100 SOLES` |

### Ejemplo B — Con descuento de línea 10 %
Entrada: igual que A + `descuentos:[{cod_tipo:'00', porcentaje:10}]`.

| Paso | Cálculo | Resultado |
|---|---|---|
| Base del descuento | `100 × 2` | `200.00` |
| Factor / monto desc. | `0.1` / `200×0.1` | `20.00` |
| Desc. con IGV | `round(236 × 0.1, 2)` | `23.60` |
| Valor de venta | `100×2 − 20` | `180.00` |
| Total con IGV | `236 − 23.60` | `212.40` |
| IGV | `212.40 − 180.00` | `32.40` |
| **mto_imp_venta** | — | **212.40** |

### Ejemplo C — Ítem con ISC 10 % (precio incluye ISC+IGV)
Entrada: 1 ítem, `cantidad=1`, `precio_unitario=118.00`, `porcentaje_isc=10`,
`tip_afe_igv=10`.

| Paso | Cálculo | Resultado |
|---|---|---|
| Valor unitario | `118 / (1.10 × 1.18)` | `90.9090909091` |
| Valor de venta | `round(90.909…, 2)` | `90.91` |
| ISC | `round(90.91 × 10/100, 2)` | `9.09` |
| Base IGV | `round(90.91 + 9.09, 2)` | `100.00` |
| IGV | `round(100 × 18/100, 2)` | `18.00` |
| **mto_imp_venta** | `90.91 + (18 + 9.09)` | **118.00** |

### Ejemplo D — Bolsa con ICBPER
Entrada: 1 ítem bolsa gravada, `cantidad=10`, `precio_unitario=1.18`,
`factor_icbper=0.50`, `tip_afe_igv=10`.

| Paso | Cálculo | Resultado |
|---|---|---|
| Valor de venta | `(1.18/1.18) × 10` | `10.00` |
| IGV | `round(11.80 − 10.00, 2)` | `1.80` |
| ICBPER | `round(0.50 × 10, 2)` | `5.00` |
| **mto_imp_venta** | `10 + 1.80 + 5.00` | **16.80** |

### Ejemplo E — Operación gratuita gravada (bonificación)
Entrada: 1 ítem, `cantidad=2`, `mto_valor_unitario=50.00`, `tip_afe_igv=11`.

| Paso | Cálculo | Resultado |
|---|---|---|
| Valor de venta (ref.) | `50 × 2` | `100.00` |
| IGV gratuitas | `round(100 × 18/100, 2)` | `18.00` |
| **mto_oper_gratuitas / mto_igv_gratuitas** | — | **100.00 / 18.00** |
| **mto_imp_venta** | (las gratuitas no suman al total) | **0.00** |

### Ejemplo F — Ítem exonerado
Entrada: 1 ítem, `cantidad=3`, `precio_unitario=50.00`, `tip_afe_igv=20`.
→ `valorUnitario = 50` (no se le quita IGV), `valorVenta = 150.00`, `igv = 0`,
`mto_oper_exoneradas = 150.00`, **`mto_imp_venta = 150.00`**.

---

## 6. Redondeo y precisión (resumen)

| Magnitud | Decimales | Evidencia |
|---|---|---|
| Valor unitario | 10 | `:39,41,44` |
| Factor de descuento | 6 | `:71,78,256` |
| Importes monetarios (venta, IGV, ISC, ICBPER, totales) | 2 | en todo el servicio |
| Estrategia IGV gravado | diferencia `totalConIgv − valorVenta` (evita centavo) | `:98-99` |

---

## 7. Observaciones de auditoría (cálculos)

1. **Totales overridables** (`:308-326`, nivel ítem `:116,126,130,132,152-153`):
   si el cliente envía `mto_igv`, `mto_imp_venta`, `igv` por ítem, etc., se usan
   sin recálculo ni verificación. Flexible pero sin “red de seguridad” servidor.
2. **Gratuitas**: correctamente excluidas de `valor_venta`/`mto_imp_venta` y con
   base forzada para evitar 3272/3224.
3. **Anticipos** como PrepaidAmount (decisión deliberada documentada en código).
4. **IPM combinado** en el IGV (no tributo separado) — correcto para SUNAT.
5. **RUS** → 0 % y afectación por defecto `30`.
6. El motor **no valida** que la suma de afectaciones declaradas por el cliente
   coincida con la recalculada cuando el cliente las envía (ver punto 1).

> Ejemplos adicionales por tipo de comprobante en [documento 08](08-ejemplos-json-xml.md).
