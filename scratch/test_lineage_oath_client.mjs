/**
 * test_lineage_oath_client.mjs — Verificación de la Tarea 3.1 de TASKS-09.
 *
 * Valida el cliente del juramento (`lineageOathClient.js`) contra el
 * criterio «Hecho cuando»: cada código HTTP del contrato (200/204/400/
 * 401/403) produce el veredicto estructurado correspondiente y ningún
 * método lanza excepción no controlada ante 4xx/5xx.
 *
 * Fases:
 *   [A] Contratos con mock de fetch: URLs, verbos, cookies y payloads.
 *   [B] El mapa de códigos: 200 sellado/idempotente, 204 retención,
 *       400 INVALID_LINEAGE, 401 SESSION_EXPIRED, 403 conflicto/rol.
 *   [C] Hostilidad: 500, JSON corrupto y corte de red — veredictos
 *       controlados, jamás excepciones hacia la vista.
 *
 * Constitución:
 *   - Art. I (Dogma Vanilla): fetch nativo mockeado, sin librerías.
 *   - Art. V: identificadores en inglés; narrativa en castellano.
 *
 * Uso: node scratch/test_lineage_oath_client.mjs
 */

import assert from 'node:assert';

let assertsPassed = 0;
let assertsFailed = 0;
let uncaughtErrors = 0;

// Centinela: ningún flujo del juramento debe escapar sin control.
process.on('uncaughtException', () => { uncaughtErrors++; });
process.on('unhandledRejection', () => { uncaughtErrors++; });

/** Aserta una condición y registra el resultado en la bitácora de consola. */
function assertCondition(condition, description) {
  if (condition) {
    assertsPassed++;
    console.log(`  [PASA] ${description}`);
  } else {
    assertsFailed++;
    console.log(`  [FALLA] ${description}`);
  }
}

/** Guarda el fetch real y monta un mock que captura la petición. */
function installFetchMock(mockImplementation) {
  const originalFetch = globalThis.fetch;
  globalThis.fetch = mockImplementation;
  return function restore() {
    globalThis.fetch = originalFetch;
  };
}

console.log('== VERIFICACION TAREA 3.1: lineageOathClient.js ==\n');

// --- Import del módulo bajo prueba ---
let client = null;
try {
  client = await import('../public/assets/js/api/lineageOathClient.js');
} catch (importError) {
  console.log(`  [FALLA] El módulo lineageOathClient.js no pudo importarse: ${importError.message}`);
  process.exit(1);
}

const { fetchOathCatalog, sealOath, retainRoute, LINEAGE_OATH_ERROR_CODES } = client;
assertCondition(typeof fetchOathCatalog === 'function', 'Exporta fetchOathCatalog()');
assertCondition(typeof sealOath === 'function', 'Exporta sealOath(lineageId)');
assertCondition(typeof retainRoute === 'function', 'Exporta retainRoute(route)');
assertCondition(
  typeof LINEAGE_OATH_ERROR_CODES === 'object'
  && LINEAGE_OATH_ERROR_CODES.lineageOathConflict === 'LINEAGE_OATH_CONFLICT'
  && LINEAGE_OATH_ERROR_CODES.oathForbiddenRole === 'OATH_FORBIDDEN_ROLE'
  && LINEAGE_OATH_ERROR_CODES.invalidLineage === 'INVALID_LINEAGE'
  && LINEAGE_OATH_ERROR_CODES.sessionExpired === 'SESSION_EXPIRED',
  'Exporta el catálogo de códigos del contrato (INVALID_LINEAGE, SESSION_EXPIRED, conflicto, rol)'
);

// --- FASE A: Contratos con mock de fetch ---
console.log('\nFASE A: Peticiones nativas con fetch simulado');

// A.1: fetchOathCatalog() — GET con cookies same-origin.
{
  let capturedUrl = '';
  let capturedInit = null;
  const restoreFetch = installFetchMock(async (requestedUrl, init) => {
    capturedUrl = String(requestedUrl);
    capturedInit = init ?? {};
    return new Response(
      JSON.stringify({
        success: true,
        data: { accountState: 'pilgrim', lineages: [{ id: 'primordialFlame', name: 'Linaje de la Llama Primordial' }] },
      }),
      { status: 200, headers: { 'Content-Type': 'application/json' } },
    );
  });

  const result = await fetchOathCatalog();

  assertCondition(capturedUrl === '/api/v1/lineage/oath-catalog', 'fetchOathCatalog apunta a GET /api/v1/lineage/oath-catalog');
  assertCondition(capturedInit.method === 'GET', 'La petición usa el verbo GET');
  assertCondition(capturedInit.credentials === 'same-origin', 'La petición porta credentials: same-origin (cookies automáticas)');
  assertCondition(result.success === true && result.status === 200, 'El 200 se traduce en success: true con result.status');
  assertCondition(result.data?.accountState === 'pilgrim', 'data.accountState disponible para la vista');
  assertCondition(Array.isArray(result.data?.lineages) && result.data.lineages[0]?.id === 'primordialFlame', 'data.lineages disponible para la rejilla solemne');
  restoreFetch();
}

// A.2: sealOath() — POST JSON con el payload del contrato.
{
  let capturedUrl = '';
  let capturedInit = null;
  const restoreFetch = installFetchMock(async (requestedUrl, init) => {
    capturedUrl = String(requestedUrl);
    capturedInit = init ?? {};
    return new Response(
      JSON.stringify({
        success: true,
        data: { lineage: 'primordialFlame', sealedNow: true, retainedRoute: '#/creador' },
      }),
      { status: 200, headers: { 'Content-Type': 'application/json' } },
    );
  });

  const result = await sealOath('primordialFlame');

  assertCondition(capturedUrl === '/api/v1/lineage/oath', 'sealOath apunta a POST /api/v1/lineage/oath');
  assertCondition(capturedInit.method === 'POST', 'La petición usa el verbo POST');
  assertCondition(capturedInit.credentials === 'same-origin', 'El sellado porta credentials: same-origin');
  const sentPayload = JSON.parse(String(capturedInit.body));
  assertCondition(sentPayload.lineageId === 'primordialFlame', 'El payload porta { lineageId } según el contrato');
  assertCondition(result.success === true && result.data?.sealedNow === true, 'El veredicto feliz porta sealedNow=true');
  assertCondition(result.data?.retainedRoute === '#/creador', 'El veredicto porta la ruta retenida para el retorno (RF-03.1)');
  restoreFetch();
}

// A.3: retainRoute() — POST con { route }.
{
  let capturedUrl = '';
  let capturedInit = null;
  let capturedBody = null;
  const restoreFetch = installFetchMock(async (requestedUrl, init) => {
    capturedUrl = String(requestedUrl);
    capturedInit = init ?? {};
    capturedBody = init?.body ?? null;
    return new Response(null, { status: 204 });
  });

  const result = await retainRoute('#/simulador');

  assertCondition(capturedUrl === '/api/v1/lineage/retained-route', 'retainRoute apunta a POST /api/v1/lineage/retained-route');
  assertCondition(JSON.parse(String(capturedBody)).route === '#/simulador', 'El payload porta { route } según el contrato');
  assertCondition(result.success === true && result.status === 204, 'El 204 se traduce en success: true sin cuerpo que negociar');
  assertCondition(result.data === undefined, 'El 204 no inventa data');
  restoreFetch();
}

// --- FASE B: El mapa de códigos del contrato ---
console.log('\nFASE B: Cada código HTTP produce su veredicto estructurado');

const codeCases = [
  {
    description: '400 INVALID_LINEAGE produce success: false con el código intacto',
    status: 400,
    payload: { success: false, error: { code: 'INVALID_LINEAGE', message: 'El linaje «dracoStorm» no figura entre los ocho linajes canónicos.' } },
    check: (result) => result.success === false && result.status === 400 && result.error?.code === 'INVALID_LINEAGE',
    call: () => sealOath('dracoStorm'),
  },
  {
    description: '401 SESSION_EXPIRED permite a la vista reautenticar (RF-03.2)',
    status: 401,
    payload: { success: false, error: { code: 'SESSION_EXPIRED', message: 'Tu vínculo con el santuario ha expirado.' } },
    check: (result) => result.success === false && result.status === 401 && result.error?.code === 'SESSION_EXPIRED',
    call: () => sealOath('primordialFlame'),
  },
  {
    description: '403 LINEAGE_OATH_CONFLICT porta el rechazo solemne sin mutación',
    status: 403,
    payload: { success: false, error: { code: 'LINEAGE_OATH_CONFLICT', message: 'Tu palabra ya está dada.' } },
    check: (result) => result.success === false && result.status === 403 && result.error?.code === 'LINEAGE_OATH_CONFLICT',
    call: () => sealOath('solarCrown'),
  },
  {
    description: '403 OATH_FORBIDDEN_ROLE distingue la exención del Supremo (RF-01.6)',
    status: 403,
    payload: { success: false, error: { code: 'OATH_FORBIDDEN_ROLE', message: 'El Administrador Supremo navega exento.' } },
    check: (result) => result.success === false && result.status === 403 && result.error?.code === 'OATH_FORBIDDEN_ROLE',
    call: () => sealOath('primordialFlame'),
  },
];

for (const codeCase of codeCases) {
  const restoreFetch = installFetchMock(async () => new Response(
    JSON.stringify(codeCase.payload),
    { status: codeCase.status, headers: { 'Content-Type': 'application/json' } },
  ));
  const result = await codeCase.call();
  assertCondition(codeCase.check(result), codeCase.description);
  restoreFetch();
}

// --- FASE C: Hostilidad controlada ---
console.log('\nFASE C: Hostilidad — veredictos controlados, jamás excepciones');

// C.1: 500 sin sobre legible.
{
  const restoreFetch = installFetchMock(async () => new Response('Internal Server Error', { status: 500 }));
  const result = await fetchOathCatalog();
  assertCondition(result.success === false && result.status === 500 && result.error?.code === 'MANA_STREAM_INTERRUPTED', 'Un 500 se traduce en error controlado (sin lanzar)');
  restoreFetch();
}

// C.2: cuerpo JSON corrupto tras un 200.
{
  const restoreFetch = installFetchMock(async () => new Response('{roto', { status: 200 }));
  const result = await sealOath('primordialFlame');
  assertCondition(result.success === false && result.error?.code === 'MANA_STREAM_INTERRUPTED', 'Un 200 con JSON corrupto no engaña al cliente: error controlado');
  restoreFetch();
}

// C.3: corte de red real (fetch rechaza).
{
  const restoreFetch = installFetchMock(async () => { throw new TypeError('Failed to fetch'); });
  const result = await retainRoute('#/creador');
  assertCondition(result.success === false && result.status === 0 && result.error?.code === 'MANA_STREAM_INTERRUPTED', 'Un corte de red produce status 0 con recoveryAction RETRY');
  restoreFetch();
}

assertCondition(uncaughtErrors === 0, `Ninguna excepción escapó sin control (${uncaughtErrors} cazadas)`);

console.log(`\n== RESUMEN == Asertos superados: ${assertsPassed}, fallidos: ${assertsFailed}`);
if (assertsFailed === 0 && uncaughtErrors === 0) {
  console.log('RESULTADO: EXITO — El cliente del juramento traduce el contrato completo en veredictos estructurados sin lanzar jamás (Tarea 3.1).');
  process.exit(0);
}

console.log('RESULTADO: FALLO — Corregir los asertos en rojo antes de continuar.');
process.exit(1);
