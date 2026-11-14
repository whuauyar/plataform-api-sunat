import { Head } from '@inertiajs/react';
import { useState } from 'react';
import {
    Bell, ChevronRight, LayoutDashboard, FileText, Users, Settings, Building2,
    Receipt, Truck, BarChart3, Search, Plus, Pencil, Trash2, X, Menu,
    CheckCircle2, AlertTriangle, XCircle, Info, ChevronDown, LogOut,
} from '@/lib/icons';

/**
 * /styleguide — Sistema visual institucional del proyecto.
 * Estética tipo portal empresarial (SUNAT) pintada con la identidad de
 * jorge-claw/webchat: azul IBM Plex. TODO usa design tokens (sin hardcode).
 */

// ── Primitivas ──────────────────────────────────────────────────────
function Panel({ title, sub, children, tone = 'primary' }: { title: string; sub?: string; children: React.ReactNode; tone?: 'primary' | 'muted' }) {
    return (
        <section className="overflow-hidden rounded-md bg-card shadow-soft">
            <header className={`flex items-baseline justify-between gap-3 border-b border-border px-4 py-2.5 ${tone === 'primary' ? 'bg-primary text-primary-foreground' : 'bg-muted text-foreground'}`}>
                <h3 className="text-sm font-semibold tracking-tight">{title}</h3>
                {sub && <span className={`text-xs ${tone === 'primary' ? 'text-primary-foreground/80' : 'text-muted-foreground'}`}>{sub}</span>}
            </header>
            <div className="p-4">{children}</div>
        </section>
    );
}

function Btn({ variant = 'primary', children, disabled, ...p }: React.ButtonHTMLAttributes<HTMLButtonElement> & { variant?: 'primary' | 'secondary' | 'outline' | 'danger' | 'ghost' }) {
    const base = 'inline-flex items-center justify-center gap-1.5 rounded-sm px-3 py-1.5 text-sm font-medium transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-1 focus-visible:ring-offset-background disabled:cursor-not-allowed disabled:opacity-50';
    const styles: Record<string, string> = {
        primary: 'bg-primary text-primary-foreground hover:brightness-110',
        secondary: 'bg-secondary text-secondary-foreground hover:brightness-95',
        outline: 'border border-input bg-transparent text-foreground hover:bg-muted',
        danger: 'bg-destructive text-destructive-foreground hover:brightness-110',
        ghost: 'text-foreground hover:bg-muted',
    };
    return <button className={`${base} ${styles[variant]}`} disabled={disabled} {...p}>{children}</button>;
}

function Badge({ tone, children }: { tone: 'success' | 'warning' | 'error' | 'info' | 'neutral'; children: React.ReactNode }) {
    const map: Record<string, string> = {
        success: 'bg-success/12 text-success border-success/30',
        warning: 'bg-warning/15 text-warning border-warning/40',
        error: 'bg-destructive/12 text-destructive border-destructive/30',
        info: 'bg-info/12 text-info border-info/30',
        neutral: 'bg-muted text-muted-foreground border-border',
    };
    return <span className={`inline-flex items-center gap-1 rounded-sm border px-2 py-0.5 text-xs font-medium ${map[tone]}`}>{children}</span>;
}

function Alert({ tone, title, children }: { tone: 'success' | 'warning' | 'error' | 'info'; title: string; children: React.ReactNode }) {
    const cfg = {
        success: { c: 'bg-success/8 border-success/30 text-foreground', i: <CheckCircle2 className="size-4 text-success" /> },
        warning: { c: 'bg-warning/12 border-warning/40 text-foreground', i: <AlertTriangle className="size-4 text-warning" /> },
        error: { c: 'bg-destructive/8 border-destructive/30 text-foreground', i: <XCircle className="size-4 text-destructive" /> },
        info: { c: 'bg-info/8 border-info/30 text-foreground', i: <Info className="size-4 text-info" /> },
    }[tone];
    return (
        <div className={`flex items-start gap-2.5 rounded-md border p-3 text-sm ${cfg.c}`}>
            <span className="mt-0.5 shrink-0">{cfg.i}</span>
            <div><p className="font-semibold">{title}</p><p className="text-muted-foreground">{children}</p></div>
        </div>
    );
}

function Field({ label, hint, error, children, required }: { label: string; hint?: string; error?: string; children: React.ReactNode; required?: boolean }) {
    return (
        <div className="flex flex-col gap-1">
            <label className="text-xs font-medium text-foreground">{label}{required && <span className="text-destructive"> *</span>}</label>
            {children}
            {error ? <p className="text-xs text-destructive">{error}</p> : hint ? <p className="text-xs text-muted-foreground">{hint}</p> : null}
        </div>
    );
}

const inputCls = 'h-9 w-full rounded-sm border border-input bg-card px-2.5 text-sm text-foreground placeholder:text-muted-foreground focus-visible:outline-none focus-visible:border-ring focus-visible:ring-2 focus-visible:ring-ring/25';

// ── Datos demo para la tabla ────────────────────────────────────────
const ROWS = [
    { n: 'F001-00000148', cli: 'GAONA HUAMAN SILVIA', fecha: '2026-09-15', total: 'S/ 236.00', estado: 'success' as const, e: 'Aceptado' },
    { n: 'B001-00000080', cli: 'CLIENTE PRUEBA ANTICIPO', fecha: '2026-09-15', total: 'S/ 226.00', estado: 'warning' as const, e: 'Enviado' },
    { n: 'F001-00000147', cli: 'MIGO S.A.C.', fecha: '2026-09-14', total: 'S/ 1,180.00', estado: 'error' as const, e: 'Rechazado' },
    { n: 'B001-00000079', cli: 'LIMA GAS S A', fecha: '2026-09-14', total: 'S/ 59.00', estado: 'info' as const, e: 'Pendiente' },
    { n: 'F001-00000146', cli: 'MUNICIPALIDAD DE SAN ISIDRO', fecha: '2026-09-13', total: 'S/ 3,540.00', estado: 'neutral' as const, e: 'Borrador' },
];

const NAV = [
    { icon: LayoutDashboard, label: 'Panel', active: true },
    { icon: Receipt, label: 'Comprobantes' },
    { icon: FileText, label: 'Notas' },
    { icon: Truck, label: 'Guías de remisión' },
    { icon: Users, label: 'Clientes' },
    { icon: Building2, label: 'Empresas' },
    { icon: BarChart3, label: 'Reportes' },
    { icon: Settings, label: 'Configuración' },
];

export default function Styleguide() {
    const [collapsed, setCollapsed] = useState(false);
    const [modal, setModal] = useState(false);

    return (
        <div className="min-h-screen bg-background text-foreground">
            <Head title="Styleguide" />

            {/* ── TOPBAR ── */}
            <header className="sticky top-0 z-30 flex h-12 items-center justify-between gap-4 bg-primary px-4 text-primary-foreground">
                <div className="flex items-center gap-3">
                    <button onClick={() => setCollapsed(!collapsed)} className="rounded-sm p-1 hover:bg-white/15" aria-label="Alternar menú"><Menu className="size-5" /></button>
                    <span className="text-sm font-semibold tracking-tight">Jorge Chavez API SUNAT</span>
                    <span className="hidden rounded-sm bg-white/15 px-1.5 py-0.5 text-[10px] font-medium uppercase sm:inline">Beta</span>
                </div>
                <div className="flex items-center gap-3">
                    <button className="relative rounded-sm p-1 hover:bg-white/15" aria-label="Notificaciones">
                        <Bell className="size-4" />
                        <span className="absolute right-0.5 top-0.5 size-1.5 rounded-full bg-warning" />
                    </button>
                    <div className="flex items-center gap-2 border-l border-white/20 pl-3">
                        <div className="flex size-7 items-center justify-center rounded-full bg-white/20 text-xs font-semibold">JC</div>
                        <div className="hidden text-right leading-tight sm:block">
                            <div className="text-xs font-medium">Jorge Chávez</div>
                            <div className="text-[10px] text-primary-foreground/75">RUC 20604895988</div>
                        </div>
                        <ChevronDown className="size-4 opacity-80" />
                    </div>
                </div>
            </header>

            <div className="flex">
                {/* ── SIDEBAR ── */}
                <aside className={`sticky top-12 h-[calc(100vh-3rem)] shrink-0 border-r border-sidebar-border bg-sidebar transition-[width] ${collapsed ? 'w-14' : 'w-60'}`}>
                    <nav className="flex flex-col gap-0.5 p-2">
                        {!collapsed && <p className="px-2 pb-1 pt-2 text-[10px] font-semibold uppercase tracking-wide text-muted-foreground">Emisión</p>}
                        {NAV.map((it) => (
                            <a key={it.label} href="#"
                                className={`flex items-center gap-2.5 rounded-sm px-2.5 py-2 text-sm transition-colors ${it.active ? 'bg-sidebar-primary text-sidebar-primary-foreground' : 'text-sidebar-foreground hover:bg-sidebar-accent hover:text-sidebar-accent-foreground'}`}>
                                <it.icon className="size-4 shrink-0" />
                                {!collapsed && <span className="truncate">{it.label}</span>}
                            </a>
                        ))}
                    </nav>
                    {!collapsed && (
                        <div className="absolute inset-x-2 bottom-2">
                            <a href="#" className="flex items-center gap-2.5 rounded-sm px-2.5 py-2 text-sm text-sidebar-foreground hover:bg-sidebar-accent"><LogOut className="size-4" /> Cerrar sesión</a>
                        </div>
                    )}
                </aside>

                {/* ── CONTENIDO ── */}
                <main className="min-w-0 flex-1">
                    {/* Breadcrumbs */}
                    <div className="flex items-center gap-1.5 border-b border-border bg-card px-5 py-2 text-xs text-muted-foreground">
                        <a href="#" className="hover:text-foreground">Inicio</a>
                        <ChevronRight className="size-3" />
                        <a href="#" className="hover:text-foreground">Comprobantes</a>
                        <ChevronRight className="size-3" />
                        <span className="font-medium text-foreground">Styleguide</span>
                    </div>

                    <div className="mx-auto flex max-w-6xl flex-col gap-6 p-5">
                        <div>
                            <h1 className="text-2xl font-bold tracking-tight">Sistema visual</h1>
                            <p className="text-sm text-muted-foreground">Estética institucional · identidad azul IBM Plex · todo con design tokens.</p>
                        </div>

                        {/* TIPOGRAFÍA + COLOR */}
                        <div className="grid gap-6 lg:grid-cols-2">
                            <Panel title="Tipografía" sub="IBM Plex Sans / Mono">
                                <div className="flex flex-col gap-2">
                                    <p className="text-3xl font-bold tracking-tight">Aa Comprobante 3xl</p>
                                    <p className="text-xl font-semibold">Aa Encabezado xl</p>
                                    <p className="text-base">Texto base — párrafo de lectura corrida para formularios densos.</p>
                                    <p className="text-sm text-muted-foreground">Texto secundario sm — ayudas y descripciones.</p>
                                    <p className="font-mono text-sm">RUC 20604895988 · F001-00000148 · DNI 12345678 <span className="text-muted-foreground">(mono, tabular)</span></p>
                                </div>
                            </Panel>
                            <Panel title="Paleta" sub="tokens semánticos">
                                <div className="grid grid-cols-3 gap-2 sm:grid-cols-4">
                                    {[
                                        ['primary', 'bg-primary'], ['secondary', 'bg-secondary'], ['success', 'bg-success'],
                                        ['warning', 'bg-warning'], ['destructive', 'bg-destructive'], ['info', 'bg-info'],
                                        ['muted', 'bg-muted'], ['border', 'bg-border'],
                                    ].map(([name, cls]) => (
                                        <div key={name} className="flex flex-col gap-1">
                                            <div className={`h-10 rounded-sm border border-border ${cls}`} />
                                            <span className="text-[10px] text-muted-foreground">{name}</span>
                                        </div>
                                    ))}
                                </div>
                            </Panel>
                        </div>

                        {/* BOTONES + BADGES */}
                        <Panel title="Botones y badges de estado">
                            <div className="flex flex-col gap-4">
                                <div className="flex flex-wrap items-center gap-2">
                                    <Btn variant="primary"><Plus className="size-4" /> Primario</Btn>
                                    <Btn variant="secondary">Secundario</Btn>
                                    <Btn variant="outline">Outline</Btn>
                                    <Btn variant="danger"><Trash2 className="size-4" /> Peligro</Btn>
                                    <Btn variant="ghost">Ghost</Btn>
                                    <Btn variant="primary" disabled>Deshabilitado</Btn>
                                </div>
                                <div className="flex flex-wrap items-center gap-2">
                                    <Badge tone="success"><CheckCircle2 className="size-3" /> Aceptado</Badge>
                                    <Badge tone="warning">Enviado</Badge>
                                    <Badge tone="error">Rechazado</Badge>
                                    <Badge tone="info">Pendiente</Badge>
                                    <Badge tone="neutral">Borrador</Badge>
                                </div>
                            </div>
                        </Panel>

                        {/* ALERTAS */}
                        <div className="grid gap-3 sm:grid-cols-2">
                            <Alert tone="success" title="Comprobante aceptado">La Boleta B001-80 fue aceptada por SUNAT (code 0).</Alert>
                            <Alert tone="warning" title="Aceptado con observación">Observación 3287: revisa el descuento por anticipo.</Alert>
                            <Alert tone="error" title="Rechazado por SUNAT">El RUC del receptor no existe (code 2022).</Alert>
                            <Alert tone="info" title="Envío en proceso">El comprobante está en cola; el estado se actualiza solo.</Alert>
                        </div>

                        {/* FORMULARIO DENSO */}
                        <Panel title="Formulario" sub="labels arriba · inputs compactos · errores inline">
                            <form className="flex flex-col gap-5">
                                <fieldset className="flex flex-col gap-3">
                                    <legend className="mb-1 text-xs font-semibold uppercase tracking-wide text-muted-foreground">Datos del comprobante</legend>
                                    <div className="grid gap-3 sm:grid-cols-3">
                                        <Field label="Serie" required><input className={inputCls} defaultValue="F001" /></Field>
                                        <Field label="Fecha de emisión" required><input type="date" className={inputCls} defaultValue="2026-09-15" /></Field>
                                        <Field label="Moneda">
                                            <select className={inputCls}><option>PEN - Soles</option><option>USD - Dólares</option></select>
                                        </Field>
                                    </div>
                                </fieldset>
                                <fieldset className="flex flex-col gap-3">
                                    <legend className="mb-1 text-xs font-semibold uppercase tracking-wide text-muted-foreground">Cliente</legend>
                                    <div className="grid gap-3 sm:grid-cols-2">
                                        <Field label="RUC / DNI" required error="El número de documento es obligatorio."><input className={`${inputCls} border-destructive focus-visible:border-destructive focus-visible:ring-destructive/25`} placeholder="20604895988" /></Field>
                                        <Field label="Razón social" hint="Se autocompleta al consultar el RUC."><input className={inputCls} placeholder="EMPRESA S.A.C." /></Field>
                                    </div>
                                </fieldset>
                                <div className="flex justify-end gap-2 border-t border-border pt-3">
                                    <Btn variant="outline">Cancelar</Btn>
                                    <Btn variant="secondary">Guardar borrador</Btn>
                                    <Btn variant="primary">Emitir y enviar a SUNAT</Btn>
                                </div>
                            </form>
                        </Panel>

                        {/* TABLA DE DATOS */}
                        <Panel title="Tabla de datos" sub="cabecera primaria · zebra · filtros · acciones">
                            {/* Filtros arriba */}
                            <div className="mb-3 flex flex-wrap items-end gap-2">
                                <div className="relative w-full sm:max-w-xs">
                                    <Search className="absolute left-2.5 top-1/2 size-4 -translate-y-1/2 text-muted-foreground" />
                                    <input className={`${inputCls} pl-8`} placeholder="Buscar por número o cliente…" />
                                </div>
                                <select className={`${inputCls} w-40`}><option>Estado: Todos</option><option>Aceptado</option><option>Rechazado</option></select>
                                <select className={`${inputCls} w-36`}><option>Tipo: Todos</option><option>Factura</option><option>Boleta</option></select>
                                <div className="flex-1" />
                                <Btn variant="primary"><Plus className="size-4" /> Nuevo</Btn>
                            </div>
                            <div className="overflow-x-auto rounded-sm border border-border">
                                <table className="w-full text-sm">
                                    <thead>
                                        <tr className="bg-primary text-primary-foreground text-left">
                                            <th className="px-3 py-2 font-semibold">Número</th>
                                            <th className="px-3 py-2 font-semibold">Cliente</th>
                                            <th className="px-3 py-2 font-semibold">Fecha</th>
                                            <th className="px-3 py-2 text-right font-semibold">Total</th>
                                            <th className="px-3 py-2 font-semibold">Estado</th>
                                            <th className="px-3 py-2 text-right font-semibold">Acciones</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {ROWS.map((r, i) => (
                                            <tr key={r.n} className={`border-t border-border ${i % 2 ? 'bg-muted/40' : 'bg-card'}`}>
                                                <td className="px-3 py-2 font-mono text-xs">{r.n}</td>
                                                <td className="px-3 py-2"><span className="block max-w-[200px] truncate">{r.cli}</span></td>
                                                <td className="px-3 py-2 text-muted-foreground">{r.fecha}</td>
                                                <td className="px-3 py-2 text-right tabular-nums">{r.total}</td>
                                                <td className="px-3 py-2"><Badge tone={r.estado}>{r.e}</Badge></td>
                                                <td className="px-3 py-2">
                                                    <div className="flex justify-end gap-1">
                                                        <button className="rounded-sm p-1 text-muted-foreground hover:bg-muted hover:text-foreground" aria-label="Editar"><Pencil className="size-3.5" /></button>
                                                        <button className="rounded-sm p-1 text-muted-foreground hover:bg-muted hover:text-destructive" aria-label="Eliminar"><Trash2 className="size-3.5" /></button>
                                                    </div>
                                                </td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                            {/* Paginación abajo */}
                            <div className="mt-3 flex items-center justify-between text-xs text-muted-foreground">
                                <span>1–5 de 148 comprobantes</span>
                                <div className="flex gap-1">
                                    <Btn variant="outline">Anterior</Btn>
                                    <Btn variant="outline">Siguiente</Btn>
                                </div>
                            </div>
                        </Panel>

                        {/* MODAL */}
                        <Panel title="Modal" tone="muted">
                            <Btn variant="primary" onClick={() => setModal(true)}>Abrir modal</Btn>
                        </Panel>
                    </div>
                </main>
            </div>

            {/* Modal overlay */}
            {modal && (
                <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4" onClick={() => setModal(false)}>
                    <div className="w-full max-w-md overflow-hidden rounded-md bg-card shadow-soft shadow-lg" onClick={(e) => e.stopPropagation()}>
                        <div className="flex items-center justify-between border-b border-border bg-primary px-4 py-2.5 text-primary-foreground">
                            <h3 className="text-sm font-semibold">Anular comprobante</h3>
                            <button onClick={() => setModal(false)} className="rounded-sm p-1 hover:bg-white/15"><X className="size-4" /></button>
                        </div>
                        <div className="flex flex-col gap-3 p-4">
                            <p className="text-sm text-muted-foreground">¿Confirmas la comunicación de baja de <span className="font-mono text-foreground">F001-00000148</span>? Esta acción se envía a SUNAT.</p>
                            <Field label="Motivo" required><input className={inputCls} placeholder="Error en datos del cliente" /></Field>
                        </div>
                        <div className="flex justify-end gap-2 border-t border-border px-4 py-3">
                            <Btn variant="outline" onClick={() => setModal(false)}>Cancelar</Btn>
                            <Btn variant="danger" onClick={() => setModal(false)}>Anular</Btn>
                        </div>
                    </div>
                </div>
            )}
        </div>
    );
}
