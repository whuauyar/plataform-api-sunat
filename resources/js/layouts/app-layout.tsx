import { usePage } from '@inertiajs/react';
import { FaviconSync } from '@/components/favicon-sync';
import { LoginCelebration } from '@/components/login-celebration';
import AppLayoutTemplate from '@/layouts/app/app-sidebar-layout';
import type { AppLayoutProps, SharedData } from '@/types';

export default ({ children, breadcrumbs, ...props }: AppLayoutProps) => {
    // Favicon de la plataforma (super admin). null → se conserva el del blade.
    const favicon = usePage<SharedData>().props.branding?.favicon ?? null;

    return (
        <AppLayoutTemplate breadcrumbs={breadcrumbs} {...props}>
            <FaviconSync url={favicon} />
            <LoginCelebration />
            {children}
        </AppLayoutTemplate>
    );
};
