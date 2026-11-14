# Factura electrónica (Tipo 01)

**Qué es:** comprobante de pago que sustenta operaciones entre empresas (receptor
con **RUC**) y da derecho a crédito fiscal/costo/gasto. Se envía a SUNAT por WS
SOAP y la aceptación es **síncrona por CDR** (dentro del Job de cola).

## Endpoints — `InvoiceController`
`routes/api.php:70-82`

| Método | Ruta | Acción |
|---|---|---|
| POST | `/v1/facturas` | `store` (emite; `enviar_automatico` default true) |
| POST | `/v1/facturas/masivo` | `masivo` (máx 100) |
| GET | `/v1/facturas` · `/{id}` | listado · detalle |
| PUT | `/v1/facturas/{id}` | actualizar (si aún no aceptada) |
| GET | `/{id}/xml` · `/cdr` · `/pdf` | descargas |
| POST | `/{id}/enviar` · `/reenviar` | envío manual |
| POST/GET/DELETE | `/{id}/pagos[...]` | pagos (`PaymentController`) |

Los `POST` emisores llevan middleware extra `license, check.limit:sunat`.

## Campos de entrada (`StoreInvoiceRequest`)

**Obligatorios:** `serie` (regex `^F[A-Z0-9]{3}$`), `fecha_emision` (date),
`cliente.tipo_doc` (Cat 06), `cliente.num_doc` (max 15), `cliente.razon_social`
(max 1500), `items[]` (min 1); por ítem: `descripcion`, `unidad` (Cat 03),
`cantidad` (>0), `precio_unitario` (≥0).

**Opcionales clave:** `correlativo` (autoincrementa si se omite), `cod_local`,
`fecha_vencimiento`, `tipo_operacion` (Cat 51, default `0101`), `tipo_moneda`
(PEN/USD/EUR), `forma_pago` (Contado/Credito/...), montos (se recalculan, ver
[doc 07](../07-calculos-matematicos-tributarios.md)), `cuotas`, `guias`,
`detraccion` (Cat 54/59), `percepcion` (Cat 22), `anticipos` (Cat 12),
`descuentos_globales` (Cat 53), `leyendas` (Cat 52), `pagos`,
`contrato_colaboracion`.

## Reglas de negocio específicas (`withValidator:162-224`)

1. **NRUS no puede emitir facturas** (régimen restringido → error).
2. **Receptor con RUC obligatorio:** `cliente.tipo_doc` debe ser `6`; RUC de 11
   dígitos con prefijo `10/15/17/20` (`:206-222`). (Para DNI/otros → boleta.)
3. **Exportación** (`tipo_operacion` empieza en `02`): receptor **no domiciliado**,
   NO admite RUC (6); sí `0/4/7/A` (`:191-203`).
4. **`forma_pago=Credito` ⇒ `cuotas` obligatorias** (`:180-182`).
5. `validarSerieRegistrada('01')`, `validarCorrelativoManual`,
   `validarCodProductoObligatorio` (padrón `ind_padron 12`), `validarTipoOperacion0112`
   (gastos deducibles persona natural exige cod_producto 84121901/80131501).

## Procesamiento interno

`CreateInvoiceAction::execute` (`app/Actions/Documents/CreateInvoiceAction.php:27-123`):
1. `DB::transaction`.
2. `Serie::lockForUpdate` tipo '01' + `resolveCorrelativo` (correlativo seguro).
3. `ClientResolverService::resolve` (busca/crea cliente).
4. `calculateItems` + `calculateTotals` (doc 07).
5. Genera `leyenda` (monto en letras) si falta.
6. `Invoice::create` (+ items) con `sunat_status='pendiente'`, `tipo_operacion`
   default `0101`.
7. `event(DocumentCreated)`, `PlanService::incrementUsage`.
8. Si `enviar_automatico` → `SendDocumentToSunat::dispatch(Invoice::class, id)` +
   `sunat_status='enviado'`.
9. Registra `pagos` si vienen.

## Construcción del XML — `InvoiceBuilder`
`setUblVersion('2.1')`, `setTipoDoc('01')`, forma de pago Contado/Credito, montos
solo `>0` (evita nodos de tributo vacíos), ítems vía `buildItem` (afectación Cat
07, ISC, ICBPER, descuentos de línea). Cabecera: **detracción** (leyenda 2006),
**percepción** (leyenda 2000, mapea Cat 22→53), **anticipos** (PrepaidPayment),
descuentos globales, guías. Es el **único builder** con soporte de forma de
pago/cuotas/detracción/percepción/anticipos/exportación.

## Firma y envío
Job `SendDocumentToSunat` → `GreenterService::send`: `buildInvoice` → post-procesos
→ `SignedXml` (certificado PEM del tenant) → SOAP `See::sendXml`. Clasificación
por **código de CDR** (doc 01/11). Éxito (0 / ≥4000) → `aceptado` + hash_cpe + XML
+ CDR + PDF async + webhook. Rechazo (1000-3999) → `rechazado`. Timeout/red →
reintenta con backoff; agotado → `recoverFromCdr`; si no, `pendiente/SUNAT_TIMEOUT`.

## Estados
`pendiente` → `enviado` → `aceptado` | `rechazado` (| `SUNAT_TIMEOUT`→`pendiente`).
Anulación posterior por **comunicación de baja** (RA), no por borrado.

## Modelo (`Invoice.php`)
`getTipoDocumento()='01'`; casts array: cuotas/detraccion/percepcion/anticipos/
descuentos_globales/guias/extras/contrato_colaboracion; decimales `mto_*`.

## Ejemplo
JSON + XML + cálculo paso a paso en [documento 08](../08-ejemplos-json-xml.md#factura).
