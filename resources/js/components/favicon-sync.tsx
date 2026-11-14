import { useEffect } from 'react';

/**
 * Ajusta el favicon (icono de pestaña) del documento en tiempo de ejecución.
 *
 * El blade raíz pinta un favicon inicial (el de la plataforma o /logo.png). Como
 * el panel es una SPA (Inertia), al cambiar de empresa activa o entre paneles no
 * hay recarga completa, así que el favicon por empresa se aplica desde aquí.
 *
 * Pasa `url = null/undefined` para no tocar nada (se conserva el favicon del blade).
 */
export function FaviconSync({ url }: { url?: string | null }) {
    useEffect(() => {
        if (!url) return;

        const links = document.querySelectorAll<HTMLLinkElement>(
            'link[rel~="icon"], link[rel="shortcut icon"], link[rel="apple-touch-icon"]',
        );

        if (links.length === 0) {
            const link = document.createElement('link');
            link.rel = 'icon';
            link.href = url;
            document.head.appendChild(link);
            return;
        }

        links.forEach((l) => {
            l.href = url;
        });
    }, [url]);

    return null;
}
