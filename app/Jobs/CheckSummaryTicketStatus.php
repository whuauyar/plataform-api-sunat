<?php

namespace App\Jobs;

use App\Models\Boleta;
use App\Models\Summary;
use App\Models\Tenant;
use App\Services\Greenter\GreenterService;
use App\Services\Pdf\PdfGeneratorService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class CheckSummaryTicketStatus implements ShouldQueue
{
    use Queueable;

    public int $tries = 15;

    public array $backoff = [15, 30, 30, 60, 60, 60, 120, 120, 120, 300, 300, 300, 600, 600, 600];

    public function __construct(
        private int $summaryId
    ) {}

    public function handle(): void
    {
        $summary = Summary::findOrFail($this->summaryId);

        if (! $summary->ticket) {
            return;
        }

        // Si ya tiene estado final, no hacer nada
        if (in_array($summary->sunat_status, ['aceptado', 'rechazado'])) {
            return;
        }

        $tenant = Tenant::findOrFail($summary->tenant_id);
        $service = new GreenterService($tenant);

        try {
            $result = $service->getStatus($summary->ticket);
        } catch (\SoapFault $e) {
            $this->release($this->nextBackoff());

            return;
        }

        if ($result['success']) {
            $accepted = $result['accepted'] ?? false;
            $status = $accepted ? 'aceptado' : 'rechazado';

            $updateData = [
                'sunat_status' => $status,
                'sunat_code' => $result['code'] ?? null,
                'sunat_description' => $result['description'] ?? null,
                'sunat_notes' => $result['notes'] ?? null,
            ];

            // Guardar CDR zip
            if (! empty($result['cdr_zip'])) {
                $cdrPath = str_replace('/xml/', '/cdr/', $summary->xml_path ?? '');
                $cdrPath = $cdrPath ? preg_replace('/\.xml$/', '.zip', $cdrPath) : null;

                if ($cdrPath) {
                    $cdrPath = str_replace($summary->identifier, 'R-'.$summary->identifier, $cdrPath);
                    \App\Support\Storage\Comprobantes::put($cdrPath, $result['cdr_zip']);
                    $updateData['cdr_path'] = $cdrPath;
                }
            }

            $summary->update($updateData);

            // Estado final de las boletas según el tipo de resumen y el resultado:
            //  - envío aceptado     → aceptado
            //  - envío rechazado    → rechazado
            //  - anulación aceptada → anulado
            //  - anulación rechazada→ vuelven a 'aceptado' (la boleta sigue siendo válida)
            if ($accepted) {
                $boletaStatus = $summary->tipo === 'anulacion' ? 'anulado' : 'aceptado';
            } else {
                $boletaStatus = $summary->tipo === 'anulacion' ? 'aceptado' : 'rechazado';
            }

            $boletaUpdate = ['sunat_status' => $boletaStatus];
            // No pisamos el CDR/respuesta original de la boleta cuando revertimos
            // una anulación rechazada: esa respuesta es de la anulación, no de la boleta.
            if (! ($summary->tipo === 'anulacion' && ! $accepted)) {
                $boletaUpdate['sunat_code'] = $result['code'] ?? null;
                $boletaUpdate['sunat_description'] = $result['description'] ?? null;
                $boletaUpdate['sunat_notes'] = $result['notes'] ?? null;
            }

            $boletaQuery = Boleta::whereIn('id', $summary->document_ids ?? []);
            // Un resumen de envío RECHAZADO no debe degradar una boleta que ya fue aceptada
            // (o anulada) en otro resumen: es el caso de los resúmenes duplicados que SUNAT
            // rechaza con 2282 "documento ya informado". Solo tocamos las no finalizadas.
            if ($boletaStatus === 'rechazado') {
                $boletaQuery->whereNotIn('sunat_status', ['aceptado', 'anulado']);
            }
            $boletaQuery->update($boletaUpdate);

            // Generar PDFs de las boletas aceptadas
            if ($accepted && $summary->tipo !== 'anulacion' && config('pdf.auto_generate', true)) {
                $boletas = Boleta::whereIn('id', $summary->document_ids ?? [])->get();
                foreach ($boletas as $boleta) {
                    try {
                        app(PdfGeneratorService::class)->generateAndStore($boleta, $tenant);
                    } catch (\Throwable) {
                    }
                }
            }

            if ($tenant->webhook_url) {
                NotifyWebhookJob::dispatch(Summary::class, $summary->id, 'summary.status_updated');
            }
        } else {
            $errorCode = $result['error_code'] ?? '';
            $codigoNum = is_numeric((string) $errorCode) ? (int) $errorCode : null;

            // Códigos "aún en proceso" de SUNAT → reintentar (NO es rechazo):
            //   0, 98 (0098 = el proceso por lotes aún no termina) y 187. Se normaliza
            //   el cero a la izquierda (0098 → 98). No numérico (ej. "HTTP") = fallo de
            //   red → también reintentar.
            if ($codigoNum === null || in_array($codigoNum, [0, 98, 187], true)) {
                $this->release($this->nextBackoff());

                return;
            }

            // Error definitivo (código numérico SUNAT: 1xxx, 2xxx, 4xxx)
            $summary->update([
                'sunat_status' => 'rechazado',
                'sunat_code' => $errorCode,
                'sunat_description' => $result['error_message'] ?? null,
            ]);

            // En una anulación con error definitivo, la boleta sigue siendo válida → 'aceptado'.
            // En un envío con error, la boleta queda 'rechazado'.
            if ($summary->tipo === 'anulacion') {
                Boleta::whereIn('id', $summary->document_ids ?? [])
                    ->where('sunat_status', 'anulacion_en_proceso')
                    ->update(['sunat_status' => 'aceptado']);
            } else {
                // No degradar boletas ya aceptadas/anuladas por un resumen duplicado
                // rechazado (error SUNAT 2282 "documento ya informado anteriormente").
                Boleta::whereIn('id', $summary->document_ids ?? [])
                    ->whereNotIn('sunat_status', ['aceptado', 'anulado'])
                    ->update([
                        'sunat_status' => 'rechazado',
                        'sunat_code' => $errorCode,
                        'sunat_description' => $result['error_message'] ?? null,
                    ]);
            }

            if ($tenant->webhook_url) {
                NotifyWebhookJob::dispatch(Summary::class, $summary->id, 'summary.status_updated');
            }
        }
    }

    private function nextBackoff(): int
    {
        $attempt = $this->attempts();

        return $this->backoff[$attempt - 1] ?? 600;
    }
}
