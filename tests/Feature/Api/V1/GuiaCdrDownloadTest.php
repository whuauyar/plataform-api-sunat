<?php

declare(strict_types=1);

/**
 * Issue: GET /api/v1/guias-remision/{id}/cdr respondía 404 porque la ruta/método
 * no existían (solo había xml y pdf). El serving del CDR es idéntico al de xml
 * (getCdrContent), ya cubierto por otros tests de CDR. Aquí solo se verifica que
 * la ruta exista y apunte al método cdr del controlador.
 */
test('la ruta guias-remision/{id}/cdr existe y apunta a DispatchGuideController@cdr', function () {
    $existe = collect(app('router')->getRoutes()->getRoutes())
        ->contains(function ($r) {
            return $r->uri() === 'api/v1/guias-remision/{id}/cdr'
                && str_contains($r->getActionName(), 'DispatchGuideController@cdr');
        });

    expect($existe)->toBeTrue();
});
