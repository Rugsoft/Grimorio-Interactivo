/**
 * grimoireCollectionClient.js — Cliente HTTP nativo del Tomo Personal y
 * la puerta del Elogio Popular (SPEC-11, Tarea 4.2).
 *
 * Los cuatro endpoints del contrato (plan §2.2):
 *
 *   fetchCollection(element, page)   GET    /api/v1/grimoire/collection
 *   collectSpell(spellId)            POST   /api/v1/grimoire/collection
 *   discardSpell(spellId, element)   DELETE /api/v1/grimoire/collection/{id}
 *   praiseSpell(spellId)             POST   /api/v1/grimoire/praise
 *
 * Constitución:
 *   - Artículo I (Dogma Vanilla): fetch nativo; cero axios ni librerías
 *     HTTP; ES Modules nativos (RNF-02).
 *   - Artículo II: el cliente JAMÁS decide el canon — no calcula marcas
 *     solemnes (llegan en el DTO), no mueve gloria, no traduce estados
 *     del ciclo de vida: porta la intención y propaga el veredicto del
 *     santuario tal cual, con su código y su leyenda.
 *   - Artículo IV (El Velo Arcano): toda leyenda viaja en noble
 *     castellano.
 *   - Artículo V: funciones en inglés camelCase; comentarios y leyendas
 *     en castellano.
 *
 * Contrato de respuesta (idéntico a vestibuleClient.js):
 *   - Toda función retorna SIEMPRE un objeto controlado
 *     { success, status, data | error } — jamás lanza hacia las vistas
 *     (los estados solemnes del homenaje y la retención del peregrino
 *     NO son excepciones: son veredictos).
 *   - `status` porta el código HTTP real para que la vista distinga 401
 *     (degradación solemne, RF-05.2), 403 (juramento o veto), 404 y
 *     409 (conflicto de estado).
 *   - El sobre de error del backend se propaga ÍNTEGRO: `code`, `message`
 *     y `recoveryAction` viajan intactos, y sobreviven campos futuros.
 *
 * Credenciales:
 *   - La autoridad de sesión del santuario es la cookie HttpOnly de
 *     SPEC-03, que viaja sola con `credentials: 'same-origin'` y que
 *     JavaScript jamás toca.
 */

/** Códigos de error canónicos del tomo (SPEC-11 + heredados). */
export const TOME_ERROR_CODES = Object.freeze({
  /** Gesto de colección/elogio sin linaje jurado (403, hallazgo 16). */
  lineageOathRequired: 'LINEAGE_OATH_REQUIRED',
  /** Sellado vedado: leyenda UNIFORME ante cualquier no validado (403, RF-01.2). */
  tomeSealVeto: 'TOME_SEAL_VETO',
  /** Hechizo inexistente (404, RF-05.1). */
  spellNotFound: 'SPELL_NOT_FOUND',
  /** Retirada de un hechizo que no habita el tomo (409, RF-02.4). */
  spellNotInTome: 'SPELL_NOT_IN_TOME',
  /** Elogio forzado sobre no validado (409, RF-04.5). */
  praiseSpellNotValidated: 'PRAISE_SPELL_NOT_VALIDATED',
  /** Parámetro fuera del canon (400: afinidad, hoja). */
  invalidQuery: 'INVALID_QUERY',
  /** Sin vínculo arcano activo (401, SPEC-03 — degradación solemne, RF-05.2). */
  unauthenticated: 'UNAUTHENTICATED',
  /** Corte de red o servidor inalcanzable (contrato del proyecto). */
  networkError: 'MANA_STREAM_INTERRUPTED',
});

/**
 * Leyendas canónicas del Anexo A del plan (RATIFICADO): el texto LITERAL
 * que la interfaz muestra cuando el sobre llega sin mensaje legible —un
 * proxy que devuelve HTML, un 502 sin cuerpo— para que la interfaz jamás
 * muestre un texto técnico al mago.
 */
export const TOME_CEREMONIAL_LEGENDS = Object.freeze({
  [TOME_ERROR_CODES.lineageOathRequired]:
    'El santuario aguarda tu juramento: nadie pisa sus salas sin linaje jurado.',
  [TOME_ERROR_CODES.tomeSealVeto]:
    'Solo lo que el Tribunal ha sellado entra al tomo.',
  [TOME_ERROR_CODES.spellNotFound]:
    'Ese conjuro no existe o no habita tu grimorio.',
  [TOME_ERROR_CODES.spellNotInTome]:
    'Solo se retira lo que se selló: ese hechizo no habita tu tomo.',
  [TOME_ERROR_CODES.praiseSpellNotValidated]:
    'La gloria solo nace de obra sellada por el Tribunal.',
  [TOME_ERROR_CODES.invalidQuery]:
    'Ese filtro no pertenece al canon del santuario.',
  [TOME_ERROR_CODES.unauthenticated]:
    'Tu vínculo con el santuario ha expirado: renuévalo y tus gestos aguardarán donde los dejaste.',
  [TOME_ERROR_CODES.networkError]:
    'La corriente de maná hacia tu tomo se ha interrumpido.',
});

/** Razones canónicas del recibo del Elogio Popular (plan §2.2). */
export const PRAISE_REASONS = Object.freeze({
  /** Gloria nueva acreditada al clan del hechizo (RF-04.2). */
  awarded: 'AWARDED',
  /** El voto ya vive en favorites: eco idempotente (RF-04.3). */
  alreadyPraised: 'ALREADY_PRAISED',
  /** Militancia en la casa del hechizo: recibo denegado vivo (RF-04.4). */
  ownClanFavorite: 'OWN_CLAN_FAVORITE',
});

/** Leyendas de los estados solemnes del homenaje (plan §4.3). */
export const PRAISE_LEGENDS = Object.freeze({
  [PRAISE_REASONS.awarded]:
    'Tu homenaje a la obra ya resuena en su casa.',
  [PRAISE_REASONS.alreadyPraised]:
    'Ya rendiste homenaje a esta obra.',
  [PRAISE_REASONS.ownClanFavorite]:
    'Un adepto de la casa no granjea gloria para su propio estandarte.',
});

/** Base de la API (idéntica al resto de clientes del santuario). */
const API_BASE = '/api/v1';

/**
 * Traduce un sobre de error (o su código) a una leyenda ceremonial legible.
 *
 * La leyenda del santuario tiene precedencia: es la autoridad del texto.
 * El mapa del Anexo A solo entra cuando el sobre llega sin mensaje legible.
 *
 * @param {object|string} result Sobre del cliente, o código de error directo.
 * @returns {string} Leyenda en noble castellano; jamás una cadena vacía.
 */
export function ceremonialLegendFor(result) {
  const error = typeof result === 'string' ? { code: result } : (result?.error ?? {});

  if (typeof error.message === 'string' && error.message.trim() !== '') {
    return error.message;
  }

  return TOME_CEREMONIAL_LEGENDS[error.code]
    ?? 'El tomo ha respondido con un presagio indescifrable.';
}

/**
 * Crea el cliente HTTP del Tomo Personal.
 *
 * @param {object} [options]
 *   - fetch:  implementación inyectable (arneses); por defecto, la nativa.
 *   - baseUrl: base sobreescribible (despliegues alternativos); en el
 *     navegador la relativa /api/v1 es la canónica.
 * @returns {object} cliente con los cuatro métodos del contrato.
 */
export function createGrimoireCollectionClient(options = {}) {
  const fetchImpl = options.fetch ?? ((url, init) => fetch(url, init));
  const apiBase = options.baseUrl ?? API_BASE;

  /**
   * Envoltura pacífica: ejecuta la petición y convierte CUALQUIER fallo
   * (red, JSON ilegible, estado sin sobre) en el error controlado del
   * proyecto — jamás una excepción hacia las vistas.
   *
   * @param {string}      requestUrl URL completa a solicitar.
   * @param {object}      [init]     Opciones de fetch (method...).
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
            ? TOME_ERROR_CODES.unauthenticated
            : response.status === 403
              ? TOME_ERROR_CODES.lineageOathRequired
              : TOME_ERROR_CODES.networkError,
          message: `El tomo respondió con el estado ${response.status}.`,
          recoveryAction: 'RETRY',
        },
      };
    } catch {
      // Corte de red real: objeto controlado, jamás excepción al llamador.
      return {
        success: false,
        status: 0,
        error: {
          code: TOME_ERROR_CODES.networkError,
          message: TOME_CEREMONIAL_LEGENDS[TOME_ERROR_CODES.networkError],
          recoveryAction: 'RETRY',
        },
      };
    }
  }

  /** Codifica un identificador de ruta sin permitir inyección de segmentos. */
  const path = (value) => encodeURIComponent(String(value ?? ''));

  return {
    /**
     * GET /grimoire/collection?element&page (RF-02.1, RF-02.3, caso límite 4).
     *
     * @param {string|null} [element] Afinidad canónica del filtro, o null.
     * @param {number}      [page=1]  Hoja solicitada (base 1).
     * @returns {Promise<object>} 200 con el sobre CollectionPageDto;
     *   401; 403 LINEAGE_OATH_REQUIRED; 400 INVALID_QUERY.
     */
    fetchCollection(element = null, page = 1) {
      const query = new URLSearchParams();
      if (element !== null && element !== '') {
        query.set('element', String(element));
      }
      query.set('page', String(Math.max(1, Number(page) || 1)));
      const queryString = query.toString();
      return requestJson(
        `${apiBase}/grimoire/collection${queryString !== '' ? `?${queryString}` : ''}`,
        { method: 'GET' },
      );
    },

    /**
     * POST /grimoire/collection (RF-01.1, plan §3.2).
     *
     * 201 sellado nuevo; 200 «Ya está en tu tomo» (RF-01.3, idempotente:
     * no es error); 403 TOME_SEAL_VETO o LINEAGE_OATH_REQUIRED; 404.
     *
     * @param {string} spellId Identificador del hechizo a sellar.
     * @returns {Promise<object>} Sobre con el eco del acto (data: { alreadyCollected, addedAt }).
     */
    collectSpell(spellId) {
      return requestJson(
        `${apiBase}/grimoire/collection`,
        { method: 'POST' },
        { spellId: String(spellId ?? '') },
      );
    },

    /**
     * DELETE /grimoire/collection/{spellId} (RF-02.4, plan §3.5).
     *
     * 200 con el total actualizado (data: { removed, total }); 409
     * SPELL_NOT_IN_TOME (no es error de red: conflicto de estado).
     *
     * @param {string}        spellId  Identificador del hechizo a retirar.
     * @param {string|null}   [element] Filtro de afinidad vigente en la vista,
     *        para que el total devuelto describa el conjunto exhibido.
     * @returns {Promise<object>} Sobre con el eco de la retirada.
     */
    discardSpell(spellId, element = null) {
      const query = element !== null && element !== '' ? `?element=${encodeURIComponent(String(element))}` : '';
      return requestJson(
        `${apiBase}/grimoire/collection/${path(spellId)}${query}`,
        { method: 'DELETE' },
      );
    },

    /**
     * POST /grimoire/praise (RF-04.1, plan §3.3): la puerta del Elogio.
     *
     * Los tres desenlaces son ESTADOS (200), jamás excepciones:
     *   - data.reason 'AWARDED'          → gloria nueva acreditada.
     *   - data.reason 'ALREADY_PRAISED'  → «Ya rendiste homenaje».
     *   - data.reason 'OWN_CLAN_FAVORITE' → recibo denegado de militancia.
     * Solo el no validado forzado viaja como 409 PRAISE_SPELL_NOT_VALIDATED.
     *
     * @param {string} spellId Identificador del hechizo a homenajear.
     * @returns {Promise<object>} Sobre con { praised, reason, awarded? }.
     */
    praiseSpell(spellId) {
      return requestJson(
        `${apiBase}/grimoire/praise`,
        { method: 'POST' },
        { spellId: String(spellId ?? '') },
      );
    },
  };
}
