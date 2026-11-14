# 04 — Matriz Postman vs Código

## Cobertura global

| Métrica | Valor |
|---|---|
| Rutas API v1 (normalizadas) | **198** |
| Requests Postman (únicos método+path) | **197** |
| Intersección (cubiertas por Postman) | **193** |
| Cobertura v1 efectiva | **≈ 197/198** |

> La diferencia respecto a “317 requests” es que Postman incluye muchas
> **variantes de ejemplo** del mismo endpoint (ver [documento 02](02-inventario-postman.md)).

## El verificador oficial está roto

`tools/check-postman-coverage.php` **no corre**: en `:5` apunta a un archivo
inexistente (`API SUNAT PRO V2.1 ✅✅✅✅✅.postman_collection.json`). El archivo
real es `API FACTURACION SUNAT PERU ✅✅✅✅✅.postman_collection.json`. Falla con:

```
Warning: file_get_contents(...API SUNAT PRO V2.1...): Failed to open stream
Fatal error: walkExtract(): Argument #1 ($items) must be of type array, null given (EXIT=255)
```

**Recomendación:** actualizar la ruta del archivo en el tool (ver
[documento 15](15-recomendaciones.md)). La cobertura de este informe se derivó
manualmente con la misma lógica apuntando a la colección real + `route:list`.

## (A) Rutas implementadas que NO están en Postman

| Ruta | Nota |
|---|---|
| `GET /v1/sunat/estado` (`api.php:49`) | **Única ruta v1 real sin request en Postman.** |
| `GET /api/health` (`:35`) | Fuera de v1. |
| `GET /api/debug/lookup` (`:339`) | Solo `APP_DEBUG`; diagnóstico. |
| `POST /api/bridge/auth`,`/provision`,`DELETE /tenant` (`:401-403`) | Bridge SaaS interno (`X-Bridge-Key`). |
| Alias `/api/sunat/*` — 10 rutas (`:410-424`) | configuracion, facturas/boletas index/store/pdf/xml/anular. |

## (B) Requests de Postman que NO existen en el código

**Ninguno real.** El comparador marca 4 como “extra” por un artefacto de
normalización: Postman usa el literal `actual` donde la ruta define el parámetro
`{variant}` (`api.php:301-305`, `sire/rce/{id}/ajustes-posteriores/{variant}/...`).
Sí mapean a la ruta real; el tool no normaliza la palabra `actual`.

## (C) Discrepancias de método/path

- **Ajustes-posteriores SIRE** (4): mismo método, path con `actual` vs `{variant}`
  → equivalentes.
- **Cotización estado**: la API/Postman usan **PUT** (`api.php:235`), pero el panel
  **web** usa **PATCH** para el equivalente (`web.php:159`). API y Postman son
  consistentes entre sí; difieren del panel web.
- No se detectaron otras discrepancias de método: la intersección (193) confirma
  método+path idénticos.

## Conclusión

La documentación Postman está **muy completa para la superficie `/api/v1`**
(≈197/198; solo falta documentar `GET sunat/estado`). Quedan sin documentar, por
diseño, los bloques fuera de `/api/v1`: `health`, `bridge/*` y el **alias
`sunat/*`** (14 rutas) — este último además con un stack de middleware reducido
que merece atención de seguridad (ver [documento 14](14-riesgos.md)).
