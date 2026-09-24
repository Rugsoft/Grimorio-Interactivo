/**
 * test_grimoire_client.mjs — Arnés TDD de la Tarea 4.1 (TASKS-05).
 *
 * Estrategia TDD: este script se escribe ANTES que la implementación.
 * Verifica, sobre `public/assets/js/api/grimoireClient.js`:
 *   [1] API: createGrimoireClient({ fetch } inyectable) con fetchSpells y
 *       fetchSpellDetail.
 *   [2] fetchSpells(params): GET /api/v1/grimoire/spells con Accept JSON,
 *       credentials 'same-origin', query construida solo con parámetros
 *       definidos, y desempaquetado del sobre { success, data }.
 *   [3] fetchSpellDetail(id): GET /api/v1/grimoire/spells/{id} con la
 *       ficha litúrgica en data.
 *   [4] 401 en essays anónimas y 404 en detalle inexistente: sobre
 *       controlado { success: false, status, error } jamás lanzado.
 *   [5] Robustez: corte de red y JSON ilegible → error controlado
 *       (contrato del proyecto, AGENTS.md 6.1).
 *
 * Constitución:
 *   - Artículo I: fetch nativo, cero librerías HTTP.
 *   - Artículo V: funciones en inglés camelCase, comentarios castellanos.
 *
 * Uso: node scratch/test_grimoire_client.mjs
 */

let assertsPassed = 0;
let assertsFailed = 0;

function assertCondition(condition, description) {
  if (condition) {
    assertsPassed++;
    console.log(`  [PASA] ${description}`);
  } else {
    assertsFailed++;
    console.log(`  [FALLA] ${description}`);
  }
}

/** Respuesta falsa de fetch (mínima y suficiente). */
function createFakeResponse({ status = 200, payload = null, jsonOk = true } = {}) {
  return {
    status,
    ok: status >= 200 && status < 300,
    async json() {
      if (!jsonOk) throw new TypeError('Unexpected token < in JSON');
      return payload;
    },
  };
}

console.log('== ARNÉS TDD — Tarea 4.1: Cliente HTTP del Grimorio ==');

let module;
try {
  module = await import('../public/assets/js/api/grimoireClient.js');
} catch (error) {
  console.log(`  FATAL — no se pudo importar grimoireClient.js: ${error.message}`);
  console.log('== RESUMEN == Fase roja: la implementación aún no existe.');
  process.exit(1);
}

const { createGrimoireClient } = module;

const PAGED_PAYLOAD = {
  success: true,
  data: {
    totalSpells: 2,
    currentPage: 1,
    totalPages: 1,
    hasPrevious: false,
    hasNext: false,
    spells: [
      { id: 'spl_1', name: 'Esfera de Llamas Purificadoras', circle: 2, status: 'validated' },
      { id: 'spl_2', name: 'Marea de Escarcha', circle: 3, status: 'validated' },
    ],
  },
};

const DETAIL_PAYLOAD = {
  success: true,
  data: {
    id: 'spl_1',
    name: 'Esfera de Llamas Purificadoras',
    incantationFormula: '¡Llamas del alba, descended y consumid la penumbra!',
    effects: { damage: 30, healing: 0, barrier: 0, crowdControlType: 'none' },
  },
};

console.log('[1] API de la factoría');

{
  const client = createGrimoireClient({ fetch: async () => createFakeResponse({ payload: PAGED_PAYLOAD }) });
  assertCondition(typeof createGrimoireClient === 'function', 'exporta createGrimoireClient(options)');
  assertCondition(typeof client.fetchSpells === 'function', 'expone fetchSpells(params)');
  assertCondition(typeof client.fetchSpellDetail === 'function', 'expone fetchSpellDetail(id)');
}

console.log('[2] fetchSpells — petición y desempaquetado (RF-01.2)');

{
  const calls = [];
  const fakeFetch = async (url, init) => {
    calls.push({ url, init });
    return createFakeResponse({ payload: PAGED_PAYLOAD });
  };
  const client = createGrimoireClient({ fetch: fakeFetch });

  const result = await client.fetchSpells({ circle: 2, element: 'fire', mode: 'canonical', page: 1, limit: 10 });

  assertCondition(result.success === true, 'sobre exitoso desempaquetado');
  assertCondition(result.data.totalSpells === 2 && Array.isArray(result.data.spells), 'data porta el sobre de paginación íntegro');
  assertCondition(result.data.spells[0].incantationFormula !== undefined || result.data.spells[0].name !== undefined, 'fichas litúrgicas presentes');

  const call = calls[0];
  assertCondition(call.url.startsWith('/api/v1/grimoire/spells'), `URL canónica del Endpoint 1 — ${call.url}`);
  assertCondition(call.url.includes('circle=2') && call.url.includes('element=fire'), 'query con circle y element');
  assertCondition(call.url.includes('mode=canonical') && call.url.includes('page=1') && call.url.includes('limit=10'), 'query con mode, page y limit');
  assertCondition(call.init.headers.Accept === 'application/json', 'cabecera Accept JSON');
  assertCondition(call.init.credentials === 'same-origin', 'credentials same-origin (cookies HttpOnly)');
}

console.log('[3] fetchSpells sin parámetros y omisión de indefinidos');

{
  const calls = [];
  const fakeFetch = async (url, init) => {
    calls.push({ url, init });
    return createFakeResponse({ payload: PAGED_PAYLOAD });
  };
  const client = createGrimoireClient({ fetch: fakeFetch });

  await client.fetchSpells();
  assertCondition(calls[0].url === '/api/v1/grimoire/spells' || calls[0].url === '/api/v1/grimoire/spells?', 'sin parámetros: URL limpia sin query');

  await client.fetchSpells({ circle: undefined, element: null, page: 3 });
  assertCondition(calls[1].url.includes('page=3'), 'los parámetros definidos viajan');
  assertCondition(!calls[1].url.includes('circle') && !calls[1].url.includes('element'), 'los parámetros undefined/null se omiten (sin circle=undefined)');
}

console.log('[4] fetchSpellDetail — Endpoint 2 (RF-01.5)');

{
  const calls = [];
  const fakeFetch = async (url, init) => {
    calls.push({ url, init });
    return createFakeResponse({ payload: DETAIL_PAYLOAD });
  };
  const client = createGrimoireClient({ fetch: fakeFetch });

  const result = await client.fetchSpellDetail('spl_1');
  assertCondition(result.success === true && result.data.id === 'spl_1', 'ficha litúrgica individual desempaquetada');
  assertCondition(calls[0].url === '/api/v1/grimoire/spells/spl_1', `URL con el id — ${calls[0].url}`);
  assertCondition(calls[0].init.credentials === 'same-origin', 'credentials en el detalle también');
}

console.log('[5] Errores limpios: 401 essays anónimas y 404 detalle (RF-01.2)');

{
  // 401: mode=essays sin sesión.
  const client401 = createGrimoireClient({
    fetch: async () => createFakeResponse({
      status: 401,
      payload: { success: false, error: { code: 'UNAUTHENTICATED', message: 'Sin vínculo activo.' } },
    }),
  });
  const essays = await client401.fetchSpells({ mode: 'essays' });
  assertCondition(essays.success === false && essays.status === 401, '401 anónimo en essays: sobre controlado');
  assertCondition(essays.error.code === 'UNAUTHENTICATED', 'código de error del backend preservado');

  // 404: detalle inexistente o de otro autor.
  const client404 = createGrimoireClient({
    fetch: async () => createFakeResponse({
      status: 404,
      payload: { success: false, error: { code: 'SPELL_NOT_FOUND', message: 'Ese conjuro no está en el tomo.' } },
    }),
  });
  const missing = await client404.fetchSpellDetail('spl_fantasma');
  assertCondition(missing.success === false && missing.status === 404, '404 en detalle: sobre controlado');
  assertCondition(missing.error.code === 'SPELL_NOT_FOUND', 'código SPELL_NOT_FOUND preservado');
}

console.log('[6] Robustez: corte de red y JSON ilegible');

{
  // Corte de red: fetch lanza → sobre controlado, jamás excepción al vistas.
  const clientDown = createGrimoireClient({
    fetch: async () => { throw new TypeError('Failed to fetch'); },
  });
  let threw = false;
  let result = null;
  try { result = await clientDown.fetchSpells({}); } catch { threw = true; }
  assertCondition(!threw && result.success === false, 'corte de red: sobre controlado sin lanzar');

  // JSON ilegible en 2xx: error controlado de corriente.
  const clientGarbage = createGrimoireClient({
    fetch: async () => createFakeResponse({ status: 200, jsonOk: false }),
  });
  const garbage = await clientGarbage.fetchSpells({});
  assertCondition(garbage.success === false && typeof garbage.error.code === 'string', 'JSON ilegible: error controlado con código');
}

console.log('== RESUMEN ==');
console.log(`Asertos superados: ${assertsPassed}, fallidos: ${assertsFailed}`);
if (assertsFailed === 0) {
  console.log('RESULTADO: EXITO — grimoireClient listo para el Tomo Arcano (Tarea 4.1).');
  process.exit(0);
} else {
  console.log('RESULTADO: DENEGADO — hay asertos incumplidos.');
  process.exit(1);
}
