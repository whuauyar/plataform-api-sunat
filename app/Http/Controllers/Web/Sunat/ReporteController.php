<?php

namespace App\Http\Controllers\Web\Sunat;

use App\Http\Controllers\Controller;
use App\Services\Reports\ReportService;
use App\Services\Tenancy\EmpresaActiva;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/** Reportes de la empresa activa (para el dueño). Reutiliza ReportService. */
class ReporteController extends Controller
{
    public function index(): Response|RedirectResponse
    {
        $tenant = app(EmpresaActiva::class)->actual();
        if (! $tenant) {
            return redirect()->route('sunat.configuracion');
        }

        return Inertia::render('sunat/reportes/index', []);
    }

    public function registroVentas(Request $request, ReportService $service): JsonResponse
    {
        $tenant = app(EmpresaActiva::class)->actualOFallar();
        Gate::authorize('ver-reportes', $tenant);

        $max = (int) config('reportes.max_rango_dias', 366);

        $filters = $request->validate([
            'fecha_desde' => 'required|date',
            'fecha_hasta' => [
                'required', 'date', 'after_or_equal:fecha_desde',
                // Acota la memoria: un rango enorme cargaría todo el histórico del tenant.
                function (string $attribute, mixed $value, \Closure $fail) use ($request, $max) {
                    if ($max > 0 && $request->filled('fecha_desde')) {
                        $dias = \Carbon\Carbon::parse($request->input('fecha_desde'))->diffInDays(\Carbon\Carbon::parse($value));
                        if ($dias > $max) {
                            $fail("El rango máximo permitido es de {$max} días.");
                        }
                    }
                },
            ],
        ]);

        try {
            $data = $service->registroVentas($tenant, $filters);

            return response()->json(['ok' => true, 'data' => $data]);
        } catch (\Throwable $e) {
            return response()->json(['ok' => false, 'error' => $e->getMessage()]);
        }
    }
}
