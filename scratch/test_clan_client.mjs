/**
 * test_clan_client.mjs — Arnés de la Tarea 5.1 (TASKS-07).
 *
 * Verifica sobre `public/assets/js/api/clanClient.js`:
 *   [1]  Superficie del módulo: fábrica, códigos canónicos y leyendas.
 *   [2]  Endpoint 1 · foundClan(): POST con cuerpo JSON (RF-01.1, RF-01.2).
 *   [3]  Endpoint 2 · fetchClans(): query filtrada y paginada (RF-01.4, RF-05.3).
 *   [4]  Endpoint 3 · fetchClan(): ficha con identificador codificado (RF-01.3).
 *   [5]  Endpoint 4 · updateClan(): PATCH parcial de lema, blasón y régimen.
 *   [6]  Endpoint 5 · applyToClan() y Endpoint 6 · resolveApplication() (RF-01.5).
 *   [7]  Endpoints 7-9 · leaveClan(), expelMember() y transferLeadership() (RF-01.6).
 *   [8]  Credencial Bearer OPCIONAL y aditiva, junto a la cookie de sesión.
 *   [9]  Nunca lanza: red caída, JSON ilegible y estados sin sobre.
 *   [10] Paridad de contratos con las rutas realmente registradas en el
 *        Front Controller (anti-deriva): toda URL invocada existe de verdad.
 *
 * Criterio «Hecho cuando» (Tarea 5.1): los clientes realizan las llamadas
 * asíncronas a los endpoints backend propagando respuestas estructuradas.
 *
 * Constitución:
 *   - Artículo I (Dogma Vanilla): fetch nativo; cero librerías.
 *   - Artículo V: identificadores en inglés camelCase; comentarios en castellano.
 */

import { readFile } from 'node:fs/promises';

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

const clientModule = await import('../public/assets/js/api/clanClient.js');
const { createClanClient, CLAN_ERROR_CODES, CLAN_CEREMONIAL_LEGENDS, ceremonialLegendFor } = clientModule;

// ---------------------------------------------------------------------
// Doble de fetch: graba cada petición y responde desde una cola de guiones.
// ---------------------------------------------------------------------

function createFetchStub(routes) {
  const calls = [];
  const stub = async (url, options = {}) => {
    const rawUrl = String(url);
    const call = {
      url: rawUrl,
      // Ruta sin query: permite emparejar por camino EXACTO, de modo que la
      // ruta del detalle jamás sea capturada por la del catálogo.
      path: new URL(rawUrl, 'http://santuario.local').pathname,
      method: options.method ?? 'GET',
      headers: options.headers ?? {},
      body: options.body ?? null,
      credentials: options.credentials ?? null,
    };
    calls.push(call);

    for (const route of routes) {
      const methodMatches = route.method === undefined || route.method === call.method;
      const matches = typeof route.match === 'function'
        ? route.match(call)
        : route.path !== undefined
          ? call.path === route.path && methodMatches
          : call.url.includes(route.match) && methodMatches;
      if (matches) {
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

const CLAN_FICHA = {
  success: true,
  data: {
    id: 'cln_llama',
    slug: 'custodios-de-la-llama',
    name: 'Custodios de la Llama',
    motto: 'En la ceniza renace la llama inmortal',
    coatOfArms: 'rune-ignis',
    lineageType: 'primordialFlame',
    admissionMode: 'open',
    status: 'active',
    patriarchId: 'usr_fundador',
    weeklyPoints: 320,
    historicalPoints: 620,
    memberCount: 7,
    memberLimit: 30,
  },
};

// =====================================================================
// [1] Superficie del módulo
// =====================================================================
console.log('[1] Superficie del módulo');
assertCondition(typeof createClanClient === 'function', 'el módulo exporta la fábrica createClanClient');
assertCondition(typeof ceremonialLegendFor === 'function', 'el módulo exporta el traductor de leyendas ceremoniales');
assertCondition(
  CLAN_ERROR_CODES.convalescenceActive === 'CONVALESCENCE_ACTIVE'
    && CLAN_ERROR_CODES.quotaExceeded === 'CLAN_QUOTA_EXCEEDED'
    && CLAN_ERROR_CODES.nameAlreadyReserved === 'NAME_ALREADY_RESERVED'
    && CLAN_ERROR_CODES.pendingApplicationsLimit === 'PENDING_APPLICATIONS_LIMIT',
  'los códigos canónicos del backend viajan en el mapa de errores'
);
assertCondition(
  typeof CLAN_CEREMONIAL_LEGENDS[CLAN_ERROR_CODES.convalescenceActive] === 'string'
    && CLAN_CEREMONIAL_LEGENDS[CLAN_ERROR_CODES.convalescenceActive].includes('convalecencia'),
  'el canon ceremonial (RNF-03) declara su leyenda de convalecencia en castellano'
);
assertCondition(
  ceremonialLegendFor({ error: { code: 'CODIGO_AJENO_SIN_LEYENDA' } }).length > 0,
  'un código desconocido sin mensaje jamás deja la interfaz en blanco'
);
assertCondition(
  ceremonialLegendFor({ error: { code: CLAN_ERROR_CODES.notPatriarch, message: 'Leyenda del santuario.' } })
    === 'Leyenda del santuario.',
  'la leyenda del santuario tiene precedencia sobre la del cliente'
);

// =====================================================================
// [2] Endpoint 1 · Fundación (RF-01.1, RF-01.2)
// =====================================================================
console.log('\n[2] Endpoint 1 · foundClan()');
const CLAN_FICHA_COMPLETA = { success: true, data: { clan: CLAN_FICHA.data, patriarch: { id: 'usr_fundador' }, members: [], applications: [] } };
const CLAN_MEMBRESIA_CERRADA = { success: true, data: { leftAt: '2026-09-14T12:00:00Z', convalescenceExpiresAt: '2026-09-28T12:00:00Z' } };

const stub = createFetchStub([
  { path: '/api/v1/clans', method: 'POST', status: 201, body: CLAN_FICHA },
  { path: '/api/v1/clans', method: 'GET', status: 200, body: { success: true, data: { items: [], pagination: {} } } },
  { path: '/api/v1/clans/cln_llama', method: 'GET', status: 200, body: CLAN_FICHA_COMPLETA },
  { path: '/api/v1/clans/cln%2Fllama', method: 'GET', status: 200, body: CLAN_FICHA_COMPLETA },
  { path: '/api/v1/clans/cln_llama', method: 'PATCH', status: 200, body: CLAN_FICHA },
  { path: '/api/v1/clans/cln_llama/applications', method: 'POST', status: 201, body: { success: true, data: { mode: 'active', application: null, membership: { role: 'adept' } } } },
  { path: '/api/v1/clans/cln_llama/applications/app_1/resolve', method: 'POST', status: 200, body: { success: true, data: { mode: 'pending', application: { id: 'app_1' }, membership: null } } },
  { path: '/api/v1/clans/cln_llama/leave', method: 'POST', status: 200, body: CLAN_MEMBRESIA_CERRADA },
  { path: '/api/v1/clans/cln_llama/expel/usr_adepto', method: 'POST', status: 200, body: CLAN_MEMBRESIA_CERRADA },
  { path: '/api/v1/clans/cln_llama/transfer-leadership', method: 'POST', status: 200, body: { success: true, data: { role: 'patriarch' } } },
]);
const client = createClanClient({ fetch: stub });

const foundResult = await client.foundClan({
  name: 'Custodios de la Llama',
  motto: 'En la ceniza renace la llama inmortal',
  coatOfArms: 'rune-ignis',
  lineageType: 'primordialFlame',
});
assertCondition(foundResult.success === true && foundResult.status === 201, 'la fundación responde 201 con el sobre de éxito');
assertCondition(foundResult.data?.patriarchId === 'usr_fundador', 'la ficha de la casa fundada se propaga en data');
const foundCall = stub.calls.at(-1);
assertCondition(foundCall.method === 'POST' && foundCall.url.endsWith('/api/v1/clans'), `la URL y el método son canónicos (${foundCall.method} ${foundCall.url})`);
assertCondition(foundCall.headers['Content-Type'] === 'application/json', 'el cuerpo declara Content-Type: application/json');
assertCondition(foundCall.headers.Accept === 'application/json', 'toda petición declara Accept: application/json');
const sentPayload = JSON.parse(foundCall.body);
assertCondition(
  sentPayload.lineageType === 'primordialFlame' && sentPayload.name === 'Custodios de la Llama',
  'el payload de la fundación viaja íntegro y sin mutilar'
);

// 409 de nombre reservado: el sobre se propaga entero, con su código y su acción de recuperación.
const rejectionStub = createFetchStub([
  {
    match: '/api/v1/clans',
    status: 409,
    body: {
      success: false,
      error: {
        code: 'NAME_ALREADY_RESERVED',
        message: 'El Nombre Canónico «Custodios de la Llama» ya está inscrito en los anales.',
        recoveryAction: 'CHOOSE_ANOTHER_CANONICAL_NAME',
      },
    },
  },
]);
const rejectionClient = createClanClient({ fetch: rejectionStub });
let rejectionThrown = false;
let rejectionResult = null;
try {
  rejectionResult = await rejectionClient.foundClan({ name: 'Custodios de la Llama', lineageType: 'primordialFlame' });
} catch {
  rejectionThrown = true;
}
assertCondition(rejectionThrown === false, 'el 409 jamás se lanza hacia la vista del fundador');
assertCondition(rejectionResult?.success === false && rejectionResult.status === 409, 'el 409 llega como sobre de error controlado con su estado');
assertCondition(rejectionResult?.error?.code === CLAN_ERROR_CODES.nameAlreadyReserved, `el código canónico viaja (${rejectionResult?.error?.code})`);
assertCondition(
  rejectionResult?.error?.recoveryAction === 'CHOOSE_ANOTHER_CANONICAL_NAME',
  'la acción de recuperación del santuario sobrevive al cliente'
);
assertCondition(
  ceremonialLegendFor(rejectionResult).includes('anales'),
  'la leyenda ceremonial del santuario se sirve tal cual a la interfaz'
);

// 422 de entidad incompleta: código distinto, mismo trato.
const unprocessableStub = createFetchStub([
  { match: '/api/v1/clans', status: 422, body: { success: false, error: { code: 'INVALID_CLAN_PAYLOAD', message: 'La fundación exige nombre canónico, linaje rector y un régimen de los dos canónicos.' } } },
]);
const unprocessableClient = createClanClient({ fetch: unprocessableStub });
const unprocessableResult = await unprocessableClient.foundClan({ name: 'Casa Incompleta' });
assertCondition(
  unprocessableResult?.error?.code === CLAN_ERROR_CODES.invalidClanPayload && unprocessableResult.status === 422,
  'el 422 INVALID_CLAN_PAYLOAD se propaga sin lanzar'
);

// =====================================================================
// [3] Endpoint 2 · Catálogo filtrado (RF-01.4, RF-05.3)
// =====================================================================
console.log('\n[3] Endpoint 2 · fetchClans()');
const catalogResult = await client.fetchClans({ lineage: 'primordialFlame', status: 'active', page: 2, perPage: 20 });
assertCondition(catalogResult.success === true, 'el catálogo responde con el sobre de éxito');
const catalogCall = stub.calls.at(-1);
assertCondition(catalogCall.method === 'GET', 'el catálogo se consulta con GET');
assertCondition(
  catalogCall.url === '/api/v1/clans?lineage=primordialFlame&status=active&page=2&perPage=20',
  `la query porta los cuatro filtros canónicos (${catalogCall.url})`
);
const bareCatalog = createFetchStub([{ match: '/api/v1/clans', status: 200, body: { success: true, data: { items: [], pagination: {} } } }]);
const bareClient = createClanClient({ fetch: bareCatalog });
await bareClient.fetchClans({ lineage: undefined, status: '', page: 1 });
assertCondition(
  bareCatalog.calls.at(-1).url === '/api/v1/clans?page=1',
  `los filtros ausentes no viajan como cadenas vacías (${bareCatalog.calls.at(-1).url})`
);

// 400 UNKNOWN_LINEAGE: el catálogo no se vacía en silencio, se rechaza.
const unknownLineageStub = createFetchStub([
  { match: '/api/v1/clans', status: 400, body: { success: false, error: { code: 'UNKNOWN_LINEAGE', message: 'Ese linaje no figura entre los ocho canónicos del santuario.' } } },
]);
const unknownLineageClient = createClanClient({ fetch: unknownLineageStub });
const unknownLineageResult = await unknownLineageClient.fetchClans({ lineage: 'necromancia' });
assertCondition(unknownLineageResult?.error?.code === CLAN_ERROR_CODES.unknownLineage, 'un linaje ajeno al canon llega como 400 UNKNOWN_LINEAGE');

// =====================================================================
// [4] Endpoint 3 · Ficha heráldica (RF-01.3)
// =====================================================================
console.log('\n[4] Endpoint 3 · fetchClan()');
const fichaResult = await client.fetchClan('cln_llama');
assertCondition(fichaResult.success === true && fichaResult.data?.clan?.memberCount === 7, 'la ficha porta censo y contadores');
assertCondition(
  stub.calls.at(-1).url === '/api/v1/clans/cln_llama',
  `la ficha se consulta por su identificador (${stub.calls.at(-1).url})`
);
await client.fetchClan('cln/llama');
assertCondition(
  stub.calls.at(-1).url === '/api/v1/clans/cln%2Fllama',
  'los identificadores se codifican: jamás se inyecta un segmento de ruta'
);
const notFoundStub = createFetchStub([
  { match: '/api/v1/clans/', status: 404, body: { success: false, error: { code: 'CLAN_NOT_FOUND', message: 'No hay hermandad inscrita con ese identificador.' } } },
]);
const notFoundClient = createClanClient({ fetch: notFoundStub });
const notFoundResult = await notFoundClient.fetchClan('cln_inexistente');
assertCondition(notFoundResult?.status === 404 && notFoundResult?.error?.code === CLAN_ERROR_CODES.clanNotFound, 'el 404 CLAN_NOT_FOUND se propaga sin lanzar');

// =====================================================================
// [5] Endpoint 4 · Muda de heráldica (RF-01.3)
// =====================================================================
console.log('\n[5] Endpoint 4 · updateClan()');
const updateResult = await client.updateClan('cln_llama', { admissionMode: 'byApplication' });
assertCondition(updateResult.success === true, 'la muda responde con la ficha actualizada');
const updateCall = stub.calls.at(-1);
assertCondition(updateCall.method === 'PATCH', 'la muda usa PATCH, no un PUT que reescriba la casa entera');
assertCondition(JSON.parse(updateCall.body).admissionMode === 'byApplication', 'la muda porta solo el campo mudado');
const notPatriarchStub = createFetchStub([
  { match: '/api/v1/clans/', status: 403, body: { success: false, error: { code: 'NOT_PATRIARCH', message: 'Solo quien ciñe la corona puede gobernar esta hermandad.' } } },
]);
const notPatriarchClient = createClanClient({ fetch: notPatriarchStub });
const notPatriarchResult = await notPatriarchClient.updateClan('cln_llama', { motto: 'Otro lema' });
assertCondition(notPatriarchResult?.status === 403 && notPatriarchResult?.error?.code === CLAN_ERROR_CODES.notPatriarch, 'el 403 NOT_PATRIARCH se propaga sin lanzar');

// =====================================================================
// [6] Endpoints 5-6 · Postulación y deliberación (RF-01.5)
// =====================================================================
console.log('\n[6] Endpoints 5 y 6 · applyToClan() y resolveApplication()');
const admissionResult = await client.applyToClan('cln_llama');
assertCondition(admissionResult.success === true && admissionResult.status === 201, 'la postulación responde 201 en régimen abierto');
assertCondition(
  stub.calls.at(-1).url === '/api/v1/clans/cln_llama/applications' && stub.calls.at(-1).method === 'POST',
  'la postulación se cursa contra la ruta canónica'
);

const resolutionResult = await client.resolveApplication('cln_llama', 'app_1', 'approve');
assertCondition(resolutionResult.success === true, 'la deliberación responde con su desenlace');
const resolutionCall = stub.calls.at(-1);
assertCondition(
  resolutionCall.url === '/api/v1/clans/cln_llama/applications/app_1/resolve',
  `la deliberación viaja por la ruta de la solicitud (${resolutionCall.url})`
);
assertCondition(JSON.parse(resolutionCall.body).action === 'approve', 'el veredicto viaja en el campo «action» que el santuario exige');

const invalidDecisionStub = createFetchStub([
  { match: '/resolve', status: 400, body: { success: false, error: { code: 'INVALID_DECISION', message: 'El veredicto solo admite «approve» o «reject».' } } },
]);
const invalidDecisionClient = createClanClient({ fetch: invalidDecisionStub });
const invalidDecisionResult = await invalidDecisionClient.resolveApplication('cln_llama', 'app_1', 'quizá');
assertCondition(invalidDecisionResult?.error?.code === CLAN_ERROR_CODES.invalidDecision, 'un veredicto ajeno al canon llega como 400 INVALID_DECISION');

const quotaStub = createFetchStub([
  { match: '/applications', status: 409, body: { success: false, error: { code: 'CLAN_QUOTA_EXCEEDED', message: 'La hermandad ha alcanzado su plenitud de 30 adeptos activos.', recoveryAction: 'SEEK_ANOTHER_CLAN' } } },
]);
const quotaClient = createClanClient({ fetch: quotaStub });
const quotaResult = await quotaClient.applyToClan('cln_lleno');
assertCondition(quotaResult?.error?.code === CLAN_ERROR_CODES.quotaExceeded, 'el cupo colmado llega como 409 CLAN_QUOTA_EXCEEDED');
assertCondition(ceremonialLegendFor(quotaResult).includes('plenitud'), 'la leyenda del cupo se sirve en noble castellano');

// =====================================================================
// [7] Endpoints 7-9 · Renuncia, expulsión y traspaso (RF-01.6, RF-01.3)
// =====================================================================
console.log('\n[7] Endpoints 7, 8 y 9 · leaveClan(), expelMember() y transferLeadership()');
const leaveResult = await client.leaveClan('cln_llama');
assertCondition(leaveResult.success === true && leaveResult.data?.convalescenceExpiresAt === '2026-09-28T12:00:00Z', 'la renuncia devuelve su ventana de convalecencia');
assertCondition(stub.calls.at(-1).url === '/api/v1/clans/cln_llama/leave', 'la renuncia usa su ruta propia');

const expelResult = await client.expelMember('cln_llama', 'usr_adepto');
assertCondition(expelResult.success === true, 'la expulsión responde con la membresía cerrada');
assertCondition(
  stub.calls.at(-1).url === '/api/v1/clans/cln_llama/expel/usr_adepto',
  `la expulsión porta el adepto en la ruta (${stub.calls.at(-1).url})`
);

const crownResult = await client.transferLeadership('cln_llama', 'usr_heredero');
assertCondition(crownResult.success === true && crownResult.data?.role === 'patriarch', 'el traspaso devuelve la membresía coronada');
const crownCall = stub.calls.at(-1);
assertCondition(crownCall.url === '/api/v1/clans/cln_llama/transfer-leadership', 'el traspaso usa su ruta propia');
assertCondition(JSON.parse(crownCall.body).newPatriarchId === 'usr_heredero', 'el heredero viaja en el campo canónico newPatriarchId');

const transferVetoStub = createFetchStub([
  { match: '/transfer-leadership', status: 400, body: { success: false, error: { code: 'INELIGIBLE_SUCCESSOR', message: 'El adepto propuesto no es apto para ceñir la corona de la casa.' } } },
]);
const transferVetoClient = createClanClient({ fetch: transferVetoStub });
const transferVetoResult = await transferVetoClient.transferLeadership('cln_llama', 'usr_ajeno');
assertCondition(transferVetoResult?.error?.code === CLAN_ERROR_CODES.ineligibleSuccessor, 'un heredero inepto llega como 400 INELIGIBLE_SUCCESSOR');

// =====================================================================
// [8] Credencial Bearer opcional y aditiva
// =====================================================================
console.log('\n[8] Credenciales');
assertCondition(stub.calls.every((call) => call.credentials === 'same-origin'), 'toda petición viaja con credentials same-origin: la cookie de sesión va sola');
assertCondition(
  stub.calls.every((call) => call.headers.Authorization === undefined),
  'sin token inyectado no se inventa credencial Bearer alguna'
);

const bearerStub = createFetchStub([{ match: '/api/v1/clans', status: 200, body: { success: true, data: { items: [], pagination: {} } } }]);
const bearerClient = createClanClient({ fetch: bearerStub, token: 'sello-del-custodio' });
await bearerClient.fetchClans();
assertCondition(
  bearerStub.calls.at(-1).headers.Authorization === 'Bearer sello-del-custodio',
  'con token inyectado la petición porta Authorization: Bearer'
);
const blankTokenStub = createFetchStub([{ match: '/api/v1/clans', status: 200, body: { success: true, data: { items: [], pagination: {} } } }]);
await createClanClient({ fetch: blankTokenStub, token: '   ' }).fetchClans();
assertCondition(blankTokenStub.calls.at(-1).headers.Authorization === undefined, 'un token en blanco no se traduce en una cabecera vacía');

// =====================================================================
// [9] Nunca lanza: red caída, JSON ilegible y estados sin sobre
// =====================================================================
console.log('\n[9] Los fallos de corriente se convierten en sobres controlados');
const brokenClient = createClanClient({ fetch: async () => { throw new TypeError('Failed to fetch'); } });
const brokenResult = await brokenClient.fetchClans();
assertCondition(brokenResult.success === false && brokenResult.status === 0, 'el corte de red se convierte en error controlado sin estado HTTP');
assertCondition(brokenResult.error?.code === CLAN_ERROR_CODES.networkError, `el corte porta el código canónico (${brokenResult.error?.code})`);
assertCondition(ceremonialLegendFor(brokenResult).includes('interrumpido'), 'el corte de red se anuncia con leyenda ceremonial en castellano');

const garbageClient = createClanClient({
  fetch: async () => ({ ok: true, status: 200, json: async () => { throw new SyntaxError('Unexpected token <'); } }),
});
const garbageResult = await garbageClient.fetchClans();
assertCondition(garbageResult.success === false && garbageResult.error?.code === CLAN_ERROR_CODES.networkError, 'un cuerpo ilegible no rompe la vista del catálogo');

const htmlErrorClient = createClanClient({ fetch: async () => ({ ok: false, status: 502, json: async () => { throw new SyntaxError('nope'); } }) });
const htmlErrorResult = await htmlErrorClient.foundClan({ name: 'Casa Tras El Proxy' });
assertCondition(htmlErrorResult.success === false && htmlErrorResult.status === 502, 'un 502 sin sobre JSON se traduce en error controlado con su estado');

// =====================================================================
// [10] Paridad de contratos con el router real (anti-deriva)
// =====================================================================
console.log('\n[10] Paridad de contratos con el Front Controller');
const routerSource = await readFile(new URL('../public/index.php', import.meta.url), 'utf8');
const registeredRoutes = [...routerSource.matchAll(/addRoute\('([A-Z]+)',\s*'([^']+)'/g)].map(([, method, pattern]) => ({ method, pattern }));
assertCondition(registeredRoutes.length > 0, `el Front Controller declara sus rutas (${registeredRoutes.length} encontradas)`);

/** Convierte un patrón del router en expresión regular, igual que él lo hace. */
function routePatternToRegExp(pattern) {
  const escaped = pattern.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
  return new RegExp(`^${escaped.replace(/\\\{[^}]+\\\}/g, '[^/]+')}$`);
}

const consulted = [...stub.calls, ...bareCatalog.calls, ...rejectionStub.calls, ...unprocessableStub.calls, ...unknownLineageStub.calls, ...notFoundStub.calls, ...notPatriarchStub.calls, ...invalidDecisionStub.calls, ...quotaStub.calls, ...transferVetoStub.calls, ...bearerStub.calls]
  .map((call) => ({ method: call.method, path: call.url.split('?')[0] }));
const orphanCalls = consulted.filter((call) => !registeredRoutes.some(
  (route) => route.method === call.method && routePatternToRegExp(route.pattern).test(call.path)
));
assertCondition(
  orphanCalls.length === 0,
  `toda URL invocada por el cliente existe en el router${orphanCalls.length === 0 ? '' : ` — huérfanas: ${orphanCalls.map((call) => `${call.method} ${call.path}`).join(', ')}`}`
);
assertCondition(
  registeredRoutes.some((route) => route.method === 'POST' && route.pattern === '/api/v1/clans')
    && registeredRoutes.some((route) => route.method === 'PATCH' && route.pattern === '/api/v1/clans/{id}')
    && registeredRoutes.some((route) => route.method === 'POST' && route.pattern === '/api/v1/clans/{id}/transfer-leadership'),
  'las rutas mutables del gobierno están registradas con su método propio'
);

// =====================================================================
// [11] Smoke E2E real contra el santuario local (omitible sin servidor)
// =====================================================================
console.log('\n[11] Smoke E2E real contra el santuario local');
const { default: http } = await import('node:http');
const LIVE_BASE = process.env.GRIMORIO_LIVE_BASE ?? 'http://127.0.0.1:8095';

/**
 * Sonda HTTP mínima con node:http: el fetch global de Node (undici) deja un
 * socket keep-alive pendiente que dispara una aserción libuv al salir en
 * Windows. La sonda es del arnés; el cliente de producción usa fetch nativo.
 */
function liveRequest(method, path) {
  return new Promise((resolve) => {
    const { hostname, port, pathname, search } = new URL(LIVE_BASE + path);
    const request = http.request({ hostname, port, path: pathname + search, method, timeout: 2000 }, (response) => {
      let raw = '';
      response.on('data', (chunk) => { raw += chunk; });
      response.on('end', () => {
        let payload = null;
        try { payload = JSON.parse(raw); } catch { payload = null; }
        resolve({ ok: response.statusCode >= 200 && response.statusCode < 300, status: response.statusCode, payload });
      });
    });
    request.on('error', () => resolve({ ok: false, status: 0, payload: null }));
    request.on('timeout', () => { request.destroy(); resolve({ ok: false, status: 0, payload: null }); });
    request.end();
  });
}

const probe = await liveRequest('GET', '/api/v1/clans');
if (!probe.ok) {
  console.log('  [OMITIDA] Santuario local no disponible: arranca `php -S 127.0.0.1:8095 scratch/demo_router.php` y repite para el smoke completo');
} else {
  const liveClient = createClanClient({
    baseUrl: LIVE_BASE + '/api/v1',
    // El cliente de producción usa fetch nativo; la sonda lo puentea a node:http.
    fetch: (url, init = {}) => new Promise((resolve, reject) => {
      const { hostname, port, pathname, search } = new URL(url);
      const request = http.request({
        hostname,
        port,
        path: pathname + search,
        method: init.method ?? 'GET',
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

  const liveCatalog = await liveClient.fetchClans({ page: 1, perPage: 5 });
  assertCondition(
    liveCatalog.success === true && Array.isArray(liveCatalog.data?.items),
    `GET /api/v1/clans real: ${liveCatalog.data?.items?.length ?? 0} hermandades y su paginación`
  );

  const liveFiltered = await liveClient.fetchClans({ status: 'active', page: 1, perPage: 5 });
  assertCondition(liveFiltered.success === true, 'GET /api/v1/clans?status=active real: el filtro canónico responde 200');

  const liveUnknownLineage = await liveClient.fetchClans({ lineage: 'necromancia' });
  assertCondition(
    liveUnknownLineage.status === 400 && liveUnknownLineage.error?.code === CLAN_ERROR_CODES.unknownLineage,
    'GET con linaje ajeno al canon real: 400 UNKNOWN_LINEAGE a través del cliente'
  );

  const liveMissingClan = await liveClient.fetchClan('cln_inexistente');
  assertCondition(
    liveMissingClan.status === 404 && liveMissingClan.error?.code === CLAN_ERROR_CODES.clanNotFound,
    'GET /api/v1/clans/cln_inexistente real: 404 CLAN_NOT_FOUND a través del cliente'
  );

  const liveUnauthenticated = await liveClient.foundClan({ name: 'Casa Anónima', lineageType: 'primordialFlame' });
  assertCondition(
    liveUnauthenticated.status === 401 && liveUnauthenticated.error?.code === CLAN_ERROR_CODES.unauthenticated,
    'POST /api/v1/clans sin vínculo arcano real: 401 UNAUTHENTICATED a través del cliente'
  );
}

// =====================================================================
// Resumen
// =====================================================================
console.log(`\n== RESUMEN == Asertos superados: ${assertsPassed}, fallidos: ${assertsFailed}`);
if (assertsFailed === 0) {
  console.log('RESULTADO: EXITO — El cliente de hermandades consume los nueve endpoints y doma 400/401/404/409/422 (Tarea 5.1).');
  process.exit(0);
}
console.log('RESULTADO: DENEGADO — Revisa los asertos marcados.');
process.exit(1);
