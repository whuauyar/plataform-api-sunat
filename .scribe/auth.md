# Authenticating requests

To authenticate requests, include a **`X-Api-Key`** header with the value **`"{TU_API_KEY}"`**.

All authenticated endpoints are marked with a `requires authentication` badge in the documentation below.

Cada petición debe enviar dos cabeceras: <code>X-Api-Key</code> y <code>X-Api-Secret</code>. Ambas se obtienen en el panel de tu empresa (sección Credenciales / Generar credenciales). <b>El secreto solo se muestra al generarlo; guárdalo en un lugar seguro.</b>
