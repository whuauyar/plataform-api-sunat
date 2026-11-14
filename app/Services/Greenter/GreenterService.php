<?php

namespace App\Services\Greenter;

use App\Models\Tenant;
use App\Services\Greenter\Builders\DespatchBuilder;
use App\Services\Greenter\Builders\InvoiceBuilder;
use App\Services\Greenter\Builders\NoteBuilder;
use App\Services\Greenter\Builders\SummaryBuilder;
use App\Services\Greenter\Builders\VoidedBuilder;
use Greenter\Api\ApiFactory;
use Greenter\Factory\XmlBuilderResolver;
use Greenter\Model\DocumentInterface;
use Greenter\Model\Response\BillResult;
use Greenter\Model\Response\SummaryResult;
use Greenter\Model\Sale\Invoice;
use Greenter\Model\Sale\Note;
use Greenter\See;
use Greenter\Sunat\GRE\Api\AuthApi;
use Greenter\Sunat\GRE\ApiException;
use Greenter\Sunat\GRE\Configuration;
use Greenter\XMLSecLibs\Sunat\SignedXml;
use GuzzleHttp\Client;

class GreenterService
{
    private Tenant $tenant;

    private ?See $currentSee = null;

    private ?string $lastXml = null;

    public function __construct(Tenant $tenant)
    {
        $this->tenant = $tenant;
    }

    /**
     * Verificación de licencia en el punto más profundo del envío a SUNAT.
     *
     * Es la segunda barrera (además del middleware): aunque alguien quite el
     * middleware, la emisión a producción no ocurre sin licencia válida. En
     * entorno beta no aplica, para poder probar sin licencia.
     */
    private function guardEmisionAutorizada(): void
    {
        if ($this->tenant->environment === 'beta') {
            return;
        }

        $check = app(\App\Services\License\LicenseClient::class)->check();

        if (! $check->allowed) {
            throw new \RuntimeException('Emisión no disponible: '.$check->message);
        }
    }

    public function getLastXml(): ?string
    {
        return $this->lastXml ?? $this->currentSee?->getFactory()->getLastXml();
    }

    public function createSee(?string $endpoint = null): See
    {
        $see = new See;

        if (! $endpoint) {
            $env = $this->tenant->environment;
            $endpoint = config("facturacion.sunat.{$env}.fe");
        }

        $see->setService($endpoint);

        $see->setCertificate($this->resolveCertificatePem());

        $see->setClaveSOL(
            $this->tenant->ruc,
            $this->tenant->sol_user,
            $this->tenant->sol_pass
        );

        $cachePath = storage_path('app/cache/greenter');
        if (! is_dir($cachePath)) {
            mkdir($cachePath, 0755, true);
        }
        $see->setCachePath($cachePath);

        return $see;
    }

    /**
     * Intenta obtener la respuesta SOAP cruda de SUNAT del último envío.
     * Solo devuelve contenido si el trace del SoapClient está activo; si no,
     * o si algo falla, devuelve null (nunca rompe el flujo de envío).
     */
    private function respuestaSoapCruda(): ?string
    {
        try {
            $client = $this->currentSee?->getFactory()?->getSender()?->getClient();
            if ($client instanceof \SoapClient) {
                $resp = $client->__getLastResponse();

                return $resp ? mb_substr($resp, 0, 4000) : null;
            }
        } catch (\Throwable) {
            // Silencioso a propósito: es solo diagnóstico, no debe afectar el envío.
        }

        return null;
    }

    public function createSeeRetention(): See
    {
        $env = $this->tenant->environment;

        return $this->createSee(config("facturacion.sunat.{$env}.retention"));
    }

    public function createApi(): \Greenter\Api
    {
        $env = $this->tenant->environment;
        $endpoints = [
            'auth' => config("facturacion.sunat.{$env}.guias_auth"),
            'cpe' => config("facturacion.sunat.{$env}.guias_cpe"),
        ];
        $client = new Client;
        $configuration = new Configuration;
        $factory = new ApiFactory(
            new AuthApi($client, $configuration->setHost($endpoints['auth'])),
            $client,
            new LaravelCacheTokenStore($this->greTokenNamespace()),
            $endpoints['cpe'],
        );

        $api = new \Greenter\Api($endpoints, $factory);

        $api->setBuilderOptions([
            'strict_variables' => true,
            'optimizations' => 0,
            'debug' => false,
            'cache' => false,
        ]);

        // Credenciales OAuth2 GRE (requeridas para guías de remisión).
        [$clientId, $clientSecret] = $this->greCredentials();

        if (! $clientId || ! $clientSecret) {
            throw new \RuntimeException(
                'Credenciales GRE (client_id/client_secret) no configuradas. '
                .'Agrega SUNAT_GRE_CLIENT_ID y SUNAT_GRE_CLIENT_SECRET en .env'
            );
        }

        $api->setApiCredentials($clientId, $clientSecret);

        $api->setClaveSOL(
            $this->tenant->ruc,
            $this->tenant->sol_user,
            $this->tenant->sol_pass
        );

        $api->setCertificate($this->resolveCertificatePem());

        return $api;
    }

    private function greTokenNamespace(): string
    {
        $env = $this->tenant->environment;

        return implode('|', [
            $env,
            config("facturacion.sunat.{$env}.guias_auth"),
            config("facturacion.sunat.{$env}.guias_cpe"),
            $this->tenant->ruc,
            $this->tenant->sol_user,
        ]);
    }

    private function greClientId(): ?string
    {
        return $this->greCredentials()[0];
    }

    /**
     * Credenciales OAuth2 efectivas para GRE.
     *
     * El panel de la empresa guarda las credenciales de la API SUNAT (rótulo
     * "Client ID / Client Secret", usadas para GRE / consulta / SIRE) en las
     * columnas sire_client_id/sire_client_secret. GreenterService leía SOLO
     * client_id/client_secret, por eso lo que se cargaba en el panel nunca
     * llegaba al envío de guías y caía al default del config → 401
     * "unauthorized_client". Ahora se consideran ambas fuentes.
     *
     * Prioridad: client_id/secret (integración/API) → sire_client_id/secret
     * (panel) → config global del entorno.
     *
     * @return array{0: ?string, 1: ?string}
     */
    private function greCredentials(): array
    {
        $env = $this->tenant->environment;

        return [
            $this->tenant->client_id ?: $this->tenant->sire_client_id ?: config("facturacion.sunat.{$env}.gre_client_id"),
            $this->tenant->client_secret ?: $this->tenant->sire_client_secret ?: config("facturacion.sunat.{$env}.gre_client_secret"),
        ];
    }

    private function forgetGreToken(): void
    {
        (new LaravelCacheTokenStore($this->greTokenNamespace()))->forget($this->greClientId());
    }

    private function isMissingGreTokenError(\Throwable $e): bool
    {
        return $e instanceof ApiException
            && (int) $e->getCode() === 401
            && str_contains($e->getMessage(), 'Token NO existe');
    }

    private function isMissingGreTokenResult(mixed $result): bool
    {
        if (! is_object($result) || ! method_exists($result, 'isSuccess') || $result->isSuccess()) {
            return false;
        }

        if (! method_exists($result, 'getError')) {
            return false;
        }

        $error = $result->getError();

        return $error
            && (string) $error->getCode() === 'API'
            && str_contains((string) $error->getMessage(), 'Token NO existe');
    }

    public function buildInvoice(array $data): Invoice
    {
        return (new InvoiceBuilder($this->tenant))->build($data);
    }

    public function buildNote(array $data): Note
    {
        return (new NoteBuilder($this->tenant))->build($data);
    }

    public function buildDespatch(array $data): \Greenter\Model\Despatch\Despatch
    {
        return (new DespatchBuilder($this->tenant))->build($data);
    }

    /**
     * Envía una GRT (tipo 31) a SUNAT con flujo custom:
     *   1. Genera XML vía XmlBuilderResolver (no firmado)
     *   2. Inyecta cac:DespatchParty dentro de cac:Despatch (remitente)
     *      — Greenter no soporta este nodo nativamente para GRT
     *   3. Firma con SignedXml
     *   4. Envía via $api->sendXml()
     *
     * $data debe incluir:
     *   - 'remitente' => ['tipo_doc'=>'6','num_doc'=>'20XXXXXXXXX','razon_social'=>'...']
     */
    public function sendGrt(array $data): array
    {
        return $this->sendDespatch($data);
    }

    /**
     * Envía una GRE con ajustes XML que Greenter aún no modela en su API pública.
     *
     * GRT (tipo 31):
     *   - Inyecta cac:DespatchParty dentro de cac:Despatch.
     *
     * @return array{result: mixed, xml: string}
     */
    public function sendDespatch(array $data): array
    {
        $this->guardEmisionAutorizada();

        $despatch = $this->buildDespatch($data);

        // 1. XML no firmado
        $resolver = new \Greenter\Factory\XmlBuilderResolver([
            'strict_variables' => true,
            'optimizations' => 0,
            'debug' => false,
            'cache' => false,
        ]);
        $xmlBuilder = $resolver->find(get_class($despatch));
        $unsignedXml = $xmlBuilder->build($despatch);

        // Limpieza: la plantilla emite <cbc:CompanyID/> vacío (Registro MTC del
        // transportista) cuando no se informa; SUNAT lo observa (4391). Se retira
        // el vacío; si el transportista tiene MTC, el tag va con valor y se conserva.
        $unsignedXml = preg_replace('#\s*<cbc:CompanyID\s*/>#', '', $unsignedXml);
        $unsignedXml = preg_replace('#\s*<cbc:CompanyID>\s*</cbc:CompanyID>#', '', $unsignedXml);

        $tipoDocumento = $data['tipo_documento'] ?? '09';
        $modTraslado = $data['mod_traslado'] ?? null;

        // 2. Inyectar nodos requeridos por SUNAT no cubiertos por Greenter.
        if ($tipoDocumento === '09' && $modTraslado === '01' && ! empty($data['fecha_entrega_transportista'])) {
            $unsignedXml = $this->injectCarrierDeliveryDate(
                $unsignedXml,
                $data['fecha_entrega_transportista']
            );
        }

        if ($tipoDocumento === '31') {
            // La plantilla Greenter (despatch2022) está pensada para GRE Remitente
            // y emite HandlingCode (motivo, cat20) y TransportModeCode (modalidad,
            // cat18). La GRE Transportista NO lleva esos campos (confirmado contra
            // el XML de referencia SUNAT y la matriz de validaciones GRT), así que
            // se retiran antes de firmar.
            $unsignedXml = $this->stripGrtMotivoModalidad($unsignedXml);
            $unsignedXml = $this->injectGrtShipmentStageNodes($unsignedXml, $data);
            $unsignedXml = $this->injectGrtSubcontratadorPagador($unsignedXml, $data);
            $unsignedXml = $this->injectDespatchPartyGrt($unsignedXml, $data['remitente'] ?? null);
        }

        // 3. Firmar después de toda modificación del XML.
        $signer = new SignedXml;
        $signer->setCertificate($this->resolveCertificatePem());
        $signedXml = $signer->signXml($unsignedXml);

        // 4. Enviar. Nubefact beta puede invalidar tokens del cache; reintentar
        // una vez con token fresco cuando responde "Token NO existe".
        try {
            $api = $this->createApi();
            $result = $api->sendXml($despatch->getName(), $signedXml);
        } catch (\Throwable $e) {
            if (! $this->isMissingGreTokenError($e)) {
                throw $e;
            }

            $this->forgetGreToken();
            $api = $this->createApi();
            $result = $api->sendXml($despatch->getName(), $signedXml);
        }

        if ($this->isMissingGreTokenResult($result)) {
            $this->forgetGreToken();
            $api = $this->createApi();
            $result = $api->sendXml($despatch->getName(), $signedXml);
        }

        return [
            'result' => $result,
            'xml' => $signedXml,
        ];
    }

    /**
     * SUNAT/Nubefact exige desde 2026-06-01 la fecha de entrega de bienes al
     * transportista para GRE remitente con transporte publico.
     *
     * Greenter 5.3.0 modela este campo como envio.fecEntregaBienes y lo emite
     * en cac:LoadingTransportEvent/cbc:OccurrenceDate. La version instalada
     * aun no tiene ese setter, por eso se inyecta antes de firmar.
     */
    private function injectCarrierDeliveryDate(string $xml, string $date): string
    {
        $deliveryDate = (new \DateTime($date))->format('Y-m-d');
        $block = '<cac:LoadingTransportEvent>'
            .'<cbc:OccurrenceDate>'.$deliveryDate.'</cbc:OccurrenceDate>'
            .'</cac:LoadingTransportEvent>';

        $pattern = '/(<\/cac:CarrierParty>)/';
        $replaced = preg_replace($pattern, '$1'.$block, $xml, 1, $count);

        if ($count === 0) {
            throw new \RuntimeException('No se encontró cac:CarrierParty para inyectar fecha_entrega_transportista.');
        }

        return $replaced;
    }

    /**
     * Inyecta el bloque cac:DespatchParty dentro de cac:Despatch.
     *
     * Path SUNAT: /DespatchAdvice/cac:Shipment/cac:Delivery/cac:Despatch/cac:DespatchParty
     * En UBL cac:DespatchAddress debe ir antes de cac:DespatchParty.
     */
    /**
     * Quita del XML los nodos que solo corresponden a la GRE Remitente y que la
     * plantilla de Greenter emite siempre, pero que la GRE Transportista (31)
     * NO debe llevar:
     *   - cbc:HandlingCode           (Motivo de traslado, cat20) — nivel Shipment
     *   - cbc:TransportModeCode      (Modalidad de traslado, cat18) — en ShipmentStage
     *
     * SUNAT valida la GRT contra un esquema/XSL que no contempla estos campos.
     * Verificado contra el XML de referencia oficial (…-31-VVV1-1.xml), un GRT
     * real de producción (…-31-V001-150.xml) y la matriz ValidacionesGRE.
     */
    private function stripGrtMotivoModalidad(string $xml): string
    {
        $xml = preg_replace('#\s*<cbc:HandlingCode\b[^>]*>.*?</cbc:HandlingCode>#s', '', $xml, 1, $c1);
        $xml = preg_replace('#\s*<cbc:TransportModeCode\b[^>]*>.*?</cbc:TransportModeCode>#s', '', $xml, 1, $c2);

        if ($c1 === 0 || $c2 === 0) {
            \Illuminate\Support\Facades\Log::warning('gre.grt.strip_motivo_modalidad', [
                'handling_code_removed' => $c1,
                'transport_mode_removed' => $c2,
                'ruc' => $this->tenant->ruc,
            ]);
        }

        return $xml;
    }

    /**
     * En GRE Transportista el ShipmentStage debe llevar, en este orden UBL:
     *   TransitPeriod → CarrierParty → TransportMeans → DriverPerson
     * La plantilla Greenter no emite CarrierParty ni TransportMeans en este caso
     * (el transportista es el emisor), pero el XML de referencia SUNAT y un GRT
     * real de producción sí los incluyen:
     *   - cac:CarrierParty vacío (o con cbc:CompanyID = Registro MTC si se informa)
     *   - cac:TransportMeans/cac:RoadTransport/cbc:LicensePlateID = placa
     * Se insertan justo después de </cac:TransitPeriod>.
     */
    /**
     * GRE Transportista: emite las PARTES (no solo el indicador) de:
     *   - Subcontratador → cac:Shipment/cac:Consignment/cac:LogisticsOperatorParty
     *     (va antes de cac:ShipmentStage). RUC obligatorio (schemeID 6).
     *   - Pagador del flete tercero → cac:OriginatorCustomerParty (nivel raíz, antes
     *     de cac:Shipment). Solo cuando datos_pagador_flete.tipo === 'tercero'.
     * Referencia: matriz ValidacionesGRE campos 60-63 (Guía Transportista).
     */
    private function injectGrtSubcontratadorPagador(string $xml, array $data): string
    {
        $cat06 = 'schemeName="Documento de Identidad" schemeAgencyName="PE:SUNAT" schemeURI="urn:pe:gob:sunat:cpe:see:gem:catalogos:catalogo06"';

        $sub = $data['datos_subcontratador'] ?? null;
        if (! empty($sub['num_doc']) && ! empty($sub['razon_social'])) {
            $consignment = '<cac:Consignment>'
                .'<cbc:ID>SUNAT_Envio</cbc:ID>'
                .'<cac:LogisticsOperatorParty>'
                .'<cac:PartyIdentification>'
                .'<cbc:ID schemeID="6" '.$cat06.'>'.htmlspecialchars((string) $sub['num_doc'], ENT_XML1).'</cbc:ID>'
                .'</cac:PartyIdentification>'
                .'<cac:PartyLegalEntity>'
                .'<cbc:RegistrationName><![CDATA['.$sub['razon_social'].']]></cbc:RegistrationName>'
                .'</cac:PartyLegalEntity>'
                .'</cac:LogisticsOperatorParty>'
                .'</cac:Consignment>';

            $xml = preg_replace('#(<cac:ShipmentStage>)#', $consignment.'$1', $xml, 1, $cCount);
            if ($cCount === 0) {
                throw new \RuntimeException('No se encontró cac:ShipmentStage para inyectar el subcontratador (Consignment) en GRT.');
            }
        }

        $pag = $data['datos_pagador_flete'] ?? null;
        if (($pag['tipo'] ?? null) === 'tercero' && ! empty($pag['num_doc']) && ! empty($pag['razon_social'])) {
            $tipoDoc = $pag['tipo_doc'] ?? '6';
            $originator = '<cac:OriginatorCustomerParty>'
                .'<cac:Party>'
                .'<cac:PartyIdentification>'
                .'<cbc:ID schemeID="'.$tipoDoc.'" '.$cat06.'>'.htmlspecialchars((string) $pag['num_doc'], ENT_XML1).'</cbc:ID>'
                .'</cac:PartyIdentification>'
                .'<cac:PartyLegalEntity>'
                .'<cbc:RegistrationName><![CDATA['.$pag['razon_social'].']]></cbc:RegistrationName>'
                .'</cac:PartyLegalEntity>'
                .'</cac:Party>'
                .'</cac:OriginatorCustomerParty>';

            $xml = preg_replace('#(<cac:Shipment>)#', $originator.'$1', $xml, 1, $oCount);
            if ($oCount === 0) {
                throw new \RuntimeException('No se encontró cac:Shipment para inyectar el pagador del flete (OriginatorCustomerParty) en GRT.');
            }
        }

        return $xml;
    }

    private function injectGrtShipmentStageNodes(string $xml, array $data): string
    {
        $mtc = $data['transportista']['nro_mtc'] ?? $data['nro_mtc'] ?? null;
        $placa = $data['vehiculo']['placa'] ?? null;

        $carrier = '<cac:CarrierParty>'
            .'<cac:PartyIdentification><cbc:ID schemeID=""/></cac:PartyIdentification>'
            .'<cac:PartyLegalEntity><cbc:RegistrationName/>'
            .($mtc ? '<cbc:CompanyID>'.htmlspecialchars((string) $mtc, ENT_XML1).'</cbc:CompanyID>' : '')
            .'</cac:PartyLegalEntity>'
            .'</cac:CarrierParty>';

        $transportMeans = $placa
            ? '<cac:TransportMeans><cac:RoadTransport><cbc:LicensePlateID>'
                .htmlspecialchars((string) $placa, ENT_XML1)
                .'</cbc:LicensePlateID></cac:RoadTransport></cac:TransportMeans>'
            : '';

        $pattern = '#(<cac:ShipmentStage>\s*<cac:TransitPeriod>.*?</cac:TransitPeriod>)#s';
        $replaced = preg_replace($pattern, '$1'.$carrier.$transportMeans, $xml, 1, $count);

        if ($count === 0) {
            throw new \RuntimeException('No se encontró cac:ShipmentStage>cac:TransitPeriod para inyectar CarrierParty/TransportMeans en GRT.');
        }

        return $replaced;
    }

    private function injectDespatchPartyGrt(string $xml, ?array $remitente): string
    {
        if (empty($remitente) || empty($remitente['num_doc']) || empty($remitente['razon_social'])) {
            throw new \RuntimeException('GRT requiere los datos del remitente (tipo_doc, num_doc, razon_social).');
        }

        $tipoDoc = $remitente['tipo_doc'] ?? '6';
        $numDoc = htmlspecialchars($remitente['num_doc'], ENT_XML1);
        $razonSocial = htmlspecialchars($remitente['razon_social'], ENT_XML1);

        $block = '<cac:DespatchParty>'
            .'<cac:PartyIdentification>'
            .'<cbc:ID schemeID="'.$tipoDoc.'" schemeName="Documento de Identidad" '
            .'schemeAgencyName="PE:SUNAT" schemeURI="urn:pe:gob:sunat:cpe:see:gem:catalogos:catalogo06">'
            .$numDoc
            .'</cbc:ID>'
            .'</cac:PartyIdentification>'
            .'<cac:PartyLegalEntity>'
            .'<cbc:RegistrationName><![CDATA['.$razonSocial.']]></cbc:RegistrationName>'
            .'</cac:PartyLegalEntity>'
            .'</cac:DespatchParty>';

        // Insertar despues de </cac:DespatchAddress> (primer ocurrencia dentro de cac:Despatch).
        $pattern = '/(<cac:Despatch>\s*<cac:DespatchAddress>.*?<\/cac:DespatchAddress>)/s';
        $replaced = preg_replace($pattern, '$1'.$block, $xml, 1, $count);
        if ($count === 0) {
            throw new \RuntimeException('No se encontró el nodo cac:Despatch > cac:DespatchAddress en el XML para inyectar DespatchParty.');
        }

        return $replaced;
    }

    public function buildSummary(array $data): \Greenter\Model\Summary\Summary
    {
        return (new SummaryBuilder($this->tenant))->build($data);
    }

    public function buildVoided(array $data): \Greenter\Model\Voided\Voided
    {
        return (new VoidedBuilder($this->tenant))->build($data);
    }

    /** @var array<string,mixed>|null Contrato de colaboración empresarial a inyectar en el XML. */
    private ?array $contratoColaboracion = null;

    public function setContratoColaboracion(?array $contrato): void
    {
        $this->contratoColaboracion = $contrato;
    }

    /**
     * Inyecta cac:ContractDocumentReference (Contrato de Colaboración Empresarial)
     * antes de firmar. Greenter no modela este nodo. Se ubica en la secuencia UBL
     * correcta: antes de AdditionalDocumentReference / Signature.
     */
    private function injectContratoColaboracion(string $xml): string
    {
        $c = $this->contratoColaboracion;
        if (empty($c) || empty($c['numero'])) {
            return $xml;
        }

        $esc = fn ($s) => htmlspecialchars((string) $s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
        $porcentaje = number_format((float) ($c['porcentaje'] ?? 0), 2, '.', '');

        $node = '<cac:ContractDocumentReference>'
            .'<cbc:ID>'.$esc($c['numero']).'</cbc:ID>'
            .'<cbc:DocumentTypeCode>'.$esc($c['tipo'] ?? '').'</cbc:DocumentTypeCode>'
            .'<cbc:DocumentDescription>'.$esc($c['descripcion'] ?? '').'</cbc:DocumentDescription>'
            .'<cac:IssuerParty><cac:PartyLegalEntity><cac:ShareholderParty>'
            .'<cbc:PartecipationPercent>'.$porcentaje.'</cbc:PartecipationPercent>'
            .'</cac:ShareholderParty></cac:PartyLegalEntity></cac:IssuerParty>'
            .'</cac:ContractDocumentReference>';

        foreach (['<cac:AdditionalDocumentReference', '<cac:Signature'] as $anchor) {
            $pos = strpos($xml, $anchor);
            if ($pos !== false) {
                return substr($xml, 0, $pos).$node.substr($xml, $pos);
            }
        }

        return $xml;
    }

    /**
     * Nota de débito por Penalidades (motivo 13) sin documento afectado: SUNAT
     * exige que el tag cac:BillingReference esté AUSENTE, pero Greenter siempre
     * lo renderiza (vacío). Se remueve el nodo vacío antes de firmar.
     */
    private function stripEmptyNoteReference(string $xml, DocumentInterface $document): string
    {
        if (! $document instanceof \Greenter\Model\Sale\Note) {
            return $xml;
        }
        if ($document->getCodMotivo() !== '13' || ! empty($document->getNumDocfectado())) {
            return $xml;
        }

        return preg_replace('#\s*<cac:BillingReference>.*?</cac:BillingReference>#s', '', $xml, 1) ?? $xml;
    }

    public function send(DocumentInterface $document): array
    {
        $this->guardEmisionAutorizada();

        $see = $this->resolveSee($document);
        $this->currentSee = $see;

        // Build unsigned XML, sign, then send.
        $cachePath = storage_path('app/cache/greenter');
        if (! is_dir($cachePath)) {
            mkdir($cachePath, 0755, true);
        }
        $options = ['autoescape' => false, 'cache' => $cachePath];
        $xmlBuilder = (new XmlBuilderResolver($options))->find(get_class($document));
        $unsignedXml = $xmlBuilder->build($document);
        $unsignedXml = $this->stripEmptyNoteReference($unsignedXml, $document);
        $unsignedXml = $this->injectContratoColaboracion($unsignedXml);

        $cert = $this->resolveCertificatePem();
        $signer = new SignedXml;
        $signer->setCertificate($cert);
        $signedXml = $signer->signXml($unsignedXml);
        $this->lastXml = $signedXml;

        $result = $see->sendXml(get_class($document), $document->getName(), $signedXml);
        $xml = $signedXml;

        // Verificar primero si es BillResult con CDR. SUNAT: 0=aceptado,
        // 1000–3999=RECHAZO, 4000+=observación (aceptado con advertencias). Solo
        // 4000+ se considera aceptado-con-observación; 2xxx/3xxx son rechazos.
        if ($result instanceof BillResult) {
            $cdr = $result->getCdrResponse();
            $cdrCode = $cdr ? (string) $cdr->getCode() : null;
            $isObservation = $cdrCode !== null && ctype_digit($cdrCode) && (int) $cdrCode >= 4000;
            // RECHAZO por código de CDR (1000–3999), p. ej. 2108 "Presentación fuera de
            // fecha". La aceptación se decide por el CÓDIGO, no por isSuccess(): Greenter
            // puede reportar isSuccess()=true aunque el CDR traiga un código de rechazo.
            $isRechazo = $cdrCode !== null && ctype_digit($cdrCode) && (int) $cdrCode >= 1000 && (int) $cdrCode < 4000;

            if (($result->isSuccess() || $isObservation) && ! $isRechazo) {
                return [
                    'success' => true,
                    'xml' => $xml,
                    'cdr_zip' => $result->getCdrZip(),
                    'hash' => $this->extractHashFromXml($xml),
                    'code' => $cdrCode,
                    'description' => $cdr ? $this->sanitizeUtf8($cdr->getDescription()) : null,
                    'notes' => $cdr ? array_map(fn ($n) => $this->sanitizeUtf8($n), $cdr->getNotes() ?? []) : [],
                    'accepted' => true,
                ];
            }

            // CDR con código de rechazo: devolver como fallo permanente para que el job
            // lo marque 'rechazado' (no se reintenta; es error de datos/plazo).
            if ($isRechazo) {
                return [
                    'success' => false,
                    'xml' => $xml,
                    'error_code' => $cdrCode,
                    'error_message' => $cdr ? $this->sanitizeUtf8($cdr->getDescription()) : 'Rechazado por SUNAT',
                ];
            }
        }

        if (! $result->isSuccess()) {
            $error = $result->getError();
            $errorCode = (string) $error->getCode();

            // Log detallado del fallo — imprescindible para diagnosticar los errores
            // de transporte ('HTTP'/'Bad Request'), donde SUNAT descarta el motivo
            // real y solo queda el reason phrase. Guardamos documento, RUC, entorno y,
            // para errores de transporte, el XML enviado y la respuesta SOAP cruda
            // (si el trace estuviera activo). Así se ve QUÉ documento y con qué falló.
            $esTransporte = $errorCode === '' || ! ctype_digit($errorCode);
            \Illuminate\Support\Facades\Log::warning('sunat.send.failed', [
                'documento' => $document->getName(),
                'ruc' => $this->tenant->ruc,
                'entorno' => $this->tenant->environment,
                'error_code' => $errorCode,
                'error_message' => $this->sanitizeUtf8($error->getMessage()),
                'transporte' => $esTransporte,
                'soap_response' => $esTransporte ? $this->respuestaSoapCruda() : null,
                'request_xml' => $esTransporte ? mb_substr((string) $this->lastXml, 0, 8000) : null,
            ]);

            // Observaciones 4000+: documento aceptado por SUNAT con advertencia
            // (aunque venga como SOAP fault, tratarlo como aceptado). Los 2xxx/3xxx
            // NO entran aquí: son rechazos y caen al return success=false de abajo.
            if (ctype_digit($errorCode) && (int) $errorCode >= 4000) {
                return [
                    'success' => true,
                    'xml' => $xml,
                    'cdr_zip' => $result instanceof BillResult ? $result->getCdrZip() : null,
                    'hash' => $this->extractHashFromXml($xml),
                    'code' => $errorCode,
                    'description' => 'Aceptado con observación',
                    'notes' => [$this->sanitizeUtf8($error->getMessage())],
                    'accepted' => true,
                ];
            }

            return [
                'success' => false,
                'xml' => $xml,
                'error_code' => $errorCode,
                'error_message' => $error->getMessage(),
            ];
        }

        if ($result instanceof SummaryResult) {
            return [
                'success' => true,
                'xml' => $xml,
                'ticket' => $result->getTicket(),
            ];
        }

        return ['success' => false, 'xml' => $xml, 'error_message' => 'Tipo de respuesta no soportado'];
    }

    /**
     * Firma y envía un XML ya construido que Greenter NO modela (p. ej. la liquidación de compra,
     * UBL SelfBilledInvoice). Usa el billService (endpoint `fe`), igual que la factura, y clasifica
     * el CDR por su CÓDIGO (0 aceptado, 1000–3999 rechazo, 4000+ observación), no por isSuccess().
     * Devuelve el mismo arreglo que {@see send()}.
     *
     * @param  string  $unsignedXml  XML UBL sin firmar con el placeholder <ext:ExtensionContent/>
     * @param  string  $name         Nombre del comprobante: {ruc}-{tipoDoc}-{serie}-{correlativo}
     */
    public function sendRawBill(string $unsignedXml, string $name): array
    {
        $this->guardEmisionAutorizada();

        $see = $this->createSee();
        $this->currentSee = $see;

        $cert = $this->resolveCertificatePem();
        $signer = new SignedXml;
        $signer->setCertificate($cert);
        $xml = $signer->signXml($unsignedXml);
        $this->lastXml = $xml;

        // El tipo (Invoice::class) selecciona el BillSender/billService; el XML ya lleva InvoiceTypeCode=04.
        $result = $see->sendXml(\Greenter\Model\Sale\Invoice::class, $name, $xml);

        if ($result instanceof BillResult) {
            $cdr = $result->getCdrResponse();
            $cdrCode = $cdr ? (string) $cdr->getCode() : null;
            $isObservation = $cdrCode !== null && ctype_digit($cdrCode) && (int) $cdrCode >= 4000;
            $isRechazo = $cdrCode !== null && ctype_digit($cdrCode) && (int) $cdrCode >= 1000 && (int) $cdrCode < 4000;

            if (($result->isSuccess() || $isObservation) && ! $isRechazo) {
                return [
                    'success' => true,
                    'xml' => $xml,
                    'cdr_zip' => $result->getCdrZip(),
                    'hash' => $this->extractHashFromXml($xml),
                    'code' => $cdrCode,
                    'description' => $cdr ? $this->sanitizeUtf8($cdr->getDescription()) : null,
                    'notes' => $cdr ? array_map(fn ($n) => $this->sanitizeUtf8($n), $cdr->getNotes() ?? []) : [],
                    'accepted' => true,
                ];
            }

            if ($isRechazo) {
                return [
                    'success' => false,
                    'xml' => $xml,
                    'error_code' => $cdrCode,
                    'error_message' => $cdr ? $this->sanitizeUtf8($cdr->getDescription()) : 'Rechazado por SUNAT',
                ];
            }
        }

        if (! $result->isSuccess()) {
            $error = $result->getError();
            $errorCode = (string) $error->getCode();

            \Illuminate\Support\Facades\Log::warning('sunat.send.failed', [
                'documento' => $name,
                'ruc' => $this->tenant->ruc,
                'entorno' => $this->tenant->environment,
                'error_code' => $errorCode,
                'error_message' => $this->sanitizeUtf8($error->getMessage()),
            ]);

            if (ctype_digit($errorCode) && (int) $errorCode >= 4000) {
                return [
                    'success' => true,
                    'xml' => $xml,
                    'cdr_zip' => $result instanceof BillResult ? $result->getCdrZip() : null,
                    'hash' => $this->extractHashFromXml($xml),
                    'code' => $errorCode,
                    'description' => 'Aceptado con observación',
                    'notes' => [$this->sanitizeUtf8($error->getMessage())],
                    'accepted' => true,
                ];
            }

            return [
                'success' => false,
                'xml' => $xml,
                'error_code' => $errorCode,
                'error_message' => $error->getMessage(),
            ];
        }

        return ['success' => true, 'xml' => $xml, 'accepted' => true];
    }

    public function getStatus(string $ticket, ?string $endpoint = null): array
    {
        $see = $endpoint ? $this->createSee($endpoint) : $this->createSee();

        try {
            $result = $see->getStatus($ticket);
        } catch (\SoapFault $e) {
            return [
                'success' => false,
                'error_code' => 'NETWORK_ERROR',
                'error_message' => $this->sanitizeUtf8($e->getMessage()),
            ];
        }

        if (! $result->isSuccess()) {
            $error = $result->getError();
            $code = (string) $error->getCode();
            $msg = $this->sanitizeUtf8($error->getMessage());

            // Greenter wraps network-layer failures (SoapFaults caught internally) as
            // numeric codes like 200 with messages such as "Failed to process response
            // headers". Those are transient, not SUNAT validation rejections.
            $looksLikeNetworkError = ! is_numeric($code) ||
                str_contains(strtolower($msg), 'failed to process') ||
                str_contains(strtolower($msg), 'could not connect') ||
                str_contains(strtolower($msg), 'connection refused') ||
                str_contains(strtolower($msg), 'timed out');

            if ($looksLikeNetworkError) {
                $code = 'NETWORK_ERROR';
            }

            return [
                'success' => false,
                'error_code' => $code,
                'error_message' => $msg,
            ];
        }

        $cdr = $result->getCdrResponse();

        return [
            'success' => true,
            'cdr_zip' => $result->getCdrZip(),
            'code' => $cdr->getCode(),
            'description' => $this->sanitizeUtf8($cdr->getDescription()),
            'notes' => array_map(fn ($n) => $this->sanitizeUtf8($n), $cdr->getNotes() ?? []),
            'accepted' => $cdr->isAccepted(),
        ];
    }

    public function getGreStatus(string $ticket): array
    {
        $api = $this->createApi();

        try {
            $result = $api->getStatus($ticket);
        } catch (ApiException $e) {
            if ($this->isMissingGreTokenError($e)) {
                try {
                    $this->forgetGreToken();
                    $api = $this->createApi();
                    $result = $api->getStatus($ticket);
                } catch (ApiException $retryException) {
                    return [
                        'success' => false,
                        'error_code' => (string) $retryException->getCode(),
                        'error_message' => $this->sanitizeUtf8($retryException->getMessage()),
                    ];
                }
            } else {
                return [
                    'success' => false,
                    'error_code' => (string) $e->getCode(),
                    'error_message' => $this->sanitizeUtf8($e->getMessage()),
                ];
            }
        } catch (\Throwable $e) {
            return [
                'success' => false,
                'error_code' => (string) $e->getCode(),
                'error_message' => $this->sanitizeUtf8($e->getMessage()),
            ];
        }

        if ($this->isMissingGreTokenResult($result)) {
            $this->forgetGreToken();
            $api = $this->createApi();
            $result = $api->getStatus($ticket);
        }

        if (! $result->isSuccess()) {
            $error = $result->getError();

            return [
                'success' => false,
                'error_code' => (string) $error->getCode(),
                'error_message' => $this->sanitizeUtf8($error->getMessage()),
            ];
        }

        $cdr = $result->getCdrResponse();

        return [
            'success' => true,
            'cdr_zip' => $result->getCdrZip(),
            'code' => $cdr ? (string) $cdr->getCode() : null,
            'description' => $cdr ? $this->sanitizeUtf8($cdr->getDescription()) : null,
            'notes' => $cdr ? array_map(fn ($n) => $this->sanitizeUtf8($n), $cdr->getNotes() ?? []) : [],
            'accepted' => $cdr ? $cdr->isAccepted() : true,
        ];
    }

    public function getXmlSigned(DocumentInterface $document): string
    {
        $see = $this->resolveSee($document);

        return $see->getXmlSigned($document);
    }

    private function extractHashFromXml(?string $xml): ?string
    {
        if (empty($xml)) {
            return null;
        }

        try {
            $doc = new \DOMDocument;
            $doc->loadXML($xml);
            $xpath = new \DOMXPath($doc);
            $xpath->registerNamespace('ds', 'http://www.w3.org/2000/09/xmldsig#');
            $nodes = $xpath->query('//ds:Signature/ds:SignedInfo/ds:Reference/ds:DigestValue');

            if ($nodes && $nodes->length > 0) {
                return $nodes->item(0)->nodeValue;
            }
        } catch (\Throwable) {
            // valor de respaldo
        }

        return null;
    }

    private function sanitizeUtf8(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        // Intentar convertir desde ISO-8859-1 si no es UTF-8 válido
        if (! mb_check_encoding($value, 'UTF-8')) {
            $value = mb_convert_encoding($value, 'UTF-8', 'ISO-8859-1');
        }

        return $value;
    }

    private function resolveSee(DocumentInterface $document): See
    {
        $class = get_class($document);

        // Retenciones (20), Percepciones (40) y su Reversión (RR) se emiten por el
        // servicio "otros CPE" (endpoint retention), NO por el de facturas/resúmenes.
        // La reversión es un Greenter\Model\Voided\Reversion; la Comunicación de Baja
        // normal (RA) es un ...\Voided\Voided y sigue yendo al endpoint estándar.
        if (str_contains($class, 'Retention')
            || str_contains($class, 'Perception')
            || str_contains($class, 'Reversion')) {
            return $this->createSeeRetention();
        }

        return $this->createSee();
    }

    /**
     * Devuelve el certificado en formato PEM para firmar XMLs.
     * Cada tenant debe firmar con su propio certificado.
     * Convierte automáticamente de .p12/.pfx a PEM si es necesario.
     */
    private function resolveCertificatePem(): string
    {
        // El certificado es propiedad de la empresa que emite el documento.
        $tenantCert = $this->tenant->getCertificateContent();
        if ($tenantCert) {
            return $this->toPem($tenantCert, $this->tenant->certificate_password);
        }

        throw new \RuntimeException(
            'Certificado digital no encontrado. '
            .'Cargue el certificado .p12/.pfx de esta empresa desde su configuración.'
        );
    }

    /**
     * Convierte contenido de certificado a PEM si es un archivo .p12/.pfx binario.
     * Si ya está en PEM (empieza con -----) lo devuelve sin cambios.
     */
    private function toPem(string $content, ?string $tenantPassword = null): string
    {
        // Un PEM ya listo se devuelve tal cual, pero antes se comprueba que
        // traiga la clave privada: si solo tiene el certificado público, el
        // fallo aparecía mucho más tarde, dentro de openssl_sign(), como un
        // "Supplied key param cannot be coerced into a private key" que no
        // apunta a ninguna parte.
        if (str_starts_with(ltrim($content), '-----')) {
            if (@openssl_pkey_get_private($content) === false) {
                throw new \RuntimeException(
                    'El certificado digital cargado no incluye la clave privada, así que no puede firmar '
                    .'comprobantes. Hay que volver a subir el archivo .pfx/.p12 entregado por la certificadora '
                    .'(el .cer que se descarga del portal de SUNAT solo tiene la parte pública).'
                );
            }

            return $content;
        }

        $certs = [];
        $password = (string) config('facturacion.certificate.password', '');

        // Intentar con la contraseña configurada, la del tenant, la del RUC PSE, y finalmente vacío
        $attempts = array_filter(array_unique([
            $password,
            $tenantPassword,
            (string) config('facturacion.certificate.pse_ruc', ''),
            '',
        ]), fn ($val) => $val !== null && $val !== '');

        // NUNCA registrar las contraseñas en el log: con la contraseña del
        // certificado se puede firmar en nombre del cliente, y storage/logs no
        // tiene el mismo cuidado que la base de datos.
        $success = false;
        foreach ($attempts as $pass) {
            if (@openssl_pkcs12_read($content, $certs, $pass) && ! empty($certs['pkey'])) {
                $success = true;
                break;
            }
        }

        if (! $success) {
            if (@openssl_pkcs12_read($content, $certs, '') && ! empty($certs['pkey'])) {
                $success = true;
            }
        }

        if (empty($certs) || empty($certs['pkey']) || empty($certs['cert'])) {
            $error = openssl_error_string() ?: 'Error desconocido';
            \Illuminate\Support\Facades\Log::error('toPem failed to decrypt PKCS12', [
                'error' => $error,
            ]);
            throw new \RuntimeException(
                "No se pudo leer el certificado .p12. Verifica CERTIFICATE_PASSWORD en .env. OpenSSL: {$error}"
            );
        }

        // SignedXml::setCertificate() espera: clave privada PEM + certificado PEM concatenados
        return $certs['pkey'].$certs['cert'];
    }
}
