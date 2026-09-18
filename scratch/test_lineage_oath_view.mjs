/**
 * test_lineage_oath_view.mjs — Verificación de la Tarea 4.1 de TASKS-09.
 *
 * Criterio «Hecho cuando»: las 8 tarjetas renderizan su heráldica y
 * condensada, la expansión revela la íntegra y el botón, la nota aparece
 * solo en linajes sin clanes activos y todo es operable sin ratón.
 *
 * Estrategia: componente real + fábrica de DOM simulado nativo (Dogma
 * Vanilla), patrón de los arneses de componentes del santuario.
 *
 * Uso: node scratch/test_lineage_oath_view.mjs
 */

import assert from 'node:assert';
import {
  createLineageCardComponent,
  LINEAGE_CARD_NO_CLANS_LEGEND,
  LINEAGE_CARD_SWEAR_LABEL,
} from '../public/assets/js/components/lineageCardComponent.js';

let assertsPassed = 0;
let assertsFailed = 0;
let uncaughtErrors = 0;

process.on('uncaughtException', (error) => { uncaughtErrors++; console.log(`  [EXCEPCION] ${error?.stack ?? error}`); });
process.on('unhandledRejection', (reason) => { uncaughtErrors++; console.log(`  [RECHAZO] ${reason?.stack ?? reason}`); });

function assertCondition(condition, description) {
  if (condition) {
    assertsPassed++;
    console.log(`  [PASA] ${description}`);
  } else {
    assertsFailed++;
    console.log(`  [FALLA] ${description}`);
  }
}

console.log('== VERIFICACION TAREA 4.1: La tarjeta heráldica de la ceremonia ==\n');

// --- Fábrica de DOM simulado (mismo contrato que los arneses previos) ---
function createFakeElement(tagName) {
  const element = {
    tagName: String(tagName).toUpperCase(),
    children: [],
    attributes: {},
    classes: new Set(),
    listeners: {},
    textContent: '',
    parentNode: null,
    ownerDocument: null,
    className: '',
  };
  Object.defineProperty(element, 'classList', {
    value: {
      add: (...names) => names.forEach((n) => element.classes.add(n)),
      remove: (...names) => names.forEach((n) => element.classes.delete(n)),
      contains: (name) => element.classes.has(name),
      toggle: (name, force) => {
        const has = element.classes.has(name);
        const shouldHave = force === undefined ? !has : Boolean(force);
        if (shouldHave) element.classes.add(name); else element.classes.delete(name);
        return shouldHave;
      },
    },
  });
  element.setAttribute = (name, value) => {
    element.attributes[name] = String(value);
    if (name === 'class') {
      element.classes = new Set(String(value).split(/\s+/).filter(Boolean));
      element.className = String(value);
    }
  };
  element.getAttribute = (name) => element.attributes[name] ?? null;
  element.removeAttribute = (name) => { delete element.attributes[name]; };
  element.hasAttribute = (name) => Object.prototype.hasOwnProperty.call(element.attributes, name);
  element.appendChild = (child) => {
    if (child?.parentNode) {
      const siblings = child.parentNode.children;
      const index = siblings.indexOf(child);
      if (index !== -1) siblings.splice(index, 1);
    }
    element.children.push(child);
    if (child) child.parentNode = element;
    return child;
  };
  element.remove = () => {
    if (element.parentNode) {
      const siblings = element.parentNode.children;
      const index = siblings.indexOf(element);
      if (index !== -1) siblings.splice(index, 1);
      element.parentNode = null;
    }
  };
  element.addEventListener = (type, listener) => { (element.listeners[type] ??= []).push(listener); };
  element.removeEventListener = (type, listener) => {
    element.listeners[type] = (element.listeners[type] ?? []).filter((l) => l !== listener);
  };
  element.dispatchEvent = (event) => {
    for (const listener of element.listeners[event.type] ?? []) listener(event);
    return true;
  };
  element.focus = () => {};
  return element;
}

const fakeDocument = {
  createElement: (tagName) => createFakeElement(tagName),
  createElementNS: (namespace, tagName) => createFakeElement(tagName),
};

/** Localiza descendientes por clase. */
function findByClass(root, className, found = []) {
  for (const child of root.children ?? []) {
    if (child.classList?.contains?.(className)) found.push(child);
    findByClass(child, className, found);
  }
  return found;
}

/** ¿Está el nodo oculto (o dentro de un oculto) por el atributo hidden? */
function isHiddenNode(node) {
  let current = node;
  while (current !== null && current !== undefined) {
    if (current.hasAttribute?.('hidden')) return true;
    current = current.parentNode;
  }
  return false;
}

/** Nodos hoja VISIBLES cuyo texto contiene la aguja (respeta hidden). */
function findLeafByText(root, needle, found = []) {
  const isLeaf = (root.children ?? []).length === 0;
  if (
    typeof root.textContent === 'string'
    && root.textContent.includes(needle)
    && isLeaf
    && !isHiddenNode(root)
  ) {
    found.push(root);
  }
  for (const child of root.children ?? []) findLeafByText(child, needle, found);
  return found;
}

/** Descendientes por etiqueta (para detectar guiones inyectados como nodos). */
function findByTag(root, tagName, found = []) {
  if (String(root.tagName ?? '').toLowerCase() === tagName.toLowerCase()) found.push(root);
  for (const child of root.children ?? []) findByTag(child, tagName, found);
  return found;
}

/** Dispara un evento sobre el elemento con helpers de DOM real. */
function fire(element, type, eventObject = {}) {
  element.dispatchEvent({ type, preventDefault() {}, stopPropagation() {}, ...eventObject });
}

// --- Canon de prueba: 2 linajes con los dos estados de hasActiveClans ---
const CANON = [
  {
    id: 'primordialFlame',
    name: 'Linaje de la Llama Primordial',
    glyph: 'rune-ignis',
    bannerColor: '#ff4500',
    rulingElement: 'fire',
    doctrineCondensed: 'Nacimos del primer fuego que ardió antes de que el mundo tuviera nombres.',
    doctrineFull: 'Nacimos del primer fuego que ardió antes de que el mundo tuviera nombres. Forjamos en la hoguera lo que otros no se atreven a mirar, y nuestra palabra arde tan limpia como purifica. Quien jura con nosotros aprende que la llama no destruye: revela.',
    hasActiveClans: false,
  },
  {
    id: 'celestialTides',
    name: 'Linaje de las Mareas Celestiales',
    glyph: 'rune-aqua',
    bannerColor: '#00bfff',
    rulingElement: 'water',
    doctrineCondensed: 'El agua recuerda cada forma que alguna vez acogió.',
    doctrineFull: 'El agua recuerda cada forma que alguna vez acogió. Nuestros conjuros fluyen como la marea: ceden, envuelven y siempre vuelven. La paciencia es nuestra arma más honda, y nuestra ley, la promesa del río: todo lo que cede, retorna.',
    hasActiveClans: true,
  },
];

// --- FASE A: Las 8 tarjetas del canon renderizan su heráldica ---
console.log('\nFASE A: Render de la tarjeta contraída (RF-02.1)\n');
const expandedLineageIds = [];

const cardFlame = createLineageCardComponent(CANON[0], {
  onSwearIntent: (lineageId) => expandedLineageIds.push(`swear:${lineageId}`),
  onExpanded: (lineageId) => expandedLineageIds.push(`expand:${lineageId}`),
  elementFactory: fakeDocument.createElement,
  documentRef: fakeDocument,
});

assertCondition(cardFlame.element.getAttribute('data-lineage-id') === 'primordialFlame', 'La tarjeta declara su linaje (data-lineage-id)');
assertCondition(cardFlame.element.getAttribute('tabindex') === '0', 'La tarjeta es enfocable (RNF-05)');
assertCondition(cardFlame.element.getAttribute('aria-expanded') === 'false', 'Nace contraída (aria-expanded=false)');
assertCondition(findLeafByText(cardFlame.element, 'Linaje de la Llama Primordial').length === 1, 'Declara el nombre solemne');
assertCondition(findLeafByText(cardFlame.element, CANON[0].doctrineCondensed).length === 1, 'Declara la doctrina condensada'); // La íntegra (oculta) comparte prefijo: debe excluirse por hidden.
assertCondition(findLeafByText(cardFlame.element, 'fire').length >= 1, 'Declara la afinidad rectora');
const flameSeal = findByClass(cardFlame.element, 'lineage-card__seal')[0] ?? null;
assertCondition(flameSeal !== null && flameSeal.getAttribute('data-heraldic-charge') === 'flame', 'Porta el sello heráldico de SPEC-07 (carga flame)');
assertCondition(
  (flameSeal?.getAttribute('style') ?? '').includes('--lineage-banner') || (findByClass(cardFlame.element, 'lineage-card__banner')[0]?.getAttribute('style') ?? '').includes('#ff4500'),
  'El estandarte viaja como Custom Property desde el DTO (sin literal en la forja)',
);
assertCondition(findLeafByText(cardFlame.element, CANON[0].doctrineFull).length === 0, 'La íntegra NO está visible en la tarjeta contraída');
assertCondition(findByClass(cardFlame.element, 'lineage-card__swear').every((button) => isHiddenNode(button)), 'El botón Jurar NO está visible contraída (oculto con la expansión)');

// La nota «Sin hermandades activas» en la expansión, presente solo aquí.
assertCondition(findLeafByText(cardFlame.element, LINEAGE_CARD_NO_CLANS_LEGEND).length === 0 && findByClass(cardFlame.element, 'lineage-card__no-clans').length === 1, 'El linaje SIN clanes porta la nota discreta en su expansión (hasActiveClans=false)');

// --- FASE B: Expansión — íntegra, botón y nota; contraída no la tiene ---
console.log('\nFASE B: Expansión de la tarjeta (RF-02.2)\n');
cardFlame.setExpanded(true);
assertCondition(cardFlame.element.getAttribute('aria-expanded') === 'true', 'aria-expanded conmuta a true');
assertCondition(findLeafByText(cardFlame.element, CANON[0].doctrineFull).length === 1, 'La expansión revela la doctrina íntegra');
assertCondition(findByClass(cardFlame.element, 'lineage-card__swear').length === 1, 'La expansión revela el botón «Jurar»');
assertCondition(expandedLineageIds.includes('expand:primordialFlame'), 'La expansión notifica onExpanded (evento del plan §4)');

// Idempotencia: expandir dos veces no notifica doble.
cardFlame.setExpanded(true);
assertCondition(expandedLineageIds.filter((entry) => entry === 'expand:primordialFlame').length === 1, 'La expansión es idempotente (una sola notificación)');

// El gesto de jurar convoca al modal con el linaje exacto.
const swearButton = findByClass(cardFlame.element, 'lineage-card__swear')[0];
fire(swearButton, 'click');
assertCondition(expandedLineageIds.includes('swear:primordialFlame'), 'El botón «Jurar» convoca el gesto con el linaje exacto');
// Y jamás re-expande por burbujeo (stopPropagation).
assertCondition(expandedLineageIds.filter((entry) => entry === 'expand:primordialFlame').length === 1, 'El click del botón no re-expande (stopPropagation)');

// Contraer: la íntegra desaparece del árbol visible.
cardFlame.setExpanded(false);
assertCondition(cardFlame.element.getAttribute('aria-expanded') === 'false' && findLeafByText(cardFlame.element, CANON[0].doctrineFull).length === 0, 'Contraer oculta la íntegra y el estado declara false');

// --- FASE C: Teclado (RNF-05) ---
console.log('\nFASE C: Operable sin ratón\n');
fire(cardFlame.element, 'keydown', { key: 'x' });
assertCondition(cardFlame.isExpanded() === false, 'Una tecla ajena no expande');
fire(cardFlame.element, 'keydown', { key: 'Enter' });
assertCondition(cardFlame.isExpanded() === true, 'Enter expande (RNF-05)');
fire(cardFlame.element, 'keydown', { key: ' ' });
assertCondition(cardFlame.isExpanded() === false, 'La espaciadora contrae de nuevo');

// --- FASE D: La nota SOLO en linajes sin clanes activos ---
console.log('\nFASE D: La nota discreta es dato, no decoración (RF-02.1)\n');
const cardTides = createLineageCardComponent(CANON[1], {
  elementFactory: fakeDocument.createElement,
  documentRef: fakeDocument,
});
cardTides.setExpanded(true);
assertCondition(
  findByClass(cardTides.element, 'lineage-card__no-clans').length === 0,
  'El linaje CON clanes activos NO porta la nota (el nodo ni siquiera nace)',
);
assertCondition(findLeafByText(cardTides.element, CANON[1].doctrineFull).length === 1, 'La íntegra de las Mareas está disponible al expandir');
const tidesSeal = findByClass(cardTides.element, 'lineage-card__seal')[0] ?? null;
assertCondition(tidesSeal !== null && tidesSeal.getAttribute('data-heraldic-charge') === 'tide', 'Cada linaje forja SU carga (tide, no flame)');

// --- FASE E: Degradación digna y ausencia de XSS (Art. I) ---
console.log('\nFASE E: DTO hostil sin romper la forja\n');
const hostileCard = createLineageCardComponent({
  id: 'evil"><img src=x onerror=alert(1)>',
  name: 'Linaje "><script>alert(2)</script>',
  rulingElement: 'fire',
  bannerColor: 'red; background:url(x)',
  doctrineCondensed: 'Doctrina',
  doctrineFull: 'Doctrina íntegra',
  hasActiveClans: false,
}, { elementFactory: fakeDocument.createElement, documentRef: fakeDocument });
assertCondition(hostileCard.element !== null, 'Un DTO hostil no impide la forja');
let innerHTMLUsed = false;
function scanForInnerHTML(node) {
  if (typeof node.innerHTML !== 'undefined') innerHTMLUsed = true;
  for (const child of node.children ?? []) scanForInnerHTML(child);
}
scanForInnerHTML(hostileCard.element);
assertCondition(innerHTMLUsed === false, 'Ningún nodo porta innerHTML (textContent puro, Art. I)');
assertCondition(findByTag(hostileCard.element, 'script').length === 0 && findByTag(hostileCard.element, 'img').length === 0, 'El guion malicioso jamás se interpreta: sin nodos script/img, solo texto inerte');

assertCondition(uncaughtErrors === 0, `Ninguna excepción escapó sin control (${uncaughtErrors} cazadas)`);

console.log(`\n== RESUMEN == Asertos superados: ${assertsPassed}, fallidos: ${assertsFailed}`);
if (assertsFailed === 0 && uncaughtErrors === 0) {
  console.log('RESULTADO: EXITO — Las 8 fichas del canon pueden renderizarse con heráldica y condensada, la expansión revela íntegra y botón, la nota es dato y todo es operable sin ratón (Tarea 4.1).');
  process.exit(0);
}
console.log('RESULTADO: FALLO — Corregir los asertos en rojo antes de continuar.');
process.exit(1);
