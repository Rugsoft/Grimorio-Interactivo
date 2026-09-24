/**
 * test_clan_banner_component.mjs — Arnés de la Tarea 5.2 (TASKS-07).
 *
 * Verifica sobre `public/assets/js/components/clanBannerComponent.js` el
 * criterio «Hecho cuando»:
 *   «La cabecera del portal muestra de forma destacada el escudo y lema del
 *    Clan Soberano de la semana actual con atributos ARIA accesibles.»
 *
 * Fases:
 *   [1]  Superficie del módulo y hoja de estilo (tokens, keyframes, less-motion).
 *   [2]  El blasón del Clan Regente: escudo, lema, linaje elemental y corona.
 *   [3]  Accesibilidad ARIA: región etiquetada, emblemas con nombre y proclamación.
 *   [4]  Trono vacío: sin contienda no hay error, sino leyenda solemne.
 *   [5]  Degradación elegante sin catálogo de linajes (AGENTS.md 8).
 *   [6]  Corte de maná: alerta temática y reintento que vuelve a consultar.
 *   [7]  Proclamación dirigida por evento (setRegent, plan 4.1).
 *   [8]  Ciclo de vida: re-render idempotente, destroy y respuestas tardías.
 *   [9]  Integración con el Gran Portal (landingView, SPEC-01).
 *   [10] XSS: el lore hostil viaja como texto literal (AGENTS.md 6.1).
 *
 * Constitución:
 *   - Artículo I (Dogma Vanilla): cero librerías; DOM simulado propio.
 *   - Artículo V: identificadores en inglés camelCase; comentarios en castellano.
 */

import { readFile } from 'node:fs/promises';
import {
  createClanBannerComponent,
  CLAN_BANNER_EMPTY_LEGEND,
  CLAN_BANNER_ERROR_LEGEND,
} from '../public/assets/js/components/clanBannerComponent.js';
import { createLandingView } from '../public/assets/js/views/landingView.js';

let assertsPassed = 0;
let assertsFailed = 0;

/** Aserta una condición y registra el resultado en la bitácora de consola. */
function assertCondition(condition, description) {
  if (condition) {
    assertsPassed += 1;
    console.log(`  [PASA] ${description}`);
  } else {
    assertsFailed += 1;
    console.log(`  [FALLA] ${description}`);
  }
}

/** Elemento DOM mínimo simulado (patrón consolidado de las vistas previas). */
function createFakeElement(tagName) {
  const element = {
    tagName: String(tagName).toUpperCase(),
    children: [],
    attributes: {},
    classes: new Set(),
    listeners: {},
    _textContent: '',
    parentElement: null,
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
    get textContent() {
      return `${this._textContent}${this.children.map((child) => child.textContent).join('')}`;
    },
    set textContent(value) {
      for (const child of this.children.splice(0)) child.parentElement = null;
      this._textContent = String(value);
    },
    get innerHTML() { throw new Error('PROHIBIDO innerHTML (AGENTS.md 6.1: XSS); usar textContent'); },
    set innerHTML(value) { throw new Error(`PROHIBIDO innerHTML (AGENTS.md 6.1): se intentó escribir '${String(value).slice(0, 40)}'`); },
    focus() {},
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

/** Linaje canónico tal y como lo sirve el Endpoint 10 (Tarea 3.1). */
const LINEAGE_PAYLOAD = {
  success: true,
  count: 8,
  data: [
    { id: 'primordialFlame', name: 'Linaje de la Llama Primordial', rulingElement: 'fire', glyph: 'rune-ignis', bannerColor: '#ff4500', heraldicFrame: 'phoenixShield' },
    { id: 'aetherWeavers', name: 'Linaje de los Tejedores Etéreos', rulingElement: 'pureArcane', glyph: 'rune-arcana', bannerColor: '#4169e1', heraldicFrame: 'aetherShield' },
  ],
};

/** ClanDto del reinante, con las claves del contrato (ClanDto::jsonSerialize). */
const REGENT_CLAN = {
  id: 'cln_llama',
  slug: 'custodios-de-la-llama',
  name: 'Custodios de la Llama',
  motto: 'En la ceniza renace la llama inmortal',
  coatOfArms: 'rune-ignis',
  lineageType: 'primordialFlame',
  admissionMode: 'open',
  status: 'active',
  patriarchId: 'usr_fundador',
  weeklyPoints: 0,
  historicalPoints: 620,
  memberCount: 12,
  memberLimit: 30,
};

/** Salón del Dominio con reinante (Endpoints 11, Tarea 3.3). */
const LEADERBOARD_PAYLOAD = {
  success: true,
  data: {
    weeklyRanking: [{ rank: 1, clan: REGENT_CLAN }],
    historicalRanking: [{ rank: 1, clan: REGENT_CLAN }],
    currentRegentClan: REGENT_CLAN,
    hallOfFameWeeks: [{ weekNumber: 37, cycleYear: 2026, regentClanId: 'cln_llama', winningPoints: 480 }],
  },
};

/** Salón sin contienda: aún ningún linaje ha contendido. */
const EMPTY_LEADERBOARD_PAYLOAD = {
  success: true,
  data: { weeklyRanking: [], historicalRanking: [], currentRegentClan: null, hallOfFameWeeks: [] },
};

const NETWORK_ENVELOPE = {
  success: false,
  status: 0,
  error: { code: 'MANA_STREAM_INTERRUPTED', message: 'La corriente de maná se ha interrumpido.', recoveryAction: 'RETRY' },
};

console.log('== VERIFICACION TAREA 5.2: clanBannerComponent.js ==\n');

// =====================================================================
// [1] Superficie del módulo y hoja de estilo
// =====================================================================
console.log('[1] Superficie del módulo y hoja de estilo');
assertCondition(typeof createClanBannerComponent === 'function', 'el módulo exporta la fábrica createClanBannerComponent');
assertCondition(
  typeof CLAN_BANNER_EMPTY_LEGEND === 'string' && typeof CLAN_BANNER_ERROR_LEGEND === 'string',
  'el módulo publica sus leyendas ceremoniales (RNF-03)'
);

const componentsCss = await readFile(new URL('../public/assets/css/components.css', import.meta.url), 'utf8');
assertCondition(componentsCss.includes('.clan-banner__crown--golden'), 'la hoja declara la corona dorada ceremonial (RF-04.4)');
assertCondition(componentsCss.includes('@keyframes regentFilaments'), 'los filamentos dorados animan por @keyframes nativo (Art. I)');
assertCondition(
  /@media \(prefers-reduced-motion: reduce\) \{\s*\.clan-banner__crown--golden \{\s*animation: none;/.test(componentsCss),
  'la animación se rinde ante prefers-reduced-motion (accesibilidad)'
);
const bannerCssBlock = componentsCss.slice(
  componentsCss.indexOf('.clan-banner {'),
  componentsCss.indexOf('/* Respeto por la preferencia de menos movimiento (accesibilidad, RNF-03):')
);
assertCondition(
  bannerCssBlock !== '' && /#[0-9a-fA-F]{6}/.test(bannerCssBlock) === false,
  'el blasón viste SOLO tokens de diseño: cero literales de color (RNF-05)'
);
assertCondition(
  componentsCss.includes('.clan-banner__shield') && componentsCss.includes('.clan-banner__motto'),
  'el escudo y el lema tienen su vestidura declarada en el sistema'
);

// =====================================================================
// [2] El blasón del Clan Regente (criterio)
// =====================================================================
console.log('\n[2] El blasón del Clan Regente en el portal');
const liveClient = {
  fetchLeaderboard: async () => LEADERBOARD_PAYLOAD,
  fetchLineages: async () => LINEAGE_PAYLOAD,
};
const mount1 = createFakeElement('div');
const banner1 = createClanBannerComponent(mount1, { dominionClient: liveClient, elementFactory: fakeElementFactory });
await banner1.render();

assertCondition(byClass(mount1, 'clan-banner') !== null, 'El blasón monta su región en la cabecera del portal');
assertCondition(byClass(mount1, 'clan-banner__title')?.textContent === 'Clan Regente del Santuario', 'El blasón se rotula solemnemente (RNF-03)');
const regent1 = byClass(mount1, 'clan-banner__regent');
assertCondition(regent1 !== null && regent1.getAttribute('data-regent') === 'true', 'La casa reinante se marca como regente');
assertCondition(regent1?.getAttribute('data-clan-id') === 'cln_llama', 'El blasón porta el identificador del Clan Soberano');
const seal1 = byClass(mount1, 'clan-banner__shield');
assertCondition(
  seal1?.tagName === 'SVG' && seal1?.getAttribute('viewBox') === '0 0 100 100',
  'El blasón del clan se FORJA como Sello Rúnico (SVG en línea, SPEC-02 RF-07)'
);
assertCondition(
  seal1?.getAttribute('data-heraldic-charge') === 'flame',
  'La carga central del sello declara el Linaje rector (Llama Primordial)'
);
assertCondition(
  seal1?.getAttribute('data-heraldic-state') === 'regent',
  'El sello del reinante se forja en su estado de regente (oro vivo)'
);
assertCondition(
  seal1?.textContent === '' && String(seal1?.getAttribute('aria-label') ?? '').includes('rune-ignis') === false,
  'El identificador del blasón JAMÁS se imprime: se codifica en las muescas (RF-07.3)'
);
assertCondition(
  seal1?.children.filter((child) => child.getAttribute('stroke') === 'var(--sigil-tick)').length === 8,
  'El anillo forja sus ocho muescas: la casa escrita en su propio metal'
);
assertCondition(
  byClass(mount1, 'clan-banner__motto')?.textContent === '«En la ceniza renace la llama inmortal»',
  'El LEMA heráldico se exhibe entre comillas ceremoniales'
);
assertCondition(
  byClass(mount1, 'clan-banner__clan-name')?.textContent === 'Custodios de la Llama',
  'El Nombre Canónico del linaje reinante encabeza el blasón'
);
const lineage1 = byClass(mount1, 'clan-banner__lineage');
assertCondition(
  lineage1?.textContent.includes('Linaje de la Llama Primordial') && lineage1?.textContent.includes('Fuego'),
  'El linaje elemental rector se nombra con su elemento (RF-02.2)'
);
assertCondition(lineage1?.getAttribute('data-lineage') === 'primordialFlame', 'El párrafo del linaje conserva su clave técnica para el filtro del Salón');
assertCondition(
  byClass(mount1, 'clan-banner__crown')?.classes.has('clan-banner__crown--golden'),
  'La corona dorada ceremonial preside el blasón (RF-04.4)'
);
assertCondition(
  byClass(mount1, 'clan-banner__element-glyph')?.textContent === '🜂'
    || byClass(mount1, 'clan-banner__element-glyph') !== null,
  'El glifo del elemento rector acompaña al linaje'
);
assertCondition(
  byClass(mount1, 'clan-banner__shield')?.getAttribute('style') === '--banner-tint: #ff4500',
  'El tinte del estandarte del linaje llega como Custom Property (paleta del sistema)'
);

// =====================================================================
// [3] Accesibilidad ARIA (criterio: «con atributos ARIA accesibles»)
// =====================================================================
console.log('\n[3] Accesibilidad ARIA del blasón');
const region1 = byClass(mount1, 'clan-banner');
assertCondition(region1?.getAttribute('role') === 'region', 'El blasón es una región con nombre accesible');
assertCondition(
  region1?.getAttribute('aria-labelledby') === 'clanBannerTitle'
    && byClass(mount1, 'clan-banner__title')?.getAttribute('id') === 'clanBannerTitle',
  'aria-labelledby apunta al título ceremonial del blasón'
);
assertCondition(
  seal1?.getAttribute('role') === 'img'
    && seal1?.getAttribute('aria-label')
      === 'Sello heráldico de Custodios de la Llama, del Linaje de la Llama Primordial; porta la corona dorada del Dominio durante los siete días de su mandato.',
  'El sello expone role="img" y su nombre accesible nombra casa, linaje y honor (RF-07.5)'
);
const crown1 = byClass(mount1, 'clan-banner__crown');
assertCondition(
  crown1?.getAttribute('role') === 'img' && crown1?.getAttribute('aria-label') === 'Corona dorada del Clan Regente',
  'La corona dorada se anuncia como emblema, no como adorno vacío'
);
const status1 = byClass(mount1, 'clan-banner__proclamation');
assertCondition(
  status1?.getAttribute('role') === 'status' && status1?.getAttribute('aria-live') === 'polite',
  'La proclamación vive en una región viva de cortesía (no interrumpe)'
);
assertCondition(
  status1?.textContent === '«Custodios de la Llama» reina el santuario como Clan Regente de la semana en curso.',
  'La proclamación verbaliza el reinado para lectores de pantalla'
);
assertCondition(
  regent1?.getAttribute('aria-label')
    === 'Clan Regente del Santuario: Custodios de la Llama, del Linaje de la Llama Primordial. Porta la corona dorada del Dominio.',
  'El blasón entero declara su nombre accesible con clan y linaje'
);
assertCondition(
  byClass(mount1, 'clan-banner__element-glyph')?.getAttribute('aria-hidden') === 'true',
  'El glifo ornamental queda oculto a lectores de pantalla (su texto ya se lee)'
);

// La semana reinante no se mide por el contador semanal (el corte lo reinicia):
// el blasón jamás exhibe una cifra de PDA que mostraría cero.
assertCondition(
  regent1?.textContent.includes('620') === false && regent1?.textContent.includes('Semanal: 0') === false,
  'El blasón no exhibe el contador semanal reiniciado por el corte (RF-04.3)'
);

// =====================================================================
// [4] Trono vacío (sin contienda no hay error)
// =====================================================================
console.log('\n[4] El trono vacío');
const mount4 = createFakeElement('div');
const banner4 = createClanBannerComponent(mount4, {
  dominionClient: { fetchLeaderboard: async () => EMPTY_LEADERBOARD_PAYLOAD, fetchLineages: async () => LINEAGE_PAYLOAD },
  elementFactory: fakeElementFactory,
});
await banner4.render();
assertCondition(byClass(mount4, 'clan-banner__empty')?.textContent === CLAN_BANNER_EMPTY_LEGEND, 'Sin contienda se proclama el trono vacío con leyenda solemne');
assertCondition(byClass(mount4, 'clan-banner__crown') === null, 'Trono vacío sin corona: no se corona a quien no contiende (Art. III)');
assertCondition(byClass(mount4, 'clan-banner__error') === null, 'La falta de contienda no se presenta como error técnico');

// =====================================================================
// [5] Degradación elegante sin catálogo de linajes
// =====================================================================
console.log('\n[5] Degradación sin catálogo de linajes');
const mount5 = createFakeElement('div');
const banner5 = createClanBannerComponent(mount5, {
  dominionClient: { fetchLeaderboard: async () => LEADERBOARD_PAYLOAD, fetchLineages: async () => NETWORK_ENVELOPE },
  elementFactory: fakeElementFactory,
});
await banner5.render();
assertCondition(byClass(mount5, 'clan-banner__regent') !== null, 'Sin catálogo de linajes el blasón sigue en pie');
assertCondition(
  byClass(mount5, 'clan-banner__lineage')?.textContent.includes('primordialFlame'),
  'Sin catálogo se conserva la clave técnica del linaje: nada queda en blanco (AGENTS.md 8)'
);

const mount5b = createFakeElement('div');
const banner5b = createClanBannerComponent(mount5b, {
  dominionClient: { fetchLeaderboard: async () => LEADERBOARD_PAYLOAD },
  elementFactory: fakeElementFactory,
});
await banner5b.render();
assertCondition(byClass(mount5b, 'clan-banner__regent') !== null, 'Un cliente sin fetchLineages también degrada sin romper el portal');
assertCondition(
  byClass(mount5b, 'clan-banner__element-glyph') === null,
  'Sin elemento rector conocido no se inventa glifo alguno'
);

// =====================================================================
// [6] Corte de maná y reintento
// =====================================================================
console.log('\n[6] Corte de maná y reintento');
let leaderboardAttempts = 0;
const flakyClient = {
  fetchLeaderboard: async () => {
    leaderboardAttempts += 1;
    return leaderboardAttempts === 1 ? NETWORK_ENVELOPE : LEADERBOARD_PAYLOAD;
  },
  fetchLineages: async () => LINEAGE_PAYLOAD,
};
const mount6 = createFakeElement('div');
const banner6 = createClanBannerComponent(mount6, { dominionClient: flakyClient, elementFactory: fakeElementFactory });
await banner6.render();
const errorBlock6 = byClass(mount6, 'clan-banner__error');
assertCondition(errorBlock6 !== null && errorBlock6.getAttribute('role') === 'alert', 'El corte de maná se declara como alerta (AGENTS.md 6.1)');
assertCondition(byClass(mount6, 'clan-banner__error-message')?.textContent === CLAN_BANNER_ERROR_LEGEND, 'La leyenda del corte es ceremonial y en castellano');
assertCondition(byClass(mount6, 'clan-banner__error-retry') !== null, 'El error ofrece reintento de invocación');

byClass(mount6, 'clan-banner__error-retry').dispatch('click');
await new Promise((resolve) => setTimeout(resolve, 0));
assertCondition(leaderboardAttempts === 2, 'El reintento vuelve a consultar el Salón');
assertCondition(byClass(mount6, 'clan-banner__regent') !== null, 'Tras la reconexión el blasón corona al linaje reinante');
assertCondition(byClass(mount6, 'clan-banner__error') === null, 'El error se retira al recuperar la corriente de maná');

// =====================================================================
// [7] Proclamación dirigida por evento (plan 4.1)
// =====================================================================
console.log('\n[7] Proclamación dirigida por evento');
const mount7 = createFakeElement('div');
const banner7 = createClanBannerComponent(mount7, { dominionClient: liveClient, elementFactory: fakeElementFactory });
banner7.setRegent({ ...REGENT_CLAN, id: 'cln_tempestad', name: 'Casa de la Tempestad', lineageType: 'eternalTempest', coatOfArms: 'rune-fulgur' });
assertCondition(
  byClass(mount7, 'clan-banner__regent')?.getAttribute('data-clan-id') === 'cln_tempestad',
  'setRegent corona al nuevo linaje sin consultar la red (evento dominion:week-closed)'
);
assertCondition(
  byClass(mount7, 'clan-banner__proclamation')?.textContent.includes('Casa de la Tempestad'),
  'La proclamación se actualiza con el nuevo reinante'
);
banner7.setRegent(null);
assertCondition(byClass(mount7, 'clan-banner__empty') !== null, 'setRegent(null) exhibe el trono vacío');

// =====================================================================
// [8] Ciclo de vida: idempotencia, destroy y respuestas tardías
// =====================================================================
console.log('\n[8] Ciclo de vida del blasón');
const mount8 = createFakeElement('div');
const banner8 = createClanBannerComponent(mount8, { dominionClient: liveClient, elementFactory: fakeElementFactory });
await banner8.render();
await banner8.render();
assertCondition(allByClass(mount8, 'clan-banner').length === 1, 'Re-render idempotente: una sola región, sin duplicados');
assertCondition(allByClass(mount8, 'clan-banner__crown').length === 1, 'Y una sola corona ceremonial');
banner8.destroy();
assertCondition(allByClass(mount8, 'clan-banner').length === 0, 'destroy() retira el blasón del montaje');

// Respuesta tardía: el componente destruido no pinta nada.
let releaseLeaderboard = null;
const pendingClient = {
  fetchLeaderboard: () => new Promise((resolve) => { releaseLeaderboard = resolve; }),
  fetchLineages: async () => LINEAGE_PAYLOAD,
};
const mount8b = createFakeElement('div');
const banner8b = createClanBannerComponent(mount8b, { dominionClient: pendingClient, elementFactory: fakeElementFactory });
const pendingRender = banner8b.render();
banner8b.destroy();
// Se cede el turno para que la consulta pendiente llegue a su fetch real.
await new Promise((resolve) => setTimeout(resolve, 0));
releaseLeaderboard?.(LEADERBOARD_PAYLOAD);
await pendingRender;
await new Promise((resolve) => setTimeout(resolve, 0));
assertCondition(
  allByClass(mount8b, 'clan-banner__regent').length === 0 && allByClass(mount8b, 'clan-banner').length === 0,
  'Una respuesta tardía no pinta sobre un blasón destruido (sin fugas)'
);

// =====================================================================
// [9] Integración con el Gran Portal (SPEC-01)
// =====================================================================
console.log('\n[9] Integración en la cabecera del Gran Portal');
const spellClientStub = { fetchFeatured: async () => ({ success: true, data: [] }) };

const portalRoot = createFakeElement('main');
const portal = createLandingView(portalRoot, {
  spellClient: spellClientStub,
  dominionClient: liveClient,
  onReservedAction: () => {},
  onSpellSelect: () => {},
  elementFactory: fakeElementFactory,
});
await portal.render();
const portalView = byClass(portalRoot, 'landing-view');
assertCondition(byClass(portalRoot, 'clan-banner') !== null, 'La portada del Gran Portal exhibe el blasón del Clan Regente (criterio)');
assertCondition(
  portalView?.children?.[0]?.classes?.has('landing-view__regent') === true,
  'El blasón preside la CABECERA del portal, por delante del héroe'
);
assertCondition(
  portalView?.children?.[1]?.classes?.has('landing-hero') === true,
  'La narrativa del héroe sigue a continuación sin desplazarse'
);
assertCondition(
  byClass(portalRoot, 'clan-banner__shield')?.getAttribute('data-heraldic-state') === 'regent'
    && byClass(portalRoot, 'clan-banner__motto')?.textContent.includes('En la ceniza renace la llama inmortal'),
  'El sello forjado y el lema del Clan Soberano se ven en la portada (criterio)'
);
portal.destroy();
assertCondition(byClass(portalRoot, 'clan-banner') === null, 'destroy() de la portada retira también el blasón');

// Sin cliente del Dominio, la portada queda EXACTAMENTE como antes (SPEC-01).
const legacyPortalRoot = createFakeElement('main');
const legacyPortal = createLandingView(legacyPortalRoot, {
  spellClient: spellClientStub,
  onReservedAction: () => {},
  onSpellSelect: () => {},
  elementFactory: fakeElementFactory,
});
await legacyPortal.render();
assertCondition(
  byClass(legacyPortalRoot, 'clan-banner') === null && byClass(legacyPortalRoot, 'landing-hero') !== null,
  'Sin cliente del Dominio el portal no cambia: compatibilidad intacta con SPEC-01'
);
legacyPortal.destroy();

// =====================================================================
// [10] XSS: el lore del usuario viaja como texto
// =====================================================================
console.log('\n[10] El lore hostil viaja como texto literal');
const hostilePayload = {
  success: true,
  data: {
    weeklyRanking: [],
    historicalRanking: [],
    currentRegentClan: {
      ...REGENT_CLAN,
      name: 'Clan <script>alert(1)</script>',
      motto: '<img src=x onerror=alert(1)>',
      coatOfArms: '<svg onload=alert(1)>',
    },
    hallOfFameWeeks: [],
  },
};
const mount10 = createFakeElement('div');
const banner10 = createClanBannerComponent(mount10, {
  dominionClient: { fetchLeaderboard: async () => hostilePayload, fetchLineages: async () => LINEAGE_PAYLOAD },
  elementFactory: fakeElementFactory,
});
await banner10.render();
assertCondition(
  byClass(mount10, 'clan-banner__clan-name')?.textContent === 'Clan <script>alert(1)</script>',
  'El nombre hostil viaja como TEXTO literal (cero innerHTML, AGENTS.md 6.1)'
);
assertCondition(
  byClass(mount10, 'clan-banner__motto')?.textContent.includes('<img src=x onerror=alert(1)>'),
  'El lema hostil también viaja como texto: el DOM simulado habría lanzado con innerHTML'
);
assertCondition(
  byClass(mount10, 'clan-banner__regent') !== null,
  'El blasón sobrevive a un lore hostil sin ejecutar nada'
);
assertCondition(
  byClass(mount10, 'clan-banner__shield')?.textContent === ''
    && String(byClass(mount10, 'clan-banner__shield')?.getAttribute('aria-label') ?? '').includes('<svg') === false,
  'Un blasón hostil se reduce a huella numérica: ni texto ni nombre accesible lo transportan'
);

// =====================================================================
// Resumen
// =====================================================================
console.log(`\n== RESUMEN == Asertos superados: ${assertsPassed}, fallidos: ${assertsFailed}`);
if (assertsFailed === 0) {
  console.log('RESULTADO: EXITO — El blasón del Clan Regente preside el portal con ARIA accesible (Tarea 5.2).');
  process.exit(0);
}
console.log('RESULTADO: DENEGADO — Revisa los asertos marcados.');
process.exit(1);
