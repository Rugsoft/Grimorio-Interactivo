/**
 * clanClient.js — Cliente HTTP nativo del gobierno de hermandades.
 *
 * Tarea 5.1 (TASKS-07): funciones nativas contra los nueve Endpoints del plan
 * técnico (SPEC-07, plan 2.2), con manejo homogéneo de los errores canónicos
 * del santuario y su traducción a leyendas ceremoniales en noble castellano.
 *
 *   Endpoint 1 · foundClan(payload)                       POST /api/v1/clans
 *   Endpoint 2 · fetchClans(params)                     GET  /api/v1/clans
 *   Endpoint 3 · fetchClan(clanId)                    GET  /api/v1/clans/{id}
 *   Endpoint 4 · updateClan(clanId, payload)          PATCH /api/v1/clans/{id}
 *   Endpoint 5 · applyToClan(clanId)                  POST /api/v1/clans/{id}/applications
 *   Endpoint 6 · resolveApplication(clanId, applicationId, decision)
 *                                       POST /api/v1/clans/{id}/applications/{appId}/resolve
 *   Endpoint 7 · leaveClan(clanId)                    POST /api/v1/clans/{id}/leave
 *   Endpoint 8 · expelMember(clanId, userId)          POST /api/v1/clans/{id}/expel/{userId}
 *   Endpoint 9 · transferLeadership(clanId, newPatriarchId)
 *                                       POST /api/v1/clans/{id}/transfer-leadership
 *
 * Constitución:
 *   - Artículo I (Dogma Vanilla): fetch nativo; cero axios ni librerías HTTP.
 *   - Artículo II: el cliente JAMÁS decide el canon — no calcula PDA, no
 *     valida cupos ni rangos: solo porta la intención y propaga el veredicto
 *     del santuario tal cual, con su código y su leyenda.
 *   - Artículo V: métodos en inglés camelCase; narrativa en castellano.
 *
 * Contrato de respuesta (idéntico a authClient/spellCreatorClient):
 *   - Toda función retorna SIEMPRE un objeto controlado
 *     { success, status, data | error } — jamás lanza hacia las vistas.
 *   - `status` porta el código HTTP real (200/201/400/401/403/404/409/422)
 *     para que el panel del Patriarca reaccione sin adivinar.
 *   - El sobre de error del backend se propaga ÍNTEGRO: `code`, `message` y
 *     `recoveryAction` viajan intactos, y sobreviven campos futuros.
 *
 * Credenciales:
 *   - La autoridad de sesión del santuario es la cookie HttpOnly de SPEC-03,
 *     que viaja sola con `credentials: 'same-origin'` y que JavaScript jamás
 *     toca (Artículo I).
 *   - Además se admite un token opcional `Bearer`, ADITIVO y nunca
 *     sustitutivo: si se inyecta un token, se adjunta la cabecera
 *     `Authorization: Bearer <token>`; si no, la petición sale con la única
 *     autoridad de la sesión. El backend no exige esa cabecera: sirve a
 *     custodios y arneses que operan sin navegador.
 */

/** Códigos de error canónicos del gobierno de hermandades (SPEC-07). */
export const CLAN_ERROR_CODES = Object.freeze({
  /** Petición malformada: el cuerpo no es JSON legible (400). */
  invalidRequestBody: 'INVALID_REQUEST_BODY',
  /** Entidad incompleta o de tipos sucios (422). */
  invalidClanPayload: 'INVALID_CLAN_PAYLOAD',
  /** Nombre Canónico fuera del canon de extensión (400). */
  invalidName: 'INVALID_NAME',
  /** Linaje ajeno a los ocho canónicos (400). */
  unknownLineage: 'UNKNOWN_LINEAGE',
  /** Estado de catálogo ajeno a `active`/`archived` (400). */
  invalidCatalogFilter: 'INVALID_CATALOG_FILTER',
  /** Paginación no entera o no positiva (400). */
  invalidQueryParams: 'INVALID_QUERY_PARAMS',
  /** Sin vínculo arcano activo (401). */
  unauthenticated: 'UNAUTHENTICATED',
  /** Rango insuficiente para fundar o postular (403). */
  insufficientRank: 'INSUFFICIENT_RANK',
  /** Convalecencia Arcana activa: veda fundar e ingresar (403). */
  convalescenceActive: 'CONVALESCENCE_ACTIVE',
  /** No ciñe la corona de esa casa (403). */
  notPatriarch: 'NOT_PATRIARCH',
  /** El Patriarca no puede expulsarse a sí mismo (403). */
  cannotExpelSelf: 'CANNOT_EXPEL_SELF',
  /** El Patriarca debe ceder la corona antes de partir (400). */
  patriarchMustTransferCrown: 'PATRIARCH_MUST_TRANSFER_CROWN',
  /** La casa no existe (404). */
  clanNotFound: 'CLAN_NOT_FOUND',
  /** El adepto no milita en esa casa (404). */
  notAMember: 'NOT_A_MEMBER',
  /** La solicitud no existe o no pertenece a esa casa (404). */
  applicationNotFound: 'APPLICATION_NOT_FOUND',
  /** Nombre Canónico ya inscrito, incluso de una casa disuelta (409). */
  nameAlreadyReserved: 'NAME_ALREADY_RESERVED',
  /** Lealtad indivisible: ya milita en otra casa (409). */
  alreadyAffiliated: 'ALREADY_AFFILIATED',
  /** La casa yace disuelta como Herencia Ancestral (409). */
  clanArchived: 'CLAN_ARCHIVED',
  /** Cupo de treinta adeptos colmado (409). */
  quotaExceeded: 'CLAN_QUOTA_EXCEEDED',
  /** Ya obra una postulación pendiente sobre esa misma casa (409). */
  applicationAlreadyPending: 'APPLICATION_ALREADY_PENDING',
  /** La solicitud ya recibió veredicto (409). */
  applicationAlreadyResolved: 'APPLICATION_ALREADY_RESOLVED',
  /** Tope de tres solicitudes pendientes simultáneas (400). */
  pendingApplicationsLimit: 'PENDING_APPLICATIONS_LIMIT',
  /** Veredicto ajeno a `approve`/`reject` (400). */
  invalidDecision: 'INVALID_DECISION',
  /** El heredero propuesto no es apto para ceñir la corona (400). */
  ineligibleSuccessor: 'INELIGIBLE_SUCCESSOR',
  /** Corte de red o servidor inalcanzable (contrato del proyecto). */
  networkError: 'MANA_STREAM_INTERRUPTED',
});

/**
 * Leyendas ceremoniales del cliente (RNF-03).
 *
 * El santuario responde siempre con su propia leyenda en noble castellano;
 * estas se emplean SOLO cuando el sobre de error llega sin mensaje legible
 * —un proxy que devuelve HTML, un 502 sin cuerpo— para que la interfaz jamás
 * muestre un texto técnico al mago.
 */
export const CLAN_CEREMONIAL_LEGENDS = Object.freeze({
  [CLAN_ERROR_CODES.insufficientRank]: 'Tu rango aún no te autoriza a fundar una hermandad ni a postular a otra casa.',
  [CLAN_ERROR_CODES.convalescenceActive]: 'Tu esencia mágica aún se encuentra en convalecencia tras disolver tu juramento anterior.',
  [CLAN_ERROR_CODES.nameAlreadyReserved]: 'Ese Nombre Canónico ya está inscrito en los anales: elige otro para tu estandarte.',
  [CLAN_ERROR_CODES.alreadyAffiliated]: 'La lealtad mágica es indivisible: ya militas bajo otro estandarte.',
  [CLAN_ERROR_CODES.clanNotFound]: 'No hay hermandad inscrita con ese estandarte en el santuario.',
  [CLAN_ERROR_CODES.clanArchived]: 'La hermandad yace disuelta como Herencia Ancestral: su estandarte ya no admite adeptos.',
  [CLAN_ERROR_CODES.quotaExceeded]: 'La hermandad ha alcanzado su plenitud de treinta hermanos.',
  [CLAN_ERROR_CODES.pendingApplicationsLimit]: 'Ya mantienes tres solicitudes pendientes: aguarda veredicto antes de cortejar otra casa.',
  [CLAN_ERROR_CODES.applicationAlreadyPending]: 'Ya obra una solicitud pendiente sobre esa hermandad.',
  [CLAN_ERROR_CODES.applicationNotFound]: 'No obra solicitud alguna con ese sello sobre esta hermandad.',
  [CLAN_ERROR_CODES.applicationAlreadyResolved]: 'Esa solicitud ya recibió veredicto: no se delibera dos veces.',
  [CLAN_ERROR_CODES.notPatriarch]: 'Solo quien ciñe la corona puede gobernar esta hermandad.',
  [CLAN_ERROR_CODES.notAMember]: 'Ese mago no milita bajo este estandarte.',
  [CLAN_ERROR_CODES.cannotExpelSelf]: 'El Patriarca no puede expulsarse a sí mismo: para partir, cede antes la corona.',
  [CLAN_ERROR_CODES.patriarchMustTransferCrown]: 'Antes de partir debes ceder la corona a otro adepto de la casa.',
  [CLAN_ERROR_CODES.ineligibleSuccessor]: 'El adepto propuesto no es apto para ceñir la corona de la casa.',
  [CLAN_ERROR_CODES.unauthenticated]: 'El vínculo arcano no está activo: conságrate o vincula tu identidad antes de gobernar una hermandad.',
  [CLAN_ERROR_CODES.invalidClanPayload]: 'El pergamino de la hermandad está incompleto o porta tipos ajenos al canon.',
  [CLAN_ERROR_CODES.invalidName]: 'El Nombre Canónico no cumple la extensión que el canon exige.',
  [CLAN_ERROR_CODES.unknownLineage]: 'Ese linaje no figura entre los ocho canónicos del santuario.',
  [CLAN_ERROR_CODES.invalidCatalogFilter]: 'El filtro del catálogo solo admite los estados «active» y «archived».',
  [CLAN_ERROR_CODES.invalidQueryParams]: 'La página y su tamaño deben ser números enteros positivos.',
  [CLAN_ERROR_CODES.networkError]: 'La corriente de maná hacia el santuario de hermandades se ha interrumpido.',
});

/** Base de la API (idéntica al resto de clientes del santuario). */
const API_BASE = '/api/v1';

/**
 * Traduce un sobre de error (o su código) a una leyenda ceremonial legible.
 *
 * @param {object|string} result Sobre del cliente, o código de error directo.
 * @returns {string} Leyenda en noble castellano; jamás una cadena vacía.
 */
export function ceremonialLegendFor(result) {
  const error = typeof result === 'string' ? { code: result } : (result?.error ?? {});

  // La leyenda del santuario tiene precedencia: es la autoridad del texto.
  if (typeof error.message === 'string' && error.message.trim() !== '') {
    return error.message;
  }

  return CLAN_CEREMONIAL_LEGENDS[error.code] ?? 'El santuario de hermandades ha respondido con un presagio indescifrable.';
}

/**
 * Construye la query de lectura del catálogo omitiendo parámetros ausentes.
 *
 * @param {object} params - lineage, status, page, perPage.
 * @returns {string} Cadena `?clave=valor` o cadena vacía.
 */
function buildQuery(params = {}) {
  const search = new URLSearchParams();
  for (const [key, value] of Object.entries(params)) {
    if (value === undefined || value === null || value === '') {
      continue; // Parámetro ausente: no viaja en la petición.
    }
    search.set(key, String(value));
  }
  const query = search.toString();

  return query ? `?${query}` : '';
}

/**
 * Crea el cliente HTTP del gobierno de hermandades.
 *
 * @param {object} [options]
 *   - fetch:  implementación inyectable (arneses); por defecto, la nativa.
 *   - baseUrl: base sobreescribible (despliegues alternativos); en el
 *     navegador la relativa /api/v1 es la canónica.
 *   - token:  credencial Bearer OPCIONAL y aditiva (custodios, arneses); la
 *     autoridad ordinaria sigue siendo la cookie HttpOnly de sesión.
 * @returns {object} cliente con los nueve métodos del plan.
 */
export function createClanClient(options = {}) {
  const fetchImpl = options.fetch ?? ((url, init) => fetch(url, init));
  const apiBase = options.baseUrl ?? API_BASE;
  const token = typeof options.token === 'string' && options.token.trim() !== '' ? options.token.trim() : null;

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
        // Credencial Bearer opcional y aditiva (ver cabecera del módulo).
        ...(token !== null ? { Authorization: `Bearer ${token}` } : {}),
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

      // Estado sin sobre legible: traducción homogénea según el código HTTP.
      return {
        success: false,
        status: response.status,
        error: {
          code: response.status === 401
            ? CLAN_ERROR_CODES.unauthenticated
            : response.status === 404
              ? CLAN_ERROR_CODES.clanNotFound
              : CLAN_ERROR_CODES.networkError,
          message: `El santuario de hermandades respondió con el estado ${response.status}.`,
          recoveryAction: 'RETRY',
        },
      };
    } catch {
      // Corte de red real: objeto controlado, jamás excepción al llamador.
      return {
        success: false,
        status: 0,
        error: {
          code: CLAN_ERROR_CODES.networkError,
          message: CLAN_CEREMONIAL_LEGENDS[CLAN_ERROR_CODES.networkError],
          recoveryAction: 'RETRY',
        },
      };
    }
  }

  /** Codifica un identificador de ruta sin permitir inyección de segmentos. */
  const path = (value) => encodeURIComponent(String(value ?? ''));

  return {
    /**
     * Endpoint 1 (RF-01.1, RF-01.2, RF-05.4): funda una hermandad.
     *
     * @param {object} payload {name, motto?, coatOfArms?, lineageType, admissionMode?}.
     * @returns {Promise<object>} 201 con la ficha y la corona del fundador,
     *   400 INVALID_NAME/UNKNOWN_LINEAGE, 401, 403 INSUFFICIENT_RANK o
     *   CONVALESCENCE_ACTIVE, 409 NAME_ALREADY_RESERVED o ALREADY_AFFILIATED,
     *   422 INVALID_CLAN_PAYLOAD.
     */
    foundClan(payload) {
      return requestJson(`${apiBase}/clans`, { method: 'POST' }, payload ?? {});
    },

    /**
     * Endpoint 2 (RF-01.4, RF-05.3): catálogo filtrado y paginado.
     *
     * @param {object} [params] {lineage, status, page, perPage}.
     * @returns {Promise<object>} 200 con {items, pagination} o 400.
     */
    fetchClans(params = {}) {
      return requestJson(`${apiBase}/clans${buildQuery(params)}`, { method: 'GET' });
    },

    /**
     * Endpoint 3 (RF-01.3, RF-01.7): ficha heráldica, censo y PDA.
     *
     * @param {string} clanId Identificador de la casa (cln_*).
     * @returns {Promise<object>} 200 con {clan, patriarch, members,
     *   applications} —la cola de solicitudes solo la ve quien ciñe la
     *   corona— o 404 CLAN_NOT_FOUND.
     */
    fetchClan(clanId) {
      return requestJson(`${apiBase}/clans/${path(clanId)}`, { method: 'GET' });
    },

    /**
     * Endpoint 4 (RF-01.3): muda de lema, blasón y régimen de admisión.
     *
     * @param {string} clanId  Identificador de la casa.
     * @param {object} payload Al menos uno de {motto, coatOfArms, admissionMode}.
     * @returns {Promise<object>} 200 con la ficha mudada, 403 NOT_PATRIARCH o
     *   CLAN_ARCHIVED, 404, 422.
     */
    updateClan(clanId, payload) {
      return requestJson(`${apiBase}/clans/${path(clanId)}`, { method: 'PATCH' }, payload ?? {});
    },

    /**
     * Endpoint 5 (RF-01.5): postulación o ingreso según el régimen.
     *
     * @param {string} clanId Identificador de la casa cortejada.
     * @returns {Promise<object>} 201 con el desenlace (`pending` o `active`),
     *   400 PENDING_APPLICATIONS_LIMIT, 403 CONVALESCENCE_ACTIVE, 409
     *   APPLICATION_ALREADY_PENDING / ALREADY_AFFILIATED / CLAN_QUOTA_EXCEEDED.
     */
    applyToClan(clanId) {
      return requestJson(`${apiBase}/clans/${path(clanId)}/applications`, { method: 'POST' }, {});
    },

    /**
     * Endpoint 6 (RF-01.5): dirime una solicitud pendiente (solo el Patriarca).
     *
     * @param {string} clanId        Identificador de la casa.
     * @param {string} applicationId Sello de la solicitud (app_*).
     * @param {string} decision      Veredicto canónico: 'approve' o 'reject'.
     * @returns {Promise<object>} 200 con el desenlace, 400 INVALID_DECISION,
     *   403 NOT_PATRIARCH, 404 APPLICATION_NOT_FOUND, 409.
     */
    resolveApplication(clanId, applicationId, decision) {
      return requestJson(
        `${apiBase}/clans/${path(clanId)}/applications/${path(applicationId)}/resolve`,
        { method: 'POST' },
        { action: decision },
      );
    },

    /**
     * Endpoint 7 (RF-01.6, RF-05.3): partida del adepto o del Patriarca.
     *
     * @param {string} clanId Identificador de la casa abandonada.
     * @returns {Promise<object>} 200 con la membresía cerrada y su
     *   convalecencia (si el Patriarca era el último morador, la casa queda
     *   disuelta en el mismo gesto), 400 PATRIARCH_MUST_TRANSFER_CROWN, 404.
     */
    leaveClan(clanId) {
      return requestJson(`${apiBase}/clans/${path(clanId)}/leave`, { method: 'POST' }, {});
    },

    /**
     * Endpoint 8 (RF-01.6): expulsión de un adepto (solo el Patriarca).
     *
     * @param {string} clanId Identificador de la casa.
     * @param {string} userId Identificador del adepto expulsado.
     * @returns {Promise<object>} 200 con la membresía cerrada, 403
     *   NOT_PATRIARCH o CANNOT_EXPEL_SELF, 404 NOT_A_MEMBER.
     */
    expelMember(clanId, userId) {
      return requestJson(`${apiBase}/clans/${path(clanId)}/expel/${path(userId)}`, { method: 'POST' }, {});
    },

    /**
     * Endpoint 9 (RF-01.3): traspaso de la corona al heredero designado.
     *
     * @param {string} clanId          Identificador de la casa.
     * @param {string} newPatriarchId  Adepto que ciñe la corona.
     * @returns {Promise<object>} 200 con la membresía del nuevo Patriarca
     *   (el saliente desciende a `adept`), 400 INELIGIBLE_SUCCESSOR, 403
     *   NOT_PATRIARCH, 404, 422.
     */
    transferLeadership(clanId, newPatriarchId) {
      return requestJson(
        `${apiBase}/clans/${path(clanId)}/transfer-leadership`,
        { method: 'POST' },
        { newPatriarchId },
      );
    },
  };
}
