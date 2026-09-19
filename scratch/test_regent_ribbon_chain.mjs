/**
 * test_regent_ribbon_chain.mjs — Arnés de la cadena completa del ribete
 * ceremonial dorado del Clan Regente (SPEC-07, RF-04.4, hueco gris A).
 *
 * Lo que los arneses previos no ejercitaban en una sola cadena:
 *   [1] El contrato del DTO canónico: REGENT_RIBBON_CLASS y data-regent.
 *   [2] La victoria cambia de manos: la cadena actualiza el podio (el
 *       regente previo pierde el ribete) y el ribete migra a la nueva casa.
 *   [3] El ribete de nacimiento: tarjetas paginadas vía «Desenrollar más
 *       pergaminos» nacen ya ceñidas cuando el regente ya era conocido.
 *   [4] El contrato CSS: filo de oro desde tokens (cero literales de color),
 *       resplandor arcano y doble anillo interior.
 *   [5] El movimiento reducido: el pulso del doble anillo se anula bajo
 *       prefers-reduced-motion (RNF-03).
 *
 * Constitución:
 *   - Artículo I (Dogma Vanilla): doble del DOM forjado a mano, cero jsdom.
 *   - Artículo V: identificadores en inglés camelCase, asertos en castellano.
 *
 * Ejecución: node scratch/test_regent_ribbon_chain.mjs  (exit 0 = verde)
 */

let assertsPassed = 0;
let assertsFailed = 0;

function assertCondition(condition, description) {
  if (condition) {
    assertsPassed++;
    console.log(`  [PASA] ${description}`);
  } else {
    assertsFailed++;
    console.log(`  [FALLA] ${description}`);
  }
}

const flushMicrotasks = () => new Promise((resolve) => setTimeout(resolve, 0));

// =====================================================================
// Dobles del navegador (mismas forjas canónicas de los arneses previos)
// =====================================================================

/** Elemento DOM simulado con burbujeo, clases, atributos y controles. */
function createFakeElement(tagName, ownerDocument = null) {
  const element = {
    tagName: String(tagName).toUpperCase(),
    ownerDocument,
    children: [],
    attributes: {},
    classes: new Set(),
    listeners: {},
    style: {
      inline: {},
      setProperty(name, value) { this.inline[name] = String(value); },
      getProperty(name) { return this.inline[name] ?? null; },
    },
    parentElement: null,
    _textContent: '',
    _value: '',
    disabled: false,
    hidden: false,
    setAttribute(name, value) {
      this.attributes[name] = String(value);
      if (name === 'class') this.classes = new Set(String(value).split(/\s+/).filter(Boolean));
    },
    getAttribute(name) { return name in this.attributes ? this.attributes[name] : null; },
    removeAttribute(name) { delete this.attributes[name]; },
    hasAttribute(name) { return name in this.attributes; },
    addEventListener(name, listener) { (this.listeners[name] ??= []).push(listener); },
    removeEventListener(name, listener) {
      this.listeners[name] = (this.listeners[name] ?? []).filter((item) => item !== listener);
    },
    dispatchEvent(event) {
      let node = this;
      while (node) {
        for (const listener of node.listeners?.[event?.type] ?? []) listener(event);
        if (event?.bubbles === false) break;
        node = node.parentElement ?? null;
      }
      return true;
    },
    dispatch(name, event = {}) { this.dispatchEvent({ type: name, bubbles: true, ...event }); },
    appendChild(child) { child.parentElement = this; this.children.push(child); return child; },
    replaceChildren(...nodes) {
      for (const child of this.children) child.parentElement = null;
      this.children = [];
      for (const node of nodes) this.appendChild(node);
    },
    remove() {
      if (!this.parentElement) return;
      this.parentElement.children = this.parentElement.children.filter((child) => child !== this);
      this.parentElement = null;
    },
    querySelectorAll(selector) {
      const wanted = String(selector).replace(/^\./, '').split('.').filter(Boolean);
      const found = [];
      const walk = (node) => {
        for (const child of node.children ?? []) {
          if (wanted.every((name) => child.classes?.has(name))) found.push(child);
          walk(child);
        }
      };
      walk(this);
      return found;
    },
    querySelector(selector) { return this.querySelectorAll(selector)[0] ?? null; },
    set className(value) { this.classes = new Set(String(value).split(/\s+/).filter(Boolean)); },
    get className() { return [...this.classes].join(' '); },
    get textContent() {
      return this.children.length ? this.children.map((child) => child.textContent).join('') : this._textContent;
    },
    set textContent(value) { this._textContent = String(value); this.children = []; },
    get innerHTML() { throw new Error('PROHIBIDO innerHTML (AGENTS.md 6.1: XSS); usar textContent'); },
    set innerHTML(value) { throw new Error(`PROHIBIDO innerHTML (AGENTS.md 6.1): '${String(value).slice(0, 30)}'`); },
    get value() { return this._value; },
    set value(next) { this._value = String(next); },
    focus() {},
  };

  element.classList = {
    _owner: element,
    add(...names) { names.forEach((name) => this._owner.classes.add(name)); },
    remove(...names) { names.forEach((name) => this._owner.classes.delete(name)); },
    contains(name) { return this._owner.classes.has(name); },
  };

  return element;
}

/** Documento simulado con los métodos que las vistas invocan. */
function createFakeDocument() {
  const doc = {
    hidden: false,
    listeners: {},
    createElement(tagName) { return createFakeElement(tagName, doc); },
    createElementNS(_namespace, tagName) { return createFakeElement(tagName, doc); },
    addEventListener(name, listener) { (doc.listeners[name] ??= []).push(listener); },
    removeEventListener(name, listener) {
      doc.listeners[name] = (doc.listeners[name] ?? []).filter((item) => item !== listener);
    },
  };
  return doc;
}

// =====================================================================
// [1] Contrato del DTO canónico del ribete
// =====================================================================
console.log('\n[1] El contrato del DTO canónico (REGENT_RIBBON_CLASS)');

const { REGENT_RIBBON_CLASS, createSpellCardComponent } = await import('../public/assets/js/components/spellCardComponent.js');
const { createStore } = await import('../public/assets/js/state/store.js');
const { createLibraryView } = await import('../public/assets/js/views/libraryView.js');

assertCondition(REGENT_RIBBON_CLASS === 'spell-card-regent-border', 'La clase canónica del ribete es spell-card-regent-border');

const CSS_CARD = await import('node:fs/promises').then((fs) => fs.readFile('public/assets/css/components/clan-heraldry.css', 'utf8'));
const CSS_COMPONENTS = await import('node:fs/promises').then((fs) => fs.readFile('public/assets/css/components.css', 'utf8'));
const TOKENS_CSS = await import('node:fs/promises').then((fs) => fs.readFile('public/assets/css/tokens.css', 'utf8'));

// =====================================================================
// Forja del Tomo (la Biblioteca) con dos casas contendientes
// =====================================================================

const TOME_SPELLS_PAGE_ONE = [
  { id: '1', slug: 'llamas-del-soberano', name: 'Llamas del Soberano', magicSchool: 'evocation', magicSchoolLabel: 'Evocación', manaCost: 45, clanId: 'cln_ignis', clanName: 'Custodios de la Llama', summary: 'Ráfaga de fuego purificador.', status: 'validated', isGenesisSample: false },
  { id: '2', slug: 'manto-ajeno', name: 'Manto Ajeno', magicSchool: 'abjuration', magicSchoolLabel: 'Abjuración', manaCost: 30, clanId: 'cln_tide', clanName: 'Mareas de Aether', summary: 'Barrera de maná denso.', status: 'validated', isGenesisSample: false },
];
const TOME_SPELLS_PAGE_TWO = [
  { id: '3', slug: 'sello-de-mareas', name: 'Sello de Mareas', magicSchool: 'abjuration', magicSchoolLabel: 'Abjuración', manaCost: 28, clanId: 'cln_tide', clanName: 'Mareas de Aether', summary: 'Dique de escarcha perpetuo.', status: 'validated', isGenesisSample: false },
];

/** Cliente del Dominio que reina sobre una casa (o yace derrotado). */
const regentClientFor = (clanId) => ({
  calls: 0,
  async fetchLeaderboard() {
    this.calls++;
    return { success: true, status: 200, data: { currentRegentClan: { id: clanId, name: 'Casa del Podio' } } };
  },
});

/** Monta la Biblioteca con paginación de dos rollos. */
async function buildTome(dominionClient, pages) {
  const doc = createFakeDocument();
  const mount = createFakeElement('main', doc);
  let pageIndex = 0;
  const view = createLibraryView(mount, {
    store: createStore(),
    spellClient: {
      async fetchSpells() {
        const items = pages[Math.min(pageIndex, pages.length - 1)];
        pageIndex++;
        return { success: true, status: 200, data: { items, hasMore: pageIndex < pages.length, offset: pageIndex * 2, limit: 2 } };
      },
    },
    onSpellSelect: () => {},
    dominionClient,
    elementFactory: (tagName) => createFakeElement(tagName, doc),
  });
  await view.render();
  await flushMicrotasks();
  return { view, mount };
}

const cardOf = (mount, slug) => mount.querySelectorAll('.spell-card').find((card) => card.getAttribute('data-slug') === slug) ?? null;

// =====================================================================
// [2] El ribete cambia de manos: derrota y coronación de la nueva casa
// =====================================================================
console.log('\n[2] La corona cambia de manos y el ribete migra');

const firstReign = await buildTome(regentClientFor('cln_ignis'), [TOME_SPELLS_PAGE_ONE]);
assertCondition(
  cardOf(firstReign.mount, 'llamas-del-soberano')?.classes.has(REGENT_RIBBON_CLASS) === true,
  'El conjuro de la casa coronada luce el ribete dorado',
);
assertCondition(
  cardOf(firstReign.mount, 'manto-ajeno')?.classes.has(REGENT_RIBBON_CLASS) === false,
  'La casa derrotada yace sin ribete',
);

const secondReign = await buildTome(regentClientFor('cln_tide'), [TOME_SPELLS_PAGE_ONE]);
assertCondition(
  cardOf(secondReign.mount, 'llamas-del-soberano')?.classes.has(REGENT_RIBBON_CLASS) === false,
  'Derrotada la casa previa, su conjuro pierde el ribete (la corona es semanal)',
);
assertCondition(
  cardOf(secondReign.mount, 'manto-ajeno')?.classes.has(REGENT_RIBBON_CLASS) === true,
  'El conjuro de la nueva casa reina ceñido con el oro ceremonial',
);

// =====================================================================
// [3] El ribete de nacimiento: paginación posterior con regente conocido
// =====================================================================
console.log('\n[3] Los pergaminos posteriores nacen ceñidos (paginación)');

const knownRegentClient = regentClientFor('cln_tide');
const pagedTome = await buildTome(knownRegentClient, [TOME_SPELLS_PAGE_ONE, TOME_SPELLS_PAGE_TWO]);
// El regente ya era conocido cuando la segunda página llegó: la nueva
// tarjeta debe nacer con el ribete vía isRegent, sin esperar al repintado.
const loadMoreButton = pagedTome.mount.querySelector('.library-view__load-more');
assertCondition(loadMoreButton !== null, 'El Tomo ofrece «Desenrollar más pergaminos» con segunda página pendiente');
loadMoreButton.listeners.click[0]();
await flushMicrotasks();

const pagedCard = cardOf(pagedTome.mount, 'sello-de-mareas');
assertCondition(pagedCard !== null, 'La segunda página llega al pie de la rejilla');
assertCondition(
  pagedCard?.classes.has(REGENT_RIBBON_CLASS) === true && pagedCard.getAttribute('data-regent') === 'true',
  'La tarjeta paginada de la casa reina NACE ceñida con el ribete (isRegent en forja)',
);

// =====================================================================
// [4] El contrato CSS del ribete (materia y filo desde tokens)
// =====================================================================
console.log('\n[4] La materia del ribete: tokens, resplandor y doble anillo');

const ruleBody = (selector) => {
  const match = CSS_CARD.match(new RegExp(`${selector.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')}\\s*\\{([^}]*)\\}`));
  return match?.[1] ?? '';
};
const ribbonBody = ruleBody('.spell-card-regent-border');

assertCondition(/border-color:\s*var\(--heraldry-regent-gold\)/.test(ribbonBody), 'El filo viste el oro ceremonial desde el token del sistema');
assertCondition(/box-shadow:[^;]*var\(--shadow-gold-glow\)/.test(ribbonBody), 'El resplandor dorado del Dominio envuelve la tarjeta');
assertCondition(/transition:\s*border-color/.test(ribbonBody), 'La entrada y salida del sello transiciona con la curva del sistema');
assertCondition(ruleBody('.spell-card-regent-border::after') !== '', 'El doble anillo interior existe como pseudo-elemento');
assertCondition(/var\(--heraldry-regent-bright\)/.test(ruleBody('.spell-card-regent-border::after')), 'El anillo interior viste el oro radiante');
assertCondition(!/#([0-9a-fA-F]{3,8})\b/.test(ribbonBody), 'La materia del ribete declara CERO literales de color (tokens canónicos)');

// =====================================================================
// [5] El movimiento reducido anula el pulso del anillo
// =====================================================================
console.log('\n[5] Movimiento reducido: el pulso del anillo cesa (RNF-03)');

const reducedAll = CSS_CARD.split('@media (prefers-reduced-motion: reduce)').slice(1).join('\n');
assertCondition(
  /\.spell-card-regent-border::after[\s\S]{0,120}animation:\s*none/.test(reducedAll),
  'Bajo prefers-reduced-motion el pulso del doble anillo queda anulado',
);
assertCondition(
  /\.regent-glow__aura[\s\S]{0,120}animation:\s*none/.test(reducedAll),
  'El aura del estandarte regente también descansa bajo movimiento reducido',
);

// =====================================================================
// Resumen
// =====================================================================
console.log(`\n== RESUMEN == Asertos superados: ${assertsPassed}, fallidos: ${assertsFailed}`);
if (assertsFailed === 0) {
  console.log('RESULTADO: EXITO — La cadena completa del ribete dorado del Regente cumple (RF-04.4).');
  process.exit(0);
}
console.log('RESULTADO: FALLO — Revisa los asertos marcados.');
process.exit(1);
