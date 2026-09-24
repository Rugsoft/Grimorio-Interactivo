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
assertCondition(findLeafByText(cardFlame.element, 'Fuego').length >= 1, 'Declara la afinidad rectora en noble castellano');
assertCondition(findLeafByText(cardFlame.element, 'fire').length === 0, 'La clave técnica del elemento jamás se imprime al adepto (Art. V)');
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

// --- FASE B2: Conmutador expandir/plegar y gesto vedado (RF-02.2, criterios ratificados) ---
console.log('\nFASE B2: La tarjeta es un conmutador; «Jurar» jamás procede contraída\n');
const swearCountBeforeToggle = expandedLineageIds.filter((entry) => entry === 'swear:primordialFlame').length;

// Activación real (clic sobre la tarjeta contraída): expande y notifica.
fire(cardFlame.element, 'click');
assertCondition(cardFlame.element.getAttribute('aria-expanded') === 'true', 'Una activación sobre la tarjeta contraída la expande');
assertCondition(expandedLineageIds.filter((entry) => entry === 'expand:primordialFlame').length === 2, 'La expansión por activación notifica `oath:lineage-expanded`');

// Segunda activación sobre la tarjeta YA expandida: la pliega (conmutador, no botón de una vía).
fire(cardFlame.element, 'click');
assertCondition(cardFlame.element.getAttribute('aria-expanded') === 'false' && cardFlame.isExpanded() === false, 'Una activación sobre la tarjeta expandida la PLEGA (conmutador expandir/plegar)');
assertCondition(findByClass(cardFlame.element, 'lineage-card__swear').every((button) => isHiddenNode(button)), 'El plegado oculta de nuevo el botón «Jurar»');

// Gesto vedado (capa componente): el botón vive DENTRO de la región de expansión,
// así que contraída está oculto y la activación de tarjeta no lo alcanza (RF-02.2).
// La guardia de expandedCard en la vista se exercise en la FASE G2.
fire(swearButton, 'click');
assertCondition(cardFlame.isExpanded() === false, 'El gesto vedado tampoco re-expande la tarjeta');

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

// =====================================================================
// FASES F–J: La vista de la ceremonia (Tarea 4.3)
// =====================================================================

console.log('\nFASES F–J: La vista orquestadora de la ceremonia (Tarea 4.3)\n');

const { createLineageOathView } = await import('../public/assets/js/views/lineageOathView.js');

/** Canon completo de 8 linajes para la vista (4 sin clanes activos). */
const FULL_CANON = [
  'primordialFlame', 'celestialTides', 'eternalTempest', 'worldRoots',
  'dawnWinds', 'solarCrown', 'abyssalShadows', 'aetherWeavers',
].map((id, index) => ({
  id,
  name: `Linaje ${index + 1} de prueba`,
  glyph: 'rune-x',
  bannerColor: '#101010',
  rulingElement: 'fire',
  doctrineCondensed: `Doctrina condensada ${index + 1}.`,
  doctrineFull: `Doctrina íntegra ${index + 1}, con su juramento solemne.`,
  hasActiveClans: index % 2 === 0,
}));

/** Cliente fingido del juramento con respuestas programables. */
function createFakeOathClient() {
  return {
    calls: { catalog: [], seal: [] },
    catalogResponse: { success: true, status: 200, data: { accountState: 'pilgrim', lineages: FULL_CANON } },
    sealResponse: { success: true, status: 200, data: { lineage: '', sealedNow: true, retainedRoute: null } },
    async fetchOathCatalog() {
      this.calls.catalog.push(1);
      return this.catalogResponse;
    },
    async sealOath(lineageId) {
      this.calls.seal.push(lineageId);
      return this.sealResponse;
    },
  };
}

/** Punto de montaje con replaceChildren y children reales. */
function createMountRoot() {
  const root = createFakeElement('main');
  root.replaceChildren = (...newChildren) => {
    for (const child of root.children) child.parentNode = null;
    root.children = [];
    for (const child of newChildren) root.appendChild(child);
  };
  return root;
}

/** Espera los microturnos que la vista usa para cargar. */
const wait = (ticks = 4) => new Promise((resolve) => setTimeout(resolve, 0)).then(() => new Promise((r) => setTimeout(r, ticks > 2 ? 0 : 0)));

/** Captura de eventos del plan §4 sobre el bus. */
function watchBus(bus) {
  const seen = [];
  const originalDispatch = bus.dispatchEvent.bind(bus);
  bus.dispatchEvent = (event) => {
    if (typeof event.type === 'string' && event.type.startsWith('oath:')) {
      seen.push({ type: event.type, detail: event.detail });
    }
    return originalDispatch(event);
  };
  return seen;
}

const oathClient = createFakeOathClient();
const mountRoot = createMountRoot();
const seenEvents = watchBus(mountRoot);
const view = createLineageOathView(mountRoot, {
  lineageOathClient: oathClient,
  documentRef: fakeDocument,
  elementFactory: fakeDocument.createElement,
  eventTarget: mountRoot,
});

// --- FASE F: Carga del canon y rejilla solemne (RF-02.1) ---
console.log('\nFASE F: Carga del canon y rejilla\n');
await view.render();
await wait();

assertCondition(oathClient.calls.catalog.length === 1, 'Una sola carga del canon por montaje (RNF-04)');
assertCondition(findByClass(mountRoot, 'lineage-card').length === 8, 'La rejilla despliega las 8 tarjetas heráldicas');
assertCondition(
  seenEvents.some((e) => e.type === 'oath:catalog-loaded' && e.detail?.lineages?.length === 8),
  'Emite oath:catalog-loaded con el canon (plan §4)',
);
assertCondition(findByClass(mountRoot, 'lineage-oath__failure').length === 0, 'Sin aviso de fallo con el canon en pie');

// --- FASE G: Flujo completo expandida → modal → sellado (RF-02.2/03.1) ---
console.log('\nFASE G: Flujo peregrino → sellado de punta a punta\n');
const cardFlameView = findByClass(mountRoot, 'lineage-card')
  .find((node) => node.getAttribute('data-lineage-id') === 'primordialFlame');
fire(cardFlameView, 'click'); // expande
oathClient.sealResponse = { success: true, status: 200, data: { lineage: 'primordialFlame', sealedNow: true, retainedRoute: '#/creador' } };

// El botón Jurar de la tarjeta expandida convoca al modal; el modal forjado
// por la vista vive sobre un dialog propio dentro del montaje.
const swearButtonView = findByClass(cardFlameView, 'lineage-card__swear')[0];
fire(swearButtonView, 'click');
const viewDialog = findByClass(mountRoot, 'modal--oath')[0] ?? null;
assertCondition(viewDialog !== null, 'La vista monta su modal solemne sobre un dialog propio');
const oathTextElement = findByClass(viewDialog, 'oath-modal__oath-text')[0] ?? null;
assertCondition(
  oathTextElement !== null && (oathTextElement.textContent ?? '').includes('Linaje 1 de prueba'),
  'El modal nombra al linaje elegido con el MISMO texto canónico (RF-02.2)',
);

// La segunda confirmación dispara el sellado y el veredicto.
fire(findByClass(viewDialog, 'oath-modal__seal')[0], 'click');
await wait();
assertCondition(oathClient.calls.seal.length === 1 && oathClient.calls.seal[0] === 'primordialFlame', 'La vista invoca sealOath con el linaje exacto');
const sealedEvent = seenEvents.find((e) => e.type === 'oath:sealed');
assertCondition(sealedEvent !== undefined, 'Emite oath:sealed (plan §4)');
assertCondition(
  sealedEvent?.detail?.lineage === 'primordialFlame' && sealedEvent?.detail?.retainedRoute === '#/creador',
  'El veredicto porta { lineage, retainedRoute } exactos (RF-03.1)',
);

// El descarte previo al sellado no consume nada.
fire(findByClass(mountRoot, 'lineage-card')[1], 'click'); // expande Mareas
fire(findByClass(findByClass(mountRoot, 'lineage-card')[1], 'lineage-card__swear')[0], 'click');
fire(findByClass(viewDialog, 'oath-modal__dismiss')[0], 'click');
await wait();
assertCondition(oathClient.calls.seal.length === 1, 'El descarte del modal no invoca sealOath (RF-02.3)');

// --- FASE G2: Gesto vedado sobre tarjeta contraída (RF-02.2 ratificado) ---
console.log('\nFASE G2: «Jurar» sobre tarjeta CONTRAÍDA jamás convoca el modal\n');
// El visor fingido no implementa la propiedad `open` de <dialog>: el estado del
// modal se lee por su región viva (`oath-modal__announce`), que muta con cada
// apertura y descarte (leyendas canónicas de oathModalComponent).
const tidesCardView = findByClass(mountRoot, 'lineage-card').find((node) => node.getAttribute('data-lineage-id') === 'celestialTides');
fire(tidesCardView, 'click'); // pliega (conmutador)
assertCondition(tidesCardView.getAttribute('aria-expanded') === 'false', 'La tarjeta Mareas queda contraída tras el plegado');
const sealCallsBeforeVedado = oathClient.calls.seal.length;
fire(findByClass(tidesCardView, 'lineage-card__swear')[0], 'click');
await wait();
const announceVedado = findByClass(viewDialog, 'oath-modal__announce')[0]?.textContent ?? '';
assertCondition(!announceVedado.includes('abierto'), 'El modal NO convoca sobre tarjeta contraída');
assertCondition(oathClient.calls.seal.length === sealCallsBeforeVedado, 'El gesto vedado no consume juramento alguno');
// La ceremonia permanece operativa: la misma tarjeta re-expandida SÍ convoca.
fire(tidesCardView, 'click');
fire(findByClass(tidesCardView, 'lineage-card__swear')[0], 'click');
await wait();
const announceReabierto = findByClass(viewDialog, 'oath-modal__announce')[0]?.textContent ?? '';
assertCondition(announceReabierto.includes('abierto'), 'Re-expandida, la misma tarjeta SÍ convoca el modal (ceremonia operativa)');
const dismissButton = findByClass(viewDialog, 'oath-modal__dismiss')[0] ?? null;
if (dismissButton) fire(dismissButton, 'click');
await wait();

// --- FASE H: Fallo del canon — aviso + reintento, retención intacta ---
console.log('\nFASE H: El canon no responde (RF-02.1, RF-05.1)\n');
const clientH = createFakeOathClient();
clientH.catalogResponse = { success: false, status: 0, error: { code: 'networkError', message: 'corriente interrumpida' } };
const mountH = createMountRoot();
const eventsH = watchBus(mountH);
const viewH = createLineageOathView(mountH, {
  lineageOathClient: clientH,
  documentRef: fakeDocument,
  elementFactory: fakeDocument.createElement,
  eventTarget: mountH,
});
await viewH.render();
await wait();
assertCondition(findByClass(mountH, 'lineage-oath__failure').length === 1, 'El aviso solemne «El canon no responde» se despliega');
assertCondition(findByClass(mountH, 'lineage-card').length === 0, 'Sin canon no hay tarjetas (sin juramento sin datos, RF-05.1)');
assertCondition(eventsH.some((e) => e.type === 'oath:failed'), 'Emite oath:failed ante el fallo de canon');

// El reintento con canon restaurado despliega la ceremonia.
clientH.catalogResponse = { success: true, status: 200, data: { accountState: 'pilgrim', lineages: FULL_CANON } };
fire(findByClass(mountH, 'lineage-oath__retry')[0], 'click');
await wait();
assertCondition(findByClass(mountH, 'lineage-card').length === 8, 'El reintento con canon restaurado despliega las 8 tarjetas');
assertCondition(findByClass(mountH, 'lineage-oath__failure')[0]?.hasAttribute?.('hidden') !== false, 'El aviso se retira al recuperar el canon');

// --- FASE I: Fallo del sellado — ceremonia operativa (RF-03.2) ---
console.log('\nFASE I: Fallo del sellado y reintento\n');
const clientI = createFakeOathClient();
clientI.sealResponse = { success: false, status: 500, error: { code: 'networkError', message: 'error interno' } };
const mountI = createMountRoot();
const eventsI = watchBus(mountI);
const viewI = createLineageOathView(mountI, {
  lineageOathClient: clientI,
  documentRef: fakeDocument,
  elementFactory: fakeDocument.createElement,
  eventTarget: mountI,
});
await viewI.render();
await wait();
const cardI = findByClass(mountI, 'lineage-card')[2];
fire(cardI, 'click');
fire(findByClass(cardI, 'lineage-card__swear')[0], 'click');
fire(findByClass(findByClass(mountI, 'modal--oath')[0], 'oath-modal__seal')[0], 'click');
await wait();
assertCondition(findByClass(mountI, 'lineage-oath__failure').length === 1, 'El fallo del sellado muestra el aviso solemne (sin trazas)');
assertCondition(eventsI.some((e) => e.type === 'oath:failed' && e.detail?.code === 'networkError'), 'Emite oath:failed con el código del contrato');
assertCondition(findByClass(mountI, 'lineage-card').length === 8, 'La ceremonia permanece operativa para el reintento (RF-03.2)');

// Reintento del sellado tras el fallo: la ceremonia sigue operativa, se
// reabre el modal sobre la tarjeta expandida y ahora el sellado es feliz.
clientI.sealResponse = { success: true, status: 200, data: { lineage: 'eternalTempest', sealedNow: true, retainedRoute: null } };
fire(findByClass(cardI, 'lineage-card__swear')[0], 'click');
fire(findByClass(findByClass(mountI, 'modal--oath')[0], 'oath-modal__seal')[0], 'click');
await wait();
const sealedI = eventsI.filter((e) => e.type === 'oath:sealed');
assertCondition(sealedI.length === 1 && sealedI[0].detail?.retainedRoute === null, 'El reintento sella y conduce al portal (retainedRoute null)');

// --- FASE J: 401 y limpieza (RF-03.2, RNF-05) ---
console.log('\nFASE J: Sesión caducada y desmontaje limpio\n');
const clientJ = createFakeOathClient();
clientJ.sealResponse = { success: false, status: 401, error: { code: 'SESSION_EXPIRED', message: 'sesión expirada' } };
const mountJ = createMountRoot();
const viewJ = createLineageOathView(mountJ, {
  lineageOathClient: clientJ,
  documentRef: fakeDocument,
  elementFactory: fakeDocument.createElement,
  eventTarget: mountJ,
});
await viewJ.render();
await wait();
const cardJ = findByClass(mountJ, 'lineage-card')[0];
fire(cardJ, 'click');
fire(findByClass(cardJ, 'lineage-card__swear')[0], 'click');
fire(findByClass(findByClass(mountJ, 'modal--oath')[0], 'oath-modal__seal')[0], 'click');
await wait();
const failureJ = findByClass(mountJ, 'lineage-oath__failure')[0] ?? null;
assertCondition(
  failureJ !== null && (failureJ._legendElement?.textContent ?? '').includes('expirado'),
  'La sesión caducada produce su aviso solemne de reautenticación (RF-03.2)',
);

// El desmontaje libera las tarjetas y deja inertes las respuestas tardías.
viewJ.destroy();
assertCondition(findByClass(mountJ, 'lineage-card').length === 0, 'destroy() desmonta la ceremonia sin huérfanos');

// --- FASE K: El Velo Arcano — inspección de estilos (Tarea 4.4, RNF-01/05) ---
console.log('\nFASE K: La hoja de la ceremonia viste tokens y respeta AA\n');

const { readFileSync } = await import('node:fs');
const css = readFileSync(new URL('../public/assets/css/components/lineage-oath.css', import.meta.url), 'utf8');
const tokensCss = readFileSync(new URL('../public/assets/css/tokens.css', import.meta.url), 'utf8');

// 1) Cero literales de color en la forja: la paleta vive en tokens.css.
const hexLiterals = css.match(/#[0-9a-fA-F]{3,8}\b/g) ?? [];
assertCondition(hexLiterals.length === 0, `Sin literales de color en la hoja (RNF-01): ${hexLiterals.length} hallados`);

// 2) Toda Custom Property usada existe en tokens.css (o es dato del DTO).
const usedVars = [...css.matchAll(/var\(--([a-z0-9-]+)/g)].map((m) => m[1]);
const declaredTokens = new Set([...tokensCss.matchAll(/--([a-z0-9-]+)\s*:/g)].map((m) => m[1]));
// --lineage-banner es DATO heráldico del DTO (bannerColor), no un token.
const missingVars = [...new Set(usedVars)].filter((v) => v !== 'lineage-banner' && !declaredTokens.has(v));
assertCondition(missingVars.length === 0, `Toda var(--x) existe en tokens.css: faltan ${JSON.stringify(missingVars)}`);

// 3) Clases de la vista: cada clase usada por el JS viste en la hoja.
const viewSource = readFileSync(new URL('../public/assets/js/views/lineageOathView.js', import.meta.url), 'utf8');
const usedClasses = [...new Set([...viewSource.matchAll(/lineage-oath__[a-z-]+/g)].map((m) => m[0]))];
const missingClasses = usedClasses.filter((c) => !css.includes(`.${c}`));
assertCondition(missingClasses.length === 0, `Todas las clases de la vista visten: faltan ${JSON.stringify(missingClasses)}`);

// 4) Foco visible con el token de contraste (RNF-05) y objetivo táctil.
assertCondition(css.includes('.lineage-card:focus-visible') && css.includes('var(--color-parchment-border-focus)'), 'La tarjeta declara :focus-visible con el token dorado (RNF-05)');
assertCondition(css.includes('.lineage-card__swear') && css.includes('var(--touch-target-min)'), 'El gesto «Jurar» porta objetivo táctil completo');

// 5) Movimiento reducido: toda transición/animación queda neutralizada.
const reduceBlock = css.slice(css.indexOf('@media (prefers-reduced-motion'));
const transitionDecls = (css.match(/transition:[^;]+;/g) ?? []).length;
assertCondition(transitionDecls > 0 && reduceBlock.includes('transition: none'), 'Toda transición tiene contrapartida none en reduced-motion (RNF-05)');
assertCondition(reduceBlock.includes('animation: none'), 'La entrada solemne del modal se apaga en reduced-motion');

// 6) Contraste AA de los pares canónicos de la ceremonia (tokens vs fondos).
function luminance(hex) {
  const channels = hex.replace('#', '').match(/../g).map((h) => parseInt(h, 16) / 255)
    .map((v) => (v <= 0.03928 ? v / 12.92 : ((v + 0.055) / 1.055) ** 2.4));
  return 0.2126 * channels[0] + 0.7152 * channels[1] + 0.0722 * channels[2];
}
function contrastRatio(foreground, background) {
  const [l1, l2] = [luminance(foreground), luminance(background)].sort((a, b) => b - a);
  return (l1 + 0.05) / (l2 + 0.05);
}
function tokenValue(tokenName) {
  const match = tokensCss.match(new RegExp(`--${tokenName}:\\s*(${['#[0-9a-fA-F]{6}', 'var\\([^)]+\\)'].join('|')})`));
  return match?.[1] ?? null;
}
// Los pares: texto/acentos de la ceremonia sobre sus fondos de obsidiana.
const contrastPairs = [
  ['Título dorado sobre la estela', tokenValue('color-gold-arcane'), tokenValue('color-bg-obsidian-surface')],
  ['Cuerpo sobre la estela', tokenValue('color-text-secondary'), tokenValue('color-bg-obsidian-surface')],
  ['Aviso muted sobre la estela', tokenValue('color-text-light-muted'), tokenValue('color-bg-obsidian-surface')],
];
const contrastFailures = contrastPairs
  .filter(([, fg, bg]) => fg?.startsWith('#') && bg?.startsWith('#') && contrastRatio(fg, bg) < 4.5);
assertCondition(contrastFailures.length === 0, `Contraste AA ≥ 4.5:1 en los pares canónicos: fallan ${contrastFailures.length}`);

assertCondition(uncaughtErrors === 0, `Ninguna excepción escapó sin control (${uncaughtErrors} cazadas)`);

console.log(`\n== RESUMEN == Asertos superados: ${assertsPassed}, fallidos: ${assertsFailed}`);
if (assertsFailed === 0 && uncaughtErrors === 0) {
  console.log('RESULTADO: EXITO — La ceremonia carga el canon, despliega las 8 tarjetas, sella de punta a punta con veredicto { lineage, retainedRoute }, avisa sin liberar retención, queda operativa ante fallos y viste el Velo Arcano con tokens y AA (Tareas 4.1+4.3+4.4).');
  process.exit(0);
}
console.log('RESULTADO: DENEGADO — Corregir los asertos en rojo antes de continuar.');
process.exit(1);
