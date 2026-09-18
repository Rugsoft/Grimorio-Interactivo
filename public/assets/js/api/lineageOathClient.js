/**
 * lineageOathClient.js — Cliente HTTP nativo del Juramento de Linaje
 * (SPEC-09, Tarea 3.1).
 *
 * Tarea 3.1 (TASKS-09): funciones nativas fetchOathCatalog(), sealOath(lineageId)
 * y retainRoute(route) contra los tres endpoints de la ceremonia (plan §2.2),
 * con gestión de cookies automáticas (credentials: 'same-origin').
 *
 * Constitución:
 *   - Artículo I (Dogma Vanilla): fetch nativo; cero axios/librerías HTTP.
 *   - Artículo V: funciones en inglés camelCase; comentarios en castellano.
 *
 * Contratos (plan §2.2, Endpoints 1-3):
 *   - Sobre estándar del backend: { success: true, data } | { success: false, error }.
 *   - Toda función retorna SIEMPRE un veredicto controlado { success, status,
 *     data | error } — jamás lanza hacia la vista de la ceremonia (RF-03.2:
 *     el fallo solemne deja la ceremonia operativa para el reintento).
 *   - El objeto porta result.status con el código HTTP real para que la
 *     vista distinga 401 (reautenticar) de 403 (conflicto solemne) de
 *     400 (canon inválido).
 *   - El 204 de la retención viaja como { success: true, status: 204 }
 *     sin cuerpo que negociar.
 *   - Las cookies de sesión viajan solas: credentials 'same-origin'.
 */

/** Códigos de error que la ceremonia del juramento necesita conocer. */
export const LINEAGE_OATH_ERROR_CODES = Object.freeze({
  /** Linaje ajeno al canon de 8 (400). */
  invalidLineage: 'INVALID_LINEAGE',
  /** La cuenta ya porta un linaje distinto (403). */
  lineageOathConflict: 'LINEAGE_OATH_CONFLICT',
  /** El actor está exento por privilegio (Admin Supremo, 403). */
  oathForbiddenRole: 'OATH_FORBIDDEN_ROLE',
  /** Sesión caducada: reautenticar y la ceremonia reaparece (401, RF-03.2). */
  sessionExpired: 'SESSION_EXPIRED',
  /** Retención activa: el santuario aguarda el juramento (403). */
  lineageOathRequired: 'LINEAGE_OATH_REQUIRED',
  /** Corte de red o servidor inalcanzable (contrato del proyecto). */
  networkError: 'MANA_STREAM_INTERRUPTED',
});

/** Base de la API (plan §2.2). */
const API_BASE = '/api/v1';

/**
 * Envoltura pacífica: ejecuta la petición y convierte CUALQUIER fallo
 * (red, JSON corrupto, sobre inesperado) en el error controlado del
 * proyecto. Las cookies viajan siempre con credentials 'same-origin'.
 *
 * @param {string}   requestUrl URL completa a solicitar.
 * @param {object}   [init]     Opciones de fetch (method, body...).
 * @param {object|null} [body]  Payload JSON a serializar (null = sin cuerpo).
 * @returns {Promise<object>} Veredicto { success, status, data | error } nunca lanzado.
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

    // 204 No Content: retenida o descartada, sin cuerpo que negociar.
    if (response.status === 204) {
      return { success: true, status: 204 };
    }

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

    // Estado de error sin sobre legible: traducción según el código HTTP.
    return {
      success: false,
      status: response.status,
      error: {
        code: LINEAGE_OATH_ERROR_CODES.networkError,
        message: `El santuario respondió con el estado ${response.status}.`,
      },
    };
  } catch {
    // Corte de red real: objeto controlado, jamás excepción al llamador.
    return {
      success: false,
      status: 0,
      error: {
        code: LINEAGE_OATH_ERROR_CODES.networkError,
        message: 'La corriente de maná se ha interrumpido.',
        recoveryAction: 'RETRY',
      },
    };
  }
}

/**
 * GET /api/v1/lineage/oath-catalog — El canon ceremonial completo con el
 * estado de la cuenta (RF-02.1, RF-02.2): las 8 fichas heráldicas en el
 * orden de la rejilla y `accountState` ('pilgrim' | 'lineaged').
 */
export async function fetchOathCatalog() {
  return requestJson(`${API_BASE}/lineage/oath-catalog`, { method: 'GET' });
}

/**
 * POST /api/v1/lineage/oath — Sella el juramento (RF-03.1, RF-03.3).
 * El backend resuelve la idempotencia y la serialización; la respuesta
 * porta `sealedNow` y la `retainedRoute` consumida de la sesión (null si
 * el retorno es al portal de inicio).
 *
 * @param {string} lineageId Clave canónica del linaje jurado (ej. 'primordialFlame').
 */
export async function sealOath(lineageId) {
  return requestJson(`${API_BASE}/lineage/oath`, { method: 'POST' }, { lineageId });
}

/**
 * POST /api/v1/lineage/retained-route — Registra la intención de ruta del
 * interceptor (RF-05.3). 204 con la ruta retenida; las externas el backend
 * las descarta en silencio. El fallo es inocuo: jamás interrumpe la
 * navegación del peregrino hacia la ceremonia.
 *
 * @param {string} route Hash interno de la vista solicitada (ej. '#/creador').
 */
export async function retainRoute(route) {
  return requestJson(`${API_BASE}/lineage/retained-route`, { method: 'POST' }, { route });
}
