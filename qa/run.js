/*
 * Ejecuta coleccion.postman_collection.json con Newman y emite una línea "QA|{json}"
 * por request, para leer el resultado desde los logs de Railway.
 *
 * La colección original no tiene scripts; aquí, SOLO en memoria, se le añade:
 *  - certificado de prueba autofirmado (generado en el arranque, nunca versionado)
 *  - un RUC válido aleatorio para /registro (re-ejecutable sin choque de RUC)
 *  - captura de api_key/api_secret e IDs creados ({{id_factura}}, {{id_boleta}}...)
 * y se omite DELETE /empresa (borraría la empresa a mitad de la corrida).
 */
import { Buffer } from 'node:buffer';
import { execSync } from 'node:child_process';
import fs from 'node:fs';
import process from 'node:process';
import newman from 'newman';

const BASE_URL = process.env.BASE_URL || 'http://localhost:8000/api/v1';
// La API limita a 120 req/min por empresa en plan pro: 700 ms entre requests deja margen.
const DELAY_MS = Number(process.env.DELAY_MS || 700);

function rucValido() {
  const base = '20' + String(Math.floor(Math.random() * 1e8)).padStart(8, '0');
  const f = [5, 4, 3, 2, 7, 6, 5, 4, 3, 2];
  const s = base.split('').reduce((a, d, i) => a + Number(d) * f[i], 0);
  let dv = 11 - (s % 11);
  if (dv === 10) dv = 0;
  if (dv === 11) dv = 1;
  return base + dv;
}

const RUC = process.env.RUC || rucValido();
const PFX = '/tmp/cert-prueba.pfx';
execSync(
  `openssl req -x509 -newkey rsa:2048 -nodes -days 365 -keyout /tmp/k.pem -out /tmp/c.pem -subj "/CN=PRUEBA QA ${RUC}/O=${RUC}" && ` +
  `openssl pkcs12 -export -out ${PFX} -inkey /tmp/k.pem -in /tmp/c.pem -passout pass:123456 -keypbe PBE-SHA1-3DES -certpbe PBE-SHA1-3DES -macalg sha1`,
  { stdio: 'ignore' }
);
const LOGO = '/tmp/logo.png';
fs.writeFileSync(LOGO, Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==', 'base64'));
const TXT = '/tmp/propuesta.txt';
fs.writeFileSync(TXT, 'QA|prueba\n');

const col = JSON.parse(fs.readFileSync(new URL('./coleccion.postman_collection.json', import.meta.url), 'utf8'));
const omitidos = [];

function patch(items, ruta) {
  return items.filter((it) => {
    if (it.item) { it.item = patch(it.item, ruta + it.name + ' / '); return true; }
    const r = it.request;
    const raw = typeof r.url === 'string' ? r.url : r.url.raw;
    if (r.method === 'DELETE' && /\{\{base_url\}\}\/empresa\/?$/.test(raw)) {
      omitidos.push({ carpeta: ruta, nombre: it.name, method: r.method, url: raw, motivo: 'destructivo: elimina la empresa de prueba' });
      return false;
    }
    if (r.body && r.body.mode === 'formdata') {
      for (const f of r.body.formdata) {
        if (f.type !== 'file') continue;
        if (f.key === 'certificado') f.src = PFX;
        else if (f.key === 'logo') f.src = LOGO;
        else f.src = TXT;
        if (f.key === 'certificado' || f.key === 'logo') f.disabled = false;
      }
      for (const f of r.body.formdata) {
        if (f.key === 'ruc' && /\/registro$/.test(raw)) f.value = RUC;
        if (f.key === 'contrasena_certificado') f.value = '123456';
      }
    }
    return true;
  });
}
col.item = patch(col.item, '');

// Captura de credenciales e IDs (en memoria; la colección del repo queda intacta).
col.event = [{
  listen: 'test',
  script: { type: 'text/javascript', exec: [
    "let j = null; try { j = pm.response.json(); } catch (e) {}",
    "// La API responde { estado, mensaje, datos }; se aceptan también data o la raíz.",
    "const d = j && (j.datos !== undefined ? j.datos : (j.data !== undefined ? j.data : j));",
    "const url = pm.request.url.getPath();",
    "const m = pm.request.method;",
    "// Solo credenciales reales: GET /empresa/credenciales devuelve el secreto enmascarado ('*** oculto — ... ***').",
    "const real = (v) => typeof v === 'string' && /^[A-Za-z0-9]{20,}$/.test(v);",
    "if (d && real(d.api_key) && real(d.api_secret)) { pm.collectionVariables.set('api_key', d.api_key); pm.collectionVariables.set('api_secret', d.api_secret); }",
    "const mapa = { '/facturas': 'id_factura', '/boletas': 'id_boleta', '/notas-credito': 'id_NC', '/notas-debito': 'id_ND', '/resumenes': 'id_resumen', '/anulaciones': 'id_anular', '/guias-remision': 'id_guia', '/guias-remision-transportista': 'id_guia_t' };",
    "if (m === 'POST' && pm.response.code < 300 && d && (d.id || (d.documento && d.documento.id))) {",
    "  const id = d.id || d.documento.id;",
    "  for (const k of Object.keys(mapa)) { if (url.endsWith('/v1' + k)) { if (!pm.collectionVariables.get(mapa[k] + '__fijado')) { pm.collectionVariables.set(mapa[k], id); pm.collectionVariables.set(mapa[k] + '__fijado', '1'); } if (k === '/anulaciones') pm.collectionVariables.set('id', id); } }",
    "}",
    "if (d && d.ticket && /sire/.test(url)) pm.collectionVariables.set('num_ticket', d.ticket);",
  ] },
}];
col.variable = (col.variable || []).filter((v) => !['base_url', 'ruc_empresa'].includes(v.key));
col.variable.push({ key: 'base_url', value: BASE_URL }, { key: 'ruc_empresa', value: RUC });

const out = (o) => console.log('QA|' + JSON.stringify(o));
out({ tipo: 'inicio', base_url: BASE_URL, ruc: RUC, omitidos });

const pila = [];
newman.run({ collection: col, delayRequest: DELAY_MS, timeoutRequest: 90000, reporters: [], insecure: false })
  .on('beforeItem', (err, a) => { pila.length = 0; let p = a.item.parent(); while (p && p.name && p.parent && p.parent()) { pila.unshift(p.name); p = p.parent(); } })
  .on('request', (err, a) => {
    const res = a.response;
    let cuerpo = '';
    if (res) {
      try {
        cuerpo = res.stream.toString('utf8');
      } catch {
        cuerpo = '[cuerpo no legible como texto]';
      }
    }
    const ct = res && res.headers.get('Content-Type') || '';
    out({
      tipo: 'req',
      carpeta: pila.join(' / '),
      nombre: a.item.name,
      method: a.request.method,
      url: a.request.url.toString().replace(BASE_URL, ''),
      code: res ? res.code : null,
      ms: res ? res.responseTime : null,
      ct: ct.split(';')[0],
      error: err ? String(err.message || err) : null,
      cuerpo: /json|text|html/.test(ct) || !res ? cuerpo.slice(0, 600) : `[binario ${res.responseSize} bytes]`,
    });
  })
  .on('done', (err, summary) => {
    const s = summary.run.stats;
    out({ tipo: 'fin', error: err ? String(err) : null, requests: s.requests, duracion_ms: summary.run.timings.completed - summary.run.timings.started });
    // Mantener el contenedor vivo un rato evita que Railway lo marque como crash y reintente.
    setTimeout(() => process.exit(0), 5000);
  });
