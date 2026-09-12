/**
 * test_library_view.mjs — Verificación de la Vista de Biblioteca (Tarea 5.2).
 *
 * Estrategia TDD: este script se escribe ANTES que libraryView.js.
 * Valida contra el criterio "Hecho cuando" de specs/01-portal-and-navigation.tasks.md:
 *   Escribir en el buscador o alternar filtros actualiza el listado visible
 *   en menos de 150 ms sin refrescar la página.
 *
 * Requisitos: RF-03.3 (búsqueda reactiva sin tildes/mayúsculas, umbral de 2
 * caracteres), RF-03.5 (checkboxes de Escuelas operando bajo unión OR),
 * RF-03.6 (deslizador de tope de maná <=), RNF-02 (latencia < 150 ms), y
 * persistencia de los filtros en el store reactivo (Tarea 3.2).
 *
 * Uso: node scratch/test_library_view.mjs
 */

import { createLibraryView } from '../public/assets/js/views/libraryView.js';
import { createStore } from '../public/assets/js/state/store.js';
import { normalizeSearchText } from '../public/assets/js/utils/textNormalizer.js';

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

/** Espera real de milisegundos (latencias observables, no simuladas). */
const wait = (ms) => new Promise((resolve) => setTimeout(resolve, ms));

/**
 * Elemento DOM mínimo simulado (patrón consolidado Tareas 4.1-5.1).
 * innerHTML PROHIBIDO (AGENTS.md 6.1); value para inputs; checked para radios.
 */
function createFakeElement(tagName) {
  const element = {
    tagName: String(tagName).toUpperCase(),
    children: [],
    attributes: {},
    classes: new Set(),
    listeners: {},
    _textContent: '',
    _value: '',
    _checked: false,
    parentElement: null,
    focusCount: 0,
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

/** Búsqueda recursiva por clase (querySelector real). */
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

/** Catálogo que la API retornaría (mezcla de escuelas y manás variados). */
const catalogSpells = [
  { id: '1', slug: 'llamas-de-frieren', name: 'Llamas de Frieren', magicSchool: 'evocation', magicSchoolLabel: 'Evocación', manaCost: 45, clanName: 'Eruditos Astrales', summary: 'Ráfaga de fuego purificador.', status: 'validated', isGenesisSample: false },
  { id: '2', slug: 'manto-de-abjuracion', name: 'Manto de Abjuración', magicSchool: 'abjuration', magicSchoolLabel: 'Abjuración', manaCost: 30, clanName: 'Eruditos Astrales', summary: 'Barrera protectora de maná denso.', status: 'validated', isGenesisSample: false },
  { id: '3', slug: 'ojo-del- augurio', name: 'Ojo del Augurio', magicSchool: 'divination', magicSchoolLabel: 'Adivinación', manaCost: 15, clanName: 'Círculo Velado', summary: 'Revela lo oculto durante un instante.', status: 'validated', isGenesisSample: true },
  { id: '4', slug: 'tormenta-electrica', name: 'Tormenta Eléctrica', magicSchool: 'evocation', magicSchoolLabel: 'Evocación', manaCost: 80, clanName: 'Eruditos Astrales', summary: 'Descarga masiva sobre el campo enemigo.', status: 'validated', isGenesisSample: false },
  { id: '5', slug: 'bruma-ilusoria', name: 'Bruma Ilusoria', magicSchool: 'illusion', magicSchoolLabel: 'Ilusión', manaCost: 20, clanName: 'Círculo Velado', summary: 'Niebla que confunde los sentidos.', status: 'validated', isGenesisSample: false },
];

/**
 * spellClient simulado: responde al contrato del plan 3 (query, schools CSV,
 * maxMana, includeExperimental, offset, limit) filtrando server-side como lo
 * haría el backend (la UDF norm de SQLite, Tarea 1.4).
 */
function createFakeSpellClient(latencyMs = 0) {
  const calls = [];
  return {
    calls,
    fetchSpells: async (params) => {
      calls.push({ ...params, fetchedAt: Date.now() });
      if (latencyMs > 0) await wait(latencyMs);
      let items = catalogSpells;
      if (params.query && String(params.query).length >= 2) {
        const q = normalizeSearchText(String(params.query));
        items = items.filter((s) =>
          normalizeSearchText(s.name).includes(q) || normalizeSearchText(s.summary).includes(q));
      }
      if (params.schools) {
        const wanted = String(params.schools).split(',').filter(Boolean);
        if (wanted.length > 0) items = items.filter((s) => wanted.includes(s.magicSchool)); // unión OR
      }
      if (params.maxMana != null && params.maxMana !== '') {
        items = items.filter((s) => s.manaCost <= Number(params.maxMana)); // tope <=
      }
      const offset = Number(params.offset ?? 0);
      const limit = Number(params.limit ?? 50);
      return { success: true, data: { items: items.slice(offset, offset + limit), hasMore: offset + limit < items.length } };
    },
  };
}

console.log('== VERIFICACION TAREA 5.2: libraryView.js ==\n');

// --- FASE 1: Estructura de la biblioteca y filtros operativos ---
console.log('FASE 1: Estructura, buscador y controles de filtro');

const store1 = createStore();
const client1 = createFakeSpellClient();
const root1 = createFakeElement('main');
const library1 = createLibraryView(root1, {
  store: store1,
  spellClient: client1,
  elementFactory: fakeElementFactory,
  onSpellSelect: () => {},
});

await library1.render();

assertCondition(byClass(root1, 'library-view') !== null, 'La biblioteca monta su sección raíz');
assertCondition(byClass(root1, 'library-view__search') !== null, 'La barra de búsqueda existe (RF-03.3)');

const schoolCheckboxes1 = allByClass(root1, 'library-filter__school');
assertCondition(schoolCheckboxes1.length === 8, 'Los 8 checkboxes de Escuelas canónicas del semillero existen (RF-03.5)');
assertCondition(
  schoolCheckboxes1.every((checkbox) => checkbox.getAttribute('data-school') !== null),
  'Cada checkbox declara su escuela (data-school)',
);

const manaRange1 = byClass(root1, 'library-filter__max-mana');
assertCondition(manaRange1 !== null, 'El control deslizante de tope de maná existe (RF-03.6)');
assertCondition(
  manaRange1 !== null && manaRange1.getAttribute('type') === 'range',
  'El tope de maná es un input[type=range] nativo (Art. I)',
);
const manaOutput1 = byClass(root1, 'library-filter__max-mana-value');
assertCondition(manaOutput1 !== null && manaOutput1.textContent.length > 0, 'El valor del tope de maná se muestra en vivo');

const cards1 = allByClass(root1, 'spell-card');
assertCondition(cards1.length === 5, 'El catálogo inicial renderiza las 5 tarjetas');

// --- FASE 2: CRITERIO — búsqueda reactiva en < 150 ms, sin refresco ---
console.log('\nFASE 2: Criterio (buscador reactualiza el listado en < 150 ms)');

const searchInput2 = byClass(root1, 'library-view__search');
searchInput2.value = 'FUEGO PURIFICADOR'; // mayúsculas y sin tildes: RF-03.3
const tSearchStart = Date.now();
searchInput2.dispatch('input');
await wait(320); // supera holgadamente el debounce del plan (250 ms)
const searchElapsed = Date.now() - tSearchStart - 320; // tiempo efectivo de trabajo

assertCondition(allByClass(root1, 'spell-card').length === 1, 'La búsqueda insensible filtra a 1 tarjeta («llamas-de-frieren», RF-03.3)');
assertCondition(
  byClass(root1, 'library-view__search') !== null && searchElapsed < 150,
  `El listado se actualizó en ${searchElapsed} ms efectivos tras el debounce (< 150 ms, criterio)`,
);
assertCondition(
  client1.calls.at(-1)?.query === 'FUEGO PURIFICADOR' || client1.calls.at(-1)?.query === 'fuego purificador',
  'La query viaja al backend (el servidor normaliza con su UDF norm, Tarea 1.4)',
);
assertCondition(store1.getState().activeFilters.query === 'FUEGO PURIFICADOR', 'La query queda registrada en store.activeFilters (RF-03.3, Tarea 3.2)');
assertCondition(store1.getState().currentView === 'library', 'La vista activa del store es library (plan 4.1)');

// Umbral de 2 caracteres (plan 5.1): 1 carácter no filtra.
searchInput2.value = 'f';
searchInput2.dispatch('input');
await wait(320);
assertCondition(allByClass(root1, 'spell-card').length === 5, 'Con 1 solo carácter la búsqueda NO filtra (umbral del plan 5.1)');

// --- FASE 3: CRITERIO — alternar filtros actualiza < 150 ms ---
console.log('\nFASE 3: Criterio (filtros de escuelas y maná reaccionan en < 150 ms)');

const evocation3 = allByClass(root1, 'library-filter__school').find((c) => c.getAttribute('data-school') === 'evocation');
const illusion3 = allByClass(root1, 'library-filter__school').find((c) => c.getAttribute('data-school') === 'illusion');

evocation3.checked = true;
const tFilterStart = Date.now();
evocation3.dispatch('change');
assertCondition(
  [...store1.getState().activeFilters.schools].includes('evocation'),
  'Marcar Evocación actualiza store.activeFilters.schools de inmediato (RF-03.5)',
);
illusion3.checked = true;
illusion3.dispatch('change');
await wait(320);
const filterElapsed = Date.now() - tFilterStart - 320;

assertCondition(
  [...store1.getState().activeFilters.schools].includes('evocation') &&
  [...store1.getState().activeFilters.schools].includes('illusion'),
  'Dos escuelas marcadas conviven en el filtro (unión OR, RF-03.5)',
);
const orSlugs3 = allByClass(root1, 'spell-card').map((c) => c.getAttribute('data-slug'));
assertCondition(
  orSlugs3.length === 3 && orSlugs3.includes('llamas-de-frieren') && orSlugs3.includes('tormenta-electrica') && orSlugs3.includes('bruma-ilusoria'),
  'La unión OR muestra los 2 de evocación + el de ilusión (3 tarjetas, RF-03.5)',
);
assertCondition(filterElapsed < 150, `Alternar filtros actualizó el listado en ${filterElapsed} ms efectivos (< 150 ms, criterio)`);

// Desmarcar restaura (la query 'f' de 1 carácter sigue sin filtrar):
illusion3.checked = false;
illusion3.dispatch('change');
await wait(320);
const evocationSlugs = allByClass(root1, 'spell-card').map((c) => c.getAttribute('data-slug'));
assertCondition(
  evocationSlugs.length === 2 && evocationSlugs.includes('llamas-de-frieren') && evocationSlugs.includes('tormenta-electrica'),
  'Desmarcar Ilusión deja exactamente los 2 hechizos de Evocación (unión OR consistente)',
);

// Tope de maná (RF-03.6):
searchInput2.value = '';
searchInput2.dispatch('input');
evocation3.checked = false;
evocation3.dispatch('change');
illusion3.checked = false;
illusion3.dispatch('change');
await wait(320);
manaRange1.value = '25';
manaRange1.dispatch('input');
await wait(320);
assertCondition(store1.getState().activeFilters.maxMana === 25, 'El deslizador registra maxMana=25 en el store (RF-03.6)');
const manaCards = allByClass(root1, 'spell-card').map((c) => c.getAttribute('data-slug'));
assertCondition(
  manaCards.length === 2 && manaCards.includes('ojo-del- augurio') && manaCards.includes('bruma-ilusoria'),
  'El tope <= 25 maná muestra solo los hechizos baratos (RF-03.6)',
);
assertCondition(
  byClass(root1, 'library-filter__max-mana-value') !== null &&
  /25/.test(byClass(root1, 'library-filter__max-mana-value').textContent),
  'La etiqueta de maná se actualiza en vivo con el deslizador',
);

// --- FASE 4: Sanitización y error controlado (AGENTS 6.1, RF-06.3) ---
console.log('\nFASE 4: Robustez — query hostil y fallo de red');

const store4 = createStore();
const client4 = {
  fetchSpells: async () => ({ success: false, error: { code: 'MANA_STREAM_INTERRUPTED', message: 'La corriente de maná se ha interrumpido.', recoveryAction: 'RETRY' } }),
};
const root4 = createFakeElement('main');
const library4 = createLibraryView(root4, {
  store: store4,
  spellClient: client4,
  elementFactory: fakeElementFactory,
  onSpellSelect: () => {},
});
await library4.render();

assertCondition(byClass(root4, 'library-view__error') !== null, 'Un fallo de la API muestra el estado de error temático (RF-06.3)');
assertCondition(byClass(root4, 'library-view__error-retry') !== null, 'El error ofrece botón de reintento (recoveryAction RETRY)');

const search4 = byClass(root4, 'library-view__search');
search4.value = '<script>alert(1)</script>';
search4.dispatch('input');
await wait(320);
assertCondition(
  store4.getState().activeFilters.query === '<script>alert(1)</script>',
  'La query hostil viaja como DATO (nunca se ejecuta: cero innerHTML en la vista)',
);

// Reintento con cliente recuperado (se limpia primero la query hostil):
search4.value = '';
search4.dispatch('input');
await wait(320); // el reintento implícito aún choca con el cliente roto
const client4b = createFakeSpellClient();
library4.setSpellClient?.(client4b);
await library4.retry?.();
assertCondition(byClass(root4, 'library-view__error') === null && allByClass(root4, 'spell-card').length === 5, 'El reintento con cliente recuperado restaura el catálogo completo');

// --- FASE 5: Ciclo de vida y selección ---
console.log('\nFASE 5: Selección de tarjeta, re-render idempotente y destroy()');

const selected5 = [];
const store5 = createStore();
const client5 = createFakeSpellClient();
const root5 = createFakeElement('main');
const library5 = createLibraryView(root5, {
  store: store5,
  spellClient: client5,
  elementFactory: fakeElementFactory,
  onSpellSelect: (slug) => selected5.push(slug),
});
await library5.render();
allByClass(root5, 'spell-card')[0].dispatch('click');
assertCondition(selected5.length === 1 && selected5[0] === 'llamas-de-frieren', 'El clic en una tarjeta emite su slug (abrirá la ficha, Tarea 4.3)');

await library5.render();
assertCondition(allByClass(root5, 'spell-card').length === 5, 'Re-render idempotente: no duplica tarjetas');

library5.destroy();
assertCondition(allByClass(root5, 'spell-card').length === 0, 'destroy() limpia la vista del montaje');

// --- Resumen final ---
console.log('\n== RESUMEN ==');
console.log(`Asertos superados: ${assertsPassed}`);
console.log(`Asertos fallidos:  ${assertsFailed}`);

if (assertsFailed === 0) {
  console.log("\nRESULTADO: EXITO — La Tarea 5.2 cumple su criterio 'Hecho cuando'.");
  process.exit(0);
}

console.log('\nRESULTADO: FALLO — Corregir los asertos marcados con [FALLA].');
process.exit(1);
