# Introduction

API REST para la emisión electrónica ante SUNAT (facturas, boletas, notas de crédito/débito, guías de remisión, resúmenes y comunicaciones de baja).

<aside>
    <strong>Base URL</strong>: <code>http://api-pro.test</code>
</aside>

    Esta documentación describe todos los endpoints de la API para integrar la emisión electrónica ante SUNAT.

    <aside>A la derecha (o integrado en el contenido en móvil) verás ejemplos de código en varios lenguajes; puedes cambiar el lenguaje con las pestañas de arriba.</aside>

    **Autenticación:** toda petición requiere las cabeceras <code>X-Api-Key</code> y <code>X-Api-Secret</code> de tu empresa (las obtienes en el panel). No se aceptan credenciales por query string.

    **Ambientes:** usa <code>beta</code> para pruebas y <code>produccion</code> para emisión real (se configura por empresa en el panel).

    **Idempotencia:** en las emisiones puedes enviar la cabecera <code>Idempotency-Key</code> para evitar comprobantes duplicados ante reintentos de red.

