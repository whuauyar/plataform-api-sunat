<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\CheckVoidedTicketStatus;
use App\Models\VoidedDocument;
use Illuminate\Console\Command;

/**
 * Re-consulta las comunicaciones de baja que quedaron 'rechazado' por el bug del
 * código 98/0098 ("el proceso aún no ha terminado", que se trataba como rechazo).
 * Las devuelve a 'enviado' y reencola la consulta del ticket; con el fix ya
 * aplicado, el job fija el estado real (normalmente 'aceptado') y anula el
 * documento original. Por defecto SOLO toca las de código 98 (seguro).
 */
class ReconsultarBajasEnProceso extends Command
{
    protected $signature = 'bajas:reconsultar
        {--tenant= : limitar a una empresa (tenant_id)}
        {--id= : reconsultar solo esta baja (voided id)}
        {--todas : incluir cualquier baja rechazada con ticket, no solo código 98}
        {--dry-run : mostrar qué se reconsultaría sin modificar}';

    protected $description = 'Re-consulta bajas rechazadas por el código 98 (reencola la consulta del ticket).';

    public function handle(): int
    {
        $dry = (bool) $this->option('dry-run');

        $bajas = VoidedDocument::query()
            ->where('sunat_status', 'rechazado')
            ->whereNotNull('ticket')
            ->when($this->option('tenant'), fn ($q) => $q->where('tenant_id', (int) $this->option('tenant')))
            ->when($this->option('id'), fn ($q) => $q->whereKey((int) $this->option('id')))
            ->when(! $this->option('todas'), fn ($q) => $q->whereIn('sunat_code', ['98', '098', '0098']))
            ->get();

        if ($bajas->isEmpty()) {
            $this->info('No hay bajas rechazadas por código 98 para reconsultar.');

            return self::SUCCESS;
        }

        $filas = [];
        foreach ($bajas as $baja) {
            $filas[] = [$baja->id, $baja->tenant_id, $baja->identifier, $baja->sunat_code, $dry ? '(dry-run)' : 'reencolada'];

            if (! $dry) {
                $baja->update(['sunat_status' => 'enviado']);
                CheckVoidedTicketStatus::dispatch($baja->id);
            }
        }

        $this->table(['ID', 'Empresa', 'Identificador', 'Código', 'Acción'], $filas);
        $this->{$dry ? 'warn' : 'info'}(
            $dry ? count($filas).' baja(s) se reconsultarían (dry-run).'
                 : count($filas).' baja(s) reencoladas. El worker fijará el estado real al consultar el ticket.'
        );

        return self::SUCCESS;
    }
}
