<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Crear particiones para los próximos 3 meses (mensual, día 1 a las 2:00 AM)
Schedule::command('partitions:create --months=3')->monthlyOn(1, '02:00');

// Purgar api_logs con más de 90 días (semanal, domingos a las 3:00 AM)
Schedule::command('logs:purge --days=90')->weeklyOn(0, '03:00');

// Mantiene acotada idempotency_keys y libera reservas en proceso colgadas.
Schedule::command('idempotency:purge')->dailyAt('03:20')->withoutOverlapping();

// === Suscripciones ===
// Procesar pagos recurrentes vencidos (diario a las 6:00 AM)
Schedule::job(new \App\Jobs\ProcessRecurringPayments)->dailyAt('06:00');

// Verificar trials vencidos y notificar trials por vencer (diario a las 7:00 AM)
Schedule::job(new \App\Jobs\CheckTrialExpiration)->dailyAt('07:00');

// Resetear contadores mensuales de uso (1ro de cada mes a las 00:05 AM)
Schedule::job(new \App\Jobs\ResetMonthlyUsage)->monthlyOn(1, '00:05');

// === SIRE ===
// Poolear tickets pendientes cada minuto (idempotente, usa withoutOverlapping)
Schedule::command('sire:poll-pending')
    ->everyMinute()
    ->withoutOverlapping()
    ->runInBackground();

// Reconciliar todos los tenants activos (diario 03:00 AM)
Schedule::command('sire:reconcile-all')
    ->dailyAt('03:00')
    ->withoutOverlapping();

// === Recuperación automática de comprobantes atascados ===
// Reencola facturas/boletas en pendiente/enviado que no llegaron a estado final
// (SUNAT estuvo caído, o el worker no corría al crearlas). NO toca los rechazados.
Schedule::command('sunat:reintentar-pendientes')
    ->everyFifteenMinutes()
    ->withoutOverlapping()
    ->runInBackground();

// Cierra el ciclo de los timeouts: consulta el CDR de retención/percepción/liquidación
// que quedaron en pendiente/SUNAT_TIMEOUT y resuelve su estado sin reenviar.
Schedule::command('sunat:conciliar-timeouts')
    ->everyFifteenMinutes()
    ->withoutOverlapping()
    ->runInBackground();

// Evita que los comprobantes se pasen del plazo de envío a SUNAT: auto-envía los
// que están por vencer y avisa (webhook + OpsAlert) de los vencidos.
Schedule::command('sunat:plazo-envio')
    ->dailyAt('07:00')
    ->withoutOverlapping()
    ->runInBackground();

// === Resúmenes diarios de boletas ===
// Vuelve a consultar el ticket de los resúmenes sin estado final. El comando
// decide a cuáles les toca (seguido la primera hora, cada 4h después), así que
// correrlo cada 5 minutos no genera consultas de más.
Schedule::command('summaries:poll-pending')
    ->everyFiveMinutes()
    ->withoutOverlapping()
    ->runInBackground();

// === Backups (spatie/laravel-backup) ===
// Limpia backups viejos, genera el del día y verifica que el último esté sano.
// Los fallos se avisan por OpsAlert (webhook), no por correo (SMTP bloqueado).
Schedule::command('backup:clean')->dailyAt('01:00')->withoutOverlapping();
Schedule::command('backup:run')->dailyAt('01:30')->withoutOverlapping();
Schedule::command('backup:monitor')->dailyAt('09:00');

// === Alertas de operación (OpsAlert) ===
// Certificados vencidos/por vencer (avisa con 30 días de margen, configurable).
Schedule::command('certificados:verificar --alertar')->dailyAt('08:00');

// Jobs fallidos acumulados (envíos a SUNAT que agotaron reintentos, etc.).
Schedule::command('ops:jobs-fallidos --alertar')->hourly()->withoutOverlapping();

// Salud de infraestructura: BD, Redis, disco y circuit breaker de SUNAT.
Schedule::command('ops:salud --alertar')
    ->everyTenMinutes()
    ->withoutOverlapping()
    ->runInBackground();
