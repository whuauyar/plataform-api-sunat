import { usePage } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import { PdfPreviewModal } from '@/components/sunat/pdf-preview-modal';

type Emitido = { tipo: string; id: number; numero: string; formato?: string };

/**
 * Muestra el modal de vista previa del PDF cuando el backend redirige con el
 * flash 'emitido' (tras emitir un comprobante correctamente). Se coloca en las
 * páginas de LISTA: al emitir, el controlador redirige a la lista (form limpio)
 * y este modal aparece sobre ella. Si no hay flash, no renderiza nada.
 */
export function PdfEmitidoModal() {
    const { props } = usePage<{ flash?: { emitido?: Emitido } }>();
    const emitido = props.flash?.emitido;
    const [modal, setModal] = useState<Emitido | null>(null);

    useEffect(() => {
        if (emitido?.id) setModal(emitido);
        // Solo reaccionar a un nuevo id emitido.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [emitido?.id]);

    if (!modal) return null;

    return (
        <PdfPreviewModal
            tipo={modal.tipo}
            id={modal.id}
            numero={modal.numero}
            initialFormat={modal.formato ?? 'a4'}
            onClose={() => setModal(null)}
        />
    );
}
