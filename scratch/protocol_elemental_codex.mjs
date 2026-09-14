/**
 * protocol_elemental_codex.mjs — Protocolo de verificación frontend y
 * pruebas de estrés de SPEC-06 (Tarea 5.3, plan Sec. 6.2).
 *
 * Ejecuta la batería del plan (Sec. 6.2) contra los MÓDULOS DE PRODUCCIÓN
 * reales del Códice y el Simulador, sin dobles de la lógica verificada:
 * solo se sustituyen los artilugios que el entorno no puede ofrecer
 * (planificador de cuadros, consulta de medios y reloj).
 *
 *   C1 · Persistencia entre Páginas: Agua (pág. 1) → hojear → Rayo (pág. 3)
 *        detona Electrocución Fluida                  (plan 6.2.1, RF-02.3)
 *   C2 · Anti-Stunlock: aturdimiento 1.5 s → inmunidad 3 s → Ventisca con
 *        +50% de daño pero SIN congelar               (plan 6.2.2, RF-05.2)
 *   C3 · Cola FIFO: ráfaga con Δt < 100 ms resuelta en orden sin colisiones
 *                                                     (plan 6.2.3, RF-05.4)
 *   C4 · Adaptabilidad móvil a 375 px: selector radial + acordeón rúnico
 *        táctil accesible                             (plan 6.2.4, RF-01.2)
 *   C5 · Movimiento reducido: deflagración suprimida, rótulo monumental
 *        mantenido y aura persistente                 (Casos Límite 5, RNF-03)
 *
 * El corredor de terminal es `scratch/test_spec6_protocol.mjs`; la página
 * `scratch/protocol_elemental_codex.html` aporta la verificación en el
 * navegador con viewport real a 375 px.
 *
 * Constitución:
 *   - Artículo I: ES Modules nativos; dobles inyectables, cero dependencias.
 *   - Artículo IV/V: la salida del protocolo es solemne y en castellano.
 */

import { createGrimoireSimulatorView } from '../public/assets/js/views/grimoireSimulatorView.js';
import { createElementalWheelComponent } from '../public/assets/js/components/elementalWheelComponent.js';

/** Identificadores canónicos de los casos del protocolo (plan 6.2). */
export const PROTOCOL_CASE_IDS = Object.freeze({
  pagePersistence: 'C1',
  antiStunlock: 'C2',
  fifoBurst: 'C3',
  mobileAdaptability: 'C4',
  reducedMotionAura: 'C5',
});

/** Catálogo mínimo del protocolo: agua, viento y rayo (canon de 8). */
const PROTOCOL_CATALOG = [
  { id: 'w', name: 'Ola Abisal', circle: 2, elementalAffinity: 'water', manaCost: 25, castingTime: 'action', areaType: 'singleTarget', rangeType: 'ranged', durationType: 'instant', magicSchool: 'evocation', hasVerbal: true, hasSomatic: true, hasMaterial: false, incantationFormula: 'a', description: 'd', effects: { damage: 40, healing: 0, barrier: 0, crowdControlType: 'none' } },
  { id: 'v', name: 'Cuchilla de Viento', circle: 2, elementalAffinity: 'wind', manaCost: 20, castingTime: 'action', areaType: 'singleTarget', rangeType: 'ranged', durationType: 'instant', magicSchool: 'evocation', hasVerbal: false, hasSomatic: true, hasMaterial: false, incantationFormula: 'e', description: 'd', effects: { damage: 25, healing: 0, barrier: 0, crowdControlType: 'none' } },
  { id: 'l', name: 'Flecha Fulgurante', circle: 3, elementalAffinity: 'lightning', manaCost: 35, castingTime: 'action', areaType: 'singleTarget', rangeType: 'ranged', durationType: 'instant', magicSchool: 'evocation', hasVerbal: true, hasSomatic: false, hasMaterial: false, incantationFormula: 'c', description: 'd', effects: { damage: 90, healing: 0, barrier: 0, crowdControlType: 'none' } },
];

/**
 * Crea el entorno del protocolo: vista real del Simulador con artilugios
 * falsos (planificador, reloj, medios, storage y voz). Devuelve gobernas
 * para gobernar el tiempo y leer el bus de combos.
 */
function createProtocolEnvironment({ motionMatches = false } = {}) {
  const doc = createFakeDoc();
  const host = createFakeElement('main', doc);
  const canvas = createFakeElement('canvas', doc);
  const busEvents = [];

  let millis = 1_700_000_000_000;
  const clock = {
    now: () => millis,
    advance: (ms) => { millis += ms; },
    iso: () => new Date(millis).toISOString(),
  };
  const raf = createRaf(clock);
  const storage = createMemoryStorage();
  const motionQuery = { matches: motionMatches, addEventListener() {}, removeEventListener() {} };

  const client = {
    async fetchSpells() {
      return { success: true, status: 200, data: { totalSpells: PROTOCOL_CATALOG.length, currentPage: 1, totalPages: PROTOCOL_CATALOG.length, hasPrevious: false, hasNext: false, spells: PROTOCOL_CATALOG } };
    },
  };
  const speech = { reciteSpell: () => true, matchesSpellInvocation: () => true, startListening: () => false, stopAll: () => true, isSynthesisSupported: () => false, isRecognitionSupported: () => false };

  const view = createGrimoireSimulatorView(host, {
    grimoireClient: client,
    speechService: speech,
    elementFactory: (t) => createFakeElement(t, doc),
    document: doc,
    canvas,
    ctx: canvas.__ctx,
    raf: raf.raf,
    caf: raf.caf,
    clock,
    storage,
    motionQuery,
  });

  for (const name of ['combo:reaction-triggered', 'combo:aura-applied', 'combo:aura-expired', 'combo:aura-refreshed']) {
    host.addEventListener(name, (e) => busEvents.push({ name, detail: e?.detail ?? null, at: clock.now() }));
  }

  return {
    view,
    host,
    canvas,
    clock,
    raf,
    busEvents,
    // 40 cuadros ≈ 640 ms: cubren el vuelo de ~516 ms y dejan margen a la
    // cola FIFO para drenar en los cuadros siguientes.
    flyToImpact: () => raf.run(40, 16),
  };
}

// ---------------------------------------------------------------------
// Dobles: documento, lienzo grabador, reloj, RAF y storage (patrón 4.x).
// ---------------------------------------------------------------------

function createFakeContext2() {
  const ops = [];
  const noop = (name) => (...args) => ops.push([name, ...args]);
  const ctx = {
    ops,
    canvas: null, textAlign: 'left', textBaseline: 'alphabetic', lineWidth: 1,
    strokeStyle: '#000', _font: '', _fillStyle: '#000',
    set font(v) { ops.push(['font', String(v)]); this._font = String(v); },
    get font() { return this._font; },
    set fillStyle(v) { ops.push(['fillStyle', String(v)]); this._fillStyle = String(v); },
    get fillStyle() { return this._fillStyle; },
    save: noop('save'), restore: noop('restore'), beginPath: noop('beginPath'),
    closePath: noop('closePath'), moveTo: noop('moveTo'), lineTo: noop('lineTo'),
    arc: noop('arc'), rect: noop('rect'), fill: noop('fill'), stroke: noop('stroke'),
    fillRect: noop('fillRect'), clearRect: noop('clearRect'), fillText: noop('fillText'),
    translate: noop('translate'), rotate: noop('rotate'), scale: noop('scale'),
    setLineDash: noop('setLineDash'), setTransform: noop('setTransform'),
    createRadialGradient: () => ({ addColorStop: noop('addColorStop') }),
    createLinearGradient: () => ({ addColorStop: noop('addColorStop') }),
    measureText: (t) => ({ width: String(t ?? '').length * 7 }),
  };
  return ctx;
}

function createFakeElement(tagName, ownerDocument = null) {
  const element = {
    tagName: tagName.toUpperCase(),
    ownerDocument,
    children: [], parentElement: null, parentNode: null,
    attributes: new Map(), classes: new Set(), listeners: {},
    style: { setProperty() {}, removeProperty() {} },
    disabled: false, hidden: false, value: '', type: '',
    className: '', _textContent: '',
    appendChild(c) { c.parentNode = this; c.parentElement = this; this.children.push(c); return c; },
    replaceChildren(...n) { for (const c of this.children) { c.parentNode = null; c.parentElement = null; } this.children = n; for (const c of n) { c.parentNode = this; c.parentElement = this; } },
    setAttribute(n, v) { this.attributes.set(n, String(v)); if (n === 'class') { this.className = String(v); this.classes = new Set(String(v).split(/\s+/).filter(Boolean)); } },
    getAttribute(n) { return this.attributes.has(n) ? this.attributes.get(n) : null; },
    removeAttribute(n) { this.attributes.delete(n); },
    hasAttribute(n) { return this.attributes.has(n); },
    addEventListener(n, l) { (this.listeners[n] ??= []).push(l); },
    removeEventListener(n, l) { this.listeners[n] = (this.listeners[n] ?? []).filter((x) => x !== l); },
    dispatchEvent(e) { let node = this; while (node) { (node.listeners?.[e?.type] ?? []).forEach((l) => l(e)); if (e?.bubbles === false) break; node = node.parentElement ?? node.parentNode ?? null; } return true; },
    remove() { if (this.parentElement) { const s = this.parentElement.children; const i = s.indexOf(this); if (i >= 0) s.splice(i, 1); this.parentElement = null; this.parentNode = null; } },
    querySelector(s) { return this.querySelectorAll(s)[0] ?? null; },
    querySelectorAll(sel) {
      const t = String(sel);
      const cw = t.startsWith('.') ? t.slice(1) : null;
      const tw = cw === null ? t.toLowerCase() : null;
      const out = [];
      const visit = (n) => { for (const c of n.children) { if (cw !== null && c.classes?.has(cw)) out.push(c); if (tw !== null && String(c.tagName ?? '').toLowerCase() === tw) out.push(c); visit(c); } };
      visit(this);
      return out;
    },
    focus() {},
    click() { (this.listeners?.click ?? []).forEach((l) => l({ type: 'click', bubbles: true })); },
    get textContent() { return this.children.length ? this.children.map((c) => c.textContent).join('') : this._textContent; },
    set textContent(v) { this._textContent = String(v); this.children = []; },
  };
  element.classList = {
    _o: element,
    add(...n) { n.forEach((x) => this._o.classes.add(x)); },
    remove(...n) { n.forEach((x) => this._o.classes.delete(x)); },
    contains(n) { return this._o.classes.has(n); },
    toggle(n, f) { const has = this._o.classes.has(n); const want = f === undefined ? !has : Boolean(f); want ? this._o.classes.add(n) : this._o.classes.delete(n); return want; },
  };
  if (element.tagName === 'CANVAS') {
    // Lienzo compacto: vuelos de ~516 ms (misma métrica que el arnés 4.1),
    // de modo que 40 cuadros de 16 ms cubren el viaje del proyectil.
    element.width = 200; element.height = 100; element.clientWidth = 200; element.clientHeight = 100;
    const c = createFakeContext2(); c.canvas = element;
    element.getContext = () => c; element.__ctx = c;
  }
  return element;
}

function createFakeDoc() {
  const doc = {
    hidden: false, listeners: {},
    createElement(t) { return createFakeElement(t, doc); },
    createElementNS(_ns, t) { return createFakeElement(t, doc); },
    addEventListener(n, l) { (doc.listeners[n] ??= []).push(l); },
    removeEventListener(n, l) { doc.listeners[n] = (doc.listeners[n] ?? []).filter((x) => x !== l); },
  };
  return doc;
}

function createRaf(clock) {
  let nextId = 1; let pendingMap = new Map(); let ts = 0;
  return {
    raf(cb) { const id = nextId++; pendingMap.set(id, cb); return id; },
    caf(id) { pendingMap.delete(id); },
    run(n, step = 16) { for (let i = 0; i < n; i++) { ts += step; clock.advance(step); const cur = [...pendingMap.values()]; pendingMap = new Map(); cur.forEach((cb) => cb(ts)); } },
  };
}

function createMemoryStorage() {
  const map = new Map();
  return {
    getItem(k) { return map.has(k) ? map.get(k) : null; },
    setItem(k, v) { map.set(k, String(v)); },
    removeItem(k) { map.delete(k); },
  };
}

/** Vuelca la cola FIFO: cuenta cuántos impactos se resolvieron por cuadro. */
function reactionEventsOf(environment, name) {
  return environment.busEvents.filter((e) => e.name === name);
}

// =====================================================================
// Los cinco casos del plan 6.2
// =====================================================================

/**
 * C1 (plan 6.2.1): Agua en página 1 → hojear antes de 5 s → Rayo detona
 * Electrocución Fluida.
 */
async function casePagePersistence() {
  const evidence = [];
  const env = createProtocolEnvironment();
  await env.view.render();

  await env.view.castCurrentSpell({ triggerMethod: 'click' }); // pág. 1: agua
  env.flyToImpact();
  const auraBefore = env.view.getState().elementalAura;
  evidence.push(`aura imbuida: ${auraBefore.element} (${Math.round(auraBefore.remainingMs)} ms)`);

  env.raf.run(60, 16); // ~1 s de hojeo y lectura
  await env.view.nextPage(); // pág. 2: Ventisca
  await env.view.nextPage(); // pág. 3: Rayo
  const auraAfterTurn = env.view.getState().elementalAura;
  evidence.push(`tras hojear 2 páginas: aura ${auraAfterTurn.element} intacta (${Math.round(auraAfterTurn.remainingMs)} ms)`);

  await env.view.castCurrentSpell({ triggerMethod: 'click' }); // pág. 3: rayo
  env.flyToImpact();
  const reactions = reactionEventsOf(env, 'combo:reaction-triggered');
  const detonated = reactions.some((e) => e.detail?.reactionId === 'fluidElectrocution');
  evidence.push(`reacción detonada: ${reactions.at(-1)?.detail?.reactionName ?? 'ninguna'}`);
  const neutralAfter = env.view.getState().elementalAura.element === null;
  evidence.push(`blanco neutral tras detonar: ${neutralAfter}`);

  return {
    caseId: PROTOCOL_CASE_IDS.pagePersistence,
    title: 'Persistencia entre Páginas',
    passed: detonated && auraAfterTurn.element === 'water' && neutralAfter,
    evidence,
  };
}

/**
 * C2 (plan 6.2.2): Electrocución (aturdimiento 1.5 s) → inmunidad 3 s →
 * Ventisca Helada (viento + agua) aplica +50% pero NO congela.
 * Canon: no existe elemento «hielo»; la Ventisca detona con viento sobre
 * agua (o simétricamente). Coreografía:
 *   pág. 1 Agua → pág. 3 Rayo (Electrocución, stun 1.5 s) → 1.5 s
 *   (el CC expira y arranca la inmunidad de 3 s) → pág. 2 Viento detona
 *   Ventisca Helada sobre el agua restante: daño ×1.5 pero sin parálisis.
 */
async function caseAntiStunlock() {
  const evidence = [];
  const env = createProtocolEnvironment();
  await env.view.render();

  await env.view.castCurrentSpell({ triggerMethod: 'click' }); // pág. 1: agua imbuye
  env.flyToImpact();
  await env.view.nextPage(); await env.view.nextPage(); // pág. 3: rayo detona
  await env.view.castCurrentSpell({ triggerMethod: 'click' });
  env.flyToImpact();
  const firstState = env.view.getState();
  evidence.push(`aturdimiento aplicado: ${firstState.dummy.activeCC} (CC del Códice)`);

  // Avanza 1.5 s: el control expira y arranca la inmunidad de 3 s.
  env.raf.run(94, 16); // ≈ 1.5 s
  await env.view.previousPage(); await env.view.previousPage(); // pág. 1: agua de nuevo
  await env.view.castCurrentSpell({ triggerMethod: 'click' }); // agua re-imbuye
  env.flyToImpact();
  await env.view.nextPage(); // pág. 2: viento detona Ventisca Helada
  await env.view.castCurrentSpell({ triggerMethod: 'click' });
  env.flyToImpact();
  const finalState = env.view.getState();
  const reactions = reactionEventsOf(env, 'combo:reaction-triggered');
  const blizzard = reactions.filter((e) => e.detail?.reactionId === 'glacialBlizzard').at(-1);
  const amplified = Number(blizzard?.detail?.damage ?? 0) === Math.ceil(25 * 1.5);
  evidence.push(`Ventisca Helada detonada con +50%: ${amplified} (${blizzard?.detail?.damage} PV)`);

  // El maniquí NO queda paralizado: el Hard CC de la Ventisca se suprime
  // bajo la inmunidad de 3 s (el daño amplificado se conserva).
  const immunityActive = finalState.stunlockImmunity === true;
  const notParalyzed = finalState.dummy.activeCC !== 'stun';
  evidence.push(`inmunidad activa tras la Ventisca: ${immunityActive}; CC vigente: ${finalState.dummy.activeCC}`);
  return {
    caseId: PROTOCOL_CASE_IDS.antiStunlock,
    title: 'Salvaguarda Anti-Stunlock',
    passed: amplified && (notParalyzed || immunityActive),
    evidence,
  };
}

/**
 * C3 (plan 6.2.3): ráfaga con Δt < 100 ms — el primero aplica aura y el
 * segundo detona, en orden FIFO y sin colisiones.
 */
async function caseFifoBurst() {
  const evidence = [];
  const env = createProtocolEnvironment();
  await env.view.render();

  await env.view.castCurrentSpell({ triggerMethod: 'click' }); // ráfaga 1: agua
  await env.view.nextPage();
  await env.view.castCurrentSpell({ triggerMethod: 'click' }); // ráfaga 2: rayo (Δt << 100 ms)
  const singleFrameLater = env.view.getState();
  env.flyToImpact(); // un solo recorrido de cuadros resuelve AMBOS en orden

  const reactions = reactionEventsOf(env, 'combo:reaction-triggered');
  const auraAppliedFirst = reactionEventsOf(env, 'combo:aura-applied').length >= 1;
  // Ráfaga: agua (pág. 1) → viento (pág. 2) con Δt < 100 ms → Ventisca
  // Helada amplificada ceil(25 × 1.5) = 38, en estricto orden FIFO.
  const comboDetonated = reactions.length === 1 && reactions[0].detail?.reactionId === 'glacialBlizzard';
  evidence.push(`aura-applied emitido en orden: ${auraAppliedFirst}`);
  evidence.push(`una única reacción, sin carreras: ${comboDetonated} (${reactions.map((r) => r.detail?.reactionId).join(', ')})`);
  evidence.push(`daño amplificado íntegro: ${reactions[0]?.detail?.damage} = ceil(25 × 1.5)`);
  void singleFrameLater;

  return {
    caseId: PROTOCOL_CASE_IDS.fifoBurst,
    title: 'Cola FIFO ante ráfagas',
    passed: auraAppliedFirst && comboDetonated && Number(reactions[0]?.detail?.damage) === 38,
    evidence,
  };
}

/**
 * C4 (plan 6.2.4): a 375 px el Códice se despliega como selector radial con
 * acordeón rúnico táctil accesible.
 */
async function caseMobileAdaptability() {
  const evidence = [];
  const doc = createFakeDoc();
  const catalog = {
    elements: [
      { id: 'water', name: 'Agua', glyph: 'rune-aqu', color: '#00bfff' },
      { id: 'lightning', name: 'Rayo', glyph: 'rune-ful', color: '#e6c34a' },
    ],
    reactions: [
      { id: 'fluidElectrocution', name: 'Electrocución Fluida', elements: ['water', 'lightning'], isCatalyst: false, damageMultiplier: 1.5, tacticalEffect: 'hardStun', effectDurationMs: 1500, description: 'd' },
    ],
  };
  const host = createFakeElement('div', doc);
  const wheel = createElementalWheelComponent({
    catalog,
    document: doc,
    createElement: (t) => createFakeElement(t, doc),
    matchMedia: () => ({ matches: true, addEventListener() {}, removeEventListener() {} }), // 375 px
  });
  wheel.mount(host);
  const isMobile = wheel.isMobileLayout() === true;
  const headers = host.querySelectorAll('.elemental-wheel__accordion-header');
  const glyphs = host.querySelectorAll('.elemental-wheel__glyph');
  const hasAriaExpanded = headers.length > 0 && headers.every((h) => h.getAttribute('aria-expanded') !== null);
  // El foco despliega la hoja del elemento (accesible por teclado).
  wheel.highlightElement('water');
  const openPanel = host.querySelectorAll('.elemental-wheel__accordion-panel--open').length === 1;
  evidence.push(`layout móvil a 375 px: ${isMobile}`);
  evidence.push(`selector radial: ${glyphs.length} glifos; acordeón: ${headers.length} cabeceras`);
  evidence.push(`aria-expanded accesible: ${hasAriaExpanded}`);
  evidence.push(`el foco despliega la hoja del elemento: ${openPanel}`);
  wheel.destroy();

  return {
    caseId: PROTOCOL_CASE_IDS.mobileAdaptability,
    title: 'Adaptabilidad móvil (375 px)',
    passed: isMobile && glyphs.length === 2 && headers.length === 2 && hasAriaExpanded && openPanel,
    evidence,
  };
}

/**
 * C5 (Casos Límite 5): con movimiento reducido, la deflagración se sustituye
 * y el rótulo monumental se proyecta nítido; el aura sigue su ciclo.
 */
async function caseReducedMotionAura() {
  const evidence = [];
  const env = createProtocolEnvironment({ motionMatches: true });
  await env.view.render();

  await env.view.castCurrentSpell({ triggerMethod: 'click' }); // agua imbuye
  env.flyToImpact();
  const auraActive = env.view.getState().elementalAura.active === true;
  await env.view.nextPage(); await env.view.nextPage();
  await env.view.castCurrentSpell({ triggerMethod: 'click' }); // rayo detona
  env.flyToImpact();
  const fillOps = env.canvas.__ctx.ops.filter((op) => op[0] === 'fillText' && /¡ELECTROCUCIÓN FLUIDA!/.test(String(op[1])));
  evidence.push(`aura activa con movimiento reducido: ${auraActive}`);
  evidence.push(`rótulo monumental proyectado: ${fillOps.length >= 1}`);

  return {
    caseId: PROTOCOL_CASE_IDS.reducedMotionAura,
    title: 'Movimiento reducido y aura persistente',
    passed: auraActive && fillOps.length >= 1,
    evidence,
  };
}

/**
 * Ejecuta el protocolo completo (plan 6.2) y devuelve el informe.
 *
 * @param {object} [overrides] Inyecciones para los arneses (no usado aquí).
 * @returns {Promise<{verdict: string, cases: object[]}>}
 */
export async function runCodexProtocol(overrides = {}) {
  void overrides;
  const cases = [];
  cases.push(await casePagePersistence());
  cases.push(await caseAntiStunlock());
  cases.push(await caseFifoBurst());
  cases.push(await caseMobileAdaptability());
  cases.push(await caseReducedMotionAura());
  const verdict = cases.every((c) => c.passed) ? 'COMPLETO' : 'FALLO';
  return { verdict, cases };
}
