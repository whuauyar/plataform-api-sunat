# 00 — Resumen ejecutivo

## Qué es
API REST **multiempresa** de facturación electrónica SUNAT Perú, sobre **Laravel 12
+ Inertia/React**, autoalojada (se vende como código fuente). Emite todos los
comprobantes electrónicos del régimen peruano y además ofrece panel web, reportes,
SIRE y gestión SaaS.

## Cifras

| Dimensión | Valor |
|---|---|
| Rutas API (`routes/api.php`) | **203 registros** → 198 endpoints v1 |
| Requests en Postman | **317** (≈197 únicos; cobertura v1 ≈ 197/198) |
| Tipos de comprobante soportados | 11 (01, 03, 04, 07, 08, 09, 20, 31, 40, RC, RA, RR) + internos |
| Controladores API | ~30 (`app/Http/Controllers/Api/V1/`) |
| Builders Greenter | 9 |
| Jobs de cola | 12+ |
| Suite de pruebas | 342 passed, 0 failed, 6 skipped |

## Arquitectura (resumen)
`HTTP → middleware (tenant, idempotency, license, limit, log) → Controller →
FormRequest → Action (transacción, serie con lock, cálculo, persistencia) → Job de
cola → Greenter (XML UBL 2.1 + firma + envío SOAP/REST) → CDR → almacenamiento +
PDF + webhook`. La emisión es **asíncrona**: la respuesta HTTP vuelve antes de que
SUNAT confirme. Detalle en [documento 01](01-arquitectura.md).

Dos canales SUNAT: **SOAP** (facturas, notas, resúmenes, bajas, retención,
percepción, liquidación) y **REST OAuth2 GRE** (guías 09/31). Síncrono por **CDR** o
asíncrono por **ticket** según el tipo.

## Fortalezas (verificadas)
- **Motor de cálculo correcto y preciso** (valor unitario 10 dec, montos 2 dec, IGV
  por diferencia para evitar centavos). Verificado ejecutando el código real
  ([doc 12](12-pruebas-resultados.md)).
- **Clasificación de CDR por código** (no por `isSuccess()`) → distingue aceptado,
  observado, rechazado y timeout.
- **Idempotencia** (HTTP + jobs), **correlativo con lock**, **WithoutOverlapping**,
  **recoverFromCdr** → robustez ante duplicados y falsos rechazos.
- **Defensa en profundidad de licencia** (middleware + guard en el envío).
- **Secretos cifrados**, saneo de credenciales en logs, descargas autenticadas.
- Cobertura Postman casi total de la superficie `/api/v1`.

## Hallazgos principales (ver [13](13-inconsistencias.md) / [14](14-riesgos.md))

**Críticos / altos (comprobados):**
1. **Recuperación de credenciales rota** (R1): el secreto recuperado nunca
   autentica (se guarda hash, se compara claro).
2. **Alias `/sunat/*` sin gates** (R2): emite saltando el cupo del plan y sin
   idempotencia (duplicados posibles).

**Medios:**
3. Totales **overridables** sin verificación (R3).
4. **Reversión de liquidación (04)** por canal incorrecto (R4).
5. Anti-duplicado con `whereJsonContains` no fiable en PostgreSQL (R5).

**Menores / mantenibilidad:** verificador de cobertura roto (ruta de archivo),
percepción sin PDF, catálogos inline duplicados, `sunat_status` sin enum central,
filtros ad-hoc en NC/ND, etc.

## Recomendación de alto nivel
La base es **sólida y bien diseñada** (capas limpias, robustez SUNAT, cálculo
correcto). Las prioridades son: **(P1)** arreglar la recuperación de credenciales y
cerrar el alias `/sunat/*`, **(P2)** la reversión de liquidación y el anti-duplicado
Postgres, **(P3)** limpieza de consistencia. Detalle priorizado en
[documento 15](15-recomendaciones.md).

## Cómo navegar esta documentación
Empieza por [01 Arquitectura](01-arquitectura.md) y el
[índice](README.md). Para integrar, ve directo a
[`GUIA-USUARIO-COMPLETA.md`](GUIA-USUARIO-COMPLETA.md).
