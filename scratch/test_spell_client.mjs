/**
 * test_spell_client.mjs — Verificación del cliente HTTP nativo (Tarea 3.3).
 *
 * Estrategia TDD: este script se escribe ANTES que spellClient.js.
 * Valida contra el criterio "Hecho cuando" de specs/01-portal-and-navigation.tasks.md:
 *   Una invocación fallida por corte de red retorna un objeto de error
 *   controlado { success: false, error: { code: 'MANA_STREAM_INTERRUPTED' } }
 *   sin provocar excepciones no capturadas en consola.
 *
 * Dos niveles:
 *   A) Mock de fetch global: corte de red, 404, 400, 500, JSON corrupto.
 *   B) E2E real contra la API (php -S + base efímera, patrón de las Tareas 1.6/2.x).
 *
 * Uso: node scratch/test_spell_client.mjs [ --e2e ]
 *   Con --e2e ejecuta también la fase B (arranca php -S por su cuenta).
 */

import {
  fetchFeatured,
  fetchSpells,
  fetchSpellBySlug,
  fetchClansPreview,
  API_ERROR_CODES,
} from '../public/assets/js/api/spellClient.js';

let assertsPassed = 0;
let assertsFailed = 0;
let uncaughtErrors = 0;

// Centinela: el criterio exige que NINGUNA invocación falle sin control.
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

/** Guarda el fetch real y monta un mock que retorna la respuesta dada. */
function installFetchMock(mockImplementation) {
  const originalFetch = globalThis.fetch;
  globalThis.fetch = mockImplementation;
  return function restore() {
    globalThis.fetch = originalFetch;
  };
}

console.log('== VERIFICACION TAREA 3.3: spellClient.js ==\n');

// --- FASE A: Contratos con mock de fetch ---
console.log('FASE A: Contratos con fetch simulado');

// A.1: Éxito — el cliente desenvuelve el sobre { success, data }.
{
  const restoreFetch = installFetchMock(async () => new Response(
    JSON.stringify({ success: true, data: [{ slug: 'chispa-de-ignicion' }] }),
    { status: 200, headers: { 'Content-Type': 'application/json' } }
  ));

  const featuredResult = await fetchFeatured();
  assertCondition(featuredResult.success === true, 'fetchFeatured() retorna success: true en 200');
  assertCondition(featuredResult.data?.length === 1, 'Los datos llegan desenvueltos del sobre JSON');
  restoreFetch();
}

// A.2: CRITERIO — corte de red retorna error CONTROLADO, sin excepción.
{
  const restoreFetch = installFetchMock(async () => {
    throw new TypeError('fetch failed: red cortada');
  });

  let networkFailureResult = null;
  let threwUncontrolled = false;
  try {
    networkFailureResult = await fetchFeatured();
  } catch {
    threwUncontrolled = true;
  }

  assertCondition(threwUncontrolled === false, 'El corte de red NO lanza excepción al llamador');
  assertCondition(
    networkFailureResult?.success === false,
    'El corte de red retorna success: false'
  );
  assertCondition(
    networkFailureResult?.error?.code === 'MANA_STREAM_INTERRUPTED',
    "El código del error es 'MANA_STREAM_INTERRUPTED' (criterio literal)"
  );
  restoreFetch();
}

// A.3: 404 místico — fetchSpellBySlug propaga el contrato del backend.
{
  const restoreFetch = installFetchMock(async () => new Response(
    JSON.stringify({
      success: false,
      error: { code: 'SCROLL_LOST_IN_AETHER', message: 'El pergamino que buscas se ha desvanecido en el éter.', recoveryAction: 'RETURN_TO_LIBRARY' },
    }),
    { status: 404, headers: { 'Content-Type': 'application/json' } }
  ));

  const lostResult = await fetchSpellBySlug('pergamino-inexistente');
  assertCondition(lostResult.success === false, 'El 404 del backend no lanza excepción (propagación pacífica)');
  assertCondition(
    lostResult.error?.code === 'SCROLL_LOST_IN_AETHER',
    "El 404 propaga 'SCROLL_LOST_IN_AETHER' intacto (RF-06.2)"
  );
  assertCondition(
    lostResult.error?.recoveryAction === 'RETURN_TO_LIBRARY',
    'La acción de rescate viaja al frontend'
  );
  restoreFetch();
}

// A.4: 400 y 500 — errores de parámetros y del servidor también controlados.
{
  const restoreFetch = installFetchMock(async () => new Response(
    JSON.stringify({ success: false, error: { code: 'INVALID_MAX_MANA' } }),
    { status: 400, headers: { 'Content-Type': 'application/json' } }
  ));

  const badRequestResult = await fetchSpells({ maxMana: 'mucho' });
  assertCondition(
    badRequestResult.success === false && badRequestResult.error?.code === 'INVALID_MAX_MANA',
    'El 400 se propaga como error controlado (sin excepción)'
  );
  restoreFetch();
}
{
  const restoreFetch = installFetchMock(async () => new Response(
    JSON.stringify({ success: false, error: { code: 'MANA_STREAM_INTERRUPTED' } }),
    { status: 500, headers: { 'Content-Type': 'application/json' } }
  ));

  const serverErrorResult = await fetchClansPreview();
  assertCondition(
    serverErrorResult.success === false && serverErrorResult.error?.code === 'MANA_STREAM_INTERRUPTED',
    'El 500 se propaga como error controlado'
  );
  restoreFetch();
}

// A.5: JSON corrupto — respuesta 200 con cuerpo inválido no revienta.
{
  const restoreFetch = installFetchMock(async () => new Response(
    '<html>esto no es json</html>',
    { status: 200, headers: { 'Content-Type': 'text/html' } }
  ));

  let corruptResult = null;
  let threwUncontrolled = false;
  try {
    corruptResult = await fetchFeatured();
  } catch {
    threwUncontrolled = true;
  }
  assertCondition(threwUncontrolled === false, 'JSON corrupto NO lanza excepción al llamador');
  assertCondition(
    corruptResult?.success === false && typeof corruptResult?.error?.code === 'string',
    'JSON corrupto retorna error controlado con código'
  );
  restoreFetch();
}

// A.6: Parámetros de catálogo — la query string respeta el contrato del plan 3.
{
  let capturedUrl = '';
  const restoreFetch = installFetchMock(async (requestedUrl) => {
    capturedUrl = String(requestedUrl);
    return new Response(JSON.stringify({ success: true, data: { items: [], hasMore: false } }), { status: 200 });
  });

  await fetchSpells({
    query: 'ignicion',
    schools: ['evocation', 'abjuration'],
    maxMana: 30,
    includeExperimental: true,
    offset: 50,
    limit: 50,
  });

  assertCondition(capturedUrl.includes('/api/v1/spells'), 'fetchSpells apunta a /api/v1/spells');
  assertCondition(capturedUrl.includes('query=ignicion'), 'La query viaja en el query string');
  assertCondition(capturedUrl.includes('schools=evocation%2Cabjuration'), 'Las escuelas viajan como CSV (plan 3)');
  assertCondition(capturedUrl.includes('maxMana=30'), 'El tope de maná viaja como entero');
  assertCondition(capturedUrl.includes('includeExperimental=1'), 'La bandera experimental viaja como 1/0');
  assertCondition(capturedUrl.includes('offset=50') && capturedUrl.includes('limit=50'), 'La paginación viaja en offset/limit');
  restoreFetch();
}

// A.7: Códigos exportados para las vistas de rescate.
assertCondition(
  API_ERROR_CODES?.networkError === 'MANA_STREAM_INTERRUPTED',
  'API_ERROR_CODES expone networkError para errorView (RF-06.3)'
);

// --- FASE B: Corte de red REAL (sin servidor; el E2E completo vive en test_endpoints.php) ---
if (process.argv.includes('--e2e')) {
  console.log('\nFASE B: Corte de red real (puerto muerto)');

  // fetch real, sin mock, contra el puerto discard (9): nunca hay servidor.
  const deadPortResult = await fetchFeatured('http://127.0.0.1:9/');
  assertCondition(
    deadPortResult?.success === false && deadPortResult?.error?.code === 'MANA_STREAM_INTERRUPTED',
    'E2E: un servidor muerto produce el error controlado del criterio'
  );
}

// --- Centinela final: cero excepciones no capturadas en toda la ejecución ---
console.log('\n== CENTINELA DE CONSOLA ==');
assertCondition(uncaughtErrors === 0, `Excepciones no capturadas en consola: ${uncaughtErrors} (el criterio exige 0)`);

// --- Resumen final ---
console.log('\n== RESUMEN ==');
console.log(`Asertos superados: ${assertsPassed}`);
console.log(`Asertos fallidos:  ${assertsFailed}`);

if (assertsFailed === 0) {
  console.log("\nRESULTADO: EXITO — La Tarea 3.3 cumple su criterio 'Hecho cuando'.");
  process.exit(0);
}

console.log('\nRESULTADO: DENEGADO — Corregir los asertos marcados con [FALLA].');
process.exit(1);
