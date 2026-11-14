# 12 — Pruebas y validación (Fase 9)

Verificaciones **controladas**, sin emitir comprobantes reales ni tocar producción,
sin modificar código ni datos.

## 1. Verificación del motor de cálculo (ejecución real del código)

Se ejecutó `DocumentCalculationService` (el código real, booteando la app) sobre
los ejemplos del [documento 07](07-calculos-matematicos-tributarios.md). **Los
resultados coinciden exactamente con lo documentado:**

| Caso | Entrada | `mto_imp_venta` | Detalle verificado |
|---|---|---|---|
| A — gravada | 1 ítem, cant 2, precio 118, afe 10 | **236.00** | valorUnit 100, valorVenta 200, IGV 36 |
| A — leyenda | total 236 | — | `DOSCIENTOS TREINTA Y SEIS CON 00/100 SOLES` |
| C — con ISC 10 % | 1 ítem, cant 1, precio 118, ISC 10 | **118.00** | valorUnit 90.9090909091, valorVenta 90.91, ISC 9.09, baseIGV 100, IGV 18 |
| F — exonerado | 1 ítem, cant 3, precio 50, afe 20 | **150.00** | exoneradas 150, IGV 0 |

> Esto confirma la **precisión decimal** (valor unitario a 10 decimales, montos a 2),
> la estrategia de IGV `totalConIgv − valorVenta`, el recálculo del IGV con ISC
> (base = valorVenta + ISC) y la exclusión del IGV en exonerados.

## 2. Suite de pruebas automatizadas del proyecto

La suite del repositorio está **en verde**: **342 passed, 0 failed, 6 skipped**
(los 6 *skipped* son generación de certificados que requiere `openssl.cnf`, corren
en Linux/CI). Cubre, entre otros: emisión y estados SUNAT, idempotencia de jobs,
conciliación de `SUNAT_TIMEOUT`, correlativo único, plazos de envío, reportes
(golden-master), SIRE y comandos de reparación.

## 3. Coherencia Postman ↔ FormRequests

Los ejemplos de la colección Postman (317 requests) corresponden a las rutas reales
(cobertura ≈ 197/198, ver [documento 04](04-matriz-postman-vs-codigo.md)). Las
estructuras JSON de los ejemplos son coherentes con los campos obligatorios/
opcionales de los `Store*Request` (ver [comprobantes](comprobantes/)).

## 4. Qué NO se hizo (por política de la auditoría)
- No se emitieron comprobantes a SUNAT (ni beta ni producción) durante esta
  investigación documental.
- No se modificó código, configuración ni datos.
- No se ejecutaron operaciones destructivas.

## 5. Observaciones de las pruebas
- La verificación del cálculo valida el núcleo tributario con el **código real**, no
  con una reimplementación.
- Para una validación extremo-a-extremo contra **SUNAT beta** (firma + envío + CDR),
  se recomienda usar la colección Postman apuntando a un tenant de beta con
  certificado de prueba — fuera del alcance de esta auditoría de solo lectura.
