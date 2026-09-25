/**
 * userPanelClient.js — Cliente HTTP nativo del Panel del Adepto
 * (SPEC-12, Tarea 5.1; plan §1.2).
 *
 * Los cinco endpoints del contrato (plan §2):
 *
 *   fetchPanel()                       GET    /api/v1/panel
 *   fetchAvatarCatalog()               GET    /api/v1/panel/avatars
 *   chooseAvatar(mode, payload)        POST   /api/v1/panel/avatar
 *   removeAvatar()                     DELETE /api/v1/panel/avatar
 *   changePassphrase(payload)          POST   /api/v1/panel/passphrase
 *   fetchLedger(cursor)                GET    /api/v1/panel/ledger
 *
 * Constitución:
 *   - Artículo I (Dogma Vanilla): fetch nativo; cero axios ni librerías
 *     HTTP; ES Modules nativos (RNF-02).
 *   - Artículo II: el cliente JAMÁS decide el canon — no valida frases,
 *     no encuadra imágenes, no calcula convalecencia: porta la intención
 *     y propaga el veredicto del santuario tal cual, con su código y su
 *     leyenda.
 *   - Artículo IV (El Velo Arcano): toda leyenda viaja en noble
 *     castellano.
 *   - Artículo V: funciones en inglés camelCase; comentarios y leyendas
 *     en castellano.
 *
 * Contrato de respuesta (idéntico a grimoireCollectionClient.js):
 *   - Toda función retorna SIEMPRE un objeto controlado
 *     { success, status, data | error } — jamás lanza hacia las vistas
 *     (el fallo ciego de la frase, la retención del peregrino y el
 *     aviso de imagen idéntica NO son excepciones: son veredictos).
 *   - `status` porta el código HTTP real para que la vista distinga 401
 *     (degradación solemne, RF-01.4), 403 (juramento), 400 (motivo
 *     nombrado del avatar) y 500 (velo arcano).
 *   - El sobre de error del backend se propaga ÍNTEGRO: `code`, `message`
 *     y `recoveryAction` viajan intactos, y sobreviven campos futuros.
 *
 * Credenciales:
 *   - La autoridad de sesión del santuario es la cookie HttpOnly de
 *     SPEC-03, que viaja sola con `credentials: 'same-origin'` y que
 *     JavaScript jamás toca.
 */

/** Códigos de error canónicos del panel (SPEC-12 + heredados). */
export const PANEL_ERROR_CODES = Object.freeze({
  /** Sin vínculo arcano activo (401, SPEC-03 — degradación solemne, RF-01.4). */
  unauthenticated: 'UNAUTHENTICATED',
  /** Retención de sustancia del peregrino (403, RF-01.3, hallazgo 12). */
  lineageOathRequired: 'LINEAGE_OATH_REQUIRED',
  /** Re-subida de imagen idéntica a la vigente (400, caso límite 18). */
  avatarIdentical: 'AVATAR_IDENTICAL',
  /** El canon de efigies no responde (500, caso límite 14). */
  avatarCatalogUnavailable: 'AVATAR_CATALOG_UNAVAILABLE',
  /** Envío de efigie sin mutación del vigente (400/413, RF-03.2). */
  avatarStoreFailed: 'AVATAR_STORE_FAILED',
  /** Fallo ciego único de la custodia de la frase (400, RF-04.1). */
  passphraseChangeFailed: 'PASSPHRASE_CHANGE_FAILED',
  /** Nueva frase idéntica a la vigente (400, caso límite 17). */
  passphraseIdentical: 'PASSPHRASE_IDENTICAL',
  /** La vitrina, el canon o la custodia no pueden iluminarse (500). */
  panelUnavailable: 'PANEL_UNAVAILABLE',
  /** Corte de red o servidor inalcanzable (contrato del proyecto). */
  networkError: 'MANA_STREAM_INTERRUPTED',
});

/**
 * Leyendas ceremoniales (Art. V): el texto que la interfaz muestra
 * cuando el sobre llega sin mensaje legible —un proxy que devuelve
 * HTML, un 502 sin cuerpo— para que jamás se pinte un texto técnico.
 */
export const PANEL_CEREMONIAL_LEGENDS = Object.freeze({
  [PANEL_ERROR_CODES.unauthenticated]:
    'Tu vínculo con el santuario ha expirado: renuévalo y tu morada aguardará donde la dejaste.',
  [PANEL_ERROR_CODES.lineageOathRequired]:
    'Tu efigie aguarda al juramento: la ceremonia de linaje te espera.',
  [PANEL_ERROR_CODES.avatarIdentical]:
    'La imagen ya viste tu identidad.',
  [PANEL_ERROR_CODES.avatarCatalogUnavailable]:
    'El canon de efigies no responde en este instante: inténtalo de nuevo en breve.',
  [PANEL_ERROR_CODES.avatarStoreFailed]:
    'La efigie no pudo vestirse en este instante: tu identidad conserva su efigie anterior.',
  [PANEL_ERROR_CODES.passphraseChangeFailed]:
    'La custodia no pudo consumarse: revisa tus credenciales y la solidez de la nueva frase.',
  [PANEL_ERROR_CODES.passphraseIdentical]:
    'La nueva frase coincide con la vigente.',
  [PANEL_ERROR_CODES.panelUnavailable]:
    'El panel no puede iluminarse en este instante: inténtalo de nuevo en breve.',
  [PANEL_ERROR_CODES.networkError]:
    'La corriente de maná hacia tu morada se ha interrumpido.',
});

/** Base de la API (idéntica al resto de clientes del santuario). */
const API_BASE = '/api/v1';

/**
 * Crea el cliente HTTP del Panel del Adepto.
 *
 * @param {object} [options]
 *   - fetch:   implementación inyectable (arneses); por defecto, la nativa.
 *   - baseUrl: base sobreescribible (despliegues alternativos); en el
 *     navegador la relativa /api/v1 es la canónica.
 * @returns {object} cliente con los cinco métodos del contrato.
 */
export function createUserPanelClient(options = {}) {
  const fetchImpl = options.fetch ?? ((url, init) => fetch(url, init));
  const apiBase = options.baseUrl ?? API_BASE;

  /**
   * Envoltura pacífica: ejecuta la petición y convierte CUALQUIER fallo
   * (red, JSON ilegible, estado sin sobre) en el error controlado del
   * proyecto — jamás una excepción hacia las vistas.
   *
   * @param {string}      requestUrl URL completa a solicitar.
   * @param {object}      [init]     Opciones de fetch (method, body binario...).
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
      // sin exponer JAMÁS el cuerpo técnico recibido (RNF-04).
      return {
        success: false,
        status: response.status,
        error: {
          code: response.status === 401
            ? PANEL_ERROR_CODES.unauthenticated
            : response.status === 403
              ? PANEL_ERROR_CODES.lineageOathRequired
              : PANEL_ERROR_CODES.panelUnavailable,
          message: `El panel respondió con el estado ${response.status}.`,
          recoveryAction: 'RETRY',
        },
      };
    } catch {
      // Corte de red real: objeto controlado, jamás excepción al llamador.
      return {
        success: false,
        status: 0,
        error: {
          code: PANEL_ERROR_CODES.networkError,
          message: PANEL_CEREMONIAL_LEGENDS[PANEL_ERROR_CODES.networkError],
          recoveryAction: 'RETRY',
        },
      };
    }
  }

  return {
    /**
     * GET /panel — la vitrina de la identidad íntegra (RF-01, RF-02,
     * RF-07; plan §2.2).
     *
     * @returns {Promise<object>} 200 con el sobre UserPanelDto;
     *   401 UNAUTHENTICATED; 500 PANEL_UNAVAILABLE sin trazas.
     */
    fetchPanel() {
      return requestJson(`${apiBase}/panel`, { method: 'GET' });
    },

    /**
     * GET /panel/avatars — el catálogo canónico de efigies (RF-03.1,
     * plan §2.3).
     *
     * @returns {Promise<object>} 200 con el sobre AvatarCatalogDto;
     *   401; 500 AVATAR_CATALOG_UNAVAILABLE (caso límite 14).
     */
    fetchAvatarCatalog() {
      return requestJson(`${apiBase}/panel/avatars`, { method: 'GET' });
    },

    /**
     * POST /panel/avatar — alta/elección de efigie (RF-03.1, RF-03.6;
     * plan §2.4).
     *
     * Modo `catalog`: cuerpo JSON { mode: 'catalog', avatarId }.
     * Modo `own`: FormData con el fichero (el navegador pone el
     * Content-Type multipart; el cliente NO lo toca en ese caso).
     *
     * 200 con efecto real o inocuo (`identical`); 400
     * INVALID_AVATAR_FORMAT / AVATAR_TOO_LARGE /
     * AVATAR_DIMENSIONS_EXCEEDED / AVATAR_IDENTICAL; 403
     * LINEAGE_OATH_REQUIRED; 401; 413; 500 AVATAR_STORE_FAILED.
     *
     * @param {'catalog'|'own'} mode Modo del envío.
     * @param {string|FormData} payload avatarId (catalog) o FormData (own).
     * @returns {Promise<object>} Sobre con { avatar, auditRecorded, identical }.
     */
    chooseAvatar(mode, payload) {
      if (mode === 'own') {
        // El fichero viaja como multipart nativo; sin Content-Type manual.
        return requestJson(`${apiBase}/panel/avatar`, {
          method: 'POST',
          headers: {},
          body: payload,
        });
      }
      return requestJson(
        `${apiBase}/panel/avatar`,
        { method: 'POST' },
        { mode: 'catalog', avatarId: String(payload ?? '') },
      );
    },

    /**
     * DELETE /panel/avatar — retiro al canónico (RF-03.4, RF-03.5;
     * plan §2.5).
     *
     * @returns {Promise<object>} 200 con { avatar: { kind: 'default' } };
     *   403 peregrino; 401 caducada; 500.
     */
    removeAvatar() {
      return requestJson(`${apiBase}/panel/avatar`, { method: 'DELETE' });
    },

    /**
     * POST /panel/passphrase — la custodia de la frase de paso (RF-04,
     * cuatro salidas; plan §2.6).
     *
     * 200 changed (con othersDissolvedCount) / 200 idempotentReceipt;
     * 400 PASSPHRASE_CHANGE_FAILED (ciego) / PASSPHRASE_IDENTICAL;
     * 401 caducada sin mutación parcial. El cuerpo JAMÁS se registra.
     *
     * @param {string} currentPassphrase Frase vigente del adepto.
     * @param {string} newPassphrase Nueva frase (primera entrada).
     * @param {string} newPassphraseRepeat Nueva frase (confirmación).
     * @returns {Promise<object>} Sobre con el veredicto del santuario.
     */
    /**
     * GET /panel/ledger?cursor={cursor} — la Lente de Bitácora Personal
     * (RF-06.1/06.2, Tareas 4.1/4.2 del backend; plan §2.7).
     *
     * Pura lente de lectura: 20 asientos por página, cursor opaco, sin
     * parámetro de identidad ajena (403 LEDGER_NOT_YOURS si se colara).
     *
     * @param {string|null} [cursor] Cursor opaco de la página anterior.
     * @returns {Promise<object>} 200 con { entries, nextCursor };
     *   401; 403 LEDGER_NOT_YOURS; 500 PANEL_UNAVAILABLE.
     */
    fetchLedger(cursor = null) {
      const query = cursor !== null && cursor !== ''
        ? `?cursor=${encodeURIComponent(String(cursor))}`
        : '';
      return requestJson(`${apiBase}/panel/ledger${query}`, { method: 'GET' });
    },

    changePassphrase(currentPassphrase, newPassphrase, newPassphraseRepeat) {
      return requestJson(
        `${apiBase}/panel/passphrase`,
        { method: 'POST' },
        {
          currentPassphrase: String(currentPassphrase ?? ''),
          newPassphrase: String(newPassphrase ?? ''),
          newPassphraseRepeat: String(newPassphraseRepeat ?? ''),
        },
      );
    },
  };
}
