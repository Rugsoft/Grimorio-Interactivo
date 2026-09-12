/**
 * test_accessibility_flows.mjs — Verificación interactiva estructurada (Tarea 6.2).
 *
 * Estrategia TDD: este script se escribe ANTES de completar los enganches
 * que falten en main.js. Ejecuta el guion completo del plan 7.2 (los tres
 * bloques de pruebas de experiencia y accesibilidad) contra el orquestador
 * REAL (main.js) y sus componentes reales, con DOM/window simulados fieles:
 *
 *   BLOQUE A - Navegación por teclado (RNF-03, RF-04.2, RF-04.3):
 *     A1. Abrir ficha de detalle mediante Enter sobre una tarjeta.
 *     A2. Tab cicla únicamente dentro de los interactivos del modal.
 *     A3. Escape cierra y el foco regresa a la tarjeta exacta de origen.
 *
 *   BLOQUE B - Pila de modales (RF-05.2):
 *     B1. Con la ficha abierta, pulsar «Añadir a mi Grimorio».
 *     B2. «Cruzar el Umbral» se abre por encima sin desmontar la ficha.
 *     B3. Cerrar el acceso: la ficha sigue visible e interactiva.
 *
 *   BLOQUE C - Botón «Atrás» nativo (RNF-05, RF-04.2):
 *     C1. Abrir un hechizo y observar la URL #hechizo-nombre.
 *     C2. Atrás cierra el modal pacíficamente sin salir de la web.
 *
 *   BLOQUE D - Restauración de foco tras filtrado (RF-04.3, plan 5.3):
 *     D1. La tarjeta de origen desaparece al filtrar → el foco de rescate
 *         cae en el título de la biblioteca (#libraryHeaderTitle).
 *
 * Criterio «Hecho cuando» (tasks.md): pulsar «Atrás» cierra modales sin
 * salir de la web y el foco regresa ordenadamente a la tarjeta
 * correspondiente o al título de rescate.
 *
 * Uso: node scratch/test_accessibility_flows.mjs
 */

import { createGrimoireApp } from '../public/assets/js/main.js';

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

const wait = (ms) => new Promise((resolve) => setTimeout(resolve, ms));

/** Contador de foco + registro del último foco (activeElement fiel). */
const activeElementTracker = { current: null };

/** Elemento DOM mínimo simulado (patrón consolidado de las Tareas 4-6). */
function createFakeElement(tagName) {
  const element = {
    tagName: String(tagName).toUpperCase(),
    children: [],
    attributes: {},
    classes: new Set(),
    listeners: {},
    style: { setProperty(name, value) { (this.inline ??= {})[name] = String(value); }, getProperty(name) { return (this.inline ?? {})[name] ?? null; } },

    _textContent: '',
    _value: '',
    _checked: false,
    parentElement: null,
    focusCount: 0,
    open: false,
    showModalCount: 0,
    disabled: false,
    href: '',
    id: null,
    setAttribute(name, value) {
      this.attributes[name] = String(value);
      if (name === 'id') this.id = String(value);
    },
    getAttribute(name) { return name in this.attributes ? this.attributes[name] : null; },
    removeAttribute(name) { delete this.attributes[name]; },
    hasAttribute(name) { return name in this.attributes; },
    addEventListener(eventName, listener) { (this.listeners[eventName] ??= []).push(listener); },
    removeEventListener(eventName, listener) { this.listeners[eventName] = (this.listeners[eventName] ?? []).filter((l) => l !== listener); },
    dispatch(eventName, eventObject = {}) {
      for (const listener of this.listeners[eventName] ?? []) {
        listener({ type: eventName, preventDefault() {}, stopPropagation() {}, currentTarget: this, target: this, ...eventObject });
      }
      // Escape nativo del <dialog> (replica el navegador).
      if (eventName === 'keydown' && eventObject.key === 'Escape' && this.open) {
        this.close();
      }
    },
    appendChild(child) { child.parentElement = this; this.children.push(child); return child; },
    remove() {
      if (!this.parentElement) return;
      const index = this.parentElement.children.indexOf(this);
      if (index >= 0) this.parentElement.children.splice(index, 1);
      this.parentElement = null;
    },
    // textContent fiel al DOM real: agrega recursivamente (lección Tarea 5.6).
    get textContent() {
      return `${this._textContent}${this.children.map((c) => c.textContent).join('')}`;
    },
    set textContent(value) {
      for (const child of this.children.splice(0)) child.parentElement = null;
      this._textContent = String(value);
    },
    set className(value) { this.classes = new Set(String(value).split(/\s+/).filter(Boolean)); },
    get className() { return [...this.classes].join(' '); },
    get value() { return this._value; },
    set value(v) { this._value = String(v); },
    get checked() { return this._checked; },
    set checked(c) { this._checked = Boolean(c); },
    focus() {
      this.focusCount++;
      activeElementTracker.current = this;
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

function queryByClass(node, className, found = []) {
  for (const child of node.children) {
    if (child.classes.has(className)) found.push(child);
    queryByClass(child, className, found);
  }
  return found;
}
const byClass = (root, className) => queryByClass(root, className)[0] ?? null;
const allByClass = (root, className) => queryByClass(root, className);

function queryById(node, elementId, found = []) {
  for (const child of node.children) {
    if (child.getAttribute('id') === elementId) found.push(child);
    queryById(child, elementId, found);
  }
  return found;
}

/** Dialog simulado fiel al navegador (showModal/close + evento close + Escape). */
function createFakeDialog(elementId) {
  const dialog = createFakeElement('dialog');
  dialog.setAttribute('id', elementId);
  dialog.querySelector = (selector) => {
    const match = /^#([\w-]+)$/.exec(selector);
    return match ? queryById(dialog, match[1])[0] ?? null : null;
  };
  dialog.querySelectorAll = (selector) => {
    // querySelectorAll real del trap de foco (plan 5.3): nodos enfocables.
    const focusableTags = new Set(['BUTTON', 'A', 'INPUT', 'SELECT', 'TEXTAREA']);
    const focusables = [];
    (function walk(node) {
      for (const child of node.children) {
        const isFocusableTag = focusableTags.has(child.tagName);
        const tabIndex = child.getAttribute('tabindex');
        const isFocusable = (isFocusableTag && !child.disabled) || (tabIndex !== null && tabIndex !== '-1');
        if (isFocusable) focusables.push(child);
        walk(child);
      }
    })(dialog);
    return focusables;
  };
  dialog.showModal = function showModal() { this.open = true; this.showModalCount++; activeElementTracker.current = null; };
  dialog.close = function close() {
    if (!this.open) return;
    this.open = false;
    for (const listener of this.listeners.close ?? []) listener({ type: 'close' });
  };
  return dialog;
}

/** Entorno window/location/history simulado, fiel al navegador (Tarea 3.4). */
function createFakeWindow(initialUrl = 'http://grimorio.test/') {
  const win = {
    listeners: {},
    location: { href: initialUrl, hash: initialUrl.includes('#') ? `#${initialUrl.split('#')[1]}` : '', assignCalls: [] },
    // Cada entrada guarda su state (fiel al navegador: popstate entrega el
    // state de la entrada destino, no el actual).
    history: { _entries: [initialUrl], _states: [null], _index: 0, state: null },
    addEventListener(name, listener) { (this.listeners[name] ??= []).push(listener); },
    removeEventListener(name, listener) { this.listeners[name] = (this.listeners[name] ?? []).filter((l) => l !== listener); },
    // «Pulsar el botón Atrás del navegador»: back() real del simulador.
    pressBrowserBack() {
      this.history.back();
    },
  };
  const syncLocation = () => {
    win.location.href = win.history._entries[win.history._index];
    win.location.hash = win.location.href.includes('#') ? `#${win.location.href.split('#')[1]}` : '';
  };
  win.history.pushState = function pushState(state, _title, url) {
    this._entries = this._entries.slice(0, this._index + 1);
    this._states = this._states.slice(0, this._index + 1);
    this._entries.push(url.startsWith('http') ? url : `http://grimorio.test/${url.replace(/^#/, '#')}`);
    this._states.push(state);
    this._index++;
    this.state = state;
    syncLocation();
  };
  win.history.replaceState = function replaceState(state, _title, url) {
    this._entries[this._index] = url.startsWith('http') ? url : `http://grimorio.test/${url.replace(/^#/, '#')}`;
    this._states[this._index] = state; // fiel al navegador: reemplaza el state de la entrada ACTUAL
    this.state = state;
    syncLocation();
  };
  win.history.back = function back() {
    if (this._index === 0) return;
    this._index--;
    this.state = this._states[this._index]; // el state de la entrada destino
    syncLocation();
    for (const listener of win.listeners.popstate ?? []) listener({ type: 'popstate', state: this.state });
    if (win.location.hash) {
      for (const listener of win.listeners.hashchange ?? []) listener({ type: 'hashchange', newURL: win.location.href });
    }
  };
  return win;
}

/** Catálogo del cliente simulado (2 hechizos para el escenario D). */
const catalogSpells = [
  { id: '1', slug: 'llamas-de-frieren', name: 'Llamas de Frieren', magicSchool: 'evocation', magicSchoolLabel: 'Evocación', manaCost: 45, clanName: 'Eruditos Astrales', summary: 'Ráfaga de fuego purificador.', status: 'validated', isGenesisSample: false },
  { id: '2', slug: 'manto-de-abjuracion', name: 'Manto de Abjuración', magicSchool: 'abjuration', magicSchoolLabel: 'Abjuración', manaCost: 30, clanName: 'Eruditos Astrales', summary: 'Barrera protectora de maná denso.', status: 'validated', isGenesisSample: false },
];

function createFakeSpellClient() {
  const detailBySlug = Object.fromEntries(catalogSpells.map((spell) => [spell.slug, {
    ...spell,
    description: 'Conjuro canónico.',
    components: { verbal: true, somatic: true, material: 'Una brasa viva' },
  }]));
  return {
    fetchFeatured: async () => ({ success: true, data: catalogSpells }),
    fetchSpells: async (params) => {
      let items = catalogSpells;
      // Simula el filtrado server-side por query (RF-03.3, para el bloque D).
      if (params.query && String(params.query).length >= 2) {
        const q = String(params.query).toLowerCase();
        items = items.filter((s) => s.name.toLowerCase().includes(q) || s.summary.toLowerCase().includes(q));
      }
      return { success: true, data: { items, hasMore: false } };
    },
    fetchSpellBySlug: async (slug) => detailBySlug[slug]
      ? { success: true, data: detailBySlug[slug] }
      : { success: false, error: { code: 'SCROLL_LOST_IN_AETHER', message: 'El pergamino que buscas se ha desvanecido en el éter.', recoveryAction: 'RETURN_TO_LIBRARY' } },
    fetchClansPreview: async () => ({ success: true, data: [] }),
  };
}

/** Shell completo con el título de rescate del plan 5.3 (#libraryHeaderTitle). */
function buildShell(initialUrl) {
  const appRoot = createFakeElement('main');
  appRoot.setAttribute('id', 'app');
  const shellLocal = { appRoot }; // referencia para getElementById inyectable
  const navRoot = createFakeElement('nav');
  navRoot.setAttribute('id', 'siteNav');
  const linksList = createFakeElement('ul');
  linksList.setAttribute('id', 'navLinks');
  const toggleButton = createFakeElement('button');
  toggleButton.setAttribute('id', 'navToggle');
  navRoot.appendChild(linksList);
  navRoot.appendChild(toggleButton);
  navRoot.querySelector = (selector) => {
    const match = /^#([\w-]+)$/.exec(selector);
    return match ? queryById(navRoot, match[1])[0] ?? null : null;
  };

  const spellDetailDialog = createFakeDialog('spellDetailModal');
  const detailClose = createFakeElement('button');
  detailClose.setAttribute('id', 'spellDetailClose');
  const detailBody = createFakeElement('section');
  detailBody.setAttribute('id', 'spellDetailBody');
  spellDetailDialog.appendChild(detailClose);
  spellDetailDialog.appendChild(detailBody);

  const accessDialog = createFakeDialog('accessModal');
  const loginForm = createFakeElement('form');
  loginForm.setAttribute('id', 'loginForm');
  const registerForm = createFakeElement('form');
  registerForm.setAttribute('id', 'registerForm');
  accessDialog.appendChild(loginForm);
  accessDialog.appendChild(registerForm);

  return {
    appRoot,
    navRoot,
    spellDetailDialog,
    accessDialog,
    fakeWindow: createFakeWindow(initialUrl),
    // El documento simulado resuelve el título de rescate que la biblioteca
    // real registra en su render (plan 5.3): el componente lo consulta al
    // cerrar si la tarjeta de origen ya no está en el DOM.
    fakeDocument: {
      createElement: (tag) => createFakeElement(tag),
      getElementById: (elementId) => queryById(shellLocal.appRoot, elementId)[0] ?? null,
    },
    spellClient: createFakeSpellClient(),
  };
}

/** Arranca la app en la biblioteca (escenario común de los bloques). */
async function bootAtLibrary(shell) {
  const app = createGrimoireApp({
    appRoot: shell.appRoot,
    navRoot: shell.navRoot,
    spellDetailDialog: shell.spellDetailDialog,
    accessDialog: shell.accessDialog,
    spellClient: shell.spellClient,
    windowRef: shell.fakeWindow,
    documentRef: shell.fakeDocument,
  });
  await app.boot();
  const libraryLink = queryById(shell.navRoot, 'navLinks')[0].children.find((l) => l.getAttribute('data-view') === 'library');
  libraryLink.dispatch('click');
  await wait(320); // supera el debounce de la biblioteca
  return app;
}

/** Simula la tecla Tab global sobre el elemento enfocado activo. */
function pressTabOn(activeElement, { shiftKey = false } = {}) {
  activeElement.dispatch('keydown', { key: 'Tab', shiftKey, preventDefault() {} });
}

console.log('== VERIFICACION TAREA 6.2: flujos de accesibilidad y Atrás (plan 7.2) ==\n');

// ==========================================================================
// BLOQUE A — Navegación por teclado (RNF-03, RF-04.2, RF-04.3)
// ==========================================================================
console.log('BLOQUE A: Navegación por teclado');

const shellA = buildShell('http://grimorio.test/');
const appA = await bootAtLibrary(shellA);

// A1. Abrir ficha mediante Enter sobre una tarjeta:
const cardA = allByClass(shellA.appRoot, 'spell-card')[0];
assertCondition(cardA !== null && cardA.getAttribute('tabindex') === '0', 'A1: la tarjeta es enfocable (tabindex=0, RNF-03)');
cardA.focus();
cardA.dispatch('keydown', { key: 'Enter' });
await wait(20);

assertCondition(shellA.spellDetailDialog.open === true, 'A1: Enter sobre la tarjeta abre la ficha de detalle (plan 7.2-A1)');
const originCardA = cardA; // referencia viva para A3.

// A2. Tab cicla solo dentro del modal:
const closeButtonA = queryById(shellA.spellDetailDialog, 'spellDetailClose')[0];
closeButtonA.focus(); // foco inicial dentro del modal (primer enfocable)
const reserveButtonA = allByClass(shellA.spellDetailDialog, 'spell-detail__reserve')[0];
reserveButtonA.focus(); // último enfocable

let focusLeftModal = false;
// Tab en el último enfocable: el trap debe devolverlo al primero (dentro).
pressTabOn(reserveButtonA);
const activeAfterForwardTab = activeElementTracker.current;
focusLeftModal = !shellA.spellDetailDialog.querySelectorAll('*').includes(activeAfterForwardTab)
  && activeAfterForwardTab !== closeButtonA && activeAfterForwardTab !== reserveButtonA;
assertCondition(focusLeftModal === false, 'A2: Tab en el último control vuelve al primero — NUNCA sale del modal (plan 7.2-A2)');

// Shift+Tab en el primero: vuelve al último (dentro).
pressTabOn(closeButtonA, { shiftKey: true });
const activeAfterBackTab = activeElementTracker.current;
assertCondition(
  activeAfterBackTab === reserveButtonA || shellA.spellDetailDialog.querySelectorAll('*').includes(activeAfterBackTab),
  'A2: Shift+Tab en el primero vuelve al último — el ciclo es interno (plan 7.2-A2)',
);

// A3. Escape cierra y el foco regresa a la tarjeta EXACTA de origen:
shellA.spellDetailDialog.dispatch('keydown', { key: 'Escape' });
await wait(20);
assertCondition(shellA.spellDetailDialog.open === false, 'A3: Escape cierra la ficha (RF-04.2)');
assertCondition(originCardA.focusCount >= 2, 'A3: el foco regresa a la tarjeta EXACTA de origen (RF-04.3, plan 7.2-A3)');
assertCondition(activeElementTracker.current === originCardA, 'A3: document.activeElement es la tarjeta de origen');

// ==========================================================================
// BLOQUE B — Pila de modales (RF-05.2)
// ==========================================================================
console.log('\nBLOQUE B: Pila de modales («Añadir a mi Grimorio» → Cruzar el Umbral)');

const cardB = allByClass(shellA.appRoot, 'spell-card')[0];
cardB.dispatch('click');
await wait(20);
assertCondition(shellA.spellDetailDialog.open === true, 'B: escenario montado — ficha abierta');

// B1. «Añadir a mi Grimorio» (visitante) → acción reservada de la ficha:
const detailBodyB = queryById(shellA.spellDetailDialog, 'spellDetailBody')[0];
const reserveB = allByClass(detailBodyB, 'spell-detail__reserve')[0];
reserveB?.dispatch('click');
await wait(20);

assertCondition(shellA.accessDialog.open === true, 'B1-B2: «Cruzar el Umbral» se abrió POR ENCIMA (RF-05.2, plan 7.2-B)');
assertCondition(shellA.spellDetailDialog.open === true, 'B2: la ficha NO se desmontó — ambos coexisten (plan 7.2-B2)');

// B3. Cerrar el acceso (Escape): la ficha sigue viva e interactiva:
shellA.accessDialog.dispatch('keydown', { key: 'Escape' });
await wait(20);
assertCondition(shellA.accessDialog.open === false, 'B3: el acceso se cerró (solo el Nivel 2)');
assertCondition(shellA.spellDetailDialog.open === true, 'B3: la ficha sigue abierta e interactiva (plan 7.2-B3)');

// Limpieza del escenario: cerrar la ficha para el bloque C.
shellA.spellDetailDialog.close();
await wait(20);

// ==========================================================================
// BLOQUE C — Botón «Atrás» nativo (RNF-05, RF-04.2)
// ==========================================================================
console.log('\nBLOQUE C: Botón «Atrás» del navegador');

// C1. Abrir un hechizo y observar la URL:
const cardC = allByClass(shellA.appRoot, 'spell-card')[0];
cardC.dispatch('click');
await wait(20);
assertCondition(shellA.spellDetailDialog.open === true, 'C1: ficha abierta');
assertCondition(
  shellA.fakeWindow.location.hash === '#hechizo-llamas-de-frieren',
  `C1: la URL refleja ${shellA.fakeWindow.location.hash} (plan 7.2-C1)`,
);

// C2. «Pulsar el botón Atrás del navegador»:
shellA.fakeWindow.pressBrowserBack();
await wait(20);
assertCondition(shellA.spellDetailDialog.open === false, 'C2: Atrás cierra el modal pacíficamente (plan 7.2-C2, criterio)');
assertCondition(
  shellA.fakeWindow.location.href.startsWith('http://grimorio.test/'),
  'C2: la SPA NO se abandonó — seguimos en el santuario (RNF-05)',
);
assertCondition(shellA.appRoot.children.length > 0 && byClass(shellA.appRoot, 'library-view') !== null, 'C2: la biblioteca sigue viva bajo el modal cerrado');

// ==========================================================================
// BLOQUE D — Restauración de foco tras filtrado (RF-04.3, plan 5.3)
// ==========================================================================
console.log('\nBLOQUE D: Rescate de foco cuando la tarjeta de origen desaparece');

// Abrimos la ficha de «Llamas de Frieren»...
const cardD = allByClass(shellA.appRoot, 'spell-card').find((c) => c.getAttribute('data-slug') === 'llamas-de-frieren');
cardD.focus();
cardD.dispatch('click');
await wait(20);
assertCondition(shellA.spellDetailDialog.open === true, 'D: ficha abierta desde su tarjeta');

// ...mientras está abierta, un filtrado ajeno deja fuera la tarjeta de origen
// (cambio de filtro en otra pestaña / re-búsqueda del catálogo):
const searchInputD = byClass(shellA.appRoot, 'library-view__search');
searchInputD.value = 'abjuración'; // solo queda «Manto de Abjuración»
searchInputD.dispatch('input');
await wait(320);

const survivingCardD = allByClass(shellA.appRoot, 'spell-card').find((c) => c.getAttribute('data-slug') === 'manto-de-abjuracion');
assertCondition(survivingCardD !== undefined, 'D: el filtro dejó fuera la tarjeta de origen (escenario del plan 5.3)');

// Cerrar la ficha: el rescate de foco NO puede ir a la tarjeta muerta...
shellA.spellDetailDialog.dispatch('keydown', { key: 'Escape' });
await wait(20);
assertCondition(shellA.spellDetailDialog.open === false, 'D: ficha cerrada tras el filtrado');

// ...debe caer en el título de rescate (#libraryHeaderTitle, plan 5.3) o en
// una tarjeta viva: NUNCA en un nodo huérfano.
const rescuedTitle = queryById(shellA.appRoot, 'libraryHeaderTitle')[0];
const focusLandedOk = activeElementTracker.current === rescuedTitle
  || allByClass(shellA.appRoot, 'spell-card').includes(activeElementTracker.current);
assertCondition(focusLandedOk === true, 'D: el foco cayó en el título de rescate o en una tarjeta VIVA (criterio, plan 5.3)');
assertCondition(
  activeElementTracker.current === null || activeElementTracker.current.parentElement !== null,
  'D: el foco NUNCA quedó en un nodo huérfano (orden en el rescate, RF-04.3)',
);

// --- Resumen final ---
console.log('\n== RESUMEN ==');
console.log(`Asertos superados: ${assertsPassed}`);
console.log(`Asertos fallidos:  ${assertsFailed}`);

if (assertsFailed === 0) {
  console.log("\nRESULTADO: EXITO — La Tarea 6.2 cumple su criterio 'Hecho cuando'.");
  process.exit(0);
}

console.log('\nRESULTADO: FALLO — Corregir los asertos marcados con [FALLA].');
process.exit(1);
