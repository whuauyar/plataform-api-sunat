import { Head, router } from '@inertiajs/react';
import { type ColumnDef } from '@tanstack/react-table';
import { AlertTriangle, Ban } from '@/lib/icons';
import { useState } from 'react';
import { Button } from '@/components/ui/button';
import { DataTable } from '@/components/ui/data-table';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import SunatLayout from '@/layouts/sunat-layout';

type DocAnulable = {
    tipo_documento: string;
    tipo_label: string;
    serie: string;
    correlativo: string;
    numero: string;
    cliente: string | null;
    total: number;
    moneda: string;
    fecha_emision: string;
};

type Props = {
    documentos: DocAnulable[];
};

function fmt(n: number) {
    return new Intl.NumberFormat('es-PE', { minimumFractionDigits: 2, maximumFractionDigits: 2 }).format(n);
}

export default function NuevaAnulacion({ documentos }: Props) {
    const [sel, setSel] = useState<string | null>(null);
    const [motivo, setMotivo] = useState('');
    const [submitting, setSubmitting] = useState(false);

    const doc = documentos.find((d) => d.numero === sel) ?? null;

    const columns: ColumnDef<DocAnulable>[] = [
        {
            id: 'sel',
            header: '',
            enableSorting: false,
            meta: { hideLabel: true },
            cell: ({ row }) => (
                <input
                    type="radio"
                    name="doc"
                    checked={sel === row.original.numero}
                    onChange={() => setSel(row.original.numero)}
                    aria-label={`Seleccionar ${row.original.numero}`}
                    className="size-5 shrink-0 cursor-pointer appearance-none rounded-full border-2 border-muted-foreground/40 align-middle transition-colors checked:border-primary checked:bg-primary checked:bg-clip-content checked:p-[4px]"
                />
            ),
        },
        {
            accessorKey: 'numero',
            header: 'Documento',
            meta: { label: 'Documento', primary: true },
            cell: ({ row }) => (
                <button type="button" onClick={() => setSel(row.original.numero)} className="text-left">
                    <div className="font-mono font-medium text-foreground">{row.original.numero}</div>
                    <div className="text-xs text-muted-foreground">{row.original.tipo_label}</div>
                </button>
            ),
        },
        {
            accessorKey: 'cliente',
            header: 'Cliente',
            meta: { label: 'Cliente' },
            cell: ({ row }) => <span className="text-muted-foreground">{row.original.cliente ?? '-'}</span>,
        },
        {
            accessorKey: 'fecha_emision',
            header: 'Fecha',
            meta: { label: 'Fecha' },
            cell: ({ row }) => <span className="tabular-nums text-muted-foreground">{row.original.fecha_emision}</span>,
        },
        {
            accessorKey: 'total',
            header: 'Total',
            meta: { label: 'Total', alignRight: true },
            cell: ({ row }) => (
                <span className="font-medium tabular-nums text-foreground">
                    {row.original.moneda === 'USD' ? '$' : 'S/'} {fmt(row.original.total)}
                </span>
            ),
        },
    ];

    const anular = () => {
        if (!doc || !motivo.trim()) return;
        if (!confirm(`¿Anular ${doc.tipo_label} ${doc.numero}? Esto envía una comunicación de baja a SUNAT y no se puede deshacer.`)) return;

        setSubmitting(true);
        router.post(
            '/sunat/anulaciones',
            {
                tipo_documento: doc.tipo_documento,
                serie: doc.serie,
                correlativo: doc.correlativo,
                motivo,
                fecha_generacion: doc.fecha_emision,
            },
            { onFinish: () => setSubmitting(false) },
        );
    };

    return (
        <SunatLayout>
            <Head title="Anular comprobante" />

            <div className="mx-auto max-w-4xl space-y-5">
                <header className="flex items-center gap-3">
                    <span className="flex size-10 items-center justify-center rounded-xl bg-muted text-foreground">
                        <Ban className="size-5" />
                    </span>
                    <div>
                        <h1 className="text-xl font-semibold text-foreground">Anular comprobante</h1>
                        <p className="text-sm text-muted-foreground">
                            Comunicación de baja a SUNAT para facturas y notas aceptadas (dentro de 7 días).
                        </p>
                    </div>
                </header>

                <div className="flex items-start gap-2 rounded-xl border border-border bg-muted/40 px-4 py-3 text-sm text-muted-foreground">
                    <AlertTriangle className="mt-0.5 size-4 shrink-0 text-amber-500" />
                    <p>
                        Las <strong className="font-medium text-foreground">boletas</strong> no se anulan aquí — van por resumen diario. La anulación es
                        <strong className="font-medium text-foreground"> irreversible</strong> una vez aceptada por SUNAT.
                    </p>
                </div>

                {documentos.length === 0 ? (
                    <p className="rounded-xl border border-dashed border-border p-8 text-center text-sm text-muted-foreground">
                        No hay comprobantes anulables (aceptados y dentro de los últimos 7 días).
                    </p>
                ) : (
                    <>
                        <section className="space-y-2.5">
                            <h2 className="px-1 text-sm font-semibold text-foreground">Selecciona el comprobante a anular</h2>
                            <DataTable
                                columns={columns}
                                data={documentos}
                                showIndex={false}
                                searchPlaceholder="Buscar por número o cliente..."
                                emptyMessage="No hay comprobantes anulables."
                            />
                        </section>

                        <section className="rounded-2xl bg-card shadow-soft">
                            <div className="border-b border-border/60 px-5 py-3.5">
                                <span className="text-sm font-semibold text-foreground">Motivo de la anulación</span>
                            </div>
                            <div className="p-5">
                                <div className="grid gap-1.5">
                                    <Label htmlFor="motivo" className="text-xs font-medium text-muted-foreground">
                                        Motivo <span className="text-destructive">*</span>
                                    </Label>
                                    <Input
                                        id="motivo"
                                        value={motivo}
                                        onChange={(e) => setMotivo(e.target.value)}
                                        placeholder="Ej: Error en el monto / cliente equivocado"
                                        maxLength={255}
                                        className="h-10 rounded-xl"
                                    />
                                </div>
                                <div className="mt-4 flex items-center gap-3">
                                    <Button
                                        type="button"
                                        variant="destructive"
                                        onClick={anular}
                                        disabled={submitting || !doc || !motivo.trim()}
                                        className="gap-2 rounded-xl"
                                    >
                                        <Ban className="size-4" />
                                        {doc ? `Anular ${doc.numero}` : 'Selecciona un comprobante'}
                                    </Button>
                                    {doc && <span className="text-xs text-muted-foreground">Se enviará la baja de <span className="font-medium text-foreground">{doc.numero}</span> a SUNAT.</span>}
                                </div>
                            </div>
                        </section>
                    </>
                )}
            </div>
        </SunatLayout>
    );
}
