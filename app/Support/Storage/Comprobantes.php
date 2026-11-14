<?php

declare(strict_types=1);

namespace App\Support\Storage;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;

/**
 * Almacenamiento de comprobantes (XML/CDR/PDF) en disco PRIVADO.
 *
 * Los archivos se ESCRIBEN siempre en el disco privado `comprobantes`
 * (storage/app/private), que NO es accesible por URL — se descargan solo por los
 * endpoints autenticados. Las LECTURAS caen en fallback al disco `public` para
 * los archivos antiguos que aún no se han movido (ver comando
 * comprobantes:mover-a-privado). Así la migración no rompe descargas existentes.
 *
 * Reemplaza los antiguos `Storage::disk('public')->...` de documentos. Los
 * logos/favicones siguen en `public` (se sirven por URL a propósito).
 */
class Comprobantes
{
    public const DISK = 'comprobantes';

    /** Disco destino configurable (local privado por defecto; s3/MinIO para otro servidor). */
    private static function diskName(): string
    {
        return (string) config('comprobantes.disk', self::DISK);
    }

    private static function priv(): Filesystem
    {
        return Storage::disk(self::diskName());
    }

    private static function pub(): Filesystem
    {
        return Storage::disk('public');
    }

    /** Escribe SIEMPRE en el disco privado. */
    public static function put(string $path, mixed $contents): bool
    {
        return (bool) self::priv()->put($path, $contents);
    }

    /** Existe en privado o (legacy) en público. */
    public static function exists(string $path): bool
    {
        return self::priv()->exists($path) || self::pub()->exists($path);
    }

    /** Contenido desde privado; si no, legacy público. */
    public static function get(string $path): ?string
    {
        if (self::priv()->exists($path)) {
            return self::priv()->get($path);
        }
        if (self::pub()->exists($path)) {
            return self::pub()->get($path);
        }

        return null;
    }

    /** Respuesta de descarga desde el disco que tenga el archivo. */
    public static function download(string $path, ?string $name = null, array $headers = [])
    {
        $disk = self::priv()->exists($path) ? self::priv() : self::pub();

        return $disk->download($path, $name, $headers);
    }

    /** Ruta absoluta en el disco que tenga el archivo (privado preferido). */
    public static function path(string $path): string
    {
        $disk = self::priv()->exists($path) ? self::priv() : self::pub();

        return $disk->path($path);
    }

    /** Borra en ambos discos (privado y legacy público). */
    public static function delete(string $path): void
    {
        self::priv()->delete($path);
        self::pub()->delete($path);
    }
}
