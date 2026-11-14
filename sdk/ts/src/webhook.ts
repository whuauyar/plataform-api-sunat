import { createHmac, timingSafeEqual } from 'node:crypto';

/**
 * Verifica la firma de un webhook (server-side).
 *
 * La API firma el cuerpo con HMAC-SHA256 usando tu api_secret y la envía en la
 * cabecera `X-Signature: sha256=<hmac>`. Verifica SIEMPRE antes de confiar en el
 * contenido. Usa el cuerpo CRUDO (sin re-serializar).
 */
export function verificarWebhook(
  cuerpoCrudo: string,
  firmaHeader: string,
  apiSecret: string,
): boolean {
  const esperado = 'sha256=' + createHmac('sha256', apiSecret).update(cuerpoCrudo).digest('hex');
  const a = Buffer.from(esperado);
  const b = Buffer.from(firmaHeader ?? '');
  return a.length === b.length && timingSafeEqual(a, b);
}
