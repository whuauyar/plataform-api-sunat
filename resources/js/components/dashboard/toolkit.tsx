import { useEffect, useRef, useState } from 'react';
import { ArrowDownRight, ArrowUpRight, type LucideIcon } from '@/lib/icons';
import { Card } from '@/components/ui/card';
import { cn } from '@/lib/utils';

/**
 * Toolkit de dashboard — piezas compartidas por el panel del superadmin y el del
 * cliente para garantizar consistencia visual. Paleta viva y estratégica, KPIs
 * animados, tarjetas de gráfico, filtros de rango, skeletons y estados vacíos.
 * Todo respeta los tokens del sistema (fondos/bordes/tipografía).
 */

// ── Paleta de gráficos — FAMILIA del azul primario del proyecto ─────────────
// Derivada del primario (hue ~246): tonos del azul + vecinos analógicos (índigo,
// cielo, cian). Sin colores ajenos a la marca (nada de naranja/amarillo/rosa
// sueltos: esos quedan solo como colores semánticos de estado). Así todos los
// gráficos se sienten del mismo producto. Ordenada de distinguible a distinguible.
export const PALETTE_LIGHT = ['#2563EB', '#3B82F6', '#0EA5E9', '#6063E9', '#60A5FA', '#06B6D4'];
export const PALETTE_DARK = ['#3B82F6', '#60A5FA', '#38BDF8', '#818CF8', '#93C5FD', '#22D3EE'];

/** Colores semánticos por estado SUNAT (mismo significado en cualquier gráfico). */
export const ESTADO_COLOR: Record<string, string> = {
    Aceptado: '#22C45E',
    Enviado: '#3B82F6',
    Pendiente: '#F59E0B',
    Rechazado: '#F04438',
    Anulado: '#94A3B8',
};

export function useIsDark(): boolean {
    const [dark, setDark] = useState(
        () => typeof document !== 'undefined' && document.documentElement.classList.contains('dark'),
    );
    useEffect(() => {
        const el = document.documentElement;
        const update = () => setDark(el.classList.contains('dark'));
        update();
        const obs = new MutationObserver(update);
        obs.observe(el, { attributes: true, attributeFilter: ['class'] });
        return () => obs.disconnect();
    }, []);
    return dark;
}

export function usePalette(): string[] {
    return useIsDark() ? PALETTE_DARK : PALETTE_LIGHT;
}

function prefersReducedMotion(): boolean {
    return typeof window !== 'undefined' && window.matchMedia?.('(prefers-reduced-motion: reduce)').matches;
}

/** Anima un número de 0 → target al montar (ease-out). Respeta reduce-motion. */
export function useCountUp(target: number, duration = 850): number {
    const [value, setValue] = useState(prefersReducedMotion() ? target : 0);
    const ref = useRef(target);
    ref.current = target;
    useEffect(() => {
        if (prefersReducedMotion()) { setValue(target); return; }
        let raf = 0;
        const start = performance.now();
        const from = 0;
        const tick = (t: number) => {
            const p = Math.min(1, (t - start) / duration);
            const eased = 1 - Math.pow(1 - p, 3);
            setValue(from + (target - from) * eased);
            if (p < 1) raf = requestAnimationFrame(tick);
            else setValue(target);
        };
        raf = requestAnimationFrame(tick);
        return () => cancelAnimationFrame(raf);
    }, [target, duration]);
    return value;
}

// ── Formatters ──────────────────────────────────────────────────────────────
export const fmtSoles = (n: number) =>
    new Intl.NumberFormat('es-PE', { style: 'currency', currency: 'PEN', maximumFractionDigits: 0 }).format(n);
export const fmtInt = (n: number) => Math.round(n).toLocaleString('es-PE');
export const fmtFecha = (iso: string) => {
    const [, m, d] = iso.split('-');
    return `${d}/${m}`;
};

export const TOOLTIP_STYLE = {
    backgroundColor: 'var(--popover)',
    border: '1px solid var(--border)',
    borderRadius: 10,
    fontSize: 12,
    boxShadow: '0 8px 24px -8px rgba(0,0,0,0.25)',
    color: 'var(--popover-foreground)',
} as const;

// ── KPI card ────────────────────────────────────────────────────────────────
// Card de dashboard normal: PLANO (borde definido, sin sombra ni degradado),
// compacto (no alto) y acoplado al color PRIMARIO del proyecto para el ícono y el
// acento. La cifra es la protagonista; la tendencia va como chip semántico.
export function KpiCard({
    label, valueNum, format, subvalue, growth, icon: Icon, index = 0,
}: {
    label: string;
    valueNum: number;
    format?: (n: number) => string;
    subvalue?: string;
    growth?: number | null;
    icon: LucideIcon;
    /** Escalona la aparición para un "load" con vida. */
    index?: number;
}) {
    const animated = useCountUp(valueNum);
    const value = format ? format(animated) : fmtInt(animated);

    return (
        <div
            className="rounded-xl bg-card p-4 shadow-soft transition-all duration-200 hover:-translate-y-0.5 hover:shadow-md motion-safe:animate-in motion-safe:fade-in"
            style={{ animationDelay: `${index * 60}ms`, animationFillMode: 'both' }}
        >
            <div className="flex items-center justify-between gap-2">
                <span className="truncate text-[11px] font-medium uppercase tracking-wide text-muted-foreground">{label}</span>
                {growth !== null && growth !== undefined && (
                    <span
                        className={cn(
                            'inline-flex shrink-0 items-center gap-0.5 rounded-full px-1.5 py-0.5 text-xs font-semibold tabular-nums',
                            growth >= 0 ? 'bg-green-500/15 text-green-600 dark:text-green-400' : 'bg-red-500/12 text-red-600 dark:text-red-400',
                        )}
                    >
                        {growth >= 0 ? <ArrowUpRight className="size-3" /> : <ArrowDownRight className="size-3" />}
                        {growth >= 0 ? '+' : ''}{growth}%
                    </span>
                )}
            </div>

            <div className="mt-2 flex items-center justify-between gap-2">
                <span className="truncate text-xl font-bold leading-none tracking-tight tabular-nums">{value}</span>
                <Icon className="size-5 shrink-0 text-primary" />
            </div>

            {subvalue && <div className="mt-2 truncate text-xs text-muted-foreground">{subvalue}</div>}
        </div>
    );
}

// ── Chart card ──────────────────────────────────────────────────────────────
export function ChartCard({
    title, subtitle, right, children, className,
}: {
    title: string;
    subtitle?: string;
    right?: React.ReactNode;
    children: React.ReactNode;
    className?: string;
}) {
    return (
        <Card className={cn('gap-0 p-5 transition-shadow duration-200 hover:shadow-md', className)}>
            <div className="mb-4 flex items-start justify-between gap-3">
                <div className="min-w-0">
                    <h3 className="truncate text-sm font-semibold text-foreground">{title}</h3>
                    {subtitle && <p className="truncate text-xs text-muted-foreground">{subtitle}</p>}
                </div>
                {right && <div className="shrink-0">{right}</div>}
            </div>
            {children}
        </Card>
    );
}

// ── Segmented control (filtros de rango) ────────────────────────────────────
export function RangeToggle<T extends string>({ value, onChange, options }: {
    value: T;
    onChange: (v: T) => void;
    options: { value: T; label: string }[];
}) {
    return (
        <div className="inline-flex rounded-lg border border-border bg-muted/40 p-0.5">
            {options.map((o) => (
                <button
                    key={o.value}
                    type="button"
                    onClick={() => onChange(o.value)}
                    className={cn(
                        'rounded-md px-2.5 py-1 text-xs font-medium transition-colors',
                        value === o.value ? 'bg-card text-foreground shadow-soft' : 'text-muted-foreground hover:text-foreground',
                    )}
                >
                    {o.label}
                </button>
            ))}
        </div>
    );
}

export function EmptyState({ message, height = 220 }: { message: string; height?: number }) {
    return (
        <div className="flex items-center justify-center text-center text-sm text-muted-foreground" style={{ height }}>
            {message}
        </div>
    );
}

/** Skeleton de carga (elegante, respeta reduce-motion vía animate-pulse). */
export function SkeletonBlock({ className }: { className?: string }) {
    return <div className={cn('animate-pulse rounded-lg bg-muted', className)} />;
}
