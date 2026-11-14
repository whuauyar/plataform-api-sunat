import { createInertiaApp, router } from '@inertiajs/react';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import { StrictMode } from 'react';
import { createRoot } from 'react-dom/client';
import { toast } from 'sonner';
import '../css/app.css';
import { ConfirmDialogProvider } from '@/components/ui/confirm-dialog';
import { Toaster } from '@/components/ui/sonner';
import { initializeTheme } from '@/hooks/use-appearance';

const appName = import.meta.env.VITE_APP_NAME || 'Laravel';

// Manejo elegante de sesión/token expirado (HTTP 419 "CSRF token mismatch").
// Ocurre cuando la pestaña llevaba rato abierta o hubo un redeploy: el token de
// la página ya no coincide. En vez de mostrar la pantalla cruda de Laravel,
// avisamos y recargamos para obtener un token fresco (el usuario reintenta la acción).
let recargandoPorSesion = false;
router.on('invalid', (event) => {
    const status = (event.detail?.response as { status?: number } | undefined)?.status;
    if (status === 419 && !recargandoPorSesion) {
        recargandoPorSesion = true;
        event.preventDefault();
        toast.error('Tu sesión se actualizó. Recargando la página…');
        setTimeout(() => window.location.reload(), 1200);
    }
});

createInertiaApp({
    title: (title) => (title ? `${title} - ${appName}` : appName),
    resolve: (name) =>
        resolvePageComponent(
            `./pages/${name}.tsx`,
            import.meta.glob('./pages/**/*.tsx'),
        ),
    setup({ el, App, props }) {
        const root = createRoot(el);

        root.render(
            <StrictMode>
                <ConfirmDialogProvider>
                    <App {...props} />
                    <Toaster />
                </ConfirmDialogProvider>
            </StrictMode>,
        );
    },
    progress: {
        color: '#4B5563',
    },
});

// This will set light / dark mode on load...
initializeTheme();
