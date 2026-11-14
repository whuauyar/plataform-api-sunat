/** Tipos compartidos del SDK. */

/** Estado de un comprobante frente a SUNAT. */
export type SunatStatus =
  | 'pendiente'
  | 'enviado'
  | 'aceptado'
  | 'rechazado'
  | 'anulado';

/** Envelope de error que devuelve la API. */
export interface ApiErrorBody {
  estado: 'error';
  mensaje: string;
  errores?: Record<string, string[]>;
}

/** Respuesta genérica de la API (estado + datos variables). */
export interface ApiResponse<T = unknown> {
  estado: 'exito' | 'error';
  mensaje?: string;
  datos?: T;
  [key: string]: unknown;
}

export interface ClientOptions {
  apiKey: string;
  apiSecret: string;
  /** Base sin /api/v1, p. ej. https://tu-dominio.com */
  baseUrl: string;
  /** ms, por defecto 30000 */
  timeoutMs?: number;
}

export interface RequestOptions {
  /** Envía Idempotency-Key en escrituras para evitar duplicados. */
  idempotencyKey?: string;
  query?: Record<string, string | number | boolean | undefined>;
}
