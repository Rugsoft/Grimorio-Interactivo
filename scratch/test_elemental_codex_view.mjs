/**
 * test_elemental_codex_view.mjs — Arnés TDD de la Tarea 5.2 (TASKS-06).
 *
 * Estrategia TDD: este script se escribe ANTES que la implementación.
 * Verifica, sobre `public/assets/js/views/elementalCodexView.js`:
 *   [1] Fábrica y ciclo de vida: render() monta, destroy() limpia.
 *   [2] Carga autónoma: fetchMatrixGraph() alimenta la Rueda Rúnica real
 *       (RF-01.1); el fallo de corriente degrada con leyenda solemne y
 *       reintento (RNF-05 / patrón de rescate de SPEC-05).
 *   [3] Consulta de compatibilidades: al pulsar un glifo, la vista llama
 *       fetchReactionsForElement(element) y la lámina refleja las
 *       aristas del elemento (RF-01.2).
 *   [4] Sincronización desacoplada con el Simulador: la vista escucha
 *       `grimoire:codex-focus` (enlace rúnico de la Tarea 4.4) y emite
 *       `grimoire:codex-resolve` con el payload del combo para que el
 *       orquestador consulte la resolución autoritativa vía resolveCombo
 *       (RF-04.1) — sin imports cruzados entre vistas.
 *   [5] Vista móvil: con la consulta de medios en marcha, el Códice monta
 *       el acordeón (Tarea 3.3) y el foco despliega la hoja correcta.
 *
 * Criterio «Hecho cuando» (Tarea 5.2): el Códice opera de forma autónoma
 * y sincronizada con el resto del portal arcano mediante eventos
 * desacoplados.
 *
 * Constitución:
 *   - Artículo I (Dogma Vanilla): ES Modules nativos, DOM estándar.
 *   - Artículo V: identificadores en inglés camelCase; comentarios en
 *     castellano.
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
// Dobles: documento falso con SVG y búsqueda por clase exacta.
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
    className: '',
    _textContent: '',
    appendChild(child) { child.parentNode = this; child.parentElement = this; this.children.push(child); return child; },
    replaceChildren(...next) { for (const c of this.children) { c.parentNode = null; c.parentElement = null; } this.children = next; for (const c of next) { c.parentNode = this; c.parentElement = this; } },
    setAttribute(name, value) {
      this.attributes.set(name, String(value));
      if (name === 'class') { this.className = String(value); this.classes = new Set(String(value).split(/\s+/).filter(Boolean)); }
    },
    getAttribute(name) { return this.attributes.has(name) ? this.attributes.get(name) : null; },
    removeAttribute(name) { this.attributes.delete(name); },
    hasAttribute(name) { return this.attributes.has(name); },
    addEventListener(name, listener) { (this.listeners[name] ??= []).push(listener); },
    removeEventListener(name, listener) { this.listeners[name] = (this.listeners[name] ?? []).filter((l) => l !== listener); },
    dispatchEvent(event) {
      let node = this;
      while (node) { (node.listeners?.[event?.type] ?? []).forEach((l) => l(event)); if (event?.bubbles === false) break; node = node.parentElement ?? node.parentNode ?? null; }
      return true;
    },
    remove() { if (this.parentElement) { const siblings = this.parentElement.children; const i = siblings.indexOf(this); if (i >= 0) siblings.splice(i, 1); this.parentElement = null; this.parentNode = null; } },
    querySelector(selector) { return this.querySelectorAll(selector)[0] ?? null; },
    querySelectorAll(selector) {
      // Coincidencia por clase (token exacto) O por etiqueta — el doble
      // es la columna vertebral de la búsqueda en la vista.
      const selectorText = String(selector);
      const classWanted = selectorText.startsWith('.') ? selectorText.slice(1) : null;
      const tagWanted = classWanted === null ? selectorText.toLowerCase() : null;
      const found = [];
      const visit = (node) => {
        for (const child of node.children ?? []) {
          if (classWanted !== null && child.classes?.has(classWanted)) found.push(child);
          if (tagWanted !== null && String(child.tagName ?? '').toLowerCase() === tagWanted) found.push(child);
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
  };
  return doc;
}

// ---------------------------------------------------------------------
// Dobles del cliente HTTP y de la fábrica de la Rueda.
// ---------------------------------------------------------------------

const GRAPH = {
  elements: [
    { id: 'water', name: 'Agua', glyph: 'rune-aqu', color: '#00bfff', description: 'Fluencia y calma' },
    { id: 'lightning', name: 'Rayo', glyph: 'rune-ful', color: '#e6c34a', description: 'Celeridad y fulgor' },
  ],
  reactions: [
    { id: 'fluidElectrocution', name: 'Electrocución Fluida', elements: ['water', 'lightning'], isCatalyst: false, damageMultiplier: 1.5, tacticalEffect: 'hardStun', effectDurationMs: 1500, description: 'El vapor condensa el torrente.' },
  ],
};

function createClientStub({ failGraph = false } = {}) {
  const calls = { matrix: 0, reactions: [], resolve: [] };
  const state = { failGraph };
  return {
    calls,
    /** Conmuta el fallo en caliente (el arnés rescata el Códice). */
    set failGraph(value) { state.failGraph = value; },
    get failGraph() { return state.failGraph; },
    async fetchMatrixGraph() {
      calls.matrix += 1;
      if (state.failGraph) {
        return { success: false, error: { code: 'ELEMENTAL_STREAM_INTERRUPTED', message: 'La corriente de maná hacia el Códice se interrumpió.' } };
      }
      return { success: true, data: GRAPH };
    },
    async fetchReactionsForElement(element) {
      calls.reactions.push(element);
      const dual = GRAPH.reactions.filter((r) => !r.isCatalyst && r.elements.includes(element));
      return { success: true, data: { element, dualReactions: dual, catalystReactions: [] } };
    },
    async resolveCombo(data) {
      calls.resolve.push(data);
      return { success: true, data: { isReaction: true, reactionId: 'fluidElectrocution', reactionName: 'Electrocución Fluida', effectiveDamage: 135, clearedAura: true } };
    },
  };
}

function createWheelSpy() {
  const calls = { mounts: [], highlights: [], clears: 0, destroyed: false };
  return {
    calls,
    create({ host, catalog }) {
      calls.mounts.push(catalog);
      return {
        mount(target) { void target; },
        highlightElement: (id) => calls.highlights.push(id),
        clearHighlight: () => { calls.clears += 1; },
        getSelectedElement: () => calls.highlights.at(-1) ?? null,
        isMobileLayout: () => false,
        destroy: () => { calls.destroyed = true; },
      };
    },
  };
}

const viewModule = await import('../public/assets/js/views/elementalCodexView.js');
const { createCodexView } = viewModule;

// =====================================================================
// [0] Superficie
// =====================================================================
console.log('[0] Superficie de la vista ceremonial');
assertCondition(typeof createCodexView === 'function', 'el módulo exporta la fábrica createCodexView');

// =====================================================================
// [1] Ciclo de vida: render() y destroy()
// =====================================================================
console.log('\n[1] Ciclo de vida');
{
  const doc = createFakeDoc();
  const host = createFakeElement('main', doc);
  const client = createClientStub();
  const wheelSpy = createWheelSpy();
  const view = createCodexView(host, {
    elementalMatrixClient: client,
    createRuneWheel: wheelSpy.create,
    document: doc,
    elementFactory: (t) => createFakeElement(t, doc),
  });
  await view.render();
  assertCondition(host.children.length > 0, 'render() monta el Códice en el punto de anclaje');
  assertCondition(wheelSpy.calls.mounts.length === 1, 'la Rueda Rúnica se construye con el catálogo cargado');
  assertCondition(wheelSpy.calls.mounts[0]?.elements?.length === 2, 'el grafo de la API alimenta la Rueda (RF-01.1)');
  view.destroy();
  assertCondition(wheelSpy.calls.destroyed === true, 'destroy() desmonta la Rueda');
  assertCondition(host.children.length === 0, 'destroy() limpia el punto de anclaje');
}

// =====================================================================
// [2] Autonomía: fallo de corriente con rescate (RNF-05)
// =====================================================================
console.log('\n[2] Fallo de corriente: leyenda solemne y reintento');
{
  const doc = createFakeDoc();
  const host = createFakeElement('main', doc);
  const client = createClientStub({ failGraph: true });
  const wheelSpy = createWheelSpy();
  const view = createCodexView(host, {
    elementalMatrixClient: client,
    createRuneWheel: wheelSpy.create,
    document: doc,
    elementFactory: (t) => createFakeElement(t, doc),
  });
  await view.render();
  assertCondition(wheelSpy.calls.mounts.length === 0, 'sin grafo no se monta Rueda alguna');
  const bodyText = host.textContent;
  assertCondition(bodyText.includes('corriente de maná'), 'la leyenda de fallo es solemne y castellana');
  const retryButton = host.querySelectorAll('button').find((b) => b.getAttribute('data-action') === 'retry');
  assertCondition(retryButton !== undefined, 'existe el botón de reintento');
  client.failGraph = false;
  retryButton.click();
  // Dos saltos de macrotask: el clic lanza loadMatrix (async) y la
  // resolución de fetchMatrixGraph añade otro microtask antes de montar.
  await new Promise((resolve) => setTimeout(resolve, 0));
  await new Promise((resolve) => setTimeout(resolve, 0));
  assertCondition(wheelSpy.calls.mounts.length === 1, 'el reintento recarga el grafo y monta la Rueda');
  view.destroy();
}

// =====================================================================
// [3] Consulta de compatibilidades (RF-01.2)
// =====================================================================
console.log('\n[3] La consulta de compatibilidades pasa por el cliente');
{
  const doc = createFakeDoc();
  const host = createFakeElement('main', doc);
  const client = createClientStub();
  const wheelSpy = createWheelSpy();
  const view = createCodexView(host, {
    elementalMatrixClient: client,
    createRuneWheel: wheelSpy.create,
    document: doc,
    elementFactory: (t) => createFakeElement(t, doc),
  });
  await view.render();
  const compatibility = await view.getReactionsForElement('water');
  assertCondition(client.calls.reactions.includes('water'), 'la consulta viaja por fetchReactionsForElement');
  assertCondition(compatibility.success === true && compatibility.data?.dualReactions?.length === 1, 'las aristas del elemento vuelven íntegras (Electrocución Fluida)');
  view.destroy();
}

// =====================================================================
// [4] Sincronización desacoplada con el Simulador (RF-01.3, RF-04.1)
// =====================================================================
console.log('\n[4] Eventos desacoplados: codex-focus entrante, codex-resolve saliente');
{
  const doc = createFakeDoc();
  const host = createFakeElement('main', doc);
  const client = createClientStub();
  const wheelSpy = createWheelSpy();
  const outgoingEvents = [];
  const view = createCodexView(host, {
    elementalMatrixClient: client,
    createRuneWheel: wheelSpy.create,
    document: doc,
    elementFactory: (t) => createFakeElement(t, doc),
    eventTarget: host,
  });
  await view.render();
  host.addEventListener('grimoire:codex-resolve', (e) => outgoingEvents.push(e?.detail ?? null));

  // Entrante: el enlace rúnico del Tomo (Tarea 4.4) enfoca el Códice.
  const focusEvent = { type: 'grimoire:codex-focus', detail: { elementId: 'lightning', source: 'bookRune' }, bubbles: true };
  host.dispatchEvent(focusEvent);
  assertCondition(wheelSpy.calls.highlights.at(-1) === 'lightning', 'grimoire:codex-focus preselecciona el elemento en la Rueda');

  // Saliente: la vista emite grimoire:codex-resolve con el payload del combo.
  await view.requestComboResolution({
    activeAura: 'water',
    incomingSpell: { id: 'l', element: 'lightning', baseDamage: 90, baseHealing: 0, baseBarrier: 0, crowdControlType: 'none' },
    stunlockImmune: false,
  });
  assertCondition(client.calls.resolve.length === 1, 'la resolución pasa por resolveCombo del cliente (RF-04.1)');
  assertCondition(outgoingEvents.length === 1, 'la vista emite grimoire:codex-resolve hacia el portal');
  assertCondition(outgoingEvents[0]?.verdict?.reactionId === 'fluidElectrocution', 'el veredicto autoritativo viaja en el evento');
  assertCondition(outgoingEvents[0]?.verdict?.effectiveDamage === 135, 'el daño amplificado viaja íntegro (ceil(90 × 1.5))');
  view.destroy();
}

// =====================================================================
// [5] Vista móvil: el foco despliega el acordeón (Tarea 3.3)
// =====================================================================
console.log('\n[5] Vista móvil del Códice');
{
  const doc = createFakeDoc();
  const host = createFakeElement('main', doc);
  const client = createClientStub();
  // Doble de Rueda que declara layout móvil y despliega el acordeón.
  const mobileWheel = {
    create() {
      return {
        mount() {},
        highlightElement(id) { this.lastHighlight = id; },
        clearHighlight() {},
        getSelectedElement() { return this.lastHighlight ?? null; },
        isMobileLayout: () => true,
        destroy() {},
      };
    },
  };
  const view = createCodexView(host, {
    elementalMatrixClient: client,
    createRuneWheel: mobileWheel.create,
    document: doc,
    elementFactory: (t) => createFakeElement(t, doc),
  });
  await view.render();
  assertCondition(typeof view.isMobileLayout === 'function', 'la vista expone el estado de layout (isMobileLayout)');
  assertCondition(view.isMobileLayout() === true, 'la vista declara el layout móvil de la Rueda');
  view.destroy();
}

// =====================================================================
// Resumen
// =====================================================================
console.log(`\n== RESUMEN == Asertos superados: ${assertsPassed}, fallidos: ${assertsFailed}`);
if (assertsFailed === 0) {
  console.log('RESULTADO: EXITO — El Códice opera autónomo y sincronizado por eventos (Tarea 5.2).');
  process.exit(0);
}
console.log('RESULTADO: FALLO — Revisa los asertos marcados.');
process.exit(1);
