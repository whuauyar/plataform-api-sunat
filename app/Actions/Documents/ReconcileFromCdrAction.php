<?php

declare(strict_types=1);

namespace App\Actions\Documents;

use App\Models\Tenant;
use App\Support\Storage\Comprobantes;
use Illuminate\Database\Eloquent\Model;

/**
 * Concilia un comprobante que quedó en pendiente/SUNAT_TIMEOUT: CONSULTA su CDR en
 * SUNAT (nunca reenvía) y resuelve el estado real. Mismo criterio que recoverFromCdr
 * de SendDocumentToSunat (factura/boleta), reutilizable para retención/percepción/
 * liquidación, que también tienen CDR individual.
 */
class ReconcileFromCdrAction
{
    public function __construct(private ConsultCdrAction $consult) {}

    /**
     * @return 'aceptado'|'rechazado'|'sin_cambio'
     */
    public function execute(Model $doc, Tenant $tenant, string $tipo, string $cdrPath): string
    {
        try {
            $res = $this->consult->execute($tenant, $tipo, (string) $doc->serie, (int) $doc->correlativo);
        } catch (\Throwable) {
            return 'sin_cambio';
        }

        // Sin respuesta de SUNAT (red / aún no registrado) → no se puede resolver todavía.
        if (empty($res['success'])) {
            return 'sin_cambio';
        }

        $code = (string) ($res['code'] ?? '');
        $aceptado = ! empty($res['accepted']) || $code === '0' || (ctype_digit($code) && (int) $code >= 4000);

        if ($aceptado) {
            $update = [
                'sunat_status' => 'aceptado',
                'sunat_code' => $code !== '' ? substr($code, 0, 20) : '0',
                'sunat_description' => isset($res['description']) ? substr((string) $res['description'], 0, 500) : null,
                'sunat_notes' => $res['notes'] ?? null,
            ];

            if (! empty($res['cdr_zip'])) {
                Comprobantes::put($cdrPath, $res['cdr_zip']);
                $update['cdr_path'] = $cdrPath;
            }
            if ($doc->isFillable('cdr_fecha')) {
                $update['cdr_fecha'] = now();
            }
            if ($doc->isFillable('sent_at')) {
                $update['sent_at'] = now();
            }

            $doc->update($update);

            return 'aceptado';
        }

        // CDR con código de rechazo real (1000–3999).
        if (ctype_digit($code) && (int) $code >= 1000) {
            $doc->update([
                'sunat_status' => 'rechazado',
                'sunat_code' => substr($code, 0, 20),
                'sunat_description' => isset($res['description']) ? substr((string) $res['description'], 0, 500) : null,
            ]);

            return 'rechazado';
        }

        return 'sin_cambio';
    }
}
