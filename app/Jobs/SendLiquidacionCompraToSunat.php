<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\PurchaseSettlement;
use App\Models\Tenant;
use App\Services\Greenter\Builders\LiquidacionCompraXmlBuilder;
use App\Services\Greenter\GreenterService;
use App\Support\Storage\Comprobantes;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;

/**
 * Envío de la liquidación de compra (tipo 04) a SUNAT: construye el XML SelfBilledInvoice, lo firma
 * y lo envía por el billService (el mismo de la factura, RS 097-2012/SUNAT art. 10 num. 10.5 b).
 * El CDR aceptado guarda {@code cdr_fecha}, base del plazo de reversión. 10 intentos con backoff;
 * un error de certificado deja la liquidación en {@code pendiente}.
 */
class SendLiquidacionCompraToSunat implements ShouldQueue
{
    use Queueable;

    public int $tries = 10;

    public array $backoff = [15, 30, 60, 60, 120, 120, 300, 300, 600, 600];

    public function __construct(
        private int $settlementId
    ) {
        $this->onQueue('sunat');
    }

    public function middleware(): array
    {
        // Serializa los envíos de la MISMA liquidación (ver SendRetentionToSunat).
        return [
            (new WithoutOverlapping('sunat-send:settlement:'.$this->settlementId))
                ->dontRelease()
                ->expireAfter(180),
        ];
    }

    public function handle(): void
    {
        $settlement = PurchaseSettlement::with('items')->findOrFail($this->settlementId);

        // Un comprobante aceptado o revertido no se reenvía.
        if (in_array($settlement->sunat_status, ['aceptado', 'anulado', 'anulacion_en_proceso'], true)) {
            return;
        }

        $tenant = Tenant::findOrFail($settlement->tenant_id);
        $service = new GreenterService($tenant);

        $xml = (new LiquidacionCompraXmlBuilder)->build($settlement, $tenant);
        $name = "{$tenant->ruc}-04-{$settlement->serie}-{$settlement->correlativo}";

        try {
            $result = $service->sendRawBill($xml, $name);
        } catch (\SoapFault $e) {
            $this->handleRetryableError($settlement, $tenant, $e->getMessage());

            return;
        } catch (\Greenter\XMLSecLibs\Exception\XmlSignException $e) {
            // Error de certificado: no es rechazo de SUNAT; se deja pendiente para reintentar tras corregirlo.
            $settlement->update([
                'sunat_status' => 'pendiente',
                'sunat_code' => 'CERT_ERROR',
                'sunat_description' => substr('Error de certificado: '.$e->getMessage(), 0, 500),
            ]);

            return;
        }

        if ($result['success']) {
            $settlement->update([
                'sunat_status' => ($result['accepted'] ?? true) ? 'aceptado' : 'rechazado',
                'sunat_code' => $result['code'] ?? null,
                'sunat_description' => $result['description'] ?? null,
                'sunat_notes' => $result['notes'] ?? null,
                'hash_cpe' => $result['hash'] ?? null,
                'sent_at' => now(),
                'cdr_fecha' => now(),
            ]);

            $base = "{$tenant->ruc}/liquidaciones_compra/{$settlement->serie}-{$settlement->correlativo}";
            if (! empty($result['xml'])) {
                Comprobantes::put("{$base}.xml", $result['xml']);
                $settlement->update(['xml_path' => "{$base}.xml"]);
            }
            if (! empty($result['cdr_zip'])) {
                Comprobantes::put("{$tenant->ruc}/liquidaciones_compra/R-{$settlement->serie}-{$settlement->correlativo}.zip", $result['cdr_zip']);
                $settlement->update(['cdr_path' => "{$tenant->ruc}/liquidaciones_compra/R-{$settlement->serie}-{$settlement->correlativo}.zip"]);
            }

            if ($tenant->webhook_url) {
                NotifyWebhookJob::dispatch(PurchaseSettlement::class, $settlement->id, 'purchase_settlement.sent');
            }

            return;
        }

        $errorCode = $result['error_code'] ?? '';
        if ($this->isRetryableError($errorCode)) {
            $this->handleRetryableError($settlement, $tenant, $result['error_message'] ?? 'Error temporal SUNAT');

            return;
        }

        $this->markAsRejected($settlement, $tenant, $errorCode, $result['error_message'] ?? null);
    }

    private function isRetryableError(string $errorCode): bool
    {
        return in_array($errorCode, ['0', '100', '109', '500', '1033', '2800'], true);
    }

    private function handleRetryableError(PurchaseSettlement $settlement, Tenant $tenant, string $message): void
    {
        if ($this->attempts() >= $this->tries) {
            // TIMEOUT ≠ RECHAZO: al agotar reintentos sin respuesta, queda POR CONFIRMAR
            // (pendiente/SUNAT_TIMEOUT), NUNCA 'rechazado'. Se concilia consultando el estado/CDR;
            // reenviar a ciegas duplicaría la liquidación en SUNAT.
            $settlement->update([
                'sunat_status' => 'pendiente',
                'sunat_code' => 'SUNAT_TIMEOUT',
                'sunat_description' => substr("Sin respuesta de SUNAT tras {$this->tries} intentos; por confirmar. {$message}", 0, 500),
            ]);

            return;
        }

        $this->release($this->backoff[$this->attempts() - 1] ?? 600);
    }

    private function markAsRejected(PurchaseSettlement $settlement, Tenant $tenant, string $code, ?string $message): void
    {
        $settlement->update([
            'sunat_status' => 'rechazado',
            'sunat_code' => substr($code, 0, 20),
            'sunat_description' => $message ? substr($message, 0, 500) : null,
        ]);

        if ($tenant->webhook_url) {
            NotifyWebhookJob::dispatch(PurchaseSettlement::class, $settlement->id, 'purchase_settlement.rejected');
        }
    }
}
