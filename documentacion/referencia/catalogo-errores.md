# 36 · Catálogo de errores de la API

Referencia de los errores que devuelve la API (D5/R3 de la auditoría). Cubre el
**formato de error**, los **códigos HTTP** propios, la **idempotencia** y los
**estados/códigos de SUNAT**. Pensado para que quien integre sepa qué significa
cada respuesta y qué hacer.

---

## 1. Formato de error (envelope)

Todas las rutas `/api/*` devuelven JSON. Un error siempre tiene esta forma:

```json
{
  "estado": "error",
  "mensaje": "Texto legible de qué pasó"
}
```

En errores de validación se agrega `errores`:

```json
{
  "estado": "error",
  "mensaje": "Error de validación",
  "errores": {
    "serie": ["El campo serie es obligatorio."],
    "items.0.cantidad": ["El campo cantidad debe ser mayor a 0."]
  }
}
```

> Siempre revisa el campo `estado`: `"exito"` = ok, `"error"` = algo falló.
> No te bases solo en el HTTP status (aunque también es correcto).

---

## 2. Códigos HTTP

| HTTP | `mensaje` típico | Qué significa | Qué hacer |
|---|---|---|---|
| **200** | — | OK (consultas, descargas) | — |
| **201** | — | Creado (emisión aceptada en cola/registro) | Guardar serie-correlativo; consultar estado |
| **401** | `Las cabeceras X-Api-Key y X-Api-Secret son requeridas.` | Falta alguna credencial | Enviar ambas cabeceras |
| **401** | `Credenciales de API inválidas.` | Key/secret incorrectos | Revisar credenciales del panel |
| **403** | `Empresa desactivada. Contacte al administrador.` | Tenant inactivo | Contactar al proveedor |
| **403** | `No autorizado.` | Sin permiso para la acción | Revisar plan/permisos |
| **404** | `Recurso no encontrado.` | El comprobante/ID no existe | Verificar serie-correlativo/ID |
| **405** | `Método HTTP no permitido para esta ruta.` | Método equivocado (p. ej. GET en vez de POST) | Revisar el método |
| **409** | `Ya hay una solicitud en proceso con esa Idempotency-Key.` | Reintento mientras la 1ª aún corre | Esperar y reconsultar (ver §3) |
| **422** | `Error de validación` | Datos inválidos (ver `errores`) | Corregir los campos listados |
| **422** | `La Idempotency-Key ya se usó con un cuerpo distinto.` | Reusaste la key con otro payload | Usar una key nueva (ver §3) |
| **429** | `Demasiadas solicitudes. Intenta de nuevo más tarde.` | Rate limit por plan | Reintentar con backoff |
| **500** | `Error interno del servidor.` | Error inesperado | Reintentar; si persiste, reportar |

> En **429** respeta la cabecera `Retry-After` si viene. Los límites por minuto
> dependen del plan (free/pro/business).
> En modo debug, el 500 incluye además `excepcion`, `detalle` y `archivo` (no
> confiar en esos campos en producción).

---

## 3. Idempotencia (evitar comprobantes duplicados)

En peticiones de escritura (POST/PUT/PATCH/DELETE) puedes enviar la cabecera
`Idempotency-Key` (un valor único por operación, p. ej. un UUID).

| Situación | Respuesta |
|---|---|
| 1ª vez | Se procesa normal; respuesta con cabecera `Idempotency-Replayed: false` |
| Misma key + **mismo** cuerpo | Devuelve la **misma** respuesta guardada, con `Idempotency-Replayed: true` (NO re-emite) |
| Misma key + **distinto** cuerpo | **422** `La Idempotency-Key ya se usó con un cuerpo distinto.` |
| Misma key mientras la 1ª aún corre | **409** en proceso |
| La 1ª respondió 5xx | La key se libera → puedes reintentar |

**Recomendación**: genera una `Idempotency-Key` por cada factura/boleta que
emitas y reutilízala solo si reintentas esa misma emisión tras un corte de red.

---

## 4. Estados del comprobante (`sunat_status`)

La emisión es asíncrona: la API encola el envío a SUNAT y responde rápido. El
estado evoluciona así:

| Estado | Significado |
|---|---|
| `pendiente` | Creado, aún no enviado a SUNAT |
| `enviado` | Enviado a SUNAT, esperando respuesta (CDR/ticket) |
| `aceptado` | SUNAT lo aceptó (CDR código 0, o aceptado con observaciones) |
| `rechazado` | SUNAT lo rechazó (error de datos; **no se reintenta**, hay que corregir y reemitir) |
| `anulado` | Dado de baja / comunicado como anulado |

Consulta el estado con el endpoint de consulta (ver guía de primeros pasos) o
recíbelo por **webhook** (§6).

---

## 5. Códigos de respuesta de SUNAT (CDR)

Cuando SUNAT procesa el comprobante devuelve un **CDR** con un código. La API lo
guarda y lo expone. Rangos:

| Código CDR | Significado | Estado resultante |
|---|---|---|
| `0` | Aceptado | `aceptado` |
| `100`–`999` | Excepción (problema temporal del servicio) | se reintenta |
| `1000`–`1999` | Error del contribuyente (firma, RUC, etc.) | `rechazado` |
| `2000`–`3999` | Error en los datos del comprobante | `rechazado` |
| `4000`+ | **Observaciones**: aceptado, pero revisar | `aceptado` |

Ejemplos frecuentes de rechazo: `2335` (valor de venta no coincide), `3127`,
`3265` (validaciones de montos/series). El **mensaje exacto** de SUNAT viene en la
descripción del CDR del comprobante; úsalo para corregir y reemitir.

> Un **rechazo (1000–3999)** es un error permanente de datos: la API **no** lo
> reintenta. Corrige el comprobante y vuelve a emitir (con otra correlativo/serie
> según corresponda). Un comprobante con **observaciones (4000+)** quedó aceptado.

---

## 6. Webhooks (notificación de estado)

Si configuras `webhook_url` en tu empresa, la API te notifica los cambios de
estado con un POST firmado. Verifica la firma antes de confiar en el contenido:

- Cabecera **`X-Signature: sha256=<hmac>`**.
- El HMAC es `hash_hmac('sha256', <cuerpo_crudo>, <tu_api_secret>)`.
- Compara con `hash_equals` (comparación segura).

Ver el ejemplo de verificación en la guía de primeros pasos (doc 37) y en los
SDKs (`sdk/`).

---

## 7. Buenas prácticas ante errores
- Reintenta **solo** 429 y 5xx, con *backoff* exponencial y una `Idempotency-Key`.
- **No** reintentes 4xx de validación (422/404/403): corrige y reenvía.
- Registra `serie`, `correlativo` y el `mensaje` de SUNAT para soporte.
- Trata `rechazado` como acción manual (corregir datos), no como reintento.
