/**
 * dominionClient.js — Cliente HTTP nativo del Salón de los Linajes y del corte
 * dominical.
 *
 * Tarea 5.1 (TASKS-07): funciones nativas contra los Endpoints 10 a 12 del
 * plan técnico (SPEC-07, plan 2.2).
 *
 *   Endpoint 10 · fetchLineages()          GET  /api/v1/lineages
 *   Endpoint 11 · fetchLeaderboard()       GET  /api/v1/dominion/leaderboard
 *   Endpoint 12 · closeCycle(secret)       POST /api/v1/dominion/cron-cycle-close
 *
 * Constitución:
 *   - Artículo I (Dogma Vanilla): fetch nativo; cero axios ni librerías HTTP.
 *   - Artículo II: el cliente JAMÁS calcula PDA, ni ordena podios, ni dirime
 *     desempates — el Salón es la autoridad y el cliente solo lo contempla.
 *   - Artículo V: métodos en inglés camelCase; narrativa en castellano.
 *
 * Contrato de respuesta (idéntico al resto de clientes del santuario):
 *   - Toda función retorna SIEMPRE un objeto controlado
 *     { success, status, data | error } — jamás lanza hacia las vistas.
 *   - Las tres rutas son de LECTURA PÚBLICA (RF-06.1: el Salón se contempla
 *     sin vínculo arcano), salvo el corte dominical, que exige el sello del
 *     custodio y falla cerrado.
 *
 * Credenciales:
 *   - La sesión canónica viaja en la cookie HttpOnly de SPEC-03, adjunta sola
 *     con `credentials: 'same-origin'` y jamás tocada por JavaScript.
 *   - Se admite además un token opcional `Bearer`, ADITIVO y nunca
 *     sustitutivo, para custodios y arneses que operan sin navegador.
 */

/** Códigos de error canónicos del Salón del Dominio (SPEC-07). */
export const DOMINION_ERROR_CODES = Object.freeze({
  /** Sin vínculo arcano activo (401). */
  unauthenticated: 'UNAUTHENTICATED',
  /** Ruta o estandarte inexistente (404). */
  notFound: 'DOMINION_NOT_FOUND',
  /** El corte dominical exige el sello del custodio en la cabecera (401). */
  cronSecretRequired: 'CRON_SECRET_REQUIRED',
  /** El sello presentado no autoriza la invocación (403). */
  cronSecretInvalid: 'CRON_SECRET_INVALID',
  /** Corte de red o servidor inalcanzable (contrato del proyecto). */
  networkError: 'MANA_STREAM_INTERRUPTED',
});

/** Cabecera ceremonial que porta el sello del custodio del corte. */
export const CRON_SECRET_HEADER = 'X-Arcane-Cron-Secret';

/**
 * Leyendas ceremoniales del cliente (RNF-03), usadas SOLO cuando el sobre de
 * error llega sin mensaje legible (proxy con HTML, 502 sin cuerpo).
 */
export const DOMINION_CEREMONIAL_LEGENDS = Object.freeze({
  [DOMINION_ERROR_CODES.cronSecretRequired]: 'El corte dominical exige el sello del custodio.',
  [DOMINION_ERROR_CODES.cronSecretInvalid]: 'El sello presentado no autoriza esta invocación del corte dominical.',
  [DOMINION_ERROR_CODES.notFound]: 'Ese rincón del Salón de los Linajes no figura en los anales.',
  [DOMINION_ERROR_CODES.unauthenticated]: 'El vínculo arcano no está activo: el Santuario no reconoce tu firma.',
  [DOMINION_ERROR_CODES.networkError]: 'La corriente de maná hacia el Salón de los Linajes se ha interrumpido.',
});

/** Base de la API (idéntica al resto de clientes del santuario). */
const API_BASE = '/api/v1';

/**
 * Traduce un sobre de error (o su código) a una leyenda ceremonial legible.
 *
 * @param {object|string} result Sobre del cliente, o código de error directo.
 * @returns {string} Leyenda en noble castellano; jamás una cadena vacía.
 */
export function dominionLegendFor(result) {
  const error = typeof result === 'string' ? { code: result } : (result?.error ?? {});

  // La leyenda del santuario tiene precedencia: es la autoridad del texto.
  if (typeof error.message === 'string' && error.message.trim() !== '') {
    return error.message;
  }

  return DOMINION_CEREMONIAL_LEGENDS[error.code] ?? 'El Salón de los Linajes ha respondido con un presagio indescifrable.';
}

/**
 * Crea el cliente HTTP del Salón de los Linajes y del corte dominical.
 *
 * @param {object} [options]
 *   - fetch:       implementación inyectable (arneses); por defecto, la nativa.
 *   - baseUrl:     base sobreescribible; en el navegador /api/v1 es la canónica.
 *   - token:       credencial Bearer OPCIONAL y aditiva.
 *   - cronSecret:  sello del custodio para el corte dominical; puede
 *     sobreescribirse en cada invocación de closeCycle().
 * @returns {object} cliente con fetchLineages, fetchLeaderboard y closeCycle.
 */
export function createDominionClient(options = {}) {
  const fetchImpl = options.fetch ?? ((url, init) => fetch(url, init));
  const apiBase = options.baseUrl ?? API_BASE;
  const token = typeof options.token === 'string' && options.token.trim() !== '' ? options.token.trim() : null;
  const cronSecret = typeof options.cronSecret === 'string' && options.cronSecret.trim() !== ''
    ? options.cronSecret.trim()
    : null;

  /**
   * Envoltura pacífica: ejecuta la petición y convierte CUALQUIER fallo (red,
   * JSON ilegible, estado sin sobre) en el error controlado del proyecto.
   *
   * @param {string} requestUrl URL completa a solicitar.
   * @param {object} [init]     Opciones de fetch (method, headers...).
   * @returns {Promise<object>} Sobre { success, status, data | error } nunca lanzado.
   */
  async function requestJson(requestUrl, init = {}) {
    const requestInit = {
      ...init,
      headers: {
        Accept: 'application/json',
        // Credencial Bearer opcional y aditiva (ver cabecera del módulo).
        ...(token !== null ? { Authorization: `Bearer ${token}` } : {}),
        ...(init.headers ?? {}),
      },
      credentials: 'same-origin', // Cookie HttpOnly de sesión (SPEC-03).
    };

    try {
      const response = await fetchImpl(requestUrl, requestInit);

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

      // Estado sin sobre legible: traducción homogénea según el código HTTP.
      return {
        success: false,
        status: response.status,
        error: {
          code: response.status === 401
            ? DOMINION_ERROR_CODES.cronSecretRequired
            : response.status === 404
              ? DOMINION_ERROR_CODES.notFound
              : DOMINION_ERROR_CODES.networkError,
          message: `El Salón de los Linajes respondió con el estado ${response.status}.`,
          recoveryAction: 'RETRY',
        },
      };
    } catch {
      // Corte de red real: objeto controlado, jamás excepción al llamador.
      return {
        success: false,
        status: 0,
        error: {
          code: DOMINION_ERROR_CODES.networkError,
          message: DOMINION_CEREMONIAL_LEGENDS[DOMINION_ERROR_CODES.networkError],
          recoveryAction: 'RETRY',
        },
      };
    }
  }

  return {
    /**
     * Endpoint 10 (RF-02.1, RF-02.2): los ocho Linajes Canónicos con su
     * elemento rector, glifo rúnico, color de estandarte y marco heráldico.
     *
     * @returns {Promise<object>} 200 con la lista de los ocho linajes.
     */
    fetchLineages() {
      return requestJson(`${apiBase}/lineages`, { method: 'GET' });
    },

    /**
     * Endpoint 11 (RF-06.1, RF-06.2): Salón del Dominio completo.
     *
     * @returns {Promise<object>} 200 con {weeklyRanking, historicalRanking,
     *   currentRegentClan, hallOfFameWeeks} — el podio semanal llega ordenado
     *   por PDA descendente y cada estandarte porta su censo de adeptos.
     */
    fetchLeaderboard() {
      return requestJson(`${apiBase}/dominion/leaderboard`, { method: 'GET' });
    },

    /**
     * Endpoint 12 (RF-04.1 a RF-04.3): corte dominical y proclamación del
     * Clan Regente. Idempotente: un segundo latido de la misma semana
     * devuelve el acta ya inmortalizada sin volver a plegar contadores.
     *
     * @param {string} [secret] Sello del custodio; si falta, se usa el
     *   declarado al crear el cliente. Sin sello, el santuario falla cerrado.
     * @returns {Promise<object>} 200 con {cycle, regentClan}, 401
     *   CRON_SECRET_REQUIRED si la cabecera viaja vacía, 403
     *   CRON_SECRET_INVALID si el sello no es el del custodio.
     */
    closeCycle(secret) {
      const presented = typeof secret === 'string' && secret.trim() !== '' ? secret.trim() : cronSecret;

      return requestJson(`${apiBase}/dominion/cron-cycle-close`, {
        method: 'POST',
        // El sello viaja SIEMPRE como cabecera; jamás en el cuerpo ni en la
        // query, para que no quede escrito en bitácoras de acceso.
        headers: { ...(presented !== null ? { [CRON_SECRET_HEADER]: presented } : {}) },
      });
    },
  };
}
