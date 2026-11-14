<?php

declare(strict_types=1);

namespace JorgeChavez\ApiSunat;

/**
 * Error devuelto por la API. Expone el envelope { estado, mensaje, errores }.
 */
class ApiException extends \RuntimeException
{
    /**
     * @param  int  $status  código HTTP
     * @param  array<string, array<int, string>>  $errores  errores de validación por campo
     * @param  array<string, mixed>  $cuerpo  cuerpo JSON completo de la respuesta
     */
    public function __construct(
        string $mensaje,
        public readonly int $status = 0,
        public readonly array $errores = [],
        public readonly array $cuerpo = [],
    ) {
        parent::__construct($mensaje, $status);
    }

    /** ¿Es un error de validación (422)? */
    public function esValidacion(): bool
    {
        return $this->status === 422;
    }

    /** ¿Conviene reintentar? (rate limit o error de servidor) */
    public function esReintentable(): bool
    {
        return $this->status === 429 || $this->status >= 500;
    }
}
