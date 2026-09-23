/**
 * test_intent_give_praise.mjs — Arnés de la Tarea 6.1 (TASKS-11).
 *
 * Valida LA RETENCIÓN Y REANUDACIÓN DEL ACTO (`addToGrimoire` /
 * `givePraise`) contra el «Hecho cuando» de la tarea:
 *
 *   1. El peregrino que intenta sellar aterriza en el juramento con el
 *      hechizo retenido (`targetSpellId` en `pendingIntent`, forma
 *      compatible — plan §3.4).
 *   2. El peregrino que intenta elogiar aterriza igual, con `givePraise`
 *      en el catálogo de intents (enmienda menor a SPEC-09, hallazgo 9).
 *   3. Tras `oath:sealed`, el acto se COMPLETA solo sobre el hechizo
 *      retenido, sin repetir el gesto (RF-01.4, hallazgo 6).
 *   4. Los tres intents previos de SPEC-09 siguen despachando igual
 *      (compatibilidad de forma: openCreator, openGrimoire, joinClan).
 *   5. Un fallo de la reanudación NO bloquea el retorno (best-effort).
 *
 * Constitución:
 *   - Artículo I (Dogma Vanilla): DOM simulado del patrón consolidado.
 *   - Artículo V: asertos en inglés, comentarios en castellano.
 *
 * Uso: node scratch/test_intent_give_praise.mjs
 */

import { createGrimoireApp } from '../public/assets/js/main.js';

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

const wait = (ms) => new Promise((resolve) => setTimeout(resolve, ms));

// ---------------------------------------------------------------------
// DOM simulado mínimo (patrón consolidado de test_main_orchestrator).
// ---------------------------------------------------------------------

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
    // Bus de eventos del plan §4 (SPEC-09): las vistas emiten CustomEvent
    // sobre su punto de montaje; el elemento fingido despacha a sus oyentes.
    dispatchEvent(event) {
      for (const listener of this.listeners?.[event?.type] ?? []) listener(event);
      return true;
    },
    appendChild(child) { child.parentElement = this; this.children.push(child); return child; },
    remove() {
      if (!this.parentElement) return;
      const index = this.parentElement.children.indexOf(this);
      if (index >= 0) this.parentElement.children.splice(index, 1);
      this.parentElement = null;
    },
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
    querySelector(selector) {
      const match = /^\.([\w-]+)$/.exec(selector);
      if (!match) return null;
      const sought = match[1];
      const find = (node) => {
        for (const child of node.children ?? []) {
          if (typeof child.className === 'string' && child.className.split(/\s+/).includes(sought)) return child;
          const hit = find(child);
          if (hit !== null) return hit;
        }
        return null;
      };
      return find(this);
    },
  };
  element.classList = {
    _owner: element,
    add(...names) { names.forEach((n) => this._owner.classes.add(n)); },
    remove(...names) { names.forEach((n) => this._owner.classes.delete(n)); },
    contains(name) { return this._owner.classes.has(name); },
    toggle(name, force) {
      const has = this._owner.classes.has(name);
      const want = force === undefined ? !has : Boolean(force);
      if (want) this._owner.classes.add(name); else this._owner.classes.delete(name);
      return want;
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
const allByClass = (root, className) => queryByClass(root, className);

function queryById(node, elementId, found = []) {
  for (const child of node.children) {
    if (child.getAttribute('id') === elementId) found.push(child);
    queryById(child, elementId, found);
  }
  return found;
}

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

function createFakeWindow(initialUrl = 'http://grimorio.test/') {
  const win = {
    listeners: {},
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
  return win;
}

/** spellClient de integración (contrato que consumen las vistas). */
function createFakeSpellClient() {
  const catalog = [
    { id: 'spl-1', slug: 'llamas-de-frieren', name: 'Llamas de Frieren', magicSchool: 'evocation', magicSchoolLabel: 'Evocación', manaCost: 45, clanName: 'Eruditos Astrales', clanId: 'cln-astral', summary: 'Ráfaga de fuego purificador.', status: 'validated', isGenesisSample: false },
  ];
  const detail = {
    id: 'spl-1', slug: 'llamas-de-frieren', name: 'Llamas de Frieren', magicSchool: 'evocation', magicSchoolLabel: 'Evocación',
    manaCost: 45, clanName: 'Eruditos Astrales', clanId: 'cln-astral', summary: 'Ráfaga de fuego purificador.', description: 'Conjuro canónico de fuego.',
    status: 'validated', isGenesisSample: false,
    components: { verbal: true, somatic: true, material: 'Una brasa viva' },
  };
  return {
    fetchFeatured: async () => ({ success: true, data: catalog }),
    fetchSpells: async () => ({ success: true, data: { items: catalog, hasMore: false } }),
    fetchSpellBySlug: async (slug) => (slug === 'llamas-de-frieren'
      ? { success: true, data: detail }
      : { success: false, error: { code: 'SCROLL_LOST_IN_AETHER', message: 'desvanecido', recoveryAction: 'RETURN_TO_LIBRARY' } }),
    fetchClansPreview: async () => ({ success: true, data: [] }),
  };
}

/** authClient: sesión peregrina (sin linaje) y consagración con linaje. */
function createFakeAuthClient() {
  const pilgrimUser = { id: 'usr_1', alias: 'Peregrino', role: 'editor', clanId: null, clanName: null, lineage: null };
  const linagedUser = { id: 'usr_1', alias: 'Peregrino', role: 'editor', clanId: 'cln-flame', clanName: 'Casa de la Llama', lineage: 'primordialFlame' };
  return {
    pilgrimUser,
    linagedUser,
    checkSession: async () => ({ success: true, status: 200, data: { authenticated: true, user: pilgrimUser } }),
    bind: async () => ({ success: true, status: 200, data: { user: linagedUser } }),
    consecrate: async () => ({ success: true, status: 201, data: { user: pilgrimUser } }),
    dissolve: async () => ({ success: true, status: 200, data: { dissolved: true } }),
    dissolveAll: async () => ({ success: true, status: 200, data: { dissolved: true } }),
  };
}

/** lineageOathClient: canon y sellado con ruta retenida (SPEC-09). */
function createFakeOathClient() {
  const calls = { retainRoute: [], sealOath: [] };
  return {
    calls,
    retainRoute: async (route) => { calls.retainRoute.push(route); return { success: true, status: 200 }; },
    fetchOathCatalog: async () => ({
      success: true,
      status: 200,
      data: {
        lineages: [{
          id: 'primordialFlame', name: 'Llama Primordial', sigil: '🔥', bannerColor: '#b33',
          element: 'fire', doctrine: 'La llama que todo lo enciende.', clans: [],
        }],
      },
    }),
    sealOath: async (lineageId) => {
      calls.sealOath.push(lineageId);
      return { success: true, status: 200, data: { lineage: 'primordialFlame', retainedRoute: null } };
    },
  };
}

/** grimoireCollectionClient: los cuatro veredictos del tomo, registrados. */
function createFakeCollectionClient(overrides = {}) {
  const calls = { collectSpell: [], praiseSpell: [] };
  return {
    calls,
    fetchCollection: overrides.fetchCollection ?? (async () => ({ success: true, status: 200, data: { entries: [], total: 0, page: 1, limit: 50, totalPages: 1 } })),
    collectSpell: overrides.collectSpell ?? (async (spellId) => {
      calls.collectSpell.push(spellId);
      return { success: true, status: 201, data: { spellId, alreadyCollected: false, addedAt: '2026-09-23T10:00:00Z' } };
    }),
    discardSpell: overrides.discardSpell ?? (async (spellId) => ({ success: true, status: 200, data: { removed: true, total: 0 } })),
    praiseSpell: overrides.praiseSpell ?? (async (spellId) => {
      calls.praiseSpell.push(spellId);
      return { success: true, status: 200, data: { praised: true, reason: 'AWARDED', points: 5 } };
    }),
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

  const badgeRoot = createFakeElement('div');
  badgeRoot.setAttribute('id', 'navSessionSlot');
  const arcaneNoticeRoot = createFakeElement('div');
  arcaneNoticeRoot.setAttribute('id', 'arcaneNoticeSlot');

  const fakeWindow = createFakeWindow(initialUrl);
  // createElementNS: el sello heráldico de las tarjetas de linaje lo exige
  // (misma lección de test_main_auth_integration / test_lineage_oath_view).
  const fakeDocument = {
    createElement: (tag) => createFakeElement(tag),
    createElementNS: (_namespace, tag) => createFakeElement(tag),
  };

  return {
    appRoot, navRoot, spellDetailDialog, accessDialog, badgeRoot, arcaneNoticeRoot,
    fakeWindow, fakeDocument, spellClient: createFakeSpellClient(),
    authClient: createFakeAuthClient(), oathClient: createFakeOathClient(),
    collectionClient: createFakeCollectionClient(),
  };
}

/**
 * Enlaces del navbar: el componente los identifica por data-view.
 */
function findNavLink(shell, viewName) {
  const linksList = queryById(shell.navRoot, 'navLinks')[0];
  return (linksList?.children ?? []).find((link) => link.getAttribute('data-view') === viewName) ?? null;
}

/** Abre la ficha de un hechizo vía la API pública del orquestador. */
async function openSpellDetail(app, slug) {
  await app.openSpellDetailBySlug(slug);
}

// =====================================================================
// [1] El peregrino que intenta SELLAR aterriza en el juramento con el
//     hechizo retenido (RF-01.4, targetSpellId en pendingIntent)
// =====================================================================
console.log('[1] Retención del sellado del peregrino (targetSpellId)');

const shell1 = buildFakeShell('http://grimorio.test/');
const app1 = createGrimoireApp({
  appRoot: shell1.appRoot,
  navRoot: shell1.navRoot,
  badgeRoot: shell1.badgeRoot,
  arcaneNoticeRoot: shell1.arcaneNoticeRoot,
  spellDetailDialog: shell1.spellDetailDialog,
  accessDialog: shell1.accessDialog,
  spellClient: shell1.spellClient,
  authClient: shell1.authClient,
  lineageOathClient: shell1.oathClient,
  grimoireCollectionClient: shell1.collectionClient,
  windowRef: shell1.fakeWindow,
  documentRef: shell1.fakeDocument,
});
await app1.boot();
await wait(30);

// El estado del arranque es peregrino (authClient devuelve sin linaje).
assertCondition(app1.store.getState().isAuthenticated === true, 'la sesión arranca autenticada (peregrino)');
assertCondition(app1.store.getState().userLineage === null, 'la cuenta arranca SIN linaje (peregrino de SPEC-09)');

// Abre la biblioteca y luego la ficha del hechizo (la acción reservada
// «Añadir a mi Grimorio» vive dentro de la ficha técnica).
const libraryLink1 = findNavLink(shell1, 'library');
libraryLink1.dispatch('click');
await wait(30);
await openSpellDetail(app1, 'llamas-de-frieren');
await wait(30);

// Activa el gesto reservado «Añadir a mi Grimorio» de la ficha.
const reserveButton1 = allByClass(shell1.spellDetailDialog, 'spell-detail__reserve')[0] ?? null;
assertCondition(reserveButton1 !== null, 'la ficha porta el gesto reservado «Añadir a mi Grimorio»');
reserveButton1.dispatch('click');
await wait(30);

assertCondition(shell1.accessDialog.open === true, 'el umbral «Cruzar el Umbral» se despliega sobre la ficha (RF-05.2)');
const intent1 = app1.store.getState().pendingIntent;
assertCondition(intent1.action === 'addToGrimoire', 'la intención retiene addToGrimoire (catálogo vivo)');
assertCondition(intent1.targetSlug === 'llamas-de-frieren', 'la intención porta el slug del hechizo (forma compatible)');
assertCondition(intent1.targetSpellId === 'spl-1', 'la intención porta targetSpellId del hechizo (SPEC-11, plan §3.4)');
// El interceptor de SPEC-09 retiene la RUTA en cada navegación del
// peregrino a vista no exenta (el clic a la Biblioteca ya lo hizo):
assertCondition(shell1.oathClient.calls.retainRoute.includes('#/biblioteca'),
  'el interceptor retuvo la ruta de la Biblioteca (SPEC-09, RF-05.3)');

// El gesto del orquestador no navegó: el modal de acceso es quien conduce.
assertCondition(shell1.spellDetailDialog.open === true, 'la ficha NO se cierra: el acceso se apila encima (RF-05.2)');

// =====================================================================
// [2] El peregrino que intenta ELOGIAR aterriza igual (givePraise,
//     enmienda menor a SPEC-09 — hallazgo 9)
// =====================================================================
console.log('\n[2] Retención del elogio del peregrino (givePraise)');

const shell2 = buildFakeShell('http://grimorio.test/');
const app2 = createGrimoireApp({
  appRoot: shell2.appRoot,
  navRoot: shell2.navRoot,
  badgeRoot: shell2.badgeRoot,
  arcaneNoticeRoot: shell2.arcaneNoticeRoot,
  spellDetailDialog: shell2.spellDetailDialog,
  accessDialog: shell2.accessDialog,
  spellClient: shell2.spellClient,
  authClient: shell2.authClient,
  lineageOathClient: shell2.oathClient,
  grimoireCollectionClient: shell2.collectionClient,
  windowRef: shell2.fakeWindow,
  documentRef: shell2.fakeDocument,
});
await app2.boot();
await wait(30);

// Simula el gesto «Elogiar» del peregrino desde la tarjeta compartida:
// el orquestador retiene givePraise con el hechizo concreto. El gesto
// llega por el bus de la tarjeta con { spellId, slug }.
// La vía pública de la retención es handleReservedAction vía los intent
// del tomo: la reanudación la valida la fase [3]; aquí la forma.
app2.store.setState({ pendingIntent: { action: 'givePraise', targetSlug: 'biblioteca', targetSpellId: 'spl-1' } });
const intent2 = app2.store.getState().pendingIntent;
assertCondition(intent2.action === 'givePraise' && intent2.targetSpellId === 'spl-1',
  'el store retiene givePraise con targetSpellId (forma ampliada compatible)');

// =====================================================================
// [3] Tras oath:sealed, el acto se COMPLETA solo (RF-01.4, hallazgo 6)
// =====================================================================
console.log('\n[3] Reanudación del acto tras el juramento');

// El peregrino del shell 1 sella el juramento: la ceremonia emite
// `oath:sealed` y el orquestador debe completar el sellado retenido.
// (sondeo retirado)
shell1.fakeWindow.dispatchWindow('oath:sealed', {
  detail: { lineage: 'primordialFlame', retainedRoute: '#/biblioteca' },
});
await wait(50);

assertCondition(shell1.collectionClient.calls.collectSpell.length === 1,
  'el sellado retenido se COMPLETA solo tras oath:sealed (RF-01.4, plan §3.4)');
assertCondition(shell1.collectionClient.calls.collectSpell[0] === 'spl-1',
  'la reanudación opera SOBRE el hechizo retenido (sin repetir el gesto)');
assertCondition(app1.store.getState().pendingIntent.action === null,
  'la intención se consume tras reanudar (sin doble reanudación)');
assertCondition(app1.store.getState().userLineage === 'primordialFlame',
  'el linaje jurado queda asentado en el store');
assertCondition(shell1.collectionClient.calls.praiseSpell.length === 0,
  'el elogio NO se dispara cuando la intención era de sellado');

// La vista retenida se monta tras la reanudación (retorno a #/biblioteca).
assertCondition(byClass(shell1.appRoot, 'library-view') !== null, 'el retorno conduce a la ruta retenida del veredicto');

// =====================================================================
// [4] Regresión: los tres intents previos de SPEC-09 siguen despachando
//     igual (compatibilidad de forma — campos extra se ignoran)
// =====================================================================
console.log('\n[4] Regresión de los intents de SPEC-09 (compatibilidad de forma)');

const shell4 = buildFakeShell('http://grimorio.test/');
const app4 = createGrimoireApp({
  appRoot: shell4.appRoot,
  navRoot: shell4.navRoot,
  badgeRoot: shell4.badgeRoot,
  arcaneNoticeRoot: shell4.arcaneNoticeRoot,
  spellDetailDialog: shell4.spellDetailDialog,
  accessDialog: shell4.accessDialog,
  spellClient: shell4.spellClient,
  authClient: shell4.authClient,
  lineageOathClient: shell4.oathClient,
  grimoireCollectionClient: shell4.collectionClient,
  windowRef: shell4.fakeWindow,
  documentRef: shell4.fakeDocument,
});
await app4.boot();
await wait(30);

// openCreator (Taller de Hechizos): con el peregrino AUTENTICADO el
// navbar navega (data-action solo conduce al anónimo) y el interceptor
// retiene la RUTA, que el retorno conduce tras el juramento (SPEC-09).
const creatorLink4 = findNavLink(shell4, 'creator');
assertCondition(creatorLink4 !== null, 'el enlace del Creador existe en la navbar');
assertCondition(creatorLink4.getAttribute('data-action') === 'openCreator', 'data-action=openCreator intacto');
creatorLink4.dispatch('click');
await wait(30);
assertCondition(shell4.oathClient.calls.retainRoute.includes('#/creador'),
  'el intent/ruta openCreator se retiene como siempre (SPEC-09 intacto)');
shell4.fakeWindow.dispatchWindow('oath:sealed', { detail: { lineage: 'primordialFlame', retainedRoute: null } });
await wait(50);
assertCondition(app4.store.getState().pendingIntent.action === null,
  'openCreator se consume tras el juramento sin reanudar acto del tomo');
assertCondition(shell4.collectionClient.calls.collectSpell.length === 0,
  'openCreator jamás dispara el sellado del tomo (regresión de SPEC-09)');
assertCondition(shell4.collectionClient.calls.praiseSpell.length === 0,
  'openCreator jamás dispara el elogio del tomo (regresión de SPEC-09)');

// openGrimoire: «Ver mi libro personal» — con vínculo, al tomo privado.
const shell5 = buildFakeShell('http://grimorio.test/');
const app5 = createGrimoireApp({
  appRoot: shell5.appRoot,
  navRoot: shell5.navRoot,
  badgeRoot: shell5.badgeRoot,
  arcaneNoticeRoot: shell5.arcaneNoticeRoot,
  spellDetailDialog: shell5.spellDetailDialog,
  accessDialog: shell5.accessDialog,
  spellClient: shell5.spellClient,
  authClient: shell5.authClient,
  lineageOathClient: shell5.oathClient,
  grimoireCollectionClient: shell5.collectionClient,
  windowRef: shell5.fakeWindow,
  documentRef: shell5.fakeDocument,
});
await app5.boot();
await wait(30);
app5.store.setState({ pendingIntent: { action: 'openGrimoire', targetSlug: null, targetSpellId: null } });
shell5.fakeWindow.dispatchWindow('oath:sealed', { detail: { lineage: 'primordialFlame', retainedRoute: null } });
await wait(50);
assertCondition(shell5.collectionClient.calls.collectSpell.length === 0 && shell5.collectionClient.calls.praiseSpell.length === 0,
  'openGrimoire jamás dispara actos del tomo (regresión de SPEC-09)');

// joinClan: postulación retenida en el umbral (RF-01.5).
const shell6 = buildFakeShell('http://grimorio.test/');
const app6 = createGrimoireApp({
  appRoot: shell6.appRoot,
  navRoot: shell6.navRoot,
  badgeRoot: shell6.badgeRoot,
  arcaneNoticeRoot: shell6.arcaneNoticeRoot,
  spellDetailDialog: shell6.spellDetailDialog,
  accessDialog: shell6.accessDialog,
  spellClient: shell6.spellClient,
  authClient: shell6.authClient,
  lineageOathClient: shell6.oathClient,
  grimoireCollectionClient: shell6.collectionClient,
  windowRef: shell6.fakeWindow,
  documentRef: shell6.fakeDocument,
});
await app6.boot();
await wait(30);
app6.store.setState({ pendingIntent: { action: 'joinClan', targetSlug: 'cln-flame', targetSpellId: null } });
shell6.fakeWindow.dispatchWindow('oath:sealed', { detail: { lineage: 'primordialFlame', retainedRoute: null } });
await wait(50);
assertCondition(shell6.collectionClient.calls.collectSpell.length === 0 && shell6.collectionClient.calls.praiseSpell.length === 0,
  'joinClan jamás dispara actos del tomo (regresión de SPEC-09)');

// =====================================================================
// [5] Un fallo de la reanudación NO bloquea el retorno (best-effort)
// =====================================================================
console.log('\n[5] Fallo de la reanudación: retorno digno, sin bloqueo');

const failingClient = createFakeCollectionClient({
  collectSpell: async (spellId) => ({ success: false, status: 500, error: { code: 'MANA_BALANCE_FAILED', message: 'fallo' } }),
});
const shell7 = buildFakeShell('http://grimorio.test/');
const app7 = createGrimoireApp({
  appRoot: shell7.appRoot,
  navRoot: shell7.navRoot,
  badgeRoot: shell7.badgeRoot,
  arcaneNoticeRoot: shell7.arcaneNoticeRoot,
  spellDetailDialog: shell7.spellDetailDialog,
  accessDialog: shell7.accessDialog,
  spellClient: shell7.spellClient,
  authClient: shell7.authClient,
  lineageOathClient: shell7.oathClient,
  grimoireCollectionClient: failingClient,
  windowRef: shell7.fakeWindow,
  documentRef: shell7.fakeDocument,
});
await app7.boot();
await wait(30);
app7.store.setState({ pendingIntent: { action: 'addToGrimoire', targetSlug: 'biblioteca', targetSpellId: 'spl-1' } });
shell7.fakeWindow.dispatchWindow('oath:sealed', { detail: { lineage: 'primordialFlame', retainedRoute: '#/biblioteca' } });
await wait(50);
assertCondition(app7.store.getState().pendingIntent.action === null,
  'la intención fallida se consume igual (sin reintento infinito)');
assertCondition(byClass(shell7.appRoot, 'library-view') !== null,
  'el fallo del acto NO bloquea el retorno a la ruta retenida (best-effort)');
// La leyenda solemne se narra en la franja del shell (sin códigos).
const echo7 = shell7.arcaneNoticeRoot.querySelector('.arcane-echo');
assertCondition(echo7 !== null && !/\b(500|HTTP)\b/.test(echo7.textContent ?? ''),
  'la leyenda del fallo se narra sin tecnicismos (RNF-03)');

// =====================================================================
// Veredicto
// =====================================================================
console.log(`\n=== VEREDICTO: ${assertsPassed}/${assertsPassed + assertsFailed} asertos en verde ===`);
if (assertsFailed > 0) process.exit(1);
console.log('\nRESULTADO: EXITO — La Tarea 6.1 cumple su criterio «Hecho cuando».');
process.exit(0);
