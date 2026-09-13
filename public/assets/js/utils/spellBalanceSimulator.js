/**
 * spellBalanceSimulator.js — Motor de cálculo de maná en cliente.
 *
 * Tarea 5.1 (TASKS-04): replica con fidelidad 100% simétrica las
 * constantes y la fórmula del backend (src/Services/SpellBalanceService.php,
 * Tarea 2.2) para que el Taller de Hechizos muestre el desglose pedagógico
 * en vivo (RF-04.1) sin esperar al servidor.
 *
 * SIMETRÍA GARANTIZADA (RNF-01, Art. II):
 *   - Las constantes son el ESPEJO EXACTO de las constantes públicas de
 *     SpellBalanceService. Cualquier cambio en el backend DEBE reflejarse
 *     aquí y en scratch/test_spell_balance_simulator.mjs.
 *   - El orden de operaciones es idéntico: base ponderada → multiplicadores
 *     geométricos combinados → descuento acotado → ceil con suelo → techo
 *     de sobrecarga → Círculo Arcano.
 *   - El arnés scratch/test_spell_balance_simulator.mjs verifica la
 *     simetría comparando ambos motores caso a caso contra el PHP real.
 *
 * El simulador es SOLO previsualización pedagógica: la palabra final sobre
 * el coste la tiene SIEMPRE el backend (el maná persistido se recalcula
 * de forma ciega en SpellManagementService, Art. II).
 *
 * Constitución:
 *   - Artículo I (Dogma Vanilla): ES Module nativo, aritmética estándar,
 *     sin librerías ni empaquetadores.
 *   - Artículo II: una sola Ley Universal del Maná en dos lenguajes.
 *   - Artículo V: identificadores en inglés camelCase; etiquetas solemnes
 *     de Círculos y leyendas de Sobrecarga en castellano (Art. IV).
 */

/**
 * Constantes universales del balance (espejo de SpellBalanceService).
 *
 * @type {{
 *   WEIGHT_DAMAGE: number, WEIGHT_HEALING: number, WEIGHT_BARRIER: number,
 *   CC_WEIGHTS: Record<string, number>,
 *   RANGE_FACTORS: Record<string, number>,
 *   AREA_FACTORS: Record<string, number>,
 *   DURATION_FACTORS: Record<string, number>,
 *   COMPONENT_DISCOUNTS: Record<string, number>,
 *   MAX_COMPONENT_DISCOUNT: number, MANA_FLOOR: number, MAX_MANA_CEILING: number,
 * }}
 */
export const SPELL_BALANCE_CONSTANTS = Object.freeze({
  WEIGHT_DAMAGE: 1.0,
  WEIGHT_HEALING: 1.5,
  WEIGHT_BARRIER: 1.2,

  CC_WEIGHTS: Object.freeze({ none: 0.0, slow: 8.0, root: 15.0, stun: 25.0 }),
  RANGE_FACTORS: Object.freeze({ touch: 1.0, short: 1.1, medium: 1.25, long: 1.5 }),
  AREA_FACTORS: Object.freeze({ singleTarget: 1.0, cone: 1.3, line: 1.4, sphere: 1.6 }),
  DURATION_FACTORS: Object.freeze({ instant: 1.0, concentration: 1.25, sustained: 1.5 }),

  COMPONENT_DISCOUNTS: Object.freeze({ verbal: 0.10, somatic: 0.10, material: 0.10 }),
  MAX_COMPONENT_DISCOUNT: 0.30,
  MANA_FLOOR: 5,
  MAX_MANA_CEILING: 200,
});

/** Umbrales superiores de cada Círculo (inclusivos) con su etiqueta solemne. */
const CIRCLE_THRESHOLDS = Object.freeze([
  { threshold: 20, circle: 1, label: 'Círculo I (Iniciado)' },
  { threshold: 45, circle: 2, label: 'Círculo II (Adepto)' },
  { threshold: 80, circle: 3, label: 'Círculo III (Magister)' },
  { threshold: 130, circle: 4, label: 'Círculo IV (Maestro)' },
]);

/** Etiqueta del Círculo V (más allá del último umbral, hasta el techo). */
const TOP_CIRCLE = Object.freeze({ circle: 5, label: 'Círculo V (Archimago)' });

/**
 * Error lanzado cuando el maná supera el techo de 200 (Sobrecarga Arcana).
 * Espejo cliente de ArcaneOverloadException: porta el contrato HTTP 400
 * del Endpoint 1 para que la UI reaccione igual que ante el sobre real.
 */
export class ArcaneOverloadError extends Error {
  /**
   * @param {number} calculatedMana Maná calculado que excedió el techo.
   */
  constructor(calculatedMana) {
    super('La concentración de poder supera la capacidad de contención del plano mortal (máx. 200 maná).');
    this.name = 'ArcaneOverloadError';
    this.calculatedMana = calculatedMana;
    this.httpStatusCode = 400;
    this.errorCode = 'ARCANE_OVERLOAD';
  }
}

/**
 * Valida que los modificadores pertenezcan al canon (misma defensa que
 * el DTO de entrada del backend, Tarea 1.2).
 *
 * @param {{crowdControlType: string, rangeType: string, areaType: string, durationType: string}} params
 */
function assertCanonicalModifiers(params) {
  const { CC_WEIGHTS, RANGE_FACTORS, AREA_FACTORS, DURATION_FACTORS } = SPELL_BALANCE_CONSTANTS;

  if (!(params.crowdControlType in CC_WEIGHTS)) {
    throw new RangeError(`El tipo de control de masas no pertenece al canon arcánico: ${params.crowdControlType}`);
  }
  if (!(params.rangeType in RANGE_FACTORS)) {
    throw new RangeError(`El alcance no pertenece al canon arcánico: ${params.rangeType}`);
  }
  if (!(params.areaType in AREA_FACTORS)) {
    throw new RangeError(`La geometría de área no pertenece al canon arcánico: ${params.areaType}`);
  }
  if (!(params.durationType in DURATION_FACTORS)) {
    throw new RangeError(`La duración no pertenece al canon arcánico: ${params.durationType}`);
  }
}

/**
 * Asigna el Círculo Arcano según el coste final (espejo de
 * SpellBalanceService::determineCircle, RF-03.1).
 *
 * Nota de compatibilidad: la forma de retorno es { circle, label } en
 * los umbrales y { circle, label } en el Círculo V; este módulo expone
 * además circleLabel para el consumo directo de la UI.
 *
 * @param {number} finalManaCost Coste final de maná (1..200).
 * @returns {{circle: number, label: string, circleLabel: string}} Círculo y etiqueta solemne.
 */
export function determineCircle(finalManaCost) {
  const circleResult = resolveCircle(finalManaCost);
  return { circle: circleResult.circle, label: circleResult.label, circleLabel: circleResult.label };
}

/**
 * Resolución interna del Círculo (forma canónica { circle, label }).
 *
 * @param {number} finalManaCost
 * @returns {{circle: number, label: string}}
 */
function resolveCircle(finalManaCost) {
  for (const { threshold, circle, label } of CIRCLE_THRESHOLDS) {
    if (finalManaCost <= threshold) {
      return { circle, label };
    }
  }
  return { ...TOP_CIRCLE };
}

/**
 * Evalúa si un coste supera el techo de Sobrecarga Arcana (Art. II).
 *
 * @param {number} finalManaCost Coste final de maná.
 * @returns {boolean} true si supera los 200 de maná.
 */
export function isOverloaded(finalManaCost) {
  return finalManaCost > SPELL_BALANCE_CONSTANTS.MAX_MANA_CEILING;
}

/**
 * Calcula el desglose pedagógico completo del coste de maná con la
 * fórmula EXACTA del backend (plan 3.1):
 *   base = daño×1.0 + cura×1.5 + barrera×1.2 + CC[tipo]
 *   bruto = base × (alcance × área × duración)
 *   neto = bruto × (1 − min(descuento, 0.30))
 *   coste = max(5, ceil(neto))  — con Sobrecarga si supera 200.
 *
 * @param {{
 *   damage: number, healing: number, barrier: number, crowdControlType: string,
 *   rangeType: string, areaType: string, durationType: string,
 *   hasVerbal: boolean, hasSomatic: boolean, hasMaterial: boolean,
 * }} params Parámetros matemáticos del conjuro (mismo contrato que el DTO).
 * @returns {{
 *   baseEffectPoints: number, multipliers: {range: number, area: number, duration: number, combined: number},
 *   grossMana: number, discounts: {verbal: number, somatic: number, material: number, totalPercent: number, amountDeducted: number},
 *   netMana: number, finalManaCost: number, circle: number, circleLabel: string, isOverloaded: boolean,
 * }} Desglose pedagógico (mismo contrato que SpellCalculationResultDto).
 * @throws {RangeError} Si un modificador está fuera del canon o el
 *   conjuro carece de efectos base (sin forma arcana).
 * @throws {ArcaneOverloadError} Si el coste supera los 200 de maná.
 */
export function simulateMana(params) {
  const {
    WEIGHT_DAMAGE, WEIGHT_HEALING, WEIGHT_BARRIER,
    CC_WEIGHTS, RANGE_FACTORS, AREA_FACTORS, DURATION_FACTORS,
    COMPONENT_DISCOUNTS, MAX_COMPONENT_DISCOUNT, MANA_FLOOR, MAX_MANA_CEILING,
  } = SPELL_BALANCE_CONSTANTS;

  assertCanonicalModifiers(params);

  // 1. Suma de puntos de efectos base ponderados (plan 3.1, paso 1).
  const baseEffectPoints =
    params.damage * WEIGHT_DAMAGE +
    params.healing * WEIGHT_HEALING +
    params.barrier * WEIGHT_BARRIER +
    CC_WEIGHTS[params.crowdControlType];

  if (baseEffectPoints <= 0) {
    // Mismo veredicto que el backend: sin efectos no hay forma arcana.
    throw new RangeError('Un conjuro sin efectos carece de forma arcana: necesita daño, cura, barrera o control de masas.');
  }

  // 2. Multiplicadores geométricos combinados (plan 3.1, paso 2).
  const rangeMultiplier = RANGE_FACTORS[params.rangeType];
  const areaMultiplier = AREA_FACTORS[params.areaType];
  const durationMultiplier = DURATION_FACTORS[params.durationType];
  const combinedMultiplier = rangeMultiplier * areaMultiplier * durationMultiplier;

  const grossMana = baseEffectPoints * combinedMultiplier;

  // 3. Descuento acotado por componentes (plan 3.1, paso 3).
  let totalPercent = 0.0;
  if (params.hasVerbal) {
    totalPercent += COMPONENT_DISCOUNTS.verbal;
  }
  if (params.hasSomatic) {
    totalPercent += COMPONENT_DISCOUNTS.somatic;
  }
  if (params.hasMaterial) {
    totalPercent += COMPONENT_DISCOUNTS.material;
  }
  totalPercent = Math.min(totalPercent, MAX_COMPONENT_DISCOUNT);

  // 4. Redondeo ceil y suelo mínimo de 5 (plan 3.1, paso 4).
  const netMana = grossMana * (1.0 - totalPercent);
  const finalManaCost = Math.max(MANA_FLOOR, Math.ceil(netMana));

  // 5. Evaluación de Sobrecarga Arcana (plan 3.1, paso 5, Art. II).
  if (isOverloaded(finalManaCost)) {
    throw new ArcaneOverloadError(finalManaCost);
  }

  // 6. Asignación automática del Círculo Arcano (plan 3.1, paso 6).
  const { circle, label: circleLabel } = determineCircle(finalManaCost);

  return {
    baseEffectPoints,
    multipliers: {
      range: rangeMultiplier,
      area: areaMultiplier,
      duration: durationMultiplier,
      combined: combinedMultiplier,
    },
    grossMana,
    discounts: {
      verbal: params.hasVerbal ? COMPONENT_DISCOUNTS.verbal : 0.0,
      somatic: params.hasSomatic ? COMPONENT_DISCOUNTS.somatic : 0.0,
      material: params.hasMaterial ? COMPONENT_DISCOUNTS.material : 0.0,
      totalPercent,
      amountDeducted: grossMana - netMana,
    },
    netMana,
    finalManaCost,
    circle,
    circleLabel,
    isOverloaded: false,
  };
}
