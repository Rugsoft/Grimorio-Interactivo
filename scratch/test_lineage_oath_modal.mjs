/**
 * test_lineage_oath_modal.mjs — Verificación de la Tarea 4.2 de TASKS-09.
 *
 * Criterio «Hecho cuando»: el modal exige segunda pulsación explícita, el
 * descarte no consume nada, el foco jamás escapa mientras está abierto y
 * regresa al cerrarlo, y la región viva anuncia apertura y veredicto.
 *
 * Uso: node scratch/test_lineage_oath_modal.mjs
 */

import {
  createOathModalComponent,
  buildOathText,
  OATH_MODAL_SEAL_LABEL,
  OATH_MODAL_PERPETUITY_LEGEND,
  OATH_MODAL_ANNOUNCE_OPEN,
  OATH_MODAL_ANNOUNCE_CONFIRMED,
  OATH_MODAL_ANNOUNCE_DISMISSED,
} from '../public/assets/js/components/oathModalComponent.js';

let assertsPassed = 0;
let assertsFailed = 0;
let uncaughtErrors = 0;

process.on('uncaughtException', (error) => { uncaughtErrors++; console.log(`  [EXCEPCION] ${error?.stack ?? error}`); });
process.on('unhandledRejection', (reason) => { uncaughtErrors++; console.log(`  [RECHAZO] ${reason?.stack ?? reason}`); });

function assertCondition(condition, description) {
  if (condition) {
    assertsPassed++;
    console.log(`  [PASA] ${description}`);
  } else {
    assertsFailed++;
    console.log(`  [FALLA] ${description}`);
  }
}

console.log('== VERIFICACION TAREA 4.2: El modal solemne de doble confirmación ==\n');

// --- DOM simulado nativo (mismo contrato que los arneses del santuario) ---
function createFakeElement(tagName) {
  const element = {
    tagName: String(tagName).toUpperCase(),
    children: [],
    attributes: {},
    classes: new Set(),
    listeners: {},
    textContent: '',
    parentNode: null,
    className: '',
    open: false,
    returnValue: '',
  };
  Object.defineProperty(element, 'classList', {
    value: {
      add: (...names) => names.forEach((n) => element.classes.add(n)),
      remove: (...names) => names.forEach((n) => element.classes.delete(n)),
      contains: (name) => element.classes.has(name),
    },
  });
  element.setAttribute = (name, value) => {
    element.attributes[name] = String(value);
    if (name === 'class') {
      element.classes = new Set(String(value).split(/\s+/).filter(Boolean));
      element.className = String(value);
    }
  };
  element.getAttribute = (name) => element.attributes[name] ?? null;
  element.removeAttribute = (name) => { delete element.attributes[name]; };
  element.hasAttribute = (name) => Object.prototype.hasOwnProperty.call(element.attributes, name);
  element.appendChild = (child) => {
    if (child?.parentNode) {
      const siblings = child.parentNode.children;
      const index = siblings.indexOf(child);
      if (index !== -1) siblings.splice(index, 1);
    }
    element.children.push(child);
    if (child) child.parentNode = element;
    return child;
  };
  element.remove = () => {
    if (element.parentNode) {
      const siblings = element.parentNode.children;
      const index = siblings.indexOf(element);
      if (index !== -1) siblings.splice(index, 1);
      element.parentNode = null;
    }
  };
  element.addEventListener = (type, listener) => { (element.listeners[type] ??= []).push(listener); };
  element.removeEventListener = (type, listener) => {
    element.listeners[type] = (element.listeners[type] ?? []).filter((l) => l !== listener);
  };
  element.dispatchEvent = (event) => {
    for (const listener of element.listeners[event.type] ?? []) listener(event);
    return true;
  };
  element.showModal = () => { element.open = true; };
  element.close = (returnValue = '') => {
    if (!element.open) return;
    element.open = false;
    element.returnValue = returnValue;
    element.dispatchEvent({ type: 'close' });
  };
  let focused = null;
  element.focus = () => { focused = element; };
  element._focused = () => focused;
  return element;
}

const emittedEvents = [];
const fakeWindow = {
  CustomEvent: class CustomEvent {
    constructor(type, options = {}) { this.type = type; this.detail = options.detail ?? null; }
  },
};

/** Diálogo anfitrión con querySelector por clase/id (aritmética nativa). */
function createHostDialog() {
  const dialog = createFakeElement('dialog');
  dialog.querySelector = (selector) => {
    const wanted = selector.startsWith('.') ? selector.slice(1) : null;
    const wantedId = selector.startsWith('#') ? selector.slice(1) : null;
    const scan = (node) => {
      for (const child of node.children ?? []) {
        if (wanted !== null && child.classList?.contains?.(wanted)) return child;
        if (wantedId !== null && child.getAttribute?.('id') === wantedId) return child;
        const found = scan(child);
        if (found) return found;
      }
      return null;
    };
    return scan(dialog);
  };
  return dialog;
}

function findByClass(root, className, found = []) {
  for (const child of root.children ?? []) {
    if (child.classList?.contains?.(className)) found.push(child);
    findByClass(child, className, found);
  }
  return found;
}

function findLeafByText(root, needle, found = []) {
  if (typeof root.textContent === 'string' && root.textContent.includes(needle) && (root.children ?? []).length === 0) {
    found.push(root);
  }
  for (const child of root.children ?? []) findLeafByText(child, needle, found);
  return found;
}

function fire(element, type, eventObject = {}) {
  element.dispatchEvent({ type, preventDefault() {}, stopPropagation() {}, target: element, ...eventObject });
}

/** Captura los eventos del plan §4 emitidos sobre el diálogo. */
function watchEvents(dialog) {
  const originalDispatch = dialog.dispatchEvent.bind(dialog);
  dialog.dispatchEvent = (event) => {
    if (typeof event.type === 'string' && event.type.startsWith('oath:')) {
      emittedEvents.push({ type: event.type, detail: event.detail });
    }
    return originalDispatch(event);
  };
}

// --- FASE A: Apertura solemne con perpetuidad ineludible (RNF-03) ---
console.log('\nFASE A: Apertura y advertencia ineludible (RF-02.3, RNF-03)\n');
const dialogA = createHostDialog();
watchEvents(dialogA);
const originCard = createFakeElement('article');
let confirmCalls = [];
const modal = createOathModalComponent(dialogA, {
  onConfirm: (lineageId) => confirmCalls.push(lineageId),
  documentRef: { createElement: (t) => createFakeElement(t), createElementNS: (ns, t) => createFakeElement(t) },
  windowRef: fakeWindow,
});

modal.open({ lineageId: 'primordialFlame', lineageName: 'Linaje de la Llama Primordial', originElement: originCard });
assertCondition(modal.isOpen() === true && dialogA.open === true, 'El modal abre sobre el linaje elegido');
assertCondition(
  findLeafByText(dialogA, buildOathText('Linaje de la Llama Primordial')).length === 1,
  'El texto del juramento nombra al linaje en primera persona (RF-02.2/02.3)',
);
const perpetuity = findByClass(dialogA, 'oath-modal__perpetuity')[0] ?? null;
assertCondition(
  perpetuity !== null
    && perpetuity.textContent === OATH_MODAL_PERPETUITY_LEGEND
    && perpetuity.getAttribute('role') === 'alert',
  'La perpetuidad es visible, explícita e ineludible (role=alert, cuerpo del modal)',
);
const sealButton = findByClass(dialogA, 'oath-modal__seal')[0] ?? null;
assertCondition(sealButton !== null && sealButton.textContent === OATH_MODAL_SEAL_LABEL, 'Exige la segunda confirmación explícita «Sellar el juramento»');
assertCondition(
  emittedEvents.some((e) => e.type === 'oath:confirmation-opened' && e.detail?.lineageId === 'primordialFlame'),
  'Emite oath:confirmation-opened con el linaje (plan §4)',
);
const liveRegion = findByClass(dialogA, 'oath-modal__announce')[0] ?? null;
assertCondition(
  liveRegion !== null && liveRegion.getAttribute('aria-live') === 'polite' && liveRegion.textContent === OATH_MODAL_ANNOUNCE_OPEN,
  'La región viva anuncia la apertura (RNF-05)',
);

// --- FASE B: Descarte seguro — nada se consume (RNF-03) ---
console.log('\nFASE B: El descarte no consume juramento\n');
fire(findByClass(dialogA, 'oath-modal__dismiss')[0], 'click');
assertCondition(modal.isOpen() === false && dialogA.open === false, 'El descarte cierra el modal');
assertCondition(confirmCalls.length === 0, 'NINGUNA confirmación fue consumada (onConfirm jamás invocado)');
assertCondition(
  emittedEvents.some((e) => e.type === 'oath:confirmation-dismissed'),
  'Emite oath:confirmation-dismissed (plan §4)',
);
assertCondition(liveRegion.textContent === OATH_MODAL_ANNOUNCE_DISMISSED, 'La región viva anuncia el descarte sin vínculo');
assertCondition(originCard._focused() === originCard, 'El foco regresa al elemento originador (RNF-03)');

// Reapertura idempotente: un solo panel, texto nuevo.
modal.open({ lineageId: 'celestialTides', lineageName: 'Linaje de las Mareas Celestiales' });
assertCondition(findByClass(dialogA, 'oath-modal__panel').length === 1, 'La reapertura no duplica el panel (cableado único)');
assertCondition(
  findLeafByText(dialogA, buildOathText('Linaje de las Mareas Celestiales')).length === 1,
  'La reapertura nombra al nuevo linaje',
);

// --- FASE C: Escape y × descartan sin consumir ---
console.log('\nFASE C: Escape y × convergen en descarte seguro\n');
fire(dialogA, 'keydown', { key: 'Escape' });
// En DOM simulado no hay cierre nativo por Escape: el 'close' del dialog lo cubre.
dialogA.close('oath-escape');
assertCondition(modal.isOpen() === false && confirmCalls.length === 0, 'El cierre nativo (Escape) descarta sin consumar');
modal.open({ lineageId: 'celestialTides', lineageName: 'Linaje de las Mareas Celestiales' });
fire(findByClass(dialogA, 'oath-modal__close')[0], 'click');
assertCondition(modal.isOpen() === false && confirmCalls.length === 0, 'El botón × descarta sin consumar');

// --- FASE D: Focus trap (RNF-03) ---
console.log('\nFASE D: El foco jamás escapa mientras el modal vive\n');
modal.open({ lineageId: 'solarCrown', lineageName: 'Linaje de la Corona Solar' });
const focusables = [
  findByClass(dialogA, 'oath-modal__seal')[0],
  findByClass(dialogA, 'oath-modal__dismiss')[0],
  findByClass(dialogA, 'oath-modal__close')[0],
];
fire(dialogA, 'keydown', { key: 'Tab', target: focusables[2] });
const afterWrap = focusables.find((f) => f._focused() === f);
assertCondition(afterWrap === focusables[0], 'Tab desde el último botón cicla al primero (trampa frontal)');
fire(dialogA, 'keydown', { key: 'Tab', shiftKey: true, target: focusables[0] });
// En el shim el foco queda en el elemento enfocado en cada gesto; tras el
// Shift+Tab del primero debe vivir el ÚLTIMO (focusables[2]).
assertCondition(focusables[2]._focused() === focusables[2], 'Shift+Tab desde el primero cicla al último (trampa trasera)');

// --- FASE E: La segunda pulsación consuma (RF-02.3) ---
console.log('\nFASE E: «Sellar el juramento» consuma una sola vez\n');
fire(findByClass(dialogA, 'oath-modal__seal')[0], 'click');
assertCondition(confirmCalls.length === 1 && confirmCalls[0] === 'solarCrown', 'onConfirm recibe el linaje exacto UNA sola vez');
assertCondition(modal.isOpen() === false, 'El modal cierra tras sellar');
assertCondition(
  emittedEvents.some((e) => e.type === 'oath:confirmation-confirmed' && e.detail?.lineageId === 'solarCrown'),
  'Emite oath:confirmation-confirmed (plan §4)',
);
assertCondition(liveRegion.textContent === OATH_MODAL_ANNOUNCE_CONFIRMED, 'La región viva anuncia el veredicto (RNF-05)');
assertCondition(originCard._focused() === originCard, 'El foco regresa a la tarjeta tras sellar (RNF-03)');

// Sin linaje no hay juramento: apertura inválida rechazada.
const dialogF = createHostDialog();
const modalF = createOathModalComponent(dialogF, {
  documentRef: { createElement: (t) => createFakeElement(t) },
  windowRef: fakeWindow,
});
modalF.open({ lineageId: '', lineageName: 'X' });
modalF.open(null);
assertCondition(modalF.isOpen() === false && dialogF.open === false, 'Sin linaje no se abre juramento alguno');

// --- FASE F: Hostilidad lingüística y XSS (Art. I) ---
console.log('\nFASE F: Guiones hostiles quedan en texto inerte\n');
const dialogG = createHostDialog();
const modalG = createOathModalComponent(dialogG, {
  documentRef: { createElement: (t) => createFakeElement(t) },
  windowRef: fakeWindow,
});
modalG.open({ lineageId: 'x', lineageName: '<script>alert(1)</script>' });
assertCondition(findLeafByText(dialogG, '<script>alert(1)</script>').length >= 1, 'El guion malicioso viaja como TEXTO del juramento (textContent puro)');
let innerHTMLUsed = false;
(function scan(node) {
  if (typeof node.innerHTML !== 'undefined') innerHTMLUsed = true;
  for (const child of node.children ?? []) scan(child);
})(dialogG);
assertCondition(innerHTMLUsed === false, 'Ningún nodo porta innerHTML (Art. I, AGENTS.md 6.1)');

// destroy() mientras está abierto: cierre silencioso, sin descarte emitido.
const eventsBefore = emittedEvents.length;
modalG.destroy();
assertCondition(modalG.isOpen() === false && emittedEvents.length === eventsBefore, 'destroy() cierra sin emitir descarte (apagado limpio)');

assertCondition(uncaughtErrors === 0, `Ninguna excepción escapó sin control (${uncaughtErrors} cazadas)`);

console.log(`\n== RESUMEN == Asertos superados: ${assertsPassed}, fallidos: ${assertsFailed}`);
if (assertsFailed === 0 && uncaughtErrors === 0) {
  console.log('RESULTADO: EXITO — El modal exige segunda pulsación, el descarte no consume nada, el foco queda atrapado y devuelto, y la región viva anuncia apertura y veredicto (Tarea 4.2).');
  process.exit(0);
}
console.log('RESULTADO: DENEGADO — Corregir los asertos en rojo antes de continuar.');
process.exit(1);
