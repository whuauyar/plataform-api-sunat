<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Ventana del feed "Logs de envíos" (panel admin)
    |--------------------------------------------------------------------------
    |
    | La vista admin/logs-envios es un feed EN VIVO que se auto-refresca cada
    | 10 s y hace un UNION ALL de facturas + boletas + notas de TODAS las
    | empresas. Para que ese refresco no recorra todo el histórico en cada
    | ciclo, por defecto solo considera los comprobantes de los últimos N días.
    |
    | Una búsqueda explícita del admin (RUC, serie, error…) levanta la ventana
    | y busca en todo el histórico, porque es una acción puntual, no el feed.
    |
    | 0 = sin ventana (comportamiento anterior: todo el histórico siempre).
    |
    */
    'logs_envios_dias' => (int) env('REPORTES_LOGS_ENVIOS_DIAS', 30),

    /*
    |--------------------------------------------------------------------------
    | Rango máximo de un reporte (en días)
    |--------------------------------------------------------------------------
    |
    | Los reportes (Registro de Ventas, consolidados…) cargan en memoria los
    | comprobantes del rango pedido. Para que una petición con un rango enorme
    | no agote la memoria del servidor, se limita el span fecha_desde→fecha_hasta.
    |
    | Default 366 (un año), que ya era el tope del endpoint de la API; un
    | reporte tributario normal es mensual/anual, así que no afecta el uso real.
    | Bájalo en servidores modestos con tenants de alto volumen. 0 = sin límite.
    |
    */
    'max_rango_dias' => (int) env('REPORTES_MAX_RANGO_DIAS', 366),

    /*
    |--------------------------------------------------------------------------
    | Caché de las métricas del dashboard admin (segundos)
    |--------------------------------------------------------------------------
    |
    | El dashboard admin calcula ~40 consultas agregadas (counts/sums/group-by
    | sobre todos los comprobantes) en cada carga. Es un panel de monitoreo, así
    | que se cachea el resultado por N segundos para no repetir ese trabajo en
    | cada visita/refresco. 0 = sin caché (recalcula siempre).
    |
    */
    'dashboard_cache_segundos' => (int) env('REPORTES_DASHBOARD_CACHE_SEGUNDOS', 300),
];
