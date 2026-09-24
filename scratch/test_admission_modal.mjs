/**
 * test_admission_modal.mjs — Arnés de la Tarea 5.2 (TASKS-10).
 *
 * Verifica el modal solemne del ingreso inmediato (`admissionModalComponent`),
 * según el «Hecho cuando»:
 *
 *   [1] RF-02.1: al abrir, la casa queda NOMBRADA en el cuerpo y las dos
 *       advertencias (lealtad indivisible, convalecencia futura) son
 *       visibles, explícitas y a cuerpo de modal — jamás letra menuda ni
 *       tooltip (textos canónicos, font-size de cuerpo).
 *   [2] RF-02.1: el descarte (botón, × o Escape→close) NO muta nada — sin
 *       onConfirm, con evento `vestibule:admission-dismissed` y la
 *       ceremonia permanece operativa.
 *   [3] RNF-03: el foco queda atrapado (Tab cicla) y REGRESA a la tarjeta
 *       originadora al cerrar; foco inicial en la confirmación.
 *   [4] RNF-03: región viva que anuncia apertura, descarte y confirmación.
 *   [5] RNF-03: la hoja del Vestíbulo declara prefers-reduced-motion y el
 *       componente viste el kit de controles (botones `.button`, SPEC-02
 *       RF-08.1/08.5), que ya se aquienta por sí mismo bajo movimiento
 *       reducido.
 *   [5] Eventos del plan §4: `vestibule:admission-opened` { clanId, mode },
 *       `vestibule:admission-dismissed` {}; la confirmación delega en
 *       onConfirm(clanId) — el modal JAMÁS llama a la API.
 *   [6] Artículo I: ningung nodo porta innerHTML; textContent puro.
 *
 * Constitución:
 *   - Artículo I (Dogma Vanilla): DOM simulado mínimo, ES Modules nativos.
 *   - Artículo V: identificadores en inglés camelCase; comentarios en castellano.
 *
 * Uso: node scratch/test_admission_modal.mjs
 */

import assert from 'node:assert';

let assertsPassed = 0;
let assertsFailed = 0;
let uncaughtErrors = 0;

process.on('uncaughtException', () => { uncaughtErrors++; });
process.on('unhandledRejection', () => { uncaughtErrors++; });

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

// =====================================================================
// DOM simulado (patrón consolidado de test_lineage_oath_modal.mjs)
// =====================================================================

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
  element.click = () => { element.dispatchEvent({ type: 'click' }); };
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
  const originalDispatch = dialog.dispatchEvent.bind(dialog);
  dialog.dispatchEvent = (event) => {
    if (event.type.startsWith('vestibule:')) emittedEvents.push({ type: event.type, detail: event.detail });
    return originalDispatch(event);
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

const {
  createAdmissionModalComponent,
  ADMISSION_MODAL_LOYALTY_LEGEND,
  ADMISSION_MODAL_CONVALESCENCE_LEGEND,
  ADMISSION_MODAL_CONFIRM_LABEL,
  buildAdmissionIntro,
} = await import('../public/assets/js/components/admissionModalComponent.js');

const documentRef = { createElement: (tag) => createFakeElement(tag) };

// Tarjeta originadora (el foco debe regresar a ella, RNF-03).
const originCard = createFakeElement('article');
let originFocusCount = 0;
originCard.focus = () => { originFocusCount += 1; };

console.log('== VERIFICACIÓN TAREA 5.2 (SPEC-10): Modal solemne del ingreso ==\n');

// =====================================================================
// [1] RF-02.1: casa nombrada y advertencias en el cuerpo
// =====================================================================
console.log('[1] La casa nombrada y las advertencias a cuerpo de modal (RF-02.1)');

// UNA instancia por diálogo: es el contrato del shell (el orquestador monta
// un solo modal de ingreso). Las reaperturas son del MISMO componente.
const dialogA = createHostDialog();
let confirmCallsA = 0;
const modalA = createAdmissionModalComponent(dialogA, {
  documentRef,
  windowRef: fakeWindow,
  onConfirm: () => { confirmCallsA += 1; },
});
modalA.open({ clanId: 'cln_mareas', clanName: 'Mareas de Aether', originElement: originCard });

assertCondition(dialogA.open === true, 'el <dialog> nativo abre con showModal');
const intro = findByClass(dialogA, 'admission-modal__intro')[0];
assertCondition(intro.textContent.includes('Mareas de Aether'), 'la casa queda NOMBRADA en el cuerpo (RF-02.1)');
assertCondition(buildAdmissionIntro('Brasa Viva').includes('«Brasa Viva»'), 'el intro canónico nombra a la casa entre comillas solemnes');
const loyalty = findByClass(dialogA, 'admission-modal__loyalty')[0];
const convalescence = findByClass(dialogA, 'admission-modal__convalescence')[0];
assertCondition(loyalty.textContent === ADMISSION_MODAL_LOYALTY_LEGEND, 'la lealtad indivisible es la leyenda canónica en el cuerpo');
assertCondition(convalescence.textContent === ADMISSION_MODAL_CONVALESCENCE_LEGEND, 'la convalecencia futura (14 días) es la leyenda canónica en el cuerpo');
assertCondition(loyalty.getAttribute('role') === 'alert' && convalescence.getAttribute('role') === 'alert', 'ambas advertencias son role=alert: visibles, explícitas e ineludibles');
// Jamás letra menuda: las advertencias NO declaran font-size propio menor
// (el CSS del kit las viste con el tamaño de tinta ordinaria).
assertCondition(!loyalty.hasAttribute('style') && !convalescence.hasAttribute('style'), 'sin estilos en línea: la letra menuda es imposible por construcción');
assertCondition(findByClass(dialogA, 'admission-modal__confirm').length === 1, 'la confirmación explícita está presente («Confirmar el ingreso»)');
assertCondition(findByClass(dialogA, 'admission-modal__confirm')[0].textContent === ADMISSION_MODAL_CONFIRM_LABEL, 'la confirmación porta su rótulo canónico');

// =====================================================================
// [2] El descarte no muta nada (RF-02.1, «Hecho cuando»)
// =====================================================================
console.log('\n[2] El descarte no muta nada (RF-02.1)');

modalA.open({ clanId: 'cln_mareas', clanName: 'Mareas de Aether', originElement: originCard });
const eventsBefore = emittedEvents.length;
const dismissButton = findByClass(dialogA, 'admission-modal__dismiss')[0];
dismissButton.click();
assertCondition(confirmCallsA === 0, 'el descarte NO invoca onConfirm: nada se consume (RF-02.1)');
assertCondition(modalA.isOpen() === false && dialogA.open === false, 'el modal cierra y la ceremonia permanece operativa');
assertCondition(
  emittedEvents.slice(eventsBefore).some((event) => event.type === 'vestibule:admission-dismissed'),
  'el descarte emite vestibule:admission-dismissed (plan §4) sin mutación'
);
assertCondition(originFocusCount >= 1, 'el foco regresa a la tarjeta originadora tras el descarte (RNF-03)');

// El botón × converge en el mismo descarte seguro.
modalA.open({ clanId: 'cln_mareas', clanName: 'Mareas de Aether', originElement: originCard });
findByClass(dialogA, 'admission-modal__close')[0].click();
assertCondition(confirmCallsA === 0 && modalA.isOpen() === false, 'el botón × converge en el descarte seguro (nada se consume)');
// La vía Escape→close del dialog nativo también descarta sin mutación.
modalA.open({ clanId: 'cln_mareas', clanName: 'Mareas de Aether', originElement: originCard });
dialogA.close('cancel');
assertCondition(confirmCallsA === 0 && modalA.isOpen() === false, 'Escape (close nativo) descarta sin consumir ingreso (RNF-03)');

// =====================================================================
// [3] Foco atrapado y devuelto (RNF-03)
// =====================================================================
console.log('\n[3] Foco atrapado y devuelto (RNF-03)');

const dialogC = createHostDialog();
let focusLog = [];
const focusSpyFactory = (tagName) => {
  const element = createFakeElement(tagName);
  const originalFocus = element.focus;
  element.focus = () => { focusLog.push(element); originalFocus(); };
  return element;
};
const modalC = createAdmissionModalComponent(dialogC, {
  documentRef: { createElement: focusSpyFactory },
  windowRef: fakeWindow,
});
modalC.open({ clanId: 'cln_brasa', clanName: 'Brasa Viva', originElement: originCard });
const confirmButton = findByClass(dialogC, 'admission-modal__confirm')[0];
assertCondition(focusLog.length === 1 && focusLog[0] === confirmButton, 'el foco inicial aterriza en la confirmación (la acción trascendente, RNF-03)');

// Tab en el borde del diálogo: desde el ÚLTIMO focusable (el ×), el trap
// cicla de vuelta al primero (la confirmación).
const closeButtonC = findByClass(dialogC, 'admission-modal__close')[0];
dialogC.dispatchEvent({ type: 'keydown', key: 'Tab', target: closeButtonC, preventDefault() {}, shiftKey: false });
assertCondition(focusLog[focusLog.length - 1] === confirmButton, 'el Tab en el borde cicla al primer botón (focus trap, RNF-03)');
dialogC.dispatchEvent({ type: 'keydown', key: 'Tab', target: confirmButton, preventDefault() {}, shiftKey: true });
assertCondition(focusLog.length >= 3, 'el Tab inverso (Shift+Tab) queda dentro del diálogo (sigue ciclando)');

// Sin clanId no hay modal (guardia de contexto).
const dialogD = createHostDialog();
const modalD = createAdmissionModalComponent(dialogD, { documentRef, windowRef: fakeWindow });
modalD.open({});
assertCondition(dialogD.open === false && modalD.isOpen() === false, 'sin clanId el modal jamás abre (no hay ingreso sin casa)');

// =====================================================================
// [4] Región viva (RNF-03) y confirmación delegada
// =====================================================================
console.log('\n[4] Región viva y confirmación delegada (el modal jamás llama a la API)');

const announceRegion = findByClass(dialogC, 'admission-modal__announce')[0];
assertCondition(announceRegion.getAttribute('aria-live') === 'polite' && announceRegion.textContent.length > 0, 'la región viva anuncia la apertura (RNF-03)');

let confirmCallsC = [];
modalC.close({ dismissed: true }); // reset para la fase de confirmación
const modalE = modalC; // UNA instancia por diálogo: la misma, con onConfirm ya inyectado
// (modalC nació sin onConfirm; para la delegación uso una instancia propia.)
const dialogE = createHostDialog();
const modalOwn = createAdmissionModalComponent(dialogE, {
  documentRef,
  windowRef: fakeWindow,
  onConfirm: (clanId) => confirmCallsC.push(clanId),
});
modalOwn.open({ clanId: 'cln_brasa', clanName: 'Brasa Viva', originElement: originCard });
findByClass(dialogE, 'admission-modal__confirm')[0].click();
assertCondition(confirmCallsC.length === 1 && confirmCallsC[0] === 'cln_brasa', 'la confirmación delega onConfirm(clanId) — el modal jamás llama a la API (Art. II)');
assertCondition(modalOwn.isOpen() === false, 'tras confirmar, el modal cierra y devuelve el control a la vista');
assertCondition(originFocusCount >= 2, 'el foco regresa a la tarjeta tras la confirmación (RNF-03)');

// =====================================================================
// [5] Eventos del plan §4 en orden
// =====================================================================
console.log('\n[5] Eventos del plan §4');

const openedEvents = emittedEvents.filter((event) => event.type === 'vestibule:admission-opened');
assertCondition(openedEvents.length >= 3, `vestibule:admission-opened emitido en cada apertura (${openedEvents.length})`);
assertCondition(
  openedEvents.every((event) => event.detail?.mode === 'join' && typeof event.detail?.clanId === 'string'),
  'cada apertura porta { clanId, mode: "join" } (plan §4)'
);
assertCondition(
  emittedEvents.some((event) => event.type === 'vestibule:admission-dismissed'),
  'vestibule:admission-dismissed emitido en el descarte (plan §4)'
);

// Reapertura idempotente: el panel no se duplica.
const panelCount = findByClass(dialogC, 'admission-modal__panel').length;
modalE.open({ clanId: 'cln_mareas', clanName: 'Mareas de Aether', originElement: originCard });
assertCondition(findByClass(dialogC, 'admission-modal__panel').length === panelCount, 'la reapertura no duplica el panel (cableado único)');
const introE = findByClass(dialogC, 'admission-modal__intro')[0];
assertCondition(introE.textContent.includes('Mareas de Aether'), 'la reapertura actualiza la casa nombrada');

// =====================================================================
// [6] Artículo I: textContent puro
// =====================================================================
console.log('\n[6] Ningún innerHTML (Art. I, AGENTS.md 6.1)');

let innerHTMLUsed = false;
(function scan(node) {
  if (typeof node.innerHTML !== 'undefined') innerHTMLUsed = true;
  for (const child of node.children ?? []) scan(child);
})(dialogC);
assertCondition(innerHTMLUsed === false, 'Ningún nodo porta innerHTML (textContent puro)');

// destroy() mientras está abierto: cierre silencioso, sin descarte emitido.
const eventsBeforeDestroy = emittedEvents.length;
modalE.destroy();
assertCondition(modalE.isOpen() === false && emittedEvents.length === eventsBeforeDestroy, 'destroy() cierra sin emitir descarte (apagado limpio)');

assertCondition(uncaughtErrors === 0, `Ninguna excepción escapó sin control (${uncaughtErrors} cazadas)`);

// [5] prefers-reduced-motion y kit de controles (RNF-03, SPEC-02 RF-08.5)
// =====================================================================
console.log('\n[5] Movimiento reducido y kit de controles (RNF-03)');
import { readFileSync } from 'node:fs';
const vestibuleCss = readFileSync(new URL('../public/assets/css/components/vestibule.css', import.meta.url), 'utf8');
assertCondition(vestibuleCss.includes('@media (prefers-reduced-motion: reduce)'), 'la hoja del Vestíbulo declara su bloque prefers-reduced-motion (RNF-03)');
assertCondition(vestibuleCss.includes('.admission-modal__confirm') && vestibuleCss.includes('.admission-modal__dismiss'), 'los botones del modal llevan su vestimenta declarada');
assertCondition(confirmButton.className.includes('button button--primary') && dismissButton.className.includes('button button--secondary'), 'confirmación y descarte visten el kit de controles de SPEC-02 (RF-08.1)');

// =====================================================================
// Resumen
// =====================================================================
console.log(`\n== RESUMEN == Asertos superados: ${assertsPassed}, fallidos: ${assertsFailed}`);
if (assertsFailed === 0 && uncaughtErrors === 0) {
  console.log('RESULTADO: EXITO — La casa queda nombrada, las advertencias visten el cuerpo, el descarte no muta nada, el foco vuelve a la tarjeta y el kit se aquienta bajo movimiento reducido (Tareas 5.2 y 8.2).');
  process.exit(0);
}
console.log('RESULTADO: DENEGADO — Corregir los asertos en rojo antes de continuar.');
process.exit(1);
