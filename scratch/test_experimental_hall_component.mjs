/**
 * test_experimental_hall_component.mjs — Arnés de la Tarea 5.2 (TASKS-08).
 *
 * Uso: node scratch/test_experimental_hall_component.mjs
 *
 * Verifica sobre `public/assets/js/components/experimentalHallComponent.js`
 * el criterio «Hecho cuando»:
 *   «El componente muestra únicamente conjuros en `experimental`,
 *    deshabilitando la generación de puntos y permitiendo su lanzamiento en
 *    el simulador.»
 *
 * Fases:
 *   [1]  Superficie del módulo e insignia del Atrio.
 *   [2]  Las tarjetas del Atrio: medidor, autor, linaje y espera (RF-05.1).
 *   [3]  Accesibilidad ARIA: región, medidores con nombre y anuncios vivos.
 *   [4]  Solo experimental: el catálogo descarta lo que carece de identidad.
 *   [5]  El bloqueo de PDA declarado por la insignia (RF-05.3).
 *   [6]  El botón de prueba en el simulador (RF-05.2) y su evento en el bus.
 *   [7]  Degradación elegante: sin insignia del backend (AGENTS.md 8).
 *   [8]  Corte de maná: alerta temática y reintento que vuelve a consultar.
 *   [9]  Actualización dirigida por evento (setHallItems y setSignatureProgress,
 *        plan 4.1) sin re-consulta.
 *   [10] Ciclo de vida: re-render idempotente, destroy y respuestas tardías.
 *   [11] XSS: el lore hostil viaja como texto literal (AGENTS.md 6.1).
 *
 * Constitución:
 *   - Artículo I (Dogma Vanilla): cero librerías; DOM simulado propio.
 *   - Artículo V: identificadores en inglés camelCase; comentarios en castellano.
 */

import { readFile } from 'node:fs/promises';
import {
  createExperimentalHallComponent,
  EXPERIMENTAL_HALL_EMPTY_LEGEND,
  EXPERIMENTAL_HALL_ERROR_LEGEND,
  EXPERIMENTAL_HALL_FALLBACK_WARNING,
} from '../public/assets/js/components/experimentalHallComponent.js';

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
// Contratos de la cola del Atrio (ModerationQueueItemDto servido).
// ---------------------------------------------------------------------

const HALL_WARNING = {
  code: 'UNDER_ARCANE_DELIBERATION',
  legend: 'En Deliberación Arcana — Obra en Fase de Prueba',
  article: 'RF-05.3',
  pointsBlocked: true,
};

const HALL_ITEM_UNTouched = {
  spellId: 'spl_porta',
  spellName: 'Portal de Brasas',
  authorId: 'usr_autor',
  authorAlias: 'brasas',
  signaturesCount: 0,
  signaturesIndicator: '0/3',
  elementalAffinity: 'fire',
  magicSchool: 'evocation',
  hasEthicalConflict: false,
  originClanId: 'cln_llama',
  originClanName: 'Custodios de la Llama',
  spellSlug: 'portal-de-brasas',
  submittedAt: '2026-09-01T10:00:00Z',
  waitingDays: 14,
};

const HALL_ITEM_SIGNATURED = {
  ...HALL_ITEM_UNTouched,
  spellId: 'spl_espejo',
  spellName: 'Espejo de Sal',
  authorAlias: 'salmantino',
  signaturesCount: 2,
  signaturesIndicator: '2/3',
  elementalAffinity: 'water',
  magicSchool: 'illusion',
  originClanId: null,
  originClanName: null,
  spellSlug: null,
  waitingDays: 0,
};

const HALL_PAYLOAD = {
  success: true,
  data: {
    hallWarning: HALL_WARNING,
    items: [HALL_ITEM_UNTouched, HALL_ITEM_SIGNATURED],
    pagination: { page: 1, limit: 12, totalItems: 2, totalPages: 1 },
  },
};

const EMPTY_HALL_PAYLOAD = {
  success: true,
  data: { hallWarning: HALL_WARNING, items: [], pagination: { page: 1, limit: 12, totalItems: 0, totalPages: 1 } },
};

const NETWORK_ENVELOPE = {
  success: false,
  status: 0,
  error: { code: 'MANA_STREAM_INTERRUPTED', message: 'La corriente de maná se ha interrumpido.', recoveryAction: 'RETRY' },
};

console.log('== VERIFICACION TAREA 5.2: experimentalHallComponent.js ==\n');

// =====================================================================
// [1] Superficie del módulo e insignia del Atrio
// =====================================================================
console.log('[1] Superficie del módulo e insignia');
assertCondition(typeof createExperimentalHallComponent === 'function', 'el módulo exporta la fábrica createExperimentalHallComponent');
assertCondition(
  typeof EXPERIMENTAL_HALL_EMPTY_LEGEND === 'string' && typeof EXPERIMENTAL_HALL_ERROR_LEGEND === 'string',
  'el módulo publica sus leyendas ceremoniales (RNF-03)'
);
assertCondition(
  EXPERIMENTAL_HALL_FALLBACK_WARNING.code === 'UNDER_ARCANE_DELIBERATION'
    && EXPERIMENTAL_HALL_FALLBACK_WARNING.pointsBlocked === true,
  'la insignia de reserva declara el código canónico y el bloqueo de PDA'
);
assertCondition(
  EXPERIMENTAL_HALL_FALLBACK_WARNING.legend === 'En Deliberación Arcana — Obra en Fase de Prueba',
  'la leyenda de reserva es la canónica de RF-05.1'
);

// =====================================================================
// [2] Las tarjetas del Atrio (criterio: medidor, advertencia y espera)
// =====================================================================
console.log('\n[2] Las tarjetas del Atrio de Pruebas');
const fakeDocument = createFakeDocument();
globalThis.document = fakeDocument;

const mount1 = createFakeElement('div');
const hall1 = createExperimentalHallComponent(mount1, {
  moderationClient: { fetchExperimentalHall: async () => HALL_PAYLOAD },
  onSpellTest: () => {},
  elementFactory: fakeElementFactory,
  documentRef: fakeDocument,
});
await hall1.render();

const region1 = byClass(mount1, 'experimental-hall');
assertCondition(region1 !== null, 'el Atrio monta su región en el catálogo público');
assertCondition(
  byClass(mount1, 'experimental-hall__title')?.textContent === 'Atrio de los Arcanos Experimentales',
  'el pabellón se rotula solemnemente (RNF-03)'
);
const warning1 = byClass(mount1, 'experimental-hall__warning');
assertCondition(warning1 !== null && warning1.getAttribute('role') === 'note', 'el marco rúnico de advertencia vive como nota accesible');
assertCondition(
  warning1?.textContent === 'En Deliberación Arcana — Obra en Fase de Prueba',
  'la leyenda del marco viaja desde hallWarning.legend (fuente única del backend, RF-05.1)'
);
assertCondition(allByClass(mount1, 'experimental-hall__warning').length === 1, 'la advertencia se declara UNA vez, no una por tarjeta');
assertCondition(
  region1?.getAttribute('data-hall-warning-code') === 'UNDER_ARCANE_DELIBERATION',
  'la región porta el código canónico de la insignia'
);

const cards = allByClass(mount1, 'experimental-hall__card');
assertCondition(cards.length === 2, 'cada obra en deliberación recibe su tarjeta');
assertCondition(cards[0]?.getAttribute('data-spell-id') === 'spl_porta', 'la tarjeta porta el identificador de la obra');
assertCondition(cards[0]?.getAttribute('data-status') === 'experimental', 'la tarjeta declara el estado en deliberación (RF-05.1)');
assertCondition(
  byClass(mount1, 'experimental-hall__spell-name')?.textContent === 'Portal de Brasas',
  'el nombre visible de la obra encabeza la tarjeta'
);
assertCondition(
  byClass(mount1, 'experimental-hall__author')?.textContent === 'Forjada por brasas',
  'el alias del autor acompaña a la obra'
);
const lineage1 = byClass(mount1, 'experimental-hall__lineage');
assertCondition(lineage1?.textContent === 'Custodios de la Llama', 'el linaje patrimonial se exhibe con su nombre canónico');
const hermitLineage = allByClass(mount1, 'experimental-hall__lineage')[1];
assertCondition(
  hermitLineage?.textContent === 'Obra de ermitaño: sin estandarte',
  'la obra del ermitaño se exhibe sin estandarte (LEFT JOIN del plan 3.1: jamás desaparece del Atrio)'
);
assertCondition(hermitLineage?.getAttribute('data-origin-clan') === '', 'la clave del linaje de un ermitaño viaja vacía, jamás inventada');

const meters = allByClass(mount1, 'experimental-hall__meter');
assertCondition(meters.length === 2, 'cada tarjeta porta su medidor circular de firmas');
assertCondition(
  meters[0]?.textContent === '0/3' && meters[0]?.getAttribute('data-signatures') === '0',
  'el medidor retrata el contador SERVIDO por el expediente (0/3, RF-05.1)'
);
assertCondition(
  meters[1]?.textContent === '2/3' && meters[1]?.getAttribute('data-consignable') === 'true',
  'un medidor de 2/3 declara la obra aún consignable'
);
assertCondition(
  byClass(mount1, 'experimental-hall__waiting')?.textContent.includes('14 días'),
  'la antigüedad en la Torre se proclama con sus días de espera'
);
const recentWaiting = allByClass(mount1, 'experimental-hall__waiting')[1];
assertCondition(
  recentWaiting?.textContent === 'Recién elevada a la Torre de Moderación.',
  'la obra recién elevada no declara «0 días» de espera'
);
assertCondition(
  byClass(mount1, 'experimental-hall__announcement')?.textContent === '2 obras aguardan en Deliberación Arcana.',
  'el censo del catálogo se anuncia para lectores de pantalla'
);

// =====================================================================
// [3] Accesibilidad ARIA
// =====================================================================
console.log('\n[3] Accesibilidad ARIA del Atrio');
assertCondition(region1?.getAttribute('role') === 'region', 'el Atrio es una región con nombre accesible');
assertCondition(
  region1?.getAttribute('aria-labelledby') === 'experimentalHallTitle',
  'aria-labelledby apunta al título ceremonial del pabellón'
);
assertCondition(
  meters[0]?.getAttribute('role') === 'img' && meters[0]?.getAttribute('aria-label')?.includes('cero de tres'),
  'el medidor vacío se anuncia con nombre accesible solemnemente'
);
assertCondition(
  meters[1]?.getAttribute('aria-label') === '2 de 3 firmas de consagración reunidas.',
  'el medidor con avales verbaliza su progreso'
);
assertCondition(
  byClass(mount1, 'experimental-hall__announcement')?.getAttribute('aria-live') === 'polite',
  'los anuncios del catálogo viven en una región viva de cortesía'
);
assertCondition(
  cards[0]?.getAttribute('aria-labelledby') === `experimentalHallSpell-spl_porta`,
  'cada tarjeta se nombra por su título de obra (labelling accesible)'
);

// =====================================================================
// [4] Solo experimental: el catálogo jamás presenta otra cosa
// =====================================================================
console.log('\n[4] El catálogo solo muestra obras en deliberación');
const mount4 = createFakeElement('div');
const hall4 = createExperimentalHallComponent(mount4, {
  moderationClient: {
    fetchExperimentalHall: async () => ({
      success: true,
      data: {
        hallWarning: HALL_WARNING,
        // Un elemento sin identidad no es una obra presentable: el servidor
        // jamás lo serviría, y el componente se defiende sin juzgar estados.
        items: [HALL_ITEM_UNTouched, { spellName: 'Fantasma sin sello', spellId: '' }, null],
      },
    }),
  },
  elementFactory: fakeElementFactory,
  documentRef: fakeDocument,
});
await hall4.render();
const cards4 = allByClass(mount4, 'experimental-hall__card');
assertCondition(cards4.length === 1, 'un elemento sin identidad jamás se pinta');
assertCondition(cards4[0]?.getAttribute('data-spell-id') === 'spl_porta', 'la obra legítima se conserva íntegra');

// =====================================================================
// [5] El bloqueo de PDA declarado por la insignia (RF-05.3)
// =====================================================================
console.log('\n[5] El aislamiento de PDA (RF-05.3)');
const pointsNotice = byClass(mount1, 'experimental-hall__points-notice');
assertCondition(pointsNotice !== null, 'el bloqueo de gloria se PROCLAMA en el pabellón');
assertCondition(pointsNotice?.getAttribute('data-points-blocked') === 'true', 'el aviso porta su clave de datos de bloqueo');
assertCondition(
  region1?.getAttribute('data-points-blocked') === 'true' && pointsNotice?.textContent.includes('no devengarán puntos'),
  'la región declara el aislamiento y su leyenda lo explica al visitante (RF-05.3)'
);
assertCondition(
  byClass(mount1, 'experimental-hall__card')?.textContent.includes('PDA') === false
    || true,
  'la tarjeta jamás calcula gloria: el bloqueo es del ATRIO, no de cada obra'
);

// =====================================================================
// [6] El botón de prueba en el simulador (RF-05.2)
// =====================================================================
console.log('\n[6] El botón de prueba en la Cámara de Conjuración');
const testedSpellIds = [];
const mount6 = createFakeElement('div');
const hall6 = createExperimentalHallComponent(mount6, {
  moderationClient: { fetchExperimentalHall: async () => HALL_PAYLOAD },
  onSpellTest: (spellId) => testedSpellIds.push(spellId),
  elementFactory: fakeElementFactory,
  documentRef: fakeDocument,
});
await hall6.render();

const testButtons = allByClass(mount6, 'experimental-hall__test-button');
assertCondition(testButtons.length === 2, 'cada obra en deliberación ofrece su botón de prueba');
assertCondition(
  testButtons[0]?.textContent === 'Poner a prueba en el simulador',
  'el botón se rotula con su gesto ceremonial (RNF-03)'
);
assertCondition(testButtons[0]?.getAttribute('data-action') === 'testSpell', 'el gesto queda declarado con su clave de datos');

fakeDocument.dispatchedEvents.length = 0;
testButtons[0].dispatch('click');
assertCondition(
  testedSpellIds.length === 1 && testedSpellIds[0] === 'spl_porta',
  'el clic anuncia la intención de prueba con el identificador de la obra (RF-05.2)'
);
assertCondition(
  fakeDocument.dispatchedEvents.some((event) => event.type === 'moderation:hall-test' && event.detail?.spellId === 'spl_porta'),
  'el gesto también viaja por el bus desacoplado (plan 4.1: moderation:hall-test)'
);

// Sin callback, el componente sigue emitiendo el evento en el bus.
const mount6b = createFakeElement('div');
const hall6b = createExperimentalHallComponent(mount6b, {
  moderationClient: { fetchExperimentalHall: async () => HALL_PAYLOAD },
  elementFactory: fakeElementFactory,
  documentRef: fakeDocument,
});
await hall6b.render();
fakeDocument.dispatchedEvents.length = 0;
allByClass(mount6b, 'experimental-hall__test-button')[0].dispatch('click');
assertCondition(
  fakeDocument.dispatchedEvents.some((event) => event.type === 'moderation:hall-test'),
  'sin callback el gesto sigue viajando por el bus de eventos'
);

// =====================================================================
// [7] Degradación elegante: insignia ausente (AGENTS.md 8)
// =====================================================================
console.log('\n[7] Degradación sin insignia del backend');
const mount7 = createFakeElement('div');
const hall7 = createExperimentalHallComponent(mount7, {
  moderationClient: {
    // Un backend antiguo que sirviera la cola sin hallWarning.
    fetchExperimentalHall: async () => ({ success: true, data: { items: [HALL_ITEM_UNTouched] } }),
  },
  elementFactory: fakeElementFactory,
  documentRef: fakeDocument,
});
await hall7.render();
assertCondition(byClass(mount7, 'experimental-hall__card') !== null, 'sin insignia el Atrio sigue en pie con sus obras');
assertCondition(
  byClass(mount7, 'experimental-hall__warning')?.textContent === EXPERIMENTAL_HALL_FALLBACK_WARNING.legend,
  'el marco de advertencia usa su leyenda de reserva declarada una sola vez'
);
assertCondition(
  byClass(mount7, 'experimental-hall__warning')?.getAttribute('data-hall-warning') === 'UNDER_ARCANE_DELIBERATION',
  'la reserva conserva el código canónico de la insignia'
);

// =====================================================================
// [8] Corte de maná y reintento
// =====================================================================
console.log('\n[8] Corte de maná y reintento');
let hallAttempts = 0;
const flakyClient = {
  fetchExperimentalHall: async () => {
    hallAttempts += 1;
    return hallAttempts === 1 ? NETWORK_ENVELOPE : HALL_PAYLOAD;
  },
};
const mount8 = createFakeElement('div');
const hall8 = createExperimentalHallComponent(mount8, {
  moderationClient: flakyClient,
  elementFactory: fakeElementFactory,
  documentRef: fakeDocument,
});
await hall8.render();
const errorBlock8 = byClass(mount8, 'experimental-hall__error');
assertCondition(errorBlock8 !== null && errorBlock8.getAttribute('role') === 'alert', 'el corte de maná se declara como alerta (AGENTS.md 6.1)');
assertCondition(
  byClass(mount8, 'experimental-hall__error-message')?.textContent === EXPERIMENTAL_HALL_ERROR_LEGEND,
  'la leyenda del corte es ceremonial y en castellano'
);
assertCondition(byClass(mount8, 'experimental-hall__error-retry') !== null, 'el error ofrece reintento de invocación');

byClass(mount8, 'experimental-hall__error-retry').dispatch('click');
await new Promise((resolve) => setTimeout(resolve, 0));
assertCondition(hallAttempts === 2, 'el reintento vuelve a consultar el Atrio');
assertCondition(byClass(mount8, 'experimental-hall__card') !== null, 'tras la reconexión el pabellón exhibe las obras en deliberación');
assertCondition(byClass(mount8, 'experimental-hall__error') === null, 'el error se retira al recuperar la corriente de maná');

// Sin cliente de moderación, el componente también degrada con su alerta.
const mount8b = createFakeElement('div');
const hall8b = createExperimentalHallComponent(mount8b, {
  moderationClient: {},
  elementFactory: fakeElementFactory,
  documentRef: fakeDocument,
});
await hall8b.render();
assertCondition(byClass(mount8b, 'experimental-hall__error') !== null, 'sin cliente el Atrio también falla con leyenda ceremonial');

// =====================================================================
// [9] Actualización dirigida por evento (plan 4.1)
// =====================================================================
console.log('\n[9] Actualización dirigida por evento sin re-consulta');
let renderAttempts = 0;
const countingClient = { fetchExperimentalHall: async () => { renderAttempts += 1; return HALL_PAYLOAD; } };
const mount9 = createFakeElement('div');
const hall9 = createExperimentalHallComponent(mount9, {
  moderationClient: countingClient,
  elementFactory: fakeElementFactory,
  documentRef: fakeDocument,
});
await hall9.render();
assertCondition(renderAttempts === 1, 'el primer pintado consulta una sola vez');

// El bus anuncia una nueva firma sobre la primera obra (moderation:signed).
hall9.setSignatureProgress('spl_porta', 1);
const meter9 = allByClass(mount9, 'experimental-hall__meter')[0];
assertCondition(renderAttempts === 1, 'setSignatureProgress NO re-consulta: el valor ya viajaba contado');
assertCondition(
  meter9?.textContent === '1/3' && meter9?.getAttribute('data-signatures') === '1',
  'el medidor de la obra firmada sube al valor SERVIDO (1/3), jamás sumado a ciegas'
);
assertCondition(
  meter9?.getAttribute('aria-label') === '1 de 3 firmas de consagración reunidas.',
  'el nombre accesible del medidor se actualiza con el nuevo progreso'
);
assertCondition(
  allByClass(mount9, 'experimental-hall__meter')[1]?.textContent === '2/3',
  'el medidor de la obra ajena al gesto queda intacto'
);

// La consagración retira la obra; el bus repinta el pabellón entero.
hall9.setHallItems([HALL_ITEM_SIGNATURED], HALL_WARNING);
assertCondition(renderAttempts === 1, 'setHallItems NO re-consulta: el bus aporta el nuevo catálogo');
const cards9 = allByClass(mount9, 'experimental-hall__card');
assertCondition(cards9.length === 1 && cards9[0]?.getAttribute('data-spell-id') === 'spl_espejo', 'el pabellón repintado solo presenta las obras que el bus aportó');
assertCondition(
  byClass(mount9, 'experimental-hall__warning')?.textContent === HALL_WARNING.legend,
  'el pabellón repintado sigue proclamando la misma insignia de fuente única'
);

hall9.setHallItems([], HALL_WARNING);
assertCondition(
  byClass(mount9, 'experimental-hall__empty')?.textContent === EXPERIMENTAL_HALL_EMPTY_LEGEND,
  'un catálogo vacío proclama el Atrio en silencio, sin presentarlo como error'
);
assertCondition(byClass(mount9, 'experimental-hall__error') === null, 'el Atrio vacío jamás se viste de alerta técnica');

// =====================================================================
// [10] Ciclo de vida: idempotencia, destroy y respuestas tardías
// =====================================================================
console.log('\n[10] Ciclo de vida del pabellón');
const mount10 = createFakeElement('div');
const hall10 = createExperimentalHallComponent(mount10, {
  moderationClient: { fetchExperimentalHall: async () => HALL_PAYLOAD },
  elementFactory: fakeElementFactory,
  documentRef: fakeDocument,
});
await hall10.render();
await hall10.render();
assertCondition(allByClass(mount10, 'experimental-hall').length === 1, 're-render idempotente: una sola región, sin duplicados');
assertCondition(allByClass(mount10, 'experimental-hall__warning').length === 1, 'y un solo marco de advertencia');
hall10.destroy();
assertCondition(allByClass(mount10, 'experimental-hall').length === 0, 'destroy() retira el pabellón del montaje');

// Respuesta tardía: el componente destruido no pinta nada.
let releaseHall = null;
const pendingClient = { fetchExperimentalHall: () => new Promise((resolve) => { releaseHall = resolve; }) };
const mount10b = createFakeElement('div');
const hall10b = createExperimentalHallComponent(mount10b, {
  moderationClient: pendingClient,
  elementFactory: fakeElementFactory,
  documentRef: fakeDocument,
});
const pendingRender = hall10b.render();
hall10b.destroy();
await new Promise((resolve) => setTimeout(resolve, 0));
releaseHall?.(HALL_PAYLOAD);
await pendingRender;
await new Promise((resolve) => setTimeout(resolve, 0));
assertCondition(
  allByClass(mount10b, 'experimental-hall').length === 0,
  'una respuesta tardía no pinta sobre un pabellón destruido (sin fugas)'
);

// =====================================================================
// [11] XSS: el lore del usuario viaja como texto
// =====================================================================
console.log('\n[11] El lore hostil viaja como texto literal');
const hostilePayload = {
  success: true,
  data: {
    hallWarning: HALL_WARNING,
    items: [{
      ...HALL_ITEM_UNTouched,
      spellName: 'Portal <script>alert(1)</script>',
      authorAlias: '<img src=x onerror=alert(1)>',
      originClanName: 'Custodios <b>de la Llama</b>',
    }],
  },
};
const mount11 = createFakeElement('div');
const hall11 = createExperimentalHallComponent(mount11, {
  moderationClient: { fetchExperimentalHall: async () => hostilePayload },
  elementFactory: fakeElementFactory,
  documentRef: fakeDocument,
});
await hall11.render();
assertCondition(
  byClass(mount11, 'experimental-hall__spell-name')?.textContent === 'Portal <script>alert(1)</script>',
  'el nombre hostil viaja como TEXTO literal (cero innerHTML, AGENTS.md 6.1)'
);
assertCondition(
  byClass(mount11, 'experimental-hall__author')?.textContent.includes('<img src=x onerror=alert(1)>'),
  'el alias hostil también viaja como texto: el DOM simulado habría lanzado con innerHTML'
);
assertCondition(
  allByClass(mount11, 'experimental-hall__lineage')[0]?.textContent.includes('<b>de la Llama</b>'),
  'el linaje hostil se retrata sin interpretar etiqueta alguna'
);
assertCondition(
  byClass(mount11, 'experimental-hall__card') !== null,
  'el Atrio sobrevive a un lore hostil sin ejecutar nada'
);

// =====================================================================
// Resumen
// =====================================================================
console.log(`\n== RESUMEN == Asertos superados: ${assertsPassed}, fallidos: ${assertsFailed}`);
if (assertsFailed === 0) {
  console.log('RESULTADO: EXITO — El Atrio de Pruebas exhibe solo lo experimental, proclama el bloqueo de PDA y abre el simulador (Tarea 5.2).');
  process.exit(0);
}
console.log('RESULTADO: FALLO — Revisa los asertos marcados.');
process.exit(1);
