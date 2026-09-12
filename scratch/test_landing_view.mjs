/**
 * test_landing_view.mjs — Verificación de la Vista de Portada (Tarea 5.1).
 *
 * Estrategia TDD: este script se escribe ANTES que landingView.js.
 * Valida contra el criterio "Hecho cuando" de specs/01-portal-and-navigation.tasks.md:
 *   La portada renderiza los 3 destacados obtenidos de la API y el clic en
 *   «Consagrar Linaje» despliega el diálogo «Cruzar el Umbral».
 *
 * Requisitos: RF-01.1 (narrativa introductoria), RF-01.4 (botón de
 * consagración → interceptación de acceso), RF-01.5 (galería de 3 destacados
 * reutilizando spellCardComponent).
 *
 * Uso: node scratch/test_landing_view.mjs
 */

import { createLandingView } from '../public/assets/js/views/landingView.js';

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
 * Elemento DOM mínimo simulado (patrón consolidado de las Tareas 4.1-4.4).
 * innerHTML está PROHIBIDO (AGENTS.md 6.1): su uso lanza excepción.
 */
function createFakeElement(tagName) {
  const element = {
    tagName: String(tagName).toUpperCase(),
    children: [],
    attributes: {},
    classes: new Set(),
    listeners: {},
    style: { setProperty(name, value) { (this.inline ??= {})[name] = String(value); }, getProperty(name) { return (this.inline ?? {})[name] ?? null; } },

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

/** Búsqueda recursiva de descendientes que porten una clase (querySelector real). */
function queryByClass(node, className, found = []) {
  for (const child of node.children) {
    if (child.classes.has(className)) found.push(child);
    queryByClass(child, className, found);
  }
  return found;
}

/** Primer descendiente con la clase dada (querySelector real). */
function querySelectorByClass(root, className) {
  return queryByClass(root, className)[0] ?? null;
}

/** Todos los descendientes con la clase dada (querySelectorAll real). */
function querySelectorAllByClass(root, className) {
  return queryByClass(root, className);
}

const fakeElementFactory = (tagName) => createFakeElement(tagName);

/** Los 3 destacados que la API del portal retornaría (plan 2.1 / Tarea 1.5). */
const featuredSpells = [
  {
    id: 'spl_genesis_01',
    slug: 'chispa-de-ignicion',
    name: 'Chispa de Ignición',
    magicSchool: 'evocation',
    magicSchoolLabel: 'Evocación',
    manaCost: 10,
    clanId: 'cln_primordial',
    clanName: 'Linaje Primordial',
    summary: 'El primer conjuro del grimorio: una llama diminuta que responde al aliento del invocador.',
    status: 'validated',
    isGenesisSample: true,
    validatedAt: '2026-01-01T00:00:00Z',
  },
  {
    id: 'spl_9f8b2c1a',
    slug: 'llamas-de-frieren',
    name: 'Llamas de Frieren',
    magicSchool: 'evocation',
    magicSchoolLabel: 'Evocación',
    manaCost: 45,
    clanId: 'cln_astral_scholars',
    clanName: 'Eruditos Astrales',
    summary: 'Proyecta una ráfaga continua de fuego purificador que calcina barreras mágicas.',
    status: 'validated',
    isGenesisSample: false,
    validatedAt: '2026-09-10T14:30:00Z',
  },
  {
    id: 'spl_2c7d1e4b',
    slug: 'manto-de-niebla',
    name: 'Manto de Niebla',
    magicSchool: 'illusion',
    magicSchoolLabel: 'Ilusionismo',
    manaCost: 20,
    clanId: 'cln_veiled_circle',
    clanName: 'Círculo Velado',
    summary: 'Envuelve al conjurador en una bruma impenetrable que confunde los sentidos enemigos.',
    status: 'validated',
    isGenesisSample: false,
    validatedAt: '2026-09-08T09:00:00Z',
  },
];

/** Sobre de error controlado del cliente HTTP (Tarea 3.3). */
const networkErrorEnvelope = {
  success: false,
  error: { code: 'MANA_STREAM_INTERRUPTED', message: 'La corriente de maná se ha interrumpido.', recoveryAction: 'RETRY' },
};

console.log('== VERIFICACION TAREA 5.1: landingView.js ==\n');

// --- FASE 1: Render de la narrativa introductoria (RF-01.1) ---
console.log('FASE 1: Narrativa introductoria de la portada');

const root1 = createFakeElement('main');
const spellClient1 = { fetchFeatured: async () => ({ success: true, data: featuredSpells }) };
const landing1 = createLandingView(root1, {
  spellClient: spellClient1,
  elementFactory: fakeElementFactory,
  onReservedAction: () => {},
  onSpellSelect: () => {},
});

await landing1.render();

const title1 = querySelectorByClass(root1, 'landing-hero__title');
assertCondition(title1 !== null, 'La portada renderiza un título hero principal (RF-01.1)');
assertCondition(title1 !== null && title1.textContent.length > 0, 'El título hero porta la narrativa introductoria');

const intro1 = querySelectorByClass(root1, 'landing-hero__intro');
assertCondition(intro1 !== null && intro1.textContent.length > 0, 'Existe un párrafo introductorio solemne (RF-01.1)');

const consagrate1 = querySelectorByClass(root1, 'landing-hero__cta');
assertCondition(consagrate1 !== null, 'El botón destacado «Consagrar Linaje» existe (RF-01.4)');
assertCondition(
  consagrate1 !== null && consagrate1.textContent.includes('Consagrar Linaje'),
  'El CTA porta el texto solemne exacto «Consagrar Linaje» (Art. IV)',
);

// --- FASE 2: Galería con los 3 destacados de la API (RF-01.5) ---
console.log('\nFASE 2: Galería de los 3 hechizos destacados');

const cards2 = querySelectorAllByClass(root1, 'spell-card');
assertCondition(cards2.length === 3, 'La galería renderiza EXACTAMENTE 3 tarjetas destacadas (RF-01.5)');
assertCondition(
  querySelectorAllByClass(root1, 'landing-view__featured').length === 1,
  'La galería de destacados vive en su sección propia',
);
const slugs2 = cards2.map((card) => card.getAttribute('data-slug'));
assertCondition(
  slugs2.includes('chispa-de-ignicion') && slugs2.includes('llamas-de-frieren') && slugs2.includes('manto-de-niebla'),
  'Las tarjetas reutilizan spellCardComponent con los slugs de la API (data-slug)',
);
assertCondition(
  cards2.every((card) => card.getAttribute('tabindex') === '0'),
  'Las tarjetas destacadas heredan la accesibilidad por teclado del componente (RNF-03)',
);

// --- FASE 3: CRITERIO — clic en «Consagrar Linaje» despliega «Cruzar el Umbral» ---
console.log('\nFASE 3: Criterio (Consagrar Linaje → Cruzar el Umbral)');

const reservedActions3 = [];
const root3 = createFakeElement('main');
const landing3 = createLandingView(root3, {
  spellClient: spellClient1,
  elementFactory: fakeElementFactory,
  onReservedAction: (action) => reservedActions3.push(action),
  onSpellSelect: () => {},
});

await landing3.render();

const consagrate3 = querySelectorByClass(root3, 'landing-hero__cta');
consagrate3.dispatch('click');

assertCondition(reservedActions3.length === 1, 'El clic en «Consagrar Linaje» emite la acción reservada (RF-01.4)');
assertCondition(
  reservedActions3[0] === 'joinClan',
  'La acción emitida es la intención de consagración (joinClan → abrirá «Cruzar el Umbral»)',
);
assertCondition(
  consagrate3.getAttribute('data-reserved') === 'true',
  'El botón declara data-reserved (contrato de interceptación con el orquestador)',
);

// La activación por teclado también dispara la intercepción (RNF-03):
consagrate3.dispatch('keydown', { key: 'Enter' });
assertCondition(reservedActions3.length === 2, 'Enter sobre el CTA también intercepta (accesibilidad, RNF-03)');

// --- FASE 4: Selección de un destacado emite su slug (RF-01.5 → ficha) ---
console.log('\nFASE 4: Selección de un destacado emite el slug');

const selected4 = [];
const root4 = createFakeElement('main');
const landing4 = createLandingView(root4, {
  spellClient: spellClient1,
  elementFactory: fakeElementFactory,
  onReservedAction: () => {},
  onSpellSelect: (slug) => selected4.push(slug),
});

await landing4.render();

const card4 = querySelectorAllByClass(root4, 'spell-card')[0];
card4.dispatch('click');

assertCondition(selected4.length === 1 && selected4[0] === 'chispa-de-ignicion', 'El clic en una tarjeta destacada emite el slug al orquestador (abrirá la ficha)');

// --- FASE 5: Estado de carga y error de red controlado (RF-06.3, plan 6) ---
console.log('\nFASE 5: Carga y error de red controlado');

const root5 = createFakeElement('main');
let resolveFetch5;
const landing5 = createLandingView(root5, {
  spellClient: { fetchFeatured: () => new Promise((resolve) => { resolveFetch5 = resolve; }) },
  elementFactory: fakeElementFactory,
  onReservedAction: () => {},
  onSpellSelect: () => {},
});

const pending5 = landing5.render();
assertCondition(
  querySelectorByClass(root5, 'landing-view__loading') !== null,
  'Mientras la API responde, la portada muestra su estado de invocación',
);
resolveFetch5({ success: true, data: featuredSpells });
await pending5;
assertCondition(
  querySelectorByClass(root5, 'landing-view__loading') === null && querySelectorAllByClass(root5, 'spell-card').length === 3,
  'Resuelta la carga, el estado de invocación desaparece y quedan las 3 tarjetas',
);

const root5b = createFakeElement('main');
const landing5b = createLandingView(root5b, {
  spellClient: { fetchFeatured: async () => networkErrorEnvelope },
  elementFactory: fakeElementFactory,
  onReservedAction: () => {},
  onSpellSelect: () => {},
});
await landing5b.render();
assertCondition(
  querySelectorByClass(root5b, 'landing-view__error') !== null,
  'Un corte de red muestra el estado de error temático (RF-06.3) sin lanzar excepción',
);
assertCondition(
  querySelectorByClass(root5b, 'landing-view__error-retry') !== null,
  'El error ofrece el botón de reintento (recoveryAction RETRY, Tarea 3.3)',
);

// --- FASE 6: Idempotencia del render y limpieza (RNF-05, ciclo de vida) ---
console.log('\nFASE 6: Re-render idempotente y destroy()');

const root6 = createFakeElement('main');
const landing6 = createLandingView(root6, {
  spellClient: spellClient1,
  elementFactory: fakeElementFactory,
  onReservedAction: () => {},
  onSpellSelect: () => {},
});
await landing6.render();
await landing6.render();
assertCondition(
  querySelectorAllByClass(root6, 'spell-card').length === 3,
  'Un segundo render no duplica las tarjetas (idempotente, como hará main.js)',
);

landing6.destroy();
const cardsAfterDestroy = querySelectorAllByClass(root6, 'spell-card');
assertCondition(cardsAfterDestroy.length === 0, 'destroy() limpia la vista del punto de montaje');

// --- Resumen final ---
console.log('\n== RESUMEN ==');
console.log(`Asertos superados: ${assertsPassed}`);
console.log(`Asertos fallidos:  ${assertsFailed}`);

if (assertsFailed === 0) {
  console.log("\nRESULTADO: EXITO — La Tarea 5.1 cumple su criterio 'Hecho cuando'.");
  process.exit(0);
}

console.log('\nRESULTADO: FALLO — Corregir los asertos marcados con [FALLA].');
process.exit(1);
