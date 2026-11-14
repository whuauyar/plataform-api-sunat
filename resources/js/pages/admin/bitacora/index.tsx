import { Head, router } from '@inertiajs/react';
import { type ColumnDef } from '@tanstack/react-table';
import { useRef, useState } from 'react';

import { History } from '@/lib/icons';
import AppLayout from '@/layouts/app-layout';
import { Badge } from '@/components/ui/badge';
import { DataTable } from '@/components/ui/data-table';
import { Pagination, type PaginationLink } from '@/components/ui/pagination';
import type { BreadcrumbItem } from '@/types';

type Registro = {
    id: number;
    accion: string;
    actor_tipo: string;
    actor: string | null;
    descripcion: string | null;
    empresa: string | null;
    tenant_id: number | null;
    ip: string | null;
    created_at: string | null;
};

type Paginacion<T> = {
    data: T[];
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
    from: number | null;
    to: number | null;
    links: PaginationLink[];
};

type Props = {
    logs: Paginacion<Registro>;
    filtros: { buscar: string; accion: string; actor_tipo: string };
};

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Administración', href: '#' },
    { title: 'Bitácora', href: '/admin/bitacora' },
];

const fecha = (value: string | null) =>
    value ? new Date(value.replace(' ', 'T')).toLocaleString('es-PE', { dateStyle: 'short', timeStyle: 'short' }) : '—';

const actorVariant = (tipo: string): 'default' | 'secondary' | 'outline' =>
    tipo === 'usuario' ? 'default' : tipo === 'api' ? 'secondary' : 'outline';

export default function BitacoraIndex({ logs, filtros }: Props) {
    const [busqueda, setBusqueda] = useState(filtros?.buscar ?? '');
    const debounceRef = useRef<ReturnType<typeof setTimeout> | null>(null);

    const onBuscar = (valor: string) => {
        setBusqueda(valor);
        if (debounceRef.current) clearTimeout(debounceRef.current);
        debounceRef.current = setTimeout(() => {
            router.get(
                '/admin/bitacora',
                { buscar: valor || undefined },
                { preserveState: true, preserveScroll: true, replace: true, only: ['logs', 'filtros'] },
            );
        }, 350);
    };

    const columns: ColumnDef<Registro>[] = [
        {
            accessorKey: 'accion',
            header: 'Acción',
            meta: { label: 'Acción', primary: true },
            cell: ({ row }) => (
                <Badge variant="outline" className="font-mono text-xs">
                    {row.original.accion}
                </Badge>
            ),
        },
        {
            accessorKey: 'created_at',
            header: 'Fecha',
            meta: { label: 'Fecha' },
            cell: ({ row }) => <span className="whitespace-nowrap text-sm">{fecha(row.original.created_at)}</span>,
        },
        {
            accessorKey: 'empresa',
            header: 'Empresa',
            meta: { label: 'Empresa' },
            cell: ({ row }) => <span className="text-sm">{row.original.empresa ?? '—'}</span>,
        },
        {
            accessorKey: 'actor',
            header: 'Actor',
            meta: { label: 'Actor' },
            cell: ({ row }) => (
                <div className="flex items-center gap-2">
                    <Badge variant={actorVariant(row.original.actor_tipo)} className="text-xs capitalize">
                        {row.original.actor_tipo}
                    </Badge>
                    <span className="text-muted-foreground text-sm">{row.original.actor ?? '—'}</span>
                </div>
            ),
        },
        {
            accessorKey: 'descripcion',
            header: 'Detalle',
            meta: { label: 'Detalle' },
            cell: ({ row }) => <span className="text-sm">{row.original.descripcion ?? '—'}</span>,
        },
        {
            accessorKey: 'ip',
            header: 'IP',
            meta: { label: 'IP' },
            cell: ({ row }) => <span className="text-muted-foreground font-mono text-xs">{row.original.ip ?? '—'}</span>,
        },
    ];

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Bitácora" />

            <div className="flex flex-1 flex-col gap-6 p-4">
                <div className="flex items-center gap-3">
                    <div className="text-primary flex size-10 items-center justify-center rounded-lg">
                        <History className="size-5" />
                    </div>
                    <div>
                        <h1 className="text-xl font-semibold tracking-tight">Bitácora</h1>
                        <p className="text-muted-foreground text-sm">
                            Registro de acciones sensibles (credenciales, anulaciones, configuración, equipo).
                        </p>
                    </div>
                </div>

                <DataTable
                    columns={columns}
                    data={logs.data}
                    indexOffset={(logs.from ?? 1) - 1}
                    manualPagination
                    searchValue={busqueda}
                    onSearchChange={onBuscar}
                    searchPlaceholder="Buscar por acción, detalle o actor..."
                    emptyMessage="No hay acciones registradas."
                    compactBreakpoint="xl"
                />

                <Pagination links={logs.links} from={logs.from} to={logs.to} total={logs.total} />
            </div>
        </AppLayout>
    );
}
