/**
 * test_spell_balance_simulator.mjs — Arnés TDD de la Tarea 5.1 (TASKS-04).
 *
 * Verifica el simulador matemático en cliente
 * (public/assets/js/utils/spellBalanceSimulator.js, ES Module nativo):
 *   - Constantes espejo exactas del backend (WEIGHTS, CC_WEIGHTS,
 *     RANGE_FACTORS, AREA_FACTORS, DURATION_FACTORS, COMPONENT_DISCOUNTS,
 *     MAX_COMPONENT_DISCOUNT, MANA_FLOOR, MAX_MANA_CEILING).
 *   - Fórmula simétrica: mismo desglose que SpellBalanceService (base,
 *     multiplicadores, bruto, descuentos, neto, coste final, círculo,
 *     etiqueta solemne, isOverloaded) y sobrecarga > 200.
 *   - SIMETRÍA PHP-JS: casos canónicos y aleatorios comparados contra la
 *     salida real del backend PHP (CLI), incluidas las 10 fronteras de
 *     los 5 Círculos y la leyenda ceremonial de sobrecarga.
 *   - Latencia < 10 ms por simulación en lote (RNF-02).
 *
 * Criterio «Hecho cuando» (Tarea 5.1): el simulador ejecutado con un
 * objeto de parámetros produce exactamente los mismos valores de maná
 * bruto, descuento, coste final y círculo que el backend de PHP.
 *
 * Constitución:
 *   - Artículo I (Dogma Vanilla): ES Modules nativos, sin empaquetadores
 *     ni librerías; node:child_process solo en el ARNÉS (verificación).
 *   - Artículo II: una sola ley universal del maná, dos lenguajes.
 *   - Artículo V: identificadores en inglés camelCase, leyendas y
 *     comentarios en castellano.
 *
 * Uso: node scratch/test_spell_balance_simulator.mjs
 */

import { spawnSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';
import path from 'node:path';
import fs from 'node:fs';

const projectRoot = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const simulatorPath = path.join(projectRoot, 'public/assets/js/utils/spellBalanceSimulator.js');

let assertsPassed = 0;
let assertsFailed = 0;

/** Aserta una condición y registra el resultado en la bitáloga de consola. */
function assertArcane(condition, legend) {
  if (condition) {
    assertsPassed++;
    console.log(`  OK   ${legend}`);
  } else {
    assertsFailed++;
    console.log(`  FALLA ${legend}`);
  }
}

/** Comparación de flotantes con tolerancia épsilon (aritmética IEEE-754). */
function approxEqual(a, b, epsilon = 1e-9) {
  return Math.abs(a - b) <= epsilon * Math.max(1, Math.abs(a), Math.abs(b));
}

console.log('=== Tarea 5.1 (TASKS-04): simulador de balance en cliente ===\n');

// =====================================================================
// [0] Superficie (fase roja): el módulo existe y exporta el contrato.
// =====================================================================
console.log('[0] Superficie');
assertArcane(fs.existsSync(simulatorPath), 'public/assets/js/utils/spellBalanceSimulator.js existe');

let simulator = null;
try {
  simulator = await import(`file://${simulatorPath.replace(/\\/g, '/')}`);
} catch (importError) {
  assertArcane(false, `El módulo se importa sin errores (${importError.message})`);
}

if (simulator) {
  for (const exportName of ['SPELL_BALANCE_CONSTANTS', 'simulateMana', 'determineCircle', 'isOverloaded']) {
    assertArcane(typeof simulator[exportName] !== 'undefined', `El módulo exporta ${exportName}`);
  }
}

// Si el módulo no existe, no tiene sentido continuar: fase roja completa.
if (!simulator || typeof simulator.simulateMana !== 'function') {
  console.log(`\n=== RESUMEN: ${assertsPassed} asertos superados, ${assertsFailed} fallidos ===`);
  process.exit(1);
}

const { SPELL_BALANCE_CONSTANTS, simulateMana, determineCircle, isOverloaded } = simulator;

// =====================================================================
// [1] Constantes espejo: idénticas a las del backend PHP.
// =====================================================================
console.log('\n[1] Constantes espejo del backend (plan 3.1)');

assertArcane(SPELL_BALANCE_CONSTANTS.WEIGHT_DAMAGE === 1.0, 'WEIGHT_DAMAGE = 1.0');
assertArcane(SPELL_BALANCE_CONSTANTS.WEIGHT_HEALING === 1.5, 'WEIGHT_HEALING = 1.5');
assertArcane(SPELL_BALANCE_CONSTANTS.WEIGHT_BARRIER === 1.2, 'WEIGHT_BARRIER = 1.2');
assertArcane(
  JSON.stringify(SPELL_BALANCE_CONSTANTS.CC_WEIGHTS) === JSON.stringify({ none: 0.0, slow: 8.0, root: 15.0, stun: 25.0 }),
  'CC_WEIGHTS = { none: 0, slow: 8, root: 15, stun: 25 }'
);
assertArcane(
  JSON.stringify(SPELL_BALANCE_CONSTANTS.RANGE_FACTORS) === JSON.stringify({ touch: 1.0, short: 1.1, medium: 1.25, long: 1.5 }),
  'RANGE_FACTORS = { touch: 1, short: 1.1, medium: 1.25, long: 1.5 }'
);
assertArcane(
  JSON.stringify(SPELL_BALANCE_CONSTANTS.AREA_FACTORS) === JSON.stringify({ singleTarget: 1.0, cone: 1.3, line: 1.4, sphere: 1.6 }),
  'AREA_FACTORS = { singleTarget: 1, cone: 1.3, line: 1.4, sphere: 1.6 }'
);
assertArcane(
  JSON.stringify(SPELL_BALANCE_CONSTANTS.DURATION_FACTORS) === JSON.stringify({ instant: 1.0, concentration: 1.25, sustained: 1.5 }),
  'DURATION_FACTORS = { instant: 1, concentration: 1.25, sustained: 1.5 }'
);
assertArcane(
  JSON.stringify(SPELL_BALANCE_CONSTANTS.COMPONENT_DISCOUNTS) === JSON.stringify({ verbal: 0.10, somatic: 0.10, material: 0.10 }),
  'COMPONENT_DISCOUNTS = { verbal: 0.10, somatic: 0.10, material: 0.10 }'
);
assertArcane(SPELL_BALANCE_CONSTANTS.MAX_COMPONENT_DISCOUNT === 0.30, 'MAX_COMPONENT_DISCOUNT = 0.30');
assertArcane(SPELL_BALANCE_CONSTANTS.MANA_FLOOR === 5, 'MANA_FLOOR = 5');
assertArcane(SPELL_BALANCE_CONSTANTS.MAX_MANA_CEILING === 200, 'MAX_MANA_CEILING = 200 (techo de Sobrecarga Arcana)');

// =====================================================================
// [2] Fórmula simétrica: casos canónicos del plan 6.1.
// =====================================================================
console.log('\n[2] Casos canónicos del plan (simetría de fórmula)');

// Caso del Endpoint 1: 30 daño, medium/sphere/instant, v+s → 48, Círculo III.
const canonical = simulateMana({
  damage: 30, healing: 0, barrier: 0, crowdControlType: 'none',
  rangeType: 'medium', areaType: 'sphere', durationType: 'instant',
  hasVerbal: true, hasSomatic: true, hasMaterial: false,
});
assertArcane(approxEqual(canonical.baseEffectPoints, 30.0), 'baseEffectPoints = 30.0');
assertArcane(approxEqual(canonical.multipliers.combined, 2.0), 'multipliers.combined = 2.0');
assertArcane(approxEqual(canonical.grossMana, 60.0), 'grossMana = 60.0');
assertArcane(approxEqual(canonical.discounts.totalPercent, 0.20), 'discounts.totalPercent = 0.20');
assertArcane(approxEqual(canonical.discounts.amountDeducted, 12.0), 'discounts.amountDeducted = 12.0');
assertArcane(approxEqual(canonical.netMana, 48.0), 'netMana = 48.0');
assertArcane(canonical.finalManaCost === 48, 'finalManaCost = 48');
assertArcane(canonical.circle === 3 && canonical.circleLabel === 'Círculo III (Magister)', 'Círculo III (Magister) solemne');
assertArcane(canonical.isOverloaded === false, 'isOverloaded = false');

// Suelo: 1 daño con 3 componentes → 5 (MANA_FLOOR).
const floorCase = simulateMana({
  damage: 1, healing: 0, barrier: 0, crowdControlType: 'none',
  rangeType: 'touch', areaType: 'singleTarget', durationType: 'instant',
  hasVerbal: true, hasSomatic: true, hasMaterial: true,
});
assertArcane(floorCase.finalManaCost === 5 && floorCase.circle === 1, 'El suelo de 5 de maná clasifica en Círculo I');

// Sobrecarga: 150 daño × long × sphere ≈ 360 → excepción.
let overloadThrown = false;
try {
  simulateMana({
    damage: 150, healing: 0, barrier: 0, crowdControlType: 'none',
    rangeType: 'long', areaType: 'sphere', durationType: 'instant',
    hasVerbal: false, hasSomatic: false, hasMaterial: false,
  });
} catch (overloadError) {
  overloadThrown = overloadError.name === 'ArcaneOverloadError'
    && overloadError.calculatedMana > 200
    && overloadError.httpStatusCode === 400
    && overloadError.errorCode === 'ARCANE_OVERLOAD';
}
assertArcane(overloadThrown, 'La sobrecarga (> 200) lanza ArcaneOverloadError con calculatedMana y contrato 400');

// Frontera inclusiva: 200 exactos admitidos, 201 rechazados.
assertArcane(
  simulateMana({ damage: 200, healing: 0, barrier: 0, crowdControlType: 'none', rangeType: 'touch', areaType: 'singleTarget', durationType: 'instant', hasVerbal: false, hasSomatic: false, hasMaterial: false }).finalManaCost === 200,
  'El maná 200 exacto NO dispara la sobrecarga (frontera inclusiva)'
);

// Sin efectos: InvalidArgumentException (un conjuro sin forma arcana).
let shapelessThrown = false;
try {
  simulateMana({
    damage: 0, healing: 0, barrier: 0, crowdControlType: 'none',
    rangeType: 'touch', areaType: 'singleTarget', durationType: 'instant',
    hasVerbal: false, hasSomatic: false, hasMaterial: false,
  });
} catch (shapelessError) {
  shapelessThrown = shapelessError instanceof RangeError;
}
assertArcane(shapelessThrown, 'Un conjuro sin efectos lanza RangeError (carece de forma arcana)');

// =====================================================================
// [3] Círculos: las 10 fronteras del RF-03.1.
// =====================================================================
console.log('\n[3] Fronteras de los 5 Círculos Arcanos');

const circleBoundaries = [[5, 1], [20, 1], [21, 2], [45, 2], [46, 3], [80, 3], [81, 4], [130, 4], [131, 5], [200, 5]];
let boundariesOk = true;
for (const [mana, expectedCircle] of circleBoundaries) {
  if (determineCircle(mana).circle !== expectedCircle) {
    boundariesOk = false;
  }
}
assertArcane(boundariesOk, 'Las 10 fronteras de los 5 Círculos clasifican correctamente');
assertArcane(determineCircle(48).circleLabel === 'Círculo III (Magister)', 'Las etiquetas solemnes viajan en castellano');

// =====================================================================
// [4] SIMETRÍA PHP-JS: comparación contra el backend real.
// =====================================================================
console.log('\n[4] Simetría PHP-JS contra SpellBalanceService (CLI)');

/**
 * Puente de verificación: invoca un script PHP del arnés que ejecuta el
 * backend REAL (SpellBalanceService) con un payload y devuelve su JSON.
 * Espejo del cálculo cliente: mismo contrato que simulateMana().
 */
function backendCalculate(payload) {
  const bridgePath = path.join(projectRoot, 'scratch/test_spell_balance_bridge.php');
  const result = spawnSync('php', [bridgePath, JSON.stringify(payload)], { encoding: 'utf8' });
  if (result.status !== 0) {
    return { overload: true, raw: result.stdout };
  }
  try {
    return JSON.parse(result.stdout);
  } catch (parseError) {
    return { parseError: true, raw: result.stdout };
  }
}

/**
 * Caso aleatorio dentro del dominio canónico (daño/cura/barrera 0-60,
 * modificadores del canon, componentes booleanos).
 */
function forgeRandomCase(randomSeed) {
  const modifiers = {
    crowdControlType: ['none', 'slow', 'root', 'stun'],
    rangeType: ['touch', 'short', 'medium', 'long'],
    areaType: ['singleTarget', 'cone', 'line', 'sphere'],
    durationType: ['instant', 'concentration', 'sustained'],
  };
  const pick = (values) => values[Math.floor(randomSeed() * values.length)];
  return {
    damage: Math.floor(randomSeed() * 61),
    healing: Math.floor(randomSeed() * 61),
    barrier: Math.floor(randomSeed() * 61),
    crowdControlType: pick(modifiers.crowdControlType),
    rangeType: pick(modifiers.rangeType),
    areaType: pick(modifiers.areaType),
    durationType: pick(modifiers.durationType),
    hasVerbal: randomSeed() < 0.5,
    hasSomatic: randomSeed() < 0.5,
    hasMaterial: randomSeed() < 0.5,
  };
}

// Generador determinista (mulberry32): la batería es reproducible.
function createRandomGenerator(seed) {
  let state = seed;
  return function random() {
    state |= 0;
    state = (state + 0x6D2B79F5) | 0;
    let t = Math.imul(state ^ (state >>> 15), 1 | state);
    t = (t + Math.imul(t ^ (t >>> 7), 61 | t)) ^ t;
    return ((t ^ (t >>> 14)) >>> 0) / 4294967296;
  };
}

// Casos canónicos fijos + 25 aleatorios reproducibles.
const symmetryCases = [
  { damage: 30, healing: 0, barrier: 0, crowdControlType: 'none', rangeType: 'medium', areaType: 'sphere', durationType: 'instant', hasVerbal: true, hasSomatic: true, hasMaterial: false },
  { damage: 1, healing: 0, barrier: 0, crowdControlType: 'none', rangeType: 'touch', areaType: 'singleTarget', durationType: 'instant', hasVerbal: true, hasSomatic: true, hasMaterial: true },
  { damage: 0, healing: 20, barrier: 5, crowdControlType: 'slow', rangeType: 'short', areaType: 'cone', durationType: 'concentration', hasVerbal: false, hasSomatic: true, hasMaterial: false },
  { damage: 45, healing: 0, barrier: 0, crowdControlType: 'none', rangeType: 'long', areaType: 'sphere', durationType: 'instant', hasVerbal: true, hasSomatic: true, hasMaterial: false },
];
const randomGenerator = createRandomGenerator(20260913);
for (let i = 0; i < 25; i++) {
  symmetryCases.push(forgeRandomCase(randomGenerator));
}

let compared = 0;
let identical = 0;
const divergences = [];
for (const testCase of symmetryCases) {
  const clientResult = (() => {
    try {
      return simulateMana(testCase);
    } catch (clientError) {
      // El cliente también puede lanzar (sin efectos o sobrecarga).
      return { thrown: clientError.name, calculatedMana: clientError.calculatedMana ?? null };
    }
  })();
  const backendResult = backendCalculate(testCase);
  compared++;

  if (clientResult.thrown !== undefined || backendResult.overload) {
    // Camino de excepción: la sobrecarga debe coincidir en ambos mundos.
    const clientOverload = clientResult.thrown === 'ArcaneOverloadError';
    const backendOverload = backendResult.overload === true;
    if (clientOverload && backendOverload && clientResult.calculatedMana === backendResult.calculatedMana) {
      identical++;
    } else {
      divergences.push({ testCase, clientResult, backendResult });
    }
    continue;
  }

  const same =
    approxEqual(clientResult.baseEffectPoints, backendResult.baseEffectPoints) &&
    approxEqual(clientResult.multipliers.combined, backendResult.multipliers.combined) &&
    approxEqual(clientResult.grossMana, backendResult.grossMana) &&
    approxEqual(clientResult.discounts.totalPercent, backendResult.discounts.totalPercent) &&
    approxEqual(clientResult.discounts.amountDeducted, backendResult.discounts.amountDeducted) &&
    approxEqual(clientResult.netMana, backendResult.netMana) &&
    clientResult.finalManaCost === backendResult.finalManaCost &&
    clientResult.circle === backendResult.circle &&
    clientResult.circleLabel === backendResult.circleLabel &&
    clientResult.isOverloaded === backendResult.isOverloaded &&
    approxEqual(clientResult.multipliers.range, backendResult.multipliers.range) &&
    approxEqual(clientResult.multipliers.area, backendResult.multipliers.area) &&
    approxEqual(clientResult.multipliers.duration, backendResult.multipliers.duration);

  if (same) {
    identical++;
  } else {
    divergences.push({ testCase, clientResult, backendResult });
  }
}

assertArcane(compared === 29, `Los 29 casos (4 canónicos + 25 aleatorios) se comparan con el backend PHP`);
assertArcane(
  identical === compared,
  `Simetría PERFECTA PHP-JS en ${identical}/${compared} casos (base, multiplicadores, bruto, descuento, neto, coste, círculo)`
);
for (const divergence of divergences.slice(0, 3)) {
  console.log(`       ↳ divergencia: ${JSON.stringify(divergence.testCase)} → cliente ${JSON.stringify(divergence.clientResult?.finalManaCost ?? divergence.clientResult?.thrown)} vs backend ${JSON.stringify(divergence.backendResult?.finalManaCost ?? 'overload')}`);
}

// =====================================================================
// [5] Latencia: < 10 ms por simulación (RNF-02).
// =====================================================================
console.log('\n[5] Latencia del simulador en cliente');

const latencyPayload = {
  damage: 30, healing: 5, barrier: 3, crowdControlType: 'slow',
  rangeType: 'medium', areaType: 'line', durationType: 'sustained',
  hasVerbal: true, hasSomatic: true, hasMaterial: false,
};
// Calentamiento del motor JS.
for (let i = 0; i < 100; i++) {
  simulateMana(latencyPayload);
}
const latencyIterations = 1000;
const latencyStart = process.hrtime.bigint();
for (let i = 0; i < latencyIterations; i++) {
  simulateMana(latencyPayload);
}
const averageMs = Number(process.hrtime.bigint() - latencyStart) / 1e6 / latencyIterations;
assertArcane(averageMs < 10, `Latencia media de ${averageMs.toFixed(4)} ms por simulación (< 10 ms, ${latencyIterations} iteraciones)`);

// =====================================================================
// Resumen final.
// =====================================================================
console.log(`\n=== RESUMEN: ${assertsPassed} asertos superados, ${assertsFailed} fallidos ===`);
process.exit(assertsFailed === 0 ? 0 : 1);
