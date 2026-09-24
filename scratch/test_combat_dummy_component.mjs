/**
 * test_combat_dummy_component.mjs — Arnés TDD de la Tarea 3.1 (TASKS-05).
 *
 * Estrategia TDD: este script se escribe ANTES que la implementación.
 * Verifica, sobre `public/assets/js/components/combatDummyComponent.js`:
 *   [1] Fábrica y estado inicial (RF-02.1): 500 PV, barrera 0, sin CC,
 *       estado «intact», barra visible en el DOM simulado.
 *   [2] Algoritmo de impacto cuantitativo (RF-02.4, plan 3.2): absorción
 *       prioritaria de barrera, derrame a PV, renovación por valor
 *       dominante (sin apilamiento), techo de curación 500 y leyenda
 *       «[Salud Plena]» con salud llena.
 *   [3] Control de masas (RF-02.4/RF-05.1): activación con 4 s de vigencia
 *       y disipación simulada del tiempo.
 *   [4] Persistencia entre páginas (RF-02.3): hojear no altera el estado.
 *   [5] Destrucción y regeneración (RF-02.5): a 0 PV nube de paja y
 *       regeneración automática a 2 s (reloj inyectable).
 *   [6] «Restaurar Maniquí» (RF-02.6): reseteo instantáneo completo.
 *
 * Constitución:
 *   - Artículo I: ES Modules nativos, DOM simulado sin frameworks.
 *   - Artículo IV/V: leyendas solemnes castellanas; API en inglés camelCase.
 *
 * Uso: node scratch/test_combat_dummy_component.mjs
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

/** Reloj inyectable: el arnés controla el tiempo de CC y regeneración. */
function createFakeClock() {
  let now = 1_000_000;
  return {
    get now() { return now; },
    advance(ms) { now += ms; },
  };
}

/** DOM mínimo simulado (patrón consolidado; innerHTML prohibido). */
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
    parentElement: null,
    setAttribute(name, value) { this.attributes[name] = String(value); },
    getAttribute(name) { return name in this.attributes ? this.attributes[name] : null; },
    hasAttribute(name) { return name in this.attributes ? true : false; },
    addEventListener(name, listener) { (this.listeners[name] ??= []).push(listener); },
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
    get innerHTML() { throw new Error('PROHIBIDO innerHTML (AGENTS.md 6.1)'); },
    set innerHTML(value) { throw new Error('PROHIBIDO innerHTML (AGENTS.md 6.1)'); },
  };
  element.classList = {
    _owner: element,
    add(...names) { names.forEach((n) => this._owner.classes.add(n)); },
    remove(...names) { names.forEach((n) => this._owner.classes.delete(n)); },
    contains(name) { return this._owner.classes.has(name); },
    toggle(name, force) {
      const want = force === undefined ? !this._owner.classes.has(name) : Boolean(force);
      if (want) this._owner.classes.add(name);
      else this._owner.classes.delete(name);
      return want;
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

console.log('== ARNÉS TDD — Tarea 3.1: Máquina de estados del Maniquí Arcano ==');

let module;
try {
  module = await import('../public/assets/js/components/combatDummyComponent.js');
} catch (error) {
  console.log(`  FATAL — no se pudo importar combatDummyComponent.js: ${error.message}`);
  console.log('== RESUMEN == Fase roja: la implementación aún no existe.');
  process.exit(1);
}

const { createCombatDummyComponent } = module;

/** Construye un maniquí montado sobre un anfitrión simulado. */
function buildDummy(clock) {
  const host = createFakeElement('div');
  host.classList.add('dummy-host');
  const component = createCombatDummyComponent({ host, clock });
  return { component, host, clock };
}

/** Conjuro de prueba con los efectos pedidos. */
function spell({ damage = 0, healing = 0, barrier = 0, crowdControlType = 'none' } = {}) {
  return { name: 'Conjuro de Prueba', effects: { damage, healing, barrier, crowdControlType } };
}

console.log('[1] Fábrica y estado inicial (RF-02.1)');

const clockOne = createFakeClock();
const { component, host } = buildDummy(clockOne);

assertCondition(typeof createCombatDummyComponent === 'function', 'exporta createCombatDummyComponent(options)');
assertCondition(component.getState().health === 500, 'salud base de 500 PV');
assertCondition(component.getState().barrier === 0, 'barrera inicial a 0');
assertCondition(component.getState().activeCC === null, 'sin control de masas activo');
assertCondition(component.getState().state === 'intact', 'estado inicial «intact»');
assertCondition(component.getState().maxHealth === 500, 'techo inmutable registrado (maxHealth 500)');
assertCondition(host.children.length > 0, 'el maniquí se monta en el anfitrión con su barra');

console.log('[2] Algoritmo de impacto cuantitativo (RF-02.4, plan 3.2)');

{
  // Daño puro.
  const { component: dummy } = buildDummy(createFakeClock());
  const result = dummy.applySpellImpact(spell({ damage: 120 }));
  assertCondition(result.remainingHealth === 380, 'daño puro: 500 − 120 = 380 PV');
  assertCondition(dummy.getState().state === 'damaged', 'estado «damaged» tras recibir daño');

  // Curación con techo inmutable.
  const heal = dummy.applySpellImpact(spell({ healing: 50 }));
  assertCondition(heal.remainingHealth === 430, 'curación aplica por encima del daño (430 PV)');
  const over = dummy.applySpellImpact(spell({ healing: 999 }));
  assertCondition(over.remainingHealth === 500, 'la curación jamás supera el techo de 500 PV');
  assertCondition(over.fullHealthLegend === true, 'con salud plena se emite la leyenda «[Salud Plena]»');

  // Barrera: absorción prioritaria total.
  const shielded = buildDummy(createFakeClock());
  shielded.component.applySpellImpact(spell({ barrier: 100 }));
  assertCondition(shielded.component.getState().barrier === 100, 'barrera aplicada (100)');
  assertCondition(shielded.component.getState().state === 'shielded', 'estado «shielded» con barrera activa');
  const absorbed = shielded.component.applySpellImpact(spell({ damage: 40 }));
  assertCondition(absorbed.barrierAbsorbed === 40, 'la barrera absorbe el daño en primer término (40)');
  assertCondition(absorbed.remainingHealth === 500, 'los PV no sufren con absorción total');
  assertCondition(shielded.component.getState().barrier === 60, 'la barrera se descuenta (100 → 60)');

  // Barrera: derrame a PV cuando el daño la supera.
  const leaked = shielded.component.applySpellImpact(spell({ damage: 90 }));
  assertCondition(leaked.barrierAbsorbed === 60, 'la barrera agotada absorbe su resto (60)');
  assertCondition(leaked.damageApplied === 30, 'el excedente derrama a PV (90 − 60 = 30)');
  assertCondition(leaked.remainingHealth === 470, 'PV tras el derrame (500 − 30 = 470)');

  // Renovación por valor dominante (sin apilamiento).
  const renewal = buildDummy(createFakeClock());
  renewal.component.applySpellImpact(spell({ barrier: 80 }));
  renewal.component.applySpellImpact(spell({ barrier: 50 }));
  assertCondition(renewal.component.getState().barrier === 80, 'barrera menor NO apila (mantiene 80)');
  renewal.component.applySpellImpact(spell({ barrier: 150 }));
  assertCondition(renewal.component.getState().barrier === 150, 'barrera mayor reemplaza por valor dominante (150)');

  // Impacto mixto simultáneo (daño + curación + barrera).
  const mixed = buildDummy(createFakeClock());
  mixed.component.applySpellImpact(spell({ damage: 200 }));
  const mixedResult = mixed.component.applySpellImpact(spell({ damage: 30, healing: 60, barrier: 40 }));
  assertCondition(mixedResult.remainingHealth === 330, 'impacto mixto: 300 − 30 + 60 = 330 PV');
  assertCondition(mixed.component.getState().barrier === 40, 'impacto mixto deja su barrera activa');
}

console.log('[3] Control de masas con disipación a 4 s (RF-02.4 / RF-05.1)');

{
  const cc = buildDummy(createFakeClock());
  const result = cc.component.applySpellImpact(spell({ damage: 10, crowdControlType: 'stun' }));
  assertCondition(result.crowdControlApplied === 'stun', 'el CC del conjuro se aplica');
  assertCondition(cc.component.getState().activeCC === 'stun', 'estado alterado registrado');
  assertCondition(cc.component.getState().state === 'ccIncapacitated', 'estado «ccIncapacitated»');
  assertCondition(cc.component.getState().ccExpiresAt - cc.clock.now === 4000, 'vigencia exacta de 4000 ms');

  // El reloj avanza: la CC disipa a los 4 s.
  cc.clock.advance(3900);
  cc.component.tick();
  assertCondition(cc.component.getState().activeCC === 'stun', 'a los 3.9 s la atadura persiste');
  cc.clock.advance(200);
  cc.component.tick();
  assertCondition(cc.component.getState().activeCC === null, 'a los 4.1 s la atadura se disipa');
  assertCondition(cc.component.getState().state === 'damaged', 'tras la CC el maniquí vuelve a «damaged» (estaba herido)');
}

console.log('[4] Persistencia entre páginas (RF-02.3)');

{
  const persisted = buildDummy(createFakeClock());
  persisted.component.applySpellImpact(spell({ damage: 175 }));
  persisted.component.applySpellImpact(spell({ barrier: 60 }));
  const before = { ...persisted.component.getState() };
  persisted.component.onPageChange(); // hojear el tomo a otro conjuro
  const after = persisted.component.getState();
  assertCondition(after.health === before.health, 'el daño acumulado sobrevive al hojear (325 PV)');
  assertCondition(after.barrier === before.barrier, 'la barrera activa sobrevive al hojear');
  assertCondition(after.state === before.state, 'el estado de la máquina sobrevive al hojear');
}

console.log('[5] Destrucción y regeneración automática a 2 s (RF-02.5)');

{
  const doomed = buildDummy(createFakeClock());
  const lethal = doomed.component.applySpellImpact(spell({ damage: 600 }));
  assertCondition(lethal.remainingHealth === 0, 'golpe letal reduce la salud a 0');
  assertCondition(doomed.component.getState().state === 'destroyed', 'estado «destroyed» (nube de paja)');

  const pending = doomed.component.getState().regenerationAt;
  assertCondition(pending - doomed.clock.now === 2000, 'regeneración programada exactamente a 2 s');

  // Antes de los 2 s: sigue destruido.
  doomed.clock.advance(1900);
  doomed.component.tick();
  assertCondition(doomed.component.getState().state === 'destroyed', 'a los 1.9 s sigue destruido');

  // Tras los 2 s: regeneración automática intacta.
  doomed.clock.advance(200);
  doomed.component.tick();
  assertCondition(doomed.component.getState().state === 'intact', 'a los 2.1 s el armazón se regenera solo');
  assertCondition(doomed.component.getState().health === 500, 'regenerado con los 500 PV originales');
  assertCondition(doomed.component.getState().barrier === 0, 'sin barreras heredadas tras regenerar');
}

console.log('[6] «Restaurar Maniquí» — reseteo instantáneo (RF-02.6)');

{
  const restored = buildDummy(createFakeClock());
  restored.component.applySpellImpact(spell({ damage: 300, crowdControlType: 'root' }));
  restored.component.applySpellImpact(spell({ barrier: 90 }));
  assertCondition(restored.component.getState().state !== 'intact', 'maniquí herido, atado y escudado antes del reset');
  const reset = restored.component.restore();
  assertCondition(reset.health === 500, 'restauración instantánea a 500 PV');
  assertCondition(reset.barrier === 0, 'barreras disipadas');
  assertCondition(reset.activeCC === null, 'estados alterados disipados');
  assertCondition(reset.state === 'intact', 'estado «intact» restaurado');
}

// ============================================================================
console.log('\n[RF-05.1] Cúpula luminosa y ataduras visuales');
// ============================================================================

{
  const visual = buildDummy(createFakeClock());
  const figureOf = () => visual.host.children.find((c) => String(c.className).includes('combat-dummy__figure'));
  const hasClass = (name) => Boolean(figureOf()?.classes?.has?.(name));

  // El daño puro jamás alza la cúpula.
  visual.component.applySpellImpact(spell({ damage: 30 }));
  assertCondition(!hasClass('combat-dummy--dome'), 'el daño puro no alza la cúpula luminosa (RF-05.1)');

  // Barrera: cúpula con residencia de ~600 ms.
  visual.component.applySpellImpact(spell({ barrier: 25 }));
  assertCondition(hasClass('combat-dummy--dome'), 'la barrera alza la cúpula luminosa (RF-05.1)');
  assertCondition(visual.component.getState().domeUntil - visual.clock.now === 600, 'la cúpula vive 600 ms (residencia canónica)');
  visual.clock.advance(700);
  visual.component.tick();
  assertCondition(!hasClass('combat-dummy--dome'), 'la cúpula se retira sola al vencer su residencia');

  // Curación: también alza la cúpula.
  visual.component.applySpellImpact(spell({ healing: 20 }));
  assertCondition(hasClass('combat-dummy--dome'), 'la curación alza la cúpula luminosa (RF-05.1)');

  // Ataduras visuales: una clase por tipo de CC.
  visual.component.applySpellImpact(spell({ crowdControlType: 'stun' }));
  assertCondition(hasClass('combat-dummy--bound-stun'), 'el stun viste la atadura de hielo (RF-05.1)');
  visual.component.applySpellImpact(spell({ crowdControlType: 'root' }));
  assertCondition(hasClass('combat-dummy--bound-root') && !hasClass('combat-dummy--bound-stun'), 'el root sustituye al hielo con sus enredaderas');
  visual.component.applySpellImpact(spell({ crowdControlType: 'slow' }));
  assertCondition(hasClass('combat-dummy--bound-slow'), 'el slow viste el halo de lentitud (RF-05.1)');

  // Disipación visual al vencer la atadura.
  visual.clock.advance(4100);
  visual.component.tick();
  assertCondition(!hasClass('combat-dummy--bound-slow'), 'la atadura visual se disipa con el CC a los 4 s (RF-05.1)');

  // Restauración inmediata: ataduras y cúpula ceden al instante.
  visual.component.applySpellImpact(spell({ crowdControlType: 'stun', barrier: 10 }));
  assertCondition(hasClass('combat-dummy--bound-stun'), 'atadura viva antes de restaurar');
  visual.component.restore();
  assertCondition(!hasClass('combat-dummy--bound-stun') && !hasClass('combat-dummy--dome'), '«Restaurar Maniquí» retira ataduras y cúpula al instante (RF-05.1)');
}

console.log('== RESUMEN ==');
console.log(`Asertos superados: ${assertsPassed}, fallidos: ${assertsFailed}`);
if (assertsFailed === 0) {
  console.log('RESULTADO: EXITO — Maniquí Arcano listo para la Cámara de Conjuración (Tarea 3.1).');
  process.exit(0);
} else {
  console.log('RESULTADO: DENEGADO — hay asertos incumplidos.');
  process.exit(1);
}
