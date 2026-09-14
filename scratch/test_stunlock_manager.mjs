/**
 * test_stunlock_manager.mjs — Arnés TDD de la Tarea 2.1 (TASKS-06).
 *
 * Estrategia TDD: este script se escribe ANTES que la implementación.
 * Verifica, sobre `public/assets/js/utils/stunlockManager.js`:
 *   [1] Superficie: constantes canónicas (3000 ms, efectos Hard/Soft) y
 *       fábrica createStunlockManager con API completa.
 *   [2] Estado inicial: sin inmunidad alguna hasta que un Hard CC expire.
 *   [3] Concesión de inmunidad: isImmuneToHardCc() devuelve true durante
 *       3000 ms y emite combo:stunlock-immunity-started {durationMs: 3000}.
 *   [4] Expiración exacta: inmune en t+2999, NO inmune en t+3000; el evento
 *       combo:stunlock-immunity-ended se emite exactamente una vez, tanto
 *       por sondeo como por el planificador.
 *   [5] Discriminación de controles (RF-05.3): Hard CC (hardStun,
 *       freezeParalysis) se bloquea durante la inmunidad sustituido por
 *       onda de choque; Soft CC (blindnessMist, rootAndSlow) se aplica
 *       siempre; efecto desconocido atraviesa sin bloqueo.
 *   [6] No apilamiento: una segunda concesión durante la ventana no
 *       extiende la duración ni duplica el evento de inicio.
 *   [7] Restaurar Maniquí: reset() despeja la inmunidad y emite el cierre.
 *   [8] Determinismo (RNF-01): todo el ciclo es función pura del reloj
 *       inyectado, sin azar ni temporizadores reales.
 *
 * Criterio «Hecho cuando» (Tarea 2.1): tras expirar un Hard CC, invocar
 * isImmuneToHardCc() devuelve true durante 3000 ms, permitiendo la
 * aplicación de Soft CC y emitiendo los eventos correspondientes.
 *
 * Constitución:
 *   - Artículo I (Dogma Vanilla): módulo ES nativo, APIs nativas falsables
 *     (CustomEvent, setTimeout inyectables), cero dependencias.
 *   - Artículo V: identificadores en inglés camelCase; documentación en
 *     castellano.
 *
 * Uso: node scratch/test_stunlock_manager.mjs
 */

import { createRequire } from 'node:module';

const require = createRequire(import.meta.url);

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

/** Relojes y planificadores falsos: el arnés gobierna el tiempo. */
function createFakeTime({ startMs = 0 } = {}) {
  let currentMs = startMs;
  /** @type {Array<{fireAtMs: number, callback: Function, cleared: boolean}>} */
  const scheduled = [];

  return {
    now: () => currentMs,
    advance(deltaMs) {
      currentMs += deltaMs;
      // Dispara en orden los vencidos (comportamiento de cola de eventos).
      scheduled.sort((a, b) => a.fireAtMs - b.fireAtMs);
      for (const task of scheduled) {
        if (!task.cleared && task.fireAtMs <= currentMs) {
          task.cleared = true;
          task.callback();
        }
      }
    },
    schedule(callback, delayMs) {
      const task = { fireAtMs: currentMs + delayMs, callback, cleared: false };
      scheduled.push(task);
      return () => { task.cleared = true; };
    },
  };
}

/** Registro de eventos: target DOM falso que graba lo despachado. */
function createRecordingTarget() {
  /** @type {Array<{type: string, detail: unknown}>} */
  const events = [];
  return {
    events,
    dispatchEvent(event) {
      events.push({ type: event.type, detail: event.detail ?? null });
      return true;
    },
    ofType(type) {
      return events.filter((e) => e.type === type);
    },
  };
}

/** Conjura el gestor con el tiempo y el target falsos. */
async function forgeManager(fakeTime, target) {
  const moduleUrl = new URL('../public/assets/js/utils/stunlockManager.js', import.meta.url).href;
  const { createStunlockManager } = await import(moduleUrl);
  return createStunlockManager({ now: fakeTime.now, scheduleTimeout: fakeTime.schedule, eventTarget: target });
}

console.log('== ARNÉS TDD — GESTOR ANTI-STUNLOCK (Tarea 2.1, SPEC-06) ==');

// ---------------------------------------------------------------------------
console.log('\n[FASE 1] Superficie del módulo');
// ---------------------------------------------------------------------------

let manager = null;
let constants = null;
try {
  const moduleUrl = new URL('../public/assets/js/utils/stunlockManager.js', import.meta.url).href;
  const module = await import(moduleUrl);
  constants = module;
  manager = await forgeManager(createFakeTime(), createRecordingTarget());
  assertTruthy(true, 'El módulo stunlockManager.js existe y exporta su fábrica (fase roja si falta)');
} catch (error) {
  assertTruthy(false, `El módulo stunlockManager.js existe y exporta su fábrica (${error.message})`);
}

if (manager !== null) {
  assertTruthy(typeof constants.STUNLOCK_IMMUNITY_DURATION_MS === 'number', 'La duración canónica de la inmunidad viaja como constante exportada');
  assertTruthy(constants.STUNLOCK_IMMUNITY_DURATION_MS === 3000, 'La duración canónica es exactamente 3000 ms');
  assertTruthy(Array.isArray(constants.HARD_CROWD_CONTROL_EFFECTS)
    && constants.HARD_CROWD_CONTROL_EFFECTS.includes('hardStun')
    && constants.HARD_CROWD_CONTROL_EFFECTS.includes('freezeParalysis'), 'Los efectos de Hard CC son hardStun y freezeParalysis');
  assertTruthy(Array.isArray(constants.SOFT_CROWD_CONTROL_EFFECTS)
    && constants.SOFT_CROWD_CONTROL_EFFECTS.includes('blindnessMist')
    && constants.SOFT_CROWD_CONTROL_EFFECTS.includes('rootAndSlow'), 'Los efectos de Soft CC son blindnessMist y rootAndSlow');

  for (const methodName of ['grantImmunity', 'isImmuneToHardCc', 'evaluateCrowdControl', 'getRemainingImmunityMs', 'reset']) {
    assertTruthy(typeof manager[methodName] === 'function', `La API expone ${methodName}()`);
  }
}

// ---------------------------------------------------------------------------
console.log('\n[FASE 2] Estado inicial: neutral sin inmunidad');
// ---------------------------------------------------------------------------

const freshTime = createFakeTime();
const freshTarget = createRecordingTarget();
const freshManager = await forgeManager(freshTime, freshTarget);
assertTruthy(freshManager.isImmuneToHardCc() === false, 'Un blanco recién restaurado no goza de inmunidad');
assertTruthy(freshManager.getRemainingImmunityMs() === 0, 'El resto de inmunidad es 0 sin ventana activa');
assertTruthy(freshTarget.events.length === 0, 'Sin concesión no se emite evento alguno');

// ---------------------------------------------------------------------------
console.log('\n[FASE 3] Concesión de la inmunidad rúnica (RF-05.2)');
// ---------------------------------------------------------------------------

const immunityTime = createFakeTime();
const immunityTarget = createRecordingTarget();
const immunityManager = await forgeManager(immunityTime, immunityTarget);
immunityManager.grantImmunity();

assertTruthy(immunityManager.isImmuneToHardCc() === true, 'Tras expirar un Hard CC, el blanco goza de inmunidad');
assertTruthy(immunityTarget.ofType('combo:stunlock-immunity-started').length === 1, 'Se emite combo:stunlock-immunity-started');
assertTruthy(immunityTarget.ofType('combo:stunlock-immunity-started')[0]?.detail?.durationMs === 3000, 'El evento de inicio porta durationMs: 3000');

immunityTime.advance(1500);
assertTruthy(immunityManager.isImmuneToHardCc() === true, 'A mitad de ventana (t+1500) la inmunidad persiste');
immunityTime.advance(1499);
assertTruthy(immunityManager.isImmuneToHardCc() === true, 'En t+2999 la inmunidad aún vive');

// ---------------------------------------------------------------------------
console.log('\n[FASE 4] Expiración exacta y evento de cierre');
// ---------------------------------------------------------------------------

immunityTime.advance(1);
assertTruthy(immunityManager.isImmuneToHardCc() === false, 'En t+3000 la inmunidad ha expirado');
assertTruthy(immunityManager.getRemainingImmunityMs() === 0, 'El resto de inmunidad cae a 0 al expirar');
assertTruthy(immunityTarget.ofType('combo:stunlock-immunity-ended').length === 1, 'El evento combo:stunlock-immunity-ended se emite exactamente una vez');

const remainingBefore = immunityTarget.ofType('combo:stunlock-immunity-ended').length;
immunityTime.advance(5000);
immunityManager.isImmuneToHardCc();
assertTruthy(immunityTarget.ofType('combo:stunlock-immunity-ended').length === remainingBefore, 'Sondeos posteriores no duplican el evento de cierre');

// ---------------------------------------------------------------------------
console.log('\n[FASE 5] Discriminación Hard/Soft CC (RF-05.3)');
// ---------------------------------------------------------------------------

const gateTime = createFakeTime();
const gateTarget = createRecordingTarget();
const gateManager = await forgeManager(gateTime, gateTarget);

// Fuera de inmunidad: el Hard CC se aplica (luego, al expirar, concede inmunidad).
const hardOutside = gateManager.evaluateCrowdControl('hardStun');
assertTruthy(hardOutside.allowed === true && hardOutside.replacedByShockwave === false, 'Fuera de inmunidad, el Hard CC se aplica sin onda de choque');

gateManager.grantImmunity();

const hardStunGated = gateManager.evaluateCrowdControl('hardStun');
assertTruthy(hardStunGated.allowed === false, 'Con inmunidad, el aturdimiento (hardStun) queda bloqueado');
assertTruthy(hardStunGated.replacedByShockwave === true, 'El bloqueo sustituye la parálisis por onda de choque (RF-05.3)');

const freezeGated = gateManager.evaluateCrowdControl('freezeParalysis');
assertTruthy(freezeGated.allowed === false && freezeGated.replacedByShockwave === true, 'La congelación (freezeParalysis) sufre la misma salvaguarda');

const softMist = gateManager.evaluateCrowdControl('blindnessMist');
assertTruthy(softMist.allowed === true && softMist.replacedByShockwave === false, 'El Soft CC (niebla) se aplica con normalidad bajo inmunidad');

const softRoot = gateManager.evaluateCrowdControl('rootAndSlow');
assertTruthy(softRoot.allowed === true, 'El Soft CC (enraizamiento) también se aplica');

const unknownEffect = gateManager.evaluateCrowdControl('barrierPiercing');
assertTruthy(unknownEffect.allowed === true, 'Un efecto ajeno a los controles de masas atraviesa sin bloqueo');

// Al expirar la ventana, el Hard CC vuelve a aplicarse.
gateTime.advance(3000);
gateManager.isImmuneToHardCc();
const hardAfterExpiry = gateManager.evaluateCrowdControl('hardStun');
assertTruthy(hardAfterExpiry.allowed === true, 'Expirada la inmunidad, el Hard CC vuelve a aplicarse');

// ---------------------------------------------------------------------------
console.log('\n[FASE 6] No apilamiento de la inmunidad');
// ---------------------------------------------------------------------------

const stackTime = createFakeTime();
const stackTarget = createRecordingTarget();
const stackManager = await forgeManager(stackTime, stackTarget);

stackManager.grantImmunity();
stackTime.advance(2000);
stackManager.grantImmunity();
assertTruthy(stackTarget.ofType('combo:stunlock-immunity-started').length === 1, 'Una segunda concesión durante la ventana no emite otro inicio');
stackTime.advance(1001);
assertTruthy(stackManager.isImmuneToHardCc() === false, 'La ventana original expira en su plazo: no se extiende');

// ---------------------------------------------------------------------------
console.log('\n[FASE 7] Restaurar Maniquí (reset)');
// ---------------------------------------------------------------------------

const resetTime = createFakeTime();
const resetTarget = createRecordingTarget();
const resetManager = await forgeManager(resetTime, resetTarget);

resetManager.grantImmunity();
resetManager.reset();
assertTruthy(resetManager.isImmuneToHardCc() === false, 'reset() despeja la inmunidad de inmediato');
assertTruthy(resetTarget.ofType('combo:stunlock-immunity-ended').length === 1, 'El despeje emite el cierre de la inmunidad');
resetTime.advance(5000);
assertTruthy(resetTarget.ofType('combo:stunlock-immunity-ended').length === 1, 'Tras el despeje no llega ningún cierre fantasma');

// ---------------------------------------------------------------------------
console.log('\n[FASE 8] Determinismo (RNF-01)');
// ---------------------------------------------------------------------------

const deterministicA = await forgeManager(createFakeTime(), createRecordingTarget());
const deterministicB = await forgeManager(createFakeTime(), createRecordingTarget());
deterministicA.grantImmunity();
deterministicB.grantImmunity();
assertTruthy(deterministicA.getRemainingImmunityMs() === deterministicB.getRemainingImmunityMs(), 'Dos blancos con idéntico historial miden idéntico resto de inmunidad');

// ---------------------------------------------------------------------------
console.log('\n== RESUMEN ==');
console.log(`Asertos superados: ${passed}, fallidos: ${failed}`);

if (failed > 0) {
  console.log('\nFALLOS:');
  for (const failure of failures) {
    console.log(`  - ${failure}`);
  }
  console.log('\nRESULTADO: FRACASO — El gestor anti-stunlock aún no cumple el contrato de la Tarea 2.1.');
  process.exit(1);
}

console.log('\nRESULTADO: ÉXITO — El ciclo de inmunidad de 3 s discrimina Hard/Soft CC y emite sus eventos (Tarea 2.1).');
process.exit(0);
