/**
 * test_history_manager.mjs — Verificación del sincronizador de historial (Tarea 3.4).
 *
 * Estrategia TDD: este script se escribe ANTES que historyManager.js.
 * Valida contra el criterio "Hecho cuando" de specs/01-portal-and-navigation.tasks.md:
 *   Al cambiar el hash se notifica al orquestador y pulsar el botón "Atrás"
 *   del navegador revierte el estado sin abandonar la aplicación web.
 *
 * Entorno: se simulan window/history/location con objetos mínimos conformes
 * a la Web API (nada de dependencias). El módulo debe operar con ellos vía
 * inyección, igual que lo hará con los reales del navegador.
 *
 * Uso: node scratch/test_history_manager.mjs
 */

import {
  createHistoryManager,
  SPELL_HASH_PREFIX,
  buildSpellHash,
  parseSpellHash,
} from '../public/assets/js/utils/historyManager.js';

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
 * Entorno de historial simulado conforme a la Web API:
 *  - pushState/replaceState acumulan entradas (entry = { url, state }).
 *  - back() dispara popstate y restaura la entrada previa (botón Atrás).
 *  - El hash de la URL cambia exactamente como en el navegador.
 */
function createFakeHistoryEnvironment(initialUrl = 'http://grimorio.test/') {
  let currentUrl = initialUrl;
  const entries = [{ url: currentUrl, state: null }];
  let entryIndex = 0;
  const popstateListeners = new Set();
  const hashChangeListeners = new Set();

  /*
   * Resolución de URLs relativas idéntica a la del navegador: el fragmento
   * (#hash) reemplaza al fragmento previo conservando origen y path.
   */
  function resolveAgainstCurrent(rawUrl) {
    if (rawUrl.startsWith('http')) return rawUrl;
    if (rawUrl.startsWith('#')) return currentUrl.split('#')[0] + rawUrl;
    return `http://grimorio.test${rawUrl}`;
  }

  const fakeLocation = {
    get href() { return currentUrl; },
    get hash() {
      const hashIndex = currentUrl.indexOf('#');
      return hashIndex === -1 ? '' : currentUrl.slice(hashIndex);
    },
    // Asignación location.hash = '#...' navega y dispara hashchange.
    set hash(newHash) {
      const withoutHash = currentUrl.split('#')[0];
      currentUrl = `${withoutHash}${newHash.startsWith('#') ? newHash : `#${newHash}`}`;
      entries.splice(entryIndex + 1); // La asignación de hash trunca el futuro.
      entries.push({ url: currentUrl, state: null });
      entryIndex = entries.length - 1;
      for (const listener of hashChangeListeners) listener({ newURL: currentUrl });
    },
  };  const fakeHistory = {
    /* En el navegador, pushState/replaceState resuelven la URL relativa
       contra la actual (el path se conserva; cambia solo el fragmento).
       El simulador replica esa resolución para ser fiel al runtime real. */
    pushState(stateObject, _unusedTitle, url) {
      const resolvedUrl = resolveAgainstCurrent(url);
      entries.splice(entryIndex + 1);
      entries.push({ url: resolvedUrl, state: stateObject });
      entryIndex = entries.length - 1;
      currentUrl = resolvedUrl;
    },
    replaceState(stateObject, _unusedTitle, url) {
      const resolvedUrl = resolveAgainstCurrent(url);
      entries[entryIndex] = { url: resolvedUrl, state: stateObject };
      currentUrl = resolvedUrl;
    },
    back() {
      if (entryIndex === 0) return;
      entryIndex -= 1;
      currentUrl = entries[entryIndex].url;
      // El navegador dispara popstate al navegar por el historial (RNF-05).
      for (const listener of popstateListeners) {
        listener({ state: entries[entryIndex].state });
      }
    },
    forward() {
      if (entryIndex >= entries.length - 1) return;
      entryIndex += 1;
      currentUrl = entries[entryIndex].url;
      for (const listener of popstateListeners) {
        listener({ state: entries[entryIndex].state });
      }
    },
  };

  const fakeWindow = {
    get location() { return fakeLocation; },
    get history() { return fakeHistory; },
    addEventListener(eventName, listener) {
      if (eventName === 'popstate') popstateListeners.add(listener);
      if (eventName === 'hashchange') hashChangeListeners.add(listener);
    },
    removeEventListener(eventName, listener) {
      popstateListeners.delete(listener);
      hashChangeListeners.delete(listener);
    },
  };

  return {
    window: fakeWindow,
    get currentUrl() { return currentUrl; },
    get entryCount() { return entries.length; },
    get entryIndex() { return entryIndex; },
    get currentState() { return entries[entryIndex].state; },
  };
}

console.log('== VERIFICACION TAREA 3.4: historyManager.js ==\n');

// --- FASE 1: Utilidades puras de hash ---
console.log('FASE 1: Utilidades de hash');
assertCondition(typeof SPELL_HASH_PREFIX === 'string' && SPELL_HASH_PREFIX.startsWith('#'), 'SPELL_HASH_PREFIX exportado con el prefijo del plan (#hechizo-...)');
assertCondition(buildSpellHash('rayo-astral') === '#hechizo-rayo-astral', 'buildSpellHash construye #hechizo-slug (plan 4.3)');
assertCondition(buildSpellHash('llamas-de-frieren') === '#hechizo-llamas-de-frieren', 'buildSpellHash preserva slugs compuestos');
assertCondition(parseSpellHash('#hechizo-rayo-astral') === 'rayo-astral', 'parseSpellHash extrae el slug del hash');
assertCondition(parseSpellHash('#otra-cosa') === null, 'parseSpellHash retorna null para hashes ajenos');
assertCondition(parseSpellHash('') === null && parseSpellHash('#hechizo-') === null, 'parseSpellHash rechaza hashes vacíos o sin slug');

// --- FASE 2: CRITERIO — cambio de hash notifica al orquestador ---
console.log('\nFASE 2: Criterio (cambio de hash notifica)');

const environmentA = createFakeHistoryEnvironment();
const hashEvents = [];
const managerA = createHistoryManager(environmentA.window, {
  onSpellOpen: (slug) => hashEvents.push({ kind: 'open', slug }),
  onSpellClose: () => hashEvents.push({ kind: 'close' }),
});

// El orquestador abre la ficha de un hechizo:
managerA.openSpellDetail('rayo-astral');

assertCondition(environmentA.currentUrl.includes('#hechizo-rayo-astral'), 'openSpellDetail actualiza la dirección con #hechizo-slug (RF-04.1)');
assertCondition(environmentA.entryCount === 2, 'La apertura añade una entrada al historial (el Atrás volverá al catálogo)');

// Simulación del usuario que comparte/pega el enlace o cambia el hash a mano:
environmentA.window.location.hash = '#hechizo-llamas-de-frieren';
assertCondition(
  hashEvents.some((event) => event.kind === 'open' && event.slug === 'llamas-de-frieren'),
  'Al cambiar el hash (hashchange) se notifica al orquestador con el slug (criterio)'
);

// --- FASE 3: CRITERIO — botón Atrás revierte sin salir de la app ---
console.log('\nFASE 3: Criterio (Atrás cierra el panel, RNF-05)');

/*
 * Escenario fiel al navegador: entorno fresco, UNA ficha abierta, Atrás.
 * (En la FASE 2 hubo dos hashes de hechizo seguidos; en un navegador real
 * el Atrás habría ido del hechizo B al hechizo A, no al catálogo: por eso
 * este escenario usa un entorno limpio con una sola entrada de modal.)
 */
const environmentBack = createFakeHistoryEnvironment();
const backEvents = [];
const managerBack = createHistoryManager(environmentBack.window, {
  onSpellOpen: (slug) => backEvents.push({ kind: 'open', slug }),
  onSpellClose: () => backEvents.push({ kind: 'close' }),
});

managerBack.openSpellDetail('rayo-astral');
environmentBack.window.history.back();

assertCondition(
  backEvents.some((event) => event.kind === 'close'),
  'Al pulsar Atrás el orquestador recibe la orden de cierre (popstate state=null)'
);
assertCondition(
  !environmentBack.currentUrl.includes('#hechizo-'),
  'La URL revierte al catálogo sin hash de hechizo (sin abandonar la web)'
);
assertCondition(environmentBack.entryIndex === 0, 'El historial retrocedió una entrada exactamente');

// Navegación entre dos fichas: Atrás va del hechizo B al hechizo A (sin cerrar).
const environmentChained = createFakeHistoryEnvironment();
const chainedEvents = [];
const managerChained = createHistoryManager(environmentChained.window, {
  onSpellOpen: (slug) => chainedEvents.push({ kind: 'open', slug }),
  onSpellClose: () => chainedEvents.push({ kind: 'close' }),
});
managerChained.openSpellDetail('rayo-astral');
environmentChained.window.location.hash = '#hechizo-llamas-de-frieren'; // hashchange: entrada B (state null)
environmentChained.window.history.back(); // vuelve a la entrada A, que SÍ porta el modal
assertCondition(
  chainedEvents.filter((event) => event.kind === 'open' && event.slug === 'rayo-astral').length === 1,
  'El Atrás entre dos fichas reabre el hechizo anterior (sin cerrar el panel)'
);

// --- FASE 4: Apertura directa por hash inicial (enlace compartido) ---
console.log('\nFASE 4: Hash inicial (enlace compartido, RF-04.1)');

// Página cargada directamente con el hash de un hechizo:
const environmentB = createFakeHistoryEnvironment('http://grimorio.test/#hechizo-manto-de-niebla');
const openedOnBoot = [];
createHistoryManager(environmentB.window, {
  onSpellOpen: (slug) => openedOnBoot.push(slug),
  onSpellClose: () => {},
  resolveInitialHash: true,
});

assertCondition(
  openedOnBoot.length === 1 && openedOnBoot[0] === 'manto-de-niebla',
  'Al arrancar con #hechizo-slug, el orquestador recibe la orden de abrir la ficha'
);

// Arranque sin hash: no abre nada.
const environmentC = createFakeHistoryEnvironment('http://grimorio.test/');
const openedC = [];
createHistoryManager(environmentC.window, {
  onSpellOpen: (slug) => openedC.push(slug),
  onSpellClose: () => {},
  resolveInitialHash: true,
});
assertCondition(openedC.length === 0, 'Sin hash inicial no se notifica apertura alguna');

// --- FASE 5: Cierre programático sin re-apertura en bucle ---
console.log('\nFASE 5: Cierre programático (sin bucles)');

const environmentD = createFakeHistoryEnvironment();
let openCount = 0;
let closeCount = 0;
const managerD = createHistoryManager(environmentD.window, {
  onSpellOpen: () => { openCount++; },
  onSpellClose: () => { closeCount++; },
});

managerD.openSpellDetail('susurro-del-viento');
const entriesAfterOpen = environmentD.entryCount;

// El orquestador cierra el panel (Escape, botón ×): el gestor debe sustituir
// la URL (replaceState) SIN añadir entrada ni disparar apertura de nuevo.
managerD.closeSpellDetail();

assertCondition(closeCount === 0, 'El cierre programático NO re-dispara el callback de cierre (sin bucles)');
assertCondition(environmentD.entryCount === entriesAfterOpen, 'El cierre no añade entradas al historial');
assertCondition(!environmentD.currentUrl.includes('#hechizo-'), 'El hash del hechizo desaparece de la URL tras cerrar');

// Tras el cierre, un Atrás posterior conduce al catálogo (entrada 0, sin modal):
// NO reabre el panel y la orden que emite es de cierre, nunca de apertura.
environmentD.window.history.back();
assertCondition(openCount === 0, 'El Atrás posterior NO reabre el panel (la entrada del modal fue sustituida)');
assertCondition(closeCount === 1, 'El Atrás posterior emite cierre limpio hacia el catálogo (RF-04.3)');

// --- FASE 6: Bajas limpias de listeners ---
console.log('\nFASE 6: Ciclo de vida');

const environmentE = createFakeHistoryEnvironment();
const eventsE = [];
const managerE = createHistoryManager(environmentE.window, {
  onSpellOpen: (slug) => eventsE.push(slug),
  onSpellClose: () => {},
});
managerE.destroy();
environmentE.window.location.hash = '#hechizo-chispa-de-ignicion';
assertCondition(eventsE.length === 0, 'destroy() retira los listeners (sin avisos tras la baja)');

// --- Resumen final ---
console.log('\n== RESUMEN ==');
console.log(`Asertos superados: ${assertsPassed}`);
console.log(`Asertos fallidos:  ${assertsFailed}`);

if (assertsFailed === 0) {
  console.log("\nRESULTADO: EXITO — La Tarea 3.4 cumple su criterio 'Hecho cuando'.");
  process.exit(0);
}

console.log('\nRESULTADO: FALLO — Corregir los asertos marcados con [FALLA].');
process.exit(1);
