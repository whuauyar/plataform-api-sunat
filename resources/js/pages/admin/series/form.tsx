import { Head, Link, useForm } from '@inertiajs/react';
import { useRef } from 'react';
import { ArrowLeft } from '@/lib/icons';
import AppLayout from '@/layouts/app-layout';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import { Combobox } from '@/components/ui/combobox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import type { BreadcrumbItem } from '@/types';

type Serie = {
    id: number | null;
    tipo_documento: string;
    serie: string;
    correlativo: number;
    sucursal_id: number | null;
    is_active: boolean;
};

type Props = {
    tenant: { id: number; ruc: string; razon_social: string };
    serie: Serie;
    sucursales: { id: number; nombre: string; cod_local: string }[];
    prefijos: Record<string, string[]>;
    modo: 'crear' | 'editar';
    /** Último paso del alta guiada: al guardar finaliza y vuelve a la empresa. */
    onboarding?: boolean;
};

/**
 * Prefijo por defecto de la serie según el tipo de documento (Cat. 61 SUNAT).
 * Al elegir el tipo, la serie se autocompleta con este prefijo y el foco cae en
 * el campo para que solo se escriba el correlativo. Ej: Factura → F | NC → FC.
 */
const PREFIJO_DEFECTO: Record<string, string> = {
    '01': 'F',
    '03': 'B',
    '07': 'FC',
    '08': 'FD',
    '09': 'T',
    '31': 'V',
    '20': 'R',
    '40': 'P',
};

const TIPO_LABEL: Record<string, string> = {
    '01': 'Factura (01) - F',
    '03': 'Boleta (03) - B',
    '07': 'Nota de Crédito (07) - F/B',
    '08': 'Nota de Débito (08) - F/B',
    '09': 'Guía Remitente (09) - T',
    '31': 'Guía Transportista (31) - V',
    '20': 'Retención (20) - R',
    '40': 'Percepción (40) - P',
};

const breadcrumbs = (razon: string, id: number, modo: 'crear' | 'editar'): BreadcrumbItem[] => [
    { title: 'Administración', href: '#' },
    { title: 'Empresas', href: '/admin/empresas' },
    { title: razon, href: `/admin/empresas/${id}` },
    { title: 'Series', href: `/admin/empresas/${id}/series` },
    { title: modo === 'crear' ? 'Nueva' : 'Editar', href: '#' },
];

/** Marca de campo obligatorio: (*) en rojo, igual que en el form de empresa. */
const Req = () => <span className="font-bold text-[#EF233C]">(*)</span>;

export default function SeriesForm({ tenant, serie, sucursales, prefijos, modo, onboarding = false }: Props) {
    const editando = modo === 'editar';
    const { data, setData, post, put, processing, errors } = useForm({
        tipo_documento: serie.tipo_documento,
        // Al crear, arranca con el prefijo del tipo por defecto (F para factura…).
        serie: serie.serie || (modo === 'crear' ? (PREFIJO_DEFECTO[serie.tipo_documento] ?? '') : ''),
        correlativo: serie.correlativo,
        sucursal_id: serie.sucursal_id ?? '',
        is_active: serie.is_active,
        // Viaja al store para cerrar el flujo empresa → sucursal → serie.
        onboarding,
    });

    const serieRef = useRef<HTMLInputElement>(null);
    const acabaDeElegirTipo = useRef(false);

    // Al cambiar el tipo, autocompleta la serie con su prefijo. El foco se mueve
    // al input en onCloseAutoFocus (cuando el desplegable se cierra), porque si
    // se hiciera aquí Radix devuelve el foco a su propio trigger al cerrarse.
    const cambiarTipo = (tipo: string) => {
        setData('tipo_documento', tipo);
        setData('serie', PREFIJO_DEFECTO[tipo] ?? '');
        acabaDeElegirTipo.current = true;
    };

    // Se dispara al cerrar el desplegable de tipo. Si se acaba de elegir un tipo,
    // evita que Radix reenfoque su trigger y pone el foco en la serie (cursor al
    // final) para escribir directo el correlativo. Si se cerró sin elegir, foco normal.
    const alCerrarTipo = (e: Event) => {
        if (!acabaDeElegirTipo.current) return;
        acabaDeElegirTipo.current = false;
        e.preventDefault();
        const el = serieRef.current;
        if (!el) return;
        el.focus();
        const fin = el.value.length;
        el.setSelectionRange(fin, fin);
    };

    const submit = (e: React.FormEvent) => {
        e.preventDefault();
        if (editando && serie.id) put(`/admin/empresas/${tenant.id}/series/${serie.id}`);
        else post(`/admin/empresas/${tenant.id}/series`);
    };

    const prefijosValidos = prefijos[data.tipo_documento] ?? [];

    return (
        <AppLayout breadcrumbs={breadcrumbs(tenant.razon_social, tenant.id, modo)}>
            <Head title={editando ? 'Editar serie' : 'Nueva serie'} />

            <form onSubmit={submit} className="flex flex-1 flex-col gap-4 p-4">
                <div className="flex items-center justify-between">
                    <div>
                        <h1 className="text-xl font-semibold tracking-tight">
                            {editando ? 'Editar serie' : 'Nueva serie'}
                        </h1>
                        <p className="text-sm text-muted-foreground">{tenant.razon_social}</p>
                    </div>
                    <Button variant="ghost" asChild>
                        <Link href={onboarding ? `/admin/empresas/${tenant.id}` : `/admin/empresas/${tenant.id}/series`}>
                            <ArrowLeft className="size-4" />
                            {onboarding ? 'Volver a la empresa' : 'Volver'}
                        </Link>
                    </Button>
                </div>

                <Card className="max-w-2xl p-6">
                    <div className="grid gap-4 md:grid-cols-2">
                        <div>
                            <Label htmlFor="tipo_documento">Tipo de documento <Req /></Label>
                            <Combobox
                                value={data.tipo_documento}
                                onChange={cambiarTipo}
                                onCloseAutoFocus={alCerrarTipo}
                                options={Object.entries(TIPO_LABEL).map(([code, label]) => ({ value: code, label }))}
                                disabled={editando}
                                className={editando ? 'opacity-70' : ''}
                            />
                            {editando && (
                                <p className="mt-1 text-xs text-muted-foreground">
                                    El tipo no se puede cambiar en una serie existente.
                                </p>
                            )}
                        </div>

                        <div>
                            <Label htmlFor="serie">Serie <Req /></Label>
                            <Input
                                ref={serieRef}
                                id="serie"
                                value={data.serie}
                                onChange={(e) => setData('serie', e.target.value.toUpperCase())}
                                required
                                maxLength={4}
                                minLength={4}
                                pattern="[A-Z][A-Z0-9]{3}"
                                readOnly={editando}
                                className={`font-mono uppercase ${editando ? 'bg-muted' : ''}`}
                                placeholder="F001"
                            />
                            {errors.serie && <p className="mt-1 text-xs text-red-600">{errors.serie}</p>}
                            <p className="mt-1 text-xs text-muted-foreground">
                                Prefijo válido: {prefijosValidos.join(' o ')}
                            </p>
                        </div>

                        <div>
                            <Label htmlFor="correlativo">Número inicial</Label>
                            <Input
                                id="correlativo"
                                type="number"
                                value={data.correlativo}
                                onChange={(e) => setData('correlativo', Number(e.target.value))}
                                min={1}
                                className="font-mono"
                                placeholder="1"
                            />
                            {errors.correlativo ? (
                                <p className="mt-1 text-xs text-red-600">{errors.correlativo}</p>
                            ) : (
                                <p className="mt-1 text-xs text-muted-foreground">
                                    El primer comprobante usará este número. Ej: 1 → {data.serie || 'F001'}-1.
                                </p>
                            )}
                        </div>

                        <div>
                            <Label htmlFor="sucursal_id">Sucursal</Label>
                            <Combobox
                                value={String(data.sucursal_id)}
                                onChange={(v) => setData('sucursal_id', v)}
                                options={[
                                    { value: '', label: 'Sin asignar' },
                                    ...sucursales.map((s) => ({ value: String(s.id), label: `${s.nombre} (${s.cod_local})` })),
                                ]}
                                placeholder="Sin asignar"
                                searchable
                            />
                        </div>

                        <label className="md:col-span-2 inline-flex items-center gap-2 text-sm">
                            <Checkbox
                                checked={data.is_active}
                                onCheckedChange={(v) => setData('is_active', v === true)}
                            />
                            Serie activa
                        </label>
                    </div>

                    <div className="mt-6 flex justify-end gap-3 border-t pt-4">
                        <Button variant="ghost" asChild>
                            <Link href={onboarding ? `/admin/empresas/${tenant.id}` : `/admin/empresas/${tenant.id}/series`}>
                                {onboarding ? 'Volver a la empresa' : 'Cancelar'}
                            </Link>
                        </Button>
                        <Button type="submit" disabled={processing}>
                            {editando ? 'Guardar cambios' : onboarding ? 'Crear y agregar otra' : 'Crear serie'}
                        </Button>
                    </div>
                </Card>
            </form>
        </AppLayout>
    );
}
