import * as React from 'react';
import {
    type Column,
    type ColumnDef,
    type ColumnFiltersState,
    type FilterFn,
    type SortingState,
    flexRender,
    getCoreRowModel,
    getFilteredRowModel,
    getPaginationRowModel,
    getSortedRowModel,
    useReactTable,
} from '@tanstack/react-table';
import { ArrowUpDown, ChevronDown, ChevronLeft, ChevronRight, ChevronUp, Inbox, Plus, Search, X } from '@/lib/icons';
import { Button } from '@/components/ui/button';
import { Combobox } from '@/components/ui/combobox';
import { Input } from '@/components/ui/input';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { cn } from '@/lib/utils';

/**
 * Metadata opcional por columna.
 * - label:      etiqueta que se muestra en el detalle cuando la columna se colapsa.
 * - primary:    columna "título" del registro; es la última en colapsarse.
 * - hideLabel:  columna de acciones (sin etiqueta); siempre visible, nunca colapsa.
 * - alignRight: alinea a la derecha (acciones/montos).
 * - prioridad:  cuál se colapsa primero cuando falta lugar (menor = aguanta más).
 * - anchoMaximo: tope de ancho (px) para columnas de texto que pueden truncarse.
 */
export type ColumnMeta = {
    label?: string;
    primary?: boolean;
    hideLabel?: boolean;
    alignRight?: boolean;
    prioridad?: number;
    anchoMaximo?: number;
};

/**
 * Filtro declarativo (client-side, en tiempo real).
 * - 'select':    desplegable de valor exacto sobre una columna (estado, tipo…).
 * - 'dateRange': rango desde/hasta sobre una columna de fecha (YYYY-MM-DD).
 */
export type FacetFilter =
    | { type: 'select'; column: string; label: string; options: { value: string; label: string }[] }
    | { type: 'dateRange'; column: string; label: string };

const equalsFilterFn: FilterFn<unknown> = (row, columnId, value) =>
    value == null || value === '' ? true : String(row.getValue(columnId)) === String(value);

const dateRangeFilterFn: FilterFn<unknown> = (row, columnId, value) => {
    const v = value as { from?: string; to?: string } | undefined;
    if (!v || (!v.from && !v.to)) return true;
    const d = String(row.getValue(columnId) ?? '').slice(0, 10);
    if (!d) return false;
    if (v.from && d < v.from) return false;
    if (v.to && d > v.to) return false;
    return true;
};

const PAGE_SIZES = [10, 25, 50, 100];

// ── Ajuste responsive por medición (estilo api-sunat-descarga) ──────────────
/** Lo que mide el botón "+" con su celda. Se reserva antes de repartir. */
const ANCHO_EXPANSOR = 44;
/** Margen libre al repartir: los anchos medidos se redondean hacia arriba. */
const MARGEN_REDONDEO = 8;
/** Sin prioridad declarada, la columna es la última en la cola. */
const PRIORIDAD_POR_OMISION = 10_000;
/** Tope por defecto de una columna de texto truncable (≈15rem, igual que antes). */
const TOPE_TEXTO = 240;

type DataTableProps<TData, TValue> = {
    columns: ColumnDef<TData, TValue>[];
    data: TData[];
    searchPlaceholder?: string;
    searchable?: boolean;
    /** Filas por página inicial. Default: 10 */
    pageSize?: number;
    /** Muestra la columna "N°" con numeración correlativa. Default: true */
    showIndex?: boolean;
    emptyMessage?: string;
    toolbar?: React.ReactNode;
    /** Paginación gestionada por el servidor (+ <Pagination> externo). */
    manualPagination?: boolean;
    /** Offset de numeración para tablas server-side (ej. `from - 1`) → N° global. */
    indexOffset?: number;
    /** Búsqueda server-side controlada por el padre. */
    searchValue?: string;
    onSearchChange?: (value: string) => void;
    /** Filtros declarativos client-side. */
    filters?: FacetFilter[];
    /** Muestra skeleton de carga. */
    loading?: boolean;
    /** @deprecated El ajuste ahora es por medición; se mantiene por compatibilidad. */
    compactBreakpoint?: 'lg' | 'xl' | '2xl';
};

/** El nombre legible de una columna, para el detalle colapsado. */
function nombreColumna<TData, TValue>(column: Column<TData, TValue>): string {
    const meta = (column.columnDef.meta as ColumnMeta) ?? {};
    if (meta.label) return meta.label;
    const header = column.columnDef.header;
    if (typeof header === 'string' && header !== '') return header;
    return column.id;
}

/** El ancho real del contenedor (cambia con la ventana y con el marco/sidebar). */
function useAnchoDisponible(ref: React.RefObject<HTMLDivElement | null>): number {
    const [ancho, setAncho] = React.useState(0);
    React.useLayoutEffect(() => {
        const nodo = ref.current;
        if (!nodo) return;
        setAncho(nodo.getBoundingClientRect().width);
        const obs = new ResizeObserver((e) => {
            const w = e[0]?.contentRect.width;
            if (w !== undefined) setAncho(w);
        });
        obs.observe(nodo);
        return () => obs.disconnect();
    }, [ref]);
    return ancho;
}

/** Cuántas veces terminó de cargar una fuente: dispara re-medición con la tipografía real. */
function useFuentesCargadas(): number {
    const [cargas, setCargas] = React.useState(0);
    React.useEffect(() => {
        const fuentes = typeof document !== 'undefined' ? document.fonts : undefined;
        if (!fuentes) return;
        let vigente = true;
        const volver = () => { if (vigente) setCargas((n) => n + 1); };
        void fuentes.ready.then(volver);
        fuentes.addEventListener('loadingdone', volver);
        return () => { vigente = false; fuentes.removeEventListener('loadingdone', volver); };
    }, []);
    return cargas;
}

export function DataTable<TData, TValue>({
    columns,
    data,
    searchPlaceholder = 'Buscar...',
    searchable = true,
    pageSize = 10,
    showIndex = true,
    emptyMessage = 'No hay registros disponibles',
    toolbar,
    manualPagination = false,
    indexOffset = 0,
    searchValue,
    onSearchChange,
    filters,
    loading = false,
}: DataTableProps<TData, TValue>) {
    const [sorting, setSorting] = React.useState<SortingState>([]);
    const [globalFilter, setGlobalFilter] = React.useState('');
    const [columnFilters, setColumnFilters] = React.useState<ColumnFiltersState>([]);
    const [expanded, setExpanded] = React.useState<Record<string, boolean>>({});
    const serverSearch = onSearchChange !== undefined;

    const contenedor = React.useRef<HTMLDivElement>(null);
    const anchoDisponible = useAnchoDisponible(contenedor);

    const columnsConIndice = React.useMemo<ColumnDef<TData, TValue>[]>(() => {
        const conFiltros = filters?.length
            ? columns.map((c) => {
                  const id = (c as { id?: string; accessorKey?: string }).id ?? (c as { accessorKey?: string }).accessorKey;
                  const f = filters.find((flt) => flt.column === id);
                  if (!f) return c;
                  return {
                      ...c,
                      enableColumnFilter: true,
                      filterFn: f.type === 'dateRange' ? dateRangeFilterFn : equalsFilterFn,
                  } as ColumnDef<TData, TValue>;
              })
            : columns;

        if (!showIndex) return conFiltros;

        const indexCol: ColumnDef<TData, TValue> = {
            id: '__index',
            header: 'N°',
            enableSorting: false,
            enableGlobalFilter: false,
            enableColumnFilter: false,
            meta: { label: 'N°' } as ColumnMeta,
            cell: ({ row, table }) => {
                const pageRows = table.getRowModel().rows;
                const pos = pageRows.findIndex((r) => r.id === row.id);
                const { pageIndex, pageSize: ps } = table.getState().pagination;
                const base = manualPagination ? indexOffset : pageIndex * ps;
                return <span className="text-muted-foreground tabular-nums">{base + pos + 1}</span>;
            },
        };
        return [indexCol, ...conFiltros];
    }, [columns, showIndex, filters, manualPagination, indexOffset]);

    const table = useReactTable({
        data,
        columns: columnsConIndice,
        state: { sorting, globalFilter, columnFilters },
        onSortingChange: setSorting,
        onGlobalFilterChange: setGlobalFilter,
        onColumnFiltersChange: setColumnFilters,
        getCoreRowModel: getCoreRowModel(),
        getSortedRowModel: getSortedRowModel(),
        getFilteredRowModel: getFilteredRowModel(),
        ...(manualPagination ? {} : { getPaginationRowModel: getPaginationRowModel() }),
        initialState: { pagination: { pageSize } },
    });

    const rows = table.getRowModel().rows;
    const todas = table.getVisibleLeafColumns();
    const encabezados = table.getHeaderGroups().flatMap((g) => g.headers);
    const hayBusqueda = (serverSearch ? (searchValue ?? '') : globalFilter) !== '';
    const hayFiltrosActivos = columnFilters.length > 0 || (!serverSearch && globalFilter !== '');

    const limpiarFiltros = () => {
        setColumnFilters([]);
        if (!serverSearch) setGlobalFilter('');
    };

    const vacioMsg = hayBusqueda || hayFiltrosActivos ? 'No se encontraron resultados para tu búsqueda.' : emptyMessage;

    // ── Columnas de control: N° y acciones. Siempre visibles, nunca colapsan. ──
    const esDeControl = React.useCallback((c: Column<TData, unknown>) => {
        const meta = (c.columnDef.meta as ColumnMeta) ?? {};
        return c.id === '__index' || !!meta.hideLabel;
    }, []);

    const prioridadDe = (c: Column<TData, unknown>) => {
        const meta = (c.columnDef.meta as ColumnMeta) ?? {};
        if (meta.prioridad !== undefined) return meta.prioridad;
        if (meta.primary) return 0;
        return PRIORIDAD_POR_OMISION;
    };

    // ── 1 · MEDIR el ancho natural de cada columna con una copia invisible ──
    const fuentes = useFuentesCargadas();
    const firma = `${todas.map((c) => c.id).join('|')}#${rows.map((f) => f.id).join('|')}#${fuentes}`;
    const [medida, setMedida] = React.useState<{ firma: string; anchos: Record<string, number> } | null>(null);
    const espejo = React.useRef<HTMLTableElement>(null);
    const hayQueMedir = rows.length > 0 && medida?.firma !== firma;

    React.useLayoutEffect(() => {
        const nodo = espejo.current;
        if (!hayQueMedir || !nodo) return;
        const anchos: Record<string, number> = {};
        nodo.querySelectorAll<HTMLElement>('th[data-columna]').forEach((th) => {
            const id = th.dataset.columna;
            if (id) anchos[id] = Math.ceil(th.getBoundingClientRect().width);
        });
        setMedida({ firma, anchos });
    }, [hayQueMedir, firma]);

    // ── 2 · REPARTIR: qué columnas entran y cuáles pasan al detalle "+" ──
    const { visibles, ocultas } = React.useMemo(() => {
        const control = todas.filter(esDeControl);
        const candidatas = todas.filter((c) => !esDeControl(c));

        if (!medida || anchoDisponible === 0) {
            return { visibles: todas, ocultas: [] as typeof todas };
        }

        const anchoDe = (c: (typeof todas)[number]) => {
            const meta = (c.columnDef.meta as ColumnMeta) ?? {};
            const truncable = c.id !== '__index' && !meta.hideLabel && !meta.alignRight;
            const tope = meta.anchoMaximo ?? (truncable ? TOPE_TEXTO : Number.POSITIVE_INFINITY);
            return Math.min(medida.anchos[c.id] ?? 0, tope, anchoDisponible);
        };

        const reservadoControl = control.reduce((t, c) => t + anchoDe(c), 0);
        const porImportancia = candidatas
            .map((c, i) => ({ c, i }))
            .sort((a, b) => prioridadDe(a.c) - prioridadDe(b.c) || a.i - b.i)
            .map(({ c }) => c);

        const elegir = (conExpansor: boolean) => {
            const tope = anchoDisponible - reservadoControl - (conExpansor ? ANCHO_EXPANSOR : 0) - MARGEN_REDONDEO;
            const dentro = new Set<string>();
            let acumulado = 0;
            for (const columna of porImportancia) {
                const ancho = anchoDe(columna);
                if (dentro.size > 0 && acumulado + ancho > tope) break;
                dentro.add(columna.id);
                acumulado += ancho;
            }
            return dentro;
        };

        let dentro = elegir(false);
        if (dentro.size < candidatas.length) dentro = elegir(true);

        return {
            visibles: todas.filter((c) => esDeControl(c) || dentro.has(c.id)),
            ocultas: candidatas.filter((c) => !dentro.has(c.id)),
        };
    }, [todas, medida, anchoDisponible, esDeControl]);

    const hayOcultas = ocultas.length > 0;
    const idsOcultas = new Set(ocultas.map((c) => c.id));
    const totalCols = visibles.length + (hayOcultas ? 1 : 0);

    const anchoCelda = (c: Column<TData, unknown>): number | undefined => {
        const meta = (c.columnDef.meta as ColumnMeta) ?? {};
        const truncable = c.id !== '__index' && !meta.hideLabel && !meta.alignRight;
        if (!truncable) return undefined;
        return meta.anchoMaximo ?? TOPE_TEXTO;
    };

    return (
        <div className="flex flex-col gap-4" ref={contenedor}>
            {/* Toolbar: búsqueda + acciones */}
            {(searchable || toolbar) && (
                <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                    {searchable ? (
                        <div className="relative w-full sm:max-w-xs">
                            <Search className="text-muted-foreground pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2" />
                            <Input
                                value={serverSearch ? (searchValue ?? '') : globalFilter}
                                onChange={(e) => (serverSearch ? onSearchChange!(e.target.value) : setGlobalFilter(e.target.value))}
                                placeholder={searchPlaceholder}
                                className="h-10 pl-9"
                            />
                        </div>
                    ) : (
                        <div />
                    )}
                    {toolbar}
                </div>
            )}

            {/* Filtros declarativos */}
            {filters && filters.length > 0 && (
                <div className="flex flex-wrap items-end gap-2">
                    {filters.map((f) => {
                        const col = table.getColumn(f.column);
                        if (!col) return null;
                        if (f.type === 'select') {
                            const value = (col.getFilterValue() as string) ?? '';
                            return (
                                <div key={f.column} className="flex flex-col gap-1">
                                    <span className="text-[11px] font-medium uppercase tracking-wide text-muted-foreground">{f.label}</span>
                                    <Combobox value={value} onChange={(v) => col.setFilterValue(v || undefined)}
                                        options={[{ value: '', label: 'Todos' }, ...f.options]} searchable={f.options.length > 6} className="h-9 w-44" />
                                </div>
                            );
                        }
                        const rango = (col.getFilterValue() as { from?: string; to?: string } | undefined) ?? {};
                        const setRango = (patch: { from?: string; to?: string }) => {
                            const next = { ...rango, ...patch };
                            col.setFilterValue(next.from || next.to ? next : undefined);
                        };
                        return (
                            <div key={f.column} className="flex flex-col gap-1">
                                <span className="text-[11px] font-medium uppercase tracking-wide text-muted-foreground">{f.label}</span>
                                <div className="flex items-center gap-1">
                                    <Input type="date" value={rango.from ?? ''} onChange={(e) => setRango({ from: e.target.value })} className="h-9 w-[9.5rem]" />
                                    <span className="text-xs text-muted-foreground">a</span>
                                    <Input type="date" value={rango.to ?? ''} onChange={(e) => setRango({ to: e.target.value })} className="h-9 w-[9.5rem]" />
                                </div>
                            </div>
                        );
                    })}
                    {hayFiltrosActivos && (
                        <Button type="button" variant="ghost" size="sm" onClick={limpiarFiltros} className="h-9 gap-1 text-muted-foreground">
                            <X className="size-3.5" /> Limpiar
                        </Button>
                    )}
                </div>
            )}

            {/* ── Copia invisible que se mide (sin cortes de línea) ── */}
            {hayQueMedir && (
                <div aria-hidden="true" className="pointer-events-none invisible absolute left-[-99999px] top-0 w-max">
                    <table ref={espejo} className="w-max text-sm">
                        <thead>
                            <tr>
                                {todas.map((columna) => {
                                    const header = encabezados.find((h) => h.column.id === columna.id);
                                    return (
                                        <th key={columna.id} data-columna={columna.id}
                                            className="h-11 whitespace-nowrap px-4 text-left align-middle text-xs font-medium uppercase tracking-wide">
                                            {!header || header.isPlaceholder ? null : flexRender(columna.columnDef.header, header.getContext())}
                                        </th>
                                    );
                                })}
                            </tr>
                        </thead>
                        <tbody>
                            {rows.map((row) => (
                                <tr key={row.id}>
                                    {row.getVisibleCells().map((cell) => (
                                        <td key={cell.id} className="whitespace-nowrap p-4 align-middle">
                                            {flexRender(cell.column.columnDef.cell, cell.getContext())}
                                        </td>
                                    ))}
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            )}

            {/* ── La tabla (una sola; las columnas que no entran pasan al detalle "+") ── */}
            <div className="bg-card overflow-hidden rounded-xl shadow-soft">
                <Table className="table-fixed">
                    <TableHeader>
                        <TableRow className="border-b-0 hover:bg-transparent">
                            {hayOcultas && (
                                <TableHead className="w-11">
                                    <span className="sr-only">Ver el resto de los datos</span>
                                </TableHead>
                            )}
                            {visibles.map((columna) => {
                                const header = encabezados.find((h) => h.column.id === columna.id);
                                if (!header) return null;
                                const canSort = header.column.getCanSort();
                                const sorted = header.column.getIsSorted();
                                const m = (columna.columnDef.meta as ColumnMeta) ?? {};
                                return (
                                    <TableHead key={header.id}
                                        className={cn('h-11 truncate text-foreground/60', columna.id === '__index' && 'w-14', m.hideLabel && 'w-16', m.alignRight && 'text-right')}>
                                        {header.isPlaceholder ? null : m.hideLabel ? (
                                            <span className="sr-only">Acciones</span>
                                        ) : canSort ? (
                                            <button type="button" onClick={header.column.getToggleSortingHandler()}
                                                className={cn('hover:text-foreground inline-flex items-center gap-1.5 transition-colors', m.alignRight && 'flex-row-reverse')}>
                                                {flexRender(header.column.columnDef.header, header.getContext())}
                                                {sorted === 'asc' ? <ChevronUp className="size-3.5" /> : sorted === 'desc' ? <ChevronDown className="size-3.5" /> : <ArrowUpDown className="size-3 opacity-40" />}
                                            </button>
                                        ) : flexRender(header.column.columnDef.header, header.getContext())}
                                    </TableHead>
                                );
                            })}
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        {loading ? (
                            Array.from({ length: 6 }).map((_, i) => (
                                <TableRow key={`sk-${i}`} className="hover:bg-transparent">
                                    {Array.from({ length: Math.max(totalCols, 1) }).map((__, j) => (
                                        <TableCell key={j}><div className="h-4 w-full max-w-[140px] animate-pulse rounded bg-muted" /></TableCell>
                                    ))}
                                </TableRow>
                            ))
                        ) : rows.length ? (
                            rows.map((row) => {
                                const celdas = row.getVisibleCells();
                                const alaVista = celdas.filter((c) => !idsOcultas.has(c.column.id));
                                const enElDetalle = celdas.filter((c) => idsOcultas.has(c.column.id));
                                const abierta = !!expanded[row.id] && enElDetalle.length > 0;
                                const idDetalle = `detalle-${row.id}`;
                                return (
                                    <React.Fragment key={row.id}>
                                        <TableRow className="border-b-0">
                                            {hayOcultas && (
                                                <TableCell className="w-11">
                                                    {enElDetalle.length > 0 && (
                                                        <button type="button"
                                                            onClick={() => setExpanded((s) => ({ ...s, [row.id]: !s[row.id] }))}
                                                            aria-expanded={abierta} aria-controls={abierta ? idDetalle : undefined}
                                                            aria-label={abierta ? 'Ocultar detalle' : 'Ver detalle'}
                                                            className="flex size-6 items-center justify-center rounded-md text-primary">
                                                            <Plus className={cn('size-4 transition-transform duration-200', abierta && 'rotate-45')} />
                                                        </button>
                                                    )}
                                                </TableCell>
                                            )}
                                            {alaVista.map((cell) => {
                                                const m = (cell.column.columnDef.meta as ColumnMeta) ?? {};
                                                const cap = anchoCelda(cell.column);
                                                const contenido = flexRender(cell.column.columnDef.cell, cell.getContext());
                                                return (
                                                    <TableCell key={cell.id} className={cn('overflow-hidden py-3', m.alignRight && 'text-right')}>
                                                        {cap ? (
                                                            <div className="truncate [&_a]:block [&_a]:truncate [&_div]:truncate" style={{ maxWidth: cap }}>
                                                                {contenido}
                                                            </div>
                                                        ) : contenido}
                                                    </TableCell>
                                                );
                                            })}
                                        </TableRow>
                                        {abierta && (
                                            <TableRow id={idDetalle} className="hover:bg-transparent">
                                                <TableCell colSpan={totalCols} className="bg-muted/40 pb-3 pt-0">
                                                    <dl className="grid grid-cols-2 gap-x-6 gap-y-2.5 text-sm sm:grid-cols-3">
                                                        {enElDetalle.map((cell) => (
                                                            <div key={cell.id} className="flex min-w-0 flex-col gap-0.5">
                                                                <dt className="text-muted-foreground text-[11px] uppercase tracking-wide">{nombreColumna(cell.column)}</dt>
                                                                <dd className="min-w-0 truncate">{flexRender(cell.column.columnDef.cell, cell.getContext())}</dd>
                                                            </div>
                                                        ))}
                                                    </dl>
                                                </TableCell>
                                            </TableRow>
                                        )}
                                    </React.Fragment>
                                );
                            })
                        ) : (
                            <TableRow className="hover:bg-transparent">
                                <TableCell colSpan={Math.max(totalCols, 1)} className="h-40">
                                    <EmptyState message={vacioMsg} />
                                </TableCell>
                            </TableRow>
                        )}
                    </TableBody>
                </Table>
            </div>

            {/* ── Paginación + filas por página (client-side) ── */}
            {!manualPagination && !loading && rows.length > 0 && (() => {
                const ps = table.getState().pagination.pageSize;
                const pi = table.getState().pagination.pageIndex;
                const totalRows = table.getFilteredRowModel().rows.length;
                const desde = totalRows === 0 ? 0 : pi * ps + 1;
                const hasta = Math.min((pi + 1) * ps, totalRows);
                const round = 'inline-flex size-9 shrink-0 items-center justify-center rounded-full bg-card text-foreground shadow-soft transition-colors hover:bg-accent disabled:pointer-events-none disabled:opacity-40';
                return (
                    <div className="flex flex-col items-center justify-between gap-3 sm:flex-row">
                        <div className="flex items-center gap-3 text-sm text-muted-foreground">
                            <span className="whitespace-nowrap tabular-nums">
                                <span className="font-bold text-foreground">{desde.toLocaleString('es-PE')}-{hasta.toLocaleString('es-PE')}</span>
                                {' '}de <span className="font-bold text-foreground">{totalRows.toLocaleString('es-PE')}</span>
                            </span>
                            <span className="flex items-center gap-1.5">
                                <span className="text-xs">Filas</span>
                                <select
                                    value={ps}
                                    onChange={(e) => table.setPageSize(Number(e.target.value))}
                                    className="h-8 rounded-lg bg-card px-2 text-xs font-semibold text-foreground shadow-soft outline-none focus-visible:ring-2 focus-visible:ring-ring/40"
                                >
                                    {PAGE_SIZES.map((s) => <option key={s} value={s}>{s}</option>)}
                                </select>
                            </span>
                        </div>
                        {table.getPageCount() > 1 && (
                            <div className="flex items-center gap-3">
                                <span className="whitespace-nowrap text-sm tabular-nums text-muted-foreground">
                                    Página <span className="font-bold text-foreground">{pi + 1}</span> de <span className="font-bold text-foreground">{table.getPageCount()}</span>
                                </span>
                                <div className="flex gap-1.5">
                                    <button type="button" aria-label="Anterior" onClick={() => table.previousPage()} disabled={!table.getCanPreviousPage()} className={round}>
                                        <ChevronLeft className="size-4" />
                                    </button>
                                    <button type="button" aria-label="Siguiente" onClick={() => table.nextPage()} disabled={!table.getCanNextPage()} className={round}>
                                        <ChevronRight className="size-4" />
                                    </button>
                                </div>
                            </div>
                        )}
                    </div>
                );
            })()}
        </div>
    );
}

/** Estado vacío profesional (sin datos / sin resultados). */
function EmptyState({ message }: { message: string }) {
    return (
        <div className="flex flex-col items-center justify-center gap-2 py-6 text-center">
            <Inbox className="size-8 text-muted-foreground" />
            <p className="text-sm text-muted-foreground">{message}</p>
        </div>
    );
}
