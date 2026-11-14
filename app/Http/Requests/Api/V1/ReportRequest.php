<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

class ReportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'fecha_desde' => 'required|date',
            'fecha_hasta' => 'required|date|after_or_equal:fecha_desde',
            'sucursal_id' => 'nullable|integer|exists:sucursales,id',
            'serie' => 'nullable|string|size:4',
            'client_num_doc' => 'nullable|string|max:15',
            'estado_sunat' => 'nullable|string|in:pendiente,enviado,aceptado,rechazado',
            'estado_pago' => 'nullable|string|in:pendiente,parcial,pagado',
            'tipo_moneda' => 'nullable|string|in:PEN,USD',
            'formato' => 'nullable|string|in:json,pdf,excel',
            'agrupacion' => 'nullable|string|in:dia,semana,mes',
            'vencido' => 'nullable|boolean',
            // Filtro por tipo de comprobante (registro de ventas): 01 factura, 03 boleta, 07 NC, 08 ND.
            'tipos' => 'nullable|array',
            'tipos.*' => 'string|in:01,03,07,08',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v) {
            $desde = $this->input('fecha_desde');
            $hasta = $this->input('fecha_hasta');

            $max = (int) config('reportes.max_rango_dias', 366);
            if ($max > 0 && $desde && $hasta) {
                $diff = \Carbon\Carbon::parse($desde)->diffInDays(\Carbon\Carbon::parse($hasta));
                if ($diff > $max) {
                    $v->errors()->add('fecha_hasta', "El rango máximo permitido es de {$max} días.");
                }
            }
        });
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
