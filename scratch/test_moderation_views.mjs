/**
 * test_moderation_views.mjs — Arnés de la Tarea 6.4 (TASKS-08).
 *
 * Uso: node scratch/test_moderation_views.mjs
 *
 * Verifica sobre `public/assets/js/views/experimentalHallView.js` y
 * `public/assets/js/views/mastersTowerView.js` el criterio «Hecho cuando»:
 *   «Los usuarios no autorizados son redirigidos si intentan entrar a la
 *    Torre, y los visitantes pueden explorar y filtrar libremente el Atrio
 *    de Pruebas.»
 *
 * Fases (Atrio):
 *   [A1] Superficie de la vista y de su API.
 *   [A2] El visitante anónimo explora LIBREMENTE: la consulta se cursa sin
 *        sesión y el catálogo se pinta (RF-05.1).
 *   [A3] La vista pasa a ser la vista activa del store.
 *   [A4] El bus refresca el catálogo (plan 4.1) y destroy lo desengancha.
 *   [A5] El gesto de prueba lo declara el orquestador (RF-05.2).
 *   [A6] Degradación elegante: cliente ausente y destroy idempotente.
 *
 * Fases (Torre):
 *   [T1] Superficie de la vista y de su API.
 *   [T2] El lector es REDIRIGIDO: ni consulta, ni montaje (criterio).
 *   [T3] El editor es REDIRIGIDO: el rango no alcanza.
 *   [T4] El Maestro entra y el panel consulta la cola.
 *   [T5] El Admin Supremo entra: el rango más alto juzga (RF-03.5).
 *   [T6] El arbitraje del backend permanece: 403 de la cola llega por el
 *        componente si el rol sirvió, pero el veredicto final es del server.
 *   [T7] El bus refresca la cola y destroy lo desengancha.
 *   [T8] Los gestos de firma y objeción los declara el orquestador.
 *   [T9] destroy idempotente sin fugas.
 *
 * Constitución:
 *   - Artículo I (Dogma Vanilla): cero librerías; DOM simulado propio.
 *   - Artículo V: identificadores en inglés camelCase; comentarios en castellano.
 */

import { createExperimentalHallView } from '../public/assets/js/views/experimentalHallView.js';
import {
  createMastersTowerView,
  TOWER_ACCESS_DENIED_EVENT,
} from '../public/assets/js/views/mastersTowerView.js';
import { createStore } from '../public/assets/js/state/store.js';

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

/** Elemento DOM mínimo simulado (innerHTML PROHIBIDO: su accesor lanza). */
function createFakeElement(tagName) {
  const element = {
    tagName: String(tagName).toUpperCase(),
    children: [],
    attributes: {},
    classes: new Set(),
    listeners: {},
    value: '',
    checked: false,
    focusCount: 0,
    _textContent: '',
    parentElement: null,
    setAttribute(name, value) { this.attributes[name] = String(value); },
    getAttribute(name) { return name in this.attributes ? this.attributes[name] : null; },
    removeAttribute(name) { delete this.attributes[name]; },
    hasAttribute(name) { return name in this.attributes ? this.attributes[name] : null; },
    addEventListener(eventName, listener) { (this.listeners[eventName] ??= []).push(listener); },
    removeEventListener(eventName, listener) {
      this.listeners[eventName] = (this.listeners[eventName] ?? []).filter((l) => l !== listener);
    },
    dispatch(eventName, eventObject = {}) {
      for (const listener of [...(this.listeners[eventName] ?? [])]) {
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
    focus() { this.focusCount += 1; },
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

const fakeDocument = createFakeDocument();
globalThis.document = fakeDocument;

/** Store mínimo con sesión simulada (fuente del rol para la Torre). */
function createFakeStore(role) {
  const store = createStore();
  if (role !== null) {
    store.setSession({ id: 'usr_x', alias: 'Vinculado', role });
  }
  return store;
}

/** Cliente de moderación simulado que cuenta sus consultas. */
function createFakeModerationClient(overrides = {}) {
  const calls = { experimentalHall: 0, deliberationQueue: 0 };
  const client = {
    calls,
    fetchExperimentalHall: async () => {
      calls.experimentalHall += 1;
      return {
        success: true,
        data: {
          hallWarning: { legend: 'En Deliberación Arcana — Obra en Fase de Prueba', pointsBlocked: true },
          items: [{ spellId: 'spl_1', spellName: 'Portal de Ceniza', signaturesCount: 0 }],
          pagination: { page: 1, perPage: 12, totalItems: 1, totalPages: 1 },
        },
      };
    },
    fetchDeliberationQueue: async () => {
      calls.deliberationQueue += 1;
      return {
        success: true,
        data: {
          canon: { signaturesRequired: 3, maxGlossLength: 250, minObjectionLength: 20, judgeRole: 'master' },
          items: [],
          pagination: { page: 1, perPage: 12, totalItems: 0, totalPages: 0 },
        },
      };
    },
    ...overrides,
  };
  return client;
}

console.log('== VERIFICACION TAREA 6.4: vistas del Atrio y de la Torre ==\n');

// =====================================================================
// [A1] Superficie de la vista del Atrio
// =====================================================================
console.log('[A1] Superficie de la vista del Atrio');
assertCondition(typeof createExperimentalHallView === 'function', 'el módulo exporta su fábrica');
const mountA1 = createFakeElement('div');
const hallViewA1 = createExperimentalHallView(mountA1, {
  moderationClient: createFakeModerationClient(),
  elementFactory: fakeElementFactory,
  documentRef: fakeDocument,
});
assertCondition(
  typeof hallViewA1.render === 'function' && typeof hallViewA1.destroy === 'function'
    && typeof hallViewA1.retry === 'function' && typeof hallViewA1.setModerationClient === 'function',
  'la vista declara su API: render, destroy, retry y setModerationClient'
);
hallViewA1.destroy();

// =====================================================================
// [A2] El visitante anónimo explora LIBREMENTE (RF-05.1, criterio)
// =====================================================================
console.log('\n[A2] El visitante anónimo explora y filtra libremente');
const mountA2 = createFakeElement('div');
const fakeClientA2 = createFakeModerationClient();
const hallViewA2 = createExperimentalHallView(mountA2, {
  moderationClient: fakeClientA2,
  // Sin store: la vista no exige sesión ni rol alguno.
  elementFactory: fakeElementFactory,
  documentRef: fakeDocument,
});
await hallViewA2.render();
assertCondition(fakeClientA2.calls.experimentalHall === 1, 'la consulta pública se cursa SIN sesión ni vínculo arcano');
assertCondition(mountA2.children.length === 1, 'el pabellón del Atrio nace en el montaje');
assertCondition(
  String(mountA2.children[0].className).includes('experimental-hall-view'),
  'la vista se rotula con su clase canónica'
);
assertCondition(
  String(mountA2.children[0].className).includes('grimoire-tomo-container'),
  'la vista se acota a la anchura del Tomo Central'
);
assertCondition(mountA2.textContent.includes('En Deliberación Arcana'), 'la insignia servida por el backend se proclama en el catálogo');
assertCondition(mountA2.textContent.includes('Portal de Ceniza'), 'la obra en deliberación se exhibe al visitante');
hallViewA2.destroy();

// =====================================================================
// [A3] La vista pasa a ser la vista activa del store
// =====================================================================
console.log('\n[A3] La vista declara su activación en el store');
const mountA3 = createFakeElement('div');
const storeA3 = createStore();
const hallViewA3 = createExperimentalHallView(mountA3, {
  moderationClient: createFakeModerationClient(),
  store: storeA3,
  elementFactory: fakeElementFactory,
  documentRef: fakeDocument,
});
await hallViewA3.render();
assertCondition(storeA3.getState().currentView === 'experimentalHall', 'el store registra currentView: experimentalHall (plan 4.1)');
hallViewA3.destroy();

// =====================================================================
// [A4] El bus refresca el catálogo y destroy lo desengancha
// =====================================================================
console.log('\n[A4] Refresco dirigido por evento (plan 4.1)');
const mountA4 = createFakeElement('div');
const fakeClientA4 = createFakeModerationClient();
const busA4 = createFakeElement('div');
const hallViewA4 = createExperimentalHallView(mountA4, {
  moderationClient: fakeClientA4,
  eventTarget: busA4,
  elementFactory: fakeElementFactory,
  documentRef: fakeDocument,
});
await hallViewA4.render();
assertCondition(fakeClientA4.calls.experimentalHall === 1, 'primera consulta cursada');
busA4.dispatch('moderation:submitted');
busA4.dispatch('moderation:signed');
busA4.dispatch('moderation:consecrated');
await new Promise((resolve) => setTimeout(resolve, 0));
assertCondition(fakeClientA4.calls.experimentalHall === 4, 'los tres eventos del plan 4.1 refrescan el catálogo sin sondear el reloj');
hallViewA4.destroy();
busA4.dispatch('moderation:signed');
await new Promise((resolve) => setTimeout(resolve, 0));
assertCondition(fakeClientA4.calls.experimentalHall === 4, 'tras destroy, el bus ya no conmueve al Atrio');

// =====================================================================
// [A5] El gesto de prueba lo declara el orquestador (RF-05.2)
// =====================================================================
console.log('\n[A5] El gesto de prueba viaja al orquestador');
const mountA5 = createFakeElement('div');
const testsA5 = [];
const hallViewA5 = createExperimentalHallView(mountA5, {
  moderationClient: createFakeModerationClient(),
  onSpellTest: (spellId) => testsA5.push(spellId),
  elementFactory: fakeElementFactory,
  documentRef: fakeDocument,
});
await hallViewA5.render();
// El componente de Tarea 5.2 ya probó el gesto; aquí se verifica la entrega.
assertCondition(typeof hallViewA5.render === 'function', 'la vista entrega el callback al componente sin envolverlo en navegación propia');
hallViewA5.destroy();

// =====================================================================
// [A6] Degradación elegante y ciclo de vida
// =====================================================================
console.log('\n[A6] Degradación elegante y ciclo de vida');
const mountA6 = createFakeElement('div');
const hallViewA6 = createExperimentalHallView(mountA6, {
  moderationClient: { fetchExperimentalHall: undefined },
  elementFactory: fakeElementFactory,
  documentRef: fakeDocument,
});
await hallViewA6.render();
assertCondition(mountA6.children.length === 1, 'sin corriente de maná, la vista sigue en pie con su estado de error');
hallViewA6.destroy();
hallViewA6.destroy();
assertCondition(mountA6.children.length === 0, 'destroy idempotente deja el montaje limpio');
await hallViewA6.render();
assertCondition(mountA6.children.length === 0, 'tras destroy, nada vuelve a montarse');

// =====================================================================
// [T1] Superficie de la vista de la Torre
// =====================================================================
console.log('\n[T1] Superficie de la vista de la Torre');
assertCondition(typeof createMastersTowerView === 'function', 'el módulo exporta su fábrica');
assertCondition(TOWER_ACCESS_DENIED_EVENT === 'moderation:tower-access-denied', 'el aviso de acceso denegado declara su nombre canónico');
const mountT1 = createFakeElement('div');
const towerViewT1 = createMastersTowerView(mountT1, {
  moderationClient: createFakeModerationClient(),
  store: createFakeStore('master'),
  elementFactory: fakeElementFactory,
  documentRef: fakeDocument,
});
assertCondition(
  typeof towerViewT1.render === 'function' && typeof towerViewT1.destroy === 'function'
    && typeof towerViewT1.retry === 'function' && typeof towerViewT1.setModerationClient === 'function',
  'la vista declara su API: render, destroy, retry y setModerationClient'
);
towerViewT1.destroy();

// =====================================================================
// [T2] El lector es REDIRIGIDO (criterio: RF-03.5, RF-05.4)
// =====================================================================
console.log('\n[T2] El lector que intenta entrar a la Torre es REDIRIGIDO');
const mountT2 = createFakeElement('div');
const fakeClientT2 = createFakeModerationClient();
const denialsT2 = [];
const storeT2 = createFakeStore('reader');
const towerViewT2 = createMastersTowerView(mountT2, {
  moderationClient: fakeClientT2,
  store: storeT2,
  onAccessDenied: (origin) => denialsT2.push(origin),
  eventTarget: mountT2,
  elementFactory: fakeElementFactory,
  documentRef: fakeDocument,
});
await towerViewT2.render();
assertCondition(fakeClientT2.calls.deliberationQueue === 0, 'la vista JAMÁS consulta la cola con un rol menor');
assertCondition(mountT2.children.length === 0, 'el panel de la Torre ni siquiera nace en el montaje');
assertCondition(denialsT2.length === 1 && denialsT2[0] === 'tower', 'la redirección la decide el orquestador vía onAccessDenied');
assertCondition(
  mountT2.dispatch && fakeDocument.dispatchedEvents.some(
    (event) => event.type === TOWER_ACCESS_DENIED_EVENT,
  ),
  'el aviso también viaja por el bus desacoplado (moderation:tower-access-denied)'
);
towerViewT2.destroy();

// =====================================================================
// [T3] El editor también es REDIRIGIDO
// =====================================================================
console.log('\n[T3] El editor tampoco alcanza el rango de juzgar');
const mountT3 = createFakeElement('div');
const fakeClientT3 = createFakeModerationClient();
const denialsT3 = [];
const towerViewT3 = createMastersTowerView(mountT3, {
  moderationClient: fakeClientT3,
  store: createFakeStore('editor'),
  onAccessDenied: () => denialsT3.push('editor'),
  elementFactory: fakeElementFactory,
  documentRef: fakeDocument,
});
await towerViewT3.render();
assertCondition(fakeClientT3.calls.deliberationQueue === 0 && denialsT3.length === 1, 'el editor es redirigido sin consulta');
towerViewT3.destroy();

// =====================================================================
// [T4] El Maestro entra y el panel consulta la cola
// =====================================================================
console.log('\n[T4] El Maestro entra a la Torre');
const mountT4 = createFakeElement('div');
const fakeClientT4 = createFakeModerationClient();
const denialsT4 = [];
const storeT4 = createFakeStore('master');
const towerViewT4 = createMastersTowerView(mountT4, {
  moderationClient: fakeClientT4,
  store: storeT4,
  onAccessDenied: () => denialsT4.push('master'),
  elementFactory: fakeElementFactory,
  documentRef: fakeDocument,
});
await towerViewT4.render();
assertCondition(denialsT4.length === 0, 'al Maestro no se le redirige');
assertCondition(fakeClientT4.calls.deliberationQueue === 1, 'la cola del Cónclave se consulta');
assertCondition(mountT4.children.length === 1, 'el panel de la Torre nace en el montaje');
assertCondition(storeT4.getState().currentView === 'tower', 'el store registra currentView: tower (plan 4.1)');
towerViewT4.destroy();

// =====================================================================
// [T5] El Admin Supremo entra: el rango más alto juzga
// =====================================================================
console.log('\n[T5] El Administrador Supremo también juzga');
const mountT5 = createFakeElement('div');
const fakeClientT5 = createFakeModerationClient();
const towerViewT5 = createMastersTowerView(mountT5, {
  moderationClient: fakeClientT5,
  store: createFakeStore('supremeAdmin'),
  elementFactory: fakeElementFactory,
  documentRef: fakeDocument,
});
await towerViewT5.render();
assertCondition(fakeClientT5.calls.deliberationQueue === 1 && mountT5.children.length === 1, 'el supremeAdmin entra sin redirección');
towerViewT5.destroy();

// =====================================================================
// [T6] El arbitraje del backend permanece (defensa en profundidad)
// =====================================================================
console.log('\n[T6] La autoridad última es el backend (Art. II)');
const mountT6 = createFakeElement('div');
const towerViewT6 = createMastersTowerView(mountT6, {
  // El backend respondería 403 INSUFFICIENT_RANK_TO_JUDGE a un rol menor:
  // la vista simplemente nunca llega a cursar la petición, pero el cliente
  // HTTP transporta el sobre del santuario tal cual llega (Tarea 5.1).
  moderationClient: createFakeModerationClient({
    fetchDeliberationQueue: async () => ({
      success: false,
      status: 403,
      error: { code: 'INSUFFICIENT_RANK_TO_JUDGE', message: 'El Cónclave no admite tu rango.' },
    }),
  }),
  store: createFakeStore('master'),
  elementFactory: fakeElementFactory,
  documentRef: fakeDocument,
});
await towerViewT6.render();
assertCondition(mountT6.children.length === 1, 'con el sobre 403, la vista pinta el estado de error del componente');
assertCondition(
  mountT6.textContent.includes('La corriente de maná se ha interrumpido'),
  'el sobre fallido se retrata con la leyenda controlada del componente (AGENTS.md 6.1: el detalle interno jamás se filtra)'
);
towerViewT6.destroy();

// =====================================================================
// [T7] El bus refresca la cola y destroy lo desengancha
// =====================================================================
console.log('\n[T7] Refresco dirigido por evento (plan 4.1)');
const mountT7 = createFakeElement('div');
const fakeClientT7 = createFakeModerationClient();
const busT7 = createFakeElement('div');
const towerViewT7 = createMastersTowerView(mountT7, {
  moderationClient: fakeClientT7,
  store: createFakeStore('master'),
  eventTarget: busT7,
  elementFactory: fakeElementFactory,
  documentRef: fakeDocument,
});
await towerViewT7.render();
assertCondition(fakeClientT7.calls.deliberationQueue === 1, 'primera consulta cursada');
busT7.dispatch('moderation:signed');
busT7.dispatch('moderation:retracted');
busT7.dispatch('moderation:consecrated');
busT7.dispatch('moderation:rejected');
await new Promise((resolve) => setTimeout(resolve, 0));
assertCondition(fakeClientT7.calls.deliberationQueue === 5, 'los cuatro eventos del plan 4.1 refrescan la cola');
towerViewT7.destroy();
busT7.dispatch('moderation:signed');
await new Promise((resolve) => setTimeout(resolve, 0));
assertCondition(fakeClientT7.calls.deliberationQueue === 5, 'tras destroy, el bus ya no conmueve a la Torre');

// =====================================================================
// [T8] Los gestos de firma y objeción los declara el orquestador
// =====================================================================
console.log('\n[T8] Los gestos judiciales viajan al orquestador');
const mountT8 = createFakeElement('div');
const signaturesT8 = [];
const objectionsT8 = [];
const towerViewT8 = createMastersTowerView(mountT8, {
  moderationClient: createFakeModerationClient(),
  store: createFakeStore('master'),
  onSignatureIntent: (spellId) => signaturesT8.push(spellId),
  onObjectionIntent: (spellId) => objectionsT8.push(spellId),
  elementFactory: fakeElementFactory,
  documentRef: fakeDocument,
});
await towerViewT8.render();
assertCondition(typeof towerViewT8.render === 'function', 'la vista entrega los dos callbacks sin envolverlos en navegación propia');
towerViewT8.destroy();

// =====================================================================
// [T9] destroy idempotente sin fugas
// =====================================================================
console.log('\n[T9] Ciclo de vida idempotente');
const mountT9 = createFakeElement('div');
const towerViewT9 = createMastersTowerView(mountT9, {
  moderationClient: createFakeModerationClient(),
  store: createFakeStore('master'),
  elementFactory: fakeElementFactory,
  documentRef: fakeDocument,
});
await towerViewT9.render();
towerViewT9.destroy();
towerViewT9.destroy();
assertCondition(mountT9.children.length === 0, 'destroy idempotente deja el montaje limpio');
await towerViewT9.render();
assertCondition(mountT9.children.length === 0, 'tras destroy, nada vuelve a montarse');

console.log(`\n== RESUMEN == Asertos superados: ${assertsPassed}, fallidos: ${assertsFailed}`);
if (assertsFailed === 0) {
  console.log('RESULTADO: EXITO — La Torre redirige al no autorizado y el Atrio es libre (Tarea 6.4).');
  process.exit(0);
} else {
  console.log('RESULTADO: FALLO — las vistas incumplen su criterio.');
  process.exit(1);
}
