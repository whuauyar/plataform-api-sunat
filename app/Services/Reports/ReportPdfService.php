<?php

namespace App\Services\Reports;

use App\Models\Tenant;
use Barryvdh\DomPDF\Facade\Pdf;

class ReportPdfService
{
    public function generate(string $view, array $data, Tenant $tenant, string $orientation = 'portrait'): string
    {
        $data['tenant'] = [
            'ruc' => $tenant->ruc,
            'razon_social' => $tenant->razon_social,
            'nombre_comercial' => $tenant->nombre_comercial ?? $tenant->razon_social,
            'direccion' => $tenant->direccion,
            'logo_base64' => $this->getLogoBase64($tenant),
        ];

        $data['generated_at'] = now()->format('d/m/Y H:i:s');

        $pdf = Pdf::loadView("pdf.reports.{$view}", $data)
            ->setPaper('a4', $orientation)
            ->setOption('isRemoteEnabled', true)
            ->setOption('dpi', 96);

        return $pdf->output();
    }

    private function getLogoBase64(Tenant $tenant): ?string
    {
        if (! $tenant->logo_path) {
            return null;
        }

        // Se lee por disco (no ruta local) para soportar storage remoto (S3/MinIO).
        $disk = \Illuminate\Support\Facades\Storage::disk('public');
        if (! $disk->exists($tenant->logo_path)) {
            return null;
        }

        $content = $disk->get($tenant->logo_path);
        $mime = $disk->mimeType($tenant->logo_path) ?: 'image/png';

        return "data:{$mime};base64," . base64_encode((string) $content);
    }
}
