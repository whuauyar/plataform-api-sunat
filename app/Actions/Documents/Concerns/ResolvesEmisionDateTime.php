<?php

namespace App\Actions\Documents\Concerns;

use Carbon\Carbon;

trait ResolvesEmisionDateTime
{
    /**
     * Normaliza fecha_emision a hora de Perú (America/Lima) como "wall-clock".
     *
     * El sistema guarda y arma el XML con la hora local de Lima. Si se guardara
     * un instante UTC, al emitir de noche (después de las 19:00, cuando en UTC ya
     * es el día siguiente) el XML saldría con IssueDate del día siguiente y SUNAT
     * lo rechaza con el código 2329.
     *
     * - Solo fecha (Y-m-d): se le agrega la hora ACTUAL de Lima.
     * - Fecha con hora (naive o con zona/Z): se interpreta/convierte a Lima.
     */
    protected function resolveEmisionDateTime(string $fecha): string
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha)) {
            return $fecha . ' ' . Carbon::now('America/Lima')->format('H:i:s');
        }

        // Un datetime naive se asume ya en hora de Lima; uno con zona (ej. "...Z")
        // se convierte a Lima. En ambos casos se guarda el wall-clock peruano.
        return Carbon::parse($fecha, 'America/Lima')
            ->setTimezone('America/Lima')
            ->format('Y-m-d H:i:s');
    }
}
