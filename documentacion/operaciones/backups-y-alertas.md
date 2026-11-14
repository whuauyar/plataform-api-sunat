# 33 · Backups y alertas de operación (O1 + O4)

Cierra dos brechas de la auditoría (doc 32): **O1 backups automatizados** y
**O4 alertas** (jobs fallidos, certificado por vencer, SUNAT caído, disco lleno).

> Regla de oro de este proyecto: el VPS **bloquea el SMTP saliente**, así que las
> alertas **no van por correo**. Van a un **webhook** (Slack/Discord/n8n/Telegram)
> y **siempre al log**. Un solo formato para todo.

---

## 1. Canal único de alertas — `OpsAlert`

`app/Support/Ops/OpsAlert.php`. Métodos: `OpsAlert::critico()`, `::aviso()`,
`::info()` (y `::enviar($nivel, $titulo, $mensaje, $contexto, $dedupeKey)`).

- **Siempre loguea** (canal `ops.log_channel`, por defecto el default).
- Si hay `OPS_ALERT_WEBHOOK_URL`, **publica** un JSON compatible con Slack
  (`text`), Discord (`content`) y receptores propios (campos `nivel/titulo/…`).
- **Anti-spam**: con `dedupeKey`, no reenvía la misma alerta dentro de
  `OPS_ALERT_THROTTLE_MINUTES` (def. 60).
- **Nunca lanza**: si el webhook falla o tarda, se registra y sigue — alertar
  jamás tumba al proceso que la originó.

### Configuración (`config/ops.php` + `.env`)
```env
OPS_ALERT_WEBHOOK_URL=          # vacío = solo log (local)
OPS_ALERT_LABEL="${APP_NAME}"   # distingue entorno A/B, beta…
OPS_ALERT_THROTTLE_MINUTES=60
OPS_JOBS_FALLIDOS_UMBRAL=1
OPS_CERT_DIAS_AVISO=30
OPS_DISCO_MIN_LIBRE_PCT=10
```
Funciona con cualquier webhook entrante: un *Incoming Webhook* de Slack, un
webhook de canal de Discord, o un flujo de n8n/Make que reenvíe a WhatsApp.

---

## 2. Chequeos y alertas (O4)

| Comando | Qué revisa | Cuándo (scheduler) |
|---|---|---|
| `certificados:verificar --alertar` | Certificado de cada empresa: SIN CERT / ROTO / VENCIDO / POR VENCER (margen `OPS_CERT_DIAS_AVISO`) | Diario 08:00 |
| `ops:jobs-fallidos --alertar` | Tabla `failed_jobs` sobre el umbral; desglosa por cola | Cada hora |
| `ops:salud --alertar` | BD, Redis (si se usa), disco libre, storage escribible y **circuit breaker de SUNAT** | Cada 10 min |

- **SUNAT caído** se detecta por el *circuit breaker* (`SunatCircuitBreaker`):
  estado `open` = los envíos están fallando y reintentándose en cola → alerta
  crítica. No se pinguea a SUNAT directamente (evita falsos positivos).
- Correr sin `--alertar` solo imprime la tabla (útil para revisar a mano).

### Endpoint de salud `GET /api/health` (O3)

Los mismos chequeos se exponen como endpoint **público** (para monitores como
uptime-kuma), vía el servicio compartido `App\Support\Ops\HealthChecks` (lo usan
tanto `ops:salud` como el endpoint; una sola fuente de verdad).

- **Público, sin datos sensibles**: solo `componente / estado / detalle`.
- **200** si la API opera (incluso `degradado`: SUNAT caído o disco bajo no
  tumban la API); **503** solo si un componente propio (BD, Redis,
  almacenamiento) está caído.
- Respuesta cacheada ~15s para soportar *polling* sin martillar la BD.

```json
{
  "estado": "ok",
  "timestamp": "2026-10-03T12:00:00-05:00",
  "checks": [
    {"componente": "Base de datos", "estado": "ok", "detalle": "mysql"},
    {"componente": "SUNAT beta", "estado": "ok", "detalle": ""}
  ]
}
```
Para uptime-kuma: monitor HTTP(s) a `/api/health`, considera "up" solo con 200.

---

## 3. Backups (O1) — `spatie/laravel-backup`

| Comando | Qué hace | Cuándo |
|---|---|---|
| `backup:run` | Dump de la BD (MySQL o Postgres según driver) + `storage/app/private` + `.env`, comprimido | Diario 01:30 |
| `backup:clean` | Aplica la política de retención (7 días completos, luego diario/semanal/mensual) | Diario 01:00 |
| `backup:monitor` | Verifica que el último backup exista y no sea viejo; si no, alerta | Diario 09:00 |
| `backup:list` | Lista los backups existentes | manual |

- **Qué se respalda**: el código ya está en git, así que solo se respalda lo que
  **no** está versionado y duele perder: almacenamiento privado (certificados,
  logos, XML/CDR/PDF si están en disco local) y el `.env`.
- **Destino**: `BACKUP_DISK` (`local` por defecto; **`s3` recomendado en
  producción** para no perder el backup si se cae el servidor).
- **Cifrado**: define `BACKUP_ARCHIVE_PASSWORD` (muy recomendado, el `.env` lleva
  secretos).
- **Notificaciones**: las nativas de spatie están **desactivadas**; los eventos
  (`BackupHasFailed`, `UnhealthyBackupWasFound`, `CleanupHasFailed`) se enrutan
  por `OpsAlert` desde `AppServiceProvider::configureBackupAlerts()`. El éxito no
  alerta (solo queda en el log de spatie).

### ⚠️ Requisito en el servidor
`backup:run` necesita el binario de volcado de BD:
- **Entorno A (MySQL)**: `mysqldump` (viene con el cliente de MySQL).
- **Entorno B (Docker/Postgres)**: `pg_dump` → añadir `postgresql-client` a la
  imagen de `api-sunat`. Si falta el binario, `backup:run` falla y dispara la
  alerta `Backup FALLÓ` (degrada avisando).

---

## 4. Despliegue

1. `git pull` + `composer install` (trae `spatie/laravel-backup`).
2. Asegurar el binario de dump (`mysqldump` o `pg_dump`, ver arriba).
3. En el `.env`: `OPS_ALERT_WEBHOOK_URL`, `BACKUP_DISK` (ideal `s3`) y
   `BACKUP_ARCHIVE_PASSWORD`.
4. Que el **scheduler esté corriendo**:
   - **Entorno A**: cron con `* * * * * php artisan schedule:run`.
   - **Entorno B**: servicio `schedule:work` en el compose (ver doc 26 / memoria
     de despliegue).
5. Probar: `php artisan ops:salud`, `php artisan backup:run --only-db`.

Todas las entradas del scheduler están en `routes/console.php`.

---

## 5. Estado en el roadmap (doc 32)
- ✅ **O1 backups** (spatie, BD + archivos privados, retención, monitor, S3-ready).
- ✅ **O4 alertas** (certificados, jobs fallidos, SUNAT caído, disco) por webhook.
- ⏳ Pendientes del #4/#5: Horizon, índices, Sentry, SDKs, guía de inicio,
  catálogo de errores.

Tests: `tests/Feature/Ops/AlertasOpsTest.php`.
