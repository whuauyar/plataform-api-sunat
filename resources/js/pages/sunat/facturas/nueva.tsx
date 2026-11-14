import { Head, router, usePage } from '@inertiajs/react';
import { useEffect, useMemo, useState } from 'react';
import { AlertCircle, Calendar, Check, CreditCard, Loader2, X } from '@/lib/icons';
import { ClienteSelector, type ClienteData } from '@/components/sunat/cliente-selector';
import { ItemsEditor, calcItem, type ItemRow } from '@/components/sunat/items-editor';
import { PdfPreviewModal } from '@/components/sunat/pdf-preview-modal';
import { Button } from '@/components/ui/button';
import { Combobox } from '@/components/ui/combobox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import SunatLayout from '@/layouts/sunat-layout';
import type { ClienteSunat, SerieSunat, TenantSunat } from '@/types';

type Emitido = { tipo: string; id: number; numero: string; formato?: string };

// ─── Catálogos SUNAT ─────────────────────────────────────────────────────────

// Tabla 17 – Tipo de operación
const TIPOS_OPERACION = [
    { code: '0101', label: 'Venta interna' },
    { code: '0112', label: 'Venta interna - sustituye guía' },
    { code: '0200', label: 'Exportación de bienes' },
    { code: '0201', label: 'Exportación de servicios' },
    { code: '1001', label: 'Sujeto a detracción' },
];

// Tabla Catálogo 54 – Bienes y servicios sujetos a detracción (SUNAT vigente)
const DETRACCIONES = [
    { codigo: '001', descripcion: 'Azúcar y melaza de caña',                    porcentaje: 10 },
    { codigo: '003', descripcion: 'Alcohol etílico',                             porcentaje: 10 },
    { codigo: '004', descripcion: 'Recursos hidrobiológicos',                    porcentaje:  4 },
    { codigo: '005', descripcion: 'Maíz amarillo duro',                          porcentaje:  4 },
    { codigo: '007', descripcion: 'Caña de azúcar',                              porcentaje: 10 },
    { codigo: '008', descripcion: 'Madera',                                      porcentaje:  4 },
    { codigo: '009', descripcion: 'Arena y piedra',                              porcentaje: 10 },
    { codigo: '010', descripcion: 'Residuos, subproductos, desechos',            porcentaje: 15 },
    { codigo: '012', descripcion: 'Intermediación laboral y tercerización',      porcentaje: 10 },
    { codigo: '014', descripcion: 'Carnes y despojos comestibles',               porcentaje:  4 },
    { codigo: '017', descripcion: 'Harina, polvo y pellets de pescado',          porcentaje:  4 },
    { codigo: '019', descripcion: 'Arrendamiento de bienes muebles',             porcentaje: 10 },
    { codigo: '020', descripcion: 'Mantenimiento y reparación de bienes muebles',porcentaje: 10 },
    { codigo: '021', descripcion: 'Movimiento de carga',                         porcentaje: 10 },
    { codigo: '022', descripcion: 'Otros servicios empresariales',               porcentaje: 10 },
    { codigo: '023', descripcion: 'Leche',                                       porcentaje:  4 },
    { codigo: '024', descripcion: 'Comisión mercantil',                          porcentaje: 10 },
    { codigo: '025', descripcion: 'Fabricación de bienes por encargo',           porcentaje: 10 },
    { codigo: '026', descripcion: 'Servicio de transporte de personas',          porcentaje: 10 },
    { codigo: '027', descripcion: 'Servicio de transporte de carga',             porcentaje:  4 },
    { codigo: '030', descripcion: 'Contratos de construcción',                   porcentaje:  4 },
    { codigo: '031', descripcion: 'Oro gravado con el IGV',                      porcentaje: 10 },
    { codigo: '034', descripcion: 'Minerales metálicos no auríferos',            porcentaje: 10 },
    { codigo: '035', descripcion: 'Bienes exonerados del IGV',                   porcentaje:  1.5 },
    { codigo: '036', descripcion: 'Oro y demás minerales metálicos exonerados',  porcentaje:  1.5 },
    { codigo: '037', descripcion: 'Demás servicios gravados con el IGV',         porcentaje: 10 },
    { codigo: '039', descripcion: 'Minerales no metálicos',                      porcentaje: 10 },
    { codigo: '040', descripcion: 'Bien inmueble gravado con IGV',               porcentaje:  4 },
];

// Catálogo 59 – Medios de pago de detracción
const MEDIOS_PAGO_DET = [
    { code: '001', label: 'Depósito en cuenta' },
    { code: '003', label: 'Transferencia de fondos' },
    { code: '004', label: 'Orden de pago' },
    { code: '005', label: 'Tarjeta de débito' },
    { code: '006', label: 'Tarjeta de crédito emitida en el país' },
    { code: '009', label: 'Efectivo - otros casos' },
];

// ─── Types ────────────────────────────────────────────────────────────────────

type CotizacionPrefill = {
    numero: string;
    moneda: string;
    cliente: { tipo_documento: string; numero_documento: string; razon_social: string; direccion: string };
    items: { descripcion: string; unidad: string; cantidad: number; precio_unitario: number; tip_afe_igv: string }[];
    observacion: string | null;
};

type EditarPrefill = {
    id: number;
    tipo: string;
    serie: string;
    correlativo: number;
    fecha: string;
    moneda: 'PEN' | 'USD';
    tipo_operacion: string;
    forma_pago: string;
    observacion: string;
    cliente: { tipo_doc: string; num_doc: string; razon_social: string; direccion: string };
    items: { descripcion: string; unidad: string; cantidad: number; precio_unitario: number; tip_afe_igv: string; codigo?: string; cod_producto_sunat?: string }[];
};

type Props = {
    tenant: TenantSunat;
    series_factura: SerieSunat[];
    series_boleta: SerieSunat[];
    clientes: ClienteSunat[];
    tipo_inicial: string;
    cotizacion?: CotizacionPrefill;
    /** Modo "solo boleta": oculta el selector de tipo y fuerza boleta. */
    lock_tipo?: boolean;
    /** Endpoint al que se envía (por defecto /sunat/facturas). */
    post_url?: string;
    /** Modo edición: precarga un comprobante rechazado/pendiente para corregir y reenviar. */
    editar?: EditarPrefill;
};

// ─── Helpers ─────────────────────────────────────────────────────────────────

function fmt(n: number, dec = 2) {
    return new Intl.NumberFormat('es-PE', { minimumFractionDigits: dec, maximumFractionDigits: dec }).format(n);
}

function fmtDate(iso: string) {
    if (!iso) return '-';
    const [y, m, d] = iso.split('-');
    return `${d}/${m}/${y}`;
}

type Cuota = { fecha: string; monto: number };

/** Suma días a una fecha ISO (YYYY-MM-DD) y devuelve otra ISO. */
function sumarDias(iso: string, dias: number): string {
    const base = iso ? new Date(iso + 'T00:00:00') : new Date();
    base.setDate(base.getDate() + dias);
    return base.toISOString().split('T')[0];
}

/** Reparte un total en n montos de 2 decimales; la última cuota absorbe el resto. */
function repartirMontos(total: number, n: number): number[] {
    if (n <= 0) return [];
    const cuota = Math.floor((total / n) * 100) / 100;
    const montos = Array.from({ length: n }, () => cuota);
    const suma = cuota * n;
    montos[n - 1] = Math.round((montos[n - 1] + (total - suma)) * 100) / 100;
    return montos;
}

// ─── Componente principal ─────────────────────────────────────────────────────

export default function NuevaFactura({ tenant, series_factura, series_boleta, tipo_inicial, cotizacion, lock_tipo = false, post_url = '/sunat/facturas', editar }: Props) {

    const editando = !!editar;

    // ── Cabecera ──
    const [tipoDoc, setTipoDoc]   = useState<'01' | '03'>(editar ? (editar.tipo === '03' ? '03' : '01') : (tipo_inicial === 'boleta' ? '03' : '01'));
    const series                  = tipoDoc === '01' ? series_factura : series_boleta;
    const [serie, setSerie]       = useState(editar?.serie ?? series[0]?.serie ?? (tipoDoc === '01' ? 'F001' : 'B001'));
    const [fecha, setFecha]       = useState(editar?.fecha ?? new Date().toISOString().split('T')[0]);
    const [moneda, setMoneda]     = useState<'PEN' | 'USD'>(editar?.moneda ?? 'PEN');
    const [tipoOp, setTipoOp]     = useState(editar?.tipo_operacion ?? '0101');

    // ── Forma de pago ──
    const [formaPago, setFormaPago]           = useState<'Contado' | 'Credito'>(editar?.forma_pago === 'Credito' ? 'Credito' : 'Contado');
    const [numCuotas, setNumCuotas]           = useState(1);
    const [cuotas, setCuotas]                 = useState<Cuota[]>([]);

    // ── Cliente (pre-fill desde edición o cotización si aplica) ──
    const [cliente, setCliente] = useState<ClienteData | null>(
        editar?.cliente
            ? {
                tipo_doc: editar.cliente.tipo_doc ?? '6',
                num_doc: editar.cliente.num_doc ?? '',
                razon_social: editar.cliente.razon_social ?? '',
                direccion: editar.cliente.direccion ?? '',
              }
            : cotizacion?.cliente
            ? {
                tipo_doc: cotizacion.cliente.tipo_documento ?? '6',
                num_doc: cotizacion.cliente.numero_documento ?? '',
                razon_social: cotizacion.cliente.razon_social ?? '',
                direccion: cotizacion.cliente.direccion ?? '',
              }
            : null,
    );

    // ── Ítems (pre-fill desde edición o cotización si aplica) ──
    const [items, setItems] = useState<ItemRow[]>(
        editar?.items.length
            ? editar.items.map((it) => ({
                tipo_item:          'servicio' as const,
                codigo:             it.codigo ?? '',
                cod_producto_sunat: it.cod_producto_sunat ?? '',
                descripcion:        it.descripcion,
                unidad:             it.unidad,
                cantidad:           it.cantidad,
                precio_unitario:    it.precio_unitario,
                descuento_pct:      0,
                tip_afe_igv:        it.tip_afe_igv,
              }))
            : cotizacion?.items.length
            ? cotizacion.items.map((it) => ({
                tipo_item:          'servicio' as const,
                codigo:             '',
                cod_producto_sunat: '',
                descripcion:        it.descripcion,
                unidad:             it.unidad,
                cantidad:           it.cantidad,
                precio_unitario:    it.precio_unitario,
                descuento_pct:      0,
                tip_afe_igv:        it.tip_afe_igv,
              }))
            : []
    );

    // ── Detracción ──
    const [detEnabled, setDetEnabled]     = useState(false);
    const [detCodigo, setDetCodigo]       = useState('');
    const [detCuenta, setDetCuenta]       = useState('');
    const [detMedioPago, setDetMedioPago] = useState('001');
    const [detValorRef, setDetValorRef]   = useState(''); // solo 027 (transporte de carga)

    // ── Extras ──
    const [observacion, setObservacion] = useState(editar?.observacion ?? '');
    const [ordenCompra, setOrdenCompra] = useState('');

    const [submitting, setSubmitting] = useState(false);
    const [errors, setErrors]         = useState<Record<string, string>>({});

    // ── Reset serie al cambiar tipo ──
    useEffect(() => {
        // En modo edición la serie/tipo están fijos: no reiniciar (perdería la serie del doc).
        if (editando) return;
        const s = tipoDoc === '01' ? series_factura : series_boleta;
        setSerie(s[0]?.serie ?? (tipoDoc === '01' ? 'F001' : 'B001'));
        // Al cambiar a boleta, desactivar detracción
        if (tipoDoc === '03') setDetEnabled(false);
    }, [tipoDoc]);

    // ── Auto tipo_operacion cuando se activa detracción ──
    useEffect(() => {
        if (detEnabled) setTipoOp('1001');
        else if (tipoOp === '1001') setTipoOp('0101');
    }, [detEnabled]);

    // ── Vista previa (modal representación impresa) ──
    const [previewOpen, setPreviewOpen]     = useState(false);

    // ── PDF: formato elegido + modal tras emitir ──
    const { props: pageProps } = usePage();
    const [pdfFormat, setPdfFormat]         = useState('a4');
    const [pdfModal, setPdfModal]           = useState<Emitido | null>(null);
    // eslint-disable-next-line @typescript-eslint/no-explicit-any
    const emitidoFlash = (pageProps as any)?.flash?.emitido as Emitido | undefined;
    // Inertia reutiliza el componente al redirigir al mismo formulario, por eso
    // dependemos del id del flash (no de []) para abrir el modal cuando llega.
    useEffect(() => {
        if (emitidoFlash?.id) setPdfModal(emitidoFlash);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [emitidoFlash?.id]);

    // ── Detracción ──
    const detData = DETRACCIONES.find((d) => d.codigo === detCodigo);
    const detPct  = detData?.porcentaje ?? 0;

    // ── Totales ──
    const totals = useMemo(() => {
        let gravadas = 0, exoneradas = 0, inafectas = 0, exportacion = 0, igvTotal = 0;
        for (const it of items) {
            const { base, igv } = calcItem(it);
            if      (it.tip_afe_igv === '10') { gravadas += base; igvTotal += igv; }
            else if (it.tip_afe_igv === '20') exoneradas += base;
            else if (it.tip_afe_igv === '30') inafectas  += base;
            else if (it.tip_afe_igv === '40') exportacion += base;
        }
        const valorVenta       = gravadas + exoneradas + inafectas + exportacion;
        const totalComprobante = valorVenta + igvTotal;
        // Transporte de carga (027): la detracción (4%) se aplica sobre el MAYOR entre el
        // importe de la operación y el valor referencial de la ruta (D.S. 010-2006-MTC).
        const valorRef         = detCodigo === '027' ? (Number(detValorRef) || 0) : 0;
        const baseDet          = Math.max(totalComprobante, valorRef);
        const detMonto         = detEnabled && detPct > 0 ? Math.round(baseDet * (detPct / 100) * 100) / 100 : 0;
        const netoPagar        = totalComprobante - detMonto;
        return { gravadas, exoneradas, inafectas, exportacion, igvTotal, valorVenta, totalComprobante, baseDet, detMonto, netoPagar };
    }, [items, detEnabled, detPct, detCodigo, detValorRef]);

    // ── Cuotas (crédito): montos automáticos + fechas, y re-ajuste al cambiar el total ──
    // Se reparte el TOTAL del comprobante entre N cuotas. Si cambia la cantidad de
    // cuotas se regeneran fechas (mensuales) y montos; si solo cambia el total (agregar
    // productos) se re-reparten los montos conservando las fechas ya definidas.
    useEffect(() => {
        if (formaPago !== 'Credito') return;
        setCuotas((prev) => {
            const montos = repartirMontos(totals.totalComprobante, numCuotas);
            return Array.from({ length: numCuotas }, (_, i) => ({
                fecha: prev[i]?.fecha || sumarDias(fecha, 30 * (i + 1)),
                monto: montos[i],
            }));
        });
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [formaPago, numCuotas, totals.totalComprobante]);

    // ── Validación ──
    function validate() {
        const e: Record<string, string> = {};
        if (!cliente || !cliente.num_doc || !cliente.razon_social) e.cliente = 'Selecciona o ingresa el cliente.';
        if (items.length === 0) e.items = 'Agrega al menos un ítem.';
        else if (items.some((it) => !it.descripcion)) e.items = 'Todos los ítems deben tener descripción.';
        if (items.some((it) => it.precio_unitario <= 0)) e.items_precio = 'El precio unitario debe ser mayor a 0.';
        if (formaPago === 'Credito') {
            if (cuotas.length === 0) e.cuotas = 'Agrega al menos una cuota.';
            else if (cuotas.some((c) => !c.fecha)) e.cuotas = 'Cada cuota necesita una fecha de pago.';
            else {
                const suma = cuotas.reduce((s, c) => s + (Number(c.monto) || 0), 0);
                if (Math.abs(suma - totals.totalComprobante) > 0.05) e.cuotas = `Las cuotas (S/ ${fmt(suma)}) deben sumar el total (S/ ${fmt(totals.totalComprobante)}).`;
            }
        }
        if (detEnabled && !detCodigo)  e.det_codigo  = 'Selecciona el bien o servicio sujeto a detracción.';
        if (detEnabled && !detCuenta)  e.det_cuenta  = 'La cuenta del Banco de la Nación es requerida.';
        setErrors(e);
        return Object.keys(e).length === 0;
    }

    // ── Envío ──
    function submit(enviarAuto: boolean) {
        if (!validate()) return;
        setSubmitting(true);

        const payload = {
            tipo_documento:  tipoDoc,
            serie,
            fecha_emision:   fecha,
            tipo_moneda:     moneda,
            tipo_operacion:  tipoOp,
            forma_pago:      formaPago,
            cliente: {
                tipo_doc:     cliente?.tipo_doc ?? '6',
                num_doc:      cliente?.num_doc ?? '',
                razon_social: cliente?.razon_social ?? '',
                direccion:    cliente?.direccion || undefined,
                email:        cliente?.email     || undefined,
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
            fecha_vencimiento: formaPago === 'Credito' ? (cuotas[cuotas.length - 1]?.fecha ?? null) : null,
            cuotas: formaPago === 'Credito' ? cuotas.map((c, i) => ({
                numero:      i + 1,
                fecha_pago:  c.fecha,
                monto:       c.monto,
            })) : null,
            detraccion: detEnabled && detData ? {
                cod_bien:      detData.codigo,
                porcentaje:    detData.porcentaje,
                monto:         totals.detMonto,
                cta_banco:     detCuenta,
                cod_medio_pago: detMedioPago,
            } : null,
            observacion:       observacion || null,
            extras:            ordenCompra ? { orden_compra: ordenCompra } : null,
            enviar_automatico: enviarAuto,
            pdf_format:        pdfFormat,
        };

        router.post(post_url, payload, {
            onFinish: () => setSubmitting(false),
        });
    }

    const tipoLabel  = tipoDoc === '01' ? 'Factura' : 'Boleta';
    const simbolo    = moneda === 'PEN' ? 'S/' : '$';
    const sumaCuotas = cuotas.reduce((s, c) => s + (Number(c.monto) || 0), 0);

    // ─── Render ───────────────────────────────────────────────────────────────
    return (
        <SunatLayout>
            <Head title={`${editando ? 'Editar' : 'Nueva'} ${tipoLabel}`} />

            <div className="mx-auto max-w-[1400px]">

                {/* Aviso edición de rechazado */}
                {editando && (
                    <div className="mb-4 flex items-center gap-3 rounded-xl border border-amber-300/60 bg-amber-50 px-4 py-3 text-sm dark:border-amber-900/40 dark:bg-amber-900/15">
                        <AlertCircle className="size-4 text-amber-600 dark:text-amber-400" />
                        <span>Editando <strong>{editar?.serie}-{String(editar?.correlativo).padStart(8, '0')}</strong> (rechazado). Corrige los datos y guarda: se reenviará a SUNAT con la misma serie y número.</span>
                    </div>
                )}

                {/* Aviso cotización convertida */}
                {cotizacion && !editando && (
                    <div className="mb-4 flex items-center gap-3 rounded-xl border border-primary/30 bg-primary/5 px-4 py-3 text-sm">
                        <span className="text-primary">✓</span>
                        <span>Datos pre-cargados desde cotización <strong>{cotizacion.numero}</strong>. Revisa y emite cuando estés listo.</span>
                    </div>
                )}

                {/* Page title */}
                <div className="mb-5 flex items-center gap-3">
                    <h1 className="text-xl font-semibold tracking-tight">
                        {editando ? 'Editar' : 'Nueva'} {tipoLabel}
                    </h1>
                    {tenant.environment !== 'produccion' && (
                        <span className="inline-flex items-center gap-1 rounded-full bg-amber-100 px-2.5 py-0.5 text-[11px] font-medium text-amber-800 dark:bg-amber-900/30 dark:text-amber-300">
                            <AlertCircle className="size-3" />
                            Ambiente Beta
                        </span>
                    )}
                </div>

                {/* Formulario a ancho completo */}
                <div className="mx-auto max-w-4xl">

                    {/* ══════════════ FORMULARIO ══════════════ */}
                    <div className="flex min-w-0 flex-col gap-5">

                        {/* ── 1. ENCABEZADO ── */}
                        <section className="rounded-2xl bg-card shadow-soft">
                            <div className="flex items-center gap-2 border-b border-border/60 px-5 py-3.5">
                                <span className="text-sm font-semibold text-foreground">Datos del comprobante</span>
                            </div>
                            <div className="grid gap-4 p-5 sm:grid-cols-2 lg:grid-cols-4">

                                {/* Tipo - oculto en modo solo boleta */}
                                {!lock_tipo && (
                                    <div className="flex flex-col gap-1.5">
                                        <Label className="text-xs font-medium text-muted-foreground">Tipo</Label>
                                        <div className="grid grid-cols-2 gap-1.5">
                                            {[{ v: '01', l: 'Factura' }, { v: '03', l: 'Boleta' }].map(({ v, l }) => (
                                                <label key={v} className="flex cursor-pointer items-center gap-2 py-2 text-sm font-medium text-foreground">
                                                    <input
                                                        type="radio"
                                                        name="tipo-doc"
                                                        value={v}
                                                        checked={tipoDoc === v}
                                                        onChange={() => setTipoDoc(v as '01' | '03')}
                                                        className="size-4 shrink-0 appearance-none rounded-full border-2 border-muted-foreground/40 bg-transparent transition-colors checked:border-primary checked:bg-primary checked:bg-clip-content checked:p-[3px]"
                                                    />
                                                    {l}
                                                </label>
                                            ))}
                                        </div>
                                    </div>
                                )}

                                {/* Serie */}
                                <div className="flex flex-col gap-1.5">
                                    <Label className="text-xs font-medium text-muted-foreground">Serie</Label>
                                    <Combobox
                                        value={serie}
                                        onChange={(v) => setSerie(v)}
                                        searchable
                                        options={[
                                            ...series.map((s) => ({
                                                value: String(s.serie),
                                                label: `${s.serie} - Corr. ${s.correlativo + 1}`,
                                            })),
                                            ...(series.length === 0
                                                ? [{
                                                    value: tipoDoc === '01' ? 'F001' : 'B001',
                                                    label: tipoDoc === '01' ? 'F001' : 'B001',
                                                }]
                                                : []),
                                        ]}
                                        placeholder="Serie"
                                        className="h-10 rounded-xl"
                                    />
                                </div>

                                {/* Fecha */}
                                <div className="flex flex-col gap-1.5">
                                    <Label className="text-xs font-medium text-muted-foreground">Fecha de emisión</Label>
                                    <Input
                                        type="date" value={fecha}
                                        onChange={(e) => setFecha(e.target.value)}
                                        className="h-10 rounded-xl"
                                    />
                                </div>

                                {/* Moneda */}
                                <div className="flex flex-col gap-1.5">
                                    <Label className="text-xs font-medium text-muted-foreground">Moneda</Label>
                                    <Combobox
                                        value={moneda}
                                        onChange={(v) => setMoneda(v as 'PEN' | 'USD')}
                                        options={[
                                            { value: 'PEN', label: 'PEN - Soles' },
                                            { value: 'USD', label: 'USD - Dólares' },
                                        ]}
                                        className="h-10 rounded-xl"
                                    />
                                </div>
                            </div>
                        </section>

                        {/* ── 2. CONDICIONES DE PAGO ── */}
                        <section className="rounded-2xl bg-card shadow-soft">
                            <div className="flex items-center gap-2 border-b border-border/60 px-5 py-3.5">
                                <CreditCard className="size-4 text-muted-foreground" />
                                <span className="text-sm font-semibold text-foreground">Condiciones de pago y operación</span>
                            </div>
                            <div className="grid gap-4 p-5 sm:grid-cols-2">

                                {/* Tipo operación */}
                                <div className="flex flex-col gap-1.5">
                                    <Label className="text-xs font-medium text-muted-foreground">Tipo de operación</Label>
                                    <Combobox
                                        value={tipoOp}
                                        onChange={(v) => setTipoOp(v)}
                                        options={TIPOS_OPERACION.map((t) => ({
                                            value: String(t.code),
                                            label: `${t.code} - ${t.label}`,
                                        }))}
                                        className="h-10 rounded-xl"
                                    />
                                </div>

                                {/* Forma de pago */}
                                <div className="flex flex-col gap-1.5">
                                    <Label className="text-xs font-medium text-muted-foreground">Forma de pago</Label>
                                    <div className="grid grid-cols-2 gap-1.5">
                                        {(['Contado', 'Credito'] as const).map((fp) => (
                                            <label key={fp} className="flex cursor-pointer items-center gap-2 py-2 text-sm font-medium text-foreground">
                                                <input
                                                    type="radio"
                                                    name="forma-pago"
                                                    value={fp}
                                                    checked={formaPago === fp}
                                                    onChange={() => setFormaPago(fp)}
                                                    className="size-4 shrink-0 appearance-none rounded-full border-2 border-muted-foreground/40 bg-transparent transition-colors checked:border-primary checked:bg-primary checked:bg-clip-content checked:p-[3px]"
                                                />
                                                {fp === 'Contado' ? 'Contado' : 'Crédito'}
                                            </label>
                                        ))}
                                    </div>
                                </div>

                                {/* Cuotas - solo Crédito */}
                                {formaPago === 'Credito' && (
                                    <div className="flex flex-col gap-3 sm:col-span-2">
                                        <div className="flex flex-wrap items-end gap-3">
                                            <div className="flex flex-col gap-1.5">
                                                <Label className="text-xs font-medium text-muted-foreground">
                                                    <Calendar className="mr-1 inline size-3" />
                                                    N° de cuotas
                                                </Label>
                                                <Input
                                                    type="number" min={1} max={36}
                                                    value={numCuotas}
                                                    onChange={(e) => setNumCuotas(Math.max(1, Math.min(36, parseInt(e.target.value) || 1)))}
                                                    className="h-10 w-24 rounded-xl text-center"
                                                />
                                            </div>
                                            <button
                                                type="button"
                                                onClick={() => setCuotas(repartirMontos(totals.totalComprobante, numCuotas).map((m, i) => ({ fecha: sumarDias(fecha, 30 * (i + 1)), monto: m })))}
                                                className="h-10 rounded-xl border border-border bg-secondary px-3 text-xs font-medium transition-colors hover:bg-muted"
                                            >
                                                Distribuir en partes iguales
                                            </button>
                                        </div>

                                        <div className="divide-y divide-border/60 overflow-hidden rounded-xl border border-border">
                                            {cuotas.map((c, i) => (
                                                <div key={i} className="grid grid-cols-[70px_1fr_1fr] items-center gap-3 px-3 py-2">
                                                    <span className="text-xs font-medium text-muted-foreground">Cuota {i + 1}</span>
                                                    <Input
                                                        type="date" value={c.fecha} min={fecha}
                                                        onChange={(e) => setCuotas((prev) => prev.map((x, j) => (j === i ? { ...x, fecha: e.target.value } : x)))}
                                                        className="h-9 rounded-lg text-sm"
                                                    />
                                                    <div className="relative">
                                                        <span className="pointer-events-none absolute left-2.5 top-1/2 -translate-y-1/2 text-xs text-muted-foreground">{simbolo}</span>
                                                        <Input
                                                            type="number" min={0} step="0.01" value={c.monto}
                                                            onChange={(e) => setCuotas((prev) => prev.map((x, j) => (j === i ? { ...x, monto: parseFloat(e.target.value) || 0 } : x)))}
                                                            className="h-9 rounded-lg pl-7 text-right text-sm"
                                                        />
                                                    </div>
                                                </div>
                                            ))}
                                        </div>

                                        <div className="flex items-center justify-between text-xs">
                                            <span className="text-muted-foreground">Suma de cuotas</span>
                                            <span className={Math.abs(sumaCuotas - totals.totalComprobante) > 0.05 ? 'font-medium text-destructive' : 'font-medium text-foreground'}>
                                                {simbolo} {fmt(sumaCuotas)} / {simbolo} {fmt(totals.totalComprobante)}
                                            </span>
                                        </div>
                                        {errors.cuotas && <p className="text-xs text-destructive">{errors.cuotas}</p>}
                                    </div>
                                )}
                            </div>
                        </section>

                        {/* ── 3. CLIENTE ── */}
                        <ClienteSelector value={cliente} onChange={setCliente} error={errors.cliente} />

                        {/* ── 4. ÍTEMS ── */}
                        <ItemsEditor value={items} onChange={setItems} moneda={moneda} error={errors.items || errors.items_precio} />

                        {/* ── 5. DETRACCIÓN (solo Factura) ── */}
                        {tipoDoc === '01' && (
                            <section className="rounded-2xl bg-card shadow-soft">
                                <div className="flex items-center justify-between border-b border-border/60 px-5 py-3.5">
                                    <span className="text-sm font-semibold text-foreground">Detracción (SPOT)</span>
                                    <label className="flex cursor-pointer items-center gap-2 text-sm">
                                        <span className="relative flex size-4 items-center justify-center">
                                            <input
                                                type="checkbox"
                                                checked={detEnabled}
                                                onChange={(e) => setDetEnabled(e.target.checked)}
                                                className="peer size-4 shrink-0 cursor-pointer appearance-none rounded border-2 border-muted-foreground/40 bg-transparent transition-colors checked:border-primary checked:bg-primary"
                                            />
                                            <Check className="pointer-events-none absolute size-3 text-white opacity-0 peer-checked:opacity-100" strokeWidth={3} />
                                        </span>
                                        <span className="text-xs text-muted-foreground">Aplicar detracción</span>
                                    </label>
                                </div>

                                {detEnabled && (
                                    <div className="p-5">
                                        <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                                            {/* Bien/servicio Catálogo 54 */}
                                            <div className="flex flex-col gap-1.5 sm:col-span-2 lg:col-span-3">
                                                <Label className="text-xs font-medium text-muted-foreground">
                                                    Bien o servicio sujeto a detracción (Catálogo 54)
                                                </Label>
                                                <Combobox
                                                    value={detCodigo}
                                                    onChange={(v) => setDetCodigo(v)}
                                                    searchable
                                                    options={[
                                                        { value: '', label: 'Selecciona el bien o servicio' },
                                                        ...DETRACCIONES.map((d) => ({
                                                            value: String(d.codigo),
                                                            label: `${d.codigo} - ${d.descripcion} (${d.porcentaje}%)`,
                                                        })),
                                                    ]}
                                                    placeholder="Selecciona el bien o servicio"
                                                    className={`h-10 rounded-xl ${errors.det_codigo ? 'border-destructive' : ''}`}
                                                />
                                                {errors.det_codigo && <p className="text-xs text-destructive">{errors.det_codigo}</p>}
                                            </div>

                                            {/* Cuenta Banco de la Nación */}
                                            <div className="flex flex-col gap-1.5">
                                                <Label className="text-xs font-medium text-muted-foreground">
                                                    Cuenta Banco de la Nación <span className="text-destructive">*</span>
                                                </Label>
                                                <Input
                                                    value={detCuenta}
                                                    onChange={(e) => setDetCuenta(e.target.value)}
                                                    placeholder="00-000-000000"
                                                    className={`h-10 rounded-xl ${errors.det_cuenta ? 'border-destructive' : ''}`}
                                                />
                                                {errors.det_cuenta && <p className="text-xs text-destructive">{errors.det_cuenta}</p>}
                                            </div>

                                            {/* Medio de pago Catálogo 59 */}
                                            <div className="flex flex-col gap-1.5">
                                                <Label className="text-xs font-medium text-muted-foreground">
                                                    Medio de pago (Catálogo 59)
                                                </Label>
                                                <Combobox
                                                    value={detMedioPago}
                                                    onChange={(v) => setDetMedioPago(v)}
                                                    searchable
                                                    options={MEDIOS_PAGO_DET.map((m) => ({
                                                        value: String(m.code),
                                                        label: `${m.code} - ${m.label}`,
                                                    }))}
                                                    className="h-10 rounded-xl"
                                                />
                                            </div>

                                            {/* Valor referencial — solo transporte de carga (027) */}
                                            {detCodigo === '027' && (
                                                <div className="flex flex-col gap-1.5 sm:col-span-2 lg:col-span-3">
                                                    <Label className="text-xs font-medium text-muted-foreground">
                                                        Valor referencial de la ruta (transporte de carga) — opcional
                                                    </Label>
                                                    <Input
                                                        type="number" min={0} step="0.01"
                                                        value={detValorRef}
                                                        onChange={(e) => setDetValorRef(e.target.value)}
                                                        placeholder="Déjalo vacío si la ruta no tiene valor referencial"
                                                        className="h-10 rounded-xl"
                                                    />
                                                    <p className="text-[11px] text-muted-foreground">
                                                        Si la ruta tiene valor referencial (tabla SUNAT) y es mayor que el total, la detracción (4%) se calcula sobre ese valor referencial. Si lo dejas vacío, se usa el total de la factura.
                                                    </p>
                                                </div>
                                            )}
                                        </div>

                                        {/* Info box detracción */}
                                        {detCodigo && (
                                            <div className="mt-4 rounded-xl border border-border bg-muted/40 p-4">
                                                <p className="mb-2 text-[11px] font-semibold uppercase tracking-wider text-muted-foreground">
                                                    Datos de detracción calculados
                                                </p>
                                                <div className="grid gap-2 sm:grid-cols-3">
                                                    <div>
                                                        <p className="text-[10px] text-muted-foreground">Bien/Servicio</p>
                                                        <p className="text-xs font-medium">{detData?.codigo} - {detData?.descripcion}</p>
                                                    </div>
                                                    <div>
                                                        <p className="text-[10px] text-muted-foreground">Porcentaje</p>
                                                        <p className="text-xs font-medium">{detPct}%</p>
                                                    </div>
                                                    <div>
                                                        <p className="text-[10px] text-muted-foreground">Monto a detraer</p>
                                                        <p className="text-sm font-bold text-foreground">
                                                            {simbolo} {fmt(totals.detMonto)}
                                                        </p>
                                                    </div>
                                                </div>
                                                {detCodigo === '027' && totals.baseDet > totals.totalComprobante && (
                                                    <p className="mt-2 text-[11px] text-amber-600">
                                                        Base de cálculo: {simbolo} {fmt(totals.baseDet)} (valor referencial, mayor que el total de la factura).
                                                    </p>
                                                )}
                                            </div>
                                        )}
                                    </div>
                                )}
                            </section>
                        )}

                        {/* ── 6. DATOS ADICIONALES ── */}
                        <section className="rounded-2xl bg-card shadow-soft">
                            <div className="border-b border-border/60 px-5 py-3.5">
                                <span className="text-sm font-semibold text-foreground">Datos adicionales</span>
                            </div>
                            <div className="grid gap-4 p-5 sm:grid-cols-2">
                                <div className="flex flex-col gap-1.5">
                                    <Label className="text-xs font-medium text-muted-foreground">Orden de compra referencial</Label>
                                    <Input
                                        value={ordenCompra}
                                        onChange={(e) => setOrdenCompra(e.target.value)}
                                        placeholder="OC-001"
                                        className="h-10 rounded-xl"
                                    />
                                </div>
                                <div className="flex flex-col gap-1.5">
                                    <Label className="text-xs font-medium text-muted-foreground">Observación</Label>
                                    <Input
                                        value={observacion}
                                        onChange={(e) => setObservacion(e.target.value)}
                                        placeholder="Comentario o nota interna"
                                        className="h-10 rounded-xl"
                                    />
                                </div>
                            </div>
                        </section>

                        {/* ── ACCIONES ── */}
                        <div className="flex flex-wrap items-center gap-3 pb-4">
                            {/* Formato del PDF */}
                            <div className="flex items-center gap-2">
                                <span className="text-xs font-medium text-muted-foreground">Formato PDF</span>
                                <div className="flex rounded-xl border border-border p-0.5">
                                    {[{ v: 'a4', l: 'A4' }, { v: 'a5', l: 'A5' }, { v: 'ticket-80', l: '80mm' }, { v: 'ticket-58', l: '58mm' }].map(({ v, l }) => (
                                        <button
                                            key={v}
                                            type="button"
                                            onClick={() => setPdfFormat(v)}
                                            className={`rounded-lg px-2.5 py-1 text-xs font-medium transition-colors ${pdfFormat === v ? 'bg-primary text-primary-foreground' : 'text-muted-foreground hover:bg-secondary'}`}
                                        >
                                            {l}
                                        </button>
                                    ))}
                                </div>
                            </div>

                            <div className="flex-1" />

                            <Button
                                type="button" onClick={() => submit(true)} disabled={submitting}
                                className="gap-2 rounded-xl px-5"
                            >
                                {submitting && <Loader2 className="size-4 animate-spin" />}
                                Emitir y enviar a SUNAT
                            </Button>
                            <Button
                                type="button" variant="outline" onClick={() => submit(false)} disabled={submitting}
                                className="gap-2 rounded-xl"
                            >
                                {tipoDoc === '03'
                                    ? <><Calendar className="size-4" /> Registrar para Resumen Diario</>
                                    : 'Guardar borrador'}
                            </Button>
                            <Button
                                type="button" variant="ghost" onClick={() => router.visit('/sunat')} disabled={submitting}
                                className="rounded-xl"
                            >
                                Cancelar
                            </Button>

                            {tipoDoc === '03' && (
                                <p className="w-full text-xs leading-relaxed text-muted-foreground">
                                    <span className="font-medium text-foreground">Emitir y enviar a SUNAT</span>: envía la boleta individualmente ahora mismo.{' '}
                                    <span className="font-medium text-foreground">Registrar para Resumen Diario</span>: la boleta queda <span className="font-medium">pendiente</span> y se enviará a SUNAT agrupada en el Resumen Diario (Trámites → Resumen diario).
                                </p>
                            )}
                        </div>
                    </div>

                </div>

                {/* Modal: Vista previa — representación impresa estilo SUNAT */}
                {previewOpen && (
                    <div className="fixed inset-0 z-[100] overflow-y-auto bg-black/50" onClick={() => setPreviewOpen(false)}>
                      <div className="flex min-h-full items-start justify-center p-4">
                        <div className="w-full max-w-2xl rounded-lg bg-white text-neutral-900 shadow-soft" onClick={(e) => e.stopPropagation()}>
                            {/* Barra del modal */}
                            <div className="flex items-center justify-between border-b border-neutral-200 px-4 py-3">
                                <span className="text-sm font-semibold text-neutral-800">Vista previa</span>
                                <button type="button" onClick={() => setPreviewOpen(false)} className="rounded p-1 text-neutral-500 transition-colors hover:bg-neutral-100">
                                    <X className="size-4" />
                                </button>
                            </div>

                            {/* Hoja del comprobante */}
                            <div className="p-6 text-[11px] leading-relaxed">
                                {/* Encabezado: emisor + recuadro RUC/documento */}
                                <div className="flex items-start justify-between gap-4 border-b border-neutral-300 pb-4">
                                    <div className="min-w-0">
                                        <p className="text-sm font-bold uppercase leading-tight">{tenant.razon_social}</p>
                                        {tenant.direccion && <p className="mt-0.5 text-neutral-600">{tenant.direccion}</p>}
                                    </div>
                                    <div className="shrink-0 rounded border-2 border-neutral-800 px-5 py-2 text-center">
                                        <p className="text-xs font-bold">R.U.C. {tenant.ruc}</p>
                                        <p className="my-1 text-[11px] font-bold uppercase">
                                            {tipoDoc === '01' ? 'FACTURA ELECTRÓNICA' : 'BOLETA DE VENTA ELECTRÓNICA'}
                                        </p>
                                        <p className="text-sm font-bold">{serie}-00000001</p>
                                    </div>
                                </div>

                                {/* Datos de emisión y adquirente */}
                                <div className="grid grid-cols-1 gap-x-6 gap-y-1 border-b border-neutral-300 py-3 sm:grid-cols-2">
                                    <p><span className="font-semibold">Fecha de emisión:</span> {fmtDate(fecha)}</p>
                                    <p><span className="font-semibold">Moneda:</span> {moneda === 'PEN' ? 'SOLES' : 'DÓLARES AMERICANOS'}</p>
                                    <p className="sm:col-span-2"><span className="font-semibold">Señor(es):</span> {cliente?.razon_social || '-'}</p>
                                    <p><span className="font-semibold">{cliente?.tipo_doc === '6' ? 'RUC:' : 'Doc.:'}</span> {cliente?.num_doc || '-'}</p>
                                    {cliente?.direccion && <p className="sm:col-span-2"><span className="font-semibold">Dirección:</span> {cliente.direccion}</p>}
                                    <p><span className="font-semibold">Forma de pago:</span> {formaPago === 'Credito' ? `Crédito (${cuotas.length} cuota${cuotas.length !== 1 ? 's' : ''})` : 'Contado'}</p>
                                </div>

                                {formaPago === 'Credito' && cuotas.length > 0 && (
                                    <div className="border-b border-neutral-300 py-2 text-[10px]">
                                        <p className="mb-1 font-semibold uppercase">Cuotas</p>
                                        {cuotas.map((c, i) => (
                                            <div key={i} className="flex justify-between">
                                                <span>Cuota {i + 1} · {fmtDate(c.fecha)}</span>
                                                <span className="tabular-nums">{simbolo} {fmt(c.monto)}</span>
                                            </div>
                                        ))}
                                    </div>
                                )}

                                {/* Tabla de ítems */}
                                <div className="overflow-x-auto">
                                    <table className="w-full border-collapse text-[10px]">
                                        <thead>
                                            <tr className="border-b-2 border-neutral-400 text-left">
                                                <th className="py-1.5 pr-2 font-semibold">Cant.</th>
                                                <th className="py-1.5 pr-2 font-semibold">U.M.</th>
                                                <th className="py-1.5 pr-2 font-semibold">Descripción</th>
                                                <th className="py-1.5 pr-2 text-right font-semibold">P. Unit.</th>
                                                <th className="py-1.5 text-right font-semibold">Importe</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            {items.filter((it) => it.descripcion).length === 0 ? (
                                                <tr><td colSpan={5} className="py-4 text-center text-neutral-400">Aún no has agregado ítems</td></tr>
                                            ) : items.filter((it) => it.descripcion).map((it, i) => {
                                                const { total } = calcItem(it);
                                                return (
                                                    <tr key={i} className="border-b border-neutral-200 align-top">
                                                        <td className="py-1.5 pr-2 tabular-nums">{fmt(it.cantidad, 0)}</td>
                                                        <td className="py-1.5 pr-2">{it.unidad}</td>
                                                        <td className="py-1.5 pr-2 uppercase">{it.descripcion}</td>
                                                        <td className="py-1.5 pr-2 text-right tabular-nums">{fmt(it.precio_unitario)}</td>
                                                        <td className="py-1.5 text-right tabular-nums">{fmt(total)}</td>
                                                    </tr>
                                                );
                                            })}
                                        </tbody>
                                    </table>
                                </div>

                                {/* Totales */}
                                <div className="mt-3 flex justify-end">
                                    <div className="w-full max-w-[16rem] space-y-1">
                                        {totals.gravadas > 0 && <div className="flex justify-between"><span>Op. Gravada</span><span className="tabular-nums">{simbolo} {fmt(totals.gravadas)}</span></div>}
                                        {totals.exoneradas > 0 && <div className="flex justify-between"><span>Op. Exonerada</span><span className="tabular-nums">{simbolo} {fmt(totals.exoneradas)}</span></div>}
                                        {totals.inafectas > 0 && <div className="flex justify-between"><span>Op. Inafecta</span><span className="tabular-nums">{simbolo} {fmt(totals.inafectas)}</span></div>}
                                        {totals.exportacion > 0 && <div className="flex justify-between"><span>Op. Exportación</span><span className="tabular-nums">{simbolo} {fmt(totals.exportacion)}</span></div>}
                                        {totals.igvTotal > 0 && <div className="flex justify-between"><span>I.G.V. (18%)</span><span className="tabular-nums">{simbolo} {fmt(totals.igvTotal)}</span></div>}
                                        <div className="flex justify-between border-t-2 border-neutral-400 pt-1 text-sm font-bold">
                                            <span>IMPORTE TOTAL</span>
                                            <span className="tabular-nums">{simbolo} {fmt(totals.totalComprobante)}</span>
                                        </div>
                                    </div>
                                </div>

                                {/* Detracción (si aplica) */}
                                {detEnabled && detCodigo && totals.detMonto > 0 && (
                                    <div className="mt-4 border-t border-neutral-300 pt-3 text-[10px]">
                                        <p className="font-semibold uppercase">Operación sujeta al SPOT (detracción)</p>
                                        <p>Bien/Servicio: {detData?.codigo} - {detData?.descripcion}</p>
                                        <p>Porcentaje: {detPct}% &nbsp;·&nbsp; Monto a detraer: {simbolo} {fmt(totals.detMonto)}</p>
                                        {detCuenta && <p>Cuenta Banco de la Nación: {detCuenta}</p>}
                                        <p className="font-semibold">Monto neto pendiente de pago: {simbolo} {fmt(totals.netoPagar)}</p>
                                    </div>
                                )}

                                {/* Pie: QR + leyenda */}
                                <div className="mt-5 flex items-center gap-4 border-t border-neutral-300 pt-4">
                                    <div className="flex size-16 shrink-0 items-center justify-center border border-neutral-400 text-[8px] text-neutral-500">QR</div>
                                    <div className="text-[9px] leading-relaxed text-neutral-600">
                                        <p>Representación impresa de la {tipoDoc === '01' ? 'FACTURA' : 'BOLETA DE VENTA'} ELECTRÓNICA.</p>
                                        <p>Consulte su comprobante en www.sunat.gob.pe</p>
                                        <p>Emitido mediante Sistema de Emisión Electrónica.</p>
                                    </div>
                                </div>
                            </div>

                            {/* Acciones del modal */}
                            <div className="flex justify-end border-t border-neutral-200 px-4 py-3">
                                <button type="button" onClick={() => setPreviewOpen(false)} className="rounded-lg border border-neutral-300 px-4 py-2 text-sm font-medium text-neutral-700 transition-colors hover:bg-neutral-100">
                                    Cerrar
                                </button>
                            </div>
                        </div>
                      </div>
                    </div>
                )}
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
