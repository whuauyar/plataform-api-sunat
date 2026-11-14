<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Services\Branding\BrandingService;
use App\Services\Settings\SettingsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Configuración global del sistema de emisión (Escenario 1).
 * Dos switches: emisión ilimitada para TODAS las empresas y default
 * ilimitado para las empresas nuevas.
 */
class ConfiguracionController extends Controller
{
    public function __construct(
        private SettingsService $settings,
        private BrandingService $branding,
    ) {}

    public function edit(): Response
    {
        return Inertia::render('admin/configuracion/index', [
            'config' => [
                'emision_ilimitada_global' => $this->settings->bool(SettingsService::EMISION_ILIMITADA_GLOBAL),
                'nuevas_empresas_ilimitadas' => $this->settings->bool(SettingsService::NUEVAS_EMPRESAS_ILIMITADAS),
                'consulta_token_set' => $this->settings->string(SettingsService::CONSULTA_TOKEN_GLOBAL) !== '',
                'consulta_proveedor' => $this->settings->string(SettingsService::CONSULTA_PROVEEDOR_GLOBAL) ?: 'json',
            ],
            // OJO: no llamar a esta key 'branding' — colisiona con el prop compartido
            // 'branding' de HandleInertiaRequests y lo pisa en esta página (el sidebar
            // dejaría de ver el logo de plataforma). Se llama 'marca'.
            'marca' => [
                'app_logo_url' => $this->branding->appLogoUrl(),
                'favicon_url' => $this->branding->faviconUrl(),
            ],
            'stats' => [
                'empresas_total' => Tenant::count(),
                'empresas_ilimitadas' => Tenant::where('emission_mode', Tenant::EMISSION_UNLIMITED)->count(),
                'empresas_por_plan' => Tenant::where('emission_mode', Tenant::EMISSION_PLAN)->count(),
            ],
        ]);
    }

    /**
     * Sube (o elimina) el logo y el favicon de la PLATAFORMA (marca del super admin).
     * La imagen se muestra en el sidebar/login/register y el favicon en la pestaña.
     * No toca el nombre textual de la marca ni el candado de licencia: si no hay
     * imagen, el frontend sigue mostrando el texto/fallback por defecto.
     */
    public function updateBranding(Request $request): RedirectResponse
    {
        $request->validate([
            'app_logo' => 'nullable|file|max:2048|mimetypes:image/jpeg,image/png,image/webp,image/svg+xml',
            'favicon' => 'nullable|file|max:1024|mimetypes:image/png,image/webp,image/svg+xml,image/x-icon,image/vnd.microsoft.icon,image/jpeg',
            'eliminar_logo' => 'nullable|boolean',
            'eliminar_favicon' => 'nullable|boolean',
        ], [
            'app_logo.mimetypes' => 'El logo debe ser PNG, JPG, WebP o SVG.',
            'favicon.mimetypes' => 'El favicon debe ser PNG, SVG, ICO o WebP.',
        ]);

        $cambios = [];

        if ($request->boolean('eliminar_logo')) {
            $this->borrarArchivo(SettingsService::APP_LOGO_PATH);
            $cambios[SettingsService::APP_LOGO_PATH] = '';
        } elseif ($request->hasFile('app_logo')) {
            $this->borrarArchivo(SettingsService::APP_LOGO_PATH);
            $cambios[SettingsService::APP_LOGO_PATH] = $request->file('app_logo')->store('branding', 'public');
        }

        if ($request->boolean('eliminar_favicon')) {
            $this->borrarArchivo(SettingsService::FAVICON_PATH);
            $cambios[SettingsService::FAVICON_PATH] = '';
        } elseif ($request->hasFile('favicon')) {
            $this->borrarArchivo(SettingsService::FAVICON_PATH);
            $cambios[SettingsService::FAVICON_PATH] = $request->file('favicon')->store('branding', 'public');
        }

        if ($cambios !== []) {
            $this->settings->setMany($cambios);
        }

        return back()->with('success', 'Marca de la plataforma actualizada.');
    }

    /** Borra del disco el archivo actualmente apuntado por una key de branding. */
    private function borrarArchivo(string $key): void
    {
        $path = $this->settings->string($key);
        if ($path !== '' && Storage::disk('public')->exists($path)) {
            Storage::disk('public')->delete($path);
        }
    }

    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'emision_ilimitada_global' => 'required|boolean',
            'nuevas_empresas_ilimitadas' => 'required|boolean',
        ]);

        $this->settings->setMany([
            SettingsService::EMISION_ILIMITADA_GLOBAL => (bool) $data['emision_ilimitada_global'],
            SettingsService::NUEVAS_EMPRESAS_ILIMITADAS => (bool) $data['nuevas_empresas_ilimitadas'],
        ]);

        return back()->with('success', 'Configuración global de emisión actualizada.');
    }

    /**
     * Guarda (o elimina) el token global de api.json.pe usado para consultar
     * RUC/DNI al registrar nuevas empresas desde el panel admin.
     */
    public function updateConsultaToken(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'consulta_token' => 'nullable|string|max:1000',
            'consulta_proveedor' => 'nullable|in:json,migo',
        ]);

        $cambios = [
            SettingsService::CONSULTA_PROVEEDOR_GLOBAL => $data['consulta_proveedor'] ?? 'json',
        ];

        // El token solo se reemplaza si el admin escribió uno nuevo; si lo deja
        // vacío se conserva el guardado (para poder cambiar solo el proveedor).
        $token = trim((string) ($data['consulta_token'] ?? ''));
        if ($token !== '') {
            $cambios[SettingsService::CONSULTA_TOKEN_GLOBAL] = $token;
        }

        $this->settings->setMany($cambios);

        return back()->with('success', 'Configuración de consulta RUC/DNI guardada.');
    }

    /**
     * Consulta un RUC/DNI en api.json.pe con el token global y devuelve los
     * datos para autocompletar el formulario de nueva empresa.
     */
    public function lookupRuc(Request $request, \App\Services\Consulta\ConsultaDocumentoService $consulta): JsonResponse
    {
        $numero = trim((string) $request->input('numero', ''));

        $token = $this->settings->string(SettingsService::CONSULTA_TOKEN_GLOBAL);
        if ($token === '') {
            return response()->json(['error' => 'No has configurado el token de consulta global. Agrégalo en Administración → Configuración.'], 503);
        }

        $proveedor = $this->settings->string(SettingsService::CONSULTA_PROVEEDOR_GLOBAL) ?: 'json';
        $r = $consulta->consultar($proveedor, $token, $numero);

        if (! $r['ok']) {
            return response()->json(['error' => $r['error']], $r['status']);
        }

        // El alta de empresa autocompleta razón social, dirección y ubigeo.
        return response()->json([
            'razon_social' => $r['data']['razon_social'],
            'direccion' => $r['data']['direccion'],
            'departamento' => $r['data']['departamento'],
            'provincia' => $r['data']['provincia'],
            'distrito' => $r['data']['distrito'],
            'ubigeo' => $r['data']['ubigeo'],
            'estado' => $r['data']['estado'],
            'condicion' => $r['data']['condicion'],
        ]);
    }
}
