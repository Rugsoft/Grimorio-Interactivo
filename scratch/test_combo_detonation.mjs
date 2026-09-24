/**
 * test_combo_detonation.mjs — Arnés TDD de la Tarea 4.2 (TASKS-06).
 *
 * Estrategia TDD: este script se escribe ANTES que la implementación.
 * Verifica la deflagración de partículas fusionadas y el Texto Flotante
 * Monumental (RF-06.1, RF-06.2):
 *   [0] Motor de partículas: `emitComboDetonation` emite una explosión
 *       radial bicromática alternando colorA/colorB, con PRNG inyectable
 *       (determinismo) y respeto del techo de 200 partículas.
 *   [1] Unicidad visual por reacción: cada par heráldico pinta su propia
 *       deflagración (los 8 pares del Códice son distintos dos a dos).
 *   [2] Textos flotantes: `spawnMonumentalText` proyecta el rótulo
 *       ceremonial en oro rúnico, escala ×1.3 (23 px) y cúspide del
 *       blanco, con el formato «¡NOMBRE! −X PV» (RNF-04).
 *   [3] Integración en la vista: detonar Agua→Rayo produce la deflagración
 *       en el lienzo Y el texto monumental; un impacto sin combo jamás
 *       detona nada monumental; movimiento reducido suprime las partículas
 *       pero conserva el texto (degradación grácil).
 *
 * Constitución:
 *   - Artículo I: Canvas 2D y ES Modules nativos; dobles inyectables.
 *   - Artículo II: los colores viajan del Códice (veredicto), no se
 *     deciden en la capa visual.
 *   - Artículo V: API en inglés camelCase; rótulos en noble castellano.
 *
 * Uso: node scratch/test_combo_detonation.mjs
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

/** PRNG determinista mulberry32 (RNF-01: cero azar no gobernado). */
function createDeterministicRandom(seed = 42) {
  let state = seed;
  return () => {
    state |= 0;
    state = (state + 0x6d2b79f5) | 0;
    let t = Math.imul(state ^ (state >>> 15), 1 | state);
    t = (t + Math.imul(t ^ (t >>> 7), 61 | t)) ^ t;
    return ((t ^ (t >>> 14)) >>> 0) / 4294967296;
  };
}

// =====================================================================
// [0] Motor de partículas: emitComboDetonation
// =====================================================================
console.log('\n[0] Deflagración bicromática en el motor (RF-06.1)');

let engine;
try {
  engine = await import('../public/assets/js/utils/particleEngine.js');
} catch (error) {
  console.log(`  FATAL — no se pudo importar particleEngine.js: ${error.message}`);
  console.log('== RESUMEN == Fase roja: la deflagración aún no existe.');
  process.exit(1);
}

const { ParticlePool, MAX_PARTICLES } = engine;
const CENTER = { x: 300, y: 200 };

const pool = new ParticlePool();
const emitted = pool.emitComboDetonation(CENTER, {
  colorA: '#00bfff',
  colorB: '#9932cc',
  random: createDeterministicRandom(),
});

assertCondition(typeof pool.emitComboDetonation === 'function', 'ParticlePool expone emitComboDetonation');
assertCondition(emitted > 0, `la deflagración emite partículas (${emitted})`);
assertCondition(emitted >= 12, `la deflagración es monumental (≥ 12 partículas, hay ${emitted})`);
assertCondition(
  pool.getActiveCount() === emitted,
  'el recuento activo coincide con lo emitido',
);

// Bicromía y radialidad: colores alternos y velocidades omnidireccionales.
const live = pool.getParticles().filter((p) => p.alive);
const colorACount = live.filter((p) => p.color === '#00bfff').length;
const colorBCount = live.filter((p) => p.color === '#9932cc').length;
assertCondition(colorACount > 0 && colorBCount > 0, 'ambos colores heráldicos conviven en la explosión');
assertCondition(
  Math.min(colorACount, colorBCount) >= Math.floor(emitted / 3),
  'la bicromía está equilibrada (estelas de ambos elementos)',
);
const outward = live.every((p) => Math.hypot(p.x - CENTER.x, p.y - CENTER.y) < 1 || (p.vx !== 0 || p.vy !== 0));
assertCondition(outward, 'todas las partículas nacen del blanco con velocidad radial');

// Determinismo: dos deflagraciones con la misma semilla son idénticas.
const poolA = new ParticlePool();
poolA.emitComboDetonation(CENTER, { colorA: '#a', colorB: '#b', random: createDeterministicRandom(7) });
const poolB = new ParticlePool();
poolB.emitComboDetonation(CENTER, { colorA: '#a', colorB: '#b', random: createDeterministicRandom(7) });
const snapshot = (p) => p.getParticles().slice(0, 20).map((x) => `${x.x.toFixed(3)},${x.y.toFixed(3)},${x.color}`);
assertCondition(
  JSON.stringify(snapshot(poolA)) === JSON.stringify(snapshot(poolB)),
  'mismas semilla y colores producen la misma deflagración (RNF-01)',
);

// Techo canónico: saturar el pool no revienta nada (RF-03.4 de SPEC-05).
const flood = new ParticlePool(MAX_PARTICLES);
let floodEmitted = 0;
for (let i = 0; i < 30; i++) {
  floodEmitted += flood.emitComboDetonation(CENTER, { colorA: '#1', colorB: '#2', random: createDeterministicRandom(i) });
}
assertCondition(
  flood.getActiveCount() <= MAX_PARTICLES,
  `el techo de ${MAX_PARTICLES} partículas se respeta bajo ráfaga (${flood.getActiveCount()})`,
);

// =====================================================================
// [1] Unicidad por reacción: los 8 pares del Códice son distintos
// =====================================================================
console.log('\n[1] Cada reacción pinta su propia deflagración');

const reactions = [
  { id: 'arcaneVaporization', colorA: '#ff4500', colorB: '#00bfff' },
  { id: 'fluidElectrocution', colorA: '#00bfff', colorB: '#9932cc' },
  { id: 'vortexDeflagration', colorA: '#ff4500', colorB: '#38d9a9' },
  { id: 'basalticFracture', colorA: '#8b4513', colorB: '#9932cc' },
  { id: 'petrifyingSwamp', colorA: '#8b4513', colorB: '#00bfff' },
  { id: 'glacialBlizzard', colorA: '#00bfff', colorB: '#ffd700' },
  { id: 'twilightCollapse', colorA: '#ffd700', colorB: '#4b0082' },
  { id: 'pureArcaneResonance', colorA: '#d4af37', colorB: '#ff4500' },
];
const signatures = new Set();
for (const reaction of reactions) {
  const signaturePool = new ParticlePool();
  signaturePool.emitComboDetonation(CENTER, {
    colorA: reaction.colorA,
    colorB: reaction.colorB,
    random: createDeterministicRandom(99),
  });
  const colors = signaturePool.getParticles().filter((p) => p.alive).map((p) => p.color);
  signatures.add([...new Set(colors)].sort().join('|'));
}
assertCondition(
  signatures.size === reactions.length,
  `los 8 pares heráldicos generan 8 firmas cromáticas distintas (hay ${signatures.size})`,
);

// =====================================================================
// [2] Texto Flotante Monumental (RF-06.2)
// =====================================================================
console.log('\n[2] Rótulo ceremonial en oro rúnico (RF-06.2, RNF-04)');

const floating = await import('../public/assets/js/components/floatingCombatTextComponent.js');
const { createFloatingCombatTextComponent, MONUMENTAL_SCALE, MONUMENTAL_FONT_PX } = floating;

let fakeMillis = 1_000_000;
const fakeClock = { now: () => fakeMillis, advance: (ms) => { fakeMillis += ms; } };
const operations = [];
const ctx = {
  save: () => operations.push(['save']),
  restore: () => operations.push(['restore']),
  fillText: (text, x, y) => operations.push(['fillText', text, x, y]),
  set font(value) { operations.push(['font', value]); },
  get font() { return ''; },
  set fillStyle(value) { operations.push(['fillStyle', value]); },
  get fillStyle() { return ''; },
  set globalAlpha(value) { operations.push(['globalAlpha', value]); },
  get globalAlpha() { return 1; },
  set textAlign(value) { operations.push(['textAlign', value]); },
  get textAlign() { return ''; },
};

const texts = createFloatingCombatTextComponent({ ctx, clock: fakeClock });
assertCondition(typeof texts.spawnMonumentalText === 'function', 'floatingCombatText expone spawnMonumentalText');
assertCondition(Math.abs(MONUMENTAL_SCALE - 1.3) < 0.001, `la escala monumental es ×1.3 (${MONUMENTAL_SCALE})`);
assertCondition(MONUMENTAL_FONT_PX === Math.round(18 * MONUMENTAL_SCALE), `el cuerpo monumental es 18 × 1.3 = 23 px (${MONUMENTAL_FONT_PX})`);

const scheduled = texts.spawnMonumentalText(
  { reactionName: 'Vaporización Arcana', damage: 90 },
  { x: 300, y: 200 },
);
assertCondition(scheduled === 1, 'la detonación monumental programa un único rótulo');

// Tras su retardo canónico (60 ms), el rótulo se dibuja: oro, 23 px,
// cúspide y formato.
fakeClock.advance(70);
texts.updateAndRender(0.016);
const draws = operations.filter((op) => op[0] === 'fillText');
assertCondition(draws.length === 1, `el rótulo se dibuja una vez (${draws.length})`);
const [drawOp] = draws;
const monumentalText = String(drawOp?.[1] ?? '');
assertCondition(
  monumentalText.includes('¡VAPORIZACIÓN ARCANA!') && monumentalText.includes('−90 PV'),
  `el rótulo exhibe nombre solemne y daño amplificado: «${monumentalText}»`,
);
const fontOp = operations.find((op) => op[0] === 'font' && String(op[1]).includes('23px'));
assertCondition(Boolean(fontOp), `el cuerpo tipográfico es monumental (23 px): ${fontOp?.[1]}`);
const ceremonialFont = operations.find((op) => op[0] === 'font' && /Cinzel|MedievalArcaneTitle|serif/i.test(String(op[1])));
assertCondition(Boolean(ceremonialFont), 'la tipografía es ceremonial (Cinzel/serif)');
const goldOp = operations.find((op) => op[0] === 'fillStyle' && /d4af37|f3cf58/i.test(String(op[1])));
assertCondition(Boolean(goldOp), `el color es oro rúnico: ${goldOp?.[1]}`);
const [ , , , drawnY ] = drawOp;
assertCondition(drawnY < 200 - 55, `el rótulo corona la cúspide del blanco (y=${drawnY} < 145)`);

// =====================================================================
// [3] Integración en la vista (deflagración + monumental al detonar)
// =====================================================================
console.log('\n[3] La vista detona combos con deflagración y rótulo');

// --- Dobles del navegador (compactos, fiel al arnés de la Tarea 4.1) ---
function createFakeContext2() {
  const ops = [];
  const noop = (name) => (...args) => ops.push([name, ...args]);
  const ctx = {
    ops,
    canvas: null, textAlign: 'left', textBaseline: 'alphabetic', lineWidth: 1,
    strokeStyle: '#000',
    _font: '',
    _fillStyle: '#000',
    save: noop('save'), restore: noop('restore'), beginPath: noop('beginPath'),
    // Propiedades grabadoras: el estilo monumental viaja por asignaciones
    // (font/fillStyle), no por métodos, y el arnés debe poder auditarlas.
    set font(v) { ops.push(['font', String(v)]); this._font = String(v); },
    get font() { return this._font; },
    set fillStyle(v) { ops.push(['fillStyle', String(v)]); this._fillStyle = String(v); },
    get fillStyle() { return this._fillStyle; },
    closePath: noop('closePath'), moveTo: noop('moveTo'), lineTo: noop('lineTo'),
    arc: noop('arc'), rect: noop('rect'), fill: noop('fill'), stroke: noop('stroke'),
    fillRect: noop('fillRect'), clearRect: noop('clearRect'), fillText: noop('fillText'),
    translate: noop('translate'), rotate: noop('rotate'), scale: noop('scale'),
    setLineDash: noop('setLineDash'), setTransform: noop('setTransform'),
    createRadialGradient: () => ({ addColorStop: noop('addColorStop') }),
    createLinearGradient: () => ({ addColorStop: noop('addColorStop') }),
    measureText: (t) => ({ width: String(t ?? '').length * 7 }),
  };
  // Devuelve el contexto grabador (la fábrica debe entregar la instancia)
  return ctx;
}
function createFakeElement(tagName, ownerDocument = null) {
  const element = {
    tagName: String(tagName).toUpperCase(), ownerDocument, children: [], attributes: {},
    classes: new Set(), listeners: {},
    style: { inline: {}, setProperty(n, v) { this.inline[n] = String(v); }, getProperty(n) { return this.inline[n] ?? null; } },
    _textContent: '', disabled: false, hidden: false, parentElement: null,
    setAttribute(n, v) { this.attributes[n] = String(v); if (n === 'class') this.classes = new Set(String(v).split(/\s+/).filter(Boolean)); },
    getAttribute(n) { return this.attributes[n] ?? null; },
    hasAttribute(n) { return n in this.attributes; },
    removeAttribute(n) { delete this.attributes[n]; },
    addEventListener(n, l) { (this.listeners[n] ??= []).push(l); },
    removeEventListener(n, l) { this.listeners[n] = (this.listeners[n] ?? []).filter((x) => x !== l); },
    dispatchEvent(e) {
      let node = this;
      while (node) { (node.listeners?.[e?.type] ?? []).forEach((l) => l(e)); if (e?.bubbles === false) break; node = node.parentElement ?? null; }
      return true;
    },
    dispatch(name, event = {}) { this.dispatchEvent({ type: name, bubbles: true, ...event }); },
    appendChild(c) { c.parentElement = this; this.children.push(c); return c; },
    replaceChildren(...nodes) { this.children = [...nodes]; nodes.forEach((n) => { n.parentElement = this; }); },
    remove() { if (this.parentElement) { this.parentElement.children = this.parentElement.children.filter((c) => c !== this); this.parentElement = null; } },
    querySelectorAll(sel) {
      const wanted = String(sel).split('.').filter(Boolean);
      const out = [];
      const walk = (node) => { for (const c of node.children ?? []) { if (wanted.every((w) => c.classes?.has(w))) out.push(c); walk(c); } };
      walk(this); return out;
    },
    querySelector(sel) { return this.querySelectorAll(sel)[0] ?? null; },
    set className(v) { this.classes = new Set(String(v).split(/\s+/).filter(Boolean)); },
    get className() { return [...this.classes].join(' '); },
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
    dispatch(n, e = {}) { (doc.listeners[n] ?? []).forEach((l) => l({ type: n, ...e })); },
  };
  return doc;
}

let millis = 1_700_000_000_000;
const clock = { now: () => millis, advance: (ms) => { millis += ms; } };
function createRaf() {
  let nextId = 1; let pendingMap = new Map(); let ts = 0;
  return {
    raf(cb) { const id = nextId++; pendingMap.set(id, cb); return id; },
    caf(id) { pendingMap.delete(id); },
    run(n, step = 16) { for (let i = 0; i < n; i++) { ts += step; clock.advance(step); const cur = [...pendingMap.values()]; pendingMap = new Map(); cur.forEach((cb) => cb(ts)); } },
  };
}
const storage = { map: new Map(), getItem(k) { return this.map.has(k) ? this.map.get(k) : null; }, setItem(k, v) { this.map.set(k, String(v)); }, removeItem(k) { this.map.delete(k); } };

const CATALOG = [
  { id: 'w', name: 'Ola Abisal', circle: 2, elementalAffinity: 'water', manaCost: 25, castingTime: 'action', areaType: 'singleTarget', rangeType: 'ranged', durationType: 'instant', magicSchool: 'evocation', hasVerbal: true, hasSomatic: true, hasMaterial: false, incantationFormula: 'a', description: 'd', effects: { damage: 40, healing: 0, barrier: 0, crowdControlType: 'none' } },
  { id: 'l', name: 'Flecha Fulgurante', circle: 3, elementalAffinity: 'lightning', manaCost: 35, castingTime: 'action', areaType: 'singleTarget', rangeType: 'ranged', durationType: 'instant', magicSchool: 'evocation', hasVerbal: true, hasSomatic: false, hasMaterial: false, incantationFormula: 'b', description: 'd', effects: { damage: 90, healing: 0, barrier: 0, crowdControlType: 'none' } },
];
function createClient() {
  return {
    async fetchSpells() {
      return { success: true, status: 200, data: { totalSpells: 2, currentPage: 1, totalPages: 2, hasPrevious: false, hasNext: true, spells: CATALOG } };
    },
  };
}
const speech = { reciteSpell: () => true, matchesSpellInvocation: () => true, startListening: () => false, stopAll: () => true, isSynthesisSupported: () => false, isRecognitionSupported: () => false };

const viewModule = await import('../public/assets/js/views/grimoireSimulatorView.js');
const { createGrimoireSimulatorView } = viewModule;

function buildScene({ motionMatches = false } = {}) {
  const doc = createFakeDoc();
  const sceneRaf = createRaf();
  const host = createFakeElement('main', doc);
  const canvas = createFakeElement('canvas', doc);
  const busEvents = [];
  const view = createGrimoireSimulatorView(host, {
    grimoireClient: createClient(),
    speechService: speech,
    elementFactory: (t) => createFakeElement(t, doc),
    document: doc,
    canvas,
    ctx: canvas.__ctx,
    raf: sceneRaf.raf,
    caf: sceneRaf.caf,
    clock,
    storage,
    motionQuery: { matches: motionMatches, addEventListener() {}, removeEventListener() {} },
  });
  for (const name of ['combo:reaction-triggered']) {
    host.addEventListener(name, (e) => busEvents.push({ name, detail: e?.detail ?? null }));
  }
  return { view, host, doc, raf: sceneRaf, canvas, busEvents };
}

const flyToImpact = (scene) => scene.raf.run(30, 16);

// Detonación completa: Agua (pág. 1) → Rayo (pág. 2).
const comboScene = buildScene();
await comboScene.view.render();
await comboScene.view.castCurrentSpell({ triggerMethod: 'click' });
flyToImpact(comboScene);
const particlesAfterWater = comboScene.view.getState().activeParticles;
await comboScene.view.nextPage();
await comboScene.view.castCurrentSpell({ triggerMethod: 'click' });
flyToImpact(comboScene);
comboScene.raf.run(8, 16); // el monumental nace 60 ms tras el impacto: deja que flote

assertCondition(comboScene.busEvents.length === 1, 'la detonación emite combo:reaction-triggered');
const reaction = comboScene.busEvents[0]?.detail;
assertCondition(reaction?.reactionId === 'fluidElectrocution', 'la reacción es Electrocución Fluida');
const particlesAfterCombo = comboScene.view.getState().activeParticles;
assertCondition(
  particlesAfterCombo > particlesAfterWater,
  `la deflagración fusiona partículas nuevas en el lienzo (${particlesAfterWater} → ${particlesAfterCombo})`,
);
assertCondition(particlesAfterCombo <= 200, `el techo canónico se respeta (${particlesAfterCombo} ≤ 200)`);

// El rótulo monumental flota sobre el lienzo: oro ceremonial a 23 px.
const drawOps = comboScene.canvas.__ctx.ops.filter((op) => op[0] === 'fillText');
const monumentalDraw = drawOps.find((op) => String(op[1]).includes('¡ELECTROCUCIÓN FLUIDA!'));
assertCondition(Boolean(monumentalDraw), `el rótulo monumental del combo flota en el lienzo: «${monumentalDraw?.[1]}»`);
const monumentalFont = comboScene.canvas.__ctx.ops.find((op) => op[0] === 'font' && String(op[1]).includes('23px'));
assertCondition(Boolean(monumentalFont), 'el rótulo usa el cuerpo monumental de 23 px');
const monumentalGold = comboScene.canvas.__ctx.ops.some((op) => op[0] === 'fillStyle' && /d4af37|f3cf58/i.test(String(op[1])));
assertCondition(monumentalGold, 'el rótulo se pinta en oro rúnico');

// Un impacto sin reacción jamás detona monumentalidad: nuevo banco limpio.
const plainScene = buildScene();
await plainScene.view.render();
await plainScene.view.castCurrentSpell({ triggerMethod: 'click' });
flyToImpact(plainScene);
const plainMonumental = plainScene.canvas.__ctx.ops.filter((op) => op[0] === 'fillText' && /¡.*!/.test(String(op[1])));
assertCondition(plainMonumental.length === 0, 'un impacto sin combo no genera rótulo monumental alguno');

// Movimiento reducido: partículas de combo suprimidas, rótulo intacto.
const calmScene = buildScene({ motionMatches: true });
await calmScene.view.render();
await calmScene.view.castCurrentSpell({ triggerMethod: 'click' });
flyToImpact(calmScene);
await calmScene.view.nextPage();
const calmBefore = calmScene.view.getState().activeParticles;
await calmScene.view.castCurrentSpell({ triggerMethod: 'click' });
flyToImpact(calmScene);
const calmReaction = calmScene.busEvents.length === 1;
const calmMonumental = calmScene.canvas.__ctx.ops.filter((op) => op[0] === 'fillText' && /¡ELECTROCUCIÓN FLUIDA!/.test(String(op[1])));
assertCondition(calmReaction, 'con movimiento reducido la reacción se detona igualmente');
assertCondition(
  calmScene.view.getState().activeParticles === calmBefore,
  'con movimiento reducido la deflagración de partículas se suprime (RF-06.1 de SPEC-05)',
);
assertCondition(calmMonumental.length >= 1, 'con movimiento reducido el rótulo monumental se mantiene (degradación grácil)');

// =====================================================================
// Resumen
// =====================================================================
console.log(`\n== RESUMEN == Asertos superados: ${assertsPassed}, fallidos: ${assertsFailed}`);
if (assertsFailed === 0) {
  console.log('RESULTADO: EXITO — La detonación deflagra en bicromía y corona el rótulo monumental (Tarea 4.2).');
  process.exit(0);
}
console.log('RESULTADO: DENEGADO — Revisa los asertos marcados.');
process.exit(1);
