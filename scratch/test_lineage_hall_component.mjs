/**
 * test_lineage_hall_component.mjs — Arnés de la Tarea 6.3 (TASKS-07).
 *
 * Verifica sobre `public/assets/js/components/lineageHallComponent.js` y
 * `public/assets/js/views/lineageHallView.js` el criterio «Hecho cuando»:
 *   «El usuario puede alternar entre clasificación semanal e histórica, y
 *    filtrar las hermandades pulsando en el icono rúnico de cualquiera de
 *    los 8 linajes.»
 *
 * Fases:
 *   [1]  Superficie del módulo y Dogma Vanilla.
 *   [2]  Armazón: región, título, tres pestañas y ocho filtros (RF-06.1/06.2).
 *   [3]  Clasificación Semanal en Vivo con su podio y su corona (RF-04.4).
 *   [4]  Alternar al Prestigio Histórico Perpetuo (criterio).
 *   [5]  Alternar al Libro Mayor de Campeones Pasados (RF-06.1).
 *   [6]  Filtrar por los 8 glifos rúnicos, uno a uno (criterio, RF-06.2).
 *   [7]  El linaje fundacional neutro se señala como no competidor (Art. III).
 *   [8]  Estados: carga, corriente cortada con reintento y vacío.
 *   [9]  Teclado y ARIA del conmutador (RNF-03).
 *   [10] Degradación sin catálogo, XSS y ciclo de vida.
 *   [11] La vista: consulta los Endpoints 10 y 11 y sincroniza el store.
 *
 * Constitución:
 *   - Artículo I: cero librerías; DOM simulado propio.
 *   - Artículo V: identificadores en inglés camelCase; comentarios en castellano.
 */

import { readFile } from 'node:fs/promises';
import {
  createLineageHallComponent,
  LINEAGE_HALL_TITLE,
  LINEAGE_HALL_HINT,
  LINEAGE_HALL_TABS,
  LINEAGE_HALL_TAB_WEEKLY,
  LINEAGE_HALL_TAB_HISTORICAL,
  LINEAGE_HALL_TAB_HALL_OF_FAME,
  LINEAGE_HALL_LOADING_LEGEND,
  LINEAGE_HALL_EMPTY_LEGEND,
  LINEAGE_HALL_ERROR_LEGEND,
  LINEAGE_HALL_FAME_HINT,
  LINEAGE_HALL_NEUTRAL_CLAN_ID,
  LINEAGE_HALL_NEUTRAL_LEGEND,
  LINEAGE_HALL_REGENT_LABEL,
} from '../public/assets/js/components/lineageHallComponent.js';
import { createLineageHallView } from '../public/assets/js/views/lineageHallView.js';

let assertsPassed = 0;
let assertsFailed = 0;

/** Aserta una condición y registra el resultado. */
function assertCondition(condition, description) {
  if (condition) {
    assertsPassed += 1;
    console.log(`  [PASA] ${description}`);
  } else {
    assertsFailed += 1;
    console.log(`  [FALLA] ${description}`);
  }
}

/** Elemento DOM mínimo simulado (innerHTML PROHIBIDO: su accesor lanza). */
function createFakeElement(tagName) {
  const element = {
    tagName: String(tagName).toUpperCase(),
    children: [],
    attributes: {},
    classes: new Set(),
    listeners: {},
    value: '',
    focusCount: 0,
    _textContent: '',
    parentElement: null,
    setAttribute(name, value) { this.attributes[name] = String(value); },
    getAttribute(name) { return name in this.attributes ? this.attributes[name] : null; },
    removeAttribute(name) { delete this.attributes[name]; },
    hasAttribute(name) { return name in this.attributes; },
    addEventListener(eventName, listener) { (this.listeners[eventName] ??= []).push(listener); },
    removeEventListener(eventName, listener) {
      this.listeners[eventName] = (this.listeners[eventName] ?? []).filter((l) => l !== listener);
    },
    dispatch(eventName, eventObject = {}) {
      const event = {
        type: eventName,
        defaultPrevented: false,
        currentTarget: this,
        target: this,
        key: eventObject.key ?? '',
        preventDefault() { this.defaultPrevented = true; },
        stopPropagation() {},
      };
      for (const listener of [...(this.listeners[eventName] ?? [])]) listener(event);
      return event;
    },
    appendChild(child) { child.parentElement = this; this.children.push(child); return child; },
    remove() {
      if (!this.parentElement) return;
      const index = this.parentElement.children.indexOf(this);
      if (index >= 0) this.parentElement.children.splice(index, 1);
      this.parentElement = null;
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
    querySelector() { return null; },
  };
  element.classList = {
    _owner: element,
    add(...names) { names.forEach((n) => this._owner.classes.add(n)); },
    remove(...names) { names.forEach((n) => this._owner.classes.delete(n)); },
    contains(name) { return this._owner.classes.has(name); },
  };
  return element;
}

const fakeElementFactory = (tagName) => createFakeElement(tagName);

/** Barrido recursivo por clase sobre el DOM simulado. */
function queryByClass(node, className, found = []) {
  for (const child of node.children) {
    if (child.classes.has(className)) found.push(child);
    queryByClass(child, className, found);
  }
  return found;
}
const byClass = (root, className) => queryByClass(root, className)[0] ?? null;
const allByClass = (root, className) => queryByClass(root, className);

/** Barrido recursivo por etiqueta. */
function allByTag(node, tagName, found = []) {
  for (const child of node.children) {
    if (child.tagName === String(tagName).toUpperCase()) found.push(child);
    allByTag(child, tagName, found);
  }
  return found;
}

/** Awaits pendientes: deja correr las promesas de la vista. */
const settle = async (rounds = 6) => {
  for (let i = 0; i < rounds; i += 1) await Promise.resolve();
  await new Promise((resolve) => setTimeout(resolve, 0));
};

/** Los 8 Linajes Canónicos, en el orden del canon (Endpoint 10). */
const CANONICAL_LINEAGES = [
  { id: 'primordialFlame', name: 'Linaje de la Llama Primordial', rulingElement: 'fire', glyph: 'rune-ignis', bannerColor: '#ff4500', heraldicFrame: 'phoenixShield', description: '…' },
  { id: 'celestialTides', name: 'Linaje de las Mareas Celestiales', rulingElement: 'water', glyph: 'rune-aqua', bannerColor: '#00bfff', heraldicFrame: 'leviathanShield', description: '…' },
  { id: 'eternalTempest', name: 'Linaje de la Tempestad Eterna', rulingElement: 'lightning', glyph: 'rune-fulgur', bannerColor: '#9932cc', heraldicFrame: 'thunderbirdShield', description: '…' },
  { id: 'worldRoots', name: 'Linaje de las Raíces del Mundo', rulingElement: 'earth', glyph: 'rune-terra', bannerColor: '#8b4513', heraldicFrame: 'worldtreeShield', description: '…' },
  { id: 'dawnWinds', name: 'Linaje de los Vientos del Alba', rulingElement: 'wind', glyph: 'rune-ventus', bannerColor: '#2e8b57', heraldicFrame: 'zephyrShield', description: '…' },
  { id: 'solarCrown', name: 'Linaje de la Corona Solar', rulingElement: 'light', glyph: 'rune-lux', bannerColor: '#ffd700', heraldicFrame: 'sunwheelShield', description: '…' },
  { id: 'abyssalShadows', name: 'Linaje de las Sombras Abisales', rulingElement: 'darkness', glyph: 'rune-tenebrae', bannerColor: '#4b0082', heraldicFrame: 'voidSerpentShield', description: '…' },
  { id: 'aetherWeavers', name: 'Linaje de los Tejedores del Éter', rulingElement: 'pureArcane', glyph: 'rune-arcana', bannerColor: '#4169e1', heraldicFrame: 'aetherloomShield', description: '…' },
];

/** ClanDto del contrato del backend. */
function buildClan(overrides = {}) {
  return {
    id: 'cln_ember',
    slug: 'custodios-de-la-llama',
    name: 'Custodios de la Llama',
    motto: 'En la ceniza renace la llama inmortal',
    coatOfArms: 'rune-ignis',
    lineageType: 'primordialFlame',
    admissionMode: 'byApplication',
    status: 'active',
    patriarchId: 'usr_fundador',
    weeklyPoints: 170,
    historicalPoints: 620,
    memberCount: 12,
    memberLimit: 30,
    lastActivityAt: '2026-09-10T10:00:00Z',
    createdAt: '2026-06-01T00:00:00Z',
    updatedAt: '2026-09-10T10:00:00Z',
    ...overrides,
  };
}

/** WeeklyCycleDto del contrato del backend. */
function buildCycle(overrides = {}) {
  return {
    id: 'cyc_2026_35',
    weekNumber: 35,
    cycleYear: 2026,
    regentClanId: 'cln_ember',
    regentClanName: 'Custodios de la Llama',
    winningPoints: 500,
    winnerSpellCount: 2,
    closedAt: '2026-08-30T23:59:59Z',
    label: 'Año 2026 · Semana 35',
    ...overrides,
  };
}

/** Salón del Dominio (Endpoint 11) con sus cuatro secciones. */
function buildHall(overrides = {}) {
  const weeklyRanking = [
    buildClan({ id: 'cln_ember', name: 'Custodios de la Llama', lineageType: 'primordialFlame', weeklyPoints: 170, memberCount: 12 }),
    buildClan({ id: 'cln_tide', name: 'Mareas de Aether', lineageType: 'celestialTides', weeklyPoints: 140, memberCount: 9, coatOfArms: 'rune-aqua' }),
    buildClan({ id: 'cln_tempest', name: 'Portadores del Fulgor', lineageType: 'eternalTempest', weeklyPoints: 110, memberCount: 7, coatOfArms: 'rune-fulgur' }),
    buildClan({ id: 'cln_roots', name: 'Raíces Eternas', lineageType: 'worldRoots', weeklyPoints: 60, memberCount: 4, coatOfArms: 'rune-terra' }),
    buildClan({ id: LINEAGE_HALL_NEUTRAL_CLAN_ID, slug: 'custodios-del-fuego-primordial', name: 'Custodios del Fuego Primordial', motto: 'El canon no compite', lineageType: 'primordialFlame', weeklyPoints: 0, historicalPoints: 0, memberCount: 1, coatOfArms: 'rune-ignis', patriarchId: 'usr_custodio_primordial' }),
  ];

  const historicalRanking = [
    buildClan({ id: 'cln_ember', historicalPoints: 620 }),
    buildClan({ id: 'cln_tide', name: 'Mareas de Aether', lineageType: 'celestialTides', historicalPoints: 480 }),
    buildClan({ id: 'cln_solar', name: 'Corona del Alba', lineageType: 'solarCrown', historicalPoints: 300, coatOfArms: 'rune-lux' }),
  ];

  return {
    weeklyRanking,
    historicalRanking,
    currentRegentClan: weeklyRanking[0],
    hallOfFameWeeks: [
      buildCycle({ id: 'cyc_2026_35', label: 'Año 2026 · Semana 35' }),
      buildCycle({ id: 'cyc_2026_34', weekNumber: 34, regentClanId: 'cln_tide', regentClanName: 'Mareas de Aether', winningPoints: 420, winnerSpellCount: 1, label: 'Año 2026 · Semana 34' }),
    ],
    ...overrides,
  };
}

const mount = () => createFakeElement('main');

console.log('FASE 1: Superficie del módulo y Dogma Vanilla');
{
  const source = await readFile(new URL('../public/assets/js/components/lineageHallComponent.js', import.meta.url), 'utf8');
  const viewSource = await readFile(new URL('../public/assets/js/views/lineageHallView.js', import.meta.url), 'utf8');

  assertCondition(typeof createLineageHallComponent === 'function', 'El módulo exporta la fábrica del componente');
  assertCondition(typeof createLineageHallView === 'function', 'El módulo de la vista exporta su fábrica');
  assertCondition(LINEAGE_HALL_TABS.length === 3, 'El Salón declara las tres secciones del canon (RF-06.1)');
  assertCondition(
    LINEAGE_HALL_TABS.map((tab) => tab.id).join(',') === 'weekly,historical,hallOfFame',
    'Las claves de sección son canónicas y en inglés (Art. V)',
  );
  assertCondition(
    LINEAGE_HALL_TABS.every((tab) => tab.label.trim() !== ''),
    'Cada sección porta su leyenda ceremonial en castellano (RNF-03)',
  );
  assertCondition(
    !/innerHTML/.test(source) || !/\.innerHTML\s*=/.test(source),
    'El componente jamás asigna innerHTML (AGENTS.md 6.1)',
  );
  assertCondition(
    !/\bimport\b[^\n]*from\s+['"](?!\.\.\/utils\/comboResolver\.js)/.test(source),
    'El componente solo importa el espejo elemental del propio santuario (Art. I)',
  );
  assertCondition(
    !/https?:\/\/(?!.*example)/.test(source) && !/cdn\./i.test(source) && !/cdn\./i.test(viewSource),
    'Cero recursos ni CDNs externos (Dogma Vanilla)',
  );
  assertCondition(
    !/fetch\(/.test(source),
    'El componente NO consulta la red: la vista es la única que habla con el santuario (Art. II)',
  );

  const instance = createLineageHallComponent(mount(), { elementFactory: fakeElementFactory });
  assertCondition(
    ['render', 'setLineages', 'setHall', 'setError', 'setTab', 'setLineageFilter', 'getActiveTab', 'getActiveLineageFilter', 'destroy']
      .every((method) => typeof instance[method] === 'function'),
    'La API pública del componente está completa',
  );
  instance.destroy();
}

console.log('\nFASE 2: Armazón — región, título, tres pestañas y ocho filtros');
{
  const root = mount();
  const hall = createLineageHallComponent(root, {
    lineages: CANONICAL_LINEAGES,
    hall: buildHall(),
    elementFactory: fakeElementFactory,
  });
  hall.render();

  const pabellon = byClass(root, 'lineage-hall');
  assertCondition(pabellon !== null, 'El Salón monta su pabellón (RF-06.1)');
  assertCondition(pabellon.getAttribute('role') === 'region', 'El pabellón es una región semántica');
  assertCondition(pabellon.getAttribute('aria-labelledby') === 'lineageHallTitle', 'La región se etiqueta por su título');

  const title = byClass(root, 'lineage-hall__title');
  assertCondition(title?.tagName === 'H1' && title.textContent === LINEAGE_HALL_TITLE, '«Salón de los Linajes» encabeza la página');
  assertCondition(byClass(root, 'lineage-hall__hint')?.textContent === LINEAGE_HALL_HINT, 'El preámbulo ceremonial acompaña al título');

  const tabs = allByClass(root, 'lineage-tab');
  assertCondition(tabs.length === 3, 'El conmutador ofrece las TRES secciones del canon (RF-06.1)');
  assertCondition(byClass(root, 'lineage-hall__tabs')?.getAttribute('role') === 'tablist', 'El conmutador se declara tablist');
  assertCondition(
    tabs.every((tab) => tab.getAttribute('role') === 'tab' && tab.getAttribute('aria-controls') === 'lineageHallPanel'),
    'Cada pestaña es un tab que gobierna el panel',
  );
  assertCondition(
    tabs.filter((tab) => tab.getAttribute('aria-selected') === 'true').length === 1
      && tabs[0].getAttribute('aria-selected') === 'true',
    'Nace activa la Clasificación Semanal en Vivo',
  );
  assertCondition(tabs[0].getAttribute('tabindex') === '0' && tabs[1].getAttribute('tabindex') === '-1', 'Recorrido de foco: solo la pestaña activa es tabulable');

  const filters = allByClass(root, 'lineage-filter');
  assertCondition(filters.length === 8, 'La barra de filtros viste los OCHO Linajes Canónicos (RF-06.2)');
  assertCondition(
    filters.map((filter) => filter.getAttribute('data-lineage')).join(',') === CANONICAL_LINEAGES.map((l) => l.id).join(','),
    'Los filtros siguen el orden del canon, sin inventar un noveno',
  );
  assertCondition(
    filters.map((filter) => filter.getAttribute('data-heraldic-frame')).join(',') === CANONICAL_LINEAGES.map((l) => l.heraldicFrame).join(','),
    'Cada filtro porta el marco heráldico de su linaje (Tarea 6.1)',
  );
  assertCondition(
    filters.map((filter) => filter.getAttribute('aria-label')).every((label) => label.startsWith('Filtrar por el Linaje de')),
    'Cada glifo declara su acción en castellano (RNF-03)',
  );
  assertCondition(
    filters.every((filter) => filter.getAttribute('aria-pressed') === 'false'),
    'Sin filtro activo, ninguno se declara pulsado',
  );
  assertCondition(
    filters.every((filter) => byClass(filter, 'lineage-filter__glyph')?.getAttribute('aria-hidden') === 'true'),
    'Los glifos son ornamentales: su significado viaja en el nombre accesible',
  );
  hall.destroy();
}

console.log('\nFASE 3: Clasificación Semanal en Vivo (RF-06.1, RF-04.4)');
{
  const root = mount();
  const hall = createLineageHallComponent(root, {
    lineages: CANONICAL_LINEAGES,
    hall: buildHall(),
    elementFactory: fakeElementFactory,
  });
  hall.render();

  const podium = byClass(root, 'lineage-hall__podium');
  const ranks = allByClass(root, 'podium-rank');
  assertCondition(podium !== null && ranks.length === 5, 'El podio alza las cinco casas de la contienda');

  assertCondition(
    ranks.map((rank) => rank.getAttribute('data-clan-id')).join(',') === 'cln_ember,cln_tide,cln_tempest,cln_roots,cln_primordial',
    'El orden del podio es EXACTAMENTE el que proclama el Salón (Art. II)',
  );
  assertCondition(
    ranks.slice(0, 4).map((rank) => byClass(rank, 'podium-rank__position').textContent).join(',') === '1º,2º,3º,4º',
    'Cada casa porta su puesto ordinal',
  );

  const regent = ranks[0];
  assertCondition(regent.getAttribute('data-clan-id') === 'cln_ember', 'El regente vigente encabeza el podio');
  assertCondition(regent.classes.has('podium-rank--regent'), 'Solo la casa reinante lleva el oro del Dominio');
  assertCondition(
    byClass(regent, 'podium-rank__crown')?.getAttribute('aria-label') === LINEAGE_HALL_REGENT_LABEL,
    'La corona dorada es contenido con nombre accesible (RF-04.4)',
  );
  assertCondition(allByClass(root, 'podium-rank__crown').length === 1, 'Nadie más ciñe corona alguna');

  assertCondition(
    byClass(regent, 'podium-rank__clan-name').textContent === 'Custodios de la Llama'
      && byClass(regent, 'podium-rank__motto').textContent === '«En la ceniza renace la llama inmortal»',
    'El podio porta el Nombre Canónico y el lema entre comillas ceremoniales',
  );
  assertCondition(
    byClass(regent, 'podium-rank__lineage').textContent === 'Linaje de la Llama Primordial · elemento rector: Fuego',
    'El linaje rector viaja con su elemento (RF-02.2)',
  );
  assertCondition(
    byClass(regent, 'podium-rank__points').textContent === '170 PDA semanales · 12 adeptos',
    'El podio exhibe los PDA de la semana en curso y el censo de adeptos',
  );
  assertCondition(
    byClass(regent, 'podium-rank__coat')?.getAttribute('aria-label') === 'Escudo heráldico de Custodios de la Llama: rune-ignis',
    'Cada estandarte porta su blasón rúnico accesible',
  );

  const announcement = byClass(root, 'lineage-hall__announcement');
  assertCondition(announcement.getAttribute('role') === 'status' && announcement.getAttribute('aria-live') === 'polite', 'Las mudanzas se verbalizan por cortesía');
  assertCondition(announcement.textContent === 'Clasificación Semanal en Vivo: 5 hermandades.', 'La proclama narra la contienda en curso');
  hall.destroy();
}

console.log('\nFASE 4: Alternar al Prestigio Histórico Perpetuo (criterio)');
{
  const root = mount();
  const hall = createLineageHallComponent(root, { lineages: CANONICAL_LINEAGES, hall: buildHall(), elementFactory: fakeElementFactory });
  hall.render();

  hall.setTab(LINEAGE_HALL_TAB_HISTORICAL);
  assertCondition(hall.getActiveTab() === LINEAGE_HALL_TAB_HISTORICAL, 'La sección activa pasa a Prestigio Histórico');

  const tabs = allByClass(root, 'lineage-tab');
  assertCondition(
    tabs[1].getAttribute('aria-selected') === 'true' && tabs[0].getAttribute('aria-selected') === 'false',
    'El estado ARIA del conmutador acompaña el cambio',
  );
  assertCondition(byClass(root, 'lineage-hall__podium') === null, 'El podio semanal se retira al cambiar de sección');

  const rows = allByClass(root, 'historical-row');
  assertCondition(byClass(root, 'lineage-hall__historical') !== null && rows.length === 3, 'El Prestigio Histórico alza sus tres casas');
  assertCondition(
    rows.map((row) => row.getAttribute('data-clan-id')).join(',') === 'cln_ember,cln_tide,cln_solar',
    'El histórico respeta el orden perpetuo del Salón',
  );
  assertCondition(
    byClass(rows[0], 'historical-row__glory').textContent === '620 de gloria perpetua'
      && byClass(rows[0], 'historical-row__clan').textContent === 'Custodios de la Llama'
      && byClass(rows[0], 'historical-row__rank').textContent === '1º',
    'Cada fila porta puesto, casa y gloria perpetua',
  );
  assertCondition(
    byClass(rows[2], 'historical-row__lineage').textContent === 'Linaje de la Corona Solar',
    'El histórico nombra el linaje rector en castellano',
  );
  assertCondition(
    byClass(root, 'lineage-hall__announcement').textContent === 'Prestigio Histórico Perpetuo: 3 hermandades.',
    'La proclama narra el prestigio perpetuo',
  );
  hall.destroy();
}

console.log('\nFASE 5: Alternar al Libro Mayor de Campeones Pasados (RF-06.1)');
{
  const root = mount();
  const hall = createLineageHallComponent(root, { lineages: CANONICAL_LINEAGES, hall: buildHall(), elementFactory: fakeElementFactory });
  hall.render();
  hall.setTab(LINEAGE_HALL_TAB_HALL_OF_FAME);

  assertCondition(byClass(root, 'lineage-hall__fame-hint')?.textContent === LINEAGE_HALL_FAME_HINT, 'El Libro Mayor se declara perpetuo');

  const entries = allByClass(root, 'fame-entry');
  assertCondition(byClass(root, 'lineage-hall__hall-of-fame') !== null && entries.length === 2, 'El Libro Mayor inmortaliza las semanas concluidas');
  assertCondition(
    byClass(entries[0], 'fame-entry__week').textContent === 'Año 2026 · Semana 35'
      && byClass(entries[0], 'fame-entry__champion').textContent === 'Custodios de la Llama',
    'Cada acta porta su etiqueta ceremonial y su campeón',
  );
  assertCondition(
    byClass(entries[0], 'fame-entry__glory').textContent === '500 PDA · 2 conjuros sellados',
    'El acta cifra la gloria con la que se alzó el campeón',
  );
  assertCondition(
    byClass(root, 'lineage-hall__announcement').textContent === 'Libro Mayor de Campeones Pasados: 2 semanas inmortalizadas.',
    'La proclama narra el Libro Mayor',
  );

  // Idempotencia: volver a pulsar la sección vigente no altera el acta.
  hall.setTab(LINEAGE_HALL_TAB_HALL_OF_FAME);
  assertCondition(allByClass(root, 'fame-entry').length === 2, 'Repulsar la sección vigente no duplica el acta');
  hall.destroy();
}

console.log('\nFASE 6: Filtrar por los 8 glifos rúnicos, uno a uno (criterio, RF-06.2)');
{
  const root = mount();
  const hall = createLineageHallComponent(root, { lineages: CANONICAL_LINEAGES, hall: buildHall(), elementFactory: fakeElementFactory });
  hall.render();

  const filters = allByClass(root, 'lineage-filter');
  const expectedByLineage = {
    primordialFlame: ['cln_ember', 'cln_primordial'],
    celestialTides: ['cln_tide'],
    eternalTempest: ['cln_tempest'],
    worldRoots: ['cln_roots'],
    dawnWinds: [],
    solarCrown: [],
    abyssalShadows: [],
    aetherWeavers: [],
  };

  let parity = true;
  for (const filter of filters) {
    const lineageId = filter.getAttribute('data-lineage');
    filter.dispatch('click');

    const expected = expectedByLineage[lineageId];
    const visible = allByClass(root, 'podium-rank');
    const got = visible.filter((rank) => rank.getAttribute('data-clan-id') !== LINEAGE_HALL_NEUTRAL_CLAN_ID)
      .map((rank) => rank.getAttribute('data-clan-id'));

    // El neutro no compite: se contrasta aparte (Fase 7).
    const gotWithNeutral = visible.map((rank) => rank.getAttribute('data-clan-id'));
    if (gotWithNeutral.join(',') !== expected.join(',') && got.join(',') !== expected.filter((id) => id !== LINEAGE_HALL_NEUTRAL_CLAN_ID).join(',')) {
      parity = false;
    }
    if (hall.getActiveLineageFilter() !== lineageId) parity = false;
    if (filter.getAttribute('aria-pressed') !== 'true') parity = false;

    if (lineageId !== 'primordialFlame') {
      if (visible.length === 0 && expected.length !== 0) parity = false;
    }

    if (lineageId === 'primordialFlame') {
      if (byClass(root, 'podium-rank__position').textContent !== '1º') parity = false;
      if (byClass(root, 'lineage-hall__announcement').textContent
        !== 'Clasificación Semanal en Vivo: 2 hermandades. Filtro: Linaje de la Llama Primordial.') parity = false;
    }

    if (lineageId === 'celestialTides') {
      if (allByClass(root, 'podium-rank').length !== 1) parity = false;
      if (byClass(root, 'podium-rank__clan-name').textContent !== 'Mareas de Aether') parity = false;
    }

    if (lineageId === 'dawnWinds' || lineageId === 'solarCrown' || lineageId === 'abyssalShadows' || lineageId === 'aetherWeavers') {
      if (byClass(root, 'lineage-hall__empty') === null) parity = false;
      if (byClass(root, 'lineage-hall__empty').textContent !== LINEAGE_HALL_EMPTY_LEGEND) parity = false;
    }

    // El mismo gesto apaga el filtro y devuelve el Salón entero.
    filter.dispatch('click');
    if (hall.getActiveLineageFilter() !== null) parity = false;
    if (filter.getAttribute('aria-pressed') !== 'false') parity = false;
    if (allByClass(root, 'podium-rank').length !== 5) parity = false;
  }

  assertCondition(parity, 'Pulsar cada uno de los 8 glifos filtra las hermandades y el mismo gesto retira el filtro (RF-06.2)');
  assertCondition(
    allByClass(root, 'lineage-filter').filter((f) => f.getAttribute('aria-pressed') === 'true').length === 0,
    'Ningún filtro queda encendido tras la ronda completa',
  );

  // El filtro también gobierna el Prestigio Histórico.
  const tideFilter = allByClass(root, 'lineage-filter')
    .find((f) => f.getAttribute('data-lineage') === 'celestialTides');
  hall.setTab(LINEAGE_HALL_TAB_HISTORICAL);
  tideFilter.dispatch('click');
  const historicalRows = allByClass(root, 'historical-row');
  assertCondition(
    historicalRows.length === 1 && historicalRows[0].getAttribute('data-clan-id') === 'cln_tide',
    'El filtro elemental también rige el Prestigio Histórico',
  );

  // Y el Libro Mayor, por la casa campeona.
  hall.setTab(LINEAGE_HALL_TAB_HALL_OF_FAME);
  const fameEntries = allByClass(root, 'fame-entry');
  assertCondition(
    tideFilter.getAttribute('aria-pressed') === 'true'
      && fameEntries.length === 1
      && byClass(fameEntries[0], 'fame-entry__champion').textContent === 'Mareas de Aether',
    'El filtro alcanza al Libro Mayor por el linaje de la casa campeona',
  );

  hall.setLineageFilter(null);
  hall.setTab(LINEAGE_HALL_TAB_HALL_OF_FAME);
  assertCondition(
    tideFilter.getAttribute('aria-pressed') === 'false' && allByClass(root, 'fame-entry').length === 2,
    'Retirado el filtro, el Libro Mayor vuelve a inmortalizarlo todo',
  );
  hall.destroy();
}

console.log('\nFASE 7: El linaje fundacional neutro se señala como no competidor (Art. III)');
{
  const root = mount();
  const hall = createLineageHallComponent(root, { lineages: CANONICAL_LINEAGES, hall: buildHall(), elementFactory: fakeElementFactory });
  hall.render();

  const neutral = allByClass(root, 'podium-rank').find((rank) => rank.getAttribute('data-clan-id') === LINEAGE_HALL_NEUTRAL_CLAN_ID);
  assertCondition(neutral !== undefined, 'El linaje fundacional figura en el Salón');
  assertCondition(neutral.classes.has('podium-rank--neutral'), 'Se señala visualmente como neutro');
  assertCondition(byClass(neutral, 'podium-rank__position').textContent === '—', 'No se le finge puesto de contienda');
  assertCondition(byClass(neutral, 'podium-rank__neutral')?.textContent === LINEAGE_HALL_NEUTRAL_LEGEND, 'Porta la leyenda del Artículo III');
  assertCondition(byClass(neutral, 'podium-rank__crown') === null, 'No ciñe corona alguna');
  hall.destroy();
}

console.log('\nFASE 8: Estados — carga, corriente cortada con reintento y vacío');
{
  const root = mount();
  const hall = createLineageHallComponent(root, { lineages: CANONICAL_LINEAGES, elementFactory: fakeElementFactory });
  hall.render();
  assertCondition(byClass(root, 'lineage-hall__loading')?.textContent === LINEAGE_HALL_LOADING_LEGEND, 'Sin Salón aún, se declara la convocatoria');

  let retries = 0;
  const errorRoot = mount();
  const errorHall = createLineageHallComponent(errorRoot, {
    lineages: CANONICAL_LINEAGES,
    onRetry: () => { retries += 1; },
    elementFactory: fakeElementFactory,
  });
  errorHall.render();
  errorHall.setError(null);
  const errorBlock = byClass(errorRoot, 'lineage-hall__error');
  assertCondition(errorBlock !== null && errorBlock.getAttribute('role') === 'alert', 'El corte de corriente se declara con role=alert');
  assertCondition(
    byClass(errorRoot, 'lineage-hall__error-message').textContent === LINEAGE_HALL_ERROR_LEGEND,
    'El error usa la leyenda ceremonial del santuario',
  );
  byClass(errorRoot, 'lineage-hall__error-retry').dispatch('click');
  assertCondition(retries === 1, 'El botón de reintento invoca al orquestador una sola vez');
  assertCondition(byClass(errorRoot, 'lineage-hall__loading') !== null, 'El reintento vuelve al estado de convocatoria');

  const emptyRoot = mount();
  const emptyHall = createLineageHallComponent(emptyRoot, {
    lineages: CANONICAL_LINEAGES,
    hall: buildHall({ weeklyRanking: [], currentRegentClan: null }),
    elementFactory: fakeElementFactory,
  });
  emptyHall.render();
  assertCondition(
    byClass(emptyRoot, 'lineage-hall__empty')?.textContent === LINEAGE_HALL_EMPTY_LEGEND,
    'Sin contienda, el Salón aguarda con su leyenda de vacío',
  );
  hall.destroy();
  errorHall.destroy();
  emptyHall.destroy();
}

console.log('\nFASE 9: Teclado y ARIA del conmutador (RNF-03)');
{
  const root = mount();
  const hall = createLineageHallComponent(root, { lineages: CANONICAL_LINEAGES, hall: buildHall(), elementFactory: fakeElementFactory });
  hall.render();

  const tabs = allByClass(root, 'lineage-tab');
  const arrowRight = tabs[0].dispatch('keydown', { key: 'ArrowRight' });
  assertCondition(hall.getActiveTab() === LINEAGE_HALL_TAB_HISTORICAL, 'La flecha derecha avanza de sección');
  assertCondition(arrowRight.defaultPrevented === true, 'El gesto se marca como consumido');
  assertCondition(tabs[1].focusCount === 1, 'El foco acompaña a la nueva sección');

  tabs[1].dispatch('keydown', { key: 'ArrowLeft' });
  assertCondition(hall.getActiveTab() === LINEAGE_HALL_TAB_WEEKLY, 'La flecha izquierda retrocede de sección');
  tabs[0].dispatch('keydown', { key: 'End' });
  assertCondition(hall.getActiveTab() === LINEAGE_HALL_TAB_HALL_OF_FAME, 'El fin de lista salta a la última sección');
  tabs[2].dispatch('keydown', { key: 'Home' });
  assertCondition(hall.getActiveTab() === LINEAGE_HALL_TAB_WEEKLY, 'El inicio de lista vuelve a la primera');

  const ignored = tabs[0].dispatch('keydown', { key: 'Tab' });
  assertCondition(hall.getActiveTab() === LINEAGE_HALL_TAB_WEEKLY && ignored.defaultPrevented === false, 'Una tecla ajena no altera el Salón');
  hall.destroy();
}

console.log('\nFASE 10: Degradación, XSS y ciclo de vida');
{
  // Sin catálogo no hay filtros, pero el Salón sigue en pie.
  const bareRoot = mount();
  const bareHall = createLineageHallComponent(bareRoot, { hall: buildHall(), elementFactory: fakeElementFactory });
  bareHall.render();
  assertCondition(allByClass(bareRoot, 'lineage-filter').length === 0, 'Sin catálogo de linajes, no se inventa filtro alguno');
  assertCondition(
    byClass(bareRoot, 'podium-rank__lineage').textContent === 'primordialFlame',
    'Sin catálogo, el estandarte degrada a la clave técnica (Art. V)',
  );
  assertCondition(byClass(bareRoot, 'podium-rank__clan-name').textContent === 'Custodios de la Llama', 'El Salón sigue contemplándose');
  bareHall.destroy();

  // XSS: todo dato de usuario viaja por textContent.
  const hostile = '<img src=x onerror="alert(1)">';
  const xssRoot = mount();
  const xssHall = createLineageHallComponent(xssRoot, {
    lineages: CANONICAL_LINEAGES,
    hall: buildHall({ weeklyRanking: [buildClan({ name: hostile, motto: hostile })] }),
    elementFactory: fakeElementFactory,
  });
  xssHall.render();
  const nameNode = byClass(xssRoot, 'podium-rank__clan-name');
  assertCondition(nameNode.textContent === hostile, 'El nombre hostil se pinta como TEXTO, no como marcado (AGENTS.md 6.1)');
  assertCondition(
    Object.values(nameNode.attributes).every((value) => !String(value).includes('<')),
    'Ningún atributo hospeda el marcado hostil',
  );
  assertCondition(typeof byClass(xssRoot, 'podium-rank__motto')._textContent === 'string', 'El lema hostil tampoco se interpreta');
  xssHall.destroy();

  // Ciclo de vida: destroy retira el pabellón y sella el componente.
  const lifeRoot = mount();
  const lifeHall = createLineageHallComponent(lifeRoot, { lineages: CANONICAL_LINEAGES, hall: buildHall(), elementFactory: fakeElementFactory });
  lifeHall.render();
  assertCondition(lifeRoot.children.length === 1, 'El pabellón vive montado');
  lifeHall.destroy();
  assertCondition(lifeRoot.children.length === 0, 'destroy() retira el pabellón del montaje');
  lifeHall.setHall(buildHall());
  assertCondition(lifeRoot.children.length === 0, 'Un componente destruido no vuelve a pintar');
  lifeHall.destroy();
}

console.log('\nFASE 11: La vista consulta los Endpoints 10 y 11 y sincroniza el store');
{
  const root = mount();
  const calls = [];
  const store = {
    state: {},
    setState(next) { this.state = { ...this.state, ...next }; },
  };

  const dominionClient = {
    async fetchLineages() {
      calls.push('lineages');
      return { success: true, status: 200, data: CANONICAL_LINEAGES, count: 8 };
    },
    async fetchLeaderboard() {
      calls.push('leaderboard');
      return { success: true, status: 200, data: buildHall() };
    },
  };

  const view = createLineageHallView(root, { dominionClient, store, elementFactory: fakeElementFactory });
  await view.render();

  assertCondition(calls.length === 2 && calls.includes('lineages') && calls.includes('leaderboard'), 'La vista consulta el catálogo y el Salón (Endpoints 10 y 11)');
  assertCondition(store.state.currentView === 'clans', 'La vista registra currentView=clans en el store (plan 4.1)');
  assertCondition(byClass(root, 'lineage-hall-view') !== null, 'La vista monta su raíz');
  assertCondition(allByClass(root, 'lineage-filter').length === 8, 'La vista viste los ocho filtros');
  assertCondition(allByClass(root, 'podium-rank').length === 5, 'La vista pinta el Salón entero');
  assertCondition(typeof view.retry === 'function' && typeof view.setDominionClient === 'function', 'La vista expone reintento y conmutación de cliente');

  // Corte de corriente: el Salón declara el error y ofrece reintento.
  const brokenRoot = mount();
  const brokenClient = {
    async fetchLineages() { return { success: false, status: 502 }; },
    async fetchLeaderboard() {
      return { success: false, status: 0, error: { code: 'MANA_STREAM_INTERRUPTED', message: 'La corriente de maná hacia el Salón se ha interrumpido.' } };
    },
  };
  const brokenView = createLineageHallView(brokenRoot, { dominionClient: brokenClient, elementFactory: fakeElementFactory });
  await brokenView.render();
  assertCondition(
    byClass(brokenRoot, 'lineage-hall__error-message')?.textContent === 'La corriente de maná hacia el Salón se ha interrumpido.',
    'La leyenda del santuario tiene precedencia en el error',
  );

  // El catálogo caído NO derriba el Salón: degradación elegante.
  const partialRoot = mount();
  const partialClient = {
    async fetchLineages() { return { success: false, status: 502 }; },
    async fetchLeaderboard() { return { success: true, status: 200, data: buildHall() }; },
  };
  const partialView = createLineageHallView(partialRoot, { dominionClient: partialClient, elementFactory: fakeElementFactory });
  await partialView.render();
  assertCondition(
    allByClass(partialRoot, 'lineage-filter').length === 0 && allByClass(partialRoot, 'podium-rank').length === 5,
    'Sin catálogo, el Salón sigue en pie con sus claves técnicas',
  );

  // Cliente ausente: se declara el corte, jamás una excepción.
  const noClientRoot = mount();
  const noClientView = createLineageHallView(noClientRoot, { elementFactory: fakeElementFactory });
  await noClientView.render();
  assertCondition(byClass(noClientRoot, 'lineage-hall__error') !== null, 'Sin cliente del Salón, se declara el corte de corriente');

  // destroy(): la vista se retira por completo.
  await settle();
  view.destroy();
  assertCondition(root.children.length === 0, 'destroy() retira la vista del punto de montaje');
  brokenView.destroy();
  partialView.destroy();
  noClientView.destroy();
}

console.log('\n== RESUMEN ==');
console.log(`Asertos superados: ${assertsPassed}`);
console.log(`Asertos fallidos:  ${assertsFailed}`);
if (assertsFailed > 0) {
  console.log("\nRESULTADO: FALLO — La Tarea 6.3 no cumple aún su criterio 'Hecho cuando'.");
  process.exit(1);
}
console.log("\nRESULTADO: EXITO — La Tarea 6.3 cumple su criterio 'Hecho cuando'.");
