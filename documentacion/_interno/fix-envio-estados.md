# 29 · Envío a SUNAT: estados, idempotencia y Resumen Diario

Correcciones a partir de reportes de clientes sobre el envío de boletas y el
flujo de Resumen Diario. Resume causa raíz, fix y cómo operar.

---

## 1. Una boleta "oscilaba" de estado (aceptado → pendiente → rechazado)

**Síntoma:** un comprobante que ya había sido **aceptado** por SUNAT volvía a
`pendiente`, se reenviaba y terminaba `rechazado`, cambiando de estado una y otra
vez.

**Causa raíz:** el job `SendDocumentToSunat` no verificaba el estado antes de
enviar. Si el documento se re-encolaba (por el reintento automático, un doble
encolado o un reenvío manual), se mandaba **otra vez** a SUNAT; SUNAT lo rechaza
por duplicado y ese rechazo **pisaba** el estado `aceptado`.

**Fix (`app/Jobs/SendDocumentToSunat.php`):**
- **Guard de idempotencia:** si el documento ya está `aceptado` o `anulado`, el
  job retorna sin enviar.
- **Anti-solapamiento (`WithoutOverlapping`):** serializa los envíos del *mismo*
  documento para que dos jobs no lo manden a la vez (evita el rechazo por
  duplicado en condición de carrera).

> Un comprobante **aceptado es final**: nunca se reenvía.

---

## 2. Las boletas "se enviaban solas" aunque se querían por Resumen Diario

**Síntoma:** al registrar boletas para enviarlas agrupadas por Resumen Diario,
se enviaban igual una por una.

**Causa raíz:** una boleta destinada al resumen queda en estado `pendiente` (es
intencional). El comando de recuperación `sunat:reintentar-pendientes` reencolaba
**todos** los `pendiente` y los mandaba individualmente, rompiendo el flujo de
resumen.

**Fix (`app/Console/Commands/ReintentarComprobantesPendientes.php`):** el
comando ahora **solo recupera `enviado`** (documentos despachados que quedaron
atascados sin estado final). **Ya no toca `pendiente`**, porque ese estado es
intencional: boleta para Resumen Diario o comprobante guardado para envío manual.

> `pendiente` = intencional (no enviar). `enviado` = en vuelo (recuperable).
> `aceptado`/`rechazado` = final (no se tocan).

### Flujo recomendado: boletas por Resumen Diario (RC)
1. **Registrar** cada boleta sin enviarla:
   - API: `POST /boletas` con `"solo_registro": true` (o `"enviar_automatico": false`).
   - Panel: al emitir, elegir **"Registrar para Resumen Diario"**.
   La boleta queda en `pendiente` y **no** se envía individualmente.
2. **Revisar** las pendientes del día: `GET /boletas?sunat_status=pendiente`.
3. **Generar el Resumen Diario** (panel: *Trámites → Resumen diario*; o el
   endpoint de resúmenes). SUNAT procesa las boletas agrupadas.

Para enviar **una** boleta de inmediato: `enviar_automatico=true` (default) o
`POST /boletas/{id}/enviar`.

---

## 3. "No se puede hacer Nota de Crédito desde el panel"

**Revisión:** la ruta (`/sunat/nota-credito/nueva`), el controlador
(`NotaCreditoController`), la acción (`CreateCreditNoteAction`) y los permisos
están correctos. El menú y el `store` autorizan con la ability
**`nota_credito.emitir`**, que existe en el catálogo RBAC (`Ability::TIPOS`) y
está incluida en el **preset por defecto del cajero**.

**Conclusión:** no es un error de código. Lo más probable es **permisos del
usuario**: un cajero sin `nota_credito.emitir` no ve/usa la opción.

**Cómo habilitarlo:** con el dueño, en **Mi equipo → (cajero) → Permisos**,
marcar **Nota de crédito → Emitir**. Con el dueño/super admin siempre está
disponible.

> Si aun con el permiso marcado falla, enviar el **mensaje de error exacto** y
> los pasos para reproducirlo (afecta a qué comprobante, motivo, etc.).

---

## Tests
- `tests/Feature/Sunat/EnvioEstadoTest.php`
  - el reintento reencola `enviado` pero **no** `pendiente`.
  - `SendDocumentToSunat` **no** reenvía un comprobante ya `aceptado`.

## Postman
La colección principal ya incluye el flujo: *Boleta solo registro (envío vía
resumen)*, *Boleta envío manual*, *Listar boletas pendientes*, *Enviar a SUNAT
(manual)*. Se amplió la descripción del ejemplo "solo registro" con el flujo
completo.
