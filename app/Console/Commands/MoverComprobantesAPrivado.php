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
use App\Models\VoidedDocument;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Mueve los archivos de comprobantes (XML/CDR/PDF) del disco PÚBLICO (legacy,
 * storage/app/public) al disco PRIVADO (storage/app/private). Idempotente: salta
 * los que ya están en privado. Con --dry-run solo informa. Los logos/favicones
 * NO se tocan (siguen en público).
 */
class MoverComprobantesAPrivado extends Command
{
    protected $signature = 'comprobantes:mover-a-privado {--dry-run : Solo muestra lo que haría, sin mover}';

    protected $description = 'Mueve XML/CDR/PDF de comprobantes del disco público al privado.';

    public function handle(): int
    {
        $dry = (bool) $this->option('dry-run');
        $priv = Storage::disk('comprobantes');
        $pub = Storage::disk('public');

        $modelos = [
            Invoice::class, Boleta::class, CreditNote::class, DebitNote::class,
            DispatchGuide::class, Perception::class, Retention::class,
            Summary::class, VoidedDocument::class,
        ];
        $campos = ['xml_path', 'cdr_path', 'pdf_path'];

        $movidos = 0; $saltados = 0; $faltantes = 0;

        foreach ($modelos as $modelo) {
            $tabla = (new $modelo)->getTable();
            $cols = array_values(array_filter($campos, fn ($c) => \Illuminate\Support\Facades\Schema::hasColumn($tabla, $c)));
            if (empty($cols)) {
                continue;
            }

            $modelo::query()->select(array_merge(['id'], $cols))->chunkById(300, function ($docs) use ($cols, $priv, $pub, $dry, &$movidos, &$saltados, &$faltantes) {
                foreach ($docs as $doc) {
                    foreach ($cols as $col) {
                        $path = $doc->{$col};
                        if (empty($path)) {
                            continue;
                        }
                        if ($priv->exists($path)) {
                            $saltados++;
                            continue;
                        }
                        if (! $pub->exists($path)) {
                            $faltantes++;
                            continue;
                        }
                        if ($dry) {
                            $this->line("MOVERÍA: {$path}");
                            $movidos++;
                            continue;
                        }
                        $priv->put($path, $pub->get($path));
                        $pub->delete($path);
                        $movidos++;
                    }
                }
            });
        }

        $this->info(($dry ? '[dry-run] ' : '')."Movidos: {$movidos} · Ya privados: {$saltados} · Sin archivo: {$faltantes}");

        return self::SUCCESS;
    }
}
