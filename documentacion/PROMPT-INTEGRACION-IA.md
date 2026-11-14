# 🤖 Prompt de integración — API de Facturación Electrónica SUNAT (Perú)

> **Cómo usar este archivo:** pégalo COMPLETO en tu asistente de IA de código (Claude, Cursor, Copilot, ChatGPT, etc.) **junto con el archivo `API FACTURACION SUNAT PERU ✅✅✅✅✅.postman_collection.json`**. El asistente usará la colección como catálogo exacto de endpoints/parámetros y este prompt como las reglas de comportamiento, respuestas y buenas prácticas que la colección no incluye. Sirve para **integrar la API en un sistema nuevo (desde cero) o en uno ya existente**, en **cualquier lenguaje, framework, base de datos u ORM**.

---

## 1. Rol y objetivo

Actúa como **ingeniero senior de integraciones**. Tu objetivo es implementar, de extremo a extremo y con calidad de producción, la integración de **esta API de facturación electrónica SUNAT (Perú)** dentro del sistema del usuario, **adaptándote al stack real del proyecto** (lenguaje, framework, ORM, cola de tareas, almacenamiento). No entregues solo un plan ni fragmentos sueltos: entrega una **capa de integración reutilizable, tipada, con manejo de errores, reintentos y pruebas**, más los ejemplos de uso de cada comprobante que el usuario necesite.

No inventes endpoints, campos ni reglas: **la colección Postman adjunta es la fuente de verdad** de rutas, métodos, headers, parámetros y cuerpos de ejemplo. Este prompt complementa lo que la colección no trae (formato de respuestas, códigos HTTP, flujos asíncronos, idempotencia, errores y buenas prácticas).

## 2. Insumos que recibes

1. **La colección Postman** (`*.postman_collection.json`): todos los requests reales, agrupados por carpetas (Configuración, Facturas, Boletas, Notas, Resúmenes, Anulaciones, Guías, Retenciones, Percepciones, Liquidaciones de compra, Reportes, SIRE, Catálogos, etc.). Úsala como catálogo autoritativo. Variables de colección: `{{base_url}}`, `{{api_key}}`, `{{api_secret}}`, `{{ruc_empresa}}`, `{{periodo}}`, `{{num_ticket}}`.
2. **Este prompt**: contrato de respuestas, semántica HTTP, flujos, robustez y seguridad.

> ⚠️ La colección **no incluye las respuestas** de ejemplo. El formato de respuesta está definido en la sección 4 de este documento; respétalo.

## 3. Qué es la API (contexto)

- API REST, JSON, mensajes en **español**. Base: `{{base_url}}` (termina en `/api/v1`).
- **Multiempresa (multi-tenant):** cada empresa (RUC) tiene su par de credenciales. Toda petición se autentica y queda aislada a esa empresa; nunca verás datos de otra empresa.
- **Autenticación:** headers en **cada** request:
  - `X-Api-Key: <api_key>`
  - `X-Api-Secret: <api_secret>`
  - `Accept: application/json` y, en envíos con cuerpo, `Content-Type: application/json`.
- La configuración de la empresa (certificado digital .pfx/.p12, usuario/clave SOL, régimen, logo, credenciales GRE/SIRE) **se guarda en la API por empresa**, no en tu sistema. Tú solo consumes la API con el `api_key`/`api_secret`.
- **Sucursales y series:** un comprobante pertenece a una sucursal **a través de su serie**. Cada serie se registra a una `sucursal_id`; al emitir solo envías el `serie`, y el comprobante hereda `sucursal_id` y `cod_local` (código de local anexo que va en el XML). No se envía “sucursal” al emitir.

## 4. Contrato de respuestas (OBLIGATORIO — no está en la colección)

Todas las respuestas usan este sobre JSON:

```json
// Éxito (200 / 201)
{ "estado": "exito", "mensaje": "texto", "datos": { ... }, "meta": { ... } }

// Error
{ "estado": "error", "mensaje": "texto humano", "errores": { "campo": ["detalle"] } }

// Error accionable (algunos 422): incluye código y siguiente paso sugerido
{ "estado": "error", "mensaje": "...", "codigo_error": "CODIGO", "siguiente_accion": { ... } }
```

Códigos HTTP y su significado:

| HTTP | Significado | Qué hacer |
|------|-------------|-----------|
| `200` | OK (consulta / acción) | procesar `datos` |
| `201` | Creado (emisión, pago, reversión) | procesar `datos` (incluye `id`, `numero_completo`, estado SUNAT) |
| `401` | Credenciales inválidas | revisar `api_key`/`api_secret` |
| `403` | Licencia o suscripción vencida | no reintentar; avisar al usuario |
| `422` | Validación / regla SUNAT | mostrar `errores`; corregir datos; **no** reintentar igual |
| `429` | Límite de plan o rate limit | respetar `Retry-After`; reintentar con backoff |
| `404` | No encontrado | id/recurso inexistente |
| `5xx` | Error del servidor | reintentar con backoff exponencial |

Formas típicas de `datos` por familia (dedúcelas de la colección + estas pautas):
- **Emisión de comprobante** (`POST /facturas`, `/boletas`, `/notas-credito`, `/notas-debito`, `/retenciones`, `/percepciones`, `/liquidaciones-compra`): `datos` trae al menos `id`, `serie`, `correlativo`, `numero_completo`, y un bloque de estado SUNAT (p. ej. `sunat_status`/`sunat.estado` y `sunat_code`). El envío a SUNAT suele ser **asíncrono**: el estado inicial es `enviado`/`pendiente` y se resuelve a `aceptado`/`rechazado` poco después (ver sección 5).
- **Listado** (`GET /facturas`, etc.): `datos` suele ser `{ "datos": [ ... ], "paginacion": { "pagina_actual", "ultima_pagina", "por_pagina", "total" } }`. Respeta parámetros de query (`por_pagina`, `page`, filtros por fecha/estado/serie/documento).
- **Descargas** (`GET /{tipo}/{id}/xml | /cdr | /pdf`): devuelven **binario** (no JSON): `application/xml`, `application/zip` (CDR) y `application/pdf`. Guarda los bytes tal cual; respeta el `Content-Disposition`.
- **Comprobantes por ticket** (resúmenes `RC`, anulaciones/bajas `RA`, guías GRE, reversiones `RR`): el `POST` responde con un `ticket`/`identifier`; el resultado final se consulta aparte (sección 5).

## 5. Flujos asíncronos (clave para no marcar como aceptado algo que SUNAT no aceptó)

Hay dos patrones; detéctalos por la familia de endpoint en la colección:

**A. CDR síncrono diferido (facturas, boletas, notas, retenciones, percepciones, liquidación de compra):**
1. `POST` emite → `datos` con estado `enviado`/`pendiente`.
2. El resultado final (`aceptado`/`rechazado`) se obtiene por **una** de estas vías (usa la que ofrezca la colección/tu caso):
   - **Webhook** (recomendado): si la empresa configuró `webhook_url`, la API notifica el cambio de estado. Implementa un receptor idempotente.
   - **Polling**: `GET /{tipo}/{id}` hasta que el estado sea final, con backoff (p. ej. 2s, 5s, 10s, 30s…). No hagas polling agresivo.
3. **Nunca** consideres el comprobante válido hasta ver estado `aceptado` (o `aceptado con observación`, 4000+). Guarda `sunat_code`, `sunat_description` y descarga el **CDR** como evidencia.

**B. Ticket SUNAT (resúmenes diarios RC, comunicaciones de baja/anulaciones RA, guías GRE, reversiones RR):**
1. `POST` crea → devuelve `ticket`/`identifier` y estado `enviado`.
2. Consulta el estado con el endpoint de estado correspondiente (en la colección aparece como `GET /{recurso}/{id}/estado` o similar) hasta `aceptado`/`rechazado`.
3. Reintenta la consulta con backoff; SUNAT puede tardar. Códigos “en proceso” (p. ej. `98`) significan **reintentar**, no error.

## 6. Robustez (implementar sí o sí)

- **Idempotencia:** para evitar duplicados ante reintentos de red, si la colección define un header de idempotencia (p. ej. `Idempotency-Key`), **úsalo** con una clave estable por emisión lógica (p. ej. el ID interno de tu documento, o `serie+correlativo`). Si no, evita reenvíos ciegos: antes de reintentar un `POST` de emisión, consulta si ya existe.
- **Reintentos con backoff exponencial + jitter** solo para `429` y `5xx` y errores de red/timeout. **Nunca** reintentes un `422` con el mismo cuerpo.
- **Timeouts** razonables (p. ej. 30–60s) y manejo de cancelación.
- **Rate limit:** respeta `429` y `Retry-After`. Serializa o encola los envíos por empresa.
- **Colas/jobs:** si tu sistema tiene cola de tareas, emite y consulta estados en **background**; no bloquees la petición del usuario esperando a SUNAT.
- **Reconciliación:** job periódico que busca comprobantes en estado no final y consulta su estado, por si se perdió un webhook.
- **Mapeo de errores:** convierte el sobre de error en excepciones/tipos de tu lenguaje (p. ej. `ValidationError` con `errores`, `AuthError`, `RateLimitError`, `PlanLimitError`, `SunatRejectedError`, `ServerError`).

## 7. Seguridad (no negociable)

- `api_key`/`api_secret` son secretos: **nunca** en el código fuente, en URLs/query string, en logs, ni expuestos al navegador/cliente. Guárdalos en variables de entorno / gestor de secretos del proyecto.
- Todo por **HTTPS**. No deshabilites verificación TLS.
- No registres cuerpos con datos sensibles (certificados, claves SOL) en logs.
- Si tu sistema es web, las llamadas a la API van **desde el backend**, nunca desde el frontend con las credenciales embebidas.

## 8. Dos escenarios de implementación

### A) Sistema desde cero
1. Crea un **cliente HTTP reutilizable** (módulo/clase) con: base_url, inyección de credenciales, headers comunes, timeouts, reintentos, parseo del sobre de respuesta y mapeo de errores tipados.
2. Modela DTOs/entidades para los comprobantes que el negocio necesita (empieza por facturas y boletas).
3. Expón funciones de alto nivel: `emitirFactura()`, `emitirBoleta()`, `consultarEstado()`, `descargarXml()/Cdr()/Pdf()`, `anular()`, etc.
4. Persiste localmente el vínculo: tu documento interno ↔ `id`/`numero_completo`/estado SUNAT/rutas de archivos, para trazabilidad y reconciliación.
5. Agrega el receptor de webhook (si aplica) y/o los jobs de polling/reconciliación.

### B) Sistema ya existente (lo más común)
1. **No reescribas** el sistema. Crea una **capa de integración aislada** (carpeta/módulo `facturacion_sunat/` o equivalente) que encapsule toda la comunicación con la API.
2. **Mapea** tus modelos existentes (venta/pedido/cliente/ítems) → el cuerpo que la colección exige para cada comprobante. Centraliza ese mapeo.
3. Añade a tus tablas/documentos los campos mínimos para el ciclo SUNAT (sin romper lo actual): `cpe_id` (id en la API), `cpe_numero`, `cpe_estado`, `cpe_codigo`, `cpe_xml_path`, `cpe_cdr_path`, `cpe_pdf_path`, `cpe_ticket`. Usa migraciones aditivas; no borres ni alteres datos existentes.
4. Dispara la emisión desde el evento de negocio correcto (p. ej. “venta confirmada/pagada”), preferentemente en **background**.
5. Mantén idempotencia usando tu **clave de negocio** (id de venta) para no duplicar emisiones ante reintentos.
6. Actualiza el estado por webhook y/o reconciliación; refleja el estado SUNAT en tu UI.

## 9. Pasos de implementación (hazlos en orden)

1. **Analiza la colección**: lista las carpetas y, por cada operación que el usuario necesite, extrae método, ruta, headers, parámetros de query y cuerpo de ejemplo. Sustituye las variables `{{...}}` por configuración.
2. **Detecta el stack** del proyecto (lenguaje, framework, ORM, cliente HTTP, cola, almacenamiento, gestión de secretos) leyendo el repositorio. Si algo es ambiguo, **pregunta** antes de asumir.
3. Implementa el **cliente HTTP base** (sección 8.A.1) con el contrato de respuestas (sección 4) y la robustez (sección 6).
4. Implementa la **configuración inicial** si el flujo lo requiere: alta/lectura de empresa, **sucursales** y **series** (recuerda: serie ligada a `sucursal_id`), usando los requests de la carpeta de Configuración.
5. Implementa la **emisión** de los comprobantes requeridos (empieza por factura y boleta) con su mapeo de datos.
6. Implementa **estados asíncronos** (webhook y/o polling) y **descargas** (XML/CDR/PDF).
7. Implementa **anulación/reversión** y **reportes** si el negocio los usa.
8. Escribe **pruebas** (unitarias del mapeo/cliente con respuestas simuladas; de integración contra el entorno **beta** si hay credenciales de prueba). **No** realices emisiones productivas reales sin autorización explícita.
9. Documenta: variables de entorno, cómo configurar credenciales, ejemplos de uso y cómo probar.

## 10. Entregables

- Capa/cliente de integración reutilizable y tipada.
- Mapeo centralizado modelo-de-negocio → cuerpo de la API.
- Manejo de errores tipado + reintentos + idempotencia.
- Emisión + consulta de estado + descargas de los comprobantes requeridos, con ejemplos ejecutables.
- Receptor de webhook y/o jobs de polling/reconciliación (según el stack).
- Pruebas y documentación (incluida la configuración de `.env`/secretos).

## 11. Criterios de aceptación

- Se emite al menos una factura y una boleta de extremo a extremo (en beta) y se alcanza estado `aceptado`, con CDR descargable.
- Ningún comprobante se marca como válido sin confirmación de SUNAT (`aceptado`).
- Credenciales fuera del código y fuera de logs/URLs; todo por HTTPS.
- Reintentos solo donde corresponde; `422` nunca se reintenta a ciegas; `429`/`5xx` con backoff.
- La integración no rompe funcionalidades existentes (en sistemas ya hechos).
- Pruebas ejecutadas y documentadas; limitaciones (p. ej. falta de credenciales beta) documentadas, no simuladas.

## 12. Qué NO hacer

- No inventar endpoints, campos ni reglas tributarias que no estén en la colección o en la respuesta real de la API.
- No incrustar credenciales en el cliente/frontend ni en el repositorio.
- No considerar “emitido = aceptado”: esperar la confirmación de SUNAT.
- No reintentar errores de validación (`422`) con el mismo cuerpo.
- No migrar ni reescribir el sistema existente para “encajar” la API.
- No usar datos ficticios para simular una integración real en producción.

## 13. Primera acción del asistente

1. Lee la colección y resume las operaciones disponibles por carpeta.
2. Inspecciona el repositorio y determina el stack. Si falta algo crítico (lenguaje/framework/dónde guardar secretos/cómo encolar), **haz 2–4 preguntas concretas** antes de escribir código.
3. Propón en 5–8 líneas el plan de integración (archivos a crear, dónde, y qué comprobantes primero) y, con el visto bueno, **implementa** siguiendo los pasos de la sección 9.
