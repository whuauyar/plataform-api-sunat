<?php

namespace App\Services\Greenter\Builders;

use App\Models\PurchaseSettlement;
use App\Models\PurchaseSettlementItem;
use App\Models\Tenant;

/**
 * XML de la liquidación de compra electrónica: UBL 2.1 {@code SelfBilledInvoice}, versión 2.0.
 * Greenter NO modela este documento (su plantilla de factura usa la raíz Invoice y roles de parte
 * invertidos), por eso se construye a mano.
 *
 * Fuentes: Anexo N.° 9-A literal e) de la RS 097-2012/SUNAT (incorporado por la RS 244-2019/SUNAT);
 * orden de elementos del esquema OASIS UBL-SelfBilledInvoice-2.1.xsd (el comprador,
 * AccountingCustomerParty, va ANTES que el vendedor, AccountingSupplierParty); tributos IGV
 * 1000/9997/9998 y retención de renta 3000 (IR, TOX), que no suma al TaxTotal de IGV.
 *
 * El XML queda SIN firmar con el placeholder {@code <ext:ExtensionContent/>} y el bloque
 * {@code cac:Signature} con URI {@code #GREENTER-SIGN}, igual que las facturas, para firmarlo con
 * el {@code SignedXml} de Greenter.
 */
class LiquidacionCompraXmlBuilder
{
    private const CAT = 'urn:pe:gob:sunat:cpe:see:gem:catalogos:catalogo';

    private const UNECE = 'United Nations Economic Commission for Europe';

    /** Oro (0503): propiedades del catálogo 55 → columna json `oro` del ítem. */
    private const ORO = [
        '6000' => ['codigo_concesion', 'Comercialización de Oro :  Código Unico Concesión Minera'],
        '6004' => ['ley_mineral', 'Comercialización de Oro : Ley Mineral (% concent. oro)'],
        '6005' => ['naturaleza_mineral', 'Comercialización de Oro : Naturaleza del mineral'],
        '6006' => ['nombre_derecho_minero', 'Comercialización de Oro : Nombre del derecho minero'],
    ];

    public function build(PurchaseSettlement $s, Tenant $tenant): string
    {
        $cur = $s->tipo_moneda ?: 'PEN';
        $x = '<?xml version="1.0" encoding="utf-8"?>';
        $x .= '<SelfBilledInvoice xmlns="urn:oasis:names:specification:ubl:schema:xsd:SelfBilledInvoice-2"'
            .' xmlns:cac="urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2"'
            .' xmlns:cbc="urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2"'
            .' xmlns:ds="http://www.w3.org/2000/09/xmldsig#"'
            .' xmlns:ext="urn:oasis:names:specification:ubl:schema:xsd:CommonExtensionComponents-2">';
        $x .= '<ext:UBLExtensions><ext:UBLExtension><ext:ExtensionContent/></ext:UBLExtension></ext:UBLExtensions>';
        $x .= '<cbc:UBLVersionID>2.1</cbc:UBLVersionID>';
        $x .= '<cbc:CustomizationID schemeAgencyName="PE:SUNAT">2.0</cbc:CustomizationID>';
        $x .= $this->el('cbc:ID', $s->serie.'-'.$s->correlativo);
        $fecha = $s->fecha_emision;
        $x .= $this->el('cbc:IssueDate', $fecha->format('Y-m-d'));
        $x .= $this->el('cbc:IssueTime', $fecha->format('H:i:s'));
        $x .= '<cbc:InvoiceTypeCode listAgencyName="PE:SUNAT" listName="Tipo de Documento" listURI="'.self::CAT.'01"'
            .' listID="'.$this->attr($s->tipo_operacion).'" name="Tipo de Operacion" listSchemeURI="'.self::CAT.'51">04</cbc:InvoiceTypeCode>';
        if ($s->leyenda !== null && $s->leyenda !== '') {
            $x .= '<cbc:Note languageLocaleID="1000">'.$this->cdata($s->leyenda).'</cbc:Note>';
        }
        $x .= '<cbc:DocumentCurrencyCode listID="ISO 4217 Alpha" listName="Currency" listAgencyName="'.self::UNECE.'">'
            .$this->esc($cur).'</cbc:DocumentCurrencyCode>';

        foreach ((array) ($s->guias ?? []) as $g) {
            $x .= '<cac:DespatchDocumentReference>';
            $x .= $this->el('cbc:ID', (string) ($g['numero'] ?? ''));
            $x .= '<cbc:DocumentTypeCode listAgencyName="PE:SUNAT" listName="Tipo de Documento" listURI="'.self::CAT.'01">'
                .$this->esc((string) ($g['tipo'] ?? '')).'</cbc:DocumentTypeCode>';
            $x .= '</cac:DespatchDocumentReference>';
        }

        $x .= $this->signature($tenant);
        $x .= $this->comprador($tenant);
        $x .= $this->vendedor($s);

        if ($s->placa_vehiculo !== null && $s->placa_vehiculo !== '') {
            // Traslado sustentado con la liquidación (art. 29-D): motivo 02 (compra) y placa
            $x .= '<cac:Delivery><cac:Shipment>'.$this->el('cbc:ID', '02')
                .'<cac:ShipmentStage><cac:TransportMeans><cac:RoadTransport>'
                .$this->el('cbc:LicensePlateID', $s->placa_vehiculo)
                .'</cac:RoadTransport></cac:TransportMeans></cac:ShipmentStage>'
                .'</cac:Shipment></cac:Delivery>';
        }

        $x .= '<cac:DeliveryTerms><cac:DeliveryLocation>';
        $x .= $this->el('cbc:LocationTypeCode', $s->lugar_tipo);
        $x .= '<cac:Address>';
        $x .= $this->ubigeo($s->lugar_ubigeo);
        $x .= '<cac:AddressLine>'.$this->cdataEl('cbc:Line', $s->lugar_direccion).'</cac:AddressLine>';
        $x .= $this->country();
        $x .= '</cac:Address></cac:DeliveryLocation></cac:DeliveryTerms>';

        // TaxTotal de cabecera: el TaxAmount global es solo el IGV; la renta (3000) va como
        // subtotal informativo y NO suma al TaxAmount.
        $gravadas = (float) $s->mto_oper_gravadas;
        $exoneradas = (float) $s->mto_oper_exoneradas;
        $inafectas = (float) $s->mto_oper_inafectas;
        $x .= '<cac:TaxTotal>';
        $x .= $this->amount('cbc:TaxAmount', $cur, $s->mto_igv);
        if ($gravadas > 0 || ($exoneradas == 0.0 && $inafectas == 0.0)) {
            $x .= $this->subtotal($cur, $s->mto_oper_gravadas, $s->mto_igv, '1000', 'IGV', 'VAT');
        }
        if ($exoneradas > 0) {
            $x .= $this->subtotal($cur, $s->mto_oper_exoneradas, 0, '9997', 'EXO', 'VAT');
        }
        if ($inafectas > 0) {
            $x .= $this->subtotal($cur, $s->mto_oper_inafectas, 0, '9998', 'INA', 'FRE');
        }
        $x .= $this->subtotal($cur, $s->mto_base_renta, $s->mto_retencion_renta, '3000', 'IR', 'TOX');
        $x .= '</cac:TaxTotal>';

        $x .= '<cac:LegalMonetaryTotal>';
        $x .= $this->amount('cbc:LineExtensionAmount', $cur, $s->valor_venta);
        $x .= $this->amount('cbc:TaxInclusiveAmount', $cur, $s->sub_total);
        $x .= $this->amount('cbc:PayableAmount', $cur, $s->mto_imp_neto);
        $x .= '</cac:LegalMonetaryTotal>';

        $i = 0;
        foreach ($s->items as $line) {
            $x .= $this->line($line, ++$i, $cur);
        }

        $x .= '</SelfBilledInvoice>';

        return $x;
    }

    private function signature(Tenant $t): string
    {
        return '<cac:Signature>'
            .$this->el('cbc:ID', 'SIGN'.$t->ruc)
            .'<cac:SignatoryParty>'
            .'<cac:PartyIdentification>'.$this->el('cbc:ID', $t->ruc).'</cac:PartyIdentification>'
            .'<cac:PartyName>'.$this->cdataEl('cbc:Name', $t->razon_social).'</cac:PartyName>'
            .'</cac:SignatoryParty>'
            .'<cac:DigitalSignatureAttachment><cac:ExternalReference>'
            .$this->el('cbc:URI', '#GREENTER-SIGN')
            .'</cac:ExternalReference></cac:DigitalSignatureAttachment>'
            .'</cac:Signature>';
    }

    /** Comprador = emisor (empresa con RUC): AccountingCustomerParty. */
    private function comprador(Tenant $t): string
    {
        $x = '<cac:AccountingCustomerParty><cac:Party>';
        $x .= '<cac:PartyIdentification>';
        $x .= '<cbc:ID schemeID="6" schemeName="Documento de Identidad" schemeAgencyName="PE:SUNAT" schemeURI="'.self::CAT.'06">'
            .$this->esc($t->ruc).'</cbc:ID>';
        $x .= '</cac:PartyIdentification>';
        if ($t->nombre_comercial !== null && $t->nombre_comercial !== '') {
            $x .= '<cac:PartyName>'.$this->cdataEl('cbc:Name', $t->nombre_comercial).'</cac:PartyName>';
        }
        $x .= '<cac:PartyLegalEntity>'.$this->cdataEl('cbc:RegistrationName', $t->razon_social);
        $x .= '<cac:RegistrationAddress>';
        if (! empty($t->ubigeo)) {
            $x .= $this->ubigeo($t->ubigeo);
        }
        if (! empty($t->provincia)) {
            $x .= $this->el('cbc:CityName', $t->provincia);
        }
        if (! empty($t->departamento)) {
            $x .= $this->el('cbc:CountrySubentity', $t->departamento);
        }
        if (! empty($t->distrito)) {
            $x .= $this->el('cbc:District', $t->distrito);
        }
        $x .= '<cac:AddressLine>'.$this->cdataEl('cbc:Line', $t->direccion ?: '-').'</cac:AddressLine>';
        $x .= $this->country();
        $x .= '</cac:RegistrationAddress>';
        $x .= '</cac:PartyLegalEntity>';
        $x .= '</cac:Party></cac:AccountingCustomerParty>';

        return $x;
    }

    /** Vendedor = proveedor persona natural sin RUC (DNI/CE): AccountingSupplierParty. */
    private function vendedor(PurchaseSettlement $s): string
    {
        $x = '<cac:AccountingSupplierParty><cac:Party>';
        $x .= '<cac:PartyIdentification>';
        $x .= '<cbc:ID schemeID="'.$this->attr($s->vendedor_tipo_doc).'" schemeName="Documento de Identidad" schemeAgencyName="PE:SUNAT" schemeURI="'.self::CAT.'06">'
            .$this->esc($s->vendedor_num_doc).'</cbc:ID>';
        $x .= '</cac:PartyIdentification>';
        $x .= '<cac:PartyLegalEntity>'.$this->cdataEl('cbc:RegistrationName', $s->vendedor_nombre);
        $x .= '<cac:RegistrationAddress>';
        $x .= $this->ubigeo($s->vendedor_ubigeo);
        $x .= $this->el('cbc:AddressTypeCode', $s->vendedor_tipo_domicilio);
        $x .= '<cac:AddressLine>'.$this->cdataEl('cbc:Line', $s->vendedor_direccion).'</cac:AddressLine>';
        $x .= $this->country();
        $x .= '</cac:RegistrationAddress>';
        $x .= '</cac:PartyLegalEntity>';
        $x .= '</cac:Party></cac:AccountingSupplierParty>';

        return $x;
    }

    private function line(PurchaseSettlementItem $l, int $n, string $cur): string
    {
        $x = '<cac:InvoiceLine>';
        $x .= $this->el('cbc:ID', (string) $n);
        $x .= '<cbc:InvoicedQuantity unitCode="'.$this->attr($l->unidad).'" unitCodeListID="UN/ECE rec 20" unitCodeListAgencyName="'.self::UNECE.'">'
            .$this->dec($l->cantidad).'</cbc:InvoicedQuantity>';
        $x .= $this->amount('cbc:LineExtensionAmount', $cur, $l->valor_venta);
        $x .= '<cac:PricingReference><cac:AlternativeConditionPrice>';
        $x .= '<cbc:PriceAmount currencyID="'.$this->attr($cur).'">'.$this->dec($l->precio_unitario).'</cbc:PriceAmount>';
        $x .= '<cbc:PriceTypeCode listName="Tipo de Precio" listAgencyName="PE:SUNAT" listURI="'.self::CAT.'16">01</cbc:PriceTypeCode>';
        $x .= '</cac:AlternativeConditionPrice></cac:PricingReference>';

        $x .= '<cac:TaxTotal>';
        $x .= $this->amount('cbc:TaxAmount', $cur, $l->igv);
        $t = match ($l->tip_afe_igv) {
            '20' => ['9997', 'EXO', 'VAT'],
            '30' => ['9998', 'INA', 'FRE'],
            default => ['1000', 'IGV', 'VAT'],
        };
        $x .= '<cac:TaxSubtotal>';
        $x .= $this->amount('cbc:TaxableAmount', $cur, $l->valor_venta);
        $x .= $this->amount('cbc:TaxAmount', $cur, $l->igv);
        $x .= '<cac:TaxCategory>';
        $x .= $this->el('cbc:Percent', $this->dec($l->porcentaje_igv));
        $x .= '<cbc:TaxExemptionReasonCode listAgencyName="PE:SUNAT" listName="Afectacion del IGV" listURI="'.self::CAT.'07">'
            .$this->esc($l->tip_afe_igv).'</cbc:TaxExemptionReasonCode>';
        $x .= $this->scheme($t[0], $t[1], $t[2]);
        $x .= '</cac:TaxCategory></cac:TaxSubtotal>';
        // Retención de renta (3000): sin código de afectación (ERR-3050)
        $x .= '<cac:TaxSubtotal>';
        $x .= $this->amount('cbc:TaxableAmount', $cur, $l->valor_venta);
        $x .= $this->amount('cbc:TaxAmount', $cur, $l->renta);
        $x .= '<cac:TaxCategory>';
        $x .= $this->el('cbc:Percent', $this->dec($l->porcentaje_renta));
        $x .= $this->scheme('3000', 'IR', 'TOX');
        $x .= '</cac:TaxCategory></cac:TaxSubtotal>';
        $x .= '</cac:TaxTotal>';

        $x .= '<cac:Item>';
        $x .= $this->cdataEl('cbc:Description', $l->descripcion);
        if ($l->codigo !== null && $l->codigo !== '') {
            $x .= '<cac:SellersItemIdentification>'.$this->el('cbc:ID', $l->codigo).'</cac:SellersItemIdentification>';
        }
        $x .= '<cac:CommodityClassification>';
        $x .= '<cbc:ItemClassificationCode listID="UNSPSC" listAgencyName="GS1 US" listName="Item Classification">'
            .$this->esc($l->cod_producto_sunat).'</cbc:ItemClassificationCode>';
        $x .= '</cac:CommodityClassification>';
        foreach ($this->oroProperties($l) as [$code, $name, $value]) {
            $x .= '<cac:AdditionalItemProperty>';
            $x .= $this->el('cbc:Name', $name);
            $x .= '<cbc:NameCode listName="Propiedad del item" listAgencyName="PE:SUNAT" listURI="'.self::CAT.'55">'
                .$this->esc($code).'</cbc:NameCode>';
            $x .= $this->el('cbc:Value', $value);
            $x .= '</cac:AdditionalItemProperty>';
        }
        $x .= '</cac:Item>';
        $x .= '<cac:Price><cbc:PriceAmount currencyID="'.$this->attr($cur).'">'.$this->dec($l->valor_unitario).'</cbc:PriceAmount></cac:Price>';
        $x .= '</cac:InvoiceLine>';

        return $x;
    }

    /** @return array<int, array{0:string,1:string,2:string}> */
    private function oroProperties(PurchaseSettlementItem $l): array
    {
        $oro = $l->oro;
        if (! is_array($oro)) {
            return [];
        }
        $out = [];
        foreach (self::ORO as $code => [$campo, $name]) {
            $v = $oro[$campo] ?? null;
            if ($v !== null && $v !== '') {
                $out[] = [$code, $name, (string) $v];
            }
        }

        return $out;
    }

    private function subtotal(string $cur, $base, $monto, string $code, string $name, string $type): string
    {
        return '<cac:TaxSubtotal>'
            .$this->amount('cbc:TaxableAmount', $cur, $base)
            .$this->amount('cbc:TaxAmount', $cur, $monto)
            .'<cac:TaxCategory>'.$this->scheme($code, $name, $type).'</cac:TaxCategory>'
            .'</cac:TaxSubtotal>';
    }

    private function scheme(string $code, string $name, string $type): string
    {
        return '<cac:TaxScheme>'
            .'<cbc:ID schemeName="Codigo de tributos" schemeAgencyName="PE:SUNAT" schemeURI="'.self::CAT.'05">'.$this->esc($code).'</cbc:ID>'
            .$this->el('cbc:Name', $name)
            .$this->el('cbc:TaxTypeCode', $type)
            .'</cac:TaxScheme>';
    }

    private function ubigeo(string $ubigeo): string
    {
        return '<cbc:ID schemeAgencyName="PE:INEI" schemeName="Ubigeos">'.$this->esc($ubigeo).'</cbc:ID>';
    }

    private function country(): string
    {
        return '<cac:Country><cbc:IdentificationCode listID="ISO 3166-1" listAgencyName="'.self::UNECE.'" listName="Country">PE</cbc:IdentificationCode></cac:Country>';
    }

    private function amount(string $tag, string $cur, $value): string
    {
        return '<'.$tag.' currencyID="'.$this->attr($cur).'">'.number_format((float) $value, 2, '.', '').'</'.$tag.'>';
    }

    private function el(string $tag, string $value): string
    {
        return '<'.$tag.'>'.$this->esc($value).'</'.$tag.'>';
    }

    private function cdataEl(string $tag, ?string $value): string
    {
        return '<'.$tag.'>'.$this->cdata((string) $value).'</'.$tag.'>';
    }

    private function cdata(string $value): string
    {
        return '<![CDATA['.str_replace(']]>', ']]]]><![CDATA[>', $value).']]>';
    }

    private function esc(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }

    private function attr(?string $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
    }

    /** Decimal sin ceros sobrantes ni notación científica (hasta 10 decimales). */
    private function dec($value): string
    {
        $s = number_format((float) $value, 10, '.', '');
        if (str_contains($s, '.')) {
            $s = rtrim(rtrim($s, '0'), '.');
        }

        return $s === '' || $s === '-0' ? '0' : $s;
    }
}
