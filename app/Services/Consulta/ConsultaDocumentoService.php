<?php

declare(strict_types=1);

namespace App\Services\Consulta;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Consulta RUC/DNI contra el proveedor elegido por la empresa (o el global del
 * admin). Soporta dos proveedores intercambiables:
 *
 *   - 'json' → api.json.pe   (POST /api/{ruc|dni} con Bearer token; envuelve en `data`)
 *   - 'migo' → api.migo.pe   (GET  /api/v2/{ruc|dni}/{numero} con Bearer token; respuesta plana)
 *
 * Ambos se normalizan a la MISMA forma para que el frontend (selector de cliente,
 * alta de empresa) no tenga que saber qué proveedor se usó.
 *
 * Devuelve un sobre uniforme:
 *   ['ok' => bool, 'status' => int, 'error' => ?string, 'data' => ?array]
 * donde `data` (en éxito) trae:
 *   razon_social, direccion, departamento, provincia, distrito, ubigeo,
 *   estado, condicion, tipo_documento ('6' RUC | '1' DNI)
 */
class ConsultaDocumentoService
{
    public const PROVEEDORES = ['json', 'migo'];

    /**
     * @return array{ok: bool, status: int, error: ?string, data: ?array<string, mixed>}
     */
    public function consultar(string $proveedor, ?string $token, string $numero): array
    {
        $numero = trim($numero);

        if (strlen($numero) !== 8 && strlen($numero) !== 11) {
            return $this->error(422, 'Número inválido (8 dígitos para DNI, 11 para RUC).');
        }

        if (empty($token)) {
            return $this->error(503, 'No hay token de consulta configurado.');
        }

        $esRuc = strlen($numero) === 11;
        $proveedor = in_array($proveedor, self::PROVEEDORES, true) ? $proveedor : 'json';

        try {
            return $proveedor === 'migo'
                ? $this->consultarMigo($token, $numero, $esRuc)
                : $this->consultarJson($token, $numero, $esRuc);
        } catch (\Throwable $e) {
            Log::error("Consulta {$proveedor} falló para {$numero}: ".$e->getMessage());

            return $this->error(500, 'No se pudo conectar con el servicio de consulta.');
        }
    }

    /** api.json.pe — POST /api/{ruc|dni} con { ruc|dni }, respuesta { success, data }. */
    private function consultarJson(string $token, string $numero, bool $esRuc): array
    {
        $response = Http::withToken($token)
            ->acceptJson()
            ->timeout(8)
            ->post(
                $esRuc ? 'https://api.json.pe/api/ruc' : 'https://api.json.pe/api/dni',
                $esRuc ? ['ruc' => $numero] : ['dni' => $numero],
            );

        if (in_array($response->status(), [401, 403], true)) {
            return $this->error(502, 'Token de consulta inválido o vencido. Revísalo en Configuración.');
        }

        $json = $response->json();

        if (! $response->successful() || ! ($json['success'] ?? false) || empty($json['data'])) {
            return $this->error(404, $esRuc ? 'RUC no encontrado.' : 'DNI no encontrado.');
        }

        $d = $json['data'];

        if ($esRuc) {
            return $this->ok([
                'razon_social' => $d['nombre_o_razon_social'] ?? '',
                'direccion' => $d['direccion'] ?? $d['direccion_completa'] ?? '',
                'departamento' => $d['departamento'] ?? '',
                'provincia' => $d['provincia'] ?? '',
                'distrito' => $d['distrito'] ?? '',
                'ubigeo' => $d['ubigeo_sunat'] ?? $d['ubigeo'] ?? '',
                'estado' => $d['estado'] ?? '',
                'condicion' => $d['condicion'] ?? '',
                'tipo_documento' => '6',
            ]);
        }

        $nombre = $d['nombre_completo'] ?? trim(implode(' ', array_filter([
            $d['nombres'] ?? '',
            $d['apellido_paterno'] ?? '',
            $d['apellido_materno'] ?? '',
        ])));

        return $this->ok([
            'razon_social' => $nombre,
            'direccion' => $d['direccion_completa'] ?? $d['direccion'] ?? '',
            'departamento' => $d['departamento'] ?? '',
            'provincia' => $d['provincia'] ?? '',
            'distrito' => $d['distrito'] ?? '',
            'ubigeo' => $d['ubigeo_sunat'] ?? $d['ubigeo'] ?? '',
            'estado' => '',
            'condicion' => '',
            'tipo_documento' => '1',
        ]);
    }

    /** api.migo.pe — GET /api/v2/{ruc|dni}/{numero} con Bearer, respuesta plana. */
    private function consultarMigo(string $token, string $numero, bool $esRuc): array
    {
        $url = $esRuc
            ? "https://api.migo.pe/api/v2/ruc/{$numero}"
            : "https://api.migo.pe/api/v2/dni/{$numero}";

        $response = Http::withToken($token)
            ->acceptJson()
            ->timeout(12) // migo consulta DNI en tiempo real: mayor latencia
            ->get($url);

        if (in_array($response->status(), [401, 403], true)) {
            return $this->error(502, 'Token de consulta inválido o sin saldo en api.migo.pe. Revísalo en Configuración.');
        }

        $d = $response->json();

        if (! $response->successful() || ! is_array($d) || ! ($d['success'] ?? false)) {
            return $this->error(404, $esRuc ? 'RUC no encontrado.' : 'DNI no encontrado.');
        }

        if ($esRuc) {
            $razon = $d['nombre_o_razon_social'] ?? '';
            if ($razon === '') {
                return $this->error(404, 'RUC no encontrado.');
            }

            return $this->ok([
                'razon_social' => $razon,
                'direccion' => $d['direccion'] ?? $d['direccion_simple'] ?? '',
                'departamento' => $d['departamento'] ?? '',
                'provincia' => $d['provincia'] ?? '',
                'distrito' => $d['distrito'] ?? '',
                'ubigeo' => $d['ubigeo'] ?? '',
                'estado' => $d['estado_del_contribuyente'] ?? '',
                'condicion' => $d['condicion_de_domicilio'] ?? '',
                'tipo_documento' => '6',
            ]);
        }

        // DNI: migo solo devuelve el nombre completo (no dirección).
        $nombre = $d['nombre'] ?? '';
        if ($nombre === '') {
            return $this->error(404, 'DNI no encontrado.');
        }

        return $this->ok([
            'razon_social' => $nombre,
            'direccion' => '',
            'departamento' => '',
            'provincia' => '',
            'distrito' => '',
            'ubigeo' => '',
            'estado' => '',
            'condicion' => '',
            'tipo_documento' => '1',
        ]);
    }

    /** @param array<string, mixed> $data */
    private function ok(array $data): array
    {
        return ['ok' => true, 'status' => 200, 'error' => null, 'data' => $data];
    }

    private function error(int $status, string $mensaje): array
    {
        return ['ok' => false, 'status' => $status, 'error' => $mensaje, 'data' => null];
    }
}
