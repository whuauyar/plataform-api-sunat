# 10 — Catálogos y códigos SUNAT

Se distingue **NORMATIVA SUNAT** (del Formato 1.3.4) de **DECISIÓN de esta API**
(qué subconjunto valida realmente).

## Arquitectura de validación

- **Fuente central:** `config/sunat_catalogs.php` (Formato SUNAT 1.3.4). Cada
  catálogo es `codigo => descripción` o `codigo => [metadata]`.
- **Regla genérica:** `app/Rules/SunatCatalog.php`. `new SunatCatalog('NN')` carga
  `config("sunat_catalogs.NN")` y valida `array_key_exists` (`:26,39`). Helpers:
  `description()`, `codes()`, `rate()` (cat 22/23/54), `icbperFactor()`.
- **Código de producto (Cat 25 UNSPSC):** `app/Rules/SunatProductCode.php` — 8
  dígitos, no termina en `0000`, existe en tabla `unspsc_codes` (`:29,35,41`).
- **No existen** `app/Enums/**` ni `app/Constants/**`: todo vive en config + reglas
  + migraciones. Algunos catálogos se validan con `in:` embebido en el FormRequest
  (decisión de implementación, no fuente central).

## Catálogos definidos en `config/sunat_catalogs.php`

| Cat | Nombre | Valores soportados | Validación | Líneas config |
|---|---|---|---|---|
| **01** | Tipo de comprobante | 01,03,07,08,09,12,20,31,40 | `SunatCatalog('01')` en `guias.*.tipo_doc` | `:16-26` |
| **05** | Tributos | 1000 IGV, 1016 IVAP, 2000 ISC, 7152 ICBPER, 9995 EXP, 9996 GRA, 9997 EXO, 9998 INA, 9999 OTROS | interno (mapeado desde Cat 07) | `:29-39` |
| **06** | Tipo doc. identidad | 0,1,4,6,7,A,B,C,D,E | `SunatCatalog('06')` en cliente/proveedor/destinatario | `:42-53` |
| **07** | Afectación IGV | 10 gravado, 11-17 gratuitas gravadas/IVAP, 20 exonerado, 21 exon. gratuita, 30 inafecto, 31-37 inafectas, 40 exportación | `SunatCatalog('07')` en `items.*.tip_afe_igv` | `:56-76` |
| **08** | Sistema cálculo ISC | — | `SunatCatalog('08')` en `items.*.tip_sis_isc` | `:79-83` |
| **09** | Motivo NC | 01-09 | `SunatCatalog('09')` en `cod_motivo` | `:86-96` |
| **10** | Motivo ND | 01-13 (**13 Penalidades**, RS 048-2026) | `SunatCatalog('10')` en `cod_motivo` | `:99-112` |
| **11** | Tipo valor venta resumen | — | interno | `:115-121` |
| **12** | Docs relacionados tributarios | — | `SunatCatalog('12')` en `anticipos.*.tipo_doc` | `:124-136` |
| **16** | Tipo precio unitario | — | interno | `:139-143` |
| **19** | Estado ítem resumen | — | interno (resumen/baja) | `:146-150` |
| **22** | Percepciones | 01 (2%), 02 (1%), 03 (0.5%) | `SunatCatalog('22')` en `percepcion.cod_regimen` | `:153-157` |
| **23** | Retenciones | 01 (3%), 02 (6%) | `SunatCatalog('23')` en `regimen` | `:160-163` |
| **51** | Tipo de operación | 24 códigos (0101/0112/0113, 02xx export, 1001-1004 detracción, 2001 percepción) | `SunatCatalog('51')` en factura/boleta | `:166-188` |
| **52** | Leyendas | 1000 (letras), 1002, 2000, 2001-2011 (2006 detracción, 2007 IVAP) | `SunatCatalog('52')` en `leyendas.*.code` | `:191-206` |
| **53** | Cargos/descuentos | — | `SunatCatalog('53')` en `descuentos.*.cod_tipo` | `:209-226` |
| **54** | Detracciones | ~37 códigos con `desc` y `tasa` (027 transporte 4%) | `SunatCatalog('54')` en `detraccion.cod_bien` | `:229-266` |
| **59** | Medios de pago (detracción) | — | `SunatCatalog('59')` en `detraccion.cod_medio_pago` | `:269-292` |
| ICBPER | Factor por año | 2019-2026 | `SunatCatalog::icbperFactor()` | `:295-304` |

### Motivos de nota de crédito (Catálogo 09) — `:86-96`
`01` Anulación de la operación · `02` Anulación por error en el RUC · `03`
Corrección por error en la descripción · `04` Descuento global · `05` Descuento por
ítem · `06` Devolución total/parcial · `07` Bonificación · `08` Disminución en el
valor · `09` Otros.

### Motivos de nota de débito (Catálogo 10) — `:99-112`
`01` Intereses por mora · `02` Aumento en el valor · `03` Otros conceptos · `04-08`
Ajustes (exportación/moneda/cantidad/descuentos/cargos) · `09` Otros · `11` Ajustes
exportación · `12` Ajustes IVAP · **`13` Penalidades** (RS 048-2026, solo
operaciones inafectas).

## Catálogos validados inline (decisión de la API, NO en config)

| Cat | Uso | Validación | Evidencia |
|---|---|---|---|
| **02** Moneda | PEN/USD/EUR (liquidación solo PEN) | `in:PEN,USD,EUR` repetido en cada request | `StoreInvoiceRequest.php:38` |
| **03** Unidades de medida | ~90 códigos (NIU, ZZ, KGM…) | `in:` embebido, **duplicado en 4 requests** | `StoreInvoiceRequest.php:70` |
| **20** Motivo de traslado | 01-19 subset | `in:01,02,...,19` | `StoreDispatchGuideRequest.php:93` |
| **18** Modalidad de traslado | 01,02 | `in:01,02` | `:94` |
| **55** Datos oro (liquidación) | por presencia de campos | — | `StoreLiquidacionCompraRequest.php:131-142` |
| **60** Tipo de domicilio | 01-05 | constante local `CAT60` | `StoreLiquidacionCompraRequest.php:26` |

## Código de establecimiento (`cod_local` / anexo)
- Columna `string(4)` default `'0000'` en todas las tablas de documentos.
- Tabla `sucursales` con unique `(tenant_id, cod_local)`.
- **Validación solo de formato** (`nullable|string|size:4`); **no se valida contra
  la tabla `sucursales`** (hallazgo, ver [documento 13](13-inconsistencias.md)).

## Estados internos (`sunat_status`) — NO es catálogo SUNAT
Definición autoritativa (CHECK PostgreSQL): `pendiente, enviado, aceptado,
rechazado, anulado, anulacion_en_proceso`
(`2026_05_14_000001_add_anulacion_en_proceso_to_sunat_status.php:15`). Summaries/
voided usan enum más corto; purchase_settlements `string(30)` sin CHECK. **No hay
enum PHP central**: los estados son strings literales repartidos por jobs/actions.

## Endpoints de catálogos expuestos

- **Producto SUNAT (UNSPSC v14, Cat 25):** `CatalogoProductoController` — búsqueda,
  drill-down Segmento→Familia→Clase→Producto, y `{codigo}` (8 díg). Datos en tablas
  `unspsc_codes`/`unspsc_taxonomy` (seeder desde `database/data/unspsc_v14.csv`, ~52k filas).
- **Ubigeo (INEI/SUNAT):** `UbigeoController` — regiones (2d) → provincias (4d) →
  distritos (6d = ubigeo). Datos en tablas `ubigeo_*` (seeder desde `public/data_ubi.txt`).

> No hay endpoint que exponga los catálogos de `config/sunat_catalogs.php` al
> cliente: solo se usan server-side para validar. Los únicos consultables por API
> son producto-SUNAT y ubigeo.

## Brechas (resumen)
1. Unidades (Cat 03) duplicadas en 4 requests sin fuente única.
2. `cod_local` solo validado por formato, no contra `sucursales`.
3. `sunat_status` sin enum/constante central (solo CHECK de PostgreSQL).
4. Cat 01 de config no incluye `04` (liquidación) aunque la API lo emite por otra vía.
5. Monedas limitadas a PEN/USD/EUR (PEN-only en liquidación), por diseño.
6. Catálogos 20/18/55/60 inline en FormRequests en vez de en config.
7. Cat 54 con tasas `null` pendientes para códigos vigentes 01/09/2026 (no afecta
   el cálculo: el monto de detracción lo envía el cliente).
