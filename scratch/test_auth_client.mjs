/**
 * test_auth_client.mjs — Verificación del cliente HTTP de autenticación (Tarea 4.1).
 *
 * Estrategia TDD: este script se escribe ANTES que authClient.js.
 * Fase roja = el módulo public/assets/js/api/authClient.js no existe todavía.
 *
 * Valida contra el criterio «Hecho cuando» de specs/03-auth-rbac.tasks.md:
 *   El cliente efectúa peticiones HTTP nativas a la API y traduce los
 *   códigos de estado en objetos reactivos controlados en el frontend.
 *
 * Contratos verificados (plan 2.2, Endpoints 1-5 + Tarea 4.1):
 *   - Funciones exportadas: consecrate(data), bind(identity, passphrase),
 *     dissolve(), dissolveAll(), checkSession(), fetchAuditLog(params).
 *   - Cookies automáticas: credentials: 'same-origin' en TODAS las peticiones.
 *   - Códigos de estado traducidos a objetos controlados (sin excepciones):
 *     200/201 → success: true; 400/401/409/429 → success: false con
 *     error.code intacto (INVALID_CREDENTIALS, RATE_LIMITED,
 *     IDENTITY_ALREADY_CLAIMED, ...).
 *   - Cuerpos JSON enviados con Content-Type: application/json (Dogma
 *     Vanilla: fetch nativo, sin librerías).
 *
 * Uso: node scratch/test_auth_client.mjs
 */

import assert from 'node:assert';

let assertsPassed = 0;
let assertsFailed = 0;
let uncaughtErrors = 0;

// Centinela: ningún flujo de autenticación debe escapar sin control.
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

console.log('== VERIFICACION TAREA 4.1: authClient.js ==\n');

// --- Import del módulo bajo prueba (fase roja: no existe aún) ---
let authClient = null;
try {
  authClient = await import('../public/assets/js/api/authClient.js');
} catch (importError) {
  console.log(`  [FALLA] El módulo authClient.js no pudo importarse: ${importError.message}`);
  console.log('\n== RESUMEN ==');
  console.log('Asertos superados: 0');
  console.log('Asertos fallidos: 1');
  console.log('\nRESULTADO: FALLO — Fase roja: implementar public/assets/js/api/authClient.js');
  process.exit(1);
}

const {
  consecrate,
  bind,
  dissolve,
  dissolveAll,
  checkSession,
  fetchAuditLog,
  AUTH_ERROR_CODES,
} = authClient;

// --- Verificación de la superficie exportada (plan 2.2, Tarea 4.1) ---
console.log('FASE 0: Superficie exportada del módulo');

assertCondition(typeof consecrate === 'function', 'consecrate(data) está exportada');
assertCondition(typeof bind === 'function', 'bind(identity, passphrase) está exportada');
assertCondition(typeof dissolve === 'function', 'dissolve() está exportada');
assertCondition(typeof dissolveAll === 'function', 'dissolveAll() está exportada');
assertCondition(typeof checkSession === 'function', 'checkSession() está exportada');
assertCondition(typeof fetchAuditLog === 'function', 'fetchAuditLog(params) está exportada');

// --- FASE A: Contratos con mock de fetch ---
console.log('\nFASE A: Peticiones nativas con fetch simulado');

// A.1: consecrate() — POST JSON con Content-Type y cookies same-origin.
{
  let capturedUrl = '';
  let capturedInit = null;
  const restoreFetch = installFetchMock(async (requestedUrl, init) => {
    capturedUrl = String(requestedUrl);
    capturedInit = init ?? {};
    return new Response(
      JSON.stringify({
        success: true,
        data: { user: { id: 'usr_9a8b7c6d', alias: 'FrierenElf', role: 'editor', clanId: 'cln_astral_scholars', clanName: 'Eruditos Astrales' } },
      }),
      { status: 201, headers: { 'Content-Type': 'application/json' } },
    );
  });

  const result = await consecrate({
    alias: 'FrierenElf',
    email: 'frieren@sanctuario.arc',
    passphrase: 'palabra-secreta-del-mago',
    clanId: 'cln_astral_scholars',
  });

  assertCondition(capturedUrl === '/api/v1/auth/consecrate', 'consecrate apunta a POST /api/v1/auth/consecrate');
  assertCondition(capturedInit.method === 'POST', 'La petición usa el verbo POST');
  assertCondition(capturedInit.credentials === 'same-origin', 'La petición porta credentials: same-origin (cookies automáticas)');
  assertCondition(String(capturedInit.headers?.['Content-Type']).includes('application/json'), 'El cuerpo viaja como application/json');
  const sentPayload = JSON.parse(String(capturedInit.body));
  assertCondition(sentPayload.clanId === 'cln_astral_scholars', 'El payload porta el clan obligatorio (RF-01.1)');

  assertCondition(result.success === true, 'El 201 se traduce en success: true');
  assertCondition(result.status === 201, 'El estado HTTP viaja en el objeto controlado (result.status)');
  assertCondition(result.data?.user?.role === 'editor', 'data.user.role editor disponible para el store reactivo');
  restoreFetch();
}

// A.2: bind() — credenciales válidas (200) y erróneas (401 neutro).
{
  let capturedUrl = '';
  let capturedInit = null;
  const restoreFetch = installFetchMock(async (requestedUrl, init) => {
    capturedUrl = String(requestedUrl);
    capturedInit = init ?? {};
    return new Response(
      JSON.stringify({
        success: true,
        data: { user: { id: 'usr_9a8b7c6d', alias: 'FrierenElf', role: 'editor', clanId: 'cln_astral', clanName: 'Eruditos Astrales' } },
      }),
      { status: 200, headers: { 'Content-Type': 'application/json' } },
    );
  });

  const okResult = await bind('frieren@sanctuario.arc', 'palabra-secreta-del-mago');
  assertCondition(capturedUrl === '/api/v1/auth/bind', 'bind apunta a POST /api/v1/auth/bind');
  assertCondition(capturedInit.credentials === 'same-origin', 'bind porta credentials: same-origin');
  const sentPayload = JSON.parse(String(capturedInit.body));
  assertCondition(
    sentPayload.identity === 'frieren@sanctuario.arc' && sentPayload.passphrase === 'palabra-secreta-del-mago',
    'El payload porta identity y passphrase según el plan Endpoint 2',
  );
  assertCondition(okResult.success === true && okResult.data?.user?.alias === 'FrierenElf', 'El 200 se traduce en success: true con data.user');

  // 401 neutro: el error controlado conserva el código del backend.
  restoreFetch();
  const restore401 = installFetchMock(async () => new Response(
    JSON.stringify({
      success: false,
      error: { code: 'INVALID_CREDENTIALS', message: 'Las runas no reconocen este vínculo o la palabra secreta es errónea.', recoveryAction: 'RETRY_OR_RECOVER' },
    }),
    { status: 401, headers: { 'Content-Type': 'application/json' } },
  ));
  const badResult = await bind('frieren@sanctuario.arc', 'frase-erronea');
  assertCondition(badResult.success === false, 'El 401 se traduce en success: false (sin excepción)');
  assertCondition(badResult.status === 401, 'El estado 401 viaja en el objeto controlado');
  assertCondition(badResult.error?.code === 'INVALID_CREDENTIALS', 'El código INVALID_CREDENTIALS viaja intacto para la UI');
  restore401();
}

// A.3: 429 RATE_LIMITED — la traducción permite bloquear el botón (RF-03.2).
{
  const restoreFetch = installFetchMock(async () => new Response(
    JSON.stringify({
      success: false,
      error: { code: 'RATE_LIMITED', message: 'El umbral permanecerá cerrado durante 15 minutos.', remainingSeconds: 840, recoveryAction: 'WAIT' },
    }),
    { status: 429, headers: { 'Content-Type': 'application/json' } },
  ));

  const frozenResult = await bind('asedio@sanctuario.arc', 'clave-del-sitiado-larga');
  assertCondition(frozenResult.success === false && frozenResult.status === 429, 'El 429 se traduce en success: false con status 429');
  assertCondition(frozenResult.error?.code === 'RATE_LIMITED', 'El código RATE_LIMITED llega a la UI para bloquear el botón');
  assertCondition(frozenResult.error?.remainingSeconds === 840, 'remainingSeconds viaja para la cuenta atrás del modal');
  restoreFetch();
}

// A.4: 409 IDENTITY_ALREADY_CLAIMED — anti-enumeración propagada.
{
  const restoreFetch = installFetchMock(async () => new Response(
    JSON.stringify({
      success: false,
      error: { code: 'IDENTITY_ALREADY_CLAIMED', message: 'Esa identidad ya fue reclamada por otro iniciado del santuario.', recoveryAction: 'CHOOSE_IDENTITY' },
    }),
    { status: 409, headers: { 'Content-Type': 'application/json' } },
  ));

  const duplicateResult = await consecrate({ alias: 'FrierenElf', email: 'otra@sanctuario.arc', passphrase: 'clave-larga-suficiente', clanId: 'cln_astral' });
  assertCondition(duplicateResult.success === false && duplicateResult.status === 409, 'El 409 se traduce en success: false con status 409');
  assertCondition(duplicateResult.error?.code === 'IDENTITY_ALREADY_CLAIMED', 'El código anti-enumeración viaja intacto (RF-01.3)');
  restoreFetch();
}

// A.5: dissolve() y dissolveAll() — POST sin cuerpo con cookies.
{
  let capturedInit = null;
  const restoreFetch = installFetchMock(async (_requestedUrl, init) => {
    capturedInit = init ?? {};
    return new Response(
      JSON.stringify({ success: true, data: { message: 'El vínculo ha sido disuelto en paz.' } }),
      { status: 200, headers: { 'Content-Type': 'application/json' } },
    );
  });

  const dissolveResult = await dissolve();
  assertCondition(capturedInit.method === 'POST' && capturedInit.credentials === 'same-origin', 'dissolve hace POST con cookies automáticas');
  assertCondition(dissolveResult.success === true && dissolveResult.data?.message !== undefined, 'La disolución individual porta la leyenda del plan');

  const dissolveAllResult = await dissolveAll();
  assertCondition(dissolveAllResult.success === true, 'dissolveAll se traduce en success: true');
  restoreFetch();
}

// A.6: checkSession() — GET con autenticado true/false.
{
  let capturedUrl = '';
  const restoreFetch = installFetchMock(async (requestedUrl) => {
    capturedUrl = String(requestedUrl);
    return new Response(
      JSON.stringify({
        success: true,
        data: { authenticated: true, user: { id: 'usr_9a8b7c6d', alias: 'FrierenElf', role: 'master', clanId: 'cln_astral', clanName: 'Eruditos Astrales' } },
      }),
      { status: 200, headers: { 'Content-Type': 'application/json' } },
    );
  });

  const sessionResult = await checkSession();
  assertCondition(capturedUrl === '/api/v1/auth/session', 'checkSession apunta a GET /api/v1/auth/session');
  assertCondition(
    sessionResult.success === true && sessionResult.data?.authenticated === true && sessionResult.data?.user?.role === 'master',
    'checkSession expone authenticated y user para el store reactivo (Tarea 4.2)',
  );
  restoreFetch();
}

// A.7: fetchAuditLog() — query string de paginación y filtros (Endpoint 6).
{
  let capturedUrl = '';
  const restoreFetch = installFetchMock(async (requestedUrl) => {
    capturedUrl = String(requestedUrl);
    return new Response(
      JSON.stringify({
        success: true,
        data: {
          items: [{ id: 142, actorAlias: 'HeiterElSabio', actionType: 'SIGN_VALIDATE', targetEntityType: 'spell', targetEntityId: 'spl_llamas_frieren', justification: 'Motivo solemne.', createdAt: '2026-09-12T14:22:10Z' }],
          pagination: { page: 1, limit: 25, totalItems: 142, totalPages: 6 },
        },
      }),
      { status: 200, headers: { 'Content-Type': 'application/json' } },
    );
  });

  const auditResult = await fetchAuditLog({ page: 2, limit: 25, clanId: 'cln_astral' });
  assertCondition(capturedUrl.startsWith('/api/v1/audit/log'), 'fetchAuditLog apunta a GET /api/v1/audit/log');
  assertCondition(capturedUrl.includes('page=2') && capturedUrl.includes('limit=25'), 'La paginación viaja en el query string');
  assertCondition(capturedUrl.includes('clanId=cln_astral'), 'El filtro de clan viaja en el query string (RF-08.2)');
  assertCondition(
    auditResult.success === true && auditResult.data?.pagination?.totalPages === 6 && auditResult.data?.items?.length === 1,
    'La página de bitácora llega desenvuelta con items y pagination',
  );
  restoreFetch();
}

// A.8: Corte de red y JSON corrupto — objetos controlados, jamás excepciones.
{
  const restoreDead = installFetchMock(async () => { throw new TypeError('fetch failed'); });
  let deadResult = null;
  try {
    deadResult = await checkSession();
  } catch {
    deadResult = null;
  }
  assertCondition(deadResult !== null && deadResult.success === false, 'El corte de red NO lanza excepción al llamador');
  assertCondition(
    deadResult?.error?.code === 'MANA_STREAM_INTERRUPTED',
    "El corte de red produce 'MANA_STREAM_INTERRUPTED' (contrato del proyecto)",
  );
  restoreDead();

  const restoreCorrupt = installFetchMock(async () => new Response('<html>no soy json</html>', { status: 200 }));
  const corruptResult = await checkSession();
  assertCondition(
    corruptResult?.success === false && typeof corruptResult?.error?.code === 'string',
    'JSON corrupto retorna error controlado con código',
  );
  restoreCorrupt();
}

// A.9: Códigos de error exportados para los componentes de interfaz.
assertCondition(
  typeof AUTH_ERROR_CODES === 'object' && AUTH_ERROR_CODES !== null,
  'AUTH_ERROR_CODES está exportado para accessModal y recoveryModal (Tareas 4.3/4.4)',
);
if (typeof AUTH_ERROR_CODES === 'object' && AUTH_ERROR_CODES !== null) {
  assertCondition(AUTH_ERROR_CODES.invalidCredentials === 'INVALID_CREDENTIALS', 'AUTH_ERROR_CODES.invalidCredentials expone el 401');
  assertCondition(AUTH_ERROR_CODES.rateLimited === 'RATE_LIMITED', 'AUTH_ERROR_CODES.rateLimited expone el 429');
  assertCondition(AUTH_ERROR_CODES.identityClaimed === 'IDENTITY_ALREADY_CLAIMED', 'AUTH_ERROR_CODES.identityClaimed expone el 409');
  assertCondition(AUTH_ERROR_CODES.networkError === 'MANA_STREAM_INTERRUPTED', 'AUTH_ERROR_CODES.networkError expone el corte de maná');
}

// --- Centinela final: cero excepciones no capturadas en toda la ejecución ---
console.log('\n== CENTINELA DE CONSOLA ==');
assertCondition(uncaughtErrors === 0, `Excepciones no capturadas en consola: ${uncaughtErrors} (el criterio exige 0)`);

// --- Resumen final ---
console.log('\n== RESUMEN ==');
console.log(`Asertos superados: ${assertsPassed}`);
console.log(`Asertos fallidos:  ${assertsFailed}`);

if (assertsFailed === 0) {
  console.log("\nRESULTADO: EXITO — La Tarea 4.1 cumple su criterio 'Hecho cuando'.");
  process.exit(0);
}

console.log('\nRESULTADO: FALLO — Corregir los asertos marcados con [FALLA].');
process.exit(1);
