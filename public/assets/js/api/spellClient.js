/**
 * spellClient.js — Cliente HTTP nativo de la API del Grimorio (Tarea 3.3).
 *
 * Todos los clientes retornan SIEMPRE un objeto de resultado — jamás lanzan
 * hacia las vistas (criterio T3.3: sin excepciones no capturadas en consola).
 *
 * Constitución:
 *   - Artículo I: fetch nativo; cero axios/librerías HTTP.
 *   - Artículo V: funciones en inglés camelCase; comentarios en castellano.
 *
 * Contratos:
 *   - Sobre estándar del backend: { success: true, data } | { success: false, error }.
 *   - Corte de red / respuesta ilegible: { success: false,
 *     error: { code: 'MANA_STREAM_INTERRUPTED', ... } } (plan 4.1, RF-06.3).
 *   - Query string del catálogo según plan técnico 3 (query, schools CSV,
 *     maxMana, includeExperimental, offset, limit).
 */

/** Códigos de error que las vistas de rescate (RF-06) necesitan conocer. */
export const API_ERROR_CODES = Object.freeze({
  /** La corriente de maná (red/servidor) se interrumpió. */
  networkError: 'MANA_STREAM_INTERRUPTED',
  /** El pergamino solicitado no existe (404 místico). */
  scrollLost: 'SCROLL_LOST_IN_AETHER',
});

/** Base de la API (plan técnico 3). */
const API_BASE = '/api/v1';

/**
 * Envoltura pacífica: ejecuta la petición y convierte CUALQUIER fallo
 * (red, JSON corrupto, sobre inesperado) en el error controlado.
 *
 * @param {string} requestUrl URL completa a solicitar.
 * @returns {Promise<object>} Sobre { success, data | error } nunca lanzado.
 */
async function requestJson(requestUrl) {
  try {
    const response = await fetch(requestUrl, {
      headers: { Accept: 'application/json' },
      credentials: 'same-origin', // Sesión PHP nativa (SPEC-03 futura).
    });

    // El cuerpo puede no ser JSON (proxy, HTML de error): parseo defendido.
    let payload = null;
    try {
      payload = await response.json();
    } catch {
      payload = null;
    }

    // Sobre válido del backend: se propaga tal cual (200, 400, 404, 500...).
    if (payload !== null && typeof payload === 'object' && 'success' in payload) {
      return payload;
    }

    // Respuesta 2xx con cuerpo ilegible: error controlado de corriente.
    if (response.ok) {
      return {
        success: false,
        error: {
          code: API_ERROR_CODES.networkError,
          message: 'La corriente de maná entregó un pergamino ilegible.',
        },
      };
    }

    // Estado de error sin sobre legible: se traduce según el código HTTP.
    return {
      success: false,
      error: {
        code: response.status === 404 ? API_ERROR_CODES.scrollLost : API_ERROR_CODES.networkError,
        message: `El santuario respondió con el estado ${response.status}.`,
      },
    };
  } catch {
    // Corte de red real (criterio literal de la Tarea 3.3).
    return {
      success: false,
      error: {
        code: API_ERROR_CODES.networkError,
        message: 'La corriente de maná se ha interrumpido.',
        recoveryAction: 'RETRY',
      },
    };
  }
}

/**
 * Serializa un objeto de parámetros en query string (URLSearchParams nativo).
 * @param {Record<string, string|number>} params Parámetros del plan 3.
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
 * GET /api/v1/portal/featured — los 3 destacados o Pergaminos Primordiales (RF-01).
 * @param {string} [overrideUrl] URL alternativa (pruebas de corte de red).
 */
export async function fetchFeatured(overrideUrl) {
  return requestJson(overrideUrl ?? `${API_BASE}/portal/featured`);
}

/**
 * GET /api/v1/spells — catálogo paginado y filtrado (RF-03).
 *
 * @param {object} catalogParams Parámetros del contrato del plan 3:
 *   query, schools (array), maxMana, includeExperimental, offset, limit.
 */
export async function fetchSpells(catalogParams = {}) {
  // Normalización de escuelas: el contrato admite array (['evocation']) o
  // CSV ya serializado ('evocation,abjuration', como lo envía la vista).
  let schoolsCsv;
  if (Array.isArray(catalogParams.schools)) {
    schoolsCsv = catalogParams.schools.join(',');
  } else if (typeof catalogParams.schools === 'string') {
    schoolsCsv = catalogParams.schools;
  }

  const queryString = buildQueryString({
    query: catalogParams.query,
    // Escuelas como CSV (plan 3): evocation,abjuration.
    schools: schoolsCsv,
    maxMana: catalogParams.maxMana,
    // Bandera Art. III: admite booleano (true) o numérico (1) del contrato.
    includeExperimental: catalogParams.includeExperimental ? 1 : undefined,
    offset: catalogParams.offset,
    limit: catalogParams.limit,
  });

  return requestJson(`${API_BASE}/spells${queryString}`);
}

/**
 * GET /api/v1/spells/{slug} — ficha completa del hechizo (RF-04, RF-06.2).
 * @param {string} spellSlug Slug del pergamino buscado.
 */
export async function fetchSpellBySlug(spellSlug) {
  return requestJson(`${API_BASE}/spells/${encodeURIComponent(spellSlug)}`);
}

/**
 * GET /api/v1/clans/preview — Salón de Linajes en solo lectura (RF-02.2).
 */
export async function fetchClansPreview() {
  return requestJson(`${API_BASE}/clans/preview`);
}
