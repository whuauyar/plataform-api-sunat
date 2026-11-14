# 13 — Inconsistencias encontradas

Hallazgos de la comparación Postman↔código y del trazado del código. Se separan
por severidad. Los **riesgos de seguridad/integridad** se amplían en
[documento 14](14-riesgos.md).

## Postman ↔ código
1. **Verificador de cobertura roto:** `tools/check-postman-coverage.php:5` apunta a
   un archivo con nombre obsoleto (`API SUNAT PRO V2.1…`) en vez del real
   (`API FACTURACION SUNAT PERU…`). Falla con error fatal.
2. **`GET /v1/sunat/estado`** (`api.php:49`) es la única ruta v1 sin request en
   Postman.
3. **Alias `/api/sunat/*`** (14 rutas) y `bridge/*` no están en Postman (por diseño).
4. **Cotización estado:** API/Postman usan `PUT`; el panel web usa `PATCH`
   (`web.php:159`). Consistente API↔Postman, difiere del web.

## Código (funcionales / de mantenimiento)

5. **Clobber de estado con cola síncrona (potencial):** en los `Create*Action` y
   `enviar()` se hace `dispatch(...)` e inmediatamente `->update(['sunat_status'=>'enviado'])`.
   Con `QUEUE_CONNECTION=sync` el job corre inline (deja `aceptado`/`rechazado`) y el
   `update('enviado')` posterior lo **sobreescribe**. En producción (Redis/Horizon,
   async) no ocurre; es un riesgo en local/tests con `sync`.
   `CreateInvoiceAction:113-114`.

6. **Alias `/sunat/*` sin gates:** `POST sunat/facturas`/`boletas`
   (`api.php:415,421`) corren **sin `idempotency`, `license` ni `check.limit`** →
   cupo de plan saltable y duplicados posibles. Ver [documento 14](14-riesgos.md).

7. **Reversión de liquidación (04) — REVISADO:** el mecanismo (reversión) es el
   **correcto** según la normativa SUNAT (la liquidación se anula por reversión, no
   por baja). Queda por **validar en beta** que SUNAT acepta el ResumenReversión con
   `VoidedDetail` tipoDoc `04`, y confirmar el **plazo** (código usa 5 días; normativa
   cita 7). No es un “canal incorrecto”. Ver [documento 14](14-riesgos.md) R4.

8. **Dos caminos de reversión con robustez distinta:** el endpoint
   `POST /reversiones` usa `whereJsonContains` (no fiable en PostgreSQL por el
   operador `@>`), mientras `VoidedService::crearReversion` (filtrado en PHP,
   robusto) no se usa desde la API. Mismo patrón en
   `SummaryController` (anti-duplicado de anulación con `whereJsonContains`).

9. **`StoreVoidedRequest` acepta `tipo in:01,03,07,08,20,40`** pero el servicio solo
   mapea 01/03/07/08; enviar 20/40 a `/anulaciones` pasa la validación y luego falla
   con “tipo no soportado”. La validación es engañosa (debería usarse `/reversiones`).

10. **Percepción sin endpoint PDF:** `RetentionController` tiene `pdf` y ruta;
    `PerceptionController` no (asimetría).

11. **`index` de NC/ND con filtrado manual** en vez del trait
    `AppliesDocumentFilters` que usan factura/boleta (inconsistencia de mantenimiento).

12. **Parámetro de formato PDF inconsistente:** `InvoiceController::pdf` acepta
    `formato` o `format`; boleta/NC/ND solo `format`.

13. **`ValidatesNotaCreditoMonto` referencia motivo '10'** como excepción, pero el
    Catálogo 09 (NC) no contiene '10' → guarda muerta/confusa.

14. **Builder de descuentos globales difiere** entre `InvoiceBuilder` (recalcula
    monto_base/factor) y `NoteBuilder` (los toma del input). Una NC con descuento
    global por porcentaje podría requerir enviar monto_base/factor explícitos.

15. **Boleta comparte `InvoiceBuilder`:** acepta nodos (detracción/percepción/
    anticipos/exportación) que SUNAT podría observar para tipo 03; el FormRequest no
    los bloquea.

16. **Marcado de estado optimista:** todos los `enviar/store` marcan `enviado` tras
    `dispatch` sin confirmar que haya worker; si no corre `queue:work/horizon`, el
    documento queda `enviado` sin ticket indefinidamente.

## Catálogos / validación
17. **Unidades (Cat 03)** duplicadas como `in:` en 4 requests (sin fuente única).
18. **`cod_local`** validado solo por formato (`size:4`), no contra `sucursales`.
19. **`sunat_status`** sin enum/constante central (solo CHECK de PostgreSQL; no
    cubre por igual summaries/voided/purchase_settlements).
20. **Cat 01** de config no incluye `04` (liquidación) aunque la API lo emite.
