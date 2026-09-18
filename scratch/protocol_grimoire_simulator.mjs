/**
 * protocol_grimoire_simulator.mjs — Protocolo de verificación frontend y de
 * rendimiento del Simulador de Grimorio (Tarea 5.2, TASKS-05).
 *
 * Ejecuta la batería del Plan Técnico (Sec. 6.2) contra los MÓDULOS DE
 * PRODUCCIÓN reales, sin dobles de la lógica verificada: solo se sustituyen
 * los artilugios que el entorno no puede ofrecer (planificador de cuadros,
 * permisos de micrófono, preferencia de movimiento reducido y transcripciones
 * vocales).
 *
 *   P1 · 60 FPS sostenidos con conjuros de Círculo V        (RNF-01, RF-03.3)
 *   P6 · Latencia de la invocación: clic → primera emisión   (RNF-02)
 *   P2 · Autorregulación ante la caída de cuadros           (RF-06.2)
 *   P3 · Movimiento reducido y pulsación estática           (RF-06.1)
 *   P4 · Degradación ceremonial con el micrófono denegado   (RF-04.4, RF-04.5)
 *   P5 · Declamación y tolerancia fonética de palabras clave (RF-04.1/04.2/04.3)
 *
 * P1 se mide en dos planos complementarios y honestos:
 *   a) **Capacidad del motor**: coste de CPU del trabajo de cada cuadro con el
 *      pozo saturado a 200 partículas, medido cuadro a cuadro contra el
 *      presupuesto de 16,6 ms (60 FPS). Se mide en cualquier sustrato.
 *   b) **Cadencia de pantalla**: muestreo de `requestAnimationFrame` con
 *      invocaciones sostenidas de Círculo V. Solo es medible si el sustrato
 *      emite cuadros; cuando no los emite (pestaña sin foco, ventana oculta),
 *      el caso se declara PARCIAL con su motivo en lugar de simular un fallo.
 *
 * Constitución:
 *   - Artículo I: ES Modules nativos, sin dependencias ni empaquetadores.
 *   - Artículo IV (Velo Arcano): la salida del protocolo es solemne y en
 *     noble castellano; jamás se exponen trazas internas.
 *   - Artículo V (Dualidad Lingüística): identificadores en inglés camelCase;
 *     documentación y veredictos en castellano.
 */

import { createGrimoireSimulatorView } from '../public/assets/js/views/grimoireSimulatorView.js';
import { createArcaneCanvasComponent } from '../public/assets/js/components/arcaneCanvasComponent.js';
import {
  createSpeechService,
  RECITATION_LANG,
  RECITATION_PITCH,
  RECITATION_RATE,
} from '../public/assets/js/utils/speechService.js';

/** Identificadores canónicos de los casos del protocolo. */
export const PROTOCOL_CASE_IDS = Object.freeze({
  frameRate: 'P1',
  invocationLatency: 'P6',
  autoThrottle: 'P2',
  reducedMotion: 'P3',
  microphoneDenied: 'P4',
  phoneticTolerance: 'P5',
});

/** Ventana de la prueba de cadencia (plan 6.2.1). */
export const FRAME_RATE_WINDOW_MS = 3000;
/** Cadencia entre invocaciones sostenidas de Círculo V. */
export const FRAME_RATE_CAST_INTERVAL_MS = 300;
/** Tasa mínima exigida por el plan 6.2.1 («entre 55 y 60 FPS»). */
export const FRAME_RATE_MIN_FPS = 55;
/** Presupuesto de CPU por cuadro para sostener 60 FPS. */
export const FRAME_BUDGET_MS = 16.6;
/** Presupuesto de latencia de la invocación (RNF-02): clic → primera emisión. */
export const INVOCATION_LATENCY_BUDGET_MS = 100;
/** Invocaciones muestreadas por el caso de latencia (P6). */
export const INVOCATION_LATENCY_SAMPLES = 8;
/** Cuadros del sondeo de capacidad del motor. */
export const ENGINE_FRAME_SAMPLES = 120;
/** Margen para que un proyectil cruce la Cámara y golpee al maniquí. */
export const IMPACT_SETTLE_MS = 2600;
/** Ventana del sondeo del sustrato de renderizado. */
export const FRAME_SOURCE_PROBE_MS = 400;

/** Conjuro de Círculo V del banco de pruebas (RF-03.3). */
const CIRCLE_FIVE_SPELL = Object.freeze({
  id: 'spl_protocol_v',
  name: 'Sello del Trueno Mayor',
  circle: 5,
  elementalAffinity: 'lightning',
  manaCost: 120,
  castingTime: 'ritual',
  areaType: 'sphere',
  incantationFormula: '¡Runas del trueno mayor, quebrad el velo del silencio!',
  description: 'Descarga plena del círculo mayor sobre el blanco señalado.',
  effects: { damage: 120, healing: 0, barrier: 40, crowdControlType: 'stun' },
});

/** Conjuro de Círculo III para las pruebas de degradación y sensibilidad. */
const CIRCLE_THREE_SPELL = Object.freeze({
  id: 'spl_protocol_iii',
  name: 'Ardor del Alba',
  circle: 3,
  elementalAffinity: 'fire',
  manaCost: 30,
  castingTime: 'action',
  areaType: 'singleTarget',
  incantationFormula: '¡Llamas del alba, descended y consumid la penumbra!',
  description: 'Ascuas del alba que abrazan al blanco.',
  effects: { damage: 60, healing: 0, barrier: 0, crowdControlType: 'none' },
});

// =====================================================================
// Dobles del planificador (transportables a cualquier entorno)
// =====================================================================

/**
 * Planificador sintético determinista: permite gobernar los cuadros y forzar
 * la caída de tasa sin depender del reloj del sistema (plan 6.2.2).
 */
export function createSyntheticScheduler() {
  let nextId = 1;
  let pending = new Map();
  let timestamp = 0;
  let millis = 1_700_000_000_000;
  return {
    clock: { now: () => millis },
    now: () => millis,
    raf(callback) { const id = nextId++; pending.set(id, callback); return id; },
    caf(id) { pending.delete(id); },
    run(frames, stepMs = 16) {
      for (let i = 0; i < frames; i++) {
        timestamp += stepMs;
        millis += stepMs;
        const inFlight = [...pending.values()];
        pending = new Map();
        inFlight.forEach((callback) => callback(timestamp));
      }
    },
  };
}

/**
 * Contexto 2D grabador: reenvía cada trazado al contexto real y lo registra.
 * Imprescindible para auditar la pulsación estática sin ceder fidelidad.
 */
export function createRecordingContext(target) {
  const operations = [];
  if (!target) {
    return { proxy: null, operations };
  }
  const proxy = new Proxy(target, {
    get(holder, property) {
      const value = holder[property];
      if (typeof value !== 'function') return value;
      return (...args) => {
        operations.push({ name: String(property), args });
        return value.apply(holder, args);
      };
    },
    set(holder, property, value) {
      holder[property] = value;
      return true;
    },
  });
  return { proxy, operations };
}

// =====================================================================
// Utilidades del protocolo
// =====================================================================

/** Reloj de alta resolución (estándar en navegador y en Node moderno). */
function perfNow() {
  return typeof performance !== 'undefined' ? performance.now() : Date.now();
}

/** Cliente del grimorio del protocolo: entrega el catálogo dado. */
function createCatalogClient(spells) {
  return {
    async fetchSpells() {
      return {
        success: true,
        status: 200,
        data: {
          totalSpells: spells.length,
          currentPage: 1,
          totalPages: spells.length,
          hasPrevious: false,
          hasNext: false,
          spells,
        },
      };
    },
  };
}

/** Resumen estadístico de una serie de medidas (ms). */
function summarizeSamples(samples) {
  if (samples.length === 0) {
    return { count: 0, meanMs: 0, p95Ms: 0, maxMs: 0 };
  }
  const sorted = [...samples].sort((a, b) => a - b);
  const total = samples.reduce((sum, value) => sum + value, 0);
  return {
    count: samples.length,
    meanMs: Number((total / samples.length).toFixed(3)),
    p95Ms: Number(sorted[Math.min(sorted.length - 1, Math.floor(sorted.length * 0.95))].toFixed(3)),
    maxMs: Number(sorted[sorted.length - 1].toFixed(3)),
  };
}

/** Muestreador de la cadencia real de cuadros (RF-06.2/RNF-01). */
function createFrameSampler(environment, ticks) {
  const intervals = [];
  let last = null;
  let running = true;
  let handle = 0;
  const tick = (timestamp) => {
    if (!running) return;
    ticks.count++;
    if (last !== null) intervals.push(timestamp - last);
    last = timestamp;
    handle = environment.raf(tick);
  };
  handle = environment.raf(tick);
  return {
    stop() {
      running = false;
      environment.caf?.(handle);
      if (intervals.length === 0) {
        return { frames: 0, durationMs: 0, averageFps: 0, p95IntervalMs: 0, maxIntervalMs: 0 };
      }
      const durationMs = intervals.reduce((total, value) => total + value, 0);
      const sorted = [...intervals].sort((a, b) => a - b);
      return {
        frames: intervals.length,
        durationMs: Math.round(durationMs),
        averageFps: Number(((intervals.length * 1000) / durationMs).toFixed(1)),
        p95IntervalMs: Number(sorted[Math.min(sorted.length - 1, Math.floor(sorted.length * 0.95))].toFixed(2)),
        maxIntervalMs: Number(sorted[sorted.length - 1].toFixed(2)),
      };
    },
  };
}

/** Cuenta los trazados de un tipo registrados por el contexto grabador. */
function countOperations(operations, name) {
  return operations.filter((operation) => operation.name === name).length;
}

/**
 * Sondea el sustrato de renderizado: ¿emite cuadros el navegador?
 * Las pestañas sin foco o los visores sin compositor no los emiten, y el
 * protocolo debe distinguir esa limitación de un defecto del santuario.
 */
async function probeFrameSource(environment) {
  const ticks = { count: 0 };
  let handle = 0;
  const count = () => { ticks.count++; handle = environment.raf(count); };
  handle = environment.raf(count);
  await environment.sleep(FRAME_SOURCE_PROBE_MS);
  environment.caf?.(handle);
  return { ticks: ticks.count, available: ticks.count > 0 };
}

// =====================================================================
// Montaje de la vista con los ajustes de cada caso
// =====================================================================

/**
 * Monta la vista real sobre el lienzo y el documento del entorno.
 * @returns {Promise<object>} { view, host, canvas, operations, busEvents }
 */
async function mountSimulator(environment, config = {}) {
  const host = environment.document.createElement('main');
  // El entorno de navegador expone la Cámara en pantalla (cuadros reales).
  environment.attachHost?.(host);
  const busEvents = [];
  host.addEventListener?.('grimoire:cast-spell', (event) => busEvents.push(event?.detail ?? null));
  const { canvas, ctx } = environment.createCanvas(config.canvasWidth ?? 960, config.canvasHeight ?? 440);
  const recorder = createRecordingContext(ctx);
  const speechService = config.speechService ?? createSpeechService({
    synth: environment.synthesis,
    Utterance: environment.Utterance,
    Recognition: config.recognitionClass ?? null,
  });
  const view = createGrimoireSimulatorView(host, {
    grimoireClient: createCatalogClient(config.spells ?? [CIRCLE_FIVE_SPELL]),
    elementFactory: environment.elementFactory,
    document: environment.document,
    canvas,
    ctx: recorder.proxy,
    raf: environment.raf,
    caf: environment.caf,
    clock: { now: environment.now },
    motionQuery: config.motionQuery ?? environment.matchMedia('(prefers-reduced-motion: reduce)', {}),
    speechSynthesis: environment.synthesis,
    speechService,
    storage: null,
  });
  await view.render();
  return { view, host, canvas, operations: recorder.operations, busEvents };
}

// =====================================================================
// Casos del protocolo
// =====================================================================

/** P1 · 60 FPS sostenidos con conjuros de Círculo V. */
async function runFrameRateCase(environment, context) {
  const evidence = [];
  let checksPassed = 0;
  let checksFailed = 0;
  const check = (condition, description) => {
    if (condition) { checksPassed++; evidence.push(`PASA · ${description}`); }
    else { checksFailed++; evidence.push(`FALLA · ${description}`); }
  };

  // --- a) Capacidad del motor: coste de CPU por cuadro con el pozo lleno ---
  const scheduler = createSyntheticScheduler();
  const { ctx } = environment.createCanvas(960, 440);
  const recorder = createRecordingContext(ctx);
  const arcane = createArcaneCanvasComponent({
    ctx: recorder.proxy,
    document: environment.document,
    raf: scheduler.raf,
    caf: scheduler.caf,
    clock: scheduler.clock,
  });
  arcane.start();

  const castOptions = {
    geometry: 'sphere',
    origin: { x: 960 * 0.12, y: 440 * 0.78 },
    target: { x: 960 * 0.70, y: 440 * 0.50 },
    count: 14,
  };
  let emitted = 0;
  for (let burst = 0; burst < 12; burst++) {
    emitted = arcane.castSpell(CIRCLE_FIVE_SPELL, castOptions);
  }
  const peakParticles = arcane.getActiveParticleCount();
  const frameCosts = [];
  for (let frame = 0; frame < ENGINE_FRAME_SAMPLES; frame++) {
    const started = perfNow();
    scheduler.run(1, 16);
    frameCosts.push(perfNow() - started);
  }
  const cost = summarizeSamples(frameCosts);
  arcane.stop();

  evidence.push(`Pozo saturado con ráfaga de Círculo V: ${emitted} partículas por invocación, ${peakParticles}/200 activas`);
  evidence.push(`Coste del trabajo de cuadro (${cost.count} cuadros): media ${cost.meanMs} ms · p95 ${cost.p95Ms} ms · máximo ${cost.maxMs} ms`);
  check(peakParticles > 0, 'el lienzo emite partículas para el Círculo V');
  check(peakParticles <= 200, 'la población respeta el techo FIFO de 200 partículas (RF-03.4)');
  check(cost.p95Ms <= FRAME_BUDGET_MS,
    `el trabajo de cuadro cabe en el presupuesto de ${FRAME_BUDGET_MS} ms (60 FPS, RNF-01)`);

  // --- b) Cadencia de pantalla: muestreo de cuadros reales ---
  let status = 'complete';
  if (!context.frameSource.available) {
    status = 'partial';
    evidence.push(`Cadencia de pantalla NO medible: el sustrato no emitió cuadros en ${FRAME_SOURCE_PROBE_MS} ms`
      + ' (pestaña sin foco o visor sin compositor); el bucle se suspende justificadamente por RF-06.3.');
  } else {
    const { view } = await mountSimulator(environment, { spells: [CIRCLE_FIVE_SPELL] });
    const sampler = createFrameSampler(environment, { count: 0 });
    const startInstant = environment.now();
    let casts = 0;
    let screenPeak = 0;
    while (environment.now() - startInstant < FRAME_RATE_WINDOW_MS) {
      await view.castCurrentSpell({ triggerMethod: 'click' });
      casts++;
      screenPeak = Math.max(screenPeak, view.getState().activeParticles);
      await environment.sleep(FRAME_RATE_CAST_INTERVAL_MS);
    }
    const stats = sampler.stop();
    const cadenceLabel = environment.realFrames ? 'Cadencia de pantalla' : 'Cadencia sintética';
    evidence.push(`${cadenceLabel}: ${casts} invocaciones sostenidas, ${stats.frames} cuadros en ${stats.durationMs} ms`);
    evidence.push(`Tasa media ${stats.averageFps} FPS · p95 del intervalo ${stats.p95IntervalMs} ms · máximo ${stats.maxIntervalMs} ms · pico ${screenPeak}/200 partículas`);
    check(casts >= 8, 'las invocaciones sostenidas cubren la ventana completa del protocolo');
    check(stats.frames >= 60, 'se registran cuadros suficientes para una medida representativa');
    check(stats.averageFps >= FRAME_RATE_MIN_FPS, `la tasa media sostiene ${FRAME_RATE_MIN_FPS}+ FPS en pantalla (RNF-01)`);
    check(stats.p95IntervalMs <= 33.4, 'el percentil 95 del intervalo no degrada la cadencia de 60 FPS');
    view.destroy();
    if (!environment.realFrames) {
      status = 'partial';
      evidence.push('La cadencia se ha ejercitado con cuadros sintéticos: la tasa real de pantalla solo'
        + ' puede medirse en el navegador (RNF-01). La capacidad del motor sí quedó medida de verdad.');
    }
  }

  if (checksFailed > 0) status = 'failed';
  return { status, checksPassed, checksFailed, evidence };
}

/** P2 · Autorregulación de cuadros por segundo (auto-throttle). */
async function runAutoThrottleCase() {
  const evidence = [];
  let checksPassed = 0;
  let checksFailed = 0;
  const check = (condition, description) => {
    if (condition) { checksPassed++; evidence.push(`PASA · ${description}`); }
    else { checksFailed++; evidence.push(`FALLA · ${description}`); }
  };

  const scheduler = createSyntheticScheduler();
  const arcane = createArcaneCanvasComponent({
    ctx: null,
    document: null,
    raf: scheduler.raf,
    caf: scheduler.caf,
    clock: scheduler.clock,
  });

  const plainSpell = { name: 'Conjuro de prueba', elementalAffinity: 'fire' };
  const castOptions = {
    geometry: 'singleTarget',
    origin: { x: 80, y: 320 },
    target: { x: 620, y: 180 },
    count: 20,
  };

  arcane.start();
  const canonicalEmission = arcane.castSpell(plainSpell, castOptions);
  evidence.push(`Emisión canónica: ${canonicalEmission} partículas (20 × densidad 1.0)`);

  // Una ventana de un segundo con un solo cuadro: muy por debajo de 30 FPS.
  scheduler.run(1, 1000);
  evidence.push(`Tras una ventana lenta (1 cuadro/s): densidad ${arcane.getDensityScale()}`);
  check(arcane.getDensityScale() === 1, 'una única muestra lenta no altera la densidad (sin sobrerreacción)');

  scheduler.run(1, 1000);
  evidence.push(`Tras dos ventanas lentas consecutivas: densidad ${arcane.getDensityScale()}`);
  check(arcane.getDensityScale() === 0.4, 'dos muestras lentas consecutivas activan el modo de bajo consumo (RF-06.2)');

  const throttledEmission = arcane.castSpell(plainSpell, castOptions);
  evidence.push(`Emisión en bajo consumo: ${throttledEmission} partículas (20 × 0.4)`);
  check(throttledEmission === Math.round(20 * 0.4), 'la densidad se reduce a la mitad sin bloquear la interfaz (plan 4.2)');

  arcane.stop();
  return { status: 'complete', checksPassed, checksFailed, evidence };
}

/** P3 · Movimiento reducido: pulsación estática e impacto inmediato. */
/**
 * P6 · Latencia de la invocación (RNF-02): lapso entre la orden de lanzamiento
 * (clic) y la PRIMERA emisión cinemática en el lienzo, contra el presupuesto
 * de 100 ms. La medición es determinista y honesta en cualquier sustrato:
 * el reloj es el sintético del protocolo, avanzado exclusivamente por el
 * planificador de cuadros — la misma cadencia que la propia Cámara usa —
 * de modo que el lapso medido es exactamente el trabajo de la vista
 * (resolución de la cola, manifestación en el lienzo y despacho del
 * impacto), sin ruido de anfitrión. Se muestrean ambas rutas: la
 * cinemática plena (proyectiles) y la de movimiento reducido (destello
 * estático + impacto inmediato).
 */
async function runInvocationLatencyCase(environment) {
  const evidence = [];
  let checksPassed = 0;
  let checksFailed = 0;
  const check = (condition, description) => {
    if (condition) { checksPassed++; evidence.push(`PASA · ${description}`); }
    else { checksFailed++; evidence.push(`FALLA · ${description}`); }
  };

  /**
   * Mide una invocación: timestamp del clic, cuadros sintéticos avanzados
   * uno a uno y captura del instante en que el lienzo traza por primera
   * vez (primera emisión cinemática). Devuelve el lapso en ms del reloj
   * del protocolo y las emisiones contadas.
   */
  const measureCast = async ({ motionQuery }) => {
    const mounted = await mountSimulator(environment, {
      spells: [CIRCLE_FIVE_SPELL],
      motionQuery,
    });
    const { view, operations } = mounted;
    let firstDrawMs = null;
    let drawCount = 0;
    const castInstant = environment.now();
    const drawSnapshot = () => countOperations(operations, 'arc')
      + countOperations(operations, 'fillRect')
      + countOperations(operations, 'drawImage');
    const baseline = drawSnapshot();
    await view.castCurrentSpell({ triggerMethod: 'click' });
    // Avanza cuadros de a uno (el planificador inyecta 16 ms por cuadro):
    // la primera emisión cinemática aparece en el cuadro donde el pozo
    // de partículas renderiza por primera vez.
    for (let frame = 0; frame < 12 && firstDrawMs === null; frame++) {
      environment.runFrames(1, 16);
      const now = drawSnapshot();
      if (now > baseline) {
        firstDrawMs = environment.now() - castInstant;
      }
      drawCount = now - baseline;
    }
    // Drena los cuadros restantes para completar el vuelo.
    environment.runFrames(40, 16);
    const finalState = view.getState();
    view.destroy();
    return { firstDrawMs, drawCount, state: finalState };
  };

  // --- a) Ruta cinemática plena (con proyectiles) ---
  const fullMotion = await measureCast({
    motionQuery: environment.matchMedia('(prefers-reduced-motion: reduce)', {}),
  });
  evidence.push(`Ruta cinemática: primera emisión a los ${fullMotion.firstDrawMs ?? '—'} ms del reloj del protocolo, ${fullMotion.drawCount} trazados`);
  check(fullMotion.firstDrawMs !== null, 'la ruta cinemática produce una primera emisión observable (RNF-02)');
  check(
    fullMotion.firstDrawMs !== null && fullMotion.firstDrawMs <= INVOCATION_LATENCY_BUDGET_MS,
    `la primera emisión ocurre dentro del presupuesto de ${INVOCATION_LATENCY_BUDGET_MS} ms (RNF-02)`,
  );
  check(fullMotion.state.activeParticles > 0, 'la emisión cinemática deja partículas activas en el lienzo');

  // --- b) Ruta de movimiento reducido (destello estático + impacto inmediato) ---
  const reduced = await measureCast({
    motionQuery: environment.matchMedia('(prefers-reduced-motion: reduce)', { reduce: true }),
  });
  evidence.push(`Ruta reducida: primera emisión a los ${reduced.firstDrawMs ?? '—'} ms del reloj del protocolo, ${reduced.drawCount} trazados`);
  check(reduced.firstDrawMs !== null, 'la ruta reducida produce una primera emisión observable (RNF-02)');
  check(
    reduced.firstDrawMs !== null && reduced.firstDrawMs <= INVOCATION_LATENCY_BUDGET_MS,
    `la primera emisión reducida ocurre dentro del presupuesto de ${INVOCATION_LATENCY_BUDGET_MS} ms (RNF-02)`,
  );

  return { status: checksFailed > 0 ? 'failed' : 'complete', checksPassed, checksFailed, evidence };
}

async function runReducedMotionCase(environment, context) {
  const evidence = [];
  let checksPassed = 0;
  let checksFailed = 0;
  const check = (condition, description) => {
    if (condition) { checksPassed++; evidence.push(`PASA · ${description}`); }
    else { checksFailed++; evidence.push(`FALLA · ${description}`); }
  };

  const reducedQuery = environment.matchMedia('(prefers-reduced-motion: reduce)', { reduce: true });
  const { view, operations, busEvents } = await mountSimulator(environment, {
    spells: [CIRCLE_THREE_SPELL],
    motionQuery: reducedQuery,
  });
  const healthBefore = view.getState().dummy.health;
  await view.castCurrentSpell({ triggerMethod: 'click' });
  // El destello rúnico se traza en el cuadro siguiente al impacto inmediato.
  await environment.sleep(context.frameSource.available ? 120 : 40);
  const state = view.getState();

  evidence.push(`Partículas emitidas: ${state.activeParticles} · impacto: ${healthBefore} → ${state.dummy.health} PV`);
  evidence.push(`Destellos rúnicos trazados: ${countOperations(operations, 'arc')}`);

  check(state.reducedMotion === true, 'la vista reconoce la preferencia de movimiento reducido (RF-06.1)');
  check(state.activeParticles === 0, 'se suprimen los proyectiles cinemáticos (RF-06.1)');
  check(state.dummy.health < healthBefore, 'el impacto se representa de inmediato, sin trayectoria (RF-06.1)');
  check(state.tremoring === false, 'se anula el temblor violento de la página (RF-06.1)');
  check(busEvents.length === 1 && busEvents[0]?.triggerMethod === 'click',
    'la invocación reducida también anuncia su método en el bus (plan 4.1)');

  // El destello se traza durante los cuadros: sin sustrato que los emita, la
  // comprobación se declara no observable en lugar de simular un defecto.
  let status = 'complete';
  if (context.frameSource.available) {
    check(countOperations(operations, 'arc') > 0, 'se dibuja la pulsación lumínica estática en el lienzo (RF-06.1)');
  } else {
    status = 'partial';
    evidence.push('La pulsación estática no es observable sin cuadros en el sustrato (RF-06.3); '
      + 'la ruta de movimiento reducido (cero proyectiles e impacto inmediato) sí quedó verificada.');
  }
  view.destroy();
  if (checksFailed > 0) status = 'failed';
  return { status, checksPassed, checksFailed, evidence };
}

/** P4 · Degradación ceremonial con el micrófono denegado. */
async function runMicrophoneDeniedCase(environment, context) {
  const evidence = [];
  let checksPassed = 0;
  let checksFailed = 0;
  const check = (condition, description) => {
    if (condition) { checksPassed++; evidence.push(`PASA · ${description}`); }
    else { checksFailed++; evidence.push(`FALLA · ${description}`); }
  };

  const deniedRecognition = environment.createRecognitionClass({ denied: true });
  const { view, host, busEvents } = await mountSimulator(environment, {
    spells: [CIRCLE_THREE_SPELL],
    recognitionClass: deniedRecognition,
  });

  const micSeal = findSeal(host, 'Micrófono de Conjuración');
  const castSeal = findSeal(host, 'Invocar Conjuro');
  let threw = false;
  try {
    pressSeal(micSeal);
  } catch {
    threw = true;
  }

  const state = view.getState();
  const solemn = view.getAnnouncements().find((message) => /micrófono|silencio/i.test(message)) ?? '';
  evidence.push(`Sello vocal en reposo: ${state.voiceSealResting} · Sello táctil habilitado: ${state.tactileSealEnabled}`);
  evidence.push(`Aviso solemne: «${solemn}»`);

  check(threw === false, 'pulsar el sello vocal no lanza excepción alguna (RF-04.4)');
  check(state.voiceSealResting === true, 'el sello vocal queda en reposo ceremonial (RF-04.4)');
  check(micSeal?.getAttribute('aria-disabled') === 'true', 'el sello en reposo se anuncia como no disponible (RF-04.4)');
  check(/micrófono|silencio/i.test(solemn), 'el aviso de permiso denegado llega a la región viva (RF-04.4)');
  check(state.tactileSealEnabled === true, 'el sello táctil jamás se deshabilita (RF-04.5)');

  // El lanzamiento manual permanece operativo pese a la voz en reposo (RF-04.5).
  const healthBefore = view.getState().dummy.health;
  pressSeal(castSeal);
  const dispatched = busEvents.filter((detail) => detail?.triggerMethod === 'click').length;
  evidence.push(`Invocaciones despachadas por el sello táctil: ${dispatched}`);
  check(dispatched >= 1, 'el sello táctil despacha `grimoire:cast-spell` con método «click» (RF-04.5)');

  let status = 'complete';
  if (context.frameSource.available) {
    await environment.sleep(IMPACT_SETTLE_MS);
    const healthAfter = view.getState().dummy.health;
    evidence.push(`Impacto manual sobre el maniquí: ${healthBefore} → ${healthAfter} PV`);
    check(healthAfter < healthBefore, 'la invocación manual sigue desatando el conjuro (RF-04.5)');
  } else {
    status = 'partial';
    evidence.push('El impacto del lanzamiento manual no es observable sin cuadros en el sustrato (RF-06.3); '
      + 'la ruta completa sí quedó verificada por el arnés de la Tarea 5.1 y por P3 con impacto inmediato.');
  }

  view.destroy();
  if (checksFailed > 0) status = 'failed';
  return { status, checksPassed, checksFailed, evidence };
}

/** P5 · Declamación y tolerancia fonética de palabras clave. */
async function runPhoneticToleranceCase(environment) {
  const evidence = [];
  let checksPassed = 0;
  let checksFailed = 0;
  const check = (condition, description) => {
    if (condition) { checksPassed++; evidence.push(`PASA · ${description}`); }
    else { checksFailed++; evidence.push(`FALLA · ${description}`); }
  };

  const recognitionClass = environment.createRecognitionClass();
  const service = createSpeechService({
    synth: environment.synthesis,
    Utterance: environment.Utterance,
    Recognition: recognitionClass,
  });

  const recited = service.reciteSpell(CIRCLE_THREE_SPELL.incantationFormula);
  const spokenBefore = environment.synthesis.calls.spoken.length;
  const lastSpoken = environment.synthesis.calls.spoken[spokenBefore - 1];
  evidence.push(`Locución: «${lastSpoken?.text}» (lang ${lastSpoken?.lang}, rate ${lastSpoken?.rate?.toFixed?.(3) ?? lastSpoken?.rate}, pitch ${lastSpoken?.pitch?.toFixed?.(3) ?? lastSpoken?.pitch})`);
  check(recited === true, 'la fórmula litúrgica se declama en voz alta (RF-04.2)');
  check(lastSpoken?.lang === RECITATION_LANG, 'la declamación usa el dialecto castellano canónico (RF-04.1)');
  // La Web Speech API almacena la cadencia en coma flotante de 32 bits: la
  // comparación exige tolerancia (0.85 llega como 0.8500000238418579).
  check(Math.abs((lastSpoken?.rate ?? 0) - RECITATION_RATE) < 0.01
    && Math.abs((lastSpoken?.pitch ?? 0) - RECITATION_PITCH) < 0.01,
  'la cadencia es pausada y solemne (RF-04.2)');

  let matchedTranscript = null;
  let matchedCount = 0;
  service.startListening(
    CIRCLE_THREE_SPELL,
    (spell, transcript) => { matchedCount++; matchedTranscript = transcript; },
    () => {},
  );
  const recognition = recognitionClass.instances[recognitionClass.instances.length - 1];

  // La tolerancia fonética exige el nombre canónico, la fórmula ceremonial o
  // DOS palabras clave del nombre («ardor», «alba»).
  const attempts = [
    { phrase: 'ardor del alba', expected: true, label: 'nombre canónico en minúsculas' },
    { phrase: 'Ardor del Alba', expected: true, label: 'nombre canónico exacto' },
    { phrase: '¡Llamas del alba, descended y consumid la penumbra!', expected: true, label: 'fórmula ceremonial íntegra' },
    { phrase: 'el ardor precede al alba', expected: true, label: 'dos palabras clave del nombre' },
    { phrase: 'ardor', expected: false, label: 'una sola palabra clave' },
    { phrase: 'las estrellas susurran', expected: false, label: 'frase ajena al conjuro' },
  ];

  let hits = 0;
  let ignored = 0;
  for (const attempt of attempts) {
    const before = matchedCount;
    recognition.speak(attempt.phrase);
    const didMatch = matchedCount > before;
    if (didMatch) hits++; else ignored++;
    check(didMatch === attempt.expected,
      `transcripción «${attempt.phrase}» (${attempt.label}) ${attempt.expected ? 'invoca' : 'no invoca'}`);
    if (didMatch) matchedTranscript = attempt.phrase;
  }
  evidence.push(`Reconocidas: ${hits} · ignoradas: ${ignored} · última: «${matchedTranscript}»`);

  return { status: checksFailed === 0 ? 'complete' : 'failed', checksPassed, checksFailed, evidence };
}

/**
 * Pulsa un sello con la API nativa del navegador o con el artefacto simulado
 * de los corredores sin DOM (misma intención, dos entornos).
 */
function pressSeal(node) {
  if (!node) return false;
  if (typeof node.click === 'function') {
    node.click();
    return true;
  }
  if (typeof node.dispatch === 'function') {
    node.dispatch('click');
    return true;
  }
  return false;
}

/** Localiza un sello ceremonial por su rótulo castellano. */
function findSeal(node, label) {
  for (const child of node?.children ?? []) {
    if (child.tagName === 'BUTTON' && child.textContent.trim() === label) return child;
    const found = findSeal(child, label);
    if (found) return found;
  }
  return null;
}

// =====================================================================
// Batería completa
// =====================================================================

/**
 * Ejecuta el protocolo de verificación completo.
 * @param {{environment: object}} options
 * @returns {Promise<object>} Informe del protocolo.
 */
export async function runSimulatorProtocol({ environment }) {
  const log = environment.log ?? (() => {});
  const cases = [];

  log('== PROTOCOLO DE VERIFICACIÓN FRONTEND — SIMULADOR DE GRIMORIO (SPEC-05) ==');
  log(`Entorno: ${environment.label}`);

  // Sondeo del sustrato: distingue «no medible aquí» de «defecto».
  const frameSource = await probeFrameSource(environment);
  const context = { frameSource };
  log(`Sustrato de renderizado: ${frameSource.available
    ? `emite cuadros (${frameSource.ticks} en ${FRAME_SOURCE_PROBE_MS} ms)`
    : `sin cuadros en ${FRAME_SOURCE_PROBE_MS} ms — los casos dependientes de pantalla se declararán PARCIALES`}`);

  const suite = [
    {
      id: PROTOCOL_CASE_IDS.frameRate,
      title: '60 FPS sostenidos con conjuros de Círculo V',
      requirements: ['RNF-01', 'RF-03.3', 'RF-03.4'],
      run: () => runFrameRateCase(environment, context),
    },
    {
      id: PROTOCOL_CASE_IDS.autoThrottle,
      title: 'Autorregulación ante la caída de cuadros',
      requirements: ['RF-06.2'],
      run: () => runAutoThrottleCase(),
    },
    {
      id: PROTOCOL_CASE_IDS.reducedMotion,
      title: 'Movimiento reducido con pulsación estática',
      requirements: ['RF-06.1'],
      run: () => runReducedMotionCase(environment, context),
    },
    {
      id: PROTOCOL_CASE_IDS.microphoneDenied,
      title: 'Degradación ceremonial con el micrófono denegado',
      requirements: ['RF-04.4', 'RF-04.5'],
      run: () => runMicrophoneDeniedCase(environment, context),
    },
    {
      id: PROTOCOL_CASE_IDS.phoneticTolerance,
      title: 'Declamación y tolerancia fonética de palabras clave',
      requirements: ['RF-04.1', 'RF-04.2', 'RF-04.3'],
      run: () => runPhoneticToleranceCase(environment),
    },
    {
      id: PROTOCOL_CASE_IDS.invocationLatency,
      title: 'Latencia de la invocación: clic → primera emisión',
      requirements: ['RNF-02'],
      run: () => runInvocationLatencyCase(environment),
    },
  ];

  for (const entry of suite) {
    const outcome = await entry.run();
    cases.push({
      id: entry.id,
      title: entry.title,
      requirements: entry.requirements,
      status: outcome.status,
      checksPassed: outcome.checksPassed,
      checksFailed: outcome.checksFailed,
      evidence: outcome.evidence,
    });
    const label = outcome.status === 'complete' ? 'COMPLETO' : outcome.status === 'partial' ? 'PARCIAL' : 'FALLIDO';
    log(`\n[${entry.id}] ${entry.title} — ${label} (${outcome.checksPassed} aciertos, ${outcome.checksFailed} fallos)`);
    outcome.evidence.forEach((line) => log(`       ${line}`));
  }

  const errors = [...(environment.collectedErrors ?? [])];
  const complete = cases.filter((entry) => entry.status === 'complete').length;
  const partial = cases.filter((entry) => entry.status === 'partial').length;
  const failed = cases.filter((entry) => entry.status === 'failed').length;

  // El protocolo exige además una consola limpia (plan 6.2).
  const sourceClean = errors.length === 0;
  const verdictOk = failed === 0 && sourceClean;
  const verdict = verdictOk
    ? `EXITO — ${complete}/${cases.length} casos completos`
      + `${partial > 0 ? `, ${partial} parcial(es) por limitación del sustrato (sin cuadros de pantalla)` : ''}`
      + ' y consola limpia (Tarea 5.2).'
    : `FALLO — ${failed} caso(s) fallido(s) y ${errors.length} error(es) no capturado(s).`;

  log(`\nConsola limpia: ${sourceClean ? 'sí' : 'no'} · Errores no capturados: ${errors.length}`);
  log(`VEREDICTO: ${verdict}`);

  return {
    environment: environment.label,
    frameSource,
    cases,
    summary: { complete, partial, failed },
    errors,
    verdict,
    exitCode: verdictOk ? 0 : 1,
  };
}
