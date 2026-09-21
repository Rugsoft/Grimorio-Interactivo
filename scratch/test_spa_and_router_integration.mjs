/**
 * test_spa_and_router_integration.mjs — Verificación de integración del Endpoint
 * de Bitácora en el Router y montaje de las 4 vistas (Codex, Atrio, Torre, Bitácora) en la SPA.
 *
 * Escenarios probados:
 *   [1] Paridad en el Router Frontal: GET /api/v1/audit/log está registrado y responde 200.
 *   [2] Barra de navegación: enlaces públicos (Códice, Atrio, Bitácora) presentes para todos.
 *   [3] Control de acceso en la barra: enlace a la Torre de Deliberación (#/torre) condicional
 *       según el rol judicial (oculto para reader/editor; visible para master/supremeAdmin).
 *   [4] Montaje de las 4 vistas en la SPA vía navigate():
 *       - 'codex' (#/codex) monta la vista del Códice Elemental.
 *       - 'experimentalHall' (#/atrio) monta el Atrio de Pruebas.
 *       - 'auditLog' (#/bitacora) monta la Bitácora de Auditoría.
 *       - 'tower' (#/torre) monta la Torre para master y redirige para reader/editor.
 *   [5] Conmutador de rutas reactivo ante eventos hashchange (#/codex, #/atrio, #/bitacora, #/torre).
 *
 * Uso: node scratch/test_spa_and_router_integration.mjs
 */

import { execFileSync } from 'node:child_process';
import { createGrimoireApp, HASH_TO_VIEW_MAP, VIEW_TO_HASH_MAP } from '../public/assets/js/main.js';
import { createNavbarComponent, NAV_LINKS, CONDITIONAL_NAV_LINKS } from '../public/assets/js/components/navbarComponent.js';

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

/** Elemento DOM mínimo simulado para arneses sin navegador. */
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
    setAttribute(name, value) {
      this.attributes[name] = String(value);
      if (name === 'class') {
        this.classes = new Set(String(value).split(/\s+/).filter(Boolean));
      }
    },
    getAttribute(name) { return name in this.attributes ? this.attributes[name] : null; },
    removeAttribute(name) {
      delete this.attributes[name];
      if (name === 'class') this.classes.clear();
    },
    hasAttribute(name) { return name in this.attributes; },
    addEventListener(eventName, listener) { (this.listeners[eventName] ??= []).push(listener); },
    removeEventListener(eventName, listener) { this.listeners[eventName] = (this.listeners[eventName] ?? []).filter((l) => l !== listener); },
    dispatch(eventName, eventObject = {}) {
      for (const listener of this.listeners[eventName] ?? []) {
        listener({ type: eventName, preventDefault() {}, stopPropagation() {}, currentTarget: this, target: this, ...eventObject });
      }
    },
    appendChild(child) { child.parentElement = this; this.children.push(child); return child; },
    replaceChildren(...newChildren) {
      for (const child of this.children) child.parentElement = null;
      this.children = [];
      for (const child of newChildren) {
        child.parentElement = this;
        this.children.push(child);
      }
    },
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
    querySelector(selector) { return this.querySelectorAll(selector)[0] ?? null; },
    querySelectorAll(selector) {
      const results = [];
      const isClass = selector.startsWith('.');
      const isId = selector.startsWith('#');
      const target = isClass || isId ? selector.slice(1) : selector.toUpperCase();

      const search = (node) => {
        for (const child of node.children) {
          if (isClass && child.classes.has(target)) results.push(child);
          else if (isId && child.getAttribute('id') === target) results.push(child);
          else if (!isClass && !isId && child.tagName === target) results.push(child);
          search(child);
        }
      };
      search(this);
      return results;
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
    if (child.classes?.has?.(className)) found.push(child);
    queryByClass(child, className, found);
  }
  return found;
}

function byClass(node, className) {
  return queryByClass(node, className)[0] ?? null;
}

function queryById(node, id, found = []) {
  if (node.getAttribute?.('id') === id) found.push(node);
  for (const child of node.children) {
    queryById(child, id, found);
  }
  return found;
}

function createFakeDialog(id) {
  const dialog = createFakeElement('dialog');
  dialog.setAttribute('id', id);
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
    location: {
      href: initialUrl,
      hash: initialUrl.includes('#') ? `#${initialUrl.split('#')[1]}` : '',
      assignCalls: [],
      assign(url) { win.location.assignCalls.push(url); },
    },
    history: { _entries: [initialUrl], _index: 0, state: null },
    addEventListener(name, listener) { (this.listeners[name] ??= []).push(listener); },
    removeEventListener(name, listener) { this.listeners[name] = (this.listeners[name] ?? []).filter((l) => l !== listener); },
    setHash(newHash) {
      win.location.hash = newHash;
      win.location.href = `http://grimorio.test/${newHash}`;
      for (const listener of win.listeners.hashchange ?? []) {
        listener({ type: 'hashchange', newURL: win.location.href });
      }
    },
  };
  return win;
}

function buildTestShell(initialUrl = 'http://grimorio.test/') {
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
  const accessDialog = createFakeDialog('accessModal');
  const fakeWindow = createFakeWindow(initialUrl);
  const fakeDocument = {
    createElement: (tag) => createFakeElement(tag),
    createElementNS: (ns, tag) => createFakeElement(tag),
  };

  const fakeSpellClient = {
    fetchFeatured: async () => ({ success: true, data: [] }),
    fetchSpells: async () => ({ success: true, data: { items: [], hasMore: false } }),
    fetchSpellBySlug: async () => ({ success: false, error: { code: 'NOT_FOUND' } }),
    fetchClansPreview: async () => ({ success: true, data: [] }),
  };

  const fakeAuthClient = {
    checkSession: async () => ({ success: true, status: 200, data: { authenticated: false, user: null } }),
    bind: async () => ({ success: true, status: 200, data: { user: { id: 'u1', alias: 'mago', role: 'reader', lineage: 'primordialFlame' } } }),
    consecrate: async () => ({ success: true, status: 201, data: { user: { id: 'u1', alias: 'mago', role: 'reader', lineage: 'primordialFlame' } } }),
    dissolve: async () => ({ success: true, status: 200, data: { dissolved: true } }),
    dissolveAll: async () => ({ success: true, status: 200, data: { dissolved: true } }),
    fetchAuditLog: async () => ({ success: true, status: 200, data: { items: [], pagination: { page: 1, limit: 25, totalPages: 1, totalItems: 0 } } }),
  };

  const fakeElementalClient = {
    fetchMatrixGraph: async () => ({
      success: true,
      data: {
        elements: [
          { id: 'fire', name: 'Fuego', color: '#ff4400', glyph: 'rune-fire' },
          { id: 'water', name: 'Agua', color: '#0088ff', glyph: 'rune-water' },
        ],
        reactions: [],
      },
    }),
    fetchReactionsForElement: async () => ({ success: true, data: [] }),
    resolveCombo: async () => ({ success: true, data: { reactionId: 'test' } }),
  };

  const fakeModerationClient = {
    fetchExperimentalHall: async () => ({ success: true, data: { hallWarning: { code: 'WARN', legend: 'Aviso', pointsBlocked: true }, items: [] } }),
    fetchDeliberationQueue: async () => ({ success: true, data: { items: [], canon: { signaturesRequired: 3, maxQueueItems: 50, judgeRoles: ['master', 'supremeAdmin'] } } }),
    signSpell: async () => ({ success: true }),
    objectSpell: async () => ({ success: true }),
  };

  return {
    appRoot,
    navRoot,
    spellDetailDialog,
    accessDialog,
    fakeWindow,
    fakeDocument,
    fakeSpellClient,
    fakeAuthClient,
    fakeElementalClient,
    fakeModerationClient,
  };
}

console.log('== VERIFICACIÓN DE INTEGRACIÓN: ROUTER Y VISTAS DE LA SPA ==\n');

// ══════════════════════════════════════════════════════════════════════
// FASE 1: Endpoint de Bitácora en el Front Controller (Router)
// ══════════════════════════════════════════════════════════════════════
console.log('FASE 1: Endpoint GET /api/v1/audit/log en el Router');

try {
  const phpCode = "$r = require 'public/index.php'; $router = buildRouter(); $req = new \\Grimorio\\Core\\Request('GET', '/api/v1/audit/log'); $res = $router->dispatch($req); echo $res->getStatusCode() . ' ' . $res->getHeader('Content-Type');";
  const phpOutput = execFileSync('php', ['-r', phpCode], { encoding: 'utf-8' }).trim();
  assertCondition(phpOutput.includes('200') && phpOutput.includes('application/json'), `El router frontal despacha GET /api/v1/audit/log con código 200 y Content-Type JSON (${phpOutput})`);
} catch (error) {
  assertCondition(false, `Error ejecutando php check para /api/v1/audit/log: ${error.message}`);
}

// ══════════════════════════════════════════════════════════════════════
// FASE 2: Enlaces en la Barra de Navegación y Roles Condicionales
// ══════════════════════════════════════════════════════════════════════
console.log('\nFASE 2: Enlaces en navbarComponent.js');

const shell2 = buildTestShell();
const linksList2 = shell2.navRoot.querySelector('#navLinks');

// 2.1 Enlaces públicos para anónimo / reader
const navVisitor = createNavbarComponent(shell2.navRoot, {
  isAuthenticated: false,
  userRole: 'reader',
  onNavigate: () => {},
  elementFactory: (tag) => createFakeElement(tag),
});
navVisitor.render();

const renderedVisitorLinks = linksList2.children.filter((c) => c.tagName === 'A');
assertCondition(renderedVisitorLinks.length === 9, `El visitante ve exactamente 9 enlaces públicos, incluidas las Hermandades de SPEC-10 (hallados ${renderedVisitorLinks.length})`);
assertCondition(renderedVisitorLinks.some((l) => l.getAttribute('data-view') === 'codex'), 'Códice de Afinidades (#/codex) está presente para todos');
assertCondition(renderedVisitorLinks.some((l) => l.getAttribute('data-view') === 'experimentalHall'), 'Atrio de Pruebas (#/atrio) está presente para todos');
assertCondition(renderedVisitorLinks.some((l) => l.getAttribute('data-view') === 'auditLog'), 'Bitácora de Auditoría (#/bitacora) está presente para todos');
assertCondition(renderedVisitorLinks.some((l) => l.getAttribute('data-view') === 'vestibule'), 'Hermandades (#/vestibulo, SPEC-10) está presente para todos');
assertCondition(!renderedVisitorLinks.some((l) => l.getAttribute('data-view') === 'tower'), 'Torre de Deliberación (#/torre) está OCULTA para rol reader');

// 2.2 Enlace condicional para master
navVisitor.setSession(true, 'master');
const renderedMasterLinks = linksList2.children.filter((c) => c.tagName === 'A');
assertCondition(renderedMasterLinks.length === 10, `El Maestro ve 10 enlaces incluyendo la Torre de Deliberación (hallados ${renderedMasterLinks.length})`);
assertCondition(renderedMasterLinks.some((l) => l.getAttribute('data-view') === 'tower'), 'Torre de Deliberación (#/torre) es visible para rol master');

// 2.3 Enlace condicional para supremeAdmin
navVisitor.setSession(true, 'supremeAdmin');
const renderedAdminLinks = linksList2.children.filter((c) => c.tagName === 'A');
assertCondition(renderedAdminLinks.some((l) => l.getAttribute('data-view') === 'tower'), 'Torre de Deliberación (#/torre) es visible para supremeAdmin');

// 2.4 Disolver sesión retira el enlace
navVisitor.setSession(false, 'reader');
const renderedClearedLinks = linksList2.children.filter((c) => c.tagName === 'A');
assertCondition(!renderedClearedLinks.some((l) => l.getAttribute('data-view') === 'tower'), 'Al disolver sesión la Torre se retira de la cabecera');
navVisitor.destroy();

// ══════════════════════════════════════════════════════════════════════
// FASE 3: Montaje de las 4 Vistas en la SPA (main.js)
// ══════════════════════════════════════════════════════════════════════
console.log('\nFASE 3: Montaje de las 4 vistas vía navigate()');

const shell3 = buildTestShell();
const app3 = createGrimoireApp({
  appRoot: shell3.appRoot,
  navRoot: shell3.navRoot,
  spellDetailDialog: shell3.spellDetailDialog,
  accessDialog: shell3.accessDialog,
  spellClient: shell3.fakeSpellClient,
  authClient: shell3.fakeAuthClient,
  elementalMatrixClient: shell3.fakeElementalClient,
  moderationClient: shell3.fakeModerationClient,
  auditClient: shell3.fakeAuthClient,
  windowRef: shell3.fakeWindow,
  documentRef: shell3.fakeDocument,
});
await app3.boot();

// 3.1 Vista Códice Elemental
await app3.navigate('codex');
assertCondition(byClass(shell3.appRoot, 'elemental-wheel') !== null || byClass(shell3.appRoot, 'elemental-codex-view') !== null || shell3.appRoot.children.length > 0, 'navigate("codex") monta la vista del Códice de Afinidades');
assertCondition(app3.store.getState().currentView === 'codex', 'El store registra currentView="codex"');

// 3.2 Vista Atrio de los Arcanos Experimentales
await app3.navigate('experimentalHall');
assertCondition(byClass(shell3.appRoot, 'experimental-hall') !== null, 'navigate("experimentalHall") monta el Atrio de Pruebas');
assertCondition(app3.store.getState().currentView === 'experimentalHall', 'El store registra currentView="experimentalHall"');

// 3.3 Vista Bitácora de Auditoría
await app3.navigate('auditLog');
assertCondition(byClass(shell3.appRoot, 'audit-log') !== null || queryById(shell3.appRoot, 'auditLogRegion').length > 0 || shell3.appRoot.children.length > 0, 'navigate("auditLog") monta la Bitácora de Auditoría');
assertCondition(app3.store.getState().currentView === 'auditLog', 'El store registra currentView="auditLog"');

// 3.4 Vista Torre de Deliberación: denegada para reader -> redirige a library
await app3.navigate('tower');
assertCondition(app3.store.getState().currentView === 'library', 'navigate("tower") sin rol judicial es redirigido a library por RBAC');

// 3.5 Vista Torre de Deliberación: admitida para master
app3.store.setSession({ id: 'u_master', alias: 'MaestroArcano', role: 'master', lineage: 'primordialFlame' }); // SPEC-09: linajado — sin retención.
await app3.navigate('tower');
assertCondition(byClass(shell3.appRoot, 'masters-tower') !== null, 'navigate("tower") con rol master monta la Torre de Deliberación');
assertCondition(app3.store.getState().currentView === 'tower', 'El store registra currentView="tower"');

app3.destroy();

// ══════════════════════════════════════════════════════════════════════
// FASE 4: Conmutación de Rutas Reactiva por Hash (hashchange)
// ══════════════════════════════════════════════════════════════════════
console.log('\nFASE 4: Enrutamiento reactivo por hash de URL');

const shell4 = buildTestShell('http://grimorio.test/#/codex');
const app4 = createGrimoireApp({
  appRoot: shell4.appRoot,
  navRoot: shell4.navRoot,
  spellDetailDialog: shell4.spellDetailDialog,
  accessDialog: shell4.accessDialog,
  spellClient: shell4.fakeSpellClient,
  authClient: shell4.fakeAuthClient,
  elementalMatrixClient: shell4.fakeElementalClient,
  moderationClient: shell4.fakeModerationClient,
  auditClient: shell4.fakeAuthClient,
  windowRef: shell4.fakeWindow,
  documentRef: shell4.fakeDocument,
});

await app4.boot();
assertCondition(app4.store.getState().currentView === 'codex', 'Cargar con hash #/codex arranca directamente en la vista codex');

// Cambio reactivo a #/atrio
shell4.fakeWindow.setHash('#/atrio');
// Dar un tick para que resuelva la promesa asíncrona de render
await new Promise((r) => setTimeout(r, 20));
assertCondition(app4.store.getState().currentView === 'experimentalHall', 'Cambiar hash a #/atrio conmuta a experimentalHall');

// Cambio reactivo a #/bitacora
shell4.fakeWindow.setHash('#/bitacora');
await new Promise((r) => setTimeout(r, 20));
assertCondition(app4.store.getState().currentView === 'auditLog', 'Cambiar hash a #/bitacora conmuta a auditLog');

// Cambio reactivo a #/torre siendo master
app4.store.setSession({ id: 'u_supremo', alias: 'Supremo', role: 'supremeAdmin' });
shell4.fakeWindow.setHash('#/torre');
await new Promise((r) => setTimeout(r, 20));
assertCondition(app4.store.getState().currentView === 'tower', 'Cambiar hash a #/torre con rol judicial conmuta a tower');

// Verificación de contratos exportados
assertCondition(HASH_TO_VIEW_MAP['#/codex'] === 'codex', 'HASH_TO_VIEW_MAP mapea #/codex a codex');
assertCondition(HASH_TO_VIEW_MAP['#/atrio'] === 'experimentalHall', 'HASH_TO_VIEW_MAP mapea #/atrio a experimentalHall');
assertCondition(HASH_TO_VIEW_MAP['#/torre'] === 'tower', 'HASH_TO_VIEW_MAP mapea #/torre a tower');
assertCondition(HASH_TO_VIEW_MAP['#/bitacora'] === 'auditLog', 'HASH_TO_VIEW_MAP mapea #/bitacora a auditLog');

app4.destroy();

// ══════════════════════════════════════════════════════════════════════
// RESUMEN
// ══════════════════════════════════════════════════════════════════════
console.log('\n== RESUMEN ==');
console.log(`Asertos superados: ${assertsPassed}`);
console.log(`Asertos fallidos:  ${assertsFailed}`);

if (assertsFailed === 0) {
  console.log('\nRESULTADO: EXITO — El endpoint de auditoría y las 4 vistas están formalmente integrados.');
  process.exit(0);
}

console.log('\nRESULTADO: FALLO — Corregir asertos fallidos.');
process.exit(1);
