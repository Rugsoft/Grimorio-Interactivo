/**
 * test_moderation_client.mjs — Arnés de la Tarea 5.1 (TASKS-08).
 *
 * Uso: node scratch/test_moderation_client.mjs
 *
 * Verifica sobre `public/assets/js/api/moderationClient.js`:
 *   [1]  Superficie del módulo: fábrica, códigos canónicos y leyendas.
 *   [2]  Endpoints 1-3 · submitSpell(), withdrawSpell() y reopenSpell() (RF-01.2 a RF-01.4).
 *   [3]  Endpoint 4 · fetchExperimentalHall(): el Atrio público con sus filtros (RF-05.1).
 *   [4]  Endpoint 5 · fetchDeliberationQueue(): la cola de la Torre con minSignatures (RF-05.4).
 *   [5]  Endpoint 6 · signSpell(): la glosa viaja íntegra (RF-02.2).
 *   [6]  Endpoints 7-8 · retractSignature() y objectSpell() (RF-02.4 a RF-02.6).
 *   [7]  Endpoints 9-11 · decretos soberanos con el objetivo en el CUERPO (RF-04).
 *   [8]  Endpoint 12 · checkExpiryCron(): el sello viaja como cabecera (RF-01.6).
 *   [9]  Credencial Bearer OPCIONAL y aditiva, junto a la cookie de sesión.
 *   [10] Nunca lanza: red caída, JSON ilegible y estados sin sobre.
 *   [11] Paridad de contratos con las rutas realmente registradas en el
 *        Front Controller (anti-deriva): toda URL invocada existe de verdad.
 *   [12] Smoke E2E real contra el santuario local (omitible sin servidor).
 *
 * Criterio «Hecho cuando» (Tarea 5.1): el cliente realiza las invocaciones
 * asíncronas a TODOS los endpoints del backend procesando las respuestas
 * normalizadas.
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

const clientModule = await import('../public/assets/js/api/moderationClient.js');
const {
  createModerationClient,
  MODERATION_ERROR_CODES,
  MODERATION_CEREMONIAL_LEGENDS,
  CRON_SECRET_HEADER,
  ceremonialLegendFor,
} = clientModule;

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

const EXPEDIENTE = {
  id: 'rev_1',
  spellId: 'spl_porta',
  authorId: 'usr_autor',
  status: 'experimental',
  signaturesCount: 0,
  signaturesIndicator: '0/3',
  mathFingerprint: 'a'.repeat(64),
};

const EXPEDIENTE_CONSAGRADO = {
  ...EXPEDIENTE,
  status: 'validated',
  signaturesCount: 3,
  signaturesIndicator: '3/3',
  consecrated: true,
};

// =====================================================================
// [1] Superficie del módulo
// =====================================================================
console.log('[1] Superficie del módulo');
assertCondition(typeof createModerationClient === 'function', 'el módulo exporta la fábrica createModerationClient');
assertCondition(typeof ceremonialLegendFor === 'function', 'el módulo exporta el traductor de leyendas ceremoniales');
assertCondition(
  MODERATION_ERROR_CODES.towerCapacityExceeded === 'TOWER_CAPACITY_EXCEEDED'
    && MODERATION_ERROR_CODES.constitutionalEthicsVeto === 'CONSTITUTIONAL_ETHICS_VETO'
    && MODERATION_ERROR_CODES.clanPluralityViolation === 'CLAN_PLURALITY_VIOLATION'
    && MODERATION_ERROR_CODES.selfSigningProhibited === 'SELF_SIGNING_PROHIBITED'
    && MODERATION_ERROR_CODES.imperialDecreeTooShort === 'IMPERIAL_DECREE_TOO_SHORT',
  'los códigos canónicos del backend viajan en el mapa de errores'
);
assertCondition(CRON_SECRET_HEADER === 'X-Arcane-Cron-Secret', 'la cabecera del sello del letargo coincide con la del backend');
assertCondition(
  typeof MODERATION_CEREMONIAL_LEGENDS[MODERATION_ERROR_CODES.towerCapacityExceeded] === 'string'
    && MODERATION_CEREMONIAL_LEGENDS[MODERATION_ERROR_CODES.towerCapacityExceeded].includes('Torre'),
  'el canon ceremonial (RNF-03) declara la leyenda del cupo en castellano'
);
assertCondition(
  ceremonialLegendFor({ error: { code: 'CODIGO_AJENO_SIN_LEYENDA' } }).length > 0,
  'un código desconocido sin mensaje jamás deja la interfaz en blanco'
);
assertCondition(
  ceremonialLegendFor({ error: { code: MODERATION_ERROR_CODES.selfSigningProhibited, message: 'Leyenda del santuario.' } })
    === 'Leyenda del santuario.',
  'la leyenda del santuario tiene precedencia sobre la del cliente'
);

// =====================================================================
// [2] Endpoints 1-3 · El ciclo de vida del autor
// =====================================================================
console.log('\n[2] Endpoints 1-3 · submitSpell(), withdrawSpell() y reopenSpell()');

const stub = createFetchStub([
  { path: '/api/v1/moderation/spells/spl_porta/submit', method: 'POST', status: 200, body: { success: true, data: { review: EXPEDIENTE, remainingCapacity: 2 } } },
  { path: '/api/v1/moderation/spells/spl_porta/withdraw', method: 'POST', status: 200, body: { success: true, data: { review: { ...EXPEDIENTE, status: 'draft' }, remainingCapacity: 3 } } },
  { path: '/api/v1/moderation/spells/spl_porta/reopen', method: 'POST', status: 200, body: { success: true, data: { review: { ...EXPEDIENTE, status: 'draft' }, lastVerdict: { objectionReason: 'La descripción presenta anacronismos.' }, remainingCapacity: 3 } } },
  { path: '/api/v1/moderation/spells/spl%2Fporta/submit', method: 'POST', status: 200, body: { success: true, data: { review: EXPEDIENTE, remainingCapacity: 2 } } },
]);
const client = createModerationClient({ fetch: stub });

const submitResult = await client.submitSpell('spl_porta');
assertCondition(submitResult.success === true && submitResult.status === 200, 'la elevación responde 200 con el sobre de éxito');
assertCondition(submitResult.data?.remainingCapacity === 2, 'el cupo restante del autor viaja para el medidor de la libreta');
const submitCall = stub.calls.at(-1);
assertCondition(
  submitCall.method === 'POST' && submitCall.url === '/api/v1/moderation/spells/spl_porta/submit',
  `la URL y el método de la elevación son canónicos (${submitCall.method} ${submitCall.url})`
);
assertCondition(JSON.parse(submitCall.body) !== null, 'la elevación porta un cuerpo JSON aunque sea vacío');

const withdrawResult = await client.withdrawSpell('spl_porta');
assertCondition(withdrawResult.success === true && withdrawResult.data?.review?.status === 'draft', 'la retirada devuelve la obra a borrador');
assertCondition(
  stub.calls.at(-1).url === '/api/v1/moderation/spells/spl_porta/withdraw',
  'la retirada usa su ruta propia'
);

const reopenResult = await client.reopenSpell('spl_porta');
assertCondition(reopenResult.success === true, 'la re-apertura responde con su sobre');
assertCondition(
  typeof reopenResult.data?.lastVerdict?.objectionReason === 'string' && reopenResult.data.lastVerdict.objectionReason.includes('anacronismos'),
  'el dictamen íntegro viaja en lastVerdict para la subsanación del autor (RF-06.2)'
);

await client.submitSpell('spl/porta');
assertCondition(
  stub.calls.at(-1).url === '/api/v1/moderation/spells/spl%2Fporta/submit',
  'los identificadores se codifican: jamás se inyecta un segmento de ruta'
);

// 409 del cupo colmado: el sobre se propaga entero, con su código y su acción de recuperación.
const quotaStub = createFetchStub([
  {
    match: '/submit',
    status: 409,
    body: {
      success: false,
      error: {
        code: 'TOWER_CAPACITY_EXCEEDED',
        message: 'La Torre de Moderación ya custodia 3 de tus obras en deliberación. Aguarda su resolución antes de elevar nuevas plegarias.',
        recoveryAction: 'AWAIT_TOWER_VERDICT',
      },
    },
  },
]);
const quotaClient = createModerationClient({ fetch: quotaStub });
let quotaThrown = false;
let quotaResult = null;
try {
  quotaResult = await quotaClient.submitSpell('spl_cuarto');
} catch {
  quotaThrown = true;
}
assertCondition(quotaThrown === false, 'el 409 del cupo jamás se lanza hacia la vista del autor');
assertCondition(quotaResult?.success === false && quotaResult.status === 409, 'el 409 llega como sobre de error controlado con su estado');
assertCondition(quotaResult?.error?.code === MODERATION_ERROR_CODES.towerCapacityExceeded, 'el código canónico viaja intacto');
assertCondition(
  quotaResult?.error?.recoveryAction === 'AWAIT_TOWER_VERDICT',
  'la acción de recuperación del santuario sobrevive al cliente'
);
assertCondition(
  ceremonialLegendFor(quotaResult).includes('custodia'),
  'la leyenda ceremonial del santuario se sirve tal cual a la interfaz'
);

// 404 de la obra ajena: la retirada ajena responde 404, no 403 (contrato de la Fase 3).
const foreignStub = createFetchStub([
  { match: '/withdraw', status: 404, body: { success: false, error: { code: 'SPELL_NOT_FOUND', message: 'Ese conjuro no existe o no habita tu grimorio.', recoveryAction: 'RETRY_WITH_VALID_ID' } } },
]);
const foreignClient = createModerationClient({ fetch: foreignStub });
const foreignResult = await foreignClient.withdrawSpell('spl_ajeno');
assertCondition(
  foreignResult?.status === 404 && foreignResult?.error?.code === MODERATION_ERROR_CODES.spellNotFound,
  'la obra ajena llega como 404 SPELL_NOT_FOUND sin lanzar'
);

// =====================================================================
// [3] Endpoint 4 · El Atrio de Pruebas (RF-05.1, RF-05.3)
// =====================================================================
console.log('\n[3] Endpoint 4 · fetchExperimentalHall()');

const ATRIO = {
  success: true,
  data: {
    hallWarning: {
      code: 'UNDER_ARCANE_DELIBERATION',
      legend: 'En Deliberación Arcana — Obra en Fase de Prueba',
      article: 'RF-05.3',
      pointsBlocked: true,
    },
    items: [{ spellId: 'spl_porta', spellName: 'Portal de Brasas', signaturesCount: 0, signaturesIndicator: '0/3', hasEthicalConflict: false }],
    pagination: { page: 1, limit: 12, totalItems: 1, totalPages: 1 },
  },
};
const hallStub = createFetchStub([{ match: '/api/v1/moderation/experimental', status: 200, body: ATRIO }]);
const hallClient = createModerationClient({ fetch: hallStub });
const hallResult = await hallClient.fetchExperimentalHall({ element: 'fire', school: 'evocation', page: 2, perPage: 20 });
assertCondition(hallResult.success === true, 'el Atrio responde con el sobre de éxito');
assertCondition(hallResult.data?.hallWarning?.code === 'UNDER_ARCANE_DELIBERATION', 'la insignia del Atrio viaja en hallWarning (una sola por catálogo)');
assertCondition(hallResult.data?.hallWarning?.pointsBlocked === true, 'el bloqueo de PDA declara el aislamiento del Atrio (RF-05.3)');
assertCondition(hallResult.data?.items?.[0]?.signaturesIndicator === '0/3', 'el medidor de firmas viaja por tarjeta');
const hallCall = hallStub.calls.at(-1);
assertCondition(hallCall.method === 'GET', 'el Atrio se consulta con GET');
assertCondition(
  hallCall.url === '/api/v1/moderation/experimental?element=fire&school=evocation&page=2&perPage=20',
  `la query porta los filtros canónicos (${hallCall.url})`
);

const bareHallStub = createFetchStub([{ match: '/api/v1/moderation/experimental', status: 200, body: ATRIO }]);
const bareHallClient = createModerationClient({ fetch: bareHallStub });
await bareHallClient.fetchExperimentalHall({ element: undefined, school: '', page: 1 });
assertCondition(
  bareHallStub.calls.at(-1).url === '/api/v1/moderation/experimental?page=1',
  `los filtros ausentes no viajan como cadenas vacías (${bareHallStub.calls.at(-1).url})`
);

const hallParamsStub = createFetchStub([{ match: '/api/v1/moderation/experimental', status: 400, body: { success: false, error: { code: 'INVALID_QUERY_PARAMS', message: 'La página y su tamaño deben ser números enteros positivos.' } } }]);
const hallParamsClient = createModerationClient({ fetch: hallParamsStub });
const hallParamsResult = await hallParamsClient.fetchExperimentalHall({ page: 'abelincoln' });
assertCondition(
  hallParamsResult?.error?.code === MODERATION_ERROR_CODES.invalidQueryParams && hallParamsResult.status === 400,
  'la paginación imposible llega como 400 INVALID_QUERY_PARAMS'
);

// =====================================================================
// [4] Endpoint 5 · La cola de la Torre (RF-05.4, RF-03.1)
// =====================================================================
console.log('\n[4] Endpoint 5 · fetchDeliberationQueue()');

const COLA = {
  success: true,
  data: {
    canon: { signaturesRequired: 3, glossMaxLength: 250, objectionMinLength: 20 },
    items: [{
      spellId: 'spl_porta',
      spellName: 'Portal de Brasas',
      authorAlias: 'brasas',
      signaturesCount: 1,
      signaturesIndicator: '1/3',
      hasEthicalConflict: true,
      ethicalVeto: { code: 'clanIncompatibility', legend: 'Conflicto de intereses.' },
    }],
    pagination: { page: 1, limit: 12, totalItems: 1, totalPages: 1 },
  },
};
const queueStub = createFetchStub([{ match: '/api/v1/moderation/queue', status: 200, body: COLA }]);
const queueClient = createModerationClient({ fetch: queueStub });
const queueResult = await queueClient.fetchDeliberationQueue({ element: 'fire', school: 'evocation', minSignatures: 1, page: 1, perPage: 12 });
assertCondition(queueResult.success === true, 'la cola responde con el sobre de éxito');
assertCondition(queueResult.data?.canon?.signaturesRequired === 3, 'los umbrales del Cónclave llegan declarados por su autoridad');
const conflictedItem = queueResult.data?.items?.[0];
assertCondition(conflictedItem?.hasEthicalConflict === true, 'el veredicto ético viaja YA RESUELTO en servidor');
assertCondition(
  conflictedItem?.ethicalVeto?.code === 'clanIncompatibility' && typeof conflictedItem.ethicalVeto.legend === 'string',
  'la CAUSA del veto viaja con su leyenda ceremonial (Tarea 5.3 la rotula sin deducir la ley de un true)'
);
const queueCall = queueStub.calls.at(-1);
assertCondition(
  queueCall.url === '/api/v1/moderation/queue?element=fire&school=evocation&minSignatures=1&page=1&perPage=12',
  `la query porta el filtro de firmas mínimas (${queueCall.url})`
);

const queueRangeStub = createFetchStub([{ match: '/api/v1/moderation/queue', status: 400, body: { success: false, error: { code: 'INVALID_QUERY_PARAMS', message: 'El filtro de firmas mínimas ha de ser un entero entre cero y el techo de tres.' } } }]);
const queueRangeClient = createModerationClient({ fetch: queueRangeStub });
const queueRangeResult = await queueRangeClient.fetchDeliberationQueue({ minSignatures: 4 });
assertCondition(
  queueRangeResult?.status === 400 && queueRangeResult?.error?.code === MODERATION_ERROR_CODES.invalidQueryParams,
  'un umbral por encima del techo llega como 400 sin lanzar'
);

// =====================================================================
// [5] Endpoint 6 · Firma de Consagración (RF-02.1 a RF-02.3)
// =====================================================================
console.log('\n[5] Endpoint 6 · signSpell()');

const signStub = createFetchStub([
  { match: '/sign', status: 200, body: { success: true, data: { review: EXPEDIENTE_CONSAGRADO, consecrated: true } } },
]);
const signClient = createModerationClient({ fetch: signStub });

// Cada veredicto adverso se ejercita sobre su propio guion: un doble que
// empata por la ruta devolvería siempre la PRIMERA respuesta programada.
const glossStub = createFetchStub([
  { match: '/sign', status: 400, body: { success: false, error: { code: 'GLOSS_TOO_LONG', message: 'La glosa ceremonial no puede exceder los 250 caracteres: se pronunciaron 251.', recoveryAction: 'SHORTEN_CEREMONIAL_GLOSS' } } },
]);
const glossClient = createModerationClient({ fetch: glossStub });
const vetoStub = createFetchStub([
  { match: '/sign', status: 403, body: { success: false, error: { code: 'CONSTITUTIONAL_ETHICS_VETO', message: 'Conflicto de intereses.', recoveryAction: 'ABSTAIN_FROM_DELIBERATION' } } },
]);
const vetoClient = createModerationClient({ fetch: vetoStub });

const signResult = await signClient.signSpell('spl_porta', 'Por la pureza del fulgor y la armonía de su invocación.');
assertCondition(signResult.success === true, 'la firma responde con el expediente');
assertCondition(signResult.data?.consecrated === true, 'la consagración en la 3ª firma llega declarada');
const signedCall = signStub.calls.at(-1);
assertCondition(signedCall.method === 'POST', 'la firma se cursa con POST');
assertCondition(signedCall.url === '/api/v1/moderation/spells/spl_porta/sign', 'la firma viaja por la ruta canónica');
assertCondition(
  JSON.parse(signedCall.body).ceremonialGloss === 'Por la pureza del fulgor y la armonía de su invocación.',
  'la glosa viaja íntegra en el campo canónico ceremonialGloss'
);

await signClient.signSpell('spl_porta', '');
const emptyGlossCall = signStub.calls.at(-1);
assertCondition(
  JSON.parse(emptyGlossCall.body).ceremonialGloss === undefined,
  'la ausencia de glosa viaja como ausencia, jamás como cadena vacía'
);

const glossResult = await glossClient.signSpell('spl_porta', 'g'.repeat(251));
assertCondition(
  glossResult?.error?.code === MODERATION_ERROR_CODES.glossTooLong && glossResult.status === 400,
  'el 400 GLOSS_TOO_LONG se propaga sin lanzar: el canon lo dicta el santuario'
);

const vetoResult = await vetoClient.signSpell('spl_porta', 'Firmo con la boca pequeña.');
assertCondition(
  vetoResult?.error?.code === MODERATION_ERROR_CODES.constitutionalEthicsVeto && vetoResult.status === 403,
  'el veto ético del Artículo III se propaga como 403 sin lanzar'
);

// =====================================================================
// [6] Endpoints 7-8 · Retractación y Dictamen (RF-02.4 a RF-02.6)
// =====================================================================
console.log('\n[6] Endpoints 7-8 · retractSignature() y objectSpell()');

const retractStub = createFetchStub([
  { match: '/retract', status: 200, body: { success: true, data: { review: { ...EXPEDIENTE, signaturesCount: 0 }, signaturesCount: 0, signaturesIndicator: '0/3' } } },
]);
const retractClient = createModerationClient({ fetch: retractStub });
const noSigStub = createFetchStub([
  { match: '/retract', status: 400, body: { success: false, error: { code: 'NO_ACTIVE_SIGNATURE', message: 'No obra firma viva de ese Maestro sobre esta obra.', recoveryAction: 'REVIEW_SIGNATURE_STATE' } } },
]);
const noSigClient = createModerationClient({ fetch: noSigStub });
const irrevocableStub = createFetchStub([
  { match: '/retract', status: 409, body: { success: false, error: { code: 'SIGNATURE_IRREVOCABLE', message: 'La obra ya alcanzó la consagración.', recoveryAction: 'FORGE_A_VARIANT_INSTEAD' } } },
]);
const irrevocableClient = createModerationClient({ fetch: irrevocableStub });

const retractResult = await retractClient.retractSignature('spl_porta', 'Duda razonable sobre la resonancia elemental.');
assertCondition(retractResult.success === true && retractResult.data?.signaturesIndicator === '0/3', 'la retractación devuelve el contador descendido');
const retractCall = retractStub.calls.at(-1);
assertCondition(
  JSON.parse(retractCall.body).reason === 'Duda razonable sobre la resonancia elemental.',
  'el motivo de la retractación viaja en el campo canónico reason'
);

const noSigResult = await noSigClient.retractSignature('spl_porta');
assertCondition(
  noSigResult?.error?.code === MODERATION_ERROR_CODES.noActiveSignature && noSigResult.status === 400,
  'el 400 NO_ACTIVE_SIGNATURE se propaga sin lanzar'
);

const irrevocableResult = await irrevocableClient.retractSignature('spl_porta');
assertCondition(
  irrevocableResult?.error?.code === MODERATION_ERROR_CODES.signatureIrrevocable && irrevocableResult.status === 409,
  'el 409 SIGNATURE_IRREVOCABLE se propaga sin lanzar'
);

const objectStub = createFetchStub([
  { match: '/object', status: 200, body: { success: true, data: { review: { ...EXPEDIENTE, status: 'rejected' }, verdict: { objectionReason: 'La descripción presenta anacronismos que vulneran el Velo Arcano.' } } } },
]);
const objectClient = createModerationClient({ fetch: objectStub });
const briefStub = createFetchStub([
  { match: '/object', status: 422, body: { success: false, error: { code: 'OBJECTION_TOO_BRIEF', message: 'El Dictamen de Objeción exige una justificación en castellano de al menos 20 caracteres: se redactaron 19.', recoveryAction: 'RESTATE_OBJECTION_REASON' } } },
]);
const briefClient = createModerationClient({ fetch: briefStub });

const objectResult = await objectClient.objectSpell('spl_porta', 'La descripción presenta anacronismos que vulneran el Velo Arcano.');
assertCondition(objectResult.success === true, 'el dictamen responde con la obra vetada');
assertCondition(
  objectResult.data?.verdict?.objectionReason?.includes('Velo Arcano'),
  'el dictamen íntegro viaja en la respuesta para la subsanación del autor'
);
const objectCall = objectStub.calls.at(-1);
assertCondition(
  JSON.parse(objectCall.body).objectionReason?.includes('anacronismos'),
  'la justificación viaja en el campo canónico objectionReason'
);

const briefResult = await briefClient.objectSpell('spl_porta', 'Muy breve');
assertCondition(
  briefResult?.error?.code === MODERATION_ERROR_CODES.objectionTooBrief && briefResult.status === 422,
  'el 422 OBJECTION_TOO_BRIEF se propaga sin lanzar'
);

// =====================================================================
// [7] Endpoints 9-11 · Los decretos soberanos (RF-04)
// =====================================================================
console.log('\n[7] Endpoints 9-11 · sovereignValidate(), sovereignRescue() y sovereignArchive()');

const sovereignStub = createFetchStub([
  { path: '/api/v1/moderation/sovereign/validate', method: 'POST', status: 200, body: { success: true, data: { review: EXPEDIENTE_CONSAGRADO, decree: { decreeType: 'sovereignValidation', imperialDecreeText: 'Edicto de consagración solemne.' } } } },
]);
const rescueStub = createFetchStub([
  { path: '/api/v1/moderation/sovereign/rescue', method: 'POST', status: 200, body: { success: true, data: { review: { ...EXPEDIENTE, status: 'experimental', signaturesCount: 0 }, decree: { decreeType: 'rescueToExperimental', imperialDecreeText: 'Edicto de rescate solemne.' } } } },
]);
const archiveStub = createFetchStub([
  { path: '/api/v1/moderation/sovereign/archive', method: 'POST', status: 200, body: { success: true, data: { review: { ...EXPEDIENTE, status: 'archived' }, decree: { decreeType: 'revokeAndArchive', imperialDecreeText: 'Edicto de destierro solemne.' }, gloryDeductionOrdered: true } } },
]);
const shortDecreeStub = createFetchStub([
  { match: '/sovereign', status: 422, body: { success: false, error: { code: 'IMPERIAL_DECREE_TOO_SHORT', message: 'Todo Decreto Imperial exige un edicto de justificación de al menos 20 caracteres.', recoveryAction: 'RESTATE_IMPERIAL_DECREE' } } },
]);
const sovereignClient = createModerationClient({ fetch: sovereignStub });
const rescueClient = createModerationClient({ fetch: rescueStub });
const archiveClient = createModerationClient({ fetch: archiveStub });
const shortDecreeClient = createModerationClient({ fetch: shortDecreeStub });

const validateResult = await sovereignClient.sovereignValidate('spl_porta', 'Por decreto soberano, esta obra queda consagrada en el Tomo.');
assertCondition(validateResult.success === true, 'la Firma Soberana responde con la obra consagrada');
const validateCall = sovereignStub.calls.at(-1);
assertCondition(validateCall.path === '/api/v1/moderation/sovereign/validate' && validateCall.method === 'POST', 'el decreto viaja por su ruta literal');
const validateBody = JSON.parse(validateCall.body);
assertCondition(
  validateBody.spellId === 'spl_porta' && validateBody.imperialDecreeText.includes('Tomo'),
  'el OBJETIVO del decreto viaja en el CUERPO (spellId), no en la ruta (contrato de la Fase 3)'
);

const rescueResult = await rescueClient.sovereignRescue('spl_porta', 'experimental', 'La objeción carecía de fundamento litúrgico objetivo.');
assertCondition(
  rescueResult.success === true && rescueResult.data?.review?.status === 'experimental',
  'el rescate devuelve la obra a deliberación limpia'
);
const rescueBody = JSON.parse(rescueStub.calls.at(-1).body);
assertCondition(
  rescueBody.spellId === 'spl_porta' && rescueBody.targetStatus === 'experimental',
  'el destino del rescate viaja en el campo canónico targetStatus'
);

const archiveResult = await archiveClient.sovereignArchive('spl_porta', 'Se constata fraude en la composición del conjuro.', true);
assertCondition(archiveResult.success === true, 'el destierro responde con la obra caída del canon');
const archiveCall = archiveStub.calls.at(-1);
const archiveBody = JSON.parse(archiveCall.body);
assertCondition(archiveBody.deductPoints === true, 'la orden de deducción viaja como booleano EXPLÍCITO');
assertCondition(archiveResult.data?.gloryDeductionOrdered === true, 'el acuse de la deducción viaja en gloryDeductionOrdered');

const shortDecreeResult = await shortDecreeClient.sovereignValidate('spl_porta', 'Edicto breve');
assertCondition(
  shortDecreeResult?.error?.code === MODERATION_ERROR_CODES.imperialDecreeTooShort && shortDecreeResult.status === 422,
  'el 422 IMPERIAL_DECREE_TOO_SHORT se propaga sin lanzar'
);

// =====================================================================
// [8] Endpoint 12 · El barrido de caducidad (RF-01.6)
// =====================================================================
console.log('\n[8] Endpoint 12 · checkExpiryCron()');

const cronStub = createFetchStub([
  { match: '/cron-check-expiry', status: 200, body: { success: true, data: { legend: 'Letargo Arcano', staleDays: 90, expiredCount: 1, expired: [] } } },
]);
const cronClient = createModerationClient({ fetch: cronStub });
const unsealedStub = createFetchStub([
  { match: '/cron-check-expiry', status: 401, body: { success: false, error: { code: 'CRON_SECRET_REQUIRED', message: 'El barrido exige el sello del custodio.', recoveryAction: 'PROVIDE_CRON_SECRET' } } },
]);
const unsealedClient = createModerationClient({ fetch: unsealedStub });
const forgedStub = createFetchStub([
  { match: '/cron-check-expiry', status: 403, body: { success: false, error: { code: 'CRON_SECRET_INVALID', message: 'El sello presentado no autoriza esta invocación.', recoveryAction: 'REVIEW_CRON_SECRET' } } },
]);
const forgedClient = createModerationClient({ fetch: forgedStub });

const cronResult = await cronClient.checkExpiryCron('sello-del-custodio');
assertCondition(cronResult.success === true && cronResult.data?.staleDays === 90, 'el barrido rinde su acta con el umbral de noventa días');
const cronCall = cronStub.calls.at(-1);
assertCondition(
  cronCall.headers[CRON_SECRET_HEADER] === 'sello-del-custodio',
  'el sello viaja SIEMPRE como cabecera, jamás en el cuerpo ni en la query'
);

const unsealedResult = await unsealedClient.checkExpiryCron();
assertCondition(
  unsealedResult?.status === 401 && unsealedResult?.error?.code === MODERATION_ERROR_CODES.cronSecretRequired,
  'sin sello el barrido falla cerrado con 401 CRON_SECRET_REQUIRED'
);

const forgedResult = await forgedClient.checkExpiryCron('sello-falso');
assertCondition(
  forgedResult?.status === 403 && forgedResult?.error?.code === MODERATION_ERROR_CODES.cronSecretInvalid,
  'el sello ajeno llega como 403 CRON_SECRET_INVALID'
);

// =====================================================================
// [9] Credenciales
// =====================================================================
console.log('\n[9] Credenciales');
assertCondition(stub.calls.every((call) => call.credentials === 'same-origin'), 'toda petición viaja con credentials same-origin: la cookie de sesión va sola');
assertCondition(
  stub.calls.every((call) => call.headers.Authorization === undefined),
  'sin token inyectado no se inventa credencial Bearer alguna'
);

const bearerStub = createFetchStub([{ match: '/api/v1/moderation', status: 200, body: { success: true, data: {} } }]);
const bearerClient = createModerationClient({ fetch: bearerStub, token: 'sello-del-custodio' });
await bearerClient.fetchExperimentalHall();
assertCondition(
  bearerStub.calls.at(-1).headers.Authorization === 'Bearer sello-del-custodio',
  'con token inyectado la petición porta Authorization: Bearer'
);
const blankTokenStub = createFetchStub([{ match: '/api/v1/moderation', status: 200, body: { success: true, data: {} } }]);
await createModerationClient({ fetch: blankTokenStub, token: '   ' }).fetchExperimentalHall();
assertCondition(blankTokenStub.calls.at(-1).headers.Authorization === undefined, 'un token en blanco no se traduce en una cabecera vacía');

// =====================================================================
// [10] Nunca lanza: red caída, JSON ilegible y estados sin sobre
// =====================================================================
console.log('\n[10] Los fallos de corriente se convierten en sobres controlados');
const brokenClient = createModerationClient({ fetch: async () => { throw new TypeError('Failed to fetch'); } });
const brokenResult = await brokenClient.fetchExperimentalHall();
assertCondition(brokenResult.success === false && brokenResult.status === 0, 'el corte de red se convierte en error controlado sin estado HTTP');
assertCondition(brokenResult.error?.code === MODERATION_ERROR_CODES.networkError, 'el corte porta el código canónico');
assertCondition(ceremonialLegendFor(brokenResult).includes('interrumpido'), 'el corte de red se anuncia con leyenda ceremonial en castellano');

const garbageClient = createModerationClient({
  fetch: async () => ({ ok: true, status: 200, json: async () => { throw new SyntaxError('Unexpected token <'); } }),
});
const garbageResult = await garbageClient.fetchExperimentalHall();
assertCondition(garbageResult.success === false && garbageResult.error?.code === MODERATION_ERROR_CODES.networkError, 'un cuerpo ilegible no rompe la vista del Atrio');

const htmlErrorClient = createModerationClient({ fetch: async () => ({ ok: false, status: 502, json: async () => { throw new SyntaxError('nope'); } }) });
const htmlErrorResult = await htmlErrorClient.fetchDeliberationQueue();
assertCondition(htmlErrorResult.success === false && htmlErrorResult.status === 502, 'un 502 sin sobre JSON se traduce en error controlado con su estado');

// =====================================================================
// [11] Paridad de contratos con el router real (anti-deriva)
// =====================================================================
console.log('\n[11] Paridad de contratos con el Front Controller');
const routerSource = await readFile(new URL('../public/index.php', import.meta.url), 'utf8');
const registeredRoutes = [...routerSource.matchAll(/addRoute\('([A-Z]+)',\s*'([^']+)'/g)].map(([, method, pattern]) => ({ method, pattern }));
assertCondition(registeredRoutes.length > 0, `el Front Controller declara sus rutas (${registeredRoutes.length} encontradas)`);

/** Convierte un patrón del router en expresión regular, igual que él lo hace. */
function routePatternToRegExp(pattern) {
  const escaped = pattern.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
  return new RegExp(`^${escaped.replace(/\\\{[^}]+\\\}/g, '[^/]+')}$`);
}

const consulted = [...stub.calls, ...quotaStub.calls, ...foreignStub.calls, ...hallStub.calls, ...bareHallStub.calls, ...hallParamsStub.calls, ...queueStub.calls, ...queueRangeStub.calls, ...signStub.calls, ...glossStub.calls, ...vetoStub.calls, ...retractStub.calls, ...noSigStub.calls, ...irrevocableStub.calls, ...objectStub.calls, ...briefStub.calls, ...sovereignStub.calls, ...rescueStub.calls, ...archiveStub.calls, ...shortDecreeStub.calls, ...cronStub.calls, ...unsealedStub.calls, ...forgedStub.calls, ...bearerStub.calls, ...blankTokenStub.calls]
  .map((call) => ({ method: call.method, path: call.url.split('?')[0] }));
const orphanCalls = consulted.filter((call) => !registeredRoutes.some(
  (route) => route.method === call.method && routePatternToRegExp(route.pattern).test(call.path)
));
assertCondition(
  orphanCalls.length === 0,
  `toda URL invocada por el cliente existe en el router${orphanCalls.length === 0 ? '' : ` — huérfanas: ${orphanCalls.map((call) => `${call.method} ${call.path}`).join(', ')}`}`
);
assertCondition(
  registeredRoutes.some((route) => route.method === 'POST' && route.pattern === '/api/v1/moderation/spells/{id}/submit')
    && registeredRoutes.some((route) => route.method === 'POST' && route.pattern === '/api/v1/moderation/sovereign/validate')
    && registeredRoutes.some((route) => route.method === 'POST' && route.pattern === '/api/v1/moderation/cron-check-expiry'),
  'las doce rutas de moderación están registradas con su método propio'
);

// =====================================================================
// [12] Smoke E2E real contra el santuario local (omitible sin servidor)
// =====================================================================
console.log('\n[12] Smoke E2E real contra el santuario local');
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

const probe = await liveRequest('GET', '/api/v1/moderation/experimental');
if (!probe.ok) {
  console.log('  [OMITIDA] Santuario local no disponible: arranca el servidor de demo y repite para el smoke completo');
} else {
  const liveClient = createModerationClient({
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

  const liveHall = await liveClient.fetchExperimentalHall({ page: 1, perPage: 5 });
  assertCondition(
    liveHall.success === true && Array.isArray(liveHall.data?.items),
    `GET /api/v1/moderation/experimental real: ${liveHall.data?.items?.length ?? 0} obras en deliberación con su insignia`
  );
  assertCondition(
    typeof liveHall.data?.hallWarning?.legend === 'string',
    'la insignia del Atrio real viaja con su leyenda ceremonial'
  );

  const liveQueue = await liveClient.fetchDeliberationQueue();
  assertCondition(
    liveQueue.status === 401 && liveQueue.error?.code === MODERATION_ERROR_CODES.unauthenticated,
    'GET /api/v1/moderation/queue sin vínculo real: 401 UNAUTHENTICATED a través del cliente'
  );

  const liveUnsealed = await liveClient.checkExpiryCron('sello-ajeno');
  assertCondition(
    liveUnsealed.status === 401 || liveUnsealed.status === 403,
    `POST /api/v1/moderation/cron-check-expiry real: el barrido falla cerrado (${liveUnsealed.status})`
  );
}

// =====================================================================
// Resumen
// =====================================================================
console.log(`\n== RESUMEN == Asertos superados: ${assertsPassed}, fallidos: ${assertsFailed}`);
if (assertsFailed === 0) {
  console.log('RESULTADO: EXITO — El cliente de moderación consume los doce endpoints y doma 400/401/403/404/409/422 (Tarea 5.1).');
  process.exit(0);
}
console.log('RESULTADO: DENEGADO — Revisa los asertos marcados.');
process.exit(1);
