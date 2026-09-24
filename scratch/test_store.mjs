/**
 * test_store.mjs — Verificación del gestor de estado reactivo (Tarea 3.2).
 *
 * Estrategia TDD: este script se escribe ANTES que store.js.
 * Valida contra el criterio "Hecho cuando" de specs/01-portal-and-navigation.tasks.md:
 *   Los cambios de estado mediante store.setState(...) notifican a los
 *   suscriptores registrados y conservan la intención pendiente en memoria
 *   volátil de sesión.
 *
 * Contratos cubiertos (plan 4.1):
 *   - currentView, catalogSpells, activeFilters, pagination, activeModal, pendingIntent.
 *   - RF-05.4: si localStorage no está disponible, la intención vive en memoria.
 *
 * Uso: node scratch/test_store.mjs
 */

import { createStore, VIEW_NAMES } from '../public/assets/js/state/store.js';

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

console.log('== VERIFICACION TAREA 3.2: store.js (estado reactivo) ==\n');

// --- FASE 1: Estado inicial conforme al plan 4.1 ---
console.log('FASE 1: Estado inicial');
const store = createStore();
const initialState = store.getState();

assertCondition(Array.isArray(VIEW_NAMES) && VIEW_NAMES.length >= 4, 'VIEW_NAMES exporta las vistas del plan (landing, library, clans, error)');
assertCondition(initialState.currentView === 'landing', 'currentView inicia en landing');
assertCondition(Array.isArray(initialState.catalogSpells) && initialState.catalogSpells.length === 0, 'catalogSpells inicia vacío');

const filters = initialState.activeFilters;
assertCondition(
  filters.query === '' && filters.schools.length === 0 && filters.maxMana === null && filters.includeExperimental === false,
  'activeFilters inicia con el contrato del plan 4.1 (query, schools, maxMana, includeExperimental)'
);
assertCondition(
  initialState.pagination.offset === 0 && initialState.pagination.limit === 50 && initialState.pagination.hasMore === true && initialState.pagination.isLoading === false,
  'pagination inicia en { offset: 0, limit: 50, hasMore: true, isLoading: false } (RF-03.7)'
);
assertCondition(
  initialState.activeModal.type === null && initialState.activeModal.data === null,
  'activeModal inicia cerrado'
);
assertCondition(
  initialState.pendingIntent.action === null && initialState.pendingIntent.targetSlug === null,
  'pendingIntent inicia vacío (sin intención interceptada)'
);

// --- FASE 2: CRITERIO — setState notifica a los suscriptores ---
console.log('\nFASE 2: Criterio Hecho cuando (setState notifica)');

let notifications = 0;
let lastSeenState = null;
const unsubscribe = store.subscribe((newState) => {
  notifications++;
  lastSeenState = newState;
});

store.setState({ currentView: 'library' });
assertCondition(notifications === 1, 'setState dispara un aviso al suscriptor registrado');
assertCondition(lastSeenState?.currentView === 'library', 'El suscriptor recibe el NUEVO estado, no el anterior');

store.setState({ currentView: 'clans' });
assertCondition(notifications === 2, 'Cada setState dispara exactamente un aviso adicional');

// Varios suscriptores reciben el aviso en orden de registro.
const callOrder = [];
store.subscribe(() => callOrder.push('second'));
store.subscribe(() => callOrder.push('third'));
store.setState({ currentView: 'library' });
assertCondition(
  callOrder.join(',') === 'second,third' && notifications === 3,
  'Múltiples suscriptores reciben el aviso en orden de registro'
);

// La baja del suscriptor funciona (patrón Observador completo).
unsubscribe();
store.setState({ currentView: 'landing' });
assertCondition(notifications === 3, 'unsubscribe() detiene los avisos del suscriptor retirado');

// --- FASE 3: Fusión superficial e inmutabilidad ---
console.log('\nFASE 3: Fusión superficial e inmutabilidad');

const before = store.getState();
store.setState({ activeModal: { type: 'spellDetail', data: { slug: 'manto-de-niebla' } } });
const after = store.getState();

assertCondition(before !== after, 'setState produce un NUEVO objeto de estado (nunca muta el previo)');
assertCondition(
  before.currentView === 'landing' && after.currentView === 'landing',
  'El estado previo conserva sus valores (los componentes pueden comparar)'
);
assertCondition(
  after.activeModal.type === 'spellDetail' && after.activeModal.data?.slug === 'manto-de-niebla',
  'Los objetos anidados se reemplazan por los nuevos'
);

// El estado expuesto está congelado: nadie lo muta por accidente (RF-05.2 depende de esto).
let freezeEnforced = false;
try {
  store.getState().currentView = 'clans';
} catch (error) {
  freezeEnforced = true;
}
assertCondition(
  freezeEnforced || Object.isFrozen(store.getState()),
  'El estado está protegido contra mutación (congelado)'
);

// Los arrays anidados también: catalogSpells es una fuente de verdad confiable.
store.setState({ catalogSpells: [{ slug: 'chispa-de-ignicion' }] });
let nestedFreezeEnforced = false;
try {
  store.getState().catalogSpells.push({ slug: 'intruder' });
} catch {
  nestedFreezeEnforced = true;
}
assertCondition(
  (nestedFreezeEnforced || Object.isFrozen(store.getState().catalogSpells)) && store.getState().catalogSpells.length === 1,
  'Los arrays anidados están congelados (la rejilla no puede corromperlos por accidente)'
);

// --- FASE 4: CRITERIO — intención pendiente en memoria volátil (RF-05.4) ---
console.log('\nFASE 4: Criterio Hecho cuando (intención pendiente, RF-05.2/05.4)');

// RF-05.4: el almacenamiento puede estar bloqueado; el store no debe tocar
// localStorage jamás: la intención reside en memoria del módulo/proceso.
const intentStore = createStore();
intentStore.setState({ pendingIntent: { action: 'openCreator', targetSlug: null } });
assertCondition(
  intentStore.getState().pendingIntent.action === 'openCreator',
  'La intención interceptada se conserva en el estado (RF-05.2)'
);

// Reemplazo de intención posterior (una acción nueva sustituye a la vieja).
intentStore.setState({ pendingIntent: { action: 'joinClan', targetSlug: 'cln_primordial' } });
assertCondition(
  intentStore.getState().pendingIntent.action === 'joinClan' && intentStore.getState().pendingIntent.targetSlug === 'cln_primordial',
  'Una nueva intención sustituye a la anterior con su destino'
);

// Acciones de conveniencia del store (consumo limpio por componentes).
intentStore.clearPendingIntent();
assertCondition(
  intentStore.getState().pendingIntent.action === null,
  'clearPendingIntent() restablece la intención tras completar la acción (RF-05.3)'
);

// El store NUNCA toca localStorage: sin rastros en el entorno simulado.
// (Simulamos un entorno donde localStorage explota si se usa: RF-05.4.)
globalThis.localStorage = {
  setItem() { throw new Error('ALMACENAMIENTO BLOQUEADO'); },
  getItem() { return null; },
};
const volatileStore = createStore();
volatileStore.setState({ pendingIntent: { action: 'signSpell', targetSlug: 'llamas-de-frieren' } });
assertCondition(
  volatileStore.getState().pendingIntent.targetSlug === 'llamas-de-frieren',
  'Con almacenamiento bloqueado, la intención sigue viva en memoria sin fallas (RF-05.4)'
);

// --- FASE 5: Helpers de abono de catálogo (RF-03.7: carga incremental) ---
console.log('\nFASE 5: Abono incremental del catálogo');

const pageStore = createStore();
const firstPage = [{ slug: 'a' }, { slug: 'b' }];
pageStore.setState({ catalogSpells: firstPage, pagination: { offset: 0, limit: 50, hasMore: true, isLoading: false } });

pageStore.appendCatalogSpells([{ slug: 'c' }]);
assertCondition(
  pageStore.getState().catalogSpells.length === 3 && pageStore.getState().catalogSpells[2].slug === 'c',
  'appendCatalogSpells() concatena la siguiente página sin duplicar el estado'
);
assertCondition(
  firstPage.length === 2,
  'La página previa no fue mutada (inmutabilidad estructural)'
);

// --- Resumen final ---
console.log('\n== RESUMEN ==');
console.log(`Asertos superados: ${assertsPassed}`);
console.log(`Asertos fallidos:  ${assertsFailed}`);

if (assertsFailed === 0) {
  console.log("\nRESULTADO: EXITO — La Tarea 3.2 cumple su criterio 'Hecho cuando'.");
  process.exit(0);
}

console.log('\nRESULTADO: DENEGADO — Corregir los asertos marcados con [FALLA].');
process.exit(1);
