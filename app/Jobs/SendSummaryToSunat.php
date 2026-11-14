<?php

namespace App\Jobs;

use App\Models\Boleta;
use App\Models\Summary;
use App\Models\Tenant;
use App\Services\Greenter\GreenterService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;

class SendSummaryToSunat implements ShouldQueue
{
    use Queueable;

    public int $tries = 10;

    public array $backoff = [15, 30, 60, 60, 120, 120, 300, 300, 600, 600];

    public function __construct(
        private int $summaryId
    ) {
        $this->onQueue('sunat');
    }

    public function middleware(): array
    {
        // Serializa los envíos del MISMO resumen (ver SendRetentionToSunat).
        return [
            (new WithoutOverlapping('sunat-send:summary:'.$this->summaryId))
                ->dontRelease()
                ->expireAfter(180),
        ];
    }

    public function handle(): void
    {
        $summary = Summary::findOrFail($this->summaryId);

        // Idempotencia: un resumen en estado final NO se reenvía (ver SendRetentionToSunat).
        if (in_array($summary->sunat_status, ['aceptado', 'anulado'], true)) {
            return;
        }

        $tenant = Tenant::findOrFail($summary->tenant_id);

        $service = new GreenterService($tenant);

        $boletas = Boleta::whereIn('id', $summary->document_ids ?? [])->get();
        $estado = $summary->tipo === 'anulacion' ? '3' : '1';

        $data = [
            'correlativo' => $summary->correlativo,
            'fecha_referencia' => $summary->fecha_referencia->format('Y-m-d'),
            'fecha_envio' => $summary->fecha_envio->format('Y-m-d'),
            'fecha_resumen' => $summary->fecha_referencia->format('Y-m-d'),
            'detalles' => $boletas->map(fn (Boleta $doc) => [
                'tipo_documento' => '03',
                'serie_nro' => $doc->serie.'-'.$doc->correlativo,
                'estado' => $estado,
                'cliente_tipo_doc' => $doc->client_tipo_doc,
                'cliente_num_doc' => $doc->client_num_doc,
                'total' => (float) $doc->mto_imp_venta,
                'mto_oper_gravadas' => (float) $doc->mto_oper_gravadas,
                'mto_oper_exoneradas' => (float) $doc->mto_oper_exoneradas,
                'mto_oper_inafectas' => (float) $doc->mto_oper_inafectas,
                'mto_oper_gratuitas' => (float) $doc->mto_oper_gratuitas,
                'mto_igv' => (float) $doc->mto_igv,
            ])->toArray(),
        ];

        try {
            $greenterSummary = $service->buildSummary($data);
            $result = $service->send($greenterSummary);
        } catch (\SoapFault $e) {
            // Guardar XML aunque falle el envío SOAP (fue generado y firmado antes del error)
            $this->saveXmlIfAvailable($service, $summary, $tenant, $data);
            $this->handleRetryableError($summary, $tenant, $e->getMessage());

            return;
        } catch (\Greenter\XMLSecLibs\Exception\XmlSignException $e) {
            $this->markAsRejected($summary, $tenant, 'CERT_ERROR', 'Error de certificado: '.$e->getMessage());

            return;
        }

        // Guardar XML
        if (! empty($result['xml'])) {
            $fechaId = str_replace('-', '', $data['fecha_envio']);
            $identifier = "RC-{$fechaId}-{$data['correlativo']}";
            $xmlPath = "{$tenant->ruc}/0000/{$data['fecha_referencia']}/xml/{$identifier}.xml";
            \App\Support\Storage\Comprobantes::put($xmlPath, $result['xml']);

            $summary->update([
                'identifier' => $identifier,
                'xml_path' => $xmlPath,
            ]);
        }

        if ($result['success'] && ! empty($result['ticket'])) {
            $summary->update([
                'ticket' => $result['ticket'],
                'sunat_status' => 'enviado',
            ]);

            $newStatus = $summary->tipo === 'anulacion' ? 'anulado' : 'enviado';
            Boleta::whereIn('id', $summary->document_ids ?? [])
                ->update([
                    'sunat_status' => $newStatus,
                    'ticket' => $result['ticket'],
                    'sent_at' => now(),
                ]);

            CheckSummaryTicketStatus::dispatch($summary->id)
                ->delay(now()->addSeconds(15));

            if ($tenant->webhook_url) {
                NotifyWebhookJob::dispatch(Summary::class, $summary->id, 'summary.sent');
            }
        } else {
            $errorCode = $result['error_code'] ?? '';

            if ($this->isRetryableError($errorCode)) {
                $this->handleRetryableError($summary, $tenant, $result['error_message'] ?? 'Error temporal SUNAT');

                return;
            }

            $this->markAsRejected($summary, $tenant, $errorCode, $result['error_message'] ?? null);
        }
    }

    private function saveXmlIfAvailable(
        \App\Services\Greenter\GreenterService $service,
        Summary $summary,
        Tenant $tenant,
        array $data
    ): void {
        $xml = $service->getLastXml();
        if (empty($xml) || $summary->xml_path) {
            return;
        }

        $fechaId = str_replace('-', '', $data['fecha_envio']);
        $identifier = "RC-{$fechaId}-{$data['correlativo']}";
        $xmlPath = "{$tenant->ruc}/0000/{$data['fecha_referencia']}/xml/{$identifier}.xml";

        \App\Support\Storage\Comprobantes::put($xmlPath, $xml);
        $summary->update(['xml_path' => $xmlPath]);
    }

    private function isRetryableError(string $errorCode): bool
    {
        return in_array($errorCode, ['0', '100', '109', '500', '1033', '2800'], true);
    }

    private function handleRetryableError(Summary $summary, Tenant $tenant, string $message): void
    {
        if ($this->attempts() >= $this->tries) {
            // TIMEOUT ≠ RECHAZO: al agotar reintentos sin respuesta, queda POR CONFIRMAR
            // (pendiente/SUNAT_TIMEOUT), NUNCA 'rechazado'. Se concilia consultando el estado/CDR;
            // reenviar a ciegas duplicaría el resumen en SUNAT.
            $summary->update([
                'sunat_status' => 'pendiente',
                'sunat_code' => 'SUNAT_TIMEOUT',
                'sunat_description' => substr("Sin respuesta de SUNAT tras {$this->tries} intentos; por confirmar. {$message}", 0, 500),
            ]);

            return;
        }

        $delay = $this->backoff[$this->attempts() - 1] ?? 600;
        $this->release($delay);
    }

    private function markAsRejected(Summary $summary, Tenant $tenant, string $code, ?string $message): void
    {
        $summary->update([
            'sunat_status' => 'rechazado',
            'sunat_code' => substr($code, 0, 20),
            'sunat_description' => $message ? substr($message, 0, 500) : null,
        ]);

        if ($tenant->webhook_url) {
            NotifyWebhookJob::dispatch(Summary::class, $summary->id, 'summary.rejected');
        }
    }
}
