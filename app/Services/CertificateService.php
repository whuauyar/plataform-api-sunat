<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use RuntimeException;
use Symfony\Component\Process\Process;

class CertificateService
{
    /** Último error (stderr) del binario openssl, para diagnóstico. */
    private ?string $lastCliError = null;

    /**
     * Convierte un certificado (PFX o PEM) a formato PEM.
     * Si ya es PEM, lo retorna tal cual.
     * Si es PFX, lo convierte usando el password proporcionado.
     */
    public function convertToPem(string $content, string $originalExtension, ?string $password = null): string
    {
        $extension = strtolower($originalExtension);

        if (in_array($extension, ['pem', 'cer', 'crt'])) {
            // Validar que sea un PEM válido
            if (str_contains($content, '-----BEGIN')) {
                return $this->asegurarClavePrivada($content);
            }

            // Puede ser un DER binario, intentar convertir
            $pem = $this->derToPem($content);
            if ($pem) {
                return $this->asegurarClavePrivada($pem);
            }

            throw new RuntimeException('El archivo no contiene un certificado PEM válido.');
        }

        if (in_array($extension, ['pfx', 'p12'])) {
            return $this->asegurarClavePrivada($this->pfxToPem($content, $password ?? ''));
        }

        throw new RuntimeException("Formato de certificado no soportado: .{$extension}");
    }

    /**
     * Rechaza un certificado que no sirva para firmar.
     *
     * Antes bastaba con que el archivo contuviera un "-----BEGIN" para darlo por
     * bueno. Un .cer o .crt lo tiene, pero solo lleva el certificado PÚBLICO —y
     * es justo el archivo que SUNAT deja descargar de su portal, así que es el
     * error más fácil de cometer.
     *
     * El problema no era aceptarlo, era CUÁNDO se notaba: el archivo se
     * guardaba sin chistar y recién fallaba días después al firmar un
     * comprobante, con un "openssl_sign(): Supplied key param cannot be coerced
     * into a private key" que no le dice nada a nadie. Para entonces ya hay
     * boletas emitidas que nunca llegaron a SUNAT y un plazo de 7 días corriendo.
     *
     * Validar acá cuesta un milisegundo y el usuario todavía tiene el archivo
     * correcto a mano.
     */
    private function asegurarClavePrivada(string $pem): string
    {
        if (@openssl_x509_read($pem) === false) {
            throw new RuntimeException(
                'El archivo no contiene un certificado digital válido.'
            );
        }

        if (@openssl_pkey_get_private($pem) === false) {
            // La clave está, pero cifrada con una frase de paso. Pasa cuando se
            // convierte el .p12 sin -nodes. Greenter tampoco podría usarla, así
            // que se rechaza igual — pero el motivo es otro y la solución
            // también, y decir "no tiene clave privada" mandaría a buscar el
            // archivo equivocado.
            if (str_contains($pem, 'ENCRYPTED PRIVATE KEY') || str_contains($pem, 'Proc-Type: 4,ENCRYPTED')) {
                throw new RuntimeException(
                    'La clave privada del archivo está protegida con una contraseña propia y no se puede usar '
                    .'para firmar. Al convertir el .p12 hay que agregar -nodes: '
                    .'openssl pkcs12 -in certificado.p12 -out certificado.pem -nodes'
                );
            }

            throw new RuntimeException(
                'El archivo tiene el certificado pero NO la clave privada, así que no sirve para firmar '
                .'comprobantes. Si convertiste tu .p12, la conversión descartó la clave: repetila con '
                .'openssl pkcs12 -in certificado.p12 -out certificado.pem -nodes (sin -nokeys). '
                .'También podés subir el .p12 tal cual, con su contraseña.'
            );
        }

        return $pem;
    }

    /**
     * Convierte PFX/P12 a PEM usando openssl.
     */
    private function pfxToPem(string $pfxContent, string $password): string
    {
        $original = $pfxContent;
        $pfxContent = $this->normalizarPfxBinario($pfxContent);

        $certs = [];

        if (openssl_pkcs12_read($pfxContent, $certs, $password) && ! empty($certs)) {
            $pem = ($certs['cert'] ?? '').($certs['pkey'] ?? '');

            if ($pem !== '') {
                return $pem;
            }

            throw new RuntimeException('El archivo PFX no contiene certificado ni clave privada.');
        }

        // Se vacía la cola de errores de OpenSSL para capturar el motivo real de
        // la lectura nativa (p. ej. "mac verify failure" = password equivocado).
        $nativeErrors = [];
        while (($e = openssl_error_string()) !== false) {
            $nativeErrors[] = $e;
        }

        // openssl_pkcs12_read falló. En PHP 8.3+ (OpenSSL 3) esto NO significa
        // password equivocado: OpenSSL 3 rechaza por defecto los PFX cifrados con
        // algoritmos viejos (RC2-40 / 3DES), y JUSTO así los emite SUNAT. El
        // binario openssl sí los lee con -legacy, así que se reintenta por CLI
        // antes de dar el certificado por malo.
        $pem = $this->pfxToPemCli($pfxContent, $password);

        if ($pem !== null) {
            return $pem;
        }

        // Ambas vías fallaron: se deja en el log el motivo exacto para no tener
        // que adivinar password vs. formato. No se registra el password.
        Log::warning('No se pudo leer un PFX (lectura nativa y CLI fallaron).', [
            'pfx_bytes' => strlen($pfxContent),
            'orig_bytes' => strlen($original),
            'orig_head_hex' => bin2hex(substr($original, 0, 24)),
            'orig_head_ascii' => preg_replace('/[^\x20-\x7E]/', '.', substr($original, 0, 24)),
            'native_errors' => $nativeErrors,
            'cli_error' => $this->lastCliError,
        ]);

        // El carácter de reemplazo UTF-8 (U+FFFD = EF BF BD) delata que el binario
        // fue tratado como texto y perdió bytes: el .pfx se abrió/guardó con un
        // editor, se copió y pegó, o se envió como texto. Es IRRECUPERABLE en el
        // servidor, así que se avisa con precisión en vez del mensaje de password.
        if (str_contains($original, "\xEF\xBF\xBD")) {
            throw new RuntimeException(
                'El archivo del certificado llegó dañado: fue tratado como texto y perdió parte de su '
                .'contenido binario (se detectaron caracteres de reemplazo "�"). Esto ocurre al abrir y '
                .'guardar el .pfx con un editor de texto, al copiar y pegar su contenido, o al enviarlo como '
                .'texto en lugar de archivo. Suba el archivo .pfx ORIGINAL tal como lo obtuvo de SUNAT, '
                .'adjuntándolo como archivo (no pegue su contenido).'
            );
        }

        throw new RuntimeException(
            'No se pudo leer el archivo PFX. Verifique que el password sea correcto. '
            .'Si el password es correcto, el certificado puede usar un formato antiguo '
            .'que el servidor no pudo abrir; vuelva a exportarlo desde SUNAT o conviértalo con '
            .'"openssl pkcs12 -legacy -in cert.pfx -out cert.pem -nodes".'
        );
    }

    /**
     * Normaliza el contenido de un PFX a binario DER.
     *
     * Un PKCS12 real es DER y empieza con 0x30 (SEQUENCE). Pero es común que el
     * archivo llegue como TEXTO base64: el .pfx se codificó en algún paso, se
     * guardó el "contenido" en lugar del binario, o se renombró un .txt. En ese
     * caso openssl falla con "asn1 ... too long" (lee un carácter ASCII como si
     * fuera una longitud). Si el contenido no es DER pero es base64 válido que
     * decodifica a algo que empieza en 0x30, se usa el binario decodificado. Un
     * .pfx binario normal se devuelve intacto.
     */
    private function normalizarPfxBinario(string $content): string
    {
        // 1) DER directo: un PKCS12 real empieza con 0x30 (SEQUENCE).
        if (isset($content[0]) && $content[0] === "\x30") {
            return $content;
        }

        // 2) Quitar BOM (UTF-8/UTF-16) por si llegó como texto con marca de orden.
        foreach (["\xEF\xBB\xBF", "\xFF\xFE", "\xFE\xFF"] as $bom) {
            if (str_starts_with($content, $bom)) {
                $content = substr($content, strlen($bom));
                break;
            }
        }

        if (isset($content[0]) && $content[0] === "\x30") {
            return $content;
        }

        // 3) Intentar como base64, tolerando cabeceras tipo PEM ("-----BEGIN...-----"),
        //    saltos de línea, comillas, espacios y la variante url-safe (-_). Nos
        //    quedamos solo con caracteres base64 y decodificamos; si el resultado
        //    empieza en 0x30, es el PFX binario.
        $texto = preg_replace('/-----[^-]*-----/', '', $content) ?? $content;
        $texto = preg_replace('#[^A-Za-z0-9+/_=-]#', '', $texto) ?? '';
        $texto = strtr($texto, '-_', '+/');
        $texto = rtrim($texto, '=');

        if (strlen($texto) >= 100) {
            $decoded = base64_decode($texto, false);

            if ($decoded !== false && isset($decoded[0]) && $decoded[0] === "\x30") {
                return $decoded;
            }
        }

        return $content;
    }

    /**
     * Lee un PFX con el binario `openssl` cuando openssl_pkcs12_read() no puede.
     *
     * Devuelve el PEM (cert + clave sin cifrar) o null si tampoco se pudo. Se
     * intenta primero SIN -legacy y luego CON -legacy, para cubrir tanto PFX
     * modernos como los de algoritmos viejos de SUNAT. El password se pasa por
     * variable de entorno (no por argv) para que no quede en la lista de procesos.
     */
    private function pfxToPemCli(string $pfxContent, string $password): ?string
    {
        $tmp = tempnam(sys_get_temp_dir(), 'pfx_');

        if ($tmp === false) {
            return null;
        }

        file_put_contents($tmp, $pfxContent);

        try {
            foreach ([false, true] as $legacy) {
                $args = ['openssl', 'pkcs12'];
                if ($legacy) {
                    $args[] = '-legacy';
                }
                array_push($args, '-in', $tmp, '-nodes', '-passin', 'env:PFX_PASSWORD');

                $process = new Process($args, null, ['PFX_PASSWORD' => $password]);
                $process->run();

                if ($process->isSuccessful()) {
                    $out = $process->getOutput();

                    if (str_contains($out, '-----BEGIN') && str_contains($out, 'PRIVATE KEY')) {
                        return $out;
                    }
                }

                // Se guarda el stderr del último intento para diagnóstico.
                $err = trim($process->getErrorOutput());
                if ($err !== '') {
                    $this->lastCliError = ($legacy ? '[-legacy] ' : '[normal] ').$err;
                }
            }
        } catch (\Throwable $e) {
            // Binario openssl ausente o no ejecutable: no es fatal, se reporta
            // como "no se pudo leer" más arriba.
            return null;
        } finally {
            @unlink($tmp);
        }

        return null;
    }

    /**
     * Intenta convertir un certificado DER binario a PEM.
     */
    private function derToPem(string $derContent): ?string
    {
        $cert = @openssl_x509_read($derContent);

        if ($cert === false) {
            // Intentar como DER
            $pem = "-----BEGIN CERTIFICATE-----\n"
                .chunk_split(base64_encode($derContent), 64, "\n")
                ."-----END CERTIFICATE-----\n";

            $cert = @openssl_x509_read($pem);

            if ($cert === false) {
                return null;
            }

            return $pem;
        }

        openssl_x509_export($cert, $output);

        return $output;
    }
}
