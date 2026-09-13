/**
 * test_grimoire_book_component.mjs — Arnés TDD de la Tarea 4.2 (TASKS-05).
 *
 * Estrategia TDD: este script se escribe ANTES que la implementación.
 * Verifica, sobre `public/assets/js/components/grimoireBookComponent.js`:
 *   [1] Fábrica y doble página (RF-01.1): página izquierda con iluminación,
 *       metadatos, componentes y fórmula; página derecha con el contenedor
 *       de la Cámara de Conjuración.
 *   [2] Exhibición de página (RF-01.5): nombre, Círculo, afinidad, tiempo
 *       de lanzamiento, coste de maná, fórmula litúrgica y descripción.
 *   [3] Navegación acotada (RF-01.3/01.4): next/previous mueven la página,
 *       se desvanecen rúnicamente en los extremos (sin bucle infinito) y
 *       emiten `grimoire:page-change`.
 *   [4] Atajos de teclado (RF-01.3): flechas izquierda/derecha pasan página.
 *   [5] Pergamino virgen (RF-01.4): catálogo vacío muestra la leyenda
 *       ceremonial y desactiva ambas flechas.
 *   [6] Índice rúnico (RF-01.4): filtros por Círculo y Afinidad aplicados
 *       a través del cliente inyectado.
 *   [7] Seguridad: todo el contenido viaja por textContent (AGENTS.md 6.1).
 *
 * Constitución:
 *   - Artículo I: ES Modules nativos, DOM simulado sin frameworks.
 *   - Artículo IV/V: leyendas solemnes castellanas; API en inglés camelCase.
 *
 * Uso: node scratch/test_grimoire_book_component.mjs
 */

let assertsPassed = 0;
let assertsFailed = 0;

function assertCondition(condition, description) {
  if (condition) {
    assertsPassed++;
    console.log(`  [PASA] ${description}`);
  } else {
    assertsFailed++;
    console.log(`  [FALLA] ${description}`);
  }
}

/** Elemento DOM mínimo simulado (patrón consolidado; innerHTML prohibido). */
function createFakeElement(tagName) {
  const element = {
    tagName: String(tagName).toUpperCase(),
    children: [],
    attributes: {},
    classes: new Set(),
    listeners: {},
    style: {
      setProperty(name, value) { (this.inline ??= {})[name] = String(value); },
      getProperty(name) { return (this.inline ?? {})[name] ?? null; },
    },
    _textContent: '',
    disabled: false,
    parentElement: null,
    setAttribute(name, value) { this.attributes[name] = String(value); },
    getAttribute(name) { return name in this.attributes ? this.attributes[name] : null; },
    addEventListener(name, listener) { (this.listeners[name] ??= []).push(listener); },
    appendChild(child) { child.parentElement = this; this.children.push(child); return child; },
    dispatch(name, event = {}) { (this.listeners[name] ?? []).forEach((l) => l(event)); },
    set className(value) { this.classes = new Set(String(value).split(/\s+/).filter(Boolean)); },
    get className() { return [...this.classes].join(' '); },
    get textContent() { return this._textContent; },
    set textContent(value) { this._textContent = String(value); },
    get innerHTML() { throw new Error('PROHIBIDO innerHTML (AGENTS.md 6.1)'); },
    set innerHTML(value) { throw new Error('PROHIBIDO innerHTML (AGENTS.md 6.1)'); },
  };
  element.classList = {
    _owner: element,
    add(...names) { names.forEach((n) => this._owner.classes.add(n)); },
    remove(...names) { names.forEach((n) => this._owner.classes.delete(n)); },
    contains(name) { return this._owner.classes.has(name); },
    toggle(name, force) {
      if (force === undefined) { this._owner.classes.has(name) ? this._owner.classes.delete(name) : this._owner.classes.add(name); return; }
      force ? this._owner.classes.add(name) : this._owner.classes.delete(name);
    },
  };
  return element;
}

function queryByClass(node, className, found = []) {
  for (const child of node.children) {
    if (child.classes.has(className)) found.push(child);
    queryByClass(child, className, found);
  }
  return found;
}

/** Texto acumulado del subárbol (semántica del textContent real). */
function subtreeText(node) {
  let text = node.textContent ?? '';
  for (const child of node.children) text += subtreeText(child);
  return text;
}

/** Catálogo de prueba (3 conjuros + estados derivados). */
const CATALOG = [
  {
    id: 'spl_1', name: 'Ardor del Alba', circle: 1, elementalAffinity: 'fire',
    manaCost: 15, castingTime: 'action', incantationFormula: '¡Llamas del alba, descended!',
    description: 'Ascuas del alba que abrazan al blanco.', status: 'validated',
    effects: { damage: 20, healing: 0, barrier: 0, crowdControlType: 'none' },
  },
  {
    id: 'spl_2', name: 'Marea de Escarcha', circle: 2, elementalAffinity: 'water',
    manaCost: 28, castingTime: 'bonus', incantationFormula: '¡Olas gélidas, envolvedme!',
    description: 'Ondas de escarcha que calan hasta el hueso.', status: 'validated',
    effects: { damage: 0, healing: 25, barrier: 0, crowdControlType: 'none' },
  },
  {
    id: 'spl_3', name: 'Resonancia Rúnica', circle: 5, elementalAffinity: 'pureArcane',
    manaCost: 90, castingTime: 'ritual', incantationFormula: '¡Runas del origen, resonad!',
    description: 'Constelaciones que giran en torno al núcleo.', status: 'validated',
    effects: { damage: 0, healing: 0, barrier: 30, crowdControlType: 'none' },
  },
];

console.log('== ARNÉS TDD — Tarea 4.2: Tomo Arcano y Navegación de Páginas ==');

let module;
try {
  module = await import('../public/assets/js/components/grimoireBookComponent.js');
} catch (error) {
  console.log(`  FATAL — no se pudo importar grimoireBookComponent.js: ${error.message}`);
  console.log('== RESUMEN == Fase roja: la implementación aún no existe.');
  process.exit(1);
}

const { createGrimoireBookComponent } = module;

/** Monte un tomo con un anfitrión nuevo y el catálogo dado. */
function buildBook({ spells = CATALOG, host = null } = {}) {
  const root = host ?? createFakeElement('section');
  const cameraSlot = createFakeElement('div');
  cameraSlot.className = 'grimoire-book__camera-slot';
  const pageChanges = [];
  const component = createGrimoireBookComponent({
    root,
    cameraSlot,
    spells,
    createElement: createFakeElement,
    onPageChange: (detail) => pageChanges.push(detail),
  });
  return { component, root, cameraSlot, pageChanges };
}

/** Localiza un botón por su clase BEM. */
function findButton(root, modifier) {
  return queryByClass(root, `grimoire-book__arrow--${modifier}`)[0] ?? null;
}

console.log('[1] Fábrica y doble página (RF-01.1)');

{
  const { root, cameraSlot } = buildBook();

  assertCondition(typeof createGrimoireBookComponent === 'function', 'exporta createGrimoireBookComponent(options)');
  assertCondition(root.children.length > 0, 'el tomo se monta en el anfitrión');

  const left = queryByClass(root, 'grimoire-book__page--left')[0];
  const right = queryByClass(root, 'grimoire-book__page--right')[0];
  assertCondition(Boolean(left) && Boolean(right), 'doble página: izquierda (liturgia) y derecha (cámara)');
  assertCondition(right.children.includes(cameraSlot) || cameraSlot.parentElement === right, 'la página derecha aloja el contenedor de la Cámara');
}

console.log('[2] Exhibición de página (RF-01.5)');

{
  const { root } = buildBook();
  const text = subtreeText(root);

  assertCondition(text.includes('Ardor del Alba'), 'nombre del conjuro visible');
  assertCondition(text.includes('Círculo I'), 'Círculo Arcano visible');
  assertCondition(text.includes('Fuego'), 'afinidad elemental visible');
  assertCondition(text.includes('action') || text.includes('acción'), 'tiempo de lanzamiento visible');
  assertCondition(text.includes('15'), 'coste de maná visible');
  assertCondition(text.includes('¡Llamas del alba, descended!'), 'fórmula litúrgica visible');
  assertCondition(text.includes('Ascuas del alba'), 'descripción narrativa visible');
}

console.log('[3] Navegación acotada sin bucle infinito (RF-01.3/01.4)');

{
  const { component, root, pageChanges } = buildBook();

  // Estado inicial: primera página → prev desvanecida, next activa.
  const prev = findButton(root, 'prev');
  const next = findButton(root, 'next');
  assertCondition(prev && next, 'flechas de navegación presentes');
  assertCondition(prev.disabled === true, 'en la primera página la flecha previa está desvanecida');
  assertCondition(next.disabled === false, 'la flecha siguiente está activa');

  // Avanzar hasta la última: next se desvanece, prev vuelve.
  component.nextPage();
  component.nextPage();
  assertCondition(pageChanges.length === 2, 'cada paso emite grimoire:page-change');
  assertCondition(pageChanges[1].spell.name === 'Resonancia Rúnica' && pageChanges[1].pageNumber === 3 && pageChanges[1].totalPages === 3, 'la carga útil porta spell, pageNumber y totalPages');
  assertCondition(next.disabled === true, 'en la última página la flecha siguiente se desvanece');
  assertCondition(prev.disabled === false, 'la flecha previa vuelve a activarse');

  // Los pasos extra no hacen nada (sin bucle infinito).
  const changesBefore = pageChanges.length;
  component.nextPage();
  component.nextPage();
  assertCondition(pageChanges.length === changesBefore, 'next en el extremo NO emite ni avanza (lomo físico)');
  assertCondition(findButton(root, 'next').disabled === true, 'sigue desvanecida tras insistir');

  // Volver al inicio.
  component.previousPage();
  component.previousPage();
  assertCondition(pageChanges[pageChanges.length - 1].spell.name === 'Ardor del Alba', 'vuelta a la primera página');
  assertCondition(findButton(root, 'prev').disabled === true, 'prev desvanecida otra vez en el origen');
}

console.log('[4] Atajos de teclado (RF-01.3)');

{
  const { component, root, pageChanges } = buildBook();
  const zone = queryByClass(root, 'grimoire-book')[0] ?? root;

  zone.dispatch('keydown', { key: 'ArrowRight' });
  assertCondition(pageChanges.length === 1, 'flecha derecha pasa página');
  zone.dispatch('keydown', { key: 'ArrowLeft' });
  assertCondition(pageChanges[pageChanges.length - 1].spell.name === 'Ardor del Alba', 'flecha izquierda retrocede');

  // Extremos: el teclado tampoco buclea.
  zone.dispatch('keydown', { key: 'ArrowLeft' });
  assertCondition(pageChanges[pageChanges.length - 1].spell.name === 'Ardor del Alba', 'ArrowLeft en la primera página no hace nada');

  // Otras teclas ignoradas.
  zone.dispatch('keydown', { key: 'a' });
  assertCondition(pageChanges.length === 2, 'teclas ajenas ignoradas');
}

console.log('[5] Pergamino virgen ante filtros vacíos (RF-01.4)');

{
  const { root } = buildBook({ spells: [] });
  const text = subtreeText(root);

  assertCondition(text.includes('Aún no se han inscrito conjuros'), 'leyenda ceremonial del pergamino virgen visible');
  assertCondition(findButton(root, 'prev').disabled && findButton(root, 'next').disabled, 'ambas flechas desvanecidas sin catálogo');
}

console.log('[6] Índice rúnico — filtros por Círculo y Afinidad (RF-01.4)');

{
  const { component, root, pageChanges } = buildBook();
  const circleIndex = queryByClass(root, 'grimoire-book__circle-index')[0];
  const elementIndex = queryByClass(root, 'grimoire-book__element-index')[0];
  assertCondition(Boolean(circleIndex) && Boolean(elementIndex), 'índice rúnico de Círculos y Afinidades presente');

  const circleBtn = queryByClass(circleIndex ?? root, 'grimoire-book__circle-option')[0];
  assertCondition(Boolean(circleBtn), 'opciones del índice de círculos presentes');
  circleBtn.dispatch('click');
  assertCondition(typeof component.getFilter === 'function', 'el filtro queda consultable');
  assertCondition(component.getFilter().circle === 1, 'el clic en Círculo I aplica el filtro circle=1');

  // Limpiar filtro: restaura el catálogo completo.
  const clearBtn = queryByClass(root, 'grimoire-book__filter-clear')[0];
  assertCondition(Boolean(clearBtn), 'control de limpieza de filtros presente');
  clearBtn.dispatch('click');
  assertCondition(component.getFilter().circle === null, 'limpiar restablece el filtro');
  assertCondition(pageChanges[pageChanges.length - 1].spell.id === 'spl_1', 'tras limpiar, el tomo vuelve a la primera página del catálogo completo');
}

console.log('[7] Seguridad — textContent en todo el árbol (AGENTS.md 6.1)');

{
  // La construcción ya habría lanzado si algo hubiera usado innerHTML.
  const { root } = buildBook();
  assertCondition(root.children.length > 0, 'el tomo se construyó sin innerHTML (el fake lo prohíbe)');
  const hostile = buildBook({ spells: [{ ...CATALOG[0], name: '<img src=x onerror=alert(1)>' }] });
  const text = subtreeText(hostile.root);
  assertCondition(text.includes('<img'), 'el nombre hostil viaja como TEXTO, no como nodo (XSS contenido)');
}

console.log('== RESUMEN ==');
console.log(`Asertos superados: ${assertsPassed}, fallidos: ${assertsFailed}`);
if (assertsFailed === 0) {
  console.log('RESULTADO: EXITO — Tomo Arcano listo para el simulador (Tarea 4.2).');
  process.exit(0);
} else {
  console.log('RESULTADO: FALLO — hay asertos incumplidos.');
  process.exit(1);
}
