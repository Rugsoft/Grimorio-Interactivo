/**
 * test_clan_heraldry_css.mjs — Arnés de la Tarea 6.1 (TASKS-07).
 *
 * Verifica sobre `public/assets/css/components/clan-heraldry.css` y
 * `public/assets/css/components/lineage-hall.css` el criterio «Hecho cuando»:
 *   «Los componentes heráldicos se adaptan responsive a móvil y escritorio, y
 *    los conjuros del Clan Regente lucen el ribete dorado brillante
 *    ceremonial.»
 *
 * Fases:
 *   [0]  Superficie: las dos hojas existen y el shell las enlaza en orden.
 *   [1]  Paleta heráldica: los 8 linajes leen su estandarte de la matriz
 *        elemental de tokens.css (paridad con el canon del backend).
 *   [2]  Los 8 marcos heráldicos canónicos, con paridad EXACTA con
 *        `LineageSyncergyService::CANONICAL_LINEAGES` y motivo distintivo.
 *   [3]  Estandarte del linaje (RF-02.2).
 *   [4]  Brillo dorado del Clan Regente (RF-04.4).
 *   [5]  Ribete dorado de los conjuros del clan soberano (RF-04.4).
 *   [6]  El Salón de los Linajes: pabellón, pestañas, filtros, podio,
 *        histórico y Libro Mayor.
 *   [7]  Contraste WCAG 2.1 AA (≥ 4.5:1) de los pares declarados (RNF-03).
 *   [8]  Adaptación responsive a móvil y escritorio (RNF-03).
 *   [9]  Dogma Vanilla, foco visible y movimiento reducido (Artículo I, RNF-03).
 *   [10] Integración con el sistema de diseño y el shell.
 *
 * Constitución:
 *   - Artículo I: CSS puro; cero literales de color y cero recursos externos.
 *   - Artículo V: clases kebab-case; comentarios en castellano.
 *
 * Uso: node scratch/test_clan_heraldry_css.mjs
 */

import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const projectRoot = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const read = (relativePath) => fs.readFileSync(path.join(projectRoot, relativePath), 'utf8');

const heraldryPath = 'public/assets/css/components/clan-heraldry.css';
const hallPath = 'public/assets/css/components/lineage-hall.css';

const heraldryCss = read(heraldryPath);
const hallCss = read(hallPath);
const tokensCss = read('public/assets/css/tokens.css');
const componentsCss = read('public/assets/css/components.css');
const indexHtml = read('public/index.html');
const lineageServiceSource = read('src/Services/LineageSynergyService.php');

let assertsPassed = 0;
let assertsFailed = 0;

/** Aserta una condición y registra el resultado. */
function assertCondition(condition, description) {
  if (condition) {
    assertsPassed += 1;
    console.log(`  [PASA] ${description}`);
  } else {
    assertsFailed += 1;
    console.log(`  [FALLA] ${description}`);
  }
}

/** Normaliza espacios para comparar declaraciones CSS sin ruido de formato. */
const normalize = (value) => String(value).replace(/\s+/g, ' ').trim();

/** Mapa nombre→valor de todas las variables declaradas en un CSS. */
function parseTokens(css) {
  const map = new Map();
  const re = /(--[a-z0-9-]+)\s*:\s*([^;{}]+);/g;
  let match;
  while ((match = re.exec(css)) !== null) {
    if (!map.has(match[1])) map.set(match[1], match[2].trim());
  }
  return map;
}

/**
 * Reúne las reglas de una hoja, incluidas las anidadas en @media.
 * @returns {Array<{selector: string, body: string, media: string|null}>}
 */
function collectRules(css, media = null) {
  const clean = css.replace(/\/\*[\s\S]*?\*\//g, '');
  const rules = [];
  let index = 0;
  while (index < clean.length) {
    const brace = clean.indexOf('{', index);
    if (brace === -1) break;
    const selector = clean.slice(index, brace).trim();
    let depth = 1;
    let cursor = brace + 1;
    while (cursor < clean.length && depth > 0) {
      if (clean[cursor] === '{') depth += 1;
      else if (clean[cursor] === '}') depth -= 1;
      cursor += 1;
    }
    const body = clean.slice(brace + 1, cursor - 1);
    if (selector.startsWith('@media')) {
      rules.push(...collectRules(body, selector.replace(/^@media\s*/, '')));
    } else if (selector.startsWith('@keyframes')) {
      rules.push({ selector, body, media, isKeyframes: true });
    } else if (selector !== '') {
      rules.push({ selector, body, media });
    }
    index = cursor;
  }
  return rules;
}

/** Cuerpo concatenado de las reglas (sin media) que incluyen el selector. */
function ruleBody(rules, selector) {
  let body = '';
  for (const rule of rules) {
    if (rule.media !== null || rule.isKeyframes) continue;
    const selectors = rule.selector.split(',').map((part) => part.trim());
    if (selectors.includes(selector)) body += rule.body;
  }
  return body;
}

/**
 * Cuerpo concatenado de las reglas que incluyen el selector, sin exigir que
 * vivan fuera de un @media: lo usan las sondas de adaptación responsive.
 */
function anyRuleBody(rules, selector) {
  let body = '';
  for (const rule of rules) {
    if (rule.isKeyframes) continue;
    const selectors = rule.selector.split(',').map((part) => part.trim());
    if (selectors.includes(selector)) body += rule.body;
  }
  return body;
}

/** Hoja sin comentarios: la prosa no debe contar como código (auditorías). */
const stripComments = (css) => css.replace(/\/\*[\s\S]*?\*\//g, '');

/** Reglas de una hoja dentro de un @media cuyo texto contenga el filtro. */
function rulesWithinMedia(rules, mediaFilter) {
  return rules.filter((rule) => rule.media !== null && rule.media.includes(mediaFilter));
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

/** Ratio de contraste WCAG 2.1 entre dos colores hex. */
function contrastRatio(hexA, hexB) {
  const la = relativeLuminance(hexA);
  const lb = relativeLuminance(hexB);
  return (Math.max(la, lb) + 0.05) / (Math.min(la, lb) + 0.05);
}

/** Resuelve un valor CSS de color a hex con los tokens conocidos. */
function resolveColor(value, tokenMap, depth = 0) {
  if (depth > 6 || value === null || value === undefined) return null;
  const clean = normalize(value);
  if (/^#[0-9a-fA-F]{6}$/.test(clean)) return clean.toLowerCase();
  const varMatch = clean.match(/^var\((--[a-z0-9-]+)\s*(?:,\s*([\s\S]+))?\)$/);
  if (varMatch !== null) {
    const declared = tokenMap.get(varMatch[1]);
    if (declared !== undefined) return resolveColor(declared, tokenMap, depth + 1);
    if (varMatch[2] !== undefined) return resolveColor(varMatch[2], tokenMap, depth + 1);
  }
  return null;
}

/** Valor declarado de una propiedad dentro de un cuerpo de regla o de hoja. */
function declaration(body, property) {
  const match = String(body).match(new RegExp(`(?:^|;|\\{)\\s*${property}\\s*:\\s*([^;]+);`));
  return match === null ? null : normalize(match[1]);
}

/**
 * Paridad con el canon del backend: extrae de `LineageSynergyService` los
 * ocho linajes canónicos con su elemento rector, su glifo y su marco.
 */
function parseCanonicalLineages(phpSource) {
  return phpSource
    .split(/'id'\s*=>\s*'/)
    .slice(1)
    .map((chunk) => {
      const grab = (key) => (chunk.match(new RegExp(`'${key}'\\s*=>\\s*'([^']+)'`)) ?? [])[1] ?? null;
      return {
        id: chunk.slice(0, chunk.indexOf("'")),
        rulingElement: grab('rulingElement'),
        glyph: grab('glyph'),
        heraldicFrame: grab('heraldicFrame'),
      };
    })
    .filter((lineage) => lineage.heraldicFrame !== null);
}

/** Clave canónica camelCase → sufijo kebab-case de las variables y clases. */
const toKebab = (value) => value.replace(/([a-z0-9])([A-Z])/g, '$1-$2').toLowerCase();

/** Elemento rector canónico → sufijo del token de afinidad del sistema. */
const affinitySuffix = (rulingElement) => (rulingElement === 'pureArcane' ? 'arcane' : rulingElement);

// El universo de tokens abarca el sistema de diseño y las variables que
// declaran las propias hojas de la fase (--heraldry-*, su fuente única).
const tokenMap = new Map([
  ...parseTokens(tokensCss),
  ...parseTokens(heraldryCss),
  ...parseTokens(hallCss),
]);
const heraldryRules = collectRules(heraldryCss);
const hallRules = collectRules(hallCss);
const canonicalLineages = parseCanonicalLineages(lineageServiceSource);

console.log('\n== ARNÉS — HERÁLDICA DE LOS LINAJES Y SALÓN (Tarea 6.1, SPEC-07) ==\n');

// -----------------------------------------------------------------------
// [0] Superficie
// -----------------------------------------------------------------------
console.log('[FASE 0] Superficie de las dos hojas ceremoniales');

assertCondition(fs.existsSync(path.join(projectRoot, heraldryPath)), `${heraldryPath} existe`);
assertCondition(fs.existsSync(path.join(projectRoot, hallPath)), `${hallPath} existe`);
assertCondition(indexHtml.includes('assets/css/components/clan-heraldry.css'), 'El shell enlaza la hoja heráldica');
assertCondition(indexHtml.includes('assets/css/components/lineage-hall.css'), 'El shell enlaza la hoja del Salón');
assertCondition(
  indexHtml.indexOf('components/clan-heraldry.css') > indexHtml.indexOf('components.css')
  && indexHtml.indexOf('components/lineage-hall.css') > indexHtml.indexOf('components/clan-heraldry.css')
  && indexHtml.indexOf('components/lineage-hall.css') < indexHtml.indexOf('print.css'),
  'Ambas hojas se cargan tras la hoja base y antes de la de impresión',
);

// -----------------------------------------------------------------------
// [1] Paleta heráldica (paridad con el canon)
// -----------------------------------------------------------------------
console.log('\n[FASE 1] Paleta heráldica de los 8 linajes (RF-02.1, RF-02.2)');

assertCondition(canonicalLineages.length === 8, `El canon del backend declara los 8 linajes (leídos: ${canonicalLineages.length})`);

for (const lineage of canonicalLineages) {
  const declared = declaration(heraldryCss, `--heraldry-${toKebab(lineage.id)}`);
  assertCondition(
    declared === `var(--color-affinity-${affinitySuffix(lineage.rulingElement)})`,
    `--heraldry-${toKebab(lineage.id)} lee el estandarte de su elemento rector (${lineage.rulingElement})`,
  );
}

// La paleta de linajes vive en UN solo bloque :root: exactamente ocho
// variables, más las dos del oro del Dominio (que no son estandartes).
const rootBlock = (heraldryCss.match(/:root\s*\{([\s\S]*?)\}/) ?? [])[1] ?? '';
const declaredLineageVariables = (rootBlock.match(/--heraldry-[a-z0-9-]+\s*:/g) ?? [])
  .map((name) => name.replace(/\s*:$/, ''))
  .filter((name) => !name.includes('regent'));
assertCondition(
  declaredLineageVariables.length === 8,
  `Se declaran exactamente 8 variables de linaje en :root (leídas: ${declaredLineageVariables.length})`,
);
assertCondition(
  new Set(declaredLineageVariables).size === 8,
  'Ningún linaje repite variable: la paleta no tiene duplicados',
);

// -----------------------------------------------------------------------
// [2] Los 8 marcos heráldicos
// -----------------------------------------------------------------------
console.log('\n[FASE 2] Los 8 marcos heráldicos canónicos (RF-02.2)');

const motifSignatures = new Set();

for (const lineage of canonicalLineages) {
  const kebab = toKebab(lineage.id);
  const frameSelector = `.heraldic-frame[data-heraldic-frame='${lineage.heraldicFrame}']`;
  const body = ruleBody(heraldryRules, frameSelector);

  assertCondition(body !== '', `El marco ${lineage.heraldicFrame} se ata a su clave canónica verbatim (data-heraldic-frame)`);
  assertCondition(
    ruleBody(heraldryRules, `.heraldic-frame--${toKebab(lineage.heraldicFrame)}`) !== '',
    `El marco ${lineage.heraldicFrame} publica además su alias kebab-case (--${toKebab(lineage.heraldicFrame)})`,
  );
  assertCondition(
    declaration(body, '--heraldry-color') === `var(--heraldry-${kebab})`,
    `El marco ${lineage.heraldicFrame} viste el estandarte del linaje ${lineage.id}`,
  );

  // Motivo distintivo: las declaraciones propias del bloque (sin el tinte).
  const motif = body
    .split(';')
    .map((part) => normalize(part))
    .filter((part) => part !== '' && !part.startsWith('--heraldry-color'))
    .sort()
    .join('; ');
  motifSignatures.add(motif);
  assertCondition(motif !== '', `El marco ${lineage.heraldicFrame} declara un motivo propio (borde, silueta o profundidad)`);
}

assertCondition(motifSignatures.size === 8, 'Los ocho marcos son visualmente distinguibles entre sí (8 motivos distintos)');

// -----------------------------------------------------------------------
// [3] Estandarte del linaje
// -----------------------------------------------------------------------
console.log('\n[FASE 3] Estandarte del linaje (RF-02.2)');

assertCondition(
  declaration(ruleBody(heraldryRules, '.clan-standard'), '--standard-tint') === 'var(--banner-tint, var(--heraldry-regent-gold))',
  'El estandarte acepta el tinte que ya publica clanBannerComponent (--banner-tint) con respaldo en el oro arcano',
);
assertCondition(ruleBody(heraldryRules, '.clan-standard__glyph') !== '', 'El estandarte exhibe el glifo rúnico del linaje');
assertCondition(ruleBody(heraldryRules, '.clan-standard__name') !== '', 'El estandarte exhibe el nombre ceremonial del clan');
assertCondition(ruleBody(heraldryRules, '.clan-standard__motto') !== '', 'El estandarte exhibe el lema heráldico');
assertCondition(ruleBody(heraldryRules, '.clan-standard__lineage') !== '', 'El estandarte declara el linaje rector con su elemento');

// -----------------------------------------------------------------------
// [4] Brillo dorado del Clan Regente
// -----------------------------------------------------------------------
console.log('\n[FASE 4] Brillo dorado del Clan Regente (RF-04.4)');

const auraBody = ruleBody(heraldryRules, '.regent-glow__aura');
assertCondition(auraBody !== '', 'El halo dorado del reinante existe (.regent-glow__aura)');
assertCondition(declaration(auraBody, 'animation') === 'regentGoldenGlow 3.6s ease-in-out infinite', 'El halo late con su ciclo ceremonial');
assertCondition(declaration(auraBody, 'will-change') === 'opacity, transform', 'El halo anima solo propiedades compositables (eje de 60 fps)');
assertCondition(
  normalize(auraBody).includes('var(--heraldry-regent-bright)'),
  'El halo se tiñe del oro radiante del sistema de diseño, nunca de un literal',
);

const glowKeyframes = heraldryRules.find((rule) => rule.isKeyframes === true && rule.selector.includes('regentGoldenGlow'));
assertCondition(glowKeyframes !== undefined, 'El ciclo del halo declara sus fotogramas clave');
assertCondition(
  glowKeyframes.body.includes('opacity') && glowKeyframes.body.includes('transform'),
  'Los fotogramas solo mueven opacidad y escala: cero recálculo de disposición',
);
assertCondition(declaration(ruleBody(heraldryRules, '.heraldic-frame--regent'), 'box-shadow') === 'var(--shadow-gold-glow)', 'El estandarte reinante viste el resplandor dorado del sistema');
assertCondition(ruleBody(heraldryRules, '.clan-mark--regent') !== '', 'La marca textual del reinado existe (.clan-mark--regent)');

// -----------------------------------------------------------------------
// [5] Ribete dorado de los conjuros del clan soberano
// -----------------------------------------------------------------------
console.log('\n[FASE 5] Ribete dorado brillante de los conjuros del Clan Regente (RF-04.4)');

const regentBorderBody = ruleBody(heraldryRules, '.spell-card-regent-border');
assertCondition(regentBorderBody !== '', 'La clase .spell-card-regent-border existe');
assertCondition(
  declaration(regentBorderBody, 'border-color') === 'var(--heraldry-regent-gold)',
  'El ribete dorado sustituye el filo de tinta de la tarjeta por el oro ceremonial',
);
assertCondition(
  normalize(regentBorderBody).includes('var(--shadow-gold-glow)'),
  'El ribete porta el resplandor dorado del Dominio',
);
assertCondition(
  /(?:^|;|\{)\s*color\s*:/.test(regentBorderBody) === false,
  'El ribete JAMÁS toca la tinta de la tarjeta: el contraste del pergamino queda intacto (RNF-03)',
);

const trimBody = ruleBody(heraldryRules, '.spell-card-regent-border::after');
assertCondition(declaration(trimBody, 'content') === "''", 'El ribete interior existe como pseudo-elemento');
assertCondition(declaration(trimBody, 'position') === 'absolute', 'El ribete interior se superpone a la tarjeta');
assertCondition(declaration(trimBody, 'pointer-events') === 'none', 'El ribete no roba un solo gesto a la tarjeta');
assertCondition(declaration(trimBody, 'animation') === 'regentBorderShimmer 3.2s ease-in-out infinite', 'El ribete brilla con su ciclo propio');
assertCondition(declaration(trimBody, 'will-change') === 'opacity', 'El brillo anima opacidad, no sombras ni medidas');

const trimKeyframes = heraldryRules.find((rule) => rule.isKeyframes === true && rule.selector.includes('regentBorderShimmer'));
assertCondition(trimKeyframes !== undefined, 'El brillo del ribete declara sus fotogramas clave');
assertCondition(
  /box-shadow|width|height|margin|padding|filter/.test(trimKeyframes.body) === false,
  'Los fotogramas del ribete no animan propiedades costosas: brillo compositable',
);

assertCondition(
  /\.spell-card\s*\{[^}]*position:\s*relative/.test(componentsCss),
  'La tarjeta base del Tomo es `position: relative`: el ribete interior se compone sobre ella sin tocar components.css',
);
assertCondition(ruleBody(heraldryRules, '.spell-card__regent-seal') !== '', 'La ficha del conjuro exhibe el sello del clan reinante');

// -----------------------------------------------------------------------
// [6] El Salón de los Linajes
// -----------------------------------------------------------------------
console.log('\n[FASE 6] El Salón de los Linajes (RF-06.1, RF-06.2)');

const hallSelectors = [
  '.lineage-hall',
  '.lineage-hall__header',
  '.lineage-hall__title',
  '.lineage-hall__hint',
  '.lineage-hall__tabs',
  '.lineage-tab',
  '.lineage-tab--active',
  '.lineage-hall__filters',
  '.lineage-filter',
  '.lineage-filter__glyph',
  '.lineage-hall__podium',
  '.podium-rank',
  '.podium-rank--regent',
  '.podium-rank__points',
  '.lineage-hall__historical',
  '.historical-row',
  '.lineage-hall__hall-of-fame',
  '.fame-entry',
  '.lineage-hall__loading',
  '.lineage-hall__error',
  '.lineage-hall__empty',
];

for (const selector of hallSelectors) {
  assertCondition(ruleBody(hallRules, selector) !== '', `El Salón viste ${selector}`);
}

for (const lineage of canonicalLineages) {
  const declared = declaration(
    ruleBody(hallRules, `.lineage-filter[data-lineage='${lineage.id}']`),
    '--lineage-tint',
  );
  assertCondition(
    declared === `var(--heraldry-${toKebab(lineage.id)}, var(--color-affinity-${affinitySuffix(lineage.rulingElement)}))`,
    `El filtro del linaje ${lineage.id} se tiñe con su estandarte (con respaldo en la matriz elemental)`,
  );
}

// -----------------------------------------------------------------------
// [7] Contraste WCAG 2.1 AA
// -----------------------------------------------------------------------
console.log('\n[FASE 7] Contraste WCAG 2.1 AA (≥ 4.5:1) de los pares declarados (RNF-03)');

const contrastPairs = [
  [heraldryRules, '.clan-standard__motto', '--color-bg-obsidian-surface', 4.5, 'lema del estandarte'],
  [heraldryRules, '.clan-standard__lineage', '--color-bg-obsidian-surface', 4.5, 'linaje del estandarte'],
  [heraldryRules, '.clan-mark--regent', '--color-bg-obsidian-elevated', 4.5, 'sello del reinado'],
  [heraldryRules, '.clan-standard__name', '--color-bg-obsidian-surface', 4.5, 'nombre del clan en el estandarte'],
  [hallRules, '.lineage-hall__title', '--color-bg-obsidian-deep', 4.5, 'título del Salón'],
  [hallRules, '.lineage-hall__hint', '--color-bg-obsidian-deep', 4.5, 'ayuda del Salón'],
  [hallRules, '.lineage-tab', '--color-parchment-old', 4.5, 'pestaña en reposo'],
  [hallRules, '.lineage-tab--active', '--color-parchment', 4.5, 'pestaña activa'],
  [hallRules, '.lineage-filter', '--color-bg-obsidian-surface', 4.5, 'filtro elemental'],
  [hallRules, '.podium-rank', '--color-parchment', 4.5, 'peld año del podio'],
  [hallRules, '.podium-rank--regent', '--color-bg-obsidian-elevated', 4.5, 'peld año del clan reinante'],
  [hallRules, '.historical-row', '--color-bg-obsidian-surface', 4.5, 'fila del prestigio histórico'],
  [hallRules, '.fame-entry', '--color-parchment-old', 4.5, 'entrada del Libro Mayor'],
  [hallRules, '.lineage-hall__error', '--color-bg-obsidian-elevated', 4.5, 'corte de maná del Salón'],
  [hallRules, '.lineage-hall__empty', '--color-bg-obsidian-deep', 4.5, 'Salón sin contienda'],
];

for (const [rules, selector, backgroundToken, minRatio, label] of contrastPairs) {
  const body = ruleBody(rules, selector);
  const colorHex = resolveColor(declaration(body, 'color'), tokenMap);
  // El fondo puede vivir en la propia regla (pares de componente) o en el
  // contenedor declarado por el arnés (pares heredados).
  const backgroundHex = resolveColor(declaration(body, 'background-color'), tokenMap)
    ?? resolveColor(`var(${backgroundToken})`, tokenMap);

  if (colorHex === null || backgroundHex === null) {
    assertCondition(false, `${label}: color o fondo no resolubles estáticamente (${selector})`);
    continue;
  }

  const ratio = contrastRatio(colorHex, backgroundHex);
  assertCondition(
    ratio >= minRatio,
    `${label}: ${ratio.toFixed(2)}:1 ≥ ${minRatio}:1 (${colorHex} sobre ${backgroundHex})`,
  );
}

// -----------------------------------------------------------------------
// [8] Responsive
// -----------------------------------------------------------------------
console.log('\n[FASE 8] Adaptación responsive a móvil y escritorio (RNF-03)');

for (const [label, css, rules] of [['heráldica', heraldryCss, heraldryRules], ['Salón', hallCss, hallRules]]) {
  assertCondition(
    /@media\s*\(max-width:\s*767\.9px\)/.test(css),
    `La hoja ${label} declara su bloque de móvil (≤ 767.9 px, el punto de ruptura del santuario)`,
  );
  assertCondition(
    /@media\s*\(min-width:\s*768px\)/.test(css),
    `La hoja ${label} declara su bloque de escritorio (≥ 768 px)`,
  );
  assertCondition(
    rulesWithinMedia(rules, 'max-width: 767.9px').length > 0 && rulesWithinMedia(rules, 'min-width: 768px').length > 0,
    `Los dos bloques de la hoja ${label} contienen reglas reales`,
  );
}

// El marco heráldico cambia de medida según el ancho: la adaptación es real.
const frameDesktop = heraldryRules.find((rule) => rule.media !== null && rule.media.includes('min-width: 768px')
  && rule.selector.split(',').map((s) => s.trim()).includes('.heraldic-frame'));
const frameMobile = heraldryRules.find((rule) => rule.media !== null && rule.media.includes('max-width: 767.9px')
  && rule.selector.split(',').map((s) => s.trim()).includes('.heraldic-frame'));
assertCondition(frameDesktop !== undefined && frameMobile !== undefined, 'El marco heráldico se re-mide en ambos anchos');
assertCondition(
  declaration(frameDesktop.body, 'padding') !== declaration(frameMobile.body, 'padding')
  && declaration(frameMobile.body, 'border-width') === '2px',
  'Móvil ciñe el marco (padding y filo menores) mientras el escritorio lo engrandece',
);

const mobileHallRules = rulesWithinMedia(hallRules, 'max-width: 767.9px');
const tabsMobile = anyRuleBody(mobileHallRules, '.lineage-hall__tabs');
const podiumMobile = anyRuleBody(mobileHallRules, '.lineage-hall__podium');
assertCondition(declaration(tabsMobile, 'flex-direction') === 'column', 'En móvil las pestañas del Salón se apilan');
assertCondition(declaration(podiumMobile, 'grid-template-columns') === '1fr', 'En móvil el podio pasa a una sola columna');

const filterMobile = ruleBody(hallRules, '.lineage-filter');
assertCondition(
  normalize(filterMobile).includes('min-height: var(--touch-target-min)')
  && normalize(filterMobile).includes('min-width: var(--touch-target-min)'),
  'Cada filtro elemental respeta la zona táctil mínima también en móvil',
);

// -----------------------------------------------------------------------
// [9] Dogma Vanilla, foco y movimiento reducido
// -----------------------------------------------------------------------
console.log('\n[FASE 9] Dogma Vanilla, foco visible y movimiento reducido (RNF-03)');

for (const [label, css] of [['heráldica', heraldryCss], ['Salón', hallCss]]) {
  const code = stripComments(css);
  assertCondition(
    (code.match(/#[0-9a-fA-F]{3,8}\b/g) ?? []).length === 0,
    `La hoja ${label} no porta un solo literal de color: todo bebe de tokens.css (Artículo I)`,
  );
  assertCondition(
    /@import\s+(url\(|['"])/.test(code) === false,
    `La hoja ${label} no importa otra hoja (@import prohibido)`,
  );
  assertCondition(
    /url\(\s*['"]?https?:/.test(code) === false,
    `La hoja ${label} no llama a recurso externo alguno (cero CDN, Artículo I)`,
  );
}

// Toda animación de las dos hojas se anula bajo movimiento reducido.
for (const [label, css, rules] of [['heráldica', heraldryCss, heraldryRules], ['Salón', hallCss, hallRules]]) {
  const reducedRules = rulesWithinMedia(rules, 'prefers-reduced-motion: reduce');
  assertCondition(reducedRules.length > 0, `La hoja ${label} respeta prefers-reduced-motion`);

  const animatedSelectors = rules
    .filter((rule) => rule.media === null && !rule.isKeyframes)
    .filter((rule) => /\banimation\s*:/.test(rule.body) && /animation:\s*none/.test(rule.body) === false)
    .flatMap((rule) => rule.selector.split(',').map((part) => part.trim()));

  for (const selector of animatedSelectors) {
    const silenced = reducedRules.some((rule) => rule.selector.split(',').map((s) => s.trim()).includes(selector)
      && /animation:\s*none/.test(rule.body));
    assertCondition(silenced, `La animación de ${selector} (hoja ${label}) se anula con el movimiento reducido`);
  }
}

assertCondition(
  /:focus-visible/.test(hallCss) && /outline/.test(hallCss),
  'Los controles del Salón publican foco visible propio (RNF-03)',
);
assertCondition(
  /:focus-visible[^{]*\{[^}]*outline:\s*none/.test(heraldryCss) === false,
  'La heráldica jamás borra el foco de la tarjeta del conjuro: lo hereda de components.css',
);
assertCondition(
  /\.spell-card:focus-visible/.test(componentsCss),
  'La tarjeta base conserva su foco visible, que el ribete honra',
);

// -----------------------------------------------------------------------
// [10] Integración con el sistema de diseño
// -----------------------------------------------------------------------
console.log('\n[FASE 10] Integración con el sistema de diseño y el shell');

assertCondition(
  ruleBody(heraldryRules, ':root') !== '' || /:root\s*\{[\s\S]*--heraldry-/.test(heraldryCss),
  'La paleta heráldica se declara una sola vez, en el ámbito global (:root)',
);
assertCondition(
  hallCss.includes('var(--heraldry-') || hallCss.includes('var(--lineage-tint'),
  'El Salón consume la paleta declarada por la hoja heráldica (fuente única de la fase)',
);
assertCondition(
  /--font-arcane-title/.test(heraldryCss) && /--font-arcane-title/.test(hallCss),
  'Las dos hojas visten la tipografía ceremonial del sistema',
);
assertCondition(
  /--radius-seal/.test(heraldryCss) && /--radius-seal/.test(hallCss),
  'Las dos hojas respetan el radio de sello del sistema',
);
assertCondition(
  /--transition-arcane/.test(heraldryCss) && /--transition-arcane/.test(hallCss),
  'Las dos hojas usan la curva de movimiento del sistema',
);
assertCondition(
  /--color-ember-red/.test(hallCss),
  'El corte de maná del Salón usa la brasa del sistema para el recuadro de error',
);

// -----------------------------------------------------------------------
// Veredicto
// -----------------------------------------------------------------------
console.log(`\n${'-'.repeat(64)}`);
console.log(`Asertos superados: ${assertsPassed}`);
console.log(`Asertos fallidos:  ${assertsFailed}`);
console.log(`Veredicto: ${assertsFailed === 0 ? 'TODO EN ORDEN — el criterio «Hecho cuando» se cumple' : 'HAY FALLOS'}`);
process.exit(assertsFailed === 0 ? 0 : 1);
