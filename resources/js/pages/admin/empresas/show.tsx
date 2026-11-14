import { Head, Link, router, usePage } from '@inertiajs/react';
import { useState } from 'react';
import { ArrowLeft, Check, Copy, Download, Infinity as InfinityIcon, KeyRound, MoreVertical, Pencil, Power, Store, Trash2 } from '@/lib/icons';
import AppLayout from '@/layouts/app-layout';
import { ConfettiBurst } from '@/components/ui/confetti-burst';
import { usePermissions } from '@/hooks/use-permissions';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { useConfirm } from '@/components/ui/confirm-dialog';
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuSeparator, DropdownMenuTrigger } from '@/components/ui/dropdown-menu';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import type { BreadcrumbItem } from '@/types';

type Tenant = {
    id: number;
    ruc: string;
    razon_social: string;
    nombre_comercial: string | null;
    direccion: string | null;
    ubigeo: string | null;
    departamento: string | null;
    provincia: string | null;
    distrito: string | null;
    telefonos: string[];
    emails: string[];
    environment: string;
    tax_regime: string | null;
    plan: string;
    emission_mode: string;
    max_documents_month: number;
    is_active: boolean;
    webhook_url: string | null;
    has_certificado: boolean;
    logo_url: string | null;
    sire_enabled: boolean;
    created_at: string | null;
    sucursales_count: number;
    series_count: number;
    user: { id: number; name: string; email: string } | null;
};

type Credenciales = {
    api_key: string;
    api_secret: string;
    ruc: string;
    razon_social: string;
};

type Props = {
    tenant: Tenant;
    credencialesNuevas: Credenciales | null;
};

const breadcrumbs = (razon: string): BreadcrumbItem[] => [
    { title: 'Administración', href: '#' },
    { title: 'Empresas', href: '/admin/empresas' },
    { title: razon, href: '#' },
];

/** Fila etiqueta → valor, con divisor sutil. Legible y 100% responsivo. */
const Row = ({ label, children }: { label: string; children: React.ReactNode }) => (
    <div className="flex items-start justify-between gap-4 border-b border-border/50 py-2.5 last:border-0">
        <dt className="shrink-0 text-sm text-muted-foreground">{label}</dt>
        <dd className="min-w-0 break-words text-right text-sm font-medium text-foreground">{children}</dd>
    </div>
);

export default function EmpresasShow({ tenant, credencialesNuevas }: Props) {
    const [credOpen, setCredOpen] = useState<boolean>(Boolean(credencialesNuevas));
    const [copied, setCopied] = useState<'key' | 'secret' | 'base' | null>(null);
    const confirm = useConfirm();
    const perms = usePermissions();

    // Base URL de la API según el dominio donde está desplegado el proyecto
    // (localhost, miapi.kodevo.es, etc.). El panel y la API viven en el mismo host.
    const baseUrl = `${window.location.origin}/api/v1`;

    // Confeti al crear la empresa (empresa_creada) y también al GENERAR/REGENERAR
    // credenciales (cada vez que llega credencialesNuevas). Se resetea con onDone
    // para poder volver a dispararlo.
    const flash = usePage<{ flash?: { empresa_creada?: boolean } }>().props.flash;
    const [celebrar, setCelebrar] = useState<boolean>(Boolean(flash?.empresa_creada || credencialesNuevas));

    // Abrir el modal cada vez que Inertia entrega credencialesNuevas
    // (POST /regenerar-credenciales redirige al mismo show sin remontar, así
    // useState no se reinicializa). Se detecta el cambio de props durante el
    // render, patrón recomendado por React en lugar de setState dentro de useEffect.
    const [previo, setPrevio] = useState({ cred: credencialesNuevas, creada: flash?.empresa_creada });
    if (previo.cred !== credencialesNuevas || previo.creada !== flash?.empresa_creada) {
        setPrevio({ cred: credencialesNuevas, creada: flash?.empresa_creada });
        if (credencialesNuevas && credencialesNuevas !== previo.cred) {
            setCredOpen(true);
            setCelebrar(true);
        }
        if (flash?.empresa_creada && !previo.creada) setCelebrar(true);
    }

    // Al cerrar el modal de credenciales: si la empresa se ACABA de crear
    // (empresa_creada), continúa el alta guiada llevando a crear la primera
    // sucursal. Si el modal vino de regenerar credenciales, solo cierra.
    const cerrarCredenciales = () => {
        setCredOpen(false);
        if (flash?.empresa_creada) {
            router.visit(`/admin/empresas/${tenant.id}/sucursales/nueva?onboarding=1`);
        }
    };

    const copy = (text: string, which: 'key' | 'secret' | 'base') => {
        navigator.clipboard.writeText(text);
        setCopied(which);
        setTimeout(() => setCopied(null), 1500);
    };

    // Genera y descarga un .txt con las credenciales. Todo pasa en el
    // navegador (Blob) — no hay endpoint ni correo, así evitamos los
    // filtros anti-spam del SMTP que bloqueaban el envío.
    const descargarTxt = () => {
        if (!credencialesNuevas) return;
        const c = credencialesNuevas;
        const contenido = [
            'CREDENCIALES DE API - Jorge Chavez API SUNAT',
            '===========================================',
            '',
            `Empresa: ${c.razon_social}`,
            `RUC:     ${c.ruc}`,
            '',
            '-- Endpoint --',
            `Base URL:     ${baseUrl}`,
            '',
            '-- Credenciales de acceso --',
            `X-Api-Key:    ${c.api_key}`,
            `X-Api-Secret: ${c.api_secret}`,
            '',
            'Envía ambos como headers HTTP en cada request a la API.',
            `Ejemplo: GET ${baseUrl}/empresa`,
            '',
            'IMPORTANTE: guarda este archivo en un lugar seguro.',
            'El api_secret no se puede volver a mostrar. Si lo pierdes,',
            'deberás regenerar las credenciales desde el panel.',
            '',
            `Generado: ${new Date().toLocaleString('es-PE')}`,
            '',
        ].join('\r\n');

        const blob = new Blob([contenido], { type: 'text/plain;charset=utf-8' });
        const url = URL.createObjectURL(blob);
        const a = document.createElement('a');
        a.href = url;
        a.download = `credenciales-api-${c.ruc}.txt`;
        document.body.appendChild(a);
        a.click();
        document.body.removeChild(a);
        URL.revokeObjectURL(url);
    };

    const regenerar = async () => {
        if (
            await confirm({
                title: '¿Regenerar credenciales?',
                description: 'Las credenciales anteriores dejarán de funcionar inmediatamente.',
                variant: 'danger',
                confirmText: 'Regenerar',
            })
        ) {
            router.post(`/admin/empresas/${tenant.id}/regenerar-credenciales`);
        }
    };

    const toggle = () => {
        router.post(`/admin/empresas/${tenant.id}/toggle`, {}, { preserveScroll: true });
    };

    const eliminar = async () => {
        if (
            await confirm({
                title: `¿Eliminar "${tenant.razon_social}"?`,
                description:
                    'Se borrarán DEFINITIVAMENTE la empresa y TODOS sus datos: comprobantes, series, ' +
                    'clientes, sucursales y archivos (XML/CDR/PDF). Esta acción es IRREVERSIBLE.',
                variant: 'danger',
                confirmText: 'Eliminar todo',
            })
        ) {
            router.delete(`/admin/empresas/${tenant.id}`);
        }
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs(tenant.razon_social)}>
            <Head title={tenant.razon_social} />
            <ConfettiBurst show={celebrar} onDone={() => setCelebrar(false)} />

            {/* Modal credenciales */}
            {credencialesNuevas && (
                <Dialog open={credOpen} onOpenChange={(o) => { if (!o) cerrarCredenciales(); }}>
                    <DialogContent className="rounded-2xl border-border shadow-none sm:max-w-lg">
                        <DialogHeader>
                            <DialogTitle className="flex items-center gap-2">
                                <KeyRound className="size-5 text-muted-foreground" />
                                Credenciales de la API
                            </DialogTitle>
                            <DialogDescription>
                                Cópialas ahora: son las únicas que debes darle al cliente. El{' '}
                                <span className="font-medium text-foreground">api_secret</span> no se volverá a mostrar.
                            </DialogDescription>
                        </DialogHeader>

                        <div className="space-y-3">
                            {/* Empresa */}
                            <div className="rounded-xl border border-border bg-muted/30 px-3.5 py-2.5">
                                <div className="text-[11px] font-medium uppercase tracking-wide text-muted-foreground">
                                    Empresa
                                </div>
                                <div className="mt-0.5 text-sm font-medium">
                                    {credencialesNuevas.ruc} — {credencialesNuevas.razon_social}
                                </div>
                            </div>

                            {/* Base URL + Credenciales */}
                            {([
                                { label: 'Base URL', value: baseUrl, which: 'base' as const },
                                { label: 'X-Api-Key', value: credencialesNuevas.api_key, which: 'key' as const },
                                { label: 'X-Api-Secret', value: credencialesNuevas.api_secret, which: 'secret' as const },
                            ]).map((c) => (
                                <div key={c.which} className="rounded-xl bg-muted/40 px-3.5 py-2.5">
                                    <div className="mb-1.5 flex items-center justify-between">
                                        <span className="text-[11px] font-medium uppercase tracking-wide text-muted-foreground">
                                            {c.label}
                                        </span>
                                        <button
                                            type="button"
                                            onClick={() => copy(c.value, c.which)}
                                            className="inline-flex items-center gap-1 rounded-md px-1.5 py-0.5 text-xs font-medium text-muted-foreground transition-colors hover:bg-muted hover:text-foreground"
                                        >
                                            {copied === c.which ? (
                                                <>
                                                    <Check className="size-3.5" /> Copiado
                                                </>
                                            ) : (
                                                <>
                                                    <Copy className="size-3.5" /> Copiar
                                                </>
                                            )}
                                        </button>
                                    </div>
                                    <div className="break-all font-mono text-xs leading-relaxed">{c.value}</div>
                                </div>
                            ))}
                        </div>

                        <DialogFooter className="gap-2 sm:justify-between">
                            <Button type="button" variant="secondary" onClick={descargarTxt}>
                                <Download className="size-4" />
                                Descargar .txt
                            </Button>
                            <Button onClick={cerrarCredenciales}>
                                {flash?.empresa_creada ? 'Ya guardé - crear sucursal' : 'Ya guardé las credenciales'}
                            </Button>
                        </DialogFooter>
                    </DialogContent>
                </Dialog>
            )}

            <div className="mx-auto flex w-full max-w-6xl flex-1 flex-col gap-5 p-4">
                {(!tenant.emails || tenant.emails.length === 0) && !tenant.user && (
                    <div className="rounded-md border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800 dark:border-amber-900/50 dark:bg-amber-950/20 dark:text-amber-300">
                        <strong>Sin destinatario para correos:</strong> esta empresa no tiene emails registrados ni un usuario asignado.
                        Las credenciales no se enviarán automáticamente hasta que agregues al menos un email en{' '}
                        <Link href={`/admin/empresas/${tenant.id}/editar`} className="underline font-medium">
                            Editar empresa
                        </Link>
                        .
                    </div>
                )}

                <div className="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                    <div className="flex min-w-0 items-center gap-4">
                        {/* Logo de la empresa */}
                        <div className="flex size-16 shrink-0 items-center justify-center overflow-hidden rounded-xl border border-border bg-muted/40">
                            {tenant.logo_url ? (
                                <img
                                    src={tenant.logo_url}
                                    alt={`Logo ${tenant.razon_social}`}
                                    className="size-full object-contain p-1"
                                    onError={(e) => (e.currentTarget.style.display = 'none')}
                                />
                            ) : (
                                <Store className="size-6 text-muted-foreground/40" />
                            )}
                        </div>
                        <div className="min-w-0">
                            <h1 className="truncate text-xl font-semibold tracking-tight">{tenant.razon_social}</h1>
                            <p className="font-mono text-sm text-muted-foreground">{tenant.ruc}</p>
                            <div className="mt-2 flex flex-wrap items-center gap-1.5">
                                <span className="inline-flex items-center gap-1.5 rounded-full border border-border px-2.5 py-0.5 text-xs font-medium">
                                    <span className={`size-1.5 rounded-full ${tenant.is_active ? 'bg-emerald-500' : 'bg-muted-foreground/40'}`} />
                                    {tenant.is_active ? 'Activa' : 'Inactiva'}
                                </span>
                                <span className="inline-flex items-center rounded-full border border-border px-2.5 py-0.5 text-xs font-medium uppercase text-muted-foreground">
                                    {tenant.environment}
                                </span>
                                <span className="inline-flex items-center rounded-full border border-border px-2.5 py-0.5 text-xs font-medium text-muted-foreground">
                                    {tenant.emission_mode === 'unlimited' ? 'Emisión ilimitada' : `Plan ${tenant.plan}`}
                                </span>
                            </div>
                        </div>
                    </div>

                    <div className="flex shrink-0 items-center gap-2">
                        <Button variant="ghost" size="sm" asChild>
                            <Link href="/admin/empresas">
                                <ArrowLeft className="size-4" />
                                Volver
                            </Link>
                        </Button>
                        {perms.canWrite && (
                            <Button variant="secondary" size="sm" asChild>
                                <Link href={`/admin/empresas/${tenant.id}/editar`}>
                                    <Pencil className="size-4" />
                                    Editar
                                </Link>
                            </Button>
                        )}
                        {(perms.canWrite || perms.canDelete) && (
                            <DropdownMenu>
                                <DropdownMenuTrigger asChild>
                                    <Button variant="outline" size="sm">
                                        <MoreVertical className="size-4" />
                                        Más
                                    </Button>
                                </DropdownMenuTrigger>
                                <DropdownMenuContent align="end" className="w-56">
                                    {perms.canWrite && (
                                        <>
                                            <DropdownMenuItem onSelect={(e) => { e.preventDefault(); regenerar(); }}>
                                                <KeyRound className="size-4" />
                                                Regenerar credenciales
                                            </DropdownMenuItem>
                                            <DropdownMenuItem onSelect={(e) => { e.preventDefault(); toggle(); }}>
                                                <Power className="size-4" />
                                                {tenant.is_active ? 'Desactivar' : 'Activar'}
                                            </DropdownMenuItem>
                                        </>
                                    )}
                                    {perms.canDelete && (
                                        <>
                                            {perms.canWrite && <DropdownMenuSeparator />}
                                            <DropdownMenuItem
                                                onSelect={(e) => { e.preventDefault(); eliminar(); }}
                                                className="text-red-600 focus:text-red-600 dark:text-red-400 dark:focus:text-red-400"
                                            >
                                                <Trash2 className="size-4 text-red-600 dark:text-red-400" />
                                                Eliminar empresa
                                            </DropdownMenuItem>
                                        </>
                                    )}
                                </DropdownMenuContent>
                            </DropdownMenu>
                        )}
                    </div>
                </div>

                <div className="grid gap-4 lg:grid-cols-3">
                    <div className="space-y-4 lg:col-span-2">
                        <Card className="p-5 sm:p-6">
                            <h3 className="mb-2 text-base font-semibold">Identidad</h3>
                            <dl>
                                <Row label="Nombre comercial">{tenant.nombre_comercial ?? '-'}</Row>
                                <Row label="Dirección">{tenant.direccion ?? '-'}</Row>
                                <Row label="Ubigeo"><span className="font-mono">{tenant.ubigeo ?? '-'}</span></Row>
                                <Row label="Depto / Prov / Distrito">
                                    {[tenant.departamento, tenant.provincia, tenant.distrito].filter(Boolean).join(' / ') || '-'}
                                </Row>
                                <Row label="Teléfonos">{tenant.telefonos.length ? tenant.telefonos.join(', ') : '-'}</Row>
                                <Row label="Emails">{tenant.emails.length ? tenant.emails.join(', ') : '-'}</Row>
                            </dl>
                        </Card>

                        <Card className="p-5 sm:p-6">
                            <h3 className="mb-2 text-base font-semibold">SUNAT &amp; régimen</h3>
                            <dl>
                                <Row label="Entorno"><span className="uppercase">{tenant.environment}</span></Row>
                                <Row label="Régimen tributario"><span className="uppercase">{tenant.tax_regime ?? '-'}</span></Row>
                                <Row label="Certificado">
                                    {tenant.has_certificado
                                        ? <span className="inline-flex items-center gap-1.5"><Check className="size-3.5 text-muted-foreground" /> Cargado</span>
                                        : <span className="text-muted-foreground">No cargado</span>}
                                </Row>
                                <Row label="SIRE">{tenant.sire_enabled ? 'Activo' : <span className="text-muted-foreground">Inactivo</span>}</Row>
                                <Row label="Webhook"><span className="break-all">{tenant.webhook_url ?? '-'}</span></Row>
                                <Row label="Plan / Límite">
                                    {tenant.emission_mode === 'unlimited' ? (
                                        <span className="inline-flex items-center gap-1.5">
                                            <InfinityIcon className="size-4 text-muted-foreground" /> Ilimitada
                                            <span className="font-normal text-muted-foreground">· plan {tenant.plan}</span>
                                        </span>
                                    ) : (
                                        <span className="uppercase">{tenant.plan} — {tenant.max_documents_month} docs/mes</span>
                                    )}
                                </Row>
                            </dl>
                        </Card>
                    </div>

                    <div className="space-y-4">
                        {/* Sucursales y Series como contadores accionables */}
                        <div className="grid grid-cols-2 gap-4">
                            {[
                                { label: 'Sucursales', value: tenant.sucursales_count, href: `/admin/empresas/${tenant.id}/sucursales` },
                                { label: 'Series', value: tenant.series_count, href: `/admin/empresas/${tenant.id}/series` },
                            ].map((s) => (
                                <Card key={s.label} className="flex flex-col p-4">
                                    <span className="text-xs text-muted-foreground">{s.label}</span>
                                    <span className="mt-1 text-2xl font-semibold tabular-nums">{s.value}</span>
                                    <Link href={s.href} className="mt-2 text-xs font-medium text-primary hover:underline">
                                        Gestionar →
                                    </Link>
                                </Card>
                            ))}
                        </div>

                        {/* Usuario asignado */}
                        <Card className="p-5">
                            <div className="mb-2 text-sm font-semibold">Usuario asignado</div>
                            {tenant.user ? (
                                <div className="flex items-center gap-3">
                                    <div className="flex size-9 shrink-0 items-center justify-center rounded-full bg-muted text-xs font-semibold uppercase text-muted-foreground">
                                        {tenant.user.name.slice(0, 2)}
                                    </div>
                                    <div className="min-w-0">
                                        <div className="truncate text-sm font-medium">{tenant.user.name}</div>
                                        <div className="truncate text-xs text-muted-foreground">{tenant.user.email}</div>
                                    </div>
                                </div>
                            ) : (
                                <div className="text-sm text-muted-foreground">Sin asignar</div>
                            )}
                        </Card>

                        {/* Registro */}
                        <Card className="p-5">
                            <div className="mb-2 text-sm font-semibold">Registro</div>
                            <dl>
                                <Row label="Creada">{tenant.created_at ? new Date(tenant.created_at).toLocaleString('es-PE') : '-'}</Row>
                                <Row label="ID interno"><span className="font-mono">#{tenant.id}</span></Row>
                            </dl>
                        </Card>
                    </div>
                </div>
            </div>
        </AppLayout>
    );
}
