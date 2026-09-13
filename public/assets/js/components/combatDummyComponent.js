/**
 * combatDummyComponent.js — Máquina de estados del Maniquí Arcano.
 *
 * Tarea 3.1 (TASKS-05): maniquí de entrenamiento de la Cámara de
 * Conjuración con salud base de 500 PV, absorción prioritaria de barrera
 * con renovación por valor dominante, techo inmutable de curación,
 * ataduras de control de masas con disipación a 4 s, persistencia de
 * estado entre páginas y regeneración automática a 2 s tras disolverse.
 *
 * Constitución:
 *   - Artículo I (Dogma Vanilla): ES Modules nativos; DOM vía
 *     createElement/textContent — el componente jamás usa innerHTML.
 *   - Artículo IV/V: leyendas solemnes en castellano; identificadores en
 *     inglés camelCase.
 *
 * Cobertura: RF-02.1 (maniquí 500 PV + indicador de barrera),
 * RF-02.3 (persistencia entre páginas), RF-02.4 (reglas cuantitativas),
 * RF-02.5 (regeneración a 2 s), RF-05.1 (reacción ante CC).
 */

/** Salud base y techo inmutable de curación (RF-02.1/02.4). */
export const DUMMY_MAX_HEALTH = 500;

/** Vigencia de las ataduras de control de masas (RF-02.4 / RF-05.1). */
export const CC_DURATION_MS = 4000;

/** Tiempo de regeneración automática tras la destrucción (RF-02.5). */
export const REGENERATION_DELAY_MS = 2000;

/**
 * Crea el componente del maniquí montado sobre `options.host`.
 *
 * @param {object} options
 *   - host: elemento anfitrión donde se monta la figura y su barra.
 *   - clock: reloj inyectable ({ get now(), advance(ms) }); por defecto
 *     usa Date.now() (los arneses controlan el tiempo con el suyo).
 * @returns {object} API del componente: applySpellImpact, tick,
 *   onPageChange, restore, getState.
 */
export function createCombatDummyComponent(options = {}) {
  const host = options.host;
  const clock = options.clock ?? { now: () => Date.now() };
  const now = () => (typeof clock.now === 'function' ? clock.now() : clock.now);

  /** Estado interno persistente de la máquina (plan 3.2). */
  const state = {
    health: DUMMY_MAX_HEALTH,
    maxHealth: DUMMY_MAX_HEALTH,
    barrier: 0,
    activeCC: null, // 'stun' | 'root' | 'slow' | null
    ccExpiresAt: 0,
    state: 'intact', // intact | shielded | damaged | ccIncapacitated | destroyed
    regenerationAt: 0,
  };

  // --- Render mínimo (figura + barra de salud + indicador de barrera) ---
  // Creador de nodos: en el navegador usa ownerDocument.createElement
  // (nodos reales, appendChild nativo); los arneses sin DOM aportan su
  // propio creador vía options.createElement (shims compatibles).
  const createNode = options.createElement
    ?? host?.ownerDocument?.createElement?.bind(host.ownerDocument)
    ?? createElementShim;

  const figure = createNode('div');
  figure.className = 'combat-dummy__figure';
  host.appendChild(figure);

  const healthBar = createNode('div');
  healthBar.className = 'combat-dummy__health-bar';
  const healthFill = createNode('div');
  healthFill.className = 'combat-dummy__health-fill';
  healthBar.appendChild(healthFill);

  const barrierBadge = createNode('div');
  barrierBadge.className = 'combat-dummy__barrier-badge';
  const statusLegend = createNode('div');
  statusLegend.className = 'combat-dummy__status-legend';
  statusLegend.setAttribute('role', 'status');
  statusLegend.setAttribute('aria-live', 'polite');

  figure.appendChild(healthBar);
  figure.appendChild(barrierBadge);
  figure.appendChild(statusLegend);

  function render() {
    healthFill.style.setProperty('width', `${(state.health / state.maxHealth) * 100}%`);
    barrierBadge.textContent = state.barrier > 0 ? `[Barrera ${state.barrier}]` : '';
    const legends = {
      intact: 'Maniquí intacto',
      shielded: 'Maniquí escudado',
      damaged: 'Maniquí dañado',
      ccIncapacitated: 'Maniquí atado por el conjuro',
      destroyed: 'El armazón se disuelve en paja arcana…',
    };
    statusLegend.textContent = state.state === 'destroyed'
      ? legends.destroyed
      : `${legends[state.state] ?? ''} — ${state.health} / ${state.maxHealth} PV`;
  }

  /** Recalcula el estado derivado tras cada mutación (plan 3.2). */
  function refreshDerivedState() {
    if (state.state === 'destroyed') {
      return; // La destrucción solo se resuelve por regeneración.
    }
    const wasCC = state.state === 'ccIncapacitated';
    let next;
    if (state.activeCC !== null) {
      next = 'ccIncapacitated';
    } else if (state.barrier > 0 && state.health === state.maxHealth) {
      next = 'shielded';
    } else if (state.health < state.maxHealth) {
      next = 'damaged';
    } else if (state.barrier > 0) {
      next = 'shielded';
    } else {
      next = 'intact';
    }
    state.state = next;
    if (wasCC && next !== 'ccIncapacitated') {
      state.activeCC = null; // la disipación se materializa aquí
    }
  }

  /**
   * Resuelve el impacto de un conjuro (RF-02.4, plan 3.2):
   * 1) absorción prioritaria de barrera, 2) reducción de PV,
   * 3) curación con techo 500 (leyenda «[Salud Plena]» si está lleno),
   * 4) barrera por valor dominante, 5) CC por 4 s.
   *
   * @param {object} spell - { name, effects: { damage, healing, barrier,
   *   crowdControlType } }.
   * @returns {object} { damageApplied, barrierAbsorbed, remainingHealth,
   *   fullHealthLegend, crowdControlApplied }.
   */
  function applySpellImpact(spell) {
    const effects = spell?.effects ?? {};
    const damage = Number(effects.damage ?? 0) || 0;
    const healing = Number(effects.healing ?? 0) || 0;
    const barrier = Number(effects.barrier ?? 0) || 0;
    const ccType = effects.crowdControlType && effects.crowdControlType !== 'none'
      ? effects.crowdControlType
      : null;

    if (state.state === 'destroyed') {
      // El maniquí disuelto no absorbe impactos hasta regenerarse.
      return { damageApplied: 0, barrierAbsorbed: 0, remainingHealth: 0, fullHealthLegend: false, crowdControlApplied: null };
    }

    // 1. Absorción de barrera (prioritaria sobre la salud).
    let damageToApply = damage;
    let barrierAbsorbed = 0;
    if (state.barrier > 0 && damageToApply > 0) {
      if (state.barrier >= damageToApply) {
        state.barrier -= damageToApply;
        barrierAbsorbed = damageToApply;
        damageToApply = 0;
      } else {
        barrierAbsorbed = state.barrier;
        damageToApply -= state.barrier;
        state.barrier = 0;
      }
    }

    // 2. Reducción de PV (sin bajar de 0).
    state.health = Math.max(0, state.health - damageToApply);

    // 3. Curación con techo inmutable de 500 PV. Si la curación se
    // acota (salud plena antes o después), se emite «[Salud Plena]».
    let fullHealthLegend = false;
    if (healing > 0) {
      const beforeHeal = state.health;
      state.health = Math.min(DUMMY_MAX_HEALTH, state.health + healing);
      if (beforeHeal === DUMMY_MAX_HEALTH || state.health === DUMMY_MAX_HEALTH) {
        fullHealthLegend = true;
      }
    }

    // 4. Renovación de barrera por mayor valor dominante (sin apilar).
    if (barrier > 0) {
      state.barrier = Math.max(state.barrier, barrier);
    }

    // 5. Control de masas por 4 segundos.
    let crowdControlApplied = null;
    if (ccType) {
      state.activeCC = ccType;
      state.ccExpiresAt = now() + CC_DURATION_MS;
      crowdControlApplied = ccType;
    }

    // 6. Destrucción: la salud a 0 disuelve el armazón (RF-02.5).
    if (state.health === 0) {
      state.state = 'destroyed';
      state.barrier = 0;
      state.activeCC = null;
      state.regenerationAt = now() + REGENERATION_DELAY_MS;
      render();
      return { damageApplied: damageToApply + barrierAbsorbed, barrierAbsorbed, remainingHealth: 0, fullHealthLegend, crowdControlApplied };
    }

    refreshDerivedState();
    render();
    return {
      damageApplied: damageToApply,
      barrierAbsorbed,
      remainingHealth: state.health,
      fullHealthLegend,
      crowdControlApplied,
    };
  }

  /**
   * Avanza la máquina en el tiempo: disipación de CC a los 4 s y
   * regeneración automática a los 2 s de la destrucción.
   */
  function tick() {
    const instant = now();

    if (state.state === 'destroyed') {
      if (instant >= state.regenerationAt) {
        state.health = DUMMY_MAX_HEALTH;
        state.barrier = 0;
        state.activeCC = null;
        state.state = 'intact';
        state.regenerationAt = 0;
        render();
      }
      return;
    }

    if (state.activeCC !== null && instant >= state.ccExpiresAt) {
      state.activeCC = null;
      state.ccExpiresAt = 0;
      refreshDerivedState();
      render();
    }
  }

  /**
   * Notifica un cambio de página del tomo (RF-02.3): el estado se
   * conserva íntegro — el método existe para hacer explícito el contrato
   * de persistencia y permitir efectos visuales de futuras tareas.
   */
  function onPageChange() {
    // Persistencia deliberada: nada se resetea al hojear (RF-02.3).
    render();
  }

  /**
   * «Restaurar Maniquí» (RF-02.6): reseteo instantáneo completo —
   * 500 PV, sin barreras, sin estados alterados.
   */
  function restore() {
    state.health = DUMMY_MAX_HEALTH;
    state.barrier = 0;
    state.activeCC = null;
    state.ccExpiresAt = 0;
    state.regenerationAt = 0;
    state.state = 'intact';
    render();
    return { ...state };
  }

  /** Copia de solo lectura del estado (para la vista y los arneses). */
  function getState() {
    return { ...state };
  }

  render();
  return { applySpellImpact, tick, onPageChange, restore, getState };
}

/**
 * Shim de elemento para los arneses (DOM simulado sin innerHTML). En el
 * navegador real el anfitrión aporta ownerDocument.createElement.
 */
function createElementShim(tagName) {
  return {
    tagName: String(tagName).toUpperCase(),
    children: [],
    classes: new Set(),
    attributes: {},
    listeners: {},
    style: {
      setProperty(name, value) { (this.inline ??= {})[name] = String(value); },
      getProperty(name) { return (this.inline ?? {})[name] ?? null; },
    },
    _textContent: '',
    parentElement: null,
    setAttribute(name, value) { this.attributes[name] = String(value); },
    addEventListener(name, listener) { (this.listeners[name] ??= []).push(listener); },
    appendChild(child) { child.parentElement = this; this.children.push(child); return child; },
    set className(value) { this.classes = new Set(String(value).split(/\s+/).filter(Boolean)); },
    get className() { return [...this.classes].join(' '); },
    get textContent() { return this._textContent; },
    set textContent(value) { this._textContent = String(value); },
  };
}
