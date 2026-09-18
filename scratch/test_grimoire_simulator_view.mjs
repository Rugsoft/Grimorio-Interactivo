/**
 * test_grimoire_simulator_view.mjs — Arnés TDD de la Tarea 5.1 (TASKS-05).
 *
 * Estrategia TDD: este script se escribe ANTES que la implementación.
 * Verifica, sobre `public/assets/js/views/grimoireSimulatorView.js`:
 *   [1] Montaje y estructura (RF-01.1, RF-05.3, RF-06.4): el tomo, la Cámara
 *       de Conjuración, los sellos ceremoniales, el conmutador de catálogo,
 *       la bitácora y la región viva `aria-live="polite"`.
 *   [2] Segmentación del catálogo (RF-01.2): Tomo Canónico público y
 *       «Mis Ensayos Arcanos» privado con degradación ante 401.
 *   [3] Hojear el tomo (RF-01.3/01.4) y persistencia del maniquí (RF-02.3),
 *       incluidos los gestos táctiles.
 *   [4] Invocación por clic (RF-03, RF-04.5, RF-05.1, RF-05.3, RF-06.4):
 *       bus `grimoire:cast-spell`, partículas, impacto sobre el maniquí,
 *       textos flotantes escalonados, bitácora y anuncio accesible.
 *   [5] Restaurar Maniquí (RF-02.6): 500 PV, barreras y estados disipados,
 *       bitácora limpia y bus `grimoire:dummy-reset`.
 *   [6] Voz (RF-04.2/04.3/04.4): declamación del cántico, invocación por
 *       micrófono, bus `grimoire:speech-triggered` y sellos en reposo.
 *   [7] Rendimiento y sensibilidad (RF-06.1/06.2/06.3): bucle de escena,
 *       suspensión por visibilidad, auto-throttle y movimiento reducido.
 *
 * Constitución:
 *   - Artículo I: ES Modules nativos; dobles inyectables, cero dependencias.
 *   - Artículo IV/V: leyendas solemnes castellanas; API en inglés camelCase.
 *
 * Uso: node scratch/test_grimoire_simulator_view.mjs
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

// =====================================================================
// Dobles del navegador (Dogma Vanilla: ni jsdom ni frameworks)
// =====================================================================

/** Contexto 2D falso que registra cada trazado sin dibujar nada. */
function createFakeContext() {
  const operations = [];
  const record = (name, args) => operations.push({ name, args: [...args] });
  const noop = (name) => (...args) => record(name, args);
  const ctx = {
    operations,
    canvas: null,
    globalAlpha: 1,
    fillStyle: '#000',
    strokeStyle: '#000',
    font: '',
    textAlign: 'left',
    textBaseline: 'alphabetic',
    lineWidth: 1,
    save: noop('save'),
    restore: noop('restore'),
    beginPath: noop('beginPath'),
    closePath: noop('closePath'),
    moveTo: noop('moveTo'),
    lineTo: noop('lineTo'),
    arc: noop('arc'),
    rect: noop('rect'),
    fill: noop('fill'),
    stroke: noop('stroke'),
    fillRect: noop('fillRect'),
    clearRect: noop('clearRect'),
    strokeRect: noop('strokeRect'),
    fillText: noop('fillText'),
    strokeText: noop('strokeText'),
    translate: noop('translate'),
    rotate: noop('rotate'),
    scale: noop('scale'),
    setLineDash: noop('setLineDash'),
    setTransform: noop('setTransform'),
    createRadialGradient: () => ({ addColorStop: noop('addColorStop') }),
    createLinearGradient: () => ({ addColorStop: noop('addColorStop') }),
    measureText: (text) => ({ width: String(text ?? '').length * 7 }),
  };
  return ctx;
}

/** Elemento DOM simulado (innerHTML prohibido, AGENTS.md 6.1). */
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
    hidden: false,
    parentElement: null,
    appendedTo: null,
    setAttribute(name, value) { this.attributes[name] = String(value); },
    getAttribute(name) { return name in this.attributes ? this.attributes[name] : null; },
    hasAttribute(name) { return name in this.attributes; },
    removeAttribute(name) { delete this.attributes[name]; },
    addEventListener(name, listener) { (this.listeners[name] ??= []).push(listener); },
    removeEventListener(name, listener) {
      this.listeners[name] = (this.listeners[name] ?? []).filter((l) => l !== listener);
    },
    // Los eventos del bus burbujean hasta el shell, como en el navegador.
    dispatchEvent(event) {
      let node = this;
      while (node) {
        (node.listeners?.[event?.type] ?? []).forEach((l) => l(event));
        if (event?.bubbles === false) break;
        node = node.parentElement ?? null;
      }
      return true;
    },
    // Despacho con burbujeo (los gestos llegan al contenedor de la vista).
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
        this._owner.classes.has(name) ? this._owner.classes.delete(name) : this._owner.classes.add(name);
        return this._owner.classes.has(name);
      }
      force ? this._owner.classes.add(name) : this._owner.classes.delete(name);
      return Boolean(force);
    },
  };
  // Lienzo falso con contexto grabador (el motor de partículas lo dibuja).
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

/** Documento falso con visibilidad conmutable (RF-06.3). */
function createFakeDocument() {
  const doc = {
    hidden: false,
    listeners: {},
    createElement(tagName) { return createFakeElement(tagName, doc); },
    addEventListener(name, listener) { (doc.listeners[name] ??= []).push(listener); },
    removeEventListener(name, listener) {
      doc.listeners[name] = (doc.listeners[name] ?? []).filter((l) => l !== listener);
    },
    dispatch(name, event = {}) {
      (doc.listeners[name] ?? []).forEach((l) => l({ type: name, ...event }));
    },
  };
  return doc;
}

/**
 * Planificador RAF falso: el arnés gobierna los cuadros (sin esperas).
 * El vuelo de cada cuadro adelanta también el reloj inyectado, de modo que
 * los temporizadores reales del motor (vuelos, FPS, temblor) transcurren.
 */
function createFakeRaf(clock = null) {
  let nextId = 1;
  let pending = new Map();
  let timestamp = 0;
  return {
    get pendingCount() { return pending.size; },
    get timestamp() { return timestamp; },
    raf(callback) { const id = nextId++; pending.set(id, callback); return id; },
    caf(id) { pending.delete(id); },
    /** Ejecuta `frames` cuadros avanzando `stepMs` milisegundos cada uno. */
    run(frames, stepMs = 16) {
      for (let i = 0; i < frames; i++) {
        timestamp += stepMs;
        clock?.advance(stepMs);
        const inFlight = [...pending.values()];
        pending = new Map();
        inFlight.forEach((callback) => callback(timestamp));
      }
    },
  };
}

/** Reloj falso coherente con el planificador (impactos sin esperas reales). */
function createFakeClock() {
  let millis = 1_700_000_000_000;
  return {
    now: () => millis,
    advance: (ms) => { millis += ms; },
    set: (value) => { millis = value; },
  };
}

/** Almacén local falso con semántica nativa (solo cadenas). */
function createFakeStorage() {
  const map = new Map();
  return {
    map,
    getItem: (key) => (map.has(key) ? map.get(key) : null),
    setItem: (key, value) => map.set(key, String(value)),
    removeItem: (key) => map.delete(key),
  };
}

/** Cliente del grimorio falso que registra cada consulta (RF-01.2). */
function createFakeGrimoireClient({ catalog = CATALOG, essays = [], essaysStatus = 200 } = {}) {
  const calls = [];
  return {
    calls,
    async fetchSpells(params = {}) {
      calls.push({ ...params });
      if (params.mode === 'essays' && essaysStatus !== 200) {
        return {
          success: false,
          status: essaysStatus,
          error: { code: 'UNAUTHENTICATED', message: 'Vínculo no reconocido para los ensayos.' },
        };
      }
      const spells = params.mode === 'essays' ? essays : catalog;
      return {
        success: true,
        status: 200,
        data: {
          totalSpells: spells.length,
          currentPage: 1,
          totalPages: Math.max(1, spells.length),
          hasPrevious: false,
          hasNext: spells.length > 1,
          spells,
        },
      };
    },
    async fetchSpellDetail(id) {
      return { success: true, status: 200, data: catalog.find((s) => s.id === id) ?? null };
    },
  };
}

/** Servicio de voz falso con dobles de síntesis y reconocimiento (RF-04). */
function createFakeSpeechService({ synthesis = true, recognition = true, matchByName = true } = {}) {
  const calls = { recited: [], listenings: 0, stopAll: 0 };
  let handlers = null;
  return {
    calls,
    reciteSpell(formula) { calls.recited.push(formula); return synthesis; },
    matchesSpellInvocation(transcript, spell) {
      if (!matchByName) return false;
      return String(transcript).toLowerCase().includes(String(spell?.name ?? '').toLowerCase());
    },
    startListening(spell, onMatched, onError = () => {}) {
      calls.listenings++;
      handlers = { spell, onMatched, onError };
      if (!recognition) {
        onError('El oráculo del sonido reposa en silencio: este navegador no sabe escuchar conjuros.');
        return false;
      }
      return true;
    },
    stopAll() { calls.stopAll++; handlers = null; return true; },
    isSynthesisSupported: () => synthesis,
    isRecognitionSupported: () => recognition,
    /** El arnés hace hablar al oráculo: transcripción reconocida. */
    emitTranscript(phrase) { handlers?.onMatched?.(handlers.spell, phrase); },
    /** El arnés deniega el permiso del micrófono. */
    emitError(message) { handlers?.onError?.(message); },
    get listening() { return handlers !== null; },
  };
}

/** Media query falsa de movimiento reducido (RF-06.1). */
function createFakeMotionQuery({ matches = false } = {}) {
  return { matches, addEventListener() {}, removeEventListener() {} };
}

// =====================================================================
// Herramientas de consulta del árbol simulado
// =====================================================================

function queryByClass(node, className, found = []) {
  for (const child of node.children ?? []) {
    if (child.classes?.has(className)) found.push(child);
    queryByClass(child, className, found);
  }
  return found;
}

function queryFirst(node, className) {
  return queryByClass(node, className, [])[0] ?? null;
}

function queryByTag(node, tagName, found = []) {
  for (const child of node.children ?? []) {
    if (child.tagName === String(tagName).toUpperCase()) found.push(child);
    queryByTag(child, tagName, found);
  }
  return found;
}

function buttonWithText(root, text) {
  return queryByTag(root, 'button', []).find((b) => b.textContent.trim() === text) ?? null;
}

// =====================================================================
// Catálogo de prueba (3 conjuros canónicos, uno de impacto mixto)
// =====================================================================

const CATALOG = [
  {
    id: 'spl_1', name: 'Ardor del Alba', circle: 3, elementalAffinity: 'fire', manaCost: 30,
    castingTime: 'action', areaType: 'singleTarget', rangeType: 'ranged', durationType: 'instant',
    magicSchool: 'evocation', hasVerbal: true, hasSomatic: true, hasMaterial: true,
    incantationFormula: '¡Llamas del alba, descended y consumid la penumbra!',
    description: 'Ascuas del alba que abrazan al blanco.',
    effects: { damage: 60, healing: 0, barrier: 0, crowdControlType: 'none' },
  },
  {
    id: 'spl_2', name: 'Marea de Escarcha', circle: 2, elementalAffinity: 'water', manaCost: 28,
    castingTime: 'bonus', areaType: 'cone', rangeType: 'touch', durationType: 'timed',
    magicSchool: 'abjuration', hasVerbal: true, hasSomatic: true, hasMaterial: false,
    incantationFormula: '¡Olas gélidas, envolvedme en vuestro abrazo!',
    description: 'Ondas de escarcha que calan hasta el hueso.',
    effects: { damage: 0, healing: 25, barrier: 0, crowdControlType: 'none' },
  },
  {
    id: 'spl_3', name: 'Sello de Tormenta', circle: 5, elementalAffinity: 'lightning', manaCost: 90,
    castingTime: 'ritual', areaType: 'sphere', rangeType: 'line', durationType: 'instant',
    magicSchool: 'evocation', hasVerbal: true, hasSomatic: true, hasMaterial: true,
    incantationFormula: '¡Runas del trueno, quebrad el velo del silencio!',
    description: 'Sello de rayos encadenados que descarga su furia sobre el señalado.',
    effects: { damage: 90, healing: 0, barrier: 30, crowdControlType: 'stun' },
  },
];

console.log('== ARNÉS TDD — Tarea 5.1: Vista principal del Simulador y Bus de Eventos ==');

let module;
try {
  module = await import('../public/assets/js/views/grimoireSimulatorView.js');
} catch (error) {
  console.log(`  FATAL — no se pudo importar grimoireSimulatorView.js: ${error.message}`);
  console.log('== RESUMEN == Fase roja: la implementación aún no existe.');
  process.exit(1);
}

const { createGrimoireSimulatorView } = module;
const { DUMMY_MAX_HEALTH } = await import('../public/assets/js/components/combatDummyComponent.js');

/** Monta la vista completa con dobles inyectables. */
function buildView(overrides = {}) {
  const doc = overrides.document ?? createFakeDocument();
  const clock = overrides.clock ?? createFakeClock();
  const raf = overrides.raf ?? createFakeRaf(clock);
  const storage = overrides.storage ?? createFakeStorage();
  const client = overrides.client ?? createFakeGrimoireClient();
  const speech = overrides.speech ?? createFakeSpeechService();
  const canvas = overrides.canvas ?? createFakeElement('canvas', doc);
  const host = overrides.host ?? createFakeElement('main', doc);
  const busEvents = [];
  const view = createGrimoireSimulatorView(host, {
    grimoireClient: client,
    speechService: speech,
    elementFactory: (tagName) => createFakeElement(tagName, doc),
    document: doc,
    canvas,
    ctx: canvas.__ctx,
    raf: raf.raf,
    caf: raf.caf,
    clock,
    storage,
    motionQuery: overrides.motionQuery ?? createFakeMotionQuery(),
    ...overrides.options,
  });
  for (const name of ['grimoire:cast-spell', 'grimoire:dummy-reset', 'grimoire:speech-triggered', 'grimoire:page-change']) {
    host.addEventListener(name, (event) => busEvents.push({ name, detail: event?.detail ?? null }));
  }
  return { view, host, doc, raf, clock, storage, client, speech, canvas, busEvents };
}

// =====================================================================
// [1] Montaje y estructura (RF-01.1, RF-05.3, RF-06.4)
// =====================================================================
console.log('\n[1] Montaje y estructura de la vista');

const scene = buildView();
await scene.view.render();

const simulator = queryFirst(scene.host, 'grimoire-simulator');
assertCondition(Boolean(simulator), 'render() monta la sección raíz del simulador');
assertCondition(simulator?.tagName === 'SECTION', 'la raíz es una sección semántica');

const liveRegion = queryFirst(simulator ?? scene.host, 'grimoire-simulator__announcer');
assertCondition(Boolean(liveRegion), 'existe una región viva para los anuncios accesibles (RF-06.4)');
assertCondition(liveRegion?.getAttribute('aria-live') === 'polite', 'la región viva declara aria-live="polite"');
assertCondition(liveRegion?.getAttribute('role') === 'status', 'la región viva porta role="status"');

const touchSeal = buttonWithText(simulator ?? scene.host, 'Invocar Conjuro');
const listenSeal = buttonWithText(simulator ?? scene.host, 'Escuchar Cántico');
const micSeal = buttonWithText(simulator ?? scene.host, 'Micrófono de Conjuración');
const restoreSeal = buttonWithText(simulator ?? scene.host, 'Restaurar Maniquí');
assertCondition(Boolean(touchSeal), 'el sello táctil «Invocar Conjuro» se exhibe (RF-04.5)');
assertCondition(Boolean(listenSeal), 'el sello «Escuchar Cántico» se exhibe (RF-04.2)');
assertCondition(Boolean(micSeal), 'el sello «Micrófono de Conjuración» se exhibe (RF-04.3)');
assertCondition(Boolean(restoreSeal), 'el botón «Restaurar Maniquí» se exhibe (RF-02.6)');
assertCondition(touchSeal?.tagName === 'BUTTON', 'los sellos ceremoniales son botones nativos');

const canonicalOption = buttonWithText(simulator ?? scene.host, 'Tomo Canónico');
const essaysOption = buttonWithText(simulator ?? scene.host, 'Mis Ensayos Arcanos');
assertCondition(Boolean(canonicalOption) && Boolean(essaysOption), 'el conmutador ofrece Catálogo Canónico y Ensayos (RF-01.2)');

const controls = queryFirst(simulator ?? scene.host, 'grimoire-simulator__controls');
assertCondition(controls?.children.length >= 6, 'los controles agrupan sellos y conmutador en una barra');

assertCondition(Boolean(queryFirst(simulator ?? scene.host, 'grimoire-book')), 'el Tomo Arcano se monta en la vista (RF-01.1)');
assertCondition(Boolean(queryFirst(simulator ?? scene.host, 'arcane-canvas')), 'la Cámara aloja el lienzo de conjuración (RF-02.1)');
assertCondition(Boolean(queryFirst(simulator ?? scene.host, 'combat-dummy__figure')), 'la Cámara aloja el Maniquí Arcano (RF-02.1)');
assertCondition(Boolean(queryFirst(simulator ?? scene.host, 'grimoire-simulator__logbook')), 'la Bitácora de Pruebas se exhibe como panel (RF-05.3)');

const noInnerHtml = await import('../public/assets/js/views/grimoireSimulatorView.js').then(
  () => true,
  () => false,
);
assertCondition(noInnerHtml, 'el árbol se construye sin innerHTML (la trampa del DOM falso no saltó)');
assertCondition(typeof scene.view.getState === 'function' && scene.view.getState().mode === 'canonical', 'el catálogo arranca en el Tomo Canónico (RF-01.2)');

// =====================================================================
// [2] Segmentación del catálogo (RF-01.2)
// =====================================================================
console.log('\n[2] Segmentación del catálogo: Canónico vs. Ensayos');

const canonicalCalls = scene.client.calls.filter((c) => c.mode === 'canonical');
assertCondition(canonicalCalls.length >= 1, 'render() solicita el catálogo canónico al santuario');
assertCondition(scene.view.getState().totalPages === 3, 'el tomo se monta con los 3 conjuros recibidos');

const essays = buildView({
  client: createFakeGrimoireClient({ essays: [CATALOG[0]] }),
});
await essays.view.render();
await essays.view.switchCatalog('essays');
assertCondition(essays.client.calls.some((c) => c.mode === 'essays'), 'el conmutador solicita el catálogo mode=essays (RF-01.2)');
assertCondition(essays.view.getState().mode === 'essays', 'la vista conmuta al tomo privado de ensayos');
assertCondition(essays.view.getState().totalPages === 1, 'el tomo privado exhibe los ensayos del autor');

const forbidden = buildView({ client: createFakeGrimoireClient({ essaysStatus: 401 }) });
await forbidden.view.render();
await forbidden.view.switchCatalog('essays');
assertCondition(forbidden.view.getState().mode === 'canonical', 'ante 401 la vista regresa al Tomo Canónico (RF-01.2)');
assertCondition(
  forbidden.view.getAnnouncements().some((m) => /ensayos|umbral|vínculo/i.test(m)),
  'el acceso vedado a los ensayos se anuncia con un aviso solemne (RF-01.2)',
);
assertCondition(forbidden.view.getState().totalPages === 3, 'el tomo canónico permanece intacto tras el rechazo');
assertCondition(
  forbidden.client.calls.filter((c) => c.mode === 'canonical').length >= 1,
  'el refugio reclama el catálogo canónico sin exponer jamás un borrador (RF-01.2)',
);
assertCondition(
  forbidden.view.getState().currentSpell?.name === 'Ardor del Alba',
  'la lámina del refugio ilumina un conjuro canónico (RF-01.2)',
);
assertCondition(
  !forbidden.view.getState().currentSpell?.status || forbidden.view.getState().currentSpell.status === 'validated',
  'ningún ensayo privado se filtra en el refugio público (RF-01.2)',
);

const emptyEssays = buildView({ client: createFakeGrimoireClient({ essays: [] }) });
await emptyEssays.view.render();
await emptyEssays.view.switchCatalog('essays');
assertCondition(Boolean(queryFirst(emptyEssays.host, 'grimoire-book__empty')), 'los ensayos vacíos muestran el pergamino virgen (RF-01.4)');
assertCondition(
  emptyEssays.view.getState().currentSpell === null,
  'sin conjuros no hay página iluminada (RF-01.4)',
);

// =====================================================================
// [3] Hojear el tomo y persistencia del maniquí (RF-01.3/01.4, RF-02.3)
// =====================================================================
console.log('\n[3] Navegación del tomo y persistencia del maniquí');

const browsing = buildView();
await browsing.view.render();
const firstSpell = browsing.view.getState().currentSpell;
assertCondition(firstSpell?.name === 'Ardor del Alba', 'la vista abre en el primer conjuro del catálogo (RF-01.5)');

await browsing.view.castCurrentSpell({ triggerMethod: 'click' });
browsing.raf.run(140, 16); // el vuelo alcanza al maniquí
const woundAfterCast = browsing.view.getState().dummy.health;
assertCondition(woundAfterCast < 500, 'el impacto hiere al maniquí antes de hojear');

await browsing.view.nextPage();
assertCondition(browsing.view.getState().currentSpell?.name === 'Marea de Escarcha', 'nextPage() presenta el conjuro siguiente (RF-01.3)');
assertCondition(browsing.view.getState().dummy.health === woundAfterCast, 'el daño residual se conserva al hojear (RF-02.3)');
assertCondition(
  browsing.view.getAnnouncements().some((m) => /página 2 de 3/i.test(m)),
  'la transición anuncia la página y el conjuro (RF-01.3)',
);
assertCondition(
  browsing.view.getState().floatingTexts === 0,
  'los textos flotantes se purgan al cambiar de lámina (RF-05.2)',
);

await browsing.view.previousPage();
assertCondition(browsing.view.getState().currentSpell?.name === 'Ardor del Alba', 'previousPage() vuelve al conjuro anterior');
assertCondition(browsing.view.getState().prevDisabled === true, 'en el primer conjuro la flecha anterior se desvanece (RF-01.4)');

// Gesto táctil: un deslizamiento horizontal pasa página (RF-01.3).
const book = queryFirst(browsing.host, 'grimoire-book');
book.dispatch('touchstart', { touches: [{ clientX: 300, clientY: 200 }] });
book.dispatch('touchend', { changedTouches: [{ clientX: 120, clientY: 205 }] });
assertCondition(browsing.view.getState().currentSpell?.name === 'Marea de Escarcha', 'el gesto táctil pasa página (RF-01.3)');
book.dispatch('touchstart', { touches: [{ clientX: 120, clientY: 200 }] });
book.dispatch('touchend', { changedTouches: [{ clientX: 310, clientY: 202 }] });
assertCondition(browsing.view.getState().currentSpell?.name === 'Ardor del Alba', 'el gesto inverso retrocede de página (RF-01.3)');

// =====================================================================
// [4] Invocación por clic: bus, partículas, impacto, bitácora y anuncio
// =====================================================================
console.log('\n[4] Invocación por clic sobre el maniquí');

const casting = buildView();
await casting.view.render();
await casting.view.nextPage();
await casting.view.nextPage();
assertCondition(casting.view.getState().currentSpell?.name === 'Sello de Tormenta', 'la vista alcanza el conjuro de Círculo V');

const healthBeforeCast = casting.view.getState().dummy.health;
await casting.view.castCurrentSpell({ triggerMethod: 'click' });
const castEvents = casting.busEvents.filter((e) => e.name === 'grimoire:cast-spell');
assertCondition(castEvents.length === 1, 'la invocación emite un único `grimoire:cast-spell` en el bus');
assertCondition(castEvents[0]?.detail?.triggerMethod === 'click', 'el evento declara el método de disparo «click»');
assertCondition(castEvents[0]?.detail?.spell?.name === 'Sello de Tormenta', 'el evento porta el conjuro invocado');
assertCondition(casting.view.getState().activeParticles > 0, 'el lienzo emite partículas para el conjuro (RF-03)');
assertCondition(casting.view.getState().dummy.health === healthBeforeCast, 'el impacto aún no ha ocurrido: las partículas viajan (RF-05.1)');

// Corrector de impacto: con la detección por proximidad la deflagración
// esférica detona en el primer cuadro (nace sobre el blanco); los rótulos
// escalonados (vida 1600 ms + retardo de control 150 ms) se muestrean
// vivos antes de su expiración natural.
casting.raf.run(60, 16);
const dummyAfterImpact = casting.view.getState().dummy;
assertCondition(dummyAfterImpact.health === 500 - 90, 'el impacto con barrera descontó 90 PV al maniquí (RF-02.4)');
assertCondition(casting.view.getState().floatingTexts >= 3, 'el impacto mixto escalona sus rótulos: daño, barrera y control (RF-05.2)');

const logEntries = casting.view.getState().logEntries;
assertCondition(logEntries.length === 1, 'la bitácora inscribe el impacto (RF-05.3)');
assertCondition(logEntries[0]?.spellName === 'Sello de Tormenta', 'la entrada de bitácora nombra el conjuro');
assertCondition(logEntries[0]?.damageDealt === 90, 'la entrada registra el desglose de efectos');
assertCondition(typeof logEntries[0]?.timestamp === 'string', 'la entrada porta su marca temporal (plan 2.2)');
assertCondition(casting.storage.map.has('grimorio_test_log_v1'), 'la bitácora persiste en localStorage (RF-05.3)');

const announcements = casting.view.getAnnouncements();
const impactAnnouncement = announcements.find((m) => m.startsWith('Lanzado Sello de Tormenta: inflige 90 puntos de daño'));
assertCondition(
  Boolean(impactAnnouncement) && impactAnnouncement.endsWith('al maniquí de pruebas'),
  'el anuncio accesible sigue la fórmula de RF-06.4',
);
assertCondition(
  /barrera de 30 puntos/.test(impactAnnouncement ?? ''),
  'el anuncio del impacto mixto relata también la barrera alzada (RF-06.4)',
);
const castingAnnouncer = queryFirst(casting.host, 'grimoire-simulator__announcer');
assertCondition(castingAnnouncer?.textContent === announcements[announcements.length - 1], 'la región viva refleja el último anuncio');
assertCondition(casting.view.getState().logbookEntries >= 1, 'el panel de la bitácora se repinta con el impacto');
assertCondition(casting.view.getState().activeParticles <= 200, 'la población nunca excede el techo de 200 partículas (RF-03.4)');

// Impactos sucesivos: la bitácora retiene exactamente cinco entradas.
for (let i = 0; i < 6; i++) {
  await casting.view.castCurrentSpell({ triggerMethod: 'click' });
  casting.raf.run(140, 16);
}
assertCondition(casting.view.getState().logEntries.length === 5, 'la bitácora retiene un máximo de 5 impactos (RF-05.3)');

// Conjuro de curación: anuncia la restauración de salud.
const healing = buildView();
await healing.view.render();
await healing.view.nextPage();
await healing.view.castCurrentSpell({ triggerMethod: 'click' });
healing.raf.run(140, 16);
assertCondition(
  healing.view.getAnnouncements().some((m) => /restaura 25 puntos de salud/i.test(m)),
  'un conjuro de curación anuncia la salud restaurada (RF-06.4)',
);

// =====================================================================
// [5] Restaurar Maniquí (RF-02.6)
// =====================================================================
console.log('\n[5] Restaurar Maniquí');

const restoring = buildView();
await restoring.view.render();
await restoring.view.castCurrentSpell({ triggerMethod: 'click' });
restoring.raf.run(140, 16);
await restoring.view.restoreDummy();

const restoredState = restoring.view.getState();
assertCondition(restoredState.dummy.health === 500, '«Restaurar Maniquí» restablece los 500 PV (RF-02.6)');
assertCondition(restoredState.dummy.barrier === 0, 'las barreras quedan disipadas');
assertCondition(restoredState.dummy.activeCC === null, 'los estados alterados se disipan');
assertCondition(restoredState.logEntries.length === 0, 'la bitácora de impactos queda limpia');
const persistedAfterRestore = JSON.parse(restoring.storage.getItem('grimorio_test_log_v1') ?? '{}');
assertCondition(
  Array.isArray(persistedAfterRestore.logs) && persistedAfterRestore.logs.length === 0,
  'la limpieza alcanza al almacenamiento local persistido (plan 2.2)',
);
assertCondition(restoredState.floatingTexts === 0, 'los textos flotantes se purgan al restaurar');
assertCondition(
  restoring.busEvents.some((e) => e.name === 'grimoire:dummy-reset' && e.detail?.reason === 'user'),
  'el bus recibe `grimoire:dummy-reset` con motivo «user» (RF-02.6)',
);
assertCondition(
  restoring.view.getAnnouncements().some((m) => /restaurad/i.test(m)),
  'la restauración se anuncia en la región viva',
);

// =====================================================================
// [6] Voz: declamación, invocación y degradación grácil (RF-04)
// =====================================================================
console.log('\n[6] Recitado mágico y sellos vocales');

const voiced = buildView();
await voiced.view.render();
await voiced.view.reciteCurrentSpell();
assertCondition(
  voiced.speech.calls.recited[0] === CATALOG[0].incantationFormula,
  '«Escuchar Cántico» declama la fórmula litúrgica del conjuro vigente (RF-04.2)',
);

await voiced.view.toggleMicrophone();
assertCondition(voiced.speech.calls.listenings === 1, 'el sello del micrófono arranca la escucha (RF-04.3)');
voiced.speech.emitTranscript('ardor del alba');
await voiced.raf.run(4, 16);
const voiceEvents = voiced.busEvents.filter((e) => e.name === 'grimoire:speech-triggered');
assertCondition(voiceEvents.length === 1, 'el reconocimiento emite `grimoire:speech-triggered` (bus del plan 4.1)');
assertCondition(voiceEvents[0]?.detail?.recognizedPhrase === 'ardor del alba', 'el evento porta la frase reconocida');
assertCondition(voiceEvents[0]?.detail?.spellMatched === true, 'el evento confirma la coincidencia fonética');
assertCondition(
  voiced.busEvents.some((e) => e.name === 'grimoire:cast-spell' && e.detail?.triggerMethod === 'voice'),
  'la transcripción válida invoca con el método «voice» (RF-04.3)',
);

const unheard = buildView({ speech: createFakeSpeechService({ matchByName: false }) });
await unheard.view.render();
await unheard.view.toggleMicrophone();
unheard.speech.emitTranscript('palabras ajenas al grimorio');
assertCondition(
  unheard.view.getAnnouncements().some((m) => /no reconoció las palabras rituales/i.test(m)),
  'una transcripción ajena se declara no reconocida sin conjurar (RF-04.3)',
);
assertCondition(
  !unheard.busEvents.some((e) => e.name === 'grimoire:cast-spell'),
  'el oráculo confundido no desata conjuro alguno (RF-04.3)',
);
assertCondition(
  unheard.busEvents.some((e) => e.name === 'grimoire:speech-triggered' && e.detail?.spellMatched === false),
  'el bus recibe `grimoire:speech-triggered` con la coincidencia fallida (plan 4.1)',
);

// --- Caso Límite 3 (SPEC-05): maniquí inalterado + bruma de disipación ---
await unheard.raf.run(10, 16); // el latido de 100 ms de la bruma vence; nace en el siguiente cuadro
const mistVisible = unheard.view._floatingTextsProbe().find((t) => t.mist === true);
assertCondition(
  mistVisible !== undefined && /disipado en el éter/.test(mistVisible.text),
  'la palabra no reconocida alza la bruma de disipación con su leyenda canónica (Caso Límite 3)',
);
assertCondition(
  mistVisible !== undefined && mistVisible.color === '#9aa7b8',
  'la bruma viste el gris-azulada del éter (Caso Límite 3)',
);
assertCondition(
  unheard.view.getState().dummy.health === DUMMY_MAX_HEALTH
    && unheard.view.getState().dummy.activeCC === null
    && unheard.view.getState().dummy.barrier === 0,
  'el maniquí permanece INALTERADO ante la palabra extraviada (Caso Límite 3)',
);
await unheard.raf.run(130, 16); // ≈2080 ms: la bruma se disuelve (vida 2 s)
assertCondition(
  unheard.view._floatingTextsProbe().every((t) => t.mist !== true),
  'la bruma se disipa del éter a su plazo (2 s, Caso Límite 3)',
);

const deaf = buildView({ speech: createFakeSpeechService({ recognition: false, synthesis: false }) });
await deaf.view.render();
assertCondition(deaf.view.getState().voiceSealResting === true, 'sin soporte vocal el sello queda en reposo ceremonial (RF-04.4)');
const deafMic = buttonWithText(deaf.host, 'Micrófono de Conjuración');
assertCondition(deafMic?.getAttribute('aria-disabled') === 'true', 'el sello en reposo se anuncia como no disponible (RF-04.4)');
await deaf.view.toggleMicrophone();
assertCondition(deaf.speech.calls.listenings === 1, 'pulsar el sello en reposo la invoca una vez para informar (sin lanzar errores)');
assertCondition(
  deaf.view.getAnnouncements().some((m) => /oráculo|silencio/i.test(m)),
  'la falta de soporte vocal se comunica con un aviso solemne (RF-04.4)',
);
deaf.view.getState();
await deaf.view.castCurrentSpell({ triggerMethod: 'click' });
assertCondition(deaf.view.getState().tactileSealEnabled === true, 'el sello táctil permanece habilitado sin voz (RF-04.5)');

const denied = buildView();
await denied.view.render();
await denied.view.toggleMicrophone();
denied.speech.emitError('El oráculo del sonido ha sido silenciado: concede permiso al micrófono para conjurar con la voz.');
assertCondition(
  denied.view.getState().voiceSealResting === true,
  'la denegación del micrófono deja el sello en reposo (RF-04.4)',
);
assertCondition(
  denied.view.getAnnouncements().some((m) => /micrófono/i.test(m)),
  'el aviso de permiso denegado llega a la región viva (RF-04.4)',
);

// ---------------------------------------------------------------------------
// [6b] Fallo imprevisto de la invocación (RF-05.5, criterio del DoD):
//      SI el motor de manifestación lanza una excepción, ENTONCES la vista
//      anuncia el fallo con solemnidad por la región viva, no propaga la
//      excepción y el banco de pruebas queda operativo para un nuevo intento.
// ---------------------------------------------------------------------------
console.log('\n[6b] Fallo imprevisto de la invocación (RF-05.5)');

// Sabotaje honesto del motor: un conjuro con Círculo fuera del canon (1-5)
// hace que `pool.emitSpell` lance RangeError dentro de `castSpell` — el
// fallo imprevisto que el try/catch de la vista debe absorber.
const BROKEN_SPELL = { ...CATALOG[0], id: 'spl_broken', name: 'Grieta del Cielo', circle: 99 };
const failing = buildView({ client: createFakeGrimoireClient({ catalog: [...CATALOG, BROKEN_SPELL] }) });
await failing.view.render();
// Navegar hasta la página del conjuro saboteado (la última).
for (let i = 0; i < CATALOG.length; i++) await failing.view.nextPage();

const healthBeforeFailure = failing.view.getState().dummy.health;
let castOutcome = 'no-thrown';
try {
  await failing.view.castCurrentSpell({ triggerMethod: 'click' });
} catch (error) {
  castOutcome = 'thrown'; // RF-05.5 prohíbe propagar excepciones silenciosas.
}
assertCondition(castOutcome === 'no-thrown', 'el fallo del motor NO se propaga como excepción (RF-05.5)');
assertCondition(
  failing.view.getAnnouncements().some((m) => /se dispersó sin alcanzar el maniquí/i.test(m)),
  'el fallo se anuncia con solemnidad por la región viva (RF-05.5)',
);
assertCondition(
  failing.view.getState().dummy.health === healthBeforeFailure,
  'el maniquí queda intacto: sin impacto fantasma del conjuro fallido (RF-05.5)',
);
assertCondition(
  failing.view.getState().logEntries.length === 0,
  'la bitácora no inscribe el conjuro fallido (RF-05.5)',
);

// El banco queda operativo: volviendo al primer conjuro, el nuevo intento prospera.
for (let i = 0; i < CATALOG.length; i++) await failing.view.previousPage();
const castOk = await failing.view.castCurrentSpell({ triggerMethod: 'click' });
assertCondition(castOk === true, 'tras un fallo, el banco de pruebas queda operativo para un nuevo intento (RF-05.5)');

// =====================================================================
// [7] Rendimiento, movimiento reducido y visibilidad (RF-06)
// =====================================================================
console.log('\n[7] Rendimiento, sensibilidad y suspensión');

const perf = buildView();
await perf.view.render();
assertCondition(perf.view.getState().sceneRunning === true, 'el bucle de escena de la vista arranca con la vista (RNF-01)');
assertCondition(perf.view.getState().canvasRunning === true, 'el bucle del lienzo arranca en armonía (RF-06.2)');

// Dos ventanas de 1 s con un solo cuadro: FPS < 30 → modo de bajo consumo.
perf.raf.run(2, 1000);
assertCondition(perf.view.getState().densityScale === 0.4, 'dos muestras lentas activan el modo de bajo consumo (RF-06.2)');

// Suspensión por visibilidad (RF-06.3).
perf.doc.hidden = true;
perf.doc.dispatch('visibilitychange');
assertCondition(perf.view.getState().sceneRunning === false, 'la pestaña oculta suspende el bucle de escena (RF-06.3)');
assertCondition(perf.view.getState().canvasRunning === false, 'la pestaña oculta suspende el lienzo (RF-06.3)');
assertCondition(perf.speech.calls.stopAll >= 1, 'la pestaña oculta silencia la voz (RF-06.3)');
perf.doc.hidden = false;
perf.doc.dispatch('visibilitychange');
assertCondition(perf.view.getState().sceneRunning === true, 'recuperar la visibilidad reanuda la escena (RF-06.3)');

// Movimiento reducido (RF-06.1): cero partículas e impacto inmediato.
const calm = buildView({ motionQuery: createFakeMotionQuery({ matches: true }) });
await calm.view.render();
const calmHealth = calm.view.getState().dummy.health;
await calm.view.castCurrentSpell({ triggerMethod: 'click' });
assertCondition(calm.view.getState().activeParticles === 0, 'con movimiento reducido no se emiten proyectiles (RF-06.1)');
assertCondition(calm.view.getState().dummy.health < calmHealth, 'el impacto se resuelve de inmediato sin trayectoria (RF-06.1)');
assertCondition(calm.view.getState().reducedMotion === true, 'la vista declara su respeto al movimiento reducido (RF-06.1)');
assertCondition(calm.view.getState().tremoring === false, 'con movimiento reducido no hay temblor de página (RF-06.1)');

// Sin movimiento reducido, el impacto estremece la Cámara y luego se serena.
const tremoring = buildView();
await tremoring.view.render();
await tremoring.view.castCurrentSpell({ triggerMethod: 'click' });
assertCondition(tremoring.view.getState().tremoring === true, 'el impacto estremece la página de la Cámara (RF-05.1)');
tremoring.raf.run(20, 16);
assertCondition(tremoring.view.getState().tremoring === false, 'el temblor se serena pasados sus milisegundos (RF-05.1)');

// Desmontaje limpio.
calm.view.destroy();
assertCondition(calm.host.children.length === 0, 'destroy() desmonta la vista por completo');
assertCondition(calm.view.getState().sceneRunning === false, 'destroy() detiene el bucle de escena');
assertCondition(calm.view.getState().canvasRunning === false, 'destroy() detiene el bucle del lienzo');

// =====================================================================
// Resumen
// =====================================================================
console.log('\n== RESUMEN ==');
console.log(`Asertos superados: ${assertsPassed}, fallidos: ${assertsFailed}`);
if (assertsFailed === 0) {
  console.log('RESULTADO: EXITO — La vista del Simulador orquesta el tomo, el lienzo, el maniquí y la voz (Tarea 5.1).');
  process.exit(0);
}
console.log('RESULTADO: FALLO — Revisa los asertos marcados.');
process.exit(1);
