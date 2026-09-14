/**
 * test_elemental_codex_css.mjs — Arnés TDD de la Tarea 3.4 (TASKS-06).
 *
 * Verifica la hoja del Códice Elemental
 * (public/assets/css/components/elemental-codex.css):
 *   [0] Superficie: la hoja existe y public/index.html la enlaza.
 *   [1] Paleta heráldica: los 8 elementos canónicos definen su color
 *       como variable del sistema (Códice, RF-01.1).
 *   [2] Dogma de tokens (Artículo IV): sin colores hex crudos; toda la
 *       hoja consume las variables de tokens.css (oro, pergamino, tinta).
 *   [3] Texto Flotante Monumental (RF-06.2): tipografía ceremonial
 *       (Cinzel vía token), oro rúnico, escala ×1.3 y animación de
 *       deflagración con degradación en movimiento reducido.
 *   [4] Contraste WCAG 2.1 AA (≥ 4.5:1) de los pares texto/fondo de la
 *       lámina del Códice y del acordeón móvil.
 *   [5] Filamentos animados (RF-01.2) y halo pulsante (RF-02.2)
 *       con anulación bajo prefers-reduced-motion (RNF-03).
 *   [6] Foco visible en los glifos (RNF-03).
 *
 * Criterio «Hecho cuando» (Tarea 3.4): todos los elementos visuales del
 * códice y del simulador aplican las variables del sistema de diseño con
 * contraste accesible WCAG 2.1 AA.
 *
 * Constitución:
 *   - Artículo I (Dogma Vanilla): CSS3 puro, variables nativas.
 *   - Artículo IV (Velo Arcano): pergamino, cuero y oro del grimorio.
 *   - Artículo V: clases kebab-case; comentarios en castellano.
 *
 * Uso: node scratch/test_elemental_codex_css.mjs
 */

import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const projectRoot = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const cssPath = path.join(projectRoot, 'public/assets/css/components/elemental-codex.css');
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

/** Mapa nombre→valor de las variables de tokens.css. */
function parseTokens(css) {
  const map = new Map();
  const re = /(--[a-z0-9-]+)\s*:\s*([^;]+);/g;
  let match;
  while ((match = re.exec(css)) !== null) {
    map.set(match[1], match[2].trim());
  }
  return map;
}

/** Resuelve una variable de tokens.css a su valor hex (dereferencias anidadas). */
function resolveToken(tokenMap, varName) {
  let value = tokenMap.get(varName);
  if (value === undefined) return null;
  let guard = 0;
  while (guard++ < 5) {
    const nested = value.match(/var\((--[a-z0-9-]+)\)/);
    if (!nested) break;
    const resolved = tokenMap.get(nested[1]);
    if (resolved === undefined) return null;
    value = value.replace(nested[0], resolved).trim();
  }
  const hex = value.match(/#[0-9a-fA-F]{3,8}/);
  return hex ? hex[0] : null;
}

const css = fs.readFileSync(cssPath, 'utf8');
const indexHtml = fs.readFileSync(indexPath, 'utf8');
const tokenMap = parseTokens(fs.readFileSync(tokensPath, 'utf8'));

console.log('\n== ARNÉS TDD — ESTILOS DEL CÓDICE (Tarea 3.4, SPEC-06) ==\n');

// -----------------------------------------------------------------------
// [0] Superficie
// -----------------------------------------------------------------------
console.log('[FASE 0] Superficie');
assertCondition(fs.existsSync(cssPath), 'public/assets/css/components/elemental-codex.css existe');
assertCondition(
  indexHtml.includes('elemental-codex.css'),
  'public/index.html enlaza la hoja del Códice'
);

// -----------------------------------------------------------------------
// [1] Paleta heráldica de los 8 elementos canónicos
// -----------------------------------------------------------------------
console.log('\n[FASE 1] Paleta heráldica de los 8 elementos (RF-01.1)');
const canonicalElements = ['fire', 'water', 'lightning', 'earth', 'wind', 'light', 'darkness', 'pureArcane'];
const paletteBlockMatch = css.match(/\.elemental-codex-palette\s*\{([\s\S]*?)\}/);
assertCondition(paletteBlockMatch !== null, 'Existe el bloque .elemental-codex-palette con la heráldica');
const paletteBlock = paletteBlockMatch ? paletteBlockMatch[1] : '';
for (const element of canonicalElements) {
  const varName = `--elemental-${element}`;
  const declared = paletteBlock.includes(varName);
  assertCondition(declared, `La heráldica declara ${varName}`);
  if (declared) {
    // Cada color heráldico bebe del sistema de diseño (tokens de afinidad).
    const value = paletteBlock.match(new RegExp(`${varName.replace(/-/g, '\\-')}\\s*:\\s*([^;]+);`));
    const referencesToken = value ? value[1].includes('var(--color-affinity') || value[1].includes('var(--color-gold') : false;
    assertCondition(referencesToken, `${varName} consume tokens del sistema de diseño`);
  }
}

// -----------------------------------------------------------------------
// [2] Dogma de tokens: cero hex crudos en la hoja
// -----------------------------------------------------------------------
console.log('\n[FASE 2] Dogma de tokens — cero hex crudos (Artículo IV)');
const rawHexMatches = css.match(/#[0-9a-fA-F]{3,8}\b/g) ?? [];
assertCondition(
  rawHexMatches.length === 0,
  `La hoja no contiene colores hex crudos (hallados: ${rawHexMatches.length})`
);
assertCondition(css.includes('var(--color-gold-arcane'), 'El oro ceremonial usa --color-gold-arcane');
assertCondition(css.includes('var(--color-parchment'), 'Las superficies usan tokens de pergamino');
assertCondition(css.includes('var(--color-text-'), 'Las tintas usan tokens de texto');

// -----------------------------------------------------------------------
// [3] Texto Flotante Monumental (RF-06.2)
// -----------------------------------------------------------------------
console.log('\n[FASE 3] Texto Flotante Monumental en oro rúnico (RF-06.2)');
assertCondition(css.includes('.elemental-combat-text'), 'Existe la clase .elemental-combat-text');
const monumentalBlock = css.match(/\.elemental-combat-text\s*\{([\s\S]*?)\}/)?.[1] ?? '';
assertCondition(
  monumentalBlock.includes('var(--font-arcane-title)'),
  'Tipografía ceremonial: Cinzel vía --font-arcane-title'
);
assertCondition(
  monumentalBlock.includes('var(--color-gold'),
  'Color oro rúnico desde el sistema de diseño'
);
const monumentalKeyframes = css.includes('@keyframes elemental-combat-text-rise');
assertCondition(monumentalKeyframes, 'Animación de deflagración (brote y ascenso) declarada');
const monumentalScale = css.match(/--combat-text-scale\s*:\s*([\d.]+)/);
assertCondition(
  monumentalScale !== null && Math.abs(parseFloat(monumentalScale[1]) - 1.3) < 0.001,
  'Escala monumental ×1.3 respecto a los impactos convencionales'
);
const reducedMotionBlock = css.match(/@media \(prefers-reduced-motion: reduce\)\s*\{([\s\S]*?)\n\}/)?.[1] ?? '';
assertCondition(
  reducedMotionBlock.includes('elemental-combat-text'),
  'Movimiento reducido (RNF-03): la deflagración monumental se anula'
);

// -----------------------------------------------------------------------
// [4] Contraste WCAG 2.1 AA de los pares texto/fondo
// -----------------------------------------------------------------------
console.log('\n[FASE 4] Contraste WCAG 2.1 AA (≥ 4.5:1)');
const textPairs = [
  {
    description: 'Lámina: título dorado sobre superficie de pergamino',
    text: resolveToken(tokenMap, '--color-gold-arcane'),
    background: resolveToken(tokenMap, '--color-parchment-surface'),
  },
  {
    description: 'Lámina: tinta principal sobre superficie de pergamino',
    text: resolveToken(tokenMap, '--color-text-primary'),
    background: resolveToken(tokenMap, '--color-parchment-surface'),
  },
  {
    description: 'Lámina: tinta secundaria sobre superficie de pergamino',
    text: resolveToken(tokenMap, '--color-text-secondary'),
    background: resolveToken(tokenMap, '--color-parchment-surface'),
  },
  {
    description: 'Lámina: tinta apagada (efecto) sobre superficie de pergamino',
    text: resolveToken(tokenMap, '--color-text-muted'),
    background: resolveToken(tokenMap, '--color-parchment-surface'),
  },
  {
    description: 'Acordeón: tinta principal sobre panel base',
    text: resolveToken(tokenMap, '--color-text-primary'),
    background: resolveToken(tokenMap, '--color-parchment-base'),
  },
  {
    description: 'Monumento: oro radiante sobre fondo de obsidiana',
    text: resolveToken(tokenMap, '--color-gold-bright'),
    background: resolveToken(tokenMap, '--color-bg-obsidian-surface'),
  },
];
for (const pair of textPairs) {
  if (!pair.text || !pair.background) {
    assertCondition(false, `${pair.description} (token irresoluble)`);
    continue;
  }
  const ratio = contrastRatio(pair.text, pair.background);
  assertCondition(ratio >= 4.5, `${pair.description}: ${ratio.toFixed(2)}:1`);
}

// -----------------------------------------------------------------------
// [5] Filamentos, halo y movimiento reducido (RNF-03)
// -----------------------------------------------------------------------
console.log('\n[FASE 5] Animaciones y movimiento reducido (RNF-03)');
assertCondition(css.includes('@keyframes elemental-filament-flow'), 'Flujo rúnico de filamentos declarado');
assertCondition(css.includes('@keyframes elemental-aura-pulse'), 'Pulso del halo declarado');
assertCondition(
  reducedMotionBlock.includes('elemental-aura-pulse') || css.includes('animation: none'),
  'Movimiento reducido: animaciones suspendidas'
);

// -----------------------------------------------------------------------
// [6] Foco visible (RNF-03)
// -----------------------------------------------------------------------
console.log('\n[FASE 6] Foco visible en glifos (RNF-03)');
assertCondition(
  css.includes(':focus-visible'),
  'Los glifos exhiben anillo de foco visible (:focus-visible)'
);

// -----------------------------------------------------------------------
// Resumen
// -----------------------------------------------------------------------
console.log(`\n== RESUMEN == Asertos superados: ${assertsPassed}, fallidos: ${assertsFailed}`);
if (assertsFailed === 0) {
  console.log('RESULTADO: ÉXITO — La hoja del Códice aplica el sistema de diseño con contraste AA (Tarea 3.4).');
  process.exit(0);
} else {
  console.log('RESULTADO: FALLO — La hoja incumple la Tarea 3.4.');
  process.exit(1);
}
