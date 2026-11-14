<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\AuditLog;
use Illuminate\Console\Command;

/**
 * Lista la bitácora de acciones sensibles (S5). Útil para revisar incidentes:
 * quién anuló, regeneró credenciales o cambió configuración, y cuándo.
 */
class VerAuditoria extends Command
{
    protected $signature = 'ops:auditoria
        {--tenant= : filtrar por empresa (tenant_id)}
        {--accion= : filtrar por acción (coincidencia parcial)}
        {--limit=30 : cuántos registros mostrar}';

    protected $description = 'Muestra las últimas acciones sensibles registradas.';

    public function handle(): int
    {
        $registros = AuditLog::query()
            ->when($this->option('tenant'), fn ($q) => $q->where('tenant_id', (int) $this->option('tenant')))
            ->when($this->option('accion'), fn ($q) => $q->where('accion', 'like', '%'.$this->option('accion').'%'))
            ->latest('created_at')
            ->limit((int) $this->option('limit'))
            ->get();

        if ($registros->isEmpty()) {
            $this->info('Sin registros de auditoría para el filtro indicado.');

            return self::SUCCESS;
        }

        $this->table(
            ['Fecha', 'Empresa', 'Actor', 'Acción', 'Detalle'],
            $registros->map(fn (AuditLog $r) => [
                $r->created_at?->format('Y-m-d H:i'),
                $r->tenant_id ?? '—',
                mb_strimwidth((string) $r->actor_etiqueta, 0, 28, '…') ?: $r->actor_tipo,
                $r->accion,
                mb_strimwidth((string) $r->descripcion, 0, 40, '…'),
            ])->all(),
        );

        return self::SUCCESS;
    }
}
