/**
 * test_simulator_route.mjs — Arnés TDD del cableado del Simulador de Grimorio
 * en el orquestador de la SPA (SPEC-05, RF-01.2).
 *
 * Estrategia TDD: este arnés se escribe ANTES que la implementación.
 * Verifica, sobre `public/assets/js/main.js`, `navbarComponent.js` y
 * `grimoireSimulatorView.js`:
 *   [A] Enlace persistente y ruta pública: el Simulador se ofrece en la
 *       cabecera y monta el Tomo Canónico para cualquier visitante (RF-01.2).
 *   [B] Guardia del libro personal: pedir el tomo de Ensayos sin vínculo NO
 *       consulta al santuario, retiene la intención y despliega «Cruzar el
 *       Umbral», dejando el Tomo Canónico como refugio (RF-01.2, RNF-05).
 *   [C] Con vínculo vivo, «Ver mi libro personal» navega al Simulador en modo
 *       Ensayos sin abrir el diálogo de acceso (RF-01.2).
 *   [D] Restauración de la intención retenida tras «Cruzar el Umbral».
 *   [E] Degradación grácil si el santuario niega los Ensayos (401).
 *
 * Constitución:
 *   - Artículo I: ES Modules nativos; DOM simulado sin frameworks.
 *   - Artículo V: identificadores en inglés camelCase; comentarios en castellano.
 *
 * Uso: node scratch/test_simulator_route.mjs
 */

import { createGrimoireApp } from '../public/assets/js/main.js';
import { NAV_LINKS } from '../public/assets/js/components/navbarComponent.js';

let assertsPassed = 0;
let assertsFailed = 0;

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

// =====================================================================
// DOM simulado (innerHTML prohibido, AGENTS.md 6.1)
// =====================================================================

function createFakeContext() {
  const operations = [];
  const record = (name) => (...args) => operations.push({ name, args });
  return {
    operations,
    canvas: null,
    globalAlpha: 1,
    fillStyle: '#000',
    strokeStyle: '#000',
    font: '',
    textAlign: 'left',
    textBaseline: 'alphabetic',
    lineWidth: 1,
    save: record('save'),
    restore: record('restore'),
    beginPath: record('beginPath'),
    closePath: record('closePath'),
    moveTo: record('moveTo'),
    lineTo: record('lineTo'),
    arc: record('arc'),
    fill: record('fill'),
    stroke: record('stroke'),
    fillRect: record('fillRect'),
    clearRect: record('clearRect'),
    fillText: record('fillText'),
    translate: record('translate'),
    rotate: record('rotate'),
    scale: record('scale'),
    setLineDash: record('setLineDash'),
    setTransform: record('setTransform'),
    createRadialGradient: () => ({ addColorStop: record('addColorStop') }),
    createLinearGradient: () => ({ addColorStop: record('addColorStop') }),
    measureText: (text) => ({ width: String(text ?? '').length * 7 }),
  };
}

function createFakeElement(tagName, ownerDocument = undefined) {
  const element = {
    tagName: String(tagName).toUpperCase(),
    ownerDocument,
    children: [],
    attributes: {},
    classes: new Set(),
    listeners: {},
    style: {
      inline: {},
      setProperty(name, value) { this.inline[name] = String(value); },
      getProperty(name) { return this.inline[name] ?? null; },
    },
    _textContent: '',
    _value: '',
    disabled: false,
    open: false,
    showModalCount: 0,
    focusCount: 0,
    parentElement: null,

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
    dispatchEvent(event) {
      let node = this;
      while (node) {
        for (const listener of node.listeners?.[event?.type] ?? []) listener(event);
        if (event?.bubbles === false) break;
        node = node.parentElement ?? null;
      }
      return true;
    },
    appendChild(child) { child.parentElement = this; this.children.push(child); return child; },
    replaceChildren(...nodes) {
      this.children.forEach((child) => { child.parentElement = null; });
      this.children = [...nodes];
      this.children.forEach((child) => { child.parentElement = this; });
    },
    remove() {
      if (!this.parentElement) return;
      const index = this.parentElement.children.indexOf(this);
      if (index >= 0) this.parentElement.children.splice(index, 1);
      this.parentElement = null;
    },
    focus() { this.focusCount++; },
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
    get innerHTML() { throw new Error('PROHIBIDO innerHTML (AGENTS.md 6.1)'); },
    set innerHTML(value) { throw new Error('PROHIBIDO innerHTML (AGENTS.md 6.1)'); },
  };
  element.classList = {
    _owner: element,
    add(...names) { names.forEach((n) => this._owner.classes.add(n)); },
    remove(...names) { names.forEach((n) => this._owner.classes.delete(n)); },
    contains(name) { return this._owner.classes.has(name); },
    toggle(name, force) {
      if (force === undefined) {
        this._owner.classes.has(name)
          ? this._owner.classes.delete(name)
          : this._owner.classes.add(name);
        return this._owner.classes.has(name);
      }
      force ? this._owner.classes.add(name) : this._owner.classes.delete(name);
      return Boolean(force);
    },
  };
  if (element.tagName === 'CANVAS') {
    element.width = 800;
    element.height = 400;
    element.clientWidth = 800;
    element.clientHeight = 400;
    const context = createFakeContext();
    context.canvas = element;
    element.getContext = () => context;
  }
  return element;
}

function queryByClass(node, className, found = []) {
  for (const child of node.children ?? []) {
    if (child.classes?.has(className)) found.push(child);
    queryByClass(child, className, found);
  }
  return found;
}

const byClass = (root, className) => queryByClass(root, className)[0] ?? null;

function queryById(node, elementId, found = []) {
  for (const child of node.children ?? []) {
    if (child.getAttribute?.('id') === elementId) found.push(child);
    queryById(child, elementId, found);
  }
  return found;
}

function queryByTag(node, tagName, found = []) {
  for (const child of node.children ?? []) {
    if (child.tagName === String(tagName).toUpperCase()) found.push(child);
    queryByTag(child, tagName, found);
  }
  return found;
}

function buttonWithText(root, text) {
  return queryByTag(root, 'button').find((button) => button.textContent.trim() === text) ?? null;
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
  dialog.open = false;
  dialog.showModal = function showModal() { this.open = true; this.showModalCount++; };
  dialog.close = function close() {
    if (!this.open) return;
    this.open = false;
    for (const listener of this.listeners.close ?? []) listener({ type: 'close' });
  };
  return dialog;
}

function createFakeWindow(initialUrl = 'http://grimorio.test/') {
  const listeners = {};
  return {
    location: { hash: '', href: initialUrl },
    history: {
      replaced: null,
      pushed: null,
      replaceState(state, title, url) { this.replaced = url; },
      pushState(state, title, url) { this.pushed = url; },
    },
    addEventListener(name, listener) { (listeners[name] ??= []).push(listener); },
    removeEventListener(name, listener) {
      listeners[name] = (listeners[name] ?? []).filter((l) => l !== listener);
    },
    dispatch(name, event = {}) { (listeners[name] ?? []).forEach((l) => l({ type: name, ...event })); },
    innerWidth: 1280,
  };
}

/** Cliente SPEC-01 del shell (la portada y la biblioteca lo consumen). */
function createFakeSpellClient() {
  return {
    async fetchFeatured() {
      return { success: true, data: [] };
    },
    async fetchSpells() {
      return { success: true, data: { items: [], hasMore: false } };
    },
    async fetchSpellBySlug() {
      return { success: false, error: { code: 'SCROLL_LOST_IN_AETHER', message: 'Pergamino desvanecido.' } };
    },
    async fetchClansPreview() {
      return { success: true, data: [{ id: 'cln_primordial', name: 'Custodios del Fuego Primordial' }] };
    },
  };
}

function createFakeAuthClient({ sessionUser = null } = {}) {
  return {
    calls: { checkSession: [], bind: [], consecrate: [], dissolve: [], dissolveAll: [] },
    async checkSession() {
      this.calls.checkSession.push({});
      if (sessionUser === null) {
        return { success: true, status: 200, data: { authenticated: false, user: null } };
      }
      return { success: true, status: 200, data: { authenticated: true, user: sessionUser } };
    },
    async bind(credentials) {
      this.calls.bind.push(credentials);
      return { success: true, status: 200, data: { user: SESSION_USER } };
    },
    async consecrate(payload) {
      this.calls.consecrate.push(payload);
      return { success: true, status: 201, data: { user: SESSION_USER } };
    },
    async dissolve() { this.calls.dissolve.push({}); return { success: true, status: 200, data: {} }; },
    async dissolveAll() { this.calls.dissolveAll.push({}); return { success: true, status: 200, data: {} }; },
  };
}

/** Cliente SPEC-05 del grimorio: registra cada consulta de catálogo. */
function createFakeGrimoireClient({ essaysStatus = 200, essaySpells = [ESSAY_SPELL] } = {}) {
  const calls = [];
  return {
    calls,
    async fetchSpells(params = {}) {
      calls.push({ ...params });
      if (params.mode === 'essays') {
        if (essaysStatus !== 200) {
          return { success: false, status: essaysStatus, error: { code: 'UNAUTHENTICATED', message: 'Vínculo no reconocido para los ensayos.' } };
        }
        return {
          success: true,
          status: 200,
          data: {
            totalSpells: essaySpells.length,
            currentPage: 1,
            totalPages: Math.max(1, essaySpells.length),
            hasPrevious: false,
            hasNext: false,
            spells: essaySpells,
          },
        };
      }
      return {
        success: true,
        status: 200,
        data: {
          totalSpells: CANONICAL_CATALOG.length,
          currentPage: 1,
          totalPages: CANONICAL_CATALOG.length,
          hasPrevious: false,
          hasNext: true,
          spells: CANONICAL_CATALOG,
        },
      };
    },
    async fetchSpellDetail(id) {
      return { success: true, status: 200, data: CANONICAL_CATALOG.find((spell) => spell.id === id) ?? null };
    },
    essayCalls: () => calls.filter((call) => call.mode === 'essays'),
  };
}

function buildFakeShell(initialUrl) {
  const appRoot = createFakeElement('main');
  appRoot.setAttribute('id', 'app');
  const navRoot = createFakeElement('nav');
  navRoot.setAttribute('id', 'siteNav');
  navRoot.querySelector = (selector) => {
    const match = /^#([\w-]+)$/.exec(selector);
    return match ? queryById(navRoot, match[1])[0] ?? null : null;
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
  const fakeDocument = {
    hidden: false,
    listeners: {},
    createElement: (tag) => createFakeElement(tag, fakeDocument),
    // createElementNS: el sello heráldico del badge (SPEC-09, Tarea 3.3) lo exige.
    createElementNS: (_namespace, tag) => createFakeElement(tag, fakeDocument),
    addEventListener(name, listener) { (fakeDocument.listeners[name] ??= []).push(listener); },
    removeEventListener(name, listener) {
      fakeDocument.listeners[name] = (fakeDocument.listeners[name] ?? []).filter((l) => l !== listener);
    },
  };

  return {
    appRoot, navRoot, badgeRoot, spellDetailDialog, accessDialog,
    loginForm, loginName, loginPassword, registerForm, registerName, registerPassword, registerEmail,
    fakeWindow, fakeDocument,
    spellClient: createFakeSpellClient(),
  };
}

function buildApp(shell, { authClient, grimoireClient } = {}) {
  return createGrimoireApp({
    appRoot: shell.appRoot,
    navRoot: shell.navRoot,
    badgeRoot: shell.badgeRoot,
    spellDetailDialog: shell.spellDetailDialog,
    accessDialog: shell.accessDialog,
    spellClient: shell.spellClient,
    authClient: authClient ?? createFakeAuthClient(),
    grimoireClient: grimoireClient ?? createFakeGrimoireClient(),
    windowRef: shell.fakeWindow,
    documentRef: shell.fakeDocument,
  });
}

/** Enlaces del navbar identificados por data-view. */
function findNavLink(shell, viewName) {
  const linksList = queryById(shell.navRoot, 'navLinks')[0];
  return (linksList?.children ?? []).find((link) => link.getAttribute('data-view') === viewName) ?? null;
}

/** Último anuncio accesible del simulador (RF-06.4): la región es un estado. */
function liveRegionText(shell) {
  return byClass(shell.appRoot, 'grimoire-simulator__announcer')?.textContent ?? '';
}

/** Conjuro iluminado en la lámina del Tomo (RF-01.5). */
function exhibitedSpell(shell) {
  return byClass(shell.appRoot, 'grimoire-book__spell-name')?.textContent?.trim() ?? null;
}

/** Tomo activo según el conmutador rúnico (RF-01.2). */
function activeCatalogMode(shell) {
  const essaysOption = byClass(shell.appRoot, 'grimoire-simulator__catalog-switch--essays');
  return essaysOption?.classList.contains('grimoire-simulator__catalog-switch--active')
    ? 'essays'
    : 'canonical';
}

const SESSION_USER = {
  id: 'usr_visual', alias: 'Erudita Visual', role: 'editor',
  lineage: 'primordialFlame', // SPEC-09: vínculo jurado; sin él la retención del juramento desvía la navegación a la ceremonia
  clanId: 'cln_primordial', clanName: 'Custodios del Fuego Primordial',
};

const CANONICAL_CATALOG = [
  {
    id: 'spl_genesis_01', slug: 'chispa-de-ignicion', name: 'Chispa de Ignición', circle: 1,
    elementalAffinity: 'fire', manaCost: 5, castingTime: 'action', areaType: 'singleTarget',
    incantationFormula: 'Ignis Primordialis, lucem manifestare',
    description: 'El primer conjuro que todo aprendiz traza.', effects: { damage: 5, healing: 0, barrier: 0, crowdControlType: 'none' },
  },
  {
    id: 'spl_genesis_02', slug: 'velo-de-bruma', name: 'Velo de Bruma', circle: 2,
    elementalAffinity: 'water', manaCost: 12, castingTime: 'ritual', areaType: 'cone',
    incantationFormula: 'Vapor Arcanum, silentium tege',
    description: 'Teje un manto de neblina fría.', effects: { damage: 0, healing: 25, barrier: 0, crowdControlType: 'none' },
  },
];

const ESSAY_SPELL = {
  id: 'spl_essay_01', slug: 'borrador-del-alba', name: 'Borrador del Alba', circle: 3,
  elementalAffinity: 'pureArcane', manaCost: 40, castingTime: 'action', areaType: 'line',
  incantationFormula: '¡Runas en prueba, responded al llamado!',
  description: 'Ensayo privado del autor.', status: 'draft',
  effects: { damage: 30, healing: 0, barrier: 0, crowdControlType: 'none' },
};

console.log('== ARNÉS TDD — Ruta del Simulador de Grimorio en la SPA (SPEC-05) ==\n');

// =====================================================================
// [A] Enlace persistente y ruta pública (RF-01.2)
// =====================================================================
console.log('[A] Enlace persistente y ruta pública del Simulador');

const navEntry = NAV_LINKS.find((link) => link.view === 'simulator') ?? null;
assertCondition(navEntry !== null, 'el catálogo de enlaces declara la vista del Simulador');
assertCondition(navEntry?.hash === '#/simulador', 'el enlace apunta al sendero canónico #/simulador (RF-02.1)');
assertCondition(navEntry?.action === undefined, 'el enlace es público: ningún visitante queda excluido (RF-01.2)');

const shellA = buildFakeShell('http://grimorio.test/');
const grimoireA = createFakeGrimoireClient();
const appA = buildApp(shellA, { grimoireClient: grimoireA });
await appA.boot();

const simulatorLink = findNavLink(shellA, 'simulator');
assertCondition(simulatorLink !== null, 'la cabecera pinta el enlace «Simulador de Grimorio»');
assertCondition(simulatorLink?.getAttribute('data-reserved') === null, 'el enlace no está marcado como reservado');

simulatorLink.dispatch('click');
await wait(10);
assertCondition(appA.store.getState().currentView === 'simulator', 'el visitante navega al Simulador sin barreras (RF-01.2)');
assertCondition(byClass(shellA.appRoot, 'grimoire-simulator') !== null, 'la vista del Simulador se monta en el punto de anclaje');
assertCondition(grimoireA.calls.some((call) => call.mode === 'canonical'), 'la vista solicita el Tomo Canónico al santuario');
assertCondition(grimoireA.essayCalls().length === 0, 'el visitante jamás consulta los Ensayos privados');
assertCondition(activeCatalogMode(shellA) === 'canonical', 'el conmutador declara activo el Tomo Canónico (RF-01.2)');
assertCondition(exhibitedSpell(shellA) === 'Chispa de Ignición', 'la lámina ilumina el primer conjuro canónico (RF-01.5)');
assertCondition(/Página 1 de 2/.test(liveRegionText(shellA)), 'la región viva anuncia la página abierta (RF-06.4)');
assertCondition(byClass(shellA.appRoot, 'spell-library') === null, 'la vista anterior se desmonta (una sola vista por vez)');

await appA.navigate('library');
await wait(10);
assertCondition(byClass(shellA.appRoot, 'grimoire-simulator') === null, 'salir del Simulador desmonta su vista y libera sus bucles');
assertCondition(appA.store.getState().currentView === 'library', 'el store refleja la vista vigente');

// =====================================================================
// [B] Guardia del libro personal sin vínculo
// =====================================================================
console.log('\n[B] Guardia del libro personal sin vínculo');

const shellB = buildFakeShell('http://grimorio.test/');
const grimoireB = createFakeGrimoireClient();
const authB = createFakeAuthClient();
const appB = buildApp(shellB, { authClient: authB, grimoireClient: grimoireB });
await appB.boot();

await appB.navigate('simulator', { catalogMode: 'essays' });
await wait(10);
assertCondition(grimoireB.essayCalls().length === 0, 'sin vínculo NO se consulta el tomo privado al santuario (RF-01.2)');
assertCondition(shellB.accessDialog.open === true, 'se despliega «Cruzar el Umbral» ante el intento vedado (RF-02.3)');
assertCondition(appB.store.getState().pendingIntent.action === 'openGrimoire', 'la intención del libro personal queda retenida (RF-05.2)');
assertCondition(appB.store.getState().currentView === 'simulator', 'el sendero público sigue abierto: la vista se monta igualmente');
assertCondition(activeCatalogMode(shellB) === 'canonical', 'el Tomo Canónico queda como refugio público (RF-01.2)');
assertCondition(exhibitedSpell(shellB) === 'Chispa de Ignición', 'el refugio ilumina un conjuro canónico, jamás un borrador');
assertCondition(byClass(shellB.appRoot, 'grimoire-simulator') !== null, 'el visitante no queda ante un pergamino en blanco');

// =====================================================================
// [C] Con vínculo vivo: «Ver mi libro personal»
// =====================================================================
console.log('\n[C] Con vínculo vivo: «Ver mi libro personal»');

const shellC = buildFakeShell('http://grimorio.test/');
const grimoireC = createFakeGrimoireClient();
const authC = createFakeAuthClient({ sessionUser: SESSION_USER });
const appC = buildApp(shellC, { authClient: authC, grimoireClient: grimoireC });
await appC.boot();
await wait(10);

const badgeC = queryById(shellC.badgeRoot, 'userProfileBadge')[0] ?? null;
assertCondition(badgeC !== null, 'con vínculo la cabecera muestra el distintivo de sesión');
const grimoireAction = queryByTag(shellC.badgeRoot, 'button')
  .find((button) => button.getAttribute('data-action') === 'openGrimoire') ?? null;
assertCondition(grimoireAction !== null, 'el menú arcano ofrece «Ver mi libro personal» (RF-07.1)');

grimoireAction.dispatch('click');
await wait(10);
assertCondition(appC.store.getState().currentView === 'simulator', 'la acción navega al Simulador (RF-01.2)');
assertCondition(grimoireC.essayCalls().length === 1, 'el Tomo de Ensayos se consulta con el vínculo activo');
assertCondition(grimoireC.essayCalls()[0]?.mode === 'essays', 'la consulta declara mode=essays (plan 2.1)');
assertCondition(shellC.accessDialog.open === false, 'con vínculo no se despliega «Cruzar el Umbral»');
assertCondition(appC.store.getState().pendingIntent.action === null, 'no queda intención pendiente que consumir');
assertCondition(activeCatalogMode(shellC) === 'essays', 'el conmutador declara activo el Tomo de Ensayos (RF-01.2)');
assertCondition(exhibitedSpell(shellC) === 'Borrador del Alba', 'la lámina ilumina el ensayo privado del autor (RF-01.5)');

// =====================================================================
// [D] Restauración de la intención tras «Cruzar el Umbral»
// =====================================================================
console.log('\n[D] Restauración de la intención tras autenticar');

const shellD = buildFakeShell('http://grimorio.test/');
const grimoireD = createFakeGrimoireClient();
const authD = createFakeAuthClient();
const appD = buildApp(shellD, { authClient: authD, grimoireClient: grimoireD });
await appD.boot();
await appD.navigate('simulator', { catalogMode: 'essays' });
await wait(10);
assertCondition(appD.store.getState().pendingIntent.action === 'openGrimoire', 'la guardia retuvo la intención del libro personal');

shellD.loginName.value = 'Erudita Visual';
shellD.loginPassword.value = 'frase-secreta';
const loginFormD = queryById(shellD.accessDialog, 'loginForm')[0];
loginFormD.dispatch('submit', { preventDefault() {} });
await wait(20);

assertCondition(authD.calls.bind.length === 1, 'el orquestador llamó a bind() con las credenciales del shell (SPEC-03)');
assertCondition(appD.store.getState().pendingIntent.action === null, 'la intención retenida se consumió (RF-05.3)');
assertCondition(grimoireD.essayCalls().length === 1, 'tras autenticar se restablece la navegación al Tomo de Ensayos');
assertCondition(appD.store.getState().currentView === 'simulator', 'la restauración desemboca en el Simulador');
assertCondition(exhibitedSpell(shellD) === 'Borrador del Alba', 'el tomo privado queda abierto tras el vínculo');
assertCondition(queryById(shellD.badgeRoot, 'userProfileBadge').length === 1, 'el badge reemplaza al botón del umbral');

// =====================================================================
// [E] Degradación grácil si el santuario niega los Ensayos
// =====================================================================
console.log('\n[E] Degradación grácil si el santuario niega los Ensayos');

const shellE = buildFakeShell('http://grimorio.test/');
const grimoireE = createFakeGrimoireClient({ essaysStatus: 401 });
const authE = createFakeAuthClient({ sessionUser: SESSION_USER });
const appE = buildApp(shellE, { authClient: authE, grimoireClient: grimoireE });
await appE.boot();
await wait(10);
await appE.navigate('simulator', { catalogMode: 'essays' });
await wait(10);

assertCondition(grimoireE.essayCalls().length === 1, 'con vínculo sí se solicitó el tomo privado');
assertCondition(activeCatalogMode(shellE) === 'canonical', 'ante 401 el Tomo Canónico permanece abierto (RF-01.2)');
assertCondition(exhibitedSpell(shellE) === 'Chispa de Ignición', 'la degradación exhibe el catálogo público, no el privado');
assertCondition(shellE.appRoot.children.length === 1, 'la vista no duplica su montaje tras la degradación');

// =====================================================================
// Resumen
// =====================================================================
console.log('\n== RESUMEN ==');
console.log(`Asertos superados: ${assertsPassed}, fallidos: ${assertsFailed}`);
if (assertsFailed === 0) {
  console.log('RESULTADO: EXITO — El Simulador está cableado en el orquestador con su navegación protegida.');
  process.exit(0);
}
console.log('RESULTADO: FALLO — Revisa los asertos marcados.');
process.exit(1);
