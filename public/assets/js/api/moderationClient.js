/**
 * moderationClient.js — Cliente HTTP nativo de la Moderación en Dos Pasos.
 *
 * Tarea 5.1 (TASKS-08): funciones nativas contra los doce Endpoints del plan
 * técnico (SPEC-08, plan 2.2), con manejo homogéneo de los errores canónicos
 * del Cónclave y su traducción a leyendas ceremoniales en noble castellano.
 *
 *   Endpoint 1  · submitSpell(spellId)         POST /api/v1/moderation/spells/{id}/submit
 *   Endpoint 2  · withdrawSpell(spellId)       POST /api/v1/moderation/spells/{id}/withdraw
 *   Endpoint 3  · reopenSpell(spellId)         POST /api/v1/moderation/spells/{id}/reopen
 *   Endpoint 4  · fetchExperimentalHall(p)     GET  /api/v1/moderation/experimental
 *   Endpoint 5  · fetchDeliberationQueue(p)    GET  /api/v1/moderation/queue
 *   Endpoint 6  · signSpell(spellId, gloss)    POST /api/v1/moderation/spells/{id}/sign
 *   Endpoint 7  · retractSignature(id, motivo) POST /api/v1/moderation/spells/{id}/retract
 *   Endpoint 8  · objectSpell(id, motivo)      POST /api/v1/moderation/spells/{id}/object
 *   Endpoint 9  · sovereignValidate(id, edicto)     POST /api/v1/moderation/sovereign/validate
 *   Endpoint 10 · sovereignRescue(id, destino, edicto) POST /api/v1/moderation/sovereign/rescue
 *   Endpoint 11 · sovereignArchive(id, edicto, deduce) POST /api/v1/moderation/sovereign/archive
 *   Endpoint 12 · checkExpiryCron(sello)       POST /api/v1/moderation/cron-check-expiry
 *
 * Constitución:
 *   - Artículo I (Dogma Vanilla): fetch nativo; cero axios ni librerías HTTP.
 *   - Artículo II: el cliente JAMÁS calcula maná, huella ni contador: la
 *     huella la sella el backend y el contador lo cuenta el Cónclave. El
 *     cliente solo porta la intención y propaga el veredicto tal cual.
 *   - Artículo III: el cliente jamás decide ética, cupo ni pluralidad: el
 *     veto ético le llega RESUELTO en la cola de la Torre
 *     (`hasEthicalConflict` + `ethicalVeto`), porque la memoria de clanes
 *     jamás se filtra al navegador.
 *   - Artículo V: métodos en inglés camelCase; narrativa en castellano.
 *
 * Contrato de respuesta (idéntico a clanClient/dominionClient):
 *   - Toda función retorna SIEMPRE un objeto controlado
 *     { success, status, data | error } — jamás lanza hacia las vistas.
 *   - `status` porta el código HTTP real (200/400/401/403/404/409/422)
 *     para que el Atrio y la Torre reaccionen sin adivinar.
 *   - El sobre de error del backend se propaga ÍNTEGRO: `code`, `message`
 *     y `recoveryAction` viajan intactos, y sobreviven campos futuros.
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

/** Códigos de error canónicos de la moderación (SPEC-08). */
export const MODERATION_ERROR_CODES = Object.freeze({
  /** Sin vínculo arcano activo (401). */
  unauthenticated: 'UNAUTHENTICATED',
  /** Conjuro inexistente o ajeno al invocante (404, contrato de SPEC-04). */
  spellNotFound: 'SPELL_NOT_FOUND',
  /** Cuerpo sin objetivo declarado: el decreto no nombra a quién se dicta (400). */
  invalidRequestBody: 'INVALID_REQUEST_BODY',
  /** Paginación o filtros fuera de los enteros positivos (400). */
  invalidQueryParams: 'INVALID_QUERY_PARAMS',
  /** La obra ya no descansa en la libreta: solo un borrador se eleva (400). */
  spellNotInDraft: 'SPELL_NOT_IN_DRAFT',
  /** La obra no yace en deliberación: nada hay que retirar (409). */
  spellNotUnderReview: 'SPELL_NOT_UNDER_REVIEW',
  /** Solo una obra vetada se reabre como borrador (400). */
  spellNotRejected: 'SPELL_NOT_REJECTED',
  /** La Torre ya custodia tres obras del autor (409, RNF-04). */
  towerCapacityExceeded: 'TOWER_CAPACITY_EXCEEDED',
  /** El rango técnico no alcanza para elevar obras (403). */
  insufficientRank: 'INSUFFICIENT_RANK',
  /** Convalecencia Arcana vigente: la pluma calla (403). */
  convalescenceActive: 'CONVALESCENCE_ACTIVE',
  /** La obra en deliberación es inmutable (403, RF-01.3). */
  underReviewImmutable: 'UNDER_REVIEW_IMMUTABLE',
  /** La obra vetada yace inmutable hasta su re-apertura (403). */
  spellAwaitingReopen: 'SPELL_AWAITING_REOPEN',
  /** Solo se juzga lo que yace en deliberación (409). */
  spellNotInReview: 'SPELL_NOT_IN_REVIEW',
  /** La propia pluma jamás se firma (403, RF-03.2). */
  selfSigningProhibited: 'SELF_SIGNING_PROHIBITED',
  /** Veto constitucional del Artículo III (403, RF-03.1). */
  constitutionalEthicsVeto: 'CONSTITUTIONAL_ETHICS_VETO',
  /** Dos voces del mismo estandarte sobre una obra (409, RF-02.1). */
  clanPluralityViolation: 'CLAN_PLURALITY_VIOLATION',
  /** El Maestro ya avaló esta obra (409, RF-02.4). */
  alreadySigned: 'ALREADY_SIGNED',
  /** La glosa excede el canon de 250 caracteres (400, RF-02.2). */
  glossTooLong: 'GLOSS_TOO_LONG',
  /** No obra firma viva que retractar (400). */
  noActiveSignature: 'NO_ACTIVE_SIGNATURE',
  /** La consagración es irrevocable para los Maestros (409, RF-02.4). */
  signatureIrrevocable: 'SIGNATURE_IRREVOCABLE',
  /** El dictamen no alcanza los veinte caracteres (422, RF-02.5). */
  objectionTooBrief: 'OBJECTION_TOO_BRIEF',
  /** El rango no alcanza para juzgar en la Torre (403, RF-02.1). */
  insufficientRankToJudge: 'INSUFFICIENT_RANK_TO_JUDGE',
  /** Solo el Administrador Supremo decreta (403, RF-04). */
  insufficientSovereignRank: 'INSUFFICIENT_SOVEREIGN_RANK',
  /** La Firma Soberana solo alcanza obras en deliberación (400, RF-04.1). */
  cannotSovereignValidateNonExperimental: 'CANNOT_SOVEREIGN_VALIDATE_NON_EXPERIMENTAL',
  /** El rescate de oficio solo alcanza obras vetadas (400, RF-04.3). */
  cannotSovereignRescueNonRejected: 'CANNOT_SOVEREIGN_RESCUE_NON_REJECTED',
  /** Solo se destierra lo consagrado (400, RF-04.4). */
  cannotSovereignArchiveNonValidated: 'CANNOT_SOVEREIGN_ARCHIVE_NON_VALIDATED',
  /** El rescate solo admite experimental o validated (400, RF-04.3). */
  sovereignRescueInvalidTarget: 'SOVEREIGN_RESCUE_INVALID_TARGET',
  /** La potestad suprema no absuelve la propia pluma (403, RF-03.2). */
  selfValidationProhibited: 'SELF_VALIDATION_PROHIBITED',
  /** Veto del propio estandarte al Administrador (403, RF-04.2). */
  sovereignOwnClanVeto: 'SOVEREIGN_OWN_CLAN_VETO',
  /** El Edicto Imperial no alcanza los veinte caracteres (422, RF-04.5). */
  imperialDecreeTooShort: 'IMPERIAL_DECREE_TOO_SHORT',
  /** El barrido exige el sello del custodio en la cabecera (401). */
  cronSecretRequired: 'CRON_SECRET_REQUIRED',
  /** El sello presentado no autoriza la invocación (403). */
  cronSecretInvalid: 'CRON_SECRET_INVALID',
  /** Corte de red o servidor inalcanzable (contrato del proyecto). */
  networkError: 'MANA_STREAM_INTERRUPTED',
});

/** Cabecera ceremonial que porta el sello del custodio del letargo. */
export const CRON_SECRET_HEADER = 'X-Arcane-Cron-Secret';

/**
 * Leyendas ceremoniales del cliente (RNF-03).
 *
 * El santuario responde siempre con su propia leyenda en noble castellano;
 * estas se emplean SOLO cuando el sobre de error llega sin mensaje legible
 * —un proxy que devuelve HTML, un 502 sin cuerpo— para que la interfaz jamás
 * muestre un texto técnico al mago.
 */
export const MODERATION_CEREMONIAL_LEGENDS = Object.freeze({
  [MODERATION_ERROR_CODES.unauthenticated]: 'El vínculo arcano no está activo: conságrate o vincula tu identidad antes de acudir a la Torre.',
  [MODERATION_ERROR_CODES.spellNotFound]: 'Ese conjuro no existe o no habita tu grimorio.',
  [MODERATION_ERROR_CODES.invalidRequestBody]: 'El decreto ha de nombrar el conjuro sobre el que se dicta.',
  [MODERATION_ERROR_CODES.invalidQueryParams]: 'La página y su tamaño deben ser números enteros positivos.',
  [MODERATION_ERROR_CODES.spellNotInDraft]: 'Solo los borradores privados se elevan a la Torre: la obra ya no descansa en tu libreta.',
  [MODERATION_ERROR_CODES.spellNotUnderReview]: 'La obra no se halla en deliberación: ningún aval hay que anular.',
  [MODERATION_ERROR_CODES.spellNotRejected]: 'Solo una obra vetada se reabre como borrador.',
  [MODERATION_ERROR_CODES.towerCapacityExceeded]: 'La Torre de Moderación ya custodia tres de tus obras en deliberación. Aguarda su resolución antes de elevar nuevas plegarias.',
  [MODERATION_ERROR_CODES.insufficientRank]: 'Solo los magos consagrados —rango de editor o superior— elevan plegarias a la Torre de Moderación.',
  [MODERATION_ERROR_CODES.convalescenceActive]: 'En Convalecencia Arcana la pluma calla: aguarda el fin de los catorce días antes de elevar obras a la Torre.',
  [MODERATION_ERROR_CODES.underReviewImmutable]: 'La obra permanece en deliberación y es inmutable: retírala a tu libreta para enmendarla, asumiendo el reinicio de sus firmas.',
  [MODERATION_ERROR_CODES.spellAwaitingReopen]: 'La obra fue vetada y permanece inmutable en tu libreta: reábrela como borrador para enmendarla.',
  [MODERATION_ERROR_CODES.spellNotInReview]: 'Solo se juzga lo que yace en deliberación.',
  [MODERATION_ERROR_CODES.selfSigningProhibited]: 'Ningún Maestro avala su propia obra: el juicio de la propia pluma no es juicio (Artículo III).',
  [MODERATION_ERROR_CODES.constitutionalEthicsVeto]: 'Conflicto de intereses: no es lícito juzgar las obras nacidas bajo tu propio estandarte, linajes recientes o propia pluma.',
  [MODERATION_ERROR_CODES.clanPluralityViolation]: 'Pluralidad de hermandades: un mismo linaje no puede aportar dos voces sobre la misma obra.',
  [MODERATION_ERROR_CODES.alreadySigned]: 'Ya estampaste tu firma de consagración sobre esta obra: un aval vivo por Maestro y conjuro.',
  [MODERATION_ERROR_CODES.glossTooLong]: 'La glosa ceremonial no puede exceder los doscientos cincuenta caracteres.',
  [MODERATION_ERROR_CODES.noActiveSignature]: 'No obra firma viva tuya sobre esta obra: nada hay que retractar.',
  [MODERATION_ERROR_CODES.signatureIrrevocable]: 'La obra ya alcanzó la consagración: la tercera rúbrica es irrevocable para los Maestros.',
  [MODERATION_ERROR_CODES.objectionTooBrief]: 'El Dictamen de Objeción exige una justificación en castellano de al menos veinte caracteres.',
  [MODERATION_ERROR_CODES.insufficientRankToJudge]: 'Solo los Maestros del Cónclave pueden firmar, objetar o retractarse: el rango no alcanza para juzgar.',
  [MODERATION_ERROR_CODES.insufficientSovereignRank]: 'Solo el Administrador Supremo puede dictar decretos imperiales: la Firma Soberana no se delega.',
  [MODERATION_ERROR_CODES.cannotSovereignValidateNonExperimental]: 'La Firma Soberana solo alcanza obras en deliberación (Artículo II.3).',
  [MODERATION_ERROR_CODES.cannotSovereignRescueNonRejected]: 'El rescate de oficio solo alcanza obras vetadas.',
  [MODERATION_ERROR_CODES.cannotSovereignArchiveNonValidated]: 'Solo se destierra lo que fue consagrado.',
  [MODERATION_ERROR_CODES.sovereignRescueInvalidTarget]: 'El rescate de oficio solo admite dos destinos: experimental o validated.',
  [MODERATION_ERROR_CODES.selfValidationProhibited]: 'La potestad suprema no absuelve la propia pluma (Artículo III.2).',
  [MODERATION_ERROR_CODES.sovereignOwnClanVeto]: 'Veto del Artículo III.2: las obras de tu propio estandarte se someten al juicio imparcial de tres Maestros independientes.',
  [MODERATION_ERROR_CODES.imperialDecreeTooShort]: 'Todo Decreto Imperial exige un edicto de al menos veinte caracteres en castellano.',
  [MODERATION_ERROR_CODES.cronSecretRequired]: 'El barrido de caducidad exige el sello del custodio.',
  [MODERATION_ERROR_CODES.cronSecretInvalid]: 'El sello presentado no autoriza esta invocación del letargo.',
  [MODERATION_ERROR_CODES.networkError]: 'La corriente de maná hacia la Torre de Moderación se ha interrumpido.',
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

  return MODERATION_CEREMONIAL_LEGENDS[error.code] ?? 'La Torre de Moderación ha respondido con un presagio indescifrable.';
}

/**
 * Construye la query de lectura omitiendo parámetros ausentes.
 *
 * @param {object} params - element, school, minSignatures, page, perPage.
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
 * Crea el cliente HTTP de la moderación en dos pasos.
 *
 * @param {object} [options]
 *   - fetch:      implementación inyectable (arneses); por defecto, la nativa.
 *   - baseUrl:    base sobreescribible (despliegues alternativos); en el
 *                 navegador la relativa /api/v1 es la canónica.
 *   - token:      credencial Bearer OPCIONAL y aditiva (custodios, arneses);
 *                 la autoridad ordinaria sigue siendo la cookie HttpOnly.
 *   - cronSecret: sello del custodio para el barrido de caducidad; puede
 *     sobreescribirse en cada invocación de checkExpiryCron().
 * @returns {object} cliente con los doce métodos del plan.
 */
export function createModerationClient(options = {}) {
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
            ? MODERATION_ERROR_CODES.unauthenticated
            : response.status === 404
              ? MODERATION_ERROR_CODES.spellNotFound
              : MODERATION_ERROR_CODES.networkError,
          message: `La Torre de Moderación respondió con el estado ${response.status}.`,
          recoveryAction: 'RETRY',
        },
      };
    } catch {
      // Corte de red real: objeto controlado, jamás excepción al llamador.
      return {
        success: false,
        status: 0,
        error: {
          code: MODERATION_ERROR_CODES.networkError,
          message: MODERATION_CEREMONIAL_LEGENDS[MODERATION_ERROR_CODES.networkError],
          recoveryAction: 'RETRY',
        },
      };
    }
  }

  /** Codifica un identificador de ruta sin permitir inyección de segmentos. */
  const path = (value) => encodeURIComponent(String(value ?? ''));

  return {
    /**
     * Endpoint 1 (RF-01.2, RF-01.5): eleva un borrador a la Torre de
     * Moderación (Paso 1). La huella matemática la sella el backend (Art. II).
     *
     * @param {string} spellId Identificador del conjuro en la libreta.
     * @returns {Promise<object>} 200 con `{review, remainingCapacity}`,
     *   400 SPELL_NOT_IN_DRAFT, 401, 403 INSUFFICIENT_RANK o
     *   CONVALESCENCE_ACTIVE, 404 SPELL_NOT_FOUND, 409
     *   TOWER_CAPACITY_EXCEEDED.
     */
    submitSpell(spellId) {
      return requestJson(`${apiBase}/moderation/spells/${path(spellId)}/submit`, { method: 'POST' }, {});
    },

    /**
     * Endpoint 2 (RF-01.3): retira la obra a borrador privado, anulando
     * TODOS sus avales previos con el motivo `author_withdrawn`.
     *
     * @param {string} spellId Identificador del conjuro en deliberación.
     * @returns {Promise<object>} 200 con `{review, remainingCapacity}`,
     *   401, 404 (inexistente o ajeno: la obra ajena responde 404, no 403),
     *   409 SPELL_NOT_UNDER_REVIEW.
     */
    withdrawSpell(spellId) {
      return requestJson(`${apiBase}/moderation/spells/${path(spellId)}/withdraw`, { method: 'POST' }, {});
    },

    /**
     * Endpoint 3 (RF-01.4, RF-06.2): «Reabrir como Borrador». La obra vetada
     * vuelve a `draft` con el dictamen ÍNTEGRO a la vista en `lastVerdict`.
     *
     * @param {string} spellId Identificador del conjuro vetado.
     * @returns {Promise<object>} 200 con `{review, lastVerdict,
     *   remainingCapacity}`, 400 SPELL_NOT_REJECTED, 401, 404.
     */
    reopenSpell(spellId) {
      return requestJson(`${apiBase}/moderation/spells/${path(spellId)}/reopen`, { method: 'POST' }, {});
    },

    /**
     * Endpoint 4 (RF-05.1, RF-05.3): catálogo PÚBLICO del Atrio de Pruebas.
     *
     * La lectura no exige vínculo arcano: cualquier visitante contempla las
     * obras en deliberación con su insignia `hallWarning` (una sola por
     * catálogo, no una por tarjeta) y el medidor `0/3` de cada obra.
     *
     * @param {object} [params] {element, school, page, perPage}.
     * @returns {Promise<object>} 200 con `{hallWarning, items, pagination}`
     *   o 400 INVALID_QUERY_PARAMS.
     */
    fetchExperimentalHall(params = {}) {
      return requestJson(`${apiBase}/moderation/experimental${buildQuery(params)}`, { method: 'GET' });
    },

    /**
     * Endpoint 5 (RF-05.4, RF-03.1): cola de la Torre de Deliberación.
     *
     * Exclusiva de Maestros y del Administrador Supremo (RF-03.5). Cada
     * elemento porta el veredicto ético YA RESUELTO en servidor:
     * `hasEthicalConflict` y `ethicalVeto: {code, legend}` —`ownAuthorship`
     * o `clanIncompatibility`—, porque la aritmética del Artículo III
     * jamás se reimplementa en el navegador.
     *
     * @param {object} [params] {element, school, minSignatures, page, perPage}.
     * @returns {Promise<object>} 200 con `{canon, items, pagination}`,
     *   401, 403 INSUFFICIENT_RANK_TO_JUDGE, 400 INVALID_QUERY_PARAMS
     *   (también con un `minSignatures` por encima del techo de tres).
     */
    fetchDeliberationQueue(params = {}) {
      return requestJson(`${apiBase}/moderation/queue${buildQuery(params)}`, { method: 'GET' });
    },

    /**
     * Endpoint 6 (RF-02.1, RF-02.2, RF-02.3): estampa la Firma de
     * Consagración de un Maestro. Al alcanzar la 3ª firma el backend
     * consagra la obra de forma atómica y acredita los PDA (SPEC-07).
     *
     * El canon de la glosa (máx. 250) lo dicta el santuario: el cliente
     * no la recorta ni la valida por su cuenta (Art. II).
     *
     * @param {string}   spellId Identificador de la obra en deliberación.
     * @param {string}   [ceremonialGloss] Glosa litúrgica opcional.
     * @returns {Promise<object>} 200 con `{review, consecrated}`, 400
     *   GLOSS_TOO_LONG, 401, 403 SELF_SIGNING_PROHIBITED /
     *   CONSTITUTIONAL_ETHICS_VETO / INSUFFICIENT_RANK_TO_JUDGE, 404, 409
     *   ALREADY_SIGNED / CLAN_PLURALITY_VIOLATION / SPELL_NOT_IN_REVIEW.
     */
    signSpell(spellId, ceremonialGloss = '') {
      const gloss = typeof ceremonialGloss === 'string' ? ceremonialGloss : '';

      return requestJson(
        `${apiBase}/moderation/spells/${path(spellId)}/sign`,
        { method: 'POST' },
        gloss.trim() !== '' ? { ceremonialGloss: gloss } : {},
      );
    },

    /**
     * Endpoint 7 (RF-02.4): retractación voluntaria de la firma mientras
     * la obra no haya sido consagrada.
     *
     * @param {string} spellId Identificador de la obra avalada.
     * @param {string} [reason] Motivo de la retractación (opcional).
     * @returns {Promise<object>} 200 con `{review, signaturesCount,
     *   signaturesIndicator}`, 400 NO_ACTIVE_SIGNATURE, 401, 403
     *   INSUFFICIENT_RANK_TO_JUDGE, 404, 409 SIGNATURE_IRREVOCABLE.
     */
    retractSignature(spellId, reason = '') {
      const motive = typeof reason === 'string' ? reason : '';

      return requestJson(
        `${apiBase}/moderation/spells/${path(spellId)}/retract`,
        { method: 'POST' },
        motive.trim() !== '' ? { reason: motive } : {},
      );
    },

    /**
     * Endpoint 8 (RF-02.5, RF-02.6): Dictamen de Objeción Fundamentada.
     * El umbral de los veinte caracteres lo dicta el santuario (422), no
     * el cliente (Art. II: jamás se coacciona una justificación).
     *
     * @param {string} spellId Identificador de la obra en deliberación.
     * @param {string} objectionReason Justificación solemne en castellano.
     * @returns {Promise<object>} 200 con `{review, verdict}`, 401, 403
     *   SELF_SIGNING_PROHIBITED / CONSTITUTIONAL_ETHICS_VETO /
     *   INSUFFICIENT_RANK_TO_JUDGE, 404, 409 SPELL_NOT_IN_REVIEW,
     *   422 OBJECTION_TOO_BRIEF.
     */
    objectSpell(spellId, objectionReason = '') {
      return requestJson(
        `${apiBase}/moderation/spells/${path(spellId)}/object`,
        { method: 'POST' },
        { objectionReason: typeof objectionReason === 'string' ? objectionReason : '' },
      );
    },

    /**
     * Endpoint 9 (RF-04.1, RF-04.2, RF-04.5): Firma Soberana Instantánea
     * del Administrador Supremo. El objetivo del decreto viaja en el
     * CUERPO (`spellId`), no en la ruta, tal como exige el contrato.
     *
     * @param {string} spellId             Identificador de la obra.
     * @param {string} imperialDecreeText  Edicto Imperial obligatorio (≥ 20 car.).
     * @returns {Promise<object>} 200 con `{review, decree}`, 400
     *   INVALID_REQUEST_BODY / CANNOT_SOVEREIGN_VALIDATE_NON_EXPERIMENTAL,
     *   401, 403 INSUFFICIENT_SOVEREIGN_RANK / SOVEREIGN_OWN_CLAN_VETO /
     *   SELF_VALIDATION_PROHIBITED, 404, 422 IMPERIAL_DECREE_TOO_SHORT.
     */
    sovereignValidate(spellId, imperialDecreeText = '') {
      return requestJson(
        `${apiBase}/moderation/sovereign/validate`,
        { method: 'POST' },
        {
          spellId: String(spellId ?? ''),
          imperialDecreeText: typeof imperialDecreeText === 'string' ? imperialDecreeText : '',
        },
      );
    },

    /**
     * Endpoint 10 (RF-04.3, RF-04.5): rescate de obra vetada. El destino
     * `experimental` reinicia la deliberación limpia con `0/3`; el destino
     * `validated` consagra directamente y acredita la gloria negada.
     *
     * @param {string} spellId            Identificador de la obra vetada.
     * @param {string} targetStatus       Destino canónico: 'experimental' | 'validated'.
     * @param {string} imperialDecreeText Edicto Imperial obligatorio (≥ 20 car.).
     * @returns {Promise<object>} 200 con `{review, decree}`, 400
     *   INVALID_REQUEST_BODY / CANNOT_SOVEREIGN_RESCUE_NON_REJECTED /
     *   SOVEREIGN_RESCUE_INVALID_TARGET, 401, 403, 404, 422.
     */
    sovereignRescue(spellId, targetStatus, imperialDecreeText = '') {
      return requestJson(
        `${apiBase}/moderation/sovereign/rescue`,
        { method: 'POST' },
        {
          spellId: String(spellId ?? ''),
          targetStatus: String(targetStatus ?? ''),
          imperialDecreeText: typeof imperialDecreeText === 'string' ? imperialDecreeText : '',
        },
      );
    },

    /**
     * Endpoint 11 (RF-04.4, RF-04.5): destierro póstumo de una obra
     * consagrada. La orden de deducción es una LEY, no una preferencia:
     * debe viajar como booleano explícito o el santuario responde 400
     * (jamás se coacciona en silencio).
     *
     * @param {string}  spellId            Identificador de la obra consagrada.
     * @param {string}  imperialDecreeText Edicto Imperial obligatorio (≥ 20 car.).
     * @param {boolean} [deductPoints]     Orden explícita de deducción retroactiva de PDA.
     * @returns {Promise<object>} 200 con `{review, decree,
     *   gloryDeductionOrdered}`, 400 INVALID_REQUEST_BODY /
     *   CANNOT_SOVEREIGN_ARCHIVE_NON_VALIDATED, 401, 403, 404, 422.
     */
    sovereignArchive(spellId, imperialDecreeText = '', deductPoints = false) {
      return requestJson(
        `${apiBase}/moderation/sovereign/archive`,
        { method: 'POST' },
        {
          spellId: String(spellId ?? ''),
          imperialDecreeText: typeof imperialDecreeText === 'string' ? imperialDecreeText : '',
          deductPoints: deductPoints === true,
        },
      );
    },

    /**
     * Endpoint 12 (RF-01.6): barrido de caducidad por letargo (90 días).
     * Lo invoca un planificador, no un mago: el sello viaja SIEMPRE como
     * cabecera, jamás en el cuerpo ni en la query.
     *
     * @param {string} [secret] Sello del custodio; si falta, se usa el
     *   declarado al crear el cliente. Sin sello, el santuario falla cerrado.
     * @returns {Promise<object>} 200 con `{legend, staleDays, expiredCount,
     *   expired}`, 401 CRON_SECRET_REQUIRED, 403 CRON_SECRET_INVALID.
     */
    checkExpiryCron(secret) {
      const presented = typeof secret === 'string' && secret.trim() !== '' ? secret.trim() : cronSecret;

      return requestJson(`${apiBase}/moderation/cron-check-expiry`, {
        method: 'POST',
        headers: { ...(presented !== null ? { [CRON_SECRET_HEADER]: presented } : {}) },
      });
    },
  };
}
