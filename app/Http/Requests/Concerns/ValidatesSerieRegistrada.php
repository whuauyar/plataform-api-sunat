<?php

declare(strict_types=1);

namespace App\Http\Requests\Concerns;

use App\Models\Serie;
use Illuminate\Contracts\Validation\Validator;

/**
 * Valida que el tenant tenga registrada (y activa) la serie del comprobante que
 * intenta emitir. Sin esto, el firstOrFail() del Action estallaba con un
 * "No query results for model [App\Models\Serie]" — un error técnico feo. Aquí
 * se convierte en un mensaje claro que le dice al emisor exactamente qué hacer:
 * registrar la serie del tipo de comprobante en el módulo Series.
 */
trait ValidatesSerieRegistrada
{
    /**
     * @param  string  $tipoDocumento  Código SUNAT del tipo (01 factura, 03 boleta, 07 NC, 08 ND, 09 guía…)
     * @param  string  $etiqueta       Nombre legible en plural para el mensaje ("facturas", "boletas"…)
     */
    protected function validarSerieRegistrada(Validator $validator, string $tipoDocumento, string $etiqueta): void
    {
        $tenant = $this->get('tenant');
        $serie = $this->input('serie');

        // Si falta el tenant o la serie, otras reglas (required) ya lo reportan.
        if (! $tenant || ! is_string($serie) || $serie === '') {
            return;
        }

        $query = Serie::where('tenant_id', $tenant->id)
            ->where('tipo_documento', $tipoDocumento)
            ->where('serie', $serie);

        // Existe y está activa → todo bien.
        if ((clone $query)->where('is_active', true)->exists()) {
            return;
        }

        // Existe pero está desactivada.
        if ((clone $query)->exists()) {
            $validator->errors()->add(
                'serie',
                "La serie {$serie} está desactivada. Actívala en el módulo Series para poder emitir {$etiqueta}."
            );

            return;
        }

        // No existe ninguna serie de este tipo para el tenant.
        $tieneAlgunaDelTipo = Serie::where('tenant_id', $tenant->id)
            ->where('tipo_documento', $tipoDocumento)
            ->exists();

        $validator->errors()->add(
            'serie',
            $tieneAlgunaDelTipo
                ? "No tienes registrada la serie {$serie} para {$etiqueta}. Revisa el número de serie o regístrala en el módulo Series."
                : "Aún no tienes ninguna serie registrada para {$etiqueta}. Crea una serie de {$etiqueta} en el módulo Series antes de emitir."
        );
    }
}
