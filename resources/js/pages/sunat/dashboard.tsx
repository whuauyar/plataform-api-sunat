import { Head, Link } from '@inertiajs/react';
import { useMemo, useState } from 'react';
import {
    Area, AreaChart, Bar, BarChart, CartesianGrid, Cell, Legend, Pie, PieChart,
    ResponsiveContainer, Tooltip, XAxis, YAxis,
} from 'recharts';
import { CheckCircle2, FileText, Plus, Users, Wallet, ArrowRight, Receipt } from '@/lib/icons';
import { type ColumnDef } from '@tanstack/react-table';
import { StatusBadge } from '@/components/sunat/status-badge';
import { Button } from '@/components/ui/button';
import { DataTable } from '@/components/ui/data-table';
import SunatLayout from '@/layouts/sunat-layout';
import type { SunatStatus } from '@/types';
import {
    ChartCard, EmptyState, ESTADO_COLOR, KpiCard, RangeToggle, TOOLTIP_STYLE,
    fmtFecha, fmtSoles, usePalette,
} from '@/components/dashboard/toolkit';

type Metricas = {
    empresa: { razon_social: string; ruc: string; entorno: string };
    kpis: {
        docs_hoy: number; docs_mes: number; ventas_mes: number; aceptados_mes: number;
        pendientes_mes: number; rechazados_mes: number; clientes_total: number;
        crecimiento_docs: number | null; crecimiento_ventas: number | null;
    };
    documentos_por_dia: { fecha: string; facturas: number; boletas: number; notas: number }[];
    documentos_por_tipo: { tipo: string; valor: number }[];
    estado_sunat: { estado: string; valor: number }[];
    ventas_por_mes: { mes: string; ventas: number }[];
    ultimos: { tipo: string; numero: string; cliente: string; total: number; moneda: string; estado: string; fecha: string }[];
    periodo: { inicio_mes: string; hoy: string };
};
type Props = { metricas: Metricas };

function saludo(): string {
    const h = new Date().getHours();
    if (h < 12) return 'Buenos días';
    if (h < 19) return 'Buenas tardes';
    return 'Buenas noches';
}

const RANGOS = [{ value: '7', label: '7d' }, { value: '14', label: '14d' }, { value: '30', label: '30d' }] as const;
type Rango = (typeof RANGOS)[number]['value'];

export default function SunatDashboard({ metricas }: Props) {
    const chart = usePalette();
    const [rango, setRango] = useState<Rango>('30');
    const { empresa, kpis, documentos_por_tipo, estado_sunat, ventas_por_mes, ultimos } = metricas;

    const serieDia = useMemo(() => metricas.documentos_por_dia.slice(-Number(rango)), [metricas.documentos_por_dia, rango]);
    const totalDocsMes = documentos_por_tipo.reduce((s, d) => s + d.valor, 0);
    const tiposConValor = documentos_por_tipo.filter((d) => d.valor > 0);
    const estadoConValor = estado_sunat.filter((d) => d.valor > 0);
    const hayVentas = ventas_por_mes.some((m) => m.ventas > 0);

    // Últimos comprobantes: mismo DataTable del proyecto (responsive, sin scroll).
    const columnsUltimos: ColumnDef<Metricas['ultimos'][number]>[] = [
        {
            id: 'comprobante',
            header: 'Comprobante',
            meta: { label: 'Comprobante', primary: true },
            cell: ({ row }) => (
                <div>
                    <span className="font-mono text-xs font-medium">{row.original.numero}</span>
                    <div className="text-[11px] uppercase text-muted-foreground">{row.original.tipo}</div>
                </div>
            ),
        },
        {
            accessorKey: 'cliente',
            header: 'Cliente',
            meta: { label: 'Cliente' },
            cell: ({ row }) => <span className="text-sm">{row.original.cliente}</span>,
        },
        {
            accessorKey: 'total',
            header: 'Total',
            meta: { label: 'Total', alignRight: true },
            cell: ({ row }) => (
                <span className="font-semibold tabular-nums">
                    {row.original.moneda === 'USD' ? '$' : 'S/'} {new Intl.NumberFormat('es-PE', { minimumFractionDigits: 2 }).format(row.original.total)}
                </span>
            ),
        },
        {
            accessorKey: 'estado',
            header: 'Estado',
            meta: { label: 'Estado' },
            cell: ({ row }) => <StatusBadge status={row.original.estado as SunatStatus} />,
        },
        {
            accessorKey: 'fecha',
            header: 'Fecha',
            meta: { label: 'Fecha', alignRight: true },
            cell: ({ row }) => <span className="whitespace-nowrap text-xs text-muted-foreground">{row.original.fecha}</span>,
        },
    ];

    return (
        <SunatLayout>
            <Head title="Panel" />

            <div className="mx-auto flex w-full max-w-6xl flex-col gap-5 sm:gap-6">
                {/* HEADER / BIENVENIDA */}
                <div className="flex flex-wrap items-end justify-between gap-3">
                    <div className="min-w-0">
                        <h1 className="truncate text-xl font-bold tracking-tight sm:text-2xl">{empresa.razon_social}</h1>
                        <p className="truncate text-sm text-muted-foreground">{saludo()} · RUC {empresa.ruc} · {empresa.entorno}</p>
                    </div>
                    <div className="flex gap-2">
                        <Button asChild variant="outline" size="sm"><Link href="/sunat/historial">Ver historial</Link></Button>
                        <Button asChild size="sm" className="gap-1.5"><Link href="/sunat/facturas/nueva"><Plus className="size-4" /> Nueva factura</Link></Button>
                    </div>
                </div>

                {/* KPIs */}
                <div className="grid grid-cols-1 gap-3 sm:grid-cols-2 sm:gap-4 lg:grid-cols-4">
                    <KpiCard index={0} label="Documentos del mes" valueNum={kpis.docs_mes} subvalue={`${kpis.docs_hoy} hoy`} growth={kpis.crecimiento_docs} icon={FileText} />
                    <KpiCard index={1} label="Ventas del mes" valueNum={kpis.ventas_mes} format={fmtSoles} growth={kpis.crecimiento_ventas} icon={Wallet} />
                    <KpiCard index={2} label="Aceptados este mes" valueNum={kpis.aceptados_mes} subvalue={`${kpis.rechazados_mes} rechazados`} icon={CheckCircle2} />
                    <KpiCard index={3} label="Clientes" valueNum={kpis.clientes_total} subvalue={`${kpis.pendientes_mes} en proceso`} icon={Users} />
                </div>

                {/* EMISIÓN DIARIA + DISTRIBUCIÓN */}
                <div className="grid gap-4 lg:grid-cols-3">
                    <div className="min-w-0 lg:col-span-2">
                        <ChartCard title="Emisión diaria" subtitle="Facturas · boletas · notas" right={<RangeToggle value={rango} onChange={setRango} options={RANGOS as unknown as { value: Rango; label: string }[]} />}>
                            <ResponsiveContainer width="100%" height={280}>
                                <AreaChart data={serieDia} margin={{ left: -18, right: 6, top: 6 }}>
                                    <defs>
                                        {['facturas', 'boletas', 'notas'].map((k, i) => (
                                            <linearGradient key={k} id={`g-cli-${k}`} x1="0" y1="0" x2="0" y2="1">
                                                <stop offset="5%" stopColor={chart[i]} stopOpacity={0.35} />
                                                <stop offset="95%" stopColor={chart[i]} stopOpacity={0.02} />
                                            </linearGradient>
                                        ))}
                                    </defs>
                                    <CartesianGrid strokeDasharray="3 3" stroke="var(--border)" vertical={false} />
                                    <XAxis dataKey="fecha" tickFormatter={fmtFecha} stroke="var(--muted-foreground)" fontSize={11} tickLine={false} axisLine={false} minTickGap={20} />
                                    <YAxis stroke="var(--muted-foreground)" fontSize={11} allowDecimals={false} width={34} tickLine={false} axisLine={false} />
                                    <Tooltip contentStyle={TOOLTIP_STYLE} labelFormatter={(v) => fmtFecha(String(v))} />
                                    <Legend wrapperStyle={{ fontSize: 12 }} iconType="circle" />
                                    <Area type="monotone" dataKey="facturas" stroke={chart[0]} strokeWidth={2} fill="url(#g-cli-facturas)" name="Facturas" />
                                    <Area type="monotone" dataKey="boletas" stroke={chart[1]} strokeWidth={2} fill="url(#g-cli-boletas)" name="Boletas" />
                                    <Area type="monotone" dataKey="notas" stroke={chart[2]} strokeWidth={2} fill="url(#g-cli-notas)" name="NC / ND" />
                                </AreaChart>
                            </ResponsiveContainer>
                        </ChartCard>
                    </div>

                    <ChartCard title="Distribución por tipo" subtitle={`${totalDocsMes} documentos este mes`}>
                        {tiposConValor.length === 0 ? <EmptyState message="Sin documentos este mes." height={280} /> : (
                            <ResponsiveContainer width="100%" height={280}>
                                <PieChart>
                                    <Pie data={tiposConValor} dataKey="valor" nameKey="tipo" cx="50%" cy="50%" outerRadius={95} innerRadius={58} paddingAngle={2} stroke="var(--card)" strokeWidth={2}>
                                        {tiposConValor.map((_, i) => <Cell key={i} fill={chart[i % chart.length]} />)}
                                    </Pie>
                                    <Tooltip contentStyle={TOOLTIP_STYLE} />
                                    <Legend wrapperStyle={{ fontSize: 11 }} iconType="circle" />
                                </PieChart>
                            </ResponsiveContainer>
                        )}
                    </ChartCard>
                </div>

                {/* VENTAS POR MES + ESTADO */}
                <div className="grid gap-4 lg:grid-cols-3">
                    <div className="min-w-0 lg:col-span-2">
                        <ChartCard title="Ventas por mes" subtitle="Últimos 6 meses · comprobantes aceptados">
                            {!hayVentas ? <EmptyState message="Aún no hay ventas aceptadas." height={220} /> : (
                                <ResponsiveContainer width="100%" height={220}>
                                    <BarChart data={ventas_por_mes} margin={{ left: 4, right: 6 }}>
                                        <defs>
                                            <linearGradient id="g-cli-ventas" x1="0" y1="0" x2="0" y2="1">
                                                <stop offset="0%" stopColor={chart[2]} stopOpacity={0.95} />
                                                <stop offset="100%" stopColor={chart[2]} stopOpacity={0.55} />
                                            </linearGradient>
                                        </defs>
                                        <CartesianGrid strokeDasharray="3 3" stroke="var(--border)" vertical={false} />
                                        <XAxis dataKey="mes" stroke="var(--muted-foreground)" fontSize={11} tickLine={false} axisLine={false} />
                                        <YAxis stroke="var(--muted-foreground)" fontSize={11} width={64} tickLine={false} axisLine={false} tickFormatter={(v) => fmtSoles(Number(v))} />
                                        <Tooltip contentStyle={TOOLTIP_STYLE} formatter={(v) => fmtSoles(Number(v))} cursor={{ fill: 'var(--muted)', opacity: 0.4 }} />
                                        <Bar dataKey="ventas" radius={[8, 8, 0, 0]} fill="url(#g-cli-ventas)" name="Ventas" maxBarSize={54} />
                                    </BarChart>
                                </ResponsiveContainer>
                            )}
                        </ChartCard>
                    </div>

                    <ChartCard title="Estado SUNAT" subtitle="Comprobantes de este mes">
                        {estadoConValor.length === 0 ? <EmptyState message="Sin datos este mes." height={220} /> : (
                            <ResponsiveContainer width="100%" height={220}>
                                <PieChart>
                                    <Pie data={estadoConValor} dataKey="valor" nameKey="estado" cx="50%" cy="50%" outerRadius={78} innerRadius={48} stroke="var(--card)" strokeWidth={2}>
                                        {estadoConValor.map((d, i) => <Cell key={i} fill={ESTADO_COLOR[d.estado] ?? chart[i % chart.length]} />)}
                                    </Pie>
                                    <Tooltip contentStyle={TOOLTIP_STYLE} />
                                    <Legend wrapperStyle={{ fontSize: 11 }} iconType="circle" />
                                </PieChart>
                            </ResponsiveContainer>
                        )}
                    </ChartCard>
                </div>

                {/* ACTIVIDAD RECIENTE (últimos comprobantes) — DataTable responsive del proyecto */}
                <section>
                    <div className="mb-3 flex items-center justify-between">
                        <div className="flex items-center gap-2"><Receipt className="size-4 text-primary" /><h3 className="text-sm font-semibold">Últimos comprobantes</h3></div>
                        <Link href="/sunat/historial" className="inline-flex items-center gap-1 text-xs font-medium text-primary hover:underline">Ver todos <ArrowRight className="size-3" /></Link>
                    </div>
                    <DataTable
                        columns={columnsUltimos}
                        data={ultimos}
                        searchable={false}
                        emptyMessage="Aún no has emitido comprobantes."
                    />
                </section>
            </div>
        </SunatLayout>
    );
}
