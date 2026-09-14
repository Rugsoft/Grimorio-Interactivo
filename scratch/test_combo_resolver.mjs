/**
 * test_combo_resolver.mjs — Arnés TDD de la Tarea 2.2 (TASKS-06).
 *
 * Estrategia TDD: este script se escribe ANTES que la implementación.
 * Verifica, sobre `public/assets/js/utils/comboResolver.js`:
 *   [1] Superficie: fábrica createComboResolver con reloj y gestor
 *       anti-stunlock inyectables, y matriz del Códice cargada en memoria.
 *   [2] Caso A — Blanco neutral: imbuye aura 5 s, daño íntegro (RF-02.1),
 *       con evento combo:aura-applied {element, expiresAt, durationMs: 5000}.
 *   [3] Caso B — Mismo elemento: refresco con efecto auraRefreshed y
 *       evento combo:aura-refreshed (RF-02.4).
 *   [4] Caso C — Catalizador: pureArcaneResonance, ceil(40×1.25)=50,
 *       consume aura (RF-03.2, RF-03.4).
 *   [5] Caso D — Reacción dual simétrica: ceil(40×1.5)=60 en ambas
 *       orientaciones, neutral puro y evento combo:reaction-triggered
 *       con los DOS colores elementales (plan 4.2) (RF-03.1, RF-04.1).
 *   [6] Caso E — Sobreescritura no reactiva: daño íntegro, auraOverwritten
 *       (RF-03.3).
 *   [7] Salvaguarda: Hard CC con inmunidad → shockwaveGranted; Hard CC sin
 *       inmunidad → concede inmunidad vía el gestor inyectado (RF-05.2/05.3).
 *   [8] Paridad con el backend (criterio «Hecho cuando»): una batería de
 *       casos se resuelve en cliente y en el servicio PHP real
 *       (scratch/bridge_combo_verdict.php); los veredictos JSON de once
 *       claves deben ser idénticos byte a byte. Además el motor mide su
 *       latencia propia: toda resolución por debajo de 5 ms.
 *   [9] Determinismo (RNF-01): dos resoluciones idénticas → JSON idéntico.
 *
 * Criterio «Hecho cuando» (Tarea 2.2): la resolución en cliente arroja
 * idénticos resultados de daño y efectos que el backend en menos de 5 ms
 * sin consultas de red.
 *
 * Constitución:
 *   - Artículo I (Dogma Vanilla): módulo ES nativo, APIs falsables,
 *     cero dependencias; la paridad se mide con un puente CLI nativo.
 *   - Artículo II: los factores se LEEN de la matriz espejo del Códice,
 *     idéntica a la del backend (mismo origen de datos, plan 2.1).
 *   - Artículo V: identificadores en inglés camelCase; documentación en
 *     castellano.
 *
 * Uso: node scratch/test_combo_resolver.mjs
 */

import { execFileSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';

let passed = 0;
let failed = 0;
/** @type {string[]} */
const failures = [];

function assertTruthy(condition, label) {
  if (condition) {
    passed++;
    console.log(`  [OK]  ${label}`);
    return;
  }
  failed++;
  failures.push(label);
  console.log(`  [FALLA] ${label}`);
}

/** Reloj falso gobernado por el arnés. */
function createFakeTime({ startMs = 1_000_000 } = {}) {
  let currentMs = startMs;
  return {
    now: () => currentMs,
    advance(deltaMs) { currentMs += deltaMs; },
  };
}

/** Target DOM falso que graba los eventos despachados. */
function createRecordingTarget() {
  const events = [];
  return {
    events,
    dispatchEvent(event) { events.push({ type: event.type, detail: event.detail ?? null }); return true; },
    ofType(type) { return events.filter((e) => e.type === type); },
  };
}

/** Doble del gestor anti-stunlock, gobernado por el arnés. */
function createFakeStunlockManager() {
  return {
    lastGranted: 0,
    immune: false,
    grantImmunity() { this.lastGranted++; },
  };
}

const MODULE_URL = new URL('../public/assets/js/utils/comboResolver.js', import.meta.url).href;

/** Carga el módulo (fase roja: no existe aún). */
async function loadModule() {
  return import(MODULE_URL);
}

/** Forja un resolutor con reloj, gestor y target falsos. */
async function forgeResolver({ time = createFakeTime(), stunlock = createFakeStunlockManager(), target = createRecordingTarget() } = {}) {
  const { createComboResolver } = await loadModule();
  return createComboResolver({ now: time.now, stunlockManager: stunlock, eventTarget: target });
}

/** Impacto de prueba. */
function spellOf(element, baseDamage, extra = {}) {
  return { id: 'spl_probe', element, baseDamage, baseHealing: 0, baseBarrier: 0, crowdControlType: 'none', ...extra };
}

/** Resuelve con el servicio PHP real (backend autoritativo). */
function backendVerdict(payload) {
  const stdout = execFileSync(
    'php',
    [fileURLToPath(new URL('bridge_combo_verdict.php', import.meta.url)), JSON.stringify(payload)],
    { encoding: 'utf8' },
  );
  return JSON.parse(stdout);
}

console.log('== ARNÉS TDD — MOTOR DE COMBOS EN CLIENTE (Tarea 2.2, SPEC-06) ==');

// ---------------------------------------------------------------------------
console.log('\n[FASE 1] Superficie del módulo');
// ---------------------------------------------------------------------------

let module = null;
try {
  module = await loadModule();
  assertTruthy(typeof module.createComboResolver === 'function', 'El módulo comboResolver.js exporta su fábrica (fase roja si falta)');
} catch (error) {
  assertTruthy(false, `El módulo comboResolver.js exporta su fábrica (${error.message})`);
}

if (module) {
  assertTruthy(Array.isArray(module.ELEMENTAL_MATRIX_REACTIONS) && module.ELEMENTAL_MATRIX_REACTIONS.length === 8, 'La matriz espejo del Códice porta las 8 aristas reactivas');
  assertTruthy(Array.isArray(module.ELEMENTAL_MATRIX_ELEMENTS) && module.ELEMENTAL_MATRIX_ELEMENTS.length === 8, 'La matriz espejo porta los 8 elementos canónicos');

  const surface = await forgeResolver();
  for (const methodName of ['resolveImpact', 'getMatrix', 'findReaction']) {
    assertTruthy(typeof surface[methodName] === 'function', `La API expone ${methodName}()`);
  }
}

// ---------------------------------------------------------------------------
console.log('\n[FASE 2] Caso A — Blanco neutral (RF-02.1)');
// ---------------------------------------------------------------------------

{
  const time = createFakeTime();
  const target = createRecordingTarget();
  const resolver = await forgeResolver({ time, target });

  const verdict = resolver.resolveImpact({ activeAura: '', incomingSpell: spellOf('water', 40), stunlockImmune: false });

  assertTruthy(verdict.isReaction === false && verdict.effectiveDamage === 40, 'Sobre blanco neutral: daño íntegro sin reacción');
  assertTruthy(verdict.resultingAura === 'water', 'El conjuro imbuye su aura elemental');
  assertTruthy(verdict.damageMultiplierApplied === 1.0, 'El factor aplicado es la unidad');
  assertTruthy(target.ofType('combo:aura-applied').length === 1, 'Se emite combo:aura-applied');
  assertTruthy(target.ofType('combo:aura-applied')[0]?.detail?.durationMs === 5000, 'El evento porta la ventana de resonancia de 5000 ms');
  assertTruthy(target.ofType('combo:aura-applied')[0]?.detail?.element === 'water', 'El evento porta el elemento imbuydo');
  assertTruthy(typeof target.ofType('combo:aura-applied')[0]?.detail?.expiresAt === 'number', 'El evento porta el instante de expiración');

  // El catalizador no imbuye aura sobre neutral.
  const arcaneNeutral = resolver.resolveImpact({ activeAura: '', incomingSpell: spellOf('pureArcane', 20), stunlockImmune: false });
  assertTruthy(arcaneNeutral.isReaction === false && arcaneNeutral.resultingAura === null, 'Arcano Puro sobre neutral no imbuye aura ni detona nada');
}

// ---------------------------------------------------------------------------
console.log('\n[FASE 3] Caso B — Mismo elemento, refresco (RF-02.4)');
// ---------------------------------------------------------------------------

{
  const time = createFakeTime();
  const target = createRecordingTarget();
  const resolver = await forgeResolver({ time, target });

  const refreshed = resolver.resolveImpact({ activeAura: 'water', incomingSpell: spellOf('water', 40), stunlockImmune: false });

  assertTruthy(refreshed.isReaction === false && refreshed.effectiveDamage === 40, 'El mismo elemento aplica daño pleno sin reacción');
  assertTruthy(refreshed.tacticalEffectApplied === 'auraRefreshed', 'El efecto aplicado es el refresco del aura');
  assertTruthy(refreshed.resultingAura === 'water', 'El aura persiste con su elemento');
  assertTruthy(target.ofType('combo:aura-refreshed').length === 1, 'Se emite combo:aura-refreshed');
  assertTruthy(target.ofType('combo:aura-refreshed')[0]?.detail?.element === 'water', 'El evento de refresco porta el elemento');
}

// ---------------------------------------------------------------------------
console.log('\n[FASE 4] Caso C — Catalizador Arcano Puro (RF-03.2)');
// ---------------------------------------------------------------------------

{
  const time = createFakeTime();
  const target = createRecordingTarget();
  const resolver = await forgeResolver({ time, target });

  const resonance = resolver.resolveImpact({ activeAura: 'fire', incomingSpell: spellOf('pureArcane', 40), stunlockImmune: false });

  assertTruthy(resonance.isReaction === true && resonance.reactionId === 'pureArcaneResonance', 'Arcano Puro sobre aura detona la Resonancia Arcana Pura');
  assertTruthy(resonance.effectiveDamage === 50 && resonance.damageMultiplierApplied === 1.25, 'La amplificación es ceil(40 × 1.25) = 50');
  assertTruthy(resonance.clearedAura === true && resonance.resultingAura === null, 'Consume el aura y deja neutral puro (RF-03.4)');
  assertTruthy(target.ofType('combo:reaction-triggered').length === 1, 'Se emite combo:reaction-triggered');
}

// ---------------------------------------------------------------------------
console.log('\n[FASE 5] Caso D — Reacción dual simétrica y +50% (RF-03.1, RF-04.1)');
// ---------------------------------------------------------------------------

{
  const time = createFakeTime();
  const target = createRecordingTarget();
  const resolver = await forgeResolver({ time, target });

  const forward = resolver.resolveImpact({ activeAura: 'fire', incomingSpell: spellOf('water', 40), stunlockImmune: false });
  const reverse = resolver.resolveImpact({ activeAura: 'water', incomingSpell: spellOf('fire', 40), stunlockImmune: false });

  assertTruthy(forward.isReaction === true && forward.reactionId === 'arcaneVaporization', 'Fuego + Agua detona la Vaporización Arcana');
  assertTruthy(reverse.reactionId === 'arcaneVaporization', 'La orientación inversa resuelve la misma reacción');
  assertTruthy(forward.effectiveDamage === 60 && forward.damageMultiplierApplied === 1.5, 'La bonificación es ceil(40 × 1.5) = 60');
  assertTruthy(forward.clearedAura === true && forward.resultingAura === null, 'La detonación deja neutral puro (RF-03.4)');

  const reactionEvent = target.ofType('combo:reaction-triggered')[0] ?? null;
  assertTruthy(reactionEvent !== null, 'Se emite combo:reaction-triggered');
  assertTruthy(reactionEvent?.detail?.reactionId === 'arcaneVaporization', 'El evento porta el identificador del combo');
  assertTruthy(typeof reactionEvent?.detail?.colorA === 'string' && typeof reactionEvent?.detail?.colorB === 'string', 'El evento porta los DOS colores elementales (plan 4.2: estelas bicromáticas)');

  // Simetría estructural en toda la matriz espejo (findReaction).
  const vaporizationForward = resolver.findReaction('fire', 'water');
  const vaporizationReverse = resolver.findReaction('water', 'fire');
  assertTruthy(vaporizationForward === vaporizationReverse && vaporizationForward?.id === 'arcaneVaporization', 'findReaction es simétrico en el cliente (A+B = B+A)');
}

// ---------------------------------------------------------------------------
console.log('\n[FASE 6] Caso E — Sobreescritura no reactiva (RF-03.3)');
// ---------------------------------------------------------------------------

{
  const time = createFakeTime();
  const target = createRecordingTarget();
  const resolver = await forgeResolver({ time, target });

  const overwritten = resolver.resolveImpact({ activeAura: 'fire', incomingSpell: spellOf('light', 40), stunlockImmune: false });

  assertTruthy(overwritten.isReaction === false && overwritten.effectiveDamage === 40, 'Luz sobre Fuego aplica daño íntegro sin reacción');
  assertTruthy(overwritten.tacticalEffectApplied === 'auraOverwritten', 'El aura previa queda sobreescrita');
  assertTruthy(overwritten.resultingAura === 'light', 'El aura resultante es la del conjuro entrante');
  assertTruthy(target.ofType('combo:aura-applied').length === 1, 'La nueva aura se anuncia (combo:aura-applied)');
}

// ---------------------------------------------------------------------------
console.log('\n[FASE 7] Salvaguarda anti-stunlock integrada (RF-05.2, RF-05.3)');
// ---------------------------------------------------------------------------

{
  const time = createFakeTime();
  const stunlock = createFakeStunlockManager();
  stunlock.immune = true; // El blanco goza de inmunidad vigente.
  const target = createRecordingTarget();
  const resolver = await forgeResolver({ time, stunlock, target });

  const suppressed = resolver.resolveImpact({ activeAura: 'water', incomingSpell: spellOf('lightning', 40), stunlockImmune: true });
  assertTruthy(suppressed.effectiveDamage === 60, 'Con inmunidad, el +50% de daño se aplica íntegro');
  assertTruthy(suppressed.tacticalEffectApplied === 'hardStun', 'El efecto del Códice viaja íntegro en el veredicto');
  assertTruthy(suppressed.stunlockTriggered === true, 'El Hard CC queda suprimido (stunlockTriggered)');
  assertTruthy(suppressed.grantStunlockImmunity === false, 'No se re-concede inmunidad (no se apila)');

  // Sin inmunidad: la reacción de Hard CC la concede vía el gestor inyectado.
  const free = createFakeStunlockManager();
  const resolverFree = await forgeResolver({ time, stunlock: free, target });
  const granted = resolverFree.resolveImpact({ activeAura: 'water', incomingSpell: spellOf('lightning', 40), stunlockImmune: false });
  assertTruthy(granted.stunlockTriggered === false && granted.grantStunlockImmunity === true, 'Sin inmunidad, el veredicto concede la Inmunidad Rúnica');
  assertTruthy(free.lastGranted === 1, 'El gestor anti-stunlock inyectado recibió la concesión');
}

// ---------------------------------------------------------------------------
console.log('\n[FASE 8] Paridad con el backend y latencia <5 ms (criterio «Hecho cuando»)');
// ---------------------------------------------------------------------------

{
  const PARITY_CASES = [
    { activeAura: '', incomingSpell: spellOf('water', 40), stunlockImmune: false },
    { activeAura: 'water', incomingSpell: spellOf('water', 40), stunlockImmune: false },
    { activeAura: 'fire', incomingSpell: spellOf('pureArcane', 40), stunlockImmune: false },
    { activeAura: 'fire', incomingSpell: spellOf('water', 40), stunlockImmune: false },
    { activeAura: 'water', incomingSpell: spellOf('fire', 40), stunlockImmune: false },
    { activeAura: 'earth', incomingSpell: spellOf('lightning', 40), stunlockImmune: false },
    { activeAura: 'light', incomingSpell: spellOf('darkness', 40), stunlockImmune: false },
    { activeAura: 'fire', incomingSpell: spellOf('light', 40), stunlockImmune: false },
    { activeAura: 'water', incomingSpell: spellOf('lightning', 40), stunlockImmune: true },
    { activeAura: 'wind', incomingSpell: spellOf('water', 41), stunlockImmune: false },
    { activeAura: '', incomingSpell: spellOf('pureArcane', 30), stunlockImmune: false },
  ];

  const resolver = await forgeResolver();
  const latenciesMs = [];

  for (const [index, parityCase] of PARITY_CASES.entries()) {
    const startedAt = performance.now();
    const clientVerdict = resolver.resolveImpact(parityCase);
    const elapsedMs = performance.now() - startedAt;
    latenciesMs.push(elapsedMs);

    const serverVerdict = backendVerdict(parityCase);
    const clientJson = JSON.stringify(clientVerdict);
    const serverJson = JSON.stringify(serverVerdict);
    assertTruthy(clientJson === serverJson, `Caso ${index + 1}: veredicto cliente ≡ backend byte a byte (${parityCase.incomingSpell.element} sobre ${parityCase.activeAura || 'neutral'})`);
  }

  const maxLatencyMs = Math.max(...latenciesMs);
  const averageLatencyMs = latenciesMs.reduce((sum, value) => sum + value, 0) / latenciesMs.length;
  assertTruthy(maxLatencyMs < 5, `Toda resolución por debajo de 5 ms (máximo medido: ${maxLatencyMs.toFixed(3)} ms, media: ${averageLatencyMs.toFixed(3)} ms)`);
}

// ---------------------------------------------------------------------------
console.log('\n[FASE 9] Determinismo (RNF-01)');
// ---------------------------------------------------------------------------

{
  const resolverA = await forgeResolver();
  const resolverB = await forgeResolver();
  const verdictA = JSON.stringify(resolverA.resolveImpact({ activeAura: 'fire', incomingSpell: spellOf('water', 40), stunlockImmune: false }));
  const verdictB = JSON.stringify(resolverB.resolveImpact({ activeAura: 'fire', incomingSpell: spellOf('water', 40), stunlockImmune: false }));
  assertTruthy(verdictA === verdictB, 'Dos resoluciones idénticas producen veredictos JSON idénticos byte a byte');
}

// ---------------------------------------------------------------------------
console.log('\n== RESUMEN ==');
console.log(`Asertos superados: ${passed}, fallidos: ${failed}`);

if (failed > 0) {
  console.log('\nFALLOS:');
  for (const failure of failures) {
    console.log(`  - ${failure}`);
  }
  console.log('\nRESULTADO: FRACASO — El motor de combos en cliente aún no cumple el contrato de la Tarea 2.2.');
  process.exit(1);
}

console.log('\nRESULTADO: ÉXITO — La resolución en cliente es idéntica al backend y vuela por debajo de 5 ms (Tarea 2.2).');
process.exit(0);
