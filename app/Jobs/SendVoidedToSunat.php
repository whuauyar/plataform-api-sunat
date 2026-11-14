<?php

namespace App\Jobs;

use App\Models\Tenant;
use App\Models\VoidedDocument;
use App\Services\Greenter\GreenterService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;

class SendVoidedToSunat implements ShouldQueue
{
    use Queueable;

    public int $tries = 10;

    public array $backoff = [15, 30, 60, 60, 120, 120, 300, 300, 600, 600];

    public function __construct(
        private int $voidedId
    ) {
        $this->onQueue('sunat');
    }

    public function middleware(): array
    {
        // Serializa los envíos de la MISMA baja (ver SendRetentionToSunat).
        return [
            (new WithoutOverlapping('sunat-send:voided:'.$this->voidedId))
                ->dontRelease()
                ->expireAfter(180),
        ];
    }

    public function handle(): void
    {
        $voided = VoidedDocument::findOrFail($this->voidedId);

        // Idempotencia: una baja en estado final NO se reenvía (ver SendRetentionToSunat).
        if (in_array($voided->sunat_status, ['aceptado', 'anulado'], true)) {
            return;
        }

        $tenant = Tenant::findOrFail($voided->tenant_id);

        $service = new GreenterService($tenant);

        $data = [
            'correlativo' => $voided->correlativo,
            'fecha_generacion' => $voided->fecha_generacion->format('Y-m-d'),
            'fecha_comunicacion' => $voided->fecha_comunicacion->format('Y-m-d'),
            'detalles' => $voided->detalles,
        ];

        try {
            $voidedDoc = $service->buildVoided($data);
            $result = $service->send($voidedDoc);
        } catch (\SoapFault $e) {
            $this->handleRetryableError($voided, $tenant, $e->getMessage());

            return;
        } catch (\Greenter\XMLSecLibs\Exception\XmlSignException $e) {
            $this->markAsRejected($voided, $tenant, 'CERT_ERROR', 'Error de certificado: '.$e->getMessage());

            return;
        }

        // Guardar XML
        if (! empty($result['xml'])) {
            $xmlPath = "{$tenant->ruc}/0000/{$data['fecha_comunicacion']}/xml/{$voided->identifier}.xml";
            \App\Support\Storage\Comprobantes::put($xmlPath, $result['xml']);
            $voided->update(['xml_path' => $xmlPath]);
        }

        if ($result['success'] && ! empty($result['ticket'])) {
            $voided->update([
                'ticket' => $result['ticket'],
                'sunat_status' => 'enviado',
            ]);

            CheckVoidedTicketStatus::dispatch($voided->id)
                ->delay(now()->addSeconds(15));

            if ($tenant->webhook_url) {
                NotifyWebhookJob::dispatch(VoidedDocument::class, $voided->id, 'voided.sent');
            }
        } else {
            $errorCode = $result['error_code'] ?? '';

            if ($this->isRetryableError($errorCode)) {
                $this->handleRetryableError($voided, $tenant, $result['error_message'] ?? 'Error temporal SUNAT');

                return;
            }

            $this->markAsRejected($voided, $tenant, $errorCode, $result['error_message'] ?? null);
        }
    }

    private function isRetryableError(string $errorCode): bool
    {
        return in_array($errorCode, ['0', '100', '109', '500', '1033', '2800'], true);
    }

    private function handleRetryableError(VoidedDocument $voided, Tenant $tenant, string $message): void
    {
        if ($this->attempts() >= $this->tries) {
            // TIMEOUT ≠ RECHAZO: al agotar reintentos sin respuesta, queda POR CONFIRMAR
            // (pendiente/SUNAT_TIMEOUT), NUNCA 'rechazado'. Se concilia consultando el estado/CDR;
            // reenviar a ciegas duplicaría la baja en SUNAT.
            $voided->update([
                'sunat_status' => 'pendiente',
                'sunat_code' => 'SUNAT_TIMEOUT',
                'sunat_description' => substr("Sin respuesta de SUNAT tras {$this->tries} intentos; por confirmar. {$message}", 0, 500),
            ]);

            return;
        }

        $delay = $this->backoff[$this->attempts() - 1] ?? 600;
        $this->release($delay);
    }

    private function markAsRejected(VoidedDocument $voided, Tenant $tenant, string $code, ?string $message): void
    {
        $voided->update([
            'sunat_status' => 'rechazado',
            'sunat_code' => substr($code, 0, 20),
            'sunat_description' => $message ? substr($message, 0, 500) : null,
        ]);

        if ($tenant->webhook_url) {
            NotifyWebhookJob::dispatch(VoidedDocument::class, $voided->id, 'voided.rejected');
        }
    }
}
