/**
 * stunlockManager.js — Gestor de la salvaguarda Anti-Stunlock (SPEC-06).
 *
 * Tarea 2.1 (TASKS-06): gobierna el ciclo de la Inmunidad Rúnica a
 * Parálisis (RF-05.2) —ventana de 3 segundos tras expirar un control duro
 * (hardStun o freezeParalysis)— y discrimina los controles de masas:
 * los Hard CC se bloquean durante la ventana (sustituidos por onda de
 * choque visual, RF-05.3) y los Soft CC se aplican con normalidad.
 *
 * Constitución:
 *   - Artículo I (Dogma Vanilla): módulo ES nativo; las APIs del entorno
 *     (reloj, planificador de timeouts, target de eventos DOM) llegan
 *     inyectadas por la fábrica, de modo que los arneses las sustituyen
 *     por dobles fieles. Cero librerías.
 *   - Artículo II: el ciclo es 100% determinista, función pura del reloj —
 *     sin azar ni temporizadores ocultos (RNF-01).
 *   - Artículo V: identificadores en inglés camelCase; documentación en
 *     castellano.
 *
 * Eventos DOM (plan 4.1), emitidos sobre el target inyectado:
 *   - combo:stunlock-immunity-started {durationMs: 3000}
 *   - combo:stunlock-immunity-ended   {}
 *
 * Cobertura: RF-05.2 (inmunidad de 3 s tras Hard CC), RF-05.3 (Hard CC
 * bloqueado con daño íntegro / Soft CC normal), RNF-01 (determinismo).
 */

/** Duración canónica de la Inmunidad Rúnica, en ms (RF-05.2). */
export const STUNLOCK_IMMUNITY_DURATION_MS = 3000;

/** Efectos de Hard CC del Códice: parálisis total susceptible de salvaguarda. */
export const HARD_CROWD_CONTROL_EFFECTS = ['hardStun', 'freezeParalysis'];

/** Efectos de Soft CC del Códice: se aplican incluso bajo inmunidad (RF-05.3). */
export const SOFT_CROWD_CONTROL_EFFECTS = ['blindnessMist', 'rootAndSlow'];

/**
 * Crea el gestor de la salvaguarda anti-stunlock.
 *
 * @param {object} [options]
 *   - now: reloj inyectable (() => ms de época). Por defecto, Date.now.
 *   - scheduleTimeout: planificador inyectable ((callback, delayMs) => cancel)
 *     para el aviso de expiración; por defecto, setTimeout/clearTimeout.
 *   - eventTarget: despachador de eventos DOM inyectable (por defecto,
 *     window). Debe implementar dispatchEvent(Event).
 * @returns {object} API: grantImmunity, isImmuneToHardCc,
 *   evaluateCrowdControl, getRemainingImmunityMs, reset.
 */
export function createStunlockManager(options = {}) {
  const now = options.now ?? (() => Date.now());
  const scheduleTimeout = options.scheduleTimeout
    ?? ((callback, delayMs) => {
      const timerId = setTimeout(callback, delayMs);
      return () => clearTimeout(timerId);
    });
  const eventTarget = options.eventTarget ?? window;

  /** Instante de expiración de la ventana vigente; null si no hay inmunidad. */
  let immunityExpiresAt = null;
  /** Cancelador del aviso programado de expiración. */
  let cancelExpiryNotice = null;

  /**
   * Emite un evento del plan 4.1 sobre el target.
   * @param {string} type
   * @param {object} [detail]
   */
  function emit(type, detail = {}) {
    // El burbujeo permite que el bus trepe hasta el shell de la SPA.
    eventTarget.dispatchEvent(new CustomEvent(type, { detail, bubbles: true }));
  }

  /**
   * Cierra la ventana vigente y anuncia su fin (una sola vez por ventana).
   */
  function endImmunity() {
    if (immunityExpiresAt === null) {
      return;
    }
    immunityExpiresAt = null;
    if (cancelExpiryNotice !== null) {
      cancelExpiryNotice();
      cancelExpiryNotice = null;
    }
    emit('combo:stunlock-immunity-ended');
  }

  /**
   * Concede la Inmunidad Rúnica a Parálisis por 3000 ms (RF-05.2),
   * normalmente al expirar un Hard CC aplicado.
   *
   * Idempotente durante la ventana: una segunda concesión no extiende el
   * plazo ni duplica el anuncio (anti-apilamiento).
   */
  function grantImmunity() {
    if (immunityExpiresAt !== null) {
      return; // La ventana ya vive: no se apila ni se extiende.
    }
    immunityExpiresAt = now() + STUNLOCK_IMMUNITY_DURATION_MS;
    emit('combo:stunlock-immunity-started', { durationMs: STUNLOCK_IMMUNITY_DURATION_MS });
    cancelExpiryNotice = scheduleTimeout(endImmunity, STUNLOCK_IMMUNITY_DURATION_MS);
  }

  /**
   * ¿Goza el blanco de inmunidad vigente contra Hard CC en este instante?
   * El sondeo cierra la ventana si el reloj la ha sobrepasado (garantía
   * determinista incluso si el planificador se retrasa).
   */
  function isImmuneToHardCc() {
    if (immunityExpiresAt === null) {
      return false;
    }
    if (now() >= immunityExpiresAt) {
      endImmunity();
      return false;
    }
    return true;
  }

  /**
   * Evalúa si un efecto táctico del Códice puede aplicarse al blanco.
   *
   * @param {string} tacticalEffect Efecto del veredicto (ej. 'hardStun').
   * @returns {{allowed: boolean, replacedByShockwave: boolean}}
   *   - Hard CC con inmunidad vigente: {allowed: false, replacedByShockwave: true}
   *     (RF-05.3: daño íntegro, parálisis sustituida por onda de choque).
   *   - Soft CC y efectos ajenos a los controles de masas: {allowed: true}.
   */
  function evaluateCrowdControl(tacticalEffect) {
    const isHardCrowdControl = HARD_CROWD_CONTROL_EFFECTS.includes(tacticalEffect);
    if (isHardCrowdControl && isImmuneToHardCc()) {
      return { allowed: false, replacedByShockwave: true };
    }
    return { allowed: true, replacedByShockwave: false };
  }

  /**
   * Milisegundos de inmunidad restantes (0 si no hay ventana vigente).
   */
  function getRemainingImmunityMs() {
    if (immunityExpiresAt === null || now() >= immunityExpiresAt) {
      if (immunityExpiresAt !== null) {
        endImmunity();
      }
      return 0;
    }
    return immunityExpiresAt - now();
  }

  /**
   * Despeja cualquier inmunidad vigente («Restaurar Maniquí», RF-02.6):
   * anuncia el fin de la ventana y no deja cierres fantasma posteriores.
   */
  function reset() {
    endImmunity();
  }

  return {
    grantImmunity,
    isImmuneToHardCc,
    evaluateCrowdControl,
    getRemainingImmunityMs,
    reset,
  };
}
