<?php

declare(strict_types=1);

use App\Actions\Documents\Concerns\ResolvesEmisionDateTime;
use App\Models\DispatchGuide;
use Carbon\Carbon;

afterEach(fn () => Carbon::setTestNow());

function resolverFecha(string $fecha): string
{
    return (new class {
        use ResolvesEmisionDateTime;
        public function r(string $f): string { return $this->resolveEmisionDateTime($f); }
    })->r($fecha);
}

/** Fecha que el job manda al XML: lo guardado, formateado como Y-m-d (sin convertir tz). */
function fechaXml(string $stored): string
{
    $g = new DispatchGuide();
    $g->fecha_emision = $stored;

    return $g->fecha_emision->format('Y-m-d');
}

test('emisión nocturna (23:44 Lima = 04:44 UTC del día siguiente) → IssueDate del día de Lima', function () {
    // 2026-09-22 23:44 Lima
    Carbon::setTestNow(Carbon::create(2026, 9, 23, 4, 44, 0, 'UTC'));

    $stored = resolverFecha('2026-09-22');           // solo fecha
    expect(fechaXml($stored))->toBe('2026-09-22');   // no 09-23
});

test('datetime con zona UTC (Z) se normaliza a la fecha de Lima', function () {
    // Instante 2026-09-23 04:44Z == 2026-09-22 23:44 Lima
    $stored = resolverFecha('2026-09-23T04:44:00Z');
    expect(fechaXml($stored))->toBe('2026-09-22');
});

test('emisión de madrugada NO retrocede de día', function () {
    // 2026-09-23 01:00 Lima == 06:00 UTC
    Carbon::setTestNow(Carbon::create(2026, 9, 23, 6, 0, 0, 'UTC'));

    $stored = resolverFecha('2026-09-23');
    expect(fechaXml($stored))->toBe('2026-09-23');   // no 09-22
});

test('IssueDate/IssueTime del builder (factura) quedan en hora de Lima', function () {
    Carbon::setTestNow(Carbon::create(2026, 9, 23, 4, 44, 0, 'UTC')); // 23:44 Lima del 22

    $stored = resolverFecha('2026-09-22');

    // SendDocumentToSunat manda 'Y-m-d H:i:s'; el builder hace new DateTime($str, Lima).
    $g = new DispatchGuide();
    $g->fecha_emision = $stored;
    $paraBuilder = $g->fecha_emision->format('Y-m-d H:i:s');
    $dt = new DateTime($paraBuilder, new DateTimeZone('America/Lima'));

    expect($dt->format('Y-m-d'))->toBe('2026-09-22')
        ->and($dt->format('H:i'))->toBe('23:44');
});
