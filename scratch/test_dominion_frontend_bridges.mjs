/**
 * test_dominion_frontend_bridges.mjs — Arnés de la Tarea 7.1 (TASKS-07).
 *
 * Verifica los TRES puentes del frontend que la integración cruzada exige,
 * más el contrato del cliente que los alimenta:
 *
 *   [1] `dominionClient.awardSimulatorCombo()` (Endpoint 14, RF-03.2): ruta,
 *       método, cuerpo, credencial Bearer opcional y envoltura pacífica.
 *   [2] La Cámara de Conjuración acredita la gloria SOLO cuando una reacción
 *       elemental se detona de verdad (nunca al imbuir un aura), declarando el
 *       elemento del conjuro entrante, y narra el recibo que el santuario
 *       devuelva —incluido el techo diario colmado— sin romper jamás la
 *       conjuración si la red cae.
 *   [3] El Salón de los Linajes se refresca por EVENTO (`dominion:points-awarded`,
 *       plan 4.1) y repinta el podio semanal en vivo sin sondear el reloj
 *       (RNF-02).
 *   [4] El Tomo ciñe el ribete ceremonial dorado (`.spell-card-regent-border`)
 *       a los conjuros del Clan Regente (RF-04.4), incluso si el Salón revela
 *       al soberano después de pintar el catálogo; sin cliente del Dominio el
 *       Tomo queda exactamente como estaba.
 *
 * Constitución:
 *   - Artículo I (Dogma Vanilla): ES Modules nativos; dobles inyectables; cero
 *     dependencias npm y cero frameworks de pruebas.
 *   - Artículo II: la vista JAMÁS decide el monto ni el techo diario; solo
 *     cursa la orden y pinta lo que el santuario responde.
 *   - Artículo V: identificadores en inglés camelCase; comentarios en castellano.
 *
 * Uso: node scratch/test_dominion_frontend_bridges.mjs
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

const flushMicrotasks = () => new Promise((resolve) => setTimeout(resolve, 0));

// =====================================================================
// Dobles del navegador (Dogma Vanilla: ni jsdom ni frameworks)
// =====================================================================

/** Elemento DOM simulado con burbujeo, clases, atributos y controles. */
function createFakeElement(tagName, ownerDocument = null) {
  const element = {
    tagName: String(tagName).toUpperCase(),
    ownerDocument,
    children: [],
    attributes: {},
    classes: new Set(),
    listeners: {},
    style: {
      inline: {},
      setProperty(name, value) { this.inline[name] = String(value); },
      getProperty(name) { return this.inline[name] ?? null; },
    },
    _textContent: '',
    _value: '',
    _checked: false,
    disabled: false,
    hidden: false,
    parentElement: null,
    focusCount: 0,
    setAttribute(name, value) {
      this.attributes[name] = String(value);
      if (name === 'class') this.classes = new Set(String(value).split(/\s+/).filter(Boolean));
    },
    getAttribute(name) { return name in this.attributes ? this.attributes[name] : null; },
    removeAttribute(name) { delete this.attributes[name]; },
    hasAttribute(name) { return name in this.attributes; },
    addEventListener(name, listener) { (this.listeners[name] ??= []).push(listener); },
    removeEventListener(name, listener) {
      this.listeners[name] = (this.listeners[name] ?? []).filter((item) => item !== listener);
    },
    dispatchEvent(event) {
      let node = this;
      while (node) {
        for (const listener of node.listeners?.[event?.type] ?? []) listener(event);
        if (event?.bubbles === false) break;
        node = node.parentElement ?? null;
      }
      return true;
    },
    dispatch(name, event = {}) { this.dispatchEvent({ type: name, bubbles: true, ...event }); },
    appendChild(child) { child.parentElement = this; this.children.push(child); return child; },
    replaceChildren(...nodes) {
      for (const child of this.children) child.parentElement = null;
      this.children = [];
      for (const node of nodes) this.appendChild(node);
    },
    remove() {
      if (!this.parentElement) return;
      this.parentElement.children = this.parentElement.children.filter((child) => child !== this);
      this.parentElement = null;
    },
    querySelectorAll(selector) {
      const wanted = String(selector).replace(/^\./, '').split('.').filter(Boolean);
      const found = [];
      const walk = (node) => {
        for (const child of node.children ?? []) {
          if (wanted.every((name) => child.classes?.has(name))) found.push(child);
          walk(child);
        }
      };
      walk(this);
      return found;
    },
    querySelector(selector) { return this.querySelectorAll(selector)[0] ?? null; },
    set className(value) { this.classes = new Set(String(value).split(/\s+/).filter(Boolean)); },
    get className() { return [...this.classes].join(' '); },
    get textContent() {
      return this.children.length ? this.children.map((child) => child.textContent).join('') : this._textContent;
    },
    set textContent(value) { this._textContent = String(value); this.children = []; },
    get innerHTML() { throw new Error('PROHIBIDO innerHTML (AGENTS.md 6.1: XSS); usar textContent'); },
    set innerHTML(value) { throw new Error(`PROHIBIDO innerHTML (AGENTS.md 6.1): '${String(value).slice(0, 30)}'`); },
    get value() { return this._value; },
    set value(next) { this._value = String(next); },
    get checked() { return this._checked; },
    set checked(next) { this._checked = Boolean(next); },
    focus() { this.focusCount++; },
  };

  // El lienzo de la Cámara de Conjuración necesita medidas reales: sin ellas
  // la trayectoria del proyectil no tiene origen ni blanco.
  if (element.tagName === 'CANVAS') {
    element.width = 200;
    element.height = 100;
    element.clientWidth = 200;
    element.clientHeight = 100;
    const context = createFakeContext();
    context.canvas = element;
    element.getContext = () => context;
    element.__ctx = context;
  }

  element.classList = {
    _owner: element,
    add(...names) { names.forEach((name) => this._owner.classes.add(name)); },
    remove(...names) { names.forEach((name) => this._owner.classes.delete(name)); },
    contains(name) { return this._owner.classes.has(name); },
    toggle(name, force) {
      const has = this._owner.classes.has(name);
      const want = force === undefined ? !has : Boolean(force);
      if (want) this._owner.classes.add(name); else this._owner.classes.delete(name);
      return want;
    },
  };

  return element;
}

/** Documento simulado con los métodos que las vistas invocan. */
function createFakeDocument() {
  const doc = {
    hidden: false,
    listeners: {},
    createElement(tagName) { return createFakeElement(tagName, doc); },
    createElementNS(_namespace, tagName) { return createFakeElement(tagName, doc); },
    addEventListener(name, listener) { (doc.listeners[name] ??= []).push(listener); },
    removeEventListener(name, listener) {
      doc.listeners[name] = (doc.listeners[name] ?? []).filter((item) => item !== listener);
    },
    dispatch(name, event = {}) { (doc.listeners[name] ?? []).forEach((listener) => listener({ type: name, ...event })); },
  };

  return doc;
}

/** Reloj monótono gobernado por el arnés. */
function createFakeClock() {
  let millis = 1_700_000_000_000;
  return { now: () => millis, advance: (ms) => { millis += ms; } };
}

/** Planificador de cuadros determinista. */
function createFakeRaf(clock) {
  let nextId = 1;
  let pending = new Map();
  let timestamp = 0;
  return {
    raf(callback) { const id = nextId++; pending.set(id, callback); return id; },
    caf(id) { pending.delete(id); },
    run(frames, step = 16) {
      for (let frame = 0; frame < frames; frame++) {
        timestamp += step;
        clock.advance(step);
        const current = [...pending.values()];
        pending = new Map();
        current.forEach((callback) => callback(timestamp));
      }
    },
  };
}

/** Almacén local simulado para la bitácora del banco de pruebas. */
function createFakeStorage() {
  return {
    map: new Map(),
    getItem(key) { return this.map.has(key) ? this.map.get(key) : null; },
    setItem(key, value) { this.map.set(key, String(value)); },
    removeItem(key) { this.map.delete(key); },
  };
}

/** Contexto 2D simulado: graba cada trazado sin dibujar. */
function createFakeContext() {
  const operations = [];
  const noop = (name) => (...args) => operations.push([name, ...args]);
  const context = {
    operations,
    canvas: null,
    textAlign: 'left',
    textBaseline: 'alphabetic',
    lineWidth: 1,
    _font: '',
    _fillStyle: '#000',
    _strokeStyle: '#000',
    save: noop('save'),
    restore: noop('restore'),
    beginPath: noop('beginPath'),
    closePath: noop('closePath'),
    moveTo: noop('moveTo'),
    lineTo: noop('lineTo'),
    arc: noop('arc'),
    rect: noop('rect'),
    fill: noop('fill'),
    stroke: noop('stroke'),
    fillRect: noop('fillRect'),
    clearRect: noop('clearRect'),
    strokeRect: noop('strokeRect'),
    fillText: noop('fillText'),
    strokeText: noop('strokeText'),
    translate: noop('translate'),
    rotate: noop('rotate'),
    scale: noop('scale'),
    setLineDash: noop('setLineDash'),
    setTransform: noop('setTransform'),
    createRadialGradient: () => ({ addColorStop: noop('addColorStop') }),
    createLinearGradient: () => ({ addColorStop: noop('addColorStop') }),
    measureText: (text) => ({ width: String(text ?? '').length * 7 }),
  };
  Object.defineProperty(context, 'font', {
    get() { return this._font; },
    set(value) { operations.push(['font', String(value)]); this._font = String(value); },
  });
  Object.defineProperty(context, 'fillStyle', {
    get() { return this._fillStyle; },
    set(value) { operations.push(['fillStyle', String(value)]); this._fillStyle = String(value); },
  });
  Object.defineProperty(context, 'strokeStyle', {
    get() { return this._strokeStyle; },
    set(value) { this._strokeStyle = String(value); },
  });
  return context;
}

// =====================================================================
// [1] El cliente del Dominio: Endpoint 14
// =====================================================================
console.log('\n[1] dominionClient.awardSimulatorCombo() (Endpoint 14, RF-03.2)');

const clientModule = await import('../public/assets/js/api/dominionClient.js');
const { createDominionClient } = clientModule;

/** Doble de fetch: graba cada petición y responde por el camino exacto. */
function createFetchStub(handler) {
  const calls = [];
  return {
    calls,
    fail: false,
    async fetch(url, options = {}) {
      calls.push({ url: String(url), method: options.method ?? 'GET', headers: options.headers ?? {}, body: options.body ?? null, credentials: options.credentials });
      if (this.fail) throw new TypeError('la corriente de maná se ha interrumpido');
      return handler(String(url), options);
    },
  };
}

/** Respuesta JSON mínima del santuario. */
function jsonResponse(payload, status = 200) {
  return { status, async json() { return payload; } };
}

const awardEnvelope = {
  success: true,
  data: {
    clanId: 'cln_ignis',
    comboElement: 'fire',
    dailyCap: 50,
    award: { actionType: 'simulatorCombo', basePoints: 10, awardedPoints: 13, hasSynergy: true, synergyBonus: 3, awardedAt: '2026-09-14T12:00:00Z', dailyQuotaRemaining: 37, reason: null },
  },
};

const awardStub = createFetchStub(async () => jsonResponse(awardEnvelope));
const awardingClient = createDominionClient({ fetch: awardStub.fetch.bind(awardStub) });

const awardResult = await awardingClient.awardSimulatorCombo('  fire  ');
const awardCall = awardStub.calls[0] ?? {};
assertCondition(
  awardCall.url === '/api/v1/dominion/simulator-combo',
  `el cliente cursa el Endpoint 14 (${awardCall.url})`,
);
assertCondition(awardCall.method === 'POST', '…por el método canónico POST');
assertCondition(
  String(awardCall.headers['Content-Type'] ?? '').startsWith('application/json'),
  '…declarando el cuerpo JSON',
);
assertCondition(
  awardCall.body === JSON.stringify({ comboElement: 'fire' }),
  `…con el elemento declarado y saneado (${awardCall.body})`,
);
assertCondition(awardCall.credentials === 'same-origin', '…y la cookie HttpOnly de sesión viajando sola');
assertCondition(
  awardCall.headers.Authorization === undefined,
  'Sin token inyectado no se inventa credencial Bearer alguna',
);
assertCondition(awardResult.success === true && awardResult.data.award.awardedPoints === 13, 'El recibo del santuario se propaga íntegro');

const silentStub = createFetchStub(async () => jsonResponse(awardEnvelope));
const silentClient = createDominionClient({ fetch: silentStub.fetch.bind(silentStub) });
await silentClient.awardSimulatorCombo();
assertCondition(
  silentStub.calls[0].body === JSON.stringify({ comboElement: '' }),
  'Sin elemento declarado el cuerpo viaja con la cadena vacía (jamás undefined)',
);

const bearerStub = createFetchStub(async () => jsonResponse(awardEnvelope));
const bearerClient = createDominionClient({ fetch: bearerStub.fetch.bind(bearerStub), token: 'sello-de-custodio' });
await bearerClient.awardSimulatorCombo('water');
assertCondition(
  bearerStub.calls[0].headers.Authorization === 'Bearer sello-de-custodio',
  'Con token inyectado la credencial Bearer viaja como cabecera',
);

const homelessStub = createFetchStub(async () => jsonResponse({
  success: false,
  error: { code: 'NO_CLAN_AFFILIATION', message: 'El mago no milita en hermandad alguna.', recoveryAction: 'JOIN_OR_FOUND_CLAN' },
}, 409));
const homelessClient = createDominionClient({ fetch: homelessStub.fetch.bind(homelessStub) });
const homeless = await homelessClient.awardSimulatorCombo('fire');
assertCondition(
  homeless.success === false && homeless.status === 409 && homeless.error.code === 'NO_CLAN_AFFILIATION',
  'El rechazo de un mago sin hermandad se propaga con su código canónico',
);

const offlineStub = createFetchStub(async () => jsonResponse(awardEnvelope));
offlineStub.fail = true;
const offlineClient = createDominionClient({ fetch: offlineStub.fetch.bind(offlineStub) });
const offline = await offlineClient.awardSimulatorCombo('fire');
assertCondition(
  offline.success === false && offline.status === 0,
  'La red caída jamás lanza: el cliente devuelve su sobre controlado',
);

// =====================================================================
// [2] La Cámara de Conjuración acredita la gloria de la reacción
// =====================================================================
console.log('\n[2] El combo detonado acredita PDA al clan del adepto (RF-03.2)');

const { createGrimoireSimulatorView } = await import('../public/assets/js/views/grimoireSimulatorView.js');

/** Catálogo mínimo: Agua en la página 1, Rayo en la 2 (Electrocución Fluida). */
const SIMULATOR_CATALOG = [
  { id: 'w', name: 'Ola Abisal', circle: 2, elementalAffinity: 'water', manaCost: 25, castingTime: 'action', areaType: 'singleTarget', rangeType: 'ranged', durationType: 'instant', magicSchool: 'evocation', hasVerbal: true, hasSomatic: true, hasMaterial: false, incantationFormula: 'a', description: 'd', effects: { damage: 40, healing: 0, barrier: 0, crowdControlType: 'none' } },
  { id: 'l', name: 'Flecha Fulgurante', circle: 3, elementalAffinity: 'lightning', manaCost: 35, castingTime: 'action', areaType: 'singleTarget', rangeType: 'ranged', durationType: 'instant', magicSchool: 'evocation', hasVerbal: true, hasSomatic: false, hasMaterial: false, incantationFormula: 'b', description: 'd', effects: { damage: 90, healing: 0, barrier: 0, crowdControlType: 'none' } },
];

const simulatorSpeech = {
  reciteSpell: () => true,
  matchesSpellInvocation: () => true,
  startListening: () => false,
  stopAll: () => true,
  isSynthesisSupported: () => false,
  isRecognitionSupported: () => false,
};

/**
 * Monta la Cámara de Conjuración con dobles del navegador.
 * @param {Object} [overrides] `awardHandler` gobierna el recibo del santuario.
 */
function buildSimulator(overrides = {}) {
  const doc = createFakeDocument();
  const clock = createFakeClock();
  const raf = createFakeRaf(clock);
  const host = createFakeElement('main', doc);
  const canvas = createFakeElement('canvas', doc);
  const context = canvas.__ctx;

  const practices = [];
  const view = createGrimoireSimulatorView(host, {
    grimoireClient: {
      async fetchSpells() {
        return { success: true, status: 200, data: { totalSpells: 2, currentPage: 1, totalPages: 2, hasPrevious: false, hasNext: true, spells: SIMULATOR_CATALOG } };
      },
    },
    speechService: simulatorSpeech,
    elementFactory: (tagName) => createFakeElement(tagName, doc),
    document: doc,
    canvas,
    ctx: context,
    raf: raf.raf,
    caf: raf.caf,
    clock,
    storage: createFakeStorage(),
    motionQuery: { matches: false, addEventListener() {}, removeEventListener() {} },
    ...(overrides.awardHandler === undefined ? {} : {
      awardSimulatorPractice: async (comboElement) => {
        practices.push(comboElement);
        return overrides.awardHandler(comboElement);
      },
    }),
  });

  return { view, host, doc, raf, clock, canvas, context, practices };
}

/** Detona el combo Agua → Rayo (Electrocución Fluida). */
async function detonateFluidElectrocution(scene) {
  await scene.view.castCurrentSpell({ triggerMethod: 'click' });
  scene.raf.run(30, 16);      // el proyectil vuela y siembra el aura de Agua
  await scene.view.nextPage(); // el Tomo alcanza la página del Rayo
  await scene.view.castCurrentSpell({ triggerMethod: 'click' });
  scene.raf.run(30, 16);      // el Rayo detona sobre el aura
  await flushMicrotasks();
}

const gloryReceipt = {
  success: true,
  status: 200,
  data: { clanId: 'cln_ignis', comboElement: 'lightning', dailyCap: 50, award: { actionType: 'simulatorCombo', basePoints: 10, awardedPoints: 10, hasSynergy: false, synergyBonus: 0, awardedAt: '2026-09-14T12:00:00Z', dailyQuotaRemaining: 40, reason: null } },
};

const creditScene = buildSimulator({ awardHandler: () => gloryReceipt });
await creditScene.view.render();

// Un impacto que solo imbúe el aura NO devenga gloria: no hubo reacción.
await creditScene.view.castCurrentSpell({ triggerMethod: 'click' });
creditScene.raf.run(30, 16);
await flushMicrotasks();
assertCondition(creditScene.practices.length === 0, 'Imbuir un aura (sin reacción) no acredita gloria alguna');

await creditScene.view.nextPage();
await creditScene.view.castCurrentSpell({ triggerMethod: 'click' });
creditScene.raf.run(30, 16);
await flushMicrotasks();
assertCondition(creditScene.practices.length === 1, 'La reacción detonada cursa UNA orden de gloria');
assertCondition(
  creditScene.practices[0] === 'lightning',
  `…declarando el elemento del CONJURO ENTRANTE, no el del aura prestada (${creditScene.practices[0]})`,
);
assertCondition(
  creditScene.view.getAnnouncements().some((text) => text.includes('10 PDA') && text.includes('40')),
  'La Cámara narra el recibo del santuario con la gloria y el cupo restante',
);

// Techo diario colmado: cero gloria con su motivo canónico, sin romper nada.
const cappedScene = buildSimulator({
  awardHandler: () => ({
    success: true,
    status: 200,
    data: { clanId: 'cln_ignis', comboElement: 'lightning', dailyCap: 50, award: { actionType: 'simulatorCombo', basePoints: 0, awardedPoints: 0, hasSynergy: false, synergyBonus: 0, awardedAt: '2026-09-14T12:00:00Z', dailyQuotaRemaining: 0, reason: 'DAILY_SIMULATOR_CAP_REACHED' } },
  }),
});
await cappedScene.view.render();
await detonateFluidElectrocution(cappedScene);
assertCondition(
  cappedScene.view.getAnnouncements().some((text) => text.includes('techo diario') && text.includes('50')),
  'Con el techo colmado la Cámara lo canta como jornada agotada, jamás como error',
);
assertCondition(
  cappedScene.view.getState().dummy.health < 500,
  '…y la conjuración sigue surtiendo efecto sobre el maniquí',
);

// Frontera: un recibo fallido (sin hermandad) no narra gloria alguna.
const refusedScene = buildSimulator({
  awardHandler: () => ({ success: false, status: 409, error: { code: 'NO_CLAN_AFFILIATION' } }),
});
await refusedScene.view.render();
await detonateFluidElectrocution(refusedScene);
assertCondition(
  refusedScene.view.getAnnouncements().every((text) => !text.includes('PDA')),
  'El rechazo del santuario no inventa gloria alguna en la narración',
);

// Degradación elegante: la red cae y la conjuración no se detiene.
const brokenScene = buildSimulator({ awardHandler: () => { throw new TypeError('corte de maná'); } });
await brokenScene.view.render();
await detonateFluidElectrocution(brokenScene);
assertCondition(
  brokenScene.view.getState().dummy.health < 500,
  'Con la acreditación caída el combo se sigue resolviendo sobre el maniquí',
);

// Sin contrato inyectado la Cámara funciona exactamente igual (SPEC-05 intacta).
const bareScene = buildSimulator();
await bareScene.view.render();
await detonateFluidElectrocution(bareScene);
assertCondition(
  bareScene.view.getState().dummy.health < 500 && bareScene.practices.length === 0,
  'Sin contrato de gloria la Cámara de Conjuración queda intacta',
);

// La vista desmontada no narra una gloria que llegó tarde.
let releaseLateReceipt = null;
const lateScene = buildSimulator({
  awardHandler: () => new Promise((resolve) => { releaseLateReceipt = () => resolve(gloryReceipt); }),
});
await lateScene.view.render();
await detonateFluidElectrocution(lateScene);
lateScene.view.destroy();
releaseLateReceipt?.();
await flushMicrotasks();
assertCondition(
  lateScene.view.getAnnouncements().every((text) => !text.includes('PDA')),
  'Una respuesta tardía jamás narra gloria sobre una vista desmontada',
);

// =====================================================================
// [3] El Salón de los Linajes se refresca por evento (plan 4.1)
// =====================================================================
console.log('\n[3] El podio semanal en vivo se refresca al acreditarse gloria');

const { createLineageHallView } = await import('../public/assets/js/views/lineageHallView.js');

const hallClan = (overrides = {}) => ({
  id: 'cln_ignis',
  slug: 'custodios-de-la-llama',
  name: 'Custodios de la Llama',
  motto: 'En la ceniza renace la llama inmortal',
  coatOfArms: 'rune-ignis',
  lineageType: 'primordialFlame',
  admissionMode: 'byApplication',
  status: 'active',
  patriarchId: 'usr_fundador',
  weeklyPoints: 170,
  historicalPoints: 620,
  memberCount: 12,
  memberLimit: 30,
  lastActivityAt: '2026-09-10T10:00:00Z',
  createdAt: '2026-06-01T00:00:00Z',
  updatedAt: '2026-09-10T10:00:00Z',
  ...overrides,
});

/** Cliente del Salón cuyo podio semanal crece con cada consulta. */
function createHallClient() {
  const calls = { leaderboard: 0, lineages: 0 };
  return {
    calls,
    async fetchLineages() {
      calls.lineages++;
      return { success: true, status: 200, data: [{ id: 'primordialFlame', name: 'Linaje de la Llama Primordial', rulingElement: 'fire', glyph: 'rune-ignis', bannerColor: '#ff4500', heraldicFrame: 'phoenixShield', description: 'Custodios de la chispa.' }] };
    },
    async fetchLeaderboard() {
      calls.leaderboard++;
      const points = calls.leaderboard === 1 ? 170 : 183;
      const podium = hallClan({ weeklyPoints: points });
      return {
        success: true,
        status: 200,
        data: { weeklyRanking: [podium], historicalRanking: [podium], currentRegentClan: podium, hallOfFameWeeks: [] },
      };
    },
  };
}

const hallMount = createFakeElement('main');
const hallBus = createFakeElement('main');
const hallClient = createHallClient();
const hallView = createLineageHallView(hallMount, {
  dominionClient: hallClient,
  eventTarget: hallBus,
  elementFactory: (tagName) => createFakeElement(tagName),
});

await hallView.render();
assertCondition(hallClient.calls.leaderboard === 1, 'El Salón consulta el podio al montarse');
assertCondition(hallMount.textContent.includes('170'), '…y pinta el marcador vigente del soberano');

hallBus.dispatch('dominion:points-awarded', { detail: { clanId: 'cln_ignis', awardedPoints: 13 } });
await flushMicrotasks();
await flushMicrotasks();
assertCondition(hallClient.calls.leaderboard === 2, 'El evento `dominion:points-awarded` relanza la consulta del Salón');
assertCondition(
  hallMount.textContent.includes('183') && !hallMount.textContent.includes('170'),
  '…y el podio en vivo repinta el nuevo marcador sin recargar la página',
);

hallView.destroy();
hallBus.dispatch('dominion:points-awarded', { detail: { clanId: 'cln_ignis', awardedPoints: 10 } });
await flushMicrotasks();
assertCondition(hallClient.calls.leaderboard === 2, 'Un Salón desmontado deja de escuchar el bus (baja limpia)');

// =====================================================================
// [4] El ribete dorado del Clan Regente en el Tomo (RF-04.4)
// =====================================================================
console.log('\n[4] El Tomo ciñe el ribete dorado a los conjuros del Clan Regente');

const { REGENT_RIBBON_CLASS } = await import('../public/assets/js/components/spellCardComponent.js');
const { createStore } = await import('../public/assets/js/state/store.js');
const { createLibraryView } = await import('../public/assets/js/views/libraryView.js');

const TOME_SPELLS = [
  { id: '1', slug: 'llamas-del-soberano', name: 'Llamas del Soberano', magicSchool: 'evocation', magicSchoolLabel: 'Evocación', manaCost: 45, clanId: 'cln_ignis', clanName: 'Custodios de la Llama', summary: 'Ráfaga de fuego purificador.', status: 'validated', isGenesisSample: false },
  { id: '2', slug: 'manto-ajeno', name: 'Manto Ajeno', magicSchool: 'abjuration', magicSchoolLabel: 'Abjuración', manaCost: 30, clanId: 'cln_tide', clanName: 'Mareas de Aether', summary: 'Barrera de maná denso.', status: 'validated', isGenesisSample: false },
];

/** Cliente del Tomo: responde el catálogo canónico. */
const createTomeClient = () => ({
  async fetchSpells() {
    return { success: true, status: 200, data: { items: TOME_SPELLS, hasMore: false, offset: 0, limit: 50 } };
  },
});

/**
 * Monta el Tomo.
 * @param {Object|null} dominionClient Cliente del Dominio (o null).
 */
async function buildTome(dominionClient, extra = {}) {
  const doc = createFakeDocument();
  const mount = createFakeElement('main', doc);
  const view = createLibraryView(mount, {
    store: createStore(),
    spellClient: createTomeClient(),
    onSpellSelect: () => {},
    dominionClient,
    elementFactory: (tagName) => createFakeElement(tagName, doc),
    ...extra,
  });
  await view.render();
  await flushMicrotasks();
  return { view, mount };
}

/** Tarjeta del Tomo por su slug. */
const cardOf = (mount, slug) => mount.querySelectorAll('.spell-card').find((card) => card.getAttribute('data-slug') === slug) ?? null;

const regentClient = {
  async fetchLeaderboard() {
    return { success: true, status: 200, data: { currentRegentClan: hallClan({ id: 'cln_ignis' }), weeklyRanking: [], historicalRanking: [], hallOfFameWeeks: [] } };
  },
};

const regentTome = await buildTome(regentClient);
const sovereignCard = cardOf(regentTome.mount, 'llamas-del-soberano');
const foreignCard = cardOf(regentTome.mount, 'manto-ajeno');
assertCondition(
  sovereignCard?.classes.has(REGENT_RIBBON_CLASS) === true && sovereignCard.getAttribute('data-regent') === 'true',
  'El conjuro del Clan Regente luce el ribete ceremonial dorado (RF-04.4)',
);
assertCondition(
  foreignCard?.classes.has(REGENT_RIBBON_CLASS) === false && foreignCard.getAttribute('data-regent') === null,
  'Un conjuro de casa ajena conserva su tarjeta canónica sin ribete',
);
assertCondition(
  sovereignCard?.classes.has('spell-card') === true,
  '…y el ribete se COMPONE sobre la tarjeta canónica del Tomo, jamás la sustituye',
);

// El Salón puede revelar al soberano DESPUÉS de pintar el catálogo.
const lateRegentClient = {
  async fetchLeaderboard() {
    await new Promise((resolve) => setTimeout(resolve, 20));
    return { success: true, status: 200, data: { currentRegentClan: hallClan({ id: 'cln_ignis' }) } };
  },
};
const lateTome = await buildTome(lateRegentClient);
// El catálogo se pintó ANTES de que el Salón respondiera: se espera su llegada.
await new Promise((resolve) => setTimeout(resolve, 40));
const lateCard = cardOf(lateTome.mount, 'llamas-del-soberano');
assertCondition(
  lateCard?.classes.has(REGENT_RIBBON_CLASS) === true && lateCard.getAttribute('data-regent') === 'true',
  'Si el soberano se revela tarde, el ribete se ciñe a las tarjetas ya pintadas (sin repintar el Tomo)',
);

// Sin cliente del Dominio, el Tomo de SPEC-01 queda exactamente igual.
const bareTome = await buildTome(null);
assertCondition(
  cardOf(bareTome.mount, 'llamas-del-soberano')?.classes.has(REGENT_RIBBON_CLASS) === false,
  'Sin cliente del Dominio el Tomo se sirve sin ribete alguno (SPEC-01 intacta)',
);

// Un Salón caído jamás rompe el Tomo.
const failingRegentClient = {
  async fetchLeaderboard() {
    return { success: false, status: 500, error: { code: 'MANA_STREAM_INTERRUPTED' } };
  },
};
const failedTome = await buildTome(failingRegentClient);
assertCondition(
  cardOf(failedTome.mount, 'llamas-del-soberano') !== null
    && cardOf(failedTome.mount, 'llamas-del-soberano').classes.has(REGENT_RIBBON_CLASS) === false,
  'Con el Salón caído el Tomo se contempla igual, solo que sin ribete (degradación elegante)',
);

// =====================================================================
// Resumen
// =====================================================================
console.log(`\n== RESUMEN == Asertos superados: ${assertsPassed}, fallidos: ${assertsFailed}`);
if (assertsFailed === 0) {
  console.log("RESULTADO: EXITO — Los puentes del Dominio cumplen la Tarea 7.1.");
  process.exit(0);
}
console.log('RESULTADO: FALLO — Revisa los asertos marcados.');
process.exit(1);
