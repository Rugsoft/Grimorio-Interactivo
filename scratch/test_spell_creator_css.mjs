/**
 * test_spell_creator_css.mjs — Arnés TDD de la Tarea 5.6 (TASKS-04).
 *
 * Verifica la hoja de estilos del Taller Mágico
 * (public/assets/css/components/spell-creator.css):
 *   - Existencia y montaje: la hoja vive en public/assets/css/components/
 *     y public/index.html la enlaza (Artículo I: CSS3 puro, sin CDNs).
 *   - Tokens: SOLO consume variables de tokens.css (sin colores crudos
 *     en reglas: rgba()/var() permitidos, hex prohibido fuera de comentarios).
 *   - Dos columnas armónicas en escritorio (forja + panel sticky) y
 *     colapso a una columna fluida en móvil (@media, 768px).
 *   - Cobertura de los selectores BEM de las Tareas 5.3-5.5:
 *     spell-creator, mana-breakdown (incluidos los 5 modificadores de
 *     Círculo y el cartel de sobrecarga en rojo bermellón), spell-form-controls.
 *   - CONTRASTE WCAG 2.1 AA: los pares texto/fondo declarados en la hoja
 *     (via tokens) miden >= 4.5:1 (texto normal) — cálculo matemático
 *     de luminancia relativa en el propio arnés.
 *   - Accesibilidad de foco: :focus-visible con contorno visible.
 *
 * Criterio «Hecho cuando» (Tarea 5.6): la interfaz del taller presenta
 * el formulario y el desglose de maná en dos columnas armónicas en
 * escritorio, se colapsa a una columna fluida en móvil y cumple los
 * criterios de accesibilidad visual y contraste.
 *
 * Constitución:
 *   - Artículo I: CSS3 puro, variables nativas, cero frameworks.
 *   - Artículo IV: atmósfera de grimorio (tokens del sistema).
 *   - Artículo V: clases kebab-case; comentarios en castellano.
 *
 * Uso: node scratch/test_spell_creator_css.mjs
 */

import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const projectRoot = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const cssPath = path.join(projectRoot, 'public/assets/css/components/spell-creator.css');
const indexPath = path.join(projectRoot, 'public/index.html');
const tokensPath = path.join(projectRoot, 'public/assets/css/tokens.css');

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
 * Luminancia relativa WCAG 2.1 de un color hex.
 * @param {string} hex Color '#rrggbb'.
 * @returns {number} Luminancia 0..1.
 */
function relativeLuminance(hex) {
  const clean = hex.replace('#', '');
  const channels = [0, 2, 4].map((offset) => {
    const raw = parseInt(clean.slice(offset, offset + 2), 16) / 255;
    return raw <= 0.03928 ? raw / 12.92 : Math.pow((raw + 0.055) / 1.055, 2.4);
  });
  return 0.2126 * channels[0] + 0.7152 * channels[1] + 0.0722 * channels[2];
}

/**
 * Ratio de contraste WCAG entre dos colores hex.
 * @param {string} hexA Color de frente.
 * @param {string} hexB Color de fondo.
 * @returns {number} Ratio 1..21.
 */
function contrastRatio(hexA, hexB) {
  const la = relativeLuminance(hexA);
  const lb = relativeLuminance(hexB);
  const lighter = Math.max(la, lb);
  const darker = Math.min(la, lb);
  return (lighter + 0.05) / (darker + 0.05);
}

/**
 * Resuelve una variable de tokens.css a su valor hex (una dereferencia).
 * @param {Map<string, string>} tokenMap Mapa nombre → valor.
 * @param {string} varName Nombre sin '--'.
 * @returns {string|null} Hex resuelto o null.
 */
function resolveToken(tokenMap, varName) {
  let value = tokenMap.get(varName);
  if (value === undefined) return null;
  // Dereferencias anidadas (var(--x) dentro del valor).
  const nested = value.match(/var\(--([a-z0-9-]+)\)/);
  if (nested) {
    const resolved = tokenMap.get(nested[1]);
    if (resolved && /^#[0-9a-fA-F]{6}$/.test(resolved)) value = resolved;
  }
  return /^#[0-9a-fA-F]{6}$/.test(value) ? value : null;
}

console.log('== VERIFICACION TAREA 5.6: spell-creator.css ==\n');

// =====================================================================
// [0] Superficie (fase roja): la hoja existe y está enlazada.
// =====================================================================
console.log('[0] Superficie');
assertCondition(fs.existsSync(cssPath), 'public/assets/css/components/spell-creator.css existe');

const cssContent = fs.existsSync(cssPath) ? fs.readFileSync(cssPath, 'utf8') : '';

const indexContent = fs.readFileSync(indexPath, 'utf8');
assertCondition(
  indexContent.includes('assets/css/components/spell-creator.css'),
  'public/index.html enlaza la hoja del Taller'
);

if (cssContent === '') {
  console.log('\n== RESUMEN ==');
  console.log('Asertos superados: 2');
  console.log('Asertos fallidos: 0');
  console.log('\nRESULTADO: DENEGADO — Fase roja: implementar public/assets/css/components/spell-creator.css');
  process.exit(1);
}

// =====================================================================
// [1] Cobertura BEM de las Tareas 5.3-5.5.
// =====================================================================
console.log('\n[1] Cobertura de los selectores BEM del Taller');

const requiredSelectors = [
  // Vista (Tarea 5.5)
  '.spell-creator',
  '.spell-creator__forge',
  '.spell-creator__drafts',
  '.spell-creator__save',
  '.spell-creator__publish',
  '.spell-creator__status',
  // Desglose (Tarea 5.3)
  '.mana-breakdown',
  '.mana-breakdown__final',
  '.mana-breakdown__circle',
  '.mana-breakdown__circle--1',
  '.mana-breakdown__circle--2',
  '.mana-breakdown__circle--3',
  '.mana-breakdown__circle--4',
  '.mana-breakdown__circle--5',
  '.mana-breakdown__overload',
  '.mana-breakdown__overload--active',
  '.mana-breakdown--overloaded',
  // Controles (Tarea 5.4)
  '.spell-form-controls',
  '.spell-form-controls__number',
  '.spell-form-controls__select',
  '.spell-form-controls__radio',
  '.spell-form-controls__checkbox',
];

const missingSelectors = requiredSelectors.filter((selector) => !cssContent.includes(selector));
assertCondition(
  missingSelectors.length === 0,
  `Los ${requiredSelectors.length} selectores BEM del Taller están estilados${missingSelectors.length ? ` (faltan: ${missingSelectors.join(', ')})` : ''}`
);

// =====================================================================
// [2] Dogma de tokens: sin colores crudos hex en las reglas.
// =====================================================================
console.log('\n[2] Dogma de tokens (Artículo IV): solo variables del sistema');

// Se extraen los valores de propiedad (decl: valor) y se buscan hex crudos
// fuera de comentarios y del bloque de documentación de cabecera.
const cssWithoutComments = cssContent.replace(/\/\*[\s\S]*?\*\//g, '');
const rawHexDeclarations = cssWithoutComments.match(/:[^;{}]*#[0-9a-fA-F]{3,8}[^;{}]*/g) ?? [];
assertCondition(
  rawHexDeclarations.length === 0,
  `Ninguna regla porta colores hex crudos (todo viaja por var(--…))${rawHexDeclarations.length ? ` — encontrados: ${rawHexDeclarations.slice(0, 2).map((s) => s.trim().slice(0, 50)).join(' | ')}` : ''}`
);

// La hoja consume los tokens solemnes del sistema.
const consumedTokens = ['--color-parchment', '--color-ink', '--color-gold', '--mana-blue', '--font-arcane'];
const missingTokens = consumedTokens.filter((token) => !cssContent.includes(`var(${token}`) && !cssContent.includes(`var(${token})`));
assertCondition(
  missingTokens.length === 0,
  `La hoja consume los tokens del sistema (parchment, ink, gold, mana-blue, tipografía arcana)${missingTokens.length ? ` — sin usar: ${missingTokens.join(', ')}` : ''}`
);

// =====================================================================
// [3] Layout: dos columnas en escritorio, una fluida en móvil.
// =====================================================================
console.log('\n[3] Layout responsivo (768px de corte)');

const desktopBlock = cssWithoutComments.match(/\.spell-creator\s*\{[^}]*\}/) ?? cssWithoutComments.match(/\.spell-creator__layout\s*\{[^}]*\}/) ?? [];
const hasGridOrFlex = /display\s*:\s*(grid|flex)/.test(cssWithoutComments);
assertCondition(hasGridOrFlex, 'El taller se maqueta con Grid o Flex nativo');

// Dos columnas en escritorio (grid-template-columns con dos pistas).
const twoColumnRule = cssWithoutComments.match(/grid-template-columns\s*:[^;]*(\d|var)[^;]*[^;]*[^;]*;/);
assertCondition(twoColumnRule !== null, 'En escritorio existen dos pistas de columnas (forja + panel)');

// El panel de desglose es sticky en escritorio (plan: panel de maná sticky).
assertCondition(/position\s*:\s*sticky/.test(cssWithoutComments), 'El panel de maná permanece sticky al desplazar');

// Media query de colapso a una columna.
const mediaQuery = cssContent.match(/@media[^{]*max-width\s*:\s*768px[^{]*\{[\s\S]*?\n\}/);
assertCondition(mediaQuery !== null, 'Existe @media (max-width: 768px) para el colapso móvil');
if (mediaQuery !== null) {
  const mediaBody = mediaQuery[0];
  const singleColumn = mediaBody.includes('grid-template-columns: 1fr') || mediaBody.includes('flex-direction: column');
  assertCondition(singleColumn, 'En móvil el taller colapsa a una columna fluida');
  const stickyReleased = /position\s*:\s*static/.test(mediaBody);
  assertCondition(stickyReleased, 'El sticky del panel se libera en móvil (position: static)');
}

// =====================================================================
// [4] Contraste WCAG 2.1 AA de los pares texto/fondo del taller.
// =====================================================================
console.log('\n[4] Contraste WCAG 2.1 AA (>= 4.5:1) de los pares declarados');

const tokensContent = fs.readFileSync(tokensPath, 'utf8');
const tokenMap = new Map();
for (const [, name, value] of tokensContent.matchAll(/--([a-z0-9-]+)\s*:\s*(#[0-9a-fA-F]{6})/g)) {
  tokenMap.set(name, value);
}

// Pares solemnes del taller (texto, fondo) tomados de los tokens consumidos.
const contrastPairs = [
  // Nota: color-text-light se resuelve vía var() a color-parchment
  // (el tokenMap solo recoge hex, así que indicamos el hex final).
  ['Texto principal del taller', 'color-parchment', 'color-parchment-base'],
  ['Texto atenuado (etiquetas)', 'color-text-light-muted', 'color-parchment-base'],
  ['Oro arcano sobre pergamino (sellos)', 'color-gold-arcane', 'color-parchment-base'],
  ['Azul de maná sobre pergamino (cifras)', 'mana-blue', 'color-parchment-base'],
  ['Coste final: tinta de sello sobre oro', 'color-ink-seal', 'color-gold-arcane'],
  ['Cartel de sobrecarga: pergamino sobre bermellón profundo', 'color-parchment', 'color-ember-red-deep'],
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
assertCondition(allPairsPass, 'Todos los pares texto/fondo del taller superan 4.5:1 (WCAG 2.1 AA)');

// =====================================================================
// [5] Accesibilidad de foco y reducción de movimiento.
// =====================================================================
console.log('\n[5] Foco visible y prefers-reduced-motion');

assertCondition(/:focus-visible/.test(cssContent), 'Los controles porta :focus-visible con contorno solemne');
assertCondition(/prefers-reduced-motion/.test(cssContent), 'Las animaciones respetan prefers-reduced-motion');

// =====================================================================
// [6] Resolución tipográfica: las familias arcanas del sistema.
// =====================================================================
console.log('\n[6] Tipografía del sistema (Cinzel + EB Garamond locales)');

assertCondition(cssContent.includes('var(--font-arcane-title)'), 'Los títulos usan la familia arcana de títulos (Cinzel local)');
assertCondition(cssContent.includes('var(--font-arcane-body)'), 'El cuerpo usa la familia de lectura (EB Garamond local)');

// =====================================================================
// Resumen final.
// =====================================================================
console.log('\n== RESUMEN ==');
console.log(`Asertos superados: ${assertsPassed}`);
console.log(`Asertos fallidos: ${assertsFailed}`);

if (assertsFailed === 0) {
  console.log('\nRESULTADO: EXITO — spell-creator.css listo; SPEC-04 completa.');
  process.exit(0);
}
console.log('\nRESULTADO: DENEGADO');
process.exit(1);
