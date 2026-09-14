/**
 * test_elemental_wheel.mjs — Arnés TDD de la Tarea 3.2 (TASKS-06).
 *
 * Estrategia TDD: este script se escribe ANTES que la implementación.
 * Verifica, sobre `public/assets/js/components/elementalWheelComponent.js`:
 *   [1] Superficie: fábrica createElementalWheelComponent con dependencias
 *       inyectables (documento, catálogo) y API completa.
 *   [2] Octógono SVG (RF-01.1): 8 glifos en posiciones angulares equidistantes
 *       (45°), cada uno con su color heráldico y su glifo rúnico del Códice.
 *   [3] Glifos distinguibles por geometría y nombre (RNF-03): cada nodo
 *       porta título accesible con el nombre litúrgico castellano.
 *   [4] Filamentos al posar (RF-01.2): hover sobre un glifo ilumina SOLO
 *       sus aristas reactivas (2 para Fuego) y no las ajenas.
 *   [5] Filamentos al pulsar: click fija la selección persistente; click en
 *       glifo ajeno la reasigna.
 *   [6] Lámina del Códice (RF-01.2, RNF-04): al seleccionar, el panel
 *       despliega nombre litúrgico, descripción mitológica y efecto de cada
 *       reacción compatible — en noble castellano, desde los datos reales.
 *   [7] Elemento sin aristas duales (Arcano Puro): lámina honesta con el
 *       papel del catalizador (Resonancia Arcana Pura), sin lista vacía.
 *   [8] Catálogo inyectado: el componente consume los datos entregados (del
 *       endpoint 1 o de la matriz espejo) sin fetch alguno.
 *   [9] Accesibilidad: glifos enfocables (role=button + tabindex) y lámina
 *       con aria-live para lectores de pantalla.
 *  [10] Determinismo (RNF-01): mismas posiciones y aristas entre montajes.
 *
 * Criterio «Hecho cuando» (Tarea 3.2): al hacer clic en un glifo elemental,
 * se encienden sus filamentos de conexión hacia los elementos reactivos y
 * se expone la descripción en noble castellano.
 *
 * Constitución:
 *   - Artículo I (Dogma Vanilla): SVG nativo, módulo ES, cero dependencias;
 *     documento inyectable para los arneses.
 *   - Artículo II: los datos de la rueda se LEEN del catálogo del Códice.
 *   - Artículo IV: nombres litúrgicos y descripciones en castellano solemne.
 *   - Artículo V: identificadores en inglés camelCase; documentación en
 *     castellano.
 *
 * Uso: node scratch/test_elemental_wheel.mjs
 */

let passed = 0;
let failed = 0;
/** @type {string[]} */
const failures = [];

function assertTruthy(condition, label) {
  if (condition) {
    passed++;
    console.log(`  [OK]  ${label}`);
    return;
  }
  failed++;
  failures.push(label);
  console.log(`  [FALLA] ${label}`);
}

/** Elemento DOM falso (mismo doble fiel de los arneses de la Fase 3). */
class FakeElement {
  constructor(tagName) {
    this.tagName = tagName.toUpperCase();
    this.children = [];
    this.parentNode = null;
    this.attributes = new Map();
    const classSet = new Set();
    const syncClass = () => {
      if (classSet.size === 0) this.attributes.delete('class');
      else this.attributes.set('class', [...classSet].join(' '));
    };
    /**
     * setAttribute('class') y classList son la misma moneda en el DOM real:
     * escrituras por ambas vías se espejan en el conjunto y el atributo.
     */
    this.setAttribute = (name, value) => {
      this.attributes.set(name, String(value));
      if (name === 'class') {
        classSet.clear();
        for (const token of String(value).split(' ')) {
          if (token !== '') classSet.add(token);
        }
      }
    };
    this.classList = {
      add: (...names) => { for (const n of names) classSet.add(n); syncClass(); },
      remove: (...names) => { for (const n of names) classSet.delete(n); syncClass(); },
      contains: (name) => String(this.attributes.get('class') ?? '').split(' ').includes(name),
    };
    this.handlers = new Map();
    this.textContentValue = '';
    this.cssCustomProperties = new Map();
    const styleStore = this.cssCustomProperties;
    this.style = {
      setProperty: (name, value) => { styleStore.set(name, String(value)); },
      removeProperty: (name) => { styleStore.delete(name); },
      _store: styleStore,
    };
  }
  setAttribute(name, value) {
    this.attributes.set(name, String(value));
    void name;
    void value;
  }
  getAttribute(name) { return this.attributes.has(name) ? this.attributes.get(name) : null; }
  removeAttribute(name) { this.attributes.delete(name); }
  addEventListener(type, handler) { const list = this.handlers.get(type) ?? []; list.push(handler); this.handlers.set(type, list); }
  removeEventListener(type, handler) { this.handlers.set(type, (this.handlers.get(type) ?? []).filter((h) => h !== handler)); }
  dispatchEvent(event) { for (const handler of this.handlers.get(event.type) ?? []) handler(event); return true; }
  appendChild(child) { child.parentNode = this; this.children.push(child); return child; }
  remove() { if (this.parentNode) { const s = this.parentNode.children; const i = s.indexOf(this); if (i >= 0) s.splice(i, 1); this.parentNode = null; } }
  get textContent() {
    if (this.children.length === 0) return this.textContentValue;
    return this.children.map((c) => c.textContent).join('');
  }
  set textContent(value) { this.children = []; this.textContentValue = String(value); }
  querySelector(selector) { return this.querySelectorAll(selector)[0] ?? null; }
  querySelectorAll(selector) {
    const found = [];
    const classWanted = selector.startsWith('.') ? selector.slice(1) : null;
    const tagWanted = classWanted === null ? selector.toLowerCase() : null;
    for (const child of this.children) {
      if (classWanted !== null && child.classList.contains(classWanted)) found.push(child);
      if (tagWanted !== null && child.tagName.toLowerCase() === tagWanted) found.push(child);
      found.push(...(child.querySelectorAll?.(selector) ?? []));
    }
    return found;
  }
}

const fakeDocument = {
  createElement: (tag) => new FakeElement(tag),
  createElementNS: (_ns, tag) => new FakeElement(tag),
};

/** Catálogo del Códice (endpoint 1, plan 2.1) — datos reales del Códice. */
const CODICE_CATALOG = {
  elements: [
    { id: 'fire', name: 'Fuego', color: '#ff4500', glyph: 'rune-ignis' },
    { id: 'water', name: 'Agua / Escarcha', color: '#00bfff', glyph: 'rune-aqua' },
    { id: 'lightning', name: 'Rayo', color: '#9932cc', glyph: 'rune-fulgur' },
    { id: 'earth', name: 'Tierra', color: '#8b4513', glyph: 'rune-terra' },
    { id: 'wind', name: 'Viento', color: '#2e8b57', glyph: 'rune-ventus' },
    { id: 'light', name: 'Luz', color: '#ffd700', glyph: 'rune-lux' },
    { id: 'darkness', name: 'Oscuridad', color: '#4b0082', glyph: 'rune-tenebrae' },
    { id: 'pureArcane', name: 'Arcano Puro', color: '#4169e1', glyph: 'rune-arcana' },
  ],
  reactions: [
    { id: 'arcaneVaporization', name: 'Vaporización Arcana', elements: ['fire', 'water'], damageMultiplier: 1.5, tacticalEffect: 'blindnessMist', effectDurationMs: 3000, description: 'Emisión de vapor abrasador que reduce la precisión del objetivo durante 3 segundos.' },
    { id: 'fluidElectrocution', name: 'Electrocución Fluida', elements: ['water', 'lightning'], damageMultiplier: 1.5, tacticalEffect: 'hardStun', effectDurationMs: 1500, description: 'Descarga en cadena que aturde fulgurantemente al blanco durante 1.5 segundos.' },
    { id: 'vortexDeflagration', name: 'Deflagración en Vórtice', elements: ['fire', 'wind'], damageMultiplier: 1.5, tacticalEffect: 'areaExpansion', effectDurationMs: 0, description: 'Combustión violenta alimentada por viento que expande el impacto a radio esférico en área.' },
    { id: 'basalticFracture', name: 'Fractura Basáltica', elements: ['earth', 'lightning'], damageMultiplier: 1.5, tacticalEffect: 'barrierShatter', effectDurationMs: 0, barrierDamage: 50, description: 'Descarga de choque que tritura hasta 50 puntos de barrera mágica del objetivo.' },
    { id: 'petrifyingSwamp', name: 'Ciénaga Petrificante', elements: ['earth', 'water'], damageMultiplier: 1.5, tacticalEffect: 'rootAndSlow', effectDurationMs: 3000, slowDurationMs: 4000, description: 'Inmovilización por enraizamiento de 3 s y reducción de velocidad al 50% por 4 s.' },
    { id: 'glacialBlizzard', name: 'Ventisca Helada', elements: ['wind', 'water'], damageMultiplier: 1.5, tacticalEffect: 'freezeParalysis', effectDurationMs: 2000, description: 'Congelación absoluta de 2 segundos que impone parálisis motora completa.' },
    { id: 'twilightCollapse', name: 'Colapso Crepuscular', elements: ['light', 'darkness'], damageMultiplier: 1.5, tacticalEffect: 'barrierPiercing', effectDurationMs: 0, description: 'Daño puro que penetra el 100% de los escudos dañando directamente los puntos de salud.' },
    { id: 'pureArcaneResonance', name: 'Resonancia Arcana Pura', elements: ['pureArcane'], isCatalyst: true, amplificationFactor: 1.25, ccExtensionMs: 1000, description: 'Catalizador universal que amplifica un 25% la magnitud y añade 1 s a la duración de controles.' },
  ],
};

const MODULE_URL = new URL('../public/assets/js/components/elementalWheelComponent.js', import.meta.url).href;

/** Forja la rueda montada en un anfitrión nuevo. */
async function forgeWheel({ catalog = CODICE_CATALOG } = {}) {
  const { createElementalWheelComponent } = await import(MODULE_URL);
  const host = new FakeElement('div');
  const component = createElementalWheelComponent({ document: fakeDocument, catalog });
  component.mount(host);
  return { component, host };
}

/** Glifos del octógono (nodos del SVG). */
function glyphNodes(host) {
  return host.querySelectorAll('.elemental-wheel__glyph');
}

/** Filamentos (aristas del SVG). */
function filamentNodes(host) {
  return host.querySelectorAll('.elemental-wheel__filament');
}

/** Glifo por identificador elemental. */
function glyphOf(host, elementId) {
  return glyphNodes(host).find((g) => g.getAttribute('data-element') === elementId) ?? null;
}

/** Despierta el hover sobre un glifo. */
function hoverGlyph(glyph) { glyph.dispatchEvent({ type: 'mouseenter' }); }
function unhoverGlyph(glyph) { glyph.dispatchEvent({ type: 'mouseleave' }); }
/** Pulsa un glifo. */
function clickGlyph(glyph) { glyph.dispatchEvent({ type: 'click' }); }

console.log('== ARNÉS TDD — RUEDA RÚNICA OCTOGONAL (Tarea 3.2, SPEC-06) ==');

// ---------------------------------------------------------------------------
console.log('\n[FASE 1] Superficie del componente');
// ---------------------------------------------------------------------------

let factory = null;
try {
  const module = await import(MODULE_URL);
  factory = module.createElementalWheelComponent;
  assertTruthy(typeof factory === 'function', 'El módulo elementalWheelComponent.js exporta su fábrica (fase roja si falta)');
} catch (error) {
  assertTruthy(false, `El módulo elementalWheelComponent.js exporta su fábrica (${error.message})`);
}

if (factory !== null) {
  const { component } = await forgeWheel();
  for (const methodName of ['mount', 'highlightElement', 'clearHighlight', 'getSelectedElement', 'destroy']) {
    assertTruthy(typeof component[methodName] === 'function', `La API expone ${methodName}()`);
  }
}

// ---------------------------------------------------------------------------
console.log('\n[FASE 2] El octógono heráldico (RF-01.1)');
// ---------------------------------------------------------------------------

{
  const { host } = await forgeWheel();
  const svg = host.querySelector('svg');
  assertTruthy(svg !== null, 'La rueda se dibuja en SVG nativo');
  const glyphs = glyphNodes(host);
  assertTruthy(glyphs.length === 8, 'El octógono porta los 8 glifos elementales');

  // Ángulos equidistantes de 45° en torno al centro (100,100).
  const angles = glyphs.map((g) => Number(g.getAttribute('data-angle')));
  const sortedAngles = [...angles].sort((a, b) => a - b);
  const equidistant = sortedAngles.every((angle, i) => {
    if (i === 0) return true;
    return Math.abs((angle - sortedAngles[i - 1]) - 45) < 0.01;
  });
  assertTruthy(equidistant && Math.abs(sortedAngles[0]) < 0.01, 'Los 8 glifos se sitúan en ángulos equidistantes de 45°');

  const fireGlyph = glyphOf(host, 'fire');
  assertTruthy(fireGlyph?.getAttribute('data-color') === '#ff4500', 'Fuego porta su color heráldico del Códice');
  assertTruthy(fireGlyph?.getAttribute('data-glyph') === 'rune-ignis', 'Fuego porta su glifo rúnico');
  const arcaneGlyph = glyphOf(host, 'pureArcane');
  assertTruthy(arcaneGlyph?.getAttribute('data-color') === '#4169e1', 'Arcano Puro porta su azul heráldico');
}

// ---------------------------------------------------------------------------
console.log('\n[FASE 3] Glifos distinguibles y accesibles (RNF-03)');
// ---------------------------------------------------------------------------

{
  const { host } = await forgeWheel();
  const glyphs = glyphNodes(host);
  const titledGlyphs = glyphs.filter((g) => {
    const title = g.querySelector('title');
    return title !== null && title.textContent.length > 0;
  });
  assertTruthy(titledGlyphs.length === 8, 'Cada glifo porta un <title> accesible con su nombre');
  const fireTitle = glyphOf(host, 'fire')?.querySelector('title')?.textContent ?? '';
  assertTruthy(fireTitle === 'Fuego', 'El título de Fuego es su nombre litúrgico castellano');
  const focusables = glyphs.filter((g) => g.getAttribute('tabindex') === '0');
  assertTruthy(focusables.length === 8, 'Los 8 glifos son enfocables por teclado (tabindex=0)');
  assertTruthy(glyphs.every((g) => g.getAttribute('role') === 'button'), 'Los glifos se anuncian como botones (role=button)');
}

// ---------------------------------------------------------------------------
console.log('\n[FASE 4] Filamentos al posar el cursor (RF-01.2)');
// ---------------------------------------------------------------------------

{
  const { host } = await forgeWheel();
  const fireGlyph = glyphOf(host, 'fire');
  hoverGlyph(fireGlyph);

  const litFilaments = filamentNodes(host).filter((f) => f.classList.contains('elemental-wheel__filament--lit'));
  assertTruthy(litFilaments.length === 2, 'El posado sobre Fuego enciende exactamente sus 2 filamentos (Agua y Viento)');

  const litTargets = litFilaments.map((f) => f.getAttribute('data-target')).sort();
  assertTruthy(litTargets.join(',') === 'water,wind', 'Los filamentos encendidos apuntan a Agua y Viento');

  unhoverGlyph(fireGlyph);
  const litAfterUnhover = filamentNodes(host).filter((f) => f.classList.contains('elemental-wheel__filament--lit'));
  assertTruthy(litAfterUnhover.length === 0, 'Al retirar el cursor, los filamentos se apagan (si no hay selección fija)');
}

// ---------------------------------------------------------------------------
console.log('\n[FASE 5] Filamentos al pulsar (selección fija)');
// ---------------------------------------------------------------------------

{
  const { host, component } = await forgeWheel();
  const waterGlyph = glyphOf(host, 'water');
  clickGlyph(waterGlyph);

  assertTruthy(component.getSelectedElement() === 'water', 'El pulso fija la selección en Agua');
  const litFilaments = filamentNodes(host).filter((f) => f.classList.contains('elemental-wheel__filament--lit'));
  // El par reactivo de cada filamento: el extremo que NO es el seleccionado.
  const litPairs = litFilaments.map((f) => (f.getAttribute('data-source') === 'water' ? f.getAttribute('data-target') : f.getAttribute('data-source'))).sort();
  assertTruthy(litFilaments.length === 4, 'La selección fija de Agua enciende sus 4 filamentos');
  assertTruthy(litPairs.join(',') === 'earth,fire,lightning,wind', 'Los filamentos conectan con Fuego, Rayo, Tierra y Viento');

  clickGlyph(glyphOf(host, 'earth'));
  const litPairsEarth = filamentNodes(host).filter((f) => f.classList.contains('elemental-wheel__filament--lit')).map((f) => (f.getAttribute('data-source') === 'earth' ? f.getAttribute('data-target') : f.getAttribute('data-source'))).sort();
  assertTruthy(component.getSelectedElement() === 'earth' && litPairsEarth.join(',') === 'lightning,water', 'El pulso sobre Tierra reasigna la selección y sus filamentos');

  component.clearHighlight();
  assertTruthy(component.getSelectedElement() === null, 'clearHighlight() despeja la selección');
  assertTruthy(filamentNodes(host).every((f) => !f.classList.contains('elemental-wheel__filament--lit')), 'Despejada la selección, ningún filamento queda encendido');
}

// ---------------------------------------------------------------------------
console.log('\n[FASE 6] Lámina del Códice en castellano (RF-01.2, RNF-04)');
// ---------------------------------------------------------------------------

{
  const { host } = await forgeWheel();
  clickGlyph(glyphOf(host, 'fire'));

  const plate = host.querySelector('.elemental-wheel__plate');
  assertTruthy(plate !== null, 'Al pulsar, la lámina del Códice se despliega');
  const plateText = plate.textContent;
  assertTruthy(plateText.includes('Vaporización Arcana'), 'La lámina proclama la Vaporización Arcana');
  assertTruthy(plateText.includes('Deflagración en Vórtice'), 'La lámina proclama la Deflagración en Vórtice');
  assertTruthy(plateText.includes('Emisión de vapor abrasador'), 'La descripción mitológica viaja íntegra');
  assertTruthy(plateText.includes('Fuego'), 'El elemento seleccionado se nombra en castellano');
  assertTruthy(!plateText.includes('undefined') && !plateText.includes('[object'), 'La lámina no fuga estructuras internas');

  // El efecto táctico canónico se expone con su nombre técnico del Códice.
  assertTruthy(plateText.includes('blindnessMist'), 'El efecto táctico canónico (blindnessMist) se expone en la lámina');
}

// ---------------------------------------------------------------------------
console.log('\n[FASE 7] Arcano Puro: lámina del catalizador');
// ---------------------------------------------------------------------------

{
  const { host } = await forgeWheel();
  clickGlyph(glyphOf(host, 'pureArcane'));

  const plate = host.querySelector('.elemental-wheel__plate');
  const plateText = plate?.textContent ?? '';
  assertTruthy(plate !== null, 'Arcano Puro también despliega su lámina');
  assertTruthy(plateText.includes('Resonancia Arcana Pura'), 'La lámina explica el papel del catalizador universal');
  assertTruthy(plateText.includes('1.25') || plateText.includes('+25%'), 'La amplificación del catalizador se expone');
  const litCount = filamentNodes(host).filter((f) => f.classList.contains('elemental-wheel__filament--lit')).length;
  assertTruthy(litCount === 0, 'El catalizador unario no enciende filamento dual alguno');
}

// ---------------------------------------------------------------------------
console.log('\n[FASE 8] Catálogo inyectado, cero red (Artículo I)');
// ---------------------------------------------------------------------------

{
  const minimalCatalog = {
    elements: [{ id: 'fire', name: 'Fuego', color: '#ff4500', glyph: 'rune-ignis' }],
    reactions: [],
  };
  const { host } = await forgeWheel({ catalog: minimalCatalog });
  assertTruthy(glyphNodes(host).length === 1, 'El componente consume el catálogo entregado, sin fetch');
  clickGlyph(glyphOf(host, 'fire'));
  const plate = host.querySelector('.elemental-wheel__plate');
  assertTruthy(plate !== null && plate.textContent.includes('sin reacciones'), 'Un elemento sin aristas declara su soledad con honestidad');
}

// ---------------------------------------------------------------------------
console.log('\n[FASE 9] Lámina viva para lectores de pantalla');
// ---------------------------------------------------------------------------

{
  const { host } = await forgeWheel();
  clickGlyph(glyphOf(host, 'light'));
  const plate = host.querySelector('.elemental-wheel__plate');
  assertTruthy(plate?.getAttribute('aria-live') === 'polite', 'La lámina se anuncia con aria-live=polite');
}

// ---------------------------------------------------------------------------
console.log('\n[FASE 10] Determinismo (RNF-01)');
// ---------------------------------------------------------------------------

{
  const geometryOf = async () => {
    const { host } = await forgeWheel();
    return glyphNodes(host).map((g) => `${g.getAttribute('data-element')}@${g.getAttribute('data-angle')}`).join('|');
  };
  assertTruthy(await geometryOf() === await geometryOf(), 'Dos montajes producen el octógono con idéntica geometría');
}

// ---------------------------------------------------------------------------
console.log('\n== RESUMEN ==');
console.log(`Asertos superados: ${passed}, fallidos: ${failed}`);

if (failed > 0) {
  console.log('\nFALLOS:');
  for (const failure of failures) {
    console.log(`  - ${failure}`);
  }
  console.log('\nRESULTADO: FRACASO — La Rueda Rúnica aún no cumple el contrato de la Tarea 3.2.');
  process.exit(1);
}

console.log('\nRESULTADO: ÉXITO — Octógono heráldico, filamentos reactivos y lámina del Códice (Tarea 3.2).');
process.exit(0);
