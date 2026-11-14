# 05 — Controladores y servicios

La API separa responsabilidades en capas: **Controller** (HTTP + validación vía
FormRequest) → **Action** (orquestación transaccional) → **Services** (cálculo,
XML, envío, almacenamiento). Los controladores son delgados.

## Controladores API (`app/Http/Controllers/Api/V1/`)

| Controlador | Recurso | Nota |
|---|---|---|
| `InvoiceController` | Facturas 01 | store/masivo/update/enviar/reenviar/xml/cdr/pdf |
| `BoletaController` | Boletas 03 | + `destroy`, `solo_registro` |
| `CreditNoteController` | NC 07 | sin destroy |
| `DebitNoteController` | ND 08 | sin destroy |
| `DispatchGuideController` | Guías 09/31 | REST GRE, `storeTransportista` |
| `SummaryController` | Resumen RC | informar / anular |
| `VoidedController` | Baja RA | atajos `.../anular` |
| `ReversionController` | Reversión RR | 20/40 |
| `LiquidacionCompraController` | Liquidación 04 | + reversión, reporte-retenciones |
| `RetentionController` / `PerceptionController` | 20 / 40 | percepción sin pdf |
| `QuotationController` / `SaleNoteController` | Internos | no SUNAT |
| `ConsultController` / `CpeConsultaController` | Consultas | CDR / CPE / RUC-DNI |
| `CatalogoProductoController` / `UbigeoController` | Catálogos | UNSPSC / ubigeo |
| `ClientController` / `SerieController` / `SucursalController` | Maestros | apiResource |
| `PaymentController` | Pagos | de factura/boleta/liquidación/nota-venta |
| `DashboardController` / `ReportController` / `ExportController` | Analítica | panel/reportes/zip |
| `TenantController` | Empresa | perfil, logo, certificado, credenciales |
| `SubscriptionController` / `RegisterController` / `CredentialRecoveryController` | SaaS | planes, registro, recuperación |
| `Sire/*` | SIRE | RVIE/RCE/tickets |

## Capa de orquestación (`app/Actions/Documents/`)
`Create{Invoice,Boleta,CreditNote,DebitNote,LiquidacionCompra,Quotation,SaleNote}Action`,
`ConsultCdrAction`, `ReconcileFromCdrAction`, `RegisterPaymentAction`. Patrón:
transacción → serie con lock → cliente → cálculo → persistencia → dispatch.

## Servicios (`app/Services/`)

| Servicio | Rol |
|---|---|
| `DocumentCalculationService` + `TaxRateService` | Cálculo de ítems/totales e IGV (doc 07) |
| `Greenter/GreenterService` + `Builders/*` | XML UBL, firma, envío SOAP/REST |
| `Documents/{VoidedService,LiquidacionCompraCalculator}` | Baja y cálculo de liquidación |
| `Sunat/SunatCircuitBreaker`, `SunatCpeConsultaService` | Resiliencia y consulta CPE |
| `Storage/DocumentStorageService`, `Support/Storage/Comprobantes` | XML/CDR/PDF/certificados en disco privado |
| `Pdf/PdfGeneratorService` + `DocumentDataMapper` | PDF (dompdf) |
| `ClientResolverService`, `DocumentLookupService` | Resolución de cliente / RUC-DNI |
| `License/*`, `Plan/PlanService`, `EmissionPolicyService` | Licencia, planes, cupos |
| `Consulta/*`, `Reports/*`, `Branding/*`, `Settings/*` | Consultas, reportes, marca, ajustes |

## Builders Greenter (`app/Services/Greenter/Builders/`)
`InvoiceBuilder` (factura+boleta), `NoteBuilder` (NC+ND), `SummaryBuilder`,
`VoidedBuilder`, `DespatchBuilder` (guías), `RetentionBuilder`, `PerceptionBuilder`,
`ReversionBuilder`, `LiquidacionCompraXmlBuilder` (UBL manual).

## Jobs (`app/Jobs/`)
`SendDocumentToSunat` (01/03/07/08), `SendDispatchGuideToSunat`, `SendSummaryToSunat`,
`SendVoidedToSunat`, `SendRetentionToSunat`, `SendPerceptionToSunat`,
`SendReversionToSunat`, `SendLiquidacionCompraToSunat`; polling
`CheckTicketStatus`, `CheckSummaryTicketStatus`, `CheckVoidedTicketStatus`;
`NotifyWebhookJob`; billing (`CheckTrialExpiration`, `ProcessRecurringPayments`,
`ResetMonthlyUsage`). Todos con guard de idempotencia + `WithoutOverlapping`.

## Middleware (`app/Http/Middleware/`)
`ResolveTenant`, `EnsureIdempotency`, `LogApiRequest`, `UsageWarningHeader`,
`CheckLicense`, `CheckDocumentLimit`, `SecurityHeaders`, `ForceJsonAccept`. Detalle
del stack y orden en [documento 01](01-arquitectura.md) y
[11](11-manejo-errores.md).

## Endpoints del panel web (`routes/web.php`, prefijo `/sunat`, `auth+verified`)
UI Inertia que **también emite y gestiona** comprobantes: Factura/Boleta/NC/ND/
Anulación/Resumen/Guía/Retención/Percepción/Reversión (create/store/editar),
Historial (pdf/xml/cdr/respuesta/reenviar/anular), Nota de venta, Cotización, SIRE,
clientes/series/sucursales/reportes. Panel **admin** (`auth+admin+panel.write`,
`/admin`): gestión cross-empresa (`EmpresaComprobanteController`), logs de envíos,
bitácora, empresas/series/usuarios/planes/licencia.
