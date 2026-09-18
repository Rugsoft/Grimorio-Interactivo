/**
 * test_main_auth_integration.mjs — Arnés TDD del enchufe SPEC-03 en main.js.
 *
 * Estrategia TDD: este script se escribe ANTES que la integración.
 * Verifica, sobre el orquestador central (createGrimoireApp):
 *   [A] checkSession() al arrancar: si hay vínculo activo, el store y la
 *       navbar reflejan la sesión (badge en cabecera) sin recargar.
 *   [B] «Renovar Vínculo» (login): el orquestador llama a bind() con las
 *       credenciales del shell; con éxito asienta la sesión, consume la
 *       intención pendiente y NAVEGA a la vista retenida (p. ej. creator);
 *       con fallo muestra el mensaje del backend y el diálogo permanece.
 *   [C] «Consagrarse» (register): llama a consecrate() con alias, correo
 *       y frase (SPEC-09: sin linaje en el registro); éxito → mismo flujo.
 *   [D] «Cruzar el Umbral» (visitante): abre el modal y retiene la intención
 *       en el store (comportamiento ya existente, no debe romperse).
 *   [E] Badge de sesión: setUser/clearUser consumen data.user; el menú
 *       «Disolver este vínculo» llama a dissolve() y devuelve la cabecera
 *       al estado anónimo.
 *
 * Constitución:
 *   - Artículo I: arnés nativo Node con DOM simulado (patrón consolidado).
 *   - Artículo V: identificadores en inglés camelCase; leyendas castellanas.
 *
 * Uso: node scratch/test_main_auth_integration.mjs
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
    parentElement: null,
    focusCount: 0,
    open: false,
    showModalCount: 0,
    disabled: false,
    href: '',

    _textContent: '',
    _value: '',
    _checked: false,

    setAttribute(name, value) { this.attributes[name] = String(value); if (name === 'class') { this.classes = new Set(String(value).split(/\s+/).filter(Boolean)); } },
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
    // La ceremonia del juramento (SPEC-09) se monta con replaceChildren:
    // sin este método la llamada opcional se salta y la vista no aparece.
    replaceChildren(...newChildren) {
      for (const child of this.children.splice(0)) child.parentElement = null;
      for (const child of newChildren) this.appendChild(child);
    },
    // Bus de eventos del plan §4 (SPEC-09): la ceremonia emite sobre el
    // propio punto de montaje; el elemento fingido despacha a sus oyentes.
    dispatchEvent(event) {
      for (const listener of this.listeners?.[event?.type] ?? []) listener(event);
      return true;
    },
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
    removeEventListener(name, listener) { this.listeners[name] = (this.listeners[name] ?? []).filter((l) => l !== listener); },
    dispatchWindow(name, eventObject = {}) {
      for (const listener of this.listeners[name] ?? []) listener({ type: name, state: this.history.state, ...eventObject });
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
  win.history.back = function back() {
    if (this._index === 0) return;
    this._index--;
    this.state = this._index === 0 ? null : this.state;
    win.location.href = this._entries[this._index];
    win.location.hash = win.location.href.includes('#') ? `#${win.location.href.split('#')[1]}` : '';
    for (const listener of win.listeners.popstate ?? []) listener({ type: 'popstate', state: this.state });
  };
  return win;
}

/** spellClient de integración: contrato consumido por las vistas. */
function createFakeSpellClient() {
  return {
    fetchFeatured: async () => ({ success: true, data: [] }),
    fetchSpells: async () => ({ success: true, data: { items: [], hasMore: false } }),
    fetchSpellBySlug: async () => ({ success: false, error: { code: 'SCROLL_LOST_IN_AETHER', message: 'El pergamino que buscas se ha desvanecido en el éther.', recoveryAction: 'RETURN_TO_LIBRARY' } }),
    // El selector obligatorio de linaje (RF-01.1) se puebla con el catálogo.
    fetchClansPreview: async () => ({ success: true, data: [{ id: 'cln_primordial', name: 'Custodios del Fuego Primordial' }] }),
  };
}

/**
 * authClient de integración: registra cada llamada y responde sobres
 * controlados según el escenario configurado por cada fase.
 */
function createFakeAuthClient() {
  const calls = { checkSession: [], bind: [], consecrate: [], dissolve: [], dissolveAll: [] };
  let sessionState = { authenticated: false, user: null };
  let bindResult = { success: false, status: 401, error: { code: 'INVALID_CREDENTIALS', message: 'El vínculo no pudo renovarse: identidad o frase de paso no coinciden.' } };
  let consecrateResult = { success: false, status: 400, error: { code: 'INVALID_REGISTRATION_DATA', message: 'La consagración exige alias, correo y frase de paso.' } };
  return {
    calls,
    grantSession(user) { sessionState = { authenticated: true, user }; },
    setBindResult(result) { bindResult = result; },
    setConsecrateResult(result) { consecrateResult = result; },
    checkSession: async () => { calls.checkSession.push(1); return { success: true, status: 200, data: sessionState }; },
    bind: async (identity, passphrase) => {
      calls.bind.push({ identity, passphrase });
      if (bindResult.success) { sessionState = { authenticated: true, user: bindResult.data.user }; }
      return bindResult;
    },
    consecrate: async (data) => {
      calls.consecrate.push(data);
      if (consecrateResult.success) { sessionState = { authenticated: true, user: consecrateResult.data.user }; }
      return consecrateResult;
    },
    dissolve: async () => { calls.dissolve.push(1); sessionState = { authenticated: false, user: null }; return { success: true, status: 200, data: { dissolved: true } }; },
    dissolveAll: async () => { calls.dissolveAll.push(1); sessionState = { authenticated: false, user: null }; return { success: true, status: 200, data: { dissolved: true } }; },
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

  // Contenedor del distintivo de sesión (badgeRoot): el shell real lo porta
  // como <div id="navSessionSlot"> tras la lista de enlaces.
  const badgeRoot = createFakeElement('div');
  badgeRoot.setAttribute('id', 'navSessionSlot');

  const spellDetailDialog = createFakeDialog('spellDetailModal');
  const accessDialog = createFakeDialog('accessModal');
  const loginForm = createFakeElement('form');
  loginForm.setAttribute('id', 'loginForm');
  const loginName = createFakeElement('input');
  loginName.setAttribute('name', 'loginName');
  const loginPassword = createFakeElement('input');
  loginPassword.setAttribute('name', 'loginPassword');
  loginForm.appendChild(loginName);
  loginForm.appendChild(loginPassword);
  const registerForm = createFakeElement('form');
  registerForm.setAttribute('id', 'registerForm');
  const registerName = createFakeElement('input');
  registerName.setAttribute('name', 'registerName');
  const registerPassword = createFakeElement('input');
  registerPassword.setAttribute('name', 'registerPassword');
  const registerEmail = createFakeElement('input');
  registerEmail.setAttribute('name', 'registerEmail');
  registerForm.appendChild(registerName);
  registerForm.appendChild(registerPassword);
  registerForm.appendChild(registerEmail);
  accessDialog.appendChild(loginForm);
  accessDialog.appendChild(registerForm);

  const fakeWindow = createFakeWindow(initialUrl);
  // createElementNS: el sello heráldico del badge (Tarea 3.3) lo exige.
  const fakeDocument = {
    createElement: (tag) => createFakeElement(tag),
    createElementNS: (_namespace, tag) => createFakeElement(tag),
  };

  return {
    appRoot, navRoot, badgeRoot, spellDetailDialog, accessDialog,
    loginForm, loginName, loginPassword, registerForm, registerName, registerPassword, registerEmail,
    fakeWindow, fakeDocument, spellClient: createFakeSpellClient(),
  };
}

/** Enlaces del navbar identificados por data-view. */
function findNavLink(shell, viewName) {
  const linksList = queryById(shell.navRoot, 'navLinks')[0];
  return (linksList?.children ?? []).find((link) => link.getAttribute('data-view') === viewName) ?? null;
}

const SESSION_USER = { id: 'usr_visual', alias: 'Erudita Visual', role: 'editor', clanId: 'cln_primordial', clanName: 'Custodios del Fuego Primordial', lineage: 'primordialFlame' };

console.log('== ARNES TDD: integración SPEC-03 en main.js (authClient, intención, badge) ==\n');

// --- FASE A: arranque con sesión viva (cookie HttpOnly previa) ---
console.log('FASE A: checkSession() al arrancar refleja el vínculo activo');

const shellA = buildFakeShell('http://grimorio.test/');
const authA = createFakeAuthClient();
authA.grantSession(SESSION_USER);
const appA = createGrimoireApp({
  appRoot: shellA.appRoot,
  navRoot: shellA.navRoot,
  badgeRoot: shellA.badgeRoot,
  spellDetailDialog: shellA.spellDetailDialog,
  accessDialog: shellA.accessDialog,
  spellClient: shellA.spellClient,
  authClient: authA,
  windowRef: shellA.fakeWindow,
  documentRef: shellA.fakeDocument,
});
await appA.boot();
await wait(50);

assertCondition(authA.calls.checkSession.length === 1, 'El arranque verificó la sesión vía checkSession() (plan 4.2)');
assertCondition(appA.store.getState().isAuthenticated === true, 'El store asienta la sesión (isAuthenticated=true)');
assertCondition(appA.store.getState().userRole === 'editor', 'El store registra el rol técnico del vinculado (userRole)');
assertCondition(queryById(shellA.badgeRoot, 'userProfileBadge').length === 1, 'La cabecera muestra el distintivo de sesión (badge)');
assertCondition(queryById(shellA.badgeRoot, 'userProfileBadge')[0]?.textContent.includes('Erudita Visual'), 'El badge porta el alias del vinculado');
assertCondition(queryById(shellA.badgeRoot, 'navCrossThreshold').length === 0, 'El botón «Cruzar el Umbral» se retira al haber vínculo');

// --- FASE B: login con intención retenida navega al Taller ---
console.log('\nFASE B: «Renovar Vínculo» autentica y restablece la intención pendiente');

const shellB = buildFakeShell('http://grimorio.test/');
const authB = createFakeAuthClient();
authB.setBindResult({ success: true, status: 200, data: { user: SESSION_USER } });
const appB = createGrimoireApp({
  appRoot: shellB.appRoot,
  navRoot: shellB.navRoot,
  badgeRoot: shellB.badgeRoot,
  spellDetailDialog: shellB.spellDetailDialog,
  accessDialog: shellB.accessDialog,
  spellClient: shellB.spellClient,
  authClient: authB,
  windowRef: shellB.fakeWindow,
  documentRef: shellB.fakeDocument,
});
await appB.boot();
await wait(30);

// Visitante pulsa «Creador de Hechizos»: interceptación + intención retenida.
findNavLink(shellB, 'creator').dispatch('click');
await wait(20);
assertCondition(shellB.accessDialog.open === true, 'Visitante en «Creador» despliega «Cruzar el Umbral» (RF-02.3)');
assertCondition(appB.store.getState().pendingIntent.action === 'openCreator', 'La intención openCreator queda retenida en el store (RF-05.2)');

// Envío del formulario de login del shell. El fake carece de
// querySelector: las credenciales viajan en event.fields (contrato del
// componente de acceso con los arneses).
shellB.loginForm.dispatch('submit', { fields: { loginName: 'Erudita Visual', loginPassword: 'Passphrase-Arcana-2026!' } });
await wait(50);

assertCondition(
  authB.calls.bind.length === 1 && authB.calls.bind[0].identity === 'Erudita Visual' && authB.calls.bind[0].passphrase === 'Passphrase-Arcana-2026!',
  'El orquestador llamó a bind() con las credenciales del shell (RF-02)',
);
assertCondition(shellB.accessDialog.open === false, 'El diálogo se cerró tras la autenticación (flujo «Cruzar el Umbral»)');
assertCondition(appB.store.getState().isAuthenticated === true, 'La sesión quedó asentada en el store tras bind()');
assertCondition(appB.store.getState().pendingIntent.action === null, 'La intención pendiente se consumió (RF-05.3)');
assertCondition(byClass(shellB.appRoot, 'spell-creator') !== null, 'La intención retenida navegó al Taller de Hechizos (restauración)');
assertCondition(queryById(shellB.badgeRoot, 'userProfileBadge').length === 1, 'El badge reemplazó al botón del umbral tras autenticar');

// --- FASE C: login fallido muestra el mensaje y conserva el diálogo ---
console.log('\nFASE C: credenciales erróneas → mensaje del backend, diálogo persistente');

const shellC = buildFakeShell('http://grimorio.test/');
const authC = createFakeAuthClient();
const appC = createGrimoireApp({
  appRoot: shellC.appRoot,
  navRoot: shellC.navRoot,
  badgeRoot: shellC.badgeRoot,
  spellDetailDialog: shellC.spellDetailDialog,
  accessDialog: shellC.accessDialog,
  spellClient: shellC.spellClient,
  authClient: authC,
  windowRef: shellC.fakeWindow,
  documentRef: shellC.fakeDocument,
});
await appC.boot();
await wait(30);

findNavLink(shellC, 'creator').dispatch('click');
await wait(20);
shellC.loginForm.dispatch('submit', { fields: { loginName: 'Impostor', loginPassword: 'frase-equivocada' } });
await wait(50);

assertCondition(shellC.accessDialog.open === true, 'Con credenciales erróneas el diálogo permanece abierto');
assertCondition(appC.store.getState().isAuthenticated === false, 'Sin sesión fantasma tras el fallo (401)');
assertCondition(
  typeof shellC.accessModalErrorMessage === 'function' ? true : queryById(shellC.accessDialog, 'accessModalError')[0]?.textContent.includes('no coinciden') === true,
  'El mensaje de error del backend se exhibe en el diálogo (RF-03.1)',
);

// --- FASE D: consagración con clan obligatorio ---
console.log('\nFASE D: «Consagrarse» llama a consecrate() con alias, correo, frase y linaje');

const shellD = buildFakeShell('http://grimorio.test/');
const authD = createFakeAuthClient();
// SPEC-09: la cuenta consagrada nace PEREGRINA (lineage: null, RF-01.2).
authD.setConsecrateResult({ success: true, status: 201, data: { user: { ...SESSION_USER, alias: 'Iniciada Nova', role: 'editor', lineage: null } } });
const appD = createGrimoireApp({
  appRoot: shellD.appRoot,
  navRoot: shellD.navRoot,
  badgeRoot: shellD.badgeRoot,
  spellDetailDialog: shellD.spellDetailDialog,
  accessDialog: shellD.accessDialog,
  spellClient: shellD.spellClient,
  authClient: authD,
  windowRef: shellD.fakeWindow,
  documentRef: shellD.fakeDocument,
});
await appD.boot();
await wait(30);

findNavLink(shellD, 'creator').dispatch('click');
await wait(20);

// SPEC-09 (Tarea 5.1): el registro ya no puebla selector de linaje alguno.
// La iniciada envía el formulario con el contrato del Endpoint 1 enmendado
// (alias, correo y frase — el linaje se jura en la ceremonia del umbral).
await wait(30);
const clanSelectD = queryById(shellD.accessDialog, 'clanSelect')[0] ?? null;
assertCondition(clanSelectD === null, 'Ningún selector de linaje existe en el diálogo (RF-01.1 enmendado, SPEC-09)');

shellD.registerForm.dispatch('submit', { fields: { registerName: 'Iniciada Nova', registerPassword: 'Passphrase-Arcana-2026!', registerEmail: 'nova@grimorio.test' } });
await wait(50);

assertCondition(
  authD.calls.consecrate.length === 1
  && authD.calls.consecrate[0].alias === 'Iniciada Nova'
  && authD.calls.consecrate[0].email !== ''
  && !('clanId' in authD.calls.consecrate[0]),
  'El orquestador llamó a consecrate() con alias y correo, sin clanId (RF-01.1 enmendado)',
);
assertCondition(appD.store.getState().isAuthenticated === true, 'La sesión quedó asentada tras la consagración');
assertCondition(byClass(shellD.appRoot, 'lineage-oath') !== null, 'La consagración aterriza en la ceremonia del juramento (la cuenta nace peregrina, SPEC-09)');

// --- FASE E: disolver el vínculo desde el badge ---
console.log('\nFASE E: el menú del badge disuelve el vínculo y restituye el umbral');

const badgeToggleE = queryById(shellB.badgeRoot, 'userProfileToggle')[0];
badgeToggleE.dispatch('click');
const menuOptionsE = queryById(shellB.badgeRoot, 'userProfileMenu')[0]?.children ?? [];
const dissolveButtonE = (menuOptionsE.flatMap((li) => li.children)).find((b) => b.getAttribute('data-action') === 'dissolve');
dissolveButtonE.dispatch('click');
await wait(50);

assertCondition(authB.calls.dissolve.length === 1, 'La opción «Disolver este vínculo» llamó a dissolve() (RF-02.4)');
assertCondition(appB.store.getState().isAuthenticated === false, 'El store volvió al estado anónimo tras disolver');
assertCondition(queryById(shellB.badgeRoot, 'navCrossThreshold').length === 1, 'El botón «Cruzar el Umbral» volvió a la cabecera');

// =====================================================================
// Resumen final.
// =====================================================================
console.log(`\n== RESUMEN ==`);
console.log(`Asertos superados: ${assertsPassed}`);
console.log(`Asertos fallidos:  ${assertsFailed}`);

if (assertsFailed > 0) {
  console.log('\nRESULTADO: FALLO');
  process.exit(1);
}
console.log('\nRESULTADO: EXITO — El enchufe SPEC-03 de main.js cumple sus criterios.');
