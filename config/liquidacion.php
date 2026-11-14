<?php

/*
|--------------------------------------------------------------------------
| Liquidación de compra (comprobante tipo 04)
|--------------------------------------------------------------------------
| Tasas y excepciones en configuración porque cambian por norma. Fuentes:
|  - retencion_tasa: retención del Impuesto a la Renta 1.5% (RS 234-2005/SUNAT art. 5).
|  - retencion_tasa_oro: 4% para compra de oro (tipo de operación 0503).
|  - retencion_minimo: sin retención si el importe de la operación (sin tributos) es
|    igual o inferior a S/ 700 (RS 234-2005/SUNAT arts. 1 d) y 6).
|  - agro_exento_hasta: productos primarios agropecuarios excluidos de la retención
|    hasta esa fecha (prórroga hasta el 31/12/2026).
|  - uit: valor de la UIT por año; limite_uit: ventas anuales del vendedor (75 UIT, SUNAT 2451).
|  - reversion_dias: reversión hasta el 5.º día desde la emisión (RS 000047-2026/SUNAT).
*/

return [
    'igv_tasa' => (float) env('LC_IGV_TASA', 18),
    'retencion_tasa' => (float) env('LC_RETENCION_RENTA_TASA', 1.5),
    'retencion_tasa_oro' => (float) env('LC_RETENCION_RENTA_TASA_ORO', 4),
    'retencion_minimo' => (float) env('LC_RETENCION_RENTA_MINIMO', 700),
    'agro_exento_hasta' => env('LC_AGRO_EXENTO_HASTA', '2026-12-31'),

    'uit' => [
        2025 => (float) env('LC_UIT_2025', 5350),
        2026 => (float) env('LC_UIT_2026', 5500),
    ],
    'limite_uit' => (int) env('LC_LIMITE_UIT', 75),
    'reversion_dias' => (int) env('LC_REVERSION_DIAS', 5),
];
