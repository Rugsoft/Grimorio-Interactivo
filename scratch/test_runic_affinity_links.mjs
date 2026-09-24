/**
 * test_runic_affinity_links.mjs — Arnés TDD de la Tarea 4.4 (TASKS-06).
 *
 * Estrategia TDD: este script se escribe ANTES que la implementación.
 * Verifica, sobre `public/assets/js/components/grimoireBookComponent.js`:
 *   [1] Cada ficha de conjuro exhibe un acceso directo rúnico (glifo
 *       botón) junto a la afinidad (RF-01.3).
 *   [2] El glifo viaja con el conjuro: cambia de rótulo/elemento al
 *       hojear y porta `data-element` correcto.
 *   [3] Pulsar el glifo emite el evento canónico `grimoire:codex-focus`
 *       con `elementId` (RF-01.3) y posee accesibilidad (aria-label con
 *       el nombre del elemento, RNF-03).
 *   [4] La vista del simulador escucha `grimoire:codex-focus` y llama a
 *       `elementalWheel.highlightElement` (Códice preseleccionado con sus
 *       enlaces iluminados) — criterio «Hecho cuando».
 *   [5] Al cambiar de página (navegación del Tomo) el foco del Códice no
 *       se corrompe: el evento solo sale por pulsación explícita.
 *
 * Criterio «Hecho cuando» (Tarea 4.4): pulsar el glifo elemental de una
 * ficha abre el Códice con dicho elemento preseleccionado y sus enlaces
 * iluminados.
 *
 * Constitución:
 *   - Artículo I (Dogma Vanilla): DOM nativo, cero frameworks.
 *   - Artículo V: identificadores en inglés camelCase; solemne castellano
 *     en las etiquetas visibles.
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
// Dobles: documento y árbol DOM falso (mismo patrón que los arneses 4.x).
// ---------------------------------------------------------------------

function createFakeElement(tagName, ownerDocument = null) {
  const element = {
    tagName: tagName.toUpperCase(),
    ownerDocument,
    children: [],
    parentElement: null,
    parentNode: null,
    attributes: new Map(),
    classes: new Set(),
    listeners: {},
    style: { setProperty() {}, removeProperty() {} },
    disabled: false,
    hidden: false,
    value: '',
    type: '',
    _textContent: '',
    className: '',
    appendChild(child) { child.parentNode = this; child.parentElement = this; this.children.push(child); return child; },
    replaceChildren(...next) { for (const c of this.children) { c.parentNode = null; c.parentElement = null; } this.children = next; for (const c of next) { c.parentNode = this; c.parentElement = this; } },
    setAttribute(name, value) {
      this.attributes.set(name, String(value));
      // Sincronía atributo/propiedad (el SVG del Códice se classa vía
      // setAttribute('class', …)): className y classes reflejan el cambio.
      if (name === 'class') {
        this.className = String(value);
        this.classes = new Set(String(value).split(/\s+/).filter(Boolean));
      }
    },
    getAttribute(name) { return this.attributes.has(name) ? this.attributes.get(name) : null; },
    removeAttribute(name) { this.attributes.delete(name); },
    hasAttribute(name) { return this.attributes.has(name); },
    addEventListener(name, listener) { (this.listeners[name] ??= []).push(listener); },
    removeEventListener(name, listener) { this.listeners[name] = (this.listeners[name] ?? []).filter((l) => l !== listener); },
    dispatchEvent(event) {
      // Propagación burbujeante completa (los enlaces deben alcanzar el shell).
      let node = this;
      while (node) { (node.listeners?.[event?.type] ?? []).forEach((l) => l(event)); if (event?.bubbles === false) break; node = node.parentElement ?? node.parentNode ?? null; }
      return true;
    },
    remove() { if (this.parentElement) { const siblings = this.parentElement.children; const i = siblings.indexOf(this); if (i >= 0) siblings.splice(i, 1); this.parentElement = null; this.parentNode = null; } },
    querySelector(selector) { return this.querySelectorAll(selector)[0] ?? null; },
    querySelectorAll(selector) {
      // Selector de clase simple (mismo contrato que los arneses legados
      // de la Rueda Rúnica): '.elemental-wheel__filament' etc. Coincidencia
      // EXACTA de token de clase — nunca por subcadena (plate ≠ plate-title).
      const classWanted = String(selector).startsWith('.') ? String(selector).slice(1) : null;
      const found = [];
      const visit = (node) => {
        for (const child of node.children ?? []) {
          if (classWanted !== null && child.classes?.has(classWanted)) found.push(child);
          visit(child);
        }
      };
      visit(this);
      return found;
    },
    focus() {},
    click() { (this.listeners?.click ?? []).forEach((l) => l({ type: 'click', bubbles: true })); },
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

function createStorage() {
  const map = new Map();
  return {
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
const wheelModule = await import('../public/assets/js/components/elementalWheelComponent.js');
const { createElementalWheelComponent } = wheelModule;

function buildScene({ wheel } = {}) {
  const doc = createFakeDoc();
  const sceneRaf = createRaf();
  const host = createFakeElement('main', doc);
  const canvas = createFakeElement('canvas', doc);
  const busEvents = [];
  const options = {
    grimoireClient: createClient(),
    speechService: speech,
    elementFactory: (t) => createFakeElement(t, doc),
    document: doc,
    canvas,
    ctx: canvas.__ctx,
    raf: sceneRaf.raf,
    caf: sceneRaf.caf,
    clock,
    storage: createStorage(),
    motionQuery: { matches: false, addEventListener() {}, removeEventListener() {} },
  };
  if (wheel) {
    options.elementalWheel = wheel;
  }
  const view = createGrimoireSimulatorView(host, options);
  for (const name of ['grimoire:codex-focus']) {
    host.addEventListener(name, (e) => busEvents.push({ name, detail: e?.detail ?? null }));
  }
  return { view, host, doc, raf: sceneRaf, canvas, busEvents };
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

/** Recoge en profundidad los descendientes que casan con el predicado. */
function collectWhere(node, predicate, collected = []) {
  for (const child of node.children ?? []) {
    if (predicate(child)) collected.push(child);
    collectWhere(child, predicate, collected);
  }
  return collected;
}

// =====================================================================
// [1] El glifo rúnico vive en la ficha (RF-01.3)
// =====================================================================
console.log('[1] La ficha del conjuro exhibe su acceso directo rúnico');
const scene = buildScene();
await scene.view.render();
const glyph = findDescendantByClass(scene.host, 'grimoire-book__spell-element-rune');
assertCondition(glyph !== null, 'la ficha contiene el glifo rúnico de acceso al Códice');
assertCondition(glyph?.tagName === 'BUTTON', 'el glifo es un botón (operable por teclado y puntero)');
assertCondition(glyph?.getAttribute('data-element') === 'water', `el glifo porta el elemento de la ficha (data-element="${glyph?.getAttribute('data-element')}")`);
assertCondition(
  String(glyph?.getAttribute('aria-label') ?? '').includes('Agua'),
  `el glifo anuncia su destino a lectores de pantalla («${glyph?.getAttribute('aria-label')}»)`,
);

// =====================================================================
// [2] El glifo viaja con el conjuro al hojear (RF-01.3)
// =====================================================================
console.log('\n[2] El glifo sigue a la ficha al cambiar de página');
await scene.view.nextPage(); // página 2: Flecha Fulgurante (rayo)
assertCondition(glyph?.getAttribute('data-element') === 'lightning', 'al hojear a Rayo, el glifo porta data-element="lightning"');
const glyphOnPage2 = findDescendantByClass(scene.host, 'grimoire-book__spell-element-rune');
assertCondition(glyphOnPage2 === glyph, 'es el mismo nodo repintado (el glifo es estable entre páginas)');
await scene.view.previousPage();
assertCondition(glyph?.getAttribute('data-element') === 'water', 'de regreso en Agua, el glifo restaura su elemento');

// =====================================================================
// [3] Pulsar el glifo emite grimoire:codex-focus (RF-01.3, RNF-03)
// =====================================================================
console.log('\n[3] La pulsación del glifo enfoca el Códice');
glyph.click();
assertCondition(scene.busEvents.length === 1, 'la pulsación emite grimoire:codex-focus');
assertCondition(
  scene.busEvents[0]?.detail?.elementId === 'water',
  `el evento porta elementId del elemento de la ficha (${scene.busEvents[0]?.detail?.elementId})`,
);
assertCondition(
  scene.busEvents[0]?.detail?.source === 'bookRune',
  'el evento declara su origen (source: bookRune) para trazabilidad',
);

// =====================================================================
// [4] La vista ilumina el Códice: criterio «Hecho cuando» (RF-01.3)
// =====================================================================
console.log('\n[4] La vista preselecciona el elemento en la Rueda Rúnica');
const focusCalls = [];
const fakeWheel = {
  mount() {},
  highlightElement: (elementId) => focusCalls.push(elementId),
  clearHighlight() {},
  getSelectedElement: () => focusCalls.at(-1) ?? null,
  isMobileLayout: () => false,
  destroy() {},
};
const wiredScene = buildScene({ wheel: fakeWheel });
await wiredScene.view.render();
await wiredScene.view.nextPage(); // página 2: rayo
const wiredGlyph = findDescendantByClass(wiredScene.host, 'grimoire-book__spell-element-rune');
wiredGlyph.click();
assertCondition(focusCalls.length === 1, 'la vista deriva el foco a la Rueda Rúnica');
assertCondition(focusCalls[0] === 'lightning', `el Códice queda preseleccionado en el elemento de la ficha (${focusCalls[0]}) — enlaces iluminados`);

// =====================================================================
// [5] Navegación del Tomo no enfoca el Códice (solo pulsación explícita)
// =====================================================================
console.log('\n[5] La navegación entre páginas jamás enfoca el Códice');
const before = focusCalls.length;
await wiredScene.view.previousPage();
await wiredScene.view.nextPage();
await wiredScene.view.restoreDummy();
assertCondition(focusCalls.length === before, 'hojear y restaurar no disparan grimoire:codex-focus ni resaltados');

// =====================================================================
// [6] La Rueda real ilumina el elemento pedido (verificación cruzada)
// =====================================================================
console.log('\n[6] Verificación cruzada: highlightElement ilumina filamentos en la Rueda real');
const codexDoc = createFakeDoc();
const codexHost = createFakeElement('div', codexDoc);
const catalog = {
  elements: [
    { id: 'water', name: 'Agua', glyph: 'rune-aqu', heraldicColor: '#00bfff', opposite: 'fire' },
    { id: 'lightning', name: 'Rayo', glyph: 'rune-ful', heraldicColor: '#e6c34a', opposite: 'earth' },
  ],
  reactions: [
    { id: 'fluidElectrocution', name: 'Electrocución Fluida', elements: ['water', 'lightning'], isCatalyst: false, damageMultiplier: 1.5, tacticalEffect: 'hardStun', effectDurationMs: 1500, description: 'd' },
  ],
};
const realWheel = createElementalWheelComponent({
  host: codexHost,
  catalog,
  document: codexDoc,
  createElement: (t) => createFakeElement(t, codexDoc),
});
realWheel.mount(codexHost);
realWheel.highlightElement('water');
// La preselección de la Rueda real se manifiesta en filamentos encendidos
// (elemental-wheel__filament--lit) y lámina del Códice pintada (RF-01.2).
const litFilaments = codexHost.querySelectorAll('.elemental-wheel__filament--lit');
assertCondition(litFilaments.length === 1, `la Rueda real ilumina el filamento del elemento enfocado (${litFilaments.length} encendido)`);
assertCondition(
  codexHost.querySelectorAll('.elemental-wheel__plate').length === 1,
  'la Rueda real pinta la lámina del elemento enfocado (preselección del Códice)',
);

// =====================================================================
// Resumen
// =====================================================================
console.log(`\n== RESUMEN == Asertos superados: ${assertsPassed}, fallidos: ${assertsFailed}`);
if (assertsFailed === 0) {
  console.log('RESULTADO: EXITO — El glifo de la ficha enfoca el Códice con enlaces iluminados (Tarea 4.4).');
  process.exit(0);
}
console.log('RESULTADO: DENEGADO — Revisa los asertos marcados.');
process.exit(1);
