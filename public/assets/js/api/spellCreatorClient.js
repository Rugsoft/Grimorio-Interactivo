/**
 * spellCreatorClient.js — Cliente HTTP nativo del Taller de Hechizos.
 *
 * Tarea 5.2 (TASKS-04): funciones nativas ES Modules calculateSpell,
 * saveDraft, listDrafts, updateDraft, deleteDraft, publishSpell,
 * updateExperimental y createVariant (plan 2.2, Endpoints 1-6 + 2-3),
 * con gestión de cookies de sesión automática (credentials 'same-origin')
 * y manejo homogéneo de los errores canónicos del servidor.
 *
 * Constitución:
 *   - Artículo I (Dogma Vanilla): fetch nativo; cero axios/librerías HTTP.
 *   - Artículo II: el cliente JAMÁS envía ni calcula costes de maná; solo
 *     porta parámetros — el desglose lo determina el backend.
 *   - Artículo V: funciones en inglés camelCase; comentarios en castellano.
 *
 * Contrato de respuesta (idéntico a authClient.js):
 *   - Toda función retorna SIEMPRE un objeto controlado { success, status,
 *     data | error } — jamás lanza hacia las vistas o el store reactivo.
 *   - result.status porta el código HTTP real para que la vista del Taller
 *     (Tarea 5.5) reaccione a 400/401/403/404.
 */

/** Códigos de error canónicos del Taller (sobres del backend, SPEC-04). */
export const SPELL_CREATOR_ERROR_CODES = Object.freeze({
  /** Sobrecarga Arcana: el maná supera el techo de 200 (400). */
  arcaneOverload: 'ARCANE_OVERLOAD',
  /** Payload fuera del contrato: campos ausentes, tipos sucios o canon violado (400). */
  invalidInput: 'INVALID_SPELL_INPUT',
  /** Conflicto de integridad: nombre canónico duplicado (400). */
  spellConflict: 'SPELL_CONFLICT',
  /** Sin vínculo activo: la sesión no está autenticada (401). */
  unauthenticated: 'UNAUTHENTICATED',
  /** El validado es patrimonio inmutable (403, recoveryAction CREATE_VARIANT). */
  spellImmutable: 'SPELL_IMMUTABLE',
  /** Cuota de 10 borradores simultáneos agotada (403). */
  draftQuotaExceeded: 'DRAFT_QUOTA_EXCEEDED',
  /** Conjuro inexistente o ajeno al invocante (404). */
  spellNotFound: 'SPELL_NOT_FOUND',
  /** Corte de red o servidor inalcanzable (contrato del proyecto). */
  networkError: 'MANA_STREAM_INTERRUPTED',
});

/** Base de la API (plan 2.2). */
const API_BASE = '/api/v1';

/**
 * Envoltura pacífica: ejecuta la petición y convierte CUALQUIER fallo
 * (red, JSON corrupto, sobre inesperado) en el error controlado del
 * proyecto. Las cookies de sesión viajan siempre con credentials
 * 'same-origin' (el navegador adjunta grimorio_session HttpOnly).
 *
 * @param {string}   requestUrl URL completa a solicitar.
 * @param {object}   [init]     Opciones de fetch (method, body...).
 * @param {object|null} [body]  Payload JSON a serializar (null = sin cuerpo).
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
    // Cookies automáticas de sesión (Art. I, SPEC-03): el navegador
    // adjunta la cookie HttpOnly sin que JavaScript la toque jamás.
    credentials: 'same-origin',
  };
  if (body !== null) {
    requestInit.body = JSON.stringify(body);
  }

  try {
    const response = await fetch(requestUrl, requestInit);

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
        code: SPELL_CREATOR_ERROR_CODES.networkError,
        message: `El santuario respondió con el estado ${response.status}.`,
      },
    };
  } catch {
    // Corte de red real: objeto controlado, jamás excepción al llamador.
    return {
      success: false,
      status: 0,
      error: {
        code: SPELL_CREATOR_ERROR_CODES.networkError,
        message: 'La corriente de maná se ha interrumpido.',
        recoveryAction: 'RETRY',
      },
    };
  }
}

/**
 * POST /api/v1/spells/calculate — Simulación y desglose pedagógico de
 * maná (Endpoint 1). Pública y sin estado: no exige sesión.
 *
 * @param {object} spellInput Los 10 parámetros matemáticos del conjuro.
 * @returns {Promise<object>} { success, status, data | error } con el
 *   desglose pedagógico, o el sobre 400 ARCANE_OVERLOAD / INVALID_SPELL_INPUT.
 */
export async function calculateSpell(spellInput) {
  return requestJson(`${API_BASE}/spells/calculate`, { method: 'POST' }, spellInput);
}

/**
 * POST /api/v1/spells/drafts — Guardar borrador privado (Endpoint 2).
 *
 * @param {object} spellCreate Payload narrativo + cuantitativo completo.
 * @returns {Promise<object>} 201 con { id, slug, status, manaCost, ... },
 *   400 (INVALID_SPELL_INPUT / SPELL_CONFLICT) o 403 DRAFT_QUOTA_EXCEEDED.
 */
export async function saveDraft(spellCreate) {
  return requestJson(`${API_BASE}/spells/drafts`, { method: 'POST' }, spellCreate);
}

/**
 * GET /api/v1/spells/drafts — Listar borradores del autor activo
 * (Endpoint 3). Privacidad estricta en el backend: solo los propios.
 *
 * @returns {Promise<object>} 200 con la lista { id, slug, name, manaCost,
 *   circleLabel, updatedAt } del autor.
 */
export async function listDrafts() {
  return requestJson(`${API_BASE}/spells/drafts`, { method: 'GET' });
}

/**
 * PUT /api/v1/spells/drafts/{id} — Actualizar borrador propio.
 *
 * @param {string} spellId    Identificador del borrador (spl_*).
 * @param {object} spellCreate Payload narrativo + cuantitativo completo.
 * @returns {Promise<object>} 200 con el maná recalculado, 400, 403
 *   SPELL_IMMUTABLE o 404 SPELL_NOT_FOUND.
 */
export async function updateDraft(spellId, spellCreate) {
  return requestJson(`${API_BASE}/spells/drafts/${encodeURIComponent(spellId)}`, { method: 'PUT' }, spellCreate);
}

/**
 * DELETE /api/v1/spells/drafts/{id} — Retirar borrador propio.
 *
 * @param {string} spellId Identificador del borrador (spl_*).
 * @returns {Promise<object>} 200 con { deleted: true }, 403 o 404.
 */
export async function deleteDraft(spellId) {
  return requestJson(`${API_BASE}/spells/drafts/${encodeURIComponent(spellId)}`, { method: 'DELETE' });
}

/**
 * POST /api/v1/spells/publish/{id} — Publicar a estado experimental
 * (Endpoint 4): transición con firmas a 0/3.
 *
 * @param {string} spellId Identificador del borrador (spl_*).
 * @returns {Promise<object>} 200 con { status: 'experimental',
 *   signaturesCount: 0 }, 400, 401 o 404.
 */
export async function publishSpell(spellId) {
  return requestJson(`${API_BASE}/spells/publish/${encodeURIComponent(spellId)}`, { method: 'POST' });
}

/**
 * PUT /api/v1/spells/experimental/{id} — Edición en moderación con
 * antifraude de firmas (Endpoint 5).
 *
 * @param {string} spellId    Identificador del conjuro experimental.
 * @param {object} spellCreate Payload narrativo + cuantitativo completo.
 * @returns {Promise<object>} 200 con { signaturesReset, signaturesCount },
 *   400, 401, 403 SPELL_IMMUTABLE o 404.
 */
export async function updateExperimental(spellId, spellCreate) {
  return requestJson(`${API_BASE}/spells/experimental/${encodeURIComponent(spellId)}`, { method: 'PUT' }, spellCreate);
}

/**
 * POST /api/v1/spells/variant/{id} — Clonación como Variante
 * independiente (Endpoint 6, RF-06.3): nace draft con 0/3 firmas.
 *
 * @param {string} spellId Identificador del conjuro VALIDADO origen.
 * @returns {Promise<object>} 201 con la ficha de la variante, 400, 401 o 404.
 */
export async function createVariant(spellId) {
  return requestJson(`${API_BASE}/spells/variant/${encodeURIComponent(spellId)}`, { method: 'POST' });
}
