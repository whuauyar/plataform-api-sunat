import { usePage } from '@inertiajs/react';
import { AppContent } from '@/components/app-content';
import { AppShell } from '@/components/app-shell';
import { AppSidebarHeader } from '@/components/app-sidebar-header';
import { FaviconSync } from '@/components/favicon-sync';
import { LoginCelebration } from '@/components/login-celebration';
import { FlashMessages } from '@/components/sunat/flash-messages';
import { SunatSidebar } from '@/components/sunat/sunat-sidebar';
import type { SharedData } from '@/types';

interface SunatLayoutProps {
    children: React.ReactNode;
    title?: string;
}

export default function SunatLayout({ children }: SunatLayoutProps) {
    const { props } = usePage<SharedData>();
    // Prioridad: favicon de la empresa activa → favicon de plataforma → blade default.
    const favicon = props.tenant?.favicon_url ?? props.branding?.favicon ?? null;

    return (
        <AppShell variant="sidebar">
            <FaviconSync url={favicon} />
            <LoginCelebration />
            <SunatSidebar />
            <AppContent variant="sidebar" className="overflow-x-hidden">
                <AppSidebarHeader />
                <FlashMessages />
                <main className="mx-auto w-full max-w-[1400px] px-4 pt-4 pb-[max(2rem,env(safe-area-inset-bottom))] sm:px-6">
                    {children}
                </main>
            </AppContent>
        </AppShell>
    );
}
