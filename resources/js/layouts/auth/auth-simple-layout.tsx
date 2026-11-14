import { Link, usePage } from '@inertiajs/react';
import { home } from '@/routes';
import type { AuthLayoutProps, SharedData } from '@/types';

export default function AuthSimpleLayout({
    children,
    title,
    description,
}: AuthLayoutProps) {
    // Logo de plataforma (super admin). Si existe, reemplaza al texto de marca.
    const logo = usePage<SharedData>().props.branding?.appLogo;

    return (
        <div className="relative flex min-h-svh flex-col items-center justify-center bg-background p-6">
            {/* Marca: imagen si el super admin subió logo; si no, el texto fallback. */}
            <Link href={home()} className="mb-8 text-center">
                {logo ? (
                    <img src={logo} alt="Logo" className="mx-auto h-20 w-auto max-w-[80vw] object-contain sm:h-24" />
                ) : (
                    <>
                        <h2 className="text-3xl font-extrabold tracking-tight text-foreground">
                            Jorge Chavez
                        </h2>
                        <p className="mt-1 text-xs font-bold uppercase tracking-[0.25em] text-primary">
                            API SUNAT
                        </p>
                    </>
                )}
            </Link>

            {/* Card del formulario — sin borde, radius bonito, sombra suave */}
            <div className="w-full max-w-sm rounded-2xl bg-card shadow-soft p-8">
                <div className="mb-6 space-y-1.5 text-center">
                    <h1 className="text-xl font-bold tracking-tight text-card-foreground">
                        {title}
                    </h1>
                    <p className="text-sm text-muted-foreground">
                        {description}
                    </p>
                </div>
                {children}
            </div>

            {/* Footer discreto */}
            <p className="mt-8 text-center text-xs font-medium text-muted-foreground">
                Facturación electrónica Perú · SUNAT
            </p>
        </div>
    );
}
