/**
 * test_navbar_session_refresh.mjs — Arnés de regresión del repintado de la
 * cabecera al cambiar la sesión.
 *
 * Estrategia TDD: este script se escribe ANTES del arreglo. Documenta el
 * defecto hallado en la revisión del cableado del Simulador (SPEC-05): la
 * navbar capturaba `isAuthenticated` UNA sola vez en la fábrica y el
 * orquestador no la volvía a pintar al cambiar la sesión, de modo que un
 * erudito YA vinculado que pulsaba «Creador de Hechizos» desde la cabecera
 * recibía «Cruzar el Umbral» en lugar del Taller de Hechizos.
 *
 * Fases:
 *   [A] Arranque con vínculo vivo → el enlace reservado NAVEGA (sin umbral).
 *   [B] Visitante anónimo → el enlace reservado INTERCEPTA (no regresión, RF-02.3).
 *   [C] Al disolver el vínculo la cabecera se re-pinta y vuelve a interceptar.
 *   [D] El repintado es real y no apila listeners (menú móvil + Escape).
 *   [E] El enlace público del Simulador (SPEC-05) queda intacto en ambos estados.
 *
 * Constitución:
 *   - Artículo I: Node nativo con DOM simulado (patrón consolidado de la
 *     suite de arneses); cero dependencias externas.
 *   - Artículo V: identificadores en inglés camelCase; crónica en castellano.
 *
 * Uso: node scratch/test_navbar_session_refresh.mjs
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

/** Contexto 2D permisivo: el lienzo arcano se monta sin motor real. */
function createFakeContext() {
  return {
    canvas: null,
    save() {}, restore() {}, beginPath() {}, closePath() {}, fill() {}, stroke() {},
    arc() {}, arcTo() {}, ellipse() {}, rect() {}, roundRect() {}, clip() {},
    moveTo() {}, lineTo() {}, quadraticCurveTo() {}, bezierCurveTo() {},
    fillRect() {}, clearRect() {}, strokeRect() {}, translate() {}, rotate() {}, scale() {},
    setTransform() {}, resetTransform() {}, setLineDash() {}, fillText() {}, strokeText() {},
    drawImage() {},
    measureText() { return { width: 0 }; },
    createRadialGradient() { return { addColorStop() {} }; },
    createLinearGradient() { return { addColorStop() {} }; },
  };
}

/** Elemento DOM mínimo simulado (patrón consolidado de las Tareas 4-5). */
function createFakeElement(tagName) {
  const element = {
    tagName: String(tagName).toUpperCase(),
    children: [],
    attributes: {},
    classes: new Set(),
    listeners: {},
    parentElement: null,
    focusCount: 0,
    open: false,
    showModalCount: 0,
    disabled: false,
    href: '',
    width: 800,
    height: 400,

    // Estilos en línea: los componentes de combate escriben por setProperty.
    style: {
      _properties: {},
      setProperty(name, value) { this._properties[name] = String(value); },
      removeProperty(name) { delete this._properties[name]; },
      getPropertyValue(name) { return this._properties[name] ?? ''; },
    },

    // Lienzo: el Tomo Arcano lo reclama por clase.
    getContext() { return this._context ??= createFakeContext(); },

    _textContent: '',
    _value: '',
    _checked: false,

    setAttribute(name, value) { this.attributes[name] = String(value); },
    getAttribute(name) { return name in this.attributes ? this.attributes[name] : null; },
    removeAttribute(name) { delete this.attributes[name]; },
    hasAttribute(name) { return name in this.attributes; },
    addEventListener(eventName, listener) { (this.listeners[eventName] ??= []).push(listener); },
    removeEventListener(eventName, listener) {
      this.listeners[eventName] = (this.listeners[eventName] ?? []).filter((l) => l !== listener);
    },
    dispatch(eventName, eventObject = {}) {
      for (const listener of this.listeners[eventName] ?? []) {
        listener({
          type: eventName,
          preventDefault() {},
          stopPropagation() {},
          currentTarget: this,
          target: this,
          ...eventObject,
        });
      }
    },
    appendChild(child) { child.parentElement = this; this.children.push(child); return child; },
    removeChild(child) {
      const index = this.children.indexOf(child);
      if (index >= 0) this.children.splice(index, 1);
      child.parentElement = null;
      return child;
    },
    // Vacía y repuebla de una vez (el DOM real lo porta; las vistas lo usan).
    replaceChildren(...nodes) {
      for (const child of this.children.splice(0)) child.parentElement = null;
      for (const node of nodes) {
        if (node === null || node === undefined) continue;
        if (typeof node === 'string') {
          const textNode = createFakeElement('#text');
          textNode.textContent = node;
          this.appendChild(textNode);
        } else {
          this.appendChild(node);
        }
      }
    },
    remove() {
      const index = this.parentElement?.children.indexOf(this) ?? -1;
      if (index >= 0) this.parentElement.children.splice(index, 1);
      this.parentElement = null;
    },
    // textContent fiel al DOM real: agrega recursivamente.
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
    // Búsquedas fieles al DOM real (id, clase y [data-action]).
    querySelector(selector) {
      const idMatch = /^#([\w-]+)$/.exec(selector);
      if (idMatch) return queryById(this, idMatch[1])[0] ?? null;
      const classMatch = /^\.([\w-]+)$/.exec(selector);
      if (classMatch) return queryByClass(this, classMatch[1])[0] ?? null;
      const attrMatch = /^\[data-action="?([\w-]+)"?\]$/.exec(selector);
      if (attrMatch) return this.children.find((c) => c.getAttribute('data-action') === attrMatch[1]) ?? null;
      return null;
    },
    querySelectorAll(selector) {
      const idMatch = /^#([\w-]+)$/.exec(selector);
      if (idMatch) return queryById(this, idMatch[1]);
      const classMatch = /^\.([\w-]+)$/.exec(selector);
      if (classMatch) return queryByClass(this, classMatch[1]);
      return [];
    },
  };
  element.classList = {
    _owner: element,
    add(...names) { names.forEach((n) => this._owner.classes.add(n)); },
    remove(...names) { names.forEach((n) => this._owner.classes.delete(n)); },
    contains(name) { return this._owner.classes.has(name); },
    toggle(name, force) {
      const shouldHave = force === undefined ? !this._owner.classes.has(name) : force === true;
      if (shouldHave) this._owner.classes.add(name);
      else this._owner.classes.delete(name);
      return shouldHave;
    },
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
    location: {
      href: initialUrl,
      hash: initialUrl.includes('#') ? `#${initialUrl.split('#')[1]}` : '',
      assignCalls: [],
      assign(url) { win.location.assignCalls.push(url); },
    },
    history: { _entries: [initialUrl], _index: 0, state: null },
    addEventListener(name, listener) { (this.listeners[name] ??= []).push(listener); },
    removeEventListener(name, listener) {
      this.listeners[name] = (this.listeners[name] ?? []).filter((l) => l !== listener);
    },
  };
  const resolveUrl = (url) => (url.startsWith('http') ? url : `http://grimorio.test/${url}`);
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
  return win;
}

/** spellClient de integración: contrato consumido por las vistas. */
function createFakeSpellClient() {
  return {
    fetchFeatured: async () => ({ success: true, data: [] }),
    fetchSpells: async () => ({ success: true, data: { items: [], hasMore: false } }),
    fetchSpellBySlug: async () => ({
      success: false,
      error: { code: 'SCROLL_LOST_IN_AETHER', message: 'El pergamino se ha desvanecido.', recoveryAction: 'RETURN_TO_LIBRARY' },
    }),
    fetchClansPreview: async () => ({ success: true, data: [{ id: 'cln_primordial', name: 'Custodios del Fuego Primordial' }] }),
  };
}

/** grimoireClient mínimo: el Tomo Canónico se sirve sin tocar la red real. */
function createFakeGrimoireClient() {
  return {
    fetchSpells: async () => ({
      success: true,
      status: 200,
      data: {
        totalSpells: 1,
        spells: [{
          id: 'spl_canonico',
          slug: 'chispa-de-ignicion',
          name: 'Chispa de Ignición',
          circle: 1,
          elementalAffinity: 'fire',
          castingTime: 'action',
          manaCost: 5,
          verbalFormula: 'Ignis Primordialis',
          description: 'Una chispa ceremonial.',
        }],
      },
    }),
    fetchSpellDetail: async () => ({ success: false, status: 404, error: { code: 'SPELL_NOT_FOUND', message: 'No está.' } }),
  };
}

/** authClient de integración: registra cada llamada y responde sobres controlados. */
function createFakeAuthClient({ sessionUser = null } = {}) {
  const calls = { checkSession: [], bind: [], consecrate: [], dissolve: [], dissolveAll: [] };
  let sessionState = { authenticated: sessionUser !== null, user: sessionUser };
  return {
    calls,
    grantSession(user) { sessionState = { authenticated: true, user }; },
    checkSession: async () => { calls.checkSession.push(1); return { success: true, status: 200, data: sessionState }; },
    bind: async () => ({ success: false, status: 401, error: { code: 'INVALID_CREDENTIALS', message: 'No coinciden.' } }),
    consecrate: async () => ({ success: false, status: 400, error: { code: 'INVALID_REGISTRATION_DATA', message: 'Faltan datos.' } }),
    dissolve: async () => {
      calls.dissolve.push(1);
      sessionState = { authenticated: false, user: null };
      return { success: true, status: 200, data: { dissolved: true } };
    },
    dissolveAll: async () => {
      calls.dissolveAll.push(1);
      sessionState = { authenticated: false, user: null };
      return { success: true, status: 200, data: { dissolved: true } };
    },
  };
}

/** Shell completo del index.html (nodos que el orquestador toca). */
function buildFakeShell(initialUrl) {
  const appRoot = createFakeElement('main');
  appRoot.setAttribute('id', 'app');

  const navRoot = createFakeElement('nav');
  navRoot.setAttribute('id', 'siteNav');
  navRoot.querySelector = (selector) => {
    const match = /^#([\w-]+)$/.exec(selector);
    return match ? queryById(navRoot, match[1])[0] ?? null : null;
  };
  navRoot.querySelectorAll = (selector) => {
    const match = /^#([\w-]+)$/.exec(selector);
    return match ? queryById(navRoot, match[1]) : [];
  };
  const linksList = createFakeElement('ul');
  linksList.setAttribute('id', 'navLinks');
  const toggleButton = createFakeElement('button');
  toggleButton.setAttribute('id', 'navToggle');
  navRoot.appendChild(linksList);
  navRoot.appendChild(toggleButton);

  const badgeRoot = createFakeElement('div');
  badgeRoot.setAttribute('id', 'navSessionSlot');

  const spellDetailDialog = createFakeDialog('spellDetailModal');
  const accessDialog = createFakeDialog('accessModal');
  const loginForm = createFakeElement('form');
  loginForm.setAttribute('id', 'loginForm');
  const registerForm = createFakeElement('form');
  registerForm.setAttribute('id', 'registerForm');
  accessDialog.appendChild(loginForm);
  accessDialog.appendChild(registerForm);

  return {
    appRoot, navRoot, linksList, toggleButton, badgeRoot, spellDetailDialog, accessDialog,
    fakeWindow: createFakeWindow(initialUrl),
    fakeDocument: { createElement: (tag) => createFakeElement(tag), createElementNS: (_ns, tag) => createFakeElement(tag) },
    spellClient: createFakeSpellClient(),
  };
}

/** Monta la aplicación con el shell y los dobles dados. */
function buildApp(shell, { authClient, grimoireClient } = {}) {
  return createGrimoireApp({
    appRoot: shell.appRoot,
    navRoot: shell.navRoot,
    badgeRoot: shell.badgeRoot,
    spellDetailDialog: shell.spellDetailDialog,
    accessDialog: shell.accessDialog,
    spellClient: shell.spellClient,
    grimoireClient: grimoireClient ?? createFakeGrimoireClient(),
    authClient: authClient ?? createFakeAuthClient(),
    windowRef: shell.fakeWindow,
    documentRef: shell.fakeDocument,
  });
}

/** Enlaces del navbar identificados por data-view. */
function findNavLink(shell, viewName) {
  return shell.linksList.children.find((link) => link.getAttribute('data-view') === viewName) ?? null;
}

/** Botón del menú del badge por data-action. */
function findBadgeAction(shell, action) {
  const menuOptions = queryById(shell.badgeRoot, 'userProfileMenu')[0]?.children ?? [];
  return menuOptions.flatMap((li) => li.children).find((b) => b.getAttribute('data-action') === action) ?? null;
}

const SESSION_USER = {
  id: 'usr_visual',
  alias: 'Erudita Visual',
  role: 'editor',
  clanId: 'cln_primordial',
  clanName: 'Custodios del Fuego Primordial',
  lineage: 'primordialFlame', // SPEC-09: linajado — la retención no le alcanza.
};

console.log('== ARNÉS DE REGRESIÓN: repintado de la cabecera al cambiar la sesión ==\n');

// =====================================================================
// FASE A: arranque con vínculo vivo (cookie HttpOnly previa)
// =====================================================================
console.log('FASE A: con vínculo vivo, el enlace reservado navega sin umbral');

const shellA = buildFakeShell('http://grimorio.test/');
const authA = createFakeAuthClient({ sessionUser: SESSION_USER });
const appA = buildApp(shellA, { authClient: authA });
await appA.boot();
await wait(60);

assertCondition(appA.store.getState().isAuthenticated === true, 'checkSession() asienta el vínculo en el store');

const creatorLinkA = findNavLink(shellA, 'creator');
assertCondition(creatorLinkA !== null, 'la cabecera pinta el enlace «Creador de Hechizos»');

creatorLinkA.dispatch('click');
await wait(60);

assertCondition(
  appA.store.getState().currentView === 'creator',
  'el erudito vinculado NAVEGA al Taller de Hechizos (RF-02.3)',
);
assertCondition(byClass(shellA.appRoot, 'spell-creator') !== null, 'la vista del Taller se monta en el punto de anclaje');
assertCondition(shellA.accessDialog.showModalCount === 0, 'NO se despliega «Cruzar el Umbral» a quien ya tiene vínculo');
assertCondition(appA.store.getState().pendingIntent.action === null, 'no se retiene intención alguna que consumir');

// =====================================================================
// FASE B: visitante anónimo (no regresión de la interceptación)
// =====================================================================
console.log('\nFASE B: el visitante anónimo sigue siendo interceptado (RF-02.3)');

const shellB = buildFakeShell('http://grimorio.test/');
const appB = buildApp(shellB);
await appB.boot();
await wait(40);

assertCondition(appB.store.getState().isAuthenticated === false, 'sin vínculo, el store queda en estado anónimo');

findNavLink(shellB, 'creator').dispatch('click');
await wait(40);

assertCondition(shellB.accessDialog.open === true, 'el visitante recibe «Cruzar el Umbral»');
assertCondition(appB.store.getState().pendingIntent.action === 'openCreator', 'la intención openCreator queda retenida (RF-05.2)');
assertCondition(appB.store.getState().currentView !== 'creator', 'el visitante no entra al Taller sin consagrarse');

// =====================================================================
// FASE C: disolver el vínculo vuelve a pintar la cabecera
// =====================================================================
console.log('\nFASE C: al disolver el vínculo la cabecera se re-pinta');

const creatorLinkBeforeDissolve = findNavLink(shellA, 'creator');

const badgeToggle = queryById(shellA.badgeRoot, 'userProfileToggle')[0] ?? null;
assertCondition(badgeToggle !== null, 'con vínculo la cabecera muestra el distintivo de sesión');
badgeToggle.dispatch('click');

const dissolveButton = findBadgeAction(shellA, 'dissolve');
assertCondition(dissolveButton !== null, 'el menú arcano ofrece «Disolver este vínculo»');
dissolveButton.dispatch('click');
await wait(60);

assertCondition(authA.calls.dissolve.length === 1, 'el orquestador llamó a dissolve() (RF-02.4)');
assertCondition(appA.store.getState().isAuthenticated === false, 'el store vuelve al estado anónimo');
assertCondition(queryById(shellA.badgeRoot, 'navCrossThreshold').length === 1, 'el botón «Cruzar el Umbral» retorna a la cabecera');

// La cabecera debe haberse re-pintado: el nodo del enlace es nuevo.
const creatorLinkAfterDissolve = findNavLink(shellA, 'creator');
assertCondition(
  creatorLinkAfterDissolve !== null && creatorLinkAfterDissolve !== creatorLinkBeforeDissolve,
  'la cabecera se re-pinta de verdad al cambiar la sesión (nodo nuevo)',
);

shellA.accessDialog.open = false;
shellA.accessDialog.showModalCount = 0;
creatorLinkAfterDissolve.dispatch('click');
await wait(40);

assertCondition(shellA.accessDialog.open === true, 'sin vínculo, el enlace reservado vuelve a interceptar');
assertCondition(appA.store.getState().pendingIntent.action === 'openCreator', 'la intención openCreator se retiene de nuevo');

// =====================================================================
// FASE D: el repintado no apila listeners (menú móvil + Escape)
// =====================================================================
console.log('\nFASE D: el repintado no apila listeners en el menú móvil');

shellA.toggleButton.dispatch('click');
assertCondition(shellA.toggleButton.getAttribute('aria-expanded') === 'true', 'una pulsación abre el menú móvil (aria-expanded=true)');
shellA.toggleButton.dispatch('click');
assertCondition(shellA.toggleButton.getAttribute('aria-expanded') === 'false', 'la segunda pulsación lo recoge (sin doble manejador)');

shellA.toggleButton.dispatch('click');
assertCondition(shellA.linksList.getAttribute('data-open') === 'true', 'el menú abierto se refleja en data-open');
shellA.navRoot.dispatch('keydown', { key: 'Escape' });
assertCondition(shellA.toggleButton.getAttribute('aria-expanded') === 'false', 'Escape recoge el menú (RNF-03)');
assertCondition(shellA.linksList.getAttribute('data-open') === null, 'el estado del menú se limpia por completo');

// =====================================================================
// FASE E: el enlace público del Simulador queda intacto (SPEC-05)
// =====================================================================
console.log('\nFASE E: el enlace del Simulador sigue siendo público en ambos estados');

const shellE = buildFakeShell('http://grimorio.test/');
const authE = createFakeAuthClient({ sessionUser: SESSION_USER });
const appE = buildApp(shellE, { authClient: authE });
await appE.boot();
await wait(50);

assertCondition(appE.store.getState().isAuthenticated === true, 'la segunda aplicación arranca con vínculo vivo');
assertCondition(findNavLink(shellE, 'simulator')?.getAttribute('data-reserved') === null, 'el enlace del Simulador no está marcado como reservado');

findNavLink(shellE, 'simulator').dispatch('click');
await wait(60);

assertCondition(appE.store.getState().currentView === 'simulator', 'el erudito vinculado navega al Simulador');
assertCondition(shellE.accessDialog.showModalCount === 0, 'el enlace público jamás despliega el umbral');

// =====================================================================
// Resumen final.
// =====================================================================
console.log('\n== RESUMEN ==');
console.log(`Asertos superados: ${assertsPassed}`);
console.log(`Asertos fallidos:  ${assertsFailed}`);

if (assertsFailed > 0) {
  console.log('\nRESULTADO: DENEGADO — Revisa los asertos [FALLA].');
  process.exit(1);
}
console.log('\nRESULTADO: EXITO — La cabecera refleja el vínculo vivo en todo momento.');
