<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Canal de alertas de operación (OpsAlert)
    |--------------------------------------------------------------------------
    |
    | Las alertas de infraestructura (backups, jobs fallidos, certificados por
    | vencer, SUNAT caído, disco lleno) NO se envían por correo: en el VPS de
    | Hetzner el SMTP saliente está bloqueado. En su lugar se publican en un
    | webhook configurable (compatible con Slack, Discord, n8n, Telegram-proxy)
    | y SIEMPRE se registran en el log.
    |
    | Deja OPS_ALERT_WEBHOOK_URL vacío para solo loguear (útil en local).
    |
    */

    'webhook_url' => env('OPS_ALERT_WEBHOOK_URL'),

    // Canal de log donde se registran todas las alertas (además del webhook).
    'log_channel' => env('OPS_ALERT_LOG_CHANNEL', config('logging.default')),

    // Etiqueta que acompaña cada alerta (para distinguir entornos: A/B, beta…).
    'label' => env('OPS_ALERT_LABEL', config('app.name')),

    // Anti-spam: no reenvía la MISMA alerta (misma clave) dentro de esta ventana.
    'throttle_minutes' => (int) env('OPS_ALERT_THROTTLE_MINUTES', 60),

    // Timeout (segundos) del POST al webhook. Corto: alertar nunca debe colgar.
    'webhook_timeout' => (int) env('OPS_ALERT_WEBHOOK_TIMEOUT', 5),

    /*
    |--------------------------------------------------------------------------
    | Umbrales de los chequeos
    |--------------------------------------------------------------------------
    */
    'umbrales' => [
        // Alertar si hay al menos este número de jobs en failed_jobs.
        'jobs_fallidos' => (int) env('OPS_JOBS_FALLIDOS_UMBRAL', 1),

        // Alertar si un certificado vence en <= estos días (0 = ya vencido).
        'cert_dias_aviso' => (int) env('OPS_CERT_DIAS_AVISO', 30),

        // Alertar si el espacio libre en disco baja de este porcentaje.
        'disco_min_libre_pct' => (int) env('OPS_DISCO_MIN_LIBRE_PCT', 10),
    ],
];
