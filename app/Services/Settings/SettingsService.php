<?php

namespace App\Services\Settings;

use App\Models\Setting;
use Illuminate\Support\Facades\Cache;

/**
 * Acceso cacheado a la configuración global del sistema (tabla `settings`).
 *
 * Claves de emisión (Escenario 1):
 *   - emision_ilimitada_global    (bool) → si está ON, TODAS las empresas emiten sin límite.
 *   - nuevas_empresas_ilimitadas  (bool) → las empresas nuevas se crean en modo 'unlimited'.
 *
 * El valor se guarda como JSON en la columna `value`, así que un mismo store
 * sirve para banderas booleanas y, a futuro, para cualquier config estructurada.
 */
class SettingsService
{
    private const CACHE_KEY = 'app:settings';

    private const CACHE_TTL = 3600;

    public const EMISION_ILIMITADA_GLOBAL = 'emision_ilimitada_global';

    public const NUEVAS_EMPRESAS_ILIMITADAS = 'nuevas_empresas_ilimitadas';

    /** Token global de api.json.pe para consultar RUC/DNI al registrar empresas (admin). */
    public const CONSULTA_TOKEN_GLOBAL = 'consulta_token_global';

    /** Proveedor global de consulta RUC/DNI: 'json' (api.json.pe) o 'migo' (api.migo.pe). */
    public const CONSULTA_PROVEEDOR_GLOBAL = 'consulta_proveedor_global';

    /** Branding de la plataforma (super admin): logo del sidebar/login/register. */
    public const APP_LOGO_PATH = 'app_logo_path';

    /** Branding de la plataforma: favicon / icono de pestaña del navegador. */
    public const FAVICON_PATH = 'favicon_path';

    /** Credenciales de licencia (activación Pro desde el panel). El secreto se
     *  guarda CIFRADO. Si están presentes, pisan a las de .env (LICENSE_*). */
    public const LICENSE_KEY = 'license_key';

    public const LICENSE_SECRET = 'license_secret'; // valor cifrado (Crypt)

    public const LICENSE_DOMAIN = 'license_domain';

    /**
     * Valores por defecto cuando la clave aún no existe en la BD.
     */
    private const DEFAULTS = [
        self::EMISION_ILIMITADA_GLOBAL => false,
        self::NUEVAS_EMPRESAS_ILIMITADAS => false,
        self::CONSULTA_TOKEN_GLOBAL => '',
        self::CONSULTA_PROVEEDOR_GLOBAL => 'json',
        self::APP_LOGO_PATH => '',
        self::FAVICON_PATH => '',
        self::LICENSE_KEY => '',
        self::LICENSE_SECRET => '',
        self::LICENSE_DOMAIN => '',
    ];

    public function string(string $key): string
    {
        return (string) ($this->all()[$key] ?? '');
    }

    /**
     * @return array<string, mixed> Todas las settings (con defaults aplicados).
     */
    public function all(): array
    {
        $guardadas = Cache::remember(
            self::CACHE_KEY,
            self::CACHE_TTL,
            fn () => Setting::query()->pluck('value', 'key')->all(),
        );

        return array_merge(self::DEFAULTS, $guardadas);
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->all()[$key] ?? $default;
    }

    public function bool(string $key): bool
    {
        return (bool) ($this->all()[$key] ?? false);
    }

    /**
     * @param  array<string, mixed>  $valores
     */
    public function setMany(array $valores): void
    {
        foreach ($valores as $key => $value) {
            Setting::updateOrCreate(['key' => $key], ['value' => $value]);
        }

        $this->flush();
    }

    public function flush(): void
    {
        Cache::forget(self::CACHE_KEY);
    }
}
