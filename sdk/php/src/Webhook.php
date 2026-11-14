<?php

declare(strict_types=1);

namespace JorgeChavez\ApiSunat;

/**
 * Verificación de la firma de los webhooks.
 *
 * La API firma el cuerpo con HMAC-SHA256 usando tu api_secret y lo envía en la
 * cabecera `X-Signature: sha256=<hmac>`. Verifica SIEMPRE antes de confiar en el
 * contenido del webhook.
 */
final class Webhook
{
    /**
     * @param  string  $cuerpoCrudo  el body EXACTO recibido (sin re-serializar)
     * @param  string  $firmaHeader  valor de la cabecera X-Signature
     * @param  string  $apiSecret  el api_secret de tu empresa
     */
    public static function verificar(string $cuerpoCrudo, string $firmaHeader, string $apiSecret): bool
    {
        $esperado = 'sha256='.hash_hmac('sha256', $cuerpoCrudo, $apiSecret);

        return hash_equals($esperado, $firmaHeader);
    }
}
