<?php

namespace App\Http\Controllers\Web\Sunat;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Serie;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ConfiguracionController extends Controller
{
    public function edit(): Response
    {
        $user = auth()->user();
        $tenant = app(\App\Services\Tenancy\EmpresaActiva::class)->actual();

        if (! $tenant) {
            $tenant = \App\Models\Tenant::create([
                'user_id' => $user->id,
                'ruc' => '10463838327', // RUC personal proporcionado por el usuario
                'razon_social' => 'SUAREZ ORBEGOSO LUIS ANDRES',
                'sol_user' => '',
                'sol_pass' => '',
                'environment' => 'beta',
                'plan' => 'free',
                'is_active' => true,
            ]);

            \App\Models\Serie::firstOrCreate(
                ['tenant_id' => $tenant->id, 'tipo_documento' => '01', 'serie' => 'F001'],
                ['correlativo' => 0, 'is_active' => true]
            );

            \App\Models\Serie::firstOrCreate(
                ['tenant_id' => $tenant->id, 'tipo_documento' => '03', 'serie' => 'B001'],
                ['correlativo' => 0, 'is_active' => true]
            );
        }

        $series = $tenant
            ? Serie::where('tenant_id', $tenant->id)->where('is_active', true)->get(['id', 'serie', 'tipo_documento', 'correlativo'])
            : collect();

        return Inertia::render('sunat/configuracion', [
            'tenant' => $tenant ? [
                'ruc' => $tenant->ruc,
                'razon_social' => $tenant->razon_social,
                'nombre_comercial' => $tenant->nombre_comercial ?? '',
                'direccion' => $tenant->direccion ?? '',
                'ubigeo' => $tenant->ubigeo ?? '',
                'departamento' => $tenant->departamento ?? '',
                'provincia' => $tenant->provincia ?? '',
                'distrito' => $tenant->distrito ?? '',
                'telefonos' => $tenant->telefonos ?? [],
                'emails' => $tenant->emails ?? [],
                'cuentas_bancarias' => $tenant->cuentas_bancarias ?? [],
                'billeteras_digitales' => $tenant->billeteras_digitales ?? [],
                'mensaje_agradecimiento' => $tenant->mensaje_agradecimiento ?? '',
                'mensaje_promocional' => $tenant->mensaje_promocional ?? '',
                'logo_url' => $tenant->logo_path
                    ? \Illuminate\Support\Facades\Storage::disk('public')->url($tenant->logo_path).'?v='.($tenant->updated_at?->timestamp ?? time())
                    : null,
                'favicon_url' => $tenant->favicon_path
                    ? \Illuminate\Support\Facades\Storage::disk('public')->url($tenant->favicon_path).'?v='.($tenant->updated_at?->timestamp ?? time())
                    : null,
                'sol_user' => $tenant->sol_user ?? '',
                'environment' => $tenant->environment ?? 'beta',
                'serie_factura' => $series->firstWhere('tipo_documento', '01')?->serie ?? 'F001',
                'serie_boleta' => $series->firstWhere('tipo_documento', '03')?->serie ?? 'B001',
                'consulta_token_set' => ! empty($tenant->consulta_token),
                'consulta_proveedor' => $tenant->consulta_proveedor ?? 'json',
            ] : null,
        ]);
    }

    public function update(Request $request): \Illuminate\Http\RedirectResponse
    {
        // Los arrays llegan como JSON string dentro del FormData (por la subida de archivos).
        foreach (['telefonos', 'emails', 'cuentas_bancarias', 'billeteras_digitales'] as $campo) {
            if (is_string($request->input($campo))) {
                $decoded = json_decode((string) $request->input($campo), true);
                $request->merge([$campo => is_array($decoded) ? $decoded : []]);
            }
        }

        $data = $request->validate([
            // Credenciales / técnico (opcionales al editar: vacío = sin cambios)
            'sol_user' => 'nullable|string|max:20',
            'sol_pass' => 'nullable|string|max:255',
            'environment' => 'required|in:beta,produccion',
            'serie_factura' => 'required|string|max:4',
            'serie_boleta' => 'required|string|max:4',
            'certificate' => 'nullable|file|max:4096',
            'certificate_password' => 'nullable|string|max:255',
            'logo' => 'nullable|image|max:2048',
            'favicon' => 'nullable|file|max:1024|mimetypes:image/png,image/webp,image/svg+xml,image/x-icon,image/vnd.microsoft.icon,image/jpeg',
            // Datos del emisor
            'razon_social' => 'nullable|string|max:255',
            'nombre_comercial' => 'nullable|string|max:255',
            'direccion' => 'nullable|string|max:500',
            'ubigeo' => 'nullable|string|between:4,6',
            'departamento' => 'nullable|string|max:100',
            'provincia' => 'nullable|string|max:100',
            'distrito' => 'nullable|string|max:100',
            'mensaje_agradecimiento' => 'nullable|string|max:500',
            'mensaje_promocional' => 'nullable|string|max:500',
            // Contacto
            'telefonos' => 'nullable|array|max:5',
            'telefonos.*' => 'nullable|string|max:30',
            'emails' => 'nullable|array|max:5',
            'emails.*' => 'nullable|email|max:100',
            // Cuentas bancarias
            'cuentas_bancarias' => 'nullable|array|max:5',
            'cuentas_bancarias.*.banco' => 'required_with:cuentas_bancarias.*.numero|string|max:50',
            'cuentas_bancarias.*.tipo' => 'nullable|string|max:30',
            'cuentas_bancarias.*.numero' => 'required_with:cuentas_bancarias.*.banco|string|max:30',
            'cuentas_bancarias.*.cci' => 'nullable|string|max:30',
            'cuentas_bancarias.*.moneda' => 'nullable|in:PEN,USD',
            'cuentas_bancarias.*.titular' => 'nullable|string|max:100',
            // Billeteras digitales
            'billeteras_digitales' => 'nullable|array|max:5',
            'billeteras_digitales.*.tipo' => 'required_with:billeteras_digitales.*.numero|string|max:30',
            'billeteras_digitales.*.numero' => 'required_with:billeteras_digitales.*.tipo|string|max:20',
            'billeteras_digitales.*.titular' => 'nullable|string|max:100',
        ]);

        $user = auth()->user();
        $tenant = app(\App\Services\Tenancy\EmpresaActiva::class)->actual();

        if (! $tenant) {
            return redirect()->route('sunat.configuracion')
                ->with('error', 'No se ha encontrado ninguna empresa (Tenant) registrada para tu usuario. Primero debes registrar una empresa.');
        }

        // Solo el dueño (o super admin) puede editar credenciales/certificado de la empresa.
        \Illuminate\Support\Facades\Gate::authorize('editar-empresa', $tenant);

        // Limpia filas vacías de los arrays antes de persistir.
        $telefonos = array_values(array_filter($data['telefonos'] ?? [], fn ($t) => filled($t)));
        $emails = array_values(array_filter($data['emails'] ?? [], fn ($e) => filled($e)));
        $cuentas = array_values(array_filter($data['cuentas_bancarias'] ?? [], fn ($c) => filled($c['banco'] ?? null) && filled($c['numero'] ?? null)));
        $billeteras = array_values(array_filter($data['billeteras_digitales'] ?? [], fn ($b) => filled($b['tipo'] ?? null) && filled($b['numero'] ?? null)));

        $update = [
            'environment' => $data['environment'],
            'razon_social' => $data['razon_social'] ?: $tenant->razon_social,
            'nombre_comercial' => $data['nombre_comercial'] ?? null,
            'direccion' => $data['direccion'] ?? null,
            'ubigeo' => $data['ubigeo'] ?? null,
            'departamento' => $data['departamento'] ?? null,
            'provincia' => $data['provincia'] ?? null,
            'distrito' => $data['distrito'] ?? null,
            'telefonos' => $telefonos,
            'emails' => $emails,
            'cuentas_bancarias' => $cuentas,
            'billeteras_digitales' => $billeteras,
            'mensaje_agradecimiento' => $data['mensaje_agradecimiento'] ?? null,
            'mensaje_promocional' => $data['mensaje_promocional'] ?? null,
        ];

        // El usuario y la clave SOL solo se cambian si el usuario los escribió;
        // dejarlos en blanco conserva los actuales (evita el "sol pass required").
        if (filled($data['sol_user'] ?? null)) {
            $update['sol_user'] = $data['sol_user'];
        }
        if (filled($data['sol_pass'] ?? null)) {
            $update['sol_pass'] = $data['sol_pass'];
        }

        $tenant->update($update);

        AuditLog::registrar('empresa.actualizada', [
            'tenant_id' => $tenant->id,
            'subject' => $tenant,
            'descripcion' => 'Actualizó la configuración de la empresa (panel)',
            'metadata' => ['campos' => array_keys($update)],
        ]);

        if ($request->hasFile('logo')) {
            $path = $request->file('logo')->store("tenants/{$tenant->id}/logos", 'public');
            $tenant->update(['logo_path' => $path]);
        }

        // Favicon propio de la empresa: icono de pestaña cuando esta empresa es la activa.
        if ($request->hasFile('favicon')) {
            $path = $request->file('favicon')->store("tenants/{$tenant->id}/branding", 'public');
            $tenant->update(['favicon_path' => $path]);
        }

        if ($request->hasFile('certificate')) {
            // Convertir y validar ANTES de guardar, igual que la API.
            // Este camino guardaba el archivo crudo sin mirarlo: un .cer subido
            // desde acá quedaba almacenado como PEM sin clave privada y recién
            // fallaba días después, al firmar un comprobante.
            $archivo = $request->file('certificate');
            $certService = new \App\Services\CertificateService;

            try {
                $pemContent = $certService->convertToPem(
                    file_get_contents($archivo->getRealPath()),
                    strtolower($archivo->getClientOriginalExtension()),
                    $request->input('certificate_password'),
                );
            } catch (\RuntimeException $e) {
                return back()->withErrors(['certificate' => $e->getMessage()])->withInput();
            }

            $certPath = (new \App\Services\Storage\DocumentStorageService)
                ->storeCertificate($tenant, $pemContent, 'cert.pem');

            $tenant->update(['certificate_path' => $certPath]);

            AuditLog::registrar('certificado.actualizado', [
                'tenant_id' => $tenant->id,
                'subject' => $tenant,
                'descripcion' => 'Subió/actualizó el certificado digital (panel)',
            ]);
        }
        if ($request->filled('certificate_password')) {
            $tenant->update(['certificate_password' => $data['certificate_password']]);
        }

        Serie::firstOrCreate(
            ['tenant_id' => $tenant->id, 'tipo_documento' => '01', 'serie' => $data['serie_factura']],
            ['correlativo' => 0, 'is_active' => true]
        );

        Serie::firstOrCreate(
            ['tenant_id' => $tenant->id, 'tipo_documento' => '03', 'serie' => $data['serie_boleta']],
            ['correlativo' => 0, 'is_active' => true]
        );

        return redirect()->route('sunat.dashboard')
            ->with('success', 'Configuración SUNAT guardada correctamente.');
    }

    /**
     * Guarda (o elimina) el token propio de la empresa para consultar RUC/DNI en api.json.pe.
     * Cada empresa gestiona su propio token; no se comparte entre empresas.
     */
    public function updateConsultaToken(Request $request): \Illuminate\Http\RedirectResponse
    {
        $data = $request->validate([
            'consulta_token' => 'nullable|string|max:1000',
            'consulta_proveedor' => 'nullable|in:json,migo',
        ]);

        $tenant = app(\App\Services\Tenancy\EmpresaActiva::class)->actual();

        if (! $tenant) {
            return back()->with('error', 'No hay empresa activa.');
        }

        \Illuminate\Support\Facades\Gate::authorize('editar-empresa', $tenant);

        $cambios = ['consulta_proveedor' => $data['consulta_proveedor'] ?? 'json'];

        // El token es específico de cada proveedor: solo se reemplaza si el usuario
        // escribió uno nuevo; si lo deja vacío, se conserva el guardado (así puede
        // cambiar solo de proveedor sin re-pegar el token, o guardarlo por primera vez).
        $token = trim((string) ($data['consulta_token'] ?? ''));
        if ($token !== '') {
            $cambios['consulta_token'] = $token;
        }

        $tenant->update($cambios);

        return back()->with('success', 'Configuración de consulta RUC/DNI guardada.');
    }

    public function probarConexion(): \Illuminate\Http\JsonResponse
    {
        $tenant = app(\App\Services\Tenancy\EmpresaActiva::class)->actual();

        if (! $tenant || ! $tenant->sol_user || ! $tenant->sol_pass) {
            return response()->json(['ok' => false, 'mensaje' => 'Credenciales SOL no configuradas o empresa no registrada.']);
        }

        \Illuminate\Support\Facades\Gate::authorize('editar-empresa', $tenant);

        return response()->json([
            'ok' => true,
            'mensaje' => 'Credenciales guardadas correctamente.',
            'ambiente' => $tenant->environment === 'produccion' ? 'Producción SUNAT' : 'Beta / Homologación SUNAT',
            'ruc' => $tenant->ruc,
            'usuario' => $tenant->sol_user,
        ]);
    }
}
