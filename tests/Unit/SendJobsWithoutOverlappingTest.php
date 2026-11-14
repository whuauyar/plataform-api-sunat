<?php

declare(strict_types=1);

use App\Jobs\SendDispatchGuideToSunat;
use App\Jobs\SendLiquidacionCompraToSunat;
use App\Jobs\SendPerceptionToSunat;
use App\Jobs\SendRetentionToSunat;
use App\Jobs\SendReversionToSunat;
use App\Jobs\SendSummaryToSunat;
use App\Jobs\SendVoidedToSunat;
use Illuminate\Queue\Middleware\WithoutOverlapping;

/**
 * Cada job de envío secundario debe serializar el envío del MISMO comprobante
 * (WithoutOverlapping con clave estable por tipo+id), para evitar que dos workers
 * lo manden a SUNAT a la vez y provoquen un duplicado.
 */
test('el job de envío serializa el mismo comprobante con WithoutOverlapping', function (string $clase, string $prefijo) {
    $job = new $clase(7);

    $wo = collect($job->middleware())->first(fn ($m) => $m instanceof WithoutOverlapping);

    expect($wo)->not->toBeNull("{$clase} debería declarar WithoutOverlapping");
    expect($wo->key)->toBe($prefijo.'7');
})->with([
    [SendRetentionToSunat::class, 'sunat-send:retention:'],
    [SendPerceptionToSunat::class, 'sunat-send:perception:'],
    [SendSummaryToSunat::class, 'sunat-send:summary:'],
    [SendVoidedToSunat::class, 'sunat-send:voided:'],
    [SendReversionToSunat::class, 'sunat-send:reversion:'],
    [SendLiquidacionCompraToSunat::class, 'sunat-send:settlement:'],
    [SendDispatchGuideToSunat::class, 'sunat-send:guide:'],
]);

test('el job de envío va a la cola dedicada sunat', function (string $clase) {
    expect((new $clase(1))->queue)->toBe('sunat');
})->with([
    SendRetentionToSunat::class,
    SendPerceptionToSunat::class,
    SendSummaryToSunat::class,
    SendVoidedToSunat::class,
    SendReversionToSunat::class,
    SendLiquidacionCompraToSunat::class,
    SendDispatchGuideToSunat::class,
]);
