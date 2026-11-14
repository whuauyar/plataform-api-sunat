import { useEffect, useState } from 'react';
import { Maximize, Minimize } from '@/lib/icons';
import { cn } from '@/lib/utils';

/**
 * Botón del header para alternar pantalla completa (Fullscreen API).
 * Muestra "agrandar" cuando está en ventana y "reducir" cuando está en
 * pantalla completa. Se sincroniza con el evento nativo `fullscreenchange`
 * (por si el usuario sale con la tecla Esc / F11).
 */
export function HeaderFullscreenToggle({ className }: { className?: string }) {
    const [isFull, setIsFull] = useState(false);

    useEffect(() => {
        const onChange = () => setIsFull(Boolean(document.fullscreenElement));
        document.addEventListener('fullscreenchange', onChange);
        onChange();
        return () => document.removeEventListener('fullscreenchange', onChange);
    }, []);

    const toggle = () => {
        if (document.fullscreenElement) {
            document.exitFullscreen?.();
        } else {
            document.documentElement.requestFullscreen?.();
        }
    };

    const label = isFull ? 'Salir de pantalla completa' : 'Pantalla completa';
    const Icon = isFull ? Minimize : Maximize;

    return (
        <button
            type="button"
            onClick={toggle}
            title={label}
            aria-label={label}
            aria-pressed={isFull}
            className={cn(
                'flex size-8 items-center justify-center rounded-full text-muted-foreground transition-colors hover:bg-muted hover:text-foreground',
                className,
            )}
        >
            <Icon className="size-4" />
        </button>
    );
}
