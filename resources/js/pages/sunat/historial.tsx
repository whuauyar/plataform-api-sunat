import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';
import { Filter, Search, X } from '@/lib/icons';
import { DocumentosTable } from '@/components/sunat/documentos-table';
import { PdfEmitidoModal } from '@/components/sunat/pdf-emitido-modal';
import { Button } from '@/components/ui/button';
import { Combobox } from '@/components/ui/combobox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Card, CardContent } from '@/components/ui/card';
import { useEstadoPolling } from '@/hooks/use-estado-polling';
import SunatLayout from '@/layouts/sunat-layout';
import { type PaginationLink } from '@/components/ui/pagination';
import type { DocumentoSunat } from '@/types';

type Filtros = { tipo: string; estado: string; desde: string; hasta: string; cliente: string; buscar: string };

const TIPO_FILTRO_LABEL: Record<string, string> = {
    facturas: 'Facturas', boletas: 'Boletas', 'notas-credito': 'Notas de crédito',
    'notas-debito': 'Notas de débito', guias: 'Guías de remisión', retenciones: 'Retenciones', percepciones: 'Percepciones',
};
const ESTADO_FILTRO_LABEL: Record<string, string> = {
    aceptado: 'Aceptado', pendiente: 'Pendiente', enviado: 'Enviado',
    rechazado: 'Rechazado', anulado: 'Anulado', borrador: 'Borrador',
};

type Props = {
    documentos: { data: DocumentoSunat[]; from: number | null; to: number | null; total: number; links: PaginationLink[] };
    filtros: Filtros;
    tenant: { environment: string };
};

export default function Historial({ documentos, filtros }: Props) {
    const [tipo, setTipo]       = useState(filtros.tipo ?? 'todos');
    const [estado, setEstado]   = useState(filtros.estado ?? '');
    const [desde, setDesde]     = useState(filtros.desde ?? '');
    const [hasta, setHasta]     = useState(filtros.hasta ?? '');
    const [cliente, setCliente] = useState(filtros.cliente ?? '');
    const [buscar, setBuscar]   = useState(filtros.buscar ?? '');
    const [showFiltros, setShowFiltros] = useState(false);

    const docs = documentos.data ?? [];

    // Estados en vivo: refresca en segundo plano mientras haya comprobantes en proceso.
    useEstadoPolling(docs.map((d) => d.estado), ['documentos']);

    // La búsqueda y los filtros van al SERVIDOR (paginación server-side): así se
    // encuentra cualquier comprobante del histórico, no solo los últimos cargados.
    function navegar(extra: Partial<Filtros> = {}) {
        const params = { tipo, estado, desde, hasta, cliente, buscar, ...extra };
        router.get('/sunat/historial', params, { preserveState: true, preserveScroll: true });
    }

    function limpiarFiltros() {
        setTipo('todos'); setEstado(''); setDesde(''); setHasta(''); setCliente(''); setBuscar('');
        router.get('/sunat/historial', {}, { preserveState: false });
    }

    const hayFiltros = estado || desde || hasta || cliente || buscar || tipo !== 'todos';

    function quitarFiltro(key: keyof Filtros) {
        const vacio = key === 'tipo' ? 'todos' : '';
        if (key === 'tipo') setTipo('todos');
        if (key === 'estado') setEstado('');
        if (key === 'desde') setDesde('');
        if (key === 'hasta') setHasta('');
        if (key === 'cliente') setCliente('');
        if (key === 'buscar') setBuscar('');
        navegar({ [key]: vacio });
    }

    const chips = [
        tipo !== 'todos' ? { key: 'tipo' as const, texto: `Tipo: ${TIPO_FILTRO_LABEL[tipo] ?? tipo}` } : null,
        estado ? { key: 'estado' as const, texto: `Estado: ${ESTADO_FILTRO_LABEL[estado] ?? estado}` } : null,
        desde ? { key: 'desde' as const, texto: `Desde: ${desde}` } : null,
        hasta ? { key: 'hasta' as const, texto: `Hasta: ${hasta}` } : null,
        cliente ? { key: 'cliente' as const, texto: `Cliente: ${cliente}` } : null,
        buscar ? { key: 'buscar' as const, texto: `Buscar: ${buscar}` } : null,
    ].filter((c): c is { key: keyof Filtros; texto: string } => c !== null);

    return (
        <SunatLayout>
            <Head title="Historial de Comprobantes" />

            <div className="flex flex-col gap-6 p-4 md:p-6">
                {/* Header */}
                <div className="flex items-center justify-between">
                    <div>
                        <h1 className="text-2xl font-semibold tracking-tight">Historial</h1>
                        <p className="text-sm text-muted-foreground">
                            {documentos.total} comprobante{documentos.total !== 1 ? 's' : ''} encontrado{documentos.total !== 1 ? 's' : ''}
                        </p>
                    </div>
                    <div className="flex gap-2">
                        <Button variant="outline" size="sm" onClick={() => setShowFiltros((v) => !v)}>
                            <Filter className="mr-2 size-3.5" />
                            Filtros
                            {hayFiltros && (
                                <span className="ml-1.5 flex size-4 items-center justify-center rounded-full bg-primary text-[10px] text-primary-foreground">!</span>
                            )}
                        </Button>
                        <Button asChild size="sm">
                            <Link href="/sunat/facturas/nueva">Nueva factura</Link>
                        </Button>
                    </div>
                </div>

                {/* Filtros panel (servidor) */}
                {showFiltros && (
                    <Card>
                        <CardContent className="pt-6">
                            <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-5">
                                <div className="flex flex-col gap-1.5">
                                    <Label>Tipo</Label>
                                    <Combobox
                                        value={tipo}
                                        onChange={(v) => setTipo(v)}
                                        searchable
                                        options={[
                                            { value: 'todos', label: 'Todos' },
                                            { value: 'facturas', label: 'Facturas' },
                                            { value: 'boletas', label: 'Boletas' },
                                            { value: 'notas-credito', label: 'Notas de crédito' },
                                            { value: 'notas-debito', label: 'Notas de débito' },
                                            { value: 'guias', label: 'Guías de remisión' },
                                            { value: 'retenciones', label: 'Retenciones' },
                                            { value: 'percepciones', label: 'Percepciones' },
                                        ]}
                                        className="h-9 rounded-md"
                                    />
                                </div>
                                <div className="flex flex-col gap-1.5">
                                    <Label>Estado</Label>
                                    <Combobox
                                        value={estado}
                                        onChange={(v) => setEstado(v)}
                                        placeholder="Todos"
                                        options={[
                                            { value: '', label: 'Todos' },
                                            { value: 'aceptado', label: 'Aceptado' },
                                            { value: 'pendiente', label: 'Pendiente' },
                                            { value: 'enviado', label: 'Enviado' },
                                            { value: 'rechazado', label: 'Rechazado' },
                                            { value: 'anulado', label: 'Anulado' },
                                            { value: 'borrador', label: 'Borrador' },
                                        ]}
                                        className="h-9 rounded-md"
                                    />
                                </div>
                                <div className="flex flex-col gap-1.5">
                                    <Label>Desde</Label>
                                    <Input type="date" value={desde} onChange={(e) => setDesde(e.target.value)} />
                                </div>
                                <div className="flex flex-col gap-1.5">
                                    <Label>Hasta</Label>
                                    <Input type="date" value={hasta} onChange={(e) => setHasta(e.target.value)} />
                                </div>
                                <div className="flex flex-col gap-1.5">
                                    <Label>Cliente</Label>
                                    <Input placeholder="Buscar..." value={cliente} onChange={(e) => setCliente(e.target.value)} />
                                </div>
                            </div>
                            <div className="mt-4 flex gap-2">
                                <Button size="sm" onClick={() => navegar()}>
                                    <Search className="mr-2 size-3.5" /> Buscar
                                </Button>
                                {hayFiltros && (
                                    <Button size="sm" variant="ghost" onClick={limpiarFiltros}>
                                        <X className="mr-2 size-3.5" /> Limpiar
                                    </Button>
                                )}
                            </div>
                        </CardContent>
                    </Card>
                )}

                {/* Chips de filtros activos */}
                {chips.length > 0 && (
                    <div className="flex flex-wrap items-center gap-2">
                        {chips.map((c) => (
                            <span key={c.key} className="inline-flex items-center gap-1.5 rounded-full border border-border bg-muted px-3 py-1 text-xs text-foreground">
                                {c.texto}
                                <button type="button" onClick={() => quitarFiltro(c.key)} className="text-muted-foreground transition-colors hover:text-destructive" title="Quitar filtro">
                                    <X className="size-3" />
                                </button>
                            </span>
                        ))}
                        <button type="button" onClick={limpiarFiltros} className="text-xs font-medium text-muted-foreground hover:text-foreground hover:underline">
                            Limpiar todo
                        </button>
                    </div>
                )}

                {/* Búsqueda en el servidor: encuentra cualquier comprobante del histórico */}
                <div className="relative max-w-md">
                    <Search className="pointer-events-none absolute left-2.5 top-2.5 size-4 text-muted-foreground" />
                    <Input
                        className="pl-8"
                        value={buscar}
                        placeholder="Buscar por número (F001-00000123) o cliente…"
                        onChange={(e) => setBuscar(e.target.value)}
                        onKeyDown={(e) => e.key === 'Enter' && navegar()}
                    />
                </div>

                <DocumentosTable
                    documentos={docs}
                    emptyMessage="No se encontraron comprobantes."
                    paginacion={{ from: documentos.from, to: documentos.to, total: documentos.total, links: documentos.links }}
                />
            </div>

            {/* Modal de PDF tras emitir retención/percepción (redirect con flash 'emitido'). */}
            <PdfEmitidoModal />
        </SunatLayout>
    );
}
