/**
 * test_combo_queue.mjs — Arnés TDD de la Tarea 2.3 (TASKS-06).
 *
 * Estrategia TDD: este script se escribe ANTES que la implementación.
 * Verifica, sobre `public/assets/js/utils/comboResolver.js`, la clase/
 * fábrica SpellImpactQueue del plan 3.3:
 *   [1] Superficie: exportación de SpellImpactQueue y API (enqueue,
 *       pendingCount, isProcessing, clear).
 *   [2] Primer impacto inmediato: enqueue con la cola vacía resuelve el
 *       primer impacto SIN esperar al siguiente cuadro (pseudocódigo del
 *       plan: el primero aplica el aura al instante).
 *   [3] FIFO estricto: tres impactos en ráfaga (<100 ms) se resuelven uno
 *       por cuadro, en estricto orden de llegada, encadenados mediante el
 *       planificador de cuadros inyectado.
 *   [4] Ráfaga de 20 ms (criterio «Hecho cuando»): el primero aplica el
 *       aura y el segundo detona el combo — sin pérdidas ni colisiones,
 *       contra un estado compartido del blanco.
 *   [5] Sin reentrada: encolar DURANTE el procesamiento no dispara
 *       resoluciones duplicadas; todo pasa por la cola.
 *   [6] Drenaje: agotada la cola, isProcessing vuelve a false y una nueva
 *       ráfaga vuelve a arrancar el ciclo limpio.
 *   [7] clear(): despeja pendientes sin resolverlos (Restaurar Maniquí).
 *   [8] Orden de llegada sobre sellos temporales: los impactos se procesan
 *       por orden de ENCOLADO, jamás reordenados por su timestamp.
 *   [9] Determinismo (RNF-01): misma ráfaga → misma crónica de resolución.
 *
 * Criterio «Hecho cuando» (Tarea 2.3): al recibir dos impactos con 20 ms
 * de diferencia, ambos se resuelven secuencialmente en orden sin perderse
 * ni colisionar.
 *
 * Constitución:
 *   - Artículo I (Dogma Vanilla): módulo ES nativo; el planificador de
 *     cuadros (requestAnimationFrame) llega inyectado y el arnés gobierna
 *     los cuadros. Cero dependencias.
 *   - Artículo V: identificadores en inglés camelCase; documentación en
 *     castellano.
 *
 * Uso: node scratch/test_combo_queue.mjs
 */

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

/**
 * Planificador de cuadros falso: recoge las devoluciones de llamada y el
 * arnés bombea los cuadros manualmente (el tiempo vive bajo su mando).
 */
function createFakeFrameScheduler() {
  /** @type {Function[]} */
  let pendingCallbacks = [];
  let frameCount = 0;
  return {
    get frameCount() { return frameCount; },
    scheduleFrame(callback) {
      pendingCallbacks.push(callback);
      return () => { pendingCallbacks = pendingCallbacks.filter((cb) => cb !== callback); };
    },
    /** Ejecuta una ronda de cuadro: todos los callbacks hoy pendientes. */
    pump() {
      frameCount++;
      const callbacks = pendingCallbacks;
      pendingCallbacks = [];
      for (const callback of callbacks) {
        callback();
      }
    },
    get pendingRounds() { return pendingCallbacks.length; },
  };
}

/** Impacto de prueba con sello temporal. */
function impactOf(element, timestampMs) {
  return {
    activeAura: '',
    incomingSpell: { id: `spl_${element}_${timestampMs}`, element, baseDamage: 40, baseHealing: 0, baseBarrier: 0, crowdControlType: 'none' },
    stunlockImmune: false,
    timestamp: timestampMs,
  };
}

const MODULE_URL = new URL('../public/assets/js/utils/comboResolver.js', import.meta.url).href;

console.log('== ARNÉS TDD — COLA FIFO DE IMPACTOS (Tarea 2.3, SPEC-06) ==');

// ---------------------------------------------------------------------------
console.log('\n[FASE 1] Superficie de la exportación');
// ---------------------------------------------------------------------------

let SpellImpactQueueFactory = null;
try {
  const module = await import(MODULE_URL);
  SpellImpactQueueFactory = module.createSpellImpactQueue;
  assertTruthy(typeof SpellImpactQueueFactory === 'function', 'comboResolver.js exporta createSpellImpactQueue (fase roja si falta)');
} catch (error) {
  assertTruthy(false, `comboResolver.js exporta createSpellImpactQueue (${error.message})`);
}

let queue = null;
const crónica = [];

if (SpellImpactQueueFactory !== null) {
  const scheduler = createFakeFrameScheduler();
  // Resolutor grabador: cada impacto resuelto queda en la crónica.
  queue = SpellImpactQueueFactory({
    resolveImpact: (impact) => { crónica.push(impact); return { resolved: impact }; },
    scheduleFrame: scheduler.scheduleFrame,
  });

  for (const methodName of ['enqueue', 'pendingCount', 'isProcessing', 'clear']) {
    assertTruthy(typeof queue[methodName] === 'function', `La API expone ${methodName}()`);
  }
}

// ---------------------------------------------------------------------------
console.log('\n[FASE 2] Primer impacto inmediato (plan 3.3)');
// ---------------------------------------------------------------------------

{
  const scheduler = createFakeFrameScheduler();
  const seen = [];
  const immediateQueue = SpellImpactQueueFactory({
    resolveImpact: (impact) => { seen.push(impact); },
    scheduleFrame: scheduler.scheduleFrame,
  });

  assertTruthy(immediateQueue.isProcessing() === false, 'Con la cola vacía, no hay procesamiento activo');
  immediateQueue.enqueue(impactOf('fire', 1000));
  assertTruthy(seen.length === 1, 'El primer impacto sobre cola vacía se resuelve AL INSTANTE, sin esperar cuadro');
  assertTruthy(immediateQueue.pendingCount() === 0, 'El impacto resuelto sale de la cola');

  scheduler.pump();
  assertTruthy(seen.length === 1, 'El drenaje posterior no duplica resoluciones');
}

// ---------------------------------------------------------------------------
console.log('\n[FASE 3] FIFO estricto, un impacto por cuadro');
// ---------------------------------------------------------------------------

{
  const scheduler = createFakeFrameScheduler();
  const seen = [];
  const burstQueue = SpellImpactQueueFactory({
    resolveImpact: (impact) => { seen.push(impact.incomingSpell.element); },
    scheduleFrame: scheduler.scheduleFrame,
  });

  burstQueue.enqueue(impactOf('water', 2000));
  burstQueue.enqueue(impactOf('lightning', 2020));
  burstQueue.enqueue(impactOf('earth', 2040));

  assertTruthy(seen.join(',') === 'water', 'Solo el primero se resuelve al encolar la ráfaga');
  assertTruthy(burstQueue.pendingCount() === 2, 'Los otros dos esperan su cuadro');
  assertTruthy(burstQueue.isProcessing() === true, 'La cola se declara en procesamiento durante la ráfaga');

  scheduler.pump();
  assertTruthy(seen.join(',') === 'water,lightning', 'El segundo cuadro resuelve el segundo impacto');
  scheduler.pump();
  assertTruthy(seen.join(',') === 'water,lightning,earth', 'El tercer cuadro resuelve el tercero, en orden exacto');
  scheduler.pump(); // Cuadro de cierre del plan 3.3: la cola vacía apaga el ciclo.
  assertTruthy(burstQueue.isProcessing() === false, 'Drenada la ráfaga, el procesamiento termina en el cuadro de cierre');
  assertTruthy(scheduler.pendingRounds === 0, 'No queda ningún cuadro programado huérfano');
}

// ---------------------------------------------------------------------------
console.log('\n[FASE 4] Ráfaga de 20 ms: aura y detonación sin colisión (criterio «Hecho cuando»)');
// ---------------------------------------------------------------------------

{
  const scheduler = createFakeFrameScheduler();
  // Estado compartido del blanco: la aura vigente evoluciona con cada impacto.
  const targetState = { activeAura: '' };
  const impacts = [];
  const burstQueue = SpellImpactQueueFactory({
    resolveImpact: (impact) => {
      // El resolutor es atómico: lee y escribe el estado compartido en un
      // solo tramo, sin que otro impacto pise el estado a medias.
      const previousAura = targetState.activeAura;
      const incoming = impact.incomingSpell.element;
      const detonated = previousAura !== '' && previousAura !== incoming
        && !(['fire', 'water', 'lightning', 'earth', 'wind', 'light', 'darkness', 'pureArcane'].length === 0);
      targetState.activeAura = detonated ? '' : incoming;
      impacts.push({ previousAura, incoming, detonated, timestamp: impact.timestamp });
      return { detonated };
    },
    scheduleFrame: scheduler.scheduleFrame,
  });

  burstQueue.enqueue(impactOf('fire', 5000));      // t=5000: imbuye aura de Fuego
  burstQueue.enqueue(impactOf('water', 5020));     // t=5020 (Δ20 ms): detona Vaporización

  scheduler.pump(); // El cuadro siguiente resuelve el segundo impacto.

  assertTruthy(impacts.length === 2, 'Ambos impactos se resolvieron: ninguno se perdió');
  assertTruthy(impacts[0].previousAura === '' && impacts[0].incoming === 'fire', 'El primero encontró blanco neutral y aplicó el aura de Fuego');
  assertTruthy(impacts[1].previousAura === 'fire', 'El segundo (Δ20 ms) leyó el aura YA aplicada por el primero: sin colisión');
  assertTruthy(impacts[1].incoming === 'water', 'El segundo impacto fue el detonante de la reacción');
  assertTruthy(impacts.map((entry) => entry.timestamp).join(',') === '5000,5020', 'El orden de resolución respeta los sellos temporales (5000 → 5020)');
}

// ---------------------------------------------------------------------------
console.log('\n[FASE 5] Sin reentrada durante el procesamiento');
// ---------------------------------------------------------------------------

{
  const scheduler = createFakeFrameScheduler();
  const seen = [];
  let resolutionCount = 0;
  const reentrantQueue = SpellImpactQueueFactory({
    resolveImpact: (impact) => {
      resolutionCount++;
      seen.push(impact.incomingSpell.element);
      // El resolutor intenta colar un impacto por la puerta de atrás: la
      // cola debe procesarlo como un pendiente MÁS, jamás de inmediato.
      if (resolutionCount === 1) {
        reentrantQueue.enqueue(impactOf('darkness', 9999));
      }
    },
    scheduleFrame: scheduler.scheduleFrame,
  });

  reentrantQueue.enqueue(impactOf('fire', 7000));
  assertTruthy(seen.join(',') === 'fire', 'El primer impacto se resuelve de inmediato');
  assertTruthy(reentrantQueue.pendingCount() === 1, 'El impacto inyectado durante el procesamiento queda EN LA COLA');
  assertTruthy(reentrantQueue.isProcessing() === true, 'La cola sigue en procesamiento (pendientes vivos)');

  scheduler.pump();
  assertTruthy(seen.join(',') === 'fire,darkness', 'El impacto inyectado se resuelve en su propio cuadro, secuencial');
  assertTruthy(scheduler.frameCount === 1, 'Una sola ronda de cuadro procesa lo pendiente: sin bucles de reentrada');
}

// ---------------------------------------------------------------------------
console.log('\n[FASE 6] Drenaje y nueva ráfaga');
// ---------------------------------------------------------------------------

{
  const scheduler = createFakeFrameScheduler();
  const seen = [];
  const drainQueue = SpellImpactQueueFactory({
    resolveImpact: (impact) => { seen.push(impact.incomingSpell.element); },
    scheduleFrame: scheduler.scheduleFrame,
  });

  drainQueue.enqueue(impactOf('light', 8000));
  drainQueue.enqueue(impactOf('darkness', 8010));
  scheduler.pump(); // Resuelve el segundo impacto.
  scheduler.pump(); // Cuadro de cierre del plan 3.3.
  assertTruthy(drainQueue.isProcessing() === false && drainQueue.pendingCount() === 0, 'Tras el drenaje completo y su cuadro de cierre, la cola queda inactiva y vacía');

  drainQueue.enqueue(impactOf('earth', 9000));
  assertTruthy(seen.join(',') === 'light,darkness,earth', 'Una nueva ráfaga tras el drenaje arranca el ciclo limpio');
}

// ---------------------------------------------------------------------------
console.log('\n[FASE 7] clear(): despeje sin resolver (Restaurar Maniquí)');
// ---------------------------------------------------------------------------

{
  const scheduler = createFakeFrameScheduler();
  const seen = [];
  const clearableQueue = SpellImpactQueueFactory({
    resolveImpact: (impact) => { seen.push(impact.incomingSpell.element); },
    scheduleFrame: scheduler.scheduleFrame,
  });

  clearableQueue.enqueue(impactOf('fire', 11000));
  clearableQueue.enqueue(impactOf('water', 11010));
  clearableQueue.clear();
  assertTruthy(clearableQueue.pendingCount() === 0, 'clear() despeja los pendientes');
  assertTruthy(clearableQueue.isProcessing() === false, 'clear() apaga el procesamiento');
  scheduler.pump();
  assertTruthy(seen.join(',') === 'fire', 'Los impactos despejados jamás se resuelven (ni siquiera en el cuadro siguiente)');
}

// ---------------------------------------------------------------------------
console.log('\n[FASE 8] Orden de llegada sobre los sellos temporales');
// ---------------------------------------------------------------------------

{
  const scheduler = createFakeFrameScheduler();
  const seen = [];
  const orderQueue = SpellImpactQueueFactory({
    resolveImpact: (impact) => { seen.push(impact.timestamp); },
    scheduleFrame: scheduler.scheduleFrame,
  });

  // Llegada deliberadamente desordenada respecto a los sellos: la cola es
  // FIFO por ENCOLADO (plan 3.3), jamás reordena por timestamp.
  orderQueue.enqueue(impactOf('water', 3000));
  orderQueue.enqueue(impactOf('lightning', 1000));
  orderQueue.enqueue(impactOf('earth', 5000));
  scheduler.pump();
  scheduler.pump();

  assertTruthy(seen.join(',') === '3000,1000,5000', 'La crónica respeta el orden de llegada, no los sellos (determinismo FIFO)');
}

// ---------------------------------------------------------------------------
console.log('\n[FASE 9] Determinismo (RNF-01)');
// ---------------------------------------------------------------------------

{
  const runBurst = () => {
    const scheduler = createFakeFrameScheduler();
    const seen = [];
    const deterministicQueue = SpellImpactQueueFactory({
      resolveImpact: (impact) => { seen.push(`${impact.incomingSpell.element}@${impact.timestamp}`); },
      scheduleFrame: scheduler.scheduleFrame,
    });
    deterministicQueue.enqueue(impactOf('fire', 100));
    deterministicQueue.enqueue(impactOf('water', 120));
    scheduler.pump();
    return seen.join('|');
  };

  assertTruthy(runBurst() === runBurst(), 'Dos ráfagas idénticas producen crónicas idénticas');
}

// ---------------------------------------------------------------------------
console.log('\n== RESUMEN ==');
console.log(`Asertos superados: ${passed}, fallidos: ${failed}`);

if (failed > 0) {
  console.log('\nFALLOS:');
  for (const failure of failures) {
    console.log(`  - ${failure}`);
  }
  console.log('\nRESULTADO: FRACASO — La cola FIFO aún no cumple el contrato de la Tarea 2.3.');
  process.exit(1);
}

console.log('\nRESULTADO: ÉXITO — La ráfaga de 20 ms se resuelve en orden, sin pérdidas ni colisiones (Tarea 2.3).');
process.exit(0);
