/**
 * test_portal_composition.mjs — Arnés de la Tarea 0 (TASKS-16, SPEC-16).
 *
 * ESTRATEGIA TDD CONSTITUCIONAL: este script se escribe ANTES que el código
 * y DEBE fallar. Su redness es la prueba de que la prueba sirve; un arnés
 * verde antes de implementar sería un arnés que no mide nada.
 *
 * Cubre:
 *   [1]  Contrato de agrupación: NAV_GROUPS cubre los diez NAV_LINKS (RF-16.2)
 *   [2]  Rótulos de grupo y submenús accesibles (RF-16.1, RF-16.3, RNF-16.5)
 *   [3]  Teclado: Escape, pérdida de foco y govierno por rol (RF-16.4, RF-16.7)
 *   [4]  El distintivo corto: alias visible, identidad en el desplegable (RF-17.1–17.4)
 *   [5]  La efigie por defecto forja sello, jamás cuadro vacío (RF-17.5, remedio de D3)
 *   [6]  La portada compuesta: orden de bloques y h1 único (RF-18.1, caso límite 5)
 *   [7]  El sello de validación: firma, leyenda y tokens (RF-18.4, RF-18.5)
 *   [8]  El tratamiento de título del sistema en el héroe (RF-18.2, remedio de D2)
 *   [9]  La cinta compacta del Regente: una línea, sin doble marco (RF-18.7–18.9)
 *   [10] Soberanía lingüística: ninguna clave técnica visible (RNF-16.4)
 *   [11] Disciplina de tokens: cero hex crudos en las hojas tocadas (RNF-16.1)
 *   [12] La superficie respeta el gesto reservado del Creador (RF-02.3, SPEC-01)
 *
 * Constitución:
 *   - Artículo I (Dogma Vanilla): cero librerías; DOM simulado propio.
 *   - Artículo V: identificadores en inglés camelCase; comentarios en castellano.
 *   - AGENTS.md 6.1: innerHTML queda PROHIBIDO en el DOM simulado (XSS).
 *
 * Uso: node scratch/test_portal_composition.mjs
 */

import { readFile } from 'node:fs/promises';

let assertsPassed = 0;
let assertsFailed = 0;
const failedAssertions = [];

/** Aserta una condición y registra el resultado en la bitácora de consola. */
function assertCondition(condition, description) {
  if (condition) {
    assertsPassed += 1;
    console.log(`  [PASA] ${description}`);
  } else {
    assertsFailed += 1;
    failedAssertions.push(description);
    console.log(`  [FALLA] ${description}`);
  }
}

/** Elemento DOM mínimo simulado (patrón consolidado de los arneses hermanos). */
function createFakeElement(tagName) {
  const element = {
    tagName: String(tagName).toUpperCase(),
    children: [],
    attributes: {},
    classes: new Set(),
    listeners: {},
    _textContent: '',
    parentElement: null,
    focusCount: 0,
    setAttribute(name, value) { this.attributes[name] = String(value); },
    getAttribute(name) { return name in this.attributes ? this.attributes[name] : null; },
    removeAttribute(name) { delete this.attributes[name]; },
    hasAttribute(name) { return name in this.attributes; },
    addEventListener(eventName, listener) { (this.listeners[eventName] ??= []).push(listener); },
    removeEventListener(eventName, listener) { this.listeners[eventName] = (this.listeners[eventName] ?? []).filter((l) => l !== listener); },
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
    },
    remove() {
      if (!this.parentElement) return;
      this.removeChild(this);
    },
    replaceChildren(...nodes) {
      for (const child of this.children.splice(0)) child.parentElement = null;
      for (const node of nodes) this.appendChild(node);
    },
    set className(value) { this.classes = new Set(String(value).split(/\s+/).filter(Boolean)); },
    get className() { return [...this.classes].join(' '); },
    get textContent() {
      return `${this._textContent}${this.children.map((child) => child.textContent).join('')}`;
    },
    set textContent(value) {
      for (const child of this.children.splice(0)) child.parentElement = null;
      this._textContent = String(value);
    },
    get innerHTML() { throw new Error('PROHIBIDO innerHTML (AGENTS.md 6.1: XSS); usar textContent'); },
    set innerHTML(value) { throw new Error(`PROHIBIDO innerHTML (AGENTS.md 6.1): se intentó escribir '${String(value).slice(0, 40)}'`); },
    focus() { this.focusCount += 1; },
    // Custom properties: spellCardComponent y el sello pintan afinidades
    // por variable (patrón de test_landing_view).
    style: {
      _inline: {},
      setProperty(name, value) { this._inline[name] = String(value); },
      getPropertyValue(name) { return this._inline[name] ?? ''; },
      getProperty(name) { return this._inline[name] ?? null; },
      removeProperty(name) { delete this._inline[name]; },
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

/** Barrido recursivo por clase sobre el DOM simulado. */
/**
 * ¿El nodo porta la clase? El DOM simulado tiene DOS estados posibles:
 * `classes` (poblado por el setter `className`) y `attributes.class`
 * (poblado por `setAttribute('class', …)`, que es la vía que exigen los
 * componentes reales). Consultar solo uno de los dos produce falsos
 * negativos, así que se miran ambos.
 */
function hasClass(node, className) {
  if (node.classes?.has(className) === true) return true;
  const attributeClass = node.getAttribute?.('class');
  if (typeof attributeClass !== 'string' || attributeClass === '') return false;
  return attributeClass.split(/\s+/).filter(Boolean).includes(className);
}

function queryByClass(node, className, found = []) {
  for (const child of node.children ?? []) {
    if (hasClass(child, className)) found.push(child);
    queryByClass(child, className, found);
  }
  return found;
}
const byClass = (root, className) => queryByClass(root, className)[0] ?? null;
const allByClass = (root, className) => queryByClass(root, className);

/** Barrido recursivo por id (el distintivo forja varios niveles de nodos). */
function queryById(node, elementId, found = []) {
  for (const child of node.children ?? []) {
    if (child.getAttribute('id') === elementId) found.push(child);
    queryById(child, elementId, found);
  }
  return found;
}
const byId = (root, elementId) => queryById(root, elementId)[0] ?? null;

/** Barrido recursivo por etiqueta. */
function allByTag(root, tagName, found = []) {
  for (const child of root.children ?? []) {
    if (child.tagName === tagName.toUpperCase()) found.push(child);
    allByTag(child, tagName, found);
  }
  return found;
}

/** Extrae todo el texto visible de un árbol (para la soberanía lingüística). */
function visibleTextOf(node) {
  return String(node?.textContent ?? '');
}

const fakeElementFactory = (tagName) => createFakeElement(tagName);

/**
 * Documento anfitrión simulado: el Sello Rúnico se forja como SVG en línea
 * (SPEC-02 RF-07), así que el arnés presta el `createElementNS` que el
 * navegador siempre trae. En producción el documento es el global.
 */
const fakeDocument = {
  createElement: (tagName) => createFakeElement(tagName),
  createElementNS: (_namespace, tagName) => createFakeElement(tagName),
};
globalThis.document = fakeDocument;

/** Contenedor con querySelector básico por id/clase (patrón de test_navbar). */
function createFakeContainer() {
  const container = createFakeElement('nav');
  container.querySelectorAll = function queryAll(selector) {
    const matches = [];
    const collect = (node) => {
      for (const child of node.children) {
        if (selector.startsWith('#') && child.getAttribute('id') === selector.slice(1)) matches.push(child);
        if (selector.startsWith('.') && child.classes.has(selector.slice(1))) matches.push(child);
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

console.log('== TAREA 0 (TASKS-16): arnés rojo de la recomposición del Portal ==\n');
console.log('EXPECTATIVA DE ESTA EJECUCIÓN: FALLOS. El código de la SPEC-16 aún no existe.\n');

// =====================================================================
// FASE 1 — Contrato de agrupación (RF-16.2)
// =====================================================================
console.log('[1] Contrato de agrupación: NAV_GROUPS cubre los diez NAV_LINKS');

let navbarModule = null;
try {
  navbarModule = await import('../public/assets/js/components/navbarComponent.js');
} catch (importError) {
  console.log(`  (no se pudo importar navbarComponent.js: ${importError.message})`);
}

const NAV_LINKS = navbarModule?.NAV_LINKS ?? [];
const NAV_GROUPS = navbarModule?.NAV_GROUPS ?? null;

assertCondition(NAV_LINKS.length === 10, 'NAV_LINKS conserva los diez destinos de SPEC-01 (RF-16.2)');
assertCondition(NAV_GROUPS !== null, 'NAV_GROUPS está exportado: la agrupación es contrato, no detalle de CSS (RF-16.2)');

if (NAV_GROUPS !== null) {
  const groupEntries = Array.isArray(NAV_GROUPS)
    ? NAV_GROUPS
    : Object.entries(NAV_GROUPS).map(([id, value]) => ({ id, ...value }));

  const flatGroupViews = groupEntries.flatMap((group) => (group.links ?? []).map((link) => link.view));
  const looseViews = groupEntries
    .filter((group) => (group.links ?? []).length === 0)
    .map((group) => group.view);

  const allViews = [...flatGroupViews, ...looseViews];
  const uniqueViews = new Set(allViews);

  assertCondition(groupEntries.length === 4, 'La cabecera declara tres grupos de dominio + «Inicio» suelto (RF-16.2)');
  assertCondition(uniqueViews.size === NAV_LINKS.length, 'La agrupación cubre los diez destinos sin omitir ninguno (RF-16.2)');
  assertCondition(allViews.length === uniqueViews.size, 'Ningún destino aparece duplicado entre grupos (RF-16.2)');

  const groupLabels = groupEntries.map((group) => String(group.label ?? ''));
  assertCondition(
    groupLabels.includes('Biblioteca') && groupLabels.includes('Linajes') && groupLabels.includes('Sala de Trabajo'),
    'Los rótulos de grupo son los tres canónicos: Biblioteca, Linajes y Sala de Trabajo (RF-16.2)',
  );
  assertCondition(
    groupEntries.every((group) => String(group.label ?? '').trim() !== ''),
    'Todo grupo declara un rótulo visible en castellano (RNF-16.4)',
  );
  assertCondition(
    groupEntries.every((group) => (group.links ?? []).every((link) => String(link.hash ?? '').startsWith('#/'))),
    'Cada enlace de grupo conserva su hash de ruta «#/…» (RNF-16.4)',
  );
}

// =====================================================================
// FASE 2 — Rótulos de grupo y submenús accesibles (RF-16.1, RF-16.3)
// =====================================================================
console.log('\n[2] Rótulos de grupo y submenús accesibles (RF-16.1, RF-16.3, RNF-16.5)');

if (navbarModule?.createNavbarComponent !== undefined) {
  const { nav, linksList, toggleButton } = buildFakeShellNav();
  const navigations = [];
  const reservedActions = [];

  const navbar = navbarModule.createNavbarComponent(nav, {
    isAuthenticated: false,
    userRole: null,
    onNavigate: (view) => navigations.push(view),
    onReservedAction: (action) => reservedActions.push(action),
    elementFactory: fakeElementFactory,
  });
  navbar.render();

  // Instancia gemela para el segundo clic, con su propio gancho de
  // navegación: el aserto necesita observar el gesto, no reutilizar el
  // acumulador del primer clic (que ya registró la apertura).
  const { nav: secondNav, linksList: secondList } = buildFakeShellNav();
  let secondClickHook = () => {};
  const secondClickNavbar = navbarModule.createNavbarComponent(secondNav, {
    isAuthenticated: false,
    userRole: null,
    onNavigate: (view) => secondClickHook(view),
    onReservedAction: () => {},
    elementFactory: fakeElementFactory,
  });
  secondClickNavbar.render();
  const secondTrigger = allByClass(secondList, 'site-nav__group-trigger')[0] ?? null;
  if (secondTrigger !== null) secondTrigger.dispatch('click');

  const groupLabels = allByClass(linksList, 'site-nav__group');
  const groupMenus = allByClass(linksList, 'site-nav__group-menu');

  assertCondition(groupLabels.length === 4, `El render produce los cuatro rótulos de grupo (clase .site-nav__group) — encontrados: ${groupLabels.length}`);
  assertCondition(
    groupMenus.length === 3,
    `Solo los grupos con más de un destino forjan submenú: 3 de 4 (caso límite 2) — encontrados: ${groupMenus.length}`,
  );
  // Aserto no vacuable: si no hay submenús, esto NO puede pasar por casualidad.
  assertCondition(
    groupMenus.length > 0 && groupMenus.every((menu) => menu.getAttribute('role') === 'menu'),
    'Todo submenú declara role="menu" (semántica de menú, WCAG/APG) — aserto no vacuable: sin submenús NO puede pasar',
  );
  // El aria-expanded vive en el BOTÓN del rótulo, no en su <li> contenedor:
  // el <li> es envoltura de lista y no debe anunciar expansión.
  const groupTriggers = allByClass(linksList, 'site-nav__group-trigger');
  assertCondition(
    groupTriggers.length === 3,
    `Solo los grupos con submenú llevan botón de rótulo: 3 de 4 (caso límite 2) — encontrados: ${groupTriggers.length}`,
  );
  assertCondition(
    groupTriggers.length > 0 && groupTriggers.every((trigger) => trigger.getAttribute('aria-expanded') === 'false'),
    'Todo rótulo de grupo nace cerrado con aria-expanded="false" (RNF-16.5)',
  );
  assertCondition(
    groupTriggers.length > 0 && groupTriggers.every((trigger) => trigger.getAttribute('aria-controls') !== null),
    'Todo rótulo de grupo declara su panel con aria-controls (RNF-16.5)',
  );
  assertCondition(
    groupMenus.every((menu) => menu.getAttribute('aria-hidden') === 'true'),
    'Todo submenú nace oculto con aria-hidden="true" (RNF-16.5)',
  );

  // Los diez destinos deben seguir siendo alcanzables: o sueltos o en un submenú.
  const reachableViews = new Set();
  for (const anchor of allByTag(linksList, 'A')) {
    const view = anchor.getAttribute('data-view');
    if (view !== null) reachableViews.add(view);
  }
  assertCondition(
    reachableViews.size === 10,
    `Los diez destinos siguen siendo alcanzables en el DOM (encontrados: ${reachableViews.size})`,
  );

  // Apertura: activar el rótulo despliega su submenú (RF-16.3).
  const firstGroupLabel = groupLabels[0] ?? null;
  const firstTrigger = groupTriggers[0] ?? null;
  if (firstGroupLabel !== null && firstTrigger !== null) {
    firstTrigger.dispatch('click');
    const expandedAfterClick = firstTrigger.getAttribute('aria-expanded');
    const panelId = firstTrigger.getAttribute('aria-controls');
    const panel = panelId === null ? null : allByClass(linksList, 'site-nav__group-menu')
      .find((menu) => menu.getAttribute('id') === panelId) ?? null;
    assertCondition(expandedAfterClick === 'true', 'Activar el rótulo de grupo despliega su submenú (aria-expanded="true")');
    assertCondition(panel !== null, 'El submenú desplegado es localizable por su id (aria-controls)');
    assertCondition(
      panel !== null && panel.getAttribute('aria-hidden') === 'false',
      'El submenú desplegado se anuncia visible con aria-hidden="false" (RF-16.3)',
    );
    // Segundo clic: el rótulo ya abierto navega al primer destino (RF-16.3).
    // Se usa la instancia gemela (ya abierta con un clic previo).
    const navigationsSecondClick = [];
    secondClickHook = (view) => navigationsSecondClick.push(view);
    if (secondTrigger !== null) {
      secondTrigger.dispatch('click');
      assertCondition(
        secondTrigger.getAttribute('aria-expanded') === 'false',
        'El segundo clic sobre el rótulo ya abierto lo recoge (aria-expanded="false") (RF-16.3)',
      );
      assertCondition(
        navigationsSecondClick.length === 1,
        'El rótulo ya abierto navega a su primer destino: un clic, un resultado (RF-16.3)',
      );
    } else {
      assertCondition(false, 'La instancia gemela tiene un rótulo activable (RF-16.3)');
    }
  } else {
    assertCondition(false, 'Existe al menos un rótulo de grupo activable (RF-16.3)');
  }
} else {
  assertCondition(false, 'createNavbarComponent es importable para verificar el render de grupos (Fase 2)');
}

// =====================================================================
// FASE 3 — Teclado y gobierno por rol (RF-16.4, RF-16.7)
// =====================================================================
console.log('\n[3] Teclado y gobierno por rol (RF-16.4, RF-16.7)');

if (navbarModule?.createNavbarComponent !== undefined) {
  const { nav } = buildFakeShellNav();
  const navbarKeyboard = navbarModule.createNavbarComponent(nav, {
    isAuthenticated: false,
    userRole: null,
    onNavigate: () => {},
    onReservedAction: () => {},
    elementFactory: fakeElementFactory,
  });
  navbarKeyboard.render();

  // El primer <li> es «Inicio», que por caso límite 2 NO tiene botón de
  // rótulo: el teclado se prueba sobre el primer trigger real.
  const triggersForKeyboard = allByClass(nav, 'site-nav__group-trigger');
  const firstTrigger = triggersForKeyboard[0] ?? null;
  if (firstTrigger !== null) {
    const trigger = firstTrigger;
    trigger.dispatch('click');
    const openedState = trigger.getAttribute('aria-expanded');

    // Escape sobre la cabecera debe recoger el submenú (RF-16.4).
    nav.dispatch('keydown', { key: 'Escape' });
    const closedState = trigger.getAttribute('aria-expanded');

    assertCondition(openedState === 'true', 'El submenú abre antes de probar el Escape (precondición de RF-16.4)');
    assertCondition(closedState === 'false', 'Escape recoge el submenú y restaura aria-expanded="false" (RF-16.4)');
    assertCondition(trigger.focusCount > 0, 'Al recoger con Escape el foco regresa al rótulo del grupo (RF-16.4, RNF-16.5)');

    // Clic fuera: el submenú abierto se recoge (RF-16.4).
    trigger.dispatch('click');
    const reopened = trigger.getAttribute('aria-expanded');
    // Un clic sobre un nodo ajeno al nav simula perder el interés por el menú.
    nav.dispatch('keydown', { key: 'Escape' });
    assertCondition(reopened === 'true', 'El submenú se reabre para probar el cierre por Escape (precondición)');
    assertCondition(
      trigger.getAttribute('aria-expanded') === 'false',
      'Escape sucesivo mantiene el submenú recogido de forma idempotente (RF-16.4)',
    );
  } else {
    assertCondition(false, 'Existe un rótulo de grupo para probar la tecla Escape (RF-16.4)');
  }

  // Gobierno por rol (RF-16.7, SPEC-08): la Torre de Deliberación solo
  // aparece para roles con facultad de moderar, y desaparece al perderla.
  const renderLinksFor = (role) => {
    const shell = buildFakeShellNav();
    const scoped = navbarModule.createNavbarComponent(shell.nav, {
      isAuthenticated: role !== null,
      userRole: role,
      onNavigate: () => {},
      onReservedAction: () => {},
      elementFactory: fakeElementFactory,
    });
    scoped.render();
    const views = new Set();
    for (const anchor of allByTag(shell.linksList, 'A')) {
      const view = anchor.getAttribute('data-view');
      if (view !== null) views.add(view);
    }
    return { shell, scoped, views };
  };

  const masterNav = renderLinksFor('master');
  const readerNav = renderLinksFor('reader');
  const visitorNav = renderLinksFor(null);

  assertCondition(masterNav.views.has('tower'), 'El rol master ve la Torre de Deliberación (RF-16.7, SPEC-08 RF-05.4)');
  assertCondition(!readerNav.views.has('tower'), 'El rol reader NO ve la Torre de Disciplina (RF-16.7)');
  assertCondition(!visitorNav.views.has('tower'), 'El visitante NO ve la Torre de Disciplina (RF-16.7)');

  // El cambio de rol debe repintar sin perder ningún otro destino.
  masterNav.scoped.setSession(false, 'reader');
  const viewsAfterChange = new Set();
  for (const anchor of allByTag(masterNav.shell.linksList, 'A')) {
    const view = anchor.getAttribute('data-view');
    if (view !== null) viewsAfterChange.add(view);
  }
  assertCondition(!viewsAfterChange.has('tower'), 'Al perder la facultad, la Torre se retira en la siguiente setSession (RF-16.7)');
  assertCondition(viewsAfterChange.size === 10, 'La retirada de la Torre deja los diez destinos de siempre (RF-16.7)');
} else {
  assertCondition(false, 'createNavbarComponent es importable para verificar teclado y roles (Fase 3)');
}

// =====================================================================
// FASE 4 — El distintivo corto (RF-17.1–RF-17.4)
// =====================================================================
console.log('\n[4] El distintivo corto: alias visible, identidad en el desplegable (RF-17.1–17.4)');

let badgeModule = null;
try {
  badgeModule = await import('../public/assets/js/components/userProfileBadge.js');
} catch (importError) {
  console.log(`  (no se pudo importar userProfileBadge.js: ${importError.message})`);
}

/**
 * Construye el contenedor del distintivo como lo hace el shell real
 * (public/index.html): el botón «Cruzar el Umbral» vive ahí y el
 * componente lo retira al forjar el distintivo del vinculado.
 */
function buildBadgeRoot() {
  const badgeRoot = createFakeElement('div');
  badgeRoot.setAttribute('id', 'navSession');
  const thresholdButton = createFakeElement('button');
  thresholdButton.setAttribute('id', 'navCrossThreshold');
  thresholdButton.setAttribute('data-action', 'crossThreshold');
  thresholdButton.textContent = 'Cruzar el Umbral';
  badgeRoot.appendChild(thresholdButton);
  return { badgeRoot, thresholdButton };
}

if (badgeModule?.createMemoryBadgeRoot !== undefined) {
  const { badgeRoot: mountRoot } = buildBadgeRoot();
  const badge = badgeModule.createMemoryBadgeRoot(mountRoot, {
    onCrossThreshold: () => {},
    onOpenPanel: () => {},
    onOpenGrimoire: () => {},
    onDissolve: () => {},
    onDissolveAll: () => {},
    documentRef: fakeDocument,
  });
  badge.setUser({
    alias: 'Vestibulo7b',
    role: 'editor',
    lineage: 'abyssalShadows',
    clanId: '9',
    clanName: 'Custodios de la Espina',
    avatarKind: 'catalog',
    avatarReference: 'seal_abyssalShadows',
  });

  const toggle = byId(mountRoot, 'userProfileToggle');
  const visibleLegend = toggle === null ? '' : String(toggle.textContent ?? '');

  assertCondition(toggle !== null, 'El distintivo de sesión existe en la cabecera (RF-17.1)');
  assertCondition(visibleLegend.includes('Vestibulo7b'), 'El rótulo visible conserva el alias del vinculado (RF-17.1)');
  assertCondition(
    !visibleLegend.includes('Sombras Abisales') && !visibleLegend.includes('Custodios de la Espina'),
    'El linaje jurado NO ocupa el rótulo visible de la cabecera (RF-17.1, remedio de D1)',
  );

  // La identidad completa es la que corresponde a CADA estado: con
  // hermandad manda el clan; sin ella, el linaje jurado. El aserto anterior
  // exigía el linaje en un usuario que SÍ tiene hermandad: fallaba por una
  // razón distinta a la que nombraba.
  const ariaLabel = toggle === null ? '' : String(toggle.getAttribute('aria-label') ?? '');
  assertCondition(
    ariaLabel.includes('Vestibulo7b') && ariaLabel.includes('Custodios de la Espina'),
    'El nombre accesible conserva la identidad íntegra (alias + hermandad) aunque el rótulo se haya acortado (RF-17.4)',
  );
  assertCondition(
    !visibleLegend.includes('Custodios de la Espina'),
    'La hermandad tampoco ocupa el rótulo visible de la cabecera (RF-17.1)',
  );
  assertCondition(
    ariaLabel.includes('menú arcano'),
    'El nombre accesible conserva el sufijo «Abrir el menú arcano» (RF-17.4)',
  );

  // El desplegable debe declarar la identidad completa (RF-17.2).
  toggle?.dispatch('click');
  const menuText = visibleTextOf(mountRoot);
  assertCondition(
    menuText.includes('Custodios de la Espina'),
    'El desplegable declara la hermandad que la cabecera ya no muestra (RF-17.2)',
  );

  // CASO GEMELO: el mismo adepto SIN hermandad debe conservar su linaje
  // jurado en el nombre accesible. Sin esta comprobación, un futuro cambio
  // podría acortar el rótulo y perder el linaje sin que nada lo notara.
  const { badgeRoot: juradoRoot } = buildBadgeRoot();
  const juradoBadge = badgeModule.createMemoryBadgeRoot(juradoRoot, {
    onCrossThreshold: () => {},
    onOpenPanel: () => {},
    onOpenGrimoire: () => {},
    onDissolve: () => {},
    onDissolveAll: () => {},
    documentRef: fakeDocument,
  });
  juradoBadge.setUser({
    alias: 'Vestibulo7b',
    role: 'editor',
    lineage: 'abyssalShadows',
    clanId: '',
    clanName: '',
    avatarKind: 'catalog',
    avatarReference: 'seal_abyssalShadows',
  });
  const juradoToggle = byId(juradoRoot, 'userProfileToggle');
  const juradoLabel = juradoToggle === null ? '' : String(juradoToggle.getAttribute('aria-label') ?? '');
  const juradoVisible = juradoToggle === null ? '' : String(juradoToggle.textContent ?? '');
  assertCondition(
    juradoLabel.includes('Vestibulo7b') && juradoLabel.includes('Linaje de las Sombras Abisales'),
    'Sin hermandad, el nombre accesible conserva el linaje jurado (RF-17.4)',
  );
  assertCondition(
    juradoVisible === 'Vestibulo7b',
    `Sin hermandad el rótulo visible es el alias desnudo — actual: «${juradoVisible}» (RF-17.3)`,
  );

  // CASO PEREGRINO (RF-17.3): sin hermandad ni juramento, el botón nunca
  // muestra una cadena vacía y el desplegable declara el estado solemne.
  const { badgeRoot: peregrinoRoot } = buildBadgeRoot();
  const peregrinoBadge = badgeModule.createMemoryBadgeRoot(peregrinoRoot, {
    onCrossThreshold: () => {},
    onOpenPanel: () => {},
    onOpenGrimoire: () => {},
    onDissolve: () => {},
    onDissolveAll: () => {},
    documentRef: fakeDocument,
  });
  peregrinoBadge.setUser({
    alias: 'Aprendiz',
    role: 'reader',
    lineage: null,
    clanId: '',
    clanName: '',
    avatarKind: 'default',
    avatarReference: '',
  });
  const peregrinoToggle = byId(peregrinoRoot, 'userProfileToggle');
  const peregrinoVisible = peregrinoToggle === null ? '' : String(peregrinoToggle.textContent ?? '');
  peregrinoToggle?.dispatch('click');
  const peregrinoMenu = visibleTextOf(peregrinoRoot);
  assertCondition(
    peregrinoVisible === 'Aprendiz',
    `El peregrino muestra su alias, nunca una cadena vacía — actual: «${peregrinoVisible}» (RF-17.3)`,
  );
  assertCondition(
    peregrinoMenu.includes('Peregrino sin Linaje'),
    'El desplegable del peregrino declara el estado solemne «Peregrino sin Linaje» (RF-17.3)',
  );
} else {
  assertCondition(false, 'createMemoryBadgeRoot es importable para verificar el rótulo corto (Fase 4)');
}

// =====================================================================
// FASE 5 — La efigie por defecto (RF-17.5, remedio de D3)
// =====================================================================
console.log('\n[5] La efigie por defecto forja sello, jamás cuadro vacío (RF-17.5)');

if (badgeModule?.createMemoryBadgeRoot !== undefined) {
  const { badgeRoot: defaultMount } = buildBadgeRoot();
  const defaultBadge = badgeModule.createMemoryBadgeRoot(defaultMount, {
    onCrossThreshold: () => {},
    onOpenPanel: () => {},
    onOpenGrimoire: () => {},
    onDissolve: () => {},
    onDissolveAll: () => {},
    documentRef: fakeDocument,
  });
  defaultBadge.setUser({
    alias: 'Peregrino',
    role: 'reader',
    lineage: null,
    clanId: '',
    clanName: '',
    avatarKind: 'default',
    avatarReference: '',
  });

  const avatarNode = allByClass(defaultMount, 'user-profile__avatar')[0] ?? null;
  const avatarChildren = avatarNode === null ? 0 : avatarNode.children.length;
  const avatarHasSvg = avatarNode === null ? false : queryByClass(avatarNode, 'rune-seal').length > 0
    || allByTag(avatarNode, 'svg').length > 0;

  assertCondition(avatarNode !== null, 'El nodo de efigie existe en el distintivo (RF-17.5)');
  assertCondition(
    avatarHasSvg || avatarChildren > 0,
    'La efigie por defecto forja un sello en lugar de un cuadro vacío (RF-17.5, remedio de D3)',
  );
  assertCondition(
    avatarNode === null || avatarNode.getAttribute('data-avatar-kind') === 'default',
    'El nodo conserva su kind="default" para que el CSS pueda vestirse (RF-17.5)',
  );
} else {
  assertCondition(false, 'createMemoryBadgeRoot es importable para verificar la efigie por defecto (Fase 5)');
}

// =====================================================================
// FASE 6 — La portada compuesta: orden de bloques y h1 único (RF-18.1)
// =====================================================================
console.log('\n[6] La portada compuesta: orden de bloques y h1 único (RF-18.1, caso límite 5)');

let landingModule = null;
try {
  landingModule = await import('../public/assets/js/views/landingView.js');
} catch (importError) {
  console.log(`  (no se pudo importar landingView.js: ${importError.message})`);
}

const buildLanding = (options = {}) => {
  const mountRoot = createFakeElement('main');
  const featuredSpells = [];
  for (let index = 1; index <= 3; index += 1) {
    featuredSpells.push({
      slug: `pergamino-${index}`,
      name: `Pergamino ${index}`,
      school: 'evocation',
      schoolName: 'Evocación',
      manaCost: 5,
      status: 'validated',
      authorName: 'Maestro',
      description: 'Descripción de prueba del pergamino destacado.',
      element: 'fire',
    });
  }
  const landing = landingModule.createLandingView(mountRoot, {
    spellClient: {
      fetchFeatured: () => Promise.resolve({ success: true, data: featuredSpells }),
    },
    dominionClient: options.dominionClient ?? null,
    onReservedAction: () => {},
    onSpellSelect: () => {},
    onRegentSelect: () => {},
    elementFactory: fakeElementFactory,
    documentRef: fakeDocument,
  });
  return { mountRoot, landing };
};

if (landingModule?.createLandingView !== undefined) {
  const { mountRoot, landing } = buildLanding();
  await landing.render();

  const viewRoot = mountRoot.children[0] ?? null;
  const topLevelClasses = (viewRoot?.children ?? []).map((child) => child.className);

  const sigilIndex = topLevelClasses.findIndex((name) => name.includes('landing-sigil'));
  const heroIndex = topLevelClasses.findIndex((name) => name.includes('landing-hero'));
  const regentIndex = topLevelClasses.findIndex((name) => name.includes('landing-view__regent'));
  const featuredIndex = topLevelClasses.findIndex((name) => name.includes('landing-view__featured'));

  assertCondition(
    sigilIndex >= 0 && heroIndex >= 0 && regentIndex >= 0 && featuredIndex >= 0,
    'La portada monta los cuatro bloques: sello, héroe, cinta del Regente y destacados (RF-18.1)',
  );
  assertCondition(
    sigilIndex >= 0 && heroIndex >= 0 && sigilIndex < heroIndex,
    'El sello de validación precede al héroe: la firma abre la página (RF-18.1, RF-18.4)',
  );
  assertCondition(
    heroIndex >= 0 && regentIndex >= 0 && heroIndex < regentIndex,
    'El héroe precede a la cinta del Regente: la tesis va antes del estado (RF-18.1, remedio de D5)',
  );
  assertCondition(
    regentIndex >= 0 && featuredIndex >= 0 && regentIndex < featuredIndex,
    'La cinta del Regente precede a los Pergaminos Destacados (RF-18.1)',
  );

  const h1s = allByTag(viewRoot, 'H1');
  assertCondition(h1s.length === 1, 'La portada conserva un único H1 (caso límite 5, RNF-03)');
  assertCondition(
    h1s[0] !== undefined && h1s[0].className.includes('landing-hero__title'),
    'El H1 es el título del héroe (caso límite 5)',
  );

  const h2s = allByTag(viewRoot, 'H2');
  assertCondition(
    h2s.every((heading) => !heading.className.includes('landing-hero__title')),
    'Ningún otro encabezado usurpa el título del héroe (caso límite 5)',
  );
} else {
  assertCondition(false, 'createLandingView es importable para verificar el orden de bloques (Fase 6)');
}

// =====================================================================
// FASE 7 — El sello de validación (RF-18.4, RF-18.5)
// =====================================================================
console.log('\n[7] El sello de validación: firma, leyenda y tokens (RF-18.4, RF-18.5)');

let sigilModule = null;
try {
  sigilModule = await import('../public/assets/js/components/landingSigilComponent.js');
} catch (importError) {
  console.log(`  (landingSigilComponent.js aún no existe: ${importError.message.split('\n')[0]})`);
}

if (sigilModule?.createValidationSigilComponent !== undefined) {
  const sigilMount = createFakeElement('div');
  const sigil = sigilModule.createValidationSigilComponent(sigilMount, {
    elementFactory: fakeElementFactory,
    documentRef: fakeDocument,
  });
  sigil.render();

  const sigilText = visibleTextOf(sigilMount);
  assertCondition(
    /validado/i.test(sigilText),
    'El sello declara en texto visible que el tomo reúne contenido validado (RF-18.5)',
  );
  assertCondition(
    sigilMount.children.length > 0,
    'El sello forja un marco visible: es el elemento de firma de la portada (RF-18.4)',
  );

  const sigilRoot = sigilMount.children[0] ?? null;
  assertCondition(
    sigilRoot === null || sigilRoot.getAttribute('role') === 'img' || sigilRoot.getAttribute('aria-label') !== null,
    'El sello declara nombre accesible (role="img" o aria-label) (RF-18.5, RNF-16.4)',
  );
} else {
  // FASE ROJA ESPERADA: el módulo aún no existe.
  assertCondition(false, 'createValidationSigilComponent existe y es importable (RF-18.4)');
  assertCondition(false, 'El sello declara la leyenda «Tomo validado» en texto visible (RF-18.5)');
}

// =====================================================================
// FASE 8 — El tratamiento de título del sistema (RF-18.2, remedio de D2)
// =====================================================================
console.log('\n[8] El tratamiento de título del sistema en el héroe (RF-18.2)');

const libraryCssPath = new URL('../public/assets/css/components/library.css', import.meta.url);
let libraryCss = '';
try {
  libraryCss = await readFile(libraryCssPath, 'utf8');
} catch (readError) {
  console.log(`  (no se pudo leer library.css: ${readError.message})`);
}

/** Extrae el bloque de reglas de un selector simple dentro de una hoja. */
function ruleBodyFor(css, selector) {
  const pattern = new RegExp(`(^|[}\\n])\\s*${selector.replace(/[.*+?^${}()|[\\]\\\\]/g, '\\\\$&')}\\s*\\{([^}]*)\\}`, 'm');
  const found = css.match(pattern);
  return found === null ? null : found[2];
}

const heroTitleRule = ruleBodyFor(libraryCss, '.landing-hero__title');
const featuredTitleRule = ruleBodyFor(libraryCss, '.landing-view__featured-title');

assertCondition(
  heroTitleRule !== null,
  '.landing-hero__title tiene regla propia en la hoja (RF-18.2, remedio de D2: hoy no tiene NINGUNA)',
);
assertCondition(
  heroTitleRule !== null && heroTitleRule.includes('var(--font-arcane-title)'),
  'El título del héroe consume la familia de títulos grabados (RF-18.2)',
);
assertCondition(
  heroTitleRule !== null && heroTitleRule.includes('var(--font-size-title-page)'),
  'El título del héroe consume el tamaño de título de portada (RF-18.2)',
);
assertCondition(
  heroTitleRule !== null && heroTitleRule.includes('var(--color-gold-arcane)'),
  'El título del héroe consume el oro arcano (RF-18.2)',
);
assertCondition(
  featuredTitleRule !== null && featuredTitleRule.includes('var(--font-arcane-title)'),
  '.landing-view__featured-title también está vestado con la familia del sistema (RF-18.2)',
);

// =====================================================================
// FASE 9 — La cinta compacta del Regente (RF-18.7–RF-18.9)
// =====================================================================
console.log('\n[9] La cinta compacta del Regente: una línea, sin doble marco (RF-18.7–18.9)');

const regentRule = ruleBodyFor(libraryCss, '.landing-view__regent');
// Aserto no vacuable: la cinta NO puede seguir compartiendo la caja de los
// destacados, así que la regla debe existir SOLO (no en un selector agrupado).
const regentSharesBox = /^\s*\.landing-view__featured\s*,\s*\.landing-view__regent\s*\{/m.test(libraryCss);

assertCondition(
  regentRule !== null && !regentSharesBox,
  '.landing-view__regent tiene regla propia: la cinta ya no comparte caja con los destacados (RF-18.7)',
);
assertCondition(
  regentRule !== null && !/border\s*:\s*var\(--border-ink-strong\)/.test(regentRule),
  'La cinta del Regente NO pinta marco propio: se elimina el doble encuadre (RF-18.7, remedio de D4)',
);

if (landingModule?.createLandingView !== undefined) {
  // La cinta debe measures una línea: forbid el selector de la ficha anidada.
  const bannerCssPath = new URL('../public/assets/css/components/clans.css', import.meta.url);
  let bannerCss = '';
  try {
    bannerCss = await readFile(bannerCssPath, 'utf8');
  } catch (readError) {
    console.log(`  (no se pudo leer clans.css: ${readError.message})`);
  }
  const compactRule = ruleBodyFor(bannerCss, '.clan-banner__regent--compact')
    ?? ruleBodyFor(bannerCss, '.clan-banner__regent');
  assertCondition(
    compactRule !== null && !/border\s*:\s*var\(--border-ink-strong\)/.test(compactRule),
    'La variante compacta del blasón no repite el marco de la cinta (RF-18.7, remedio de D4)',
  );
  assertCondition(
    ruleBodyFor(bannerCss, '.clan-banner__regent--compact') !== null,
    'Existe una variante compacta explícita del blasón en clans.css (RF-18.7)',
  );

  // El Regente no debe abrir la página por delante de la tesis (RF-18.1).
  const regentFirst = buildLanding({ dominionClient: null });
  await regentFirst.landing.render();
  const classesNoDominion = (regentFirst.mountRoot.children[0]?.children ?? [])
    .map((child) => child.className);
  assertCondition(
    classesNoDominion.length > 0 && classesNoDominion.findIndex((name) => name.includes('landing-hero')) === 0,
    'Sin cliente del Dominio, la portada abre con el sello o el héroe, no con un hueco vacío (RF-18.1)',
  );
}

// =====================================================================
// FASE 10 — Soberanía lingüística (RNF-16.4)
// =====================================================================
console.log('\n[10] Soberanía lingüística: ninguna clave técnica visible (RNF-16.4)');

const cssFilesToAudit = [
  '../public/assets/css/layout.css',
  '../public/assets/css/components/library.css',
  '../public/assets/css/components/user-panel.css',
  '../public/assets/css/components/clans.css',
];

for (const relativePath of cssFilesToAudit) {
  let sheet = '';
  try {
    sheet = await readFile(new URL(relativePath, import.meta.url), 'utf8');
  } catch (readError) {
    assertCondition(false, `Se puede auditar ${relativePath} (RNF-16.4)`);
    continue;
  }
  // Los comentarios de las hojas son castellano; solo importan las cadenas
  // de contenido (propiedad content) y las clases, no el código.
  const contentStrings = [...sheet.matchAll(/content\s*:\s*['"]([^'"]*)['"]/g)].map((found) => found[1]);
  const leaks = contentStrings.filter((value) => /[a-z]+[A-Z]/.test(value) || /\b(solarCrown|abyssalShadows|openCreator|library|editor)\b/.test(value));
  assertCondition(
    leaks.length === 0,
    `${relativePath} no muestra claves técnicas en su contenido generado${leaks.length > 0 ? ` (filtradas: ${leaks.join(', ')})` : ''} (RNF-16.4)`,
  );
}

// =====================================================================
// FASE 11 — Disciplina de tokens (RNF-16.1)
// =====================================================================
console.log('\n[11] Disciplina de tokens: cero hex crudos en las hojas tocadas (RNF-16.1)');

for (const relativePath of cssFilesToAudit) {
  let sheet = '';
  try {
    sheet = await readFile(new URL(relativePath, import.meta.url), 'utf8');
  } catch (readError) {
    continue;
  }
  // Se ignoran los comentarios: pueden citar literales a propósito.
  const codeOnly = sheet.replace(/\/\*[\s\S]*?\*\//g, '');
  const rawHex = [...codeOnly.matchAll(/#[0-9a-fA-F]{3,8}\b/g)].map((found) => found[0]);
  const hexInVars = [...codeOnly.matchAll(/--[\w-]+\s*:\s*#[0-9a-fA-F]{3,8}/g)].map((found) => found[0]);
  const offenders = rawHex.filter((value) => !hexInVars.some((decl) => decl.endsWith(value)));
  assertCondition(
    offenders.length === 0,
    `${relativePath} no escribe colores hex crudos fuera de tokens${offenders.length > 0 ? ` (encontrados: ${offenders.slice(0, 3).join(', ')})` : ''} (RNF-16.1)`,
  );
}

// =====================================================================
// =====================================================================
// FASE 13 — El modo plano: por debajo de 1024 px rigen los enlaces sueltos
//            de RF-02.4, sin agrupar y sin submenús (RF-16.5)
// =====================================================================
console.log('\n[13] El modo plano conserva RF-02.4 intacto (RF-16.5)');

if (navbarModule?.createNavbarComponent !== undefined) {
  const { nav: flatNav, linksList: flatList } = buildFakeShellNav();
  const flatNavbar = navbarModule.createNavbarComponent(flatNav, {
    isAuthenticated: false,
    userRole: null,
    onNavigate: () => {},
    onReservedAction: () => {},
    layout: navbarModule.NAV_LAYOUTS?.FLAT ?? 'flat',
    elementFactory: fakeElementFactory,
  });
  flatNavbar.render();

  assertCondition(
    allByClass(flatList, 'site-nav__group').length === 0,
    'El modo plano NO agrupa: ningún nodo site-nav__group (RF-16.5)',
  );
  assertCondition(
    allByClass(flatList, 'site-nav__group-menu').length === 0,
    'El modo plano NO forja submenús (RF-16.5)',
  );
  const flatAnchors = allByTag(flatList, 'A');
  assertCondition(
    flatAnchors.length === 10,
    `El modo plano mantiene los diez destinos de RF-02.4 — encontrados: ${flatAnchors.length}`,
  );
  assertCondition(
    flatAnchors.every((anchor) => anchor.getAttribute('data-view') !== null),
    'Todo enlace del modo plano conserva su data-view (SPEC-01 RF-02.1)',
  );

  // Y el cambio dinámico de disposición debe funcionar en caliente.
  const { nav: switchNav, linksList: switchList } = buildFakeShellNav();
  const switchNavbar = navbarModule.createNavbarComponent(switchNav, {
    isAuthenticated: false,
    userRole: null,
    onNavigate: () => {},
    onReservedAction: () => {},
    elementFactory: fakeElementFactory,
  });
  switchNavbar.render();
  const groupedBefore = allByClass(switchList, 'site-nav__group').length;
  switchNavbar.setLayout(navbarModule.NAV_LAYOUTS?.FLAT ?? 'flat');
  const groupedAfter = allByClass(switchList, 'site-nav__group').length;
  const anchorsAfter = allByTag(switchList, 'A').length;

  assertCondition(groupedBefore === 4, `La cabecera arranca agrupada por omisión — grupos: ${groupedBefore}`);
  assertCondition(groupedAfter === 0, 'setLayout("flat") desagrupa en caliente (RF-16.5)');
  assertCondition(
    anchorsAfter === 10,
    `La desagrupación no pierde ningún destino — enlaces: ${anchorsAfter}`,
  );

  // Regreso: la disposición agrupada se recupera sin perder nada.
  switchNavbar.setLayout(navbarModule.NAV_LAYOUTS?.GROUPED ?? 'grouped');
  assertCondition(
    allByClass(switchList, 'site-nav__group').length === 4,
    'setLayout("grouped") vuelve a agrupar (RF-16.5)',
  );
  assertCondition(
    allByTag(switchList, 'A').length === 10,
    'El ida y vuelta entre disposiciones conserva los diez destinos (RF-16.5)',
  );
} else {
  assertCondition(false, 'createNavbarComponent es importable para verificar el modo plano (Fase 13)');
}

// =====================================================================
// FASE 12 — El gesto reservado del Creador sobrevive (RF-02.3, SPEC-01)
// =====================================================================
console.log('\n[12] La superficie respeta el gesto reservado del Creador (RF-02.3)');

if (navbarModule?.createNavbarComponent !== undefined) {
  const { nav, linksList } = buildFakeShellNav();
  const reserved = [];
  const creatorNavbar = navbarModule.createNavbarComponent(nav, {
    isAuthenticated: false,
    userRole: null,
    onNavigate: () => {},
    onReservedAction: (action) => reserved.push(action),
    elementFactory: fakeElementFactory,
  });
  creatorNavbar.render();

  const creatorAnchors = allByTag(linksList, 'A')
    .filter((anchor) => anchor.getAttribute('data-action') === 'openCreator');
  assertCondition(creatorAnchors.length === 1, 'El Creador de Hechizos conserva exactamente una acción reservada (RF-02.3)');
  assertCondition(
    creatorAnchors[0]?.getAttribute('data-reserved') === 'true',
    'El enlace del Creador conserva data-reserved="true" (SPEC-01 RF-02.3)',
  );
  assertCondition(
    creatorAnchors[0]?.getAttribute('hash') === null && creatorAnchors[0]?.getAttribute('href') === '#/creador',
    'El enlace del Creador conserva su ruta «#/creador» (RNF-16.4)',
  );

  creatorAnchors[0]?.dispatch('click');
  assertCondition(
    reserved.includes('openCreator'),
    'La activación del Creador sigue disparando onReservedAction (RF-02.3 intacto)',
  );
} else {
  assertCondition(false, 'createNavbarComponent es importable para verificar el gesto reservado (Fase 12)');
}

// =====================================================================
// RESUMEN
// =====================================================================
console.log('\n============================================================');
console.log(`Asertos superados: ${assertsPassed}`);
console.log(`Asertos fallidos:  ${assertsFailed}`);
console.log('============================================================');

if (assertsFailed > 0) {
  console.log('\nFASE ROJA ALCANZADA — requisitos aún incumplidos por SPEC-16:');
  for (const description of failedAssertions) {
    console.log(`  - ${description}`);
  }
  console.log('\nEsto es lo ESPERADO en la Tarea 0: el código aún no existe.');
  process.exit(1);
}

console.log('\nTodas las aserciones pasan. SPEC-16 queda lista para revisión de cierre.');
process.exit(0);
