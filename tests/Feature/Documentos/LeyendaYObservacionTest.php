<?php

declare(strict_types=1);

use App\Http\Requests\Api\V1\StoreCreditNoteRequest;
use App\Http\Requests\Api\V1\StoreDebitNoteRequest;
use App\Services\DocumentCalculationService;

test('el monto en letras usa "CON" y no "Y" antes de los céntimos', function () {
    $leyenda = (new DocumentCalculationService())->generateLeyenda(1013.35, 'PEN');

    expect($leyenda)->toContain('MIL TRECE CON 35/100')
        ->and($leyenda)->not->toContain(' Y 35/100');
});

test('StoreCreditNoteRequest valida observacion', function () {
    $rules = StoreCreditNoteRequest::create('/api/v1/notas-credito', 'POST', [])->rules();
    expect($rules)->toHaveKey('observacion');
});

test('StoreDebitNoteRequest valida observacion', function () {
    $rules = StoreDebitNoteRequest::create('/api/v1/notas-debito', 'POST', [])->rules();
    expect($rules)->toHaveKey('observacion');
});
