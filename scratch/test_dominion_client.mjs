/**
 * test_dominion_client.mjs — Arnés de la Tarea 5.1 (TASKS-07).
 *
 * Verifica sobre `public/assets/js/api/dominionClient.js`:
 *   [1]  Superficie del módulo: fábrica, códigos, leyendas y cabecera del sello.
 *   [2]  Endpoint 10 · fetchLineages(): los ocho Linajes Canónicos (RF-02.1).
 *   [3]  Endpoint 11 · fetchLeaderboard(): Salón del Dominio (RF-06.1, RF-06.2).
 *   [4]  Endpoint 12 · closeCycle(): corte dominical con el sello del custodio
 *        viajando SIEMPRE en cabecera, jamás en cuerpo ni query (RF-04.1-04.3).
 *   [5]  Credencial Bearer OPCIONAL y aditiva, junto a la cookie de sesión.
 *   [6]  Nunca lanza: red caída, JSON ilegible y estados sin sobre.
 *   [7]  Paridad de contratos con las rutas realmente registradas en el Front
 *        Controller (anti-deriva).
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

const clientModule = await import('../public/assets/js/api/dominionClient.js');
const {
  createDominionClient,
  DOMINION_ERROR_CODES,
  DOMINION_CEREMONIAL_LEGENDS,
  dominionLegendFor,
  CRON_SECRET_HEADER,
} = clientModule;

// ---------------------------------------------------------------------
// Doble de fetch: graba cada petición y responde por camino EXACTO.
// ---------------------------------------------------------------------

function createFetchStub(routes) {
  const calls = [];
  const stub = async (url, options = {}) => {
    const rawUrl = String(url);
    const call = {
      url: rawUrl,
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

const LINEAGE_PAYLOAD = {
  success: true,
  count: 8,
  data: [
    { id: 'primordialFlame', name: 'Linaje de la Llama Primordial', rulingElement: 'fire', glyph: 'rune-ignis', bannerColor: '#ff4500', heraldicFrame: 'phoenixShield' },
    { id: 'celestialTides', name: 'Linaje de las Mareas Celestiales', rulingElement: 'water', glyph: 'rune-aqua', bannerColor: '#00bfff', heraldicFrame: 'leviathanShield' },
  ],
};

const LEADERBOARD_PAYLOAD = {
  success: true,
  data: {
    weeklyRanking: [
      { rank: 1, clan: { id: 'cln_llama', name: 'Custodios de la Llama', lineageType: 'primordialFlame', weeklyPoints: 480, memberCount: 12 } },
      { rank: 2, clan: { id: 'cln_marea', name: 'Casa de la Marea', lineageType: 'celestialTides', weeklyPoints: 90, memberCount: 4 } },
    ],
    historicalRanking: [{ rank: 1, clan: { id: 'cln_llama', historicalPoints: 620 } }],
    currentRegentClan: { id: 'cln_llama', name: 'Custodios de la Llama', motto: 'En la ceniza renace la llama inmortal' },
    hallOfFameWeeks: [{ weekNumber: 37, cycleYear: 2026, regentClanId: 'cln_tempestad', winningPoints: 500 }],
  },
};

const CLOSE_PAYLOAD = {
  success: true,
  data: {
    cycle: { id: 'cyc_37', weekNumber: 37, cycleYear: 2026, regentClanId: 'cln_llama', winningPoints: 480, winnerSpellCount: 3, closedAt: '2026-09-13T23:59:59Z' },
    regentClan: { id: 'cln_llama', name: 'Custodios de la Llama' },
  },
};

// =====================================================================
// [1] Superficie del módulo
// =====================================================================
console.log('[1] Superficie del módulo');
assertCondition(typeof createDominionClient === 'function', 'el módulo exporta la fábrica createDominionClient');
assertCondition(typeof dominionLegendFor === 'function', 'el módulo exporta el traductor de leyendas ceremoniales');
assertCondition(CRON_SECRET_HEADER === 'X-Arcane-Cron-Secret', 'la cabecera del sello del custodio es la canónica');
assertCondition(
  DOMINION_ERROR_CODES.cronSecretRequired === 'CRON_SECRET_REQUIRED'
    && DOMINION_ERROR_CODES.cronSecretInvalid === 'CRON_SECRET_INVALID',
  'los códigos del corte dominical viajan en el mapa de errores'
);
assertCondition(
  typeof DOMINION_CEREMONIAL_LEGENDS[DOMINION_ERROR_CODES.cronSecretInvalid] === 'string',
  'el canon ceremonial (RNF-03) declara su leyenda del sello ajeno'
);
assertCondition(
  dominionLegendFor({ error: { code: 'CODIGO_AJENO_SIN_LEYENDA' } }).length > 0,
  'un código desconocido sin mensaje jamás deja la vista del Salón en blanco'
);

// =====================================================================
// [2] Endpoint 10 · Salón de los Linajes (RF-02.1, RF-02.2)
// =====================================================================
console.log('\n[2] Endpoint 10 · fetchLineages()');
const stub = createFetchStub([
  { path: '/api/v1/lineages', method: 'GET', status: 200, body: LINEAGE_PAYLOAD },
  { path: '/api/v1/dominion/leaderboard', method: 'GET', status: 200, body: LEADERBOARD_PAYLOAD },
  { path: '/api/v1/dominion/cron-cycle-close', method: 'POST', status: 200, body: CLOSE_PAYLOAD },
]);
const client = createDominionClient({ fetch: stub });

const lineagesResult = await client.fetchLineages();
assertCondition(lineagesResult.success === true && lineagesResult.count === 8, 'el canon de los ocho linajes se propaga con su recuento');
assertCondition(lineagesResult.data?.[0]?.rulingElement === 'fire', 'cada linaje porta su elemento rector');
const lineagesCall = stub.calls.at(-1);
assertCondition(lineagesCall.path === '/api/v1/lineages' && lineagesCall.method === 'GET', `la ruta y el método son canónicos (${lineagesCall.method} ${lineagesCall.path})`);
assertCondition(lineagesCall.headers.Accept === 'application/json', 'toda petición declara Accept: application/json');

// =====================================================================
// [3] Endpoint 11 · Salón del Dominio (RF-06.1, RF-06.2)
// =====================================================================
console.log('\n[3] Endpoint 11 · fetchLeaderboard()');
const leaderboardResult = await client.fetchLeaderboard();
assertCondition(leaderboardResult.success === true, 'el Salón responde con el sobre de éxito');
assertCondition(
  Array.isArray(leaderboardResult.data?.weeklyRanking)
    && leaderboardResult.data.weeklyRanking.length === 2
    && leaderboardResult.data.weeklyRanking[0].clan.weeklyPoints === 480,
  'la clasificación semanal en vivo llega ordenada por PDA descendente'
);
assertCondition(
  leaderboardResult.data?.historicalRanking !== undefined
    && leaderboardResult.data?.currentRegentClan?.id === 'cln_llama'
    && Array.isArray(leaderboardResult.data?.hallOfFameWeeks),
  'las cuatro secciones del Salón viajan íntegras (semanal, histórico, regente y Libro Mayor)'
);
assertCondition(leaderboardResult.data?.weeklyRanking[0].clan.memberCount === 12, 'cada estandarte porta el censo de adeptos que el Salón exige');
assertCondition(
  stub.calls.at(-1).path === '/api/v1/dominion/leaderboard',
  `la lectura pública usa la ruta del Salón (${stub.calls.at(-1).path})`
);
assertCondition(
  stub.calls.every((call) => call.credentials === 'same-origin'),
  'toda petición viaja con credentials same-origin: el Salón es público y la sesión, si existe, acompaña sola'
);

// =====================================================================
// [4] Endpoint 12 · Corte dominical (RF-04.1 a RF-04.3)
// =====================================================================
console.log('\n[4] Endpoint 12 · closeCycle()');
const sealedClient = createDominionClient({ fetch: stub, cronSecret: 'sello-del-custodio' });
const closeResult = await sealedClient.closeCycle();
assertCondition(closeResult.success === true && closeResult.data?.cycle?.weekNumber === 37, 'el corte devuelve el acta de la semana concluida');
assertCondition(closeResult.data?.regentClan?.id === 'cln_llama', 'el acta porta la casa que ciñe la corona');
const closeCall = stub.calls.at(-1);
assertCondition(closeCall.method === 'POST' && closeCall.path === '/api/v1/dominion/cron-cycle-close', 'el corte se invoca por POST en su ruta propia');
assertCondition(
  closeCall.headers[CRON_SECRET_HEADER] === 'sello-del-custodio',
  'el sello del custodio viaja en la cabecera canónica X-Arcane-Cron-Secret'
);
assertCondition(closeCall.body === null || closeCall.body === undefined, 'el corte no arrastra cuerpo alguno: la autoridad es la cabecera');
assertCondition(!closeCall.url.includes('sello'), 'el sello JAMÁS se escribe en la URL');

// Sello por invocación: sobreescribe el declarado al crear el cliente.
await sealedClient.closeCycle('sello-efímero');
assertCondition(
  stub.calls.at(-1).headers[CRON_SECRET_HEADER] === 'sello-efímero',
  'el sello puede pasarse en cada invocación y sobreescribe al del cliente'
);

// Sin sello alguno: la petición sale sin cabecera y el santuario falla cerrado.
const unsealedStub = createFetchStub([
  {
    path: '/api/v1/dominion/cron-cycle-close',
    status: 401,
    body: { success: false, error: { code: 'CRON_SECRET_REQUIRED', message: 'El corte dominical exige el sello del custodio en la cabecera X-Arcane-Cron-Secret.', recoveryAction: 'PROVIDE_CRON_SECRET' } },
  },
]);
const unsealedClient = createDominionClient({ fetch: unsealedStub });
const unsealedResult = await unsealedClient.closeCycle();
assertCondition(unsealedStub.calls.at(-1).headers[CRON_SECRET_HEADER] === undefined, 'sin sello declarado no se inventa cabecera alguna');
assertCondition(
  unsealedResult?.status === 401 && unsealedResult?.error?.code === DOMINION_ERROR_CODES.cronSecretRequired,
  'el santuario responde 401 CRON_SECRET_REQUIRED y el cliente lo propaga sin lanzar'
);
assertCondition(dominionLegendFor(unsealedResult).includes('sello'), 'la leyenda del sello ausente se sirve en noble castellano');

// Sello ajeno: 403 y fallo cerrado, sin delatar la configuración.
const foreignSealStub = createFetchStub([
  {
    path: '/api/v1/dominion/cron-cycle-close',
    status: 403,
    body: { success: false, error: { code: 'CRON_SECRET_INVALID', message: 'El sello presentado no autoriza esta invocación del corte dominical.', recoveryAction: 'REVIEW_CRON_SECRET' } },
  },
]);
const foreignSealClient = createDominionClient({ fetch: foreignSealStub, cronSecret: 'sello-falso' });
const foreignSealResult = await foreignSealClient.closeCycle();
assertCondition(
  foreignSealResult?.status === 403 && foreignSealResult?.error?.code === DOMINION_ERROR_CODES.cronSecretInvalid,
  'un sello ajeno llega como 403 CRON_SECRET_INVALID sin lanzar'
);
assertCondition(
  foreignSealResult?.error?.recoveryAction === 'REVIEW_CRON_SECRET',
  'la acción de recuperación del santuario sobrevive al cliente'
);

// =====================================================================
// [4b] Endpoint 14 · Gloria del Simulador (Tarea 7.1, RF-03.2)
// =====================================================================
console.log('\n[4b] Endpoint 14 · awardSimulatorCombo()');

const AWARD_PAYLOAD = {
  success: true,
  data: {
    clanId: 'cln_llama',
    comboElement: 'fire',
    dailyCap: 50,
    award: {
      actionType: 'simulatorCombo',
      basePoints: 10,
      awardedPoints: 13,
      hasSynergy: true,
      synergyBonus: 3,
      awardedAt: '2026-09-14T12:00:00Z',
      dailyQuotaRemaining: 37,
      reason: null,
    },
  },
};

const awardStub = createFetchStub([
  { path: '/api/v1/dominion/simulator-combo', method: 'POST', status: 200, body: AWARD_PAYLOAD },
]);
const awardClient = createDominionClient({ fetch: awardStub });
const awardResult = await awardClient.awardSimulatorCombo('  fire  ');
const awardCall = awardStub.calls.at(-1);
assertCondition(
  awardCall.method === 'POST' && awardCall.path === '/api/v1/dominion/simulator-combo',
  `la gloria del combo se invoca por POST en su ruta propia (${awardCall.method} ${awardCall.path})`
);
assertCondition(
  awardCall.body === JSON.stringify({ comboElement: 'fire' }),
  `el elemento viaja declarado y saneado en el cuerpo (${awardCall.body})`
);
assertCondition(
  String(awardCall.headers['Content-Type'] ?? '').startsWith('application/json'),
  'la orden del combo declara su cuerpo JSON'
);
assertCondition(awardCall.credentials === 'same-origin', 'la sesión del adepto acompaña sola: la autoridad es la cookie HttpOnly');
assertCondition(
  awardResult.success === true
    && awardResult.data?.clanId === 'cln_llama'
    && awardResult.data?.award?.awardedPoints === 13,
  'el recibo del santuario se propaga íntegro con la casa acreditada'
);
assertCondition(
  awardResult.data?.dailyCap === 50,
  'el sobre declara el techo diario canónico de 50 PDA (la vista jamás lo supone)'
);

// El techo colmado NO es un error: llega como 200 con cero gloria y su motivo.
const cappedStub = createFetchStub([
  {
    path: '/api/v1/dominion/simulator-combo',
    method: 'POST',
    status: 200,
    body: {
      success: true,
      data: {
        clanId: 'cln_llama',
        comboElement: 'fire',
        dailyCap: 50,
        award: { actionType: 'simulatorCombo', basePoints: 0, awardedPoints: 0, hasSynergy: false, synergyBonus: 0, awardedAt: '2026-09-14T20:00:00Z', dailyQuotaRemaining: 0, reason: 'DAILY_SIMULATOR_CAP_REACHED' },
      },
    },
  },
]);
const cappedResult = await createDominionClient({ fetch: cappedStub }).awardSimulatorCombo('fire');
assertCondition(
  cappedResult.success === true
    && cappedResult.data?.award?.awardedPoints === 0
    && cappedResult.data?.award?.reason === 'DAILY_SIMULATOR_CAP_REACHED',
  'el techo diario colmado llega como jornada agotada, jamás como error de la petición'
);

// Un mago sin hermandad: 409 con su código canónico, sin lanzar.
const homelessStub = createFetchStub([
  {
    path: '/api/v1/dominion/simulator-combo',
    method: 'POST',
    status: 409,
    body: { success: false, error: { code: 'NO_CLAN_AFFILIATION', message: 'El mago no milita en hermandad alguna: el Dominio solo se acredita bajo un estandarte.', recoveryAction: 'JOIN_OR_FOUND_CLAN' } },
  },
]);
const homelessResult = await createDominionClient({ fetch: homelessStub }).awardSimulatorCombo('fire');
assertCondition(
  homelessResult?.status === 409 && homelessResult?.error?.code === 'NO_CLAN_AFFILIATION',
  'sin hermandad el santuario responde 409 NO_CLAN_AFFILIATION y el cliente lo propaga'
);

await createDominionClient({ fetch: awardStub }).awardSimulatorCombo();
assertCondition(
  awardStub.calls.at(-1).body === JSON.stringify({ comboElement: '' }),
  'un combo sin afinidad declarada viaja con la cadena vacía, jamás con undefined'
);

// =====================================================================
// [5] Credencial Bearer opcional y aditiva
// =====================================================================
console.log('\n[5] Credenciales');
assertCondition(
  stub.calls.every((call) => call.headers.Authorization === undefined),
  'sin token inyectado no se inventa credencial Bearer alguna'
);
const bearerStub = createFetchStub([{ path: '/api/v1/dominion/leaderboard', status: 200, body: LEADERBOARD_PAYLOAD }]);
const bearerClient = createDominionClient({ fetch: bearerStub, token: 'firma-del-custodio' });
await bearerClient.fetchLeaderboard();
assertCondition(
  bearerStub.calls.at(-1).headers.Authorization === 'Bearer firma-del-custodio',
  'con token inyectado la petición porta Authorization: Bearer'
);
await createDominionClient({ fetch: bearerStub, token: '  ' }).fetchLeaderboard();
assertCondition(bearerStub.calls.at(-1).headers.Authorization === undefined, 'un token en blanco no se traduce en una cabecera vacía');

// =====================================================================
// [6] Nunca lanza: red caída, JSON ilegible y estados sin sobre
// =====================================================================
console.log('\n[6] Los fallos de corriente se convierten en sobres controlados');
const brokenClient = createDominionClient({ fetch: async () => { throw new TypeError('Failed to fetch'); } });
const brokenResult = await brokenClient.fetchLeaderboard();
assertCondition(brokenResult.success === false && brokenResult.status === 0, 'el corte de red se convierte en error controlado sin estado HTTP');
assertCondition(brokenResult.error?.code === DOMINION_ERROR_CODES.networkError, `el corte porta el código canónico (${brokenResult.error?.code})`);
assertCondition(dominionLegendFor(brokenResult).includes('interrumpido'), 'el corte de red se anuncia con leyenda ceremonial en castellano');

const garbageClient = createDominionClient({
  fetch: async () => ({ ok: true, status: 200, json: async () => { throw new SyntaxError('Unexpected token <'); } }),
});
const garbageResult = await garbageClient.fetchLineages();
assertCondition(garbageResult.success === false && garbageResult.error?.code === DOMINION_ERROR_CODES.networkError, 'un cuerpo ilegible no rompe el Salón');

const htmlErrorClient = createDominionClient({ fetch: async () => ({ ok: false, status: 503, json: async () => { throw new SyntaxError('nope'); } }) });
const htmlErrorResult = await htmlErrorClient.fetchLeaderboard();
assertCondition(htmlErrorResult.success === false && htmlErrorResult.status === 503, 'un 503 sin sobre JSON se traduce en error controlado con su estado');

// =====================================================================
// [7] Paridad de contratos con el router real (anti-deriva)
// =====================================================================
console.log('\n[7] Paridad de contratos con el Front Controller');
const routerSource = await readFile(new URL('../public/index.php', import.meta.url), 'utf8');
const registeredRoutes = [...routerSource.matchAll(/addRoute\('([A-Z]+)',\s*'([^']+)'/g)].map(([, method, pattern]) => ({ method, pattern }));
assertCondition(registeredRoutes.length > 0, `el Front Controller declara sus rutas (${registeredRoutes.length} encontradas)`);

/** Convierte un patrón del router en expresión regular, igual que él lo hace. */
function routePatternToRegExp(pattern) {
  const escaped = pattern.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
  return new RegExp(`^${escaped.replace(/\\\{[^}]+\\\}/g, '[^/]+')}$`);
}

const consulted = [
  ...stub.calls,
  ...unsealedStub.calls,
  ...foreignSealStub.calls,
  ...bearerStub.calls,
  ...awardStub.calls,
  ...cappedStub.calls,
  ...homelessStub.calls,
].map((call) => call.path);
const orphanCalls = consulted.filter((path) => !registeredRoutes.some((route) => routePatternToRegExp(route.pattern).test(path)));
assertCondition(
  orphanCalls.length === 0,
  `toda URL invocada por el cliente existe en el router${orphanCalls.length === 0 ? '' : ` — huérfanas: ${orphanCalls.join(', ')}`}`
);
assertCondition(
  registeredRoutes.some((route) => route.method === 'GET' && route.pattern === '/api/v1/lineages')
    && registeredRoutes.some((route) => route.method === 'GET' && route.pattern === '/api/v1/dominion/leaderboard')
    && registeredRoutes.some((route) => route.method === 'POST' && route.pattern === '/api/v1/dominion/cron-cycle-close'),
  'los tres endpoints del Salón están registrados con su método propio'
);
assertCondition(
  registeredRoutes.some((route) => route.method === 'POST' && route.pattern === '/api/v1/dominion/simulator-combo'),
  'la gloria del simulador (Endpoint 14, Tarea 7.1) está registrada con su método propio'
);
assertCondition(
  registeredRoutes.filter((route) => route.pattern.startsWith('/api/v1/dominion')).length === 3,
  'el Dominio expone tres rutas: la lectura pública, el corte sellado y la gloria del simulador'
);

// =====================================================================
// [8] Smoke E2E real contra el santuario local (omitible sin servidor)
// =====================================================================
console.log('\n[8] Smoke E2E real contra el santuario local');
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

const probe = await liveRequest('GET', '/api/v1/dominion/leaderboard');
if (!probe.ok) {
  console.log('  [OMITIDA] Santuario local no disponible: arranca `php -S 127.0.0.1:8095 scratch/demo_router.php` y repite para el smoke completo');
} else {
  const liveClient = createDominionClient({
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
        timeout: 4000,
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

  const liveLineages = await liveClient.fetchLineages();
  assertCondition(
    liveLineages.success === true && liveLineages.count === 8 && liveLineages.data?.length === 8,
    `GET /api/v1/lineages real: los ${liveLineages.data?.length ?? 0} linajes canónicos a través del cliente`
  );

  const liveHall = await liveClient.fetchLeaderboard();
  assertCondition(
    liveHall.success === true
      && Array.isArray(liveHall.data?.weeklyRanking)
      && Array.isArray(liveHall.data?.historicalRanking)
      && 'currentRegentClan' in (liveHall.data ?? {})
      && Array.isArray(liveHall.data?.hallOfFameWeeks),
    'GET /api/v1/dominion/leaderboard real: las cuatro secciones del Salón a través del cliente'
  );

  const liveUnsealed = await liveClient.closeCycle();
  assertCondition(
    liveUnsealed.status === 401 && liveUnsealed.error?.code === DOMINION_ERROR_CODES.cronSecretRequired,
    'POST cron-cycle-close sin sello real: 401 CRON_SECRET_REQUIRED (fallo cerrado) a través del cliente'
  );

  const liveForeignSeal = await liveClient.closeCycle('sello-de-un-intruso');
  assertCondition(
    liveForeignSeal.status === 403 && liveForeignSeal.error?.code === DOMINION_ERROR_CODES.cronSecretInvalid,
    'POST cron-cycle-close con sello ajeno real: 403 CRON_SECRET_INVALID a través del cliente'
  );
}

// =====================================================================
// Resumen
// =====================================================================
console.log(`\n== RESUMEN == Asertos superados: ${assertsPassed}, fallidos: ${assertsFailed}`);
if (assertsFailed === 0) {
  console.log('RESULTADO: EXITO — El cliente del Dominio contempla el Salón y sella el corte dominical (Tarea 5.1).');
  process.exit(0);
}
console.log('RESULTADO: DENEGADO — Revisa los asertos marcados.');
process.exit(1);
