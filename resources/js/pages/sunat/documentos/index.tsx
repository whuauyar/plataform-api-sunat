import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';
import { Plus, Search } from '@/lib/icons';
import { DocumentosTable } from '@/components/sunat/documentos-table';
import { PdfEmitidoModal } from '@/components/sunat/pdf-emitido-modal';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { type PaginationLink } from '@/components/ui/pagination';
import { useEstadoPolling } from '@/hooks/use-estado-polling';
import SunatLayout from '@/layouts/sunat-layout';
import type { DocumentoSunat } from '@/types';

type Props = {
    titulo: string;
    subtitulo?: string;
    nuevo: { href: string; label: string };
    documentos: { data: DocumentoSunat[]; from: number | null; to: number | null; total: number; links: PaginationLink[] };
    filtros?: { buscar?: string };
    /** Oculta la columna Tipo (listados de un solo tipo). Default: true */
    ocultarTipo?: boolean;
    emptyMessage?: string;
};

/** Listado genérico de comprobantes por tipo, con búsqueda y paginación en el servidor. */
export default function DocumentosIndex({ titulo, subtitulo, nuevo, documentos, filtros, ocultarTipo = true, emptyMessage }: Props) {
    const [buscar, setBuscar] = useState(filtros?.buscar ?? '');
    const docs = documentos.data ?? [];

    // Estados en vivo: refresca en segundo plano mientras haya comprobantes en proceso.
    useEstadoPolling(docs.map((d) => d.estado), ['documentos']);

    // Búsqueda en el servidor sobre la ruta actual (/sunat/{tipo}): alcanza todo el histórico.
    function buscarAhora() {
        router.get(window.location.pathname, { buscar }, { preserveState: true, preserveScroll: true });
    }

    return (
        <SunatLayout>
            <Head title={titulo} />

            <div className="mx-auto max-w-6xl">
                <div className="mb-6">
                    <h1 className="text-xl font-semibold tracking-tight">{titulo}</h1>
                    {subtitulo && <p className="text-sm text-muted-foreground">{subtitulo}</p>}
                    <p className="mt-1 text-sm text-muted-foreground">{documentos.total} comprobante{documentos.total !== 1 ? 's' : ''}</p>
                </div>

                <div className="mb-4 flex flex-wrap items-center justify-between gap-2">
                    <div className="relative min-w-[260px] flex-1 sm:max-w-md">
                        <Search className="pointer-events-none absolute left-2.5 top-2.5 size-4 text-muted-foreground" />
                        <Input
                            className="pl-8"
                            value={buscar}
                            placeholder="Buscar por número (ej. B001-00000123) o cliente…"
                            onChange={(e) => setBuscar(e.target.value)}
                            onKeyDown={(e) => e.key === 'Enter' && buscarAhora()}
                        />
                    </div>
                    <Button asChild className="gap-2 rounded-xl">
                        <Link href={nuevo.href}><Plus className="size-4" /> {nuevo.label}</Link>
                    </Button>
                </div>

                <DocumentosTable
                    documentos={docs}
                    ocultarTipo={ocultarTipo}
                    emptyMessage={emptyMessage ?? 'Aún no has emitido comprobantes de este tipo.'}
                    paginacion={{ from: documentos.from, to: documentos.to, total: documentos.total, links: documentos.links }}
                />
            </div>

            {/* Modal de PDF tras emitir (llega por redirect con flash 'emitido'). */}
            <PdfEmitidoModal />
        </SunatLayout>
    );
}
