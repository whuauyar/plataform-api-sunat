import { Head, Link, useForm } from '@inertiajs/react';
import { useState } from 'react';
import {
    ArrowLeft,
    Calculator,
    CreditCard,
    Database,
    IdCard,
    Info,
    Loader2,
    MapPin,
    Plus,
    Search,
    ShieldCheck,
    Store,
    UserCircle,
    X,
    type LucideIcon,
} from '@/lib/icons';
import AppLayout from '@/layouts/app-layout';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Combobox } from '@/components/ui/combobox';
import { Input } from '@/components/ui/input';
import { PasswordInput } from '@/components/ui/password-input';
import { Label } from '@/components/ui/label';
import { Checkbox } from '@/components/ui/checkbox';
import type { BreadcrumbItem } from '@/types';

type Tenant = {
    id: number | null;
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
    sol_user: string;
    environment: string;
    sire_enabled: boolean;
    sire_client_id: string | null;
    tax_regime: string;
    igv_rate_override: number | string | null;
    nrus_categoria: number | string | null;
    emission_mode: string;
    plan: string;
    max_documents_month: number;
    webhook_url: string | null;
    mensaje_agradecimiento: string | null;
    mensaje_promocional: string | null;
    cuentas_bancarias: Array<{ banco: string; tipo?: string; moneda?: string; numero: string; cci?: string }>;
    billeteras_digitales: Array<{ tipo: string; numero: string }>;
    user_id: number | null;
    is_active: boolean;
    has_certificado?: boolean;
    has_logo?: boolean;
    logo_url?: string | null;
};

type Option = { slug: string; name: string };
type UserOption = { id: number; name: string; email: string };

type Props = {
    tenant: Tenant;
    planes: Option[];
    usuarios: UserOption[];
    modo: 'crear' | 'editar';
    emisionGlobalIlimitada?: boolean;
};

const breadcrumbs = (modo: 'crear' | 'editar', razon?: string): BreadcrumbItem[] => [
    { title: 'Administración', href: '#' },
    { title: 'Empresas', href: '/admin/empresas' },
    { title: modo === 'crear' ? 'Nueva empresa' : `Editar: ${razon ?? ''}`, href: '#' },
];

/** Marca de campo obligatorio: (*) en rojo, bien visible. */
const Req = () => <span className="font-bold text-[#EF233C]">(*)</span>;

const Section = ({
    icon: Icon,
    title,
    subtitle,
    required,
    children,
}: {
    icon: LucideIcon;
    title: string;
    subtitle?: string;
    required?: boolean;
    children: React.ReactNode;
}) => (
    <Card className="p-6">
        <div className="mb-5 flex items-start gap-3">
            <div className="flex size-10 shrink-0 items-center justify-center rounded-lg text-primary">
                <Icon className="size-5" />
            </div>
            <div className="flex-1">
                <h3 className="text-base font-semibold leading-tight">
                    {title} {required && <Req />}
                </h3>
                {subtitle && <p className="mt-0.5 text-sm text-muted-foreground">{subtitle}</p>}
            </div>
        </div>
        {children}
    </Card>
);

export default function EmpresasForm({ tenant, planes, usuarios, modo, emisionGlobalIlimitada }: Props) {
    const editando = modo === 'editar';
    const [logoPreview, setLogoPreview] = useState<string | null>(null);
    const { data, setData, post, put, transform, processing, errors, progress } = useForm({
        ruc: tenant.ruc,
        razon_social: tenant.razon_social,
        nombre_comercial: tenant.nombre_comercial ?? '',
        direccion: tenant.direccion ?? '',
        ubigeo: tenant.ubigeo ?? '',
        departamento: tenant.departamento ?? '',
        provincia: tenant.provincia ?? '',
        distrito: tenant.distrito ?? '',
        // Si vienen vacíos en edición, muestro 1 input listo para escribir
        // en vez de solo el botón "Añadir" — evita el paso extra de clic.
        telefonos: (tenant.telefonos && tenant.telefonos.length > 0) ? tenant.telefonos : [''],
        emails: (tenant.emails && tenant.emails.length > 0) ? tenant.emails : [''],
        sol_user: tenant.sol_user ?? 'MODDATOS',
        sol_pass: '',
        environment: tenant.environment || 'beta',
        certificado: null as File | null,
        contrasena_certificado: '',
        sire_enabled: tenant.sire_enabled ?? false,
        sire_client_id: tenant.sire_client_id ?? '',
        sire_client_secret: '',
        tax_regime: tenant.tax_regime || 'general',
        igv_rate_override: tenant.igv_rate_override ?? '',
        nrus_categoria: tenant.nrus_categoria ?? '',
        emission_mode: tenant.emission_mode || 'plan',
        plan: tenant.plan || 'free',
        max_documents_month: Number(tenant.max_documents_month) || 20,
        webhook_url: tenant.webhook_url ?? '',
        logo: null as File | null,
        mensaje_agradecimiento: tenant.mensaje_agradecimiento ?? '',
        mensaje_promocional: tenant.mensaje_promocional ?? '',
        cuentas_bancarias: tenant.cuentas_bancarias ?? [],
        billeteras_digitales: tenant.billeteras_digitales ?? [],
        user_id: tenant.user_id ?? '',
        is_active: tenant.is_active ?? true,
    });

    const submit = (e: React.FormEvent) => {
        e.preventDefault();
        const hayArchivos = data.certificado instanceof File || data.logo instanceof File;

        if (editando && tenant.id) {
            if (hayArchivos) {
                // Inertia + PUT NO envía archivos: PHP no parsea el body multipart
                // en peticiones PUT. La solución de Inertia es POST con method
                // spoofing (_method: 'put') → así los archivos (logo/certificado)
                // llegan a Laravel y se enruta igual al handler PUT.
                transform((d) => ({ ...d, _method: 'put' }));
                post(`/admin/empresas/${tenant.id}`, { forceFormData: true });
            } else {
                // Sin archivos: un PUT en JSON funciona sin problema.
                transform((d) => d);
                put(`/admin/empresas/${tenant.id}`);
            }
        } else {
            transform((d) => d);
            post('/admin/empresas', { forceFormData: hayArchivos });
        }
    };

    const addRepeat = <K extends 'telefonos' | 'emails' | 'cuentas_bancarias' | 'billeteras_digitales'>(
        key: K,
        emptyItem: unknown,
    ) => {
        setData(key, [...(data[key] as unknown[]), emptyItem] as never);
    };

    const rmRepeat = <K extends 'telefonos' | 'emails' | 'cuentas_bancarias' | 'billeteras_digitales'>(
        key: K,
        i: number,
    ) => {
        const arr = [...(data[key] as unknown[])];
        arr.splice(i, 1);
        setData(key, arr as never);
    };

    // Traer datos reales del RUC desde api.json.pe (token global del admin).
    const [buscandoRuc, setBuscandoRuc] = useState(false);
    const [rucMsg, setRucMsg] = useState<{ ok: boolean; texto: string } | null>(null);
    const buscarRuc = async () => {
        const n = data.ruc.trim();
        if (n.length !== 11 && n.length !== 8) {
            setRucMsg({ ok: false, texto: 'Ingresa 11 dígitos (RUC) u 8 (DNI).' });
            return;
        }
        setBuscandoRuc(true);
        setRucMsg(null);
        try {
            const res = await fetch(`/admin/buscar-ruc?numero=${encodeURIComponent(n)}`, { headers: { Accept: 'application/json' } });
            const d = await res.json();
            if (!res.ok) {
                setRucMsg({ ok: false, texto: d.error ?? 'No se pudo consultar.' });
                return;
            }
            if (d.razon_social) setData('razon_social', d.razon_social);
            if (d.direccion) setData('direccion', d.direccion);
            if (d.departamento) setData('departamento', d.departamento);
            if (d.provincia) setData('provincia', d.provincia);
            if (d.distrito) setData('distrito', d.distrito);
            if (d.ubigeo) setData('ubigeo', d.ubigeo);
            const extra = d.estado ? ` · ${d.estado}${d.condicion ? ' / ' + d.condicion : ''}` : '';
            setRucMsg({ ok: true, texto: `${d.razon_social ?? 'Encontrado'}${extra}` });
        } catch {
            setRucMsg({ ok: false, texto: 'No se pudo conectar con el servicio de consulta.' });
        } finally {
            setBuscandoRuc(false);
        }
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs(modo, tenant.razon_social)}>
            <Head title={editando ? 'Editar empresa' : 'Nueva empresa'} />

            <form onSubmit={submit} className="flex flex-1 flex-col gap-4 p-4">
                <div className="flex items-center justify-between">
                    <div>
                        <h1 className="text-xl font-semibold tracking-tight">
                            {editando ? `Editar: ${tenant.razon_social}` : 'Nueva empresa'}
                        </h1>
                        <p className="text-sm text-muted-foreground">
                            Configura todos los datos SUNAT, comerciales y de plan.
                        </p>
                    </div>
                    <Button variant="ghost" asChild>
                        <Link href="/admin/empresas">
                            <ArrowLeft className="size-4" />
                            Volver
                        </Link>
                    </Button>
                </div>

                {editando && (
                    <div className="rounded-md border border-sky-200 bg-sky-50 px-4 py-3 text-sm text-sky-900 dark:border-sky-900/50 dark:bg-sky-950/20 dark:text-sky-300">
                        <strong>Editando datos existentes.</strong>{' '}
                        Los campos que ves con texto en gris <em>(Ej: ...)</em> están vacíos en la base de datos —
                        es solo texto de ejemplo para orientarte. Completa los que quieras y guarda; los campos vacíos quedan vacíos.
                    </div>
                )}

                {/* 1. Identidad */}
                <Section icon={IdCard} title="1. Identidad" required subtitle="RUC y datos legales de la empresa">
                    <div className="grid gap-4 md:grid-cols-3">
                        <div>
                            <Label htmlFor="ruc">RUC <Req /></Label>
                            <div className="flex gap-2">
                                <Input
                                    id="ruc"
                                    value={data.ruc}
                                    onChange={(e) => setData('ruc', e.target.value.replace(/\D/g, ''))}
                                    onKeyDown={(e) => { if (!editando && e.key === 'Enter') { e.preventDefault(); buscarRuc(); } }}
                                    maxLength={11}
                                    inputMode="numeric"
                                    pattern="\d{11}"
                                    required
                                    readOnly={editando}
                                    className={editando ? 'bg-muted' : ''}
                                    placeholder="20512345678"
                                />
                                {!editando && (
                                    <Button type="button" variant="secondary" onClick={buscarRuc} disabled={buscandoRuc || data.ruc.trim().length < 8} title="Traer datos reales de SUNAT">
                                        {buscandoRuc ? <Loader2 className="size-4 animate-spin" /> : <Search className="size-4" />}
                                        Buscar
                                    </Button>
                                )}
                            </div>
                            {errors.ruc && <p className="mt-1 text-xs text-red-600">{errors.ruc}</p>}
                            {rucMsg ? (
                                <p className={`mt-1 text-xs ${rucMsg.ok ? 'text-emerald-600 dark:text-emerald-400' : 'text-red-600'}`}>{rucMsg.texto}</p>
                            ) : (
                                !editando && <p className="mt-1 text-xs text-muted-foreground">11 dígitos (10/15/17/20). Pulsa <strong>Buscar</strong> para autocompletar razón social, dirección y ubigeo.</p>
                            )}
                        </div>
                        <div className="md:col-span-2">
                            <Label htmlFor="razon_social">Razón social <Req /></Label>
                            <Input
                                id="razon_social"
                                value={data.razon_social}
                                onChange={(e) => setData('razon_social', e.target.value)}
                                required
                                maxLength={255}
                                placeholder="EMPRESA EJEMPLO S.A.C."
                            />
                            {errors.razon_social && <p className="mt-1 text-xs text-red-600">{errors.razon_social}</p>}
                        </div>
                        <div className="md:col-span-3">
                            <Label htmlFor="nombre_comercial">Nombre comercial</Label>
                            <Input
                                id="nombre_comercial"
                                value={data.nombre_comercial}
                                onChange={(e) => setData('nombre_comercial', e.target.value)}
                                maxLength={255}
                                placeholder="Ej: TIENDA MODAS AMÉRICA (opcional)"
                            />
                        </div>
                    </div>
                </Section>

                {/* 2. Ubicación */}
                <Section icon={MapPin} title="2. Ubicación" required subtitle="Dirección fiscal y contacto">
                    <div className="grid gap-4 md:grid-cols-6">
                        <div className="md:col-span-6">
                            <Label htmlFor="direccion">Dirección fiscal <Req /></Label>
                            <Input
                                id="direccion"
                                value={data.direccion}
                                onChange={(e) => setData('direccion', e.target.value)}
                                maxLength={500}
                                placeholder="Av. Arequipa 1234, San Isidro"
                            />
                        </div>
                        <div>
                            <Label htmlFor="ubigeo">Ubigeo <Req /></Label>
                            <Input
                                id="ubigeo"
                                value={data.ubigeo}
                                onChange={(e) => setData('ubigeo', e.target.value.replace(/\D/g, ''))}
                                maxLength={6}
                                inputMode="numeric"
                                pattern="\d{6}"
                                placeholder="150101"
                            />
                        </div>
                        <div className="md:col-span-2">
                            <Label htmlFor="departamento">Departamento <Req /></Label>
                            <Input
                                id="departamento"
                                value={data.departamento}
                                onChange={(e) => setData('departamento', e.target.value)}
                                placeholder="LIMA"
                            />
                        </div>
                        <div className="md:col-span-2">
                            <Label htmlFor="provincia">Provincia <Req /></Label>
                            <Input
                                id="provincia"
                                value={data.provincia}
                                onChange={(e) => setData('provincia', e.target.value)}
                                placeholder="LIMA"
                            />
                        </div>
                        <div>
                            <Label htmlFor="distrito">Distrito <Req /></Label>
                            <Input
                                id="distrito"
                                value={data.distrito}
                                onChange={(e) => setData('distrito', e.target.value)}
                                placeholder="SAN ISIDRO"
                            />
                        </div>

                        <div className="md:col-span-3">
                            <Label>Teléfonos</Label>
                            {data.telefonos.map((tel, i) => (
                                <div key={i} className="mt-2 flex gap-2">
                                    <Input
                                        value={tel}
                                        onChange={(e) => {
                                            const arr = [...data.telefonos];
                                            arr[i] = e.target.value.replace(/[^\d+\s()-]/g, '');
                                            setData('telefonos', arr);
                                        }}
                                        maxLength={20}
                                        inputMode="tel"
                                        placeholder="Ej: +51 999 999 999"
                                    />
                                    <Button
                                        type="button"
                                        variant="ghost"
                                        size="icon"
                                        onClick={() => rmRepeat('telefonos', i)}
                                    >
                                        <X className="size-4" />
                                    </Button>
                                </div>
                            ))}
                            {data.telefonos.length < 5 && (
                                <Button
                                    type="button"
                                    variant="ghost"
                                    size="sm"
                                    className="mt-2"
                                    onClick={() => addRepeat('telefonos', '')}
                                >
                                    <Plus className="size-3" /> Añadir teléfono
                                </Button>
                            )}
                        </div>

                        <div className="md:col-span-3">
                            <Label>Emails</Label>
                            {data.emails.map((em, i) => (
                                <div key={i} className="mt-2 flex gap-2">
                                    <Input
                                        type="email"
                                        value={em}
                                        onChange={(e) => {
                                            const arr = [...data.emails];
                                            arr[i] = e.target.value;
                                            setData('emails', arr);
                                        }}
                                        maxLength={100}
                                        placeholder="Ej: ventas@empresa.com"
                                    />
                                    <Button
                                        type="button"
                                        variant="ghost"
                                        size="icon"
                                        onClick={() => rmRepeat('emails', i)}
                                    >
                                        <X className="size-4" />
                                    </Button>
                                </div>
                            ))}
                            {data.emails.length < 5 && (
                                <Button
                                    type="button"
                                    variant="ghost"
                                    size="sm"
                                    className="mt-2"
                                    onClick={() => addRepeat('emails', '')}
                                >
                                    <Plus className="size-3" /> Añadir email
                                </Button>
                            )}
                        </div>
                    </div>
                </Section>

                {/* 3. Credenciales SUNAT */}
                <Section icon={ShieldCheck} title="3. Credenciales SUNAT" required subtitle="Usuario secundario, certificado y entorno">
                    <p className="-mt-5 mb-1 flex items-start gap-1.5 text-xs leading-snug text-muted-foreground">
                        <Info className="mt-0.5 size-3.5 shrink-0" />
                        <span>El <strong className="font-medium text-foreground">certificado digital</strong> y el <strong className="font-medium text-foreground">usuario secundario SOL</strong> pueden tardar hasta <strong className="font-medium text-foreground">24 horas</strong> en activarse en SUNAT tras registrarlos.</span>
                    </p>
                    <div className="grid gap-4 md:grid-cols-3">
                        <div>
                            <Label htmlFor="sol_user">Usuario secundario <Req /></Label>
                            <Input
                                id="sol_user"
                                value={data.sol_user}
                                onChange={(e) => setData('sol_user', e.target.value)}
                                required
                                maxLength={50}
                                placeholder="MODDATOS (para beta)"
                            />
                        </div>
                        <div>
                            <Label htmlFor="sol_pass">Clave del usuario secundario {!editando && <Req />}</Label>
                            <PasswordInput
                                id="sol_pass"
                                value={data.sol_pass}
                                onChange={(e) => setData('sol_pass', e.target.value)}
                                required={!editando}
                                minLength={4}
                                placeholder={editando ? '••••• (dejar vacío = sin cambios)' : 'MODDATOS (para beta)'}
                            />
                        </div>
                        <div>
                            <Label htmlFor="environment">Entorno <Req /></Label>
                            <Combobox
                                value={data.environment}
                                onChange={(v) => setData('environment', v)}
                                options={[
                                    { value: 'beta', label: 'Beta (pruebas)' },
                                    { value: 'production', label: 'Producción' },
                                ]}
                            />
                        </div>
                        <div className="md:col-span-2">
                            <Label htmlFor="certificado">Certificado digital (.pfx / .p12 / .pem) {!editando && <Req />}</Label>
                            <Input
                                id="certificado"
                                type="file"
                                accept=".pfx,.p12,.pem"
                                onChange={(e) => setData('certificado', e.target.files?.[0] ?? null)}
                            />
                            {tenant.has_certificado && (
                                <p className="mt-1 text-xs text-emerald-600">✔ Certificado actual cargado</p>
                            )}
                        </div>
                        <div>
                            <Label htmlFor="contrasena_certificado">Contraseña del certificado {!editando && <Req />}</Label>
                            <PasswordInput
                                id="contrasena_certificado"
                                value={data.contrasena_certificado}
                                onChange={(e) => setData('contrasena_certificado', e.target.value)}
                                placeholder="Contraseña del archivo .pfx"
                            />
                        </div>
                    </div>
                </Section>

                {/* 4. Credenciales API REST SUNAT */}
                <Section icon={Database} title="4. Credenciales API SUNAT (opcional)" subtitle="Para GRE (guías), consulta de comprobantes, SIRE y más">
                    <div className="space-y-4">
                        <div className="flex items-center gap-2">
                            <Checkbox
                                id="sire_enabled"
                                checked={data.sire_enabled}
                                onCheckedChange={(v) => setData('sire_enabled', v === true)}
                            />
                            <Label htmlFor="sire_enabled" className="font-normal">
                                Activar servicios de la API REST SUNAT para esta empresa
                            </Label>
                        </div>
                        <div className="grid gap-4 md:grid-cols-3">
                            <div>
                                <Label htmlFor="sire_client_id">Client ID</Label>
                                <Input
                                    id="sire_client_id"
                                    value={data.sire_client_id}
                                    onChange={(e) => setData('sire_client_id', e.target.value)}
                                    maxLength={100}
                                    placeholder="Ej: 00abc123-4567-... (Client ID)"
                                />
                            </div>
                            <div className="md:col-span-2">
                                <Label htmlFor="sire_client_secret">Client Secret</Label>
                                <PasswordInput
                                    id="sire_client_secret"
                                    value={data.sire_client_secret}
                                    onChange={(e) => setData('sire_client_secret', e.target.value)}
                                    placeholder={editando ? '••••• (dejar vacío = sin cambios)' : 'Client Secret otorgado por SUNAT'}
                                    maxLength={200}
                                />
                            </div>
                        </div>
                    </div>
                </Section>

                {/* 5. Régimen tributario */}
                <Section icon={Calculator} title="5. Régimen tributario" required subtitle="Define cómo se calcula el IGV">
                    <div className="grid gap-4 md:grid-cols-3">
                        <div>
                            <Label htmlFor="tax_regime">Régimen <Req /></Label>
                            <Combobox
                                value={data.tax_regime}
                                onChange={(v) => setData('tax_regime', v)}
                                options={[
                                    { value: 'rus', label: 'Nuevo RUS (solo boletas, 0% IGV)' },
                                    { value: 'rer', label: 'Régimen Especial (RER)' },
                                    { value: 'mype', label: 'Régimen MYPE Tributario' },
                                    { value: 'general', label: 'Régimen General' },
                                ]}
                            />
                        </div>
                        {data.tax_regime !== 'rus' && (
                            <div>
                                <Label htmlFor="igv_rate_override">IGV override (%)</Label>
                                <Input
                                    id="igv_rate_override"
                                    type="number"
                                    step="0.01"
                                    value={data.igv_rate_override as string}
                                    onChange={(e) => setData('igv_rate_override', e.target.value)}
                                    placeholder="18.00"
                                />
                            </div>
                        )}
                        {data.tax_regime === 'rus' && (
                            <div>
                                <Label htmlFor="nrus_categoria">Categoría del RUS</Label>
                                <Combobox
                                    value={data.nrus_categoria as string}
                                    onChange={(v) => setData('nrus_categoria', v)}
                                    options={[
                                        { value: '', label: '-' },
                                        { value: '1', label: 'Cat. 1 (S/20/mes - hasta S/5k)' },
                                        { value: '2', label: 'Cat. 2 (S/50/mes - hasta S/8k)' },
                                    ]}
                                    placeholder="-"
                                />
                            </div>
                        )}
                    </div>
                </Section>

                {/* 6. Emisión: modo + plan */}
                <Section icon={CreditCard} title="6. Emisión y plan" required subtitle="Define si la empresa emite ilimitado o según un plan">
                    {emisionGlobalIlimitada && (
                        <p className="mb-4 text-sm text-muted-foreground">
                            <strong className="font-medium text-foreground">Emisión ilimitada global activa.</strong>{' '}
                            Mientras el switch global esté encendido, esta empresa emite sin límites sin
                            importar lo que elijas aquí. Este ajuste tomará efecto cuando lo apagues.
                        </p>
                    )}

                    {/* Selector de modo de emisión (radio) */}
                    <div className="mb-5">
                        <Label>Modo de emisión <Req /></Label>
                        <div className="mt-2 flex flex-col gap-2">
                            {[
                                { value: 'unlimited', label: 'Ilimitada', hint: 'Emite sin restricciones. No requiere plan.' },
                                { value: 'plan', label: 'Por plan / suscripción', hint: 'Respeta los límites del plan (comprobantes/mes).' },
                            ].map((opt) => {
                                const active = data.emission_mode === opt.value;
                                return (
                                    <label key={opt.value} className="flex cursor-pointer items-start gap-2.5">
                                        <input
                                            type="radio"
                                            name="emission_mode"
                                            value={opt.value}
                                            checked={active}
                                            onChange={() => setData('emission_mode', opt.value)}
                                            className="sr-only"
                                        />
                                        <span
                                            className={`mt-0.5 flex size-4 shrink-0 items-center justify-center rounded-full border ${
                                                active ? 'border-primary' : 'border-muted-foreground/40'
                                            }`}
                                        >
                                            <span className={`size-2 rounded-full ${active ? 'bg-primary' : 'bg-transparent'}`} />
                                        </span>
                                        <span>
                                            <span className="text-sm font-medium">{opt.label}</span>
                                            <span className="block text-xs text-muted-foreground">{opt.hint}</span>
                                        </span>
                                    </label>
                                );
                            })}
                        </div>
                    </div>

                    {/* Plan y cupo: sólo en modo "por plan" */}
                    {data.emission_mode === 'plan' && (
                        <div className="grid gap-4 md:grid-cols-2">
                            <div>
                                <Label htmlFor="plan">Plan <Req /></Label>
                                <Combobox
                                    value={data.plan}
                                    onChange={(v) => setData('plan', v)}
                                    options={[
                                        { value: 'free', label: 'Free' },
                                        { value: 'pro', label: 'Pro' },
                                        { value: 'business', label: 'Business' },
                                        ...planes
                                            .filter((p) => !['free', 'pro', 'business'].includes(p.slug))
                                            .map((p) => ({ value: p.slug, label: p.name })),
                                    ]}
                                />
                                <p className="text-muted-foreground mt-1 text-xs">
                                    El cupo mensual se toma del plan. Un plan vencido bloquea la emisión.
                                </p>
                            </div>
                            <div>
                                <Label htmlFor="max_documents_month">Documentos SUNAT/mes (referencia)</Label>
                                <Input
                                    id="max_documents_month"
                                    type="number"
                                    value={data.max_documents_month}
                                    onChange={(e) => setData('max_documents_month', Number(e.target.value))}
                                    min={0}
                                    max={99999}
                                    placeholder="Ej: 200 (-1 = ilimitado)"
                                />
                                <p className="text-muted-foreground mt-1 text-xs">
                                    Se sincroniza con el plan al asignarlo.
                                </p>
                            </div>
                        </div>
                    )}

                    {data.emission_mode === 'unlimited' && (
                        <p className="text-sm text-muted-foreground">
                            <strong className="font-medium text-foreground">Emisión ilimitada.</strong> Esta empresa emitirá comprobantes sin límite de
                            cantidad ni vencimiento. No se aplicará ningún plan.
                        </p>
                    )}

                    <div className="mt-5 border-t pt-4">
                        <label className="inline-flex items-center gap-2">
                            <Checkbox
                                checked={data.is_active}
                                onCheckedChange={(v) => setData('is_active', v === true)}
                            />
                            <span className="text-sm">Empresa activa</span>
                        </label>
                    </div>
                </Section>

                {/* 7. Comercial */}
                <Section icon={Store} title="7. Comercial" subtitle="Webhook, logo, mensajes y pagos">
                    <div className="grid gap-4 md:grid-cols-2">
                        <div className="md:col-span-2">
                            <Label htmlFor="webhook_url">Webhook URL</Label>
                            <Input
                                id="webhook_url"
                                type="url"
                                value={data.webhook_url}
                                onChange={(e) => setData('webhook_url', e.target.value)}
                                placeholder="Ej: https://tu-app.com/sunat-webhook (opcional)"
                                maxLength={500}
                            />
                        </div>
                        <div>
                            <Label htmlFor="logo">Logo (jpg/png/webp)</Label>
                            <div className="flex items-start gap-4">
                                {/* Vista previa del logo */}
                                <div className="flex size-20 shrink-0 items-center justify-center overflow-hidden rounded-xl border border-border bg-muted/40">
                                    {logoPreview ? (
                                        <img src={logoPreview} alt="Vista previa del logo" className="size-full object-contain p-1" />
                                    ) : tenant.logo_url ? (
                                        <img src={tenant.logo_url} alt="Logo actual" className="size-full object-contain p-1" onError={(e) => (e.currentTarget.style.display = 'none')} />
                                    ) : (
                                        <Store className="size-7 text-muted-foreground/40" />
                                    )}
                                </div>
                                <div className="flex-1">
                                    <Input
                                        id="logo"
                                        type="file"
                                        accept=".jpg,.jpeg,.png,.webp"
                                        onChange={(e) => {
                                            const file = e.target.files?.[0] ?? null;
                                            setData('logo', file);
                                            if (logoPreview) URL.revokeObjectURL(logoPreview);
                                            setLogoPreview(file ? URL.createObjectURL(file) : null);
                                        }}
                                    />
                                    <p className="mt-1.5 text-xs text-muted-foreground">
                                        Cuadrado <strong>300×300 px</strong>, PNG. Máx. 2 MB.
                                    </p>
                                    {tenant.has_logo && !logoPreview && (
                                        <p className="mt-1 text-xs text-emerald-600">✔ Logo actual cargado</p>
                                    )}
                                </div>
                            </div>
                        </div>
                        <div className="md:col-span-2">
                            <Label htmlFor="mensaje_agradecimiento">Mensaje de agradecimiento (PDFs)</Label>
                            <textarea
                                id="mensaje_agradecimiento"
                                rows={2}
                                maxLength={500}
                                className="form-input"
                                value={data.mensaje_agradecimiento}
                                onChange={(e) => setData('mensaje_agradecimiento', e.target.value)}
                                placeholder="Ej: Gracias por su compra. Vuelva pronto."
                            />
                        </div>
                        <div className="md:col-span-2">
                            <Label htmlFor="mensaje_promocional">Mensaje promocional</Label>
                            <textarea
                                id="mensaje_promocional"
                                rows={2}
                                maxLength={500}
                                className="form-input"
                                value={data.mensaje_promocional}
                                onChange={(e) => setData('mensaje_promocional', e.target.value)}
                                placeholder="Ej: Síguenos en @tuempresa • www.tuempresa.com"
                            />
                        </div>

                        {/* Cuentas bancarias */}
                        <div className="md:col-span-2">
                            <Label>Cuentas bancarias</Label>
                            {data.cuentas_bancarias.map((c, i) => (
                                <div key={i} className="mt-2 grid gap-2 rounded bg-muted/30 p-3 md:grid-cols-6">
                                    <Input
                                        placeholder="Banco"
                                        value={c.banco}
                                        onChange={(e) => {
                                            const arr = [...data.cuentas_bancarias];
                                            arr[i] = { ...arr[i], banco: e.target.value };
                                            setData('cuentas_bancarias', arr);
                                        }}
                                    />
                                    <Input
                                        placeholder="Tipo (ahorros/cte)"
                                        value={c.tipo ?? ''}
                                        onChange={(e) => {
                                            const arr = [...data.cuentas_bancarias];
                                            arr[i] = { ...arr[i], tipo: e.target.value };
                                            setData('cuentas_bancarias', arr);
                                        }}
                                    />
                                    <Combobox
                                        value={c.moneda ?? ''}
                                        onChange={(v) => {
                                            const arr = [...data.cuentas_bancarias];
                                            arr[i] = { ...arr[i], moneda: v };
                                            setData('cuentas_bancarias', arr);
                                        }}
                                        options={[
                                            { value: '', label: 'Moneda' },
                                            { value: 'PEN', label: 'PEN' },
                                            { value: 'USD', label: 'USD' },
                                        ]}
                                        placeholder="Moneda"
                                    />
                                    <Input
                                        placeholder="Número"
                                        value={c.numero}
                                        inputMode="numeric"
                                        onChange={(e) => {
                                            const arr = [...data.cuentas_bancarias];
                                            arr[i] = { ...arr[i], numero: e.target.value.replace(/[^\d-]/g, '') };
                                            setData('cuentas_bancarias', arr);
                                        }}
                                    />
                                    <Input
                                        placeholder="CCI (opcional)"
                                        value={c.cci ?? ''}
                                        inputMode="numeric"
                                        onChange={(e) => {
                                            const arr = [...data.cuentas_bancarias];
                                            arr[i] = { ...arr[i], cci: e.target.value.replace(/\D/g, '') };
                                            setData('cuentas_bancarias', arr);
                                        }}
                                    />
                                    <Button
                                        type="button"
                                        variant="ghost"
                                        size="sm"
                                        onClick={() => rmRepeat('cuentas_bancarias', i)}
                                        className="text-red-600"
                                    >
                                        Eliminar
                                    </Button>
                                </div>
                            ))}
                            {data.cuentas_bancarias.length < 5 && (
                                <Button
                                    type="button"
                                    variant="ghost"
                                    size="sm"
                                    className="mt-2"
                                    onClick={() =>
                                        addRepeat('cuentas_bancarias', {
                                            banco: '',
                                            tipo: '',
                                            moneda: 'PEN',
                                            numero: '',
                                            cci: '',
                                        })
                                    }
                                >
                                    <Plus className="size-3" /> Añadir cuenta
                                </Button>
                            )}
                        </div>

                        {/* Billeteras */}
                        <div className="md:col-span-2">
                            <Label>Billeteras digitales</Label>
                            {data.billeteras_digitales.map((b, i) => (
                                <div key={i} className="mt-2 grid gap-2 rounded bg-muted/30 p-3 md:grid-cols-3">
                                    <Input
                                        placeholder="Ej: Yape / Plin / Tunki..."
                                        value={b.tipo}
                                        onChange={(e) => {
                                            const arr = [...data.billeteras_digitales];
                                            arr[i] = { ...arr[i], tipo: e.target.value };
                                            setData('billeteras_digitales', arr);
                                        }}
                                    />
                                    <Input
                                        placeholder="Número"
                                        value={b.numero}
                                        inputMode="tel"
                                        onChange={(e) => {
                                            const arr = [...data.billeteras_digitales];
                                            arr[i] = { ...arr[i], numero: e.target.value.replace(/[^\d+\s()-]/g, '') };
                                            setData('billeteras_digitales', arr);
                                        }}
                                    />
                                    <Button
                                        type="button"
                                        variant="ghost"
                                        size="sm"
                                        onClick={() => rmRepeat('billeteras_digitales', i)}
                                        className="text-red-600"
                                    >
                                        Eliminar
                                    </Button>
                                </div>
                            ))}
                            {data.billeteras_digitales.length < 5 && (
                                <Button
                                    type="button"
                                    variant="ghost"
                                    size="sm"
                                    className="mt-2"
                                    onClick={() => addRepeat('billeteras_digitales', { tipo: '', numero: '' })}
                                >
                                    <Plus className="size-3" /> Añadir billetera
                                </Button>
                            )}
                        </div>
                    </div>
                </Section>

                {/* 8. Usuario asignado */}
                <Section icon={UserCircle} title="8. Usuario asignado (opcional)" subtitle="Cliente que administra esta empresa">
                    <div>
                        <Label htmlFor="user_id">Usuario</Label>
                        <Combobox
                            value={String(data.user_id)}
                            onChange={(v) => setData('user_id', v)}
                            options={[
                                { value: '', label: 'Sin asignar' },
                                ...usuarios.map((u) => ({ value: String(u.id), label: `${u.name} (${u.email})` })),
                            ]}
                            placeholder="Sin asignar"
                            searchable
                            className="md:max-w-md"
                        />
                    </div>
                </Section>

                {progress && (
                    <div className="text-xs text-muted-foreground">
                        Subiendo archivos: {progress.percentage}%
                    </div>
                )}

                {/* Errores generales */}
                {Object.keys(errors).length > 0 && (
                    <Card className="bg-red-50 p-4 dark:bg-red-950/20">
                        <p className="mb-2 text-sm font-medium text-red-800 dark:text-red-300">
                            Corrige los siguientes errores:
                        </p>
                        <ul className="list-disc space-y-1 pl-5 text-sm text-red-700 dark:text-red-400">
                            {Object.entries(errors).map(([key, msg]) => (
                                <li key={key}>
                                    <span className="font-mono text-xs">{key}</span>: {msg as string}
                                </li>
                            ))}
                        </ul>
                    </Card>
                )}

                <div className="flex justify-end gap-3 border-t pt-4">
                    <Button variant="ghost" asChild>
                        <Link href="/admin/empresas">Cancelar</Link>
                    </Button>
                    <Button type="submit" disabled={processing}>
                        {editando ? 'Guardar cambios' : 'Registrar empresa'}
                    </Button>
                </div>
            </form>
        </AppLayout>
    );
}
