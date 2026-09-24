/**
 * test_spell_detail_modal.mjs — Verificación de la ficha superpuesta (Tarea 4.3).
 *
 * Estrategia TDD: este script se escribe ANTES que spellDetailModalComponent.js.
 * Valida contra el criterio "Hecho cuando" de specs/01-portal-and-navigation.tasks.md:
 *   El modal se abre superpuesto sin mover el scroll inferior, la tecla Tab
 *   queda confinada dentro del modal y al cerrarse con Escape el foco regresa
 *   a la tarjeta de origen.
 *
 * Requisitos: RF-04.1 (apertura superpuesta), RF-04.2 (cierre con Escape /
 * botón / backdrop), RF-04.3 (rescate de foco), RNF-03 (focus trap).
 *
 * Uso: node scratch/test_spell_detail_modal.mjs
 */

import { createSpellDetailModalComponent } from '../public/assets/js/components/spellDetailModalComponent.js';

let assertsPassed = 0;
let assertsFailed = 0;

/** Aserta una condición y registra el resultado en la bitácora de consola. */
function assertCondition(condition, description) {
  if (condition) {
    assertsPassed++;
    console.log(`  [PASA] ${description}`);
  } else {
    assertsFailed++;
    console.log(`  [FALLA] ${description}`);
  }
}

// --- DOM simulado (patrón de las Tareas 4.1/4.2, ampliado con focus/simulación de dialog) ---
const activeElementTracker = { current: null };

function createFakeElement(tagName) {
  const element = {
    tagName: String(tagName).toUpperCase(),
    children: [],
    attributes: {},
    listeners: {},
    _textContent: '',
    parentElement: null,
    focusCount: 0,
    blurCount: 0,
    open: false,
    returnValue: '',
    setAttribute(name, value) { this.attributes[name] = String(value); },
    getAttribute(name) { return name in this.attributes ? this.attributes[name] : null; },
    removeAttribute(name) { delete this.attributes[name]; },
    hasAttribute(name) { return name in this.attributes; },
    addEventListener(eventName, listener) { (this.listeners[eventName] ??= []).push(listener); },
    removeEventListener(eventName, listener) { this.listeners[eventName] = (this.listeners[eventName] ?? []).filter((l) => l !== listener); },
    dispatch(eventName, eventObject = {}) {
      for (const listener of this.listeners[eventName] ?? []) {
        listener({ type: eventName, preventDefault() {}, stopPropagation() {}, currentTarget: this, target: this, ...eventObject });
      }
    },
    appendChild(child) { child.parentElement = this; this.children.push(child); return child; },
    set className(value) { this.classes = new Set(String(value).split(/\s+/).filter(Boolean)); },
    get className() { return [...(this.classes ?? new Set())].join(' '); },
    classes: new Set(),
    get textContent() { return this._textContent; },
    set textContent(value) { this._textContent = String(value); },
    get innerHTML() { throw new Error('PROHIBIDO innerHTML (AGENTS.md 6.1)'); },
    set innerHTML(value) { throw new Error(`PROHIBIDO innerHTML: '${String(value).slice(0, 40)}'`); },
    /** El foco simulado replica document.activeElement. */
    focus() {
      this.focusCount++;
      activeElementTracker.current = this;
    },
    blur() {
      this.blurCount++;
      if (activeElementTracker.current === this) activeElementTracker.current = null;
    },
  };
  element.classList = {
    _owner: element,
    add(...names) { names.forEach((n) => this._owner.classes.add(n)); },
    remove(...names) { names.forEach((n) => this._owner.classes.delete(n)); },
    contains(name) { return this._owner.classes.has(name); },
  };
  return element;
}

/**
 * <dialog> simulado: showModal/close con eventos 'close' y bloqueo del fondo.
 */
function createFakeDialog() {
  const dialog = createFakeElement('dialog');
  dialog.showModalCount = 0;
  dialog.closeCount = 0;
  dialog.showModal = function showModal() {
    this.open = true;
    this.showModalCount++;
    activeElementTracker.current = this;
  };
  /* El navegador maneja Escape de forma NATIVA en <dialog> (cierra y dispara
     'close'). El simulador replica ese comportamiento tras los listeners. */
  dialog.dispatch = function dispatch(eventName, eventObject = {}) {
    for (const listener of this.listeners[eventName] ?? []) {
      listener({ type: eventName, preventDefault() {}, stopPropagation() {}, currentTarget: this, target: this, ...eventObject });
    }
    if (eventName === 'keydown' && eventObject.key === 'Escape' && this.open) {
      this.close(); // comportamiento nativo del navegador
    }
  };
  dialog.close = function close(returnValue) {
    if (!this.open) return;
    this.open = false;
    this.closeCount++;
    this.returnValue = returnValue ?? '';
    // El navegador dispara 'close' tras dialog.close().
    for (const listener of this.listeners.close ?? []) listener({ type: 'close' });
  };
  return dialog;
}

/** Árbol del shell (index.html): dialog > panel > botón cerrar + cuerpo. */
function buildFakeShell() {
  const dialog = createFakeDialog();
  dialog.setAttribute('id', 'spellDetailModal');

  /* El <dialog> real posee querySelector/querySelectorAll (DOM estándar);
     el simulador los replica con búsqueda recursiva por id. */
  function queryById(node, elementId, found = []) {
    for (const child of node.children) {
      if (child.getAttribute('id') === elementId) found.push(child);
      queryById(child, elementId, found);
    }
    return found;
  }
  dialog.querySelector = function queryOne(selector) {
    const elementId = selector.startsWith('#') ? selector.slice(1) : selector;
    return queryById(this, elementId)[0] ?? null;
  };
  dialog.querySelectorAll = function queryAll(selector) {
    // Solo se usa el selector de enfocables en el trap; devolvemos todos
    // los botones/elementos con tabindex del subárbol (suficiente aquí).
    const focusable = [];
    const collect = (node) => {
      for (const child of node.children) {
        if (child.tagName === 'BUTTON' || (child.getAttribute('tabindex') && child.getAttribute('tabindex') !== '-1')) {
          focusable.push(child);
        }
        collect(child);
      }
    };
    collect(this);
    return focusable;
  };

  const closeButton = createFakeElement('button');
  closeButton.setAttribute('id', 'spellDetailClose');
  dialog.appendChild(closeButton);

  const detailBody = createFakeElement('section');
  detailBody.setAttribute('id', 'spellDetailBody');
  dialog.appendChild(detailBody);

  // Botón de acción reservada dentro de la ficha («Añadir a mi Grimorio»,
  // escenario del plan 7.2): activará la pila de modales.
  const reserveButton = createFakeElement('button');
  reserveButton.setAttribute('id', 'spellDetailReserve');
  detailBody.appendChild(reserveButton);

  const documentSim = {
    /* El document real refleja el foco en activeElement: el simulador
       delega al tracker global que actualizan los focus() simulados. */
    get activeElement() { return activeElementTracker.current; },
    createElement: (tagName) => createFakeElement(tagName),
  };
  activeElementTracker.current = null;

  return { dialog, closeButton, detailBody, reserveButton, documentSim };
}

/** DTO de detalle del plan 2.2. */
const sampleDetail = {
  id: 'spl_9f8b2c1a',
  slug: 'llamas-de-frieren',
  name: 'Llamas de Frieren',
  magicSchool: 'evocation',
  magicSchoolLabel: 'Evocación',
  manaCost: 45,
  clanName: 'Eruditos Astrales',
  summary: 'Proyecta una ráfaga continua de fuego purificador.',
  description: 'Una antigua fórmula perfeccionada en las tierras boreales.',
  components: {
    verbal: 'Ignis Caelestis Dissolvens',
    somatic: 'Palma extendida con runa trazada',
    material: 'Ceniza de sauce quemada',
  },
  status: 'validated',
  validationSignaturesCount: 3,
  isGenesisSample: false,
  validatedAt: '2026-09-10T14:30:00Z',
};

console.log('== VERIFICACION TAREA 4.3: spellDetailModalComponent.js ==\n');

// --- FASE 1: Apertura superpuesta y render seguro (RF-04.1) ---
console.log('FASE 1: Apertura superpuesta (RF-04.1)');

const shell1 = buildFakeShell();
const scrollState = { top: 432 }; // scroll del catálogo debajo del modal.
const closeCallbacks1 = [];
const modal1 = createSpellDetailModalComponent(shell1.dialog, {
  documentRef: shell1.documentSim,
  onClose: () => closeCallbacks1.push('closed'),
  windowRef: {
    get scrollY() { return scrollState.top; },
    set scrollY(value) { scrollState.top = value; },
  },
});

// Tarjeta de origen enfocada antes de abrir (flujo real de la rejilla).
const originCard = createFakeElement('article');
originCard.setAttribute('data-slug', 'llamas-de-frieren');
originCard.focus();

modal1.open(sampleDetail);

assertCondition(shell1.dialog.open === true, 'open() despliega el <dialog> superpuesto (showModal)');
assertCondition(shell1.dialog.showModalCount === 1, 'Se usa showModal() nativo (pila y backdrop gratuitos)');
assertCondition(scrollState.top === 432, 'El scroll del catálogo NO se movió al abrir (se preserva la posición)');
assertCondition(collectText(shell1.detailBody).includes('Llamas de Frieren'), 'La ficha renderiza el nombre del conjuro');
assertCondition(collectText(shell1.detailBody).includes('Ignis Caelestis Dissolvens'), 'La ficha renderiza los componentes arcanos (plan 2.2)');

/** Recolecta textContent del árbol simulado. */
function collectText(node) {
  let text = node.textContent ?? '';
  for (const child of node.children) text += ' ' + collectText(child);
  return text;
}

/** Busca recursivamente por clase. */
function findByClass(node, className, found = []) {
  if (node.classes?.has?.(className)) found.push(node);
  for (const child of node.children) findByClass(child, className, found);
  return found;
}

// --- FASE 2: Focus trap (RNF-03, plan 5.3) ---
console.log('\nFASE 2: Atrapamiento de foco (Tab confinado)');

/* Orden real del DOM tras open(): closeButton del shell (primero) y el
   botón reservado VIVO reconstruido dentro de detailBody (último). El trap
   debe ciclar exactamente en esos dos extremos; las posiciones intermedias
   las maneja el navegador de forma nativa. */
const liveReserve = shell1.detailBody.children.find((child) => child.tagName === 'BUTTON');
const focusables = [shell1.closeButton, liveReserve];
const firstFocusable = focusables[0];
const lastFocusable = focusables[focusables.length - 1];

// Tab en el ÚLTIMO enfocable: debe ciclar al PRIMERO.
activeElementTracker.current = lastFocusable;
let trapPrevented = false;
shell1.dialog.dispatch('keydown', {
  key: 'Tab',
  preventDefault() { trapPrevented = true; },
});
assertCondition(trapPrevented === true, 'Tab en el último enfocable se intercepta (preventDefault)');
assertCondition(
  activeElementTracker.current === firstFocusable,
  'El foco cicla al primer enfocable del modal'
);

// Shift+Tab en el PRIMERO: debe ciclar al ÚLTIMO.
activeElementTracker.current = firstFocusable;
let shiftTrapPrevented = false;
shell1.dialog.dispatch('keydown', {
  key: 'Tab',
  shiftKey: true,
  preventDefault() { shiftTrapPrevented = true; },
});
assertCondition(shiftTrapPrevented === true, 'Shift+Tab en el primero se intercepta');
assertCondition(
  activeElementTracker.current === lastFocusable,
  'El foco cicla al último enfocable (ciclo inverso)'
);

// --- FASE 3: Cierre por Escape con rescate de foco (RF-04.2/04.3, criterio) ---
console.log('\nFASE 3: Criterio (Escape devuelve el foco a la tarjeta de origen)');

shell1.dialog.dispatch('keydown', { key: 'Escape' });

assertCondition(shell1.dialog.open === false, 'Escape cierra el <dialog>');
assertCondition(closeCallbacks1.length === 1, 'El cierre notifica al orquestador (onClose) — para historyManager/store');
assertCondition(originCard.focusCount === 1, 'El foco REGRESA a la tarjeta de origen (criterio, RF-04.3)');

// --- FASE 4: Botón de cierre y segunda apertura (idempotencia) ---
console.log('\nFASE 4: Botón de cierre y reapertura limpia');

const shell2 = buildFakeShell();
const closeCallbacks2 = [];
const originCard2 = createFakeElement('article');
originCard2.focus();
const modal2 = createSpellDetailModalComponent(shell2.dialog, {
  documentRef: shell2.documentSim,
  onClose: () => closeCallbacks2.push('closed'),
});

modal2.open(sampleDetail);
shell2.closeButton.dispatch('click');

assertCondition(shell2.dialog.open === false, 'El botón × cierra el modal (RF-04.2)');
assertCondition(closeCallbacks2.length === 1, 'El cierre por botón también notifica al orquestador');
assertCondition(originCard2.focusCount === 1, 'El foco regresa a la tarjeta tras cerrar por botón');

// Reapertura: los callbacks no se duplican (sin listeners apilados).
modal2.open(sampleDetail);
const callbacksBeforeSecond = closeCallbacks2.length;
modal2.open(sampleDetail); // doble open: idempotente, no cierra/abre en bucle.
assertCondition(shell2.dialog.showModalCount === 2, 'La reapertura usa showModal una única vez adicional');
shell2.closeButton.dispatch('click');
assertCondition(closeCallbacks2.length === callbacksBeforeSecond + 1, 'Sin duplicación de callbacks tras varias aperturas');

// --- FASE 5: Rescate de foco alternativo (RF-04.3, caso límite 4) ---
console.log('\nFASE 5: Rescate de foco si la tarjeta de origen desapareció');

const shell3 = buildFakeShell();
const rescueTitle = createFakeElement('h2');
rescueTitle.setAttribute('id', 'libraryHeaderTitle');
rescueTitle.setAttribute('tabindex', '-1');
const documentSim3 = {
  activeElement: null,
  createElement: (tagName) => createFakeElement(tagName),
  getElementById: (elementId) => (elementId === 'libraryHeaderTitle' ? rescueTitle : null),
};
const modal3 = createSpellDetailModalComponent(shell3.dialog, {
  documentRef: documentSim3,
  onClose: () => {},
  originElement: null, // La tarjeta fue retirada del DOM por un cambio de filtro.
});

modal3.open(sampleDetail);
shell3.closeButton.dispatch('click');

assertCondition(rescueTitle.focusCount === 1, 'Si la tarjeta de origen no existe, el foco va al título de la biblioteca (RF-04.3, plan 5.3)');

// --- FASE 6: Acción reservada dentro de la ficha (RF-05.2, pila de modales) ---
console.log('\nFASE 6: Acción reservada dentro de la ficha');

const shell4 = buildFakeShell();
const reservedActions4 = [];
const modal4 = createSpellDetailModalComponent(shell4.dialog, {
  documentRef: shell4.documentSim,
  onClose: () => {},
  onReservedAction: (action, slug) => reservedActions4.push({ action, slug }),
});

modal4.open(sampleDetail);
/* open() reconstruye el cuerpo: el botón vigente es el que quedó dentro
   de detailBody tras el render (el del shell quedó huérfano). */
const liveReserveButton = shell4.detailBody.children.find(
  (child) => child.tagName === 'BUTTON'
);
liveReserveButton.dispatch('click');

assertCondition(
  reservedActions4.length === 1 && reservedActions4[0].action === 'addToGrimoire' && reservedActions4[0].slug === 'llamas-de-frieren',
  'La acción reservada dentro de la ficha notifica acción + slug (RF-05.2, plan 7.2)'
);
assertCondition(shell4.dialog.open === true, 'La ficha de detalle PERMANECE abierta bajo el diálogo de acceso (pila, RF-05.2)');

// --- Resumen final ---
console.log('\n== RESUMEN ==');
console.log(`Asertos superados: ${assertsPassed}`);
console.log(`Asertos fallidos:  ${assertsFailed}`);

if (assertsFailed === 0) {
  console.log("\nRESULTADO: EXITO — La Tarea 4.3 cumple su criterio 'Hecho cuando'.");
  process.exit(0);
}

console.log('\nRESULTADO: DENEGADO — Corregir los asertos marcados con [FALLA].');
process.exit(1);
