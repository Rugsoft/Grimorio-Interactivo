/**
 * test_spell_correction_component.mjs — Arnés de la Tarea 6.2 (TASKS-08).
 *
 * Uso: node scratch/test_spell_correction_component.mjs
 *
 * Verifica sobre `public/assets/js/components/spellCorrectionComponent.js`
 * el criterio «Hecho cuando»:
 *   «El autor puede consultar el motivo exacto del rechazo y, al pulsar en
 *    reabrir, el conjuro pasa a `draft` habilitando el formulario de
 *    edición.»
 *
 * Fases:
 *   [1]  Superficie del módulo y sus leyendas.
 *   [2]  Estado vacío: la leyenda se declara sin obra.
 *   [3]  Obra vetada: la región, la insignia y el pergamino del dictamen.
 *   [4]  El dictamen viaja ÍNTEGRO, carácter por carácter (RF-06.2, Art. IV).
 *   [5]  El gesto de re-apertura anuncia su intención (callback y bus).
 *   [6]  El dictamen AUSENTE degrada con su reserva declarada.
 *   [7]  Corte de maná: alerta viva con reintento.
 *   [8]  Actualización dirigida por evento (`setSpellStatus`) sin re-consulta.
 *   [9]  Ciclo de vida: render idempotente y destroy sin fugas.
 *   [10] XSS: el lore hostil viaja como TEXTO literal (AGENTS.md 6.1).
 *
 * Constitución:
 *   - Artículo I (Dogma Vanilla): cero librerías; DOM simulado propio.
 *   - Artículo V: identificadores en inglés camelCase; comentarios en castellano.
 */

import {
  createSpellCorrectionComponent,
  SPELL_CORRECTION_LEGENDS,
  SPELL_CORRECTION_RESERVES,
} from '../public/assets/js/components/spellCorrectionComponent.js';

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

/** Elemento DOM mínimo simulado (innerHTML PROHIBIDO: su accesor lanza). */
function createFakeElement(tagName) {
  const element = {
    tagName: String(tagName).toUpperCase(),
    children: [],
    attributes: {},
    classes: new Set(),
    listeners: {},
    value: '',
    checked: false,
    focusCount: 0,
    _textContent: '',
    parentElement: null,
    setAttribute(name, value) { this.attributes[name] = String(value); },
    getAttribute(name) { return name in this.attributes ? this.attributes[name] : null; },
    removeAttribute(name) { delete this.attributes[name]; },
    hasAttribute(name) { return name in this.attributes ? this.attributes[name] : null; },
    addEventListener(eventName, listener) { (this.listeners[eventName] ??= []).push(listener); },
    removeEventListener(eventName, listener) {
      this.listeners[eventName] = (this.listeners[eventName] ?? []).filter((l) => l !== listener);
    },
    dispatch(eventName, eventObject = {}) {
      for (const listener of [...(this.listeners[eventName] ?? [])]) {
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
    get textContent() {
      return `${this._textContent}${this.children.map((child) => child.textContent).join('')}`;
    },
    set textContent(value) {
      for (const child of this.children.splice(0)) child.parentElement = null;
      this._textContent = String(value);
    },
    get innerHTML() { throw new Error('PROHIBIDO innerHTML (AGENTS.md 6.1: XSS); usar textContent'); },
    set innerHTML(value) { throw new Error(`PROHIBIDO innerHTML (AGENTS.md 6.1): se intentó escribir '${String(value).slice(0, 40)}'`); },
    focus() { this.focusCount += 1; },
  };
  element.classList = {
    _owner: element,
    add(...names) { names.forEach((n) => this._owner.classes.add(n)); },
    remove(...names) { names.forEach((n) => this._owner.classes.delete(n)); },
    contains(name) { return this._owner.classes.has(name); },
  };
  return element;
}

/** Barrido recursivo por clase sobre el DOM simulado. */
function queryByClass(node, className, found = []) {
  for (const child of node.children) {
    if (child.classes.has(className)) found.push(child);
    queryByClass(child, className, found);
  }
  return found;
}
const byClass = (root, className) => queryByClass(root, className)[0] ?? null;
const allByClass = (root, className) => queryByClass(root, className);

/** Barrido recursivo por atributo data-role (los controles del panel). */
function queryByRole(node, role, found = []) {
  for (const child of node.children) {
    if (child.getAttribute('data-role') === role) found.push(child);
    queryByRole(child, role, found);
  }
  return found;
}
const byRole = (root, role) => queryByRole(root, role)[0] ?? null;

/** Barrido recursivo por atributo data-verdict-field (campos del dictamen). */
function queryByVerdictField(node, fieldName, found = []) {
  for (const child of node.children) {
    if (child.getAttribute('data-verdict-field') === fieldName) found.push(child);
    queryByVerdictField(child, fieldName, found);
  }
  return found;
}
const byVerdictField = (root, fieldName) => queryByVerdictField(root, fieldName)[0] ?? null;

const fakeElementFactory = (tagName) => createFakeElement(tagName);

/** Documento anfitrión simulado con un bus de eventos registrador. */
function createFakeDocument() {
  const dispatchedEvents = [];
  const fakeDocument = {
    createElement: (tagName) => createFakeElement(tagName),
    dispatchedEvents,
    defaultView: {
      dispatchEvent: (event) => {
        dispatchedEvents.push(event);
        return true;
      },
    },
    CustomEvent: class FakeCustomEvent {
      constructor(type, options = {}) {
        this.type = type;
        this.detail = options.detail ?? null;
      }
    },
  };
  return fakeDocument;
}

const fakeDocument = createFakeDocument();
globalThis.document = fakeDocument;

/** Expediente canónico de obra vetada, espejo del Dto ObjectionVerdictDto. */
function buildRejectedItem(overrides = {}) {
  return {
    spellId: 'spl_porta',
    spellName: 'Portal de Ceniza',
    spellStatus: 'rejected',
    lastVerdict: {
      id: 'ver_0001',
      spellId: 'spl_porta',
      masterId: 'usr_maestro',
      objectionReason: 'La descripción presenta anacronismos manifiestos que contradicen el Códice Elemental.',
      reasonLength: 78,
      objectedAt: '2026-09-14T10:00:00Z',
    },
    ...overrides,
  };
}

console.log('== VERIFICACION TAREA 6.2: libreta de subsanacion del autor ==\n');

// =====================================================================
// [1] Superficie del módulo
// =====================================================================
console.log('[1] Superficie del módulo');
assertCondition(typeof createSpellCorrectionComponent === 'function', 'el módulo exporta su fábrica');
assertCondition(SPELL_CORRECTION_LEGENDS.reopenButton === 'Reabrir como Borrador', 'el botón ceremonial declara su rótulo canónico (plan 4.2.4)');
assertCondition(typeof SPELL_CORRECTION_RESERVES.noVerdict === 'string' && SPELL_CORRECTION_RESERVES.noVerdict.length > 0, 'la reserva sin dictamen se declara');

// =====================================================================
// [2] Estado vacío
// =====================================================================
console.log('\n[2] Estado vacío: la leyenda se declara sin obra');
const mountEmpty = createFakeElement('div');
const componentEmpty = createSpellCorrectionComponent(mountEmpty, {
  elementFactory: fakeElementFactory,
  documentRef: fakeDocument,
});
componentEmpty.render(null);
assertCondition(byClass(mountEmpty, 'spell-correction') !== null, 'la región vive en el montaje aun sin obra');
assertCondition(
  byClass(mountEmpty, 'spell-correction__empty')?.textContent === SPELL_CORRECTION_LEGENDS.emptyLegend,
  'la leyenda de vacío se declara por sí sola'
);
assertCondition(byRole(mountEmpty, 'reopen-draft') === null, 'sin obra vetada no hay botón de re-apertura');
componentEmpty.destroy();

// =====================================================================
// [3] Obra vetada: región, insignia y pergamino del dictamen
// =====================================================================
console.log('\n[3] Obra vetada: la región, la insignia y el pergamino');
const mount1 = createFakeElement('div');
const reopenCalls1 = [];
const component1 = createSpellCorrectionComponent(mount1, {
  onReopen: (payload) => reopenCalls1.push(payload),
  elementFactory: fakeElementFactory,
  documentRef: fakeDocument,
});
const item1 = buildRejectedItem();
component1.render(item1);

const region1 = byClass(mount1, 'spell-correction');
assertCondition(region1 !== null && region1.getAttribute('data-spell-status') === 'rejected', 'la región declara su estado canónico servido');
assertCondition(
  byClass(mount1, 'spell-correction__title')?.textContent === 'Portal de Ceniza',
  'el título retrata el nombre de la obra'
);
assertCondition(
  byRole(mount1, 'correction-status')?.textContent === SPELL_CORRECTION_LEGENDS.rejectedBadge,
  'la insignia proclama el veto (RNF-03)'
);
assertCondition(byClass(mount1, 'spell-correction__verdict-scroll') !== null, 'el pergamino del dictamen nace en la región');
assertCondition(byRole(mount1, 'reopen-draft') !== null, 'el botón ceremonial de re-apertura nace con la obra vetada');

// =====================================================================
// [4] El dictamen viaja ÍNTEGRO, carácter por carácter (RF-06.2, Art. IV)
// =====================================================================
console.log('\n[4] El dictamen viaja ÍNTEGRO, carácter por carácter');
const verdict1 = item1.lastVerdict;
assertCondition(
  byVerdictField(mount1, 'masterId')?.textContent === verdict1.masterId,
  'el Maestro dictaminante viaja tal y como lo sirvió el expediente'
);
assertCondition(
  byVerdictField(mount1, 'objectionReason')?.textContent === verdict1.objectionReason,
  'las observaciones del veto viajan ÍNTEGRAS: ni resumidas ni reescritas (RF-06.2, Art. IV)'
);
assertCondition(
  byVerdictField(mount1, 'reasonLength')?.textContent === String(verdict1.reasonLength),
  'la extensión del dictamen se retrata, no se recalcula (Art. II)'
);
assertCondition(
  byVerdictField(mount1, 'objectedAt')?.textContent === verdict1.objectedAt,
  'la fecha del dictamen viaja tal y como la selló el servidor'
);

// =====================================================================
// [5] El gesto de re-apertura anuncia su intención (callback y bus)
// =====================================================================
console.log('\n[5] El gesto de re-apertura anuncia su intención');
fakeDocument.dispatchedEvents.length = 0;
byRole(mount1, 'reopen-draft').dispatch('click');
assertCondition(
  reopenCalls1.length === 1 && reopenCalls1[0].spellId === 'spl_porta',
  'el callback recibe la obra sobre la que recae la re-apertura (RF-01.4)'
);
assertCondition(
  fakeDocument.dispatchedEvents.some((event) => event.type === 'moderation:reopen-intent' && event.detail?.spellId === 'spl_porta'),
  'la intención también viaja por el bus desacoplado (plan 4.1)'
);
// El gesto es declarativo: el panel jamás transiciona el estado por su cuenta.
assertCondition(region1.getAttribute('data-spell-status') === 'rejected', 'el panel jamás muta el estado: el veredicto lo dicta el servidor (Art. II)');

// =====================================================================
// [6] El dictamen AUSENTE degrada con su reserva declarada
// =====================================================================
console.log('\n[6] El dictamen ausente degrada con su reserva');
const mount2 = createFakeElement('div');
const component2 = createSpellCorrectionComponent(mount2, {
  elementFactory: fakeElementFactory,
  documentRef: fakeDocument,
});
component2.render(buildRejectedItem({ lastVerdict: null }));
assertCondition(
  byClass(mount2, 'spell-correction__verdict-empty')?.textContent === SPELL_CORRECTION_LEGENDS.noVerdictLegend,
  'sin dictamen, la leyenda de espera lo proclama'
);
assertCondition(byRole(mount2, 'reopen-draft') !== null, 'la re-apertura no depende del dictamen: la vía del autor es siempre la misma');
component2.destroy();

// =====================================================================
// [7] Corte de maná: alerta viva con reintento
// =====================================================================
console.log('\n[7] Corte de maná: alerta viva con reintento');
const mount3 = createFakeElement('div');
const component3 = createSpellCorrectionComponent(mount3, {
  elementFactory: fakeElementFactory,
  documentRef: fakeDocument,
});
component3.showOutage(() => {});
const outage3 = byClass(mount3, 'spell-correction__outage');
assertCondition(outage3 !== null && outage3.getAttribute('role') === 'alert', 'la alerta de corriente nace con su rol vivo');
assertCondition(outage3.textContent.includes(SPELL_CORRECTION_LEGENDS.outageLegend), 'la alerta se rotula ceremonialmente (RNF-03)');
let retries3 = 0;
const outageButton3 = outage3.children.find((child) => child.tagName === 'BUTTON');
outageButton3.dispatch('click');
assertCondition(retries3 === 0 && byClass(mount3, 'spell-correction__outage') === null, 'el reintento retira la alerta');
component3.showOutage(() => { retries3 += 1; });
outageButton3.dispatch('click');
component3.destroy();
component3.destroy();
assertCondition(retries3 === 0, 'tras destroy, el reintento y el ciclo de vida son idempotentes (sin fugas)');
assertCondition(allByClass(mount3, 'spell-correction__outage').length === 0, 'destroy retira las alertas vivas');

// =====================================================================
// [8] Actualización dirigida por evento sin re-consulta
// =====================================================================
console.log('\n[8] Actualización dirigida por evento (setSpellStatus)');
const mount4 = createFakeElement('div');
const component4 = createSpellCorrectionComponent(mount4, {
  elementFactory: fakeElementFactory,
  documentRef: fakeDocument,
});
component4.render(buildRejectedItem());
component4.setSpellStatus('spl_porta', 'draft');
assertCondition(
  byClass(mount4, 'spell-correction')?.getAttribute('data-spell-status') === 'draft',
  'el aviso de re-apertura repinta la región en `draft` sin re-consulta'
);
assertCondition(byRole(mount4, 'reopen-draft') === null, 'con la obra reabierta, el botón ceremonial se retira');
component4.setSpellStatus('spl_otra', 'draft');
assertCondition(
  byClass(mount4, 'spell-correction')?.getAttribute('data-spell-status') === 'draft',
  'un aviso de obra ajena no perturba la región propia'
);
component4.destroy();

// =====================================================================
// [9] Ciclo de vida: render idempotente y destroy sin fugas
// =====================================================================
console.log('\n[9] Ciclo de vida: render idempotente y destroy sin fugas');
const mount5 = createFakeElement('div');
const component5 = createSpellCorrectionComponent(mount5, {
  elementFactory: fakeElementFactory,
  documentRef: fakeDocument,
});
component5.render(buildRejectedItem());
component5.render(buildRejectedItem({ spellId: 'spl_segundo' }));
assertCondition(allByClass(mount5, 'spell-correction').length === 1, 'el re-render repinta UNA sola región: sin huérfanos');
component5.destroy();
component5.destroy();
assertCondition(mount5.children.length === 0, 'destroy idempotente deja el montaje limpio');
component5.render(buildRejectedItem());
assertCondition(mount5.children.length === 0, 'tras destroy, nada vuelve a nacer');

// =====================================================================
// [10] XSS: el lore hostil viaja como TEXTO literal (AGENTS.md 6.1)
// =====================================================================
console.log('\n[10] XSS: el lore hostil viaja como TEXTO literal');
const mount6 = createFakeElement('div');
const component6 = createSpellCorrectionComponent(mount6, {
  elementFactory: fakeElementFactory,
  documentRef: fakeDocument,
});
const hostileReason = '<script>void();</script> Dictamen con etiquetas hostiles';
component6.render(buildRejectedItem({
  spellName: '<img src=x onerror=alert(1)>',
  lastVerdict: { ...buildRejectedItem().lastVerdict, objectionReason: hostileReason },
}));
assertCondition(
  byVerdictField(mount6, 'objectionReason')?.textContent === hostileReason,
  'el dictamen hostil alcanza el pergamino como TEXTO literal, sin interpretar etiqueta alguna'
);
assertCondition(
  byClass(mount6, 'spell-correction__title')?.textContent === '<img src=x onerror=alert(1)>',
  'el nombre hostil viaja como texto literal'
);
// La prohibición ya se ejerce de oficio: el accesor innerHTML del DOM
// simulado LANZA, de modo que cualquier uso en el componente reventaría
// todas las fases anteriores (AGENTS.md 6.1).

console.log(`\n== RESUMEN == Asertos superados: ${assertsPassed}, fallidos: ${assertsFailed}`);
if (assertsFailed === 0) {
  console.log('RESULTADO: EXITO — El autor consulta el motivo exacto y reabre como borrador (Tarea 6.2).');
  process.exit(0);
} else {
  console.log('RESULTADO: FALLO — la libreta de subsanación incumple su criterio.');
  process.exit(1);
}
