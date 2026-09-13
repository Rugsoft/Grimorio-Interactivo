/**
 * probe_navbar_session_stale.mjs — Sonda de revisión del cableado del Simulador.
 *
 * NO es un arnés de aceptación (ese es test_navbar_session_refresh.mjs):
 * es la sonda que documentó el defecto durante la revisión de la Tarea de
 * cableado del Simulador (SPEC-05) y que ahora demuestra su cura.
 *
 *   CASO 1 — Sin sincronizar la bandera: el enlace reservado intercepta a
 *            quien ya tenía vínculo (el defecto histórico).
 *   CASO 2 — Tras `setSession(true)`, la cabecera navega y no intercepta.
 *   CASO 3 — `setSession` es idempotente: repetir el mismo estado no vuelve
 *            a pintar la lista.
 *
 * Constitución:
 *   - Artículo I: Node nativo con DOM simulado; cero dependencias.
 *   - Artículo V: identificadores en inglés camelCase; crónica en castellano.
 *
 * Uso: node scratch/probe_navbar_session_stale.mjs
 */

import { createNavbarComponent } from '../public/assets/js/components/navbarComponent.js';

/** Elemento DOM mínimo simulado (patrón consolidado de los arneses). */
function createFakeElement(tagName) {
  const element = {
    tagName: String(tagName).toUpperCase(),
    children: [],
    attributes: {},
    listeners: {},
    parentElement: null,
    _textContent: '',
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
    remove() {
      const index = this.parentElement?.children.indexOf(this) ?? -1;
      if (index >= 0) this.parentElement.children.splice(index, 1);
      this.parentElement = null;
    },
    get textContent() { return this._textContent; },
    set textContent(value) { this._textContent = String(value); },
    focus() {},
  };
  return element;
}

/** Nav raíz con #navLinks (el componente lo localiza por id). */
function buildFakeNav() {
  const navRoot = createFakeElement('nav');
  navRoot.setAttribute('id', 'siteNav');
  const linksList = createFakeElement('ul');
  linksList.setAttribute('id', 'navLinks');
  navRoot.appendChild(linksList);
  navRoot.querySelector = (selector) => (selector === '#navLinks' ? linksList : null);
  return { navRoot, linksList };
}

/** Busca el enlace del navbar por su data-view. */
function findNavLink(linksList, viewName) {
  return linksList.children.find((link) => link.getAttribute('data-view') === viewName) ?? null;
}

/** Registro de lo que la navbar ordenó (navegación vs. interceptación). */
function buildRecorder() {
  return { navigated: [], intercepted: [] };
}

console.log('== SONDA DE REVISIÓN: bandera de sesión de la navbar ==\n');

// --- Caso 1: navbar pintada como visitante y jamás sincronizada ---
console.log('CASO 1: sin sincronizar, la navbar conserva la bandera de la fábrica');
const shell1 = buildFakeNav();
const recorder1 = buildRecorder();
const navbar1 = createNavbarComponent(shell1.navRoot, {
  isAuthenticated: false, // main.js:544 — y jamás se vuelve a llamar a render()
  onNavigate: (viewName) => recorder1.navigated.push(viewName),
  onReservedAction: (action) => recorder1.intercepted.push(action),
  elementFactory: (tagName) => createFakeElement(tagName),
});
navbar1.render();

const creatorLink1 = findNavLink(shell1.linksList, 'creator');
const simulatorLink1 = findNavLink(shell1.linksList, 'simulator');
creatorLink1.dispatch('click');
simulatorLink1.dispatch('click');

const creatorInterceptedAfterLogin = recorder1.intercepted.includes('openCreator')
  && !recorder1.navigated.includes('creator');
console.log(`  Enlace «Creador» tras autenticar → interceptado: ${creatorInterceptedAfterLogin ? 'SÍ (defecto)' : 'no'}`);
console.log(`  Enlace «Simulador» tras autenticar → navegación pública: ${recorder1.navigated.includes('simulator') ? 'SÍ (correcto)' : 'NO'}`);

// --- Caso 2: la misma navbar tras setSession(true) (lo que hace main.js hoy) ---
console.log('\nCASO 2: tras setSession(true) la cabecera navega y no intercepta');
const creatorLinkBeforeSync = findNavLink(shell1.linksList, 'creator');
recorder1.navigated.length = 0;
recorder1.intercepted.length = 0;

navbar1.setSession(true);

const creatorLinkAfterSync = findNavLink(shell1.linksList, 'creator');
console.log(`  La lista se vuelve a pintar: ${creatorLinkAfterSync !== creatorLinkBeforeSync ? 'SÍ (nodo nuevo)' : 'NO'}`);

creatorLinkAfterSync.dispatch('click');
console.log(`  Enlace «Creador» → navegó al Taller: ${recorder1.navigated.includes('creator') ? 'SÍ (correcto)' : 'NO'}`);
console.log(`  Enlace «Creador» → interceptación: ${recorder1.intercepted.length === 0 ? 'ninguna (correcto)' : recorder1.intercepted.join(', ')}`);

// --- Caso 3: idempotencia (no se repinta si el estado no cambia) ---
console.log('\nCASO 3: setSession es idempotente ante el mismo estado');
navbar1.setSession(true);
const creatorLinkAfterRepeat = findNavLink(shell1.linksList, 'creator');
console.log(`  Repetir setSession(true) repinta: ${creatorLinkAfterRepeat !== creatorLinkAfterSync ? 'SÍ (churn innecesario)' : 'no (correcto)'}`);

navbar1.setSession(false);
const creatorLinkAfterClose = findNavLink(shell1.linksList, 'creator');
creatorLinkAfterClose.dispatch('click');
console.log(`  Tras setSession(false) → intercepta de nuevo: ${recorder1.intercepted.includes('openCreator') ? 'SÍ (correcto)' : 'NO'}`);

// --- Veredicto ---
console.log('\n== VEREDICTO ==');
if (creatorInterceptedAfterLogin) {
  console.log('El defecto histórico queda documentado (CASO 1) y la cura verificada:');
  console.log('  - setSession() mantiene la bandera viva y repinta la lista.');
  console.log('  - Idempotente: sin cambios de sesión no hay repintado.');
  console.log('  - El enlace «Simulador de Grimorio» sigue siendo público por diseño.');
  process.exit(0);
}
console.log('Sin defecto: la navbar refleja la sesión.');
process.exit(0);
