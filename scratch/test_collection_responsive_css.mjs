/**
 * test_collection_responsive_css.mjs — Arnés responsive de la Tarea 6.2
 * (TASKS-11).
 *
 * Valida contra el «Hecho cuando» de la tarea:
 *
 *   [1] La hoja grimoire-collection.css existe y está enlazada en el
 *       shell (index.html).
 *   [2] A 360 px de ancho la vista no desborda: las reglas del bloque
 *       @media (max-width: 480px) fuerzan columna única, filtro líquido
 *       y padding contenido — cada regla crítica se audita sobre una
 *       base de tokens resuelta.
 *   [3] Cero literales de color en la hoja (SPEC-02 RF-08: solo tokens).
 *   [4] prefers-reduced-motion aquienta las transiciones y el eco de
 *       sellado/elogio (RNF-05).
 *   [5] Los gestos y el modal son operables: min-height de blanco táctil
 *       (--touch-target-min) en botones del tomo (RNF-04).
 *
 * Constitución:
 *   - Artículo I (Dogma Vanilla): ES Modules nativos, sin dependencias.
 *   - Artículo V: asertos en inglés, comentarios en castellano.
 *
 * Uso: node scratch/test_collection_responsive_css.mjs
 */

import { readFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

let assertsPassed = 0;
let assertsFailed = 0;

function assertCondition(condition, message) {
  if (condition) {
    assertsPassed += 1;
    console.log(`  [PASA] ${message}`);
  } else {
    assertsFailed += 1;
    console.log(`  [FALLA] ${message}`);
  }
}

// Raíz del proyecto anclada al PROPIO módulo: el arnés ha de juzgar las
// mismas rutas se invoque desde donde se invoque (un lote que lo ejecute
// desde scratch/ jamás ha de dar un falso rojo por cwd).
const projectRoot = join(dirname(fileURLToPath(import.meta.url)), '..');
const STYLESHEET = join(projectRoot, 'public/assets/css/components/grimoire-collection.css');
const SHELL = join(projectRoot, 'public/index.html');
const TOKENS = join(projectRoot, 'public/assets/css/tokens.css');

const css = readFileSync(STYLESHEET, 'utf8');
const shell = readFileSync(SHELL, 'utf8');
const tokens = readFileSync(TOKENS, 'utf8');

// ---------------------------------------------------------------------
// [1] Hoja presente y enlazada
// ---------------------------------------------------------------------
console.log('[1] Hoja enlazada en el shell');
assertCondition(shell.includes('components/grimoire-collection.css'),
  'grimoire-collection.css está enlazada en index.html');
assertCondition(css.includes('.collection-view') && css.includes('.discard-tome-modal'),
  'la hoja viste la vista del tomo y el modal de retirada');

// ---------------------------------------------------------------------
// [2] Responsive 360 px: reglas críticas del bloque @media (max-width: 480px)
// ---------------------------------------------------------------------
console.log('\n[2] Responsive: sin desbordes a 360 px');
const mediaMatch = /@media \(max-width: 480px\)\s*\{([\s\S]*?)\n\}/.exec(css);
assertCondition(mediaMatch !== null, 'existe el bloque @media (max-width: 480px)');
if (mediaMatch) {
  const mobileBlock = mediaMatch[1];
  assertCondition(/\.collection-view__entries\s*\{[^}]*grid-template-columns:\s*1fr/.test(mobileBlock),
    'a 360 px las entradas colapsan a columna única (sin desborde horizontal)');
  assertCondition(/\.collection-view__filter\s*\{[^}]*width:\s*100%/.test(mobileBlock),
    'a 360 px el filtro ocupa el ancho íntegro (líquido)');
  assertCondition(/\.collection-view\s*\{[^}]*padding:\s*var\(--space-ink-3\)/.test(mobileBlock),
    'a 360 px el padding se contiene (sin empujar el layout)');
  assertCondition(/\.discard-tome-modal__actions\s*\{[^}]*flex-direction:\s*column-reverse/.test(mobileBlock),
    'a 360 px las acciones del modal se apilan (la segura, última en el foco)');
  assertCondition(/\.discard-tome-modal__actions \.button\s*\{[^}]*width:\s*100%/.test(mobileBlock),
    'a 360 px los botones del modal ocupan el ancho íntegro');
}
// La rejilla de escritorio tiene un mínimo por columna menor que 360 px.
assertCondition(/grid-template-columns:\s*repeat\(auto-fill,\s*minmax\(260px,\s*1fr\)\)/.test(css),
  'la rejilla de escritorio reparte en columnas de mínimo 260 px (360 px caben una)');

// ---------------------------------------------------------------------
// [3] Cero literales de color fuera de tokens (SPEC-02, RF-08)
// ---------------------------------------------------------------------
console.log('\n[3] Cero literales de color (solo tokens de SPEC-02)');
const colorLiteralPattern = /(?<!var\()(?:#[0-9a-fA-F]{3,8}\b|rgba?\([^)]*\)|hsla?\([^)]*\))/g;
const colorHits = [...css.matchAll(colorLiteralPattern)]
  // La única excepción canónica del proyecto: el velo del ::backdrop
  // (components.css usa el mismo literal ratificado).
  .filter((hit) => !/backdrop/.test(css.slice(Math.max(0, hit.index - 200), hit.index)));
assertCondition(colorHits.length === 0,
  'la hoja no porta literales de color (todo viaja como Custom Property)'
    + (colorHits.length > 0 ? ` — ${colorHits.length} hallados` : ''));

// Los tokens usados existen en tokens.css. La clase de caracteres
// admite dígitos: los tokens de escala del proyecto (space-ink-4,
// font-size-ink-small...) los portan (hallazgo del arnés).
const usedTokens = new Set([...css.matchAll(/var\((--[a-z][a-z0-9-]*)\)/g)].map((m) => m[1]));
const definedTokens = new Set([...tokens.matchAll(/(--[a-z][a-z0-9-]*)\s*:/g)].map((m) => m[1]));
const unknownTokens = [...usedTokens].filter((t) => !definedTokens.has(t));
assertCondition(unknownTokens.length === 0,
  'cada token usado está definido en tokens.css'
    + (unknownTokens.length > 0 ? ` — faltan: ${unknownTokens.join(', ')}` : ''));

// ---------------------------------------------------------------------
// [4] prefers-reduced-motion (RNF-05)
// ---------------------------------------------------------------------
console.log('\n[4] Movimiento reducido para sellado y eco de elogio');
const reducedMatch = /@media \(prefers-reduced-motion: reduce\)\s*\{([\s\S]*?)\n\}/.exec(css);
assertCondition(reducedMatch !== null, 'existe el bloque @media (prefers-reduced-motion: reduce)');
if (reducedMatch) {
  const reducedBlock = reducedMatch[1];
  assertCondition(reducedBlock.includes('.spell-card__tome-gesture'),
    'los gestos de sellado/elogio se aquientan bajo movimiento reducido');
  assertCondition(reducedBlock.includes('.arcane-echo'),
    'el eco del acto reanudado se aquienta (sin animación de entrada)');
  assertCondition(/transition:\s*none/.test(reducedBlock) && /animation:\s*none/.test(reducedBlock),
    'transiciones y animaciones cesan íntegras en el bloque');
}
// La animación de entrada del eco existe y por tanto se aquienta con ella.
assertCondition(css.includes('@keyframes arcane-echo-breathe'),
  'el eco porta su animación canónica (aquietada bajo reduced-motion)');

// ---------------------------------------------------------------------
// [5] Blanco táctil y foco visible (RNF-04)
// ---------------------------------------------------------------------
console.log('\n[5] Operabilidad: blanco táctil y foco visible');
assertCondition(/\.spell-card__tome-gesture\s*\{[^}]*min-height:\s*var\(--touch-target-min\)/.test(css),
  'los gestos del tomo respetan el blanco táctil mínimo');
assertCondition(/\.spell-card__tome-gesture:focus-visible/.test(css),
  'los gestos del tomo portan foco visible');
assertCondition(/\.collection-view__page\s*\{[^}]*min-height:\s*var\(--touch-target-min\)/.test(css),
  'la paginación respeta el blanco táctil mínimo');
assertCondition(/\.collection-view__filter:focus-visible/.test(css),
  'el filtro porta foco visible');
assertCondition(/\.discard-tome-modal__close:focus-visible/.test(css),
  'el cierre del modal porta foco visible');

// ---------------------------------------------------------------------
// Veredicto
// ---------------------------------------------------------------------
console.log(`\n=== VEREDICTO: ${assertsPassed}/${assertsPassed + assertsFailed} asertos en verde ===`);
if (assertsFailed > 0) process.exit(1);
console.log('\nRESULTADO: EXITO — La Tarea 6.2 cumple su criterio «Hecho cuando».');
process.exit(0);
