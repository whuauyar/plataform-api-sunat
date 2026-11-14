<?php

namespace App\Services\Reports;

use App\Models\Tenant;
use Illuminate\Support\Collection;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * Genera un archivo Excel (.xlsx) de un reporte. Pensado para los reportes tipo "listado de
 * comprobantes" (registro de ventas): encabezado de la empresa, tabla de documentos con formato
 * de montos y una fila de totales. El tenant ya viene resuelto, así que el Excel es por-empresa.
 */
class ReportExcelService
{
    /** Columnas del listado de comprobantes: clave de la fila => encabezado visible. */
    private const COLUMNS = [
        'fecha_emision' => 'Fecha',
        'tipo_doc_nombre' => 'Tipo',
        'numero_completo' => 'Número',
        'client_num_doc' => 'Doc. Cliente',
        'client_razon_social' => 'Cliente',
        'mto_oper_gravadas' => 'Gravadas',
        'mto_oper_exoneradas' => 'Exoneradas',
        'mto_oper_inafectas' => 'Inafectas',
        'mto_oper_gratuitas' => 'Gratuitas',
        'mto_igv' => 'IGV',
        'mto_isc' => 'ISC',
        'mto_icbper' => 'ICBPER',
        'mto_imp_venta' => 'Total',
        'tipo_moneda' => 'Moneda',
        'estado_sunat' => 'Estado',
    ];

    private const NUMERIC = [
        'mto_oper_gravadas', 'mto_oper_exoneradas', 'mto_oper_inafectas', 'mto_oper_gratuitas',
        'mto_igv', 'mto_isc', 'mto_icbper', 'mto_imp_venta',
    ];

    public function generate(string $report, array $data, Tenant $tenant): string
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Reporte');

        $totalCols = count(self::COLUMNS);

        // --- Encabezado de la empresa ---
        $sheet->setCellValue([1, 1], $data['titulo'] ?? 'REPORTE');
        $sheet->getStyle([1, 1])->getFont()->setBold(true)->setSize(14);
        $sheet->setCellValue([1, 2], ($tenant->nombre_comercial ?: $tenant->razon_social) . ' — RUC ' . $tenant->ruc);
        if (! empty($data['periodo'])) {
            $sheet->setCellValue([1, 3], 'Periodo: ' . $data['periodo']['desde'] . ' a ' . $data['periodo']['hasta']);
        }
        $sheet->setCellValue([1, 4], 'Generado: ' . now()->format('d/m/Y H:i:s'));

        // --- Fila de encabezados de la tabla ---
        $headerRow = 6;
        $col = 1;
        foreach (self::COLUMNS as $label) {
            $sheet->setCellValue([$col, $headerRow], $label);
            $col++;
        }
        $sheet->getStyle([1, $headerRow, $totalCols, $headerRow])->getFont()->setBold(true);
        $sheet->getStyle([1, $headerRow, $totalCols, $headerRow])->getFill()
            ->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('E8EEF7');

        // --- Filas de documentos ---
        $docs = $data['documentos'] ?? [];
        if ($docs instanceof Collection) {
            $docs = $docs->toArray();
        }
        $row = $headerRow + 1;
        foreach ($docs as $doc) {
            $col = 1;
            foreach (array_keys(self::COLUMNS) as $key) {
                $value = is_array($doc) ? ($doc[$key] ?? '') : ($doc->{$key} ?? '');
                $sheet->setCellValue([$col, $row], $value);
                if (in_array($key, self::NUMERIC, true)) {
                    $sheet->getStyle([$col, $row])->getNumberFormat()->setFormatCode('#,##0.00');
                }
                $col++;
            }
            $row++;
        }

        // --- Fila de totales (gran_total tiene las mismas claves de monto que las filas) ---
        if (! empty($data['gran_total'])) {
            $total = (array) $data['gran_total'];
            $sheet->setCellValue([1, $row], 'TOTALES');
            $col = 1;
            foreach (array_keys(self::COLUMNS) as $key) {
                if (in_array($key, self::NUMERIC, true) && isset($total[$key])) {
                    $sheet->setCellValue([$col, $row], (float) $total[$key]);
                    $sheet->getStyle([$col, $row])->getNumberFormat()->setFormatCode('#,##0.00');
                }
                $col++;
            }
            $sheet->getStyle([1, $row, $totalCols, $row])->getFont()->setBold(true);
        }

        // Ancho automático de columnas
        for ($i = 1; $i <= $totalCols; $i++) {
            $sheet->getColumnDimensionByColumn($i)->setAutoSize(true);
        }
        $sheet->freezePane('A' . ($headerRow + 1));

        $writer = new Xlsx($spreadsheet);
        ob_start();
        $writer->save('php://output');
        $content = ob_get_clean();
        $spreadsheet->disconnectWorksheets();

        return $content;
    }
}
