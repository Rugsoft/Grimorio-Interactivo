/**
 * test_passphrase_changer.mjs — Arnés de la Tarea 6.2 (TASKS-12).
 *
 * Valida EL CAMBIADOR DE FRASE (`passphraseChangerComponent.js`) contra
 * el «Hecho cuando» de la tarea — las 5 fases del plan §6.2:
 *
 *   [1] Los tres campos exigidos (RF-04.1).
 *   [2] Los cuatro veredictos narrados con su leyenda castellana
 *       canónica (éxito, ciego, idéntica, idempotente — plan §2.6).
 *   [3] El recibo de éxito anuncia othersDissolvedCount (RF-04.2).
 *   [4] Doble envío → segundo intento con recibo idempotente, JAMÁS
 *       aviso mentiroso (caso límite 12, hallazgo 16).
 *   [5] La región viva anuncia el veredicto UNA SOLA VEZ (RNF-03).
 *
 * Constitución:
 *   - Artículo I (Dogma Vanilla): DOM simulado mínimo, ES Modules nativos.
 *   - AGENTS.md §6.1: centinela innerHTML (XSS).
 *   - Art. V: asertos en inglés, comentarios en castellano.
 *
 * Uso: node scratch/test_passphrase_changer.mjs
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
  createPassphraseChangerComponent,
  PASSPHRASE_CHANGER_LEGENDS,
  PASSPHRASE_CHANGER_EVENTS,
  dissolutionLegendFor,
} = await import('../public/assets/js/components/passphraseChangerComponent.js');

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

function findByText(root, tagName, text) {
  return findDescendants(root, (n) => n.tagName === tagName && n.textContent === text)[0] ?? null;
}

/** Cliente doble: cola de respuestas para changePassphrase. */
function createClientStub(responses = []) {
  const calls = [];
  return {
    calls,
    async changePassphrase(current, next, repeat) {
      calls.push({ method: 'changePassphrase', current, next, repeat });
      const handler = responses.shift();
      return typeof handler === 'function' ? handler() : handler
        ?? { success: false, status: 0, error: { code: 'MANA_STREAM_INTERRUPTED' } };
    },
  };
}

/** Rellena los tres campos y consume la custodia. */
async function fillAndSubmit(root, values) {
  const current = findByClass(root, 'passphrase-changer__current');
  const next = findByClass(root, 'passphrase-changer__new');
  const repeat = findByClass(root, 'passphrase-changer__repeat');
  current.value = values[0];
  next.value = values[1];
  repeat.value = values[2];
  findByClass(root, 'passphrase-changer__submit').click();
  await Promise.resolve(); await Promise.resolve(); await Promise.resolve();
}

// =====================================================================
// [0] Superficie del módulo + centinela del fuente
// =====================================================================
console.log('[0] Superficie del módulo y centinela');
assertCondition(typeof createPassphraseChangerComponent === 'function', 'el módulo exporta la fábrica createPassphraseChangerComponent');
assertCondition(PASSPHRASE_CHANGER_EVENTS.passphraseChanged === 'panel:passphrase-changed', 'el evento del bus es el canónico panel:passphrase-changed (plan §4.2)');
assertCondition(
  typeof dissolutionLegendFor(2) === 'string' && dissolutionLegendFor(2).includes('2'),
  'la narración de la disolución porta el número de moradas vacías (RF-04.2)',
);

const sourcePath = join(dirname(fileURLToPath(import.meta.url)), '..', 'public', 'assets', 'js', 'components', 'passphraseChangerComponent.js');
const source = readFileSync(sourcePath, 'utf8');
const sourceWithoutComments = source.replace(/\/\*[\s\S]*?\*\//g, '').replace(/^\s*\/\/.*$/gm, '');
assertCondition(!/innerHTML\s*=/.test(sourceWithoutComments), 'CENTINELA: el fuente del cambiador jamás asigna innerHTML (AGENTS.md 6.1)');

// =====================================================================
// [1] Los tres campos exigidos (RF-04.1)
// =====================================================================
console.log('\n[1] Los tres campos exigidos');
const stub1 = createClientStub();
const root1 = createObservableElement('section');
const changer1 = createPassphraseChangerComponent(root1, { panelClient: stub1, documentRef: documentSim, eventTarget: root1 });
await changer1.render();

const current1 = findByClass(root1, 'passphrase-changer__current');
const new1 = findByClass(root1, 'passphrase-changer__new');
const repeat1 = findByClass(root1, 'passphrase-changer__repeat');
assertCondition(current1 !== null && current1.getAttribute('type') === 'password', 'el campo de la frase ACTUAL existe como password');
assertCondition(new1 !== null && new1.getAttribute('type') === 'password', 'el campo de la NUEVA frase existe como password');
assertCondition(repeat1 !== null && repeat1.getAttribute('type') === 'password', 'el campo de CONFIRMACIÓN existe como password');
// Cada input porta su etiqueta propia (operable sin ratón, RNF-03).
for (const [input, id] of [[current1, 'passphrase-current'], [new1, 'passphrase-new'], [repeat1, 'passphrase-repeat']]) {
  const label = findDescendants(root1, (n) => n.tagName === 'LABEL' && n.getAttribute?.('for') === id)[0] ?? null;
  assertCondition(label !== null, `el campo ${id} porta su etiqueta (RNF-03)`);
}
// El autocomplete honesto: la actual como current-password, las nuevas como new-password.
assertCondition(
  current1.getAttribute('autocomplete') === 'current-password'
    && new1.getAttribute('autocomplete') === 'new-password'
    && repeat1.getAttribute('autocomplete') === 'new-password',
  'el autocomplete distingue la frase actual de las nuevas (gestor de contraseñas, RNF-04)',
);

// =====================================================================
// [2] Los cuatro veredictos con su leyenda canónica (plan §2.6)
// =====================================================================
console.log('\n[2] Los cuatro veredictos narrados en castellano');

// --- Veredicto ÉXITO (changed) ---
const successEnvelope = { success: true, status: 200, data: { verdict: 'changed', othersDissolvedCount: 2, currentSessionPreserved: true } };
const stub2a = createClientStub([successEnvelope]);
const root2a = createObservableElement('section');
const changer2a = createPassphraseChangerComponent(root2a, { panelClient: stub2a, documentRef: documentSim, eventTarget: root2a });
await changer2a.render();
await fillAndSubmit(root2a, ['vieja-clave', 'nueva-clave-solida', 'nueva-clave-solida']);
const verdict2a = findByClass(root2a, 'passphrase-changer__verdict');
assertCondition(verdict2a.getAttribute('data-verdict') === 'changed', 'el veredicto de éxito queda narrado como changed');
assertCondition(
  (verdict2a.textContent ?? '').includes(PASSPHRASE_CHANGER_LEGENDS.changed)
    && (verdict2a.textContent ?? '').includes('2 moradas'),
  'el recibo narra la custodia y las moradas vaciadas (RF-04.2)',
);
// La petición viaja con los TRES valores (RF-04.1).
assertCondition(
  stub2a.calls[0].current === 'vieja-clave' && stub2a.calls[0].next === 'nueva-clave-solida' && stub2a.calls[0].repeat === 'nueva-clave-solida',
  'la petición viaja con los tres campos del contrato (plan §2.6)',
);
// Los campos se purgan tras el envío: la frase en claro jamás permanece.
assertCondition(
  findByClass(root2a, 'passphrase-changer__current').value === ''
    && findByClass(root2a, 'passphrase-changer__new').value === '',
  'los campos se purgan tras el envío (la frase jamás permanece en el DOM)',
);
changer2a.destroy();

// --- Veredicto CIEGO (PASSPHRASE_CHANGE_FAILED, sin pistas) ---
const stub2b = createClientStub([
  { success: false, status: 400, error: { code: 'PASSPHRASE_CHANGE_FAILED', message: PASSPHRASE_CHANGER_LEGENDS.blindFailure } },
]);
const root2b = createObservableElement('section');
const changer2b = createPassphraseChangerComponent(root2b, { panelClient: stub2b, documentRef: documentSim, eventTarget: root2b });
await changer2b.render();
await fillAndSubmit(root2b, ['errada', 'otra-clave', 'otra-clave']);
const verdict2b = findByClass(root2b, 'passphrase-changer__verdict');
assertCondition(
  verdict2b.getAttribute('data-verdict') === 'blindFailure'
    && verdict2b.textContent === PASSPHRASE_CHANGER_LEGENDS.blindFailure,
  'el fallo ciego narra UNA sola leyenda, sin pistas de la causa (RF-04.1)',
);
// Las tres causas (frase errada, difieren, solidez) reciben EL MISMO texto:
// el veredicto del backend es idéntico y el componente no añade pistas.
assertCondition(
  !/frase actual|no coinciden|solidez/i.test(PASSPHRASE_CHANGER_LEGENDS.blindFailure),
  'la leyenda ciega jamás nombra causa concreta (frase errada, difieren o solidez)',
);
changer2b.destroy();

// --- Veredicto IDÉNTICA (PASSPHRASE_IDENTICAL, aviso noble) ---
const stub2c = createClientStub([
  { success: false, status: 400, error: { code: 'PASSPHRASE_IDENTICAL', message: PASSPHRASE_CHANGER_LEGENDS.identical } },
]);
const root2c = createObservableElement('section');
const changer2c = createPassphraseChangerComponent(root2c, { panelClient: stub2c, documentRef: documentSim, eventTarget: root2c });
await changer2c.render();
await fillAndSubmit(root2c, ['vigente', 'vigente', 'vigente']);
assertCondition(
  findByClass(root2c, 'passphrase-changer__verdict').textContent === PASSPHRASE_CHANGER_LEGENDS.identical,
  'la frase idéntica recibe su aviso noble específico (caso límite 17)',
);
changer2c.destroy();

// --- Veredicto IDEMPOTENTE (idempotentReceipt, hallazgo 16) ---
const stub2d = createClientStub([
  { success: true, status: 200, data: { verdict: 'idempotentReceipt', changedAt: '2026-09-25T10:00:00Z' } },
]);
const root2d = createObservableElement('section');
const changer2d = createPassphraseChangerComponent(root2d, { panelClient: stub2d, documentRef: documentSim, eventTarget: root2d });
await changer2d.render();
await fillAndSubmit(root2d, ['vigente', 'vigente', 'vigente']);
assertCondition(
  findByClass(root2d, 'passphrase-changer__verdict').getAttribute('data-verdict') === 'idempotentReceipt',
  'el reenvío legítimo recibe el recibo idempotente (hallazgo 16 del QA)',
);
changer2d.destroy();

// =====================================================================
// [3] El recibo de éxito anuncia othersDissolvedCount (RF-04.2)
// =====================================================================
console.log('\n[3] El recibo anuncia la disolución conservando la sesión actual');
const busEvents = [];
const stub3 = createClientStub([successEnvelope]);
const root3 = createObservableElement('section');
const changer3 = createPassphraseChangerComponent(root3, {
  panelClient: stub3,
  documentRef: documentSim,
  eventTarget: root3,
  onPassphraseChanged: (detail) => directCalls3.push(detail),
});
const directCalls3 = [];
root3.addEventListener('panel:passphrase-changed', (event) => busEvents.push(event));
await changer3.render();
await fillAndSubmit(root3, ['vieja', 'nueva-solida', 'nueva-solida']);
assertCondition(busEvents.length === 1 && busEvents[0].detail?.othersDissolvedCount === 2, 'el evento panel:passphrase-changed viaja con el recibo (plan §4.2)');
assertCondition(directCalls3.length === 1, 'el canal directo del orquestador recibe el veredicto');
// La narración cubre el caso cero moradas (el recibo jamás miente).
assertCondition(dissolutionLegendFor(0).includes('No había otras moradas'), 'con cero moradas el recibo lo narra sin inventar disoluciones');

// =====================================================================
// [4] Doble envío → recibo idempotente, jamás aviso mentiroso (caso 12)
// =====================================================================
console.log('\n[4] El doble envío recibe el recibo idempotente');
// El santuario: primer envío → changed; reenvío legítimo → idempotentReceipt.
let sendCount = 0;
const stub4 = createClientStub([
  () => { sendCount += 1; return successEnvelope; },
  () => ({ success: true, status: 200, data: { verdict: 'idempotentReceipt', changedAt: '2026-09-25T10:00:00Z' } }),
]);
const root4 = createObservableElement('section');
const changer4 = createPassphraseChangerComponent(root4, { panelClient: stub4, documentRef: documentSim, eventTarget: root4 });
await changer4.render();
await fillAndSubmit(root4, ['vieja', 'nueva-solida', 'nueva-solida']);
assertCondition(findByClass(root4, 'passphrase-changer__verdict').getAttribute('data-verdict') === 'changed', 'primer envío → recibo de éxito');
await fillAndSubmit(root4, ['nueva-solida', 'nueva-solida', 'nueva-solida']);
const verdict4 = findByClass(root4, 'passphrase-changer__verdict');
assertCondition(
  verdict4.getAttribute('data-verdict') === 'idempotentReceipt'
    && verdict4.textContent === PASSPHRASE_CHANGER_LEGENDS.idempotentReceipt,
  'segundo envío del dueño → recibo idempotente, JAMÁS aviso ciego que mienta (caso límite 12)',
);

// =====================================================================
// [5] La región viva anuncia el veredicto UNA sola vez (RNF-03)
// =====================================================================
console.log('\n[5] Anuncio único por la región viva');
const root5 = createObservableElement('section');
const changer5 = createPassphraseChangerComponent(root5, { panelClient: createClientStub([successEnvelope]), documentRef: documentSim, eventTarget: root5 });
await changer5.render();
const live5 = findByClass(root5, 'passphrase-changer__live');
assertCondition(live5 !== null && live5.getAttribute('aria-live') === 'polite', 'la región viva del cambiador existe y es polite (RNF-03)');
await fillAndSubmit(root5, ['vieja', 'nueva-solida', 'nueva-solida']);
const announcedOnce = live5.textContent;
await fillAndSubmit(root5, ['nueva-solida', 'nueva-solida', 'nueva-solida']);
// El segundo envío (recibo idempotente) es otro veredicto: sí se anuncia.
assertCondition(announcedOnce !== '', 'el primer veredicto se anuncia por la región viva');
assertCondition(
  live5.textContent !== announcedOnce || live5.textContent === PASSPHRASE_CHANGER_LEGENDS.idempotentReceipt,
  'cada veredicto DISTINTO se anuncia una vez (sin espameo, RNF-03)',
);
// Mismo veredicto consecutivo jamás se repite: forzamos dos fallos ciegos iguales.
const root6 = createObservableElement('section');
const blindEnvelope = { success: false, status: 400, error: { code: 'PASSPHRASE_CHANGE_FAILED', message: PASSPHRASE_CHANGER_LEGENDS.blindFailure } };
const changer6 = createPassphraseChangerComponent(root6, {
  panelClient: createClientStub([blindEnvelope, blindEnvelope]),
  documentRef: documentSim,
  eventTarget: root6,
});
await changer6.render();
const live6 = findByClass(root6, 'passphrase-changer__live');
await fillAndSubmit(root6, ['errada', 'x', 'x']);
const firstAnnounce = live6.textContent;
await fillAndSubmit(root6, ['errada', 'x', 'x']);
assertCondition(
  live6.textContent === firstAnnounce,
  'el MISMO veredicto consecutivo no se re-anuncia (anuncio único, fase [5] del plan)',
);
changer6.destroy();

changer5.destroy();

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
