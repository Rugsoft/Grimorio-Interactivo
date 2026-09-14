/**
 * comboResolver.js — Motor de resolución de combos en cliente (SPEC-06).
 *
 * Tarea 2.2 (TASKS-06): espejo exacto del algoritmo autoritativo del
 * backend (ElementalMatrixService::resolveCombo, plan 3.2) sobre la matriz
 * inmutable del Códice cargada en memoria —sin consultas de red—, con la
 * paridad garantizada por el arnés de la tarea: veredictos de once claves
 * idénticos byte a byte, en menos de 5 ms (RNF-01, RNF-02).
 *
 * Constitución:
 *   - Artículo I (Dogma Vanilla): módulo ES nativo; las APIs del entorno
 *     (reloj, target de eventos DOM) llegan inyectadas por la fábrica.
 *     Cero librerías y cero fetch: la matriz viaja en el propio módulo.
 *   - Artículo II: los factores (×1.5, ×1.25, +1 s) se LEEN de la matriz
 *     espejo, idéntica a la del Códice del plan 2.1; la única aritmética
 *     es Math.ceil(base × factor).
 *   - Artículo IV: los nombres litúrgicos viajan en noble castellano.
 *   - Artículo V: identificadores en inglés camelCase; documentación en
 *     castellano.
 *
 * Eventos DOM (plan 4.1), emitidos sobre el target inyectado:
 *   - combo:aura-applied         {element, expiresAt, durationMs: 5000}
 *   - combo:aura-refreshed       {element, expiresAt}
 *   - combo:reaction-triggered   {reactionId, reactionName, damage,
 *                                 tacticalEffect, elements, colorA, colorB}
 *
 * Tarea 2.3 (TASKS-06): la fábrica createSpellImpactQueue materializa la
 * clase SpellImpactQueue del plan 3.3 — cola FIFO determinista (RF-05.4)
 * para ráfagas simultáneas (<100 ms): el primer impacto se resuelve al
 * instante y los siguientes avanzan UNO POR CUADRO mediante el planificador
 * inyectado, en estricto orden de llegada y sin condiciones de carrera.
 *
 * Cobertura: RF-03.1 a 03.4, RF-04.1, RF-04.2, RF-05.2/05.3 (salvaguarda
 * delegada en el gestor inyectado), RNF-01, RNF-02.
 */

/** Ventana canónica de resonancia del aura, en ms (RF-02.1). */
export const AURA_RESONANCE_DURATION_MS = 5000;

/**
 * Los ocho elementos canónicos del Códice (plan 2.1), espejo exacto del
 * backend: identificadores técnicos y colores heráldicos para las estelas
 * bicromáticas del plan 4.2.
 */
export const ELEMENTAL_MATRIX_ELEMENTS = [
  { id: 'fire', name: 'Fuego', color: '#ff4500', glyph: 'rune-ignis' },
  { id: 'water', name: 'Agua / Escarcha', color: '#00bfff', glyph: 'rune-aqua' },
  { id: 'lightning', name: 'Rayo', color: '#9932cc', glyph: 'rune-fulgur' },
  { id: 'earth', name: 'Tierra', color: '#8b4513', glyph: 'rune-terra' },
  { id: 'wind', name: 'Viento', color: '#2e8b57', glyph: 'rune-ventus' },
  { id: 'light', name: 'Luz', color: '#ffd700', glyph: 'rune-lux' },
  { id: 'darkness', name: 'Oscuridad', color: '#4b0082', glyph: 'rune-tenebrae' },
  { id: 'pureArcane', name: 'Arcano Puro', color: '#4169e1', glyph: 'rune-arcana' },
];

/**
 * Las siete Reacciones Arcanas Duales más el catalizador universal
 * (plan 2.1), espejo exacto de la tabla del backend (Tarea 1.2).
 */
export const ELEMENTAL_MATRIX_REACTIONS = [
  {
    id: 'arcaneVaporization',
    name: 'Vaporización Arcana',
    elements: ['fire', 'water'],
    damageMultiplier: 1.5,
    tacticalEffect: 'blindnessMist',
    effectDurationMs: 3000,
    description: 'Emisión de vapor abrasador que reduce la precisión del objetivo durante 3 segundos.',
  },
  {
    id: 'fluidElectrocution',
    name: 'Electrocución Fluida',
    elements: ['water', 'lightning'],
    damageMultiplier: 1.5,
    tacticalEffect: 'hardStun',
    effectDurationMs: 1500,
    description: 'Descarga en cadena que aturde fulgurantemente al blanco durante 1.5 segundos.',
  },
  {
    id: 'vortexDeflagration',
    name: 'Deflagración en Vórtice',
    elements: ['fire', 'wind'],
    damageMultiplier: 1.5,
    tacticalEffect: 'areaExpansion',
    effectDurationMs: 0,
    description: 'Combustión violenta alimentada por viento que expande el impacto a radio esférico en área.',
  },
  {
    id: 'basalticFracture',
    name: 'Fractura Basáltica',
    elements: ['earth', 'lightning'],
    damageMultiplier: 1.5,
    tacticalEffect: 'barrierShatter',
    effectDurationMs: 0,
    barrierDamage: 50,
    description: 'Descarga de choque que tritura hasta 50 puntos de barrera mágica del objetivo.',
  },
  {
    id: 'petrifyingSwamp',
    name: 'Ciénaga Petrificante',
    elements: ['earth', 'water'],
    damageMultiplier: 1.5,
    tacticalEffect: 'rootAndSlow',
    effectDurationMs: 3000,
    slowDurationMs: 4000,
    description: 'Inmovilización por enraizamiento de 3 s y reducción de velocidad al 50% por 4 s.',
  },
  {
    id: 'glacialBlizzard',
    name: 'Ventisca Helada',
    elements: ['wind', 'water'],
    damageMultiplier: 1.5,
    tacticalEffect: 'freezeParalysis',
    effectDurationMs: 2000,
    description: 'Congelación absoluta de 2 segundos que impone parálisis motora completa.',
  },
  {
    id: 'twilightCollapse',
    name: 'Colapso Crepuscular',
    elements: ['light', 'darkness'],
    damageMultiplier: 1.5,
    tacticalEffect: 'barrierPiercing',
    effectDurationMs: 0,
    description: 'Daño puro que penetra el 100% de los escudos dañando directamente los puntos de salud.',
  },
  {
    id: 'pureArcaneResonance',
    name: 'Resonancia Arcana Pura',
    elements: ['pureArcane'],
    isCatalyst: true,
    amplificationFactor: 1.25,
    ccExtensionMs: 1000,
    description: 'Catalizador universal que amplifica un 25% la magnitud y añade 1 s a la duración de controles.',
  },
];

/** Efectos de Hard CC (salvaguarda anti-stunlock, RF-05.3). */
const HARD_CROWD_CONTROL_EFFECTS = ['hardStun', 'freezeParalysis'];

/**
 * Crea el motor de resolución de combos en cliente.
 *
 * @param {object} [options]
 *   - now: reloj inyectable (() => ms de época). Por defecto, Date.now.
 *   - stunlockManager: gestor de salvaguarda inyectado (Tarea 2.1). Si se
 *     omite, se fabrica un sustituto neutro que no concede inmunidad.
 *   - eventTarget: despachador de eventos DOM inyectable (por defecto,
 *     window). Debe implementar dispatchEvent(Event).
 * @returns {object} API: resolveImpact, findReaction, getMatrix.
 */
export function createComboResolver(options = {}) {
  const now = options.now ?? (() => Date.now());
  const eventTarget = options.eventTarget ?? window;
  const stunlockManager = options.stunlockManager ?? {
    grantImmunity: () => {},
  };

  /** Índice simétrico por par canónico (misma clave canónica del backend). */
  const reactionIndex = new Map();
  for (const reaction of ELEMENTAL_MATRIX_REACTIONS) {
    if (reaction.isCatalyst || reaction.elements.length !== 2) {
      continue; // El catalizador unario no forma pares duales.
    }
    const [componentA, componentB] = reaction.elements;
    const canonicalKey = [componentA, componentB].sort().join('|');
    reactionIndex.set(canonicalKey, reaction);
  }

  /**
   * Emite un evento del plan 4.1 sobre el target.
   * @param {string} type
   * @param {object} detail
   */
  function emit(type, detail) {
    // El burbujeo permite que el bus trepe hasta el shell de la SPA.
    eventTarget.dispatchEvent(new CustomEvent(type, { detail, bubbles: true }));
  }

  /**
   * Color heráldico de un elemento del Códice.
   * @param {string} elementId
   * @returns {string} Notación #rrggbb (in color inexistente, blanco).
   */
  function colorOf(elementId) {
    return ELEMENTAL_MATRIX_ELEMENTS.find((element) => element.id === elementId)?.color ?? '#ffffff';
  }

  /**
   * Resuelve la reacción arcana del par A + B con simetría garantizada.
   * @param {string} elementA
   * @param {string} elementB
   * @returns {object|null} Ficha de la reacción dual, o null si no reaccionan.
   */
  function findReaction(elementA, elementB) {
    if (!elementA || !elementB || elementA === elementB) {
      return null;
    }
    return reactionIndex.get([elementA, elementB].sort().join('|')) ?? null;
  }

  /**
   * Resuelve un impacto elemental contra el estado del blanco: el mismo
   * contrato de once claves que el Endpoint 3 del backend.
   *
   * @param {object} impact
   *   - activeAura: aura vigente del blanco ('' si neutral).
   *   - incomingSpell: {id, element, baseDamage, baseHealing, baseBarrier,
   *     crowdControlType} — el payload del Endpoint 3.
   *   - stunlockImmune: ¿goza el blanco de inmunidad vigente?
   * @returns {object} Veredicto de once claves, idéntico al del backend.
   */
  function resolveImpact(impact) {
    const activeAura = (impact.activeAura ?? '').trim();
    const incomingSpell = impact.incomingSpell;
    const incomingElement = (incomingSpell?.element ?? '').trim();
    const baseDamage = incomingSpell?.baseDamage ?? 0;

    // Caso A: blanco neutral — imbuición del aura (RF-02.1).
    if (activeAura === '') {
      if (incomingElement !== 'pureArcane') {
        emit('combo:aura-applied', {
          element: incomingElement,
          expiresAt: now() + AURA_RESONANCE_DURATION_MS,
          durationMs: AURA_RESONANCE_DURATION_MS,
        });
        return {
          isReaction: false,
          reactionId: null,
          reactionName: null,
          effectiveDamage: baseDamage,
          damageMultiplierApplied: 1.0,
          tacticalEffectApplied: null,
          effectDurationMs: AURA_RESONANCE_DURATION_MS,
          clearedAura: false,
          resultingAura: incomingElement,
          stunlockTriggered: false,
          grantStunlockImmunity: false,
        };
      }
      return {
        isReaction: false,
        reactionId: null,
        reactionName: null,
        effectiveDamage: baseDamage,
        damageMultiplierApplied: 1.0,
        tacticalEffectApplied: null,
        effectDurationMs: AURA_RESONANCE_DURATION_MS,
        clearedAura: false,
        resultingAura: null,
        stunlockTriggered: false,
        grantStunlockImmunity: false,
      };
    }

    // Caso B: mismo elemento — refresco del aura (RF-02.4).
    if (activeAura === incomingElement) {
      emit('combo:aura-refreshed', {
        element: activeAura,
        expiresAt: now() + AURA_RESONANCE_DURATION_MS,
      });
      return {
        isReaction: false,
        reactionId: null,
        reactionName: null,
        effectiveDamage: baseDamage,
        damageMultiplierApplied: 1.0,
        tacticalEffectApplied: 'auraRefreshed',
        effectDurationMs: AURA_RESONANCE_DURATION_MS,
        clearedAura: false,
        resultingAura: activeAura,
        stunlockTriggered: false,
        grantStunlockImmunity: false,
      };
    }

    // Caso C: catalizador sobre aura activa (RF-03.2).
    if (incomingElement === 'pureArcane') {
      emit('combo:reaction-triggered', {
        reactionId: 'pureArcaneResonance',
        reactionName: 'Resonancia Arcana Pura',
        damage: Math.ceil(baseDamage * 1.25),
        tacticalEffect: 'amplification',
        elements: ['pureArcane', activeAura],
        colorA: colorOf('pureArcane'),
        colorB: colorOf(activeAura),
      });
      return {
        isReaction: true,
        reactionId: 'pureArcaneResonance',
        reactionName: 'Resonancia Arcana Pura',
        effectiveDamage: Math.ceil(baseDamage * 1.25),
        damageMultiplierApplied: 1.25,
        tacticalEffectApplied: 'amplification',
        effectDurationMs: 0,
        clearedAura: true,
        resultingAura: null,
        stunlockTriggered: false,
        grantStunlockImmunity: false,
      };
    }

    // Caso D: reacción dual simétrica (RF-03.1, RF-04.1).
    const reaction = findReaction(activeAura, incomingElement);
    if (reaction !== null) {
      const finalDamage = Math.ceil(baseDamage * reaction.damageMultiplier);
      const isHardCrowdControl = HARD_CROWD_CONTROL_EFFECTS.includes(reaction.tacticalEffect);
      const stunlockTriggered = isHardCrowdControl && impact.stunlockImmune === true;
      const grantImmunity = isHardCrowdControl && impact.stunlockImmune !== true;

      if (grantImmunity) {
        stunlockManager.grantImmunity();
      }

      emit('combo:reaction-triggered', {
        reactionId: reaction.id,
        reactionName: reaction.name,
        damage: finalDamage,
        tacticalEffect: reaction.tacticalEffect,
        elements: [...reaction.elements],
        colorA: colorOf(reaction.elements[0]),
        colorB: colorOf(reaction.elements[1]),
      });

      return {
        isReaction: true,
        reactionId: reaction.id,
        reactionName: reaction.name,
        effectiveDamage: finalDamage,
        damageMultiplierApplied: reaction.damageMultiplier,
        tacticalEffectApplied: reaction.tacticalEffect,
        effectDurationMs: reaction.effectDurationMs ?? 0,
        clearedAura: true,
        resultingAura: null,
        stunlockTriggered,
        grantStunlockImmunity: grantImmunity,
      };
    }

    // Caso E: elemento no reactivo — sobreescritura (RF-03.3).
    emit('combo:aura-applied', {
      element: incomingElement,
      expiresAt: now() + AURA_RESONANCE_DURATION_MS,
      durationMs: AURA_RESONANCE_DURATION_MS,
    });
    return {
      isReaction: false,
      reactionId: null,
      reactionName: null,
      effectiveDamage: baseDamage,
      damageMultiplierApplied: 1.0,
      tacticalEffectApplied: 'auraOverwritten',
      effectDurationMs: AURA_RESONANCE_DURATION_MS,
      clearedAura: false,
      resultingAura: incomingElement,
      stunlockTriggered: false,
      grantStunlockImmunity: false,
    };
  }

  /**
   * Grafo del Códice en el formato del Endpoint 1 (para la Rueda Rúnica).
   * @returns {{elements: object[], reactions: object[]}}
   */
  function getMatrix() {
    return { elements: ELEMENTAL_MATRIX_ELEMENTS, reactions: ELEMENTAL_MATRIX_REACTIONS };
  }

  return {
    resolveImpact,
    findReaction,
    getMatrix,
  };
}

/**
 * Crea la cola determinista FIFO de impactos (plan 3.3, Tarea 2.3).
 *
 * Comportamiento (RF-05.4):
 *   - Encolar sobre cola inactiva: el impacto se resuelve AL INSTANTE
 *     (procesamiento atómico; el primero aplica el aura sin esperar cuadro).
 *   - Encolar durante el procesamiento: el impacto espera su turno y avanza
 *     UNO POR CUADRO, en estricto orden de llegada (jamás reordenado por
 *     timestamp), sin reentrada: encolar desde dentro del resolutor no
 *     dispara resoluciones inmediatas.
 *
 * @param {object} [options]
 *   - resolveImpact: función de resolución inyectada ((impact) => veredicto).
 *     Obligatoria para operar; sin ella la cola lanza al encolar.
 *   - scheduleFrame: planificador de cuadros inyectable
 *     ((callback) => cancel) — por defecto, requestAnimationFrame/cancelAF.
 * @returns {object} API: enqueue, pendingCount, isProcessing, clear.
 */
export function createSpellImpactQueue(options = {}) {
  const resolveImpact = options.resolveImpact;
  const scheduleFrame = options.scheduleFrame
    ?? ((callback) => {
      const frameId = requestAnimationFrame(() => callback());
      return () => cancelAnimationFrame(frameId);
    });

  /** @type {object[]} Impactos pendientes, en estricto orden de llegada. */
  let pendingImpacts = [];
  /** Cancelador del cuadro programado activo, si lo hay. */
  let cancelScheduledFrame = null;
  /** Bandera del ciclo activo: se alza ANTES de resolver (anti-reentrada del plan 3.3). */
  let cycleActive = false;

  /**
 * Drena un paso del ciclo: resuelve el primero de la cola y agenda el
 * siguiente cuadro —para drenar pendientes o para cerrar el ciclo cuando
 * el cuadro encuentre la cola vacía (semántica exacta del plan 3.3).
 */
  function processNextImpact() {
    if (pendingImpacts.length === 0) {
      // Cuadro de cierre: la ráfaga terminó, el ciclo se apaga limpio.
      cycleActive = false;
      return;
    }

    const nextImpact = pendingImpacts.shift();
    resolveImpact(nextImpact);

    // Siempre agenda el siguiente cuadro: un impacto encolado durante la
    // resolución (reentrada) cae en la cola y espera su turno.
    cancelScheduledFrame = scheduleFrame(processNextImpact);
  }

  /**
 * Encola un impacto y arranca el ciclo si estaba inactivo.
 * @param {object} impact El impacto completo (payload del Endpoint 3).
 */
  function enqueue(impact) {
    if (typeof resolveImpact !== 'function') {
      throw new TypeError('La cola FIFO exige una función resolveImpact inyectada.');
    }
    pendingImpacts.push(impact);

    if (!cycleActive) {
      // Cola inactiva: el primer impacto se resuelve AL INSTANTE (plan 3.3).
      // La bandera se alza antes de resolver, de modo que los encolados
      // durante el procesamiento esperan su turno sin reentrada.
      cycleActive = true;
      processNextImpact();
    }
  }

  /**
 * Impactos pendientes de resolución (0 si la cola está al día).
 */
  function pendingCount() {
    return pendingImpacts.length;
  }

  /**
 * ¿Hay un ciclo de ráfaga en marcha? Verdadero desde el arranque hasta el
 * cuadro de cierre, incluso si la cola ya drenó sus pendientes.
 */
  function isProcessing() {
    return cycleActive;
  }

  /**
 * Despeja los impactos pendientes sin resolverlos (Restaurar Maniquí):
 * cancela el cuadro programado y apaga el ciclo limpio.
 */
  function clear() {
    pendingImpacts = [];
    if (cancelScheduledFrame !== null) {
      cancelScheduledFrame();
      cancelScheduledFrame = null;
    }
    cycleActive = false;
  }

  return {
    enqueue,
    pendingCount,
    isProcessing,
    clear,
  };
}
