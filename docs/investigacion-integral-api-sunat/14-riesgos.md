# 14 — Riesgos técnicos

Se distingue lo **comprobado** (verificado en el código) de lo **potencial**
(depende de configuración o condiciones).

## Comprobados (verificados en código)

### R1 — Recuperación de credenciales rota (crítico)
`CredentialRecoveryController::verificar` genera `$rawSecret = Str::random(64)` y
**guarda `api_secret => hash('sha256', $rawSecret)`**, pero **devuelve `$rawSecret`**
al usuario (`:109-122`). `ResolveTenant` compara el `api_secret` descifrado (= el
hash) contra el secreto crudo que envía el cliente (`ResolveTenant.php:44`) →
**nunca coincide → 401**. El secreto recuperado por este flujo **jamás autentica**.
Contrasta con `regenerarCredenciales`, que guarda el secreto crudo (cifrado por el
cast) y sí funciona.
**Consecuencia:** un cliente que use “recuperar credenciales” queda bloqueado.
**Causa:** inconsistencia entre cómo se guarda (hash) y cómo se compara (claro).
(Coincide con la memoria `bug-recovery-secret-hash`.)

### R2 — Alias `/sunat/*` sin gates de idempotencia ni cupo (alto)
`POST /api/sunat/facturas` y `/api/sunat/boletas` (`routes/api.php:415,421`) corren
con **solo** `resolve.tenant, throttle:api` (`:407`). **No aplican `idempotency`,
`license` ni `check.limit`.**
**Consecuencia:** por esa vía se puede **emitir saltando el cupo del plan** y, por
falta de idempotencia, **duplicar comprobantes** ante reintentos. La licencia en
producción aún la cubre `guardEmisionAutorizada()` en el envío, pero el cupo no.

### R3 — Totales de comprobante overridables sin verificación (medio) · MITIGADO
El motor de cálculo usa `(float)($data['campo'] ?? <calculado>)` a nivel de ítem y
de totales. Si el cliente envía `mto_igv`, `mto_imp_venta`, etc., se usan sin
recálculo. Es flexible por diseño (integradores que mandan valores exactos de SUNAT),
pero sin red de seguridad.

**Mitigación implementada (opt-in):** `config('facturacion.verificar_totales')`
(default **false** → comportamiento histórico intacto). Al activarla, si un total de
cabecera enviado difiere del recalculado por más de `tolerancia_totales` (0.10 por
defecto), la emisión se rechaza con **422**. Así los installs que lo deseen activan
la red de seguridad sin romper a quienes dependen del override. (`DocumentCalculationService::verificarTotales`.)

### R4 — Reversión de liquidación (04): mecanismo correcto, UBL por validar (medio) · REVISADO
`LiquidacionCompraController::reversion` crea un `VoidedDocument` `RR-` con detalle
`tipo_documento='04'` y usa `SendReversionToSunat` → `ReversionBuilder` (un documento
Greenter `Reversion` / ResumenReversión).

**Corrección tras verificar la normativa SUNAT:** la liquidación de compra
electrónica **sí se anula por "reversión"** (no por comunicación de baja), vía un
resumen de reversión. Es decir, el **mecanismo que usa el código es el correcto**,
no un “canal incorrecto” como se asumió inicialmente. El `ReversionBuilder`
contempla '04' en su `modelMap` (`SendReversionToSunat.php:90`).

**Lo que queda abierto (requiere validación en beta, no code-determinable):**
1. Confirmar que SUNAT **acepta** un ResumenReversión con `VoidedDetail` tipoDoc `04`
   (el comentario del builder solo menciona 20/40; el XML podría necesitar ajustes).
2. **Plazo de reversión:** el código usa `config('liquidacion.reversion_dias', 5)`;
   la normativa citada indica **7 días** (modificación 2019 a la RS 188-2010) y una
   versión anterior hablaba del 9.º día hábil del mes siguiente. Conviene confirmar
   el plazo vigente y ajustar el default si corresponde.

**Decisión:** NO se cambia el código (el mecanismo es correcto; un cambio a ciegas
del documento/plazo podría romper un flujo válido o contradecir la norma vigente).
Se recomienda una **prueba controlada en SUNAT beta** para confirmar la aceptación
del XML y el plazo. Ver [documento 15](15-recomendaciones.md).

### R5 — Anti-duplicado con `whereJsonContains` en PostgreSQL (medio)
Los endpoints `POST /reversiones` y la anulación de `SummaryController` usan
`whereJsonContains` sobre arrays JSON, cuyo operador `@>` el propio código reconoce
como no fiable en Postgres (`VoidedService.php:300-309`).
**Consecuencia:** el anti-duplicado puede fallar → reversiones/anulaciones duplicadas.

## Potenciales / de operación (mitigados o condicionales)

| # | Riesgo | Mitigación / condición |
|---|---|---|
| R6 | Clobber de estado `enviado` con `QUEUE_CONNECTION=sync` | No ocurre en prod (Redis/Horizon async) |
| R7 | Cache de tenant por `api_key` 600 s: credencial revocada válida hasta 10 min | Los flujos de regenerar/recuperar limpian la cache |
| R8 | PDF webp/GD: logos `.webp` rompen dompdf en Docker sin GD-webp | Compilar GD `--with-webp` (memoria `dompdf-webp-gd`) |
| R9 | Sin CSP en `SecurityHeaders` | Declarado pendiente por el SPA |
| R10 | Documento queda `enviado` sin ticket si no corre el worker | Requiere Horizon/`queue:work` activo |
| R11 | Horizon requiere Redis + pcntl (no corre en Windows) | Documentado; prod es Linux |

## Defensa en profundidad (lo que SÍ está bien)
- **Licencia:** middleware `CheckLicense` + segunda barrera `guardEmisionAutorizada()`
  en el envío.
- **Estado SUNAT:** clasificación por código de CDR + `recoverFromCdr` + idempotencia
  de jobs + `WithoutOverlapping` → evita duplicados y falsos rechazos.
- **Secretos:** `api_secret`/`certificate_password` con cast `encrypted`; saneo de
  credenciales en logs; descargas solo por endpoints autenticados (no URLs públicas).
- **Correlativo:** lock pesimista + índice único.
