<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Boleta;
use App\Models\CreditNote;
use App\Models\DebitNote;
use App\Models\DispatchGuide;
use App\Models\Invoice;
use App\Models\Perception;
use App\Models\Retention;
use App\Models\Summary;
use App\Models\Tenant;
use App\Models\VoidedDocument;
use App\Services\Settings\SettingsService;
use Illuminate\Console\Command;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

/**
 * Sube archivos a un disco S3/MinIO preservando la ruta relativa, leyendo del
 * origen (por defecto local privado + público legacy). Idempotente: salta lo que
 * ya está en destino.
 *
 * Por defecto migra COMPROBANTES (XML/CDR/PDF). Con flags, además:
 *   --incluir-certificados : certificados .pem (disco local → destino privado)
 *   --incluir-logos        : logos/favicones de empresa y plataforma
 *                            (disco public → destino, con visibilidad pública)
 *
 * Orden recomendado:
 *   1) Configurar el disco S3 en el .env (credenciales AWS y endpoint).
 *   2) php artisan comprobantes:migrar-a-s3 --dry-run   (revisar)
 *   3) php artisan comprobantes:migrar-a-s3             (copiar)
 *   4) Poner COMPROBANTES_DISK=s3 en el .env.
 *   5) (opcional) re-correr con --borrar-origen para limpiar local/público.
 */
class MigrarComprobantesAS3 extends Command
{
    protected $signature = 'comprobantes:migrar-a-s3
        {--destino=s3 : Disco destino (de config/filesystems.php)}
        {--destino-publico= : Disco destino para logos (default: el mismo de --destino)}
        {--fuentes=local,public : Discos de origen a revisar, en orden (coma)}
        {--incluir-certificados : También migra los certificados .pem}
        {--incluir-logos : También migra logos/favicones (empresa y plataforma)}
        {--dry-run : Solo informa, sin copiar}
        {--borrar-origen : Borra del origen tras copiar (por defecto se conserva)}';

    protected $description = 'Sube XML/CDR/PDF (y opcional certificados/logos) de comprobantes a S3/MinIO.';

    private bool $dry = false;
    private bool $borrar = false;
    private int $copiados = 0;
    private int $saltados = 0;
    private int $faltantes = 0;
    private int $errores = 0;

    public function handle(): int
    {
        $this->dry = (bool) $this->option('dry-run');
        $this->borrar = (bool) $this->option('borrar-origen');
        $destName = (string) $this->option('destino');
        $destPubName = (string) ($this->option('destino-publico') ?: $destName);
        $fuentes = array_values(array_filter(array_map('trim', explode(',', (string) $this->option('fuentes')))));

        try {
            $dest = Storage::disk($destName);
            $destPub = Storage::disk($destPubName);
        } catch (\Throwable $e) {
            $this->error('Disco destino no configurado: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->migrarDocumentos($dest, $fuentes);

        if ($this->option('incluir-certificados')) {
            $this->migrarCertificados($dest);
        }

        if ($this->option('incluir-logos')) {
            $this->migrarLogos($destPub);
        }

        $this->info(($this->dry ? '[dry-run] ' : '')
            ."Copiados {$this->copiados} · ya existían {$this->saltados} · sin archivo {$this->faltantes} · errores {$this->errores}");

        return $this->errores > 0 ? self::FAILURE : self::SUCCESS;
    }

    /** Copia $path desde el primer disco de $fuentes que lo tenga, a $dest. */
    private function copiar(string $path, array $fuentes, Filesystem $dest, ?string $visibility = null): void
    {
        if ($path === '' || $dest->exists($path)) {
            $path !== '' && $this->saltados++;

            return;
        }

        $origen = null;
        foreach ($fuentes as $fn) {
            if (Storage::disk($fn)->exists($path)) {
                $origen = Storage::disk($fn);
                break;
            }
        }
        if (! $origen) {
            $this->faltantes++;

            return;
        }

        if ($this->dry) {
            $this->line("SUBIRÍA: {$path}");
            $this->copiados++;

            return;
        }

        try {
            $visibility
                ? $dest->put($path, $origen->get($path), $visibility)
                : $dest->put($path, $origen->get($path));
            if ($this->borrar) {
                $origen->delete($path);
            }
            $this->copiados++;
        } catch (\Throwable $e) {
            $this->warn("Error con {$path}: ".$e->getMessage());
            $this->errores++;
        }
    }

    private function migrarDocumentos(Filesystem $dest, array $fuentes): void
    {
        $modelos = [
            Invoice::class, Boleta::class, CreditNote::class, DebitNote::class,
            DispatchGuide::class, Perception::class, Retention::class,
            Summary::class, VoidedDocument::class,
        ];
        $campos = ['xml_path', 'cdr_path', 'pdf_path'];

        foreach ($modelos as $modelo) {
            $tabla = (new $modelo)->getTable();
            $cols = array_values(array_filter($campos, fn ($c) => Schema::hasColumn($tabla, $c)));
            if (empty($cols)) {
                continue;
            }
            $modelo::query()->select(array_merge(['id'], $cols))
                ->chunkById(300, function ($docs) use ($cols, $dest, $fuentes) {
                    foreach ($docs as $doc) {
                        foreach ($cols as $col) {
                            $this->copiar((string) ($doc->{$col} ?? ''), $fuentes, $dest);
                        }
                    }
                });
        }
    }

    private function migrarCertificados(Filesystem $dest): void
    {
        // Los certificados viven en el disco privado 'local'.
        Tenant::query()->select(['id', 'ruc', 'certificate_path'])
            ->chunkById(300, function ($tenants) use ($dest) {
                foreach ($tenants as $t) {
                    $paths = array_unique(array_filter([
                        // Solo rutas RELATIVAS (una ruta absoluta legacy no se puede
                        // copiar por disco; el fallback por disco cubre el caso).
                        (is_string($t->certificate_path) && $t->certificate_path !== ''
                            && ! str_starts_with($t->certificate_path, '/')
                            && ! str_contains($t->certificate_path, ':')) ? $t->certificate_path : null,
                        'certificates/'.$t->ruc.'/cert.pem',
                        'tenants/'.$t->id.'/certs/cert.pem',
                    ]));
                    foreach ($paths as $p) {
                        $this->copiar($p, ['local'], $dest);
                    }
                }
            });
    }

    private function migrarLogos(Filesystem $destPub): void
    {
        // Logos/favicones de empresa (public disk) → destino con visibilidad pública.
        Tenant::query()->select(['id', 'logo_path', 'favicon_path'])
            ->chunkById(300, function ($tenants) use ($destPub) {
                foreach ($tenants as $t) {
                    $this->copiar((string) ($t->logo_path ?? ''), ['public'], $destPub, 'public');
                    $this->copiar((string) ($t->favicon_path ?? ''), ['public'], $destPub, 'public');
                }
            });

        // Branding de la plataforma (settings globales).
        $settings = app(SettingsService::class);
        foreach ([SettingsService::APP_LOGO_PATH, SettingsService::FAVICON_PATH] as $key) {
            $this->copiar((string) $settings->get($key, ''), ['public'], $destPub, 'public');
        }
    }
}
