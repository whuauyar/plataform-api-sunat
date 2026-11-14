import type { Auth } from './auth';

export type EmpresaOption = {
    id: number;
    ruc: string;
    razon_social: string;
};

export type EmpresaRol = 'super_admin' | 'owner' | 'cajero' | null;

/** Contexto de la empresa activa + permisos del usuario (para gating de UI). */
export type EmpresaShared = {
    rol: EmpresaRol;
    esSuperAdmin: boolean;
    disponibles: EmpresaOption[];
    can: string[];
};

export type TenantShared = {
    id: number;
    ruc: string;
    razon_social: string;
    environment: string;
    sol_configurado: boolean;
    /** Logo de la empresa para el sidebar del cliente (null → fallback marca). */
    logo_url: string | null;
    /** Favicon propio de la empresa (null → favicon de plataforma / por defecto). */
    favicon_url: string | null;
} | null;

/** Marca de la plataforma (super admin). null en cada campo → fallback por defecto. */
export type BrandingShared = {
    appLogo: string | null;
    favicon: string | null;
};

export type FlashShared = {
    success?: string | null;
    error?: string | null;
    info?: string | null;
    warning?: string | null;
    nuevoCajero?: { email: string; password: string } | null;
    [key: string]: unknown;
};

export type SharedData = {
    name: string;
    auth: Auth;
    sidebarOpen: boolean;
    tenant: TenantShared;
    empresa: EmpresaShared;
    branding: BrandingShared;
    flash?: FlashShared;
    [key: string]: unknown;
};
