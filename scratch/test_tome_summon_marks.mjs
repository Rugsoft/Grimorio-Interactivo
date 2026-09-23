/**
 * test_tome_summon_marks.mjs — Arnés de la Tarea 5.4 (TASKS-11).
 *
 * Valida LAS MARCAS SOLEMNES Y LA CONVOCATORIA DESDE EL TOMO contra el
 * «Hecho cuando» de la tarea:
 *
 *   1. Cada entrada no viva muestra su marca EXACTA («Obra en gestación»
 *      / «Obra apartada del canon», Anexo A #7) — RF-03.2, mapa único
 *      consumido del DTO (la vista jamás traduce estados).
 *   2. La convocatoria está VEDADA para entradas no vivas (casos límite
 *      2 y 7): el gesto no enruta y la leyenda solemne se narra (RF-03.2).
 *   3. La convocatoria de una entrada VIVA enruta hacia el Simulador
 *      (RF-03.1) con el slug del hechizo — SPEC-05 sin variantes: el
 *      motor de partículas queda intacto (sin instancias nuevas).
 *   4. El Simulador orquesta la página convocada: `initialSpellSlug`
 *      ilumina la página del hechizo tras cargar su catálogo, sin tocar
 *      el motor (arnés de doble de cliente, sin lienzo real).
 *
 * Constitución:
 *   - Artículo I (Dogma Vanilla): DOM simulado mínimo, ES Modules nativos.
 *   - Artículo V: asertos en inglés, comentarios en castellano.
 *
 * Uso: node scratch/test_tome_summon_marks.mjs
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
// DOM simulado mínimo (patrón consolidado de los arneses de SPEC-10/11).
// ---------------------------------------------------------------------

function createObservableElement(tagName) {
  const element = {
    tagName: String(tagName).toUpperCase(),
    children: [],
    attributes: new Map(),
    classes: new Set(),
    textContent: '',
    parentNode: null,
    listeners: new Map(),
    className: '',
    type: null,
    value: '',
    disabled: false,
    style: { setProperty() {} },
    open: false,
    returnValue: '',
    focused: false,
    setAttribute(name, value) {
      element.attributes.set(name, String(value));
      if (name === 'class') {
        element.classes = new Set(String(value).split(/\s+/).filter(Boolean));
        element.className = String(value);
      }
      if (name === 'value') element.value = String(value);
    },
    getAttribute(name) {
      return element.attributes.has(name) ? element.attributes.get(name) : null;
    },
    hasAttribute(name) {
      return element.attributes.has(name);
    },
    removeAttribute(name) {
      element.attributes.delete(name);
      if (name === 'hidden') element.hiddenRemoved = true;
    },
    appendChild(child) {
      child.parentNode = element;
      element.children.push(child);
      return child;
    },
    replaceChildren() {
      element.children.splice(0);
    },
    remove() {
      if (element.parentNode) {
        const siblings = element.parentNode.children;
        const index = siblings.indexOf(element);
        if (index !== -1) siblings.splice(index, 1);
      }
    },
    showModal() { element.open = true; },
    close(returnValue = '') {
      if (!element.open) return;
      element.open = false;
      element.returnValue = returnValue;
      for (const handler of element.listeners.get('close') ?? []) handler({});
    },
    focus() { element.focused = true; },
    addEventListener(type, handler) {
      if (!element.listeners.has(type)) element.listeners.set(type, []);
      element.listeners.get(type).push(handler);
    },
    removeEventListener(type, handler) {
      const list = element.listeners.get(type) ?? [];
      const index = list.indexOf(handler);
      if (index !== -1) list.splice(index, 1);
    },
    dispatch(type, event = {}) {
      for (const handler of element.listeners.get(type) ?? []) {
        handler({ currentTarget: element, target: element, preventDefault() {}, ...event });
      }
    },
    dispatchEvent(customEvent) {
      element.dispatch(customEvent.type, customEvent);
    },
    click() { element.dispatch('click'); },
  };
  Object.defineProperty(element, 'classList', {
    value: {
      add: (...names) => names.forEach((n) => element.classes.add(n)),
      remove: (...names) => names.forEach((n) => element.classes.delete(n)),
      contains: (name) => element.classes.has(name),
      toggle(name, force) {
        const has = element.classes.has(name);
        const want = force === undefined ? !has : Boolean(force);
        if (want) element.classes.add(name); else element.classes.delete(name);
        return want;
      },
    },
  });
  Object.defineProperty(element, 'hidden', {
    get: () => element.hiddenRemoved !== true && element.attributes.get('hidden') !== undefined,
    set: (v) => { if (v) element.attributes.set('hidden', ''); else element.hiddenRemoved = true; },
  });
  return element;
}

const documentSim = { createElement: (tagName) => createObservableElement(tagName) };
class CustomEventSim {
  constructor(type, options = {}) { this.type = type; this.detail = options.detail ?? null; }
}
const windowSim = { CustomEvent: CustomEventSim };

const {
  createGrimoireCollectionView,
  TOME_MARK_LABELS,
  SUMMON_VETO_LEGENDS,
  LIVING_TOME_MARK,
} = await import('../public/assets/js/views/grimoireCollectionView.js');

function findDescendants(root, predicate, found = []) {
  if (root === null) return found;
  for (const child of root.children ?? []) {
    if (predicate(child)) found.push(child);
    findDescendants(child, predicate, found);
  }
  return found;
}

function findByClass(root, className) {
  return findDescendants(
    root,
    (n) => n.classList?.contains?.(className)
      || (typeof n.className === 'string' && n.className.split(/\s+/).includes(className)),
  );
}

/** Sobre canónico del contrato (plan §2.2). */
function envelope(entries, overrides = {}) {
  return {
    success: true,
    status: 200,
    data: { entries, total: entries.length, page: 1, limit: 50, totalPages: 1, ...overrides },
  };
}

function entryDto(spellId, spellName, mark = 'living') {
  return {
    spell: { id: spellId, slug: `slug-${spellId}`, name: spellName, status: 'validated', magicSchool: 'evocation', magicSchoolLabel: 'Evocación', manaCost: 10, summary: '', clanName: '', clanId: '' },
    addedAt: '2026-09-22T10:00:00Z',
    tomeMark: mark,
    praiseStatus: { praised: false, allowed: mark === 'living' },
  };
}

/** Cliente doble del tomo: sobre fijo y registro de llamadas. */
function createClientStub(response) {
  const calls = [];
  return {
    calls,
    async fetchCollection() {
      calls.push({ method: 'fetchCollection' });
      return response;
    },
    async collectSpell(spellId) {
      calls.push({ method: 'collectSpell', spellId });
      return { success: true, status: 200, data: { alreadyCollected: true, addedAt: '' } };
    },
    async discardSpell(spellId, element) {
      calls.push({ method: 'discardSpell', spellId, element });
      return { success: true, status: 200, data: { removed: true, total: 0 } };
    },
    async praiseSpell(spellId) {
      calls.push({ method: 'praiseSpell', spellId });
      return { success: true, status: 200, data: { praised: true, reason: 'AWARDED' } };
    },
  };
}

/** Monta la vista con captura del gesto de convocatoria. */
function mountView(collectionClient, options = {}) {
  const appRoot = createObservableElement('main');
  const summoned = [];
  const view = createGrimoireCollectionView(appRoot, {
    collectionClient,
    onSummonSpell: options.onSummonSpell ?? ((slug) => summoned.push(slug)),
    elementFactory: (tagName) => createObservableElement(tagName),
    documentRef: documentSim,
    eventTarget: appRoot,
    ...options,
  });
  return { view, appRoot, summoned };
}

/** Activa la selección de tarjeta sobre la entrada cuyo slug coincide. */
function summonCardBySpellId(root, spellId) {
  // La tarjeta porta data-slug = 'slug-<id>' (contrato de la tarjeta
  // compartida): localizarla por slug evita depender del orden pintado.
  const cards = findByClass(root, 'spell-card');
  const card = cards.find((c) => c.getAttribute('data-slug') === `slug-${spellId}`) ?? null;
  if (card === null) return false;
  card.dispatch('click');
  return true;
}

// =====================================================================
// [1] Marcas solemnes exactas en las entradas no vivas (RF-03.2, Anexo A #7)
// =====================================================================
console.log('[1] Marcas solemnes: textos literales del Anexo A');
assertCondition(TOME_MARK_LABELS.gestation === 'Obra en gestación', 'el rótulo de gestación es el LITERAL del Anexo A');
assertCondition(TOME_MARK_LABELS.withdrawn === 'Obra apartada del canon', 'el rótulo de retirada del canon es el LITERAL del Anexo A');
assertCondition(TOME_MARK_LABELS.living === 'Obra viva', 'la obra viva porta su rótulo propio');

const SOBRE = envelope([
  entryDto('spl_viva', 'Llama Eterna', 'living'),
  entryDto('spl_gest', 'Brote de Aether', 'gestation'),
  entryDto('spl_apart', 'Eco Proscrito', 'withdrawn'),
]);
const client1 = createClientStub(SOBRE);
const { view: view1, appRoot: root1 } = mountView(client1);
await view1.render();

const gestationMarks = findByClass(root1, 'spell-card__tome-mark--gestation');
const withdrawnMarks = findByClass(root1, 'spell-card__tome-mark--withdrawn');
assertCondition(gestationMarks.length === 1 && gestationMarks[0].textContent === 'Obra en gestación',
  'la entrada en gestación muestra «Obra en gestación» con su clase solemne');
assertCondition(withdrawnMarks.length === 1 && withdrawnMarks[0].textContent === 'Obra apartada del canon',
  'la entrada apartada muestra «Obra apartada del canon» con su clase solemne');
assertCondition(
  findByClass(root1, 'spell-card').every((c) => c.getAttribute('data-tome-mark') !== null),
  'cada tarjeta porta su data-tome-mark leído del DTO',
);

// =====================================================================
// [2] Convocatoria VEDADA para entradas no vivas (RF-03.2, casos límite 2 y 7)
// =====================================================================
console.log('\n[2] Vedación de convocatoria en entradas no vivas');
const summoned2 = [];
summonCardBySpellId(root1, 'spl_gest');
summonCardBySpellId(root1, 'spl_apart');
assertCondition(summoned2.length === 0, 'NINGUNA entrada no viva enruta hacia el Simulador');

// La leyenda solemne se narra (región viva, sin lenguaje de error técnico).
const liveRegion1 = findByClass(root1, 'collection-view__live')[0] ?? null;
const narrated = liveRegion1?.textContent ?? '';
assertCondition(narrated === SUMMON_VETO_LEGENDS.gestation || narrated === SUMMON_VETO_LEGENDS.withdrawn,
  'la leyenda solemne de vedación se narra en la región viva');
assertCondition(!/\b(403|error|HTTP)\b/i.test(narrated), 'la leyenda no porta tecnicismos (RNF-03, guard de soberanía)');
assertCondition(typeof LIVING_TOME_MARK === 'string' && LIVING_TOME_MARK === 'living',
  'la constante de la marca viva queda exportada (contrato de la vista)');

// =====================================================================
// [3] Convocatoria de una entrada VIVA (RF-03.1)
// =====================================================================
console.log('\n[3] Convocatoria de una entrada viva hacia el Simulador');
const summoned3 = [];
// La vista 1 ya montó con su propio sumidero (view1); la reconvocamos
// contra una vista nueva con sumidero fresco para asertar sin ruido.
const client3 = createClientStub(envelope([
  entryDto('spl_viva', 'Llama Eterna', 'living'),
  entryDto('spl_gest', 'Brote de Aether', 'gestation'),
]));
const { view: view3, appRoot: root3 } = mountView(client3, { onSummonSpell: (slug) => summoned3.push(slug) });
await view3.render();
assertCondition(summonCardBySpellId(root3, 'spl_viva'), 'la tarjeta viva se localiza por data-slug y activa');
assertCondition(summoned3.length === 1 && summoned3[0] === 'slug-spl_viva',
  'la convocatoria de la entrada VIVA enruta al Simulador con su slug (RF-03.1)');

// =====================================================================
// [4] Orquestación del Simulador: la página convocada se ilumina (SPEC-05 intacto)
// =====================================================================
console.log('\n[4] El Simulador orquesta la página convocada sin variantes');
const simModule = await import('../public/assets/js/views/grimoireSimulatorView.js');
const { createGrimoireSimulatorView, CATALOG_MODES } = simModule;

const SPELLS = [
  { id: 's1', slug: 'llama-eterna', name: 'Llama Eterna', circle: 1, elementalAffinity: 'fire', manaCost: 10, incantationFormula: '¡Llama!', description: '', castingTime: 'action' },
  { id: 's2', slug: 'mareas-de-aether', name: 'Mareas de Aether', circle: 2, elementalAffinity: 'water', manaCost: 12, incantationFormula: '¡Marea!', description: '', castingTime: 'action' },
  { id: 's3', slug: 'eco-proscrito', name: 'Eco Proscrito', circle: 3, elementalAffinity: 'darkness', manaCost: 15, incantationFormula: '¡Eco!', description: '', castingTime: 'action' },
];
const grimoireClientStub = {
  async fetchSpells() {
    return { success: true, status: 200, data: { spells: SPELLS } };
  },
};

const simRoot = createObservableElement('main');
const simulatorView = createGrimoireSimulatorView(simRoot, {
  grimoireClient: grimoireClientStub,
  elementFactory: (tagName) => createObservableElement(tagName),
  document: documentSim,
  initialMode: CATALOG_MODES.canonical,
  initialSpellSlug: 'mareas-de-aether',
  // Dobles nulos: sin síntesis ni reconocimiento (el arnés no los ejercita).
  speechSynthesis: null,
  motionQuery: null,
  storage: null,
  // Lienzo simulado: el motor REAL queda fuera del arnés (intacto, jamás
  // instanciado aquí) — la orquestación de página es lo que se valida.
});
await simulatorView.render();
const simState = simulatorView.getState();
assertCondition(simState.currentSpell?.slug === 'mareas-de-aether',
  'el Simulador ilumina la página del hechizo convocado (RF-03.1)');
assertCondition(simState.mode === CATALOG_MODES.canonical,
  'el tomo convocado abre en su modo canónico sin variantes nuevas');

// Sin convocatoria: el comportamiento de SPEC-05 queda EXACTAMENTE igual.
const simRoot2 = createObservableElement('main');
const simulatorView2 = createGrimoireSimulatorView(simRoot2, {
  grimoireClient: grimoireClientStub,
  elementFactory: (tagName) => createObservableElement(tagName),
  document: documentSim,
  initialMode: CATALOG_MODES.canonical,
  speechSynthesis: null,
  motionQuery: null,
  storage: null,
});
await simulatorView2.render();
assertCondition(simulatorView2.getState().currentSpell?.slug === 'llama-eterna',
  'sin convocatoria el tomo abre en su primera página (SPEC-05 intacto)');

// Slug convocado ausente del catálogo: primera página, jamás un pergamino roto.
const simRoot3 = createObservableElement('main');
const simulatorView3 = createGrimoireSimulatorView(simRoot3, {
  grimoireClient: grimoireClientStub,
  elementFactory: (tagName) => createObservableElement(tagName),
  document: documentSim,
  initialMode: CATALOG_MODES.canonical,
  initialSpellSlug: 'fantasma-inexistente',
  speechSynthesis: null,
  motionQuery: null,
  storage: null,
});
await simulatorView3.render();
assertCondition(simulatorView3.getState().currentSpell?.slug === 'llama-eterna',
  'slug fantasma degrada a la primera página (sin pantalla rota)');

// =====================================================================
// Veredicto
// =====================================================================
console.log(`\n=== VEREDICTO: ${assertsPassed}/${assertsPassed + assertsFailed} asertos en verde ===`);
if (assertsFailed > 0) process.exit(1);
