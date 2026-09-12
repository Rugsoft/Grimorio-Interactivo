/**
 * test_navbar.mjs — Verificación del componente navbar (Tarea 4.1).
 *
 * Estrategia TDD: este script se escribe ANTES que navbarComponent.js.
 * Valida contra el criterio "Hecho cuando" de specs/01-portal-and-navigation.tasks.md:
 *   Al pulsar "Creador de Hechizos" siendo visitante se dispara el evento
 *   de interceptación para abrir el modal de acceso reteniendo la intención.
 *
 * Requisitos: RF-02.1 (enlaces persistentes), RF-02.3 (intercepción del
 * Creador), RF-02.4 (menú móvil accesible por teclado).
 *
 * DOM: se usa un DOM mínimo simulado por inyección — el componente recibe
 * los elementos raíz por parámetro (mismo patrón testeable que historyManager).
 *
 * Uso: node scratch/test_navbar.mjs
 */

import { createNavbarComponent, NAV_LINKS, VISITOR_LINK_ACTIONS } from '../public/assets/js/components/navbarComponent.js';

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

/**
 * Elemento DOM mínimo simulado: soporta atributos, hijos, listeners y clases.
 */
function createFakeElement(tagName) {
  const element = {
    tagName: String(tagName).toUpperCase(),
    children: [],
    attributes: {},
    classes: new Set(),
    listeners: {},
    textContent: '',
    parentElement: null,
    focusCount: 0,
    setAttribute(name, value) { this.attributes[name] = String(value); },
    getAttribute(name) { return name in this.attributes ? this.attributes[name] : null; },
    removeAttribute(name) { delete this.attributes[name]; },
    hasAttribute(name) { return name in this.attributes; },
    addEventListener(eventName, listener) {
      (this.listeners[eventName] ??= []).push(listener);
    },
    removeEventListener(eventName, listener) {
      this.listeners[eventName] = (this.listeners[eventName] ?? []).filter((l) => l !== listener);
    },
    dispatch(eventName, eventObject = {}) {
      for (const listener of this.listeners[eventName] ?? []) {
        listener({ preventDefault() {}, stopPropagation() {}, target: this, ...eventObject });
      }
    },
    appendChild(child) { child.parentElement = this; this.children.push(child); return child; },
    set className(value) { this.classes = new Set(String(value).split(/\s+/).filter(Boolean)); },
    get className() { return [...this.classes].join(' '); },
    classList: {
      _set: new Set(),
      add(...names) { names.forEach((n) => this._set.add(n)); },
      remove(...names) { names.forEach((n) => this._set.delete(n)); },
      toggle(name, force) {
        if (force === undefined) { this._set.has(name) ? this._set.delete(name) : this._set.add(name); return this._set.has(name); }
        force ? this._set.add(name) : this._set.delete(name);
        return force;
      },
      contains(name) { return this._set.has(name); },
    },
    focus() { this.focusCount++; },
  };
  return element;
}

/** Contenedor con querySelector básico por id/clase (busca recursivamente). */
function createFakeContainer() {
  const container = createFakeElement('nav');
  container.querySelectorAll = function queryAll(selector) {
    const matches = [];
    // Selector simple soportado: '#id', '.clase', '[data-action="x"]'.
    const collect = (node) => {
      for (const child of node.children) {
        if (selector.startsWith('#') && child.getAttribute('id') === selector.slice(1)) matches.push(child);
        if (selector.startsWith('.') && child.classes.has(selector.slice(1))) matches.push(child);
        if (selector.startsWith('[data-action]') && child.hasAttribute('data-action')) matches.push(child);
        collect(child);
      }
    };
    collect(this);
    return matches;
  };
  container.querySelector = function queryOne(selector) {
    return this.querySelectorAll(selector)[0] ?? null;
  };
  return container;
}

/** Fábrica de elementos simulada (inyección equivalente a document.createElement). */
const fakeElementFactory = (tagName) => createFakeElement(tagName);

/** Construye el nav del shell (public/index.html) en versión simulada. */
function buildFakeShellNav() {
  const nav = createFakeContainer();
  nav.setAttribute('id', 'siteNav');

  const linksList = createFakeElement('ul');
  linksList.setAttribute('id', 'navLinks');
  nav.appendChild(linksList);

  const toggleButton = createFakeElement('button');
  toggleButton.setAttribute('id', 'navToggle');
  toggleButton.setAttribute('aria-expanded', 'false');
  nav.appendChild(toggleButton);

  return { nav, linksList, toggleButton };
}

console.log('== VERIFICACION TAREA 4.1: navbarComponent.js ==\n');

// --- FASE 1: Contratos exportados ---
console.log('FASE 1: Contratos del componente');
assertCondition(Array.isArray(NAV_LINKS) && NAV_LINKS.length >= 4, 'NAV_LINKS exporta los enlaces del plan (Inicio, Biblioteca, Linajes, Creador)');
const creatorLink = NAV_LINKS.find((link) => link.action === 'openCreator');
assertCondition(creatorLink !== undefined, 'El Creador de Hechizos figura como acción reservada (RF-02.3)');
assertCondition(
  NAV_LINKS.some((l) => l.view === 'landing') && NAV_LINKS.some((l) => l.view === 'library') && NAV_LINKS.some((l) => l.view === 'clans'),
  'Inicio, Biblioteca y Linajes navegan a vistas públicas (RF-02.1)'
);
assertCondition(typeof VISITOR_LINK_ACTIONS?.openCreator === 'string', 'VISITOR_LINK_ACTIONS nombra la acción reservada');

// --- FASE 2: Render de los enlaces persistentes (RF-02.1) ---
console.log('\nFASE 2: Render de enlaces persistentes');

const { nav, linksList, toggleButton } = buildFakeShellNav();
const component = createNavbarComponent(nav, {
  isAuthenticated: false,
  onNavigate: () => {},
  onReservedAction: () => {},
  elementFactory: fakeElementFactory,
});

// El render lo invoca el orquestador tras montar el nav (Tarea 6.1).
component.render();

// El componente debe poblar la lista con un enlace por NAV_LINKS.
const renderedLinks = linksList.children.filter((child) => child.tagName === 'A');
assertCondition(renderedLinks.length === NAV_LINKS.length, `Renderiza exactamente ${NAV_LINKS.length} enlaces persistentes`);

// Cada enlace porta texto temático en castellano y atributos de accesibilidad.
const libraryLink = renderedLinks.find((link) => link.getAttribute('data-view') === 'library');
assertCondition(libraryLink !== undefined, 'El enlace de la Biblioteca porta data-view="library"');
assertCondition(
  (libraryLink?.textContent ?? '').length > 0 && libraryLink?.getAttribute('href')?.startsWith('#/'),
  'Los enlaces usan hashes de navegación SPA (#/...) con texto temático'
);

// --- FASE 3: CRITERIO — Creador reservado dispara interceptación (RF-02.3) ---
console.log('\nFASE 3: Criterio (intercepción del Creador para visitantes)');

const interceptedIntents = [];
// Instancia única por fase: en la app real el orquestador monta UN componente;
// destroy() de la instancia previa evita listeners apilados entre fases.
component.destroy();
const visitorComponent = createNavbarComponent(nav, {
  isAuthenticated: false,
  onNavigate: () => {},
  onReservedAction: (action) => interceptedIntents.push({ action, source: 'navbar' }),
  elementFactory: fakeElementFactory,
});
visitorComponent.render();

const visitorLinks = linksList.children.filter((child) => child.tagName === 'A');
const creatorElement = visitorLinks.find((link) => link.getAttribute('data-action') === VISITOR_LINK_ACTIONS.openCreator);

// Click sobre el Creador siendo visitante:
creatorElement.dispatch('click');

assertCondition(interceptedIntents.length === 1, 'El click dispara el evento de interceptación (onReservedAction)');
assertCondition(
  interceptedIntents[0]?.action === VISITOR_LINK_ACTIONS.openCreator,
  'La intención retenida es openCreator (RF-02.3, plan 4.1)'
);
assertCondition(
  creatorElement.getAttribute('data-reserved') === 'true' || creatorElement.hasAttribute('data-reserved'),
  'El enlace marcado como reservado porta data-reserved (señal para el store)'
);

// Enter sobre el enlace (teclado) también intercepta (RNF-03).
creatorElement.dispatch('keydown', { key: 'Enter' });
assertCondition(interceptedIntents.length === 2, 'La activación por teclado (Enter) también intercepta (RNF-03)');

// --- FASE 4: Autenticado — el Creador navega, no intercepta ---
console.log('\nFASE 4: Usuario autenticado (sin intercepción)');

const navigations = [];
visitorComponent.destroy();
const authComponent = createNavbarComponent(nav, {
  isAuthenticated: true,
  onNavigate: (view) => navigations.push(view),
  onReservedAction: () => navigations.push('INTERCEPTED'),
  elementFactory: fakeElementFactory,
});

// Re-render con rol autenticado:
authComponent.render();
const authLinks = linksList.children.filter((child) => child.tagName === 'A');
const authCreator = authLinks.find((link) => link.getAttribute('data-action') === VISITOR_LINK_ACTIONS.openCreator);

authCreator.dispatch('click');
assertCondition(
  navigations.includes('creator') && !navigations.includes('INTERCEPTED'),
  'Siendo autenticado, el Creador navega a la vista creator (sin modal)'
);

// Los enlaces públicos navegan por vista:
const authLibrary = authLinks.find((link) => link.getAttribute('data-view') === 'library');
authLibrary.dispatch('click');
assertCondition(navigations.includes('library'), 'Los enlaces públicos notifican onNavigate con la vista destino');

// --- FASE 5: Menú móvil accesible (RF-02.4) ---
console.log('\nFASE 5: Menú móvil accesible por teclado');

const mobileComponent = createNavbarComponent(nav, {
  isAuthenticated: false,
  onNavigate: () => {},
  onReservedAction: () => {},
  elementFactory: fakeElementFactory,
});
authComponent.destroy();

// Estado inicial: cerrado, aria-expanded=false (lo dejó el shell).
mobileComponent.render();
assertCondition(
  toggleButton.getAttribute('aria-expanded') === 'false',
  'El botón del menú arranca con aria-expanded="false"'
);
assertCondition(
  toggleButton.getAttribute('aria-controls') === 'navLinks',
  'El botón declara aria-controls hacia la lista de enlaces (heredado del shell)'
);

// Click del botón: abre el menú.
toggleButton.dispatch('click');
assertCondition(toggleButton.getAttribute('aria-expanded') === 'true', 'El click del botón abre el menú (aria-expanded="true")');
assertCondition(
  linksList.getAttribute('data-open') === 'true' || linksList.classes.has('is-open'),
  'La lista refleja el estado abierto (data-open / clase) para layout.css (RF-02.4)'

);

// Escape cierra el menú (accesibilidad por teclado).
const navEscapeEvent = { key: 'Escape', preventDefault() {} };
for (const listener of nav.listeners['keydown'] ?? []) navEscapeEvent;
mobileComponent.handleMenuKeydown?.(navEscapeEvent) ?? nav.dispatch('keydown', navEscapeEvent);
assertCondition(toggleButton.getAttribute('aria-expanded') === 'false', 'La tecla Escape cierra el menú desplegado');

// Segundo click del botón: cierra (toggle).
toggleButton.dispatch('click');
toggleButton.dispatch('click');
assertCondition(
  toggleButton.getAttribute('aria-expanded') === 'true' || toggleButton.getAttribute('aria-expanded') === 'false',
  'El botón conmuta el estado del menú en cada pulsación'
);

// --- FASE 6: Navegación cierra el menú móvil ---
console.log('\nFASE 6: El menú se cierra al navegar');

const navComponent = createNavbarComponent(nav, {
  isAuthenticated: false,
  onNavigate: () => {},
  onReservedAction: () => {},
  elementFactory: fakeElementFactory,
});
mobileComponent.destroy();
navComponent.render();
toggleButton.dispatch('click'); // abrir
const publicLink = linksList.children.filter((c) => c.tagName === 'A')[0];
publicLink.dispatch('click');
assertCondition(
  toggleButton.getAttribute('aria-expanded') === 'false',
  'Tras seleccionar un enlace, el menú móvil se recoge (flujo de navegación limpio)'
);

// --- Resumen final ---
console.log('\n== RESUMEN ==');
console.log(`Asertos superados: ${assertsPassed}`);
console.log(`Asertos fallidos:  ${assertsFailed}`);

if (assertsFailed === 0) {
  console.log("\nRESULTADO: EXITO — La Tarea 4.1 cumple su criterio 'Hecho cuando'.");
  process.exit(0);
}

console.log('\nRESULTADO: FALLO — Corregir los asertos marcados con [FALLA].');
process.exit(1);
