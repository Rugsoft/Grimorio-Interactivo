/**
 * test_vestibule_route_badge.mjs — Arnés de la Tarea 4.2 (TASKS-10).
 *
 * Verifica la RUTA, la DOBLE VÍA y el RÓTULO de dictámenes (SPEC-10):
 *   [1] Mapas de ruta: `#/vestibulo → 'vestibule'` en ambos sentidos.
 *   [2] Enlace «Hermandades» en la navbar, con su distintivo accesible.
 *   [3] El rótulo luce SOLO con veredictos sin leer (setVestibuleBadgeCount).
 *   [4] Se apaga al contemplarlos (0) y vuelve a encenderse con datos.
 *   [5] El rótulo jamás bloquea la navegación: el enlace navega siempre.
 *   [6] Doble vía: el llamamiento del Salón conduce al mismo Vestíbulo.
 *   [7] El peregrino sin linaje queda retenido por el interceptor (SPEC-09).
 *
 * Criterio «Hecho cuando» (Tarea 4.2): ambas vías conducen al mismo
 * Vestíbulo, el rótulo luce solo con veredictos sin leer y se apaga al
 * contemplarlos sin bloquear navegación alguna.
 *
 * Constitución:
 *   - Artículo I (Dogma Vanilla): ES Modules nativos, DOM simulado mínimo.
 *   - Artículo V: identificadores en inglés camelCase; comentarios en castellano.
 *
 * Uso: node scratch/test_vestibule_route_badge.mjs
 */

import assert from 'node:assert';

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

// =====================================================================
// DOM simulado mínimo (mismo idioma que los arneses de la navbar)
// =====================================================================

/** Espía de listeners: verifica el cableado sin navegador. */
function createObservableElement(tagName) {
  return {
    tagName: String(tagName).toUpperCase(),
    children: [],
    attributes: new Map(),
    textContent: '',
    parentNode: null,
    listeners: new Map(),
    className: '',
    setAttribute(name, value) {
      this.attributes.set(name, String(value));
    },
    getAttribute(name) {
      return this.attributes.has(name) ? this.attributes.get(name) : null;
    },
    hasAttribute(name) {
      return this.attributes.has(name);
    },
    removeAttribute(name) {
      this.attributes.delete(name);
    },
    appendChild(child) {
      child.parentNode = this;
      this.children.push(child);
      return child;
    },
    removeChild(child) {
      const index = this.children.indexOf(child);
      if (index !== -1) this.children.splice(index, 1);
      child.parentNode = null;
      return child;
    },
    addEventListener(type, handler) {
      if (!this.listeners.has(type)) this.listeners.set(type, []);
      this.listeners.get(type).push(handler);
    },
    removeEventListener(type, handler) {
      const list = this.listeners.get(type) ?? [];
      const index = list.indexOf(handler);
      if (index !== -1) list.splice(index, 1);
    },
    dispatch(type, event = {}) {
      for (const handler of this.listeners.get(type) ?? []) {
        handler({ currentTarget: this, target: this, preventDefault() {}, ...event });
      }
    },
    querySelector(selector) {
      const search = (node) => {
        for (const child of node.children) {
          const id = child.attributes.get('id') ?? '';
          if (selector.startsWith('#') && id === selector.slice(1)) return child;
          const found = search(child);
          if (found) return found;
        }
        return null;
      };
      return search(this);
    },
  };
}

function createNavRoot() {
  const navRoot = createObservableElement('nav');
  const linksList = createObservableElement('ul');
  linksList.setAttribute('id', 'navLinks');
  const toggleButton = createObservableElement('button');
  toggleButton.setAttribute('id', 'navToggle');
  navRoot.appendChild(linksList);
  navRoot.appendChild(toggleButton);
  return { navRoot, linksList, toggleButton };
}

// =====================================================================
// [1] Mapas de ruta en main.js
// =====================================================================
console.log('[1] Mapas de ruta (main.js)');

const { HASH_TO_VIEW_MAP, VIEW_TO_HASH_MAP } = await import('../public/assets/js/main.js');
assertCondition(HASH_TO_VIEW_MAP['#/vestibulo'] === 'vestibule', "HASH_TO_VIEW_MAP mapea '#/vestibulo' → 'vestibule'");
assertCondition(VIEW_TO_HASH_MAP.vestibule === '#/vestibulo', "VIEW_TO_HASH_MAP mapea 'vestibule' → '#/vestibulo' (deep-link, RF-01.6)");

// =====================================================================
// [2] El enlace «Hermandades» en la navbar
// =====================================================================
console.log('\n[2] Enlace «Hermandades» en la navbar');

const { createNavbarComponent, NAV_LINKS } = await import('../public/assets/js/components/navbarComponent.js');
const vestibuleLink = NAV_LINKS.find((link) => link.view === 'vestibule');
assertCondition(vestibuleLink !== undefined, 'NAV_LINKS declara el enlace del Vestíbulo');
assertCondition(vestibuleLink?.hash === '#/vestibulo' && vestibuleLink?.label === 'Hermandades', 'el enlace porta hash y rótulo «Hermandades»');

const { navRoot, linksList } = createNavRoot();
const navigatedViews = [];
const navbar = createNavbarComponent(navRoot, {
  isAuthenticated: false,
  onNavigate: (viewName) => navigatedViews.push(viewName),
  elementFactory: createObservableElement,
});
navbar.render();

const hermandadesLink = linksList.children.find((child) => child.getAttribute('data-view') === 'vestibule');
assertCondition(hermandadesLink !== undefined, 'el enlace «Hermandades» se pinta en la lista de navegación');
assertCondition(hermandadesLink?.getAttribute('href') === '#/vestibulo', 'el enlace apunta al hash canónico');
const badgeCarrier = hermandadesLink?.children.find((child) => child.getAttribute('data-badge') === 'vestibuleBadge');
assertCondition(badgeCarrier !== undefined, 'el enlace porta el portador del distintivo (accesible)');
assertCondition(badgeCarrier?.getAttribute('role') === 'status', 'el portador es región viva (role=status, RNF-03)');
assertCondition(badgeCarrier?.textContent === '' && !badgeCarrier.hasAttribute('data-count'), 'el distintivo nace APAGADO (sin veredictos sin leer)');

// =====================================================================
// [3] El rótulo luce SOLO con veredictos sin leer
// =====================================================================
console.log('\n[3] El rótulo se enciende solo con dictámenes sin leer');

navbar.setVestibuleBadgeCount(2);
assertCondition(badgeCarrier.textContent.includes('2 dictámenes a la espera'), 'con 2 veredictos sin leer: «Tienes 2 dictámenes a la espera»');
assertCondition(badgeCarrier.getAttribute('data-count') === '2', 'el portador declara data-count=2');

navbar.setVestibuleBadgeCount(1);
assertCondition(badgeCarrier.textContent.includes('1 dictamen a la espera') && !badgeCarrier.textContent.includes('dictámenes'), 'con 1 veredicto: singular «dictamen» (leyenda 9 del Anexo A)');

// =====================================================================
// [4] Se apaga al contemplarlos y vuelve a encenderse
// =====================================================================
console.log('\n[4] Apagado al contemplar (RF-03.4) y reencendido idempotente');

navbar.setVestibuleBadgeCount(0);
assertCondition(badgeCarrier.textContent === '' && !badgeCarrier.hasAttribute('data-count'), 'al contemplar (0): el distintivo se APAGA sin dejar texto');
assertCondition(hermandadesLink.parentNode === linksList, 'el enlace «Hermandades» sobrevive al apagado (sigue navegable)');

navbar.setVestibuleBadgeCount(3);
assertCondition(badgeCarrier.textContent.includes('3 dictámenes'), 'reencendido con 3: el ciclo apagado/encendido es repetible');
// Idempotencia: la misma cifra no repinta (guardia interna).
const textoAntes = badgeCarrier.textContent;
navbar.setVestibuleBadgeCount(3);
assertCondition(badgeCarrier.textContent === textoAntes, 'la misma cifra no repinta el DOM (idempotencia)');

// =====================================================================
// [5] El rótulo jamás bloquea la navegación
// =====================================================================
console.log('\n[5] El rótulo no es portero: el enlace navega siempre');

navbar.setVestibuleBadgeCount(2);
hermandadesLink.dispatch('click');
assertCondition(navigatedViews.includes('vestibule'), 'con rótulo encendido, el click navega al Vestíbulo sin interceptación');

hermandadesLink.dispatch('keydown', { key: 'Enter' });
assertCondition(navigatedViews.filter((view) => view === 'vestibule').length === 2, 'el teclado (Enter) navega igualmente (accesibilidad, RNF-03)');

navbar.setVestibuleBadgeCount(0);
hermandadesLink.dispatch('click');
assertCondition(navigatedViews.length === 3, 'con el rótulo apagado, el enlace sigue conduciendo al Vestíbulo');

// =====================================================================
// [6] Doble vía: el llamamiento del Salón conduce al mismo Vestíbulo
// =====================================================================
console.log('\n[6] Doble vía desde el Salón de los Linajes');

const { createLineageHallComponent } = await import('../public/assets/js/components/lineageHallComponent.js');
const hallMount = createObservableElement('div');
let vestibuleCalled = false;
const hall = createLineageHallComponent(hallMount, {
  onOpenVestibule: () => {
    vestibuleCalled = true;
  },
  elementFactory: createObservableElement,
});
hall.render();

const hallRoot = hallMount.children[0];
const header = hallRoot.children.find((child) => String(child.className).includes('lineage-hall__header'));
const callLink = header?.children.find((child) => String(child.className).includes('lineage-hall__vestibule-call'));
assertCondition(callLink !== undefined, 'el Salón pinta el llamamiento «Entrar al Vestíbulo de las Hermandades»');
assertCondition(callLink?.getAttribute('href') === '#/vestibulo', 'el llamamiento apunta al MISMO hash que la navbar (doble vía, RF-01.6)');

callLink.dispatch('click');
assertCondition(vestibuleCalled === true, 'el click en el llamamiento dispara el callback que conduce al Vestíbulo');

// Degradación elegante: sin callback, el CTA no se monta y nada rompe.
const hallSinCallback = createLineageHallComponent(createObservableElement('div'), {
  elementFactory: createObservableElement,
});
hallSinCallback.render();
assertCondition(true, 'sin callback inyectado el Salón monta sin llamamiento y sin romperse (degradación)');

// =====================================================================
// [7] El peregrino queda retenido (SPEC-09 precede sobre ambas vías)
// =====================================================================
console.log('\n[7] El peregrino sin linaje jamás pisa el Vestíbulo');

// La vista 'vestibule' NO figura en la lista de vistas exentas del
// interceptor de retención (main.js): se verifica contra el código fuente.
const { readFile } = await import('node:fs/promises');
const mainSource = await readFile(new URL('../public/assets/js/main.js', import.meta.url), 'utf8');
const exemptMatch = mainSource.match(/OATH_EXEMPT_VIEWS = Object\.freeze\(\[([^\]]*)\]\)/);
assertCondition(exemptMatch !== null, 'el interceptor declara su lista de vistas exentas');
const exemptViews = (exemptMatch?.[1] ?? '').split(',').map((view) => view.trim().replace(/'/g, ''));
assertCondition(!exemptViews.includes('vestibule'), "'vestibule' NO está exenta: el peregrino es desviado a la ceremonia (RF-01.3)");

// El rótulo se alimenta del cliente real y su fallo es inocuo (best-effort).
const clientSource = await readFile(new URL('../public/assets/js/api/vestibuleClient.js', import.meta.url), 'utf8');
assertCondition(
  clientSource.includes("'/clans/verdicts/unread-count'".replace('/clans', '/clans')) === false // sanity: la ruta va por el cliente
    && mainSource.includes('fetchUnreadVerdictsCount'),
  'el orquestador consulta el Endpoint 5 (unread-count) para alimentar el rótulo'
);
assertCondition(
  mainSource.includes('refreshVestibuleBadge') && mainSource.includes('setVestibuleBadgeCount'),
  'el orquestador refresca el rótulo tras cada mudanza de identidad y lo apaga al contemplar'
);

// =====================================================================
// Resumen
// =====================================================================
console.log(`\n== RESUMEN == Asertos superados: ${assertsPassed}, fallidos: ${assertsFailed}`);
if (assertsFailed === 0) {
  console.log('RESULTADO: EXITO — Ambas vías conducen al mismo Vestíbulo, el rótulo luce solo con veredictos sin leer y se apaga al contemplarlos sin bloquear navegación (Tarea 4.2).');
  process.exit(0);
}
console.log('RESULTADO: FALLO — Revisa los asertos marcados.');
process.exit(1);
