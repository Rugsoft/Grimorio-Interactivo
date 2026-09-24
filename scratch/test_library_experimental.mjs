/**
 * test_library_experimental.mjs — Verificación de la Pestaña de Archivos
 * Experimentales (Tarea 5.4).
 *
 * Estrategia TDD: este script se escribe ANTES de ampliar libraryView.js.
 * Valida contra el criterio "Hecho cuando" de specs/01-portal-and-navigation.tasks.md:
 *   Al activar la pestaña experimental, se consultan y renderizan hechizos
 *   no validados con el distintivo de advertencia mística.
 *
 * Requisitos: RF-03.2 (segregación de experimentales), Artículo III
 * (el catálogo público solo revela experimentales con la bandera explícita;
 * por defecto viajan ocultos), en coordinación con store.activeFilters
 * (Tarea 3.2) y el contrato includeExperimental=0/1 del plan 3.
 *
 * Uso: node scratch/test_library_experimental.mjs
 */

import { createLibraryView } from '../public/assets/js/views/libraryView.js';
import { createStore } from '../public/assets/js/state/store.js';

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

/** Elemento DOM mínimo simulado (patrón consolidado de las Tareas 5.1-5.3). */
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

/** Catálogo mixto: validados y experimentales (Art. III). */
const mixedCatalog = [
  { id: '1', slug: 'llamas-de-frieren', name: 'Llamas de Frieren', magicSchool: 'evocation', magicSchoolLabel: 'Evocación', manaCost: 45, clanName: 'Eruditos Astrales', summary: 'Ráfaga de fuego purificador.', status: 'validated', isGenesisSample: false },
  { id: '2', slug: 'boceto-prohibido', name: 'Boceto Prohibido', magicSchool: 'necromancy', magicSchoolLabel: 'Nigromancia', manaCost: 60, clanName: 'Círculo Velado', summary: 'Conjuro sin firmas: inestable y peligroso.', status: 'experimental', isGenesisSample: false },
  { id: '3', slug: 'runa-erratica', name: 'Runa Errática', magicSchool: 'enchantment', magicSchoolLabel: 'Encantamiento', manaCost: 25, clanName: 'Eruditos Astrales', summary: 'Trazo sin validar, poder variable.', status: 'experimental', isGenesisSample: false },
  { id: '4', slug: 'manto-de-abjuracion', name: 'Manto de Abjuración', magicSchool: 'abjuration', magicSchoolLabel: 'Abjuración', manaCost: 30, clanName: 'Eruditos Astrales', summary: 'Barrera protectora de maná denso.', status: 'validated', isGenesisSample: false },
];

/**
 * Cliente simulado fiel al contrato del plan 3: `includeExperimental` 0/1
 * decide si los experimentales viajan (el backend los SEGREGA por defecto).
 */
function createSegmentedSpellClient() {
  const calls = [];
  return {
    calls,
    fetchSpells: async (params) => {
      calls.push({ ...params });
      const includeExperimental = params.includeExperimental === 1 || params.includeExperimental === '1';
      let items = mixedCatalog.filter((spell) =>
        includeExperimental ? true : spell.status === 'validated');
      // El backend filtra por query con su UDF norm (Tarea 1.4):
      if (params.query && String(params.query).length >= 2) {
        const q = String(params.query).toLowerCase();
        items = items.filter((s) => s.name.toLowerCase().includes(q) || s.summary.toLowerCase().includes(q));
      }
      return { success: true, data: { items, hasMore: false } };
    },
  };
}

console.log('== VERIFICACION TAREA 5.4: libraryView.js (archivos experimentales) ==\n');

// --- FASE 1: Por defecto los experimentales NO viajan (Art. III) ---
console.log('FASE 1: Estado inicial — experimentales segregados por defecto');

const store1 = createStore();
const client1 = createSegmentedSpellClient();
const root1 = createFakeElement('main');
const library1 = createLibraryView(root1, {
  store: store1,
  spellClient: client1,
  elementFactory: fakeElementFactory,
  onSpellSelect: () => {},
});
await library1.render();

assertCondition(client1.calls[0]?.includeExperimental === 0, 'La consulta inicial viaja con includeExperimental=0 (Art. III, plan 3)');
assertCondition(store1.getState().activeFilters.includeExperimental === false, 'El store arranca con includeExperimental=false (plan 4.1)');
const initialSlugs1 = allByClass(root1, 'spell-card').map((c) => c.getAttribute('data-slug'));
assertCondition(
  initialSlugs1.length === 2 && !initialSlugs1.includes('boceto-prohibido') && !initialSlugs1.includes('runa-erratica'),
  'El catálogo público inicial solo muestra los validados (segregación, Art. III)',
);
assertCondition(byClass(root1, 'library-filter__experimental') !== null, 'El conmutador de Archivos Experimentales existe (RF-03.2)');
assertCondition(
  byClass(root1, 'library-filter__experimental')?.textContent.includes('Archivos Experimentales'),
  'El conmutador porta el texto temático exacto (Art. IV)',
);

// --- FASE 2: CRITERIO — activar la pestaña consulta y renderiza experimentales ---
console.log('\nFASE 2: Criterio (activar la pestaña revela los no validados con advertencia)');

const experimentalToggle2 = byClass(root1, 'library-filter__experimental');
const toggleInput2 = allByClass(root1, 'library-filter__experimental-checkbox')[0];
toggleInput2.checked = true;
toggleInput2.dispatch('change');
await wait(320); // supera el debounce de la Tarea 5.2

assertCondition(client1.calls.at(-1)?.includeExperimental === 1, 'Activada la pestaña, la consulta viaja con includeExperimental=1 (plan 3)');
assertCondition(store1.getState().activeFilters.includeExperimental === true, 'El store registra includeExperimental=true (Tarea 3.2)');
const slugs2 = allByClass(root1, 'spell-card').map((c) => c.getAttribute('data-slug'));
assertCondition(
  slugs2.includes('boceto-prohibido') && slugs2.includes('runa-erratica') && slugs2.includes('llamas-de-frieren'),
  'El catálogo ampliado incluye los experimentales junto a los validados (criterio)',
);

const unstableBadges = allByClass(root1, 'spell-card__badge--unstable');
assertCondition(unstableBadges.length === 2, 'Cada experimental porta el sello de advertencia mística «Inestabilidad Arcana» (RF-03.2)');
assertCondition(
  unstableBadges.every((badge) => badge.textContent.length > 0),
  'Los sellos de inestabilidad llevan su leyenda visible',
);

// --- FASE 3: Desactivar vuelve a segregar (conmutador bidireccional) ---
console.log('\nFASE 3: Desactivar la pestaña restablece la segregación');

toggleInput2.checked = false;
toggleInput2.dispatch('change');
await wait(320);
assertCondition(client1.calls.at(-1)?.includeExperimental === 0, 'Desactivada la pestaña, includeExperimental vuelve a 0 (plan 3)');
assertCondition(store1.getState().activeFilters.includeExperimental === false, 'El store vuelve a includeExperimental=false');
const slugs3 = allByClass(root1, 'spell-card').map((c) => c.getAttribute('data-slug'));
assertCondition(
  slugs3.length === 2 && !slugs3.includes('boceto-prohibido'),
  'Los experimentales desaparecen del listado público (segregación restaurada)',
);
assertCondition(allByClass(root1, 'spell-card__badge--unstable').length === 0, 'Sin experimentales no quedan sellos de inestabilidad en la rejilla');

// --- FASE 4: La pestaña convive con la paginación y los demás filtros ---
console.log('\nFASE 4: Convivencia con paginación y filtros (Tareas 5.2/5.3)');

const store4 = createStore();
const client4 = createSegmentedSpellClient();
const root4 = createFakeElement('main');
const library4 = createLibraryView(root4, {
  store: store4,
  spellClient: client4,
  elementFactory: fakeElementFactory,
  onSpellSelect: () => {},
});
await library4.render();

// Con la pestaña activa, una búsqueda nueva NO pierde la bandera (acumulativos):
const toggleInput4 = allByClass(root4, 'library-filter__experimental-checkbox')[0];
toggleInput4.checked = true;
toggleInput4.dispatch('change');
await wait(320);
const search4 = byClass(root4, 'library-view__search');
search4.value = 'boceto';
search4.dispatch('input');
await wait(320);
assertCondition(
  client4.calls.at(-1)?.includeExperimental === 1 && client4.calls.at(-1)?.offset === 0,
  'La búsqueda con pestaña activa conserva includeExperimental=1 y reinicia offset (filtros acumulativos, plan 3)',
);
const slugs4 = allByClass(root4, 'spell-card').map((c) => c.getAttribute('data-slug'));
assertCondition(slugs4.includes('boceto-prohibido'), 'El experimental encontrado por búsqueda sigue visible bajo la pestaña activa');
assertCondition(slugs4.every((slug) => slug === 'boceto-prohibido'), 'La búsqueda filtra con normalización del backend y la bandera activa');

// Desactivar tras buscar vuelve a segregar:
toggleInput4.checked = false;
toggleInput4.dispatch('change');
await wait(320);
assertCondition(
  client4.calls.at(-1)?.includeExperimental === 0,
  'Desactivar tras buscar restaura la segregación (conmutador coherente en todo momento)',
);

// --- FASE 5: Ciclo de vida intacto (regresión estructural de las 5.2/5.3) ---
console.log('\nFASE 5: Re-render idempotente y destroy()');

const store5 = createStore();
const client5 = createSegmentedSpellClient();
const root5 = createFakeElement('main');
const library5 = createLibraryView(root5, {
  store: store5,
  spellClient: client5,
  elementFactory: fakeElementFactory,
  onSpellSelect: () => {},
});
await library5.render();
await library5.render();
assertCondition(allByClass(root5, 'spell-card').length === 2, 'Re-render idempotente: catálogo validado sin duplicados');
library5.destroy();
assertCondition(allByClass(root5, 'spell-card').length === 0, 'destroy() limpia la vista del montaje');

// --- Resumen final ---
console.log('\n== RESUMEN ==');
console.log(`Asertos superados: ${assertsPassed}`);
console.log(`Asertos fallidos:  ${assertsFailed}`);

if (assertsFailed === 0) {
  console.log("\nRESULTADO: EXITO — La Tarea 5.4 cumple su criterio 'Hecho cuando'.");
  process.exit(0);
}

console.log('\nRESULTADO: DENEGADO — Corregir los asertos marcados con [FALLA].');
process.exit(1);
