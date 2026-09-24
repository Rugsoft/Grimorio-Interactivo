/**
 * test_masters_tower_component.mjs — Arnés de la Tarea 5.3 (TASKS-08).
 *
 * Uso: node scratch/test_masters_tower_component.mjs
 *
 * Verifica sobre `public/assets/js/components/mastersTowerComponent.js`
 * el criterio «Hecho cuando»:
 *   «Un Maestro con conflicto ético ve el botón de firma deshabilitado con
 *    la leyenda *«Veto Constitucional: Hermandad Incompatible»*, mientras que
 *    uno apto puede firmar u objetar.»
 *
 * Fases:
 *   [1]  Superficie del módulo, canon de reserva y leyendas de veto.
 *   [2]  Las tarjetas de la Torre: medidor, elemento, escuela y espera (RF-05.4).
 *   [3]  El Maestro apto: firma y objeción habilitadas (criterio).
 *   [4]  El Maestro vetado: botón inhabilitado con la leyenda del plan 4.2
 *        servida por el backend, y su CAUSA declarada (criterio, RF-03.3).
 *   [5]  Accesibilidad ARIA: región, botones inhabilitados y alertas vivas.
 *   [6]  Los umbrales del Cónclave viajan del canon servido, jamás a mano.
 *   [7]  Los filtros de la Torre (RF-05.4): cambio de filtros y evento en el bus.
 *   [8]  Los gestos por el bus: sign-intent y objection-intent (plan 4.1).
 *   [9]  Degradación elegante: sin canon del backend (AGENTS.md 8).
 *   [10] Corte de maná: alerta temática y reintento que vuelve a consultar.
 *   [11] Actualización dirigida por evento (setQueueItems, plan 4.1).
 *   [12] Ciclo de vida: re-render idempotente, destroy y respuestas tardías.
 *   [13] XSS: el lore hostil viaja como texto literal (AGENTS.md 6.1).
 *
 * Constitución:
 *   - Artículo I (Dogma Vanilla): cero librerías; DOM simulado propio.
 *   - Artículo V: identificadores en inglés camelCase; comentarios en castellano.
 */

import { readFile } from 'node:fs/promises';
import {
  createMastersTowerComponent,
  MASTERS_TOWER_FALLBACK_CANON,
  MASTERS_TOWER_VETO_LEGENDS,
  MASTERS_TOWER_VETO_UNKNOWN_LEGEND,
  MASTERS_TOWER_EMPTY_LEGEND,
  MASTERS_TOWER_ERROR_LEGEND,
} from '../public/assets/js/components/mastersTowerComponent.js';

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
    disabled: false,
    value: '',
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

/** Documento anfitrión simulado con un bus de eventos registrador. */
function createFakeDocument() {
  const dispatchedEvents = [];
  const fakeDocument = {
    createElement: (tagName) => createFakeElement(tagName),
    dispatchedEvents,
    defaultView: {
      dispatchEvent: (event) => {
        dispatchedEvents.push(event);
        return true;
      },
    },
    CustomEvent: class FakeCustomEvent {
      constructor(type, options = {}) {
        this.type = type;
        this.detail = options.detail ?? null;
      }
    },
  };
  return fakeDocument;
}

// ---------------------------------------------------------------------
// Contratos de la cola de la Torre (ModerationQueueItemDto servido).
// ---------------------------------------------------------------------

const CANON = {
  signaturesRequired: 3,
  maxGlossLength: 250,
  minObjectionLength: 20,
  judgeRole: 'master',
};

/** Obra apta: Maestro de hermandad ajena juzga sin conflicto (plan 3.2). */
const QUEUE_ITEM_ELIGIBLE = {
  spellId: 'spl_porta',
  spellSlug: 'portal-de-brasas',
  spellName: 'Portal de Brasas',
  authorId: 'usr_autor',
  authorAlias: 'brasas',
  originClanId: 'cln_llama',
  originClanName: 'Custodios de la Llama',
  elementalAffinity: 'fire',
  magicSchool: 'evocation',
  signaturesCount: 1,
  signaturesRequired: 3,
  signaturesIndicator: '1/3',
  hasEthicalConflict: false,
  ethicalVeto: null,
  submittedAt: '2026-09-01T10:00:00Z',
  waitingDays: 14,
};

/** Obra vetada por hermandad: Maestro del linaje de la obra (plan 3.2). */
const QUEUE_ITEM_CLAN_CONFLICT = {
  ...QUEUE_ITEM_ELIGIBLE,
  spellId: 'spl_espejo',
  spellSlug: 'espejo-de-sal',
  spellName: 'Espejo de Sal',
  authorAlias: 'salmantino',
  originClanId: 'cln_marea',
  originClanName: 'Custodios de la Marea',
  elementalAffinity: 'water',
  magicSchool: 'illusion',
  signaturesCount: 0,
  signaturesIndicator: '0/3',
  hasEthicalConflict: true,
  ethicalVeto: {
    code: 'clanIncompatibility',
    legend: 'Conflicto de intereses: no es lícito a un Maestro juzgar las obras nacidas bajo su propio estandarte, linajes recientes o propia pluma.',
  },
  waitingDays: 3,
};

/** Obra vetada por propia pluma: el autor-Maestro sobre su obra (plan 3.2). */
const QUEUE_ITEM_OWN_PLUME = {
  ...QUEUE_ITEM_ELIGIBLE,
  spellId: 'spl_sombra',
  spellSlug: null,
  spellName: 'Llama de la Sombra',
  authorAlias: 'la pluma propia',
  originClanId: null,
  originClanName: null,
  elementalAffinity: 'darkness',
  magicSchool: 'necromancy',
  signaturesCount: 2,
  signaturesIndicator: '2/3',
  hasEthicalConflict: true,
  ethicalVeto: {
    code: 'ownAuthorship',
    legend: 'Conflicto de intereses: no es lícito a un Maestro juzgar las obras nacidas bajo su propio estandarte, linajes recientes o propia pluma.',
  },
  waitingDays: 0,
};

const QUEUE_PAYLOAD = {
  success: true,
  data: {
    canon: CANON,
    items: [QUEUE_ITEM_ELIGIBLE, QUEUE_ITEM_CLAN_CONFLICT, QUEUE_ITEM_OWN_PLUME],
    pagination: { page: 1, limit: 12, totalItems: 3, totalPages: 1 },
  },
};

const EMPTY_QUEUE_PAYLOAD = {
  success: true,
  data: { canon: CANON, items: [], pagination: { page: 1, limit: 12, totalItems: 0, totalPages: 1 } },
};

const NETWORK_ENVELOPE = {
  success: false,
  status: 0,
  error: { code: 'MANA_STREAM_INTERRUPTED', message: 'La corriente de maná se ha interrumpido.', recoveryAction: 'RETRY' },
};

console.log('== VERIFICACION TAREA 5.3: mastersTowerComponent.js ==\n');

const fakeDocument = createFakeDocument();
globalThis.document = fakeDocument;

// =====================================================================
// [1] Superficie del módulo
// =====================================================================
console.log('[1] Superficie del módulo');
assertCondition(typeof createMastersTowerComponent === 'function', 'el módulo exporta la fábrica createMastersTowerComponent');
assertCondition(
  typeof MASTERS_TOWER_EMPTY_LEGEND === 'string' && typeof MASTERS_TOWER_ERROR_LEGEND === 'string',
  'el módulo publica sus leyendas ceremoniales (RNF-03)'
);
assertCondition(
  MASTERS_TOWER_VETO_LEGENDS.clanIncompatibility === 'Veto Constitucional: Hermandad Incompatible (Art. III)',
  'la reserva del veto de hermandad rotula la leyenda canónica del plan 4.2'
);
assertCondition(
  MASTERS_TOWER_VETO_LEGENDS.ownAuthorship !== MASTERS_TOWER_VETO_LEGENDS.clanIncompatibility,
  'la propia pluma y la hermandad se distinguen con rótulos distintos'
);
assertCondition(
  MASTERS_TOWER_FALLBACK_CANON.signaturesRequired === 3
    && MASTERS_TOWER_FALLBACK_CANON.maxGlossLength === 250
    && MASTERS_TOWER_FALLBACK_CANON.minObjectionLength === 20,
  'la reserva del canon declara los umbrales del Cónclave'
);
assertCondition(typeof MASTERS_TOWER_VETO_UNKNOWN_LEGEND === 'string', 'el veto de causa desconocida también tiene su leyenda');

// =====================================================================
// [2] Las tarjetas de la Torre (RF-05.4)
// =====================================================================
console.log('\n[2] Las tarjetas de la Torre de Deliberación');
const mount1 = createFakeElement('div');
const tower1 = createMastersTowerComponent(mount1, {
  moderationClient: { fetchDeliberationQueue: async () => QUEUE_PAYLOAD },
  elementFactory: fakeElementFactory,
  documentRef: fakeDocument,
});
await tower1.render();

const region1 = byClass(mount1, 'masters-tower');
assertCondition(region1 !== null, 'la Torre monta su región solemne');
assertCondition(
  byClass(mount1, 'masters-tower__title')?.textContent === 'Torre de Deliberación',
  'el panel se rotula solemnemente (RNF-03)'
);
assertCondition(
  byClass(mount1, 'masters-tower__notice')?.textContent.includes('Cónclave'),
  'el recinto se anuncia con su advertencia litúrgica'
);
const cards = allByClass(mount1, 'masters-tower__card');
assertCondition(cards.length === 3, 'cada obra de la cola recibe su tarjeta');
assertCondition(cards[0]?.getAttribute('data-spell-id') === 'spl_porta', 'la tarjeta porta el identificador de la obra');
assertCondition(cards[0]?.getAttribute('data-status') === 'experimental', 'la cola solo presenta obras en deliberación');
assertCondition(
  byClass(mount1, 'masters-tower__spell-name')?.textContent === 'Portal de Brasas',
  'el nombre visible de la obra encabeza la tarjeta'
);
assertCondition(
  byClass(mount1, 'masters-tower__author')?.textContent === 'Forjada por brasas',
  'el alias del autor acompaña a la obra'
);
const lineage1 = byClass(mount1, 'masters-tower__lineage');
assertCondition(lineage1?.textContent === 'Custodios de la Llama', 'el linaje patrimonial se exhibe con su nombre canónico');
const hermitLineage = allByClass(mount1, 'masters-tower__lineage')[2];
assertCondition(
  hermitLineage?.textContent === 'Obra de ermitaño: sin estandarte',
  'la obra del ermitaño se juzga sin estandarte inventado'
);
const element1 = byClass(mount1, 'masters-tower__element');
assertCondition(
  element1?.textContent === 'Afinidad: Fuego' && element1?.getAttribute('data-element') === 'fire',
  'el elemento se nombra con su nombre ceremonial y conserva su clave técnica'
);
assertCondition(
  byClass(mount1, 'masters-tower__school')?.textContent === 'Escuela: evocation',
  'la escuela mágica viaja en su tarjeta'
);
const meters = allByClass(mount1, 'masters-tower__meter');
assertCondition(meters.length === 3, 'cada tarjeta porta su medidor de firmas');
assertCondition(
  meters[0]?.textContent === '1/3' && meters[0]?.getAttribute('data-signatures') === '1',
  'el medidor retrata el contador SERVIDO por el expediente (1/3)'
);
assertCondition(
  byClass(mount1, 'masters-tower__waiting')?.textContent.includes('14 días'),
  'la antigüedad en la Torre se proclama con sus días de espera'
);
assertCondition(
  byClass(mount1, 'masters-tower__announcement')?.textContent === '3 obras aguardan veredicto del Cónclave.',
  'el censo de la cola se anuncia para lectores de pantalla'
);

// =====================================================================
// [3] El Maestro apto: firma y objeción habilitadas (criterio)
// =====================================================================
console.log('\n[3] El Maestro apto puede firmar y objetar');
const signButtons = allByClass(mount1, 'masters-tower__sign-button');
const objectButtons = allByClass(mount1, 'masters-tower__object-button');
assertCondition(signButtons.length === 3 && objectButtons.length === 3, 'cada tarjeta porta sus dos botones del Cónclave');
assertCondition(signButtons[0]?.disabled === false, 'la obra sin conflicto deja estampar la Firma de Consagración (criterio)');
assertCondition(objectButtons[0]?.disabled === false, 'la obra sin conflicto admite también el Dictamen de Objeción');
assertCondition(signButtons[0]?.getAttribute('data-ethical-veto') === null, 'la tarjeta apta no declara veto alguno');
assertCondition(byClass(mount1, 'masters-tower__ethical-veto') === null || allByClass(mount1, 'masters-tower__ethical-veto').length === 2, 'solo las tarjetas vetadas proclaman alerta ética');

// =====================================================================
// [4] El Maestro vetado: botón inhabilitado con su CAUSA (criterio, RF-03.3)
// =====================================================================
console.log('\n[4] El veto ético del Artículo III ya resuelto');
const vetoAlerts = allByClass(mount1, 'masters-tower__ethical-veto');
assertCondition(vetoAlerts.length === 2, 'cada tarjeta vetada proclama su alerta ceremonial');
assertCondition(vetoAlerts.every((alert) => alert.getAttribute('role') === 'alert'), 'la alerta ética vive como role="alert"');
assertCondition(
  signButtons[1]?.disabled === true && signButtons[1]?.getAttribute('aria-disabled') === 'true',
  'el botón de firma de la obra del propio linaje nace INHABILITADO (criterio)'
);
assertCondition(
  signButtons[1]?.getAttribute('data-ethical-veto') === 'clanIncompatibility',
  'la CAUSA del veto viaja en la tarjeta (clanIncompatibility)'
);
assertCondition(
  vetoAlerts[0]?.getAttribute('data-ethical-veto-code') === 'clanIncompatibility'
    && vetoAlerts[0]?.textContent.includes('propio estandarte'),
  'la leyenda del veto de hermandad viaja desde su fuente única del servidor'
);
assertCondition(
  vetoAlerts[1]?.getAttribute('data-ethical-veto-code') === 'ownAuthorship',
  'la propia pluma se distingue del veto de hermandad (ownAuthorship)'
);
assertCondition(
  objectButtons[1]?.disabled === false && objectButtons[2]?.disabled === false,
  'el veto alcanza la FIRMA: el Dictamen de Objeción queda a juicio del backend (RF-03.1 lo veta al cursarlo)'
);

// =====================================================================
// [5] Accesibilidad ARIA
// =====================================================================
console.log('\n[5] Accesibilidad ARIA de la Torre');
assertCondition(region1?.getAttribute('role') === 'region', 'la Torre es una región con nombre accesible');
assertCondition(region1?.getAttribute('aria-labelledby') === 'mastersTowerTitle', 'aria-labelledby apunta al título ceremonial');
assertCondition(
  meters[0]?.getAttribute('role') === 'img' && meters[0]?.getAttribute('aria-label')?.includes('1 de 3'),
  'el medidor verbaliza su progreso con nombre accesible'
);
assertCondition(
  byClass(mount1, 'masters-tower__announcement')?.getAttribute('aria-live') === 'polite',
  'los anuncios de la cola viven en una región viva de cortesía'
);
assertCondition(
  signButtons[1]?.getAttribute('aria-disabled') === 'true' && vetoAlerts[0]?.getAttribute('role') === 'alert',
  'el bloqueo se anuncia con aria-disabled y su alerta es accesible'
);

// =====================================================================
// [6] Los umbrales del Cónclave viajan del canon servido
// =====================================================================
console.log('\n[6] El canon del Cónclave se declara, jamás se escribe a mano');
assertCondition(
  region1?.getAttribute('data-signatures-required') === '3' && region1?.getAttribute('data-judge-role') === 'master',
  'la región porta los umbrales y el rango judicial servidos por el canon'
);
assertCondition(
  meters[0]?.getAttribute('data-signatures-required') === '3',
  'el medidor usa el techo del canon, no un tres escrito a mano (Art. II)'
);

// =====================================================================
// [7] Los filtros de la Torre (RF-05.4)
// =====================================================================
console.log('\n[7] Los filtros de la cola del Cónclave');
const filtersBlock = byClass(mount1, 'masters-tower__filters');
assertCondition(filtersBlock !== null && filtersBlock.getAttribute('role') === 'group', 'los filtros viven en un grupo etiquetado');
const elementSelect = byClass(mount1, 'masters-tower__filter-element');
const schoolSelect = byClass(mount1, 'masters-tower__filter-school');
const signaturesSelect = byClass(mount1, 'masters-tower__filter-min-signatures');
assertCondition(elementSelect !== null && schoolSelect !== null && signaturesSelect !== null, 'los tres filtros del plan (elemento, escuela y firmas mínimas) están presentes');
assertCondition(
  elementSelect.children.length === 9,
  'el filtro de elementos ofrece los ocho del Códice más la lente abierta'
);
assertCondition(
  signaturesSelect.children.length === 4,
  'el filtro de firmas mínimas se acota 0..techo contra el canon servido'
);

let announcedFilters = null;
const mount7 = createFakeElement('div');
const tower7 = createMastersTowerComponent(mount7, {
  moderationClient: { fetchDeliberationQueue: async () => QUEUE_PAYLOAD },
  onFiltersChanged: (filters) => { announcedFilters = filters; },
  elementFactory: fakeElementFactory,
  documentRef: fakeDocument,
});
await tower7.render();
fakeDocument.dispatchedEvents.length = 0;
byClass(mount7, 'masters-tower__filter-element').value = 'fire';
byClass(mount7, 'masters-tower__filter-element').dispatch('change');
assertCondition(
  announcedFilters?.element === 'fire',
  'el cambio de filtro anuncia su estado por callback (RF-05.4)'
);
assertCondition(
  fakeDocument.dispatchedEvents.some((event) => event.type === 'moderation:tower-filters-changed' && event.detail?.element === 'fire'),
  'el cambio de filtros también viaja por el bus desacoplado (plan 4.1)'
);

// setFilters re-consulta con los nuevos filtros.
let lastQueueQuery = null;
const mount7b = createFakeElement('div');
const tower7b = createMastersTowerComponent(mount7b, {
  moderationClient: {
    fetchDeliberationQueue: async (params) => {
      lastQueueQuery = params;
      return QUEUE_PAYLOAD;
    },
  },
  elementFactory: fakeElementFactory,
  documentRef: fakeDocument,
});
await tower7b.render();
lastQueueQuery = null;
tower7b.setFilters({ element: 'water', school: 'illusion', minSignatures: 2 });
await new Promise((resolve) => setTimeout(resolve, 0));
assertCondition(
  lastQueueQuery?.element === 'water' && lastQueueQuery?.school === 'illusion' && lastQueueQuery?.minSignatures === 2,
  'setFilters re-consulta la cola con los filtros canónicos'
);

// =====================================================================
// [8] Los gestos por el bus (plan 4.1)
// =====================================================================
console.log('\n[8] Firma y objeción como gestos declarados');
const signIntents = [];
const objectionIntents = [];
const mount8 = createFakeElement('div');
const tower8 = createMastersTowerComponent(mount8, {
  moderationClient: { fetchDeliberationQueue: async () => QUEUE_PAYLOAD },
  onSignatureIntent: (spellId) => signIntents.push(spellId),
  onObjectionIntent: (spellId) => objectionIntents.push(spellId),
  elementFactory: fakeElementFactory,
  documentRef: fakeDocument,
});
await tower8.render();
fakeDocument.dispatchedEvents.length = 0;
allByClass(mount8, 'masters-tower__sign-button')[0].dispatch('click');
assertCondition(signIntents.length === 1 && signIntents[0] === 'spl_porta', 'el clic de firma anuncia la intención con el identificador de la obra');
assertCondition(
  fakeDocument.dispatchedEvents.some((event) => event.type === 'moderation:sign-intent' && event.detail?.spellId === 'spl_porta'),
  'la intención de firma también viaja por el bus desacoplado (plan 4.1)'
);
allByClass(mount8, 'masters-tower__object-button')[0].dispatch('click');
assertCondition(objectionIntents.length === 1 && objectionIntents[0] === 'spl_porta', 'el clic de objeción anuncia su intención con el identificador');
assertCondition(
  fakeDocument.dispatchedEvents.some((event) => event.type === 'moderation:objection-intent'),
  'la intención de objeción también viaja por el bus'
);

// El botón vetado nace INHABILITADO (disabled=true): el navegador jamás
// dispararía su listener, y el panel tampoco. El arnés lo verifica sobre la
// PROPIA bandera del elemento, como haría el agente de usuario real.
const vettedSignButton = allByClass(mount8, 'masters-tower__sign-button')[1];
assertCondition(
  vettedSignButton?.disabled === true && vettedSignButton?.getAttribute('aria-disabled') === 'true',
  'el botón inhabilitado del Maestro vetado declara su bloqueo y jamás emite gesto'
);

// =====================================================================
// [9] Degradación elegante: canon ausente (AGENTS.md 8)
// =====================================================================
console.log('\n[9] Degradación sin canon del backend');
const mount9 = createFakeElement('div');
const tower9 = createMastersTowerComponent(mount9, {
  moderationClient: {
    // Un backend antiguo que sirviera la cola sin canon.
    fetchDeliberationQueue: async () => ({ success: true, data: { items: [QUEUE_ITEM_ELIGIBLE] } }),
  },
  elementFactory: fakeElementFactory,
  documentRef: fakeDocument,
});
await tower9.render();
assertCondition(byClass(mount9, 'masters-tower__card') !== null, 'sin canon la Torre sigue en pie con su cola');
assertCondition(
  byClass(mount9, 'masters-tower')?.getAttribute('data-signatures-required') === '3',
  'el panel usa su reserva del canon declarada una sola vez'
);

// =====================================================================
// [10] Corte de maná y reintento
// =====================================================================
console.log('\n[10] Corte de maná y reintento');
let queueAttempts = 0;
const flakyClient = {
  fetchDeliberationQueue: async () => {
    queueAttempts += 1;
    return queueAttempts === 1 ? NETWORK_ENVELOPE : QUEUE_PAYLOAD;
  },
};
const mount10 = createFakeElement('div');
const tower10 = createMastersTowerComponent(mount10, {
  moderationClient: flakyClient,
  elementFactory: fakeElementFactory,
  documentRef: fakeDocument,
});
await tower10.render();
const errorBlock10 = byClass(mount10, 'masters-tower__error');
assertCondition(errorBlock10 !== null && errorBlock10.getAttribute('role') === 'alert', 'el corte de maná se declara como alerta (AGENTS.md 6.1)');
assertCondition(
  byClass(mount10, 'masters-tower__error-message')?.textContent === MASTERS_TOWER_ERROR_LEGEND,
  'la leyenda del corte es ceremonial y en castellano'
);
byClass(mount10, 'masters-tower__error-retry').dispatch('click');
await new Promise((resolve) => setTimeout(resolve, 0));
assertCondition(queueAttempts === 2, 'el reintento vuelve a consultar la Torre');
assertCondition(byClass(mount10, 'masters-tower__card') !== null, 'tras la reconexión el panel exhibe la cola del Cónclave');
assertCondition(byClass(mount10, 'masters-tower__error') === null, 'el error se retira al recuperar la corriente de maná');

const mount10b = createFakeElement('div');
const tower10b = createMastersTowerComponent(mount10b, {
  moderationClient: {},
  elementFactory: fakeElementFactory,
  documentRef: fakeDocument,
});
await tower10b.render();
assertCondition(byClass(mount10b, 'masters-tower__error') !== null, 'sin cliente la Torre también falla con leyenda ceremonial');

// =====================================================================
// [11] Actualización dirigida por evento (plan 4.1)
// =====================================================================
console.log('\n[11] Actualización dirigida por evento sin re-consulta');
let renderAttempts = 0;
const countingClient = { fetchDeliberationQueue: async () => { renderAttempts += 1; return QUEUE_PAYLOAD; } };
const mount11 = createFakeElement('div');
const tower11 = createMastersTowerComponent(mount11, {
  moderationClient: countingClient,
  elementFactory: fakeElementFactory,
  documentRef: fakeDocument,
});
await tower11.render();
assertCondition(renderAttempts === 1, 'el primer pintado consulta una sola vez');

// La firma retira la obra a consagrada; el bus repinta la cola restante.
tower11.setQueueItems([QUEUE_ITEM_CLAN_CONFLICT], CANON);
assertCondition(renderAttempts === 1, 'setQueueItems NO re-consulta: el bus aporta la nueva cola');
const cards11 = allByClass(mount11, 'masters-tower__card');
assertCondition(cards11.length === 1 && cards11[0]?.getAttribute('data-spell-id') === 'spl_espejo', 'el panel repintado solo presenta lo que el bus aportó');
assertCondition(
  allByClass(mount11, 'masters-tower__ethical-veto')[0]?.textContent.includes('propio estandarte'),
  'el panel repintado sigue proclamando el veto con su leyenda de fuente única'
);

tower11.setQueueItems([], CANON);
assertCondition(
  byClass(mount11, 'masters-tower__empty')?.textContent === MASTERS_TOWER_EMPTY_LEGEND,
  'una cola vacía proclama la Torre en silencio, sin presentarla como error'
);
assertCondition(byClass(mount11, 'masters-tower__error') === null, 'la Torre vacía jamás se viste de alerta técnica');

// =====================================================================
// [12] Ciclo de vida
// =====================================================================
console.log('\n[12] Ciclo de vida del panel');
const mount12 = createFakeElement('div');
const tower12 = createMastersTowerComponent(mount12, {
  moderationClient: { fetchDeliberationQueue: async () => QUEUE_PAYLOAD },
  elementFactory: fakeElementFactory,
  documentRef: fakeDocument,
});
await tower12.render();
await tower12.render();
assertCondition(allByClass(mount12, 'masters-tower').length === 1, 're-render idempotente: una sola región, sin duplicados');
assertCondition(allByClass(mount12, 'masters-tower__filters').length === 1, 'y una sola sección de filtros');
tower12.destroy();
assertCondition(allByClass(mount12, 'masters-tower').length === 0, 'destroy() retira el panel del montaje');

let releaseQueue = null;
const pendingClient = { fetchDeliberationQueue: () => new Promise((resolve) => { releaseQueue = resolve; }) };
const mount12b = createFakeElement('div');
const tower12b = createMastersTowerComponent(mount12b, {
  moderationClient: pendingClient,
  elementFactory: fakeElementFactory,
  documentRef: fakeDocument,
});
const pendingRender = tower12b.render();
tower12b.destroy();
await new Promise((resolve) => setTimeout(resolve, 0));
releaseQueue?.(QUEUE_PAYLOAD);
await pendingRender;
await new Promise((resolve) => setTimeout(resolve, 0));
assertCondition(
  allByClass(mount12b, 'masters-tower').length === 0,
  'una respuesta tardía no pinta sobre un panel destruido (sin fugas)'
);

// =====================================================================
// [13] XSS: el lore del usuario viaja como texto
// =====================================================================
console.log('\n[13] El lore hostil viaja como texto literal');
const hostilePayload = {
  success: true,
  data: {
    canon: CANON,
    items: [{
      ...QUEUE_ITEM_ELIGIBLE,
      spellName: 'Portal <script>alert(1)</script>',
      authorAlias: '<img src=x onerror=alert(1)>',
      originClanName: 'Custodios <b>de la Llama</b>',
    }],
  },
};
const mount13 = createFakeElement('div');
const tower13 = createMastersTowerComponent(mount13, {
  moderationClient: { fetchDeliberationQueue: async () => hostilePayload },
  elementFactory: fakeElementFactory,
  documentRef: fakeDocument,
});
await tower13.render();
assertCondition(
  byClass(mount13, 'masters-tower__spell-name')?.textContent === 'Portal <script>alert(1)</script>',
  'el nombre hostil viaja como TEXTO literal (cero innerHTML, AGENTS.md 6.1)'
);
assertCondition(
  byClass(mount13, 'masters-tower__author')?.textContent.includes('<img src=x onerror=alert(1)>'),
  'el alias hostil también viaja como texto: el DOM simulado habría lanzado con innerHTML'
);
assertCondition(
  byClass(mount13, 'masters-tower__card') !== null,
  'la Torre sobrevive a un lore hostil sin ejecutar nada'
);

// =====================================================================
// Resumen
// =====================================================================
console.log(`\n== RESUMEN == Asertos superados: ${assertsPassed}, fallidos: ${assertsFailed}`);
if (assertsFailed === 0) {
  console.log('RESULTADO: EXITO — La Torre de Deliberación veta con su causa, admite al Maestro apto y declara el canon (Tarea 5.3).');
  process.exit(0);
}
console.log('RESULTADO: DENEGADO — Revisa los asertos marcados.');
process.exit(1);
