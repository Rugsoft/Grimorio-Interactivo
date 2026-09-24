/**
 * test_vestibule_client.mjs — Arnés de la Tarea 4.1 (TASKS-10).
 *
 * Verifica sobre `public/assets/js/api/vestibuleClient.js`:
 *   [1]  Superficie del módulo: fábrica, códigos canónicos y leyendas.
 *   [2]  Endpoint 1 · fetchVestibule(): el sobre único sin parámetro (RF-01.2, RNF-04).
 *   [3]  Endpoint 3 · withdrawApplication(): retirada con ids codificados (RF-03.3).
 *   [4]  Endpoint 4 · acknowledgeVerdict(): contemplado idempotente (RF-03.4).
 *   [5]  Endpoint 5 · fetchUnreadVerdictsCount(): contador del rótulo (RF-01.1).
 *   [6]  Mapeo de errores: cada código canónico lleva su leyenda del Anexo A
 *        y la leyenda del santuario tiene precedencia. Incluye los guardias
 *        ratificados por la auditoría de la Fase 7: CONVALESCENCE_ACTIVE
 *        (Tarea 7.2) y CLAN_QUOTA_EXCEEDED (Tarea 7.4).
 *   [7]  Nunca lanza: red caída, JSON ilegible y estados sin sobre.
 *   [8]  Credenciales: cookie de sesión ('same-origin') en TODAS las peticiones.
 *   [9]  Paridad de contratos con el Front Controller (anti-deriva): toda
 *        URL invocada existe de verdad en public/index.php.
 *   [10] Smoke E2E real contra el santuario local (omitible sin servidor).
 *
 * Criterio «Hecho cuando» (Tarea 4.1): cada código de error se traduce a
 * la leyenda del Anexo A y ninguna respuesta interna expone trazas.
 *
 * Constitución:
 *   - Artículo I (Dogma Vanilla): fetch nativo; cero librerías.
 *   - Artículo V: identificadores en inglés camelCase; comentarios en castellano.
 *
 * Uso: node scratch/test_vestibule_client.mjs
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

const {
  createVestibuleClient,
  VESTIBULE_ERROR_CODES,
  VESTIBULE_CEREMONIAL_LEGENDS,
  ceremonialLegendFor,
} = await import('../public/assets/js/api/vestibuleClient.js');

// ---------------------------------------------------------------------
// Doble de fetch: graba cada petición y responde desde una cola de guiones.
// ---------------------------------------------------------------------

function createFetchStub(routes) {
  const calls = [];
  const stub = async (url, options = {}) => {
    const rawUrl = String(url);
    const call = {
      url: rawUrl,
      // Ruta sin query: permite emparejar por camino EXACTO.
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

const SOBRE_VESTIBULO = {
  success: true,
  data: {
    adeptState: {
      lineage: 'celestialTides',
      membership: null,
      aptitude: { isApt: true, vedado: null, pendingPetitionsCount: 0, pendingPetitionsLimit: 3 },
      unreadVerdictsCount: 2,
    },
    myHouse: null,
    clans: [
      {
        clanId: 'cln_mareas', name: 'Mareas de Aether', motto: '...', coatOfArms: 'cln_mareas',
        lineageType: 'celestialTides', memberCount: 12, memberLimit: 30,
        admissionMode: 'open', admissionModeLabel: 'Admisión abierta', isRegent: false,
        adeptRelation: 'none', gesture: 'join', vedadoLegend: null,
      },
    ],
    petitions: [],
  },
};

const client = createVestibuleClient();

// =====================================================================
// [1] Superficie del módulo
// =====================================================================
console.log('[1] Superficie del módulo');
assertCondition(typeof createVestibuleClient === 'function', 'el módulo exporta la fábrica createVestibuleClient');
assertCondition(typeof ceremonialLegendFor === 'function', 'el módulo exporta el traductor de leyendas ceremoniales');
assertCondition(
  VESTIBULE_ERROR_CODES.clanLineageMismatch === 'CLAN_LINEAGE_MISMATCH'
    && VESTIBULE_ERROR_CODES.clanLoyaltyBound === 'CLAN_LOYALTY_BOUND'
    && VESTIBULE_ERROR_CODES.adminLineageRequired === 'ADMIN_LINEAGE_REQUIRED'
    && VESTIBULE_ERROR_CODES.applicationHouseClosed === 'APPLICATION_HOUSE_CLOSED',
  'los cuatro códigos solemnes de SPEC-10 viajan en el mapa de errores'
);
assertCondition(
  VESTIBULE_ERROR_CODES.lineageOathRequired === 'LINEAGE_OATH_REQUIRED'
    && VESTIBULE_ERROR_CODES.unauthenticated === 'UNAUTHENTICATED',
  'los códigos heredados (SPEC-09 retención, SPEC-03 sesión) viajan también'
);

// =====================================================================
// [2] Endpoint 1 · fetchVestibule() — el sobre único
// =====================================================================
console.log('\n[2] Endpoint 1 · fetchVestibule()');
const stubEstado = createFetchStub([
  { path: '/api/v1/clans/vestibule', method: 'GET', status: 200, body: SOBRE_VESTIBULO },
]);
const clientEstado = createVestibuleClient({ fetch: stubEstado });
const estado = await clientEstado.fetchVestibule();
assertCondition(estado.success === true && estado.status === 200, 'el sobre feliz responde success con estado 200');
assertCondition(estado.data?.adeptState?.aptitude?.isApt === true, 'el sobre porta la aptitud derivada del instante (RF-01.7)');
assertCondition(stubEstado.calls.length === 1 && stubEstado.calls[0].method === 'GET', 'el sobre viaja por GET');
assertCondition(
  !stubEstado.calls[0].url.includes('?'),
  'sin parámetro de filtro alguno: el linaje lo deriva la sesión (RF-01.2)'
);
assertCondition(stubEstado.calls[0].credentials === 'same-origin', 'la cookie de sesión viaja sola (credentials same-origin)');

// =====================================================================
// [3] Endpoint 3 · withdrawApplication() — la retirada
// =====================================================================
console.log('\n[3] Endpoint 3 · withdrawApplication()');
const PETICION_RETIRADA = { success: true, data: { application: { id: 'app_9f2', status: 'cancelled' } } };
const stubRetirada = createFetchStub([
  { path: '/api/v1/clans/cln_tempestad/applications/app_9f2/withdraw', method: 'POST', status: 200, body: PETICION_RETIRADA },
]);
const clientRetirada = createVestibuleClient({ fetch: stubRetirada });
const retirada = await clientRetirada.withdrawApplication('cln_tempestad', 'app_9f2');
assertCondition(retirada.success === true && retirada.data.application.status === 'cancelled', 'la retirada feliz responde 200 con la fila cancelada');
assertCondition(stubRetirada.calls[0].method === 'POST', 'la retirada viaja por POST (mutación)');
assertCondition(
  stubRetirada.calls[0].path === '/api/v1/clans/cln_tempestad/applications/app_9f2/withdraw',
  'la ruta porta casa y petición en su segmento propio'
);

// Carrera con el dictamen: 409 con leyenda solemne.
const stubCarrera = createFetchStub([
  {
    path: '/api/v1/clans/cln_tempestad/applications/app_9f2/withdraw', method: 'POST', status: 409,
    body: { success: false, error: { code: 'APPLICATION_ALREADY_RESOLVED', message: '', recoveryAction: 'REVIEW' } },
  },
]);
const clientCarrera = createVestibuleClient({ fetch: stubCarrera });
const carrera = await clientCarrera.withdrawApplication('cln_tempestad', 'app_9f2');
assertCondition(
  carrera.success === false && carrera.status === 409 && carrera.error.code === 'APPLICATION_ALREADY_RESOLVED',
  'la carrera con el dictamen propaga 409 APPLICATION_ALREADY_RESOLVED (caso límite 5)'
);

// =====================================================================
// [4] Endpoint 4 · acknowledgeVerdict() — el contemplado
// =====================================================================
console.log('\n[4] Endpoint 4 · acknowledgeVerdict()');
const VEREDICTO_CONTEMPLADO = { success: true, data: { application: { id: 'app_9f2', verdictSeenAt: '2026-09-20T13:30:00Z' } } };
const stubContemplado = createFetchStub([
  { path: '/api/v1/clans/applications/app_9f2/verdict-acknowledge', method: 'POST', status: 200, body: VEREDICTO_CONTEMPLADO },
]);
const clientContemplado = createVestibuleClient({ fetch: stubContemplado });
const contemplado = await clientContemplado.acknowledgeVerdict('app_9f2');
assertCondition(contemplado.success === true && contemplado.data.application.verdictSeenAt !== null, 'el contemplado feliz responde 200 con su estampa (RF-03.4)');
assertCondition(stubContemplado.calls[0].method === 'POST', 'el contemplado viaja por POST (mutación)');
assertCondition(
  stubContemplado.calls[0].path === '/api/v1/clans/applications/app_9f2/verdict-acknowledge',
  'la ruta literal del contemplado no depende de casa alguna'
);

// =====================================================================
// [5] Endpoint 5 · fetchUnreadVerdictsCount() — el rótulo
// =====================================================================
console.log('\n[5] Endpoint 5 · fetchUnreadVerdictsCount()');
const stubRotulo = createFetchStub([
  { path: '/api/v1/clans/verdicts/unread-count', method: 'GET', status: 200, body: { success: true, data: { unreadVerdictsCount: 2 } } },
]);
const clientRotulo = createVestibuleClient({ fetch: stubRotulo });
const rotulo = await clientRotulo.fetchUnreadVerdictsCount();
assertCondition(rotulo.success === true && rotulo.data.unreadVerdictsCount === 2, 'el contador responde 200 con unreadVerdictsCount (RF-01.1)');
assertCondition(stubRotulo.calls[0].path === '/api/v1/clans/verdicts/unread-count', 'la ruta literal del rótulo no es capturada por {id}');

// =====================================================================
// [6] Mapeo de errores: leyendas del Anexo A (criterio «Hecho cuando»)
// =====================================================================
console.log('\n[6] Mapeo de errores a leyendas del Anexo A');
// Cada código canónico porta SU leyenda canónica (texto literal del anexo).
const leyesAnexo = {
  CLAN_LINEAGE_MISMATCH: 'Ese estandarte porta otro linaje: tu juramento te ata a las casas de tu propia sangre.',
  APPLICATION_HOUSE_CLOSED: 'Ya pronunciaste tu palabra ante esta casa: rechazada o retirada, quedó clausurada para ti. Otras puertas aguardan.',
  ADMIN_LINEAGE_REQUIRED: 'El Privilegio Fundacional te exime del juramento; sin linaje jurado no hay hermandades que contemplar.',
};
for (const [code, legend] of Object.entries(leyesAnexo)) {
  assertCondition(
    VESTIBULE_CEREMONIAL_LEGENDS[code] === legend,
    `la leyenda de ${code} es la literal del Anexo A`
  );
}
assertCondition(
  VESTIBULE_CEREMONIAL_LEGENDS[VESTIBULE_ERROR_CODES.invalidMotivation].includes('desborda el pergamino'),
  'el molde excedido porta su leyenda del Anexo A (leyenda 6)'
);

// Guardias ratificados por la auditoría de la Fase 7 (Tareas 7.2 y 7.4):
// convalecencia activa (403) y carrera de plenitud perdida (409) visten
// sus leyendas canónicas del santuario.
assertCondition(
  VESTIBULE_CEREMONIAL_LEGENDS[VESTIBULE_ERROR_CODES.convalescenceActive] === 'Tu esencia mágica aún se encuentra en convalecencia tras disolver tu juramento anterior.',
  'CONVALESCENCE_ACTIVE porta su leyenda canónica (RF-03.5, Tarea 7.2)'
);
assertCondition(
  VESTIBULE_CEREMONIAL_LEGENDS[VESTIBULE_ERROR_CODES.clanQuotaExceeded] === 'La hermandad ha alcanzado su plenitud de treinta hermanos.',
  'CLAN_QUOTA_EXCEEDED porta su leyenda de plenitud (RF-02.2, Tarea 7.4)'
);
for (const guardiaRatificado of [
  { status: 403, code: 'CONVALESCENCE_ACTIVE' },
  { status: 409, code: 'CLAN_QUOTA_EXCEEDED' },
]) {
  const stubGuardia = createFetchStub([
    { match: () => true, status: guardiaRatificado.status, body: { success: false, error: { code: guardiaRatificado.code, message: '', recoveryAction: 'RETRY' } } },
  ]);
  const clientGuardia = createVestibuleClient({ fetch: stubGuardia });
  // La vía real del gesto de adhesión es el rito de SPEC-07 (clanClient);
  // aquí se ejercita la MISMA traducción de sobre a leyenda canónica.
  const vedado = await clientGuardia.fetchVestibule();
  assertCondition(
    vedado.success === false
      && vedado.status === guardiaRatificado.status
      && vedado.error.code === guardiaRatificado.code
      && ceremonialLegendFor(vedado) === VESTIBULE_CEREMONIAL_LEGENDS[guardiaRatificado.code],
    `el guardia ${guardiaRatificado.code} se traduce a su leyenda con estado ${guardiaRatificado.status}`,
  );
}

// Escenario real: cada estado del contrato se traduce sin exponer trazas.
const escenariosError = [
  { status: 400, code: 'INVALID_MOTIVATION' },
  { status: 403, code: 'CLAN_LOYALTY_BOUND' },
  { status: 404, code: 'APPLICATION_NOT_FOUND' },
  { status: 409, code: 'APPLICATION_ALREADY_PENDING' },
];
for (const escenario of escenariosError) {
  const stubError = createFetchStub([
    {
      match: () => true,
      status: escenario.status,
      body: { success: false, error: { code: escenario.code, message: '', recoveryAction: 'RETRY' } },
    },
  ]);
  const clientError = createVestibuleClient({ fetch: stubError });
  const veredicto = await clientError.fetchVestibule();
  assertCondition(
    veredicto.success === false && veredicto.status === escenario.status && veredicto.error.code === escenario.code,
    `el estado ${escenario.status} propaga ${escenario.code} intacto`
  );
  const leyenda = ceremonialLegendFor(veredicto);
  assertCondition(
    leyenda === VESTIBULE_CEREMONIAL_LEGENDS[escenario.code] && leyenda.length > 0,
    `el código ${escenario.code} se traduce a su leyenda del Anexo A sin exponer trazas`
  );
}

assertCondition(
  ceremonialLegendFor({ error: { code: VESTIBULE_ERROR_CODES.clanLoyaltyBound, message: 'Tu lealtad ya está empeñada en «Brasa Viva»: solo renunciar a ella —y sobrevivir la convalecencia— abre de nuevo sus puertas.' } })
    .includes('Brasa Viva'),
  'la leyenda del santuario (con la casa nombrada) tiene precedencia sobre la del cliente'
);
assertCondition(
  ceremonialLegendFor({ error: { code: 'CODIGO_AJENO_SIN_LEYENDA' } }).length > 0,
  'un código desconocido sin mensaje jamás deja la interfaz en blanco'
);

// =====================================================================
// [7] Nunca lanza: red caída, JSON ilegible y estados sin sobre
// =====================================================================
console.log('\n[7] Nunca lanza: fallos domados en objeto controlado');
const stubRedCaida = createVestibuleClient({ fetch: async () => { throw new Error('ECONNREFUSED'); } });
const redCaida = await stubRedCaida.fetchVestibule();
assertCondition(
  redCaida.success === false && redCaida.status === 0 && redCaida.error.code === VESTIBULE_ERROR_CODES.networkError,
  'el corte de red real devuelve { success: false, status: 0, networkError } sin lanzar'
);
assertCondition(
  ceremonialLegendFor(redCaida).includes('corriente de maná'),
  'la leyenda de red caída es la del Anexo A (leyenda 7: «La corriente de maná…»)'
);

const stubJsonIlegible = createVestibuleClient({
  fetch: async () => ({ ok: false, status: 502, json: async () => { throw new SyntaxError('HTML no JSON'); } }),
});
const jsonIlegible = await stubJsonIlegible.fetchVestibule();
assertCondition(
  jsonIlegible.success === false && jsonIlegible.status === 502 && typeof jsonIlegible.error.code === 'string',
  'un 502 con cuerpo HTML se doma en error controlado sin exponer el cuerpo'
);

const stubSinSobre = createVestibuleClient({
  fetch: async () => ({ ok: false, status: 500, json: async () => ({ trace: 'PDOException en línea 42' }) }),
});
const sinSobre = await stubSinSobre.fetchVestibule();
assertCondition(
  sinSobre.success === false && !('trace' in sinSobre) && sinSobre.error.code === VESTIBULE_ERROR_CODES.networkError,
  'un 500 con sobre ausente jamás expone la traza interna (RNF-05)'
);

// =====================================================================
// [8] Credenciales y encodeo en TODAS las peticiones
// =====================================================================
console.log('\n[8] Credenciales de sesión y encodeo de segmentos');
const stubTodas = createFetchStub([
  { path: '/api/v1/clans/vestibule', method: 'GET', status: 200, body: SOBRE_VESTIBULO },
  { path: '/api/v1/clans/cln%20con%20espacios/applications/app%2F1/withdraw', method: 'POST', status: 200, body: PETICION_RETIRADA },
  { path: '/api/v1/clans/applications/app%2F1/verdict-acknowledge', method: 'POST', status: 200, body: VEREDICTO_CONTEMPLADO },
  { path: '/api/v1/clans/verdicts/unread-count', method: 'GET', status: 200, body: { success: true, data: { unreadVerdictsCount: 0 } } },
]);
const clientTodas = createVestibuleClient({ fetch: stubTodas });
await clientTodas.fetchVestibule();
await clientTodas.withdrawApplication('cln con espacios', 'app/1');
await clientTodas.acknowledgeVerdict('app/1');
await clientTodas.fetchUnreadVerdictsCount();
assertCondition(
  stubTodas.calls.length === 4 && stubTodas.calls.every((call) => call.credentials === 'same-origin'),
  'las cuatro funciones portan credentials same-origin (cookie de sesión SPEC-03)'
);
assertCondition(
  stubTodas.calls.every((call) => !String(call.url).includes(' ')) && !stubTodas.calls.some((call) => call.path.includes('app/1') && call.path.includes('applications/app%2F1') === false),
  'los segmentos de ruta van codificados: sin espacios ni barras inyectadas'
);

// =====================================================================
// [9] Paridad de contratos con el Front Controller (anti-deriva)
// =====================================================================
console.log('\n[9] Paridad de contratos con el Front Controller');
const routerSource = await readFile(new URL('../public/index.php', import.meta.url), 'utf8');
const registeredRoutes = [...routerSource.matchAll(/addRoute\('([A-Z]+)',\s*'([^']+)'/g)].map(([, method, pattern]) => ({ method, pattern }));
assertCondition(registeredRoutes.length > 0, `el Front Controller declara sus rutas (${registeredRoutes.length} encontradas)`);

/** Convierte un patrón del router en expresión regular, igual que él lo hace. */
function routePatternToRegExp(pattern) {
  const escaped = pattern.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
  return new RegExp(`^${escaped.replace(/\\\{[^}]+\\\}/g, '[^/]+')}$`);
}

const consulted = stubTodas.calls.concat(stubEstado.calls, stubRetirada.calls, stubContemplado.calls, stubRotulo.calls)
  .map((call) => ({ method: call.method, path: call.url.split('?')[0] }));
const orphanCalls = consulted.filter((call) => !registeredRoutes.some(
  (route) => route.method === call.method && routePatternToRegExp(route.pattern).test(call.path)
));
assertCondition(
  orphanCalls.length === 0,
  `toda URL invocada por el cliente existe en el router${orphanCalls.length === 0 ? '' : ` — huérfanas: ${orphanCalls.map((call) => `${call.method} ${call.path}`).join(', ')}`}`
);
assertCondition(
  registeredRoutes.some((route) => route.method === 'GET' && route.pattern === '/api/v1/clans/vestibule')
    && registeredRoutes.some((route) => route.method === 'GET' && route.pattern === '/api/v1/clans/verdicts/unread-count')
    && registeredRoutes.some((route) => route.method === 'POST' && route.pattern === '/api/v1/clans/{id}/applications/{appId}/withdraw')
    && registeredRoutes.some((route) => route.method === 'POST' && route.pattern === '/api/v1/clans/applications/{appId}/verdict-acknowledge'),
  'las cuatro rutas del Vestíbulo están registradas con su método propio (Tarea 3.4)'
);

// =====================================================================
// [10] Smoke E2E real contra el santuario local (omitible sin servidor)
// =====================================================================
console.log('\n[10] Smoke E2E real contra el santuario local');
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
  console.log('  [OMITIDA] Santuario local no disponible: arranca `php -S 127.0.0.1:8095 -t public` y repite para el smoke completo');
} else {
  const liveClient = createVestibuleClient({
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
          json: async () => { try { return JSON.parse(raw); } catch { return null; } },
        }));
      });
      request.on('error', reject);
      if (init.body !== undefined) {
        request.write(init.body);
      }
      request.end();
    }),
  });

  const liveAnonimo = await liveClient.fetchVestibule();
  assertCondition(
    liveAnonimo.status === 401,
    'GET /api/v1/clans/vestibule sin vínculo arcano real: 401 a través del cliente'
  );
  const liveContador = await liveClient.fetchUnreadVerdictsCount();
  assertCondition(
    liveContador.status === 401,
    'GET /api/v1/clans/verdicts/unread-count sin sesión real: 401 a través del cliente'
  );
}

// =====================================================================
// Resumen
// =====================================================================
console.log(`\n== RESUMEN == Asertos superados: ${assertsPassed}, fallidos: ${assertsFailed}`);
if (assertsFailed === 0) {
  console.log('RESULTADO: EXITO — El cliente del Vestíbulo consume los cuatro endpoints y cada error viste su leyenda del Anexo A sin exponer trazas, incluidos los guardias ratificados en la Fase 7 (Tareas 4.1 y 8.3).');
  process.exit(0);
}
console.log('RESULTADO: DENEGADO — Revisa los asertos marcados.');
process.exit(1);
