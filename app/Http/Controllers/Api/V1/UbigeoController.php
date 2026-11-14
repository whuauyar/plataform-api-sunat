<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Ubigeos INEI/SUNAT para selects dependientes: región → provincia → distrito.
 * El id del distrito (6 dígitos) es el ubigeo que va a partida_ubigeo/llegada_ubigeo.
 */
class UbigeoController extends Controller
{
    use ApiResponse;

    public function regiones(): JsonResponse
    {
        return $this->success(
            DB::table('ubigeo_regiones')->orderBy('nombre')->get(['id', 'nombre'])
        );
    }

    public function provincias(Request $request): JsonResponse
    {
        $regionId = (string) $request->query('region_id', '');
        if (! preg_match('/^\d{2}$/', $regionId)) {
            return $this->error('El parámetro region_id es requerido (2 dígitos).', 422);
        }

        return $this->success(
            DB::table('ubigeo_provincias')
                ->where('region_id', $regionId)
                ->orderBy('nombre')
                ->get(['id', 'nombre', 'region_id'])
        );
    }

    public function distritos(Request $request): JsonResponse
    {
        $provinciaId = (string) $request->query('provincia_id', '');
        if (! preg_match('/^\d{4}$/', $provinciaId)) {
            return $this->error('El parámetro provincia_id es requerido (4 dígitos).', 422);
        }

        return $this->success(
            DB::table('ubigeo_distritos')
                ->where('provincia_id', $provinciaId)
                ->orderBy('nombre')
                ->get(['id', 'nombre', 'provincia_id'])
        );
    }
}
