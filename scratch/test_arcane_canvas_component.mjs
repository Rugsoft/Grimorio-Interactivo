/**
 * test_arcane_canvas_component.mjs — Arnés TDD de la Tarea 3.3 (TASKS-05).
 *
 * Estrategia TDD: este script se escribe ANTES que la implementación.
 * Verifica, sobre `public/assets/js/components/arcaneCanvasComponent.js`:
 *   [1] Fábrica y ciclo del bucle: start() agenda RAF, cada cuadro integra
 *       el motor de partículas de producción, stop() cancela el vuelo.
 *   [2] Suspensión por visibilidad (RF-06.3): al ocultar la pestaña el
 *       bucle se cancela AL INSTANTE y se silencia la síntesis vocal;
 *       al recuperar visibilidad, se reanuda.
 *   [3] prefers-reduced-motion (RF-06.1): sin proyectiles móviles —
 *       destello estático rúnico de 400 ms e impacto inmediato con el
 *       texto flotante directo.
 *   [4] Monitor de FPS con auto-throttle (RF-06.2): dos muestras
 *       consecutivas < 30 FPS activan el modo de bajo consumo
 *       (densidad 1.0 → 0.4) para futuras invocaciones.
 *   [5] Invocación y impacto (RF-03/RF-05.1): castSpell emite partículas
 *       del pool real y despacha el impacto al maniquí al alcanzar el
 *       blanco, con las coordenadas del torso.
 *
 * Constitución:
 *   - Artículo I: Canvas 2D y RAF nativos, todo inyectable y falsable.
 *   - Artículo V: identificadores en inglés camelCase, leyendas castellanas.
 *
 * Uso: node scratch/test_arcane_canvas_component.mjs
 */

let assertsPassed = 0;
let assertsFailed = 0;

function assertCondition(condition, description) {
  if (condition) {
    assertsPassed++;
    console.log(`  [PASA] ${description}`);
  } else {
    assertsFailed++;
    console.log(`  [FALLA] ${description}`);
  }
}

/** Reloj inyectable determinista. */
function createFakeClock(start = 1_000_000) {
  let now = start;
  return {
    get now() { return now; },
    advance(ms) { now += ms; },
  };
}

/** Contexto 2D falso que cuenta trazados. */
function createFakeCtx() {
  const calls = [];
  const record = (name) => (...args) => calls.push({ name, args });
  return {
    calls,
    save: record('save'),
    restore: record('restore'),
    beginPath: record('beginPath'),
    arc: record('arc'),
    fill: record('fill'),
    stroke: record('stroke'),
    fillRect: record('fillRect'),
    set fillStyle(value) { calls.push({ name: 'fillStyle', args: [value] }); },
    set globalAlpha(value) { calls.push({ name: 'globalAlpha', args: [value] }); },
    font: '',
    textAlign: '',
    fillText: record('fillText'),
  };
}

/** Documento falso con addEventListener (visibilidad). */
function createFakeDocument() {
  const listeners = {};
  let hidden = false;
  return {
    listeners,
    get hidden() { return hidden; },
    set hidden(value) { hidden = value; },
    addEventListener(name, listener) { (listeners[name] ??= []).push(listener); },
    dispatch(name, event = {}) { (listeners[name] ?? []).forEach((l) => l(event)); },
  };
}

/** RAF falso: registra el vuelo agendado; el arnés dispara los cuadros. */
function createFakeRaf() {
  const api = {
    scheduledId: 0,
    callback: null,
    cancelledIds: [],
  };
  api.raf = (callback) => {
    api.callback = callback;
    api.scheduledId += 1;
    return api.scheduledId;
  };
  api.caf = (id) => { api.cancelledIds.push(id); };
  /** Dispara un cuadro con la marca temporal dada. */
  api.tick = (timestamp) => {
    const cb = api.callback;
    if (!cb) return false;
    api.callback = null; // cada cuadro debe re-agendarse
    cb(timestamp);
    return true;
  };
  return api;
}

/** Síntesis falsa para comprobar la pausa por visibilidad. */
function createFakeSynth() {
  return {
    paused: false,
    pauseCount: 0,
    resumeCount: 0,
    pause() { this.paused = true; this.pauseCount++; },
    resume() { this.paused = false; this.resumeCount++; },
  };
}

console.log('== ARNÉS TDD — Tarea 3.3: Contenedor del Lienzo Arcano ==');

let module;
try {
  module = await import('../public/assets/js/components/arcaneCanvasComponent.js');
} catch (error) {
  console.log(`  FATAL — no se pudo importar arcaneCanvasComponent.js: ${error.message}`);
  console.log('== RESUMEN == Fase roja: la implementación aún no existe.');
  process.exit(1);
}

const { createArcaneCanvasComponent } = module;

/** Escenario completo falsificado. */
function buildScene({ reducedMotion = false } = {}) {
  const clock = createFakeClock();
  const ctx = createFakeCtx();
  const doc = createFakeDocument();
  const raf = createFakeRaf();
  const synth = createFakeSynth();
  const motionQuery = { matches: reducedMotion };
  const impacts = [];
  const component = createArcaneCanvasComponent({
    canvas: { width: 800, height: 500 },
    ctx,
    document: doc,
    raf: raf.raf,
    caf: raf.caf,
    clock,
    motionQuery,
    speechSynthesis: synth,
    onImpact: (detail) => impacts.push(detail),
  });
  return { component, clock, ctx, doc, raf, synth, impacts, motionQuery };
}

const SPELL = {
  name: 'Llamas del Alba',
  elementalAffinity: 'fire',
  circle: 3,
  effects: { damage: 45, healing: 0, barrier: 0, crowdControlType: 'none' },
};
const ORIGIN = { x: 100, y: 400 };
const TARGET = { x: 560, y: 250 };

console.log('[1] Fábrica y ciclo del bucle RAF');

{
  const scene = buildScene();
  const { component, raf, clock } = scene;

  assertCondition(typeof createArcaneCanvasComponent === 'function', 'exporta createArcaneCanvasComponent(options)');
  assertCondition(typeof component.start === 'function' && typeof component.stop === 'function', 'expone start()/stop()');
  assertCondition(typeof component.castSpell === 'function', 'expone castSpell(spell, options)');
  assertCondition(typeof component.isRunning === 'function', 'expone isRunning()');
  assertCondition(typeof component.getDensityScale === 'function', 'expone getDensityScale()');

  assertCondition(component.isRunning() === false, 'el bucle nace detenido');
  component.start();
  assertCondition(component.isRunning() === true, 'start() pone el bucle en marcha');
  assertCondition(raf.scheduledId === 1, 'el primer vuelo RAF queda agendado');

  // Un cuadro avanza y se re-agenda (bucle continuo).
  const ts1 = clock.now;
  assertCondition(raf.tick(ts1) === true, 'el cuadro agendado se dispara');
  assertCondition(raf.scheduledId === 2, 'cada cuadro re-agenda el siguiente (bucle continuo)');

  component.stop();
  assertCondition(component.isRunning() === false, 'stop() detiene el bucle');
  assertCondition(raf.cancelledIds.length === 1, 'stop() cancela el vuelo pendiente');
}

console.log('[2] Suspensión por visibilidad (RF-06.3)');

{
  const scene = buildScene();
  const { component, doc, raf, synth } = scene;
  component.start();

  // La pestaña se oculta: suspensión inmediata.
  doc.hidden = true;
  doc.dispatch('visibilitychange');
  assertCondition(component.isRunning() === false, 'al ocultar la pestaña el bucle se suspende AL INSTANTE');
  assertCondition(raf.cancelledIds.length === 1, 'el vuelo pendiente se cancela');
  assertCondition(synth.pauseCount === 1, 'la locución vocal se pausa (RF-06.3)');

  // La pestaña vuelve: reanudación.
  doc.hidden = false;
  doc.dispatch('visibilitychange');
  assertCondition(component.isRunning() === true, 'al recuperar visibilidad el bucle se reanuda');
  assertCondition(synth.resumeCount === 1, 'la síntesis se reanuda');
}

console.log('[3] prefers-reduced-motion — destello estático e impacto inmediato (RF-06.1)');

{
  const scene = buildScene({ reducedMotion: true });
  const { component, clock, impacts, ctx, raf } = scene;
  component.start();

  const emitted = component.castSpell(SPELL, { geometry: 'singleTarget', origin: ORIGIN, target: TARGET });
  assertCondition(emitted === 0, 'sin trayectorias violentas: cero partículas móviles emitidas');

  // Impacto inmediato (sin vuelo): el texto flotante brota de inmediato.
  clock.advance(16);
  raf.tick(clock.now);
  assertCondition(impacts.length === 1, 'el impacto se despacha de inmediato (texto flotante directo)');
  assertCondition(impacts[0].spell?.name === SPELL.name, 'el impacto porta el conjuro');
  assertCondition(Math.abs(impacts[0].targetCoordinates.x - TARGET.x) < 1e-6, 'con las coordenadas del blanco');

  // Destello estático rúnico durante 400 ms: círculo dibujado sin vuelo.
  const arcsDuringFlash = ctx.calls.filter((c) => c.name === 'arc').length;
  assertCondition(arcsDuringFlash > 0, 'pulsación lumínica estática dibujada (círculo rúnico)');
}

console.log('[4] Monitor de FPS con auto-throttle (RF-06.2)');

{
  const scene = buildScene();
  const { component, clock, raf } = scene;
  component.start();
  assertCondition(component.getDensityScale() === 1, 'densidad canónica inicial 1.0');

  // Muestra 1: ventana exacta de 1 s con 1 cuadro → 1 FPS (< 30).
  // Cada tick cierra exactamente UNA ventana (windowStart = instante).
  clock.advance(1000);
  raf.tick(clock.now);
  assertCondition(component.getDensityScale() === 1, 'una sola muestra lenta NO activa el throttle aún');

  // Muestra 2: segunda ventana lenta consecutiva de 1 s.
  clock.advance(1000);
  raf.tick(clock.now);
  assertCondition(component.getDensityScale() === 0.4, 'dos muestras consecutivas < 30 FPS activan el modo de bajo consumo (0.4)');

  // El throttle reduce las partículas de FUTURAS invocaciones. Se usa un
  // conjuro SIN círculo (escala neutra 1.0) para aislar el efecto.
  const emitted = component.castSpell({ name: 'Prisma', effects: SPELL.effects }, { geometry: 'singleTarget', origin: ORIGIN, target: TARGET, count: 20 });
  assertCondition(emitted === Math.round(20 * 0.4), `las invocaciones futuras emiten al 40% (20 × 0.4 = ${emitted})`);
}

console.log('[5] Invocación, vuelo e impacto sobre el maniquí (RF-03/RF-05.1)');

{
  const scene = buildScene();
  const { component, clock, raf, impacts } = scene;
  component.start();

  // Conjuro sin círculo explícito (escala de Círculo neutra 1.0) para
  // aislar la verificación del recuento de emisión.
  const emitted = component.castSpell({ name: 'Chispa', effects: SPELL.effects }, { geometry: 'singleTarget', origin: ORIGIN, target: TARGET, count: 12 });
  assertCondition(emitted === 12, 'la invocación emite las partículas pedidas (12)');
  assertCondition(component.getActiveParticleCount() === 12, 'las partículas están activas en el pool del lienzo');

  // Sin avanzar el tiempo de vuelo: aún no hay impacto.
  clock.advance(100);
  raf.tick(clock.now);
  assertCondition(impacts.length === 0, 'el impacto NO se despacha antes de alcanzar el blanco');

  // Avanza el vuelo completo: el impacto llega con las coordenadas.
  clock.advance(2000);
  raf.tick(clock.now);
  assertCondition(impacts.length === 1, 'el impacto se despacha al alcanzar el blanco (RF-05.1)');
  assertCondition(impacts[0].spell?.name === 'Chispa' && impacts[0].spell?.effects?.damage === 45, 'con el conjuro y sus efectos');
  assertCondition(impacts[0].targetCoordinates?.x === TARGET.x && impacts[0].targetCoordinates?.y === TARGET.y, 'con las coordenadas exactas del torso');
  assertCondition(impacts.length === 1, 'el impacto se despacha una única vez (sin duplicados)');
}

console.log('== RESUMEN ==');
console.log(`Asertos superados: ${assertsPassed}, fallidos: ${assertsFailed}`);
if (assertsFailed === 0) {
  console.log('RESULTADO: EXITO — Lienzo Arcano con control de rendimiento listo (Tarea 3.3).');
  process.exit(0);
} else {
  console.log('RESULTADO: FALLO — hay asertos incumplidos.');
  process.exit(1);
}
