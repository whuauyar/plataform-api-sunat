import { Head, router, useForm } from '@inertiajs/react';
import { Building2, Globe, Image as ImageIcon, Infinity as InfinityIcon, Loader2, Search, Settings2, Sparkles, Trash2 } from '@/lib/icons';
import { useState } from 'react';
import AppLayout from '@/layouts/app-layout';
import { FormSelect } from '@/components/sunat/form-select';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Label } from '@/components/ui/label';
import { PasswordInput } from '@/components/ui/password-input';
import { Switch } from '@/components/ui/switch';
import type { BreadcrumbItem } from '@/types';

type Config = {
    emision_ilimitada_global: boolean;
    nuevas_empresas_ilimitadas: boolean;
    consulta_token_set: boolean;
    consulta_proveedor: string;
};

const PROVEEDORES_CONSULTA = [
    { value: 'json', label: 'api.json.pe' },
    { value: 'migo', label: 'api.migo.pe' },
];

type Stats = {
    empresas_total: number;
    empresas_ilimitadas: number;
    empresas_por_plan: number;
};

type Marca = { app_logo_url: string | null; favicon_url: string | null };

type Props = { config: Config; stats: Stats; marca: Marca };

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Administración', href: '#' },
    { title: 'Configuración', href: '/admin/configuracion' },
];

const Stat = ({ label, value, icon: Icon }: { label: string; value: number; icon: typeof Building2 }) => (
    <Card className="flex items-center gap-3 p-4">
        <div className="text-primary flex size-10 items-center justify-center rounded-lg">
            <Icon className="size-5" />
        </div>
        <div>
            <div className="text-2xl font-semibold leading-none">{value}</div>
            <div className="text-muted-foreground text-xs">{label}</div>
        </div>
    </Card>
);

export default function ConfiguracionIndex({ config, stats, marca }: Props) {
    const { data, setData, put, processing } = useForm({
        emision_ilimitada_global: config.emision_ilimitada_global,
        nuevas_empresas_ilimitadas: config.nuevas_empresas_ilimitadas,
    });

    const submit = (e: React.FormEvent) => {
        e.preventDefault();
        put('/admin/configuracion', { preserveScroll: true });
    };

    // Marca de la plataforma (logo + favicon). Form multipart aparte del principal.
    const brandingForm = useForm<{ app_logo: File | null; favicon: File | null }>({
        app_logo: null,
        favicon: null,
    });
    const [logoPreview, setLogoPreview] = useState<string | null>(null);
    const [faviconPreview, setFaviconPreview] = useState<string | null>(null);

    const guardarBranding = (e: React.FormEvent) => {
        e.preventDefault();
        brandingForm.post('/admin/configuracion/branding', {
            forceFormData: true,
            preserveScroll: true,
            onSuccess: () => {
                brandingForm.reset();
                setLogoPreview(null);
                setFaviconPreview(null);
            },
        });
    };

    const quitarBranding = (cual: 'logo' | 'favicon') => {
        router.post(
            '/admin/configuracion/branding',
            cual === 'logo' ? { eliminar_logo: true } : { eliminar_favicon: true },
            { forceFormData: true, preserveScroll: true },
        );
    };

    const [token, setToken] = useState('');
    const [proveedor, setProveedor] = useState(config.consulta_proveedor || 'json');
    const [savingToken, setSavingToken] = useState(false);
    const guardarToken = () => {
        setSavingToken(true);
        router.put('/admin/configuracion/consulta-token', { consulta_token: token, consulta_proveedor: proveedor }, {
            preserveScroll: true,
            onSuccess: () => setToken(''),
            onFinish: () => setSavingToken(false),
        });
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Configuración de emisión" />

            <form onSubmit={submit} className="mx-auto flex w-full max-w-3xl flex-1 flex-col gap-6 p-4">
                <div className="flex items-center gap-3">
                    <div className="text-primary flex size-10 items-center justify-center rounded-lg">
                        <Settings2 className="size-5" />
                    </div>
                    <div>
                        <h1 className="text-xl font-semibold tracking-tight">Configuración de emisión</h1>
                        <p className="text-muted-foreground text-sm">
                            Controla globalmente si las empresas emiten con o sin límites.
                        </p>
                    </div>
                </div>

                {/* Estadísticas rápidas */}
                <div className="grid gap-3 sm:grid-cols-3">
                    <Stat label="Empresas totales" value={stats.empresas_total} icon={Building2} />
                    <Stat label="Con emisión ilimitada" value={stats.empresas_ilimitadas} icon={InfinityIcon} />
                    <Stat label="Controladas por plan" value={stats.empresas_por_plan} icon={Sparkles} />
                </div>

                {/* Switch 1: global */}
                <Card className="p-6">
                    <div className="flex items-start justify-between gap-4">
                        <div className="flex items-start gap-3">
                            <div className="text-primary flex size-10 shrink-0 items-center justify-center rounded-lg">
                                <Globe className="size-5" />
                            </div>
                            <div>
                                <h3 className="text-base font-semibold leading-tight">
                                    Emisión ilimitada global
                                </h3>
                                <p className="text-muted-foreground mt-1 text-sm">
                                    Si lo activas, <strong>todas las empresas</strong> emiten comprobantes sin
                                    ningún límite, sin importar su plan. Tiene la máxima prioridad.
                                </p>
                            </div>
                        </div>
                        <Switch
                            checked={data.emision_ilimitada_global}
                            onCheckedChange={(v) => setData('emision_ilimitada_global', v)}
                        />
                    </div>

                    {data.emision_ilimitada_global && (
                        <div className="mt-4 rounded-md border border-amber-300 bg-amber-50 px-4 py-3 text-sm text-amber-900 dark:border-amber-700/50 dark:bg-amber-950/20 dark:text-amber-300">
                            <strong>Modo ilimitado global activo.</strong> Los planes y suscripciones quedan en
                            pausa para el control de emisión mientras esto esté encendido.
                        </div>
                    )}
                </Card>

                {/* Switch 2: default nuevas empresas */}
                <Card className="p-6">
                    <div className="flex items-start justify-between gap-4">
                        <div className="flex items-start gap-3">
                            <div className="text-primary flex size-10 shrink-0 items-center justify-center rounded-lg">
                                <Sparkles className="size-5" />
                            </div>
                            <div>
                                <h3 className="text-base font-semibold leading-tight">
                                    Nuevas empresas ilimitadas por defecto
                                </h3>
                                <p className="text-muted-foreground mt-1 text-sm">
                                    Cada empresa que registres nacerá en modo <strong>ilimitado</strong>, sin
                                    necesidad de asignarle un plan. Podrás cambiarlo por empresa al registrarla.
                                </p>
                            </div>
                        </div>
                        <Switch
                            checked={data.nuevas_empresas_ilimitadas}
                            onCheckedChange={(v) => setData('nuevas_empresas_ilimitadas', v)}
                        />
                    </div>
                </Card>

                <div className="flex justify-end gap-3 border-t pt-4">
                    <Button type="submit" disabled={processing}>
                        Guardar configuración
                    </Button>
                </div>

                {/* Token global de consulta RUC/DNI */}
                <Card className="p-6">
                    <div className="flex items-start gap-3">
                        <div className="text-primary flex size-10 shrink-0 items-center justify-center rounded-lg">
                            <Search className="size-5" />
                        </div>
                        <div className="flex-1">
                            <h3 className="text-base font-semibold leading-tight">Consulta RUC / DNI</h3>
                            <p className="text-muted-foreground mt-1 text-sm">
                                Se usa para <strong>traer los datos reales</strong> (razón social, dirección, ubigeo)
                                al registrar una nueva empresa. Es la configuración global del panel admin.
                            </p>
                            <div className="mt-4 grid gap-2 sm:max-w-xl">
                                <Label>Proveedor</Label>
                                <FormSelect value={proveedor} onChange={setProveedor} options={PROVEEDORES_CONSULTA} />
                                <Label htmlFor="consulta_token" className="mt-2">Token</Label>
                                <PasswordInput
                                    id="consulta_token"
                                    value={token}
                                    onChange={(e) => setToken(e.target.value)}
                                    placeholder={config.consulta_token_set ? '•••••••• (token configurado)' : `Pega aquí tu token de ${proveedor === 'migo' ? 'api.migo.pe' : 'api.json.pe'}`}
                                    autoComplete="off"
                                />
                                <p className="text-muted-foreground text-xs">
                                    {config.consulta_token_set
                                        ? 'Ya hay un token guardado. Escribe uno nuevo para reemplazarlo (déjalo vacío para conservarlo y solo cambiar el proveedor). Cada proveedor usa su propio token.'
                                        : 'Consíguelo en el proveedor elegido. Se guarda una sola vez y sirve para todas las altas de empresa.'}
                                </p>
                                <div>
                                    <Button type="button" variant="outline" disabled={savingToken} onClick={guardarToken}>
                                        {savingToken && <Loader2 className="mr-2 size-4 animate-spin" />}
                                        Guardar
                                    </Button>
                                </div>
                            </div>
                        </div>
                    </div>
                </Card>
            </form>

            {/* Marca de la plataforma — form multipart independiente (logo + favicon). */}
            <form onSubmit={guardarBranding} className="mx-auto flex w-full max-w-3xl flex-col gap-6 px-4 pb-10">
                <Card className="p-6">
                    <div className="flex items-start gap-3">
                        <div className="text-primary flex size-10 shrink-0 items-center justify-center rounded-lg">
                            <ImageIcon className="size-5" />
                        </div>
                        <div className="flex-1">
                            <h3 className="text-base font-semibold leading-tight">Marca de la plataforma</h3>
                            <p className="text-muted-foreground mt-1 text-sm">
                                Personaliza el <strong>logo</strong> (sidebar, login y registro) y el{' '}
                                <strong>favicon</strong> (icono de pestaña). Si no subes imagen, se usa la marca por defecto.
                            </p>

                            {/* Logo */}
                            <div className="mt-5 grid gap-2">
                                <Label htmlFor="app_logo">Logo (sidebar / login / registro)</Label>
                                <div className="flex items-center gap-4">
                                    <div className="flex size-16 shrink-0 items-center justify-center overflow-hidden rounded-lg border bg-muted/30">
                                        {logoPreview || marca.app_logo_url ? (
                                            <img src={logoPreview ?? marca.app_logo_url ?? undefined} alt="Logo" className="size-16 object-contain" />
                                        ) : (
                                            <ImageIcon className="text-muted-foreground size-6" />
                                        )}
                                    </div>
                                    <div className="flex min-w-0 flex-col gap-1">
                                        <input
                                            id="app_logo"
                                            type="file"
                                            accept="image/png,image/jpeg,image/webp,image/svg+xml"
                                            onChange={(e) => {
                                                const f = e.target.files?.[0] ?? null;
                                                brandingForm.setData('app_logo', f);
                                                setLogoPreview(f ? URL.createObjectURL(f) : null);
                                            }}
                                            className="text-sm file:mr-3 file:rounded-md file:border-0 file:bg-muted file:px-3 file:py-1.5 file:text-sm file:font-medium hover:file:bg-muted/70"
                                        />
                                        <span className="text-muted-foreground text-xs">PNG, JPG, WebP o SVG · máx 2 MB · recomendado 512×512.</span>
                                        {marca.app_logo_url && (
                                            <button type="button" onClick={() => quitarBranding('logo')} className="inline-flex w-fit items-center gap-1 text-xs font-medium text-red-600 hover:underline">
                                                <Trash2 className="size-3.5" /> Quitar logo
                                            </button>
                                        )}
                                    </div>
                                </div>
                                {brandingForm.errors.app_logo && <p className="text-xs text-red-600">{brandingForm.errors.app_logo}</p>}
                            </div>

                            {/* Favicon */}
                            <div className="mt-6 grid gap-2">
                                <Label htmlFor="favicon">Favicon (icono de pestaña)</Label>
                                <div className="flex items-center gap-4">
                                    <div className="flex size-16 shrink-0 items-center justify-center overflow-hidden rounded-lg border bg-muted/30">
                                        {faviconPreview || marca.favicon_url ? (
                                            <img src={faviconPreview ?? marca.favicon_url ?? undefined} alt="Favicon" className="size-10 object-contain" />
                                        ) : (
                                            <ImageIcon className="text-muted-foreground size-6" />
                                        )}
                                    </div>
                                    <div className="flex min-w-0 flex-col gap-1">
                                        <input
                                            id="favicon"
                                            type="file"
                                            accept="image/png,image/svg+xml,image/x-icon,image/vnd.microsoft.icon,image/webp"
                                            onChange={(e) => {
                                                const f = e.target.files?.[0] ?? null;
                                                brandingForm.setData('favicon', f);
                                                setFaviconPreview(f ? URL.createObjectURL(f) : null);
                                            }}
                                            className="text-sm file:mr-3 file:rounded-md file:border-0 file:bg-muted file:px-3 file:py-1.5 file:text-sm file:font-medium hover:file:bg-muted/70"
                                        />
                                        <span className="text-muted-foreground text-xs">PNG, SVG, ICO o WebP · máx 1 MB · recomendado 512×512 (cuadrado).</span>
                                        {marca.favicon_url && (
                                            <button type="button" onClick={() => quitarBranding('favicon')} className="inline-flex w-fit items-center gap-1 text-xs font-medium text-red-600 hover:underline">
                                                <Trash2 className="size-3.5" /> Quitar favicon
                                            </button>
                                        )}
                                    </div>
                                </div>
                                {brandingForm.errors.favicon && <p className="text-xs text-red-600">{brandingForm.errors.favicon}</p>}
                            </div>

                            <div className="mt-6">
                                <Button type="submit" disabled={brandingForm.processing}>
                                    {brandingForm.processing && <Loader2 className="mr-2 size-4 animate-spin" />}
                                    Guardar marca
                                </Button>
                            </div>
                        </div>
                    </div>
                </Card>
            </form>
        </AppLayout>
    );
}
