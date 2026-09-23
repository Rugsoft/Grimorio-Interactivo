/**
 * test_grimoire_collection_client.mjs — Arnés de la Tarea 4.2 (TASKS-11).
 *
 * Valida EL CLIENTE FETCH NATIVO DEL TOMO (`grimoireCollectionClient.js`)
 * contra el «Hecho cuando» de la tarea:
 *
 *   1. El doble de fetch recibe cada código/razón del contrato (plan
 *      §2.2) y el cliente lo traduce al veredicto canónico SIN lanzar
 *      excepciones en los estados solemnes (200 recibo denegado, 200
 *      idempotente, 403 retención del peregrino, 409 de conflicto).
 *   2. Los cuatro endpoints viajan con método y ruta exactos, cuerpo
 *      JSON en camelCase y `credentials: 'same-origin'` (RF-05.1).
 *   3. Las razones del elogio (AWARDED / ALREADY_PRAISED /
 *      OWN_CLAN_FAVORITE) llegan íntegras al veredicto (RF-04.3/04.4).
 *   4. Las leyendas del Anexo A (RATIFICADO) cubren todos los códigos
 *      canónicos y `ceremonialLegendFor` jamás devuelve cadena vacía.
 *   5. Fallos hostiles (cuerpo no-JSON, red cortada) degradan a error
 *      controlado sin traza técnica (RNF-05).
 *
 * Constitución:
 *   - Artículo I (Dogma Vanilla): ES Modules nativos, doble de fetch
 *     sin librerías.
 *   - Artículo IV/V: leyendas en castellano; asertos en inglés.
 *
 * Uso: node scratch/test_grimoire_collection_client.mjs
 * Salida: código 0 si todos los asertos pasan; código 1 en caso contrario.
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

const {
  createGrimoireCollectionClient,
  TOME_ERROR_CODES,
  TOME_CEREMONIAL_LEGENDS,
  PRAISE_REASONS,
  PRAISE_LEGENDS,
  ceremonialLegendFor,
} = await import('../public/assets/js/api/grimoireCollectionClient.js');

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
        if (route.reject) {
          throw new Error(route.reject);
        }
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

// Sobres canónicos del contrato (plan §2.2).
const SOBRE_TOMO = {
  success: true,
  data: {
    entries: [
      {
        spell: { id: 'spl_1', name: 'Llama Eterna', status: 'validated' },
        addedAt: '2026-09-22T10:00:00Z',
        tomeMark: 'living',
        praiseStatus: { praised: true, allowed: false },
      },
    ],
    total: 137, page: 1, limit: 50, totalPages: 3,
  },
};
const ECO_SELLADO = { success: true, data: { alreadyCollected: false, addedAt: '2026-09-22T10:00:00Z' } };
const ECO_IDEMPOTENTE = { success: true, data: { alreadyCollected: true, addedAt: '2026-09-22T09:00:00Z' } };
const ECO_RETIRADA = { success: true, data: { removed: true, total: 136 } };
const GLORIA_NUEVA = { success: true, data: { praised: true, reason: 'AWARDED', awarded: { points: 6, hasSynergy: true } } };
const ECO_YA_ELOGIADO = { success: true, data: { praised: true, reason: 'ALREADY_PRAISED' } };
const RECIBO_DENEGADO = { success: true, data: { praised: false, reason: 'OWN_CLAN_FAVORITE' } };

// =====================================================================
// [1] Superficie del módulo (Dogma Vanilla: solo exportaciones propias)
// =====================================================================
console.log('[1] Superficie del módulo');
assertCondition(typeof createGrimoireCollectionClient === 'function', 'el módulo exporta la fábrica createGrimoireCollectionClient');
assertCondition(typeof ceremonialLegendFor === 'function', 'el módulo exporta el traductor de leyendas ceremoniales');
assertCondition(
  TOME_ERROR_CODES.lineageOathRequired === 'LINEAGE_OATH_REQUIRED'
    && TOME_ERROR_CODES.tomeSealVeto === 'TOME_SEAL_VETO'
    && TOME_ERROR_CODES.spellNotFound === 'SPELL_NOT_FOUND'
    && TOME_ERROR_CODES.spellNotInTome === 'SPELL_NOT_IN_TOME'
    && TOME_ERROR_CODES.praiseSpellNotValidated === 'PRAISE_SPELL_NOT_VALIDATED'
    && TOME_ERROR_CODES.unauthenticated === 'UNAUTHENTICATED',
  'los seis códigos solemnes del contrato viajan en el mapa de errores'
);
assertCondition(
  PRAISE_REASONS.awarded === 'AWARDED'
    && PRAISE_REASONS.alreadyPraised === 'ALREADY_PRAISED'
    && PRAISE_REASONS.ownClanFavorite === 'OWN_CLAN_FAVORITE',
  'las tres razones canónicas del recibo del Elogio viajan en su mapa'
);

// =====================================================================
// [2] Endpoint 1 · fetchCollection() — la lectura paginada del tomo
// =====================================================================
console.log('\n[2] Endpoint 1 · fetchCollection()');
const stubTomo = createFetchStub([
  { path: '/api/v1/grimoire/collection', method: 'GET', status: 200, body: SOBRE_TOMO },
]);
const clientTomo = createGrimoireCollectionClient({ fetch: stubTomo });
const tomo = await clientTomo.fetchCollection();
assertCondition(tomo.success === true && tomo.status === 200, 'el sobre feliz responde success con estado 200');
assertCondition(tomo.data?.total === 137 && tomo.data?.totalPages === 3, 'el sobre porta los metadatos de paginación del contrato');
assertCondition(tomo.data?.entries?.[0]?.tomeMark === 'living', 'la entrada porta su marca solemne intacta (el cliente no la recalcula)');
assertCondition(stubTomo.calls[0].method === 'GET', 'la lectura viaja por GET');
assertCondition(stubTomo.calls[0].credentials === 'same-origin', 'la cookie de sesión viaja sola (credentials same-origin)');
assertCondition(stubTomo.calls[0].url.endsWith('page=1'), 'sin filtro la hoja viaja con page=1');

await clientTomo.fetchCollection('water', 2);
assertCondition(
  stubTomo.calls[1].url.includes('element=water') && stubTomo.calls[1].url.includes('page=2'),
  'el filtro de afinidad y la hoja viajan en la query (RF-02.3, caso límite 4)',
);

// =====================================================================
// [3] Endpoint 2 · collectSpell() — 201 nuevo y 200 idempotente
// =====================================================================
console.log('\n[3] Endpoint 2 · collectSpell()');
const stubSellado = createFetchStub([
  { match: '/api/v1/grimoire/collection', method: 'POST', status: 201, body: ECO_SELLADO },
]);
const clientSellado = createGrimoireCollectionClient({ fetch: stubSellado });
const sellado = await clientSellado.collectSpell('spl_1');
assertCondition(sellado.success === true && sellado.status === 201, 'el sellado nuevo responde 201 sin lanzar');
assertCondition(sellado.data?.alreadyCollected === false && typeof sellado.data?.addedAt === 'string', 'el eco porta alreadyCollected false y addedAt');
assertCondition(stubSellado.calls[0].method === 'POST' && stubSellado.calls[0].body === '{"spellId":"spl_1"}', 'el sellado viaja por POST con cuerpo { spellId } en camelCase');
assertCondition(stubSellado.calls[0].credentials === 'same-origin', 'la cookie viaja también en el sellado');

const stubEco = createFetchStub([
  { match: '/api/v1/grimoire/collection', method: 'POST', status: 200, body: ECO_IDEMPOTENTE },
]);
const clientEco = createGrimoireCollectionClient({ fetch: stubEco });
const eco = await clientEco.collectSpell('spl_1');
assertCondition(eco.success === true && eco.status === 200 && eco.data?.alreadyCollected === true, 'el re-sellado responde 200 idempotente sin lanzar (RF-01.3)');

// =====================================================================
// [4] Endpoint 3 · discardSpell() — 200 con total y 409 solemne
// =====================================================================
console.log('\n[4] Endpoint 3 · discardSpell()');
const stubRetirada = createFetchStub([
  { path: '/api/v1/grimoire/collection/spl_1', method: 'DELETE', status: 200, body: ECO_RETIRADA },
]);
const clientRetirada = createGrimoireCollectionClient({ fetch: stubRetirada });
const retirada = await clientRetirada.discardSpell('spl_1');
assertCondition(retirada.success === true && retirada.status === 200, 'la retirada consumada responde 200 sin lanzar');
assertCondition(retirada.data?.removed === true && retirada.data?.total === 136, 'el eco porta removed true y el total ACTUALIZADO (RF-02.4)');
assertCondition(stubRetirada.calls[0].method === 'DELETE', 'la retirada viaja por DELETE');
assertCondition(!stubRetirada.calls[0].url.includes('element='), 'sin filtro la retirada no envía element');

await clientRetirada.discardSpell('spl_1', 'water');
assertCondition(
  stubRetirada.calls[1].url.includes('element=water'),
  'el filtro vigente viaja en la query para que el total describa el conjunto exhibido',
);

const stubConflicto = createFetchStub([
  {
    match: '/api/v1/grimoire/collection/',
    method: 'DELETE',
    status: 409,
    body: { success: false, error: { code: 'SPELL_NOT_IN_TOME', message: TOME_CEREMONIAL_LEGENDS[TOME_ERROR_CODES.spellNotInTome], recoveryAction: 'RETURN_TO_TOME' } },
  },
]);
const clientConflicto = createGrimoireCollectionClient({ fetch: stubConflicto });
const conflicto = await clientConflicto.discardSpell('spl_fantasma');
assertCondition(
  conflicto.success === false && conflicto.status === 409
    && conflicto.error?.code === 'SPELL_NOT_IN_TOME'
    && conflicto.error?.message === 'Solo se retira lo que se selló: ese hechizo no habita tu tomo.',
  'el 409 SPELL_NOT_IN_TOME se propaga íntegro con su leyenda solemne, sin lanzar',
);

// =====================================================================
// [5] Endpoint 4 · praiseSpell() — los tres estados, jamás excepciones
// =====================================================================
console.log('\n[5] Endpoint 4 · praiseSpell()');
const stubElogios = createFetchStub([
  { match: '/api/v1/grimoire/praise', method: 'POST', status: 200, body: GLORIA_NUEVA, times: 0 },
]);
const clientElogios = createGrimoireCollectionClient({ fetch: stubElogios });
const gloria = await clientElogios.praiseSpell('spl_1');
assertCondition(gloria.success === true && gloria.status === 200, 'la gloria nueva responde 200 sin lanzar');
assertCondition(gloria.data?.reason === 'AWARDED' && gloria.data?.awarded?.points === 6, 'el veredicto AWARDED porta el recibo de gloria íntegro (RF-04.2)');

const colaElogios = createFetchStub([
  { match: (call) => call.path === '/api/v1/grimoire/praise', method: 'POST', status: 200, body: ECO_YA_ELOGIADO },
]);
const clientCola = createGrimoireCollectionClient({ fetch: colaElogios });
const ecoElogio = await clientCola.praiseSpell('spl_1');
assertCondition(ecoElogio.data?.reason === 'ALREADY_PRAISED' && ecoElogio.data?.praised === true, 'el eco ALREADY_PRAISED llega como veredicto, jamás excepción (RF-04.3)');

const stubDenegado = createFetchStub([
  { match: '/api/v1/grimoire/praise', method: 'POST', status: 200, body: RECIBO_DENEGADO },
]);
const clientDenegado = createGrimoireCollectionClient({ fetch: stubDenegado });
const denegado = await clientDenegado.praiseSpell('spl_1');
assertCondition(denegado.data?.reason === 'OWN_CLAN_FAVORITE' && denegado.data?.praised === false, 'el recibo denegado OWN_CLAN_FAVORITE llega como estado 200, jamás error (RF-04.4)');

const stubForzado = createFetchStub([
  {
    match: '/api/v1/grimoire/praise',
    method: 'POST',
    status: 409,
    body: { success: false, error: { code: 'PRAISE_SPELL_NOT_VALIDATED', message: TOME_CEREMONIAL_LEGENDS[TOME_ERROR_CODES.praiseSpellNotValidated] } },
  },
]);
const clientForzado = createGrimoireCollectionClient({ fetch: stubForzado });
const forzado = await clientForzado.praiseSpell('spl_experimental');
assertCondition(
  forzado.success === false && forzado.status === 409
    && forzado.error?.code === 'PRAISE_SPELL_NOT_VALIDATED'
    && forzado.error?.message === 'La gloria solo nace de obra sellada por el Tribunal.',
  'el 409 del forzado porta la leyenda literal del Anexo A, sin lanzar (RF-04.5)',
);

// =====================================================================
// [6] Los estados solemnes heredados: 403 juramento y 401 vínculo
// =====================================================================
console.log('\n[6] Estados solemnes heredados (SPEC-09 retención, SPEC-03 vínculo)');
const stubSolemnes = createFetchStub([
  {
    match: (call) => call.method === 'POST' && call.path === '/api/v1/grimoire/collection',
    status: 403,
    body: { success: false, error: { code: 'LINEAGE_OATH_REQUIRED', message: TOME_CEREMONIAL_LEGENDS[TOME_ERROR_CODES.lineageOathRequired] } },
  },
  { path: '/api/v1/grimoire/collection', method: 'GET', status: 401, body: { success: false, error: { code: 'UNAUTHENTICATED' } } },
  { match: '/api/v1/grimoire/praise', method: 'POST', status: 404, body: { success: false, error: { code: 'SPELL_NOT_FOUND', message: TOME_CEREMONIAL_LEGENDS[TOME_ERROR_CODES.spellNotFound] } } },
]);
const clientSolemnes = createGrimoireCollectionClient({ fetch: stubSolemnes });
const peregrino = await clientSolemnes.collectSpell('spl_1');
const muerto = await clientSolemnes.fetchCollection();
const fantasma = await clientSolemnes.praiseSpell('spl_fantasma');
assertCondition(
  peregrino.success === false && peregrino.status === 403
    && peregrino.error?.code === 'LINEAGE_OATH_REQUIRED'
    && peregrino.error?.message === 'El santuario aguarda tu juramento: nadie pisa sus salas sin linaje jurado.',
  'el 403 del peregrino se propaga íntegro con la voz de SPEC-09, sin lanzar (hallazgo 16)',
);
assertCondition(
  muerto.success === false && muerto.status === 401 && muerto.error?.code === 'UNAUTHENTICATED',
  'el 401 de la sesión muerta llega como veredicto para la degradación solemne (RF-05.2)',
);
assertCondition(fantasma.error?.code === 'SPELL_NOT_FOUND' || fantasma.error?.code === 'PRAISE_SPELL_NOT_VALIDATED', 'el fantasma responde con veredicto controlado, jamás excepción cruda');

// =====================================================================
// [7] Leyendas ceremoniales: cobertura del Anexo A y jamás cadena vacía
// =====================================================================
console.log('\n[7] Leyendas ceremoniales del Anexo A (RATIFICADO)');
for (const code of Object.values(TOME_ERROR_CODES)) {
  const legend = TOME_CEREMONIAL_LEGENDS[code];
  assertCondition(typeof legend === 'string' && legend.trim() !== '', `el código ${code} porta leyenda ceremonial en castellano`);
}
assertCondition(ceremonialLegendFor({ error: { code: 'UNKNOWN', message: 'La leyenda del santuario manda.' } }) === 'La leyenda del santuario manda.', 'el mensaje del santuario tiene precedencia sobre el mapa local');
assertCondition(ceremonialLegendFor({ error: { code: 'UNKNOWN_CODE' } }) !== '', 'un código desconocido jamás produce cadena vacía');
assertCondition(ceremonialLegendFor(TOME_ERROR_CODES.tomeSealVeto) === TOME_CEREMONIAL_LEGENDS[TOME_ERROR_CODES.tomeSealVeto], 'el traductor acepta el código directo como argumento');
assertCondition(
  PRAISE_LEGENDS[PRAISE_REASONS.ownClanFavorite] === 'Un adepto de la casa no granjea gloria para su propio estandarte.',
  'la leyenda de militancia es la canónica del plan §4.3 (RF-04.4)',
);

// =====================================================================
// [8] Fallos hostiles: cuerpo no-JSON y red cortada, degradación controlada
// =====================================================================
console.log('\n[8] Fallos hostiles (RNF-05: cero trazas técnicas al mago)');
const stubHostil = createFetchStub([
  { match: '/api/v1/grimoire/collection', method: 'GET', status: 502, body: '<html>Bad Gateway</html>' },
]);
// Caso 1: proxy con HTML.
const clientHostil = createGrimoireCollectionClient({ fetch: stubHostil });
const html = await clientHostil.fetchCollection();
assertCondition(
  html.success === false && html.status === 502 && html.error?.code === 'MANA_STREAM_INTERRUPTED',
  'un 502 con cuerpo HTML degrada a error controlado sin exponer el cuerpo técnico',
);
assertCondition(html.error?.message !== '<html>Bad Gateway</html>', 'el cuerpo hostil jamás llega al mago');

// Caso 2: red cortada (fetch lanza).
const stubRedCorta = async () => { throw new TypeError('Failed to fetch'); };
const clientRedCorta = createGrimoireCollectionClient({ fetch: stubRedCorta });
const redCorta = await clientRedCorta.collectSpell('spl_1');
assertCondition(
  redCorta.success === false && redCorta.status === 0
    && redCorta.error?.code === 'MANA_STREAM_INTERRUPTED'
    && redCorta.error?.message === 'La corriente de maná hacia tu tomo se ha interrumpido.',
  'el corte de red degrada a objeto controlado con su leyenda, jamás excepción al llamador',
);

// =====================================================================
// [9] El cliente jamás decide el canon (Artículo II): sin mapa de estados
// =====================================================================
console.log('\n[9] Artículo II: el cliente porta, jamás juzga');
assertCondition(
  PRAISE_REASONS && TOME_ERROR_CODES && typeof createGrimoireCollectionClient === 'function',
  'la superficie exporta SOLO códigos, leyendas y fábrica: cero lógica de canon',
);

console.log(`\n=== RESULTADO: ${assertsPassed} pasan, ${assertsFailed} fallan ===`);
process.exit(assertsFailed === 0 ? 0 : 1);
