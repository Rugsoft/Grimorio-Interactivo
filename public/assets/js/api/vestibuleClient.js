/**
 * vestibuleClient.js — Cliente HTTP nativo del Vestíbulo de las Hermandades
 * (SPEC-10, Tarea 4.1).
 *
 * Tarea 4.1 (TASKS-10): funciones nativas contra los cuatro endpoints que
 * sirven el Vestíbulo (plan §2.2, Endpoints 1, 3, 4 y 5), con mapeo de los
 * estados 200/400/403/404/409 a veredictos controlados que portan las
 * leyendas canónicas del Anexo A (ratificado) del plan.
 *
 *   Endpoint 1 · fetchVestibule()                    GET  /api/v1/clans/vestibule
 *   Endpoint 3 · withdrawApplication(clanId, appId)  POST /api/v1/clans/{id}/applications/{appId}/withdraw
 *   Endpoint 4 · acknowledgeVerdict(appId)           POST /api/v1/clans/applications/{appId}/verdict-acknowledge
 *   Endpoint 5 · fetchUnreadVerdictsCount()          GET  /api/v1/clans/verdicts/unread-count
 *
 * Constitución:
 *   - Artículo I (Dogma Vanilla): fetch nativo; cero axios ni librerías HTTP.
 *   - Artículo II: el cliente JAMÁS decide el canon — no calcula aptitud,
 *     no cierra casas, no apaga rótulos: solo porta la intención y propaga
 *     el veredicto del santuario tal cual, con su código y su leyenda.
 *   - Artículo IV (El Velo Arcano): toda leyenda viaja en noble castellano.
 *   - Artículo V: funciones en inglés camelCase; comentarios en castellano.
 *
 * Contrato de respuesta (idéntico a clanClient.js):
 *   - Toda función retorna SIEMPRE un objeto controlado
 *     { success, status, data | error } — jamás lanza hacia las vistas
 *     (RF-02.2: el gesto fallido deja el Vestíbulo operativo para el
 *     reintento, y RNF-05: ninguna traza técnica llega al mago).
 *   - `status` porta el código HTTP real para que la vista distinga 401
 *     (reautenticar), 403 (veto solemne), 404 (petición ajena) y 409
 *     (carrera o idempotencia).
 *   - El sobre de error del backend se propaga ÍNTEGRO: `code`, `message`
 *     y `recoveryAction` viajan intactos, y sobreviven campos futuros.
 *
 * Credenciales:
 *   - La autoridad de sesión del santuario es la cookie HttpOnly de SPEC-03,
 *     que viaja sola con `credentials: 'same-origin'` y que JavaScript jamás
 *     toca (Artículo I).
 */

/** Códigos de error canónicos del Vestíbulo (SPEC-10 + heredados). */
export const VESTIBULE_ERROR_CODES = Object.freeze({
  /** Gesto hacia casa de otro linaje (403, RF-01.2/RF-04.1). */
  clanLineageMismatch: 'CLAN_LINEAGE_MISMATCH',
  /** El adepto ya milita y apunta a otra casa (403, RF-02.3). */
  clanLoyaltyBound: 'CLAN_LOYALTY_BOUND',
  /** Admin Supremo sin linaje jurado (403, RF-01.1). */
  adminLineageRequired: 'ADMIN_LINEAGE_REQUIRED',
  /** Casa clausurada para la cuenta (403, RF-03.1). */
  applicationHouseClosed: 'APPLICATION_HOUSE_CLOSED',
  /** Molde de motivación 20–500 excedido (400, RF-03.1). */
  invalidMotivation: 'INVALID_MOTIVATION',
  /** En convalecencia: gesto vedado (403, RF-03.5). */
  convalescenceActive: 'CONVALESCENCE_ACTIVE',
  /** Rol sin rango de adhesión (403, legado SPEC-07). */
  insufficientRank: 'INSUFFICIENT_RANK',
  /** Plenitud: carrera de vacante perdida (409, RF-02.2). */
  clanQuotaExceeded: 'CLAN_QUOTA_EXCEEDED',
  /** Doble envío de la misma petición (409, RF-03.1). */
  applicationAlreadyPending: 'APPLICATION_ALREADY_PENDING',
  /** Petición ajena o inexistente (404, RF-03.3/RF-03.4). */
  applicationNotFound: 'APPLICATION_NOT_FOUND',
  /** Carrera con el dictamen: un solo desenlace (409, caso límite 5). */
  applicationAlreadyResolved: 'APPLICATION_ALREADY_RESOLVED',
  /** Nada hay que leer en una espera (409, RF-03.4). */
  verdictAlreadyPending: 'APPLICATION_ALREADY_PENDING',
  /** Casa mutada en vuelo o estandarte desconocido (404). */
  clanNotFound: 'CLAN_NOT_FOUND',
  /** Casa disuelta (410, caso límite 2). */
  clanArchived: 'CLAN_ARCHIVED',
  /** Sin vínculo arcano activo (401, SPEC-03). */
  unauthenticated: 'UNAUTHENTICATED',
  /** Retención activa: el santuario aguarda el juramento (403, SPEC-09). */
  lineageOathRequired: 'LINEAGE_OATH_REQUIRED',
  /** Corte de red o servidor inalcanzable (contrato del proyecto). */
  networkError: 'MANA_STREAM_INTERRUPTED',
});

/**
 * Leyendas canónicas del Anexo A (plan SPEC-10, RATIFICADO): el texto
 * LITERAL que la interfaz muestra cuando el sobre llega sin mensaje
 * legible —un proxy que devuelve HTML, un 502 sin cuerpo— para que la
 * interfaz jamás muestre un texto técnico al mago.
 */
export const VESTIBULE_CEREMONIAL_LEGENDS = Object.freeze({
  [VESTIBULE_ERROR_CODES.clanLineageMismatch]:
    'Ese estandarte porta otro linaje: tu juramento te ata a las casas de tu propia sangre.',
  [VESTIBULE_ERROR_CODES.clanLoyaltyBound]:
    'Tu lealtad ya está empeñada en una casa: solo renunciar a ella —y sobrevivir la convalecencia— abre de nuevo sus puertas.',
  [VESTIBULE_ERROR_CODES.adminLineageRequired]:
    'El Privilegio Fundacional te exime del juramento; sin linaje jurado no hay hermandades que contemplar.',
  [VESTIBULE_ERROR_CODES.applicationHouseClosed]:
    'Ya pronunciaste tu palabra ante esta casa: rechazada o retirada, quedó clausurada para ti. Otras puertas aguardan.',
  [VESTIBULE_ERROR_CODES.invalidMotivation]:
    'Tu petición desborda el pergamino: el Patriarca lee mejor lo breve.',
  [VESTIBULE_ERROR_CODES.convalescenceActive]:
    'Tu esencia mágica aún se encuentra en convalecencia tras disolver tu juramento anterior.',
  [VESTIBULE_ERROR_CODES.insufficientRank]:
    'Tu rango aún no te autoriza a postular a otra casa.',
  [VESTIBULE_ERROR_CODES.clanQuotaExceeded]:
    'La hermandad ha alcanzado su plenitud de treinta hermanos.',
  [VESTIBULE_ERROR_CODES.applicationAlreadyPending]:
    'Ya obra una solicitud pendiente sobre esa hermandad.',
  [VESTIBULE_ERROR_CODES.applicationNotFound]:
    'No obra solicitud alguna con ese sello sobre esta hermandad.',
  [VESTIBULE_ERROR_CODES.applicationAlreadyResolved]:
    'Esa solicitud ya recibió veredicto: no se delibera dos veces.',
  [VESTIBULE_ERROR_CODES.clanNotFound]:
    'No hay hermandad inscrita con ese estandarte en el santuario.',
  [VESTIBULE_ERROR_CODES.clanArchived]:
    'La hermandad yace disuelta como Herencia Ancestral: su estandarte ya no admite adeptos.',
  [VESTIBULE_ERROR_CODES.unauthenticated]:
    'El vínculo arcano no está activo: conságrate o vincula tu identidad antes de pisar el Vestíbulo.',
  [VESTIBULE_ERROR_CODES.lineageOathRequired]:
    'El santuario aguarda tu juramento: nadie pisa sus salas sin linaje jurado.',
  [VESTIBULE_ERROR_CODES.networkError]:
    'La corriente de maná hacia el Vestíbulo se ha interrumpido.',
});

/** Base de la API (idéntica al resto de clientes del santuario). */
const API_BASE = '/api/v1';

/**
 * Traduce un sobre de error (o su código) a una leyenda ceremonial legible.
 *
 * La leyenda del santuario tiene precedencia: es la autoridad del texto
 * (RF-03.5: «la interfaz pinta lo que el santuario declara»). El mapa del
 * Anexo A solo entra cuando el sobre llega sin mensaje legible.
 *
 * @param {object|string} result Sobre del cliente, o código de error directo.
 * @returns {string} Leyenda en noble castellano; jamás una cadena vacía.
 */
export function ceremonialLegendFor(result) {
  const error = typeof result === 'string' ? { code: result } : (result?.error ?? {});

  if (typeof error.message === 'string' && error.message.trim() !== '') {
    return error.message;
  }

  return VESTIBULE_CEREMONIAL_LEGENDS[error.code]
    ?? 'El Vestíbulo ha respondido con un presagio indescifrable.';
}

/**
 * Crea el cliente HTTP del Vestíbulo de las Hermandades.
 *
 * @param {object} [options]
 *   - fetch:  implementación inyectable (arneses); por defecto, la nativa.
 *   - baseUrl: base sobreescribible (despliegues alternativos); en el
 *     navegador la relativa /api/v1 es la canónica.
 * @returns {object} cliente con los cuatro métodos del plan.
 */
export function createVestibuleClient(options = {}) {
  const fetchImpl = options.fetch ?? ((url, init) => fetch(url, init));
  const apiBase = options.baseUrl ?? API_BASE;

  /**
   * Envoltura pacífica: ejecuta la petición y convierte CUALQUIER fallo (red,
   * JSON ilegible, estado sin sobre) en el error controlado del proyecto.
   *
   * @param {string}      requestUrl URL completa a solicitar.
   * @param {object}      [init]     Opciones de fetch (method, headers...).
   * @param {object|null} [body]     Payload JSON a serializar (null = sin cuerpo).
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
      // Cookie de sesión nativa (SPEC-03): el navegador la adjunta sola.
      credentials: 'same-origin',
    };
    if (body !== null) {
      requestInit.body = JSON.stringify(body);
    }

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

      // Estado sin sobre legible: traducción homogénea según el código HTTP,
      // sin exponer JAMÁS el cuerpo técnico recibido (RNF-05).
      return {
        success: false,
        status: response.status,
        error: {
          code: response.status === 401
            ? VESTIBULE_ERROR_CODES.unauthenticated
            : response.status === 403
              ? VESTIBULE_ERROR_CODES.lineageOathRequired
              : VESTIBULE_ERROR_CODES.networkError,
          message: `El Vestíbulo respondió con el estado ${response.status}.`,
          recoveryAction: 'RETRY',
        },
      };
    } catch {
      // Corte de red real: objeto controlado, jamás excepción al llamador.
      return {
        success: false,
        status: 0,
        error: {
          code: VESTIBULE_ERROR_CODES.networkError,
          message: VESTIBULE_CEREMONIAL_LEGENDS[VESTIBULE_ERROR_CODES.networkError],
          recoveryAction: 'RETRY',
        },
      };
    }
  }

  /** Codifica un identificador de ruta sin permitir inyección de segmentos. */
  const path = (value) => encodeURIComponent(String(value ?? ''));

  return {
    /**
     * Endpoint 1 (RF-01.2, RF-01.7, RNF-04): el sobre único del Vestíbulo.
     *
     * Una sola carga con aptitud, catálogo del linaje jurado, casa legada
     * divergente e inventario de peticiones. Sin parámetro alguno: el
     * linaje se DERIVA de la sesión (RF-01.2).
     *
     * @returns {Promise<object>} 200 con el estado completo; 401; 403
     *   ADMIN_LINEAGE_REQUIRED o LINEAGE_OATH_REQUIRED.
     */
    fetchVestibule() {
      return requestJson(`${apiBase}/clans/vestibule`, { method: 'GET' });
    },

    /**
     * Endpoint 3 (RF-03.3): retirada del postulante.
     *
     * @param {string} clanId        Identificador de la casa.
     * @param {string} applicationId Sello de la petición (app_*).
     * @returns {Promise<object>} 200 con la petición retirada (la casa
     *   queda clausurada), 404 APPLICATION_NOT_FOUND, 409
     *   APPLICATION_ALREADY_RESOLVED (carrera con el dictamen).
     */
    withdrawApplication(clanId, applicationId) {
      return requestJson(
        `${apiBase}/clans/${path(clanId)}/applications/${path(applicationId)}/withdraw`,
        { method: 'POST' },
        {},
      );
    },

    /**
     * Endpoint 4 (RF-03.4): veredicto contemplado.
     *
     * Idempotente por contrato del backend: el reenvío responde 200 sin
     * mutación. Apaga el rótulo «Tienes dictámenes a la espera».
     *
     * @param {string} applicationId Sello de la petición (app_*).
     * @returns {Promise<object>} 200 con la petición contemplada; 404
     *   ajena o inexistente; 409 APPLICATION_ALREADY_PENDING.
     */
    acknowledgeVerdict(applicationId) {
      return requestJson(
        `${apiBase}/clans/applications/${path(applicationId)}/verdict-acknowledge`,
        { method: 'POST' },
        {},
      );
    },

    /**
     * Endpoint 5 (RF-01.1, RNF-04): contador del rótulo de navegación.
     *
     * Consulta ligera para el distintivo «Tienes dictámenes a la espera»
     * del acceso al Vestíbulo.
     *
     * @returns {Promise<object>} 200 con { unreadVerdictsCount } o 401.
     */
    fetchUnreadVerdictsCount() {
      return requestJson(`${apiBase}/clans/verdicts/unread-count`, { method: 'GET' });
    },
  };
}
