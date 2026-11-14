<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Tenant;
use Illuminate\Console\Command;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

/**
 * Audita las empresas cuyas credenciales cifradas (api_secret, sol_pass, certificado, etc.)
 * NO se pueden descifrar con la APP_KEY actual: valores heredados/en texto plano o cifrados
 * con otra clave. Esas empresas dan 500 "The payload is invalid" al autenticar o emitir, y
 * necesitan que se regeneren/reingresen sus credenciales desde el panel.
 *
 * Solo lectura: no modifica nada. Los campos cifrados se leen dinámicamente de los casts
 * del modelo, así el comando no se desactualiza si cambian.
 */
class AuditarCredencialesIlegibles extends Command
{
    protected $signature = 'empresas:auditar-credenciales
        {--tenant= : limitar a una empresa (tenant_id)}
        {--fix : reescribe los campos ilegibles como cifrado-vacío (vía DB) para poder reingresarlos desde el panel}';

    protected $description = 'Lista (o repara con --fix) las empresas con credenciales cifradas ilegibles (dan "payload is invalid" al autenticar/emitir).';

    public function handle(): int
    {
        // Campos con cast 'encrypted' en el modelo Tenant (p. ej. api_secret, sol_pass,
        // certificate_password, client_secret, sol_user, consulta_token).
        $campos = collect((new Tenant)->getCasts())
            ->filter(fn ($cast) => $cast === 'encrypted')
            ->keys();

        if ($campos->isEmpty()) {
            $this->info('El modelo Tenant no tiene campos cifrados.');

            return self::SUCCESS;
        }

        $fix = (bool) $this->option('fix');
        $filas = [];
        $afectadas = 0;

        Tenant::query()
            ->when($this->option('tenant'), fn ($q) => $q->where('id', (int) $this->option('tenant')))
            ->chunkById(100, function ($tenants) use ($campos, $fix, &$filas, &$afectadas) {
                foreach ($tenants as $tenant) {
                    $rotos = [];
                    foreach ($campos as $campo) {
                        // Un valor NULL es válido (no está configurado); solo nos interesa
                        // cuando hay un valor crudo que no se puede descifrar.
                        if ($tenant->getRawOriginal($campo) === null) {
                            continue;
                        }
                        try {
                            $tenant->{$campo};
                        } catch (DecryptException) {
                            $rotos[] = $campo;
                        }
                    }

                    if ($rotos !== []) {
                        $afectadas++;

                        if ($fix) {
                            // Reescribimos por query builder (sin pasar por el cast ni el
                            // dirty-check de Eloquent, que descifraría el valor corrupto) con
                            // un cifrado-vacío válido: deja el campo legible ('') para que la
                            // empresa se pueda editar y reingresar la credencial desde el panel.
                            DB::table('tenants')->where('id', $tenant->id)->update(
                                array_fill_keys($rotos, Crypt::encryptString(''))
                            );
                        }

                        $filas[] = [
                            $tenant->id,
                            $tenant->ruc ?? '—',
                            mb_substr((string) ($tenant->razon_social ?? '—'), 0, 40),
                            implode(', ', $rotos),
                            $fix ? 'reparado (vacío)' : 'pendiente',
                        ];
                    }
                }
            });

        if ($filas === []) {
            $this->info('Todas las empresas tienen sus credenciales cifradas legibles. Nada que reparar.');

            return self::SUCCESS;
        }

        $this->table(['Empresa', 'RUC', 'Razón social', 'Campos ilegibles', 'Estado'], $filas);

        if ($fix) {
            $this->info("{$afectadas} empresa(s) reparadas. Ahora regenera el API Secret (panel → Credenciales → Regenerar) y reingresa Clave SOL y certificado de cada una.");
        } else {
            $this->warn("{$afectadas} empresa(s) con credenciales ilegibles. Corre de nuevo con --fix para dejarlas editables, o regenera/reingresa desde el panel.");
        }

        return self::SUCCESS;
    }
}
