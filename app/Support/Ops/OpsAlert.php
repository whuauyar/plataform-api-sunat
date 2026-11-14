<?php

declare(strict_types=1);

namespace App\Support\Ops;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Canal único de alertas de operación.
 *
 * Siempre registra en el log; si hay webhook configurado (Slack/Discord/n8n…),
 * también lo publica ahí. Pensado para esquivar el bloqueo de SMTP del VPS.
 *
 * Nunca lanza excepciones: una alerta que falla no debe tumbar el proceso que
 * la originó (un backup, un job, el scheduler).
 */
final class OpsAlert
{
    public const CRITICO = 'critical';

    public const AVISO = 'warning';

    public const INFO = 'info';

    /**
     * @param  array<string, scalar|null>  $contexto  pares clave→valor para el detalle
     * @param  string|null  $dedupeKey  si se repite dentro de la ventana de throttle, no se reenvía
     */
    public static function enviar(
        string $nivel,
        string $titulo,
        string $mensaje = '',
        array $contexto = [],
        ?string $dedupeKey = null,
    ): void {
        // Log siempre (antes del throttle, para que quede rastro completo).
        self::loguear($nivel, $titulo, $mensaje, $contexto);

        if ($dedupeKey !== null && ! self::debeEnviar($dedupeKey)) {
            return;
        }

        $url = (string) config('ops.webhook_url');
        if ($url === '') {
            return; // Solo log (p. ej. en local o si no se ha configurado).
        }

        self::publicarWebhook($url, $nivel, $titulo, $mensaje, $contexto);
    }

    public static function critico(string $titulo, string $mensaje = '', array $contexto = [], ?string $dedupeKey = null): void
    {
        self::enviar(self::CRITICO, $titulo, $mensaje, $contexto, $dedupeKey);
    }

    public static function aviso(string $titulo, string $mensaje = '', array $contexto = [], ?string $dedupeKey = null): void
    {
        self::enviar(self::AVISO, $titulo, $mensaje, $contexto, $dedupeKey);
    }

    public static function info(string $titulo, string $mensaje = '', array $contexto = [], ?string $dedupeKey = null): void
    {
        self::enviar(self::INFO, $titulo, $mensaje, $contexto, $dedupeKey);
    }

    private static function debeEnviar(string $dedupeKey): bool
    {
        $minutos = max(0, (int) config('ops.throttle_minutes', 60));
        if ($minutos === 0) {
            return true;
        }

        $cacheKey = 'ops:alert:'.sha1($dedupeKey);
        if (Cache::has($cacheKey)) {
            return false;
        }

        Cache::put($cacheKey, true, now()->addMinutes($minutos));

        return true;
    }

    private static function loguear(string $nivel, string $titulo, string $mensaje, array $contexto): void
    {
        $metodo = match ($nivel) {
            self::CRITICO => 'error',
            self::AVISO => 'warning',
            default => 'info',
        };

        $canal = (string) config('ops.log_channel', config('logging.default'));
        $etiqueta = (string) config('ops.label', config('app.name'));

        Log::channel($canal)->{$metodo}("[OPS][{$etiqueta}] {$titulo}".($mensaje !== '' ? " — {$mensaje}" : ''), $contexto);
    }

    private static function publicarWebhook(string $url, string $nivel, string $titulo, string $mensaje, array $contexto): void
    {
        $etiqueta = (string) config('ops.label', config('app.name'));
        $emoji = match ($nivel) {
            self::CRITICO => '🔴',
            self::AVISO => '🟡',
            default => '🟢',
        };

        $texto = "{$emoji} *{$titulo}* ({$etiqueta})";
        if ($mensaje !== '') {
            $texto .= "\n{$mensaje}";
        }
        foreach ($contexto as $clave => $valor) {
            $texto .= "\n• {$clave}: {$valor}";
        }

        // Carga compatible con varios destinos:
        //  - Slack usa "text"; Discord usa "content" (cada uno ignora la clave ajena).
        //  - Los campos sueltos sirven para n8n/receptores propios.
        $payload = [
            'text' => $texto,
            'content' => $texto,
            'nivel' => $nivel,
            'titulo' => $titulo,
            'mensaje' => $mensaje,
            'contexto' => $contexto,
            'app' => $etiqueta,
            'timestamp' => now()->toIso8601String(),
        ];

        try {
            $resp = Http::timeout((int) config('ops.webhook_timeout', 5))
                ->asJson()
                ->post($url, $payload);

            if ($resp->failed()) {
                Log::channel((string) config('ops.log_channel', config('logging.default')))
                    ->warning('[OPS] El webhook de alertas respondió con error', [
                        'status' => $resp->status(),
                    ]);
            }
        } catch (Throwable $e) {
            Log::channel((string) config('ops.log_channel', config('logging.default')))
                ->warning('[OPS] No se pudo publicar la alerta en el webhook', [
                    'error' => $e->getMessage(),
                ]);
        }
    }
}
