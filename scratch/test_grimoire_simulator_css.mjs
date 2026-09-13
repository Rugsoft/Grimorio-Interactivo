/**
 * test_grimoire_simulator_css.mjs — Arnés TDD de la Tarea 4.4 (TASKS-05).
 *
 * Verifica la hoja de estilos del Tomo del Simulador
 * (public/assets/css/components/grimoire-simulator.css):
 *   [0] Superficie: la hoja existe y public/index.html la enlaza.
 *   [1] Cobertura BEM de los componentes de las Tareas 3.1-3.3 y 4.2:
 *       grimoire-book (doble página, flechas, índice rúnico, pergamino
 *       virgen), combat-dummy (barra y barrera) y arcane-canvas.
 *   [2] Dogma de tokens (Artículo IV): sin colores hex crudos; consume
 *       los tokens del sistema (pergamino, tinta, oro, maná, tipografía).
 *   [3] Doble página en escritorio (RF-01.1) y colapso a una columna con
 *       pliegue alternable en móvil (Caso Límite 5, 768px).
 *   [4] Contraste WCAG 2.1 AA (≥ 4.5:1) de los pares texto/fondo.
 *   [5] Foco visible y prefers-reduced-motion (RNF-03).
 *   [6] Tipografía arcana local (Cinzel + EB Garamond vía tokens).
 *   [7] Sin desbordamientos: min-width: 0 en pistas de rejilla y
 *       overflow controlado (criterio «sin saltos visuales»).
 *
 * Criterio «Hecho cuando» (Tarea 4.4): la interfaz se maqueta como un
 * libro abierto a doble página en escritorio y se adapta a una columna
 * con pliegue alternable en móviles sin desbordamientos ni saltos.
 *
 * Constitución:
 *   - Artículo I: CSS3 puro, variables nativas, cero frameworks/CDNs.
 *   - Artículo IV: atmósfera de pergamino, cuero y oro del grimorio.
 *   - Artículo V: clases kebab-case; comentarios en castellano.
 *
 * Uso: node scratch/test_grimoire_simulator_css.mjs
 */

import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const projectRoot = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const cssPath = path.join(projectRoot, 'public/assets/css/components/grimoire-simulator.css');
const indexPath = path.join(projectRoot, 'public/index.html');
const tokensPath = path.join(projectRoot, 'public/assets/css/tokens.css');

let assertsPassed = 0;
let assertsFailed = 0;

/** Aserta una condición y registra el resultado. */
function assertCondition(condition, description) {
  if (condition) {
    assertsPassed++;
    console.log(`  [PASA] ${description}`);
  } else {
    assertsFailed++;
    console.log(`  [FALLA] ${description}`);
  }
}

/** Luminancia relativa WCAG 2.1 de un color hex. */
function relativeLuminance(hex) {
  const clean = hex.replace('#', '');
  const channels = [0, 2, 4].map((offset) => {
    const raw = parseInt(clean.slice(offset, offset + 2), 16) / 255;
    return raw <= 0.03928 ? raw / 12.92 : Math.pow((raw + 0.055) / 1.055, 2.4);
  });
  return 0.2126 * channels[0] + 0.7152 * channels[1] + 0.0722 * channels[2];
}

/** Ratio de contraste WCAG entre dos colores hex. */
function contrastRatio(hexA, hexB) {
  const la = relativeLuminance(hexA);
  const lb = relativeLuminance(hexB);
  const lighter = Math.max(la, lb);
  const darker = Math.min(la, lb);
  return (lighter + 0.05) / (darker + 0.05);
}

/** Resuelve una variable de tokens.css a su valor hex (una dereferencia). */
function resolveToken(tokenMap, varName) {
  let value = tokenMap.get(varName);
  if (value === undefined) return null;
  const nested = value.match(/var\(--([a-z0-9-]+)\)/);
  if (nested) {
    const resolved = tokenMap.get(nested[1]);
    if (resolved && /^#[0-9a-fA-F]{6}$/.test(resolved)) value = resolved;
  }
  return /^#[0-9a-fA-F]{6}$/.test(value) ? value : null;
}

console.log('== ARNÉS TDD — Tarea 4.4: Estilos del Tomo del Grimorio ==\n');

// =====================================================================
// [0] Superficie (fase roja)
// =====================================================================
console.log('[0] Superficie');
assertCondition(fs.existsSync(cssPath), 'public/assets/css/components/grimoire-simulator.css existe');

const cssContent = fs.existsSync(cssPath) ? fs.readFileSync(cssPath, 'utf8') : '';
const indexContent = fs.readFileSync(indexPath, 'utf8');
assertCondition(
  indexContent.includes('assets/css/components/grimoire-simulator.css'),
  'public/index.html enlaza la hoja del Tomo'
);

if (cssContent === '') {
  console.log('\n== RESUMEN ==');
  console.log('Asertos superados: 1, fallidos: 1');
  console.log('RESULTADO: FALLO — Fase roja: implementar la hoja del Tomo.');
  process.exit(1);
}

const cssWithoutComments = cssContent.replace(/\/\*[\s\S]*?\*\//g, '');

// =====================================================================
// [1] Cobertura BEM de los componentes del simulador
// =====================================================================
console.log('\n[1] Cobertura BEM de los componentes');

const requiredSelectors = [
  // Tomo (Tarea 4.2, RF-01.1/01.4)
  '.grimoire-book',
  '.grimoire-book__page',
  '.grimoire-book__page--left',
  '.grimoire-book__page--right',
  '.grimoire-book__arrow',
  '.grimoire-book__arrow--prev',
  '.grimoire-book__arrow--next',
  '.grimoire-book__rune-index',
  '.grimoire-book__circle-index',
  '.grimoire-book__circle-option',
  '.grimoire-book__element-index',
  '.grimoire-book__element-option',
  '.grimoire-book__filter-clear',
  '.grimoire-book__empty',
  '.grimoire-book__empty-legend',
  '.grimoire-book__face--hidden',
  '.grimoire-book__spell-name',
  '.grimoire-book__spell-formula',
  '.grimoire-book__spell-mana',
  '.grimoire-book__camera-slot',
  // Maniquí (Tarea 3.1)
  '.combat-dummy__figure',
  '.combat-dummy__health-bar',
  '.combat-dummy__health-fill',
  '.combat-dummy__barrier-badge',
  '.combat-dummy__status-legend',
  // Lienzo (Tarea 3.3) e interfaz adicional
  '.arcane-canvas',
  '.grimoire-simulator__logbook',
  '.grimoire-simulator__controls',
];
const missingSelectors = requiredSelectors.filter((selector) => !cssContent.includes(selector));
assertCondition(
  missingSelectors.length === 0,
  `Los ${requiredSelectors.length} selectores BEM del simulador están estilados${missingSelectors.length ? ` (faltan: ${missingSelectors.join(', ')})` : ''}`
);

// =====================================================================
// [2] Dogma de tokens (Artículo IV)
// =====================================================================
console.log('\n[2] Dogma de tokens (sin colores crudos)');

const rawHexDeclarations = cssWithoutComments.match(/:[^;{}]*#[0-9a-fA-F]{3,8}[^;{}]*/g) ?? [];
assertCondition(
  rawHexDeclarations.length === 0,
  `Ninguna regla porta colores hex crudos (todo viaja por var(--…))${rawHexDeclarations.length ? ` — encontrados: ${rawHexDeclarations.slice(0, 2).map((s) => s.trim().slice(0, 60)).join(' | ')}` : ''}`
);

const consumedTokens = ['--color-parchment', '--color-ink', '--color-gold-arcane', '--mana-blue-readable', '--font-arcane-title', '--font-arcane-body'];
const missingTokens = consumedTokens.filter((token) => !cssContent.includes(`var(${token})`) && !cssContent.includes(`var(${token},`));
assertCondition(
  missingTokens.length === 0,
  `La hoja consume los tokens del sistema${missingTokens.length ? ` — sin usar: ${missingTokens.join(', ')}` : ''}`
);

// =====================================================================
// [3] Doble página en escritorio y pliegue móvil
// =====================================================================
console.log('\n[3] Layout: doble página y pliegue móvil');

assertCondition(/display\s*:\s*(grid|flex)/.test(cssWithoutComments), 'El tomo usa Grid o Flex nativo');
assertCondition(
  /grid-template-columns\s*:[^;]*1fr[^;]*1fr/.test(cssWithoutComments) || /grid-template-columns\s*:[^;]*repeat\(\s*2/.test(cssWithoutComments),
  'En escritorio el tomo abre DOS páginas (dos pistas de columna)'
);
assertCondition(/min-width\s*:\s*0/.test(cssWithoutComments), 'Las pistas declaran min-width: 0 (sin desbordamientos)');

const mediaQuery = cssContent.match(/@media[^{]*max-width\s*:\s*768px[^{]*\{[\s\S]*?\n\}/);
assertCondition(mediaQuery !== null, 'Existe @media (max-width: 768px) para el pliegue móvil');
if (mediaQuery !== null) {
  const mediaBody = mediaQuery[0];
  const singleColumn = /grid-template-columns\s*:\s*1fr/.test(mediaBody) || /flex-direction\s*:\s*column/.test(mediaBody);
  assertCondition(singleColumn, 'En móvil el tomo colapsa a una sola columna');
  assertCondition(/grimoire-book__page--left/.test(mediaBody) && /grimoire-book__page--right/.test(mediaBody), 'El pliegue gobierna ambas láminas (izquierda y cámara)');
  assertCondition(
    /display\s*:\s*none/.test(mediaBody) || /grimoire-book__page--hidden/.test(mediaBody) || /--folded/.test(mediaBody),
    'El pliegue oculta alternadamente la lámina inactiva (conmutación mágica)'
  );
}
assertCondition(/fold|pliegue/i.test(cssContent), 'El pliegue móvil está documentado en la hoja (Caso Límite 5)');

// =====================================================================
// [4] Contraste WCAG 2.1 AA
// =====================================================================
console.log('\n[4] Contraste WCAG 2.1 AA (>= 4.5:1)');

const tokensContent = fs.readFileSync(tokensPath, 'utf8');
const tokenMap = new Map();
for (const [, name, value] of tokensContent.matchAll(/--([a-z0-9-]+)\s*:\s*(#[0-9a-fA-F]{6})/g)) {
  tokenMap.set(name, value);
}

const contrastPairs = [
  ['Nombre del conjuro sobre pergamino oscuro', 'color-parchment', 'color-parchment-base'],
  ['Metadatos atenuados', 'color-text-light-muted', 'color-parchment-base'],
  ['Fórmula litúrgica en oro arcano', 'color-gold-arcane', 'color-parchment-base'],
  // Auditoría a nivel de componente: el texto de maná descansa sobre la
  // SUPERFICIE del pergamino (--color-parchment-surface), no sobre la base;
  // contra ella el azul canónico solo alcanza 4.28:1, de ahí la variante
  // legible que exige RNF-03.
  ['Coste de maná en azul legible', 'mana-blue-readable', 'color-parchment-surface'],
  ['Rótulos de barrera sobre pergamino', 'color-parchment', 'color-parchment-surface'],
  ['Cartel de sobrecarga sobre bermellón profundo', 'color-parchment', 'color-ember-red-deep'],
];

let allPairsPass = true;
for (const [legend, foregroundToken, backgroundToken] of contrastPairs) {
  const foreground = resolveToken(tokenMap, foregroundToken);
  const background = resolveToken(tokenMap, backgroundToken);
  if (foreground === null || background === null) {
    allPairsPass = false;
    console.log(`       ↳ token irresoluble: ${legend} (${foregroundToken}/${backgroundToken})`);
    continue;
  }
  const ratio = contrastRatio(foreground, background);
  const passes = ratio >= 4.5;
  if (!passes) allPairsPass = false;
  console.log(`       · ${legend}: ${ratio.toFixed(2)}:1 ${passes ? 'OK' : 'FALLA'}`);
}
assertCondition(allPairsPass, 'Todos los pares texto/fondo superan 4.5:1 (WCAG 2.1 AA)');

// =====================================================================
// [5] Foco visible y movimiento reducido
// =====================================================================
console.log('\n[5] Accesibilidad de foco y reduced-motion');

assertCondition(/:focus-visible/.test(cssContent), 'Los controles portan :focus-visible con contorno solemne');
assertCondition(/prefers-reduced-motion/.test(cssContent), 'Las animaciones respetan prefers-reduced-motion');

// =====================================================================
// [6] Tipografía arcana local
// =====================================================================
console.log('\n[6] Tipografía del sistema');

assertCondition(cssContent.includes('var(--font-arcane-title)'), 'Los títulos usan la familia arcana local (Cinzel)');
assertCondition(cssContent.includes('var(--font-arcane-body)'), 'El cuerpo usa la familia de lectura (EB Garamond)');

// =====================================================================
// Resumen
// =====================================================================
console.log('\n== RESUMEN ==');
console.log(`Asertos superados: ${assertsPassed}, fallidos: ${assertsFailed}`);
if (assertsFailed === 0) {
  console.log('RESULTADO: EXITO — Estilos del Tomo listos (Tarea 4.4).');
  process.exit(0);
}
console.log('RESULTADO: FALLO — hay asertos incumplidos.');
process.exit(1);
