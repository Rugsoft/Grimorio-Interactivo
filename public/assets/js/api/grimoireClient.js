/**
 * grimoireClient.js — Cliente HTTP nativo del Simulador de Grimorio.
 *
 * Tarea 4.1 (TASKS-05): funciones nativas fetchSpells(params) y
 * fetchSpellDetail(id) contra los Endpoints 1-2 del plan 2.1, con
 * cabeceras JSON y cookies automáticas (credentials: 'same-origin').
 *
 * Constitución:
 *   - Artículo I (Dogma Vanilla): fetch nativo; cero axios/librerías HTTP.
 *   - Artículo V: funciones en inglés camelCase; comentarios en castellano.
 *
 * Contratos (plan 2.1):
 *   - Sobre estándar del backend: { success: true, data } | { success: false, error }.
 *   - Toda función retorna SIEMPRE un objeto controlado { success, status,
 *     data | error } — jamás lanza hacia las vistas.
 *   - result.status porta el código HTTP real (200/400/401/404) para que
 *     los componentes reaccionen (p. ej., 401 → modal de acceso en essays).
 *   - fetch inyectable por opciones: los arneses falsan la red sin
 *     navegador (Dogma Vanilla: la inyección es del llamador, no un framework).
 */

/** Códigos de error que la vista del simulador necesita conocer. */
export const GRIMOIRE_ERROR_CODES = Object.freeze({
  /** Ensayos sin sesión autenticada (401). */
  unauthenticated: 'UNAUTHENTICATED',
  /** Conjuro inexistente o no accesible para el llamador (404). */
  spellNotFound: 'SPELL_NOT_FOUND',
  /** Parámetros de consulta fuera del canon (400). */
  invalidQueryParams: 'INVALID_QUERY_PARAMS',
  /** Corte de red o servidor inalcanzable (contrato del proyecto). */
  networkError: 'MANA_STREAM_INTERRUPTED',
});

/** Base de la API (plan 2.1). */
const API_BASE = '/api/v1';

/**
 * Envoltura pacífica: ejecuta la petición y convierte CUALQUIER fallo
 * (red, JSON corrupto, sobre inesperado) en el error controlado del
 * proyecto. Las cookies viajan siempre con credentials 'same-origin'.
 *
 * @param {Function} fetchImpl  Implementación de fetch (nativa o del arnés).
 * @param {string}   requestUrl URL completa a solicitar.
 * @returns {Promise<object>} Sobre { success, status, data | error } nunca lanzado.
 */
async function requestJson(fetchImpl, requestUrl) {
  try {
    const response = await fetchImpl(requestUrl, {
      method: 'GET',
      headers: { Accept: 'application/json' },
      // Cookies automáticas de sesión: el navegador adjunta la cookie
      // HttpOnly en cada petición sin que JavaScript la toque jamás.
      credentials: 'same-origin',
    });

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
          code: GRIMOIRE_ERROR_CODES.networkError,
          message: 'La corriente de maná entregó un pergamino ilegible.',
        },
      };
    }

    // Estado de error sin sobre legible: traducción según el código HTTP.
    return {
      success: false,
      status: response.status,
      error: {
        code: GRIMOIRE_ERROR_CODES.networkError,
        message: `El santuario respondió con el estado ${response.status}.`,
      },
    };
  } catch {
    // Corte de red real: objeto controlado, jamás excepción al llamador.
    return {
      success: false,
      status: 0,
      error: {
        code: GRIMOIRE_ERROR_CODES.networkError,
        message: 'La corriente de maná está interrumpida: el santuario no responde.',
      },
    };
  }
}

/** Serializa los parámetros definidos (omite undefined/null) como query. */
function buildQuery(params = {}) {
  const search = new URLSearchParams();
  for (const [key, value] of Object.entries(params)) {
    if (value === undefined || value === null || value === '') {
      continue; // parámetro ausente: no viaja en la query
    }
    search.set(key, String(value));
  }
  const query = search.toString();
  return query ? `?${query}` : '';
}

/**
 * Crea el cliente HTTP del grimorio.
 *
 * @param {object} [options]
 *   - fetch: implementación inyectable (por defecto, la nativa global).
 * @returns {object} cliente con fetchSpells(params) y fetchSpellDetail(id).
 */
export function createGrimoireClient(options = {}) {
  const fetchImpl = options.fetch ?? ((url, init) => fetch(url, init));

  /**
   * Endpoint 1 (plan 2.1): catálogo paginado del tomo.
   *
   * @param {object} [params] - circle (1-5), element (afinidad canónica),
   *   mode ('canonical' | 'essays'), page (≥ 1), limit (1-50).
   * @returns {Promise<object>} { success, status, data | error }.
   *   En modo canonical es público; mode=essays exige sesión (401 si no).
   */
  async function fetchSpells(params = {}) {
    const query = buildQuery(params);
    return requestJson(fetchImpl, `${API_BASE}/grimoire/spells${query}`);
  }

  /**
   * Endpoint 2 (plan 2.1): detalle litúrgico individual.
   *
   * @param {string} id - identificador del conjuro.
   * @returns {Promise<object>} { success, status, data | error }.
   *   Los borradores ajenos responden 404 (aislamiento, Artículo III).
   */
  async function fetchSpellDetail(id) {
    return requestJson(fetchImpl, `${API_BASE}/grimoire/spells/${encodeURIComponent(String(id))}`);
  }

  return { fetchSpells, fetchSpellDetail };
}
