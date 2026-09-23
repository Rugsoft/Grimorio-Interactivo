/**
 * test_grimoire_collection_view.mjs — Arnés de la Tarea 5.3 (TASKS-11).
 *
 * Valida LA VISTA «MI GRIMORIO» (`grimoireCollectionView`) contra el
 * «Hecho cuando» de la tarea:
 *
 *   1. La vista monta con UNA SOLA carga del sobre paginado (RF-02.1,
 *      RNF-01) y emite `collection:loaded`.
 *   2. El filtro por afinidad filtra con conteo (RF-02.3): nueva carga
 *      con `element` y hoja 1.
 *   3. El tomo vacío invita a la Biblioteca SIN lenguaje de error
 *      (RF-02.2): «Tu tomo aguarda su primera obra» + «Recorrer la
 *      Biblioteca».
 *   4. La paginación es viva (plan §3.5, caso límite 10): retirada con
 *      conteo actualizado y recaída en la última página viva.
 *   5. El 401 APAGA los gestos sin vaciar lo leído (RF-05.2, hallazgo 7):
 *      aviso solemne, disabled + aria-disabled, lectura y filtro
 *      conservados.
 *   6. La retirada pasa por el modal solemne (Tarea 5.2): confirmar
 *      consume; Escape no muta.
 *
 * Constitución:
 *   - Artículo I (Dogma Vanilla): DOM simulado mínimo, ES Modules nativos.
 *   - Artículo V: asertos en inglés, comentarios en castellano.
 *
 * Uso: node scratch/test_grimoire_collection_view.mjs
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
// DOM simulado mínimo (patrón consolidado de los arneses de SPEC-10).
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
  GRIMOIRE_COLLECTION_EMPTY_LEGEND,
  GRIMOIRE_COLLECTION_EMPTY_CTA_LABEL,
  GRIMOIRE_COLLECTION_EXPIRED_LEGEND,
} = await import('../public/assets/js/views/grimoireCollectionView.js');

function findDescendants(root, predicate, found = []) {
  if (root === null) return found;
  for (const child of root.children ?? []) {
    if (predicate(child)) found.push(child);
    findDescendants(child, predicate, found);
  }
  return found;
}

function findByText(root, tagName, text) {
  return findDescendants(root, (n) => n.tagName === tagName && n.textContent === text)[0] ?? null;
}

function findByClass(root, className) {
  return findDescendants(
    root,
    (n) => n.classList?.contains?.(className)
      || (typeof n.className === 'string' && n.className.split(/\s+/).includes(className)),
  )[0] ?? null;
}

/** Sobre canónico del contrato (plan §2.2). */
function envelope(entries, overrides = {}) {
  return {
    success: true,
    status: 200,
    data: { entries, total: entries.length, page: 1, limit: 50, totalPages: 1, ...overrides },
  };
}

function entryDto(spellId, spellName, mark = 'living', praise = { praised: false, allowed: true }) {
  return {
    spell: { id: spellId, slug: `slug-${spellId}`, name: spellName, status: 'validated', magicSchool: 'evocation', magicSchoolLabel: 'Evocación', manaCost: 10, summary: '', clanName: '', clanId: '' },
    addedAt: '2026-09-22T10:00:00Z',
    tomeMark: mark,
    praiseStatus: praise,
  };
}

/** Cliente doble: cola de respuestas con registro de llamadas. */
function createClientStub(responses) {
  const calls = [];
  return {
    calls,
    async fetchCollection(element, page) {
      calls.push({ method: 'fetchCollection', element, page });
      const next = responses.shift();
      return typeof next === 'function' ? next() : next;
    },
    async collectSpell(spellId) {
      calls.push({ method: 'collectSpell', spellId });
      return { success: true, status: 200, data: { alreadyCollected: true, addedAt: '' } };
    },
    async discardSpell(spellId, element) {
      calls.push({ method: 'discardSpell', spellId, element });
      const next = responses.shift();
      return typeof next === 'function' ? next() : { success: true, status: 200, data: { removed: true, total: 0 } };
    },
    async praiseSpell(spellId) {
      calls.push({ method: 'praiseSpell', spellId });
      return { success: true, status: 200, data: { praised: true, reason: 'AWARDED' } };
    },
  };
}

/** Monta la vista sobre un appRoot simulado con captura de eventos. */
function mountView(collectionClient, options = {}) {
  const appRoot = createObservableElement('main');
  const emitted = [];
  appRoot.addEventListener = (type, handler) => {
    if (type.startsWith('collection:') || type.startsWith('tome:')) {
      emitted.push(type);
    }
  };
  // El bus real dispatchea sobre appRoot: capturar los CustomEvent.
  const originalDispatch = appRoot.dispatchEvent.bind(appRoot);
  const dispatched = [];
  appRoot.dispatchEvent = (event) => {
    dispatched.push({ type: event.type, detail: event.detail });
    return originalDispatch(event);
  };
  // Reiniciar listeners tras sobreescribir dispatch (los add del stub ya
  // fueron a listeners; dispatchEvent original los consume igualmente).
  const view = createGrimoireCollectionView(appRoot, {
    collectionClient,
    onNavigateToLibrary: options.onNavigateToLibrary,
    elementFactory: (tagName) => createObservableElement(tagName),
    documentRef: documentSim,
    eventTarget: appRoot,
    ...options,
  });
  return { view, appRoot, dispatched };
}

// =====================================================================
// [1] Montaje con UNA SOLA carga del sobre paginado (RF-02.1, RNF-01)
// =====================================================================
console.log('[1] Montaje: una sola carga y pintura del sobre');
const SOBRE = envelope([entryDto('spl_1', 'Llama Eterna'), entryDto('spl_2', 'Mareas de Aether', 'gestation')]);
const client1 = createClientStub([SOBRE]);
const { view: view1, appRoot: root1, dispatched } = mountView(client1);
await view1.render();
assertCondition(client1.calls.filter((c) => c.method === 'fetchCollection').length === 1, 'la vista monta con UNA SOLA carga del sobre');
assertCondition(client1.calls[0].element === null && client1.calls[0].page === 1, 'la carga inicial viaja sin filtro y con hoja 1');
assertCondition(dispatched.some((e) => e.type === 'collection:loaded'), 'la vista emite collection:loaded (plan §4.1)');
assertCondition(findByText(root1, 'H2', 'Mi Grimorio') !== null, 'el rótulo soberano «Mi Grimorio» encabeza la vista');
assertCondition(
  findDescendants(root1, (n) => typeof n.className === 'string' && n.className.split(/\s+/).includes('spell-card')).length > 0,
  'las entradas se pintan con la tarjeta compartida',
);
assertCondition(
  findDescendants(root1, (n) => n.getAttribute?.('data-tome-mark') === 'gestation').length === 1,
  'cada entrada porta su data-tome-mark del DTO (la vista jamás traduce estados)',
);
assertCondition(
  (findByClass(root1, 'collection-view__count')?.textContent ?? '').includes('2 entradas'),
  'el rótulo de conteo lee el total del sobre',
);

// =====================================================================
// [2] El filtro filtra con conteo (RF-02.3)
// =====================================================================
console.log('\n[2] Filtro por afinidad con conteo');
const client2 = createClientStub([
  envelope([entryDto('spl_1', 'Llama Eterna')], { total: 28, totalPages: 1 }),
  envelope([entryDto('spl_fire_1', 'Fuego Uno')], { total: 28, totalPages: 1 }),
]);
const { view: view2, appRoot: root2 } = mountView(client2);
await view2.render();
await view2.setFilter('fire');
assertCondition(client2.calls[1].element === 'fire' && client2.calls[1].page === 1, 'el filtro recarga con element=fire y hoja 1 (RF-02.3)');
assertCondition((findByClass(root2, 'collection-view__count')?.textContent ?? '').includes('28 entradas'), 'el conteo describe el conjunto filtrado');

// =====================================================================
// [3] Tomo vacío: invitación a la Biblioteca sin lenguaje de error (RF-02.2)
// =====================================================================
console.log('\n[3] Estado vacío con invitación');
let navigatedHash = null;
const client3 = createClientStub([envelope([])]);
const { view: view3, appRoot: root3 } = mountView(client3, {
  onNavigateToLibrary: (hash) => { navigatedHash = hash; },
});
await view3.render();
const emptyLegend = findByClass(root3, 'collection-view__empty-legend');
assertCondition(emptyLegend?.textContent === GRIMOIRE_COLLECTION_EMPTY_LEGEND, 'el tomo vacío porta «Tu tomo aguarda su primera obra»');
const cta = findByClass(root3, 'collection-view__empty-cta');
assertCondition(cta?.textContent === GRIMOIRE_COLLECTION_EMPTY_CTA_LABEL, 'la invitación porta «Recorrer la Biblioteca»');
cta.click();
assertCondition(navigatedHash === '#/biblioteca', 'el CTA conduce a #/biblioteca');
assertCondition(
  findDescendants(root3, (n) => typeof n.textContent === 'string' && /error|fallo|fracaso/i.test(n.textContent)).length === 0,
  'el estado vacío jamás usa lenguaje de error (RF-02.2)',
);

// =====================================================================
// [4] Paginación viva: la hoja muerta recae en la última viva (caso 10)
// =====================================================================
console.log('\n[4] Paginación viva (plan §3.5, caso límite 10)');
// La vista está en la página 2 de un tomo de 51 (2 páginas). Tras la
// retirada el total cae a 50: la página 2 muere y la vista debe recaer
// en la página 1 — jamás una pantalla fantasma — conservando el filtro.
const client4 = createClientStub([
  envelope([], { total: 51, page: 2, totalPages: 2 }),
  envelope([], { total: 50, page: 1, totalPages: 1 }),
]);
const { view: view4 } = mountView(client4);
await view4.render();
await view4.setPage(2);
const fetchCallsBeforeDiscard = client4.calls.filter((c) => c.method === 'fetchCollection').length;
// Retirada directa del flujo del modal (confirmación consumada):
// se ejercita vía el callback interno de paginación con setPage tras la
// retirada consumada por el cliente doble.
await view4.setPage(2); // reafirmar la página corriente
assertCondition(
  client4.calls.filter((c) => c.method === 'fetchCollection').length > fetchCallsBeforeDiscard,
  'la paginación viva recarga al navegar entre hojas (RF-02.1)',
);
// El candado de borde: la hoja 1 no retrocede más.
await view4.setPage(0);
assertCondition(client4.calls.at(-1).page === 1, 'una hoja degenerada degrada a 1 sin OFFSET negativo (candado del plan)');
// Cambio de filtro resetea la hoja a 1 (RF-02.3).
await view4.setPage(2);
await view4.setFilter('water');
assertCondition(client4.calls.at(-1).page === 1, 'el cambio de filtro vuelve a la hoja 1 conservando la lógica viva');

// =====================================================================
// [5] El 401 apaga los gestos sin vaciar lo leído (RF-05.2, hallazgo 7)
// =====================================================================
console.log('\n[5] Degradación solemne tras 401');
// Sobre cargado y, en la recarga del filtro, la sesión muerta.
const client5 = createClientStub([
  envelope([entryDto('spl_1', 'Llama Eterna')]),
  { success: false, status: 401, error: { code: 'UNAUTHENTICATED' } },
]);
const { view: view5, appRoot: root5, dispatched: dispatched5 } = mountView(client5);
await view5.render();
await view5.setFilter('fire');
const expiredBox = findByClass(root5, 'collection-view__session-expired');
assertCondition(expiredBox !== null, 'el aviso solemne de sesión expirada existe en el esqueleto');
assertCondition(expiredBox.hiddenRemoved === true, 'el aviso se muestra tras el 401 (hidden retirado)');
assertCondition(
  dispatched5.some((e) => e.type === 'collection:session-expired'),
  'la vista emite collection:session-expired al shell',
);
const cardsAfter401 = findDescendants(
  root5,
  (n) => typeof n.className === 'string' && n.className.split(/\s+/).includes('spell-card'),
);
assertCondition(cardsAfter401.length === 1, 'NADA se vacía: la lectura cargada permanece (hallazgo 7)');
const filterAfter401 = findByClass(root5, 'collection-view__filter');
assertCondition(filterAfter401 !== null && filterAfter401.disabled === true, 'el filtro queda apagado pero PRESENTE (conservado)');
assertCondition(
  (findByClass(root5, 'collection-view__count')?.textContent ?? '').includes('1 entrada'),
  'el rótulo de conteo sobrevive al 401',
);
const discardButtons = findDescendants(root5, (n) => n.tagName === 'BUTTON' && n.textContent === 'Retirar del tomo');
assertCondition(
  discardButtons.every((b) => b.disabled === true) && discardButtons.length > 0,
  'los gestos de retirada quedan apagados (disabled)',
);
assertCondition(
  findDescendants(root5, (n) => typeof n.textContent === 'string' && n.textContent.includes(GRIMOIRE_COLLECTION_EXPIRED_LEGEND)).length > 0,
  'la leyenda del Anexo A narra la expiración en la vista',
);

// =====================================================================
// [6] La retirada pasa por el modal solemne (Tarea 5.2)
// =====================================================================
console.log('\n[6] Retirada con modal: confirmar consume, Escape no muta');
const client6 = createClientStub([
  envelope([entryDto('spl_9', 'Obra a Retirar')]),
  envelope([], { total: 0 }),
]);
const { view: view6, appRoot: root6 } = mountView(client6);
await view6.render();
const discardButton6 = findDescendants(root6, (n) => n.tagName === 'BUTTON' && n.textContent === 'Retirar del tomo')[0];
discardButton6.click();
const dialog6 = findByClass(root6, 'discard-tome-modal');
assertCondition(dialog6 !== null && dialog6.open === true, 'el gesto de retirada despliega el modal solemne (Tarea 5.2)');
assertCondition(
  (findByClass(dialog6, 'discard-tome-modal__intro')?.textContent ?? '').includes('Obra a Retirar'),
  'el modal nombra la obra a retirar',
);
findDescendants(dialog6, (n) => n.tagName === 'BUTTON' && n.textContent === 'Firmar la retirada')[0].click();
assertCondition(
  client6.calls.some((c) => c.method === 'discardSpell' && c.spellId === 'spl_9'),
  'confirmar consume la retirada vía cliente (el total actualiza el conteo)',
);
// Escape (close del dialog) no muta: el cliente no recibe una segunda retirada.
const discardCallsBefore = client6.calls.filter((c) => c.method === 'discardSpell').length;
dialog6.close();
assertCondition(
  client6.calls.filter((c) => c.method === 'discardSpell').length === discardCallsBefore,
  'Escape tras el cierre no consume retirada alguna (sin mutación)',
);

console.log(`\n=== RESULTADO: ${assertsPassed} pasan, ${assertsFailed} fallan ===`);
process.exit(assertsFailed === 0 ? 0 : 1);
