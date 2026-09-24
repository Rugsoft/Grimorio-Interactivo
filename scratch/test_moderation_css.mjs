/**
 * test_moderation_css.mjs — Arnés de la Tarea 6.3 (TASKS-08).
 *
 * Verifica sobre `public/assets/css/components/moderation.css` el criterio
 * «Hecho cuando»:
 *   «Los componentes de moderación se renderizan con coherencia visual
 *    mística y se adaptan responsive a móvil y escritorio sin librerías
 *    externas.»
 *
 * Fases:
 *   [1]  Superficie: la hoja existe, el shell la enlaza tras los tokens.
 *   [2]  Pureza de tokens: CERO literales de color fuera del bloque del
 *        pulso de consagración (Artículo IV, fuente de verdad única).
 *   [3]  Los cinco componentes de la fase visten su liturgia.
 *   [4]  Marco rúnico de advertencia del Atrio (RF-05.1).
 *   [5]  Medidor circular de firmas con las claves de datos servidas.
 *   [6]  Sello rúnico de veto y bloqueo con piedra desgastada.
 *   [7]  Efecto dorado de consagración vía @keyframes (plan 4.1).
 *   [8]  Adaptación responsive a móvil y escritorio (RNF-03).
 *   [9]  Foco visible en todos los gestos (RNF-03).
 *   [10] Movimiento reducido: las animaciones se anulan (RNF-03).
 *   [11] Dogma Vanilla: sin @import, sin url(), sin recursos externos.
 *
 * Constitución:
 *   - Artículo I (Dogma Vanilla): cero librerías; verificación estática.
 *   - Artículo V: clases kebab-case; comentarios en castellano.
 *
 * Uso: node scratch/test_moderation_css.mjs
 */

import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const projectRoot = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const read = (relativePath) => fs.readFileSync(path.join(projectRoot, relativePath), 'utf8');

const moderationPath = 'public/assets/css/components/moderation.css';
const moderationCss = read(moderationPath);
const tokensCss = read('public/assets/css/tokens.css');
const indexHtml = read('public/index.html');

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

/** Normaliza espacios para comparar declaraciones sin ruido de formato. */
const normalize = (value) => String(value).replace(/\s+/g, ' ').trim();

/** Reúne las reglas de una hoja, incluidas las anidadas en @media. */
function collectRules(css) {
  const rules = [];
  const re = /([^{}]+)\{([^{}]*)\}/g;
  let match;
  while ((match = re.exec(css)) !== null) {
    rules.push({ selector: normalize(match[1]), body: normalize(match[2]) });
  }
  return rules;
}

const rules = collectRules(moderationCss);

/** La hoja sin comentarios: los literales del prólogo no son código. */
const codeWithoutComments = moderationCss.replace(/\/\*[\s\S]*?\*\//g, '');

/** Busca una regla cuyo selector contenga la clase pedida. */
function findRule(selectorFragment) {
  return rules.find((rule) => rule.selector.includes(selectorFragment)) ?? null;
}

/** ¿Declara la hoja la propiedad pedida en el selector pedido? */
function declares(selectorFragment, property) {
  const rule = findRule(selectorFragment);
  return rule !== null && new RegExp(`${property}\\s*:`).test(rule.body);
}

console.log('== VERIFICACION TAREA 6.3: hoja ceremonial de moderacion ==\n');

// =====================================================================
// [1] Superficie: la hoja existe y el shell la enlaza tras los tokens
// =====================================================================
console.log('[1] Superficie de la hoja');
assertCondition(moderationCss.length > 0, 'la hoja ceremonial existe y no está vacía');
assertCondition(
  indexHtml.includes('assets/css/components/moderation.css'),
  'el shell principal la enlaza (index.html)'
);
assertCondition(
  indexHtml.indexOf('assets/css/tokens.css') < indexHtml.indexOf('assets/css/components/moderation.css'),
  'el shell la enlaza DESPUÉS de los tokens: la fuente de verdad carga primero'
);

// =====================================================================
// [2] Pureza de tokens: CERO literales de color (Artículo IV, canon)
// =====================================================================
console.log('\n[2] Pureza de tokens: cero literales de color');
// El bloque del pulso de consagración usa rgba() literales porque anima
// el ALFA de una sombra — los tokens no expresan alfa de origen. Es la
// única excepción declarada, idéntica a la de clan-heraldry.css.
const withoutKeyframes = codeWithoutComments.replace(/@keyframes[\s\S]*?\n\}\n?/g, '');
const colorLiterals = (withoutKeyframes.match(/#[0-9a-fA-F]{3,8}\b/g) ?? []);
assertCondition(colorLiterals.length === 0, `las reglas no escriben un solo hex literal (encontrados: ${colorLiterals.length})`);
const nonTokenVarUses = (withoutKeyframes.match(/var\(--(?!color-|glow-|mana-|magic-|border-|radius-|shadow-|space-|font-|line-|letter-|transition-|touch-|z-|container-|measure-|affinity-|sigil-|moderation-)/g) ?? []);
assertCondition(nonTokenVarUses.length === 0, 'toda variable consumida procede de tokens.css o de la paleta litúrgica local');

// =====================================================================
// [3] Los cinco componentes de la fase visten su liturgia
// =====================================================================
console.log('\n[3] Los cinco componentes visten su liturgia');
const componentPrefixes = [
  '.experimental-hall',
  '.masters-tower',
  '.objection-modal',
  '.imperial-decree-modal',
  '.spell-correction',
];
for (const prefix of componentPrefixes) {
  assertCondition(moderationCss.includes(prefix), `la hoja viste ${prefix} (Tareas 5.2/5.3/6.1/6.2)`);
}

// =====================================================================
// [4] Marco rúnico de advertencia del Atrio (RF-05.1)
// =====================================================================
console.log('\n[4] Marco rúnico de advertencia del Atrio (RF-05.1)');
assertCondition(
  declares('.experimental-hall__warning', 'border'),
  'la insignia del Atrio porta su marco ámbar ceremonial'
);
assertCondition(
  findRule('.experimental-hall__warning')?.body.includes('var(--moderation-warning-ink)'),
  'el marco lee el ámbar inestable de tokens.css (--color-unstable-seal)'
);
assertCondition(
  declares('.experimental-hall__warning', 'border-left-width'),
  'el filo izquierdo engrosado delata el pergamino de advertencia'
);

// =====================================================================
// [5] Medidor circular de firmas con claves servidas (Artículo II)
// =====================================================================
console.log('\n[5] Medidor circular de firmas servido, no calculado');
assertCondition(
  declares('.experimental-hall__meter', 'border-radius'),
  'el medidor se dibuja circular'
);
assertCondition(
  findRule(".experimental-hall__meter[data-consignable='true']") !== null,
  'el medidor distingue el umbral de consignación por la clave SERVIDA (data-consignable)'
);

// =====================================================================
// [6] Sello rúnico de veto y piedra desgastada del bloqueo
// =====================================================================
console.log('\n[6] Sello rúnico de veto y bloqueo inerte');
assertCondition(
  findRule('.masters-tower__sign-button:disabled') !== null,
  'el botón del Maestro vetado viste la piedra desgastada'
);
assertCondition(
  declares('.masters-tower__veto-alert', 'background'),
  'la alerta de veto proclama su causa con tinta propia'
);
assertCondition(
  declares('.objection-modal__submit:disabled', 'cursor'),
  'el envío bajo umbral se muestra inerte (cursor not-allowed)'
);

// =====================================================================
// [7] Efecto dorado de consagración vía @keyframes (plan 4.1)
// =====================================================================
console.log('\n[7] Efecto dorado de consagración (@keyframes nativas)');
assertCondition(
  /@keyframes moderation-consecration-glow/.test(moderationCss),
  'el resplandor triunfal nace de una @keyframes nativa'
);
assertCondition(
  findRule(".experimental-hall__card[data-consecrated='true']") !== null,
  'la tarjeta ascendida por el bus (moderation:consecrated) lo recibe por su clave de datos'
);
assertCondition(
  /@keyframes moderation-veto-pulse/.test(moderationCss),
  'el pulso solemne del veto es también @keyframes nativa'
);

// =====================================================================
// [8] Adaptación responsive a móvil y escritorio (RNF-03)
// =====================================================================
console.log('\n[8] Adaptación responsive (RNF-03)');
assertCondition(
  moderationCss.includes('@media (max-width: 767.9px)'),
  'los componentes colapsan a columna única bajo el punto de quiebre móvil'
);
assertCondition(
  moderationCss.includes('@media (min-width: 768px)'),
  'los componentes disponen su rejilla solemne en escritorio'
);
assertCondition(
  declares('.imperial-decree-modal__actions > button', 'width'),
  'los gestos del decreto ocupan todo el ancho en móvil (zona táctil)'
);

// =====================================================================
// [9] Foco visible en todos los gestos (RNF-03)
// =====================================================================
console.log('\n[9] Foco visible (RNF-03)');
assertCondition(
  /focus-visible/.test(moderationCss),
  'la hoja declara un anillo de foco ceremonial (:focus-visible)'
);
const focusRule = rules.find((rule) => rule.selector.includes('focus-visible'));
const focusableSelectors = [
  '.experimental-hall__test-button',
  '.masters-tower__sign-button',
  '.objection-modal__reason',
  '.imperial-decree-modal__decree',
  '.spell-correction__reopen',
];
assertCondition(
  focusRule !== null && focusableSelectors.every((fragment) => focusRule.selector.includes(fragment)),
  'los cinco componentes declaran sus gestos enfocables'
);
assertCondition(
  focusRule?.body.includes('outline') === true,
  'el foco se dibuja con outline (jamás suprimido)'
);

// =====================================================================
// [10] Movimiento reducido: las animaciones se anulan (RNF-03)
// =====================================================================
console.log('\n[10] Movimiento reducido (RNF-03)');
assertCondition(
  /@media \(prefers-reduced-motion: reduce\)/.test(moderationCss),
  'la hoja respeta la pausa respetuosa (prefers-reduced-motion)'
);
const reducedBlock = moderationCss.slice(moderationCss.indexOf('@media (prefers-reduced-motion: reduce)'));
assertCondition(
  reducedBlock.includes('animation: none')
    && reducedBlock.includes(".experimental-hall__card[data-consecrated='true']")
    && reducedBlock.includes(".masters-tower__card[data-vetoed='true']"),
  'ambos efectos (@keyframes) quedan anulados bajo movimiento reducido'
);
assertCondition(
  reducedBlock.includes('transform: none'),
  'la elevación al pasar el puntero también se aquiet'
);

// =====================================================================
// [11] Dogma Vanilla: sin @import, sin recursos externos (Artículo I)
// =====================================================================
console.log('\n[11] Dogma Vanilla: cero recursos externos (Artículo I)');
assertCondition(!codeWithoutComments.includes('@import'), 'la hoja no importa nada: se sirve tal cual');
assertCondition(!/url\(/.test(moderationCss), 'la hoja no solicita recurso externo alguno');
assertCondition(!/@font-face/.test(moderationCss), 'la hoja no declara fuentes: las hereda del sistema de diseño');

console.log(`\n== RESUMEN == Asertos superados: ${assertsPassed}, fallidos: ${assertsFailed}`);
if (assertsFailed === 0) {
  console.log('RESULTADO: EXITO — La liturgia visual de la moderación viste sus cinco componentes (Tarea 6.3).');
  process.exit(0);
} else {
  console.log('RESULTADO: DENEGADO — la hoja ceremonial incumple su criterio.');
  process.exit(1);
}
