<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Services\License\LicenseClient;
use App\Services\License\MachineId;
use App\Services\Settings\SettingsService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Activación de la licencia Pro DESDE EL PANEL (antes solo por terminal).
 *
 * El cliente pega Clave + Secreto y pulsa "Activar". Las credenciales se guardan
 * en la BD (el secreto cifrado) y pisan a las de .env, de modo que regenerar el
 * .env ya no borra la licencia. Luego se registra la instalación en el servidor.
 */
class LicenciaController extends Controller
{
    public function edit(LicenseClient $license, MachineId $machineId): Response
    {
        $check = $license->check();

        return Inertia::render('admin/licencia/index', [
            'licencia' => [
                'machine_id' => $machineId->get(),
                'dominio' => (string) config('license.domain'),
                'servidor' => (string) config('license.server_url'),
                'configurada' => (bool) (config('license.key') && config('license.secret')),
                'clave_preview' => $this->preview((string) config('license.key')),
                'estado' => [
                    'permitida' => $check->allowed,
                    'codigo' => $check->code,
                    'mensaje' => $check->message,
                    'gracia' => $check->offlineGrace,
                ],
            ],
        ]);
    }

    public function activar(Request $request, LicenseClient $license, SettingsService $settings): RedirectResponse
    {
        $data = $request->validate([
            'license_key' => 'required|string|max:255',
            'license_secret' => 'required|string|max:1000',
        ]);

        $key = trim($data['license_key']);
        $secret = trim($data['license_secret']);

        // Persistir (secreto cifrado) y aplicar en caliente para que la activación
        // use las credenciales recién pegadas sin esperar al próximo arranque.
        $settings->setMany([
            SettingsService::LICENSE_KEY => $key,
            SettingsService::LICENSE_SECRET => Crypt::encryptString($secret),
        ]);
        config(['license.key' => $key, 'license.secret' => $secret]);
        Cache::forget('license:ok');

        $resultado = $license->activate();

        AuditLog::registrar('licencia.activada', [
            'descripcion' => $resultado['ok']
                ? 'Activó la licencia Pro desde el panel'
                : 'Intento de activación de licencia fallido ('.$resultado['code'].')',
            'metadata' => ['ok' => $resultado['ok'], 'codigo' => $resultado['code']],
        ]);

        if (! $resultado['ok']) {
            return back()->with('error', 'No se pudo activar ('.$resultado['code'].'): '.$resultado['message']);
        }

        return back()->with('success', 'Licencia activada: '.$resultado['message']);
    }

    private function preview(string $key): string
    {
        if ($key === '') {
            return '';
        }

        return mb_strlen($key) <= 8 ? $key : mb_substr($key, 0, 4).'…'.mb_substr($key, -4);
    }
}
