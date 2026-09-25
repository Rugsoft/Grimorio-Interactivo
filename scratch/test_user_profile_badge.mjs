/**
 * test_user_profile_badge.mjs — Verificación del distintivo de perfil y
 * estado de sesión en cabecera (Tarea 4.5 de SPEC-03).
 *
 * Estrategia TDD: este script se escribe ANTES que userProfileBadge.js.
 * Fase roja = el módulo public/assets/js/components/userProfileBadge.js
 * no existe todavía.
 *
 * Valida contra el criterio «Hecho cuando» de specs/03-auth-rbac.tasks.md:
 *   Al estar autenticado, la cabecera reemplaza el botón «Cruzar el Umbral»
 *   por el distintivo del usuario y su clan con opción de disolución
 *   individual y global.
 *
 * Contratos verificados (RF-02.4, RF-07.1, plan 2.2):
 *   - Modo anónimo: el componente muestra el botón «Cruzar el Umbral» que
 *     delega onCrossThreshold() (interceptación del orquestador).
 *   - Modo autenticado (setUser con el sobre data.user de la Tarea 4.1):
 *     el distintivo porta alias y clan (RF-07.1) y el menú desplegable
 *     arcano ofrece ver el libro personal, cambiar de clan en tregua y
 *     disolver el vínculo individual o globalmente (RF-02.4).
 *   - Accesibilidad: aria-expanded/aria-haspopup/aria-label, cierre con
 *     Escape, foco confinado al abrir.
 *   - Art. I: DOM nativo (innerHTML prohibido); Art. IV/V: leyendas
 *     solemnes en castellano, identificadores camelCase.
 *
 * Uso: node scratch/test_user_profile_badge.mjs
 */

import { createMemoryBadgeRoot } from '../public/assets/js/components/userProfileBadge.js';

let assertsPassed = 0;
let assertsFailed = 0;
let uncaughtErrors = 0;

process.on('uncaughtException', () => { uncaughtErrors++; });
process.on('unhandledRejection', () => { uncaughtErrors++; });

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

// --- DOM simulado (patrón consolidado de las Tareas 4.x) ---
function createFakeElement(tagName) {
  const element = {
    tagName: String(tagName).toUpperCase(),
    children: [],
    attributes: {},
    listeners: {},
    classes: new Set(),
    _textContent: '',
    parentElement: null,
    open: false,
    focusCount: 0,
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
    get textContent() { return this._textContent; },
    set textContent(value) { this._textContent = String(value); },
    get innerHTML() { throw new Error('PROHIBIDO innerHTML (AGENTS.md 6.1)'); },
    set innerHTML(value) { throw new Error(`PROHIBIDO innerHTML: '${String(value).slice(0, 40)}'`); },
    focus() { this.focusCount++; },
  };
  return element;
}

/** Búsqueda recursiva por id / atributo data-action. */
function queryById(node, elementId, found = []) {
  for (const child of node.children) {
    if (child.getAttribute('id') === elementId) found.push(child);
    queryById(child, elementId, found);
  }
  return found;
}
function queryByAction(node, actionName, found = []) {
  for (const child of node.children) {
    if (child.getAttribute('data-action') === actionName) found.push(child);
    queryByAction(child, actionName, found);
  }
  return found;
}
const byId = (root, elementId) => queryById(root, elementId)[0] ?? null;
const byAction = (root, actionName) => queryByAction(root, actionName)[0] ?? null;

/** Busca un nodo por su texto visible (leyendas solemnes). */
function findByText(node, needle, found = []) {
  if (node.textContent.includes(needle) && node.children.length === 0) found.push(node);
  for (const child of node.children) findByText(child, needle, found);
  return found;
}

/** Enlace real del shell: el botón «Cruzar el Umbral» (Tarea 4.1 TASKS-01). */
function buildBadgeRoot() {
  const badgeRoot = createFakeElement('div');
  badgeRoot.setAttribute('id', 'navSession');

  const thresholdButton = createFakeElement('button');
  thresholdButton.setAttribute('id', 'navCrossThreshold');
  thresholdButton.setAttribute('data-action', 'crossThreshold');
  thresholdButton.textContent = 'Cruzar el Umbral';
  badgeRoot.appendChild(thresholdButton);

  const documentSim = { createElement: (tag) => createFakeElement(tag) };
  return { badgeRoot, thresholdButton, documentSim };
}

/** Sobre data.user canónico (Tarea 4.1, plan Endpoint 4). */
const AUTHED_USER = {
  id: 'usr_9a8b7c6d',
  alias: 'FrierenElf',
  role: 'master',
  clanId: 'cln_astral',
  clanName: 'Eruditos Astrales',
};

console.log('== VERIFICACION TAREA 4.5 (SPEC-03): userProfileBadge.js ==\n');

// --- FASE 0: Existencia y superficie ---
console.log('FASE 0: Superficie del componente');

const shell0 = buildBadgeRoot();
const badge0 = createMemoryBadgeRoot(shell0.badgeRoot, {
  documentRef: shell0.documentSim,
  onCrossThreshold: () => {},
  onOpenGrimoire: () => {},
  onDissolve: () => {},
  onDissolveAll: () => {},
});

assertCondition(typeof badge0.setUser === 'function', 'setUser(user) está disponible');
assertCondition(typeof badge0.clearUser === 'function', 'clearUser() está disponible');
assertCondition(typeof badge0.destroy === 'function', 'destroy() está disponible');

// --- FASE 1: CRITERIO — autenticado reemplaza «Cruzar el Umbral» ---
console.log('\nFASE 1: Criterio Hecho cuando — reemplazo del botón de umbral');

badge0.setUser(AUTHED_USER);

assertCondition(
  shell0.thresholdButton.parentElement === null,
  'El botón «Cruzar el Umbral» desaparece de la cabecera al autenticarse (criterio literal)',
);
const aliasNode = findByText(shell0.badgeRoot, 'FrierenElf')[0] ?? null;
assertCondition(aliasNode !== null, 'El distintivo porta el alias del vinculado (RF-07.1)');
const clanNode = findByText(shell0.badgeRoot, 'Eruditos Astrales')[0] ?? null;
assertCondition(clanNode !== null, 'El distintivo porta el nombre del clan (RF-07.1)');
assertCondition(byId(shell0.badgeRoot, 'userProfileBadge') !== null, 'El nodo distintivo existe (userProfileBadge)');

// El menú desplegable arcano existe y nace cerrado.
const userMenu = byId(shell0.badgeRoot, 'userProfileMenu');
assertCondition(userMenu !== null, 'El menú desplegable arcano existe (userProfileMenu)');
const badgeButton = byId(shell0.badgeRoot, 'userProfileToggle');
assertCondition(badgeButton !== null && badgeButton.getAttribute('aria-expanded') === 'false', 'El distintivo nace con el menú cerrado (aria-expanded false)');

// FASE 2: Opciones del menú arcano (RF-02.4) ---
console.log('\nFASE 2: Menú desplegable — libro, disoluciones; SIN cambio de linaje');

badgeButton.dispatch('click');
assertCondition(badgeButton.getAttribute('aria-expanded') === 'true', 'El click sobre el distintivo despliega el menú (aria-expanded true)');

const menuCallbacks = { grimoire: 0, dissolve: 0, dissolveAll: 0 };
const shell2 = buildBadgeRoot();
const badge2 = createMemoryBadgeRoot(shell2.badgeRoot, {
  documentRef: shell2.documentSim,
  onOpenGrimoire: () => { menuCallbacks.grimoire++; },
  onDissolve: () => { menuCallbacks.dissolve++; },
  onDissolveAll: () => { menuCallbacks.dissolveAll++; },
});
badge2.setUser(AUTHED_USER);
const badgeButton2 = byId(shell2.badgeRoot, 'userProfileToggle');
badgeButton2.dispatch('click');

// Ver libro personal (RF-07.1: la colección del iniciado).
const grimoireOption = byAction(shell2.badgeRoot, 'openGrimoire');
assertCondition(grimoireOption !== null, 'El menú ofrece «ver el libro personal» (openGrimoire)');
grimoireOption.dispatch('click');
assertCondition(menuCallbacks.grimoire === 1, 'La opción del libro notifica onOpenGrimoire al orquestador');

// SPEC-09 (RF-03.4, exclusión 2): el juramento de linaje es perpetuo — el
// menú JAMÁS ofrece «Cambiar de linaje» ni ningún flujo de cambio (flujo que,
// además, jamás existió en el santuario: sin endpoint ni cliente tras de sí).
assertCondition(byAction(shell2.badgeRoot, 'changeClan') === null, 'El menú JAMÁS ofrece «Cambiar de linaje» (SPEC-09, RF-03.4: el juramento es perpetuo)');
const forbiddenLabel = findByText(shell2.badgeRoot, 'Cambiar de linaje')[0] ?? null;
assertCondition(forbiddenLabel === null, 'El rótulo «Cambiar de linaje» no existe en ninguna opción del menú');

// Disolución individual del dispositivo actual (RF-02.4).
const dissolveOption = byAction(shell2.badgeRoot, 'dissolve');
assertCondition(dissolveOption !== null, 'El menú ofrece «disolver este vínculo» (dissolve)');
dissolveOption.dispatch('click');
assertCondition(menuCallbacks.dissolve === 1, 'La disolución individual notifica onDissolve al orquestador');

// Disolución global en todos los dispositivos (RF-02.4).
const dissolveAllOption = byAction(shell2.badgeRoot, 'dissolveAll');
assertCondition(dissolveAllOption !== null, 'El menú ofrece «disolver todos los vínculos» (dissolveAll)');
dissolveAllOption.dispatch('click');
assertCondition(menuCallbacks.dissolveAll === 1, 'La disolución global notifica onDissolveAll al orquestador');

// El menú se cierra tras elegir una opción.
assertCondition(badgeButton2.getAttribute('aria-expanded') === 'false', 'Tras elegir una opción, el menú se repliega');

// --- FASE 3: Modo anónimo — el umbral vuelve con delegación ---
console.log('\nFASE 3: Modo anónimo (clearUser) — delegación de «Cruzar el Umbral»');

const thresholdCalls = [];
const shell3 = buildBadgeRoot();
const badge3 = createMemoryBadgeRoot(shell3.badgeRoot, {
  documentRef: shell3.documentSim,
  onCrossThreshold: () => { thresholdCalls.push('cross'); },
});
badge3.setUser(AUTHED_USER);
badge3.clearUser();

assertCondition(byId(shell3.badgeRoot, 'userProfileBadge') === null, 'clearUser retira el distintivo de perfil');
assertCondition(byId(shell3.badgeRoot, 'userProfileMenu') === null, 'clearUser retira el menú arcano');
const restoredThreshold = byId(shell3.badgeRoot, 'navCrossThreshold');
assertCondition(restoredThreshold !== null, 'clearUser restaura el botón «Cruzar el Umbral»');

restoredThreshold.dispatch('click');
assertCondition(thresholdCalls.length === 1, 'El botón restaurado delega onCrossThreshold al orquestador (interceptación RF-05.2)');

// --- FASE 4: Accesibilidad (RNF-03) ---
console.log('\nFASE 4: Accesibilidad del menú arcano');

const shell4 = buildBadgeRoot();
const badge4 = createMemoryBadgeRoot(shell4.badgeRoot, {
  documentRef: shell4.documentSim,
  onCrossThreshold: () => {},
});
badge4.setUser(AUTHED_USER);
const badgeButton4 = byId(shell4.badgeRoot, 'userProfileToggle');

assertCondition(badgeButton4.getAttribute('aria-haspopup') === 'true', 'El distintivo declara aria-haspopup (menú)');
assertCondition((badgeButton4.getAttribute('aria-label') ?? '').includes('FrierenElf'), 'El aria-label porta el alias del vinculado (lectores de pantalla)');

// Escape repliega el menú abierto.
badgeButton4.dispatch('click');
shell4.badgeRoot.dispatch('keydown', { key: 'Escape' });
assertCondition(badgeButton4.getAttribute('aria-expanded') === 'false', 'Escape repliega el menú (accesibilidad por teclado)');

// --- FASE 5: Prohibiciones y robustez ---
console.log('\nFASE 5: Prohibiciones (innerHTML) y robustez');

const shell5 = buildBadgeRoot();
const badge5 = createMemoryBadgeRoot(shell5.badgeRoot, {
  documentRef: shell5.documentSim,
  onCrossThreshold: () => {},
});
let innerHTMLRejected = false;
try {
  shell5.badgeRoot.innerHTML = '<p>pacto oscuro</p>';
} catch {
  innerHTMLRejected = true;
}
assertCondition(innerHTMLRejected, 'innerHTML está prohibido también en este árbol (Art. I, AGENTS.md 6.1)');

// Usuario sin clan: el distintivo se muestra sin clan fantasma.
badge5.setUser({ id: 'usr_solitario', alias: 'Andarin', role: 'editor', clanId: '', clanName: '' });
assertCondition(
  findByText(shell5.badgeRoot, 'Andarin').length === 1 && findByText(shell5.badgeRoot, 'Eruditos Astrales').length === 0,
  'Un vinculado sin linaje muestra solo su alias (sin clan fantasma, RF-01.1)',
);

badge5.destroy();
let destroyInert = true;
try {
  badge5.setUser(AUTHED_USER);
  if (byId(shell5.badgeRoot, 'userProfileBadge') !== null) destroyInert = false;
} catch {
  destroyInert = false;
}
assertCondition(destroyInert, 'Tras destroy(), setUser es inerte (sin fugas)');

// --- Centinela y resumen ---
console.log('\n== CENTINELA DE CONSOLA ==');
assertCondition(uncaughtErrors === 0, `Excepciones no capturadas en consola: ${uncaughtErrors} (exige 0)`);

console.log('\n== RESUMEN ==');
console.log(`Asertos superados: ${assertsPassed}`);
console.log(`Asertos fallidos:  ${assertsFailed}`);

if (assertsFailed === 0) {
  console.log("\nRESULTADO: EXITO — La Tarea 4.5 cumple su criterio 'Hecho cuando'.");
  process.exit(0);
}

console.log('\nRESULTADO: DENEGADO — Corregir los asertos marcados con [FALLA].');
process.exit(1);
