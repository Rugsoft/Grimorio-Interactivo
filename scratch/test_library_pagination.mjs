/**
 * test_library_pagination.mjs — Verificación de la Carga Incremental (Tarea 5.3).
 *
 * Estrategia TDD: este script se escribe ANTES de ampliar libraryView.js.
 * Valida contra el criterio "Hecho cuando" de specs/01-portal-and-navigation.tasks.md:
 *   Si hay más de 50 resultados, presionar «Desenrollar más pergaminos»
 *   carga e inserta los siguientes 50 elementos en la rejilla ocultando el
 *   botón al llegar al final.
 *
 * Requisitos: RF-03.7 (paginación en bloques de 50, concatenación incremental,
 * plan 5.2) y RNF-02 (sin salto de scroll; reutiliza la infraestructura
 * anti-carreras de la Tarea 5.2).
 *
 * Uso: node scratch/test_library_pagination.mjs
 */

import { createLibraryView } from '../public/assets/js/views/libraryView.js';
import { createStore, CATALOG_PAGE_SIZE } from '../public/assets/js/state/store.js';

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

/** Elemento DOM mínimo simulado (patrón consolidado de las Tareas 5.1-5.2). */
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
    scrollTop: 0,
    scrollY: 0,
    disabled: false,
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
    set className(value) { this.classes = new Set(String(value).split(/\s+/).filter(Boolean)); },
    get className() { return [...this.classes].join(' '); },
    get textContent() { return this._textContent; },
    set textContent(value) { this._textContent = String(value); },
    get innerHTML() { throw new Error('PROHIBIDO innerHTML (AGENTS.md 6.1: XSS); usar textContent'); },
    set innerHTML(value) { throw new Error(`PROHIBIDO innerHTML (AGENTS.md 6.1): se intentó escribir '${String(value).slice(0, 40)}'`); },
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
const allByClass = (root, className) => queryByClass(root, className);

const fakeElementFactory = (tagName) => createFakeElement(tagName);

/** Genera `count` DTOs deterministas de catálogo (plan 2.1). */
function buildCatalog(count) {
  const catalog = [];
  for (let index = 0; index < count; index++) {
    catalog.push({
      id: `spl_${String(index).padStart(3, '0')}`,
      slug: `conjuro-${String(index).padStart(3, '0')}`,
      name: `Conjuro ${String(index).padStart(3, '0')}`,
      magicSchool: 'evocation',
      magicSchoolLabel: 'Evocación',
      manaCost: 10 + (index % 50),
      clanName: 'Eruditos Astrales',
      summary: `Esencia arcaica número ${index}.`,
      status: 'validated',
      isGenesisSample: false,
    });
  }
  return catalog;
}

/**
 * Cliente simulado con paginación server-side fiel al contrato del plan 3
 * (offset/limit) y al plan 5.2 (hasMore). Registra cada llamada.
 */
function createPagedSpellClient(totalSpells) {
  const calls = [];
  return {
    calls,
    fetchSpells: async (params) => {
      calls.push({ ...params });
      const all = buildCatalog(totalSpells);
      let items = all;
      if (params.query && String(params.query).length >= 2) {
        items = items.filter((s) => s.name.toLowerCase().includes(String(params.query).toLowerCase()));
      }
      const offset = Number(params.offset ?? 0);
      const limit = Number(params.limit ?? CATALOG_PAGE_SIZE);
      return {
        success: true,
        data: { items: items.slice(offset, offset + limit), hasMore: offset + limit < items.length },
      };
    },
  };
}

console.log('== VERIFICACION TAREA 5.3: libraryView.js (carga incremental) ==\n');

// --- FASE 1: Primera tanda de 50 + botón al pie ---
console.log('FASE 1: Primera tanda de 50 y botón «Desenrollar más pergaminos»');

const store1 = createStore();
const client1 = createPagedSpellClient(120);
const root1 = createFakeElement('main');
const library1 = createLibraryView(root1, {
  store: store1,
  spellClient: client1,
  elementFactory: fakeElementFactory,
  onSpellSelect: () => {},
});
await library1.render();

assertCondition(CATALOG_PAGE_SIZE === 50, 'El store define el bloque canónico de 50 (Tarea 3.2, RF-03.7)');
assertCondition(allByClass(root1, 'spell-card').length === 50, 'La primera tanda renderiza exactamente 50 tarjetas');
assertCondition(byClass(root1, 'library-view__load-more') !== null, 'El botón «Desenrollar más pergaminos» existe al pie de la rejilla');
assertCondition(
  byClass(root1, 'library-view__load-more')?.textContent.includes('Desenrollar más pergaminos'),
  'El botón porta el texto temático exacto (Art. IV)',
);
assertCondition(store1.getState().pagination.offset === 0 && store1.getState().pagination.hasMore === true, 'El store arranca en offset 0 con hasMore=true (plan 4.1)');

// --- FASE 2: CRITERIO — pulsar el botón inserta los SIGUIENTES 50 ---
console.log('\nFASE 2: Criterio (el botón carga e inserta los siguientes 50)');

const tLoadStart = Date.now();
byClass(root1, 'library-view__load-more').dispatch('click');
await wait(20);

assertCondition(allByClass(root1, 'spell-card').length === 100, 'Tras pulsar, la rejilla acumula 100 tarjetas (50 + 50 concatenadas)');
assertCondition(client1.calls.at(-1)?.offset === 50 && client1.calls.at(-1)?.limit === 50, 'La petición viaja con offset=50, limit=50 (plan 5.2)');
assertCondition(store1.getState().pagination.offset === 50, 'El store avanza su offset a 50 (RF-03.7)');
const elapsedLoad = Date.now() - tLoadStart;
assertCondition(elapsedLoad < 150, `La carga incremental completó en ${elapsedLoad} ms (RNF-02)`);
// Concatenación sin sustitución: las 50 primeras tarjetas siguen siendo las mismas.
assertCondition(
  allByClass(root1, 'spell-card')[0].getAttribute('data-slug') === 'conjuro-000' &&
  allByClass(root1, 'spell-card')[49].getAttribute('data-slug') === 'conjuro-049',
  'Las tarjetas previas permanecen intactas: se CONCATENA, no se sustituye (RF-03.7)',
);
assertCondition(allByClass(root1, 'spell-card')[99].getAttribute('data-slug') === 'conjuro-099', 'La tanda nueva llega hasta conjuro-099');

// --- FASE 3: Sin salto de scroll (criterio de tasks.md y plan) ---
console.log('\nFASE 3: La carga incremental no altera la posición de scroll');

const loadMoreButton3 = byClass(root1, 'library-view__load-more');
loadMoreButton3.scrollY = 1337; // el visitante está leyendo al pie de la rejilla.
loadMoreButton3.dispatch('click');
await wait(20);
assertCondition(allByClass(root1, 'spell-card').length === 120, 'Tercera tanda: el catálogo completo (120) queda montado');
assertCondition(loadMoreButton3.scrollY === 1337, 'La posición de scroll NO cambió durante la inserción (sin salto, criterio)');
assertCondition(byClass(root1, 'library-view__load-more') === null, 'Al llegar al final, el botón se OCULTA (criterio: ocultar al llegar al final)');
assertCondition(store1.getState().pagination.hasMore === false, 'El store registra hasMore=false al agotar el catálogo');

// --- FASE 4: Guardas del plan 5.2 (doble click, sin hasMore) ---
console.log('\nFASE 4: Guardas — isLoading y catálogo agotado');

const store4 = createStore();
const client4 = createPagedSpellClient(55);
const root4 = createFakeElement('main');
const library4 = createLibraryView(root4, {
  store: store4,
  spellClient: client4,
  elementFactory: fakeElementFactory,
  onSpellSelect: () => {},
});
await library4.render();
const loadMoreButton4 = byClass(root4, 'library-view__load-more');
loadMoreButton4.dispatch('click');
await wait(20);
assertCondition(allByClass(root4, 'spell-card').length === 55, 'Catálogo de 55: primera tanda 50 + segunda 5');
assertCondition(byClass(root4, 'library-view__load-more') === null, 'Sin hasMore el botón desaparece (aunque queden menos de 50 en la última tanda)');
const callsBefore4 = client4.calls.length;
library4.loadMoreSpells?.();
await wait(20);
assertCondition(client4.calls.length === callsBefore4, 'loadMoreSpells() sin hasMore NO consulta (guarda del plan 5.2)');

// --- FASE 5: La carga incremental convive con los filtros (Tarea 5.2) ---
console.log('\nFASE 5: Paginación + filtros (nueva consulta reinicia offset)');

const store5 = createStore();
const client5 = createPagedSpellClient(120);
const root5 = createFakeElement('main');
const library5 = createLibraryView(root5, {
  store: store5,
  spellClient: client5,
  elementFactory: fakeElementFactory,
  onSpellSelect: () => {},
});
await library5.render();
byClass(root5, 'library-view__load-more').dispatch('click'); // offset -> 50
await wait(20);
const search5 = byClass(root5, 'library-view__search');
search5.value = 'Conjuro 0';
search5.dispatch('input');
await wait(320);
assertCondition(store5.getState().pagination.offset === 0, 'Una nueva búsqueda reinicia la paginación a offset 0 (filtros acumulativos, plan 3)');
const loadMore5 = byClass(root5, 'library-view__load-more');
assertCondition(loadMore5 !== null, 'Tras reiniciar, el botón vuelve a ofrecerse si quedan resultados');
loadMore5.dispatch('click');
await wait(20);
assertCondition(allByClass(root5, 'spell-card').length > 50, 'La paginación sigue funcionando bajo filtros activos');

// --- FASE 6: Fallo de red durante la carga incremental (RF-06.3) ---
console.log('\nFASE 6: Error de red al desenrollar (aviso sin destruir el catálogo)');

const store6 = createStore();
let failing6 = false;
const client6 = {
  fetchSpells: async (params) => {
    if (failing6) {
      return { success: false, error: { code: 'MANA_STREAM_INTERRUPTED', message: 'La corriente de maná vaciló al cargar más conjuros.', recoveryAction: 'RETRY' } };
    }
    const all = buildCatalog(120);
    const offset = Number(params.offset ?? 0);
    return { success: true, data: { items: all.slice(offset, offset + 50), hasMore: offset + 50 < 120 } };
  },
};
const root6 = createFakeElement('main');
const library6 = createLibraryView(root6, {
  store: store6,
  spellClient: client6,
  elementFactory: fakeElementFactory,
  onSpellSelect: () => {},
});
await library6.render();
const cardsBefore6 = allByClass(root6, 'spell-card').length;
failing6 = true;
byClass(root6, 'library-view__load-more').dispatch('click');
await wait(20);
assertCondition(allByClass(root6, 'spell-card').length === cardsBefore6, 'El fallo NO destruye el catálogo ya montado (aviso, plan 5.2)');
assertCondition(byClass(root6, 'library-view__error') !== null, 'El fallo muestra el aviso temático «La corriente de maná vaciló…» (plan 5.2)');

// --- Resumen final ---
console.log('\n== RESUMEN ==');
console.log(`Asertos superados: ${assertsPassed}`);
console.log(`Asertos fallidos:  ${assertsFailed}`);

if (assertsFailed === 0) {
  console.log("\nRESULTADO: EXITO — La Tarea 5.3 cumple su criterio 'Hecho cuando'.");
  process.exit(0);
}

console.log('\nRESULTADO: FALLO — Corregir los asertos marcados con [FALLA].');
process.exit(1);
