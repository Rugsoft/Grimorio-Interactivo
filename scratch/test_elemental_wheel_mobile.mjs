/**
 * test_elemental_wheel_mobile.mjs — Arnés TDD de la Tarea 3.3 (TASKS-06).
 *
 * Estrategia TDD: este script se escribe ANTES que la implementación.
 * Verifica, sobre `public/assets/js/components/elementalWheelComponent.js`,
 * la adaptabilidad móvil del Códice (RF-01.1, plan: selector radial táctil
 * compacto + lámina de acordeón rúnico):
 *   [1] Superficie: la fábrica acepta la consulta de medios inyectable
 *       (matchMedia) y expone el modo vigente (isMobileLayout).
 *   [2] Escritorio intacto: con matches=false el montaje es el octógono de
 *       la Tarea 3.2, sin acordeón (no hay regresión).
 *   [3] Conmutación móvil: con matches=true la raíz porta la clase del modo
 *       móvil, el SVG se dibuja COMPACTO (radio orbital menor) y aparece el
 *       acordeón con 8 cabeceras, una por elemento.
 *   [4] Acordeón rúnico: cada cabecera es un botón accesible (aria-expanded)
 *       con el nombre litúrgico; pulsar despliega SU lámina de reacciones en
 *       castellano; solo una lámina abierta a la vez (exclusión mutua).
 *   [5] Sin solapamientos ni desbordamiento horizontal: el acordeón es una
 *       lista vertical (una cabecera por fila, sin posicionado absoluto de
 *       filas) y el selector compacto cabe en el ancho del anfitrión.
 *   [6] Áreas táctiles (RNF-03): las cabeceras del acordeón y los glifos del
 *       selector compacto declaran el tamaño mínimo táctil (clase táctil con
 *       44 px garantizados en el CSS).
 *   [7] Selección preservada al conmutar: un elemento fijado en escritorio
 *       sigue seleccionado tras el cambio a móvil (y viceversa).
 *   [8] Reconfiguración en vivo: voltea la media query y notifica; el
 *       componente reconstruye su DOM sin montajes duplicados.
 *   [9] Determinismo (RNF-01): dos montajes móviles producen el mismo árbol.
 *
 * Criterio «Hecho cuando» (Tarea 3.3): en pantallas reducidas la interfaz
 * conmuta al formato de acordeón táctil sin solapamientos ni desbordamientos
 * horizontales.
 *
 * Constitución:
 *   - Artículo I (Dogma Vanilla): módulo ES nativo; media query inyectable.
 *   - Artículo IV: nombres litúrgicos en castellano solemne.
 *   - Artículo V: identificadores en inglés camelCase; documentación en
 *     castellano.
 *
 * Uso: node scratch/test_elemental_wheel_mobile.mjs
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

/** Elemento DOM falso (doble fiel: classList espejado al atributo). */
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
    this._store = new Map();
    this.style = { setProperty: (k, v) => this._store.set(k, String(v)), removeProperty: (k) => this._store.delete(k) };
  }
  getAttribute(n) { return this.attributes.has(n) ? this.attributes.get(n) : null; }
  removeAttribute(n) { this.attributes.delete(n); }
  addEventListener(t, h) { const l = this.handlers.get(t) ?? []; l.push(h); this.handlers.set(t, l); }
  dispatchEvent(e) { for (const h of this.handlers.get(e.type) ?? []) h(e); return true; }
  appendChild(c) { c.parentNode = this; this.children.push(c); return c; }
  remove() { if (this.parentNode) { const s = this.parentNode.children; const i = s.indexOf(this); if (i >= 0) s.splice(i, 1); this.parentNode = null; } }
  get textContent() { if (this.children.length === 0) return this.textContentValue; return this.children.map((c) => c.textContent).join(''); }
  set textContent(v) { this.children = []; this.textContentValue = String(v); }
  querySelectorAll(s) {
    const found = [];
    const cls = s.startsWith('.') ? s.slice(1) : null;
    const tag = cls === null ? s.toLowerCase() : null;
    for (const c of this.children) {
      if (cls !== null && c.classList.contains(cls)) found.push(c);
      if (tag !== null && c.tagName.toLowerCase() === tag) found.push(c);
      found.push(...(c.querySelectorAll?.(s) ?? []));
    }
    return found;
  }
  querySelector(s) { return this.querySelectorAll(s)[0] ?? null; }
}

/** Media query falsa: el arnés voltea `matches` y notifica a los oyentes. */
function createFakeMediaQuery({ initialMatches = false } = {}) {
  let matches = initialMatches;
  const listeners = [];
  return {
    get matches() { return matches; },
    addEventListener(_type, handler) { listeners.push(handler); },
    removeEventListener(_type, handler) { const i = listeners.indexOf(handler); if (i >= 0) listeners.splice(i, 1); },
    /** Voltea el estado y notifica (simula un cambio de viewport). */
    setMatches(nextMatches) {
      matches = nextMatches;
      for (const handler of listeners) handler();
    },
    listenerCount() { return listeners.length; },
  };
}

const fakeDocument = {
  createElement: (tag) => new FakeElement(tag),
  createElementNS: (_ns, tag) => new FakeElement(tag),
};

/** Catálogo del Códice (endpoint 1) — los 8 elementos y 8 aristas reales. */
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

/** Forja la rueda con la media query falsa y el anfitrión nuevo. */
async function forgeWheel({ media = createFakeMediaQuery() } = {}) {
  const { createElementalWheelComponent } = await import(MODULE_URL);
  const host = new FakeElement('div');
  const component = createElementalWheelComponent({ document: fakeDocument, catalog: CODICE_CATALOG, matchMedia: () => media });
  component.mount(host);
  return { component, host, media };
}

console.log('== ARNÉS TDD — ADAPTABILIDAD MÓVIL DEL CÓDICE (Tarea 3.3, SPEC-06) ==');

// ---------------------------------------------------------------------------
console.log('\n[FASE 1] Superficie del modo móvil');
// ---------------------------------------------------------------------------

let factory = null;
try {
  const module = await import(MODULE_URL);
  factory = module.createElementalWheelComponent;
  assertTruthy(typeof factory === 'function', 'El módulo exporta su fábrica (fase roja si falta)');
} catch (error) {
  assertTruthy(false, `El módulo exporta su fábrica (${error.message})`);
}

if (factory !== null) {
  const { component } = await forgeWheel();
  assertTruthy(typeof component.isMobileLayout === 'function', 'La API expone isMobileLayout()');
  assertTruthy(component.isMobileLayout() === false, 'Con medios de escritorio, el modo móvil está inactivo');
}

// ---------------------------------------------------------------------------
console.log('\n[FASE 2] Escritorio intacto (sin regresión de la Tarea 3.2)');
// ---------------------------------------------------------------------------

{
  const { host } = await forgeWheel();
  const root = host.children[0];
  assertTruthy(root?.querySelector('svg') !== null, 'En escritorio, la rueda se dibuja en SVG');
  assertTruthy(host.querySelectorAll('.elemental-wheel__glyph').length === 8, 'El octógono porta sus 8 glifos');
  assertTruthy(host.querySelectorAll('.elemental-wheel__accordion').length === 0, 'En escritorio no existe acordeón alguno');
  assertTruthy(root?.classList.contains('elemental-wheel--mobile') === false, 'La raíz no porta la clase del modo móvil');
}

// ---------------------------------------------------------------------------
console.log('\n[FASE 3] Conmutación móvil: selector compacto + acordeón');
// ---------------------------------------------------------------------------

{
  const { component, host, media } = await forgeWheel({ media: createFakeMediaQuery({ initialMatches: true }) });

  assertTruthy(component.isMobileLayout() === true, 'Con medios de pantalla reducida, el modo móvil está activo');
  const root = host.children[0];
  assertTruthy(root?.classList.contains('elemental-wheel--mobile') === true, 'La raíz porta la clase del modo móvil');

  const svg = root?.querySelector('svg');
  assertTruthy(svg !== null, 'El selector radial táctil sigue siendo SVG (versión compacta)');
  const glyphs = host.querySelectorAll('.elemental-wheel__glyph');
  assertTruthy(glyphs.length === 8, 'El selector compacto conserva los 8 glifos');
  const orbit = svg?.getAttribute('data-orbit-radius');
  assertTruthy(orbit !== null && Number(orbit) < 78, `El radio orbital es menor que el de escritorio (medido ${orbit})`);

  const accordion = host.querySelectorAll('.elemental-wheel__accordion');
  assertTruthy(accordion.length === 1, 'El acordeón rúnico está presente en móvil');
  const headers = host.querySelectorAll('.elemental-wheel__accordion-header');
  assertTruthy(headers.length === 8, 'El acordeón porta 8 cabeceras, una por elemento');
}

// ---------------------------------------------------------------------------
console.log('\n[FASE 4] Acordeón rúnico con exclusión mutua (RNF-04)');
// ---------------------------------------------------------------------------

{
  const { host } = await forgeWheel({ media: createFakeMediaQuery({ initialMatches: true }) });
  const headers = host.querySelectorAll('.elemental-wheel__accordion-header');

  assertTruthy(headers.every((h) => h.getAttribute('aria-expanded') === 'false'), 'Todas las láminas nacen plegadas');

  const fireHeader = headers.find((h) => h.getAttribute('data-element') === 'fire');
  assertTruthy(fireHeader !== undefined, 'La cabecera de Fuego existe');
  const headerText = fireHeader.textContent;
  assertTruthy(headerText.includes('Fuego'), 'La cabecera proclama el nombre litúrgico (RNF-04)');

  fireHeader.dispatchEvent({ type: 'click' });
  assertTruthy(fireHeader.getAttribute('aria-expanded') === 'true', 'Pulsar la cabecera despliega su lámina');
  const openPanels = host.querySelectorAll('.elemental-wheel__accordion-panel').filter((p) => p.classList.contains('elemental-wheel__accordion-panel--open'));
  assertTruthy(openPanels.length === 1, 'Solo una lámina está abierta (exclusión mutua)');
  const openText = openPanels[0]?.textContent ?? '';
  assertTruthy(openText.includes('Vaporización Arcana') && openText.includes('Deflagración en Vórtice'), 'La lámina desplegada expone las reacciones de Fuego en castellano');

  headers.find((h) => h.getAttribute('data-element') === 'water').dispatchEvent({ type: 'click' });
  const stillOpen = host.querySelectorAll('.elemental-wheel__accordion-panel').filter((p) => p.classList.contains('elemental-wheel__accordion-panel--open'));
  assertTruthy(stillOpen.length === 1 && headers.find((h) => h.getAttribute('data-element') === 'water').getAttribute('aria-expanded') === 'true', 'Abrir Agua cierra Fuego (una lámina a la vez)');

  headers.find((h) => h.getAttribute('data-element') === 'water').dispatchEvent({ type: 'click' });
  const noneOpen = host.querySelectorAll('.elemental-wheel__accordion-panel').filter((p) => p.classList.contains('elemental-wheel__accordion-panel--open'));
  assertTruthy(noneOpen.length === 0, 'Repetir el pulso pliega la lámina (toggle)');
}

// ---------------------------------------------------------------------------
console.log('\n[FASE 5] Sin solapamientos ni desbordamiento horizontal');
// ---------------------------------------------------------------------------

{
  const { host } = await forgeWheel({ media: createFakeMediaQuery({ initialMatches: true }) });
  const root = host.children[0];

  // El acordeón es una lista vertical: cada cabecera es una fila hija
  // directa de la lista, sin posicionado absoluto que solape filas.
  const list = root?.querySelector('.elemental-wheel__accordion');
  assertTruthy(list !== null, 'El acordeón existe');
  const headerRows = host.querySelectorAll('.elemental-wheel__accordion-header');
  assertTruthy(headerRows.every((h) => h.getAttribute('role') === 'button'), 'Las cabeceras son botones (role=button)');
  assertTruthy(headerRows.every((h) => h.getAttribute('tabindex') === '0'), 'Las cabeceras son enfocables (tabindex=0)');

  // El selector compacto y el acordeón declaran ancho fluido (sin ancho
  // fijo en píxeles que desborde pantallas de 320 px).
  const svg = root?.querySelector('svg');
  assertTruthy(svg?.getAttribute('class')?.includes('elemental-wheel__svg--compact') === true, 'El SVG del selector compacto porta su clase de ancho fluido');
}

// ---------------------------------------------------------------------------
console.log('\n[FASE 6] Áreas táctiles (RNF-03)');
// ---------------------------------------------------------------------------

{
  const { host } = await forgeWheel({ media: createFakeMediaQuery({ initialMatches: true }) });
  const headers = host.querySelectorAll('.elemental-wheel__accordion-header');
  assertTruthy(headers.every((h) => h.classList.contains('elemental-wheel__touch-target')), 'Las cabeceras porta la clase de área táctil (44 px mínimos en el CSS)');
  const glyphs = host.querySelectorAll('.elemental-wheel__glyph');
  assertTruthy(glyphs.every((g) => g.classList.contains('elemental-wheel__touch-target')), 'Los glifos del selector compacto también son áreas táctiles');
}

// ---------------------------------------------------------------------------
console.log('\n[FASE 7] Selección preservada al conmutar de modo');
// ---------------------------------------------------------------------------

{
  const { component, host, media } = await forgeWheel();

  // Selecciona Tierra en escritorio (pulso sobre el glifo).
  host.querySelectorAll('.elemental-wheel__glyph').find((g) => g.getAttribute('data-element') === 'earth').dispatchEvent({ type: 'click' });
  assertTruthy(component.getSelectedElement() === 'earth', 'Selección fija en escritorio');

  // Conmuta a móvil: la selección debe sobrevivir.
  media.setMatches(true);
  assertTruthy(component.isMobileLayout() === true, 'El viewport se redujo: modo móvil');
  assertTruthy(component.getSelectedElement() === 'earth', 'La selección de Tierra sobrevive a la conmutación');
  const earthHeader = host.querySelectorAll('.elemental-wheel__accordion-header').find((h) => h.getAttribute('data-element') === 'earth');
  assertTruthy(earthHeader?.getAttribute('aria-expanded') === 'true', 'La lámina del elemento seleccionado nace desplegada en móvil');

  // Y de vuelta a escritorio.
  media.setMatches(false);
  assertTruthy(component.getSelectedElement() === 'earth' && component.isMobileLayout() === false, 'De regreso en escritorio, la selección persiste');
}

// ---------------------------------------------------------------------------
console.log('\n[FASE 8] Reconfiguración en vivo sin montajes duplicados');
// ---------------------------------------------------------------------------

{
  const { host, media } = await forgeWheel();

  media.setMatches(true);
  media.setMatches(false);
  media.setMatches(true);

  const roots = host.children.filter((c) => c.classList.contains('elemental-wheel'));
  assertTruthy(roots.length === 1, 'Tras tres conmutaciones, existe UNA sola raíz (sin montajes duplicados)');
  assertTruthy(host.querySelectorAll('.elemental-wheel__accordion').length === 1, 'Un solo acordeón tras las conmutaciones');
}

// ---------------------------------------------------------------------------
console.log('\n[FASE 9] Determinismo (RNF-01)');
// ---------------------------------------------------------------------------

{
  const mobileTree = async () => {
    const { host } = await forgeWheel({ media: createFakeMediaQuery({ initialMatches: true }) });
    return host.querySelectorAll('.elemental-wheel__accordion-header').map((h) => h.getAttribute('data-element')).join(',');
  };
  assertTruthy(await mobileTree() === await mobileTree(), 'Dos montajes móviles producen el mismo acordeón');
}

// ---------------------------------------------------------------------------
console.log('\n== RESUMEN ==');
console.log(`Asertos superados: ${passed}, fallidos: ${failed}`);

if (failed > 0) {
  console.log('\nFALLOS:');
  for (const failure of failures) {
    console.log(`  - ${failure}`);
  }
  console.log('\nRESULTADO: FRACASO — La adaptabilidad móvil aún no cumple el contrato de la Tarea 3.3.');
  process.exit(1);
}

console.log('\nRESULTADO: ÉXITO — Selector radial compacto y acordeón rúnico con conmutación viva (Tarea 3.3).');
process.exit(0);
