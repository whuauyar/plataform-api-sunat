<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use App\Http\Requests\Concerns\ValidatesManualCorrelativo;
use App\Http\Requests\Concerns\ValidatesSerieRegistrada;
use App\Models\PurchaseSettlement;
use App\Rules\SunatProductCode;
use Carbon\Carbon;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

/**
 * Liquidación de compra (tipo 04). Reglas del Anexo N.° 9-A literal e) de la RS 097-2012/SUNAT
 * y las validaciones SUNAT: vendedor persona natural sin RUC (DNI/CE), NRUS no puede emitir,
 * plazo de emisión (hoy o día siguiente), oro (0503) y tope de 75 UIT por vendedor al año.
 */
class StoreLiquidacionCompraRequest extends FormRequest
{
    use ValidatesManualCorrelativo, ValidatesSerieRegistrada;

    /** Catálogo 60 — tipo de domicilio del vendedor y lugar de la operación. */
    private const CAT60 = ['01', '02', '03', '04', '05'];

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'serie' => ['required', 'string', 'size:4', 'regex:/^L[A-Z0-9]{3}$/'],
            'correlativo' => 'nullable|integer|min:1|max:99999999',
            'fecha_emision' => 'required|date',
            'tipo_operacion' => 'nullable|string|in:0501,0502,0503',
            'tipo_moneda' => 'nullable|string|in:PEN',
            'productos_agropecuarios' => 'nullable|boolean',

            // Vendedor (proveedor persona natural sin RUC)
            'vendedor' => 'required|array',
            'vendedor.tipo_doc' => 'required|string|in:1,4',
            'vendedor.num_doc' => 'required|string|max:15',
            'vendedor.nombre' => 'required|string|min:3|max:1500',
            'vendedor.direccion' => 'required|string|min:3|max:250',
            'vendedor.ubigeo' => 'required|string|digits:6',
            'vendedor.tipo_domicilio' => ['required', 'string', 'in:'.implode(',', self::CAT60)],

            // Lugar de la operación
            'lugar' => 'required|array',
            'lugar.direccion' => 'required|string|min:3|max:250',
            'lugar.ubigeo' => 'required|string|digits:6',
            'lugar.tipo' => ['required', 'string', 'in:'.implode(',', self::CAT60)],

            // Ítems
            'items' => 'required|array|min:1|max:999',
            'items.*.descripcion' => 'required|string|max:500',
            'items.*.cantidad' => 'required|numeric|gt:0',
            'items.*.valor_unitario' => 'required|numeric|gt:0',
            'items.*.unidad' => 'nullable|string|max:3',
            'items.*.codigo' => 'nullable|string|max:30',
            'items.*.cod_producto_sunat' => ['required', 'string', 'digits:8', new SunatProductCode],
            'items.*.tip_afe_igv' => 'nullable|string|in:10,20,30',
            'items.*.oro' => 'nullable|array',
            'items.*.oro.codigo_concesion' => 'nullable|string|max:50',
            'items.*.oro.ley_mineral' => 'nullable|numeric|min:0|max:100',
            'items.*.oro.naturaleza_mineral' => 'nullable|string|max:6',
            'items.*.oro.nombre_derecho_minero' => 'nullable|string|max:100',

            // Guías de remisión relacionadas
            'guias' => 'nullable|array|max:10',
            'guias.*.tipo' => 'required|string|in:09,31',
            'guias.*.numero' => 'required|string|max:30',

            'placa_vehiculo' => 'nullable|string|regex:/^[A-Z0-9]{6,8}$/',
            'observacion' => 'nullable|string|max:500',
        ];
    }

    public function messages(): array
    {
        return [
            'serie.regex' => 'La serie de liquidación de compra debe empezar con L seguida de 3 caracteres alfanuméricos (ej: L001).',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v) {
            $this->validarSerieRegistrada($v, '04', 'liquidaciones de compra');
            $this->validarCorrelativoManual($v, PurchaseSettlement::class);

            $tenant = $this->get('tenant');

            // NRUS no puede emitir liquidación de compra (SUNAT 2463): es un comprobante que
            // solo emite un contribuyente afecto al Impuesto a la Renta de tercera categoría.
            if ($tenant && $tenant->tax_regime === config('regimenes.restringido')) {
                $v->errors()->add('tax_regime', 'La liquidación de compra solo la emite un contribuyente afecto al Impuesto a la '
                    .'Renta de tercera categoría; el Nuevo RUS no puede emitirla (SUNAT 2463).');
            }

            if ($this->input('tipo_operacion') === '0502') {
                $v->errors()->add('tipo_operacion', 'La liquidación de compra por anticipos (0502) aún no está disponible en esta API.');
            }

            // Documento del vendedor
            $tipoDoc = $this->input('vendedor.tipo_doc');
            $numDoc = (string) $this->input('vendedor.num_doc', '');
            if ($tipoDoc === '1' && ! preg_match('/^\d{8}$/', $numDoc)) {
                $v->errors()->add('vendedor.num_doc', 'El DNI del vendedor debe tener 8 dígitos.');
            } elseif ($tipoDoc === '4' && ! preg_match('/^[A-Za-z0-9]{1,15}$/', $numDoc)) {
                $v->errors()->add('vendedor.num_doc', 'El carné de extranjería debe ser alfanumérico, de hasta 15 caracteres y sin espacios (SUNAT 2802).');
            }

            // Plazo de emisión: hoy o hasta el día calendario siguiente (RS 097-2012/SUNAT art. 12.1 b)
            $fechaRaw = $this->input('fecha_emision');
            $fecha = $this->parseDate($fechaRaw);
            if ($fecha !== null) {
                $hoy = Carbon::now('America/Lima')->startOfDay();
                if ($fecha->gt($hoy)) {
                    $v->errors()->add('fecha_emision', 'La fecha de emisión no puede ser posterior a la fecha de envío (SUNAT 2329).');
                } elseif ($fecha->lt($hoy->copy()->subDay())) {
                    $v->errors()->add('fecha_emision', 'La liquidación de compra se envía en su fecha de emisión o hasta el día calendario '
                        .'siguiente; fuera de ese plazo no tiene calidad de liquidación de compra (RS 097-2012/SUNAT, art. 12.1 b; SUNAT 2108).');
                }
            }

            // Oro (0503): cada ítem exige los datos de la concesión minera (catálogo 55)
            if ($this->input('tipo_operacion') === '0503') {
                foreach ((array) $this->input('items', []) as $i => $item) {
                    $oro = is_array($item['oro'] ?? null) ? $item['oro'] : [];
                    foreach (['codigo_concesion', 'ley_mineral', 'naturaleza_mineral', 'nombre_derecho_minero'] as $campo) {
                        if (($oro[$campo] ?? null) === null || $oro[$campo] === '') {
                            $v->errors()->add("items.{$i}.oro.{$campo}", 'La compra de oro (0503) exige '
                                .str_replace('_', ' ', $campo).' en cada ítem (catálogo 55).');
                        }
                    }
                }
            }

            // Tope de 75 UIT por vendedor al año (SUNAT 2451)
            if ($tenant && $fecha !== null && ! $v->errors()->has('vendedor.num_doc')) {
                $this->validarLimiteAnual($v, $tenant, $numDoc, $fecha);
            }
        });
    }

    private function validarLimiteAnual(Validator $v, $tenant, string $numDoc, Carbon $fecha): void
    {
        $uit = $this->uitDe((int) $fecha->year);
        if ($uit === null) {
            return;
        }

        $actual = 0.0;
        foreach ((array) $this->input('items', []) as $item) {
            if (is_numeric($item['cantidad'] ?? null) && is_numeric($item['valor_unitario'] ?? null)) {
                $actual += (float) $item['cantidad'] * (float) $item['valor_unitario'];
            }
        }

        $acumulado = (float) PurchaseSettlement::where('tenant_id', $tenant->id)
            ->where('vendedor_num_doc', $numDoc)
            ->where('fecha_emision', '>=', $fecha->copy()->startOfYear())
            ->whereNotIn('sunat_status', ['rechazado', 'anulado'])
            ->sum('valor_venta');

        $limite = $uit * (int) config('liquidacion.limite_uit');
        if ($acumulado + $actual > $limite) {
            $v->errors()->add('vendedor.num_doc', "Las compras al vendedor en {$fecha->year} superarían ".config('liquidacion.limite_uit')
                .' UIT (S/ '.number_format($limite, 2, '.', '').'): no corresponde emitir liquidación de compra (SUNAT 2451). '
                .'Acumulado: S/ '.number_format($acumulado, 2, '.', '').'.');
        }
    }

    /** UIT del año; si no está configurada, la del último año configurado anterior. */
    private function uitDe(int $anio): ?float
    {
        $uit = (array) config('liquidacion.uit', []);
        krsort($uit);
        foreach ($uit as $year => $valor) {
            if ((int) $year <= $anio) {
                return (float) $valor;
            }
        }

        return null;
    }

    private function parseDate($value): ?Carbon
    {
        if (! is_string($value) || $value === '') {
            return null;
        }
        try {
            return Carbon::parse($value, 'America/Lima')->startOfDay();
        } catch (\Throwable) {
            return null;
        }
    }

    protected function failedValidation(Validator $validator): void
    {
        throw new HttpResponseException(response()->json([
            'estado' => 'error',
            'mensaje' => 'Error de validación',
            'errores' => $validator->errors(),
        ], 422));
    }
}
