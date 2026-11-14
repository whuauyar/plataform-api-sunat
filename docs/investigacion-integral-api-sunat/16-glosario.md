# 16 — Glosario técnico

## SUNAT / tributario
- **CPE:** Comprobante de Pago Electrónico.
- **CDR:** Constancia de Recepción. Respuesta de SUNAT (ZIP con XML) que acepta,
  observa o rechaza un comprobante. En esta API se interpreta **por código**.
- **Ticket:** identificador que devuelve SUNAT en los envíos asíncronos (resumen,
  baja, reversión, GRE); se consulta después para obtener el resultado.
- **IGV:** Impuesto General a las Ventas (18 % general). Incluye el **IPM** de forma
  combinada en la tasa.
- **IVAP:** Impuesto a la Venta de Arroz Pilado (4 %, `tip_afe_igv=17`).
- **ISC:** Impuesto Selectivo al Consumo.
- **ICBPER:** Impuesto al Consumo de las Bolsas Plásticas (monto fijo por bolsa).
- **Detracción (SPOT):** depósito de parte del pago en una cuenta del Banco de la
  Nación (Catálogo 54). El monto lo envía el cliente.
- **Percepción / Retención:** regímenes de pago adelantado del IGV (comprobantes 40
  y 20).
- **RUS / RER / MYPE / General:** regímenes tributarios. RUS tributa 0 % de IGV por
  operación.
- **Ley 31556:** tasa especial de IGV para restaurantes/hoteles MYPE (10 %→18 % por
  año); en esta API se aplica vía `igv_rate_override`.
- **tip_afe_igv:** tipo de afectación del IGV del ítem (Catálogo 07): 10 gravado,
  17 IVAP, 20 exonerado, 30 inafecto, 40 exportación, 11-16/21/31-36 gratuitas.
- **UIT:** Unidad Impositiva Tributaria (referencia de topes, p. ej. 75 UIT en
  liquidación).

## Tipos de comprobante (Catálogo 01)
01 Factura · 03 Boleta · 04 Liquidación de compra · 07 Nota de crédito · 08 Nota de
débito · 09 Guía de remisión remitente · 20 Retención · 31 Guía de remisión
transportista · 40 Percepción · **RC** Resumen diario · **RA** Comunicación de baja
· **RR** Reversión.

## UBL / técnico
- **UBL 2.1:** Universal Business Language, el formato XML estándar de los
  comprobantes.
- **Greenter:** librería PHP que construye y firma el XML UBL y lo envía a SUNAT
  (`greenter/lite`). Aquí se usa con **Builders** propios + post-procesos.
- **SignedXml:** componente de Greenter que firma digitalmente el XML con el
  certificado del emisor.
- **See / Api:** clientes de Greenter para el WS SOAP (See) y la API REST GRE (Api).
- **GRE:** Guía de Remisión Electrónica; usa API REST con **OAuth2** (token).
- **PrepaidAmount:** nodo UBL donde se declara el **anticipo** (no como descuento).

## Arquitectura de la API
- **Tenant:** empresa (cliente de la API). Multi-tenant por credenciales.
- **ResolveTenant:** middleware que autentica por `X-Api-Key` + `X-Api-Secret`.
- **Action:** clase en `app/Actions/Documents/` que orquesta la emisión dentro de
  una transacción.
- **Job (`Send*ToSunat`):** tarea de cola que firma y envía el comprobante.
- **Horizon:** gestor de colas Redis (procesa la cola `sunat` y otras).
- **CheckLicense / check.limit:** middlewares de licencia y de cupo del plan.
- **Idempotency-Key:** cabecera para deduplicar escrituras.
- **`sunat_status`:** estado del comprobante (pendiente/enviado/aceptado/rechazado/
  anulacion_en_proceso/anulado).
- **SUNAT_TIMEOUT:** marca de un envío que no obtuvo CDR por un fallo temporal (no es
  rechazo).
