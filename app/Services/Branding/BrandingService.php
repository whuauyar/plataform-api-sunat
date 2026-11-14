<?php

declare(strict_types=1);

namespace App\Services\Branding;

use App\Models\Tenant;
use App\Services\Settings\SettingsService;
use Illuminate\Support\Facades\Storage;

/**
 * Resuelve las URLs de branding (logo/favicon) a dos niveles:
 *   - Plataforma: settings globales (super admin) → logo del sidebar/login y favicon.
 *   - Empresa (tenant): logo del sidebar del cliente y favicon propio.
 *
 * Devuelve null cuando no hay imagen configurada, para que el frontend caiga al
 * fallback (texto de marca "Jorge Chavez API SUNAT" / favicon por defecto /logo.png).
 *
 * Las URLs llevan cache-buster (?v=mtime) porque el navegador cachea favicons y
 * logos de forma muy agresiva y, sin esto, no se vería el cambio tras subir uno nuevo.
 */
class BrandingService
{
    public function __construct(private SettingsService $settings) {}

    public function appLogoUrl(): ?string
    {
        return $this->urlFor($this->settings->string(SettingsService::APP_LOGO_PATH));
    }

    public function faviconUrl(): ?string
    {
        return $this->urlFor($this->settings->string(SettingsService::FAVICON_PATH));
    }

    public function tenantLogoUrl(Tenant $tenant): ?string
    {
        return $this->urlFor((string) ($tenant->logo_path ?? ''));
    }

    public function tenantFaviconUrl(Tenant $tenant): ?string
    {
        return $this->urlFor((string) ($tenant->favicon_path ?? ''));
    }

    private function urlFor(string $path): ?string
    {
        if ($path === '') {
            return null;
        }

        $disk = Storage::disk('public');

        if (! $disk->exists($path)) {
            return null;
        }

        $version = '';
        try {
            $version = '?v=' . $disk->lastModified($path);
        } catch (\Throwable) {
            // Sin cache-buster si el driver no soporta lastModified.
        }

        return $disk->url($path) . $version;
    }
}
