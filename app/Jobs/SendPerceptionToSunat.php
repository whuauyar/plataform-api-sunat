<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Perception;
use App\Models\Tenant;
use App\Services\Greenter\Builders\PerceptionBuilder;
use App\Services\Greenter\GreenterService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;

class SendPerceptionToSunat implements ShouldQueue
{
    use Queueable;

    public int $tries = 10;

    public array $backoff = [15, 30, 60, 60, 120, 120, 300, 300, 600, 600];

    public function __construct(
        private int $perceptionId
    ) {
        $this->onQueue('sunat');
    }

    public function middleware(): array
    {
        // Serializa los envíos del MISMO comprobante (ver SendRetentionToSunat).
        return [
            (new WithoutOverlapping('sunat-send:perception:'.$this->perceptionId))
                ->dontRelease()
                ->expireAfter(180),
        ];
    }

    public function handle(): void
    {
        $perception = Perception::with('items')->findOrFail($this->perceptionId);

        // Idempotencia: un comprobante en estado final NO se reenvía (ver SendRetentionToSunat).
        if (in_array($perception->sunat_status, ['aceptado', 'anulado'], true)) {
            return;
        }

        $tenant = Tenant::findOrFail($perception->tenant_id);

        $service = new GreenterService($tenant);
        $builder = new PerceptionBuilder($tenant);

        $data = $this->perceptionToArray($perception);

        try {
            $greenterPerception = $builder->build($data);
            $result = $service->send($greenterPerception);
        } catch (\SoapFault $e) {
            $this->handleRetryableError($perception, $tenant, $e->getMessage());

            return;
        } catch (\Greenter\XMLSecLibs\Exception\XmlSignException $e) {
            $this->markAsRejected($perception, $tenant, 'CERT_ERROR', 'Error de certificado: '.$e->getMessage());

            return;
        }

        if ($result['success']) {
            $perception->update([
                'sunat_status' => ($result['accepted'] ?? true) ? 'aceptado' : 'rechazado',
                'sunat_code' => $result['code'] ?? null,
                'sunat_description' => $result['description'] ?? null,
                'sunat_notes' => $result['notes'] ?? null,
                'hash_cpe' => $result['hash'] ?? null,
                'sent_at' => now(),
            ]);

            if (! empty($result['xml'])) {
                $xmlPath = "{$tenant->ruc}/perceptions/{$perception->serie}-{$perception->correlativo}.xml";
                \App\Support\Storage\Comprobantes::put($xmlPath, $result['xml']);
                $perception->update(['xml_path' => $xmlPath]);
            }
            if (! empty($result['cdr_zip'])) {
                $cdrPath = "{$tenant->ruc}/perceptions/R-{$perception->serie}-{$perception->correlativo}.zip";
                \App\Support\Storage\Comprobantes::put($cdrPath, $result['cdr_zip']);
                $perception->update(['cdr_path' => $cdrPath]);
            }

            if ($tenant->webhook_url) {
                NotifyWebhookJob::dispatch(Perception::class, $perception->id, 'perception.sent');
            }
        } else {
            $errorCode = $result['error_code'] ?? '';

            if ($this->isRetryableError($errorCode)) {
                $this->handleRetryableError($perception, $tenant, $result['error_message'] ?? 'Error temporal SUNAT');

                return;
            }

            $this->markAsRejected($perception, $tenant, $errorCode, $result['error_message'] ?? null);
        }
    }

    private function isRetryableError(string $errorCode): bool
    {
        return in_array($errorCode, ['0', '100', '109', '500', '1033', '2800'], true);
    }

    private function handleRetryableError(Perception $perception, Tenant $tenant, string $message): void
    {
        if ($this->attempts() >= $this->tries) {
            // TIMEOUT ≠ RECHAZO: al agotar reintentos sin respuesta, queda POR CONFIRMAR
            // (pendiente/SUNAT_TIMEOUT), NUNCA 'rechazado'. Se concilia consultando el estado/CDR;
            // reenviar a ciegas duplicaría la percepción en SUNAT.
            $perception->update([
                'sunat_status' => 'pendiente',
                'sunat_code' => 'SUNAT_TIMEOUT',
                'sunat_description' => substr("Sin respuesta de SUNAT tras {$this->tries} intentos; por confirmar. {$message}", 0, 500),
            ]);

            return;
        }

        $delay = $this->backoff[$this->attempts() - 1] ?? 600;
        $this->release($delay);
    }

    private function markAsRejected(Perception $perception, Tenant $tenant, string $code, ?string $message): void
    {
        $perception->update([
            'sunat_status' => 'rechazado',
            'sunat_code' => substr($code, 0, 20),
            'sunat_description' => $message ? substr($message, 0, 500) : null,
        ]);

        if ($tenant->webhook_url) {
            NotifyWebhookJob::dispatch(Perception::class, $perception->id, 'perception.rejected');
        }
    }

    private function perceptionToArray(Perception $perception): array
    {
        return [
            'serie' => $perception->serie,
            'correlativo' => $perception->correlativo,
            'cod_local' => $perception->cod_local,
            'fecha_emision' => $perception->fecha_emision->format('Y-m-d'),
            'cliente' => [
                'tipo_doc' => $perception->cliente_tipo_doc,
                'num_doc' => $perception->cliente_num_doc,
                'razon_social' => $perception->cliente_razon_social,
                'direccion' => $perception->cliente_direccion,
            ],
            'regimen' => $perception->regimen,
            'tasa' => (float) $perception->tasa,
            'imp_percibido' => (float) $perception->imp_percibido,
            'imp_cobrado' => (float) $perception->imp_cobrado,
            'observacion' => $perception->observacion,
            'documentos' => $perception->items->map(fn ($item) => [
                'tipo_doc' => $item->tipo_doc,
                'num_doc' => $item->num_doc,
                'fecha_emision' => $item->fecha_emision_doc->format('Y-m-d'),
                'imp_total' => (float) $item->imp_total,
                'moneda' => $item->moneda,
                'cobros' => $item->cobros,
                'fecha_percepcion' => $item->fecha_percepcion->format('Y-m-d'),
                'imp_percibido' => (float) $item->imp_percibido,
                'imp_cobrar' => (float) $item->imp_cobrar,
                'tipo_cambio' => $item->tipo_cambio,
            ])->toArray(),
        ];
    }
}
