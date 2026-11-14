import { router } from '@inertiajs/react';
import { type ColumnDef } from '@tanstack/react-table';
import { useState } from 'react';
import { Ban, Check, Copy, Download, Eye, FileMinus, FilePlus, FileText, Loader2, Pencil, RefreshCw, Search, X } from '@/lib/icons';
import { StatusBadge } from '@/components/sunat/status-badge';
import { Pagination, type PaginationLink } from '@/components/ui/pagination';
import { Button } from '@/components/ui/button';
import { useConfirm } from '@/components/ui/confirm-dialog';
import { DataTable, type FacetFilter } from '@/components/ui/data-table';
import { DataTableRowActions } from '@/components/ui/data-table-row-actions';
import { Label } from '@/components/ui/label';
import type { DocumentoSunat } from '@/types';

const ESTADOS_FILTRO = [
    { value: 'aceptado', label: 'Aceptado' },
    { value: 'pendiente', label: 'Pendiente' },
    { value: 'enviado', label: 'Enviado' },
    { value: 'rechazado', label: 'Rechazado' },
    { value: 'anulacion_en_proceso', label: 'Anulación en proceso' },
    { value: 'anulado', label: 'Anulado' },
    { value: 'borrador', label: 'Borrador' },
];

/** Rutas de "Editar y reenviar" por tipo (solo tipos con formulario de edición). */
const EDITAR_URL: Record<string, (id: number) => string> = {
    '01': (id) => `/sunat/comprobantes/01/${id}/editar`,
    '03': (id) => `/sunat/comprobantes/03/${id}/editar`,
    '07': (id) => `/sunat/nota-credito/${id}/editar`,
    '08': (id) => `/sunat/nota-debito/${id}/editar`,
    '09': (id) => `/sunat/guias/${id}/editar`,
    '31': (id) => `/sunat/guias/${id}/editar`,
};

const TIPO_LABEL: Record<string, string> = {
    '01': 'Factura',
    '03': 'Boleta',
    '07': 'N. Crédito',
    '08': 'N. Débito',
    '09': 'Guía rem.',
    '31': 'Guía transp.',
    '20': 'Retención',
    '40': 'Percepción',
};

function formatPen(amount: number, moneda: string) {
    return (moneda === 'USD' ? '$ ' : 'S/ ') +
        new Intl.NumberFormat('es-PE', { minimumFractionDigits: 2 }).format(amount);
}

/** Colorea el JSON (theme-aware). El contenido se escapa antes, así que es seguro. */
function resaltarJson(json: string): string {
    const esc = json.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
    return esc.replace(
        /("(\\u[a-zA-Z0-9]{4}|\\[^u]|[^\\"])*"(\s*:)?|\b(true|false|null)\b|-?\d+(?:\.\d*)?(?:[eE][+-]?\d+)?)/g,
        (match) => {
            let cls = 'text-emerald-600 dark:text-emerald-400'; // número
            if (/^"/.test(match)) {
                cls = /:$/.test(match) ? 'text-sky-700 dark:text-sky-300 font-medium' : 'text-amber-600 dark:text-amber-400';
            } else if (/true|false/.test(match)) {
                cls = 'text-rose-600 dark:text-rose-400';
            } else if (/null/.test(match)) {
                cls = 'text-muted-foreground';
            }
            return `<span class="${cls}">${match}</span>`;
        },
    );
}

type Props = {
    documentos: DocumentoSunat[];
    searchPlaceholder?: string;
    emptyMessage?: string;
    /** Oculta la columna de Tipo (útil en listados de un solo tipo). Default: false */
    ocultarTipo?: boolean;
    /** Muestra la barra de filtros (estado, fecha, tipo) client-side. Default: false.
     *  No usar donde ya haya filtros server-side propios (ej. Historial). */
    conFiltros?: boolean;
    /** Si viene, la tabla pagina EN EL SERVIDOR (muestra el control de paginación y
     *  desactiva la búsqueda client-side, que se maneja fuera). */
    paginacion?: { from: number | null; to: number | null; total: number; links: PaginationLink[] };
};

/**
 * Tabla reutilizable de comprobantes con todas sus acciones (descargas, consulta,
 * reenvío, notas y anulación). La usan el Historial y los listados por tipo de Emitir.
 */
export function DocumentosTable({ documentos, searchPlaceholder = 'Buscar en resultados...', emptyMessage = 'No se encontraron comprobantes.', ocultarTipo = false, conFiltros = false, paginacion }: Props) {
    const confirm = useConfirm();
    const [anularDoc, setAnularDoc] = useState<DocumentoSunat | null>(null);
    const [motivo, setMotivo] = useState('');
    const [motivoError, setMotivoError] = useState('');
    const [anulando, setAnulando] = useState(false);

    // Visor de respuesta de SUNAT ("ojito")
    const [respDoc, setRespDoc] = useState<DocumentoSunat | null>(null);
    const [respJson, setRespJson] = useState<string | null>(null);
    const [respSunat, setRespSunat] = useState<{ estado?: string; codigo?: string; descripcion?: string; notas?: unknown } | null>(null);
    const [cargandoResp, setCargandoResp] = useState(false);
    const [errorResp, setErrorResp] = useState<string | null>(null);
    const [copiado, setCopiado] = useState(false);

    async function abrirRespuesta(doc: DocumentoSunat) {
        setRespDoc(doc);
        setRespJson(null);
        setRespSunat(null);
        setErrorResp(null);
        setCopiado(false);
        setCargandoResp(true);
        try {
            const r = await fetch(`/sunat/historial/${doc.tipo_doc}/${doc.id}/respuesta`, { headers: { Accept: 'application/json' } });
            if (!r.ok) throw new Error();
            const body = await r.json();
            const d = body.datos ?? {};
            setRespJson(JSON.stringify(d, null, 4));
            setRespSunat({
                estado: d.sunat?.estado ?? d.sunat_status,
                codigo: d.sunat?.codigo ?? d.sunat_code,
                descripcion: d.sunat?.descripcion ?? d.sunat_description,
                notas: d.sunat?.notas ?? d.sunat_notes,
            });
        } catch {
            setErrorResp('No se pudo cargar la respuesta de SUNAT.');
        } finally {
            setCargandoResp(false);
        }
    }

    function copiarResp() {
        if (respJson) {
            navigator.clipboard.writeText(respJson);
            setCopiado(true);
            setTimeout(() => setCopiado(false), 1500);
        }
    }

    async function reenviar(doc: DocumentoSunat) {
        if (await confirm({
            title: `¿Reenviar ${doc.numero} a SUNAT?`,
            description: 'Se volverá a enviar el comprobante a SUNAT para obtener su respuesta (CDR).',
            confirmText: 'Reenviar',
        })) {
            router.post(`/sunat/historial/${doc.tipo_doc}/${doc.id}/reenviar`, {}, { preserveScroll: true });
        }
    }

    function abrirAnular(doc: DocumentoSunat) {
        setAnularDoc(doc);
        setMotivo('');
        setMotivoError('');
    }

    function ejecutarAnulacion() {
        if (!anularDoc) return;
        if (motivo.trim().length < 3) {
            setMotivoError('Ingresa el motivo de la anulación (mínimo 3 caracteres).');
            return;
        }
        setAnulando(true);
        router.post(`/sunat/historial/${anularDoc.tipo_doc}/${anularDoc.id}/anular`, { motivo: motivo.trim() }, {
            preserveScroll: true,
            onSuccess: () => setAnularDoc(null),
            onFinish: () => setAnulando(false),
        });
    }

    const mecanismo = anularDoc?.tipo_doc === '03'
        ? { nombre: 'Resumen Diario de anulación (RC)', desc: 'Las boletas se anulan enviando un Resumen Diario a SUNAT. La anulación se confirma cuando SUNAT procesa el ticket.' }
        : { nombre: 'Comunicación de Baja (RA)', desc: 'Las facturas y notas se dan de baja ante SUNAT. Solo procede dentro de los 7 días de emitido y si el comprobante no tiene una nota de crédito asociada.' };
    const puedeNotaCredito = anularDoc?.tipo_doc === '01' || anularDoc?.tipo_doc === '03';

    const columns: ColumnDef<DocumentoSunat>[] = [
        ...(ocultarTipo ? [] : [{
            accessorKey: 'tipo_doc',
            header: 'Tipo',
            meta: { label: 'Tipo' },
            cell: ({ row }) => (
                <span className="rounded-md bg-muted px-2 py-0.5 text-xs font-medium">
                    {TIPO_LABEL[row.original.tipo_doc] ?? row.original.tipo_doc}
                </span>
            ),
        } as ColumnDef<DocumentoSunat>]),
        {
            accessorKey: 'numero',
            header: 'Número',
            meta: { label: 'Número', primary: true },
            cell: ({ row }) => <span className="font-mono text-xs">{row.original.numero}</span>,
        },
        {
            accessorKey: 'cliente',
            header: 'Cliente',
            meta: { label: 'Cliente' },
            cell: ({ row }) => <span className="block max-w-[200px] truncate">{row.original.cliente}</span>,
        },
        {
            accessorKey: 'fecha',
            header: 'Fecha',
            meta: { label: 'Fecha' },
            cell: ({ row }) => (
                <span className="whitespace-nowrap text-sm text-muted-foreground">
                    {new Date(row.original.fecha).toLocaleDateString('es-PE')}
                </span>
            ),
        },
        {
            accessorKey: 'total',
            header: 'Total',
            meta: { label: 'Total', alignRight: true },
            cell: ({ row }) => (
                <span className="whitespace-nowrap font-medium tabular-nums">
                    {row.original.tipo_doc === '09' || row.original.tipo_doc === '31'
                        ? <span className="text-muted-foreground">-</span>
                        : formatPen(row.original.total, row.original.moneda)}
                </span>
            ),
        },
        {
            accessorKey: 'estado',
            header: 'Estado',
            meta: { label: 'Estado' },
            cell: ({ row }) => (
                <div className="flex flex-wrap items-center gap-1.5">
                    <StatusBadge status={row.original.estado} />
                    {row.original.plazo_envio === 'por_vencer' && (
                        <span
                            className="rounded-full bg-amber-500/15 px-2 py-0.5 text-[10px] font-medium text-amber-600"
                            title="Por vencer el plazo de envío a SUNAT (3 días desde la emisión)"
                        >
                            ⏳ Por vencer
                        </span>
                    )}
                    {row.original.plazo_envio === 'vencido' && (
                        <span
                            className="rounded-full bg-red-500/15 px-2 py-0.5 text-[10px] font-medium text-red-600"
                            title="Venció el plazo de envío a SUNAT; enviarlo ahora sería extemporáneo (sin validez tributaria)"
                        >
                            ⛔ Vencido
                        </span>
                    )}
                    {row.original.plazo_envio === 'fuera_de_plazo' && (
                        <span
                            className="rounded-full bg-orange-500/15 px-2 py-0.5 text-[10px] font-medium text-orange-600"
                            title="Aceptado por SUNAT pero enviado fuera del plazo (extemporáneo): sin validez tributaria como factura electrónica"
                        >
                            ⚠ Fuera de plazo
                        </span>
                    )}
                </div>
            ),
        },
        {
            id: 'actions',
            header: 'Acciones',
            enableSorting: false,
            meta: { hideLabel: true, alignRight: true },
            cell: ({ row }) => {
                const doc = row.original;
                const aceptado = doc.estado === 'aceptado';
                const esComprobante = ['01', '03', '07', '08'].includes(doc.tipo_doc);
                const esGuia = doc.tipo_doc === '09' || doc.tipo_doc === '31';
                const esRetPerc = doc.tipo_doc === '20' || doc.tipo_doc === '40';
                const esVenta = doc.tipo_doc === '01' || doc.tipo_doc === '03';
                const puedeReenviar = (esComprobante || esGuia || esRetPerc) && doc.estado !== 'aceptado' && doc.estado !== 'anulado' && doc.estado !== 'anulacion_en_proceso';
                const tieneDescarga = doc.tiene_pdf || doc.tiene_xml || doc.tiene_cdr;

                const actions = [
                    { label: 'Ver respuesta SUNAT', icon: Eye, onSelect: () => abrirRespuesta(doc) },
                    ...(doc.tiene_pdf ? [{ label: 'Descargar PDF', icon: FileText, separatorBefore: true, onSelect: () => { window.location.href = `/sunat/historial/${doc.tipo_doc}/${doc.id}/pdf`; } }] : []),
                    ...(doc.tiene_xml ? [{ label: 'Descargar XML', icon: Download, onSelect: () => { window.location.href = `/sunat/historial/${doc.tipo_doc}/${doc.id}/xml`; } }] : []),
                    ...(doc.tiene_cdr ? [{ label: 'Descargar CDR', icon: Download, onSelect: () => { window.location.href = `/sunat/historial/${doc.tipo_doc}/${doc.id}/cdr`; } }] : []),
                    ...(doc.estado === 'anulado' && esComprobante ? [
                        { label: 'Constancia de anulación (CDR)', icon: Download, onSelect: () => { window.location.href = `/sunat/historial/${doc.tipo_doc}/${doc.id}/anulacion/cdr`; } },
                        { label: 'XML de la anulación', icon: FileText, onSelect: () => { window.location.href = `/sunat/historial/${doc.tipo_doc}/${doc.id}/anulacion/xml`; } },
                    ] : []),
                    ...(esComprobante ? [{ label: 'Consultar en SUNAT', icon: Search, separatorBefore: tieneDescarga || doc.estado === 'anulado', onSelect: () => router.visit(`/sunat/consulta-cpe?tipo=${doc.tipo_doc}&serie=${encodeURIComponent(doc.serie)}&correlativo=${doc.correlativo}&fecha=${encodeURIComponent(doc.fecha)}&monto=${doc.total}`) }] : []),
                    ...(doc.estado === 'rechazado' && EDITAR_URL[doc.tipo_doc] ? [{ label: 'Editar y reenviar', icon: Pencil, separatorBefore: true, onSelect: () => router.visit(EDITAR_URL[doc.tipo_doc](doc.id)) }] : []),
                    ...(puedeReenviar ? [{ label: 'Reenviar a SUNAT', icon: RefreshCw, onSelect: () => reenviar(doc) }] : []),
                    ...(aceptado && esVenta ? [{ label: 'Emitir nota de crédito', icon: FileMinus, separatorBefore: true, onSelect: () => router.visit(`/sunat/nota-credito/nueva?doc_id=${doc.id}&tipo_doc=${doc.tipo_doc}`) }] : []),
                    ...(aceptado && esVenta ? [{ label: 'Emitir nota de débito', icon: FilePlus, onSelect: () => router.visit(`/sunat/nota-debito/nueva?doc_id=${doc.id}&tipo=${doc.tipo_doc}`) }] : []),
                    ...(aceptado && esComprobante ? [{ label: 'Anular', icon: Ban, danger: true, separatorBefore: true, onSelect: () => abrirAnular(doc) }] : []),
                ];
                if (actions.length === 0) return <span className="text-muted-foreground">-</span>;
                return <DataTableRowActions actions={actions} />;
            },
        },
    ];

    const filtros: FacetFilter[] | undefined = conFiltros
        ? [
              ...(ocultarTipo
                  ? []
                  : [{
                        type: 'select' as const,
                        column: 'tipo_doc',
                        label: 'Tipo',
                        options: Object.entries(TIPO_LABEL).map(([value, label]) => ({ value, label })),
                    }]),
              { type: 'select', column: 'estado', label: 'Estado', options: ESTADOS_FILTRO },
              { type: 'dateRange', column: 'fecha', label: 'Fecha' },
          ]
        : undefined;

    return (
        <>
            <DataTable
                columns={columns}
                data={documentos}
                searchPlaceholder={searchPlaceholder}
                emptyMessage={emptyMessage}
                filters={filtros}
                {...(paginacion ? { manualPagination: true, searchable: false, indexOffset: (paginacion.from ?? 1) - 1 } : {})}
            />
            {paginacion && (
                <div className="mt-3">
                    <Pagination links={paginacion.links} from={paginacion.from} to={paginacion.to} total={paginacion.total} />
                </div>
            )}

            {/* Modal de anulación */}
            {anularDoc && (
                <div className="fixed inset-0 z-[100] overflow-y-auto bg-black/50" onClick={() => !anulando && setAnularDoc(null)}>
                  <div className="flex min-h-full items-center justify-center p-4">
                    <div className="w-full max-w-lg rounded-2xl bg-card shadow-soft p-5" onClick={(e) => e.stopPropagation()}>
                        <div className="mb-4 flex items-center justify-between">
                            <h3 className="text-base font-semibold text-foreground">Anular comprobante</h3>
                            <button type="button" onClick={() => setAnularDoc(null)} className="rounded-lg p-1 text-muted-foreground transition-colors hover:bg-secondary">
                                <X className="size-4" />
                            </button>
                        </div>

                        <div className="flex flex-col gap-4">
                            <div className="rounded-xl border border-border bg-muted/40 p-4">
                                <div className="flex items-center justify-between gap-2">
                                    <span className="font-mono text-sm font-medium text-foreground">{anularDoc.numero}</span>
                                    <StatusBadge status={anularDoc.estado} />
                                </div>
                                <p className="mt-1 truncate text-xs text-muted-foreground">{anularDoc.cliente}</p>
                            </div>

                            <div className="flex flex-col gap-1">
                                <p className="text-xs font-medium text-muted-foreground">Mecanismo de anulación</p>
                                <p className="text-sm text-foreground">{mecanismo.nombre}</p>
                                <p className="text-xs leading-relaxed text-muted-foreground">{mecanismo.desc}</p>
                            </div>

                            <div className="flex flex-col gap-1.5">
                                <Label className="text-xs font-medium text-muted-foreground">Motivo de la anulación <span className="text-destructive">*</span></Label>
                                <textarea
                                    value={motivo}
                                    onChange={(e) => { setMotivo(e.target.value); setMotivoError(''); }}
                                    rows={3}
                                    maxLength={255}
                                    placeholder="Ej: Operación no realizada / error en los datos del comprobante"
                                    className="w-full resize-none rounded-xl border border-input bg-card px-3 py-2 text-sm outline-none focus:ring-2 focus:ring-primary/20 dark:border-border dark:bg-background"
                                />
                                {motivoError && <p className="text-xs text-destructive">{motivoError}</p>}
                            </div>

                            {puedeNotaCredito && (
                                <p className="text-xs leading-relaxed text-muted-foreground">
                                    ¿Es una devolución o corrección comercial? Considera{' '}
                                    <button type="button" className="font-medium text-primary hover:underline" onClick={() => router.visit(`/sunat/nota-credito/nueva?doc_id=${anularDoc.id}&tipo_doc=${anularDoc.tipo_doc}`)}>
                                        emitir una nota de crédito
                                    </button>{' '}en lugar de anular.
                                </p>
                            )}
                        </div>

                        <div className="mt-5 flex flex-col gap-3 border-t border-border/60 pt-4 sm:flex-row sm:items-center sm:justify-between">
                            <p className="text-xs text-muted-foreground">El estado cambia solo cuando SUNAT procesa la operación.</p>
                            <div className="flex justify-end gap-2">
                                <Button type="button" variant="ghost" onClick={() => setAnularDoc(null)} disabled={anulando}>Cancelar</Button>
                                <Button type="button" variant="destructive" onClick={ejecutarAnulacion} disabled={anulando}>
                                    {anulando && <Loader2 className="mr-2 size-4 animate-spin" />}
                                    Confirmar anulación
                                </Button>
                            </div>
                        </div>
                    </div>
                  </div>
                </div>
            )}

            {/* Visor de respuesta de SUNAT ("ojito") */}
            {respDoc && (
                <div className="fixed inset-0 z-[100] overflow-y-auto bg-black/50" onClick={() => setRespDoc(null)}>
                  <div className="flex min-h-full items-center justify-center p-4">
                    <div className="w-full max-w-2xl rounded-2xl bg-card p-5 shadow-soft" onClick={(e) => e.stopPropagation()}>
                        <div className="mb-4 flex items-center justify-between gap-2">
                            <div>
                                <h3 className="text-base font-semibold text-foreground">Respuesta de SUNAT</h3>
                                <p className="font-mono text-xs text-muted-foreground">{respDoc.numero}</p>
                            </div>
                            <button type="button" onClick={() => setRespDoc(null)} className="rounded-lg p-1 text-muted-foreground transition-colors hover:bg-secondary">
                                <X className="size-4" />
                            </button>
                        </div>

                        {cargandoResp && (
                            <div className="flex items-center gap-2 py-8 text-sm text-muted-foreground">
                                <Loader2 className="size-4 animate-spin" /> Cargando respuesta…
                            </div>
                        )}

                        {errorResp && <p className="py-6 text-sm text-destructive">{errorResp}</p>}

                        {!cargandoResp && !errorResp && respJson && (
                            <div className="flex flex-col gap-4">
                                {/* Resumen legible: por qué SUNAT lo aceptó / observó / rechazó */}
                                <div className="rounded-xl border border-border bg-muted/40 p-4">
                                    <div className="mb-2 flex items-center gap-2">
                                        <StatusBadge status={respDoc.estado} />
                                        {respSunat?.codigo != null && respSunat.codigo !== '' && (
                                            <span className="font-mono text-xs text-muted-foreground">Código {respSunat.codigo}</span>
                                        )}
                                    </div>
                                    <p className="text-sm text-foreground">
                                        {respSunat?.descripcion || 'Sin mensaje de SUNAT todavía (el comprobante aún no tiene respuesta).'}
                                    </p>
                                    {Array.isArray(respSunat?.notas) && respSunat.notas.length > 0 && (
                                        <ul className="mt-2 list-disc space-y-1 pl-5 text-xs text-muted-foreground">
                                            {(respSunat.notas as string[]).map((n, i) => <li key={i}>{n}</li>)}
                                        </ul>
                                    )}
                                </div>

                                {/* JSON completo */}
                                <div>
                                    <div className="mb-1.5 flex items-center justify-between">
                                        <span className="text-xs font-medium text-muted-foreground">Respuesta completa (JSON)</span>
                                        <Button type="button" variant="ghost" size="sm" className="gap-1.5 px-2" onClick={copiarResp}>
                                            {copiado ? <Check className="size-3.5" /> : <Copy className="size-3.5" />}
                                            {copiado ? 'Copiado' : 'Copiar'}
                                        </Button>
                                    </div>
                                    <pre className="max-h-[50vh] overflow-auto rounded-xl bg-muted/50 p-3 text-xs leading-relaxed">
                                        <code dangerouslySetInnerHTML={{ __html: resaltarJson(respJson) }} />
                                    </pre>
                                </div>
                            </div>
                        )}
                    </div>
                  </div>
                </div>
            )}
        </>
    );
}
