/**
 * test_aura_page_persistence.mjs — Arnés TDD de la Tarea 4.1 (TASKS-06).
 *
 * Estrategia TDD: este script se escribe ANTES que la implementación.
 * Verifica la integración del Códice Elemental (SPEC-06) con la Cámara de
 * Conjuración (SPEC-05) en `grimoireSimulatorView.js`:
 *   [0] Superficie: la vista expone el estado elemental del maniquí
 *       (TargetAuraState) y monta el halo del aura.
 *   [1] Imbuición (RF-02.1): el primer impacto elemental imbuye el aura
 *       de 5 s y la exhibe sobre el maniquí.
 *   [2] Persistencia al hojear (RF-02.3): el aura y su cuenta atrás
 *       sobreviven intactas a los cambios de página del Tomo.
 *   [3] E2E «Hecho cuando» (RF-03.1, RF-04.1): Agua en página 1, hojear a
 *       página 3, Rayo → *Electrocución Fluida* (daño ×1.5 = ceil) y
 *       concesión de inmunidad anti-stunlock.
 *   [4] Salvaguarda (RF-05.2/05.3): un segundo Hard CC dentro de los 3 s
 *       se suprime (daño íntegro, sin atadura); fuera de la ventana vuelve
 *       a aplicarse. La duración de la atadura la gobierna el Códice.
 *   [5] Refresco (RF-02.4) y neblina sin atadura (Vaporización).
 *   [6] Catalizador (RF-03.2): Arcano Puro amplifica ×1.25 y consume.
 *   [7] Sobreescritura (RF-03.3): elemento no reactivo imbuye nuevo aura
 *       con daño íntegro.
 *   [8] Fractura Basáltica: la trituración anula la barrera sin excedente.
 *   [9] «Restaurar Maniquí» disipa el aura (RF-02.6).
 *  [10] destroy() desmonta el aura y apaga sus escuchas.
 *
 * Constitución:
 *   - Artículo I: ES Modules nativos; dobles inyectables, cero dependencias.
 *   - Artículo II: los factores (×1.5, ×1.25, 3 s, 5 s) se leen del Códice.
 *   - Artículo V: API en inglés camelCase; comentarios en castellano.
 *
 * Uso: node scratch/test_aura_page_persistence.mjs
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
}

/**
 * Elemento DOM simulado (innerHTML prohibido, AGENTS.md 6.1).
 * El setAttribute('class', …) espeja al conjunto de clases para que
 * querySelector por clase funcione igual que en el DOM real.
 */
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
    setAttribute(name, value) {
      this.attributes[name] = String(value);
      if (name === 'class') {
        this.classes = new Set(String(value).split(/\s+/).filter(Boolean));
      }
    },
    getAttribute(name) { return name in this.attributes ? this.attributes[name] : null; },
    hasAttribute(name) { return name in this.attributes; },
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
    // Búsqueda por clases (suficiente para los selectores del aura).
    querySelectorAll(selector) {
      const wanted = String(selector).split('.').filter(Boolean);
      const matches = (node) => wanted.every((c) => node.classes?.has(c));
      const results = [];
      const walk = (node) => {
        for (const child of node.children ?? []) {
          if (matches(child)) results.push(child);
          walk(child);
        }
      };
      walk(this);
      return results;
    },
    querySelector(selector) { return this.querySelectorAll(selector)[0] ?? null; },
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
      const has = this._owner.classes.has(name);
      const want = force === undefined ? !has : Boolean(force);
      want ? this._owner.classes.add(name) : this._owner.classes.delete(name);
      return want;
    },
  };
  // Lienzo falso pequeño: vuelos de ≈460 ms para gobernar las ventanas de 5 s.
  if (element.tagName === 'CANVAS') {
    element.width = 200;
    element.height = 100;
    element.clientWidth = 200;
    element.clientHeight = 100;
    const context = createFakeContext();
    context.canvas = element;
    element.getContext = () => context;
    element.__ctx = context;
  }
  return element;
}

/** Documento falso con fábrica SVG (el aura dibuja su anillo con SVG). */
function createFakeDocument() {
  const doc = {
    hidden: false,
    listeners: {},
    createElement(tagName) { return createFakeElement(tagName, doc); },
    createElementNS(_namespace, tagName) { return createFakeElement(tagName, doc); },
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

/** Planificador RAF falso: el arnés gobierna los cuadros y el reloj. */
function createFakeRaf(clock = null) {
  let nextId = 1;
  let pending = new Map();
  let timestamp = 0;
  return {
    get timestamp() { return timestamp; },
    raf(callback) { const id = nextId++; pending.set(id, callback); return id; },
    caf(id) { pending.delete(id); },
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

/** Reloj falso coherente con el planificador. */
function createFakeClock() {
  let millis = 1_700_000_000_000;
  return {
    now: () => millis,
    advance: (ms) => { millis += ms; },
    set: (value) => { millis = value; },
  };
}

/** Almacén local falso con semántica nativa. */
function createFakeStorage() {
  const map = new Map();
  return {
    map,
    getItem: (key) => (map.has(key) ? map.get(key) : null),
    setItem: (key, value) => map.set(key, String(value)),
    removeItem: (key) => map.delete(key),
  };
}

/** Cliente del grimorio falso con la forma del contrato REST. */
function createFakeGrimoireClient({ catalog = CATALOG } = {}) {
  const calls = [];
  return {
    calls,
    async fetchSpells(params = {}) {
      calls.push({ ...params });
      const spells = params.mode === 'essays' ? [] : catalog;
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

/** Servicio de voz falso (la vista lo exige en su construcción). */
function createFakeSpeechService() {
  return {
    reciteSpell: () => true,
    matchesSpellInvocation: () => true,
    startListening: () => false,
    stopAll: () => true,
    isSynthesisSupported: () => false,
    isRecognitionSupported: () => false,
  };
}

/** Media query falsa de movimiento reducido. */
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

// =====================================================================
// Catálogo de prueba (5 conjuros: agua, fuego, rayo, arcano, tierra)
// Página 1 = Agua, página 3 = Rayo (el guion del «Hecho cuando»).
// =====================================================================

const CATALOG = [
  {
    id: 'spl_1', name: 'Ola Abisal', circle: 2, elementalAffinity: 'water', manaCost: 25,
    castingTime: 'action', areaType: 'singleTarget', rangeType: 'ranged', durationType: 'instant',
    magicSchool: 'evocation', hasVerbal: true, hasSomatic: true, hasMaterial: false,
    incantationFormula: '¡Olas del abismo, arredrad al intruso!',
    description: 'Masa de agua que embiste al blanco.',
    effects: { damage: 40, healing: 0, barrier: 0, crowdControlType: 'none' },
  },
  {
    id: 'spl_2', name: 'Ardor del Alba', circle: 3, elementalAffinity: 'fire', manaCost: 30,
    castingTime: 'action', areaType: 'singleTarget', rangeType: 'ranged', durationType: 'instant',
    magicSchool: 'evocation', hasVerbal: true, hasSomatic: true, hasMaterial: true,
    incantationFormula: '¡Llamas del alba, descended!',
    description: 'Ascuas del alba que abrazan al blanco.',
    effects: { damage: 60, healing: 0, barrier: 0, crowdControlType: 'none' },
  },
  {
    id: 'spl_3', name: 'Flecha Fulgurante', circle: 3, elementalAffinity: 'lightning', manaCost: 35,
    castingTime: 'action', areaType: 'singleTarget', rangeType: 'ranged', durationType: 'instant',
    magicSchool: 'evocation', hasVerbal: true, hasSomatic: false, hasMaterial: false,
    incantationFormula: '¡Saeta del trueno, vuela recta!',
    description: 'Rayo ligero que atraviesa el aire.',
    effects: { damage: 90, healing: 0, barrier: 0, crowdControlType: 'none' },
  },
  {
    id: 'spl_4', name: 'Chispa Arcana', circle: 1, elementalAffinity: 'pureArcane', manaCost: 15,
    castingTime: 'bonus', areaType: 'singleTarget', rangeType: 'ranged', durationType: 'instant',
    magicSchool: 'evocation', hasVerbal: true, hasSomatic: false, hasMaterial: false,
    incantationFormula: '¡Chispas del origen, responded!',
    description: 'Descarga de maná puro.',
    effects: { damage: 40, healing: 0, barrier: 0, crowdControlType: 'none' },
  },
  {
    id: 'spl_5', name: 'Cola de Basalto', circle: 2, elementalAffinity: 'earth', manaCost: 20,
    castingTime: 'action', areaType: 'singleTarget', rangeType: 'touch', durationType: 'instant',
    magicSchool: 'abjuration', hasVerbal: true, hasSomatic: true, hasMaterial: false,
    incantationFormula: '¡Piedra fiel, protégeme!',
    description: 'Látigo de roca que alza escoria defensiva.',
    effects: { damage: 50, healing: 0, barrier: 25, crowdControlType: 'none' },
  },
  // Página 6: Viento — cataliza la Ventisca Helada (agua) y la Deflagración (fuego).
  {
    id: 'spl_6', name: 'Ráfaga del Collado', circle: 2, elementalAffinity: 'wind', manaCost: 22,
    castingTime: 'action', areaType: 'singleTarget', rangeType: 'ranged', durationType: 'instant',
    magicSchool: 'evocation', hasVerbal: true, hasSomatic: false, hasMaterial: false,
    incantationFormula: '¡Aire vivo, corre!',
    description: 'Ráfaga de ladera que empuja al blanco.',
    effects: { damage: 45, healing: 0, barrier: 0, crowdControlType: 'none' },
  },
  // Página 7: Luz — aura para el Colapso Crepuscular; porta barrera propia
  // (renovación por valor dominante tras su impacto).
  {
    id: 'spl_7', name: 'Faro del Amanecer', circle: 3, elementalAffinity: 'light', manaCost: 32,
    castingTime: 'action', areaType: 'singleTarget', rangeType: 'ranged', durationType: 'instant',
    magicSchool: 'evocation', hasVerbal: true, hasSomatic: true, hasMaterial: false,
    incantationFormula: '¡Luz del alba, guíanos!',
    description: 'Haz sacro que revela al blanco y alza un velo radiante.',
    effects: { damage: 40, healing: 0, barrier: 20, crowdControlType: 'none' },
  },
  // Página 8: Oscuridad — detonante del Colapso Crepuscular contra luz.
  {
    id: 'spl_8', name: 'Velo de Sombra', circle: 3, elementalAffinity: 'darkness', manaCost: 32,
    castingTime: 'action', areaType: 'singleTarget', rangeType: 'ranged', durationType: 'instant',
    magicSchool: 'necromancy', hasVerbal: true, hasSomatic: false, hasMaterial: false,
    incantationFormula: '¡Sombra callada, envuélvenos!',
    description: 'Zarcillo umbrío que devora la luz.',
    effects: { damage: 40, healing: 0, barrier: 0, crowdControlType: 'none' },
  },
  // Página 9: catalizador de la Resonancia (RF-03.2) — porta curación,
  // barrera y atadura propia para verificar la amplificación íntegra.
  {
    id: 'spl_9', name: 'Señal del Origen', circle: 4, elementalAffinity: 'pureArcane', manaCost: 40,
    castingTime: 'action', areaType: 'singleTarget', rangeType: 'ranged', durationType: 'instant',
    magicSchool: 'evocation', hasVerbal: true, hasSomatic: true, hasMaterial: false,
    incantationFormula: '¡Runa primera, resuena!',
    description: 'Onda de maná puro que cura, protege y frena.',
    effects: { damage: 40, healing: 20, barrier: 12, crowdControlType: 'slow' },
  },
];

console.log('== ARNÉS TDD — Tarea 4.1: Persistencia de auras al hojear (SPEC-06) ==');

let module;
try {
  module = await import('../public/assets/js/views/grimoireSimulatorView.js');
} catch (error) {
  console.log(`  FATAL — no se pudo importar grimoireSimulatorView.js: ${error.message}`);
  console.log('== RESUMEN == Fase roja: la integración aún no existe.');
  process.exit(1);
}

const { createGrimoireSimulatorView } = module;

/** Monta la vista completa con dobles inyectables. */
function buildView(overrides = {}) {
  const doc = overrides.document ?? createFakeDocument();
  const clock = overrides.clock ?? createFakeClock();
  const raf = overrides.raf ?? createFakeRaf(clock);
  const storage = overrides.storage ?? createFakeStorage();
  const client = overrides.client ?? createFakeGrimoireClient();
  const canvas = overrides.canvas ?? createFakeElement('canvas', doc);
  const host = overrides.host ?? createFakeElement('main', doc);
  const busEvents = [];
  const view = createGrimoireSimulatorView(host, {
    grimoireClient: client,
    speechService: createFakeSpeechService(),
    elementFactory: (tagName) => createFakeElement(tagName, doc),
    document: doc,
    canvas,
    ctx: canvas.__ctx,
    raf: raf.raf,
    caf: raf.caf,
    clock,
    storage,
    motionQuery: createFakeMotionQuery(),
    ...overrides.options,
  });
  // El bus de combos burbujea desde la raíz de la vista hasta el anfitrión.
  for (const name of [
    'combo:aura-applied',
    'combo:aura-refreshed',
    'combo:aura-expired',
    'combo:reaction-triggered',
    'combo:stunlock-immunity-started',
    'combo:stunlock-immunity-ended',
    'grimoire:page-change',
  ]) {
    host.addEventListener(name, (event) => busEvents.push({ name, detail: event?.detail ?? null }));
  }
  // Normalización para la fase roja: mientras la integración no exista,
  // getState() carece de las claves elementales; el arnés las rellena para
  // poder recorrer todas sus fases sin estrellarse.
  const rawGetState = view.getState.bind(view);
  view.getState = () => {
    const snapshot = rawGetState();
    snapshot.elementalAura ??= { element: null, active: false, remainingMs: 0 };
    snapshot.stunlockImmunity ??= false;
    return snapshot;
  };
  return { view, host, doc, raf, clock, storage, client, canvas, busEvents };
}

/** Vuelo estándar del proyectil hasta el impacto (≈460 ms de lienzo). */
function flyToImpact(scene) {
  scene.raf.run(30, 16);
}

/**
 * Vuelve a la página 1 del tomo con previousPage() en cascada (la vista no
 * expone saltos absolutos): el extremo izquierdo es inoperante, así que
 * navegar hacia atrás de más es seguro. Luego avanza hasta la página
 * solicitada. Guion robusto: cada fase declara su página ABSOLUTA y no
 * depende de dónde quedó la fase anterior.
 * @param {object} scene Superficie del arnés.
 * @param {number} page Página destino (1-indexada).
 */
async function goToPage(scene, page) {
  for (let i = 0; i < 12; i++) await scene.view.previousPage();
  for (let i = 1; i < page; i++) await scene.view.nextPage();
}

const eventsOf = (scene, name) => scene.busEvents.filter((e) => e.name === name);

// =====================================================================
// [0] Superficie: estado elemental expuesto y halo montado
// =====================================================================
console.log('\n[0] Superficie y montaje del halo');

const surface = buildView();
await surface.view.render();

const baseState = surface.view.getState();
assertCondition(typeof baseState.elementalAura === 'object', 'getState() expone el TargetAuraState del maniquí');
assertCondition(baseState.elementalAura.element === null, 'el maniquí nace neutral (sin aura)');
assertCondition(baseState.elementalAura.active === false, 'la ventana de resonancia nace apagada');
assertCondition(baseState.stunlockImmunity === false, 'el maniquí nace sin inmunidad anti-stunlock');
assertCondition(Boolean(queryFirst(surface.host, 'elemental-aura')), 'el halo del aura se monta junto al maniquí');
assertCondition(Boolean(queryFirst(surface.host, 'elemental-aura__silhouette')), 'el aura porta su silueta-luz que abraza la efigie (RF-02.2 ratificado)');
assertCondition(Boolean(queryFirst(surface.host, 'elemental-aura__countdown')), 'el aura porta su contador numérico de la ventana');
assertCondition(queryFirst(surface.host, 'elemental-aura__ring-progress') === null, 'ninguna geometría circular sobrevive: el anillo rúnico está retirado (RF-02.2 ratificado)');

// =====================================================================
// [1] Imbuición (RF-02.1)
// =====================================================================
console.log('\n[1] Imbuición del aura con el primer impacto elemental');

await surface.view.castCurrentSpell({ triggerMethod: 'click' });
flyToImpact(surface);

const appliedEvents = eventsOf(surface, 'combo:aura-applied');
assertCondition(appliedEvents.length === 1, 'el impacto emite un único `combo:aura-applied` (plan 4.1)');
assertCondition(appliedEvents[0]?.detail?.element === 'water', 'el evento porta el elemento Agua');
assertCondition(appliedEvents[0]?.detail?.durationMs === 5000, 'la ventana declarada es de 5000 ms (RF-02.1)');

const imbuedState = surface.view.getState();
assertCondition(imbuedState.elementalAura.element === 'water', 'el TargetAuraState registra el aura de Agua');
assertCondition(imbuedState.elementalAura.active === true, 'la ventana de resonancia vive');
assertCondition(
  imbuedState.elementalAura.remainingMs > 4000 && imbuedState.elementalAura.remainingMs <= 5000,
  'la cuenta atrás corre por debajo de 5 s',
);
assertCondition(imbuedState.dummy.health === 460, 'el daño base (40) se aplica sin combo');

const auraRoot = queryFirst(surface.host, 'elemental-aura');
assertCondition(
  auraRoot?.classList.contains('elemental-aura--active') === true,
  'el halo se exhibe activo sobre el maniquí',
);
assertCondition(
  auraRoot?.style.getProperty('--aura-color') === '#00bfff',
  'el halo late con el color heráldico del Códice (#00bfff para Agua)',
);

// =====================================================================
// [2] Persistencia al hojear (RF-02.3)
// =====================================================================
console.log('\n[2] El aura sobrevive intacta al hojear el Tomo');

const remainingBeforeBrowsing = surface.view.getState().elementalAura.remainingMs;
surface.raf.run(3, 16); // el tiempo fluye un instante antes de hojear
await surface.view.nextPage();
await surface.view.nextPage();

const browsedState = surface.view.getState();
assertCondition(browsedState.currentSpell?.name === 'Flecha Fulgurante', 'el tomo alcanza la página 3 (Rayo)');
assertCondition(browsedState.elementalAura.element === 'water', 'el aura de Agua persiste al hojear (RF-02.3)');
assertCondition(browsedState.elementalAura.active === true, 'la ventana de resonancia sigue viva tras hojear');
assertCondition(
  browsedState.elementalAura.remainingMs < remainingBeforeBrowsing,
  'la cuenta atrás nunca se reinicia ni se congela al hojear',
);
assertCondition(browsedState.dummy.health === 460, 'la salud del maniquí también persiste (SPEC-05, RF-02.3)');
assertCondition(
  queryFirst(surface.host, 'elemental-aura') === auraRoot,
  'el halo es el MISMO nodo montado: la Cámara viaja con el tomo',
);

// =====================================================================
// [3] E2E «Hecho cuando» (RF-03.1, RF-04.1)
// =====================================================================
console.log('\n[3] Agua (pág. 1) → hojear → Rayo (pág. 3): Electrocución Fluida');

await surface.view.castCurrentSpell({ triggerMethod: 'click' });
flyToImpact(surface);

const reactionEvents = eventsOf(surface, 'combo:reaction-triggered');
assertCondition(reactionEvents.length === 1, 'la detonación emite `combo:reaction-triggered` (plan 4.1)');
assertCondition(reactionEvents[0]?.detail?.reactionId === 'fluidElectrocution', 'la reacción detonada es Electrocución Fluida');
assertCondition(reactionEvents[0]?.detail?.reactionName === 'Electrocución Fluida', 'el evento porta el nombre solemne');
assertCondition(reactionEvents[0]?.detail?.damage === 135, 'el daño amplificado es ceil(90 × 1.5) = 135 (RF-04.1)');
assertCondition(
  Array.isArray(reactionEvents[0]?.detail?.elements)
    && reactionEvents[0].detail.elements.includes('water')
    && reactionEvents[0].detail.elements.includes('lightning'),
  'el evento declara los elementos intervinientes',
);
assertCondition(reactionEvents[0]?.detail?.colorA && reactionEvents[0]?.detail?.colorB, 'el evento porta los dos colores heráldicos (estelas de la Tarea 4.2)');

const detonatedState = surface.view.getState();
assertCondition(detonatedState.dummy.health === 460 - 135, 'el maniquí sufrió el daño amplificado (325 PV)');
assertCondition(detonatedState.dummy.activeCC === 'stun', 'el Hard CC de la reacción ata al maniquí (RF-05.1)');
assertCondition(detonatedState.elementalAura.element === null, 'el aura se consume al detonar (RF-03.4: neutral puro)');
assertCondition(detonatedState.elementalAura.active === false, 'la ventana de resonancia se apaga');

const immunityEvents = eventsOf(surface, 'combo:stunlock-immunity-started');
assertCondition(immunityEvents.length === 1, 'la salvaguarda concede la Inmunidad Rúnica (RF-05.2)');
assertCondition(immunityEvents[0]?.detail?.durationMs === 3000, 'la inmunidad declara su ventana de 3000 ms');
assertCondition(surface.view.getState().stunlockImmunity === true, 'el estado expone la inmunidad vigente');

// =====================================================================
// [4] Salvaguarda anti-stunlock (RF-05.2/05.3) y duración del Códice
// =====================================================================
console.log('\n[4] Segundo Hard CC suprimido dentro de los 3 s; fuera, aplicado');

// La atadura de la reacción dura 1.5 s (Códice), no los 4 s canónicos:
// avanzamos hasta verla disipada con la inmunidad aún viva.
surface.raf.run(100, 16); // +1600 ms: atadura disipada (1.5 s), inmunidad viva (3 s)
const afterCcExpiry = surface.view.getState();
assertCondition(afterCcExpiry.dummy.activeCC === null, 'la atadura del Códice se disipa a su plazo (1.5 s, no 4 s)');
assertCondition(afterCcExpiry.stunlockImmunity === true, 'la inmunidad sigue viva pasado el Hard CC');

// Agua (página 1) y Rayo (página 3) dentro de la ventana de inmunidad:
// el aura debe reimbuyirse antes de detonar.
await goToPage(surface, 1);
await surface.view.castCurrentSpell({ triggerMethod: 'click' });
flyToImpact(surface);
await goToPage(surface, 3);
await surface.view.castCurrentSpell({ triggerMethod: 'click' });
flyToImpact(surface);

const suppressedState = surface.view.getState();
assertCondition(
  eventsOf(surface, 'combo:stunlock-immunity-started').length === 1,
  'la inmunidad NO se re-concede ni se apila (RF-05.2)',
);
assertCondition(suppressedState.dummy.activeCC === null, 'el segundo Hard CC se suprime: onda de choque sin atadura (RF-05.3)');
assertCondition(suppressedState.dummy.health === 460 - 135 - 40 - 135, 'el daño de la reacción suprimida permanece íntegro (135)');
assertCondition(suppressedState.elementalAura.element === null, 'el aura también se consume en la detonación suprimida');

// Fuera de la ventana: la atadura vuelve a aplicarse. Reinicio limpio del
// banco (el daño acumulado habría disuelto al maniquí) y nueva transgresión
// con la tregua ya expirada.
surface.raf.run(40, 16); // +640 ms: la inmunidad expira
assertCondition(surface.view.getState().stunlockImmunity === false, 'la inmunidad expira a los 3 s exactos');
await surface.view.restoreDummy(); // banco limpio: 500 PV, sin aura, sin tregua
await goToPage(surface, 1);
await surface.view.castCurrentSpell({ triggerMethod: 'click' });
flyToImpact(surface);
await goToPage(surface, 3);
await surface.view.castCurrentSpell({ triggerMethod: 'click' });
flyToImpact(surface);
const reappliedState = surface.view.getState();
assertCondition(reappliedState.dummy.activeCC === 'stun', 'expirada la tregua, el Hard CC vuelve a atar (RF-05.3)');
assertCondition(eventsOf(surface, 'combo:stunlock-immunity-started').length === 2, 'la segunda transgresión concede una nueva inmunidad');

// =====================================================================
// [5] Refresco (RF-02.4) y Vaporización sin atadura
// =====================================================================
console.log('\n[5] Refresco del aura y neblina sin atadura');

// Refresco con el propio Rayo (el mismo elemento reinicia la ventana sin
// detonar): página 3 absoluta, sin depender de dónde quedó la fase previa.
await surface.view.restoreDummy();
await goToPage(surface, 3);
await surface.view.castCurrentSpell({ triggerMethod: 'click' }); // Rayo: imbuye
flyToImpact(surface);
const refreshedBefore = surface.view.getState().elementalAura.remainingMs;
surface.raf.run(5, 16); // +80 ms: la ventana envejece un instante
const reactionsBeforeRefresh = eventsOf(surface, 'combo:reaction-triggered').length;
await surface.view.castCurrentSpell({ triggerMethod: 'click' }); // Rayo de nuevo: refresca
flyToImpact(surface);

const refreshedEvents = eventsOf(surface, 'combo:aura-refreshed');
assertCondition(refreshedEvents.length === 1, 'el mismo elemento emite `combo:aura-refreshed` (RF-02.4)');
assertCondition(refreshedEvents[0]?.detail?.element === 'lightning', 'el refresco porta el elemento vigente');
// Corrector de impacto: el refresco por proximidad detona a mitad de vuelo;
// los cuadros residuales de flyToImpact envejecen la ventana ~340 ms.
assertCondition(
  surface.view.getState().elementalAura.remainingMs >= 4400,
  'la ventana se reinicia a 5 s sin detonar combo (RF-02.4)',
);
assertCondition(
  eventsOf(surface, 'combo:reaction-triggered').length === reactionsBeforeRefresh,
  'el refresco jamás detona reacción alguna',
);

// Agua + Fuego = Vaporización Arcana: neblina (Soft CC) sin atadura.
await surface.view.restoreDummy();
await goToPage(surface, 1);
await surface.view.castCurrentSpell({ triggerMethod: 'click' });
flyToImpact(surface);
await goToPage(surface, 2);
await surface.view.castCurrentSpell({ triggerMethod: 'click' });
flyToImpact(surface);

const vaporizedState = surface.view.getState();
assertCondition(
  eventsOf(surface, 'combo:reaction-triggered').some((e) => e.detail?.reactionId === 'arcaneVaporization' && e.detail?.damage === 90),
  'Vaporización Arcana detona con ceil(60 × 1.5) = 90',
);
assertCondition(
  vaporizedState.dummy.activeCC === 'slow' && vaporizedState.dummy.health === 500 - 40 - 90,
  'la neblina (Soft CC) ralentiza al maniquí sin atadura dura (RF-05.3)',
);

// =====================================================================
// [6] Catalizador (RF-03.2)
// =====================================================================
console.log('\n[6] Resonancia Arcana Pura: amplificación ×1.25');

// Página 1: Agua (aura) → página 4: Arcano Puro (catalizador).
await goToPage(surface, 1);
await surface.view.castCurrentSpell({ triggerMethod: 'click' }); // aura de Agua
flyToImpact(surface);
await goToPage(surface, 4);
await surface.view.castCurrentSpell({ triggerMethod: 'click' });
flyToImpact(surface);

assertCondition(
  eventsOf(surface, 'combo:reaction-triggered').some((e) => e.detail?.reactionId === 'pureArcaneResonance' && e.detail?.damage === 50),
  'el catalizador amplifica a ceil(40 × 1.25) = 50 (RF-03.2)',
);
assertCondition(
  surface.view.getState().elementalAura.element === null,
  'el catalizador consume el aura sin imbuir nueva (RF-03.2)',
);

// =====================================================================
// [7] Sobreescritura (RF-03.3)
// =====================================================================
console.log('\n[7] Elemento no reactivo: sobreescritura con daño íntegro');

// Par honesto no reactivo del Códice: aura de Tierra + Fuego entrante
// (Tierra solo reacciona con Agua y Rayo). Página 5: Tierra imbuye;
// página 2: Fuego sobreescribe con daño íntegro.
await surface.view.restoreDummy();
await goToPage(surface, 5);
await surface.view.castCurrentSpell({ triggerMethod: 'click' });
flyToImpact(surface);
assertCondition(surface.view.getState().elementalAura.element === 'earth', 'Tierra se imbuye como aura previa');
const reactionsBeforeOverwrite = eventsOf(surface, 'combo:reaction-triggered').length;
await goToPage(surface, 2);
await surface.view.castCurrentSpell({ triggerMethod: 'click' });
flyToImpact(surface);

const overwrittenState = surface.view.getState();
assertCondition(overwrittenState.elementalAura.element === 'fire', 'el aura se sobreescribe con Fuego (RF-03.3)');
assertCondition(
  eventsOf(surface, 'combo:reaction-triggered').length === reactionsBeforeOverwrite,
  'la sobreescritura no detona reacción alguna',
);
// El daño del Fuego viaja íntegro (60), sin bonificación de reacción: la
// barrera de Tierra (25) absorbe su parte por la absorción prioritaria de
// SPEC-05, y el resto alcanza a la salud (50 − 25 = 35 efectivos).
assertCondition(
  overwrittenState.dummy.health === 500 - 50 - 35,
  'el daño viaja íntegro (60, sin multiplicador); la barrera previa absorbe 25 (RF-03.3)',
);

// =====================================================================
// [8] Fractura Basáltica: trituración de barrera sin excedente
// =====================================================================
console.log('\n[8] La trituración anula la barrera sin tocar la salud');

// Reinicio limpio y escudo fresco: página 5 (Tierra) alza su barrera de 25
// con el impacto que imbuye el aura; página 3 (Rayo) detona la Fractura.
await surface.view.restoreDummy();
await goToPage(surface, 5);
await surface.view.castCurrentSpell({ triggerMethod: 'click' });
flyToImpact(surface);
assertCondition(surface.view.getState().dummy.barrier === 25, 'el blanco queda escudado con 25 PV de barrera');
const healthBeforeFracture = surface.view.getState().dummy.health;
await goToPage(surface, 3); // página 3: Rayo
await surface.view.castCurrentSpell({ triggerMethod: 'click' });
flyToImpact(surface);

const shatteredState = surface.view.getState();
assertCondition(
  eventsOf(surface, 'combo:reaction-triggered').some((e) => e.detail?.reactionId === 'basalticFracture'),
  'Fractura Basáltica detona contra la barrera',
);
assertCondition(shatteredState.dummy.barrier === 0, 'la trituración del Códice anula la barrera (RF-04.2)');
assertCondition(shatteredState.dummy.health === healthBeforeFracture - 135, 'el daño amplificado ceil(90 × 1.5) = 135 no genera excedente');

// =====================================================================
// [9] Restaurar Maniquí disipa el aura (RF-02.6)
// =====================================================================
console.log('\n[9] «Restaurar Maniquí» disipa el aura y la inmunidad');

await surface.view.restoreDummy();
await goToPage(surface, 1); // Agua: imbuye de nuevo
await surface.view.castCurrentSpell({ triggerMethod: 'click' });
flyToImpact(surface);
assertCondition(surface.view.getState().elementalAura.active === true, 'el aura vive antes de la restauración');
await surface.view.restoreDummy();

const resetState = surface.view.getState();
assertCondition(resetState.elementalAura.element === null, 'el aura se disipa con la restauración');
assertCondition(resetState.elementalAura.active === false, 'la ventana se apaga');
assertCondition(resetState.stunlockImmunity === false, 'la inmunidad anti-stunlock se disipa también');
assertCondition(resetState.dummy.health === 500, 'el maniquí vuelve a sus 500 PV (RF-02.6)');

// =====================================================================
// [9b] Expiración natural sincronizada con la vista (Hallazgo del
//      corrector de auras): al expirar la ventana, la copia del estado
//      `activeAuraElement` debe purgarse también en la vista. Sin esta
//      sincronía, reimbuir el MISMO elemento tras la expiración caía en
//      el Caso B (refresco invisible sobre un aura ya caducada) y un
//      elemento distinto detonaba un combo fantasma sobre reposo dorado.
// =====================================================================
console.log('\n[9b] Expiración natural: la vista purga su estado elemental');

await surface.view.restoreDummy();
await surface.view.castCurrentSpell({ triggerMethod: 'click' }); // imbuye
flyToImpact(surface);
assertCondition(surface.view.getState().elementalAura.active === true, 'el aura vive tras la imbuición (fase 9b)');

// La ventana vive 5 s: avanzamos el reloj más allá de la expiración y
// dejamos que el bucle de escena del halo la declare (RF-02.5).
surface.raf.run(340, 16); // +5440 ms > 5000 ms de resonancia
const expiredState = surface.view.getState();
assertCondition(expiredState.elementalAura.active === false, 'la ventana expira a su plazo natural');
assertCondition(expiredState.elementalAura.element === null, 'el estado de la vista queda neutral tras la expiración');
assertCondition(
  eventsOf(surface, 'combo:aura-expired').length >= 1,
  'la expiración natural emite `combo:aura-expired` (RF-02.5)',
);

// Reimbuyendo el MISMO elemento tras expirar: debe volver a imbuirse
// (Caso A) y no refrescar en silencio sobre un aura caducada.
const appliedBeforeReimbuement = eventsOf(surface, 'combo:aura-applied').length;
await surface.view.castCurrentSpell({ triggerMethod: 'click' });
flyToImpact(surface);
assertCondition(
  eventsOf(surface, 'combo:aura-applied').length === appliedBeforeReimbuement + 1,
  'reimbuir el mismo elemento tras expirar vuelve a imbuir (Caso A, no refresco)',
);
assertCondition(surface.view.getState().elementalAura.active === true, 'la ventana renace con la reimbuición');

// =====================================================================
// [9c] Ráfaga FIFO lee el aura VIVA al resolver (RF-05.4 + Hallazgo 13):
//      la cola congela impactos al encolar pero resuelve uno por cuadro;
//      el segundo impacto de la ráfaga debe leer el aura YA APLICADA por
//      el primero (imbuye → detona), no la instantánea del encolado.
// =====================================================================
console.log('\n[9c] Ráfaga FIFO: el segundo impacto detona sobre la imbuición del primero');

await surface.view.restoreDummy();
// Ráfaga encadenada con páginas absolutas: Agua (página 1) imbuye y Rayo
// (página 3) detona sobre esa imbuición recién aplicada (lectura VIVA).
await goToPage(surface, 1);

// Primer impacto: se resuelve AL INSTANTE al encolar (plan 3.3).
await surface.view.castCurrentSpell({ triggerMethod: 'click' });
flyToImpact(surface);
assertCondition(surface.view.getState().elementalAura.element === 'water', 'la ráfaga parte de la imbuición de Agua');

// Segundo impacto encolado mientras el reloj sigue en el MISMO cuadro de
// resolución: debe leer el aura de Agua viva y detonar Electrocución con
// el Rayo (página 3), no refrescar ni tratar el blanco como neutral.
await goToPage(surface, 3);
const reactionsBeforeBurst = eventsOf(surface, 'combo:reaction-triggered').length;
await surface.view.castCurrentSpell({ triggerMethod: 'click' });
flyToImpact(surface);

const burstState = surface.view.getState();
assertCondition(
  eventsOf(surface, 'combo:reaction-triggered').length === reactionsBeforeBurst + 1,
  'el impacto de Rayo sobre el aura de Agua recién aplicada detona la reacción (lectura VIVA, RF-05.4)',
);
assertCondition(burstState.elementalAura.element === null, 'el aura se consume en la detonación de la ráfaga');

// =====================================================================
// [9d] Congelación y perforación en cliente (regresión del mapeo de
//      efectos tácticos del Códice): la Ventisca Helada (Viento+Agua,
//      freezeParalysis, Hard CC de 2 s) DEBE atar al maniquí vía la vista
//      — sin el mapeo el CC se esfumaba en silencio — y el Colapso
//      Crepuscular (Luz+Oscuridad, barrierPiercing) DEBE perforar la
//      barrera: daño íntegro a la salud con el escudo intacto (RF-04.2,
//      Hallazgo 6). Páginas: 6=Viento, 1=Agua, 7=Luz, 8=Oscuridad.
// =====================================================================
console.log('\n[9d] Ventisca Helada ata (freezeParalysis) y Colapso perfora (barrierPiercing)');

// — Ventisca Helada: Viento imbuye, Agua detona congelación de 2 s —
await surface.view.restoreDummy();
await goToPage(surface, 6); // Viento
await surface.view.castCurrentSpell({ triggerMethod: 'click' });
flyToImpact(surface);
assertCondition(surface.view.getState().elementalAura.element === 'wind', 'Viento se imbuye como aura previa (fase 9d)');
await goToPage(surface, 1); // Agua: detona la Ventisca Helada
await surface.view.castCurrentSpell({ triggerMethod: 'click' });
flyToImpact(surface);

const blizzardState = surface.view.getState();
assertCondition(
  eventsOf(surface, 'combo:reaction-triggered').some((e) => e.detail?.reactionId === 'glacialBlizzard'),
  'Ventisca Helada detona en el pipeline cliente (Viento + Agua)',
);
assertCondition(blizzardState.dummy.activeCC === 'stun', 'la congelación del Códice ata al maniquí (freezeParalysis mapeado, no esfumado)');

// La atadura dura 2 s (Códice) DESDE el impacto, y el vuelo del Agua ya
// consumió ~480 ms: solo quedan ~1520 ms de atadura tras aterrizar.
surface.raf.run(90, 16); // +1440 ms: total ≈1920 ms desde el impacto: viva
assertCondition(surface.view.getState().dummy.activeCC === 'stun', 'la congelación de 2 s sigue viva a ~1.9 s desde el impacto');
surface.raf.run(20, 16); // +320 ms: total ≈2240 ms, ya disipada
assertCondition(surface.view.getState().dummy.activeCC === null, 'la congelación se disipa a su plazo del Códice (2 s)');

// — Colapso Crepuscular: Luz imbuye, Oscuridad detona perforación —
// Guion con escudo conviviente: Tierra alza 25 PV de barrera con su
// impacto; la Luz (no reactiva con Tierra) sobreescribe el aura con daño
// íntegro y el escudo SOBREVIVE (la renovación de barrera es por valor
// dominante, y la Luz no porta barrera); el aura de Luz queda vigente.
await surface.view.restoreDummy();
await goToPage(surface, 5); // Tierra: escudo de 25 + aura
await surface.view.castCurrentSpell({ triggerMethod: 'click' });
flyToImpact(surface);
assertCondition(surface.view.getState().dummy.barrier === 25, 'la Tierra alza su escudo de 25 PV (fase 9d)');
await goToPage(surface, 7); // Luz: sobreescritura, escudo intacto
await surface.view.castCurrentSpell({ triggerMethod: 'click' });
flyToImpact(surface);
assertCondition(surface.view.getState().elementalAura.element === 'light', 'Luz se imbuye como aura previa (fase 9d)');
// La Luz absorbió el escudo de Tierra (25) con su daño, pero alzó el suyo
// de 20: la renovación por valor dominante deja 20 PV vigentes.
assertCondition(surface.view.getState().dummy.barrier === 20, 'la Luz alza su velo radiante de 20 PV vigentes');

// Oscuridad detona el Colapso: el daño puro ceil(40×1.5)=60 perfora y
// toca la salud sin absorberse, dejando la barrera INTACTA (RF-04.2).
await goToPage(surface, 8);
const healthBeforeCollapse = surface.view.getState().dummy.health;
await surface.view.castCurrentSpell({ triggerMethod: 'click' });
flyToImpact(surface);

const collapseState = surface.view.getState();
assertCondition(
  eventsOf(surface, 'combo:reaction-triggered').some((e) => e.detail?.reactionId === 'twilightCollapse'),
  'Colapso Crepuscular detona en el pipeline cliente (Luz + Oscuridad)',
);
assertCondition(
  collapseState.dummy.health === healthBeforeCollapse - 60,
  'el daño puro (60) toca la salud SIN absorción de escudo (RF-04.2)',
);
assertCondition(
  collapseState.dummy.barrier === 20,
  'la barrera queda INTACTA tras la penetración (RF-04.2, Hallazgo 6)',
);

// =====================================================================
// [9e] Resonancia Arcana Pura íntegra (RF-03.2, Hallazgo 15):
//      el catalizador amplifica curación y barrera ×1.25 y añade (+1) s
//      a la atadura propia del conjuro — no solo el daño.
// =====================================================================
console.log('\n[9e] Catalizador íntegro: curación, barrera y CC amplificados (RF-03.2)');

await surface.view.restoreDummy();
await goToPage(surface, 2); // Fuego: imbuye el aura que el catalizador consumirá
await surface.view.castCurrentSpell({ triggerMethod: 'click' });
flyToImpact(surface);
assertCondition(surface.view.getState().elementalAura.element === 'fire', 'Fuego se imbuye como aura previa (fase 9e)');

await goToPage(surface, 9); // Señal del Origen: catalizador con cura/escudo/lentitud
const preResonance = surface.view.getState();
const healthBeforeResonance = preResonance.dummy.health;
await surface.view.castCurrentSpell({ triggerMethod: 'click' });
flyToImpact(surface);

const resonanceState = surface.view.getState();
assertCondition(
  eventsOf(surface, 'combo:reaction-triggered').some((e) => e.detail?.reactionId === 'pureArcaneResonance'),
  'la Resonancia Arcana Pura detona sobre el aura de Fuego (RF-03.2)',
);
// Balance neto del impacto: daño ya amplificado en el veredicto
// (ceil(40 × 1.25) = 50, contrato de 11 claves) menos la curación
// amplificada por la vista (ceil(20 × 1.25) = 25): 500 − 50 + 25.
assertCondition(
  resonanceState.dummy.health === healthBeforeResonance - 50 + 25,
  'el daño amplificado (50) hiere y la curación amplificada (25) restaura: neto −25 (RF-03.2, Hallazgo 15)',
);
// Barrera amplificada: ceil(12 × 1.25) = 15.
assertCondition(
  resonanceState.dummy.barrier === 15,
  'la barrera amplificada ceil(12 × 1.25) = 15 alza el escudo (RF-03.2, Hallazgo 15)',
);
// La atadura propia del catalizador ('slow') aplica con +1 s: 4 s + 1 s.
assertCondition(
  resonanceState.dummy.activeCC === 'slow',
  'la atadura propia del catalizador (slow) aplica al blanco (RF-03.2)',
);
surface.raf.run(90, 16); // +1440 ms tras el vuelo (~480 ms): ~1.9 s con el aura consumida
assertCondition(
  surface.view.getState().dummy.activeCC === 'slow',
  'la lentitud sigue viva a ~1.9 s: la ventana de 5 s (+1 s) gobierna (RF-03.2)',
);
surface.raf.run(280, 16); // +4480 ms: total ≈6.4 s desde el impacto: la atadura de 5 s expiró
assertCondition(
  surface.view.getState().dummy.activeCC === null,
  'la lentitud se disipa a su plazo extendido de 5 s (4 s canónicos + 1 s del catalizador)',
);
// La resonancia consume el aura: neutral puro (RF-03.4).
assertCondition(
  surface.view.getState().elementalAura.element === null,
  'la resonancia consume el aura: neutral puro tras el catalizador (RF-03.4)',
);

// =====================================================================
// [10] destroy() desmonta el aura
// =====================================================================
console.log('\n[10] Desmontaje limpio');

surface.view.destroy();
assertCondition(
  queryFirst(surface.host, 'elemental-aura') === null,
  'destroy() desmonta el halo del aura junto a la vista',
);

// =====================================================================
// Resumen
// =====================================================================
console.log(`\n== RESUMEN == Asertos superados: ${assertsPassed}, fallidos: ${assertsFailed}`);
if (assertsFailed === 0) {
  console.log('RESULTADO: EXITO — El aura persiste al hojear y el Códice detona en la Cámara (Tarea 4.1).');
  process.exit(0);
}
console.log('RESULTADO: DENEGADO — Revisa los asertos marcados.');
process.exit(1);
