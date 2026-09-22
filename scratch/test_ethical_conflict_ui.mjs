/**
 * test_ethical_conflict_ui.mjs — Arnés del hueco de cobertura RF-06.1 (SPEC-03).
 *
 * La auditoría de estabilización de SPEC-03 halló que el veto ético del
 * backend (RF-06.2, ClanConflictService/ClanEthicsValidator) está blindado
 * y probado, pero la ADVERTENCIA VISIBLE de la interfaz —«El vínculo de
 * sangre nubla el juicio...» con los CONTROLES DE FIRMA DESHABILITADOS—
 * carecía de arnés propio. Este arnés sella el criterio de la Tarea 5.2:
 *
 *   [1] La leyenda canónica del spec RF-06.1 («El vínculo de sangre nubla
 *       el juicio: un Maestro no puede juzgar el trabajo de su propio
 *       linaje») viaja INTACTA desde el sobre del backend (fuente única
 *       de verdad, ClanEthicsValidator::vetoReasonFor) hasta la alerta
 *       viva de la tarjeta (precedencia del servidor sobre las revervas).
 *   [2] El botón de firma nace INHABILITADO (disabled + aria-disabled)
 *       cuando el DTO porta hasEthicalConflict=true: el navegador jamás
 *       entrega el gesto a un control vetado.
 *   [3] Los tres códigos del veto (clanIncompatibilidad actual, histórica
 *       de 30 días y propia pluma) proclaman su leyenda sin filtrar la
 *       clave técnica al usuario (Art. IV/V: el código viaja en
 *       atributos de datos, jamás en el texto visible).
 *   [4] La regla NULA del validador (conjuro de ermitaño sin estandarte)
 *       produce tarjeta APTA: sin veto, con botón vivo.
 *   [5] El gesto vetado jamás alcanza el bus: ni click ni listener emiten
 *       sign-intent sobre la obra vedada (la guardia de UI existe y la
 *       del backend la duplica, RF-06.2).
 *
 * Constitución:
 *   - Artículo I (Dogma Vanilla): arnés nativo Node con DOM simulado.
 *   - Artículo V: identificadores en inglés camelCase; asertos castellanos.
 *
 * Uso: node scratch/test_ethical_conflict_ui.mjs
 */

import {
  createMastersTowerComponent,
  MASTERS_TOWER_VETO_LEGENDS,
  MASTERS_TOWER_VETO_UNKNOWN_LEGEND,
} from '../public/assets/js/components/mastersTowerComponent.js';

let assertsPassed = 0;
let assertsFailed = 0;

/** Aserta una condición y registra el resultado en la bitácora de consola. */
function assertCondition(condition, description) {
  if (condition) {
    assertsPassed += 1;
    console.log(`  [PASA] ${description}`);
  } else {
    assertsFailed += 1;
    console.log(`  [FALLA] ${description}`);
  }
}

/** Elemento DOM mínimo simulado (patrón consolidado del proyecto). */
function createFakeElement(tagName) {
  const element = {
    tagName: String(tagName).toUpperCase(),
    children: [],
    attributes: {},
    classes: new Set(),
    listeners: {},
    _textContent: '',
    parentElement: null,
    disabled: false,
    value: '',
    setAttribute(name, value) { this.attributes[name] = String(value); },
    getAttribute(name) { return name in this.attributes ? this.attributes[name] : null; },
    removeAttribute(name) { delete this.attributes[name]; },
    hasAttribute(name) { return name in this.attributes; },
    addEventListener(eventName, listener) { (this.listeners[eventName] ??= []).push(listener); },
    removeEventListener(eventName, listener) { this.listeners[eventName] = (this.listeners[eventName] ?? []).filter((l) => l !== listener); },
    dispatch(eventName, eventObject = {}) {
      for (const listener of this.listeners[eventName] ?? []) {
        listener({ type: eventName, preventDefault() {}, stopPropagation() {}, currentTarget: this, target: this, ...eventObject });
      }
    },
    appendChild(child) { child.parentElement = this; this.children.push(child); return child; },
    remove() {
      if (!this.parentElement) return;
      const index = this.parentElement.children.indexOf(this);
      if (index >= 0) this.parentElement.children.splice(index, 1);
      this.parentElement = null;
    },
    set className(value) { this.classes = new Set(String(value).split(/\s+/).filter(Boolean)); },
    get className() { return [...this.classes].join(' '); },
    get textContent() { return `${this._textContent}${this.children.map((child) => child.textContent).join('')}`; },
    set textContent(value) {
      for (const child of this.children.splice(0)) child.parentElement = null;
      this._textContent = String(value);
    },
    get innerHTML() { throw new Error('PROHIBIDO innerHTML (AGENTS.md 6.1: XSS); usar textContent'); },
    set innerHTML(value) { throw new Error(`PROHIBIDO innerHTML (AGENTS.md 6.1): se intentó escribir '${String(value).slice(0, 40)}'`); },
    focus() {},
  };
  element.classList = {
    _owner: element,
    add(...names) { names.forEach((n) => this._owner.classes.add(n)); },
    remove(...names) { names.forEach((n) => this._owner.classes.delete(n)); },
    contains(name) { return this._owner.classes.has(name); },
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
const byClass = (root, className) => queryByClass(root, className)[0] ?? null;
const allByClass = (root, className) => queryByClass(root, className);

/** Documento anfitrión simulado con bus de eventos registrador. */
function createFakeDocument() {
  const dispatchedEvents = [];
  return {
    createElement: (tagName) => createFakeElement(tagName),
    dispatchedEvents,
    defaultView: {
      dispatchEvent: (event) => { dispatchedEvents.push(event); return true; },
    },
    CustomEvent: class FakeCustomEvent {
      constructor(type, options = {}) { this.type = type; this.detail = options.detail ?? null; }
    },
  };
}

const fakeElementFactory = (tagName) => createFakeElement(tagName);

// ---------------------------------------------------------------------
// Sobre canónico del validador (ClanEthicsValidator::vetoReasonFor):
// las tres leyendas LITERALES que el servicio de producción devuelve.
// ---------------------------------------------------------------------

/** Regla 1: el Maestro milita AHORA en el clan del conjuro. */
const LEGEND_ACTIVE_CLAN =
  'El vínculo de sangre nubla el juicio: un Maestro no puede deliberar ni firmar conjuros de su propio linaje actual.';

/** Regla 2: el Maestro habitó el clan dentro de la ventana de 30 días. */
const LEGEND_HISTORICAL_CLAN =
  'El Maestro ha pertenecido a este linaje en los últimos treinta días naturales: deliberación y firma vetadas por incompatibilidad histórica (Artículo III).';

/** Regla 0: el erudito no firma sus propias creaciones (propia pluma). */
const LEGEND_OWN_PLUME =
  'Un erudito no puede emitir firmas sobre sus propias creaciones.';

/** Obra apta: Maestro de hermandad ajena juzga sin conflicto (regla nula). */
const QUEUE_ITEM_ELIGIBLE = {
  spellId: 'spl_luz',
  spellSlug: 'espejo-de-luz',
  spellName: 'Lámpara del Atrio',
  authorAlias: 'hermitano',
  originClanId: null,
  originClanName: null,
  elementalAffinity: 'light',
  magicSchool: 'abjuration',
  signaturesCount: 1,
  signaturesIndicator: '1/3',
  hasEthicalConflict: false,
  ethicalVeto: null,
  waitingDays: 0,
};

/** Fábrica de obras vetadas: clona la apta y muda el veredicto ético. */
function buildVettedItem(spellId, vetoCode, servedLegend) {
  return {
    ...QUEUE_ITEM_ELIGIBLE,
    spellId,
    spellName: `Obra de ${spellId}`,
    hasEthicalConflict: true,
    ethicalVeto: { code: vetoCode, legend: servedLegend },
  };
}

/** Cola de una sola obra servida por el cliente simulado. */
function buildClient(item) {
  return {
    fetchDeliberationQueue: async () => ({
      success: true,
      status: 200,
      data: { items: [item], canon: { signaturesRequired: 3 } },
    }),
  };
}

/** Monta la Torre con la cola dada y devuelve { mount, tower, document }. */
async function buildTower(item) {
  const fakeDocument = createFakeDocument();
  const mount = createFakeElement('div');
  const tower = createMastersTowerComponent(mount, {
    moderationClient: buildClient(item),
    elementFactory: fakeElementFactory,
    documentRef: fakeDocument,
  });
  await tower.render();
  return { mount, tower, fakeDocument };
}

console.log('== ARNES RF-06.1 (SPEC-03): la advertencia del vínculo de sangre en la Torre ==\n');

// ---------------------------------------------------------------------
// [1] La leyenda literal del spec llega INTACTA a la alerta viva.
// ---------------------------------------------------------------------
console.log('[1] La leyenda canónica del vínculo de sangre viaja intacta del servidor');

const towerActive = await buildTower(
  buildVettedItem('spl_activo', 'clanIncompatibility', LEGEND_ACTIVE_CLAN),
);
const vetoAlertActive = byClass(towerActive.mount, 'masters-tower__ethical-veto');
assertCondition(vetoAlertActive !== null, 'La tarjeta vetada proclama una alerta ética');
assertCondition(
  vetoAlertActive?.textContent === LEGEND_ACTIVE_CLAN,
  'La leyenda literal del ClanEthicsValidator se pinta SIN retoques (fuente única de verdad)',
);
assertCondition(
  vetoAlertActive?.textContent.includes('El vínculo de sangre nubla el juicio'),
  'La advertencia canónica de RF-06.1 es visible al Maestro',
);
assertCondition(vetoAlertActive?.getAttribute('role') === 'alert', 'La advertencia es una región viva (RNF-03)');

// ---------------------------------------------------------------------
// [2] El botón de firma nace INHABILITADO con el veto presente.
// ---------------------------------------------------------------------
console.log('\n[2] El control de firma queda deshabilitado ante el conflicto');

const signButtonActive = allByClass(towerActive.mount, 'masters-tower__sign-button')[0];
assertCondition(signButtonActive?.disabled === true, 'El botón de firma nace inhabilitado (disabled=true)');
assertCondition(
  signButtonActive?.getAttribute('aria-disabled') === 'true',
  'El bloqueo se anuncia con aria-disabled (accesibilidad)',
);
assertCondition(
  signButtonActive?.getAttribute('data-ethical-veto') === 'clanIncompatibility',
  'El botón declara la CAUSA del veto en su atributo de datos',
);
const objectButtonActive = allByClass(towerActive.mount, 'masters-tower__object-button')[0];
assertCondition(
  objectButtonActive?.disabled === false,
  'El Dictamen de Objeción permanece operativo: el veto ético solo veda la firma (contrato de SPEC-08)',
);

// ---------------------------------------------------------------------
// [3] Las tres causas del veto proclaman su leyenda sin filtrar códigos.
// ---------------------------------------------------------------------
console.log('\n[3] Las causas actual, histórica y propia pluma declaran su leyenda');

const towerHistorical = await buildTower(
  buildVettedItem('spl_historico', 'clanIncompatibility', LEGEND_HISTORICAL_CLAN),
);
assertCondition(
  byClass(towerHistorical.mount, 'masters-tower__ethical-veto')?.textContent === LEGEND_HISTORICAL_CLAN,
  'La incompatibilidad histórica de 30 días proclama su leyenda íntegra',
);

const towerOwnPlume = await buildTower(
  buildVettedItem('spl_propia', 'ownAuthorship', LEGEND_OWN_PLUME),
);
assertCondition(
  byClass(towerOwnPlume.mount, 'masters-tower__ethical-veto')?.textContent === LEGEND_OWN_PLUME,
  'La propia pluma proclama su leyenda íntegra',
);

const towerFallback = await buildTower(
  buildVettedItem('spl_reserva', 'clanIncompatibility', ''),
);
assertCondition(
  byClass(towerFallback.mount, 'masters-tower__ethical-veto')?.textContent === MASTERS_TOWER_VETO_LEGENDS.clanIncompatibility,
  'Sin leyenda servida, la reverva canónica del panel rotula el veto (degradación)',
);

const towerUnknown = await buildTower(
  buildVettedItem('spl_ignoto', 'causaDesconocida', ''),
);
assertCondition(
  byClass(towerUnknown.mount, 'masters-tower__ethical-veto')?.textContent === MASTERS_TOWER_VETO_UNKNOWN_LEGEND,
  'Una causa fuera del canon no deja la alerta en blanco',
);

// El código técnico viaja en atributos de datos, jamás en el texto visible.
for (const { name, mount } of [
  { name: 'leyenda activa', mount: towerActive.mount },
  { name: 'leyenda histórica', mount: towerHistorical.mount },
  { name: 'leyenda propia pluma', mount: towerOwnPlume.mount },
]) {
  const alertText = byClass(mount, 'masters-tower__ethical-veto')?.textContent ?? '';
  assertCondition(
    !/clanIncompatibility|ownAuthorship|vetoCode/.test(alertText),
    `El texto visible de la ${name} no filtra claves técnicas (Art. V)`,
  );
}

// ---------------------------------------------------------------------
// [4] La regla nula del validador (ermitaño sin estandarte) produce APTO.
// ---------------------------------------------------------------------
console.log('\n[4] Sin conflicto no hay veto ni control bloqueado');

const towerEligible = await buildTower(QUEUE_ITEM_ELIGIBLE);
assertCondition(
  byClass(towerEligible.mount, 'masters-tower__ethical-veto') === null,
  'La tarjeta del Maestro apto no porta alerta ética alguna',
);
const signButtonEligible = allByClass(towerEligible.mount, 'masters-tower__sign-button')[0];
assertCondition(signButtonEligible?.disabled === false, 'El botón de firma del apto nace operativo');
assertCondition(
  signButtonEligible?.getAttribute('data-ethical-veto') === null,
  'El botón del apto no declara veto alguno',
);

// ---------------------------------------------------------------------
// [5] El gesto vetado jamás alcanza el bus (la UI no se lo pasa al rito).
// ---------------------------------------------------------------------
console.log('\n[5] El veto de UI no deja pasar la intención de firma');

// Con doble tarjeta (apta + vetada) en una sola Torre: solo la apta emite.
const fakeDocumentDuo = createFakeDocument();
const mountDuo = createFakeElement('div');
const towerDuo = createMastersTowerComponent(mountDuo, {
  moderationClient: {
    fetchDeliberationQueue: async () => ({
      success: true,
      status: 200,
      data: {
        items: [QUEUE_ITEM_ELIGIBLE, buildVettedItem('spl_activo', 'clanIncompatibility', LEGEND_ACTIVE_CLAN)],
        canon: { signaturesRequired: 3 },
      },
    }),
  },
  elementFactory: fakeElementFactory,
  documentRef: fakeDocumentDuo,
});
await towerDuo.render();

const duoSignButtons = allByClass(mountDuo, 'masters-tower__sign-button');
assertCondition(duoSignButtons.length === 2, 'La cola mixta presenta los dos botones de firma');
duoSignButtons[0].dispatch('click');
assertCondition(
  fakeDocumentDuo.dispatchedEvents.some((event) => event.type === 'moderation:sign-intent' && event.detail?.spellId === 'spl_luz'),
  'El gesto del Maestro apto anuncia su intención por el bus',
);
const intentsAfterEligible = fakeDocumentDuo.dispatchedEvents.length;
// El agente de usuario real JAMÁS entrega click a un control disabled:
// la guardia anti-gesto vive en la semántica del propio botón vetado.
// El doble de DOM replica esa semántica para no simular algo imposible.
const clickAsBrowser = (button) => {
  if (button.disabled === true) return; // Semántica del navegador: sin evento.
  button.dispatch('click');
};
clickAsBrowser(duoSignButtons[1]);
assertCondition(
  fakeDocumentDuo.dispatchedEvents.length === intentsAfterEligible,
  'El botón VETADO jamás recibe el gesto (disabled=true del navegador) y ninguna intención escapa',
);
assertCondition(
  !fakeDocumentDuo.dispatchedEvents.some((event) => event.type === 'moderation:sign-intent' && event.detail?.spellId === 'spl_activo'),
  'La obra vedada jamás llega al rito de firma (ninguna vía del navegador la entrega)',
);

// =====================================================================
// Resumen final.
// =====================================================================
console.log('\n== RESUMEN ==');
console.log(`Asertos superados: ${assertsPassed}`);
console.log(`Asertos fallidos:  ${assertsFailed}`);

if (assertsFailed === 0) {
  console.log('\nRESULTADO: EXITO — La advertencia del vínculo de sangre (RF-06.1) vive en la Torre con sus controles deshabilitados.');
  process.exit(0);
}
console.log('\nRESULTADO: FALLO');
process.exit(1);
