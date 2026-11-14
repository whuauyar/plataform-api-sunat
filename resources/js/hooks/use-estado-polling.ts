import { usePoll } from '@inertiajs/react';
import { useEffect } from 'react';

/**
 * Estados SUNAT que todavía pueden cambiar solos cuando la cola procesa el
 * ticket (envío / anulación). Los demás (aceptado, rechazado, anulado, borrador)
 * son finales y no requieren refresco.
 */
const TRANSITORIOS = new Set(['pendiente', 'enviado', 'anulacion_en_proceso']);

/**
 * Mantiene los estados de los comprobantes al día EN VIVO, sin recargar la
 * página: recarga en segundo plano (partial reload de Inertia) solo las props
 * indicadas y solo mientras haya al menos un comprobante en estado transitorio.
 * Cuando todos quedan en un estado final, el polling se detiene solo (no gasta
 * requests). También se pausa cuando la pestaña está en segundo plano.
 *
 * @param estados  lista de estados actuales visibles (para decidir si seguir sondeando)
 * @param only     props de Inertia a refrescar (ej. ['documentos'] o ['resumenes'])
 * @param intervalMs  cada cuánto refrescar mientras haya transitorios (default 8s)
 */
export function useEstadoPolling(
    estados: Array<string | null | undefined>,
    only: string[],
    intervalMs = 8000,
): void {
    const hayTransitorios = estados.some((e) => !!e && TRANSITORIOS.has(e));

    // reload preserva scroll y estado por defecto; solo pedimos las props `only`.
    const { start, stop } = usePoll(intervalMs, { only }, { autoStart: false });

    useEffect(() => {
        if (hayTransitorios) {
            start();
        } else {
            stop();
        }

        return stop;
    }, [hayTransitorios, start, stop]);
}
