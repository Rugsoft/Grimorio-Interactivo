/**
 * test_test_log_storage.mjs — Arnés TDD de la Tarea 4.3 (TASKS-05).
 *
 * Estrategia TDD: este script se escribe ANTES que la implementación.
 * Verifica, sobre `public/assets/js/utils/testLogStorage.js`:
 *   [1] Fábrica y persistencia: los registros sobreviven a una instancia
 *       nueva (mismo localStorage, esquema del plan 2.2).
 *   [2] Registro de impacto: marca temporal, nombre del conjuro, afinidad,
 *       círculo, maná, daño/barrera/CC y salud restante del maniquí.
 *   [3] Máximo exacto de 5 entradas: la sexta desplaza a la más antigua
 *       (orden cronológico, la más reciente primero).
 *   [4] Limpieza: clear() vacía la bitácora («Restaurar Maniquí», RF-02.6).
 *   [5] Robustez: localStorage corrupto o ausente → esquema válido sin
 *       lanzar; datos corruptos individuales se descartan.
 *
 * Constitución:
 *   - Artículo I: localStorage nativo; cero IndexedDB ni librerías.
 *   - Artículo V: claves del esquema en inglés camelCase; API en inglés.
 *
 * Uso: node scratch/test_test_log_storage.mjs
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

/** localStorage falso (semántica nativa: solo cadenas). */
function createFakeStorage() {
  const map = new Map();
  return {
    getItem: (key) => (map.has(key) ? map.get(key) : null),
    setItem: (key, value) => { map.set(key, String(value)); },
    removeItem: (key) => { map.delete(key); },
    clear: () => map.clear(),
  };
}

console.log('== ARNÉS TDD — Tarea 4.3: Bitácora de Pruebas (localStorage) ==');

let module;
try {
  module = await import('../public/assets/js/utils/testLogStorage.js');
} catch (error) {
  console.log(`  FATAL — no se pudo importar testLogStorage.js: ${error.message}`);
  console.log('== RESUMEN == Fase roja: la implementación aún no existe.');
  process.exit(1);
}

const { createTestLogStorage, TEST_LOG_STORAGE_KEY, TEST_LOG_MAX_ENTRIES } = module;

/** Impacto canónico de prueba. */
function impact({ name = 'Esfera de Llamas Purificadoras', damage = 30, health = 470 } = {}) {
  return {
    spellName: name,
    elementalAffinity: 'fire',
    circle: 2,
    manaCost: 35,
    damageDealt: damage,
    barrierAbsorbed: 0,
    healingApplied: 0,
    crowdControlApplied: 'none',
    dummyRemainingHealth: health,
  };
}

console.log('[1] Fábrica y persistencia entre sesiones (RF-05.3)');

{
  const storage = createFakeStorage();
  const first = createTestLogStorage({ storage });

  assertCondition(typeof createTestLogStorage === 'function', 'exporta createTestLogStorage(options)');
  assertCondition(typeof first.recordImpact === 'function', 'expone recordImpact(impact)');
  assertCondition(typeof first.getEntries === 'function', 'expone getEntries()');
  assertCondition(typeof first.clear === 'function', 'expone clear()');
  assertCondition(TEST_LOG_STORAGE_KEY === 'grimorio_test_log_v1', `clave canónica del plan 2.2 — ${TEST_LOG_STORAGE_KEY}`);
  assertCondition(TEST_LOG_MAX_ENTRIES === 5, 'máximo canónico de 5 entradas');

  first.recordImpact(impact());
  const sessionId = first.getMetadata().sessionId;

  // «Refresco del navegador»: instancia nueva con el mismo storage.
  const second = createTestLogStorage({ storage });
  const entries = second.getEntries();
  assertCondition(entries.length === 1, 'el registro sobrevive a una instancia nueva (persistencia)');
  assertCondition(second.getMetadata().sessionId === sessionId, 'la sesión se conserva entre refrescos');
}

console.log('[2] Registro de impacto — esquema del plan 2.2');

{
  const log = createTestLogStorage({ storage: createFakeStorage() });
  log.recordImpact(impact());
  const entry = log.getEntries()[0];

  assertCondition(typeof entry.timestamp === 'string' && entry.timestamp.length > 0, 'marca temporal presente');
  assertCondition(entry.spellName === 'Esfera de Llamas Purificadoras', 'nombre del conjuro registrado');
  assertCondition(entry.elementalAffinity === 'fire' && entry.circle === 2 && entry.manaCost === 35, 'afinidad, círculo y maná registrados');
  assertCondition(entry.damageDealt === 30 && entry.barrierAbsorbed === 0 && entry.healingApplied === 0, 'desglose de daño/barrera/curación registrado');
  assertCondition(entry.crowdControlApplied === 'none', 'control de masas registrado');
  assertCondition(entry.dummyRemainingHealth === 470, 'salud restante del maniquí registrada');
}

console.log('[3] Máximo exacto de 5 entradas en orden cronológico');

{
  const log = createTestLogStorage({ storage: createFakeStorage() });
  for (let i = 1; i <= 8; i++) {
    log.recordImpact(impact({ name: `Conjuro ${i}`, health: 500 - i * 10 }));
  }

  const entries = log.getEntries();
  assertCondition(entries.length === 5, `exactamente 5 entradas tras 8 impactos — ${entries.length}`);
  assertCondition(entries[0].spellName === 'Conjuro 8', 'la más reciente encabeza la lista');
  assertCondition(entries[4].spellName === 'Conjuro 4', 'las tres más antiguas fueron desplazadas');
  // Orden cronológico descendente estricto.
  const ordered = entries.every((entry, index) => index === 0 || entries[index - 1].timestamp >= entry.timestamp);
  assertCondition(ordered, 'orden cronológico descendente mantenido');
}

console.log('[4] Limpieza al pulsar «Restaurar Maniquí» (RF-02.6)');

{
  const log = createTestLogStorage({ storage: createFakeStorage() });
  log.recordImpact(impact());
  log.recordImpact(impact({ name: 'Segundo' }));
  assertCondition(log.getEntries().length === 2, 'dos registros antes de limpiar');

  log.clear();
  assertCondition(log.getEntries().length === 0, 'clear() vacía la bitácora');
  // Y el storage físico también quedó limpio (sin cadáveres JSON).
  const second = createTestLogStorage({ storage: createFakeStorage() });
  second.recordImpact(impact());
  second.clear();
  assertCondition(second.getEntries().length === 0, 'tras limpiar, una relectura confirma la bitácora vacía');
}

console.log('[5] Robustez — storage corrupto o ausente');

{
  // JSON corrupto: el gestor lo trata como bitácora nueva sin lanzar.
  const corrupted = createFakeStorage();
  corrupted.setItem(TEST_LOG_STORAGE_KEY, '{no-es-json::');
  let threw = false;
  let log = null;
  try { log = createTestLogStorage({ storage: corrupted }); } catch { threw = true; }
  assertCondition(!threw, 'JSON corrupto no revienta la factoría');
  assertCondition(log.getEntries().length === 0, 'bitácora corrupta se reinicia vacía');

  // Esquema válido con logs malformados: las entradas inválidas se descartan.
  const halfCorrupt = createFakeStorage();
  halfCorrupt.setItem(TEST_LOG_STORAGE_KEY, JSON.stringify({
    sessionId: 'ses_x',
    maxEntries: 5,
    logs: [
      { spellName: 'Válido', timestamp: '2026-09-13T19:05:12Z' },
      null,
      { spellName: 42 },
      'basura',
    ],
  }));
  const recovered = createTestLogStorage({ storage: halfCorrupt });
  const kept = recovered.getEntries();
  assertCondition(kept.length === 1 && kept[0].spellName === 'Válido', 'entradas malformadas descartadas, válidas conservadas');

  // Storage ausente (nulo): degradación a memoria sin lanzar.
  let memoryThrew = false;
  let memoryLog = null;
  try { memoryLog = createTestLogStorage({ storage: null }); } catch { memoryThrew = true; }
  assertCondition(!memoryThrew && memoryLog !== null, 'sin storage funciona en memoria (degradación grácil)');
  memoryLog.recordImpact(impact());
  assertCondition(memoryLog.getEntries().length === 1, 'en memoria los impactos se registran igualmente');
}

console.log('== RESUMEN ==');
console.log(`Asertos superados: ${assertsPassed}, fallidos: ${assertsFailed}`);
if (assertsFailed === 0) {
  console.log('RESULTADO: EXITO — Bitácora de Pruebas lista (Tarea 4.3).');
  process.exit(0);
} else {
  console.log('RESULTADO: FALLO — hay asertos incumplidos.');
  process.exit(1);
}
