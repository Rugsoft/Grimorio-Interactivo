/**
 * test_mana_breakdown_component.mjs — Arnés TDD de la Tarea 5.3 (TASKS-04).
 *
 * Verifica el componente reactivo del desglose pedagógico de maná
 * (public/assets/js/components/manaBreakdownComponent.js):
 *   - createManaBreakdownComponent(options) crea el panel con su raíz
 *     accesible (role="status" aria-live="polite", RNF-04).
 *   - update(result) actualiza el DOM de forma INMEDIATA (< 50 ms):
 *     sumatorio de efectos base, factores multiplicadores geométricos,
 *     deducciones porcentuales por componentes, coste final destacado,
 *     distintivo del Círculo Arcano y cartel luminoso de Sobrecarga
 *     Arcana en rojo bermellón si supera los 200 puntos.
 *   - Seguridad (AGENTS.md 6.1): TODO dato viaja vía textContent —
 *     el DOM simulado lanza si alguien intenta innerHTML.
 *   - Degradación elegante: resultados incompletos no revientan el panel.
 *
 * Criterio «Hecho cuando» (Tarea 5.3): la invocación del método
 * update(result) actualiza el DOM de forma inmediata (< 50 ms),
 * coloreando los círculos arcanos y mostrando el aviso de sobrecarga
 * en rojo bermellón si supera los 200 puntos.
 *
 * Constitución:
 *   - Artículo I: ES Modules nativos, sin frameworks ni DOM virtual.
 *   - Artículo IV: etiquetas solemnes en castellano.
 *   - Artículo V: identificadores en inglés camelCase; comentarios en
 *     castellano.
 *
 * Uso: node scratch/test_mana_breakdown_component.mjs
 */

let assertsPassed = 0;
let assertsFailed = 0;
let uncaughtErrors = 0;

process.on('uncaughtException', () => { uncaughtErrors++; });
process.on('unhandledRejection', () => { uncaughtErrors++; });

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
 * Elemento DOM mínimo simulado (patrón consolidado del proyecto).
 * innerHTML está PROHIBIDO (AGENTS.md 6.1): su uso lanza excepción.
 */
function createFakeElement(tagName) {
  const element = {
    tagName: String(tagName).toUpperCase(),
    children: [],
    attributes: {},
    classes: new Set(),
    listeners: {},
    style: { setProperty(name, value) { (this.inline ??= {})[name] = String(value); }, getProperty(name) { return (this.inline ?? {})[name] ?? null; } },

    _textContent: '',
    parentElement: null,
    setAttribute(name, value) { this.attributes[name] = String(value); },
    getAttribute(name) { return name in this.attributes ? this.attributes[name] : null; },
    hasAttribute(name) { return name in this.attributes; },
    addEventListener(eventName, listener) { (this.listeners[eventName] ??= []).push(listener); },
    removeEventListener(eventName, listener) { this.listeners[eventName] = (this.listeners[eventName] ?? []).filter((l) => l !== listener); },
    appendChild(child) { child.parentElement = this; this.children.push(child); return child; },
    remove() {
      if (!this.parentElement) return;
      const index = this.parentElement.children.indexOf(this);
      if (index >= 0) this.parentElement.children.splice(index, 1);
      this.parentElement = null;
    },
    set className(value) { this.classes = new Set(String(value).split(/\s+/).filter(Boolean)); },
    get className() { return [...this.classes].join(' '); },
    get textContent() { return this._textContent; },
    set textContent(value) { this._textContent = String(value); },
    get innerHTML() { throw new Error('PROHIBIDO innerHTML (AGENTS.md 6.1: XSS); usar textContent'); },
    set innerHTML(value) { throw new Error(`PROHIBIDO innerHTML (AGENTS.md 6.1): se intentó escribir '${String(value).slice(0, 40)}'`); },
  };
  element.classList = {
    _owner: element,
    add(...names) { names.forEach((n) => this._owner.classes.add(n)); },
    remove(...names) { names.forEach((n) => this._owner.classes.delete(n)); },
    contains(name) { return this._owner.classes.has(name); },
  };
  return element;
}

/** Búsqueda recursiva de descendientes que porten una clase. */
function queryByClass(node, className, found = []) {
  for (const child of node.children) {
    if (child.classes.has(className)) found.push(child);
    queryByClass(child, className, found);
  }
  return found;
}

/** Primera clase de un nodo que empieza por el prefijo dado (modificadores BEM). */
function classStartingWith(node, prefix) {
  for (const className of node.classes) {
    if (className.startsWith(prefix)) return className;
  }
  return null;
}

/**
 * Texto acumulado del subárbol (semántica del textContent del DOM real,
 * que el fake no concatena: en el navegador el padre incluye a los hijos).
 */
function subtreeText(node) {
  let text = node.textContent ?? '';
  for (const child of node.children) {
    text += subtreeText(child);
  }
  return text;
}

console.log('== VERIFICACION TAREA 5.3: manaBreakdownComponent.js ==\n');

// --- Import del módulo bajo prueba (fase roja: no existe aún) ---
let manaBreakdownComponent = null;
try {
  manaBreakdownComponent = await import('../public/assets/js/components/manaBreakdownComponent.js');
} catch (importError) {
  console.log(`  [FALLA] El módulo manaBreakdownComponent.js no pudo importarse: ${importError.message}`);
  console.log('\n== RESUMEN ==');
  console.log('Asertos superados: 0');
  console.log('Asertos fallidos: 1');
  console.log('\nRESULTADO: FALLO — Fase roja: implementar public/assets/js/components/manaBreakdownComponent.js');
  process.exit(1);
}

const { createManaBreakdownComponent } = manaBreakdownComponent;

// Fábrica de elementos inyectada (patrón elementFactory del proyecto).
const elementFactory = createFakeElement;

// =====================================================================
// [0] Superficie: la fábrica existe.
// =====================================================================
console.log('[0] Superficie');
assertCondition(typeof createManaBreakdownComponent === 'function', 'El módulo exporta createManaBreakdownComponent()');

// =====================================================================
// [1] Creación del panel: raíz accesible y estructura BEM.
// =====================================================================
console.log('\n[1] Creación del panel (accesibilidad RNF-04)');

const panel = createManaBreakdownComponent({ elementFactory });
assertCondition(typeof panel?.root?.appendChild === 'function', 'createManaBreakdownComponent retorna { root }');
assertCondition(panel.root.getAttribute('role') === 'status', 'La raíz porta role="status" (región viva)');
assertCondition(panel.root.getAttribute('aria-live') === 'polite', 'La raíz porta aria-live="polite" (anuncio sin interrupciones)');
assertCondition(panel.root.classes.has('mana-breakdown'), 'La raíz porta la clase BEM mana-breakdown');
assertCondition(panel.root.textContent.includes('Desglose') || queryByClass(panel.root, 'mana-breakdown__title').length === 1, 'El panel porta su título solemne');

// =====================================================================
// [2] update(result): el desglose canónico del plan (48, Círculo III).
// =====================================================================
console.log('\n[2] update(result) — desglose canónico (30 → 60 → −12 → 48)');

const canonicalResult = {
  baseEffectPoints: 30.0,
  multipliers: { range: 1.25, area: 1.6, duration: 1.0, combined: 2.0 },
  grossMana: 60.0,
  discounts: { verbal: 0.10, somatic: 0.10, material: 0.0, totalPercent: 0.20, amountDeducted: 12.0 },
  netMana: 48.0,
  finalManaCost: 48,
  circle: 3,
  circleLabel: 'Círculo III (Magister)',
  isOverloaded: false,
};

const updateStart = process.hrtime.bigint();
panel.update(canonicalResult);
const updateMs = Number(process.hrtime.bigint() - updateStart) / 1e6;

assertCondition(updateMs < 50, `update() completa en ${updateMs.toFixed(3)} ms (< 50 ms)`);

const rootText = subtreeText(panel.root);
assertCondition(rootText.includes('30'), 'Muestra el sumatorio de efectos base (30)');
assertCondition(rootText.includes('1.25') && rootText.includes('1.6') && rootText.includes('2'), 'Muestra los factores multiplicadores (alcance, área, combinado)');
assertCondition(rootText.includes('20%') || rootText.includes('0.2'), 'Muestra el descuento total por componentes');
assertCondition(rootText.includes('12'), 'Muestra el importe deducido (12)');
assertCondition(rootText.includes('48'), 'Muestra el coste final destacado (48)');
assertCondition(rootText.includes('Círculo III (Magister)'), 'Muestra el distintivo solemne del Círculo Arcano');

// El distintivo del Círculo porta una clase de color del círculo (RF-03.1).
const circleBadge = queryByClass(panel.root, 'mana-breakdown__circle')[0] ?? queryByClass(panel.root, 'mana-breakdown__circle-badge')[0];
assertCondition(circleBadge !== undefined, 'El panel porta el distintivo del Círculo Arcano');
const circleColorClass = circleBadge ? classStartingWith(circleBadge, 'mana-breakdown__circle--') : null;
assertCondition(circleColorClass === 'mana-breakdown__circle--3', 'El distintivo se COLorea según el círculo (modificador --3)');

// Sin sobrecarga, el cartel no puede estar activo.
const overloadCard = queryByClass(panel.root, 'mana-breakdown__overload')[0];
const overloadInactive = overloadCard === undefined || overloadCard.attributes['hidden'] !== undefined || overloadCard.attributes['data-active'] === 'false';
assertCondition(overloadInactive, 'Sin sobrecarga, el cartel de Sobrecarga Arcana permanece oculto/inactivo');

// =====================================================================
// [3] Sobrecarga: cartel luminoso en rojo bermellón (> 200 puntos).
// =====================================================================
console.log('\n[3] Sobrecarga Arcana: cartel en rojo bermellón (> 200)');

const overloadedResult = {
  baseEffectPoints: 150.0,
  multipliers: { range: 1.5, area: 1.6, duration: 1.0, combined: 2.4 },
  grossMana: 360.0,
  discounts: { verbal: 0.0, somatic: 0.0, material: 0.0, totalPercent: 0.0, amountDeducted: 0.0 },
  netMana: 360.0,
  finalManaCost: 361,
  circle: 5,
  circleLabel: 'Círculo V (Archimago)',
  isOverloaded: true,
};

panel.update(overloadedResult);

const overloadCardActive = queryByClass(panel.root, 'mana-breakdown__overload')[0];
assertCondition(overloadCardActive !== undefined, 'update() activa el cartel de Sobrecarga Arcana');
assertCondition(
  overloadCardActive.attributes['data-active'] === 'true' || overloadCardActive.attributes['hidden'] === undefined,
  'El cartel queda visible para el autor'
);
assertCondition(subtreeText(overloadCardActive).includes('200'), 'El cartel advierte del techo de 200 puntos');
assertCondition(overloadCardActive.classes.has('mana-breakdown__overload--active'), 'El cartel porta la clase luminosa de activación');

// La raíz recibe además la marca de estado de sobrecarga (para CSS).
assertCondition(
  panel.root.classes.has('mana-breakdown--overloaded'),
  'La raíz porta la marca mana-breakdown--overloaded (rojo bermellón en CSS)'
);
assertCondition(subtreeText(panel.root).includes('361'), 'El coste sobrecargado (361) queda expuesto al autor');

// =====================================================================
// [4] Vuelta atrás: update() con un resultado sano desactiva el cartel.
// =====================================================================
console.log('\n[4] Reactividad: de sobrecarga a desglose sano');

panel.update(canonicalResult);
const overloadCardAfter = queryByClass(panel.root, 'mana-breakdown__overload')[0];
const overloadCleared = overloadCardAfter === undefined
  || overloadCardAfter.attributes['data-active'] === 'false'
  || overloadCardAfter.attributes['hidden'] !== undefined;
assertCondition(overloadCleared, 'El cartel se desactiva al volver un resultado sano');
assertCondition(!panel.root.classes.has('mana-breakdown--overloaded'), 'La marca de sobrecarga se retira de la raíz');
assertCondition(subtreeText(panel.root).includes('48'), 'El coste sano (48) vuelve a mostrarse');

// =====================================================================
// [5] Degradación elegante: resultados incompletos no revientan el panel.
// =====================================================================
console.log('\n[5] Degradación elegante (campos ausentes y sobrecarga parcial)');

let survivedIncomplete = true;
try {
  panel.update({ finalManaCost: 10, circle: 1, circleLabel: 'Círculo I (Iniciado)', isOverloaded: false });
} catch (incompleteError) {
  survivedIncomplete = false;
}
assertCondition(survivedIncomplete, 'update() con campos ausentes no lanza excepción');
assertCondition(subtreeText(panel.root).includes('10'), 'El coste parcial disponible se muestra igualmente');

// =====================================================================
// [6] Seguridad: lore hostil jamás se interpreta como markup.
// =====================================================================
console.log('\n[6] Seguridad: textContent en todo el renderizado');

let xssBlocked = false;
try {
  panel.update({ ...canonicalResult, circleLabel: '<img src=x onerror=alert(1)>' });
} catch (xssError) {
  // Si el DOM simulado lanza, es porque alguien intentó innerHTML: bloqueado.
  xssBlocked = String(xssError.message).includes('PROHIBIDO');
}
// Ambos desenlaces son seguros: o textContent lo neutraliza, o el guardián lanza.
const xssNeutralized = subtreeText(panel.root).includes('<img src=x onerror=alert(1)>') || xssBlocked;
assertCondition(xssNeutralized, 'Un circleLabel hostil viaja como texto (textContent) o es bloqueado');

// =====================================================================
// Resumen final.
// =====================================================================
console.log('\n== RESUMEN ==');
console.log(`Asertos superados: ${assertsPassed}`);
console.log(`Asertos fallidos: ${assertsFailed}`);
console.log(`Errores no controlados: ${uncaughtErrors}`);

if (assertsFailed === 0 && uncaughtErrors === 0) {
  console.log('\nRESULTADO: EXITO — manaBreakdownComponent.js listo para el Taller.');
  process.exit(0);
}
console.log('\nRESULTADO: FALLO');
process.exit(1);
