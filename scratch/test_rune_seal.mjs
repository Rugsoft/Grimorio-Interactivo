/**
 * test_rune_seal.mjs — Arnés del Sello Rúnico forjado (SPEC-02 RF-07, SPEC-07 RF-02.4).
 *
 * Verifica sobre `public/assets/js/components/runeSealComponent.js` el criterio:
 *   «El blasón de cada casa y el sello de cada linaje se forjan como Sello Rúnico
 *    determinista: el identificador técnico jamás se imprime, el estado se declara
 *    por metal y forma, y la etiqueta accesible nombra casa, linaje y honor.»
 *
 * Fases:
 *   [0]  Superficie del módulo: fábrica, estados, cargas y etiqueta declaradas.
 *   [1]  Determinismo: la huella FNV-1a de 32 bits contra sus vectores canónicos,
 *        y la misma casa forjando siempre el mismo sello.
 *   [2]  La carga central: los 8 linajes con geometría DISTINTA (canon alquímico).
 *   [3]  El anillo de ocho muescas: codifica el identificador, jamás lo imprime.
 *   [4]  Estado por metal Y forma: activa, regente (cera a las doce) y disuelta.
 *   [5]  RF-07.3: el identificador técnico no aparece en texto ni en el nombre
 *        accesible, ni con blasón hostil.
 *   [6]  RF-07.5: contenido gráfico accesible y sin movimiento ni resplandor.
 *   [7]  Dogma Vanilla: cero dependencias, cero imágenes, colores solo por token.
 *
 * Constitución:
 *   - Artículo I: SVG nativo por aritmética; cero dependencias externas.
 *   - Artículo V: identificadores en inglés camelCase; comentarios en castellano.
 *
 * Uso: node scratch/test_rune_seal.mjs
 */

import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

import {
  createRuneSeal,
  runeSealHash,
  runeSealLabel,
  LINEAGE_CHARGES,
  RULING_ELEMENT_COLORS,
  RULING_ELEMENT_NAMES,
  RUNE_SEAL_STATES,
} from '../public/assets/js/components/runeSealComponent.js';

const projectRoot = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const source = fs.readFileSync(
  path.join(projectRoot, 'public/assets/js/components/runeSealComponent.js'),
  'utf8',
);
const tokensCss = fs.readFileSync(path.join(projectRoot, 'public/assets/css/tokens.css'), 'utf8');

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

/* =====================================================================
   DOM simulado: lo justo para forjar SVG sin navegador
   ===================================================================== */

function createFakeElement(tagName) {
  const element = {
    tagName: String(tagName).toUpperCase(),
    namespace: null,
    children: [],
    attributes: {},
    classes: new Set(),
    parentElement: null,
    setAttribute(name, value) { this.attributes[name] = String(value); },
    getAttribute(name) { return name in this.attributes ? this.attributes[name] : null; },
    removeAttribute(name) { delete this.attributes[name]; },
    hasAttribute(name) { return name in this.attributes; },
    appendChild(child) { child.parentElement = this; this.children.push(child); return child; },
    remove() {
      if (!this.parentElement) return;
      const index = this.parentElement.children.indexOf(this);
      if (index >= 0) this.parentElement.children.splice(index, 1);
      this.parentElement = null;
    },
    set className(value) { this.classes = new Set(String(value).split(/\s+/).filter(Boolean)); },
    get className() { return [...this.classes].join(' '); },
    get textContent() {
      return `${this._textContent ?? ''}${this.children.map((child) => child.textContent).join('')}`;
    },
    set textContent(value) {
      for (const child of this.children.splice(0)) child.parentElement = null;
      this._textContent = String(value);
    },
    get innerHTML() { throw new Error('PROHIBIDO innerHTML (AGENTS.md 6.1: XSS); usar textContent'); },
    set innerHTML(value) { throw new Error(`PROHIBIDO innerHTML (AGENTS.md 6.1): se intentó escribir '${String(value).slice(0, 40)}'`); },
  };
  element.classList = {
    add: (...names) => names.forEach((name) => element.classes.add(name)),
    remove: (...names) => names.forEach((name) => element.classes.delete(name)),
    contains: (name) => element.classes.has(name),
  };
  return element;
}

/** Documento anfitrión inyectable: el módulo jamás toca el global. */
const fakeDocument = {
  createElement: (tagName) => createFakeElement(tagName),
  createElementNS: (namespace, tagName) => {
    const element = createFakeElement(tagName);
    element.namespace = namespace;
    return element;
  },
};

/** Forja un sello con el documento simulado. */
const forgeSeal = (options) => createRuneSeal({ document: fakeDocument, ...options });

/** Todos los descendientes de un nodo, en profundidad. */
function descendants(node, found = []) {
  for (const child of node.children) {
    found.push(child);
    descendants(child, found);
  }
  return found;
}

/** Trazos por etiqueta SVG (para contar la geometría forjada). */
const byTag = (seal, tagName) => descendants(seal).filter((node) => node.tagName === String(tagName).toUpperCase());

/* =====================================================================
   [0] Superficie del módulo
   ===================================================================== */
console.log('\n[0] Superficie del módulo del Sello Rúnico');
assertCondition(typeof createRuneSeal === 'function', 'El módulo exporta la forja createRuneSeal()');
assertCondition(typeof runeSealHash === 'function', 'El módulo exporta la huella determinista runeSealHash()');
assertCondition(typeof runeSealLabel === 'function' && typeof RULING_ELEMENT_NAMES === 'object', 'El módulo declara la etiqueta accesible y los nombres ceremoniales');
assertCondition(
  Object.values(RUNE_SEAL_STATES).join('|') === 'active|regent|archived',
  'Los tres estados heráldicos canónicos quedan congelados (RF-07.4)',
);
assertCondition(
  Object.keys(LINEAGE_CHARGES).join('|') === 'fire|water|lightning|earth|wind|light|darkness|pureArcane',
  'Los ocho linajes canónicos tienen carga declarada (RF-07.2)',
);
assertCondition(
  Object.values(LINEAGE_CHARGES).filter((charge, index, all) => all.indexOf(charge) === index).length === 8,
  'Ninguna carga se repite entre linajes: ocho sellos distintos',
);
assertCondition(
  Object.keys(RULING_ELEMENT_COLORS).join('|') === 'fire|water|lightning|earth|wind|light|darkness|pureArcane'
    && Object.values(RULING_ELEMENT_COLORS).every((color) => /^var\(--color-affinity-[a-z]+\)$/.test(color)),
  'Cada afinidad rectora tiñe su carga con la paleta de afinidades del sistema (RF-07.1)',
);

/* =====================================================================
   [1] Determinismo: huella FNV-1a de 32 bits
   ===================================================================== */
console.log('\n[1] Determinismo de la huella FNV-1a de 32 bits');
const FNV_VECTORS = [
  ['', 0x811c9dc5],
  ['a', 0xe40c292c],
  ['foobar', 0xbf9cf968],
];
for (const [text, expected] of FNV_VECTORS) {
  assertCondition(
    runeSealHash(text) === expected,
    `La huella de ${text === '' ? '(cadena vacía)' : `«${text}»`} es 0x${expected.toString(16)} (vector FNV-1a)`,
  );
}
assertCondition(runeSealHash('rune-ignis') === runeSealHash('rune-ignis'), 'La misma casa forja siempre la misma huella (RF-07.2)');
assertCondition(runeSealHash('rune-ignis') !== runeSealHash('rune-aqua'), 'Dos blasones distintos no comparten huella');
assertCondition(runeSealHash('rune-ignis') >>> 0 === runeSealHash('rune-ignis'), 'La huella es un entero sin signo de 32 bits (sin NaN ni negativos)');

/* =====================================================================
   [2] La carga central: el linaje rector, dibujado
   ===================================================================== */
console.log('\n[2] La carga central declara el linaje (canon alquímico)');
const ELEMENTS_CANON = ['fire', 'water', 'lightning', 'earth', 'wind', 'light', 'darkness', 'pureArcane'];
const chargeSignatures = new Map();
for (const element of ELEMENTS_CANON) {
  const seal = forgeSeal({ houseName: `Casa de ${element}`, coatOfArms: `rune_${element}`, rulingElement: element });
  assertCondition(seal.getAttribute('data-heraldic-charge') === LINEAGE_CHARGES[element], `La carga de ${element} se declara en el sello (${LINEAGE_CHARGES[element]})`);
  assertCondition(
    seal.children.slice(11).every((node) => node.getAttribute('fill') === RULING_ELEMENT_COLORS[element]
      || node.getAttribute('stroke') === RULING_ELEMENT_COLORS[element]),
    `La carga de ${element} se tiñe con el token de su afinidad, jamás con un literal`,
  );
  // La geometría de la carga vive tras el disco, el anillo, las 8 muescas y la muesca maestra.
  const charge = seal.children.slice(11).map((node) => `${node.tagName}:${node.getAttribute('d') ?? node.getAttribute('cx') ?? ''}`);
  assertCondition(charge.length > 0, `La carga de ${element} dibuja geometría propia`);
  chargeSignatures.set(element, JSON.stringify(charge));
}
assertCondition(
  new Set(chargeSignatures.values()).size === 8,
  'Los ocho linajes forjan ocho cargas de geometría DISTINTA (ninguna se repite)',
);

// El canon alquímico: fuego y viento alzan el triángulo, agua y tierra lo hunden.
const fireCharge = chargeSignatures.get('fire');
const waterCharge = chargeSignatures.get('water');
assertCondition(
  fireCharge !== waterCharge && fireCharge.includes('M50 24') && waterCharge.includes('M50 76'),
  'Fuego (△) y agua (▽) siguen el canon alquímico, con signos opuestos',
);
assertCondition(
  chargeSignatures.get('earth').includes('H67') && chargeSignatures.get('wind').includes('H74'),
  'Tierra (▽ barrada) y viento (△ con barra) llevan su trazo distintivo',
);
assertCondition(
  forgeSeal({ houseName: 'Casa sin linaje', coatOfArms: 'rune_x', rulingElement: 'unknown' }).getAttribute('data-heraldic-charge') === 'ouroboros',
  'Un linaje desconocido cae en el ouroboros del Arcano Puro (respaldo)',
);

/* =====================================================================
   [3] El anillo de ocho muescas
   ===================================================================== */
console.log('\n[3] El anillo de ocho muescas codifica el blasón');
const sealA = forgeSeal({ houseName: 'Custodios de la Llama', coatOfArms: 'rune-ignis', rulingElement: 'fire' });
const tallyPaths = sealA.children.filter(
  (node) => node.tagName === 'PATH' && node.getAttribute('stroke') === 'var(--sigil-tick)',
);
assertCondition(tallyPaths.length === 8, 'El anillo forja exactamente ocho muescas, ni una más');
assertCondition(
  new Set(tallyPaths.map((node) => node.getAttribute('stroke-width'))).size > 1
    && tallyPaths.every((node) => ['1.2', '2', '3', '4'].includes(node.getAttribute('stroke-width'))),
  'El grosor de cada muesca sale de los bits de la huella (peso como dato, no adorno)',
);
const diamondMaster = sealA.children.filter(
  (node) => node.tagName === 'PATH' && node.getAttribute('d')?.includes('Z') && node.getAttribute('fill') === 'var(--sigil-ring-active)',
);
assertCondition(diamondMaster.length === 1, 'Una muesca maestra se remata en rombo de metal: el sello se lee hasta sin color');
const sealB = forgeSeal({ houseName: 'Mareas de Aether', coatOfArms: 'rune-aqua', rulingElement: 'water' });
/** Punto extremo (x, y) de una muesca, medido sobre su propio trazo. */
const tallyTip = (node) => {
  const [, , , x, y] = node.getAttribute('d').match(/M([\d.]+) ([\d.]+) L([\d.]+) ([\d.]+)/).map(Number);
  return { x, y };
};
/** ¿El rombo de metal remata exactamente la muesca que el sello señala? */
const masterIsMarked = (seal) => {
  const tallies = seal.children.filter((node) => node.tagName === 'PATH' && node.getAttribute('stroke') === 'var(--sigil-tick)');
  const index = seal.children.findIndex((node) => node.tagName === 'PATH' && node.getAttribute('fill') === 'var(--sigil-ring-active)');
  if (index < 0 || index === 0) return false;
  // El rombo nace en el extremo de su muesca: su vértice superior menos 3.4 es el centro.
  const diamondTop = Number(seal.children[index].getAttribute('d').slice(1).split(' ')[1]);
  const marked = tallies.filter((node) => {
    const tip = tallyTip(node);
    return Math.abs(tip.x - Number(seal.children[index].getAttribute('d').slice(1).split(' ')[0])) < 0.15
      && Math.abs(tip.y - (diamondTop + 3.4)) < 0.15;
  });
  return marked.length === 1 && seal.children[index - 1] === marked[0];
};
assertCondition(
  sealA.children.map((n) => n.getAttribute('d')).join('|') !== sealB.children.map((n) => n.getAttribute('d')).join('|'),
  'Dos casas distintas forjan anillos distintos (misma entrada, mismo sello; otra entrada, otro sello)',
);
assertCondition(masterIsMarked(sealA) && masterIsMarked(sealB), 'Cada sello señala su muesca maestra con el rombo de metal, en su propio sector');

/* =====================================================================
   [4] Estado por metal Y forma (RF-07.4)
   ===================================================================== */
console.log('\n[4] El estado se declara por metal y forma, jamás por color solo');
const activeSeal = forgeSeal({ houseName: 'Casa Viva', coatOfArms: 'rune_viva', rulingElement: 'wind', state: RUNE_SEAL_STATES.ACTIVE });
const regentSeal = forgeSeal({ houseName: 'Casa Regente', coatOfArms: 'rune_viva', rulingElement: 'wind', state: RUNE_SEAL_STATES.REGENT });
const archivedSeal = forgeSeal({ houseName: 'Casa Disuelta', coatOfArms: 'rune_viva', rulingElement: 'wind', state: RUNE_SEAL_STATES.ARCHIVED });

assertCondition(
  activeSeal.getAttribute('data-heraldic-state') === 'active'
    && regentSeal.getAttribute('data-heraldic-state') === 'regent'
    && archivedSeal.getAttribute('data-heraldic-state') === 'archived',
  'El sello publica su estado heráldico en el DOM (active | regent | archived)',
);
const ringOf = (seal) => seal.children.find(
  (node) => (node.tagName === 'CIRCLE' || node.tagName === 'PATH') && node.getAttribute('stroke')?.startsWith('var(--sigil-ring-'),
);
assertCondition(ringOf(activeSeal)?.getAttribute('stroke') === 'var(--sigil-ring-active)', 'Casa activa: anillo de oro antiguo');
assertCondition(ringOf(regentSeal)?.getAttribute('stroke') === 'var(--sigil-ring-regent)', 'Clan Regente: anillo de oro vivo');
assertCondition(ringOf(archivedSeal)?.getAttribute('stroke') === 'var(--sigil-ring-archived)', 'Casa disuelta: anillo de bronce');
assertCondition(
  ringOf(archivedSeal).tagName === 'PATH' && ringOf(archivedSeal).getAttribute('d')?.endsWith('92 50'),
  'Casa disuelta: el anillo aparece ROTO en su base (la forma también lo declara)',
);
assertCondition(ringOf(activeSeal).tagName === 'CIRCLE', 'Casa viva: el anillo cierra el círculo completo');
assertCondition(
  archivedSeal.children.find((node) => node.tagName === 'CIRCLE')?.getAttribute('opacity') === '0.82',
  'El disco de la casa disuelta se apaga sin perderse',
);
const waxCircles = regentSeal.children.filter(
  (node) => node.tagName === 'CIRCLE' && node.getAttribute('fill') === 'var(--sigil-wax)',
);
assertCondition(
  waxCircles.length === 1 && waxCircles[0].getAttribute('cx') === '50' && waxCircles[0].getAttribute('cy') === '8',
  'El Clan Regente presiona su sello de cera a las doce en punto',
);
assertCondition(
  activeSeal.children.filter((node) => node.getAttribute('fill') === 'var(--sigil-wax)').length === 0
    && archivedSeal.children.filter((node) => node.getAttribute('fill') === 'var(--sigil-wax)').length === 0,
  'Nadie más ostenta la cera: el honor no se hereda por accidente',
);
assertCondition(
  forgeSeal({ houseName: 'Casa', coatOfArms: 'r', state: 'corrupto' }).getAttribute('data-heraldic-state') === 'active',
  'Un estado hostil cae en casa activa: el sello jamás forja un metal inexistente',
);

/* =====================================================================
   [5] RF-07.3: el identificador se codifica, jamás se imprime
   ===================================================================== */
console.log('\n[5] El identificador técnico no se imprime jamás');
const identifierSeal = forgeSeal({
  houseName: 'Custodios de la Llama',
  coatOfArms: 'rune-ignis',
  rulingElement: 'fire',
  lineageName: 'Linaje de la Llama Primordial',
});
assertCondition(identifierSeal.textContent === '', 'El sello no imprime texto alguno: ni el blasón ni un glifo');
assertCondition(
  identifierSeal.getAttribute('aria-label').includes('rune-ignis') === false
    && identifierSeal.getAttribute('aria-label').includes('primordialFlame') === false,
  'El nombre accesible nombra la casa, jamás la clave técnica',
);
const hostileSeal = forgeSeal({
  houseName: 'Clan <script>alert(1)</script>',
  coatOfArms: '<svg onload=alert(1)>',
  rulingElement: 'fire',
});
assertCondition(
  hostileSeal.textContent === '' && descendants(hostileSeal).every((node) => node.textContent === ''),
  'Un blasón hostil viaja como huella numérica: no llega al DOM como texto',
);
assertCondition(
  descendants(hostileSeal).every((node) => Object.values(node.attributes).every((value) => !String(value).includes('onload'))),
  'Ningún atributo del sello transporta el blasón hostil (cero inyección)',
);
assertCondition(
  descendants(hostileSeal).every((node) => ['fill', 'stroke'].every((name) => {
    const value = node.getAttribute(name);
    return value === null || value === 'none' || value.startsWith('var(--');
  })),
  'Todo trazo del sello se viste con tokens: ninguna entrada hostil puede colarse como color',
);
assertCondition(
  forgeSeal({ houseName: 'Casa sin linaje', coatOfArms: 'r', rulingElement: '' }).children
    .slice(11).every((node) => [node.getAttribute('fill'), node.getAttribute('stroke')].includes('var(--sigil-tick)')),
  'Sin afinidad declarada, la carga cae en las muescas marfil del sistema',
);

/* =====================================================================
   [6] RF-07.5: contenido gráfico accesible y sobrio
   ===================================================================== */
console.log('\n[6] Accesibilidad y sobriedad del sello');
assertCondition(identifierSeal.getAttribute('role') === 'img', 'El sello se anuncia como contenido gráfico (role="img")');
assertCondition(identifierSeal.getAttribute('focusable') === 'false', 'El SVG no secuestra el foco del teclado');
assertCondition(
  identifierSeal.getAttribute('aria-label')
    === 'Sello heráldico de Custodios de la Llama, del Linaje de la Llama Primordial.',
  'La etiqueta nombra en castellano la casa y su linaje',
);
assertCondition(
  forgeSeal({ houseName: 'Casa Disuelta', coatOfArms: 'r', state: RUNE_SEAL_STATES.ARCHIVED }).getAttribute('aria-label')
    === 'Sello heráldico de Casa Disuelta; casa disuelta, conservada como Herencia Ancestral.',
  'La casa disuelta declara su Herencia Ancestral en el nombre accesible',
);
assertCondition(
  regentSeal.getAttribute('aria-label').includes('porta la corona dorada del Dominio durante los siete días de su mandato'),
  'El Clan Regente verbaliza el mandato de siete días en su etiqueta',
);
assertCondition(
  forgeSeal({ houseName: 'Linaje de las Mareas Celestiales', coatOfArms: 'r', role: 'lineage' }).getAttribute('aria-label')
    === 'Sello del Linaje de las Mareas Celestiales.',
  'El sello de un linaje se rotula como tal, sin inventarle una casa',
);
assertCondition(
  runeSealLabel({ role: 'lineage' }) === 'Sello del linaje no declarado.'
    && runeSealLabel({}) === 'Sello heráldico de una casa sin nombre.',
  'Sin nombre, el sello declara su vacío en lugar de imprimir un hueco',
);
assertCondition(
  identifierSeal.getAttribute('viewBox') === '0 0 100 100' && identifierSeal.getAttribute('class') === 'rune-seal',
  'El sello trae su lienzo y su clase canónica desde la forja',
);
assertCondition(
  !/setInterval|requestAnimationFrame|@keyframes|animation|<animate/.test(source),
  'El sello no anima: cero movimiento añadido (RF-07.5)',
);
assertCondition(!/filter\s*:|drop-shadow|blur\(|glow/.test(source), 'El sello no lleva resplandor: la sobriedad es deliberada');

/* =====================================================================
   [7] Dogma Vanilla y materia por tokens
   ===================================================================== */
console.log('\n[7] Dogma Vanilla de la materia del sello');
const imports = [...source.matchAll(/^import .*$/gm)].map((match) => match[0]);
assertCondition(imports.length === 0, 'El módulo no importa nada: cero dependencias (Artículo I)');
const externals = [...source.matchAll(/https?:\/\/[^\s'"`)]+/g)].map((match) => match[0]);
assertCondition(
  externals.every((url) => url === 'http://www.w3.org/2000/svg'),
  'Cero recursos externos: solo el espacio de nombres de SVG',
);
assertCondition(!/<img|image href|xlink:href/.test(source), 'El sello no arrastra un solo byte de imagen');
const colorLiterals = source.match(/#[0-9a-fA-F]{3,6}\b/g) ?? [];
assertCondition(colorLiterals.length === 0, 'Cero literales de color en la forja: la materia vive en tokens.css (RF-07.1)');
const usedColors = new Set();
for (const element of [identifierSeal, regentSeal, archivedSeal, forgeSeal({ houseName: 'Casa Solar', coatOfArms: 'r', rulingElement: 'light' })]) {
  for (const node of descendants(element)) {
    for (const value of Object.values(node.attributes)) {
      if (/^(var\(--|#)/.test(String(value))) usedColors.add(String(value));
    }
    if (node.getAttribute('style')) usedColors.add(node.getAttribute('style'));
  }
}
assertCondition(
  [...usedColors].every((color) => color.startsWith('var(--')),
  'Todo color del sello viaja como Custom Property del sistema de diseño',
);
const SIGIL_TOKENS = ['--sigil-disc', '--sigil-tick', '--sigil-ring-active', '--sigil-ring-regent', '--sigil-ring-archived', '--sigil-wax'];
assertCondition(
  SIGIL_TOKENS.every((token) => tokensCss.includes(`${token}:`)),
  'Los seis tokens de la materia del sello están declarados en tokens.css (RF-07.1)',
);
assertCondition(
  RULING_ELEMENT_NAMES.fire === 'Fuego' && RULING_ELEMENT_NAMES.pureArcane === 'Arcano Puro',
  'Los nombres ceremoniales de las afinidades hablan en noble castellano (Artículo V)',
);

/* =====================================================================
   Resumen
   ===================================================================== */
console.log(`\n== RESUMEN == Asertos superados: ${assertsPassed}, fallidos: ${assertsFailed}`);
if (assertsFailed === 0) {
  console.log('RESULTADO: EXITO — El Sello Rúnico se forja determinista, se lee sin color y jamás imprime su clave (SPEC-02 RF-07).');
  process.exit(0);
}
console.log('RESULTADO: DENEGADO — la heráldica forjada incumple el criterio de RF-07.');
process.exit(1);
