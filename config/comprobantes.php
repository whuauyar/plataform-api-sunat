<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Disco de almacenamiento de comprobantes (XML/CDR/PDF)
    |--------------------------------------------------------------------------
    |
    | Disco (de config/filesystems.php) donde se GUARDAN los comprobantes. Por
    | defecto el disco privado local `comprobantes` (storage/app/private). Para
    | almacenar en OTRO servidor, poner COMPROBANTES_DISK=s3 y configurar las
    | credenciales AWS y el endpoint (AWS S3, DigitalOcean Spaces o MinIO propio).
    |
    | NOTA: antes de cambiar a 's3' hay que resolver las dependencias de ruta
    | local (export ZIP y logo en PDFs). Ver documentacion/31-Arquitectura-Distribuida.md
    |
    */
    'disk' => env('COMPROBANTES_DISK', 'comprobantes'),
];
