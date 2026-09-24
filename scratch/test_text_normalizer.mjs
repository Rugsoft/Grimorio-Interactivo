/**
 * test_text_normalizer.mjs — Verificación del normalizador (Tarea 3.1).
 *
 * Estrategia TDD: este script se escribe ANTES que textNormalizer.js.
 * Valida contra el criterio "Hecho cuando" de specs/01-portal-and-navigation.tasks.md:
 *   La función normalizeSearchText("  ¡IGNICIÓN MÁGICA!  ") retorna
 *   "ignicion magica" y cadenas de más de 100 caracteres son truncadas
 *   sin error.
 *
 * Runtime: Node nativo solo como ejecutor de ES Modules (no es una
 * dependencia del proyecto: el navegador carga el mismo archivo tal cual).
 *
 * Uso: node scratch/test_text_normalizer.mjs
 * Salida: código 0 si todos los asertos pasan; código 1 en caso contrario.
 */

import { normalizeSearchText, SEARCH_MAX_LENGTH, matchesSearch } from '../public/assets/js/utils/textNormalizer.js';

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

console.log('== VERIFICACION TAREA 3.1: textNormalizer.js ==\n');

// --- FASE 1: Criterio literal "Hecho cuando" ---
console.log('FASE 1: Criterio literal de la tarea');
const criterionResult = normalizeSearchText('  ¡IGNICIÓN MÁGICA!  ');
assertCondition(
  criterionResult === 'ignicion magica',
  `normalizeSearchText("  ¡IGNICIÓN MÁGICA!  ") === "ignicion magica" (retorna: "${criterionResult}")`
);

// Truncado sin error: cadena de 250 caracteres.
let truncateDidNotThrow = true;
let truncateResult = '';
try {
  truncateResult = normalizeSearchText('a'.repeat(250));
} catch {
  truncateDidNotThrow = false;
}
assertCondition(truncateDidNotThrow, 'Una cadena de 250 caracteres se procesa sin lanzar error');
assertCondition(
  truncateResult.length === SEARCH_MAX_LENGTH,
  `El resultado queda truncado a 100 caracteres (retorna: ${truncateResult.length})`
);

// --- FASE 2: Reglas del algoritmo del plan 5.1 ---
console.log('\nFASE 2: Reglas del plan 5.1');

// Diacríticos castellanos completos (NFD + eliminación de marcas).
assertCondition(
  normalizeSearchText('Ignición') === 'ignicion',
  'Elimina tildes: "Ignición" -> "ignicion"'
);
assertCondition(
  normalizeSearchText('Niña ültima añoranza') === 'nina ultima añoranza'.replace('ñ', 'n').replace('ñ', 'n'),
  'Elimina eñes y diéresis como diacríticos'
);

// Minúsculas y colapso de espacios.
assertCondition(
  normalizeSearchText('MANTO   de   NIEBLA') === 'manto de niebla',
  'Minúsculas y colapso de espacios múltiples'
);

// La puntuación se retira según el criterio de la tarea ('¡IGNICIÓN MÁGICA!'
// -> 'ignicion magica'): los signos no ensucian el índice de búsqueda.
assertCondition(
  normalizeSearchText('¡Susurro!') === 'susurro',
  'La puntuación circundante se retira ("¡Susurro!" -> "susurro")'
);

// Búsqueda insensible a acentos lado a lado (RF-03.3).
assertCondition(
  normalizeSearchText('ignicion') === normalizeSearchText('IGNICIÓN'),
  '"ignicion" y "IGNICIÓN" normalizan al mismo término (RF-03.3)'
);

// --- FASE 3: Robustez ante entradas hostiles ---
console.log('\nFASE 3: Robustez');
const edgeCases = [
  ['', 'cadena vacía'],
  ['   ', 'solo espacios'],
  ['ñÑáÁüÜ', 'solo diacríticos'],
  ['\t\n IGNICIÓN \r\n  ', 'tabulaciones y saltos de línea'],
];

let edgeOk = true;
for (const [input, description] of edgeCases) {
  try {
    const output = normalizeSearchText(input);
    if (typeof output !== 'string') {
      edgeOk = false;
      console.log(`        - "${description}" no retorna string`);
    }
  } catch (error) {
    edgeOk = false;
    console.log(`        - "${description}" lanzó: ${error.message}`);
  }
}
assertCondition(edgeOk, 'Casos límite (vacío, espacios, diacríticos, saltos) no lanzan errores y retornan string');

// El truncado es a 100 exactos incluso con diacríticos que NFD expande.
const accentsOverflow = 'á'.repeat(150);
assertCondition(
  normalizeSearchText(accentsOverflow).length <= SEARCH_MAX_LENGTH,
  `Con 150 tildes (NFD las expande), el resultado no excede 100 caracteres`
);

// matchesSearch: umbral de 2 caracteres del plan 5.1 (helper de vista).
assertCondition(
  matchesSearch('Chispa de Ignición', 'a') === true,
  'matchesSearch con 1 carácter no filtra (retorna true, plan 5.1)'
);
assertCondition(
  matchesSearch('Chispa de Ignición', 'ignicion') === true,
  'matchesSearch encuentra "ignicion" sin tilde en "Chispa de Ignición"'
);
assertCondition(
  matchesSearch('Manto de Niebla', 'ignicion') === false,
  'matchesSearch rechaza términos que no coinciden'
);

// --- Resumen final ---
console.log('\n== RESUMEN ==');
console.log(`Asertos superados: ${assertsPassed}`);
console.log(`Asertos fallidos:  ${assertsFailed}`);

if (assertsFailed === 0) {
  console.log("\nRESULTADO: EXITO — La Tarea 3.1 cumple su criterio 'Hecho cuando'.");
  process.exit(0);
}

console.log('\nRESULTADO: DENEGADO — Corregir los asertos marcados con [FALLA].');
process.exit(1);
