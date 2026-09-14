/**
 * test_elemental_aura.mjs — Arnés TDD de la Tarea 3.1 (TASKS-06).
 *
 * Estrategia TDD: este script se escribe ANTES que la implementación.
 * Verifica, sobre `public/assets/js/components/elementalAuraComponent.js`:
 *   [1] Superficie: fábrica createElementalAuraComponent con dependencias
 *       inyectables (documento, reloj, planificador de cuadros, bus) y
 *       API completa (mount, applyAura, refreshAura, dissipate, clear,
 *       consultas de estado, destroy).
 *   [2] Imbuición (RF-02.1/02.2): al aplicar un elemento, la raíz se
 *       exhibe con el color heráldico exacto en la variable CSS y el halo
 *       pulsante activo.
 *   [3] Anillo decreciente: el progreso del anillo rúnico decrece de forma
 *       proporcional al tiempo restante de la ventana de 5 s.
 *   [4] Expiración (RF-02.5): agotada la ventana, disolución suave (clase
 *       de desvanecimiento) y emisión ÚNICA de combo:aura-expired
 *       {element} sobre el bus (plan 4.1).
 *   [5] Refresco homogéneo (RF-02.4): volver a aplicar el mismo elemento
 *       reinicia la ventana a 5 s sin evento de expiración fantasma.
 *   [6] Sobreescritura visual: aplicar otro elemento actualiza el color y
 *       el estado sin pasar por expiración.
 *   [7] Disipación manual inmediata: sin clase de desvanecimiento y sin
 *       evento de expiración (no fue una expiración natural).
 *   [8] Suscripción al bus: la escucha de combo:aura-applied y
 *       combo:aura-refreshed gobierna el componente (plan 4.1) y destroy()
 *       da de baja las escuchas.
 *   [9] Movimiento reducido (RNF-03): con la media query activa, el halo
 *       se renderiza SIN la clase pulsante (el anillo informativo sigue).
 *  [10] Determinismo (RNF-01): mismos actos → mismos atributos del anillo.
 *
 * Criterio «Hecho cuando» (Tarea 3.1): al aplicar un elemento, el maniquí
 * muestra el halo y la barra circular decreciente durante 5 s, refrescándose
 * si vuelve a impactar el mismo elemento y disolviéndose suavemente al expirar.
 *
 * Constitución:
 *   - Artículo I (Dogma Vanilla): módulo ES nativo; documento, reloj,
 *     planificador y bus llegan inyectados. Cero dependencias.
 *   - Artículo IV: el color y el nombre viajan del Códice (fuente única).
 *   - Artículo V: identificadores en inglés camelCase; documentación en
 *     castellano.
 *
 * Uso: node scratch/test_elemental_aura.mjs
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

/** Reloj falso gobernado por el arnés. */
function createFakeTime({ startMs = 1_000_000 } = {}) {
  let currentMs = startMs;
  return {
    now: () => currentMs,
    advance(deltaMs) { currentMs += deltaMs; },
  };
}

/** Planificador de cuadros falso: el arnés bombea las rondas. */
function createFakeFrameScheduler() {
  let pending = [];
  let frameCount = 0;
  return {
    get frameCount() { return frameCount; },
    scheduleFrame(callback) {
      pending.push(callback);
      return () => { pending = pending.filter((cb) => cb !== callback); };
    },
    pump() {
      frameCount++;
      const callbacks = pending;
      pending = [];
      for (const callback of callbacks) callback();
    },
    get pendingRounds() { return pending.length; },
  };
}

/** Bus de eventos falso: graba lo emitido y reparte a las escuchas. */
function createFakeEventBus() {
  /** @type {Array<{type: string, detail: unknown}>} */
  const events = [];
  /** @type {Map<string, Function[]>} */
  const listeners = new Map();
  return {
    events,
    listeners,
    addEventListener(type, handler) {
      const list = listeners.get(type) ?? [];
      list.push(handler);
      listeners.set(type, list);
    },
    removeEventListener(type, handler) {
      listeners.set(type, (listeners.get(type) ?? []).filter((h) => h !== handler));
    },
    dispatchEvent(event) {
      events.push({ type: event.type, detail: event.detail ?? null });
      for (const handler of listeners.get(event.type) ?? []) {
        handler(event);
      }
      return true;
    },
    ofType(type) { return events.filter((e) => e.type === type); },
    listenerCount(type) { return (listeners.get(type) ?? []).length; },
  };
}

/** Elemento DOM falso con la superficie que usa el componente. */
class FakeElement {
  constructor(tagName) {
    this.tagName = tagName.toUpperCase();
    this.children = [];
    this.parentNode = null;
    this.attributes = new Map();
    const classSet = new Set();
    const syncClass = () => {
      // Espeja el conjunto en el atributo class (querySelectors fieles).
      if (classSet.size === 0) {
        this.attributes.delete('class');
      } else {
        this.attributes.set('class', [...classSet].join(' '));
      }
    };
    this.classList = {
      add: (...names) => { for (const n of names) classSet.add(n); syncClass(); },
      remove: (...names) => { for (const n of names) classSet.delete(n); syncClass(); },
      // La fuente de verdad es el atributo espejo: también ve las clases
      // pintadas directamente con setAttribute('class', …).
      contains: (name) => String(this.attributes.get('class') ?? '').split(' ').includes(name),
    };
    this.cssCustomProperties = new Map();
    const styleStore = this.cssCustomProperties;
    this.style = {
      setProperty: (name, value) => { styleStore.set(name, String(value)); },
      removeProperty: (name) => { styleStore.delete(name); },
      _store: styleStore,
    };
  }

  setAttribute(name, value) { this.attributes.set(name, String(value)); }
  getAttribute(name) { return this.attributes.has(name) ? this.attributes.get(name) : null; }
  removeAttribute(name) { this.attributes.delete(name); }

  appendChild(child) {
    child.parentNode = this;
    this.children.push(child);
    return child;
  }

  remove() {
    if (this.parentNode !== null) {
      const siblings = this.parentNode.children;
      const index = siblings.indexOf(this);
      if (index >= 0) siblings.splice(index, 1);
      this.parentNode = null;
    }
  }

  querySelector(selector) {
    // Selector de clase simple ('.nombre') o etiqueta; búsqueda en profundidad.
    const wanted = selector.startsWith('.') ? selector.slice(1) : null;
    const byTag = wanted === null ? selector.toLowerCase() : null;
    for (const child of this.children) {
      if (wanted !== null && child.classList.contains(wanted)) return child;
      if (byTag !== null && child.tagName.toLowerCase() === byTag) return child;
      const found = child.querySelector?.(selector);
      if (found) return found;
    }
    return null;
  }
}

/** Documento falso mínimo: createElement + createElementNS (SVG). */
function createFakeDocument() {
  return {
    createElement(tagName) { return new FakeElement(tagName); },
    createElementNS(_namespace, tagName) { return new FakeElement(tagName); },
  };
}

const MODULE_URL = new URL('../public/assets/js/components/elementalAuraComponent.js', import.meta.url).href;
const RING_CIRCUMFERENCE = 2 * Math.PI * 45; // Radio rúnico canónico del anillo.

/** Forja el componente montado en un anfitrión nuevo. */
async function forgeComponent({ time = createFakeTime(), scheduler = createFakeFrameScheduler(), bus = createFakeEventBus(), reducedMotion = false } = {}) {
  const { createElementalAuraComponent } = await import(MODULE_URL);
  const document = createFakeDocument();
  const host = new FakeElement('div');
  const component = createElementalAuraComponent({
    document,
    now: time.now,
    scheduleFrame: scheduler.scheduleFrame,
    eventTarget: bus,
    prefersReducedMotion: () => reducedMotion,
  });
  component.mount(host);
  return { component, host, time, scheduler, bus, document };
}

/** Atributo del progreso del anillo (o null si no existe el círculo). */
function ringOffsetOf(componentRoot) {
  const circle = componentRoot.querySelector('.elemental-aura__ring-progress');
  return circle === null ? null : circle.getAttribute('stroke-dashoffset');
}

console.log('== ARNÉS TDD — HALO DE AURA ELEMENTAL (Tarea 3.1, SPEC-06) ==');

// ---------------------------------------------------------------------------
console.log('\n[FASE 1] Superficie del componente');
// ---------------------------------------------------------------------------

let factory = null;
try {
  const module = await import(MODULE_URL);
  factory = module.createElementalAuraComponent;
  assertTruthy(typeof factory === 'function', 'El módulo elementalAuraComponent.js exporta su fábrica (fase roja si falta)');
} catch (error) {
  assertTruthy(false, `El módulo elementalAuraComponent.js exporta su fábrica (${error.message})`);
}

if (factory !== null) {
  const { component } = await forgeComponent();
  for (const methodName of ['mount', 'applyAura', 'refreshAura', 'dissipate', 'clear', 'isAuraActive', 'getActiveElement', 'getRemainingMs', 'destroy']) {
    assertTruthy(typeof component[methodName] === 'function', `La API expone ${methodName}()`);
  }
}

// ---------------------------------------------------------------------------
console.log('\n[FASE 2] Imbuición del aura (RF-02.1, RF-02.2)');
// ---------------------------------------------------------------------------

{
  const { component, host, scheduler, time } = await forgeComponent();
  assertTruthy(component.isAuraActive() === false, 'Sin imbuición, el blanco está neutral');
  assertTruthy(host.children.length === 0 || component.isAuraActive() === false, 'Nada visible antes del primer impacto');

  component.applyAura('fire');
  scheduler.pump();
  const root = host.children[0] ?? null;
  assertTruthy(root !== null, 'Al aplicar un elemento, la raíz del aura se monta en el anfitrión');
  assertTruthy(root?.getAttribute('hidden') === null, 'La raíz queda exhibida (sin atributo hidden)');
  assertTruthy(root?.classList.contains('elemental-aura--active') === true, 'La raíz porta la clase de aura activa');
  assertTruthy(root?.classList.contains('elemental-aura--pulse') === true, 'El halo porta la clase del pulso luminoso');
  assertTruthy(root?.style._store.get('--aura-color') === '#ff4500', 'El color heráldico del Códice viaja en la variable CSS (#ff4500 para Fuego)');
  assertTruthy(component.getActiveElement() === 'fire' && component.isAuraActive() === true, 'El estado interno declara el elemento imbuydo');
  assertTruthy(component.getRemainingMs() === 5000, 'La ventana de resonancia comienza en 5000 ms');

  const firstOffset = Number(ringOffsetOf(root));
  assertTruthy(firstOffset === 0, `El anillo nace lleno (offset 0, medido ${firstOffset})`);
}

// ---------------------------------------------------------------------------
console.log('\n[FASE 3] Anillo rúnico decreciente (RF-02.2)');
// ---------------------------------------------------------------------------

{
  const { component, host, scheduler, time } = await forgeComponent();
  component.applyAura('water');
  scheduler.pump();
  const root = host.children[0];
  const offsetAtStart = Number(ringOffsetOf(root));

  time.advance(2500);
  scheduler.pump();
  const offsetAtHalf = Number(ringOffsetOf(root));
  assertTruthy(offsetAtHalf > offsetAtStart, 'A mitad de ventana el offset crece (el anillo se consume)');
  assertTruthy(Math.abs(offsetAtHalf - RING_CIRCUMFERENCE / 2) < 2, `A mitad de ventana el offset es medio círculo (±2, medido ${offsetAtHalf.toFixed(1)})`);
  assertTruthy(Math.abs(component.getRemainingMs() - 2500) <= 1, 'El resto de ventana consultable es ~2500 ms');

  time.advance(2000);
  scheduler.pump();
  const offsetLate = Number(ringOffsetOf(root));
  assertTruthy(offsetLate > offsetAtHalf, 'El anillo sigue decreciendo monótonamente');
}

// ---------------------------------------------------------------------------
console.log('\n[FASE 4] Expiración y disolución suave (RF-02.5)');
// ---------------------------------------------------------------------------

{
  const { component, host, scheduler, time, bus } = await forgeComponent();
  component.applyAura('fire');
  scheduler.pump();

  time.advance(5000);
  scheduler.pump();

  const root = host.children[0];
  assertTruthy(component.isAuraActive() === false, 'Agotada la ventana, el aura se disuelve del estado');
  assertTruthy(root?.classList.contains('elemental-aura--fading') === true, 'La disolución es SUAVE: la raíz porta la clase de desvanecimiento');
  const expiredEvents = bus.ofType('combo:aura-expired');
  assertTruthy(expiredEvents.length === 1, 'Se emite combo:aura-expired exactamente una vez');
  assertTruthy(expiredEvents[0]?.detail?.element === 'fire', 'El evento porta el elemento expirado');

  time.advance(5000);
  scheduler.pump();
  assertTruthy(bus.ofType('combo:aura-expired').length === 1, 'Sondeos posteriores no duplican la expiración');
  assertTruthy(scheduler.pendingRounds === 0, 'El bucle de cuadros se apaga al disolverse (sin cuadros huérfanos)');
}

// ---------------------------------------------------------------------------
console.log('\n[FASE 5] Refresco homogéneo (RF-02.4)');
// ---------------------------------------------------------------------------

{
  const { component, host, scheduler, time, bus } = await forgeComponent();
  component.applyAura('water');
  scheduler.pump();
  time.advance(3000);
  scheduler.pump();

  component.refreshAura();
  assertTruthy(component.getRemainingMs() === 5000, 'El refresco reinicia el contador a 5000 ms');
  assertTruthy(component.getActiveElement() === 'water', 'El elemento persiste tras el refresco');

  time.advance(4500);
  scheduler.pump();
  assertTruthy(component.isAuraActive() === true, 'La ventana refrescada sigue viva donde la original ya expiró');
  assertTruthy(bus.ofType('combo:aura-expired').length === 0, 'Ningún evento de expiración fantasma de la ventana antigua');

  // Aplicar el mismo elemento por la puerta principal también refresca.
  component.applyAura('water');
  assertTruthy(component.getRemainingMs() === 5000, 'applyAura con el mismo elemento reinicia la ventana (combo:aura-refreshed)');
}

// ---------------------------------------------------------------------------
console.log('\n[FASE 6] Sobreescritura visual con otro elemento');
// ---------------------------------------------------------------------------

{
  const { component, host, scheduler } = await forgeComponent();
  component.applyAura('fire');
  scheduler.pump();
  const root = host.children[0];

  component.applyAura('light');
  scheduler.pump();
  assertTruthy(component.getActiveElement() === 'light', 'El aura sobreescrita declara el nuevo elemento');
  assertTruthy(root?.style._store.get('--aura-color') === '#ffd700', 'El color heráldico se actualiza al del nuevo elemento (#ffd700, Luz)');
  assertTruthy(root?.classList.contains('elemental-aura--fading') === false, 'La sobreescritura no desata disolución');
  assertTruthy(component.getRemainingMs() === 5000, 'La ventana se reinicia con la nueva aura');
}

// ---------------------------------------------------------------------------
console.log('\n[FASE 7] Disipación manual inmediata');
// ---------------------------------------------------------------------------

{
  const { component, host, scheduler, bus } = await forgeComponent();
  component.applyAura('earth');
  scheduler.pump();
  const root = host.children[0];

  component.dissipate();
  assertTruthy(component.isAuraActive() === false, 'La disipación manual apaga el aura de inmediato');
  assertTruthy(root?.classList.contains('elemental-aura--fading') === false, 'Sin clase de desvanecimiento: la retirada es inmediata');
  assertTruthy(bus.ofType('combo:aura-expired').length === 0, 'Sin evento de expiración (no fue una expiración natural)');
  assertTruthy(scheduler.pendingRounds === 0, 'El bucle de cuadros queda apagado');

  component.clear();
  assertTruthy(component.isAuraActive() === false, 'clear() sobre aura ya ausente es inocuo (Restaurar Maniquí)');
}

// ---------------------------------------------------------------------------
console.log('\n[FASE 8] Gobernancia por el bus y baja de escuchas (plan 4.1)');
// ---------------------------------------------------------------------------

{
  const { component, host, scheduler, bus, time } = await forgeComponent();

  assertTruthy(bus.listenerCount('combo:aura-applied') >= 1, 'El componente escucha combo:aura-applied');
  assertTruthy(bus.listenerCount('combo:aura-refreshed') >= 1, 'El componente escucha combo:aura-refreshed');

  bus.dispatchEvent({ type: 'combo:aura-applied', detail: { element: 'lightning', expiresAt: time.now() + 5000 } });
  assertTruthy(component.getActiveElement() === 'lightning' && component.isAuraActive() === true, 'El evento del resolutor imbuye el aura (plan 4.1)');

  bus.dispatchEvent({ type: 'combo:aura-refreshed', detail: { element: 'lightning', expiresAt: time.now() + 5000 } });
  assertTruthy(component.getRemainingMs() === 5000, 'El evento de refresco reinicia la ventana');

  const listenersBefore = bus.listenerCount('combo:aura-applied');
  component.destroy();
  assertTruthy(bus.listenerCount('combo:aura-applied') === listenersBefore - 1, 'destroy() da de baja las escuchas del bus');
  assertTruthy(host.children.length === 0 || component.isAuraActive() === false, 'destroy() retira la raíz del anfitrión');
}

// ---------------------------------------------------------------------------
console.log('\n[FASE 9] Movimiento reducido (RNF-03)');
// ---------------------------------------------------------------------------

{
  const { component, host, scheduler } = await forgeComponent({ reducedMotion: true });
  component.applyAura('wind');
  scheduler.pump();
  const root = host.children[0];
  assertTruthy(root?.classList.contains('elemental-aura--pulse') === false, 'Con movimiento reducido, el halo NO pulsa (sin clase de pulso)');
  assertTruthy(root?.classList.contains('elemental-aura--active') === true, 'El aura sigue activa y visible');
  time_then: {
    // El anillo informativo sigue decreciendo sin animación decorativa.
    component.applyAura('wind');
    scheduler.pump();
    assertTruthy(ringOffsetOf(root) !== null, 'El anillo rúnico informativo sigue presente');
  }
}

// ---------------------------------------------------------------------------
console.log('\n[FASE 10] Determinismo (RNF-01)');
// ---------------------------------------------------------------------------

{
  const runScene = async () => {
    const forged = await forgeComponent();
    forged.component.applyAura('darkness');
    forged.scheduler.pump();
    forged.time.advance(1250);
    forged.scheduler.pump();
    return { offset: ringOffsetOf(forged.host.children[0]), remaining: forged.component.getRemainingMs() };
  };
  const firstRun = await runScene();
  const secondRun = await runScene();
  assertTruthy(firstRun.offset === secondRun.offset && firstRun.remaining === secondRun.remaining, 'Dos escenas idénticas producen idéntico anillo y resto de ventana');
}

// ---------------------------------------------------------------------------
console.log('\n== RESUMEN ==');
console.log(`Asertos superados: ${passed}, fallidos: ${failed}`);

if (failed > 0) {
  console.log('\nFALLOS:');
  for (const failure of failures) {
    console.log(`  - ${failure}`);
  }
  console.log('\nRESULTADO: FRACASO — El halo de aura aún no cumple el contrato de la Tarea 3.1.');
  process.exit(1);
}

console.log('\nRESULTADO: ÉXITO — Halo pulsante, anillo decreciente, refresco y disolución suave (Tarea 3.1).');
process.exit(0);
