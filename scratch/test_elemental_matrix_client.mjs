/**
 * test_elemental_matrix_client.mjs — Arnés TDD de la Tarea 5.1 (TASKS-06).
 *
 * Estrategia TDD: este script se escribe ANTES que la implementación.
 * Verifica, sobre `public/assets/js/api/elementalMatrixClient.js`:
 *   [1] Fábrica con fetch inyectable (Dogma Vanilla: cero librerías) y
 *       cabeceras JSON en toda petición.
 *   [2] fetchMatrixGraph(): GET /api/v1/elements/matrix → sobre
 *       { success: true, data } (RF-01.1) y traducción de fallos.
 *   [3] fetchReactionsForElement(element): GET
 *       /api/v1/elements/reactions/{element} (RF-01.2) y error 404
 *       ELEMENT_NOT_FOUND traducido sin lanzar.
 *   [4] resolveCombo(data): POST /api/v1/elements/resolve-combo con
 *       cuerpo JSON y Content-Type correcto (RF-04.1), 400 de
 *       INVALID_* propagado como sobre de error controlado.
 *   [5] Nunca lanza: corte de red, JSON ilegible y estados de error se
 *       convierten en { success: false, error } (patrón spellClient).
 *   [6] Integración real contra el servidor local (smoke E2E opcional
 *       mediante fetch nativo, omitida si el servidor no responde).
 *
 * Criterio «Hecho cuando» (Tarea 5.1): el cliente consume correctamente
 * los endpoints del servidor y maneja respuestas y posibles errores
 * 404/400.
 *
 * Constitución:
 *   - Artículo I (Dogma Vanilla): fetch nativo; cero axios o librerías.
 *   - Artículo V: identificadores en inglés camelCase; comentarios en
 *     castellano.
 */

let assertsPassed = 0;
let assertsFailed = 0;

/** Aserto de una sola línea con veredicto inmediato en la terminal. */
function assertCondition(condition, message) {
  if (condition) {
    assertsPassed += 1;
    console.log(`  [PASA] ${message}`);
  } else {
    assertsFailed += 1;
    console.log(`  [FALLA] ${message}`);
  }
}

const clientModule = await import('../public/assets/js/api/elementalMatrixClient.js');
const { createElementalMatrixClient, ELEMENTAL_API_ERROR_CODES } = clientModule;

// ---------------------------------------------------------------------
// Doble de fetch: graba peticiones y responde desde una cola de guiones.
// ---------------------------------------------------------------------

function createFetchStub(routes) {
  const calls = [];
  const stub = async (url, options = {}) => {
    calls.push({ url: String(url), method: options.method ?? 'GET', headers: options.headers ?? {}, body: options.body ?? null });
    for (const route of routes) {
      const match = typeof route.match === 'function' ? route.match(calls.at(-1)) : calls.at(-1).url.includes(route.match);
      if (match) {
        return {
          ok: route.status >= 200 && route.status < 300,
          status: route.status,
          json: async () => (typeof route.body === 'string' ? JSON.parse(route.body) : route.body),
        };
      }
    }
    return { ok: false, status: 599, json: async () => ({}) };
  };
  stub.calls = calls;
  return stub;
}

const MATRIX_PAYLOAD = {
  success: true,
  data: {
    elements: [{ id: 'water', name: 'Agua' }, { id: 'lightning', name: 'Rayo' }],
    reactions: [{ id: 'fluidElectrocution', name: 'Electrocución Fluida', elements: ['water', 'lightning'] }],
  },
};
const REACTIONS_PAYLOAD = {
  success: true,
  data: {
    element: 'water',
    dualReactions: [{ id: 'fluidElectrocution', counterpart: 'lightning' }],
    catalystReactions: [],
  },
};
const VERDICT_PAYLOAD = {
  success: true,
  data: {
    isReaction: true,
    reactionId: 'fluidElectrocution',
    reactionName: 'Electrocución Fluida',
    effectiveDamage: 135,
    damageMultiplierApplied: 1.5,
    tacticalEffectApplied: 'hardStun',
    effectDurationMs: 1500,
    clearedAura: true,
    resultingAura: null,
    stunlockTriggered: false,
    grantStunlockImmunity: true,
  },
};

// =====================================================================
// [1] Superficie y cabeceras JSON
// =====================================================================
console.log('[1] Fábrica y cabeceras JSON');
assertCondition(typeof createElementalMatrixClient === 'function', 'el módulo exporta la fábrica createElementalMatrixClient');
assertCondition(ELEMENTAL_API_ERROR_CODES !== undefined && typeof ELEMENTAL_API_ERROR_CODES === 'object', 'el módulo exporta los códigos de error canónicos');

const fetchStub = createFetchStub([
  { match: '/api/v1/elements/matrix', status: 200, body: MATRIX_PAYLOAD },
  { match: '/api/v1/elements/reactions/water', status: 200, body: REACTIONS_PAYLOAD },
  { match: '/api/v1/elements/resolve-combo', status: 200, body: VERDICT_PAYLOAD },
]);
const client = createElementalMatrixClient({ fetch: fetchStub });

// =====================================================================
// [2] fetchMatrixGraph (RF-01.1)
// =====================================================================
console.log('\n[2] fetchMatrixGraph consume el Endpoint 1');
const graphResult = await client.fetchMatrixGraph();
assertCondition(graphResult.success === true, 'el sobre de éxito se propaga intacto');
assertCondition(graphResult.data?.elements?.length === 2, 'el grafo del Códice viaja en data');
assertCondition(fetchStub.calls.at(-1).url.includes('/api/v1/elements/matrix'), `la URL es la canónica (${fetchStub.calls.at(-1).url})`);
assertCondition(fetchStub.calls.at(-1).headers.Accept === 'application/json', 'toda petición declara Accept: application/json');

// =====================================================================
// [3] fetchReactionsForElement (RF-01.2)
// =====================================================================
console.log('\n[3] fetchReactionsForElement consume el Endpoint 2');
const reactionsResult = await client.fetchReactionsForElement('water');
assertCondition(reactionsResult.success === true, 'el sobre de éxito se propaga intacto');
assertCondition(reactionsResult.data?.element === 'water', 'las aristas reactivas del elemento viajan en data');
assertCondition(
  fetchStub.calls.at(-1).url.includes('/api/v1/elements/reactions/water'),
  `la URL codifica el elemento (${fetchStub.calls.at(-1).url})`,
);

// Elemento desconocido → 404 ELEMENT_NOT_FOUND traducido sin lanzar.
const notFoundFetch = createFetchStub([
  {
    match: '/api/v1/elements/reactions/',
    status: 404,
    body: { success: false, error: { code: 'ELEMENT_NOT_FOUND', message: 'Esa afinidad no pertenece al canon de los ocho elementos del santuario.', recoveryAction: 'RETRY_WITH_CANONICAL_ELEMENT' } },
  },
]);
const notFoundClient = createElementalMatrixClient({ fetch: notFoundFetch });
let notFoundThrown = false;
let notFoundResult = null;
try {
  notFoundResult = await notFoundClient.fetchReactionsForElement('unobtainium');
} catch {
  notFoundThrown = true;
}
assertCondition(notFoundThrown === false, 'el 404 jamás se lanza hacia la vista');
assertCondition(notFoundResult?.success === false, 'el 404 llega como sobre de error controlado');
assertCondition(notFoundResult?.error?.code === 'ELEMENT_NOT_FOUND', `el código canónico viaja (${notFoundResult?.error?.code})`);

// =====================================================================
// [4] resolveCombo (RF-04.1)
// =====================================================================
console.log('\n[4] resolveCombo consume el Endpoint 3 con cuerpo JSON');
const verdictResult = await client.resolveCombo({
  activeAura: 'water',
  incomingSpell: { id: 'l', element: 'lightning', baseDamage: 90, baseHealing: 0, baseBarrier: 0, crowdControlType: 'none' },
  stunlockImmune: false,
});
assertCondition(verdictResult.success === true, 'el veredicto autoritativo viaja en data');
assertCondition(verdictResult.data?.reactionId === 'fluidElectrocution', 'la reacción resuelta es Electrocución Fluida');
const comboCall = fetchStub.calls.at(-1);
assertCondition(comboCall.method === 'POST', 'la resolución usa POST');
assertCondition(comboCall.headers['Content-Type'] === 'application/json', 'el cuerpo declara Content-Type: application/json');
const sentBody = JSON.parse(comboCall.body);
assertCondition(sentBody.activeAura === 'water' && sentBody.incomingSpell?.element === 'lightning', 'el payload del combo viaja íntegro');

// 400 de validación → sobre de error sin lanzar.
const badRequestFetch = createFetchStub([
  {
    match: '/api/v1/elements/resolve-combo',
    status: 400,
    body: { success: false, error: { code: 'INVALID_ELEMENT', message: 'La afinidad del conjuro entrante no pertenece al canon de los ocho elementos.' } },
  },
]);
const badRequestClient = createElementalMatrixClient({ fetch: badRequestFetch });
let badRequestThrown = false;
let badRequestResult = null;
try {
  badRequestResult = await badRequestClient.resolveCombo({ activeAura: '', incomingSpell: { id: 'x', element: 'chaos', baseDamage: 1, baseHealing: 0, baseBarrier: 0, crowdControlType: 'none' }, stunlockImmune: false });
} catch {
  badRequestThrown = true;
}
assertCondition(badRequestThrown === false, 'el 400 jamás se lanza hacia la vista');
assertCondition(badRequestResult?.success === false, 'el 400 llega como sobre de error controlado');
assertCondition(badRequestResult?.error?.code === 'INVALID_ELEMENT', `el código de validación viaja (${badRequestResult?.error?.code})`);

// =====================================================================
// [5] Nunca lanza: red caída y JSON ilegible
// =====================================================================
console.log('\n[5] Los fallos de corriente se convierten en sobres controlados');
const brokenFetch = async () => { throw new TypeError('Failed to fetch'); };
const brokenClient = createElementalMatrixClient({ fetch: brokenFetch });
const brokenGraph = await brokenClient.fetchMatrixGraph();
assertCondition(brokenGraph.success === false, 'el corte de red se convierte en error controlado');
assertCondition(typeof brokenGraph.error?.code === 'string' && brokenGraph.error.code.length > 0, `el corte porta código canónico (${brokenGraph.error?.code})`);

const garbageFetch = createFetchStub([{ match: '/api/v1/elements', status: 200, body: '<html>not json</html>' }]);
garbageFetch.calls.at(-1);
const garbageClient = createElementalMatrixClient({ fetch: async (url) => ({ ok: true, status: 200, json: async () => { throw new SyntaxError('Unexpected token'); } }) });
const garbageGraph = await garbageClient.fetchMatrixGraph();
assertCondition(garbageGraph.success === false, 'el JSON ilegible se convierte en error controlado');

// =====================================================================
// [6] Smoke E2E real contra el servidor local (omitible en CI sin servidor)
// =====================================================================
console.log('\n[6] Smoke E2E contra el servidor local');
import http from 'node:http';

/**
 * Sonda HTTP mínima con node:http: el fetch global de Node (undici) deja
 * un socket keep-alive pendiente que dispara una aserción libuv al salir
 * en Windows. La sonda es del arnés; el cliente de producción usa fetch.
 */
function liveRequest(method, path, body = null) {
  return new Promise((resolve) => {
    const { hostname, port, pathname, search } = new URL(LIVE_BASE + path);
    const request = http.request({
      hostname, port, path: pathname + search, method,
      headers: {
        Accept: 'application/json',
        ...(body !== null ? { 'Content-Type': 'application/json', 'Content-Length': Buffer.byteLength(body) } : {}),
      },
      timeout: 2000,
    }, (response) => {
      let raw = '';
      response.on('data', (chunk) => { raw += chunk; });
      response.on('end', () => {
        let payload = null;
        try { payload = JSON.parse(raw); } catch { payload = null; }
        resolve({ ok: response.statusCode >= 200 && response.statusCode < 300, payload });
      });
    });
    request.on('error', () => resolve({ ok: false, payload: null }));
    request.on('timeout', () => { request.destroy(); resolve({ ok: false, payload: null }); });
    if (body !== null) request.write(body);
    request.end();
  });
}

const LIVE_BASE = 'http://127.0.0.1:8095/api/v1';
const probe = await liveRequest('GET', '/elements/matrix');
const liveServer = probe.ok;
if (!liveServer) {
  console.log('  [OMITIDA] Servidor local no disponible (arranca php -S con scratch/demo_router.php para el smoke completo)');
} else {
  const liveClient = createElementalMatrixClient({
    baseUrl: LIVE_BASE,
    // El cliente de producción usa fetch nativo; para la sonda en Node
    // lo puentamos a node:http (misma firma url/init → sobre response).
    fetch: (url, init = {}) => new Promise((resolve, reject) => {
      const { hostname, port, pathname, search } = new URL(url);
      const request = http.request({
        hostname, port, path: pathname + search, method: init.method ?? 'GET',
        headers: init.headers ?? {},
        timeout: 2000,
      }, (response) => {
        let raw = '';
        response.on('data', (chunk) => { raw += chunk; });
        response.on('end', () => resolve({
          ok: response.statusCode >= 200 && response.statusCode < 300,
          status: response.statusCode,
          json: async () => JSON.parse(raw),
        }));
      });
      request.on('error', reject);
      request.on('timeout', () => { request.destroy(); reject(new Error('timeout')); });
      if (init.body) request.write(init.body);
      request.end();
    }),
  });
  const liveGraph = await liveClient.fetchMatrixGraph();
  assertCondition(liveGraph.success === true && liveGraph.data?.elements?.length === 8, `GET matrix real: ${liveGraph.data?.elements?.length ?? 0} elementos canónicos`);
  const liveReactions = await liveClient.fetchReactionsForElement('water');
  assertCondition(liveReactions.success === true, 'GET reactions/water real: sobre de éxito');
  const liveVerdict = await liveClient.resolveCombo({
    activeAura: 'water',
    incomingSpell: { id: 'l', element: 'lightning', baseDamage: 90, baseHealing: 0, baseBarrier: 0, crowdControlType: 'none' },
    stunlockImmune: false,
  });
  assertCondition(liveVerdict.success === true && liveVerdict.data?.effectiveDamage === 135, `POST resolve-combo real: ceil(90 × 1.5) = ${liveVerdict.data?.effectiveDamage}`);
}

// =====================================================================
// Resumen
// =====================================================================
console.log(`\n== RESUMEN == Asertos superados: ${assertsPassed}, fallidos: ${assertsFailed}`);
if (assertsFailed === 0) {
  console.log('RESULTADO: EXITO — El cliente HTTP consume la Matriz Elemental y doma 404/400 (Tarea 5.1).');
  process.exit(0);
}
console.log('RESULTADO: DENEGADO — Revisa los asertos marcados.');
process.exit(1);
