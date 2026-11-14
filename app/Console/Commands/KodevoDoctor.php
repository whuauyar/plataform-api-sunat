<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\License\LicenseClient;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;

/**
 * Diagnóstico de instalación de una sola pasada. Pensado para que el programador que autoaloja
 * la API resuelva solo los problemas típicos de despliegue (PHP CLI/FPM, extensiones, permisos,
 * BD, colas, certificados, conectividad SUNAT, licencia) sin escalar soporte.
 *
 *   php artisan kodevo:doctor            # diagnóstico completo
 *   php artisan kodevo:doctor --tenant=5 # limita la revisión de certificados a una empresa
 *
 * Solo lee/verifica; no cambia estado. Sale con código 1 si hay algún FALLO (para CI/healthchecks).
 */
class KodevoDoctor extends Command
{
    protected $signature = 'kodevo:doctor {--tenant= : Revisar certificados solo de este tenant} {--skip-sunat : Omitir el ping a SUNAT}';

    protected $description = 'Diagnóstico de la instalación: PHP, extensiones, permisos, BD, colas, certificados, SUNAT y licencia.';

    private const EXT_REQUERIDAS = [
        'openssl', 'soap', 'curl', 'mbstring', 'dom', 'xml', 'simplexml',
        'zip', 'bcmath', 'intl', 'pdo_mysql', 'fileinfo', 'gd', 'json',
    ];

    private int $fallos = 0;

    private int $avisos = 0;

    public function handle(LicenseClient $license): int
    {
        $this->line('');
        $this->line('  <options=bold>kodevo:doctor</> — diagnóstico de la instalación');
        $this->line('  '.now()->format('Y-m-d H:i:s').' · PHP '.PHP_VERSION.' (CLI)');
        $this->line('');

        $this->seccion('Entorno PHP');
        $this->chequear('Versión de PHP (CLI) ≥ 8.2', function () {
            return version_compare(PHP_VERSION, '8.2.0', '>=')
                ? [true, PHP_VERSION, null]
                : [false, PHP_VERSION, 'Actualiza PHP a 8.2 o superior.'];
        });
        $this->chequear('PHP-FPM usa la misma versión que CLI', function () {
            // No es introspectable 100% desde CLI; se avisa para que el programador lo verifique.
            return [null, 'CLI: '.PHP_VERSION, 'Verifica que el pool PHP-FPM de Nginx use la misma versión: `php-fpm -v`. '
                .'Un FPM distinto al CLI causa errores que no aparecen en la consola.'];
        });
        $faltantes = array_values(array_filter(self::EXT_REQUERIDAS, fn ($e) => ! extension_loaded($e)));
        $this->chequear('Extensiones PHP requeridas', function () use ($faltantes) {
            return empty($faltantes)
                ? [true, count(self::EXT_REQUERIDAS).' presentes', null]
                : [false, 'faltan: '.implode(', ', $faltantes), 'Instala/activa las extensiones faltantes (p. ej. `apt install php8.2-'.($faltantes[0] ?? 'ext').'`) y reinicia PHP-FPM.'];
        });

        $this->seccion('Sistema de archivos');
        foreach ([
            'storage/' => storage_path(),
            'storage/logs' => storage_path('logs'),
            'storage/framework' => storage_path('framework'),
            'bootstrap/cache' => base_path('bootstrap/cache'),
        ] as $etiqueta => $ruta) {
            $this->chequear("Permiso de escritura: {$etiqueta}", function () use ($ruta) {
                return is_writable($ruta)
                    ? [true, 'escribible', null]
                    : [false, 'no escribible', "Ajusta dueño/permisos: `chown -R www-data:www-data {$ruta}` y `chmod -R ug+rwx`. "
                        .'El usuario de despliegue y www-data deben poder escribir.'];
            });
        }

        $this->seccion('Base de datos');
        $this->chequear('Conexión a la base de datos', function () {
            try {
                DB::connection()->getPdo();

                return [true, DB::connection()->getDatabaseName(), null];
            } catch (\Throwable $e) {
                return [false, 'sin conexión', 'Revisa credenciales DB_* en .env y que MySQL esté arriba.'];
            }
        });
        $this->chequear('Migraciones aplicadas', function () {
            try {
                $tablasClave = ['invoices', 'boletas', 'series', 'tenants', 'idempotency_keys', 'purchase_settlements'];
                $faltan = array_values(array_filter($tablasClave, fn ($t) => ! Schema::hasTable($t)));

                return empty($faltan)
                    ? [true, 'esquema al día', null]
                    : [false, 'faltan tablas: '.implode(', ', $faltan), 'Ejecuta `php artisan migrate --force`.'];
            } catch (\Throwable $e) {
                return [null, 'no verificable (sin BD)', 'Resuelve primero la conexión a la BD.'];
            }
        });

        $this->seccion('Caché');
        $this->chequear('Lectura/escritura de caché ('.config('cache.default').')', function () {
            try {
                $k = 'kodevo:doctor:probe';
                Cache::put($k, 'ok', 5);
                $ok = Cache::get($k) === 'ok';
                Cache::forget($k);

                return $ok ? [true, 'funciona', null] : [false, 'no persiste', 'Revisa CACHE_STORE y permisos del store.'];
            } catch (\Throwable $e) {
                return [false, 'error', 'Revisa la configuración de caché.'];
            }
        });

        $this->seccion('Colas y trabajos');
        $this->chequear('Driver de colas', function () {
            $c = config('queue.default');

            return [true, $c, $c === 'sync' ? 'En producción NO uses `sync` (bloquea la emisión); usa `database` o `redis`.' : null];
        });
        $this->chequear('Trabajos fallidos', function () {
            try {
                $n = DB::table('failed_jobs')->count();

                return $n === 0 ? [true, '0', null] : [null, "{$n} fallidos", 'Revisa con `php artisan queue:failed`; reintenta `queue:retry all` o purga `queue:prune-failed`.'];
            } catch (\Throwable $e) {
                return [null, 'tabla failed_jobs no disponible', null];
            }
        });
        $this->chequear('Backlog de la cola', function () {
            try {
                $n = DB::table('jobs')->count();

                return $n < 500 ? [true, "{$n} en cola", null] : [null, "{$n} en cola (alto)", 'Verifica que los workers estén procesando (ver abajo).'];
            } catch (\Throwable $e) {
                return [null, 'tabla jobs no disponible', null];
            }
        });
        $this->chequear('Workers y scheduler (verificación manual)', function () {
            return [null, 'no auto-detectable', 'Confirma workers con `supervisorctl status` (o `ps aux | grep queue:work`) y que consuman las colas `sunat`, `webhooks` y `default`. '
                .'Tras cada despliegue o cambio de .env: `php artisan queue:restart`. Scheduler: cron `* * * * * php artisan schedule:run`.'];
        });

        $this->seccion('Certificados digitales por empresa');
        $this->chequear('Certificados pueden firmar', function () {
            try {
                $params = [];
                if ($this->option('tenant')) {
                    $params['--tenant'] = $this->option('tenant');
                }
                $code = Artisan::call('certificados:verificar', $params);

                return $code === 0
                    ? [true, 'todos OK', null]
                    : [false, 'hay certificados con problemas', 'Ejecuta `php artisan certificados:verificar` para el detalle; recarga el .pfx/.p12 (no el .cer) de la(s) empresa(s) afectada(s).'];
            } catch (\Throwable $e) {
                return [null, 'no verificable', 'Ejecuta `php artisan certificados:verificar` manualmente.'];
            }
        });

        if (! $this->option('skip-sunat')) {
            $this->seccion('Conectividad SUNAT');
            foreach (['beta' => 'facturacion.sunat.beta.fe', 'production' => 'facturacion.sunat.production.fe'] as $entorno => $cfg) {
                $url = config($cfg);
                $this->chequear("SUNAT {$entorno} alcanzable", function () use ($url) {
                    if (! $url) {
                        return [null, 'URL no configurada', null];
                    }
                    try {
                        // Cualquier respuesta HTTP (incluso 4xx/5xx) prueba DNS+TCP+TLS OK.
                        Http::timeout(8)->connectTimeout(6)->get($url);

                        return [true, 'responde', null];
                    } catch (\Illuminate\Http\Client\ConnectionException $e) {
                        return [false, 'sin conexión', 'El servidor no alcanza a SUNAT. Revisa salida a Internet/firewall/DNS hacia e-beta/e-factura.sunat.gob.pe.'];
                    } catch (\Throwable $e) {
                        return [true, 'responde (con error HTTP, pero alcanzable)', null];
                    }
                });
            }
        }

        $this->seccion('Licencia');
        $this->chequear('Licencia activa', function () use ($license) {
            try {
                $r = $license->check();

                return $r->allowed
                    ? [true, 'válida', null]
                    : [false, 'no válida / no activada', 'Actívala con `php artisan license:activate` o revisa `php artisan license:status`.'];
            } catch (\Throwable $e) {
                return [null, 'no verificable', 'Ejecuta `php artisan license:status`.'];
            }
        });

        $this->line('');
        if ($this->fallos > 0) {
            $this->error("  ✖ {$this->fallos} fallo(s) y {$this->avisos} aviso(s). Corrige los fallos antes de operar.");

            return self::FAILURE;
        }
        if ($this->avisos > 0) {
            $this->warn("  ⚠ Sin fallos, pero {$this->avisos} aviso(s) a revisar.");

            return self::SUCCESS;
        }
        $this->info('  ✔ Todo en orden. La instalación está lista para operar.');

        return self::SUCCESS;
    }

    private function seccion(string $titulo): void
    {
        $this->line('');
        $this->line("  <fg=cyan;options=bold>{$titulo}</>");
    }

    /** @param  callable():array{0:?bool,1:string,2:?string}  $fn  [estado(true=OK/false=FALLO/null=AVISO), detalle, cómo-solucionar] */
    private function chequear(string $etiqueta, callable $fn): void
    {
        [$estado, $detalle, $fix] = $fn();
        if ($estado === true) {
            $icono = '<fg=green>✔</>';
        } elseif ($estado === false) {
            $icono = '<fg=red>✖</>';
            $this->fallos++;
        } else {
            $icono = '<fg=yellow>⚠</>';
            $this->avisos++;
        }
        $this->line("  {$icono} ".str_pad($etiqueta, 42).' <fg=gray>'.$detalle.'</>');
        if ($fix && $estado !== true) {
            $this->line('      <fg=gray>↳ '.$fix.'</>');
        }
    }
}
