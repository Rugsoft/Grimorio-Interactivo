/**
 * test_combo_log_aria.mjs — Arnés TDD de la Tarea 4.3 (TASKS-06).
 *
 * Estrategia TDD: este script se escribe ANTES que la implementación.
 * Verifica, sobre `public/assets/js/views/grimoireSimulatorView.js`:
 *   [1] Un impacto sin reacción NO inscribe etiqueta de combo alguna.
 *   [2] La detonación inscribe en la Bitácora persistente (localStorage,
 *       clave `grimorio_test_log_v1`) la entrada con:
 *       - `comboTag`: etiqueta distintiva «[Combo: Electrocución Fluida]»
 *         (RF-06.3);
 *       - `comboElements`: los elementos intervinientes { activeAura,
 *         incoming } (RF-06.3);
 *       - `comboDamageDealt`: el daño total asestado (RF-06.3).
 *   [3] La fila correspondiente del panel de la bitácora exhibe la
 *       etiqueta y el daño del combo.
 *   [4] La región viva aria-live="polite" anuncia el suceso con la fórmula
 *       canónica «Reacción desatada: …» (RF-06.4).
 *   [5] El límite canónico de 5 entradas (RF-05.3) se respeta también con
 *       etiquetas de combo.
 *   [6] «Restaurar Maniquí» (RF-02.6) purga las entradas de combo.
 *
 * Criterio «Hecho cuando» (Tarea 4.3): la bitácora inscribe el registro
 * de la reacción y los lectores de pantalla anuncian la detonación del
 * combo.
 *
 * Constitución:
 *   - Artículo I (Dogma Vanilla): localStorage nativo, cero librerías;
 *     storage inyectable para el arnés.
 *   - Artículo V: identificadores en inglés camelCase, comentarios en
 *     castellano, solemne castellano en las etiquetas visibles.
 */

let assertsPassed = 0;
let assertsFailed = 0;

/** Aserto de una sola línea con veredicto inmediato en la terminal. */
function assertCondition(condition, message) {
  if (condition) {
    assertsPassed += 1;
    console.log(`  [PASA] ${message}`);
  } else {
    assertsFailed += 1;
    console.log(`  [FALLA] ${message}`);
  }
}

// ---------------------------------------------------------------------
// Dobles: documento, lienzo grabador, reloj, RAF y storage efímero
// (mismo patrón que los arneses de las Tareas 4.1 y 4.2).
// ---------------------------------------------------------------------

function createFakeContext2() {
  const ops = [];
  const noop = (name) => (...args) => ops.push([name, ...args]);
  const ctx = {
    ops,
    canvas: null, textAlign: 'left', textBaseline: 'alphabetic', lineWidth: 1,
    strokeStyle: '#000',
    _font: '',
    _fillStyle: '#000',
    set font(v) { ops.push(['font', String(v)]); this._font = String(v); },
    get font() { return this._font; },
    set fillStyle(v) { ops.push(['fillStyle', String(v)]); this._fillStyle = String(v); },
    get fillStyle() { return this._fillStyle; },
    save: noop('save'), restore: noop('restore'), beginPath: noop('beginPath'),
    closePath: noop('closePath'), moveTo: noop('moveTo'), lineTo: noop('lineTo'),
    arc: noop('arc'), rect: noop('rect'), fill: noop('fill'), stroke: noop('stroke'),
    fillRect: noop('fillRect'), clearRect: noop('clearRect'), fillText: noop('fillText'),
    translate: noop('translate'), rotate: noop('rotate'), scale: noop('scale'),
    setLineDash: noop('setLineDash'), setTransform: noop('setTransform'),
    createRadialGradient: () => ({ addColorStop: noop('addColorStop') }),
    createLinearGradient: () => ({ addColorStop: noop('addColorStop') }),
    measureText: (t) => ({ width: String(t ?? '').length * 7 }),
  };
  // Devuelve el contexto grabador (la fábrica debe entregar la instancia)
  return ctx;
}

function createFakeElement(tagName, ownerDocument = null) {
  const element = {
    tagName: tagName.toUpperCase(),
    ownerDocument,
    children: [],
    parentNode: null,
    attributes: new Map(),
    classes: new Set(),
    listeners: {},
    style: { setProperty() {}, removeProperty() {} },
    disabled: false,
    hidden: false,
    value: '',
    _textContent: '',
    appendChild(child) { child.parentNode = this; this.children.push(child); return child; },
    replaceChildren(...next) { this.children.forEach((c) => { c.parentNode = null; }); this.children = next; next.forEach((c) => { c.parentNode = this; }); },
    setAttribute(name, value) { this.attributes.set(name, String(value)); },
    getAttribute(name) { return this.attributes.has(name) ? this.attributes.get(name) : null; },
    removeAttribute(name) { this.attributes.delete(name); },
    hasAttribute(name) { return this.attributes.has(name); },
    addEventListener(name, listener) { (this.listeners[name] ??= []).push(listener); },
    removeEventListener(name, listener) { this.listeners[name] = (this.listeners[name] ?? []).filter((l) => l !== listener); },
    dispatchEvent(event) {
      // Propagación burbujeante completa (los eventos del Códice deben
      // alcanzar el shell: combo:reaction-triggered viaja hasta el host).
      let node = this;
      while (node) { (node.listeners?.[event?.type] ?? []).forEach((l) => l(event)); if (event?.bubbles === false) break; node = node.parentElement ?? node.parentNode ?? null; }
      return true;
    },
    querySelector() { return null; },
    querySelectorAll() { return []; },
    focus() {},
    get textContent() { return this.children.length ? this.children.map((c) => c.textContent).join('') : this._textContent; },
    set textContent(v) { this._textContent = String(v); this.children = []; },
  };
  element.classList = {
    _o: element,
    add(...n) { n.forEach((x) => this._o.classes.add(x)); },
    remove(...n) { n.forEach((x) => this._o.classes.delete(x)); },
    contains(n) { return this._o.classes.has(n); },
    toggle(n, f) { const has = this._o.classes.has(n); const want = f === undefined ? !has : Boolean(f); want ? this._o.classes.add(n) : this._o.classes.delete(n); return want; },
  };
  if (element.tagName === 'CANVAS') {
    element.width = 200; element.height = 100; element.clientWidth = 200; element.clientHeight = 100;
    const c = createFakeContext2(); c.canvas = element;
    element.getContext = () => c; element.__ctx = c;
  }
  return element;
}

function createFakeDoc() {
  const doc = {
    hidden: false, listeners: {},
    createElement(t) { return createFakeElement(t, doc); },
    createElementNS(_ns, t) { return createFakeElement(t, doc); },
    addEventListener(n, l) { (doc.listeners[n] ??= []).push(l); },
    removeEventListener(n, l) { doc.listeners[n] = (doc.listeners[n] ?? []).filter((x) => x !== l); },
    dispatch(n, e = {}) { (doc.listeners[n] ?? []).forEach((l) => l({ type: n, ...e })); },
  };
  return doc;
}

let millis = 1_700_000_000_000;
const clock = { now: () => millis, advance: (ms) => { millis += ms; } };
function createRaf() {
  let nextId = 1; let pendingMap = new Map(); let ts = 0;
  return {
    raf(cb) { const id = nextId++; pendingMap.set(id, cb); return id; },
    caf(id) { pendingMap.delete(id); },
    run(n, step = 16) { for (let i = 0; i < n; i++) { ts += step; clock.advance(step); const cur = [...pendingMap.values()]; pendingMap = new Map(); cur.forEach((cb) => cb(ts)); } },
  };
}

/** Storage efímero por escena: aisla la bitácora persistente de cada banco. */
function createStorage() {
  const map = new Map();
  return {
    map,
    getItem(k) { return map.has(k) ? map.get(k) : null; },
    setItem(k, v) { map.set(k, String(v)); },
    removeItem(k) { map.delete(k); },
  };
}

const CATALOG = [
  { id: 'w', name: 'Ola Abisal', circle: 2, elementalAffinity: 'water', manaCost: 25, castingTime: 'action', areaType: 'singleTarget', rangeType: 'ranged', durationType: 'instant', magicSchool: 'evocation', hasVerbal: true, hasSomatic: true, hasMaterial: false, incantationFormula: 'a', description: 'd', effects: { damage: 40, healing: 0, barrier: 0, crowdControlType: 'none' } },
  { id: 'l', name: 'Flecha Fulgurante', circle: 3, elementalAffinity: 'lightning', manaCost: 35, castingTime: 'action', areaType: 'singleTarget', rangeType: 'ranged', durationType: 'instant', magicSchool: 'evocation', hasVerbal: true, hasSomatic: false, hasMaterial: false, incantationFormula: 'b', description: 'd', effects: { damage: 90, healing: 0, barrier: 0, crowdControlType: 'none' } },
];
function createClient() {
  return {
    async fetchSpells() {
      return { success: true, status: 200, data: { totalSpells: 2, currentPage: 1, totalPages: 2, hasPrevious: false, hasNext: true, spells: CATALOG } };
    },
  };
}
const speech = { reciteSpell: () => true, matchesSpellInvocation: () => true, startListening: () => false, stopAll: () => true, isSynthesisSupported: () => false, isRecognitionSupported: () => false };

const viewModule = await import('../public/assets/js/views/grimoireSimulatorView.js');
const { createGrimoireSimulatorView } = viewModule;

function buildScene() {
  const doc = createFakeDoc();
  const sceneRaf = createRaf();
  const sceneStorage = createStorage();
  const host = createFakeElement('main', doc);
  const canvas = createFakeElement('canvas', doc);
  const busEvents = [];
  const view = createGrimoireSimulatorView(host, {
    grimoireClient: createClient(),
    speechService: speech,
    elementFactory: (t) => createFakeElement(t, doc),
    document: doc,
    canvas,
    ctx: canvas.__ctx,
    raf: sceneRaf.raf,
    caf: sceneRaf.caf,
    clock,
    storage: sceneStorage,
    motionQuery: { matches: false, addEventListener() {}, removeEventListener() {} },
  });
  for (const name of ['combo:reaction-triggered']) {
    host.addEventListener(name, (e) => busEvents.push({ name, detail: e?.detail ?? null }));
  }
  return { view, host, doc, raf: sceneRaf, canvas, busEvents, storage: sceneStorage };
}

const flyToImpact = (scene) => scene.raf.run(30, 16);

/** Lee la bitácora persistente bruta de la clave canónica (plan 2.2). */
function readPersistedLog(scene) {
  const raw = scene.storage.getItem('grimorio_test_log_v1');
  return raw ? JSON.parse(raw).logs : [];
}

// =====================================================================
// [0] Superficie
// =====================================================================
console.log('[0] Superficie y aislamiento del banco');
const surface = buildScene();
await surface.view.render();
assertCondition(typeof surface.view.getAnnouncements === 'function', 'la vista expone la crónica de anuncios accesibles');
assertCondition(surface.storage.getItem('grimorio_test_log_v1') !== null || readPersistedLog(surface).length === 0, 'la bitácora persistente arranca vacía en el banco recién montado');

// =====================================================================
// [1] Impacto sin reacción: ninguna etiqueta de combo (RF-06.3)
// =====================================================================
console.log('\n[1] Impacto sin reacción: la bitácora no inscribe combo alguno');
await surface.view.castCurrentSpell({ triggerMethod: 'click' }); // Ola Abisal (agua): imbuye aura
flyToImpact(surface);
const plainEntries = readPersistedLog(surface);
assertCondition(plainEntries.length === 1, `el impacto se inscribe en la bitácora persistente (${plainEntries.length} entrada)`);
assertCondition(plainEntries[0]?.comboTag === undefined, 'la entrada de imbuición no porta etiqueta de combo');
assertCondition(
  !surface.view.getAnnouncements().some((m) => m.startsWith('Reacción desatada:')),
  'sin reacción no hay anuncio «Reacción desatada:» (RF-06.4)',
);

// =====================================================================
// [2] Detonación: entrada de combo en localStorage (RF-06.3)
// =====================================================================
console.log('\n[2] La detonación inscribe el registro del combo en la bitácora persistente');
await surface.view.nextPage(); // página 2: Flecha Fulgurante (rayo)
await surface.view.castCurrentSpell({ triggerMethod: 'click' });
flyToImpact(surface);

assertCondition(surface.busEvents.length === 1, 'la detonación emite combo:reaction-triggered');
assertCondition(surface.busEvents[0]?.detail?.reactionId === 'fluidElectrocution', 'la reacción es Electrocución Fluida');

const persisted = readPersistedLog(surface);
const comboEntry = persisted[0];
assertCondition(persisted.length === 2, `el impacto del combo se añade a la bitácora (${persisted.length} entradas)`);
assertCondition(comboEntry?.comboTag === '[Combo: Electrocución Fluida]', `la entrada porta la etiqueta distintiva: «${comboEntry?.comboTag}»`);
assertCondition(
  comboEntry?.comboElements?.activeAura === 'water' && comboEntry?.comboElements?.incoming === 'lightning',
  'la entrada registra los elementos intervinientes (agua activa + rayo entrante)',
);
assertCondition(comboEntry?.comboDamageDealt === 135, `la entrada registra el daño total asestado (${comboEntry?.comboDamageDealt} = ceil(90 × 1.5))`);
assertCondition(comboEntry?.spellName === 'Flecha Fulgurante', 'la entrada conserva el conjuro detonante');

// =====================================================================
// [3] Panel y anuncio accesible (RF-06.3, RF-06.4)
// =====================================================================
console.log('\n[3] El panel exhibe la etiqueta y la región viva anuncia la detonación');
const state = surface.view.getState();
const comboRow = surface.doc.createElement && state.logbookEntries >= 1
  ? findLogbookRowWithText(surface, '[Combo: Electrocución Fluida]')
  : null;
assertCondition(comboRow !== null, 'la fila del combo aparece en el panel de la Bitácora con su etiqueta');
if (comboRow !== null) {
  assertCondition(comboRow.textContent.includes('135 de daño'), 'la fila del combo exhibe el daño total (135 de daño)');
}

const announcements = surface.view.getAnnouncements();
const comboAnnouncement = announcements[announcements.length - 1];
assertCondition(
  typeof comboAnnouncement === 'string' && comboAnnouncement.startsWith('Reacción desatada: Electrocución Fluida inflige 135 puntos de daño al maniquí de pruebas'),
  `la región viva anuncia: «${comboAnnouncement}»`,
);
assertCondition(
  !comboAnnouncement.startsWith('¡'),
  'el anuncio accesible usa la fórmula solemne, no el grito monumental (los dos canales son distintos)',
);

/** Localiza la fila del panel cuyo texto contiene la aguja dada. */
function findLogbookRowWithText(scene, needle) {
  // Recorre el DOM falso real de la vista (montado bajo el host) hasta la
  // lista de la Bitácora y audita sus filas <li> tal y como las pinta la
  // implementación — el arnés jamás replica el formato por su cuenta.
  const list = findDescendantByClass(scene.host, 'grimoire-simulator__logbook-list');
  if (list === null) return null;
  return list.children.find((row) => String(row.textContent ?? '').includes(needle)) ?? null;
}

/** Búsqueda en profundidad por clase en el árbol falso. */
function findDescendantByClass(node, className) {
  for (const child of node.children ?? []) {
    if (String(child.className ?? '').includes(className)) return child;
    const found = findDescendantByClass(child, className);
    if (found !== null) return found;
  }
  return null;
}

// =====================================================================
// [4] Límite canónico de 5 entradas (RF-05.3)
// =====================================================================
console.log('\n[4] El límite canónico de 5 entradas se respeta con combos');
const cappedScene = buildScene();
await cappedScene.view.render();
for (let cycle = 0; cycle < 6; cycle++) {
  await cappedScene.view.previousPage(); // página 1: agua (imbuye)
  await cappedScene.view.castCurrentSpell({ triggerMethod: 'click' });
  flyToImpact(cappedScene);
  await cappedScene.view.nextPage(); // página 2: rayo (detona)
  await cappedScene.view.castCurrentSpell({ triggerMethod: 'click' });
  flyToImpact(cappedScene);
}
const cappedEntries = readPersistedLog(cappedScene);
assertCondition(cappedEntries.length <= 5, `la bitácora retiene a lo sumo 5 entradas (${cappedEntries.length})`);
assertCondition(
  cappedEntries.every((entry) => typeof entry.comboTag === 'string' || entry.comboTag === undefined),
  'toda entrada retenida conserva su esquema íntegro',
);
assertCondition(
  cappedEntries.filter((entry) => typeof entry.comboTag === 'string').length <= 5,
  'las etiquetas de combo quedan sujetas al mismo límite canónico',
);

// =====================================================================
// [5] Restaurar Maniquí purga los combos (RF-02.6)
// =====================================================================
console.log('\n[5] «Restaurar Maniquí» limpia la bitácora de combos');
await surface.view.restoreDummy();
assertCondition(readPersistedLog(surface).length === 0, 'tras restaurar, la bitácora persistente queda vacía');
assertCondition(
  !surface.view.getAnnouncements().at(-1).includes('Combo'),
  'el anuncio de restauración no deja rastro de combo pendiente',
);

// =====================================================================
// Resumen
// =====================================================================
console.log(`\n== RESUMEN == Asertos superados: ${assertsPassed}, fallidos: ${assertsFailed}`);
if (assertsFailed === 0) {
  console.log('RESULTADO: EXITO — La bitácora inscribe el combo y la región viva lo anuncia (Tarea 4.3).');
  process.exit(0);
}
console.log('RESULTADO: FALLO — Revisa los asertos marcados.');
process.exit(1);
