import { Head, Link } from '@inertiajs/react';
import { useMemo, useState } from 'react';
import {
    Area, AreaChart, Bar, BarChart, CartesianGrid, Cell, Legend, Pie, PieChart,
    ResponsiveContainer, Tooltip, XAxis, YAxis,
} from 'recharts';
import { type ColumnDef } from '@tanstack/react-table';
import { Building2, CheckCircle2, FileText, Wallet, ArrowRight, Activity } from '@/lib/icons';
import { DataTable } from '@/components/ui/data-table';
import AppLayout from '@/layouts/app-layout';
import { dashboard } from '@/routes';
import type { BreadcrumbItem } from '@/types';
import {
    ChartCard, EmptyState, ESTADO_COLOR, KpiCard, RangeToggle, TOOLTIP_STYLE,
    fmtFecha, fmtSoles, usePalette,
} from '@/components/dashboard/toolkit';

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Inicio', href: dashboard() }];

type Kpis = {
    empresas_total: number; empresas_activas: number; docs_hoy: number; docs_mes: number;
    docs_mes_anterior: number; ventas_mes: number; ventas_mes_anterior: number;
    crecimiento_docs: number | null; crecimiento_ventas: number | null;
};
type Metricas = {
    kpis: Kpis;
    documentos_por_dia: { fecha: string; facturas: number; boletas: number; notas: number }[];
    documentos_por_tipo: { tipo: string; valor: number }[];
    empresas_por_plan: { plan: string; total: number }[];
    empresas_por_regimen: { regimen: string; total: number }[];
    estado_sunat: { estado: string; valor: number }[];
    top_empresas: { ruc: string; razon_social: string; total_ventas: number; total_docs: number }[];
    empresas_por_entorno: { entorno: string; total: number }[];
    ultimos_envios: { tipo: string; id: number; tenant_id: number; serie: string; correlativo: string | number; estado: string; codigo: string | null; descripcion: string | null; fecha_emision: string | null; sent_at: string | null; razon_social: string; ruc: string }[];
    periodo: { inicio_mes: string; hoy: string };
};
type Props = { esAdmin: boolean; metricas: Metricas | null };

function saludo(): string {
    const h = new Date().getHours();
    if (h < 12) return 'Buenos días';
    if (h < 19) return 'Buenas tardes';
    return 'Buenas noches';
}

function fechaLarga(): string {
    return new Date().toLocaleDateString('es-PE', { weekday: 'long', day: 'numeric', month: 'long' });
}

/** Pastilla de estado (string libre) para la actividad del admin. */
function EstadoPill({ estado }: { estado: string }) {
    const e = estado.toLowerCase();
    const map: Record<string, string> = {
        aceptado: 'bg-green-500/15 text-green-600 dark:text-green-400',
        rechazado: 'bg-red-500/12 text-red-600 dark:text-red-400',
        enviado: 'bg-blue-500/12 text-blue-600 dark:text-blue-400',
        pendiente: 'bg-amber-500/12 text-amber-600 dark:text-amber-400',
        anulado: 'bg-slate-500/12 text-slate-500',
    };
    return <span className={`inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium capitalize ${map[e] ?? 'bg-muted text-muted-foreground'}`}>{estado}</span>;
}

const RANGOS = [{ value: '7', label: '7d' }, { value: '14', label: '14d' }, { value: '30', label: '30d' }] as const;
type Rango = (typeof RANGOS)[number]['value'];

export default function Dashboard({ esAdmin, metricas }: Props) {
    const chart = usePalette();
    const [rango, setRango] = useState<Rango>('30');

    const serieDia = useMemo(
        () => (metricas ? metricas.documentos_por_dia.slice(-Number(rango)) : []),
        [metricas, rango],
    );

    if (!esAdmin || !metricas) {
        return (
            <AppLayout breadcrumbs={breadcrumbs}>
                <Head title="Inicio" />
                <div className="flex flex-1 flex-col items-center justify-center gap-3 p-10 text-center">
                    <Activity className="size-10 text-primary" />
                    <h1 className="text-lg font-semibold">Bienvenido</h1>
                    <p className="max-w-sm text-sm text-muted-foreground">Tu cuenta no tiene métricas globales asignadas. Usa el menú lateral para navegar por el sistema.</p>
                </div>
            </AppLayout>
        );
    }

    const { kpis, documentos_por_tipo, empresas_por_plan, empresas_por_regimen, estado_sunat, top_empresas, empresas_por_entorno, ultimos_envios } = metricas;
    const totalDocsMes = documentos_por_tipo.reduce((s, d) => s + d.valor, 0);
    const tiposConValor = documentos_por_tipo.filter((d) => d.valor > 0);
    const estadoConValor = estado_sunat.filter((d) => d.valor > 0);

    // Logs de envío: mismas columnas que el DataTable del proyecto (responsive, sin scroll).
    const columnsEnvios: ColumnDef<Metricas['ultimos_envios'][number]>[] = [
        {
            accessorKey: 'razon_social',
            header: 'Empresa',
            meta: { label: 'Empresa', primary: true },
            cell: ({ row }) => (
                <div>
                    <span className="font-medium">{row.original.razon_social}</span>
                    <div className="font-mono text-[11px] text-muted-foreground">{row.original.ruc}</div>
                </div>
            ),
        },
        {
            id: 'comprobante',
            header: 'Comprobante',
            meta: { label: 'Comprobante' },
            cell: ({ row }) => (
                <span className="font-mono text-xs">
                    {row.original.tipo} {row.original.serie}-{String(row.original.correlativo).padStart(8, '0')}
                </span>
            ),
        },
        {
            accessorKey: 'estado',
            header: 'Estado',
            meta: { label: 'Estado' },
            cell: ({ row }) => <EstadoPill estado={row.original.estado} />,
        },
        {
            id: 'respuesta',
            header: 'Respuesta',
            meta: { label: 'Respuesta' },
            cell: ({ row }) => (
                <span className="text-xs text-muted-foreground">
                    {row.original.codigo ? `${row.original.codigo} · ` : ''}
                    {row.original.descripcion ?? 'Sin respuesta todavía'}
                </span>
            ),
        },
        {
            id: 'fecha',
            header: 'Fecha',
            meta: { label: 'Fecha', alignRight: true },
            cell: ({ row }) => (
                <span className="whitespace-nowrap text-xs text-muted-foreground">
                    {row.original.sent_at ?? row.original.fecha_emision ?? '-'}
                </span>
            ),
        },
    ];
    const totalEntorno = empresas_por_entorno.reduce((s, d) => s + d.total, 0);

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Inicio" />

            <div className="flex flex-1 flex-col gap-5 p-4 sm:gap-6 sm:p-5">
                {/* HEADER */}
                <div className="flex flex-wrap items-end justify-between gap-3">
                    <div>
                        <h1 className="text-xl font-bold tracking-tight sm:text-2xl">Panel administrativo</h1>
                        <p className="text-sm text-muted-foreground">{saludo()} · estadísticas globales del mes actual</p>
                    </div>
                    <span className="text-sm font-medium capitalize text-muted-foreground">{fechaLarga()}</span>
                </div>

                {/* KPIs */}
                <div className="grid grid-cols-1 gap-3 sm:grid-cols-2 sm:gap-4 lg:grid-cols-4">
                    <KpiCard index={0} label="Empresas" valueNum={kpis.empresas_total} subvalue={`${kpis.empresas_activas} activas`} icon={Building2} />
                    <KpiCard index={1} label="Documentos hoy" valueNum={kpis.docs_hoy} subvalue={`${kpis.docs_mes} este mes`} icon={FileText} />
                    <KpiCard index={2} label="Ventas del mes" valueNum={kpis.ventas_mes} format={fmtSoles} growth={kpis.crecimiento_ventas} icon={Wallet} />
                    <KpiCard index={3} label="Documentos del mes" valueNum={kpis.docs_mes} growth={kpis.crecimiento_docs} icon={CheckCircle2} />
                </div>

                {/* GRÁFICO PRINCIPAL + DISTRIBUCIÓN */}
                <div className="grid gap-4 lg:grid-cols-3">
                    <div className="min-w-0 lg:col-span-2">
                        <ChartCard
                            title="Emisión diaria"
                            subtitle="Facturas · boletas · notas"
                            right={<RangeToggle value={rango} onChange={setRango} options={RANGOS as unknown as { value: Rango; label: string }[]} />}
                        >
                            <ResponsiveContainer width="100%" height={280}>
                                <AreaChart data={serieDia} margin={{ left: -18, right: 6, top: 6 }}>
                                    <defs>
                                        {['facturas', 'boletas', 'notas'].map((k, i) => (
                                            <linearGradient key={k} id={`g-adm-${k}`} x1="0" y1="0" x2="0" y2="1">
                                                <stop offset="5%" stopColor={chart[i === 2 ? 2 : i]} stopOpacity={0.35} />
                                                <stop offset="95%" stopColor={chart[i === 2 ? 2 : i]} stopOpacity={0.02} />
                                            </linearGradient>
                                        ))}
                                    </defs>
                                    <CartesianGrid strokeDasharray="3 3" stroke="var(--border)" vertical={false} />
                                    <XAxis dataKey="fecha" tickFormatter={fmtFecha} stroke="var(--muted-foreground)" fontSize={11} tickLine={false} axisLine={false} minTickGap={20} />
                                    <YAxis stroke="var(--muted-foreground)" fontSize={11} allowDecimals={false} width={34} tickLine={false} axisLine={false} />
                                    <Tooltip contentStyle={TOOLTIP_STYLE} labelFormatter={(v) => fmtFecha(String(v))} />
                                    <Legend wrapperStyle={{ fontSize: 12 }} iconType="circle" />
                                    <Area type="monotone" dataKey="facturas" stroke={chart[0]} strokeWidth={2} fill="url(#g-adm-facturas)" name="Facturas" />
                                    <Area type="monotone" dataKey="boletas" stroke={chart[1]} strokeWidth={2} fill="url(#g-adm-boletas)" name="Boletas" />
                                    <Area type="monotone" dataKey="notas" stroke={chart[2]} strokeWidth={2} fill="url(#g-adm-notas)" name="NC / ND" />
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

                {/* SEGUNDO BLOQUE */}
                <div className="grid gap-4 md:grid-cols-3">
                    <ChartCard title="Empresas por plan" subtitle="Distribución actual">
                        {empresas_por_plan.length === 0 ? <EmptyState message="Sin datos." height={200} /> : (
                            <ResponsiveContainer width="100%" height={200}>
                                <BarChart data={empresas_por_plan} margin={{ left: -22, right: 6 }}>
                                    <CartesianGrid strokeDasharray="3 3" stroke="var(--border)" vertical={false} />
                                    <XAxis dataKey="plan" stroke="var(--muted-foreground)" fontSize={11} tickLine={false} axisLine={false} />
                                    <YAxis stroke="var(--muted-foreground)" fontSize={11} allowDecimals={false} width={30} tickLine={false} axisLine={false} />
                                    <Tooltip contentStyle={TOOLTIP_STYLE} cursor={{ fill: 'var(--muted)', opacity: 0.4 }} />
                                    <Bar dataKey="total" radius={[8, 8, 0, 0]} maxBarSize={48}>
                                        {empresas_por_plan.map((_, i) => <Cell key={i} fill={chart[i % chart.length]} />)}
                                    </Bar>
                                </BarChart>
                            </ResponsiveContainer>
                        )}
                    </ChartCard>

                    <ChartCard title="Régimen tributario" subtitle="Empresas por régimen">
                        {empresas_por_regimen.length === 0 ? <EmptyState message="Sin datos." height={200} /> : (
                            <ResponsiveContainer width="100%" height={200}>
                                <PieChart>
                                    <Pie data={empresas_por_regimen} dataKey="total" nameKey="regimen" cx="50%" cy="50%" outerRadius={72} innerRadius={46} stroke="var(--card)" strokeWidth={2}>
                                        {empresas_por_regimen.map((_, i) => <Cell key={i} fill={chart[(i + 2) % chart.length]} />)}
                                    </Pie>
                                    <Tooltip contentStyle={TOOLTIP_STYLE} />
                                    <Legend wrapperStyle={{ fontSize: 10 }} iconType="circle" />
                                </PieChart>
                            </ResponsiveContainer>
                        )}
                    </ChartCard>

                    <ChartCard title="Estado SUNAT" subtitle="Documentos del mes">
                        {estadoConValor.length === 0 ? <EmptyState message="Sin datos." height={200} /> : (
                            <ResponsiveContainer width="100%" height={200}>
                                <PieChart>
                                    <Pie data={estadoConValor} dataKey="valor" nameKey="estado" cx="50%" cy="50%" outerRadius={72} innerRadius={46} stroke="var(--card)" strokeWidth={2}>
                                        {estadoConValor.map((d, i) => <Cell key={i} fill={ESTADO_COLOR[d.estado] ?? chart[i % chart.length]} />)}
                                    </Pie>
                                    <Tooltip contentStyle={TOOLTIP_STYLE} />
                                    <Legend wrapperStyle={{ fontSize: 10 }} iconType="circle" />
                                </PieChart>
                            </ResponsiveContainer>
                        )}
                    </ChartCard>
                </div>

                {/* TOP EMPRESAS + ENTORNO */}
                <div className="grid gap-4 lg:grid-cols-3">
                    <div className="min-w-0 lg:col-span-2">
                        <ChartCard title="Top 5 empresas del mes" subtitle="Por ventas SUNAT aceptadas">
                            <div className="-mx-1 overflow-x-auto">
                                <table className="w-full min-w-[440px] text-sm">
                                    <thead>
                                        <tr className="border-b border-border text-xs uppercase text-muted-foreground">
                                            <th className="px-1 pb-2 text-left font-medium">N°</th>
                                            <th className="px-1 pb-2 text-left font-medium">Empresa</th>
                                            <th className="px-1 pb-2 text-right font-medium">Docs</th>
                                            <th className="px-1 pb-2 text-right font-medium">Ventas</th>
                                        </tr>
                                    </thead>
                                    <tbody className="divide-y divide-border">
                                        {top_empresas.length === 0 ? (
                                            <tr><td colSpan={4} className="py-8 text-center text-muted-foreground">Sin datos este mes.</td></tr>
                                        ) : top_empresas.map((emp, i) => (
                                            <tr key={emp.ruc} className="transition-colors hover:bg-muted/40">
                                                <td className="px-1 py-2.5"><span className="flex size-6 items-center justify-center rounded-md text-xs font-bold" style={{ backgroundColor: `${chart[i % chart.length]}1f`, color: chart[i % chart.length] }}>{i + 1}</span></td>
                                                <td className="px-1 py-2.5">
                                                    <Link href={`/admin/empresas?buscar=${emp.ruc}`} className="font-medium hover:underline">{emp.razon_social}</Link>
                                                    <div className="font-mono text-[11px] text-muted-foreground">{emp.ruc}</div>
                                                </td>
                                                <td className="px-1 py-2.5 text-right tabular-nums">{emp.total_docs}</td>
                                                <td className="px-1 py-2.5 text-right font-semibold tabular-nums">{fmtSoles(emp.total_ventas)}</td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                        </ChartCard>
                    </div>

                    <ChartCard title="Entorno SUNAT" subtitle={`${totalEntorno} empresas`}>
                        {empresas_por_entorno.length === 0 ? <EmptyState message="Sin datos." height={200} /> : (
                            <ResponsiveContainer width="100%" height={200}>
                                <PieChart>
                                    <Pie data={empresas_por_entorno} dataKey="total" nameKey="entorno" cx="50%" cy="50%" outerRadius={72} innerRadius={46} stroke="var(--card)" strokeWidth={2}
                                        label={(e) => `${(e as unknown as { total?: number }).total ?? ''}`}>
                                        {empresas_por_entorno.map((_, i) => <Cell key={i} fill={chart[i === 0 ? 1 : 0]} />)}
                                    </Pie>
                                    <Tooltip contentStyle={TOOLTIP_STYLE} />
                                    <Legend wrapperStyle={{ fontSize: 11 }} iconType="circle" />
                                </PieChart>
                            </ResponsiveContainer>
                        )}
                    </ChartCard>
                </div>

                {/* ACTIVIDAD RECIENTE (logs de envío) — DataTable responsive del proyecto */}
                <section>
                    <div className="mb-3 flex items-center justify-between">
                        <div className="flex items-center gap-2"><Activity className="size-4 text-primary" /><h3 className="text-sm font-semibold">Últimos envíos a SUNAT</h3></div>
                        <Link href="/admin/logs-envios" className="inline-flex items-center gap-1 text-xs font-medium text-primary hover:underline">Ver todo <ArrowRight className="size-3" /></Link>
                    </div>
                    <DataTable
                        columns={columnsEnvios}
                        data={ultimos_envios}
                        searchable={false}
                        emptyMessage="Aún no hay envíos registrados."
                    />
                </section>
            </div>
        </AppLayout>
    );
}
