/**
 * elementalMatrixClient.js — Cliente HTTP nativo de la Matriz Elemental
 * (SPEC-06, Tarea 5.1).
 *
 * Métodos canónicos (plan técnico):
 *   - fetchMatrixGraph(): GET  /api/v1/elements/matrix          (RF-01.1)
 *   - fetchReactionsForElement(element): GET
 *       /api/v1/elements/reactions/{element}                    (RF-01.2)
 *   - resolveCombo(data): POST /api/v1/elements/resolve-combo   (RF-04.1)
 *
 * Todos los métodos retornan SIEMPRE un sobre — jamás lanzan hacia las
 * vistas (patrón de spellClient): { success: true, data } |
 * { success: false, error: { code, message } }. Los 404/400 del backend
 * viajan como sobres de error controlado con su código canónico.
 *
 * Constitución:
 *   - Artículo I (Dogma Vanilla): fetch nativo; cero axios/librerías HTTP.
 *   - Artículo V: funciones en inglés camelCase; comentarios en castellano.
 */

/** Códigos de error que las vistas del Códice (RF-01, RF-04) necesitan conocer. */
export const ELEMENTAL_API_ERROR_CODES = Object.freeze({
  /** La corriente de maná (red/servidor) se interrumpió. */
  networkError: 'ELEMENTAL_STREAM_INTERRUPTED',
  /** La afinidad solicitada no pertenece al canon (404 místico). */
  elementNotFound: 'ELEMENT_NOT_FOUND',
  /** El payload del combo fue rechazado por la validación del santuario. */
  invalidCombo: 'ELEMENTAL_COMBO_REJECTED',
});

/** Base de la API (idéntica al resto de clientes del santuario). */
const API_BASE = '/api/v1';

/**
 * Envoltura pacífica: ejecuta la petición y convierte CUALQUIER fallo
 * (red, JSON corrupto, estado de error sin sobre) en el error controlado.
 * Los sobres válidos del backend se propagan intactos (200, 400, 404...).
 *
 * @param {Function} fetchImpl Implementación de fetch (nativa o inyectada).
 * @param {string} requestUrl URL completa a solicitar.
 * @param {object} [init] Opciones extra de fetch (method, body...).
 * @returns {Promise<object>} Sobre { success, data | error } nunca lanzado.
 */
async function requestJson(fetchImpl, requestUrl, init = {}) {
  try {
    const response = await fetchImpl(requestUrl, {
      headers: { Accept: 'application/json', ...(init.body ? { 'Content-Type': 'application/json' } : {}) },
      credentials: 'same-origin', // Sesión PHP nativa (SPEC-03).
      ...init,
    });

    // El cuerpo puede no ser JSON (proxy, HTML de error): parseo defendido.
    let payload = null;
    try {
      payload = await response.json();
    } catch {
      payload = null;
    }

    // Sobre válido del backend: se propaga tal cual (éxito o error 4xx/5xx).
    if (payload !== null && typeof payload === 'object' && 'success' in payload) {
      return payload;
    }

    // Respuesta 2xx con cuerpo ilegible: error controlado de corriente.
    if (response.ok) {
      return {
        success: false,
        error: {
          code: ELEMENTAL_API_ERROR_CODES.networkError,
          message: 'La corriente de maná entregó un pergamino ilegible.',
        },
      };
    }

    // Estado de error sin sobre legible: se traduce según el código HTTP.
    return {
      success: false,
      error: {
        code: response.status === 404
          ? ELEMENTAL_API_ERROR_CODES.elementNotFound
          : ELEMENTAL_API_ERROR_CODES.networkError,
        message: `El santuario respondió con el estado ${response.status}.`,
      },
    };
  } catch {
    // Corte de red real (fetch lanza): error controlado sin propagación.
    return {
      success: false,
      error: {
        code: ELEMENTAL_API_ERROR_CODES.networkError,
        message: 'La corriente de maná hacia el Códice se interrumpió.',
      },
    };
  }
}

/**
 * Crea el cliente HTTP de la Matriz Elemental.
 *
 * @param {object} [options]
 *   - fetch: implementación inyectable (arneses); por defecto, el fetch
 *     nativo del navegador.
 * @returns {object} API: fetchMatrixGraph, fetchReactionsForElement,
 *   resolveCombo.
 */
export function createElementalMatrixClient(options = {}) {
  const fetchImpl = options.fetch ?? fetch.bind(globalThis);
  // Base sobreescribible (arneses en Node y despliegues alternativos);
  // en el navegador la relativa /api/v1 es la canónica.
  const apiBase = options.baseUrl ?? API_BASE;

  return {
    /**
     * Endpoint 1 (RF-01.1): grafo completo del Códice — ocho elementos
     * con su heráldica y todas las reacciones canónicas.
     */
    fetchMatrixGraph() {
      return requestJson(fetchImpl, `${apiBase}/elements/matrix`, { method: 'GET' });
    },

    /**
     * Endpoint 2 (RF-01.2): aristas reactivas de un glifo — reacciones
     * duales y catalizadoras en las que participa el elemento.
     *
     * @param {string} element Identificador canónico del elemento.
     */
    fetchReactionsForElement(element) {
      const safeElement = encodeURIComponent(String(element ?? ''));
      return requestJson(fetchImpl, `${apiBase}/elements/reactions/${safeElement}`, { method: 'GET' });
    },

    /**
     * Endpoint 3 (RF-04.1): resolución autoritativa de un combo —
     * { activeAura, incomingSpell, stunlockImmune } → veredicto de once
     * claves idéntico al del resolutor cliente (paridad byte a byte).
     *
     * @param {object} data Payload canónico del combo.
     */
    resolveCombo(data) {
      return requestJson(fetchImpl, `${apiBase}/elements/resolve-combo`, {
        method: 'POST',
        body: JSON.stringify(data ?? {}),
      });
    },
  };
}
