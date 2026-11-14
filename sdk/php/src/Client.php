<?php

declare(strict_types=1);

namespace JorgeChavez\ApiSunat;

/**
 * Cliente de la API Jorge Chavez API SUNAT (sin dependencias, usa cURL).
 *
 * Autenticación por cabeceras X-Api-Key / X-Api-Secret. Todas las llamadas
 * devuelven el array decodificado del JSON de respuesta, o lanzan ApiException
 * si la API responde con error.
 *
 * Los métodos de emisión aceptan una $idempotencyKey opcional: envíala para que
 * un reintento tras un corte de red no genere un comprobante duplicado.
 */
class Client
{
    private string $baseUrl;

    /**
     * @param  string  $apiKey  X-Api-Key de tu empresa (panel)
     * @param  string  $apiSecret  X-Api-Secret de tu empresa (panel)
     * @param  string  $baseUrl  p. ej. https://tu-dominio.com (sin /api/v1)
     * @param  int  $timeout  segundos
     */
    public function __construct(
        private string $apiKey,
        private string $apiSecret,
        string $baseUrl,
        private int $timeout = 30,
    ) {
        $this->baseUrl = rtrim($baseUrl, '/').'/api/v1';
    }

    // ── Métodos HTTP genéricos ───────────────────────────────────────────────

    /**
     * @return array<string, mixed>
     */
    public function get(string $path, array $query = []): array
    {
        $url = $path.($query ? '?'.http_build_query($query) : '');

        return $this->request('GET', $url);
    }

    /**
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     */
    public function post(string $path, array $body = [], ?string $idempotencyKey = null): array
    {
        return $this->request('POST', $path, $body, $idempotencyKey);
    }

    /**
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     */
    public function put(string $path, array $body = [], ?string $idempotencyKey = null): array
    {
        return $this->request('PUT', $path, $body, $idempotencyKey);
    }

    /**
     * @return array<string, mixed>
     */
    public function delete(string $path, ?string $idempotencyKey = null): array
    {
        return $this->request('DELETE', $path, [], $idempotencyKey);
    }

    /**
     * Descarga binaria (PDF/XML/CDR); devuelve los bytes crudos.
     */
    public function descargar(string $path, array $query = []): string
    {
        $url = $path.($query ? '?'.http_build_query($query) : '');

        return $this->request('GET', $url, [], null, raw: true);
    }

    // ── Emisión de comprobantes ──────────────────────────────────────────────
    // Devuelven el envelope { estado, mensaje, datos }. La emisión es ASÍNCRONA:
    // `datos.sunat.estado` arranca en "enviado" y pasa a "aceptado"/"rechazado".
    // Consulta luego con ver*() o espera el webhook. Pasa $idk (Idempotency-Key)
    // para que un reintento por corte de red no duplique el comprobante.

    public function crearFactura(array $data, ?string $idk = null): array
    {
        return $this->post('facturas', $data, $idk);
    }

    public function crearBoleta(array $data, ?string $idk = null): array
    {
        return $this->post('boletas', $data, $idk);
    }

    public function crearNotaCredito(array $data, ?string $idk = null): array
    {
        return $this->post('notas-credito', $data, $idk);
    }

    public function crearNotaDebito(array $data, ?string $idk = null): array
    {
        return $this->post('notas-debito', $data, $idk);
    }

    public function crearGuiaRemision(array $data, ?string $idk = null): array
    {
        return $this->post('guias-remision', $data, $idk);
    }

    public function crearGuiaTransportista(array $data, ?string $idk = null): array
    {
        return $this->post('guias-remision-transportista', $data, $idk);
    }

    public function crearAnulacion(array $data, ?string $idk = null): array
    {
        return $this->post('anulaciones', $data, $idk);
    }

    // ── Consulta de un comprobante y su estado ───────────────────────────────
    // $recurso: facturas | boletas | notas-credito | notas-debito | guias-remision | anulaciones ...

    public function verComprobante(string $recurso, int $id): array
    {
        return $this->get("{$recurso}/{$id}");
    }

    /** Devuelve el sunat_status actual ("pendiente|enviado|aceptado|rechazado|anulado"). */
    public function estadoComprobante(string $recurso, int $id): ?string
    {
        $r = $this->verComprobante($recurso, $id);

        return $r['datos']['sunat']['estado'] ?? null;
    }

    public function listarComprobantes(string $recurso, array $filtros = []): array
    {
        return $this->get($recurso, $filtros);
    }

    // ── Descargas (bytes crudos) ─────────────────────────────────────────────

    public function descargarPdf(string $recurso, int $id, string $formato = 'a4'): string
    {
        return $this->descargar("{$recurso}/{$id}/pdf", ['formato' => $formato]);
    }

    public function descargarXml(string $recurso, int $id): string
    {
        return $this->descargar("{$recurso}/{$id}/xml");
    }

    public function descargarCdr(string $recurso, int $id): string
    {
        return $this->descargar("{$recurso}/{$id}/cdr");
    }

    // ── Clientes ─────────────────────────────────────────────────────────────

    public function listarClientes(array $filtros = []): array
    {
        return $this->get('clientes', $filtros);
    }

    public function crearCliente(array $data): array
    {
        return $this->post('clientes', $data);
    }

    // ── Consultas a SUNAT / lookup ───────────────────────────────────────────

    /** Consulta el estado de un CPE en SUNAT. Ver campos en la doc (doc 37). */
    public function consultarCpe(array $params): array
    {
        return $this->get('consultar-cpe', $params);
    }

    /** Consulta el CDR en SUNAT: ['tipo_documento'=>'01','serie'=>'F001','correlativo'=>45]. */
    public function consultarCdr(array $data): array
    {
        return $this->post('consultar-cdr', $data);
    }

    /** Busca datos de un RUC/DNI: $tipo (6=RUC,1=DNI...), $numero. */
    public function buscarDocumento(string $tipo, string $numero): array
    {
        return $this->get('buscar-documento', ['tipo' => $tipo, 'numero' => $numero]);
    }

    /** Disponibilidad de SUNAT (circuit breaker). */
    public function estadoSunat(): array
    {
        return $this->get('sunat/estado');
    }

    /** Genera una Idempotency-Key (UUID v4) para una emisión. */
    public static function nuevaIdempotencyKey(): string
    {
        $d = random_bytes(16);
        $d[6] = chr((ord($d[6]) & 0x0F) | 0x40);
        $d[8] = chr((ord($d[8]) & 0x3F) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($d), 4));
    }

    // ── Núcleo ───────────────────────────────────────────────────────────────

    /**
     * @param  array<string, mixed>  $body
     * @return ($raw is true ? string : array<string, mixed>)
     */
    private function request(string $method, string $path, array $body = [], ?string $idempotencyKey = null, bool $raw = false): array|string
    {
        $ch = curl_init($this->baseUrl.'/'.ltrim($path, '/'));

        $headers = [
            'X-Api-Key: '.$this->apiKey,
            'X-Api-Secret: '.$this->apiSecret,
            'Accept: '.($raw ? '*/*' : 'application/json'),
        ];
        if ($idempotencyKey !== null) {
            $headers[] = 'Idempotency-Key: '.$idempotencyKey;
        }

        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_HTTPHEADER => $headers,
        ]);

        if ($body !== [] || in_array($method, ['POST', 'PUT', 'PATCH'], true)) {
            $headers[] = 'Content-Type: application/json';
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body, JSON_UNESCAPED_UNICODE));
        }

        $respuesta = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($respuesta === false) {
            throw new ApiException("No se pudo conectar con la API: {$error}", 0);
        }

        if ($raw) {
            if ($status >= 400) {
                throw new ApiException("La descarga falló (HTTP {$status}).", $status);
            }

            return (string) $respuesta;
        }

        $datos = json_decode((string) $respuesta, true) ?: [];

        if ($status >= 400 || (($datos['estado'] ?? null) === 'error')) {
            throw new ApiException(
                $datos['mensaje'] ?? "Error HTTP {$status}",
                $status,
                $datos['errores'] ?? [],
                $datos,
            );
        }

        return $datos;
    }
}
