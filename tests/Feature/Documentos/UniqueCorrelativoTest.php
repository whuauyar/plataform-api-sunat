<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schema;

/**
 * Integridad: retención y percepción deben tener el mismo UNIQUE de correlativo
 * que facturas/boletas, para que una condición de carrera no persista dos
 * comprobantes con el mismo número.
 */
test('retentions y perceptions tienen UNIQUE(tenant_id, serie, correlativo)', function (string $tabla) {
    $unico = collect(Schema::getIndexes($tabla))->first(function (array $i) {
        return ($i['unique'] ?? false)
            && collect($i['columns'])->sort()->values()->all() === ['correlativo', 'serie', 'tenant_id'];
    });

    expect($unico)->not->toBeNull("{$tabla} debería tener UNIQUE(tenant_id, serie, correlativo)");
})->with(['retentions', 'perceptions']);
