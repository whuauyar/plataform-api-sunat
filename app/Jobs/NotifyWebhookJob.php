<?php

namespace App\Jobs;

use App\Contracts\Documentable;
use App\Models\DispatchGuide;
use App\Models\Summary;
use App\Models\Tenant;
use App\Models\VoidedDocument;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Http;

class NotifyWebhookJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public array $backoff = [10, 30, 90];

    /**
     * Modelos sin relación 'items' (items en JSON o sin items).
     */
    private const MODELS_WITHOUT_ITEMS = [
        Summary::class,
        VoidedDocument::class,
        DispatchGuide::class,
    ];

    /**
     * Dos modos de uso:
     *  - Documento: dispatch($modelClass, $documentId, $event) — construye el
     *    payload a partir del modelo y resuelve el tenant desde el registro.
     *  - Tenant + payload: dispatch(tenantId: $id, event: $e, payload: [...]) —
     *    para eventos que no cuelgan de un comprobante (p. ej. SIRE reconcile).
     */
    public function __construct(
        private ?string $modelClass = null,
        private ?int $documentId = null,
        private string $event = '',
        private ?int $tenantId = null,
        private ?array $payload = null,
    ) {
        // Cola propia: los webhooks no se atrasan detrás de un flood de facturas.
        $this->onQueue('webhooks');
    }

    public function handle(): void
    {
        [$tenant, $payload] = $this->modelClass !== null
            ? $this->resolverDesdeDocumento()
            : $this->resolverDesdeTenant();

        if (! $tenant || ! $tenant->webhook_url) {
            return;
        }

        // Cuerpo firmado: se serializa a JSON y se firma con HMAC-SHA256 usando el
        // api_secret del tenant, para que el receptor verifique el origen/integridad.
        // Verificación del cliente:
        //   $esperado = hash_hmac('sha256', $rawBody, $apiSecret);
        //   hash_equals('sha256='.$esperado, $request->header('X-Signature'));
        $body = json_encode([
            'event' => $this->event,
            'document' => $payload,
            'timestamp' => now()->toIso8601String(),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $signature = 'sha256='.hash_hmac('sha256', $body, (string) $tenant->api_secret);

        Http::withHeaders([
            'Content-Type' => 'application/json',
            'X-Signature' => $signature,
            'X-Webhook-Event' => $this->event,
        ])->withBody($body, 'application/json')->timeout(15)->post($tenant->webhook_url);
    }

    /**
     * Modo documento: carga el registro y arma su payload.
     *
     * @return array{0: ?Tenant, 1: array<string, mixed>}
     */
    private function resolverDesdeDocumento(): array
    {
        $hasItems = ! in_array($this->modelClass, self::MODELS_WITHOUT_ITEMS, true);

        $record = $hasItems
            ? $this->modelClass::with(['tenant', 'items'])->find($this->documentId)
            : $this->modelClass::with('tenant')->find($this->documentId);

        if (! $record) {
            return [null, []];
        }

        $payload = match ($this->modelClass) {
            Summary::class => $this->buildSummaryPayload($record),
            VoidedDocument::class => $this->buildVoidedPayload($record),
            DispatchGuide::class => $this->buildDispatchPayload($record),
            default => $this->buildDocumentPayload($record),
        };

        return [$record->tenant, $payload];
    }

    /**
     * Modo tenant + payload: evento que no cuelga de un comprobante.
     *
     * @return array{0: ?Tenant, 1: array<string, mixed>}
     */
    private function resolverDesdeTenant(): array
    {
        return [Tenant::find($this->tenantId), $this->payload ?? []];
    }

    private function buildDocumentPayload($document): array
    {
        $tipoDocumento = $document instanceof Documentable
            ? $document->getTipoDocumento()
            : $document->tipo_documento;

        return [
            'id' => $document->id,
            'tipo_documento' => $tipoDocumento,
            'serie' => $document->serie,
            'correlativo' => $document->correlativo,
            'numero_completo' => $document->numero_completo,
            'fecha_emision' => $document->fecha_emision->format('Y-m-d'),
            'total' => $document->mto_imp_venta,
            'sunat_status' => $document->sunat_status,
            'sunat_code' => $document->sunat_code,
            'sunat_description' => $document->sunat_description,
            'hash_cpe' => $document->hash_cpe,
        ];
    }

    private function buildDispatchPayload(DispatchGuide $guide): array
    {
        return [
            'id' => $guide->id,
            'tipo_documento' => '09',
            'serie' => $guide->serie,
            'correlativo' => $guide->correlativo,
            'numero_completo' => $guide->serie.'-'.$guide->correlativo,
            'fecha_emision' => $guide->fecha_emision->format('Y-m-d'),
            'destinatario' => $guide->destinatario_razon_social,
            'ticket' => $guide->ticket,
            'sunat_status' => $guide->sunat_status,
            'sunat_code' => $guide->sunat_code,
            'sunat_description' => $guide->sunat_description,
        ];
    }

    private function buildSummaryPayload(Summary $summary): array
    {
        return [
            'id' => $summary->id,
            'tipo_documento' => 'resumen',
            'identifier' => $summary->identifier,
            'tipo' => $summary->tipo,
            'fecha_referencia' => $summary->fecha_referencia->format('Y-m-d'),
            'fecha_envio' => $summary->fecha_envio->format('Y-m-d'),
            'total_documentos' => $summary->total_documentos,
            'ticket' => $summary->ticket,
            'sunat_status' => $summary->sunat_status,
            'sunat_code' => $summary->sunat_code,
            'sunat_description' => $summary->sunat_description,
        ];
    }

    private function buildVoidedPayload(VoidedDocument $voided): array
    {
        return [
            'id' => $voided->id,
            'tipo_documento' => 'comunicacion_baja',
            'identifier' => $voided->identifier,
            'fecha_generacion' => $voided->fecha_generacion->format('Y-m-d'),
            'fecha_comunicacion' => $voided->fecha_comunicacion->format('Y-m-d'),
            'total_documentos' => $voided->total_documentos,
            'ticket' => $voided->ticket,
            'sunat_status' => $voided->sunat_status,
            'sunat_code' => $voided->sunat_code,
            'sunat_description' => $voided->sunat_description,
        ];
    }
}
