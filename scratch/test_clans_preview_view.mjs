/**
 * test_clans_preview_view.mjs — Verificación del Salón de Linajes (Tarea 5.6).
 *
 * Estrategia TDD: este script se escribe ANTES que clansPreviewView.js.
 * Valida contra el criterio "Hecho cuando" de specs/01-portal-and-navigation.tasks.md:
 *   Un visitante no autenticado accede a la ruta de linajes pudiendo leer la
 *   información de clanes y rankings sin recibir bloqueos de acceso.
 *
 * Requisitos: RF-02.2 (lectura pública de linajes con Dominio semanal),
 * HU-05, Artículo III (el clan fundacional 'cln_primordial' es neutro y no
 * compite), contrato del endpoint GET /clans/preview (plan 3, Tarea 1.5) y
 * cliente fetchClansPreview (Tarea 3.3).
 *
 * Uso: node scratch/test_clans_preview_view.mjs
 */

import { createClansPreviewView } from '../public/assets/js/views/clansPreviewView.js';
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

/** Elemento DOM mínimo simulado (patrón consolidado de las Tareas 5.1-5.5). */
function createFakeElement(tagName) {
  const element = {
    tagName: String(tagName).toUpperCase(),
    children: [],
    attributes: {},
    classes: new Set(),
    listeners: {},
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
    // textContent fiel al DOM REAL: agrega recursivamente el texto de los
    // descendientes (los asertos leen contenedores, no solo hojas).
    get textContent() {
      const own = this._textContent;
      const descendants = this.children.map((child) => child.textContent).join('');
      return `${own}${descendants}`;
    },
    set textContent(value) {
      // El setter real reemplaza el contenido: limpia hijos y fija el texto.
      for (const child of this.children.splice(0)) child.parentElement = null;
      this._textContent = String(value);
    },
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
 * Linajes que el backend retorna (contrato de ClanController, Tarea 1.5):
 * el neutro 'cln_primordial' (Art. III) y dos clanes competitivos ya
 * ordenados por Dominio descendente (ORDER BY weekly_points DESC).
 */
const clansPreview = [
  { id: 'cln_astral_scholars', slug: 'eruditos-astrales', name: 'Eruditos Astrales', motto: 'El saber es la única estrella fija.', domainPoints: 240 },
  { id: 'cln_veiled_circle', slug: 'circulo-velado', name: 'Círculo Velado', motto: 'Lo que se oculta, protege.', domainPoints: 175 },
  { id: 'cln_primordial', slug: 'custodios-del-fuego-primordial', name: 'Custodios del Fuego Primordial', motto: 'Antes de la primera palabra, ya ardimos.', domainPoints: 0 },
];

const networkEnvelope = {
  success: false,
  error: { code: 'MANA_STREAM_INTERRUPTED', message: 'La corriente de maná se ha interrumpido.', recoveryAction: 'RETRY' },
};

console.log('== VERIFICACION TAREA 5.6: clansPreviewView.js ==\n');

// --- FASE 1: CRITERIO — visitante sin sesión lee todo sin bloqueos ---
console.log('FASE 1: Criterio (visitante no autenticado lee sin bloqueos)');

const store1 = createStore();
const client1 = { fetchClansPreview: async () => ({ success: true, data: clansPreview }) };
const root1 = createFakeElement('main');
const clans1 = createClansPreviewView(root1, {
  store: store1,
  spellClient: client1,
  elementFactory: fakeElementFactory,
});

await clans1.render();

assertCondition(byClass(root1, 'clans-view') !== null, 'El Salón de Linajes monta su raíz (RF-02.2)');
assertCondition(store1.getState().currentView === 'clans', 'La vista activa del store es clans (plan 4.1)');
assertCondition(allByClass(root1, 'clans-view__card').length === 3, 'Los 3 linajes del listado se renderizan sin bloqueos (criterio)');
assertCondition(allByClass(root1, 'clans-view__lock').length === 0, 'CERO elementos de bloqueo/acceso denegado en el DOM (criterio: sin bloqueos)');

const firstCard1 = allByClass(root1, 'clans-view__card')[0];
assertCondition(
  firstCard1.textContent.includes('Eruditos Astrales') && firstCard1.textContent.includes('El saber es la única estrella fija.'),
  'Nombre y lema de cada linaje visibles (lectura pública)',
);
assertCondition(byClass(root1, 'clans-view__signup') === null, 'Sin botones de inscripción que exijan sesión (solo lectura, RF-02.2)');

// --- FASE 2: Tabla de clasificación semanal de Dominio (HU-05) ---
console.log('\nFASE 2: Clasificación semanal de Dominio del Grimorio');

const rankingRows2 = allByClass(root1, 'clans-view__ranking-row');
assertCondition(rankingRows2.length === 3, 'La tabla de clasificación lista los 3 linajes (HU-05)');
assertCondition(
  rankingRows2[0].getAttribute('data-clan-id') === 'cln_astral_scholars' &&
  rankingRows2[1].getAttribute('data-clan-id') === 'cln_veiled_circle',
  'El ranking respeta el orden por Dominio descendente que envía el backend',
);
assertCondition(
  rankingRows2.some((row) => row.textContent.includes('240')) &&
  rankingRows2.some((row) => row.textContent.includes('175')),
  'Los puntos de Dominio semanal se muestran en la tabla (RF-02.2)',
);
assertCondition(byClass(root1, 'clans-view__ranking') !== null, 'La clasificación vive en su tabla propia con cabeceras legibles');

// --- FASE 3: Artículo III — el linaje neutro no compite ---
console.log('\nFASE 3: El Custodio Primordial es neutro (no compite por el Dominio)');

const primordialRow3 = rankingRows2.find((row) => row.getAttribute('data-clan-id') === 'cln_primordial');
assertCondition(primordialRow3 !== undefined, 'El linaje fundacional figura en el listado (es parte del santuario)');
assertCondition(
  primordialRow3.classes.has('clans-view__ranking-row--neutral'),
  'El linaje neutro porta el marcado de no competidor (Art. III)',
);
assertCondition(
  primordialRow3.textContent.includes('0') || primordialRow3.textContent.includes('Neutro'),
  'El Dominio del neutro se presenta a 0 / sin competencia',
);

// --- FASE 4: Robustez — fallo de red, sobre vacío y ciclo de vida ---
console.log('\nFASE 4: Robustez y ciclo de vida');

const store4 = createStore();
const client4 = { fetchClansPreview: async () => networkEnvelope };
const root4 = createFakeElement('main');
const clans4 = createClansPreviewView(root4, {
  store: store4,
  spellClient: client4,
  elementFactory: fakeElementFactory,
});
await clans4.render();
assertCondition(byClass(root4, 'clans-view__error') !== null, 'Un fallo de red muestra el estado de error temático (RF-06.3)');
assertCondition(byClass(root4, 'clans-view__error-retry') !== null, 'El error ofrece botón de reintento');

// Sobre vacío (sin clanes aún): estado vacío, no error.
const root4b = createFakeElement('main');
const clans4b = createClansPreviewView(root4b, {
  store: store4,
  spellClient: { fetchClansPreview: async () => ({ success: true, data: [] }) },
  elementFactory: fakeElementFactory,
});
await clans4b.render();
assertCondition(byClass(root4b, 'clans-view__empty') !== null, 'Un salón sin linajes muestra el estado vacío temático');

// Markup hostil en el lema de un clan: texto literal, nunca markup (6.1).
const root4c = createFakeElement('main');
const clans4c = createClansPreviewView(root4c, {
  store: store4,
  spellClient: {
    fetchClansPreview: async () => ({
      success: true,
      data: [{ id: 'cln_x', slug: 'hostil', name: 'Clan <script>', motto: '<img src=x onerror=alert(1)>', domainPoints: 5 }],
    }),
  },
  elementFactory: fakeElementFactory,
});
await clans4c.render();
assertCondition(
  allByClass(root4c, 'clans-view__card').some((card) => card.textContent.includes('<img')),
  'El lore hostil viaja como TEXTO literal (cero innerHTML, AGENTS.md 6.1)',
);

// Re-render idempotente y destroy():
const root5 = createFakeElement('main');
const clans5 = createClansPreviewView(root5, {
  store: createStore(),
  spellClient: client1,
  elementFactory: fakeElementFactory,
});
await clans5.render();
await clans5.render();
assertCondition(allByClass(root5, 'clans-view__card').length === 3, 'Re-render idempotente: sin duplicados');
clans5.destroy();
assertCondition(allByClass(root5, 'clans-view__card').length === 0, 'destroy() limpia la vista del montaje');

// --- Resumen final ---
console.log('\n== RESUMEN ==');
console.log(`Asertos superados: ${assertsPassed}`);
console.log(`Asertos fallidos:  ${assertsFailed}`);

if (assertsFailed === 0) {
  console.log("\nRESULTADO: EXITO — La Tarea 5.6 cumple su criterio 'Hecho cuando'.");
  process.exit(0);
}

console.log('\nRESULTADO: FALLO — Corregir los asertos marcados con [FALLA].');
process.exit(1);
