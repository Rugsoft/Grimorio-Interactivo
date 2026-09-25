/**
 * authClient.js — Cliente HTTP nativo de autenticación, sesiones y auditoría.
 *
 * Tarea 4.1 (TASKS-03): funciones nativas consecrate(data), bind(identity,
 * passphrase), dissolve(), dissolveAll(), checkSession() y fetchAuditLog(params)
 * con gestión de cookies automáticas (credentials: 'same-origin').
 *
 * Constitución:
 *   - Artículo I (Dogma Vanilla): fetch nativo; cero axios/librerías HTTP.
 *   - Artículo V: funciones en inglés camelCase; comentarios en castellano.
 *
 * Contratos (plan 2.2, Endpoints 1-5):
 *   - Sobre estándar del backend: { success: true, data } | { success: false, error }.
 *   - Toda función retorna SIEMPRE un objeto controlado { success, status,
 *     data | error } — jamás lanza hacia las vistas o el store reactivo.
 *   - El objeto resultante porta result.status con el código HTTP real para
 *     que los componentes (Tareas 4.3/4.4) reaccionen a 401/409/429.
 *   - Las cookies de sesión viajan solas: credentials 'same-origin' en
 *     todas las peticiones (el backend las emite con HttpOnly, jamás
 *     accesibles por JavaScript).
 */

/** Códigos de error que los componentes de acceso y recuperación necesitan conocer. */
export const AUTH_ERROR_CODES = Object.freeze({
  /** Credenciales no coincidentes (401 neutro anti-enumeración). */
  invalidCredentials: 'INVALID_CREDENTIALS',
  /** Procedencia congelada tras 5 fallos (429, RF-03.2). */
  rateLimited: 'RATE_LIMITED',
  /** Identidad ya reclamada en la consagración (409 neutro). */
  identityClaimed: 'IDENTITY_ALREADY_CLAIMED',
  /** Datos de registro fuera del canon (alias, frase, linaje fantasma). */
  invalidRegistration: 'INVALID_REGISTRATION_DATA',
  /** Pergamino de restablecimiento inexistente, consumido o caducado. */
  recoveryTokenInvalid: 'RECOVERY_TOKEN_INVALID',
  /** Sin vínculo activo que disolver (401). */
  noActiveSession: 'NO_ACTIVE_SESSION',
  /** Parámetros de consulta de la bitácora fuera del canon (400). */
  invalidQueryParams: 'INVALID_QUERY_PARAMS',
  /** Corte de red o servidor inalcanzable (contrato del proyecto). */
  networkError: 'MANA_STREAM_INTERRUPTED',
});

/** Base de la API (plan 2.2). */
const API_BASE = '/api/v1';

/**
 * Envoltura pacífica: ejecuta la petición y convierte CUALQUIER fallo
 * (red, JSON corrupto, sobre inesperado) en el error controlado del
 * proyecto. Las cookies viajan siempre con credentials 'same-origin'.
 *
 * @param {string}   requestUrl URL completa a solicitar.
 * @param {object}   [init]     Opciones de fetch (method, body...).
 * @param {object|null} [body]  Payload JSON a serializar (null = sin cuerpo).
 * @returns {Promise<object>} Sobre { success, status, data | error } nunca lanzado.
 */
async function requestJson(requestUrl, init = {}, body = null) {
  const requestInit = {
    ...init,
    headers: {
      Accept: 'application/json',
      ...(body !== null ? { 'Content-Type': 'application/json' } : {}),
      ...(init.headers ?? {}),
    },
    // Cookies automáticas de sesión: el navegador adjunta grimorio_session
    // (HttpOnly) en cada petición sin que JavaScript la toque jamás.
    credentials: 'same-origin',
  };
  if (body !== null) {
    requestInit.body = JSON.stringify(body);
  }

  try {
    const response = await fetch(requestUrl, requestInit);

    // El cuerpo puede no ser JSON (proxy, HTML de error): parseo defendido.
    let payload = null;
    try {
      payload = await response.json();
    } catch {
      payload = null;
    }

    // Sobre válido del backend: se propaga con el estado HTTP real adjunto.
    if (payload !== null && typeof payload === 'object' && 'success' in payload) {
      return { status: response.status, ...payload };
    }

    // Respuesta 2xx con cuerpo ilegible: error controlado de corriente.
    if (response.ok) {
      return {
        success: false,
        status: response.status,
        error: {
          code: AUTH_ERROR_CODES.networkError,
          message: 'La corriente de maná entregó un pergamino ilegible.',
        },
      };
    }

    // Estado de error sin sobre legible: traducción según el código HTTP.
    return {
      success: false,
      status: response.status,
      error: {
        code: AUTH_ERROR_CODES.networkError,
        message: `El santuario respondió con el estado ${response.status}.`,
      },
    };
  } catch {
    // Corte de red real: objeto controlado, jamás excepción al llamador.
    return {
      success: false,
      status: 0,
      error: {
        code: AUTH_ERROR_CODES.networkError,
        message: 'La corriente de maná se ha interrumpido.',
        recoveryAction: 'RETRY',
      },
    };
  }
}

/**
 * POST /api/v1/auth/consecrate — Consagración de nuevo miembro (RF-01).
 * El clan es obligatorio (RF-01.1); el rol técnico editor lo asigna el backend.
 *
 * @param {object} data { alias, email, passphrase, clanId }.
 */
export async function consecrate(data) {
  return requestJson(`${API_BASE}/auth/consecrate`, { method: 'POST' }, data);
}

/**
 * POST /api/v1/auth/bind — Renovación de vínculo / inicio de sesión (RF-02, RF-03).
 * La identidad puede ser alias o correo (plan Endpoint 2).
 *
 * @param {string} identity   Alias o correo del iniciado.
 * @param {string} passphrase Frase de paso.
 */
export async function bind(identity, passphrase) {
  return requestJson(`${API_BASE}/auth/bind`, { method: 'POST' }, { identity, passphrase });
}

/**
 * POST /api/v1/auth/dissolve — Cierra la sesión del dispositivo actual (RF-02.4).
 */
export async function dissolve() {
  return requestJson(`${API_BASE}/auth/dissolve`, { method: 'POST' });
}

/**
 * POST /api/v1/auth/dissolve-all — Cierra TODAS las sesiones del usuario
 * en todos sus dispositivos (RF-02.4, revocación en base de datos).
 */
export async function dissolveAll() {
  return requestJson(`${API_BASE}/auth/dissolve-all`, { method: 'POST' });
}

/**
 * GET /api/v1/auth/session — Verificación de sesión activa (plan Endpoint 4).
 * El sobre porta data.authenticated y data.user (null si es anónimo):
 * materia prima del store reactivo (Tarea 4.2).
 */
export async function checkSession() {
  return requestJson(`${API_BASE}/auth/session`, { method: 'GET' });
}

/**
 * POST /api/v1/auth/renounce-account — Renuncia al Vínculo (RF-09.1,
 * cierre de SPEC-03). Derecho al olvido del titular de la cookie: purga
 * irreversible de datos personales con preservación del legado anónimo
 * (ACC_LINK_RENOUNCED en la bitácora). El Panel del Adepto (SPEC-12,
 * Tarea 5.3) conduce a esta superficie canónica tras su confirmación
 * solemne propia: el panel muestra la puerta, jamás la palanca.
 *
 * @returns {Promise<object>} Sobre { success, status, data | error }.
 */
export async function renounceAccount() {
  return requestJson(`${API_BASE}/auth/renounce-account`, { method: 'POST' });
}

/**
 * Serializa parámetros en query string (URLSearchParams nativo), omitiendo
 * valores vacíos o nulos.
 *
 * @param {Record<string, string|number>} params Parámetros de consulta.
 * @returns {string} '' si no hay parámetros; '?a=1&b=2' en caso contrario.
 */
function buildQueryString(params) {
  const searchParams = new URLSearchParams();
  for (const [paramKey, paramValue] of Object.entries(params)) {
    if (paramValue !== null && paramValue !== undefined && paramValue !== '') {
      searchParams.set(paramKey, String(paramValue));
    }
  }
  const queryString = searchParams.toString();
  return queryString === '' ? '' : `?${queryString}`;
}

/**
 * GET /api/v1/audit/log — Bitácora pública de auditoría (RF-08.2, plan Endpoint 6).
 *
 * @param {object} [params] { page, limit, clanId, targetEntityType, actorUserId }.
 */
export async function fetchAuditLog(params = {}) {
  const queryString = buildQueryString({
    page: params.page,
    limit: params.limit,
    clanId: params.clanId,
    targetEntityType: params.targetEntityType,
    actorUserId: params.actorUserId,
  });

  return requestJson(`${API_BASE}/audit/log${queryString}`, { method: 'GET' });
}
