import type {
  ApiErrorBody,
  ApiResponse,
  ClientOptions,
  RequestOptions,
  SunatStatus,
} from './types.js';

/** Error lanzado cuando la API responde con error. */
export class ApiError extends Error {
  constructor(
    message: string,
    readonly status: number,
    readonly errores: Record<string, string[]> = {},
    readonly body: unknown = null,
  ) {
    super(message);
    this.name = 'ApiError';
  }

  /** ¿Error de validación (422)? */
  get esValidacion(): boolean {
    return this.status === 422;
  }

  /** ¿Conviene reintentar? (rate limit o error de servidor) */
  get esReintentable(): boolean {
    return this.status === 429 || this.status >= 500;
  }
}

/**
 * Cliente de la API Jorge Chavez API SUNAT.
 *
 * Autenticación por cabeceras X-Api-Key / X-Api-Secret. Funciona en Node 18+ y
 * navegadores (usa `fetch`). Los métodos de escritura aceptan `idempotencyKey`
 * para que un reintento no genere un comprobante duplicado.
 */
export class Client {
  private readonly baseUrl: string;
  private readonly timeoutMs: number;

  constructor(private readonly opts: ClientOptions) {
    this.baseUrl = opts.baseUrl.replace(/\/+$/, '') + '/api/v1';
    this.timeoutMs = opts.timeoutMs ?? 30_000;
  }

  /** GET que devuelve JSON. */
  get<T = unknown>(path: string, opts: RequestOptions = {}): Promise<ApiResponse<T>> {
    return this.request<T>('GET', path, undefined, opts);
  }

  /** POST que devuelve JSON. */
  post<T = unknown>(path: string, body?: unknown, opts: RequestOptions = {}): Promise<ApiResponse<T>> {
    return this.request<T>('POST', path, body, opts);
  }

  /** PUT que devuelve JSON. */
  put<T = unknown>(path: string, body?: unknown, opts: RequestOptions = {}): Promise<ApiResponse<T>> {
    return this.request<T>('PUT', path, body, opts);
  }

  /** DELETE que devuelve JSON. */
  delete<T = unknown>(path: string, opts: RequestOptions = {}): Promise<ApiResponse<T>> {
    return this.request<T>('DELETE', path, undefined, opts);
  }

  /** Descarga binaria (PDF/XML/CDR); devuelve los bytes. */
  async descargar(path: string, opts: RequestOptions = {}): Promise<ArrayBuffer> {
    const res = await this.fetch('GET', path, undefined, opts, '*/*');
    if (!res.ok) {
      throw new ApiError(`La descarga falló (HTTP ${res.status}).`, res.status);
    }
    return res.arrayBuffer();
  }

  // ── Emisión de comprobantes ─────────────────────────────────────────────
  // La emisión es ASÍNCRONA: datos.sunat.estado arranca en "enviado". Pasa
  // { idempotencyKey } para que un reintento no duplique el comprobante.

  crearFactura(data: unknown, idempotencyKey?: string) {
    return this.post('facturas', data, { idempotencyKey });
  }

  crearBoleta(data: unknown, idempotencyKey?: string) {
    return this.post('boletas', data, { idempotencyKey });
  }

  crearNotaCredito(data: unknown, idempotencyKey?: string) {
    return this.post('notas-credito', data, { idempotencyKey });
  }

  crearNotaDebito(data: unknown, idempotencyKey?: string) {
    return this.post('notas-debito', data, { idempotencyKey });
  }

  crearGuiaRemision(data: unknown, idempotencyKey?: string) {
    return this.post('guias-remision', data, { idempotencyKey });
  }

  crearGuiaTransportista(data: unknown, idempotencyKey?: string) {
    return this.post('guias-remision-transportista', data, { idempotencyKey });
  }

  crearAnulacion(data: unknown, idempotencyKey?: string) {
    return this.post('anulaciones', data, { idempotencyKey });
  }

  // ── Consulta de un comprobante y su estado ───────────────────────────────
  // recurso: 'facturas' | 'boletas' | 'notas-credito' | 'notas-debito' | ...

  verComprobante<T = any>(recurso: string, id: number | string) {
    return this.get<T>(`${recurso}/${id}`);
  }

  /** Devuelve el sunat_status actual, o undefined. */
  async estadoComprobante(recurso: string, id: number | string): Promise<SunatStatus | undefined> {
    const r = await this.get<any>(`${recurso}/${id}`);
    return r.datos?.sunat?.estado as SunatStatus | undefined;
  }

  listarComprobantes(recurso: string, filtros: RequestOptions['query'] = {}) {
    return this.get(recurso, { query: filtros });
  }

  // ── Descargas (bytes) ────────────────────────────────────────────────────

  descargarPdf(recurso: string, id: number | string, formato: 'a4' | 'a5' | 'ticket-80' | 'ticket-58' = 'a4') {
    return this.descargar(`${recurso}/${id}/pdf`, { query: { formato } });
  }

  descargarXml(recurso: string, id: number | string) {
    return this.descargar(`${recurso}/${id}/xml`);
  }

  descargarCdr(recurso: string, id: number | string) {
    return this.descargar(`${recurso}/${id}/cdr`);
  }

  // ── Clientes ─────────────────────────────────────────────────────────────

  listarClientes(filtros: RequestOptions['query'] = {}) {
    return this.get('clientes', { query: filtros });
  }

  crearCliente(data: unknown) {
    return this.post('clientes', data);
  }

  // ── Consultas a SUNAT / lookup ───────────────────────────────────────────

  consultarCpe(params: Record<string, string | number>) {
    return this.get('consultar-cpe', { query: params });
  }

  consultarCdr(data: { tipo_documento: string; serie: string; correlativo: number }) {
    return this.post('consultar-cdr', data);
  }

  buscarDocumento(tipo: string, numero: string) {
    return this.get('buscar-documento', { query: { tipo, numero } });
  }

  estadoSunat() {
    return this.get('sunat/estado');
  }

  /** Genera una Idempotency-Key (UUID v4). Requiere Web Crypto (Node 18.17+ o navegador). */
  static nuevaIdempotencyKey(): string {
    return globalThis.crypto.randomUUID();
  }

  // ── Núcleo ────────────────────────────────────────────────────────────────

  private async request<T>(
    method: string,
    path: string,
    body: unknown,
    opts: RequestOptions,
  ): Promise<ApiResponse<T>> {
    const res = await this.fetch(method, path, body, opts, 'application/json');
    const text = await res.text();
    const data = (text ? JSON.parse(text) : {}) as ApiResponse<T> & ApiErrorBody;

    if (!res.ok || data.estado === 'error') {
      throw new ApiError(
        data.mensaje ?? `Error HTTP ${res.status}`,
        res.status,
        data.errores ?? {},
        data,
      );
    }
    return data;
  }

  private fetch(
    method: string,
    path: string,
    body: unknown,
    opts: RequestOptions,
    accept: string,
  ): Promise<Response> {
    const url = new URL(this.baseUrl + '/' + path.replace(/^\/+/, ''));
    for (const [k, v] of Object.entries(opts.query ?? {})) {
      if (v !== undefined) url.searchParams.set(k, String(v));
    }

    const headers: Record<string, string> = {
      'X-Api-Key': this.opts.apiKey,
      'X-Api-Secret': this.opts.apiSecret,
      Accept: accept,
    };
    if (opts.idempotencyKey) headers['Idempotency-Key'] = opts.idempotencyKey;
    if (body !== undefined) headers['Content-Type'] = 'application/json';

    return fetch(url, {
      method,
      headers,
      body: body !== undefined ? JSON.stringify(body) : undefined,
      signal: AbortSignal.timeout(this.timeoutMs),
    });
  }
}
