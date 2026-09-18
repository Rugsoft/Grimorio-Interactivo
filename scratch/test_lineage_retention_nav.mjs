/**
 * test_lineage_retention_nav.mjs — Verificación de la Tarea 3.2 de TASKS-09.
 *
 * Valida el store y el interceptor de retención (`navigate()`):
 *
 * El criterio «Hecho cuando» de la tarea se comprueba en cuatro frentes:
 *   1. Un peregrino que pide `#/creador` acaba en la ceremonia con su
 *      ruta retenida (envío al endpoint de retención).
 *   2. Tras sellar, aterriza en `#/creador` (retorno del veredicto).
 *   3. Un linajado navega sin un solo round-trip adicional.
 *   4. El Supremo jamás es retenido.
 *
 * Estrategia: se monta la SPA REAL (createGrimoireApp) con el shell mínimo
 * y todos los clientes fingidos (Dogma Vanilla: sin DOM real, shim nativo);
 * el interceptor se ejercita a través de navigate() pública.
 *
 * Uso: node scratch/test_lineage_retention_nav.mjs
 */

import assert from 'node:assert';

let assertsPassed = 0;
let assertsFailed = 0;
let uncaughtErrors = 0;

process.on("uncaughtException", (e) => { uncaughtErrors++; console.log(`  [EXCEPCION] ${e.stack ?? e}`); });
process.on("unhandledRejection", (e) => { uncaughtErrors++; console.log(`  [RECHAZO] ${e?.stack ?? e}`); });

function assertCondition(condition, description) {
  if (condition) {
    assertsPassed++;
    console.log(`  [PASA] ${description}`);
  } else {
    assertsFailed++;
    console.log(`  [FALLA] ${description}`);
  }
}

console.log('== VERIFICACION TAREA 3.2: El interceptor de retención ==\n');

// --- Shell mínimo: shim nativo de DOM (sin librerías) ---
function createFakeElement(tagName) {
  const element = {
    tagName: String(tagName).toUpperCase(),
    children: [],
    attributes: {},
    classes: new Set(),
    style: {
      setProperty(name, value) { (this.inline ??= {})[name] = String(value); },
      removeProperty(name) { const copy = { ...(this.inline ?? {}) }; delete copy[name]; this.inline = copy; },
      getProperty(name) { return (this.inline ?? {})[name] ?? null; },
    },
    listeners: {},
    textContent: '',
    parentNode: null,
    className: '',
    id: '',
  };
  Object.defineProperty(element, 'classList', {
    value: {
      add: (...names) => names.forEach((n) => element.classes.add(n)),
      remove: (...names) => names.forEach((n) => element.classes.delete(n)),
      contains: (name) => element.classes.has(name),
      toggle: (name, force) => {
        const has = element.classes.has(name);
        const shouldHave = force === undefined ? !has : Boolean(force);
        if (shouldHave) element.classes.add(name); else element.classes.delete(name);
        return shouldHave;
      },
    },
  });
  // Comportamiento DOM real: className viaja a classList; class/className
  // son vistas del mismo dato (el sello forjado viaja con setAttribute('class',…)).
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
  element.replaceChildren = (...newChildren) => {
    for (const child of element.children) child.parentNode = null;
    element.children = [];
    for (const child of newChildren) element.appendChild(child);
  };
  element.remove = () => {
    if (element.parentNode) {
      const siblings = element.parentNode.children;
      const index = siblings.indexOf(element);
      if (index !== -1) siblings.splice(index, 1);
      element.parentNode = null;
    }
  };
  element.addEventListener = (type, listener) => {
    (element.listeners[type] ??= []).push(listener);
  };
  element.removeEventListener = (type, listener) => {
    element.listeners[type] = (element.listeners[type] ?? []).filter((l) => l !== listener);
  };
  element.dispatchEvent = (event) => {
    for (const listener of element.listeners[event.type] ?? []) listener(event);
    return true;
  };
  element.querySelector = () => null;
  element.querySelectorAll = () => [];
  element.focus = () => {};
  return element;
}

const listenersByType = new Map();
const fakeWindow = {
  location: { hash: '' },
  addEventListener: (type, listener) => { (listenersByType.get(type) ?? listenersByType.set(type, []).get(type)).push(listener); },
  removeEventListener: (type, listener) => {
    const bucket = listenersByType.get(type) ?? [];
    const index = bucket.indexOf(listener);
    if (index !== -1) bucket.splice(index, 1);
  },
  dispatchEvent: (event) => {
    for (const listener of listenersByType.get(event.type) ?? []) listener(event);
    return true;
  },
  document: {
    getElementById: () => null,
    createElement: createFakeElement,
    createElementNS: (namespace, tagName) => createFakeElement(tagName),
    createDocumentFragment: () => createFakeElement('fragment'),
    querySelector: () => null,
    querySelectorAll: () => [],
  },
  matchMedia: () => ({ matches: false, addEventListener: () => {}, removeEventListener: () => {} }),
  fetch: async () => { throw new TypeError('Failed to fetch'); },
  setTimeout: (fn) => fn(),
  clearTimeout: () => {},
  CustomEvent: class CustomEvent {
    constructor(type, options = {}) { this.type = type; this.detail = options.detail ?? null; }
  },
};

const appRoot = createFakeElement('main');
/** Nav con su lista interna (#navLinks) y botón (#navToggle), como el shell. */
function createFakeNavRoot() {
  const navRoot = createFakeElement('nav');
  const linksList = createFakeElement('ul');
  linksList.id = 'navLinks';
  const toggleButton = createFakeElement('button');
  toggleButton.id = 'navToggle';
  navRoot.appendChild(linksList);
  navRoot.appendChild(toggleButton);
  navRoot.querySelector = (selector) => {
    if (selector === '#navLinks') return linksList;
    if (selector === '#navToggle') return toggleButton;
    return null;
  };
  return navRoot;
}
const navRoot = createFakeNavRoot();
const badgeRoot = createFakeElement('div');

/** Diálogo falso de los modales del shell (ficha y acceso): el modal de
 *  detalle exige que el elemento exista; su querySelector puede devolver
 *  null porque la ceremonia jamás abre fichas. */
function createFakeDialog() {
  const dialog = createFakeElement('dialog');
  dialog.open = false;
  dialog.showModal = () => { dialog.open = true; };
  dialog.close = () => {
    if (dialog.open) {
      dialog.open = false;
      dialog.dispatchEvent({ type: 'close', detail: null });
    }
  };
  return dialog;
}

const spellDetailDialog = createFakeDialog();
const accessDialog = createFakeDialog();

// --- Clientes fingidos: el retenedor captura los envíos de retención ---
const retainedRoutes = [];
const navigationLog = [];

/** El sobre de sesión servido por el authClient fingido. */
let sessionEnvelope = { success: true, data: { authenticated: false, user: null } };

const fakeAuthClient = {
  async checkSession() { return sessionEnvelope; },
  async bind() { return { success: false, error: { code: 'INVALID_CREDENTIALS', message: 'x' } }; },
  async consecrate() { return { success: false, error: { code: 'INVALID_REGISTRATION_DATA', message: 'x' } }; },
  async dissolve() { return { success: true, data: null }; },
  async dissolveAll() { return { success: true, data: null }; },
};

const fakeSpellClient = {
  // El contrato del cliente: envoltura con `data` ARRAY de hechizos.
  async fetchFeatured() { return { success: true, data: [] }; },
  async fetchSpells() { return { success: true, data: [], total: 0 }; },
  async fetchSpellBySlug() { return { success: false, error: { code: 'NOT_FOUND', message: 'x' } }; },
  async fetchClansPreview() { return { success: true, data: { clans: [] } }; },
};

// Los clientes de vistas que el orquestador cablea: respuestas vacías dignas.
const silentOk = async () => ({ success: true, data: null });

// --- Montaje de la SPA real ---
let app = null;
try {
  const { createGrimoireApp } = await import('../public/assets/js/main.js');
  app = createGrimoireApp({
    appRoot,
    navRoot,
    badgeRoot,
    spellDetailDialog,
    accessDialog,
    windowRef: fakeWindow,
    documentRef: fakeWindow.document,
    spellClient: fakeSpellClient,
    authClient: fakeAuthClient,
    grimoireClient: { async listSpells() { return { success: true, data: { spells: [], total: 0 } }; }, async showSpell() { return { success: false, error: { code: 'NOT_FOUND', message: 'x' } }; } },
    dominionClient: { async leaderboard() { return { success: true, data: { clans: [] } }; } },
    elementalMatrixClient: { async getMatrix() { return { success: true, data: null }; }, async getElementReactions() { return { success: true, data: [] }; }, async resolveCombo() { return { success: true, data: null }; } },
    lineageOathClient: {
      async retainRoute(route) { retainedRoutes.push(route); return { success: true, status: 204 }; },
      // Tarea 4.3: la ceremonia ya es una vista real que carga el canon;
      // respuesta vacía digna (renderiza el aviso de fallo sin colgar).
      async fetchOathCatalog() { return { success: false, status: 503, error: { code: 'CATALOG_UNAVAILABLE', message: 'El canon no responde.' } }; },
      async sealOath() { return { success: false, status: 400, error: { code: 'INVALID_LINEAGE', message: 'Linaje fuera del canon.' } }; },
    },
    // El simulador carga su grimorio al montar; respuesta vacía digna.
    grimoireClient: {
      async fetchSpells() { return { success: true, data: { spells: [], total: 0 } }; },
    },
  });
} catch (bootError) {
  console.log(`  [FALLA] La SPA real no pudo montarse: ${bootError.message}`);
  process.exit(1);
}

assertCondition(app !== null && typeof app.navigate === 'function', 'La SPA real monta con clientes fingidos y expone navigate()');

const { store } = app;

/** Forja un sobre de sesión para el authClient fingido. */
function forgeSession({ lineage = null, role = 'editor' } = {}) {
  sessionEnvelope = {
    success: true,
    data: {
      authenticated: true,
      user: {
        id: 'usr_peregrina',
        alias: 'Peregrina del Velo',
        role,
        clanId: null,
        clanName: '',
        lineage,
      },
    },
  };
}

// --- FASE A: Hidratación del store ---
console.log('\nFASE A: La sesión hidrata userLineage (RNF-04, decisión local)\n');
await app.boot();
await new Promise((resolve) => setTimeout(resolve, 0));

forgeSession({ lineage: null });
store.setSession(sessionEnvelope.data.user);
assertCondition(store.getState().userLineage === null && store.getState().isAuthenticated === true, 'Un usuario con lineage null hidrata userLineage=null (peregrino)');
assertCondition(store.getState().currentUser?.lineage === null, 'currentUser conserva el sobre íntegro para la vista');

store.setSession({ id: 'usr_x', alias: 'Jurada', role: 'editor', lineage: 'celestialTides' });
assertCondition(store.getState().userLineage === 'celestialTides', 'Un linajado hidrata su linaje jurado');
store.setSession({ id: 'usr_x', alias: 'Jurada', role: 'editor' });
assertCondition(store.getState().userLineage === null, 'Un sobre SIN campo lineage (cliente legado) degrada a peregrino: sin falsos linajados');

// --- FASE B: El peregrino retenido (criterio 1) ---
console.log('\nFASE B: El peregrino que pide #/creador acaba en la ceremonia\n');
forgeSession({ lineage: null }); // peregrina
retainedRoutes.length = 0;
await app.navigate('creator');
await new Promise((resolve) => setTimeout(resolve, 0));

assertCondition(retainedRoutes.includes('#/creador'), 'La ruta solicitada #/creador fue retenida vía API (RF-05.3)');
assertCondition(store.getState().currentView === 'juramento', 'La vista efectiva es la CEREMONIA: el peregrino no pisa el creador');

// Otras vistas de gestión también retienen.
retainedRoutes.length = 0;
await app.navigate('simulator');
await new Promise((resolve) => setTimeout(resolve, 0));
assertCondition(retainedRoutes.includes('#/simulador') && store.getState().currentView === 'juramento', 'Otra vista de gestión (#/simulador) también retiene y desvía');

// Lista blanca: landing y error pasan sin retención.
retainedRoutes.length = 0;
await app.navigate('landing');
await new Promise((resolve) => setTimeout(resolve, 0));
assertCondition(retainedRoutes.length === 0 && store.getState().currentView === 'landing', 'El portal de inicio está exento: sin retención (lista blanca)');

// --- FASE C: Linajado y Supremo jamás retenidos (criterios 3-4) ---
console.log('\nFASE C: Linajados y Supremo navegan sin round-trip adicional\n');
store.setSession({ id: 'usr_jurada', alias: 'Jurada de la Marea', role: 'editor', lineage: 'celestialTides' });
retainedRoutes.length = 0;
await app.navigate('creator');
await new Promise((resolve) => setTimeout(resolve, 0));
assertCondition(retainedRoutes.length === 0, 'El linajado navega SIN envío de retención: cero round-trip adicional');
assertCondition(store.getState().currentView === 'creator', 'El linajado pisa la vista solicitada');

store.setSession({ id: 'usr_supremo', alias: 'El Supremo', role: 'supremeAdmin', lineage: null });
retainedRoutes.length = 0;
await app.navigate('simulator');
await new Promise((resolve) => setTimeout(resolve, 0));
assertCondition(retainedRoutes.length === 0 && store.getState().currentView === 'simulator', 'El Supremo SIN linaje navega exento (RF-01.6)');

// --- FASE D: El retorno tras sellar (criterio 2) ---
console.log('\nFASE D: oath:sealed actualiza el store y conduce al retorno\n');
// La peregrina está en la ceremonia (o landing tras Fase B); restaura sesión peregrina.
store.setSession({ id: 'usr_peregrina', alias: 'Peregrina del Velo', role: 'editor', lineage: null });

// El veredicto del backend: sellado con ruta retenida #/creador.
retainedRoutes.length = 0;
fakeWindow.dispatchEvent({
  type: 'oath:sealed',
  detail: { lineage: 'primordialFlame', retainedRoute: '#/creador' },
});
await new Promise((resolve) => setTimeout(resolve, 0));

assertCondition(store.getState().userLineage === 'primordialFlame', 'El store porta el linaje jurado sin recarga (RF-01.7)');
assertCondition(store.getState().currentView === 'creator', 'El retorno conduce a la ruta retenida #/creador (RF-03.1)');
assertCondition(retainedRoutes.length === 0, 'Tras jurar, ninguna navegación vuelve a retener: el interceptor liberó al adepto');

// Veredicto sin ruta retenida: retorno al portal.
store.setSession({ id: 'usr_peregrina2', alias: 'Segunda Peregrina', role: 'editor', lineage: null });
fakeWindow.dispatchEvent({
  type: 'oath:sealed',
  detail: { lineage: 'abyssalShadows', retainedRoute: null },
});
await new Promise((resolve) => setTimeout(resolve, 0));
assertCondition(store.getState().currentView === 'landing' && store.getState().userLineage === 'abyssalShadows', 'Sin ruta retenida, el retorno es al portal de inicio (RF-03.1)');

// --- FASE E: La identidad del peregrino y del jurado (RF-04.3, Tarea 3.3) ---
console.log('\nFASE E: El badge declara peregrino sin heráldica y linajado con su sello\n');

/** Localiza un descendiente por atributo id (mismo contrato que el badge). */
function findBadgeById(root, elementId, found = []) {
  for (const child of root.children ?? []) {
    if (child.getAttribute?.('id') === elementId) found.push(child);
    findBadgeById(child, elementId, found);
  }
  return found;
}

/** Nodos hoja cuyo texto contiene la aguja (sin claves técnicas impresas). */
function findLeafByText(root, needle, found = []) {
  if (typeof root.textContent === 'string' && root.textContent.includes(needle) && (root.children ?? []).length === 0) {
    found.push(root);
  }
  for (const child of root.children ?? []) findLeafByText(child, needle, found);
  return found;
}

/** Descendientes que portan una clase (el sello viaja con .user-profile__seal). */
function findBadgeByClass(root, className, found = []) {
  for (const child of root.children ?? []) {
    if (child.classList?.contains?.(className)) found.push(child);
    findBadgeByClass(child, className, found);
  }
  return found;
}

// La peregrina de la Fase D (última sesión forjada) quedó en landing con su
// linaje jurado. Forjamos sesiones limpias sobre el badge VIVO de la SPA:
// el mismo badgeRoot que se montó en el orquestador.
const sessionBadgeRoot = badgeRoot;
assertCondition(sessionBadgeRoot !== null, 'El badgeRoot de la cabecera está montado en la SPA real');

if (sessionBadgeRoot !== null) {
  // 1) El peregrino: rótulo solemne, SIN heráldica y SIN clan fantasma.
  app.store.setSession({ id: 'usr_p', alias: 'Peregrina del Velo', role: 'editor', lineage: null });
  const pilgrimBadge = findBadgeById(sessionBadgeRoot, 'userProfileBadge')[0] ?? null;
  assertCondition(pilgrimBadge !== null, 'El badge del peregrino se forja con sesión activa');
  assertCondition(
    findLeafByText(sessionBadgeRoot, 'Peregrina del Velo — Peregrino sin Linaje').length === 1,
    'El rótulo visible declara «Peregrino sin Linaje» (RF-04.3)',
  );
  assertCondition(
    findBadgeByClass(sessionBadgeRoot, 'user-profile__seal').length === 0,
    'El peregrino NO porta heráldica (sin sello)',
  );
  const pilgrimToggle = findBadgeById(badgeRoot, 'userProfileToggle')[0] ?? null;
  assertCondition(
    (pilgrimToggle?.getAttribute('aria-label') ?? '').includes('Peregrino sin Linaje'),
    'El aria-label del peregrino declara su estado solemne (lectores de pantalla)',
  );

  // 2) La jurada: rótulo con nombre ceremonial y sello heráldico SPEC-07.
  app.store.setSession({ id: 'usr_j', alias: 'Jurada de la Marea', role: 'editor', lineage: 'celestialTides' });
  assertCondition(
    findLeafByText(sessionBadgeRoot, 'Jurada de la Marea — Linaje de las Mareas Celestiales').length === 1,
    'El rótulo del linajado porta el nombre ceremonial del linaje jurado',
  );
  const seals = findBadgeByClass(sessionBadgeRoot, 'user-profile__seal');
  assertCondition(seals.length === 1, 'El linajado porta EXACTAMENTE un sello heráldico (representación SPEC-07)');
  assertCondition(
    seals[0]?.getAttribute?.('data-heraldic-charge') === 'tide',
    'El sello forja la carga del linaje rector (Mareas Celestiales → tide)',
  );
  assertCondition(
    (seals[0]?.getAttribute?.('aria-label') ?? '').includes('Linaje de las Mareas Celestiales'),
    'El sello porta su etiqueta accesible ceremonial (sin clave técnica)',
  );

  // 3) Degradación digna: linaje fuera del índice local, sello con carga
  //    de respaldo y rótulo neutro, jamás clave técnica al usuario.
  app.store.setSession({ id: 'usr_x', alias: 'Viajera del Yermo', role: 'editor', lineage: 'lineajePerdido' });
  const straySeals = findBadgeByClass(badgeRoot, 'user-profile__seal');
  assertCondition(
    findLeafByText(sessionBadgeRoot, 'Viajera del Yermo — Linaje jurado').length === 1
      && straySeals.length === 1
      && straySeals[0]?.getAttribute?.('data-heraldic-charge') === 'ouroboros',
    'Un linaje fuera del índice local degrada a rótulo neutro y sello de respaldo (sin clave técnica)',
  );

  // 4) El oath:sealed repinta el badge de peregrina a jurada SIN recarga.
  app.store.setSession({ id: 'usr_p', alias: 'Peregrina del Velo', role: 'editor', lineage: null });
  assertCondition(findBadgeByClass(sessionBadgeRoot, 'user-profile__seal').length === 0, 'De vuelta a peregrina: sin sello (re-render idempotente)');
  fakeWindow.dispatchEvent({
    type: 'oath:sealed',
    detail: { lineage: 'solarCrown', retainedRoute: null },
  });
  await new Promise((resolve) => setTimeout(resolve, 0));
  const promotedSeals = findBadgeByClass(badgeRoot, 'user-profile__seal');
  assertCondition(
    promotedSeals.length === 1 && promotedSeals[0]?.getAttribute?.('data-heraldic-charge') === 'sun',
    'El oath:sealed promueve la identidad al instante: sello con la carga del Sol (RF-01.7)',
  );
}

assertCondition(uncaughtErrors === 0, `Ninguna excepción escapó sin control (${uncaughtErrors} cazadas)`);

console.log(`\n== RESUMEN == Asertos superados: ${assertsPassed}, fallidos: ${assertsFailed}`);
if (assertsFailed === 0 && uncaughtErrors === 0) {
  console.log('RESULTADO: EXITO — El interceptor retiene al peregrino con su ruta, libera a linajados y Supremo y conduce el retorno del veredicto (Tarea 3.2).');
  process.exit(0);
}

console.log('RESULTADO: FALLO — Corregir los asertos en rojo antes de continuar.');
process.exit(1);
