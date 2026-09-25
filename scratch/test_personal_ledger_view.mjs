/**
 * test_personal_ledger_view.mjs — Arnés de la Tarea 6.3 (TASKS-12).
 *
 * Valida LA LENTE DE BITÁCORA PERSONAL (`personalLedgerComponent.js`)
 * contra el «Hecho cuando» de la tarea (plan §6.2):
 *
 *   [render]     Lista semántica de asientos con estampas legibles
 *                (ISO → fecha castellana) y narrativa íntegra (RF-06.1,
 *                RF-06.4, plan §4.3).
 *   [paginación] Paginación por cursor SIN duplicados con botón «Ver
 *                más» que se retira al agotarse la bitácora (plan §2.7).
 *   [silencio]   `entries: []` → leyenda solemne de silencio, jamás
 *                página vacía cruda (RF-06.3).
 *
 * Constitución:
 *   - Artículo I (Dogma Vanilla): DOM simulado mínimo, ES Modules nativos.
 *   - AGENTS.md §6.1: centinela innerHTML (XSS).
 *   - Art. V: asertos en inglés, comentarios en castellano.
 *
 * Uso: node scratch/test_personal_ledger_view.mjs
 * Salida: código 0 si todos los asertos pasan; código 1 en caso contrario.
 */

import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';

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
// DOM simulado mínimo (patrón consolidado de los arneses del santuario)
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
    focused: false,
    setAttribute(name, value) {
      element.attributes.set(name, String(value));
      if (name === 'class') {
        element.classes = new Set(String(value).split(/\s+/).filter(Boolean));
        element.className = String(value);
      }
    },
    getAttribute(name) {
      return element.attributes.has(name) ? element.attributes.get(name) : null;
    },
    hasAttribute(name) {
      return element.attributes.has(name);
    },
    removeAttribute(name) {
      element.attributes.delete(name);
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
      element.removed = true;
      if (element.parentNode) {
        const siblings = element.parentNode.children;
        const index = siblings.indexOf(element);
        if (index !== -1) siblings.splice(index, 1);
      }
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
  // CENTINELA (AGENTS.md §6.1): el accesor innerHTML LANZA.
  Object.defineProperty(element, 'innerHTML', {
    get() { throw new Error('PROHIBIDO innerHTML (AGENTS.md 6.1: XSS); usar textContent'); },
    set(value) { throw new Error(`PROHIBIDO innerHTML: se intentó escribir '${String(value).slice(0, 40)}'`); },
  });
  Object.defineProperty(element, 'classList', {
    value: {
      add: (...names) => names.forEach((n) => element.classes.add(n)),
      remove: (...names) => names.forEach((n) => element.classes.delete(n)),
      contains: (name) => element.classes.has(name),
    },
  });
  return element;
}

const documentSim = { createElement: (tagName) => createObservableElement(tagName) };
class CustomEventSim {
  constructor(type, options = {}) { this.type = type; this.detail = options.detail ?? null; }
}
// Props propias (no prototipo): el DOM simulado hace spread del evento.
globalThis.CustomEvent = CustomEventSim;

const {
  createPersonalLedgerComponent,
  PERSONAL_LEDGER_LEGENDS,
  PERSONAL_LEDGER_EVENTS,
  formatLedgerTimestamp,
} = await import('../public/assets/js/components/personalLedgerComponent.js');

function findDescendants(root, predicate, found = []) {
  if (root === null) return found;
  for (const child of root.children ?? []) {
    if (predicate(child)) found.push(child);
    findDescendants(child, predicate, found);
  }
  return found;
}

/** Busca por clase con DOBLE vía (classList y atributo className). */
function findByClass(root, className) {
  return findDescendants(
    root,
    (n) => n.classList?.contains?.(className)
      || (typeof n.className === 'string' && n.className.split(/\s+/).includes(className)),
  )[0] ?? null;
}

/** Sobre canónico de página de la lente (plan §2.7). */
function ledgerEnvelope(entries, nextCursor = null) {
  return {
    success: true,
    status: 200,
    data: { entries, nextCursor },
  };
}

function entry(actionType, actionLabel, createdAt, narrative, targetKind) {
  return { actionLabel, actionType, createdAt, narrative, targetKind };
}

/** Cliente doble: cola de páginas con registro de cursores pedidos. */
function createClientStub(pages) {
  const calls = [];
  return {
    calls,
    async fetchLedger(cursor) {
      calls.push({ method: 'fetchLedger', cursor: cursor ?? null });
      const next = pages.shift();
      return next ?? ledgerEnvelope([]);
    },
  };
}

// =====================================================================
// [0] Superficie del módulo + centinela del fuente
// =====================================================================
console.log('[0] Superficie del módulo y centinela');
assertCondition(typeof createPersonalLedgerComponent === 'function', 'el módulo exporta la fábrica createPersonalLedgerComponent');
assertCondition(PERSONAL_LEDGER_EVENTS.ledgerPageLoaded === 'panel:ledger-page-loaded', 'el evento del bus es el canónico panel:ledger-page-loaded');
// Estampa legible: ISO → castellano; degradación noble ante basura.
assertCondition(
  typeof formatLedgerTimestamp('2025-03-01T10:00:00Z') === 'string' && formatLedgerTimestamp('2025-03-01T10:00:00Z').includes('2025'),
  'la estampa ISO se convierte a fecha castellana legible (plan §4.3)',
);
assertCondition(formatLedgerTimestamp('pergamino-ilegible') === null && formatLedgerTimestamp(null) === null, 'la estampa ilegible o ausente degrada sin romper (plan §4.3)');

const sourcePath = join(dirname(fileURLToPath(import.meta.url)), '..', 'public', 'assets', 'js', 'components', 'personalLedgerComponent.js');
const source = readFileSync(sourcePath, 'utf8');
const sourceWithoutComments = source.replace(/\/\*[\s\S]*?\*\//g, '').replace(/^\s*\/\/.*$/gm, '');
assertCondition(!/innerHTML\s*=/.test(sourceWithoutComments), 'CENTINELA: el fuente de la lente jamás asigna innerHTML (AGENTS.md 6.1)');

// =====================================================================
// [render] Lista semántica con estampas y narrativa (RF-06.1/06.4)
// =====================================================================
console.log('\n[render] La lista semántica de asientos');
const page1 = ledgerEnvelope([
  entry('PASSPHRASE_SELF_CHANGED', 'La custodia de la frase de paso', '2025-03-05T10:00:00Z', 'El adepto cambió su frase de paso desde su panel.', 'user'),
  entry('SIGN_VALIDATE', 'Firma de Validación', '2025-03-03T10:00:00Z', 'La obra merece el aval del Cónclave.', 'spell'),
  entry('LINEAGE_OATH_SWORN', 'Juramento de Linaje sellado', '2025-03-01T10:00:00Z', 'El juramento de linaje fue sellado en la ceremonia del primer acceso.', 'user'),
]);
const stubRender = createClientStub([page1]);
const rootRender = createObservableElement('section');
const ledgerRender = createPersonalLedgerComponent(rootRender, { panelClient: stubRender, documentRef: documentSim, eventTarget: rootRender });
const renderEvents = [];
rootRender.addEventListener('panel:ledger-page-loaded', (e) => renderEvents.push(e));
await ledgerRender.render();

const list = findByClass(rootRender, 'panel-ledger__list');
assertCondition(list !== null && list.tagName === 'UL', 'la bitácora es una LISTA semántica (ul con aria-label, RNF-03)');
assertCondition(findDescendants(list, (n) => n.tagName === 'LI').length === 3, 'los tres asientos de la página se narran como li semánticos');
// Estampa legible con datetime machine-readable (plan §4.3).
const firstStamp = findDescendants(list, (n) => n.tagName === 'TIME')[0] ?? null;
assertCondition(
  firstStamp !== null && firstStamp.getAttribute('datetime') === '2025-03-05T10:00:00Z' && firstStamp.textContent.includes('2025'),
  'cada asiento porta su estampa legible con datetime machine-readable (plan §4.3)',
);
// La narrativa viaja íntegra: la justification pública del asiento (RF-06.4).
assertCondition(
  findDescendants(list, (n) => n.textContent === 'La obra merece el aval del Cónclave.').length === 1,
  'la narrativa del acto con terceros viaja íntegra (RF-06.4: lo ya público)',
);
// El targetKind se traduce a castellano; jamás identificador crudo.
assertCondition(
  findDescendants(list, (n) => n.textContent === ' — Sobre una obra').length === 1,
  'el targetKind se narra en la lengua del santuario (Art. V)',
);
assertCondition(
  findDescendants(rootRender, (n) => typeof n.textContent === 'string' && /usr_|spl_/i.test(n.textContent)).length === 0,
  'la lente jamás imprime identificadores técnicos crudos (Art. V, contrato §2.7)',
);
// El evento de página cargada viaja por el bus.
assertCondition(renderEvents.length === 1 && renderEvents[0].detail?.count === 3, 'la página consumada emite panel:ledger-page-loaded (plan §4.2)');
// La página 1 llegó SIN cursor (bitácora agotada por contrato del
// backend): el botón se retira — jamás un botón muerto (RF-06.2).
assertCondition(findByClass(rootRender, 'panel-ledger__more') === null, 'sin cursor vivo, el «Ver más» se retira (jamás un botón muerto)');
ledgerRender.destroy();

// =====================================================================
// [paginación] Cursor sin duplicados, botón retirado al agotarse
// =====================================================================
console.log('\n[paginación] La paginación por cursor: estable y sin duplicados');
const stubPages = createClientStub([
  ledgerEnvelope([
    entry('TOME_SEAL', 'Sellado en el Tomo Personal', '2025-04-03T07:00:00Z', 'Sellado número 3 en el tomo personal.', 'spell'),
    entry('TOME_SEAL', 'Sellado en el Tomo Personal', '2025-04-02T07:00:00Z', 'Sellado número 2 en el tomo personal.', 'spell'),
  ], '42'),
  ledgerEnvelope([
    entry('TOME_SEAL', 'Sellado en el Tomo Personal', '2025-04-01T07:00:00Z', 'Sellado número 1 en el tomo personal.', 'spell'),
    entry('LINEAGE_OATH_SWORN', 'Juramento de Linaje sellado', '2025-03-01T10:00:00Z', 'El juramento de linaje fue sellado en la ceremonia del primer acceso.', 'user'),
  ], null),
]);
const stubPagination = createClientStub(JSON.parse(JSON.stringify([
  ledgerEnvelope([
    entry('TOME_SEAL', 'Sellado en el Tomo Personal', '2025-04-03T07:00:00Z', 'Sellado número 3 en el tomo personal.', 'spell'),
    entry('TOME_SEAL', 'Sellado en el Tomo Personal', '2025-04-02T07:00:00Z', 'Sellado número 2 en el tomo personal.', 'spell'),
  ], '42'),
  ledgerEnvelope([
    entry('TOME_SEAL', 'Sellado en el Tomo Personal', '2025-04-01T07:00:00Z', 'Sellado número 1 en el tomo personal.', 'spell'),
    entry('LINEAGE_OATH_SWORN', 'Juramento de Linaje sellado', '2025-03-01T10:00:00Z', 'El juramento de linaje fue sellado en la ceremonia del primer acceso.', 'user'),
  ], null),
])));
const rootPag = createObservableElement('section');
const ledgerPag = createPersonalLedgerComponent(rootPag, { panelClient: stubPagination, documentRef: documentSim, eventTarget: rootPag });
await ledgerPag.render();

assertCondition(stubPagination.calls[0].cursor === null, 'la primera página se pide SIN cursor');
const moreButton = findByClass(rootPag, 'panel-ledger__more');
assertCondition(moreButton !== null, 'con cursor vivo, el botón «Ver más» aguarda');
moreButton.click();
await Promise.resolve(); await Promise.resolve(); await Promise.resolve();

// El cursor opaco viaja EXACTO (el backend dicta la continuidad, RF-06.2).
assertCondition(stubPagination.calls[1].cursor === '42', 'la página siguiente viaja con el cursor opaco que el backend dictó (plan §2.7)');
// Cuatro asientos en total, NINGUNO repetido (sellados n.º 1,2,3 + juramento).
const items = findDescendants(rootPag, (n) => n.tagName === 'LI');
assertCondition(items.length === 4, 'las dos páginas reúnen los 4 asientos sin omitir ninguno');
const stamps = findDescendants(rootPag, (n) => n.tagName === 'TIME').map((n) => n.getAttribute('datetime'));
assertCondition(
  new Set(stamps).size === stamps.length,
  'NINGÚN asiento se repite entre páginas (paginación estable, hallazgo 1 del QA)',
);
// Agotada la bitácora (nextCursor null): el botón se retira.
assertCondition(
  findByClass(rootPag, 'panel-ledger__more') === null || findByClass(rootPag, 'panel-ledger__more')?.removed === true,
  'agotada la bitácora, el «Ver más» se retira (jamás un botón muerto)',
);
// Un clic más no vuelve a pedir nada (la lente sabe que terminó).
const callsAfterExhaustion = stubPagination.calls.length;
await ledgerPag.loadMore();
assertCondition(stubPagination.calls.length === callsAfterExhaustion, 'sin cursor no hay petición fantasma tras el agotamiento (RF-06.2: pura lente)');
ledgerPag.destroy();

// =====================================================================
// [silencio] entries: [] → leyenda de silencio (RF-06.3)
// =====================================================================
console.log('\n[silencio] El vacío recibe su leyenda, jamás una página cruda');
const stubEmpty = createClientStub([ledgerEnvelope([], null)]);
const rootEmpty = createObservableElement('section');
const ledgerEmpty = createPersonalLedgerComponent(rootEmpty, { panelClient: stubEmpty, documentRef: documentSim, eventTarget: rootEmpty });
await ledgerEmpty.render();

const emptyLegend = findByClass(rootEmpty, 'panel-ledger__empty');
assertCondition(
  emptyLegend !== null && emptyLegend.textContent === PERSONAL_LEDGER_LEGENDS.emptyLegend,
  'el vacío narra la leyenda solemne de silencio (RF-06.3)',
);
assertCondition(findDescendants(rootEmpty, (n) => n.tagName === 'LI').length === 0, 'sin asientos no hay li fantasma');
// El agotamiento de una lente que NUNCA tuvo asientos también retira el «Ver más».
const emptyMore = findByClass(rootEmpty, 'panel-ledger__more');
assertCondition(
  emptyMore === null || emptyMore.removed === true,
  'el «Ver más» se retira también ante el silencio (jamás un botón muerto)',
);
ledgerEmpty.destroy();

// El fallo del velo: aviso noble con rol alert, sin trazas (plan §2.8).
const stubFail = createClientStub([{ success: false, status: 500, error: { code: 'PANEL_UNAVAILABLE', message: PERSONAL_LEDGER_LEGENDS.unavailable } }]);
const rootFail = createObservableElement('section');
const ledgerFail = createPersonalLedgerComponent(rootFail, { panelClient: stubFail, documentRef: documentSim, eventTarget: rootFail });
await ledgerFail.render();
assertCondition(
  (findByClass(rootFail, 'panel-ledger__unavailable')?.textContent ?? '') === PERSONAL_LEDGER_LEGENDS.unavailable,
  'el fallo del velo narra su aviso solemne con reintento natural (caso límite 11)',
);
assertCondition(findByClass(rootFail, 'panel-ledger__more') !== null, 'tras el fallo, el «Ver más» sobrevive como reintento natural');
ledgerFail.destroy();

// --- Resumen canónico del arnés ---
console.log(`\n== RESUMEN ==`);
console.log(`Asertos superados: ${assertsPassed}`);
console.log(`Asertos fallidos: ${assertsFailed}`);
if (assertsFailed === 0) {
  console.log('RESULTADO: EXITO');
  process.exit(0);
}
console.log('RESULTADO: FRACASO');
process.exit(1);
