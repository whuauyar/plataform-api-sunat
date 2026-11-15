import { Head, router, usePage } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import { Loader2, Search } from '@/lib/icons';
import { ClienteSelector, type ClienteData } from '@/components/sunat/cliente-selector';
import { ItemsEditor, calcItem, defaultItem, type ItemRow } from '@/components/sunat/items-editor';
import { ncMotivoConfig, lockedFieldsFor } from '@/lib/nota-motivos';
import { PdfPreviewModal } from '@/components/sunat/pdf-preview-modal';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Combobox } from '@/components/ui/combobox';
import SunatLayout from '@/layouts/sunat-layout';
import type { MotivoNC, SerieSunat, TenantSunat } from '@/types';

type Emitido = { tipo: string; id: number; numero: string; formato?: string };

const PDF_FORMATOS = [{ v: 'a4', l: 'A4' }, { v: 'a5', l: 'A5' }, { v: 'ticket-80', l: '80mm' }, { v: 'ticket-58', l: '58mm' }];

type DocOriginal = {
    id: number;
    tipo_doc: string;
    serie: string;
    correlativo: number;
    numero: string;
    cliente: string;
    cliente_tipo_doc?: string | null;
    cliente_num_doc?: string | null;
    cliente_direccion?: string | null;
    total: number;
    moneda: string;
    items: {
        codigo?: string;
        cod_producto_sunat?: string;
        descripcion: string;
        cantidad: number;
        precio_unitario: number;
        unidad: string;
        tip_afe_igv?: string;
    }[];
};

function clienteDeDoc(doc: DocOriginal): ClienteData {
    return {
        tipo_doc: doc.cliente_tipo_doc || (doc.tipo_doc === '03' ? '1' : '6'),
        num_doc: doc.cliente_num_doc || '',
        razon_social: doc.cliente,
        direccion: doc.cliente_direccion || '',
    };
}

type EditarNota = {
    id: number;
    serie: string;
    correlativo: number;
    fecha: string;
    moneda: string;
    cod_motivo: string;
    des_motivo: string;
    cliente: { tipo_doc: string; num_doc: string; razon_social: string; direccion: string };
    doc_afectado: { tipo: string; serie: string; correlativo: number; numero: string };
    items: DocOriginal['items'];
};

type Props = {
    motivos: MotivoNC[];
    series: SerieSunat[];
    doc_original: DocOriginal | null;
    tenant: Pick<TenantSunat, 'ruc' | 'razon_social'>;
    /** Endpoint de envío (por defecto crea; en edición apunta a actualizar). */
    post_url?: string;
    /** Modo edición: precarga la nota rechazada/pendiente para corregir y reenviar. */
    editar?: EditarNota;
};

/** Convierte los ítems del comprobante original a filas del editor unificado. */
function docItemsToRows(doc: DocOriginal): ItemRow[] {
    return (doc.items ?? []).map((i) => ({
        ...defaultItem(),
        codigo: i.codigo ?? '',
        cod_producto_sunat: i.cod_producto_sunat ?? '',
        descripcion: i.descripcion,
        unidad: i.unidad || 'NIU',
        cantidad: i.cantidad,
        precio_unitario: i.precio_unitario,
        tip_afe_igv: i.tip_afe_igv ?? '10',
    }));
}

function fmt(n: number) {
    return new Intl.NumberFormat('es-PE', { minimumFractionDigits: 2 }).format(n);
}

export default function NuevaNotaCredito({ motivos, series, doc_original, post_url = '/sunat/nota-credito', editar }: Props) {
    const editando = !!editar;

    // En edición, el "documento afectado" se reconstruye desde la nota rechazada.
    const docEditado: DocOriginal | null = editar
        ? {
            id: 0,
            tipo_doc: editar.doc_afectado.tipo,
            serie: editar.doc_afectado.serie,
            correlativo: editar.doc_afectado.correlativo,
            numero: editar.doc_afectado.numero,
            cliente: editar.cliente.razon_social,
            cliente_tipo_doc: editar.cliente.tipo_doc,
            cliente_num_doc: editar.cliente.num_doc,
            cliente_direccion: editar.cliente.direccion,
            total: 0,
            moneda: editar.moneda,
            items: editar.items,
          }
        : null;

    const [busqueda, setBusqueda]         = useState('');
    const [resultados, setResultados]     = useState<DocOriginal[]>([]);
    const [buscando, setBuscando]         = useState(false);
    const [docSeleccionado, setDocSel]    = useState<DocOriginal | null>(docEditado ?? doc_original);
    const [cliente, setCliente]           = useState<ClienteData | null>(
        editar ? { tipo_doc: editar.cliente.tipo_doc, num_doc: editar.cliente.num_doc, razon_social: editar.cliente.razon_social, direccion: editar.cliente.direccion }
            : doc_original ? clienteDeDoc(doc_original) : null,
    );

    const [serie, setSerie]               = useState(editar?.serie ?? series[0]?.serie ?? 'FC01');
    const [fecha, setFecha]               = useState(editar?.fecha ?? new Date().toISOString().split('T')[0]);
    const [motivo, setMotivo]             = useState(editar?.cod_motivo ?? '');
    const [desMotivo, setDesMotivo]       = useState(editar?.des_motivo ?? '');
    const [descGlobal, setDescGlobal]     = useState(0); // descuento global (monto con IGV), motivo 04
    const [items, setItems]               = useState<ItemRow[]>(
        editar ? docItemsToRows(docEditado!) : doc_original ? docItemsToRows(doc_original) : [defaultItem()]
    );

    // Config del formulario según el motivo (qué se edita, secciones, emisión).
    const cfg = ncMotivoConfig(motivo);
    const itemsTotalConIgv = items.reduce((acc, it) => acc + calcItem(it).total, 0);
    const [submitting, setSubmitting]     = useState(false);
    const [errors, setErrors]             = useState<Record<string, string>>({});

    // PDF: formato + modal tras emitir
    const { props: pageProps } = usePage();
    const [pdfFormat, setPdfFormat]       = useState('a4');
    const [pdfModal, setPdfModal]         = useState<Emitido | null>(null);
    // eslint-disable-next-line @typescript-eslint/no-explicit-any
    const emitidoFlash = (pageProps as any)?.flash?.emitido as Emitido | undefined;
    useEffect(() => {
        if (emitidoFlash?.id) setPdfModal(emitidoFlash);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [emitidoFlash?.id]);

    async function buscarDocumento() {
        if (!busqueda.trim()) return;
        setBuscando(true);
        try {
            const res = await fetch(`/sunat/buscar-documento?q=${encodeURIComponent(busqueda)}`, {
                headers: { Accept: 'application/json' },
            });
            const data: DocOriginal[] = await res.json();
            setResultados(data);
        } finally {
            setBuscando(false);
        }
    }

    function seleccionarDoc(doc: DocOriginal) {
        setDocSel(doc);
        setCliente(clienteDeDoc(doc));   // prefill del cliente (editable, sin tocar el original)
        setItems(docItemsToRows(doc));
        setResultados([]);
        setBusqueda('');
    }

    function validate() {
        const e: Record<string, string> = {};
        if (!docSeleccionado) e.doc = 'Selecciona el comprobante a anular/modificar.';
        if (!motivo)          e.motivo = 'Selecciona el motivo.';
        if (items.some((it) => !it.descripcion)) e.items = 'Todos los ítems deben tener descripción.';
        setErrors(e);
        return Object.keys(e).length === 0;
    }

    function handleSubmit(enviar: boolean) {
        if (!validate() || !docSeleccionado) return;
        setSubmitting(true);

        const payload = {
            serie,
            fecha_emision:        fecha,
            tipo_moneda:          docSeleccionado.moneda ?? 'PEN',
            cod_motivo:           motivo,
            des_motivo:           desMotivo || (motivos.find((m) => m.codigo === motivo)?.descripcion ?? motivo),
            doc_afectado_tipo:        docSeleccionado.tipo_doc,
            doc_afectado_serie:       docSeleccionado.serie,
            doc_afectado_correlativo: docSeleccionado.correlativo,
            cliente: {
                tipo_doc:     cliente?.tipo_doc ?? (docSeleccionado.tipo_doc === '03' ? '1' : '6'),
                num_doc:      cliente?.num_doc ?? '',
                razon_social: cliente?.razon_social ?? docSeleccionado.cliente,
                direccion:    cliente?.direccion || undefined,
                email:        cliente?.email || undefined,
            },
            items: items.map((it) => ({
                codigo:             it.codigo || undefined,
                cod_producto_sunat: it.cod_producto_sunat || undefined,
                descripcion:        it.descripcion,
                unidad:             it.unidad,
                cantidad:           it.cantidad,
                precio_unitario:    it.precio_unitario,
                tip_afe_igv:        it.tip_afe_igv,
                descuentos: it.descuento_pct > 0 ? [{
                    cod_tipo:   '00',
                    factor:     it.descuento_pct / 100,
                    monto:      it.precio_unitario * it.cantidad * (it.descuento_pct / 100),
                    monto_base: it.precio_unitario * it.cantidad,
                }] : undefined,
            })),
            // Descuento global (motivo 04): se envía sobre la base gravable (sin IGV,
            // cod 02). El usuario ingresa el monto CON IGV, se convierte a base ÷1.18.
            descuentos_globales:
                cfg.showDescuentoGlobal && descGlobal > 0
                    ? [{ cod_tipo: '02', monto: Number((descGlobal / 1.18).toFixed(2)) }]
                    : undefined,
            enviar_automatico: enviar,
            pdf_format:        pdfFormat,
        };

        router.post(post_url, payload, {
            onFinish: () => setSubmitting(false),
        });
    }

    return (
        <SunatLayout>
            <Head title={editando ? 'Editar Nota de Crédito' : 'Nueva Nota de Crédito'} />

            <div className="mx-auto max-w-4xl">
                <h1 className="mb-6 text-2xl font-semibold tracking-tight">{editando ? 'Editar Nota de Crédito' : 'Nueva Nota de Crédito'}</h1>
                {editando && (
                    <div className="mb-4 flex items-center gap-3 rounded-xl border border-amber-300/60 bg-amber-50 px-4 py-3 text-sm dark:border-amber-900/40 dark:bg-amber-900/15">
                        <span className="text-amber-600 dark:text-amber-400">!</span>
                        <span>Editando <strong>{editar?.serie}-{String(editar?.correlativo ?? '').padStart(8, '0')}</strong> (rechazada). Corrige y guarda: se reenviará a SUNAT con la misma serie y número.</span>
                    </div>
                )}

                <div className="flex flex-col gap-6">
                    {/* ── BUSCAR DOCUMENTO ORIGINAL ── */}
                    <Card>
                        <CardHeader>
                            <CardTitle className="text-base">Comprobante original</CardTitle>
                        </CardHeader>
                        <CardContent className="flex flex-col gap-4">
                            <div className="flex gap-2">
                                <Input
                                    placeholder="Buscar por número (F001-00000001), cliente..."
                                    value={busqueda}
                                    onChange={(e) => setBusqueda(e.target.value)}
                                    onKeyDown={(e) => e.key === 'Enter' && buscarDocumento()}
                                    className="flex-1"
                                />
                                <Button type="button" variant="outline" onClick={buscarDocumento} disabled={buscando}>
                                    {buscando ? <Loader2 className="size-4 animate-spin" /> : <Search className="size-4" />}
                                </Button>
                            </div>

                            {errors.doc && <p className="text-xs text-destructive">{errors.doc}</p>}

                            {resultados.length > 0 && (
                                <div className="rounded-md border">
                                    {resultados.map((doc) => (
                                        <button
                                            key={`${doc.tipo_doc}-${doc.id}`}
                                            type="button"
                                            className="flex w-full items-center justify-between px-4 py-3 text-left hover:bg-muted/50 border-b last:border-0"
                                            onClick={() => seleccionarDoc(doc)}
                                        >
                                            <div>
                                                <span className="text-sm font-medium">{doc.numero}</span>
                                                <span className="ml-2 text-xs text-muted-foreground">{doc.cliente}</span>
                                            </div>
                                            <span className="text-sm font-medium">
                                                S/ {fmt(doc.total)}
                                            </span>
                                        </button>
                                    ))}
                                </div>
                            )}

                            {docSeleccionado && (
                                <div className="flex items-center justify-between rounded-lg border border-primary/30 bg-primary/5 px-4 py-3">
                                    <div>
                                        <p className="text-sm font-medium">{docSeleccionado.numero}</p>
                                        <p className="text-xs text-muted-foreground">{docSeleccionado.cliente}</p>
                                    </div>
                                    <div className="text-right">
                                        <p className="text-sm font-semibold">S/ {fmt(docSeleccionado.total)}</p>
                                        <button
                                            type="button"
                                            onClick={() => { setDocSel(null); setItems([defaultItem()]); }}
                                            className="text-xs text-muted-foreground hover:text-destructive"
                                        >
                                            Cambiar
                                        </button>
                                    </div>
                                </div>
                            )}
                        </CardContent>
                    </Card>

                    {/* ── CLIENTE (prefilled del comprobante, editable) ── */}
                    {docSeleccionado && (
                        <ClienteSelector
                            value={cliente}
                            onChange={setCliente}
                            label="Datos del cliente"
                            subtitulo="Se toman del comprobante relacionado. Puedes editarlos sin modificar el comprobante original."
                        />
                    )}

                    {/* ── DATOS NC ── */}
                    <Card>
                        <CardHeader><CardTitle className="text-base">Datos de la nota</CardTitle></CardHeader>
                        <CardContent>
                            <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                                <div className="flex flex-col gap-1.5">
                                    <Label>Serie</Label>
                                    <Combobox
                                        value={serie}
                                        onChange={(v) => setSerie(v)}
                                        options={
                                            series.length > 0
                                                ? series.map((s) => ({ value: String(s.serie), label: s.serie }))
                                                : [{ value: 'FC01', label: 'FC01' }]
                                        }
                                        searchable
                                        className="h-9"
                                    />
                                </div>
                                <div className="flex flex-col gap-1.5">
                                    <Label>Fecha de emisión</Label>
                                    <Input type="date" value={fecha} onChange={(e) => setFecha(e.target.value)} />
                                </div>
                                <div className="flex flex-col gap-1.5 sm:col-span-2 lg:col-span-1">
                                    <Label>Motivo</Label>
                                    <Combobox
                                        value={motivo}
                                        onChange={(v) => {
                                            setMotivo(v);
                                            const m = motivos.find((m) => m.codigo === v);
                                            if (m) setDesMotivo(m.descripcion);
                                            if (ncMotivoConfig(v).showDescuentoGlobal === false) setDescGlobal(0);
                                        }}
                                        options={[
                                            { value: '', label: 'Selecciona motivo' },
                                            ...motivos.map((m) => ({ value: String(m.codigo), label: `${m.codigo} - ${m.descripcion}` })),
                                        ]}
                                        placeholder="Selecciona motivo"
                                        searchable
                                        className="h-9"
                                    />
                                    {errors.motivo && <p className="text-xs text-destructive">{errors.motivo}</p>}
                                </div>
                            </div>
                        </CardContent>
                    </Card>

                    {/* ── AVISO SEGÚN MOTIVO ── */}
                    {docSeleccionado && motivo && cfg.aviso && (
                        <div className="rounded-lg border border-primary/30 bg-primary/5 px-4 py-2.5 text-xs text-foreground/80">
                            {cfg.aviso}
                        </div>
                    )}

                    {/* ── DESCUENTO GLOBAL (motivo 04) ── */}
                    {docSeleccionado && cfg.showDescuentoGlobal && (
                        <Card>
                            <CardHeader><CardTitle className="text-base">Descuento global</CardTitle></CardHeader>
                            <CardContent>
                                <div className="grid gap-4 sm:grid-cols-2 sm:max-w-md">
                                    <div className="flex flex-col gap-1.5">
                                        <Label>Monto del descuento (S/)</Label>
                                        <Input
                                            type="number" min={0} step="0.01"
                                            value={descGlobal || ''}
                                            onChange={(e) => setDescGlobal(parseFloat(e.target.value) || 0)}
                                            placeholder="0.00"
                                            className="text-right"
                                        />
                                    </div>
                                    <div className="flex flex-col gap-1.5">
                                        <Label>Equivale a (%)</Label>
                                        <Input
                                            type="number" min={0} max={100} step="0.01"
                                            value={itemsTotalConIgv > 0 ? Number(((descGlobal / itemsTotalConIgv) * 100).toFixed(2)) || '' : ''}
                                            onChange={(e) => {
                                                const pct = parseFloat(e.target.value) || 0;
                                                setDescGlobal(Number(((itemsTotalConIgv * pct) / 100).toFixed(2)));
                                            }}
                                            placeholder="0"
                                            className="text-right"
                                        />
                                    </div>
                                </div>
                                <p className="mt-2 text-xs text-muted-foreground">
                                    Descuento sobre el total de la operación (IGV incluido). Se aplica a la base gravada y SUNAT recalcula el IGV.
                                </p>
                            </CardContent>
                        </Card>
                    )}

                    {/* ── ITEMS ── */}
                    <ItemsEditor
                        value={items}
                        onChange={setItems}
                        moneda={docSeleccionado?.moneda === 'USD' ? 'USD' : 'PEN'}
                        titulo={cfg.itemsTitulo}
                        error={errors.items}
                        readOnly={cfg.itemsMode === 'locked'}
                        allowAdd={cfg.allowAdd}
                        allowRemove={cfg.allowRemove}
                        lockedFields={lockedFieldsFor(cfg.itemsMode)}
                        descuentoGlobal={cfg.showDescuentoGlobal ? descGlobal : 0}
                    />

                    {/* ── ACCIONES ── */}
                    <div className="flex flex-wrap items-center gap-3 pb-6">
                        <div className="flex items-center gap-2">
                            <span className="text-xs font-medium text-muted-foreground">Formato PDF</span>
                            <div className="flex rounded-xl border border-border p-0.5">
                                {PDF_FORMATOS.map(({ v, l }) => (
                                    <button key={v} type="button" onClick={() => setPdfFormat(v)}
                                        className={`rounded-lg px-2.5 py-1 text-xs font-medium transition-colors ${pdfFormat === v ? 'bg-primary text-primary-foreground' : 'text-muted-foreground hover:bg-secondary'}`}>
                                        {l}
                                    </button>
                                ))}
                            </div>
                        </div>

                        <div className="flex-1" />

                        <Button onClick={() => handleSubmit(true)} disabled={submitting} className="gap-2 rounded-xl px-5">
                            {submitting && <Loader2 className="size-4 animate-spin" />}
                            Emitir y enviar a SUNAT
                        </Button>
                        <Button variant="outline" onClick={() => handleSubmit(false)} disabled={submitting} className="rounded-xl">
                            Guardar borrador
                        </Button>
                        <Button variant="ghost" onClick={() => router.visit('/sunat/historial')} disabled={submitting} className="rounded-xl">
                            Cancelar
                        </Button>
                    </div>
                </div>
            </div>

            {/* Modal de PDF tras emitir */}
            {pdfModal && (
                <PdfPreviewModal
                    tipo={pdfModal.tipo}
                    id={pdfModal.id}
                    numero={pdfModal.numero}
                    initialFormat={pdfModal.formato ?? 'a4'}
                    onClose={() => setPdfModal(null)}
                />
            )}
        </SunatLayout>
    );
}
