# 38 · Auditoría de acciones sensibles (S5)

Bitácora **append-only** de quién hizo qué en las operaciones delicadas, para
poder reconstruir incidentes (S5 de la auditoría, doc 32).

---

## Qué registra

Tabla `audit_logs` (solo `created_at`, sin updates/deletes desde la app). Cada
registro guarda: `tenant_id`, `user_id`, `actor_tipo` (usuario/api/sistema),
`actor_etiqueta`, `accion`, `subject_type`+`subject_id`, `descripcion`, `ip`,
`user_agent`, `metadata` (JSON).

Acciones instrumentadas (API y panel):

| Acción | Dónde |
|---|---|
| `credenciales.regeneradas` | API `empresa/credenciales/regenerar`, panel admin |
| `empresa.actualizada` | API `PUT empresa`, panel admin y panel cliente |
| `certificado.actualizado` | API `empresa/certificado`, panel admin y cliente |
| `empresa.eliminada` | API `DELETE empresa`, panel admin |
| `comprobante.anulado` | API `POST anulaciones`, panel cliente (y admin vía la API) |
| `equipo.miembro_agregado` / `equipo.permisos_actualizados` / `equipo.miembro_removido` | panel "Mi equipo" |
| `usuario.creado` / `usuario.actualizado` / `usuario.eliminado` | panel admin (usuarios internos) |

El **actor se resuelve solo**: usuario del panel autenticado si lo hay; si no,
la empresa por API (`actor_tipo='api'`); si no, `sistema`. También captura IP y
user-agent. `AuditLog::registrar()` **nunca lanza** (una falla de auditoría no
rompe la acción).

## Cómo registrar una acción nueva
```php
use App\Models\AuditLog;

AuditLog::registrar('mi.accion', [
    'subject'     => $modelo,        // opcional: fija subject_type/subject_id
    'descripcion' => 'Texto legible',
    'metadata'    => ['clave' => 'valor'], // opcional
    // 'tenant_id' => ... // solo si no se puede inferir del request/ruta
]);
```

## Cómo consultarla

**Panel admin**: `/admin/bitacora` (solo super_admin/admin) — lista paginada con
búsqueda por acción/detalle/actor.

**CLI**:
```bash
php artisan ops:auditoria                      # últimas 30
php artisan ops:auditoria --tenant=12          # de una empresa
php artisan ops:auditoria --accion=credenciales --limit=100
```

---

## Nota: bug corregido de paso

Al instrumentar `regenerarCredenciales` (API) se encontró y **corrigió** un bug:
guardaba `api_secret = hash('sha256', $rawSecret)` pero le devolvía `$rawSecret`
al cliente. Como `ResolveTenant` compara el secreto **en claro**, regenerar las
credenciales por la API **dejaba la autenticación rota**. Ahora guarda el mismo
valor que entrega. (La regeneración del panel admin ya era consistente: guarda y
entrega el mismo valor opaco.)

Despliegue: `php artisan migrate --force` (crea `audit_logs`).
