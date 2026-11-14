# Tasas de IGV y régimen tributario

Esta API soporta la **tasa estándar de 18%** (régimen general) y cualquier **tasa reducida** (p. ej. la de MYPE Restaurantes/Hoteles/Alojamientos Turísticos según Ley 31556) mediante el campo **`igv_rate_override`**.

> **Cambio de modelo (migración `2026_07_30_160000_normalizar_tax_regime_tenants`):**
> "Restaurantes/Hoteles" **ya no es un régimen tributario**. Antes la lista mezclaba el
> régimen del contribuyente con una tasa especial. Ahora:
> - `tax_regime` solo admite los 4 regímenes reales de SUNAT (ver tabla).
> - La tasa reducida de restaurantes/hoteles se configura como **`igv_rate_override`**
>   sobre un emisor **MYPE** (`tax_regime=mype`).
> - Los valores antiguos se renombraron: `mype_restaurantes` → `mype` (con su tasa
>   congelada en `igv_rate_override`) y `nrus` → `rus`. Enviarlos hoy devuelve **422**.

## Regímenes soportados

Definidos en `config/regimenes.php` (`regimenes.lista`). Son los únicos que acepta la validación:

| Régimen                    | Aplica a                                                         | Tasa IGV por defecto |
|----------------------------|------------------------------------------------------------------|-----------------------------|
| `general` (default)        | Todas las empresas no acogidas a un régimen especial             | **18%** (16% IGV + 2% IPM)  |
| `mype`                     | Régimen MYPE Tributario (restaurantes/hoteles usan `igv_rate_override`) | **18%** (salvo override)  |
| `rer`                      | Régimen Especial de Renta                                        | **18%**                     |
| `rus`                      | Nuevo RUS: cuota mensual fija, solo boletas                      | **0%** — ver [NRUS.md](nrus.md) |

### Tasa reducida restaurantes/hoteles (Ley 31556) — tabla de referencia

Esta tabla **no se aplica sola por régimen**. Es la referencia del valor que debes poner en
`igv_rate_override` para un emisor MYPE acogido a la Ley 31556, según el año de emisión:

| Año  | Tasa combinada | Desglose                 |
|------|----------------|--------------------------|
| 2022-2024 | 10%        | 8% IGV + 2% IPM          |
| 2025 | 10% (*)        | 8% IGV + 2% IPM          |
| 2026 | **10.5%**      | 8% IGV + **2.5% IPM**    |
| 2027 | **15%**        | 12% IGV + 3% IPM         |
| 2028 | **18%**        | 14.5% IGV + 3.5% IPM     |
| 2029+ | 18%           | Régimen general          |

(*) El régimen original expiró a fines de 2024. La tabla contempla un 2025 de transición por compatibilidad; si SUNAT publica otra tabla, basta con actualizar la constante `app/Services/TaxRateService.php::LEY_31556`. Recuerda: esta tabla solo **sugiere** el valor de `igv_rate_override` (método `tasaLey31556()`); no se aplica automáticamente.

> **Importante sobre el XML**: En UBL 2.1 para SUNAT, la tasa **combinada** (por ejemplo `10.5`) se emite en un único `cbc:Percent` bajo `TaxTypeCode=1000` (IGV). **El IPM no se declara como tributo separado** — así es como SUNAT siempre manejó el IGV+IPM (el 18% estándar también es en realidad 16% IGV + 2% IPM combinados).

---

## Cómo activar el régimen reducido para un cliente

### Opción 1 — Al REGISTRAR la empresa (nuevo cliente)

`POST /api/v1/registro` acepta `tax_regime` e `igv_rate_override` opcionales. Si no los envías, queda en `general` (18%) como siempre.

**Ejemplo — Restaurante/Hotel MYPE nuevo (2026 → tasa 10.5%):**

```bash
curl -X POST https://tu-api.com/api/v1/registro \
  -F "ruc=20481234567" \
  -F "razon_social=RESTAURANT EL BUEN SABOR SAC" \
  -F "direccion=AV. AREQUIPA 1234" \
  -F "ubigeo=150101" \
  -F "departamento=Lima" \
  -F "provincia=Lima" \
  -F "distrito=Miraflores" \
  -F "sol_user=MODDATOS" \
  -F "sol_pass=MODDATOS" \
  -F "entorno=beta" \
  -F "plan=pro" \
  -F "tax_regime=mype" \
  -F "igv_rate_override=10.5" \
  -F "certificado=@certificado.pfx" \
  -F "contrasena_certificado=secret123"
```

**Respuesta:**

```json
{
  "estado": "exito",
  "mensaje": "Creado exitosamente",
  "datos": {
    "tenant_id": 5,
    "ruc": "20481234567",
    "razon_social": "RESTAURANT EL BUEN SABOR SAC",
    "entorno": "beta",
    "plan": "pro",
    "tax_regime": "mype",
    "igv_rate_override": 10.5,
    "api_key": "abc123...",
    "api_secret": "xyz789...",
    "importante": "Guarde sus credenciales..."
  }
}
```

**Ejemplo — Empresa normal (no MYPE restaurante):**

```bash
# Simplemente NO envíes tax_regime, queda en 'general' (18%) por default
curl -X POST https://tu-api.com/api/v1/registro \
  -F "ruc=20123456789" \
  -F "razon_social=COMERCIAL PERU SAC" \
  -F "direccion=AV. JAVIER PRADO 500" \
  -F "ubigeo=150122" \
  -F "sol_user=MODDATOS" \
  -F "sol_pass=MODDATOS" \
  -F "certificado=@cert.pfx" \
  -F "contrasena_certificado=secret"
```

### Opción 2 — Cambiar el régimen DESPUÉS del registro (empresa existente)

Usa `PUT /api/v1/empresa` con las credenciales del tenant. Útil cuando:
- Un cliente recién ahora se acoge al régimen MYPE
- Un cliente pierde la condición MYPE y vuelve al 18%

**Ejemplo — Activar la tasa reducida de restaurantes/hoteles a un cliente existente:**

```bash
curl -X PUT https://tu-api.com/api/v1/empresa \
  -H "X-Api-Key: $API_KEY" \
  -H "X-Api-Secret: $API_SECRET" \
  -H "Content-Type: application/json" \
  -d '{"tax_regime":"mype","igv_rate_override":10.5}'
```

> La tasa 10.5% proviene del `igv_rate_override`, **no** del régimen: `tax_regime=mype`
> por sí solo tributa 18%. Enviar `{"tax_regime":"mype_restaurantes"}` devuelve **422**.

**Respuesta:**

```json
{
  "estado": "exito",
  "mensaje": "Empresa actualizada.",
  "datos": {
    "tax_regime": "mype",
    "igv_rate_override": 10.5,
    "tasa_igv_vigente": 10.5,
    ...
  }
}
```

**Ejemplo — Volver a régimen general:**

```bash
curl -X PUT https://tu-api.com/api/v1/empresa \
  -H "X-Api-Key: $API_KEY" -H "X-Api-Secret: $API_SECRET" \
  -H "Content-Type: application/json" \
  -d '{"tax_regime":"general","igv_rate_override":null}'
```

### Opción 3 — Forzar una tasa manual (escape hatch)

Si SUNAT cambia las reglas antes de actualizar el schedule, o necesitas una tasa específica durante una transición:

```bash
curl -X PUT https://tu-api.com/api/v1/empresa \
  -H "X-Api-Key: $API_KEY" -H "X-Api-Secret: $API_SECRET" \
  -H "Content-Type: application/json" \
  -d '{"igv_rate_override":10.5}'
```

`igv_rate_override` **tiene precedencia** sobre `tax_regime` y sobre el schedule. Para limpiarlo: `"igv_rate_override": null`.

### Opción 4 — Vía base de datos (último recurso)

```sql
UPDATE tenants SET tax_regime = 'mype', igv_rate_override = 10.5 WHERE ruc = '20XXXXXXXXX';
```

### Consultar el estado actual de un tenant

`GET /api/v1/empresa` ahora devuelve tres campos nuevos:

```json
{
  "tax_regime": "mype",
  "igv_rate_override": 10.5,
  "tasa_igv_vigente": 10.5
}
```

`tasa_igv_vigente` es la tasa efectiva que se aplicará a las facturas emitidas hoy (toma en cuenta override → régimen).

### Precedencia final (de mayor a menor prioridad)

Según `TaxRateService::rateForItem()` + `defaultIgvRate()`:

1. `item.porcentaje_igv` (si el request lo envía explícito)
2. `item.tip_afe_igv` especial: `17` (IVAP) → 4%; exonerado/inafecto/exportación/gratuita → 0%
3. `tenant.igv_rate_override` (forzado manual)
4. `tax_regime=rus` → 0% (inafecto)
5. `18.0` (general, mype, rer — fallback)

> No existe un paso de "schedule automático por régimen": `mype`/`rer`/`general`
> tributan 18% salvo que se defina `igv_rate_override`.

---

## Cómo emitir con tasa explícita por ítem

El API ya acepta `porcentaje_igv` a nivel de ítem. Si lo envías, se respeta sin importar el régimen:

```json
POST /api/v1/facturas
{
  "serie": "F001",
  "fecha_emision": "2026-03-15",
  "cliente": { "tipo_doc": "6", "num_doc": "20555666777", "razon_social": "ACME SAC" },
  "items": [
    {
      "codigo": "REST001",
      "descripcion": "MENÚ EJECUTIVO",
      "unidad": "NIU",
      "cantidad": 1,
      "precio_unitario": 22.10,
      "tip_afe_igv": "10",
      "porcentaje_igv": 10.5
    }
  ]
}
```

---

## Requisitos SUNAT para acogerse al régimen MYPE

1. Empresa debe ser **MYPE** (Micro o Pequeña Empresa).
2. Actividad principal en ficha RUC: **restaurantes**, **hoteles** o **alojamientos turísticos**.
3. Ingresos anuales **no superan 1700 UIT**.
4. Al generar el XML, la tasa del IGV debe calzar exactamente con lo declarado (la API lo garantiza cuando `tax_regime` está bien configurado).

La validación de estas condiciones **NO** la hace la API — es responsabilidad del administrador configurar `tax_regime` correctamente por tenant. Si configuras el régimen a una empresa no elegible, SUNAT puede rechazar los XML y aplicar sanciones.

---

## Referencias en el código

| Ubicación                                                        | Descripción                                  |
|------------------------------------------------------------------|----------------------------------------------|
| `app/Services/TaxRateService.php`                                | Servicio central: tasa por régimen + override + referencia `LEY_31556` |
| `app/Services/DocumentCalculationService.php`                    | Usa el servicio en cálculos de totales       |
| `app/Services/Greenter/Builders/InvoiceBuilder.php`              | Aplica la tasa en el XML (ítem por ítem)     |
| `app/Services/Greenter/Builders/NoteBuilder.php`                 | Idem para notas de crédito/débito            |
| `config/regimenes.php`                                           | Lista única de regímenes válidos (`rus/rer/mype/general`) |
| `database/migrations/2026_04_18_120000_add_tax_regime_to_tenants.php` | Migración con los campos del tenant     |
| `database/migrations/2026_07_30_160000_normalizar_tax_regime_tenants.php` | Renombró `nrus→rus` y `mype_restaurantes→mype` (+ congeló override) |
| `tests/Unit/TaxRateServiceTest.php`                              | Suite de pruebas del servicio               |

---

## Pruebas end-to-end realizadas contra SUNAT beta (2026-04-18)

Tenant real usado: `20161515648 - EMPRESA DE PRUEBAS GRE SAC`

| # | Escenario | Config tenant | Fecha emisión | Precio | Valor | IGV | Tasa aplicada | Estado SUNAT |
|---|-----------|---------------|---------------|--------|-------|-----|---------------|--------------|
| 1 | Régimen general | `tax_regime=general` | 2026-04-18 | 23.60 | 20.00 | 3.60 | **18.00%** | ✅ Aceptada (code 0) |
| 2 | MYPE Restaurantes 2026 | `tax_regime=mype, igv_rate_override=10.5` | 2026-04-18 | 22.10 | 20.00 | 2.10 | **10.50%** | ✅ Aceptada (code 0) |
| 3 | MYPE Restaurantes 2027 | `tax_regime=mype, igv_rate_override=15.0` | 2027-06-15 | 115.00 | 100.00 | 15.00 | **15.00%** | (cálculo OK, no enviada) |
| 4 | Override manual | `igv_rate_override=12.00` | 2026-04-18 | 112.00 | 100.00 | 12.00 | **12.00%** | (cálculo OK) |

> Nota: las pruebas originales (2026-04-18) se corrieron bajo el modelo anterior
> (`tax_regime=mype_restaurantes`). La columna "Config tenant" se muestra ya con el
> modelo vigente (`mype` + `igv_rate_override`), que produce exactamente la misma tasa y XML.

### XML emitido para el caso 10.5% (Factura F001-000008)

```xml
<cac:TaxSubtotal>
  <cbc:TaxableAmount currencyID="PEN">20.00</cbc:TaxableAmount>
  <cbc:TaxAmount currencyID="PEN">2.10</cbc:TaxAmount>
  <cac:TaxCategory>
    <cbc:Percent>10.5</cbc:Percent>
    <cbc:TaxExemptionReasonCode>10</cbc:TaxExemptionReasonCode>
    <cac:TaxScheme>
      <cbc:ID>1000</cbc:ID>
      <cbc:Name>IGV</cbc:Name>
      <cbc:TaxTypeCode>VAT</cbc:TaxTypeCode>
    </cac:TaxScheme>
  </cac:TaxCategory>
</cac:TaxSubtotal>
```

**Resultado SUNAT:** `Factura numero F001-8, ha sido aceptada` (status code `0`, estado `aceptado`). Esto **confirma que SUNAT acepta la tasa 10.5% bajo TaxScheme 1000 (IGV)** sin declarar IPM por separado — tal como es el comportamiento histórico con el 18% estándar.

### ⚠️ Nota operativa sobre caché

Durante las pruebas se observó que, al cambiar `tax_regime` o `igv_rate_override` de un tenant en base de datos, el OPcache de Laragon/Apache puede mantener la versión anterior cargada por algunos segundos. Si los totales no cambian inmediatamente, ejecutar:

```bash
php artisan cache:clear
php artisan config:clear
```

O reiniciar el servicio PHP/Apache.

---

## Actualizar la tabla de referencia en el futuro

Si SUNAT modifica las tasas de la Ley 31556 (ampliación, extensión), edita la constante
`LEY_31556` (un mapa `año => tasa`, no un schedule por régimen):

```php
// app/Services/TaxRateService.php
public const LEY_31556 = [
    2026 => 10.5,
    2027 => 15.0,
    2028 => 18.0,
    // ... actualizar aquí
];
```

Esta constante solo alimenta la sugerencia de `igv_rate_override` (`tasaLey31556()`); no cambia
la tasa de ningún tenant por sí sola. Las constantes de régimen reales son `REGIMEN_GENERAL`
y `REGIMEN_RUS` (no existe `REGIMEN_MYPE_RESTAURANTES` ni una constante `SCHEDULE`).
