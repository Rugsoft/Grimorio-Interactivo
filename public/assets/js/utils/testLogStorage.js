/**
 * testLogStorage.js — Bitácora de Pruebas persistente del Simulador.
 *
 * Tarea 4.3 (TASKS-05): registra cronológicamente los últimos cinco (5)
 * impactos contra el maniquí en `localStorage` bajo la clave
 * `grimorio_test_log_v1` (esquema del plan 2.2), permitiendo su limpieza
 * al pulsar «Restaurar Maniquí» (RF-02.6) y su consulta entre sesiones
 * de prueba (RF-05.3).
 *
 * Constitución:
 *   - Artículo I (Dogma Vanilla): localStorage nativo; cero IndexedDB o
 *     librerías de persistencia. El storage es inyectable para los arneses.
 *   - Artículo V: claves del esquema en inglés camelCase; API en inglés.
 *
 * Cobertura: RF-05.3 (bitácora de 5 impactos persistente),
 * RF-02.6 (limpieza al restaurar el maniquí).
 */

/** Clave canónica del plan 2.2. */
export const TEST_LOG_STORAGE_KEY = 'grimorio_test_log_v1';

/** Máximo canónico de entradas retenidas (RF-05.3). */
export const TEST_LOG_MAX_ENTRIES = 5;

/** Campos obligatorios de una entrada válida (plan 2.2). */
const REQUIRED_FIELDS = ['timestamp', 'spellName'];

/**
 * Crea el gestor de la bitácora de pruebas.
 *
 * @param {object} [options]
 *   - storage: implementación inyectable con getItem/setItem/removeItem
 *     (por defecto, window.localStorage; null → memoria efímera).
 *   - maxEntries: máximo de entradas (5 canónico).
 *   - sessionId: identificador de sesión inyectable (arneses); por defecto
 *     se genera uno estable mientras persista la bitácora.
 *   - now: reloj inyectable ({ now: () => ISO }) para marcas temporales.
 * @returns {object} API: recordImpact, getEntries, clear, getMetadata.
 */
export function createTestLogStorage(options = {}) {
  const maxEntries = options.maxEntries ?? TEST_LOG_MAX_ENTRIES;
  const storage = options.storage ?? null; // null → memoria efímera
  const clock = options.now ?? (() => new Date().toISOString());
  const memoryFallback = storage ? null : { sessionId: null, logs: [] };

  /** Lee el esquema bruto (plan 2.2) tolerando corrupción. */
  function readSchema() {
    if (!storage) {
      return memoryFallback;
    }
    let raw = null;
    try {
      raw = storage.getItem(TEST_LOG_STORAGE_KEY);
    } catch {
      return memoryFallback;
    }
    if (!raw) {
      return { sessionId: null, logs: [] };
    }
    try {
      const parsed = JSON.parse(raw);
      if (parsed === null || typeof parsed !== 'object' || !Array.isArray(parsed.logs)) {
        return { sessionId: null, logs: [] }; // esquema roto: reinicio limpio
      }
      return parsed;
    } catch {
      return { sessionId: null, logs: [] }; // JSON corrupto: reinicio limpio
    }
  }

  /** Persiste el esquema con límites canónicos (silencioso si falla). */
  function writeSchema(schema) {
    if (!storage) {
      memoryFallback.sessionId = schema.sessionId;
      memoryFallback.logs = schema.logs;
      return;
    }
    try {
      storage.setItem(TEST_LOG_STORAGE_KEY, JSON.stringify({
        sessionId: schema.sessionId,
        maxEntries,
        logs: schema.logs,
      }));
    } catch {
      // Cuota agotada o storage bloqueado: la bitácora es prescindible
      // para el santuario; el fallo jamás interrumpe la prueba.
    }
  }

  /** Identificador de sesión estable (se crea al primer uso). */
  function ensureSessionId(schema) {
    if (!schema.sessionId) {
      schema.sessionId = options.sessionId
        ?? `ses_${Math.random().toString(36).slice(2, 10)}`;
    }
    return schema.sessionId;
  }

/**
 * Registra un impacto contra el maniquí (RF-05.3). La entrada más
 * reciente encabeza la lista; al superar el máximo, la más antigua se
 * descarta.
 *
 * Campos de combo (SPEC-06, Tarea 4.3, RF-06.3) — optativos: solo la
 * detonación de una reacción los porta (espejo del veredicto del
 * resolutor):
 *   - comboTag: etiqueta distintiva (ej. «[Combo: Electrocución Fluida]»).
 *   - comboElements: elementos intervinientes { activeAura, incoming }.
 *   - comboDamageDealt: daño total asestado por la reacción.
 *
 * @param {object} impact - { spellName, elementalAffinity, circle,
 *   manaCost, damageDealt, barrierAbsorbed, healingApplied,
 *   crowdControlApplied, dummyRemainingHealth, comboTag?, comboElements?,
 *   comboDamageDealt? }.
 * @returns {object[]} entradas tras el registro.
 */
  function recordImpact(impact) {
    const schema = readSchema();
    ensureSessionId(schema);

    const entry = {
      timestamp: clock(),
      spellName: String(impact?.spellName ?? 'Conjuro sin nombre'),
      elementalAffinity: impact?.elementalAffinity ?? null,
      circle: Number(impact?.circle ?? 0) || null,
      manaCost: Number(impact?.manaCost ?? 0) || 0,
      damageDealt: Number(impact?.damageDealt ?? 0) || 0,
      barrierAbsorbed: Number(impact?.barrierAbsorbed ?? 0) || 0,
      healingApplied: Number(impact?.healingApplied ?? 0) || 0,
      crowdControlApplied: impact?.crowdControlApplied ?? 'none',
      dummyRemainingHealth: Number(impact?.dummyRemainingHealth ?? 0) || 0,
      // Campos de combo (RF-06.3): presentes solo si el veredicto detona
      // una reacción; la imbuición, el refresco y la sobreescritura no
      // llevan etiqueta alguna.
      ...(impact?.comboTag !== undefined ? { comboTag: String(impact.comboTag) } : {}),
      ...(impact?.comboElements !== undefined ? { comboElements: impact.comboElements } : {}),
      ...(impact?.comboDamageDealt !== undefined ? { comboDamageDealt: Number(impact.comboDamageDealt) || 0 } : {}),
    };

    schema.logs.unshift(entry);
    if (schema.logs.length > maxEntries) {
      schema.logs.length = maxEntries; // descarta las más antiguas
    }
    writeSchema(schema);
    return schema.logs;
  }

  /** Entradas válidas de la bitácora, la más reciente primero. */
  function getEntries() {
    const schema = readSchema();
    return schema.logs.filter((entry) =>
      entry !== null
      && typeof entry === 'object'
      && REQUIRED_FIELDS.every((field) => entry[field] !== undefined)
    );
  }

  /** Vacía la bitácora («Restaurar Maniquí», RF-02.6). */
  function clear() {
    const schema = readSchema();
    ensureSessionId(schema);
    schema.logs = [];
    writeSchema(schema);
  }

  /** Metadatos del esquema (sessionId y límite canónico). */
  function getMetadata() {
    const schema = readSchema();
    return {
      sessionId: schema.sessionId,
      maxEntries,
      entryCount: getEntries().length,
    };
  }

  return { recordImpact, getEntries, clear, getMetadata };
}
