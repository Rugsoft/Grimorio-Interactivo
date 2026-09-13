/**
 * test_main_orchestrator.mjs — Verificación del Orquestador central (Tarea 6.1).
 *
 * Estrategia TDD: este script se escribe ANTES que main.js.
 * Valida contra el criterio "Hecho cuando" de specs/01-portal-and-navigation.tasks.md:
 *   Cargar la URL base muestra la portada, navegar por la barra cambia
 *   fluidamente entre vistas sin recarga completa y acceder directamente a
 *   un hash #hechizo-slug abre la ficha técnica de dicho hechizo de forma
 *   automática.
 *
 * Integra: store (3.2), spellClient (3.3), historyManager (3.4), navbar (4.1),
 * modales (4.3/4.4), vistas (5.1-5.6) y errorView (5.5).
 *
 * Uso: node scratch/test_main_orchestrator.mjs
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

/** Elemento DOM mínimo simulado (patrón consolidado de las Tareas 4-5). */
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
    focus() { this.focusCount++; },
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

/** Búsqueda recursiva por id (querySelector real). */
function queryById(node, elementId, found = []) {
  for (const child of node.children) {
    if (child.getAttribute('id') === elementId) found.push(child);
    queryById(child, elementId, found);
  }
  return found;
}

/** Dialog simulado fiel al navegador (showModal/close + evento close). */
function createFakeDialog(elementId) {
  const dialog = createFakeElement('dialog');
  dialog.setAttribute('id', elementId);
  dialog.querySelector = (selector) => {
    const match = /^#([\w-]+)$/.exec(selector);
    return match ? queryById(dialog, match[1])[0] ?? null : null;
  };
  dialog.querySelectorAll = () => [];
  dialog.showModal = function showModal() { this.open = true; this.showModalCount++; };
  dialog.close = function close() {
    if (!this.open) return;
    this.open = false;
    for (const listener of this.listeners.close ?? []) listener({ type: 'close' });
  };
  return dialog;
}

/** Entorno window/location/history simulado (fiel al navegador, Tarea 3.4). */
function createFakeWindow(initialUrl = 'http://grimorio.test/') {
  const win = {
    listeners: {},
    // El hash inicial se deriva de la URL (fiel al navegador: una URL con
    // #hechizo-slug expone location.hash).
    location: { href: initialUrl, hash: initialUrl.includes('#') ? `#${initialUrl.split('#')[1]}` : '', assign(url) { win.location.assignCalls.push(url); }, assignCalls: [] },
    history: { _entries: [initialUrl], _index: 0, state: null },
    addEventListener(name, listener) { (this.listeners[name] ??= []).push(listener); },
    removeEventListener(name, listener) { this.listeners[name] = (this.listeners[name] ?? []).filter((l) => l !== listener); },
    dispatchWindow(name, eventObject = {}) {
      for (const listener of this.listeners[name] ?? []) listener({ type: name, state: this.history.state, ...eventObject });
    },
  };
  const resolveUrl = (url) => (url.startsWith('http') ? url : `http://grimorio.test/${url.replace(/^#/, '#')}`);
  win.history.pushState = function pushState(state, _title, url) {
    this._entries = this._entries.slice(0, this._index + 1);
    this._entries.push(resolveUrl(url));
    this._index++;
    this.state = state;
    win.location.href = this._entries[this._index];
    win.location.hash = win.location.href.includes('#') ? `#${win.location.href.split('#')[1]}` : '';
  };
  win.history.replaceState = function replaceState(state, _title, url) {
    this._entries[this._index] = resolveUrl(url);
    this.state = state;
    win.location.href = this._entries[this._index];
    win.location.hash = win.location.href.includes('#') ? `#${win.location.href.split('#')[1]}` : '';
  };
  win.history.back = function back() {
    if (this._index === 0) return;
    this._index--;
    this.state = this._index === 0 ? null : this.state;
    win.location.href = this._entries[this._index];
    win.location.hash = win.location.href.includes('#') ? `#${win.location.href.split('#')[1]}` : '';
    for (const listener of win.listeners.popstate ?? []) listener({ type: 'popstate', state: this.state });
    if (win.location.hash) {
      for (const listener of win.listeners.hashchange ?? []) listener({ type: 'hashchange', newURL: win.location.href });
    }
  };
  return win;
}

/** spellClient de integración: el contrato completo que consumen las vistas. */
function createFakeSpellClient() {
  const calls = { detail: [] };
  const featured = [
    { id: '1', slug: 'chispa-de-ignicion', name: 'Chispa de Ignición', magicSchool: 'evocation', magicSchoolLabel: 'Evocación', manaCost: 10, clanName: 'Custodios del Fuego Primordial', summary: 'Llama diminuta del génesis.', status: 'validated', isGenesisSample: true },
  ];
  const catalog = [
    { id: '1', slug: 'llamas-de-frieren', name: 'Llamas de Frieren', magicSchool: 'evocation', magicSchoolLabel: 'Evocación', manaCost: 45, clanName: 'Eruditos Astrales', summary: 'Ráfaga de fuego purificador.', status: 'validated', isGenesisSample: false },
  ];
  const detail = {
    id: '1', slug: 'llamas-de-frieren', name: 'Llamas de Frieren', magicSchool: 'evocation', magicSchoolLabel: 'Evocación',
    manaCost: 45, clanName: 'Eruditos Astrales', summary: 'Ráfaga de fuego purificador.', description: 'Conjuro canónico de fuego.',
    status: 'validated', isGenesisSample: false,
    components: { verbal: true, somatic: true, material: 'Una brasa viva' },
  };
  return {
    calls,
    fetchFeatured: async () => ({ success: true, data: featured }),
    fetchSpells: async () => ({ success: true, data: { items: catalog, hasMore: false } }),
    fetchSpellBySlug: async (slug) => {
      calls.detail.push(slug);
      return slug === 'llamas-de-frieren'
        ? { success: true, data: detail }
        : { success: false, error: { code: 'SCROLL_LOST_IN_AETHER', message: 'El pergamino que buscas se ha desvanecido en el éter.', recoveryAction: 'RETURN_TO_LIBRARY' } };
    },
    fetchClansPreview: async () => ({ success: true, data: [] }),
  };
}

/** Shell completo del index.html (solo los nodos que el orquestador toca). */
function buildFakeShell(initialUrl) {
  const appRoot = createFakeElement('main');
  appRoot.setAttribute('id', 'app');
  const navRoot = createFakeElement('nav');
  navRoot.setAttribute('id', 'siteNav');
  navRoot.querySelector = (selector) => {
    // El navbar busca #navLinks y #navToggle (querySelector real del shell).
    const match = /^#([\w-]+)$/.exec(selector);
    return match ? queryById(navRoot, match[1])[0] ?? null : null;
  };
  navRoot.querySelectorAll = (selector) => {
    const match = /^#([\w-]+)$/.exec(selector);
    return match ? queryById(navRoot, match[1]) : [];
  };
  // El navbar del shell real porta la lista y el botón del menú móvil:
  const linksList = createFakeElement('ul');
  linksList.setAttribute('id', 'navLinks');
  const toggleButton = createFakeElement('button');
  toggleButton.setAttribute('id', 'navToggle');
  navRoot.appendChild(linksList);
  navRoot.appendChild(toggleButton);

  const spellDetailDialog = createFakeDialog('spellDetailModal');
  const detailBody = createFakeElement('section');
  detailBody.setAttribute('id', 'spellDetailBody');
  const detailClose = createFakeElement('button');
  detailClose.setAttribute('id', 'spellDetailClose');
  spellDetailDialog.appendChild(detailClose);
  spellDetailDialog.appendChild(detailBody);

  const accessDialog = createFakeDialog('accessModal');
  const loginForm = createFakeElement('form');
  loginForm.setAttribute('id', 'loginForm');
  const registerForm = createFakeElement('form');
  registerForm.setAttribute('id', 'registerForm');
  accessDialog.appendChild(loginForm);
  accessDialog.appendChild(registerForm);

  const fakeWindow = createFakeWindow(initialUrl);
  const fakeDocument = { createElement: (tag) => createFakeElement(tag) };

  // authClient de integración (SPEC-03): el bind responde éxito con la
  // sesión del vínculo (materia prima del FASE 5); el resto devuelve
  // sobres controlados de visitante.
  const sessionUser = { id: 'usr_1', alias: 'friki', role: 'editor', clanId: 'cln_primordial', clanName: 'Custodios del Fuego Primordial' };
  const authClient = {
    checkSession: async () => ({ success: true, status: 200, data: { authenticated: false, user: null } }),
    bind: async (identity, passphrase) => ({ success: true, status: 200, data: { user: sessionUser } }),
    consecrate: async (data) => ({ success: true, status: 201, data: { user: sessionUser } }),
    dissolve: async () => ({ success: true, status: 200, data: { dissolved: true } }),
    dissolveAll: async () => ({ success: true, status: 200, data: { dissolved: true } }),
  };

  return { appRoot, navRoot, spellDetailDialog, accessDialog, fakeWindow, fakeDocument, spellClient: createFakeSpellClient(), authClient };
}

console.log('== VERIFICACION TAREA 6.1: main.js (orquestador central) ==\n');

/**
 * Enlaces del navbar: el componente los identifica por data-view
 * (sin clase CSS; contrato real del componente de la Tarea 4.1).
 */
function findNavLink(shell, viewName) {
  const linksList = queryById(shell.navRoot, 'navLinks')[0];
  return (linksList?.children ?? []).find((link) => link.getAttribute('data-view') === viewName) ?? null;
}

// --- FASE 1: CRITERIO — cargar la URL base muestra la portada ---
console.log('FASE 1: Criterio (URL base → portada)');

const shell1 = buildFakeShell('http://grimorio.test/');
const app1 = createGrimoireApp({
  appRoot: shell1.appRoot,
  navRoot: shell1.navRoot,
  spellDetailDialog: shell1.spellDetailDialog,
  accessDialog: shell1.accessDialog,
  spellClient: shell1.spellClient,
  authClient: shell1.authClient,
  windowRef: shell1.fakeWindow,
  documentRef: shell1.fakeDocument,
});
await app1.boot();

assertCondition(byClass(shell1.appRoot, 'landing-view') !== null, 'Cargar la URL base monta la portada (criterio, RF-01.1)');
assertCondition(app1.store.getState().currentView === 'landing', 'El store registra currentView=landing (plan 4.1)');
assertCondition(findNavLink(shell1, 'library') !== null && findNavLink(shell1, 'clans') !== null, 'La barra de navegación está montada con sus enlaces (RF-02.1)');
assertCondition(shell1.fakeWindow.location.assignCalls.length === 0, 'El arranque NO provocó recarga ni asignación de location (SPA pura)');

// --- FASE 2: CRITERIO — la barra navega entre vistas sin recarga ---
console.log('\nFASE 2: Criterio (navegación fluida por la barra, sin recarga)');

const libraryLink2 = findNavLink(shell1, 'library');
libraryLink2.dispatch('click');
await wait(20);

assertCondition(byClass(shell1.appRoot, 'library-view') !== null, 'Pulsar «Biblioteca» monta la vista de biblioteca (RF-03)');
assertCondition(byClass(shell1.appRoot, 'landing-view') === null, 'La portada se desmontó al navegar (una vista activa a la vez)');
assertCondition(shell1.fakeWindow.location.assignCalls.length === 0, 'La navegación NO recargó la página (criterio: cambio fluido)');

const clansLink2 = findNavLink(shell1, 'clans');
clansLink2.dispatch('click');
await wait(20);
assertCondition(byClass(shell1.appRoot, 'clans-view') !== null, 'Pulsar «Salón de Linajes» monta su vista (RF-02.2)');
assertCondition(app1.store.getState().currentView === 'clans', 'El store sincroniza currentView=clans');

// Regreso a la biblioteca para las fases siguientes:
const libraryLink2b = findNavLink(shell1, 'library');
libraryLink2b.dispatch('click');
await wait(320);
assertCondition(allByClass(shell1.appRoot, 'spell-card').length === 1, 'La biblioteca quedó operativa tras la vuelta (catálogo cargado)');

// --- FASE 3: CRITERIO — hash directo #hechizo-slug abre la ficha ---
console.log('\nFASE 3: Criterio (hash directo abre la ficha automáticamente)');

const shell3 = buildFakeShell('http://grimorio.test/#hechizo-llamas-de-frieren');
const app3 = createGrimoireApp({
  appRoot: shell3.appRoot,
  navRoot: shell3.navRoot,
  spellDetailDialog: shell3.spellDetailDialog,
  accessDialog: shell3.accessDialog,
  spellClient: shell3.spellClient,
  authClient: shell3.authClient,
  windowRef: shell3.fakeWindow,
  documentRef: shell3.fakeDocument,
});
await app3.boot();

assertCondition(
  shell3.spellClient.calls.detail.includes('llamas-de-frieren'),
  'El orquestador solicitó la ficha del slug del hash inicial (RF-04.1, plan 4.3)',
);
assertCondition(shell3.spellDetailDialog.open === true && shell3.spellDetailDialog.showModalCount === 1, 'La ficha técnica se abrió automáticamente con showModal (criterio)');
assertCondition(shell3.spellDetailDialog.textContent.includes('Llamas de Frieren'), 'La ficha muestra el hechizo del enlace compartido');
assertCondition(app3.store.getState().activeModal.type === 'spellDetail', 'El store registra el modal activo (plan 4.1)');

// --- FASE 4: Selección de tarjeta → ficha + historial (RF-04.1, RNF-05) ---
console.log('\nFASE 4: Selección de tarjeta abre ficha y empuja historial');

const card4 = allByClass(shell1.appRoot, 'spell-card')[0];
card4.dispatch('click');
await wait(20);

assertCondition(shell1.spellClient.calls.detail.includes('llamas-de-frieren'), 'La selección de tarjeta pidió la ficha a la API');
assertCondition(shell1.spellDetailDialog.open === true, 'La ficha técnica se abrió superpuesta');
assertCondition(
  shell1.fakeWindow.location.hash === '#hechizo-llamas-de-frieren',
  'La URL refleja #hechizo-slug (enlaces compartibles, RF-04.1)',
);

// Cierre → sincroniza historial sin abandonar la web:
shell1.spellDetailDialog.close();
await wait(20);
assertCondition(shell1.spellDetailDialog.open === false && shell1.fakeWindow.location.assignCalls.length === 0, 'El cierre de la ficha no abandona la aplicación (sin recargas)');

// --- FASE 5: Acción reservada → intención + «Cruzar el Umbral» (RF-05.2) ---
console.log('\nFASE 5: Visitante + acción reservada retiene intención y abre el acceso');

// Desde la portada: el CTA «Consagrar Linaje» (RF-01.4).
const libraryLink5 = findNavLink(shell1, 'landing');
libraryLink5.dispatch('click');
await wait(320);
const consacrateButton5 = byClass(shell1.appRoot, 'landing-hero__cta');
consacrateButton5.dispatch('click');
await wait(20);

assertCondition(shell1.accessDialog.open === true, 'La acción reservada abrió el diálogo «Cruzar el Umbral» (RF-05.1)');
assertCondition(
  app1.store.getState().pendingIntent.action === 'joinClan',
  'La intención quedó retenida en store.pendingIntent (RF-05.2, Tarea 3.2)',
);

// Autenticación → la intención se consume (RF-05.3, punto de enganche SPEC-03):
const loginForm5 = queryById(shell1.accessDialog, 'loginForm')[0];
loginForm5.dispatch('submit', { fields: { loginName: 'friki', loginPassword: 'mana' } });
await wait(20);
assertCondition(
  app1.store.getState().pendingIntent.action === null,
  'Autenticado el vínculo, la intención se consume (RF-05.3)',
);
assertCondition(shell1.accessDialog.open === false, 'El diálogo de acceso se cerró tras autenticar (flujo completo)');

// --- FASE 6: 404 → vista de rescate con retorno (RF-06.2) ---
console.log('\nFASE 6: Slug inexistente → «Pergamino desvanecido» con retorno');

const libraryLink6 = findNavLink(shell1, 'library');
libraryLink6.dispatch('click');
await wait(320);
const lostCard6 = createFakeElement('article');
// La ficha de un slug inexistente: simulamos la selección vía la API pública del orquestador.
await app1.openSpellDetailBySlug?.('trueno-prohibido');
assertCondition(byClass(shell1.appRoot, 'error-view') !== null, 'El 404 monta la vista de rescate (RF-06.2)');
assertCondition(
  byClass(shell1.appRoot, 'error-view')?.textContent.includes('desvanecido en el éter'),
  'La vista porta «Pergamino desvanecido en el éter» (criterio de la Tarea 5.5 integrado)',
);
const returnLink6 = byClass(shell1.appRoot, 'error-view__return');
returnLink6.dispatch('click');
await wait(20);
assertCondition(byClass(shell1.appRoot, 'library-view') !== null, 'El retorno conduce de vuelta a la biblioteca (rescate completo)');

// --- FASE 7: destroy() global limpio (RNF-05) ---
console.log('\nFASE 7: destroy() del orquestador');

app1.destroy();
assertCondition(shell1.appRoot.children.length === 0, 'destroy() desmonta la vista activa');
assertCondition(shell1.fakeWindow.location.assignCalls.length === 0, 'Todo el ciclo vital transcurrió sin recargas de página (SPA íntegra)');

// --- Resumen final ---
console.log('\n== RESUMEN ==');
console.log(`Asertos superados: ${assertsPassed}`);
console.log(`Asertos fallidos:  ${assertsFailed}`);

if (assertsFailed === 0) {
  console.log("\nRESULTADO: EXITO — La Tarea 6.1 cumple su criterio 'Hecho cuando'.");
  process.exit(0);
}

console.log('\nRESULTADO: FALLO — Corregir los asertos marcados con [FALLA].');
process.exit(1);
