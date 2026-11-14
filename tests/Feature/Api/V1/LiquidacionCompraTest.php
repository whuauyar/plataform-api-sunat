<?php

declare(strict_types=1);

use App\Actions\Documents\CreateLiquidacionCompraAction;
use App\Jobs\SendReversionToSunat;
use App\Models\PurchaseSettlement;
use App\Models\Serie;
use App\Models\Tenant;
use App\Services\Greenter\Builders\LiquidacionCompraXmlBuilder;

function lcHeaders(Tenant $t): array
{
    return ['X-Api-Key' => $t->api_key, 'X-Api-Secret' => $t->api_secret, 'Accept' => 'application/json'];
}

function lcSerie(Tenant $t): Serie
{
    return Serie::create([
        'tenant_id' => $t->id,
        'tipo_documento' => '04',
        'serie' => 'L001',
        'correlativo' => 0,
        'is_active' => true,
    ]);
}

function lcData(): array
{
    return [
        'serie' => 'L001',
        'fecha_emision' => \Carbon\Carbon::now('America/Lima')->format('Y-m-d'),
        'tipo_operacion' => '0501',
        'vendedor' => [
            'tipo_doc' => '1', 'num_doc' => '12345678', 'nombre' => 'JUAN PEREZ AGRICULTOR',
            'direccion' => 'CASERIO LOS ANGELES S/N', 'ubigeo' => '150101', 'tipo_domicilio' => '01',
        ],
        'lugar' => ['direccion' => 'AV. ACOPIO 500, LIMA', 'ubigeo' => '150101', 'tipo' => '01'],
        'items' => [
            ['descripcion' => 'Cafe pergamino seco', 'cantidad' => 100, 'valor_unitario' => 10, 'unidad' => 'KGM', 'cod_producto_sunat' => '10101500', 'tip_afe_igv' => '10'],
        ],
    ];
}

test('la Action persiste la liquidación con montos correctos (IGV + retención de renta)', function () {
    $tenant = Tenant::factory()->create();
    lcSerie($tenant);

    $settlement = app(CreateLiquidacionCompraAction::class)->execute($tenant, lcData(), false);

    expect($settlement)->toBeInstanceOf(PurchaseSettlement::class);
    expect((float) $settlement->mto_oper_gravadas)->toBe(1000.0);
    expect((float) $settlement->mto_igv)->toBe(180.0);
    expect((float) $settlement->valor_venta)->toBe(1000.0);
    expect((float) $settlement->porcentaje_renta)->toBe(1.5);
    expect((float) $settlement->mto_retencion_renta)->toBe(15.0);
    expect((float) $settlement->sub_total)->toBe(1180.0);
    expect((float) $settlement->mto_imp_neto)->toBe(985.0); // neto a pagar = valor_venta - retención
    expect($settlement->sunat_status)->toBe('pendiente');     // enviar_automatico=false
    expect($settlement->items)->toHaveCount(1);
    expect((float) $settlement->items->first()->precio_unitario)->toBe(11.8);

    $this->assertDatabaseHas('purchase_settlements', [
        'id' => $settlement->id, 'serie' => 'L001', 'correlativo' => 1, 'vendedor_num_doc' => '12345678',
    ]);
});

test('el XML generado es SelfBilledInvoice tipo 04 con comprador/vendedor en los roles correctos', function () {
    $tenant = Tenant::factory()->create(['ruc' => '20000000001', 'razon_social' => 'EMPRESA DEMO SAC']);
    lcSerie($tenant);
    $settlement = app(CreateLiquidacionCompraAction::class)->execute($tenant, lcData(), false);

    $xml = (new LiquidacionCompraXmlBuilder)->build($settlement->fresh('items'), $tenant);

    $doc = new DOMDocument;
    expect($doc->loadXML($xml))->toBeTrue();                       // well-formed
    expect($doc->documentElement->localName)->toBe('SelfBilledInvoice');
    expect($doc->getElementsByTagName('InvoiceTypeCode')->item(0)->textContent)->toBe('04');
    // Comprador (emisor, RUC) = AccountingCustomerParty; vendedor (DNI) = AccountingSupplierParty
    $cust = $doc->getElementsByTagName('AccountingCustomerParty')->item(0);
    $supp = $doc->getElementsByTagName('AccountingSupplierParty')->item(0);
    expect($cust->getElementsByTagName('ID')->item(0)->textContent)->toBe('20000000001');
    expect($supp->getElementsByTagName('ID')->item(0)->textContent)->toBe('12345678');
    expect($doc->getElementsByTagName('PayableAmount')->item(0)->textContent)->toBe('985.00');
});

test('genera el PDF de la liquidación de compra (A4)', function () {
    $tenant = Tenant::factory()->create(['ruc' => '20000000001', 'razon_social' => 'EMPRESA DEMO SAC']);
    lcSerie($tenant);
    $settlement = app(CreateLiquidacionCompraAction::class)->execute($tenant, lcData(), false);

    $pdf = app(\App\Services\Pdf\PdfGeneratorService::class)
        ->generate($settlement->fresh('items'), $tenant, \App\Services\Pdf\PdfFormatConfig::A4);

    expect(substr($pdf, 0, 4))->toBe('%PDF');
    expect(strlen($pdf))->toBeGreaterThan(1000);
});

test('el NRUS no puede emitir liquidación de compra (SUNAT 2463)', function () {
    $tenant = Tenant::factory()->create(['tax_regime' => config('regimenes.restringido')]);
    lcSerie($tenant);

    $res = $this->postJson('/api/v1/liquidaciones-compra', lcData(), lcHeaders($tenant));

    $res->assertStatus(422);
    expect($res->json('errores.tax_regime'))->not->toBeNull();
});

test('revierte una liquidación aceptada: crea el RR y la deja en anulacion_en_proceso', function () {
    \Illuminate\Support\Facades\Queue::fake();
    $tenant = Tenant::factory()->create();
    lcSerie($tenant);
    $s = app(CreateLiquidacionCompraAction::class)->execute($tenant, lcData(), false);
    $s->update(['sunat_status' => 'aceptado', 'cdr_fecha' => now()]);

    $res = $this->postJson("/api/v1/liquidaciones-compra/{$s->id}/reversion",
        ['causal' => 'producto', 'motivo' => 'Error en la descripcion del bien'], lcHeaders($tenant));

    $res->assertStatus(201);
    expect($s->fresh()->sunat_status)->toBe('anulacion_en_proceso');
    $this->assertDatabaseHas('voided_documents', ['tenant_id' => $tenant->id, 'total_documentos' => 1]);
    \Illuminate\Support\Facades\Queue::assertPushed(SendReversionToSunat::class);
});

test('no revierte una liquidación que no está aceptada', function () {
    $tenant = Tenant::factory()->create();
    lcSerie($tenant);
    $s = app(CreateLiquidacionCompraAction::class)->execute($tenant, lcData(), false); // queda pendiente

    $res = $this->postJson("/api/v1/liquidaciones-compra/{$s->id}/reversion",
        ['causal' => 'producto', 'motivo' => 'motivo valido'], lcHeaders($tenant));

    $res->assertStatus(422);
});

test('no revierte una liquidación con pagos registrados', function () {
    $tenant = Tenant::factory()->create();
    lcSerie($tenant);
    $s = app(CreateLiquidacionCompraAction::class)->execute($tenant, lcData(), false);
    $s->update(['sunat_status' => 'aceptado', 'cdr_fecha' => now()]);
    $s->payments()->create(['tenant_id' => $tenant->id, 'metodo' => 'efectivo', 'monto' => 100]);
    $s->update(['monto_pagado' => 100]);

    $res = $this->postJson("/api/v1/liquidaciones-compra/{$s->id}/reversion",
        ['causal' => 'producto', 'motivo' => 'motivo valido'], lcHeaders($tenant));

    $res->assertStatus(422);
});

test('rechaza un vendedor con RUC (solo DNI o carné de extranjería)', function () {
    $tenant = Tenant::factory()->create();
    lcSerie($tenant);
    $data = lcData();
    $data['vendedor']['tipo_doc'] = '6';
    $data['vendedor']['num_doc'] = '20123456789';

    $res = $this->postJson('/api/v1/liquidaciones-compra', $data, lcHeaders($tenant));

    $res->assertStatus(422);
    expect($res->json('errores'))->toHaveKey('vendedor.tipo_doc');
});
