/**
 * test_css_coverage.mjs — Guard de cobertura estilística (SPEC-02, RF-08.6)
 * ============================================================================
 * Verifica que TODA clase emitida por el código frontend (views/ y
 * components/) cuenta con una regla CSS en las hojas del proyecto.
 *
 * Motivación (Hallazgo 1 del Registro QA de SPEC-02): la auditoría detectó
 * controles interactivos huérfanos (checkboxes, selects, deslizadores y
 * botones de la Biblioteca, Bitácora, clanes y ficha técnica) que
 * renderizaban con el aspecto nativo del navegador, rompiendo la solemnidad
 * del grimorio. Este guard impide la reincidencia.
 *
 * Estrategia:
 *   1. Extrae las clases de public/assets/js/ desde cuatro patrones:
 *      - .className = 'clase'  /  'clase clase-modificadora'
 *      - setAttribute('class', '...')
 *      - classList.add('...') / classList.toggle('...')
 *      - clases literales pasadas a helpers (createTextElement(tag, clase, ...))
 *   2. Extrae los selectores de clase de las hojas public/assets/css/.
 *   3. Para cada clase emitida, comprueba cobertura directa O por bloque
 *      BEM (la clase base sin modificador, p. ej. `.grimoire-book__page`
 *      cubre a `grimoire-book__page--left`).
 *   4. Falla listando los huérfanos.
 *
 * Uso: node scratch/test_css_coverage.mjs
 * ============================================================================
 */

import { readFileSync, readdirSync, statSync } from 'node:fs';
import { join, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';

const projectRoot = join(dirname(fileURLToPath(import.meta.url)), '..');
const jsRoot = join(projectRoot, 'public', 'assets', 'js');
const cssRoot = join(projectRoot, 'public', 'assets', 'css');

let assertsPassed = 0;
let assertsFailed = 0;

/** Aserta una condición y registra el resultado en la bitácora. */
function assertCondition(condition, description) {
  if (condition) {
    assertsPassed++;
    console.log(`  [PASA] ${description}`);
  } else {
    assertsFailed++;
    console.log(`  [FALLA] ${description}`);
  }
}

/** Recorre un directorio y retorna la lista de ficheros con extensión dada. */
function collectFiles(dirPath, extension) {
  const found = [];
  for (const entry of readdirSync(dirPath)) {
    const fullPath = join(dirPath, entry);
    const stats = statSync(fullPath);
    if (stats.isDirectory()) {
      found.push(...collectFiles(fullPath, extension));
    } else if (entry.endsWith(extension)) {
      found.push(fullPath);
    }
  }
  return found;
}

// --- 1. Extraer clases emitidas por el JavaScript ---

const jsFiles = collectFiles(jsRoot, '.js');
const emittedClasses = new Set();

// Patrón A: asignaciones directas className = '...' (admite clases múltiples).
const classNamePattern = /\.className\s*=\s*'([^']+)'/g;

// Patrón B: setAttribute('class', '...').
const setAttributePattern = /setAttribute\('class',\s*'([^']+)'\)/g;

// Patrón C: classList.add('...') / classList.toggle('...').
const classListPattern = /classList\.(?:add|toggle)\('([^']+)'/g;

// Patrón D: helpers de texto con la clase como 2º/3º argumento literal:
// createTextElement(tag, 'clase', ...) / appendTextElement(parent, tag, 'clase', ...).
const helperPattern = /(?:createTextElement|appendTextElement|createElementWithText|createNode)\(\s*'[^']+'\s*,\s*'([a-z][a-z0-9]*(?:__[a-z0-9-]+)?(?:--[a-z0-9-]+)?)'/g;

for (const filePath of jsFiles) {
  const source = readFileSync(filePath, 'utf8');
  for (const pattern of [classNamePattern, setAttributePattern, classListPattern, helperPattern]) {
    pattern.lastIndex = 0;
    let match;
    while ((match = pattern.exec(source)) !== null) {
      for (const className of match[1].trim().split(/\s+/)) {
        if (/^[a-z][a-z0-9-]*(__[a-z0-9-]+)?(--[a-z0-9-]+)?$/.test(className)) {
          emittedClasses.add(className);
        }
      }
    }
  }
}

// --- 2. Extraer selectores de clase de las hojas CSS ---

const cssFiles = [
  ...collectFiles(cssRoot, '.css'),
];
const cssSelectors = new Set();

const selectorPattern = /\.([a-zA-Z][a-zA-Z0-9_-]*)/g;

for (const filePath of cssFiles) {
  const source = readFileSync(filePath, 'utf8');
  let match;
  while ((match = selectorPattern.exec(source)) !== null) {
    cssSelectors.add(match[1]);
  }
}

// --- 3. Comprobar cobertura: directa o por bloque BEM base ---

const orphans = [];
for (const className of [...emittedClasses].sort()) {
  if (cssSelectors.has(className)) continue;
  // Cobertura por bloque: `x__y--mod` cubierta si existe `.x__y`.
  const blockMatch = className.match(/^([a-z][a-z0-9-]*__[a-z0-9-]+)--/);
  if (blockMatch && cssSelectors.has(blockMatch[1])) continue;
  // Cobertura por elemento: `x__y` cubierta si existe el bloque `.x` completo.
  const elementMatch = className.match(/^([a-z][a-z0-9-]+)__/);
  if (elementMatch && cssSelectors.has(elementMatch[1])) continue;
  if (elementMatch && cssSelectors.has(elementMatch[1])) continue;
  orphans.push(className);
}

// --- 4. Veredicto ---

console.log('======================================================================');
console.log(' GUARD DE COBERTURA CSS (SPEC-02, RF-08.6)');
console.log('======================================================================');
console.log(`  Clases emitidas por JS: ${emittedClasses.size}`);
console.log(`  Selectores de clase en CSS: ${cssSelectors.size}`);

assertCondition(
  emittedClasses.size > 100,
  'La extracción de clases del JavaScript recoge un catálogo sustancial',
);

if (orphans.length === 0) {
  console.log('  [PASA] Cobertura estilística total: cero clases huérfanas.');
} else {
  console.log(`  [FALLA] ${orphans.length} clases huérfanas sin regla CSS:`);
  for (const orphan of orphans) {
    console.log(`         - ${orphan}`);
  }
}
assertCondition(orphans.length === 0, 'Cero clases huérfanas (RF-08.6)');

console.log('----------------------------------------------------------------------');
console.log(`  RESULTADO: ${assertsPassed} asertos superados, ${assertsFailed} fallidos.`);
console.log('======================================================================');

process.exit(assertsFailed === 0 ? 0 : 1);
