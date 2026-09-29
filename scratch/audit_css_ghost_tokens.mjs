/**
 * audit_css_ghost_tokens.mjs — Auditoría de tokens CSS fantasma (scratch).
 *
 * Hallazgo de SPEC-07b (corrección post-cierre 2026-09-28): una hoja de
 * componente consumía nombres de tokens inexistentes y sus fallbacks
 * oscurecían el texto sobre el modal. Este arnés recorre TODAS las hojas
 * del santuario, extrae las variables definidas (:root y cualquier
 * selector) y las consumidas (var(--x) y fallback var(--x, ...)), y
 * reporta las consumidas-no-definidas por hoja.
 *
 * Alcance: todas las hojas bajo public/assets/css (recursivo).
 * Sin dependencias (Dogma Vanilla).
 * Constitución: identificadores en inglés; comentarios en castellano.
 */

import { readFileSync, readdirSync, statSync } from 'node:fs';
import { join } from 'node:path';

const CSS_ROOT = 'public/assets/css';

/** Recolecta recursivamente todas las hojas .css. */
function collectCssFiles(dir) {
  const files = [];
  for (const entry of readdirSync(dir)) {
    const full = join(dir, entry);
    if (statSync(full).isDirectory()) {
      files.push(...collectCssFiles(full));
    } else if (entry.endsWith('.css')) {
      files.push(full);
    }
  }
  return files;
}

/** Quita comentarios /* ... *​/ para no leer tokens dentro de ellos. */
function stripComments(css) {
  return css.replace(/\/\*[\s\S]*?\*\//g, '');
}

/** Nombres de variables definidas en una hoja (--x: valor). */
function extractDefined(css) {
  const defined = new Set();
  const re = /(--[a-zA-Z0-9-]+)\s*:/g;
  let m;
  while ((m = re.exec(css)) !== null) defined.add(m[1]);
  return defined;
}

/** Nombres de variables consumidas con var(--x ...). */
function extractConsumed(css) {
  const consumed = new Set();
  const re = /var\(\s*(--[a-zA-Z0-9-]+)/g;
  let m;
  while ((m = re.exec(css)) !== null) consumed.add(m[1]);
  return consumed;
}

const files = collectCssFiles(CSS_ROOT);

// Las definiciones GLOBALES viven en tokens.css; cualquier hoja puede
// definir además variables locales que solo ella consume.
const globalDefined = new Set();
const perFile = files.map((file) => {
  const raw = readFileSync(file, 'utf8');
  const css = stripComments(raw);
  const defined = extractDefined(css);
  const consumed = extractConsumed(css);
  for (const name of defined) globalDefined.add(name);
  return { file, defined, consumed };
});

let totalGhosts = 0;
console.log('== AUDITORÍA DE TOKENS CSS FANTASMA ==\n');

/**
 * Contrato dinámico ratificado: variables que el JavaScript inyecta en
 * caliente (setProperty/setAttribute('style')) y que por diseño NO viven
 * en tokens.css. Cada una tiene su forjador verificado en el frontend.
 */
const DYNAMIC_CONTRACT = new Set([
  '--aura-color',            // elementalAuraComponent.js (setProperty)
  '--tremor-intensity',      // grimoireSimulatorView.js (setProperty)
  '--banner-tint',           // clanBannerComponent.js (style inline)
  '--lineage-banner',        // lineageCardComponent.js (style inline)
  '--avatar-image',          // userProfileBadge.js (style inline)
  '--convalescence-progress',// convalescenceBannerComponent.js (style inline)
  '--quota-fill',            // clanManagementComponent.js (style inline)
  '--occupancy-fill',        // clanView.js (style inline, auditoría 2026-09-28)
]);

for (const { file, defined, consumed } of perFile) {
  // Fantasma: consumida, no definida ni globalmente ni en su propia hoja,
  // y fuera del contrato dinámico ratificado.
  const ghosts = [...consumed]
    .filter((name) => !globalDefined.has(name) && !defined.has(name) && !DYNAMIC_CONTRACT.has(name))
    .sort();
  if (ghosts.length === 0) continue;
  totalGhosts += ghosts.length;
  console.log(`[FANTASMAS] ${file}`);
  for (const name of ghosts) console.log(`   - ${name}`);
}

// Chequeo adicional: variables definidas jamás consumidas (solo informe,
// no es defecto: pueden ser API de diseño reservada).
const consumedAnywhere = new Set(perFile.flatMap((f) => [...f.consumed]));
const neverConsumed = [...globalDefined].filter((n) => !consumedAnywhere.has(n)).sort();
if (neverConsumed.length > 0) {
  console.log('\n[INFORME] Definidas en tokens.css pero jamás consumidas en hojas:');
  for (const name of neverConsumed) console.log(`   - ${name}`);
}

console.log(`\n== RESUMEN == ${totalGhosts === 0 ? 'SIN tokens fantasma — todas las variables consumidas están definidas.' : `${totalGhosts} consumo(s) fantasma detectado(s).`}`);
process.exit(totalGhosts === 0 ? 0 : 1);
