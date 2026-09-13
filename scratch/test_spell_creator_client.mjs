/**
 * test_spell_creator_client.mjs — Arnés TDD de la Tarea 5.2 (TASKS-04).
 *
 * Verifica el cliente HTTP nativo del Taller de Hechizos
 * (public/assets/js/api/spellCreatorClient.js, ES Module):
 *   - Funciones exportadas: calculateSpell, saveDraft, listDrafts,
 *     updateDraft, deleteDraft, publishSpell, updateExperimental,
 *     createVariant.
 *   - fetch nativo con credentials: 'same-origin' en TODAS las peticiones
 *     (cookies de sesión automáticas) y Content-Type JSON en las que
 *     portan cuerpo.
 *   - Verbos y rutas exactas de la API: POST /calculate, POST/GET
 *     /drafts, PUT/DELETE /drafts/{id}, POST /publish/{id},
 *     PUT /experimental/{id}, POST /variant/{id}.
 *   - Errores canónicos manejados de forma HOMOGÉNEA: sobre controlado
 *     { success, status, data | error }, jamás excepciones hacia la vista
 *     (400 ARCANE_OVERLOAD / INVALID_SPELL_INPUT, 401 UNAUTHENTICATED,
 *     403 SPELL_IMMUTABLE / DRAFT_QUOTA_EXCEEDED, 404 SPELL_NOT_FOUND,
 *     0 MANA_STREAM_INTERRUPTED ante corte de red).
 *
 * Criterio «Hecho cuando» (Tarea 5.2): todas las funciones cliente
 * encapsulan las llamadas HTTP, deserializan JSON de respuesta y manejan
 * de forma homogénea los errores canónicos del servidor.
 *
 * Constitución:
 *   - Artículo I (Dogma Vanilla): fetch nativo, sin librerías HTTP.
 *   - Artículo V: identificadores en inglés camelCase, comentarios en
 *     castellano.
 *
 * Uso: node scratch/test_spell_creator_client.mjs
 */

let assertsPassed = 0;
let assertsFailed = 0;
let uncaughtErrors = 0;

// Centinela: ningún flujo del cliente debe escapar sin control.
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

/**
 * Fábrica de respuestas JSON simuladas (mínimo contrato de fetch).
 */
function jsonResponse(status, payload) {
  return {
    ok: status >= 200 && status < 300,
    status,
    json: async () => payload,
  };
}

console.log('== VERIFICACION TAREA 5.2: spellCreatorClient.js ==\n');

// --- Import del módulo bajo prueba (fase roja: no existe aún) ---
let spellCreatorClient = null;
try {
  spellCreatorClient = await import('../public/assets/js/api/spellCreatorClient.js');
} catch (importError) {
  console.log(`  [FALLA] El módulo spellCreatorClient.js no pudo importarse: ${importError.message}`);
  console.log('\n== RESUMEN ==');
  console.log('Asertos superados: 0');
  console.log('Asertos fallidos: 1');
  console.log('\nRESULTADO: FALLO — Fase roja: implementar public/assets/js/api/spellCreatorClient.js');
  process.exit(1);
}

const {
  SPELL_CREATOR_ERROR_CODES,
  calculateSpell,
  saveDraft,
  listDrafts,
  updateDraft,
  deleteDraft,
  publishSpell,
  updateExperimental,
  createVariant,
} = spellCreatorClient;

const canonicalInput = {
  damage: 30, healing: 0, barrier: 0, crowdControlType: 'none',
  rangeType: 'medium', areaType: 'sphere', durationType: 'instant',
  hasVerbal: true, hasSomatic: true, hasMaterial: false,
};

const canonicalCreate = {
  name: 'Esfera Ígnea de Frieren',
  elementalAffinity: 'fire',
  magicSchool: 'evocation',
  castingTime: 'action',
  description: 'Núcleo de calor blanco.',
  ...canonicalInput,
};

// =====================================================================
// [0] Superficie: las 8 funciones y el catálogo de errores existen.
// =====================================================================
console.log('[0] Superficie');

for (const exportName of ['calculateSpell', 'saveDraft', 'listDrafts', 'updateDraft', 'deleteDraft', 'publishSpell', 'updateExperimental', 'createVariant']) {
  assertCondition(typeof spellCreatorClient[exportName] === 'function', `spellCreatorClient exporta ${exportName}()`);
}
assertCondition(typeof SPELL_CREATOR_ERROR_CODES === 'object' && SPELL_CREATOR_ERROR_CODES !== null, 'El catálogo SPELL_CREATOR_ERROR_CODES está exportado');

// =====================================================================
// [1] calculateSpell — POST /api/v1/spells/calculate.
// =====================================================================
console.log('\n[1] calculateSpell — POST /api/v1/spells/calculate');

{
  let capturedInit = null;
  const restore = installFetchMock(async (url, init) => {
    capturedInit = { url, init };
    return jsonResponse(200, { success: true, data: { finalManaCost: 48, circleLabel: 'Círculo III (Magister)' } });
  });
  const result = await calculateSpell(canonicalInput);
  restore();

  assertCondition(capturedInit?.url === '/api/v1/spells/calculate', 'Solicita POST /api/v1/spells/calculate');
  assertCondition(capturedInit?.init.method === 'POST', 'El verbo es POST');
  assertCondition(capturedInit?.init.credentials === 'same-origin', "Porta credentials: 'same-origin'");
  assertCondition(capturedInit?.init.headers['Content-Type'] === 'application/json', 'Envía Content-Type: application/json');
  assertCondition(JSON.parse(capturedInit.init.body).damage === 30, 'Serializa el cuerpo con los parámetros matemáticos');
  assertCondition(result.success === true && result.status === 200 && result.data.finalManaCost === 48, 'Retorna { success, status, data } con el desglose');
}

{
  // Sobrecarga: el sobre 400 ARCANE_OVERLOAD viaja íntegro sin excepción.
  const restore = installFetchMock(async () => jsonResponse(400, {
    success: false,
    error: { code: 'ARCANE_OVERLOAD', message: 'La concentración de poder supera…', calculatedMana: 361 },
  }));
  const result = await calculateSpell({ ...canonicalInput, damage: 150, rangeType: 'long' });
  restore();

  assertCondition(result.success === false && result.status === 400, 'La sobrecarga retorna { success: false, status: 400 }');
  assertCondition(result.error?.code === 'ARCANE_OVERLOAD' && result.error?.calculatedMana === 361, 'error.code ARCANE_OVERLOAD con calculatedMana intactos');
}

// =====================================================================
// [2] saveDraft — POST /api/v1/spells/drafts (201).
// =====================================================================
console.log('\n[2] saveDraft — POST /api/v1/spells/drafts');

{
  let capturedInit = null;
  const restore = installFetchMock(async (url, init) => {
    capturedInit = { url, init };
    return jsonResponse(201, { success: true, data: { id: 'spl_abc123', slug: 'esfera-ignea-de-frieren', status: 'draft' } });
  });
  const result = await saveDraft(canonicalCreate);
  restore();

  assertCondition(capturedInit?.url === '/api/v1/spells/drafts' && capturedInit.init.method === 'POST', 'Solicita POST /api/v1/spells/drafts');
  assertCondition(JSON.parse(capturedInit.init.body).name === 'Esfera Ígnea de Frieren', 'Serializa el payload narrativo + cuantitativo');
  assertCondition(result.success === true && result.status === 201 && result.data.id === 'spl_abc123', 'Retorna el borrador creado (201, id, slug)');
}

// =====================================================================
// [3] listDrafts — GET /api/v1/spells/drafts (sin cuerpo).
// =====================================================================
console.log('\n[3] listDrafts — GET /api/v1/spells/drafts');

{
  let capturedInit = null;
  const restore = installFetchMock(async (url, init) => {
    capturedInit = { url, init };
    return jsonResponse(200, { success: true, data: [{ id: 'spl_abc123', manaCost: 48 }] });
  });
  const result = await listDrafts();
  restore();

  assertCondition(capturedInit?.url === '/api/v1/spells/drafts' && capturedInit.init.method === 'GET', 'Solicita GET /api/v1/spells/drafts');
  assertCondition(capturedInit.init.body === undefined, 'El GET no envía cuerpo');
  assertCondition(result.success === true && result.data.length === 1, 'Retorna la lista de borradores');
}

// =====================================================================
// [4] updateDraft — PUT /api/v1/spells/drafts/{id}.
// =====================================================================
console.log('\n[4] updateDraft — PUT /api/v1/spells/drafts/{id}');

{
  let capturedInit = null;
  const restore = installFetchMock(async (url, init) => {
    capturedInit = { url, init };
    return jsonResponse(200, { success: true, data: { id: 'spl_abc123', manaCost: 64 } });
  });
  const result = await updateDraft('spl_abc123', canonicalCreate);
  restore();

  assertCondition(capturedInit?.url === '/api/v1/spells/drafts/spl_abc123' && capturedInit.init.method === 'PUT', 'Solicita PUT /api/v1/spells/drafts/spl_abc123');
  assertCondition(result.success === true && result.data.manaCost === 64, 'Retorna el borrador actualizado');
}

// =====================================================================
// [5] deleteDraft — DELETE /api/v1/spells/drafts/{id} (sin cuerpo).
// =====================================================================
console.log('\n[5] deleteDraft — DELETE /api/v1/spells/drafts/{id}');

{
  let capturedInit = null;
  const restore = installFetchMock(async (url, init) => {
    capturedInit = { url, init };
    return jsonResponse(200, { success: true, data: { id: 'spl_abc123', deleted: true } });
  });
  const result = await deleteDraft('spl_abc123');
  restore();

  assertCondition(capturedInit?.url === '/api/v1/spells/drafts/spl_abc123' && capturedInit.init.method === 'DELETE', 'Solicita DELETE /api/v1/spells/drafts/spl_abc123');
  assertCondition(capturedInit.init.body === undefined, 'El DELETE no envía cuerpo');
  assertCondition(result.success === true, 'Retorna éxito de la retirada');
}

// =====================================================================
// [6] publishSpell / updateExperimental / createVariant — transiciones.
// =====================================================================
console.log('\n[6] Transiciones: publish, experimental, variant');

{
  let capturedUrl = null;
  const restore = installFetchMock(async (url) => {
    capturedUrl = url;
    return jsonResponse(200, { success: true, data: { status: 'experimental', signaturesCount: 0 } });
  });
  const result = await publishSpell('spl_abc123');
  restore();

  assertCondition(capturedUrl === '/api/v1/spells/publish/spl_abc123', 'publishSpell solicita POST /api/v1/spells/publish/{id}');
  assertCondition(result.success === true && result.data.signaturesCount === 0, 'Retorna la transición publicada (0/3 firmas)');
}

{
  let capturedInit = null;
  const restore = installFetchMock(async (url, init) => {
    capturedInit = { url, init };
    return jsonResponse(200, { success: true, data: { signaturesReset: true, signaturesCount: 0 } });
  });
  const result = await updateExperimental('spl_abc123', canonicalCreate);
  restore();

  assertCondition(capturedInit?.url === '/api/v1/spells/experimental/spl_abc123' && capturedInit.init.method === 'PUT', 'updateExperimental solicita PUT /api/v1/spells/experimental/{id}');
  assertCondition(result.data.signaturesReset === true, 'Retorna signaturesReset del antifraude');
}

{
  let capturedUrl = null;
  const restore = installFetchMock(async (url) => {
    capturedUrl = url;
    return jsonResponse(201, { success: true, data: { id: 'spl_new9', name: 'Lanza del Alba (Variante)', status: 'draft' } });
  });
  const result = await createVariant('spl_abc123');
  restore();

  assertCondition(capturedUrl === '/api/v1/spells/variant/spl_abc123', 'createVariant solicita POST /api/v1/spells/variant/{id}');
  assertCondition(result.success === true && result.status === 201 && result.data.status === 'draft', 'Retorna la variante nacida (201, draft)');
}

// =====================================================================
// [7] Errores canónicos homogéneos: 401 / 403 / 404 sin excepciones.
// =====================================================================
console.log('\n[7] Errores canónicos homogéneos (401/403/404)');

{
  const restore = installFetchMock(async () => jsonResponse(401, {
    success: false, error: { code: 'UNAUTHENTICATED', message: 'El vínculo arcano no está activo.' },
  }));
  const results = await Promise.all([
    saveDraft(canonicalCreate),
    listDrafts(),
    publishSpell('spl_x'),
    createVariant('spl_x'),
  ]);
  restore();

  const allUnauthorized = results.every((r) => r.success === false && r.status === 401 && r.error?.code === 'UNAUTHENTICATED');
  assertCondition(allUnauthorized, 'Las 4 funciones retornan 401 UNAUTHENTICATED controlado');
}

{
  const restore = installFetchMock(async () => jsonResponse(403, {
    success: false, error: { code: 'SPELL_IMMUTABLE', message: 'Patrimonio inmutable.', recoveryAction: 'CREATE_VARIANT' },
  }));
  const result = await updateExperimental('spl_validado', canonicalCreate);
  restore();

  assertCondition(result.success === false && result.status === 403 && result.error.code === 'SPELL_IMMUTABLE', 'SPELL_IMMUTABLE (403) llega íntegro sin excepción');
}

{
  const restore = installFetchMock(async () => jsonResponse(404, {
    success: false, error: { code: 'SPELL_NOT_FOUND', message: 'Ese conjuro no existe.' },
  }));
  const result = await deleteDraft('spl_fantasma');
  restore();

  assertCondition(result.success === false && result.status === 404 && result.error.code === 'SPELL_NOT_FOUND', 'SPELL_NOT_FOUND (404) llega íntegro sin excepción');
}

{
  const restore = installFetchMock(async () => jsonResponse(403, {
    success: false, error: { code: 'DRAFT_QUOTA_EXCEEDED', message: 'La forja solo admite 10 borradores…', recoveryAction: 'DELETE_OR_PUBLISH_DRAFT' },
  }));
  const result = await saveDraft(canonicalCreate);
  restore();

  assertCondition(result.success === false && result.status === 403 && result.error.recoveryAction === 'DELETE_OR_PUBLISH_DRAFT', 'DRAFT_QUOTA_EXCEEDED (403) llega íntegro con su recoveryAction');
}

// =====================================================================
// [8] Corte de red y cuerpo ilegible: sobre controlado, jamás throw.
// =====================================================================
console.log('\n[8] Red interrumpida y cuerpos ilegibles');

{
  const restore = installFetchMock(async () => { throw new TypeError('fetch failed'); });
  const result = await calculateSpell(canonicalInput);
  restore();

  assertCondition(result.success === false && result.status === 0 && result.error.code === SPELL_CREATOR_ERROR_CODES.networkError, 'El corte de red retorna { status: 0, MANA_STREAM_INTERRUPTED }');
}

{
  const restore = installFetchMock(async () => ({
    ok: true, status: 200, json: async () => { throw new SyntaxError('Unexpected token'); },
  }));
  const result = await listDrafts();
  restore();

  assertCondition(result.success === false && result.error.code === SPELL_CREATOR_ERROR_CODES.networkError, 'Un cuerpo ilegible se convierte en error controlado');
}

// =====================================================================
// Resumen final.
// =====================================================================
console.log('\n== RESUMEN ==');
console.log(`Asertos superados: ${assertsPassed}`);
console.log(`Asertos fallidos: ${assertsFailed}`);
console.log(`Errores no controlados: ${uncaughtErrors}`);

if (assertsFailed === 0 && uncaughtErrors === 0) {
  console.log('\nRESULTADO: EXITO — spellCreatorClient.js listo para las vistas del Taller.');
  process.exit(0);
}
console.log('\nRESULTADO: FALLO');
process.exit(1);
