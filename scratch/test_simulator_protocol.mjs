/**
 * test_simulator_protocol.mjs — Corredor en terminal del protocolo de
 * verificación frontend de SPEC-05 (Tarea 5.2, plan 6.2).
 *
 * Estrategia TDD: este corredor se escribe ANTES que el protocolo.
 *
 * Monta un adaptador del navegador (DOM, lienzo, RAF, reloj, síntesis y
 * reconocimiento falsos) y ejecuta `scratch/protocol_grimoire_simulator.mjs`
 * contra los MÓDULOS DE PRODUCCIÓN reales. Los FPS auténticos solo pueden
 * medirse en el navegador: aquí el caso P1 se marca PARCIAL (presupuesto de
 * partículas) y la página `scratch/protocol_grimoire_simulator.html` aporta
 * la medición completa.
 *
 * Constitución:
 *   - Artículo I: ES Modules nativos; dobles inyectables, cero dependencias.
 *   - Artículo IV/V: la salida del protocolo es solemne y en castellano.
 *
 * Uso: node scratch/test_simulator_protocol.mjs
 */

import { runSimulatorProtocol } from './protocol_grimoire_simulator.mjs';

// =====================================================================
// Adaptador del navegador: dobles fieles, sin frameworks (Dogma Vanilla)
// =====================================================================

/** Contexto 2D falso que registra cada trazado. */
function createFakeContext() {
  const operations = [];
  const record = (name) => (...args) => operations.push({ name, args: [...args] });
  return {
    operations,
    canvas: null,
    globalAlpha: 1,
    fillStyle: '#000',
    strokeStyle: '#000',
    font: '',
    textAlign: 'left',
    textBaseline: 'alphabetic',
    lineWidth: 1,
    save: record('save'),
    restore: record('restore'),
    beginPath: record('beginPath'),
    closePath: record('closePath'),
    moveTo: record('moveTo'),
    lineTo: record('lineTo'),
    arc: record('arc'),
    fill: record('fill'),
    stroke: record('stroke'),
    fillRect: record('fillRect'),
    clearRect: record('clearRect'),
    fillText: record('fillText'),
    translate: record('translate'),
    rotate: record('rotate'),
    scale: record('scale'),
    setLineDash: record('setLineDash'),
    setTransform: record('setTransform'),
    createRadialGradient: () => ({ addColorStop: record('addColorStop') }),
    createLinearGradient: () => ({ addColorStop: record('addColorStop') }),
    measureText: (text) => ({ width: String(text ?? '').length * 7 }),
  };
}

/** Elemento simulado (innerHTML prohibido, AGENTS.md 6.1). */
function createFakeElement(tagName, ownerDocument = null) {
  const element = {
    tagName: String(tagName).toUpperCase(),
    ownerDocument,
    children: [],
    attributes: {},
    classes: new Set(),
    listeners: {},
    style: {
      inline: {},
      setProperty(name, value) { this.inline[name] = String(value); },
      getProperty(name) { return this.inline[name] ?? null; },
    },
    _textContent: '',
    disabled: false,
    parentElement: null,
    setAttribute(name, value) { this.attributes[name] = String(value); },
    getAttribute(name) { return name in this.attributes ? this.attributes[name] : null; },
    removeAttribute(name) { delete this.attributes[name]; },
    addEventListener(name, listener) { (this.listeners[name] ??= []).push(listener); },
    removeEventListener(name, listener) {
      this.listeners[name] = (this.listeners[name] ?? []).filter((l) => l !== listener);
    },
    dispatchEvent(event) {
      let node = this;
      while (node) {
        (node.listeners?.[event?.type] ?? []).forEach((l) => l(event));
        if (event?.bubbles === false) break;
        node = node.parentElement ?? null;
      }
      return true;
    },
    dispatch(name, event = {}) { this.dispatchEvent({ type: name, bubbles: true, ...event }); },
    appendChild(child) { child.parentElement = this; this.children.push(child); return child; },
    replaceChildren(...nodes) { this.children = [...nodes]; nodes.forEach((n) => { n.parentElement = this; }); },
    remove() {
      if (this.parentElement) {
        this.parentElement.children = this.parentElement.children.filter((c) => c !== this);
        this.parentElement = null;
      }
    },
    set className(value) { this.classes = new Set(String(value).split(/\s+/).filter(Boolean)); },
    get className() { return [...this.classes].join(' '); },
    get textContent() {
      if (this.children.length === 0) return this._textContent;
      return this.children.map((c) => c.textContent).join('');
    },
    set textContent(value) { this._textContent = String(value); this.children = []; },
    get innerHTML() { throw new Error('PROHIBIDO innerHTML (AGENTS.md 6.1)'); },
    set innerHTML(value) { throw new Error('PROHIBIDO innerHTML (AGENTS.md 6.1)'); },
  };
  element.classList = {
    _owner: element,
    add(...names) { names.forEach((n) => this._owner.classes.add(n)); },
    remove(...names) { names.forEach((n) => this._owner.classes.delete(n)); },
    contains(name) { return this._owner.classes.has(name); },
    toggle(name, force) {
      if (force === undefined) {
        this._owner.classes.has(name)
          ? this._owner.classes.delete(name)
          : this._owner.classes.add(name);
        return this._owner.classes.has(name);
      }
      force ? this._owner.classes.add(name) : this._owner.classes.delete(name);
      return Boolean(force);
    },
  };
  if (element.tagName === 'CANVAS') {
    element.width = 800;
    element.height = 400;
    element.clientWidth = 800;
    element.clientHeight = 400;
    const context = createFakeContext();
    context.canvas = element;
    element.getContext = () => context;
    element.__ctx = context;
  }
  return element;
}

/** Documento simulado con visibilidad conmutable. */
function createFakeDocument() {
  const doc = {
    hidden: false,
    listeners: {},
    createElement(tagName) { return createFakeElement(tagName, doc); },
    addEventListener(name, listener) { (doc.listeners[name] ??= []).push(listener); },
    removeEventListener(name, listener) {
      doc.listeners[name] = (doc.listeners[name] ?? []).filter((l) => l !== listener);
    },
    dispatch(name) { (doc.listeners[name] ?? []).forEach((l) => l({ type: name })); },
  };
  return doc;
}

/** Planificador RAF falso que adelanta el reloj inyectado. */
function createFakeScheduler() {
  let nextId = 1;
  let pending = new Map();
  let timestamp = 0;
  let millis = 1_700_000_000_000;
  return {
    clock: { now: () => millis },
    now: () => millis,
    raf(callback) { const id = nextId++; pending.set(id, callback); return id; },
    caf(id) { pending.delete(id); },
    /** Ejecuta `frames` cuadros de `stepMs` ms (determinista). */
    run(frames, stepMs = 16) {
      for (let i = 0; i < frames; i++) {
        timestamp += stepMs;
        millis += stepMs;
        const inFlight = [...pending.values()];
        pending = new Map();
        inFlight.forEach((callback) => callback(timestamp));
      }
    },
    /** Espera simulada: consume cuadros para que los temporizadores venzan. */
    async sleep(ms) {
      const step = 16;
      const frames = Math.max(1, Math.ceil(ms / step));
      for (let i = 0; i < frames; i++) {
        this.run(1, step);
        await new Promise((resolve) => setImmediate(resolve));
      }
    },
  };
}

/** Síntesis vocal falsa que registra locuciones y pausas (RF-06.3). */
function createFakeSynthesis() {
  const calls = { spoken: [], cancelled: 0, paused: 0, resumed: 0 };
  return {
    calls,
    speak(utterance) { calls.spoken.push({ text: utterance?.text, lang: utterance?.lang, rate: utterance?.rate, pitch: utterance?.pitch }); },
    cancel() { calls.cancelled++; },
    pause() { calls.paused++; },
    resume() { calls.resumed++; },
  };
}

/** Constructor de locuciones falso (SpeechSynthesisUtterance). */
class FakeUtterance {
  constructor(text) { this.text = String(text); }
}

/**
 * Constructor de reconocimiento falso.
 * @param {{denied?: boolean}} config `denied` simula permisos denegados.
 */
function createFakeRecognitionClass(config = {}) {
  const instances = [];
  class FakeRecognition {
    constructor() {
      this.started = false;
      instances.push(this);
    }
    start() {
      if (config.denied) {
        const error = new Error('Permiso de micrófono denegado');
        error.name = 'not-allowed';
        throw error;
      }
      this.started = true;
    }
    abort() { this.started = false; }
    /** El oráculo pronuncia una transcripción (doble del arnés). */
    speak(transcript) {
      this.onresult?.({ results: [[{ transcript }]] });
    }
  }
  FakeRecognition.instances = instances;
  return FakeRecognition;
}

/** Media query falsa de movimiento reducido. */
function createFakeMatchMedia({ reduce = false } = {}) {
  return (query) => ({
    media: query,
    matches: /prefers-reduced-motion/.test(query) ? reduce : false,
    addEventListener() {},
    removeEventListener() {},
  });
}

/** Recolector de errores: el equivalente a la consola del navegador. */
function createErrorCollector() {
  const collected = [];
  const onUncaught = (error) => collected.push(error?.message ?? String(error));
  process.on('uncaughtException', onUncaught);
  process.on('unhandledRejection', onUncaught);
  return { collected, stop: () => {
    process.off('uncaughtException', onUncaught);
    process.off('unhandledRejection', onUncaught);
  } };
}

// =====================================================================
// Adaptador de terminal
// =====================================================================

const scheduler = createFakeScheduler();
const documentFake = createFakeDocument();
const synthesis = createFakeSynthesis();
const collector = createErrorCollector();

const environment = {
  label: 'terminal (Node)',
  realFrames: false,
  document: documentFake,
  elementFactory: (tagName) => createFakeElement(tagName, documentFake),
  createCanvas() {
    const canvas = createFakeElement('canvas', documentFake);
    return { canvas, ctx: canvas.__ctx };
  },
  raf: scheduler.raf,
  caf: scheduler.caf,
  runFrames: (frames, stepMs) => scheduler.run(frames, stepMs),
  now: scheduler.now,
  sleep: (ms) => scheduler.sleep(ms),
  matchMedia: (query, options) => createFakeMatchMedia(options)(query),
  createRecognitionClass: (config) => createFakeRecognitionClass(config),
  synthesis,
  Utterance: FakeUtterance,
  collectedErrors: collector.collected,
  log: (line) => console.log(line),
};

let report;
try {
  report = await runSimulatorProtocol({ environment });
} catch (error) {
  console.log(`  FATAL — el protocolo no pudo ejecutarse: ${error.message}`);
  console.log('== RESUMEN == Fase roja: el protocolo aún no existe.');
  process.exit(1);
} finally {
  collector.stop();
}

console.log('\n== RESUMEN DEL PROTOCOLO EN TERMINAL ==');
console.log(`Casos completos: ${report.summary.complete} · parciales: ${report.summary.partial} · fallidos: ${report.summary.failed}`);
console.log(`Errores no capturados: ${report.errors.length}`);
console.log(`VEREDICTO: ${report.verdict}`);
process.exit(report.exitCode);
