# 15 — Recomendaciones de mejora (priorizadas)

> Priorización: **P1** corregir pronto · **P2** importante · **P3** mantenibilidad.
> Cada ítem referencia el hallazgo de [documento 13](13-inconsistencias.md) /
> [14](14-riesgos.md).

## P1 — Correcciones de integridad/seguridad

1. **Arreglar la recuperación de credenciales (R1).** Guardar el `api_secret`
   **crudo** (lo cifra el cast) igual que `regenerarCredenciales`, en vez de su
   `sha256`; o bien cambiar `ResolveTenant` para comparar hashes de forma
   consistente. Hoy el secreto recuperado nunca autentica.
2. **Cerrar el alias `/sunat/*` (R2).** Añadir `idempotency` y `check.limit:sunat`
   (y `license`) al grupo `api.php:407`, o deprecar el alias. Hoy permite saltar el
   cupo y duplicar comprobantes.
3. **Verificar los totales cuando el cliente los envía (R3).** ✅ **Implementado** como
   opt-in: `config('facturacion.verificar_totales')` (default false). Al activarla,
   un total de cabecera que no cuadra con el recalculado (más de `tolerancia_totales`)
   se rechaza con 422. Pendiente opcional: extender la verificación a los montos por
   ítem si se desea.

## P2 — Correcciones funcionales

4. **Reversión de liquidación (04) (R4).** El mecanismo (reversión) es correcto.
   **Validar en SUNAT beta** que el ResumenReversión con tipoDoc `04` es aceptado y
   **confirmar el plazo vigente** (código usa 5 días; normativa cita 7); ajustar
   `config('liquidacion.reversion_dias')` si corresponde. No cambiar el flujo a ciegas.
5. **Anti-duplicado en PostgreSQL (R5).** Reemplazar `whereJsonContains` por el
   filtrado en PHP ya existente (`VoidedService::crearReversion`) en el endpoint
   `POST /reversiones` y en la anulación de `SummaryController`.
6. **Validación engañosa de `/anulaciones`.** Alinear `StoreVoidedRequest` (quitar
   20/40 del `in:`) con lo que el servicio soporta, y redirigir 20/40 a `/reversiones`.
7. **Endpoint PDF de percepción.** Añadir `pdf` a `PerceptionController` para
   simetría con retención (si el negocio lo requiere).

## P3 — Mantenibilidad / consistencia

8. **Reparar el verificador de cobertura:** corregir la ruta del archivo en
   `tools/check-postman-coverage.php:5` (nombre real de la colección).
9. **Documentar `GET /v1/sunat/estado`** en Postman.
10. **Fuente única de catálogos inline:** mover unidades (Cat 03) y los catálogos
    20/18/55/60 a `config/sunat_catalogs.php`; eliminar la duplicación de unidades
    en 4 requests.
11. **Enum/constante central para `sunat_status`** (evita typos; hoy solo lo protege
    el CHECK de PostgreSQL y no en todas las tablas).
12. **Unificar el filtrado de listados** (`AppliesDocumentFilters`) en NC/ND.
13. **Unificar el parámetro de formato PDF** (`format` vs `formato`) en todos los
    controladores.
14. **Limpiar código muerto:** la referencia a motivo '10' en
    `ValidatesNotaCreditoMonto`; el comentario desactualizado en `NoteBuilder:229`.
15. **Validar `cod_local` contra `sucursales`** (no solo el formato), si se desea
    cortar antes de SUNAT.
16. **Blindar el marcado `enviado`:** considerar un estado “encolado” distinto de
    “enviado”, o verificar que haya worker, para no dejar documentos colgados si el
    worker no corre.

> Nota: varias de estas ya están contempladas en la auditoría por fases del
> proyecto (memoria `auditoria-apipro`). Este documento las consolida desde la
> perspectiva de la API pública.
