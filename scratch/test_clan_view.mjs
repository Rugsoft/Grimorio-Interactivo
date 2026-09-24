/**
 * test_clan_view.mjs — Arnés de la Tarea 6.4 (TASKS-07).
 *
 * Verifica sobre `public/assets/js/views/clanView.js` el criterio «Hecho
 * cuando»:
 *   «La vista muestra los conjuros validados del clan independientemente de si
 *    los autores siguen en la hermandad, y marca con el sello de "Herencia
 *    Ancestral" si el clan está en estado `archived`.»
 *
 * Fases:
 *   [1]  Superficie del módulo y Dogma Vanilla.
 *   [2]  La ficha: blasón, lema, linaje, ocupación X/30, corona y censo.
 *   [3]  El legado sellado con el crédito de su autor original (RF-05.1).
 *   [4]  Sello de Herencia Ancestral y sus vetos (RF-05.3, RF-05.4).
 *   [5]  Veredictos de afiliación: cupo, régimen y convalecencia (RF-01.4/01.5/01.6).
 *   [6]  Órdenes cursadas: postular y renunciar (Endpoints 5 y 7).
 *   [7]  El panel del Patriarca se monta solo para quien ciñe la corona (Tarea 6.2).
 *   [8]  Estados: carga, corte de corriente y legado caído (degradación).
 *   [9]  XSS y ciclo de vida.
 *
 * Constitución:
 *   - Artículo I: cero librerías; DOM simulado propio.
 *   - Artículo V: identificadores en inglés camelCase; comentarios en castellano.
 */

import { readFile } from 'node:fs/promises';
import {
  createClanView,
  CLAN_VIEW_TITLE,
  HERITAGE_ANCESTRAL_SEAL,
  HERITAGE_ANCESTRAL_LEGEND,
  LEGACY_TITLE,
  LEGACY_TITLE_ANCESTRAL,
  LEGACY_EMPTY_LEGEND,
  LEGACY_AUTHOR_PREFIX,
  GENESIS_SEAL,
  CLAN_VIEW_LOADING_LEGEND,
  CLAN_VIEW_ERROR_LEGEND,
  CLAN_VIEW_RETRY_LABEL,
  CLAN_VIEW_LEGENDS,
  ROLE_LABELS,
  ADMISSION_LABELS,
} from '../public/assets/js/views/clanView.js';

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
    disabled: false,
    focusCount: 0,
    _textContent: '',
    parentElement: null,
    style: {},
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
    querySelectorAll() { return []; },
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

/** Barrido recursivo por atributo. */
function allByAttribute(node, attribute, found = []) {
  for (const child of node.children) {
    if (child.getAttribute(attribute) !== null) found.push(child);
    allByAttribute(child, attribute, found);
  }
  return found;
}

/** Awaits pendientes: deja correr las promesas de la vista. */
const settle = async (rounds = 8) => {
  for (let i = 0; i < rounds; i += 1) await Promise.resolve();
  await new Promise((resolve) => setTimeout(resolve, 0));
};

const mount = () => createFakeElement('main');

/** Almacén de sesión simulado (contrato de `state/store.js`). */
function buildStore(session = {}) {
  let state = {
    currentView: null,
    isAuthenticated: false,
    currentUser: null,
    userClan: null,
    userRole: 'reader',
    ...session,
  };

  return {
    getState: () => state,
    setState(partial) { state = { ...state, ...partial }; },
  };
}

/** Los 8 Linajes Canónicos, en el orden del canon (Endpoint 10). */
const CANONICAL_LINEAGES = [
  { id: 'primordialFlame', name: 'Linaje de la Llama Primordial', rulingElement: 'fire', glyph: 'rune-ignis', bannerColor: '#ff4500', heraldicFrame: 'phoenixShield' },
  { id: 'celestialTides', name: 'Linaje de las Mareas Celestiales', rulingElement: 'water', glyph: 'rune-aqua', bannerColor: '#00bfff', heraldicFrame: 'leviathanShield' },
  { id: 'eternalTempest', name: 'Linaje de la Tempestad Eterna', rulingElement: 'lightning', glyph: 'rune-fulgur', bannerColor: '#9932cc', heraldicFrame: 'thunderbirdShield' },
  { id: 'worldRoots', name: 'Linaje de las Raíces del Mundo', rulingElement: 'earth', glyph: 'rune-terra', bannerColor: '#8b4513', heraldicFrame: 'worldtreeShield' },
  { id: 'dawnWinds', name: 'Linaje de los Vientos del Alba', rulingElement: 'wind', glyph: 'rune-ventus', bannerColor: '#2e8b57', heraldicFrame: 'zephyrShield' },
  { id: 'solarCrown', name: 'Linaje de la Corona Solar', rulingElement: 'light', glyph: 'rune-lux', bannerColor: '#ffd700', heraldicFrame: 'sunwheelShield' },
  { id: 'abyssalShadows', name: 'Linaje de las Sombras Abisales', rulingElement: 'darkness', glyph: 'rune-tenebrae', bannerColor: '#4b0082', heraldicFrame: 'voidSerpentShield' },
  { id: 'aetherWeavers', name: 'Linaje de los Tejedores del Éter', rulingElement: 'pureArcane', glyph: 'rune-arcana', bannerColor: '#4169e1', heraldicFrame: 'aetherloomShield' },
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
    admissionMode: 'open',
    status: 'active',
    patriarchId: 'usr_patriarch',
    weeklyPoints: 170,
    historicalPoints: 620,
    memberCount: 2,
    memberLimit: 30,
    lastActivityAt: '2026-09-10T10:00:00Z',
    createdAt: '2026-06-01T00:00:00Z',
    updatedAt: '2026-09-10T10:00:00Z',
    ...overrides,
  };
}

/** ClanMemberDto del contrato del backend. */
function buildMember(overrides = {}) {
  return {
    id: 'clm_adept_one',
    clanId: 'cln_ember',
    userId: 'usr_adept_one',
    userAlias: 'AdeptaCeniza',
    role: 'adept',
    joinedAt: '2026-06-02T00:00:00Z',
    leftAt: null,
    convalescenceExpiresAt: null,
    isActive: true,
    ...overrides,
  };
}

/** Sobre del Endpoint 3. */
function buildDetail(overrides = {}) {
  const clan = overrides.clan ?? buildClan();
  return {
    clan,
    // `patriarch: null` es un valor canónico (casa acéfala): no se sustituye.
    patriarch: 'patriarch' in overrides
      ? overrides.patriarch
      : buildMember({ id: 'clm_pat', userId: 'usr_patriarch', userAlias: 'PatriarcaBrasa', role: 'patriarch' }),
    members: overrides.members ?? [
      buildMember({ id: 'clm_pat', userId: 'usr_patriarch', userAlias: 'PatriarcaBrasa', role: 'patriarch' }),
      buildMember({}),
    ],
    applications: overrides.applications ?? [],
  };
}

/** Sobre del Endpoint 13. */
function buildLegacy(overrides = {}) {
  const clan = overrides.clan ?? buildClan();
  const spells = overrides.spells ?? [
    {
      id: 'spl_faro', slug: 'faro-de-brasa', name: 'Faro de Brasa', magicSchool: 'abjuration',
      magicSchoolLabel: 'Abjuración', circle: 5, manaCost: 80, authorAlias: 'PatriarcaBrasa',
      validatedAt: '2026-09-10T00:00:00Z', summary: 'Resumen.', isGenesisSample: false,
    },
    {
      id: 'spl_ascua', slug: 'ascua-vigilante', name: 'Ascua Vigilante', magicSchool: 'evocation',
      magicSchoolLabel: 'Evocación', circle: 3, manaCost: 42, authorAlias: 'AdeptaCeniza',
      validatedAt: '2026-09-01T00:00:00Z', summary: 'Resumen.', isGenesisSample: false,
    },
  ];

  return { clan, spells, count: spells.length };
}

/** Cliente simulado del gobierno de hermandades. */
function buildClient({ detail = buildDetail(), legacy = buildLegacy(), applyResult = null, leaveResult = null, detailResult = null } = {}) {
  const calls = [];

  return {
    calls,
    async fetchClan(clanId) {
      calls.push(['fetchClan', clanId]);
      return detailResult ?? { success: true, status: 200, data: detail };
    },
    async fetchClanSpells(clanId) {
      calls.push(['fetchClanSpells', clanId]);
      return { success: true, status: 200, data: legacy };
    },
    async applyToClan(clanId) {
      calls.push(['applyToClan', clanId]);
      return applyResult ?? { success: true, status: 201, data: { outcome: 'pending' } };
    },
    async leaveClan(clanId) {
      calls.push(['leaveClan', clanId]);
      return leaveResult ?? { success: true, status: 200, data: { convalescenceExpiresAt: '2026-09-28T12:00:00Z' } };
    },
    // Superficie que el panel del Patriarca espera del cliente (Tarea 6.2).
    async updateClan() { calls.push(['updateClan']); return { success: true, status: 200, data: detail.clan }; },
    async resolveApplication() { calls.push(['resolveApplication']); return { success: true, status: 200, data: {} }; },
    async expelMember() { calls.push(['expelMember']); return { success: true, status: 200, data: {} }; },
    async transferLeadership() { calls.push(['transferLeadership']); return { success: true, status: 200, data: {} }; },
  };
}

/** Cliente del Salón: solo aporta el catálogo ceremonial de linajes. */
const dominionClient = {
  async fetchLineages() {
    return { success: true, status: 200, data: CANONICAL_LINEAGES, count: 8 };
  },
};

const PANEL_OWNER = Object.freeze({ viewer: { id: 'usr_patriarch', alias: 'PatriarcaBrasa', role: 'editor' }, viewerClanId: 'cln_ember' });
const ADEPT = Object.freeze({ viewer: { id: 'usr_adept_one', alias: 'AdeptaCeniza', role: 'editor' }, viewerClanId: 'cln_ember' });
const FOREIGNER = Object.freeze({ viewer: { id: 'usr_foreign', alias: 'Erudito Errante', role: 'editor' }, viewerClanId: 'cln_tide' });
/** Erudito vinculado que aún no milita en casa alguna. */
const SCHOLAR = Object.freeze({ viewer: { id: 'usr_scholar', alias: 'Erudito Libre', role: 'editor' }, viewerClanId: '' });
const VISITOR = Object.freeze({ viewer: null, viewerClanId: '' });

/** Consagra una vista montada con sesión y cliente dados. */
async function forgeView({ session = VISITOR, clientOptions = {}, viewOptions = {} } = {}) {
  const root = mount();
  const store = buildStore({
    isAuthenticated: session.viewer !== null,
    currentUser: session.viewer,
    userClan: session.viewerClanId === '' ? null : { id: session.viewerClanId, name: 'Casa' },
  });
  const client = buildClient(clientOptions);
  const view = createClanView(root, {
    clanClient: client,
    clanId: 'cln_ember',
    store,
    dominionClient,
    elementFactory: fakeElementFactory,
    ...viewOptions,
  });
  await view.render();
  await settle();

  return { root, view, client, store };
}

const source = await readFile(new URL('../public/assets/js/views/clanView.js', import.meta.url), 'utf8');
const sourceClient = await readFile(new URL('../public/assets/js/api/clanClient.js', import.meta.url), 'utf8');

console.log('\n== ARNÉS — FICHA DE LA HERMANDAD Y LEGADO ANCESTRAL (Tarea 6.4, SPEC-07) ==\n');

// =====================================================================
// FASE 1 — Superficie y Dogma Vanilla
// =====================================================================
console.log('[FASE 1] Superficie del módulo y Dogma Vanilla');
{
  assertCondition(typeof createClanView === 'function', 'El módulo exporta la fábrica de la vista');
  assertCondition(CLAN_VIEW_TITLE.trim() !== '' && HERITAGE_ANCESTRAL_SEAL === 'Herencia Ancestral', 'La vista declara su rúbrica y el sello ancestral del canon (RF-05.3)');
  assertCondition(
    LEGACY_TITLE === 'Conjuros Sellados' && LEGACY_TITLE_ANCESTRAL === 'Herencia Ancestral de la Casa',
    'El catálogo del legado muda de rótulo cuando la casa yace disuelta',
  );
  assertCondition(ROLE_LABELS.patriarch === 'Patriarca / Matriarca' && ROLE_LABELS.adept === 'Adepto del Linaje', 'Los roles canónicos se rotulan en noble castellano (Art. IV)');
  assertCondition(
    ADMISSION_LABELS.open === 'Régimen Abierto' && ADMISSION_LABELS.byApplication === 'Bajo Petición',
    'Los dos regímenes de admisión viajan con su leyenda castellana',
  );
  assertCondition(
    Object.values(CLAN_VIEW_LEGENDS).every((legend) => typeof legend === 'string' && legend.trim() !== ''),
    'Todas las leyendas de la ficha están redactadas en noble castellano (RNF-03)',
  );
  assertCondition(
    !/\.innerHTML\s*=/.test(source) && !/innerHTML\s*=/.test(source.replace(/\/\*[\s\S]*?\*\//g, '')),
    'La vista jamás asigna innerHTML (AGENTS.md 6.1)',
  );
  assertCondition(
    !/https?:\/\//.test(source) && !/cdn\./i.test(source),
    'Cero recursos ni CDNs externos (Dogma Vanilla)',
  );
  assertCondition(
    !/import[^\n]*from\s+['"](?!\.\.\/)/.test(source),
    'La vista solo importa módulos del propio santuario (Art. I)',
  );
  assertCondition(
    sourceClient.includes("`${apiBase}/clans/${path(clanId)}/spells`"),
    'El cliente consagra el Endpoint 13 sin concatenar el identificador crudo',
  );

  const { view } = await forgeView();
  assertCondition(
    ['render', 'destroy', 'retry', 'setClanClient', 'getClan'].every((method) => typeof view[method] === 'function'),
    'La API pública de la vista está completa',
  );
  view.destroy();
}

// =====================================================================
// FASE 2 — La ficha
// =====================================================================
console.log('\n[FASE 2] Blasón, lema, linaje, ocupación, corona y censo');
{
  const { root, view, store } = await forgeView();

  assertCondition(store.getState().currentView === 'clan', 'La ficha registra currentView=clan en el store (plan 4.1)');
  assertCondition(byClass(root, 'clan-view') !== null && byClass(root, 'clan-view__sheet') !== null, 'La ficha monta su raíz y su pergamino');
  assertCondition(byClass(root, 'clan-view__sheet').getAttribute('data-clan-id') === 'cln_ember', 'El pergamino declara la casa retratada');
  assertCondition(byClass(root, 'clan-view__sheet').getAttribute('role') === 'region', 'La ficha es una región etiquetada por su título');
  assertCondition(byClass(root, 'clan-view__name').textContent === 'Custodios de la Llama', 'El Nombre Canónico encabeza la ficha');
  assertCondition(byClass(root, 'clan-view__motto').textContent === '«En la ceniza renace la llama inmortal»', 'El lema viaja entre comillas ceremoniales');
  assertCondition(
    byClass(root, 'clan-view__lineage').textContent === 'Linaje de la Llama Primordial · elemento rector: Fuego',
    'El linaje rector se nombra en castellano con su elemento (RF-02.2)',
  );
  assertCondition(
    byClass(root, 'clan-view__banner').getAttribute('data-heraldic-frame') === 'phoenixShield'
      && byClass(root, 'clan-view__banner').getAttribute('style').includes('#ff4500'),
    'El estandarte ata el marco heráldico y el tinte del linaje (Tarea 6.1)',
  );
  assertCondition(
    byClass(root, 'clan-view__shield').getAttribute('aria-label')
      === 'Sello heráldico de Custodios de la Llama, del Linaje de la Llama Primordial.',
    'El sello forjado porta su nombre accesible en castellano (RF-07.5)',
  );
  assertCondition(
    byClass(root, 'clan-view__shield').tagName === 'SVG'
      && byClass(root, 'clan-view__shield').getAttribute('data-heraldic-state') === 'active'
      && byClass(root, 'clan-view__shield').getAttribute('data-heraldic-charge') === 'flame',
    'La casa viva forja su sello en oro antiguo con la carga de su linaje (RF-07.2, RF-07.4)',
  );
  assertCondition(
    byClass(root, 'clan-view__shield').textContent === '',
    'El identificador `coat_of_arms` jamás se imprime en la ficha (RF-07.3)',
  );

  const occupancy = byClass(root, 'clan-view__occupancy');
  assertCondition(
    occupancy.getAttribute('role') === 'progressbar'
      && occupancy.getAttribute('aria-valuenow') === '2'
      && occupancy.getAttribute('aria-valuemax') === '30'
      && occupancy.getAttribute('aria-valuetext') === '2 / 30 adeptos',
    'La ocupación se declara como barra accesible X/30 (RF-01.4)',
  );
  assertCondition(byClass(root, 'clan-view__occupancy-figure').textContent === '2 / 30 adeptos', 'El censo a la vista cifra la ocupación');
  assertCondition(byClass(root, 'clan-view__admission').textContent === 'Régimen Abierto', 'El régimen de admisión acompaña a la ficha (RF-01.5)');
  assertCondition(
    byClass(root, 'clan-view__dominion').textContent === 'Dominio de la semana: 170 PDA · Gloria perpetua: 620',
    'La gloria semanal y la perpetua se distinguen sin confundirse',
  );

  assertCondition(byClass(root, 'clan-view__patriarch-name').textContent === 'PatriarcaBrasa', 'La corona de la casa se proclama (RF-01.3)');
  assertCondition(byClass(root, 'clan-view__patriarch-role').textContent === 'Patriarca / Matriarca', 'Y su rol canónico en castellano');

  const roster = allByClass(root, 'clan-view__roster-item');
  assertCondition(roster.length === 2, 'El censo nominal lista a los adeptos activos (RF-01.4)');
  assertCondition(
    allByClass(root, 'clan-view__roster-role')[1].textContent === 'Adepto del Linaje',
    'Cada adepto porta su rol canónico',
  );
  assertCondition(view.getClan().id === 'cln_ember', 'La vista expone la casa retratada');
  view.destroy();
}

// =====================================================================
// FASE 3 — El legado sellado (RF-05.1)
// =====================================================================
console.log('\n[FASE 3] El legado sellado con el crédito de su autor (RF-05.1)');
{
  const { root, view, client } = await forgeView();

  assertCondition(
    client.calls.some((call) => call[0] === 'fetchClanSpells' && call[1] === 'cln_ember'),
    'La ficha consulta el legado por el Endpoint 13',
  );
  assertCondition(byClass(root, 'clan-view__sheet').getAttribute('data-status') === 'active', 'La casa viva no porta sello ancestral');

  const legacyBlock = byClass(root, 'clan-view__legacy');
  assertCondition(legacyBlock !== null && legacyBlock.getAttribute('role') === 'region', 'El legado se declara como región contemplable');
  assertCondition(byClass(root, 'clan-view__section-title') !== null, 'El catálogo porta su título ceremonial');
  assertCondition(allByClass(root, 'clan-view__section-title').some((n) => n.textContent === LEGACY_TITLE), 'El título reza «Conjuros Sellados»');
  assertCondition(byClass(root, 'clan-view__legacy-count').textContent === '2 conjuros ratificados componen el patrimonio de la casa.', 'La ficha cifra el legado');

  const spells = allByClass(root, 'clan-view__spell');
  assertCondition(spells.length === 2, 'El legado expone las dos obras ratificadas');
  assertCondition(
    spells.map((spell) => spell.getAttribute('data-spell-slug')).join(',') === 'faro-de-brasa,ascua-vigilante',
    'El orden del Santuario se respeta sin reordenar el legado (Art. II)',
  );
  assertCondition(
    byClass(spells[0], 'clan-view__spell-stats').textContent === 'Abjuración · Círculo 5 · 80 de maná',
    'Cada obra porta escuela, Círculo y coste de maná',
  );
  assertCondition(
    byClass(spells[1], 'clan-view__spell-author').textContent === `${LEGACY_AUTHOR_PREFIX} AdeptaCeniza`,
    'La obra de una autora que YA NO milita conserva su crédito original (RF-05.1)',
  );
  assertCondition(
    allByClass(root, 'clan-view__spell-author').map((node) => node.textContent).join('|')
      === 'Forjado por PatriarcaBrasa|Forjado por AdeptaCeniza',
    'El crédito «Forjado por» acompaña a cada pieza del legado',
  );

  // Selección de una obra: el orquestador abre su ficha.
  const selected = [];
  const { root: selectRoot, view: selectView } = await forgeView({ viewOptions: { onSpellSelect: (slug) => selected.push(slug) } });
  const firstSpell = allByClass(selectRoot, 'clan-view__spell')[0];
  firstSpell.dispatch('click');
  firstSpell.dispatch('keydown', { key: 'Enter' });
  assertCondition(
    selected.join(',') === 'faro-de-brasa,faro-de-brasa' && firstSpell.getAttribute('role') === 'button',
    'El legado es navegable con ratón y con teclado (RNF-03)',
  );
  selectView.destroy();

  // Legado vacío: leyenda, no error.
  const { root: emptyRoot, view: emptyView } = await forgeView({ clientOptions: { legacy: buildLegacy({ spells: [] }) } });
  assertCondition(
    byClass(emptyRoot, 'clan-view__legacy-empty')?.textContent === LEGACY_EMPTY_LEGEND,
    'Una casa sin obras ratificadas exhibe su leyenda de vacío',
  );
  emptyView.destroy();

  // Pergamino Primordial: sello honorífico.
  const { root: genesisRoot, view: genesisView } = await forgeView({
    clientOptions: {
      legacy: buildLegacy({
        spells: [{ id: 'spl_genesis_01', slug: 'chispa-de-ignicion', name: 'Chispa de Ignición', magicSchool: 'evocation', magicSchoolLabel: 'Evocación', circle: 1, manaCost: 5, authorAlias: 'El Custodio Primordial', validatedAt: '2026-01-01T00:00:00Z', summary: 'x', isGenesisSample: true }],
      }),
    },
  });
  assertCondition(
    byClass(genesisRoot, 'clan-view__spell-genesis')?.textContent === GENESIS_SEAL,
    'Los Pergaminos Primordiales lucen su sello de génesis',
  );
  genesisView.destroy();

  view.destroy();
}

// =====================================================================
// FASE 4 — Herencia Ancestral (RF-05.3, RF-05.4)
// =====================================================================
console.log('\n[FASE 4] Sello de Herencia Ancestral y sus vetos');
{
  const archivedClan = buildClan({ id: 'cln_relic', name: 'Ceniza Eterna', status: 'archived', patriarchId: null, memberCount: 0 });
  const { root, view } = await forgeView({
    session: VISITOR,
    clientOptions: {
      detail: buildDetail({ clan: archivedClan, patriarch: null, members: [] }),
      legacy: buildLegacy({ clan: archivedClan }),
    },
  });

  assertCondition(byClass(root, 'clan-view__sheet').getAttribute('data-status') === 'archived', 'La ficha declara el estado `archived` de la casa');
  assertCondition(
    byClass(root, 'clan-view__shield').getAttribute('data-heraldic-state') === 'archived'
      && byClass(root, 'clan-view__shield').getAttribute('aria-label')
        === 'Sello heráldico de Ceniza Eterna, del Linaje de la Llama Primordial; casa disuelta, conservada como Herencia Ancestral.',
    'La casa disuelta viste el sello de bronce con anillo roto y lo declara (RF-07.4)',
  );
  assertCondition(
    byClass(root, 'clan-view__heritage-seal')?.textContent === HERITAGE_ANCESTRAL_SEAL
      && byClass(root, 'clan-view__heritage-seal').getAttribute('data-heritage') === 'ancestral',
    'La casa disuelta se sella como «Herencia Ancestral» (RF-05.3)',
  );
  assertCondition(
    byClass(root, 'clan-view__heritage-legend')?.textContent === HERITAGE_ANCESTRAL_LEGEND,
    'El sello se acompaña de la leyenda de la memoria inmortal (RF-05.4)',
  );
  assertCondition(
    byClass(root, 'clan-view__legacy').getAttribute('data-heritage') === 'ancestral'
      && byClass(root, 'clan-view__legacy-heritage').textContent === HERITAGE_ANCESTRAL_SEAL,
    'El catálogo del legado también porta el sello ancestral',
  );
  assertCondition(
    allByClass(root, 'clan-view__section-title').some((n) => n.textContent === LEGACY_TITLE_ANCESTRAL),
    'El catálogo muda de rótulo cuando la casa yace disuelta',
  );
  assertCondition(allByClass(root, 'clan-view__spell').length === 2, 'Los conjuros ratificados se preservan perpetuamente en el Gran Tomo');

  const action = byClass(root, 'clan-view__action');
  assertCondition(action.disabled === true && action.getAttribute('aria-disabled') === 'true', 'Una casa disuelta no admite adeptos: el gesto queda vedado');
  assertCondition(byClass(root, 'clan-view__affiliation-legend').textContent === CLAN_VIEW_LEGENDS.archived, 'Y la leyenda del canon lo explica (RF-05.3)');
  assertCondition(byClass(root, 'clan-view__patriarch-absent') !== null, 'Una casa acéfala declara la ausencia de corona');
  assertCondition(byClass(root, 'clan-view__government') === null, 'Una casa disuelta no ofrece gobierno alguno (RF-05.4)');
  assertCondition(
    byClass(root, 'clan-view__legacy-count').textContent === '2 conjuros ratificados componen el patrimonio de la casa.',
    'Un patrimonio plural se canta en plural',
  );
  view.destroy();

  // El patrimonio de una sola obra se canta en singular (noble castellano).
  const singleLegacy = buildLegacy({
    clan: archivedClan,
    spells: [buildLegacy().spells[0]],
  });
  const { root: singleRoot, view: singleView } = await forgeView({
    session: VISITOR,
    clientOptions: {
      detail: buildDetail({ clan: archivedClan, patriarch: null, members: [] }),
      legacy: singleLegacy,
    },
  });
  assertCondition(
    byClass(singleRoot, 'clan-view__legacy-count').textContent === '1 conjuro ratificado compone el patrimonio de la casa.',
    'Un patrimonio de una sola obra se canta en singular',
  );
  singleView.destroy();
}

// =====================================================================
// FASE 5 — Veredictos de afiliación
// =====================================================================
console.log('\n[FASE 5] Veredictos de afiliación (RF-01.4, RF-01.5, RF-01.6)');
{
  // Visitante anónimo: el gesto conduce al umbral.
  const reserved = [];
  const { root: visitorRoot, view: visitorView } = await forgeView({
    session: VISITOR,
    viewOptions: { onReservedAction: (action, target) => reserved.push(`${action}:${target}`) },
  });
  const visitorButton = byClass(visitorRoot, 'clan-view__action');
  assertCondition(
    visitorButton.disabled === false && visitorButton.textContent === CLAN_VIEW_LEGENDS.visitor,
    'El visitante anónimo conserva el camino de la consagración (RF-01.2)',
  );
  visitorButton.dispatch('click');
  assertCondition(reserved.join(',') === 'joinClan:cln_ember', 'El gesto reservado retiene la casa cortejada en la intención');
  visitorView.destroy();

  // Régimen abierto: «Unirse» disponible para el erudito sin casa.
  const { root: openRoot, view: openView } = await forgeView({ session: SCHOLAR });
  assertCondition(
    byClass(openRoot, 'clan-view__action').textContent === CLAN_VIEW_LEGENDS.joinOpen
      && byClass(openRoot, 'clan-view__action').getAttribute('data-affiliation-action') === 'apply',
    'El régimen abierto rotula «Unirse a la hermandad»',
  );

  // Régimen bajo petición: «Postular».
  const { root: applicationRoot, view: applicationView } = await forgeView({
    session: SCHOLAR,
    clientOptions: { detail: buildDetail({ clan: buildClan({ admissionMode: 'byApplication' }) }) },
  });
  assertCondition(byClass(applicationRoot, 'clan-view__action').textContent === CLAN_VIEW_LEGENDS.joinByApplication, 'El régimen `byApplication` rotula «Postular al ingreso»');

  // Cupo colmado a treinta.
  const { root: fullRoot, view: fullView } = await forgeView({
    session: SCHOLAR,
    clientOptions: { detail: buildDetail({ clan: buildClan({ memberCount: 30 }) }) },
  });
  assertCondition(
    byClass(fullRoot, 'clan-view__action').disabled === true
      && byClass(fullRoot, 'clan-view__affiliation-legend').textContent === CLAN_VIEW_LEGENDS.quotaFull,
    'La plenitud de treinta hermanos veda el ingreso con su leyenda (RF-01.4)',
  );

  // Convalecencia activa.
  const { root: convalescentRoot, view: convalescentView } = await forgeView({
    session: SCHOLAR,
    viewOptions: { convalescenceExpiresAt: new Date(Date.now() + 5 * 86400000).toISOString() },
  });
  assertCondition(
    byClass(convalescentRoot, 'clan-view__action').disabled === true
      && byClass(convalescentRoot, 'clan-view__affiliation-legend').textContent === CLAN_VIEW_LEGENDS.convalescence,
    'La Convalecencia Arcana veda el ingreso con su leyenda ceremonial (RF-01.6)',
  );

  // Convalecencia fenecida: el veto se levanta.
  const { root: freedRoot, view: freedView } = await forgeView({
    session: SCHOLAR,
    viewOptions: { convalescenceExpiresAt: new Date(Date.now() - 86400000).toISOString() },
  });
  assertCondition(
    byClass(freedRoot, 'clan-view__action').disabled === false
      && byClass(freedRoot, 'clan-view__action').textContent === CLAN_VIEW_LEGENDS.joinOpen,
    'Una convalecencia fenecida devuelve la libertad de afiliación',
  );

  // Lealtad indivisible: ya milita en otra casa.
  const { root: foreignRoot, view: foreignView } = await forgeView({ session: FOREIGNER });
  assertCondition(
    byClass(foreignRoot, 'clan-view__action').disabled === true
      && byClass(foreignRoot, 'clan-view__affiliation-legend').textContent === CLAN_VIEW_LEGENDS.alreadyAffiliated,
    'La lealtad mágica es indivisible: no se corteja una segunda casa (RF-01.1)',
  );

  // Adepto de la casa: renuncia disponible.
  const { root: adeptRoot, view: adeptView } = await forgeView({ session: ADEPT });
  assertCondition(
    byClass(adeptRoot, 'clan-view__action').disabled === false
      && byClass(adeptRoot, 'clan-view__action').getAttribute('data-affiliation-action') === 'leave',
    'Un adepto puede renunciar a su hermandad (RF-01.3)',
  );
  assertCondition(byClass(adeptRoot, 'clan-view__affiliation-legend').textContent === CLAN_VIEW_LEGENDS.member, 'La ficha le recuerda su juramento vigente');

  // El Patriarca no parte sin ceder la corona.
  const { root: crownRoot, view: crownView } = await forgeView({ session: PANEL_OWNER });
  assertCondition(
    byClass(crownRoot, 'clan-view__action').disabled === true
      && byClass(crownRoot, 'clan-view__affiliation-legend').textContent === CLAN_VIEW_LEGENDS.crownMustTransfer,
    'El Patriarca no puede partir sin ceder antes la corona (caso límite 1)',
  );

  [openView, applicationView, fullView, convalescentView, freedView, foreignView, adeptView, crownView].forEach((v) => v.destroy());
}

// =====================================================================
// FASE 6 — Órdenes cursadas
// =====================================================================
console.log('\n[FASE 6] Postular y renunciar (Endpoints 5 y 7)');
{
  // Postulación desde el régimen bajo petición.
  const changes = [];
  const { root, view, client, store } = await forgeView({
    session: SCHOLAR,
    clientOptions: { detail: buildDetail({ clan: buildClan({ admissionMode: 'byApplication' }) }) },
    viewOptions: { onMembershipChanged: (envelope) => changes.push(envelope) },
  });
  byClass(root, 'clan-view__action').dispatch('click');
  await settle();
  assertCondition(
    client.calls.some((call) => call[0] === 'applyToClan' && call[1] === 'cln_ember'),
    'El gesto de postular cursa la orden contra el santuario (Endpoint 5)',
  );
  assertCondition(changes.length === 1, 'El orquestador queda advertido del cambio de vínculo');
  assertCondition(
    byClass(root, 'clan-view__proclamation')?.textContent.includes('postulación'),
    'La ficha proclama la postulación consumada',
  );
  assertCondition(store.getState().currentView === 'clan', 'La ficha sigue siendo la vista activa tras la orden');

  // Renuncia del adepto.
  const { root: leaveRoot, view: leaveView, client: leaveClient } = await forgeView({ session: ADEPT });
  byClass(leaveRoot, 'clan-view__action').dispatch('click');
  await settle();
  assertCondition(
    leaveClient.calls.some((call) => call[0] === 'leaveClan' && call[1] === 'cln_ember'),
    'El gesto de renunciar cursa la orden contra el santuario (Endpoint 7)',
  );
  assertCondition(
    byClass(leaveRoot, 'clan-view__proclamation')?.textContent.includes('Convalecencia'),
    'La renuncia anuncia la Convalecencia Arcana de catorce días (RF-01.6)',
  );

  // Rechazo del santuario: la leyenda canónica se propaga sin romper la ficha.
  const { root: vetoRoot, view: vetoView } = await forgeView({
    session: SCHOLAR,
    clientOptions: { applyResult: { success: false, status: 409, error: { code: 'CLAN_QUOTA_EXCEEDED', message: 'La hermandad ha alcanzado su plenitud de treinta hermanos.' } } },
  });
  byClass(vetoRoot, 'clan-view__action').dispatch('click');
  await settle();
  assertCondition(
    byClass(vetoRoot, 'clan-view__proclamation')?.textContent === 'La hermandad ha alcanzado su plenitud de treinta hermanos.',
    'El veredicto del santuario se proclama tal cual (Art. II)',
  );

  // Corte de red: leyenda ceremonial del cliente, jamás una excepción.
  const { root: downRoot, view: downView } = await forgeView({
    session: SCHOLAR,
    clientOptions: { applyResult: { success: false, status: 0, error: { code: 'MANA_STREAM_INTERRUPTED', message: '' } } },
  });
  byClass(downRoot, 'clan-view__action').dispatch('click');
  await settle();
  assertCondition(
    byClass(downRoot, 'clan-view__proclamation')?.textContent.includes('interrumpido'),
    'Un corte de corriente se traduce a la leyenda ceremonial del cliente',
  );

  // Concurrencia: dos gestos seguidos cursan UNA sola orden.
  const { root: raceRoot, view: raceView, client: raceClient } = await forgeView({ session: ADEPT });
  const raceButton = byClass(raceRoot, 'clan-view__action');
  raceButton.dispatch('click');
  raceButton.dispatch('click');
  await settle();
  assertCondition(
    raceClient.calls.filter((call) => call[0] === 'leaveClan').length === 1,
    'Dos gestos seguidos cursan una única orden (RNF-01)',
  );

  [view, leaveView, vetoView, downView, raceView].forEach((v) => v.destroy());
}

// =====================================================================
// FASE 7 — El panel del Patriarca
// =====================================================================
console.log('\n[FASE 7] El panel de gobierno se monta solo para quien ciñe la corona');
{
  const { root, view } = await forgeView({ session: PANEL_OWNER });
  assertCondition(byClass(root, 'clan-view__government') !== null, 'El Patriarca contempla su panel de gobierno (Tarea 6.2)');
  assertCondition(byClass(root, 'clan-management') !== null, 'El panel despliega sus secciones sobre la ficha');
  view.destroy();

  const { root: strangerRoot, view: strangerView } = await forgeView({ session: FOREIGNER });
  assertCondition(byClass(strangerRoot, 'clan-view__government') === null, 'El forastero no ve gobierno alguno (artículo III: la corona manda)');
  strangerView.destroy();

  const { root: adeptRoot, view: adeptView } = await forgeView({ session: ADEPT });
  assertCondition(byClass(adeptRoot, 'clan-view__government') === null, 'El adepto tampoco gobierna: la corona es indivisible');
  adeptView.destroy();
}

// =====================================================================
// FASE 8 — Estados
// =====================================================================
console.log('\n[FASE 8] Carga, corte de corriente y legado caído (degradación)');
{
  // La ficha declara la convocatoria mientras el santuario no responde.
  const slowRoot = mount();
  const slowView = createClanView(slowRoot, {
    clanClient: {
      fetchClan: () => new Promise(() => {}),
      fetchClanSpells: () => new Promise(() => {}),
    },
    clanId: 'cln_ember',
    elementFactory: fakeElementFactory,
  });
  // La invocación queda pendiente a propósito: ningún handle mantiene vivo el
  // proceso, así que la vista se destruye sin aguardar su promesa.
  void slowView.render();
  assertCondition(byClass(slowRoot, 'clan-view__loading')?.textContent === CLAN_VIEW_LOADING_LEGEND, 'Mientras se invoca, la ficha declara su convocatoria');
  slowView.destroy();

  // 404: la leyenda del santuario tiene precedencia.
  const { root: missingRoot, view: missingView } = await forgeView({
    clientOptions: { detailResult: { success: false, status: 404, error: { code: 'CLAN_NOT_FOUND', message: 'No hay hermandad inscrita con ese estandarte en el santuario.' } } },
  });
  const errorBlock = byClass(missingRoot, 'clan-view__error');
  assertCondition(errorBlock !== null && errorBlock.getAttribute('role') === 'alert', 'Un rechazo monta el estado de error con role=alert');
  assertCondition(
    byClass(missingRoot, 'clan-view__error-message').textContent === 'No hay hermandad inscrita con ese estandarte en el santuario.',
    'La leyenda del santuario encabeza el error',
  );

  // Sin cliente: leyenda ceremonial propia, jamás una excepción.
  const bareRoot = mount();
  const bareView = createClanView(bareRoot, { clanId: 'cln_ember', elementFactory: fakeElementFactory });
  await bareView.render();
  assertCondition(
    byClass(bareRoot, 'clan-view__error-message')?.textContent === CLAN_VIEW_ERROR_LEGEND,
    'Sin cliente del santuario, la ficha declara el corte con su leyenda propia',
  );
  assertCondition(byClass(bareRoot, 'clan-view__error-retry')?.textContent === CLAN_VIEW_RETRY_LABEL, 'Y ofrece el reintento ceremonial');

  // El reintento vuelve a consultar.
  const retryCalls = [];
  const retryRoot = mount();
  let attempt = 0;
  const retryView = createClanView(retryRoot, {
    clanClient: {
      async fetchClan() {
        retryCalls.push('fetchClan');
        attempt += 1;
        return attempt === 1
          ? { success: false, status: 0, error: { code: 'MANA_STREAM_INTERRUPTED', message: 'La corriente se interrumpió.' } }
          : { success: true, status: 200, data: buildDetail() };
      },
      async fetchClanSpells() { retryCalls.push('fetchClanSpells'); return { success: true, status: 200, data: buildLegacy() }; },
    },
    clanId: 'cln_ember',
    elementFactory: fakeElementFactory,
  });
  await retryView.render();
  await settle();
  assertCondition(byClass(retryRoot, 'clan-view__error') !== null, 'El primer intento cayó y la ficha lo declara');
  byClass(retryRoot, 'clan-view__error-retry').dispatch('click');
  await settle(12);
  assertCondition(
    retryCalls.filter((name) => name === 'fetchClan').length === 2 && byClass(retryRoot, 'clan-view__sheet') !== null,
    'El reintento vuelve a invocar al santuario y la ficha se alza de nuevo',
  );

  // El legado caído no derriba la ficha: degradación elegante.
  const legacyDownRoot = mount();
  const legacyDownView = createClanView(legacyDownRoot, {
    clanClient: {
      async fetchClan() { return { success: true, status: 200, data: buildDetail() }; },
      async fetchClanSpells() { return { success: false, status: 0, error: { code: 'MANA_STREAM_INTERRUPTED', message: 'x' } }; },
    },
    clanId: 'cln_ember',
    elementFactory: fakeElementFactory,
  });
  await legacyDownView.render();
  await settle();
  assertCondition(byClass(legacyDownRoot, 'clan-view__sheet') !== null && byClass(legacyDownRoot, 'clan-view__legacy-empty') !== null, 'Con el legado caído, la ficha sigue en pie con su leyenda de vacío');

  [missingView, bareView, retryView, legacyDownView].forEach((v) => v.destroy());
}

// =====================================================================
// FASE 9 — XSS y ciclo de vida
// =====================================================================
console.log('\n[FASE 9] XSS y ciclo de vida');
{
  const hostile = '<img src=x onerror="alert(1)">';
  const { root, view } = await forgeView({
    clientOptions: {
      detail: buildDetail({ clan: buildClan({ name: hostile, motto: hostile }) }),
      legacy: buildLegacy({ spells: [{ id: 'spl_x', slug: 'x', name: hostile, magicSchool: 'evocation', magicSchoolLabel: 'Evocación', circle: 1, manaCost: 5, authorAlias: hostile, validatedAt: null, summary: 'x', isGenesisSample: false }] }),
    },
  });

  const nameNode = byClass(root, 'clan-view__name');
  assertCondition(nameNode.textContent === hostile, 'El Nombre Canónico hostil se pinta como TEXTO (AGENTS.md 6.1)');
  assertCondition(
    Object.values(nameNode.attributes).every((value) => !String(value).includes('<')),
    'Ningún atributo hospeda el marcado hostil',
  );
  assertCondition(
    byClass(root, 'clan-view__spell-author').textContent === `${LEGACY_AUTHOR_PREFIX} ${hostile}`,
    'El crédito del autor también viaja como texto seguro',
  );

  const rootChildren = root.children.length;
  assertCondition(rootChildren === 1, 'La ficha vive montada en su raíz');
  view.destroy();
  assertCondition(root.children.length === 0, 'destroy() retira la ficha del punto de montaje');
  await view.render();
  assertCondition(root.children.length === 0, 'Una vista destruida no vuelve a montar nada');
  view.destroy();
}

console.log('\n== RESUMEN ==');
console.log(`Asertos superados: ${assertsPassed}`);
console.log(`Asertos fallidos:  ${assertsFailed}`);
if (assertsFailed > 0) {
  console.log("\nRESULTADO: DENEGADO — La Tarea 6.4 no cumple aún su criterio 'Hecho cuando'.");
  process.exit(1);
}
console.log("\nRESULTADO: EXITO — La Tarea 6.4 cumple su criterio 'Hecho cuando'.");
