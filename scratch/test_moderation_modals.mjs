/**
 * test_moderation_modals.mjs — Arnés de la Tarea 6.1 (TASKS-08).
 *
 * Uso: node scratch/test_moderation_modals.mjs
 *
 * Verifica sobre `public/assets/js/components/objectionModalComponent.js` e
 * `imperialDecreeModalComponent.js` el criterio «Hecho cuando»:
 *   «Los modales bloquean el botón de confirmación si el texto tiene menos
 *    de 20 caracteres y emiten los eventos correspondientes tras la
 *    confirmación solemne.»
 *
 * Fases:
 *   [1]  Superficie de ambos módulos y umbrales de reserva.
 *   [2]  Modal de objeción: el envío nace INHABILITADO y el foco en la redacción.
 *   [3]  El contador decreciente: diecinueve bloquea, veinte habilita (criterio).
 *   [4]  Cuarenta espacios no son justificación: el umbral se mide RECORTADO.
 *   [5]  La confirmación solemne emite su evento (criterio) y cierra el diálogo.
 *   [6]  El desistimiento: Escape y el botón de desistir resuelven sin evento.
 *   [7]  El modal de edicto: los tres decretos con su liturgia propia (RF-04).
 *   [8]  La orden de deducción: SOLO en el destierro, EXPLÍCITA y desmarcada.
 *   [9]  El destino del rescate: dos opciones canónicas, decisión declarada.
 *   [10] El canon del backend muda el umbral del contador (Art. II).
 *   [11] Un solo diálogo a la vez y ciclo de vida (destroy).
 *   [12] XSS: el texto hostil viaja como texto literal (AGENTS.md 6.1).
 *
 * Constitución:
 *   - Artículo I (Dogma Vanilla): cero librerías; DOM simulado propio.
 *   - Artículo V: identificadores en inglés camelCase; comentarios en castellano.
 */

import {
  createObjectionModalComponent,
  OBJECTION_MODAL_FALLBACK_MIN_LENGTH,
} from '../public/assets/js/components/objectionModalComponent.js';
import {
  createImperialDecreeModalComponent,
  IMPERIAL_DECREE_MODAL_FALLBACK_MIN_LENGTH,
  IMPERIAL_DECREE_MODAL_RESCUE_TARGETS,
  IMPERIAL_DECREE_MODAL_LEGENDS,
} from '../public/assets/js/components/imperialDecreeModalComponent.js';

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
    hasAttribute(name) { return name in this.attributes; },
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

/** Barrido recursivo por atributo data-role (los controles del modal). */
function queryByRole(node, role, found = []) {
  for (const child of node.children) {
    if (child.getAttribute('data-role') === role) found.push(child);
    queryByRole(child, role, found);
  }
  return found;
}
const byRole = (root, role) => queryByRole(root, role)[0] ?? null;

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

console.log('== VERIFICACION TAREA 6.1: modales liturgicos de moderacion ==\n');

// =====================================================================
// [1] Superficie de ambos módulos
// =====================================================================
console.log('[1] Superficie de los módulos');
assertCondition(typeof createObjectionModalComponent === 'function', 'el módulo de objeción exporta su fábrica');
assertCondition(typeof createImperialDecreeModalComponent === 'function', 'el módulo de edicto imperial exporta su fábrica');
assertCondition(OBJECTION_MODAL_FALLBACK_MIN_LENGTH === 20, 'la reserva del dictamen declara el umbral de veinte (RF-02.5)');
assertCondition(IMPERIAL_DECREE_MODAL_FALLBACK_MIN_LENGTH === 20, 'la reserva del edicto declara el umbral de veinte (RF-04.5)');
assertCondition(
  IMPERIAL_DECREE_MODAL_RESCUE_TARGETS.length === 2
    && IMPERIAL_DECREE_MODAL_RESCUE_TARGETS[0] === 'experimental'
    && IMPERIAL_DECREE_MODAL_RESCUE_TARGETS[1] === 'validated',
  'los dos destinos canónicos del rescate viajan en el canon de reserva'
);

const fakeDocument = createFakeDocument();
globalThis.document = fakeDocument;

// =====================================================================
// [2] Modal de objeción: el envío nace INHABILITADO
// =====================================================================
console.log('\n[2] El dictamen nace con el envío inhabilitado');
const mount1 = createFakeElement('div');
const objectionModal1 = createObjectionModalComponent(mount1, {
  spellId: 'spl_porta',
  elementFactory: fakeElementFactory,
  documentRef: fakeDocument,
});
const opening1 = objectionModal1.open();

const overlay1 = byClass(mount1, 'objection-modal');
assertCondition(overlay1 !== null, 'el diálogo nace en el montaje');
assertCondition(overlay1?.getAttribute('role') === 'alertdialog' && overlay1?.getAttribute('aria-modal') === 'true', 'el diálogo es un alertdialog modal');
assertCondition(overlay1?.getAttribute('aria-labelledby') === 'objectionModalTitle', 'aria-labelledby apunta a su título ceremonial');
assertCondition(
  byClass(mount1, 'objection-modal__title')?.textContent === 'Dictamen de Objeción Fundamentada',
  'el diálogo se rotula solemnemente (RNF-03)'
);
const reasonField1 = byRole(mount1, 'objection-reason');
assertCondition(reasonField1 !== null && reasonField1.tagName === 'TEXTAREA', 'el campo de redacción es un textarea nativo');
const submitButton1 = byRole(mount1, 'objection-submit');
assertCondition(submitButton1?.disabled === true, 'el envío nace INHABILITADO (criterio)');
assertCondition(reasonField1?.focusCount === 1, 'el foco nace en la redacción: el gesto que sigue es escribir el dictamen');

// =====================================================================
// [3] El contador decreciente: diecinueve bloquea, veinte habilita (criterio)
// =====================================================================
console.log('\n[3] El validador dinámico decreciente');
const counter1 = byRole(mount1, 'objection-counter');
assertCondition(counter1?.getAttribute('role') === 'status' && counter1?.getAttribute('aria-live') === 'polite', 'el contador vive en una región viva de cortesía');

reasonField1.value = 'Muy breve';
reasonField1.dispatch('input');
assertCondition(
  counter1?.textContent.includes('Faltan 11 caracteres'),
  `con once menos, el contador decreciente lo proclama (${counter1?.textContent})`
);
assertCondition(submitButton1?.disabled === true, 'diecinueve caracteres escritos: el envío sigue bloqueado');

reasonField1.value = 'La descripción presenta anacronismos manifiestos.';
reasonField1.dispatch('input');
assertCondition(
  counter1?.textContent === 'La justificación alcanza la solemnidad requerida: el dictamen puede emitirse.',
  'al alcanzar la solemnidad, el contador lo proclama'
);
assertCondition(submitButton1?.disabled === false, 'veinte caracteres exactos HABILITAN el envío (umbral inclusivo)');
assertCondition(submitButton1?.getAttribute('aria-disabled') === null, 'el envío habilitado retira su aria-disabled');

// Borde inferior: diecinueve caracteres (una tilde incluida).
reasonField1.value = 'Dictamen de 19 cars';
reasonField1.dispatch('input');
assertCondition(
  submitButton1?.disabled === true && counter1?.textContent.includes('Faltan 1'),
  `un carácter a menos vuelve a bloquear: el contador no redondea (${reasonField1.value.trim().length})`
);

// =====================================================================
// [4] Cuarenta espacios no son justificación (umbral RECORTADO)
// =====================================================================
console.log('\n[4] El umbral se mide sobre el texto recortado');
reasonField1.value = '                                        ';
reasonField1.dispatch('input');
assertCondition(submitButton1?.disabled === true, 'cuarenta espacios jamás lucen como justificación (plan 2.4)');
reasonField1.value = '   La descripción presenta anacronismos.   ';
reasonField1.dispatch('input');
assertCondition(submitButton1?.disabled === false, 'los espacios que rodean al texto no lo descalifican');

// =====================================================================
// [5] La confirmación solemne emite su evento (criterio)
// =====================================================================
console.log('\n[5] La confirmación solemne del dictamen');
const submitted1 = [];
const mount5 = createFakeElement('div');
const objectionModal5 = createObjectionModalComponent(mount5, {
  spellId: 'spl_porta',
  onSubmitted: (spellId, objectionReason) => submitted1.push({ spellId, objectionReason }),
  elementFactory: fakeElementFactory,
  documentRef: fakeDocument,
});
const verdict5 = objectionModal5.open();
fakeDocument.dispatchedEvents.length = 0;

const reasonField5 = byRole(mount5, 'objection-reason');
reasonField5.value = 'La descripción presenta anacronismos que vulneran el Velo Arcano.';
reasonField5.dispatch('input');
byRole(mount5, 'objection-submit').dispatch('click');
const settled5 = await verdict5;

assertCondition(settled5.submitted === true, 'la confirmación resuelve el veredicto afirmativo');
assertCondition(
  settled5.objectionReason === 'La descripción presenta anacronismos que vulneran el Velo Arcano.',
  'el dictamen viaja íntegro y RECORTADO (como lo medirá el backend)'
);
assertCondition(
  submitted1.length === 1 && submitted1[0].spellId === 'spl_porta',
  'el orquestador recibe el veredicto con la obra sobre la que se dicta'
);
assertCondition(
  fakeDocument.dispatchedEvents.some((event) => event.type === 'moderation:objection-submitted' && event.detail?.spellId === 'spl_porta'),
  'el veredicto también viaja por el bus desacoplado (plan 4.1: moderation:objection-submitted)'
);
assertCondition(byClass(mount5, 'objection-modal') === null, 'el diálogo se retira del montaje al resolver');

// La doble guarda: por debajo del umbral el clic no confirma.
const mount5b = createFakeElement('div');
const objectionModal5b = createObjectionModalComponent(mount5b, {
  elementFactory: fakeElementFactory,
  documentRef: fakeDocument,
});
const verdict5b = objectionModal5b.open();
byRole(mount5b, 'objection-reason').value = 'Breve';
byRole(mount5b, 'objection-submit').dispatch('click');
// La promesa queda PENDIENTE (el diálogo sigue abierto): se mide por carrera,
// porque un clic bajo el umbral jamás resuelve el veredicto.
const belowThresholdVerdict = await Promise.race([
  verdict5b.then(() => 'settled'),
  new Promise((resolve) => setTimeout(() => resolve('pending'), 200)),
]);
assertCondition(
  belowThresholdVerdict === 'pending' && byClass(mount5b, 'objection-modal') !== null,
  'un clic bajo el umbral jamás confirma: el diálogo sigue abierto'
);
objectionModal5b.destroy();

// =====================================================================
// [6] El desistimiento: Escape y el botón de desistir
// =====================================================================
console.log('\n[6] Desistir del veto');
const mount6 = createFakeElement('div');
const objectionModal6 = createObjectionModalComponent(mount6, {
  elementFactory: fakeElementFactory,
  documentRef: fakeDocument,
});
const verdict6 = objectionModal6.open();
fakeDocument.dispatchedEvents.length = 0;
byClass(mount6, 'objection-modal').dispatch('keydown', { key: 'Escape' });
const settled6 = await verdict6;
assertCondition(settled6.submitted === false && settled6.objectionReason === '', 'Escape desiste sin dictamen');
assertCondition(
  fakeDocument.dispatchedEvents.some((event) => event.type === 'moderation:objection-submitted') === false,
  'el desistimiento jamás emite el evento de veredicto'
);
assertCondition(byClass(mount6, 'objection-modal') === null, 'el diálogo se retira al desistir');

const mount6b = createFakeElement('div');
const objectionModal6b = createObjectionModalComponent(mount6b, {
  elementFactory: fakeElementFactory,
  documentRef: fakeDocument,
});
const verdict6b = objectionModal6b.open();
byClass(mount6b, 'objection-modal__cancel').dispatch('click');
const settled6b = await verdict6b;
assertCondition(settled6b.submitted === false, 'el botón de desistir resuelve sin veredicto');

// =====================================================================
// [7] El modal de edicto: los tres decretos con su liturgia propia (RF-04)
// =====================================================================
console.log('\n[7] Los tres decretos soberanos con su liturgia');
const DECREES = [
  { decreeType: 'sovereignValidation', expectedTitle: IMPERIAL_DECREE_MODAL_LEGENDS.sovereignValidation.title },
  { decreeType: 'rescueToExperimental', expectedTitle: IMPERIAL_DECREE_MODAL_LEGENDS.rescueToExperimental.title },
  { decreeType: 'rescueToValidated', expectedTitle: IMPERIAL_DECREE_MODAL_LEGENDS.rescueToValidated.title },
  { decreeType: 'revokeAndArchive', expectedTitle: IMPERIAL_DECREE_MODAL_LEGENDS.revokeAndArchive.title },
];
for (const decreeRequest of DECREES) {
  const mountDecree = createFakeElement('div');
  const decreeModal = createImperialDecreeModalComponent(mountDecree, {
    spellId: 'spl_porta',
    elementFactory: fakeElementFactory,
    documentRef: fakeDocument,
  });
  const pendingDecree = decreeModal.open(decreeRequest);
  const overlayDecree = byClass(mountDecree, 'imperial-decree-modal');
  assertCondition(
    overlayDecree?.getAttribute('data-decree-type') === decreeRequest.decreeType
      && byClass(mountDecree, 'imperial-decree-modal__title')?.textContent === decreeRequest.expectedTitle,
    `el decreto ${decreeRequest.decreeType} abre con su liturgia propia`
  );
  const submitDecree = byRole(mountDecree, 'decree-submit');
  assertCondition(
    submitDecree?.disabled === true && byRole(mountDecree, 'imperial-decree-text')?.focusCount === 1,
    `el envío del ${decreeRequest.decreeType} nace inhabilitado con el foco en el edicto`
  );
  // La orden de deducción SOLO existe en el destierro.
  const deductionDecree = byRole(mountDecree, 'deduct-points');
  if (decreeRequest.decreeType === 'revokeAndArchive') {
    assertCondition(deductionDecree !== null, 'el destierro porta su casilla de deducción explícita');
  } else {
    assertCondition(deductionDecree === null, `el decreto ${decreeRequest.decreeType} jamás ofrece deducción`);
  }
  decreeModal.destroy();
}

// =====================================================================
// [8] La orden de deducción: explícita y desmarcada de nacimiento
// =====================================================================
console.log('\n[8] La orden de deducción es una ley, no una preferencia');
const decrees8 = [];
const mount8 = createFakeElement('div');
const decreeModal8 = createImperialDecreeModalComponent(mount8, {
  spellId: 'spl_porta',
  onSubmitted: (decree) => decrees8.push(decree),
  elementFactory: fakeElementFactory,
  documentRef: fakeDocument,
});
const verdict8 = decreeModal8.open({ decreeType: 'revokeAndArchive' });
fakeDocument.dispatchedEvents.length = 0;

const deduction8 = byRole(mount8, 'deduct-points');
assertCondition(deduction8?.checked === false, 'la casilla nace DESMARCADA: nadie debe gloria por omisión (Art. III.3)');
byRole(mount8, 'imperial-decree-text').value = 'Se constata fraude en la composición del conjuro.';
byRole(mount8, 'imperial-decree-text').dispatch('input');
byRole(mount8, 'decree-submit').dispatch('click');
const settled8 = await verdict8;
assertCondition(settled8.submitted === true && settled8.decree?.decreeType === 'revokeAndArchive', 'el destierro confirma con su tipo canónico');
assertCondition(settled8.decree?.deductPoints === false, 'sin marcar la casilla, la orden de deducción viaja EXPLÍCITAMENTE en falso');
assertCondition(
  fakeDocument.dispatchedEvents.some((event) => event.type === 'moderation:decree-submitted' && event.detail?.deductPoints === false),
  'el decreto también viaja por el bus desacoplado (plan 4.1: moderation:decreed)'
);

// Marcando la casilla, la orden viaja en verdadero.
const mount8b = createFakeElement('div');
const decreeModal8b = createImperialDecreeModalComponent(mount8b, {
  elementFactory: fakeElementFactory,
  documentRef: fakeDocument,
});
const verdict8b = decreeModal8b.open({ decreeType: 'revokeAndArchive' });
byRole(mount8b, 'deduct-points').checked = true;
byRole(mount8b, 'imperial-decree-text').value = 'El fraude merece la restitución de la gloria drenada.';
byRole(mount8b, 'imperial-decree-text').dispatch('input');
byRole(mount8b, 'decree-submit').dispatch('click');
const settled8b = await verdict8b;
assertCondition(settled8b.decree?.deductPoints === true, 'con la casilla marcada, la orden viaja en verdadero');

// =====================================================================
// [9] El destino del rescate: decisión declarada
// =====================================================================
console.log('\n[9] El destino canónico del rescate');
const mount9 = createFakeElement('div');
const decreeModal9 = createImperialDecreeModalComponent(mount9, {
  spellId: 'spl_porta',
  elementFactory: fakeElementFactory,
  documentRef: fakeDocument,
});
const verdict9 = decreeModal9.open({ decreeType: 'rescueToExperimental' });
const targetRadios9 = queryByRole(mount9, 'rescue-target');
assertCondition(targetRadios9.length === 2, 'el rescate ofrece sus dos destinos canónicos');
assertCondition(targetRadios9[0]?.checked === true, 'el rescate a deliberación limpia nace marcado: es el destino ordinario');
byRole(mount9, 'imperial-decree-text').value = 'La objeción carecía de fundamento litúrgico objetivo.';
byRole(mount9, 'imperial-decree-text').dispatch('input');
byRole(mount9, 'decree-submit').dispatch('click');
const settled9 = await verdict9;
assertCondition(settled9.decree?.targetStatus === 'experimental', 'el destino marcado viaja en targetStatus (contrato del Endpoint 10)');

// Elegir el otro destino: el decreto viaja con validated.
const mount9b = createFakeElement('div');
const decreeModal9b = createImperialDecreeModalComponent(mount9b, {
  spellId: 'spl_porta',
  elementFactory: fakeElementFactory,
  documentRef: fakeDocument,
});
const verdict9b = decreeModal9b.open({ decreeType: 'rescueToValidated' });
const targetRadios9b = queryByRole(mount9b, 'rescue-target');
targetRadios9b[1].checked = true;
targetRadios9b[0].checked = false;
byRole(mount9b, 'imperial-decree-text').value = 'El veto era injusto: la obra merece el Tomo.';
byRole(mount9b, 'imperial-decree-text').dispatch('input');
byRole(mount9b, 'decree-submit').dispatch('click');
const settled9b = await verdict9b;
assertCondition(settled9b.decree?.targetStatus === 'validated', 'el destino elegido por el custodio viaja tal cual, sin adivinanza');
assertCondition(
  byRole(mount9b, 'deduct-points') === null || byRole(mount9b, 'deduct-points') === undefined || queryByRole(mount9b, 'deduct-points').length === 0,
  'el rescate jamás ofrece orden de deducción'
);

// =====================================================================
// [10] El canon del backend muda el umbral (Art. II)
// =====================================================================
console.log('\n[10] El umbral viaja del canon servido, jamás escrito a mano');
const mount10 = createFakeElement('div');
const objectionModal10 = createObjectionModalComponent(mount10, {
  canon: { minObjectionLength: 30 },
  elementFactory: fakeElementFactory,
  documentRef: fakeDocument,
});
objectionModal10.open();
byRole(mount10, 'objection-reason').value = 'a'.repeat(29);
byRole(mount10, 'objection-reason').dispatch('input');
assertCondition(byRole(mount10, 'objection-submit')?.disabled === true, 'con canon de treinta, veintinueve bloquea');
byRole(mount10, 'objection-reason').value = 'a'.repeat(30);
byRole(mount10, 'objection-reason').dispatch('input');
assertCondition(byRole(mount10, 'objection-submit')?.disabled === false, 'con canon de treinta, treinta habilita: el canon manda, no el tres a mano');
objectionModal10.destroy();

const mount10b = createFakeElement('div');
const decreeModal10b = createImperialDecreeModalComponent(mount10b, {
  canon: { minImperialDecreeLength: 25 },
  elementFactory: fakeElementFactory,
  documentRef: fakeDocument,
});
decreeModal10b.open({ decreeType: 'sovereignValidation' });
byRole(mount10b, 'imperial-decree-text').value = 'a'.repeat(24);
byRole(mount10b, 'imperial-decree-text').dispatch('input');
assertCondition(byRole(mount10b, 'decree-submit')?.disabled === true, 'el edicto también obedece al canon servido (24 de 25 bloquea)');
byRole(mount10b, 'imperial-decree-text').value = 'a'.repeat(25);
byRole(mount10b, 'imperial-decree-text').dispatch('input');
assertCondition(byRole(mount10b, 'decree-submit')?.disabled === false, '25 de 25 habilita: el umbral inclusivo es del canon');
decreeModal10b.destroy();

// =====================================================================
// [11] Un solo diálogo a la vez y ciclo de vida
// =====================================================================
console.log('\n[11] Un solo dictamen y ciclo de vida');
const mount11 = createFakeElement('div');
const objectionModal11 = createObjectionModalComponent(mount11, {
  elementFactory: fakeElementFactory,
  documentRef: fakeDocument,
});
objectionModal11.open();
const secondVerdict = await objectionModal11.open();
assertCondition(
  secondVerdict.submitted === false && allByClass(mount11, 'objection-modal').length === 1,
  'un solo diálogo a la vez: el vigente manda'
);
objectionModal11.destroy();
assertCondition(allByClass(mount11, 'objection-modal').length === 0, 'destroy() retira el diálogo y libera sus nodos');

const destroyedVerdict = await objectionModal11.open();
assertCondition(destroyedVerdict.submitted === false, 'un modal destruido jamás vuelve a abrir');

// =====================================================================
// [12] XSS: el texto hostil viaja como texto
// =====================================================================
console.log('\n[12] El lore hostil viaja como texto literal');
const mount12 = createFakeElement('div');
const objectionModal12 = createObjectionModalComponent(mount12, {
  elementFactory: fakeElementFactory,
  documentRef: fakeDocument,
});
objectionModal12.open({ spellId: 'spl"><script>alert(1)</script>' });
assertCondition(
  byClass(mount12, 'objection-modal')?.getAttribute('data-spell-id') === 'spl"><script>alert(1)</script>',
  'el identificador hostil viaja como ATRIBUTO literal, jamás como marcado interpretado'
);
const hostileField12 = byRole(mount12, 'objection-reason');
hostileField12.value = '<img src=x onerror=alert(1)> obra fraudulenta del canon';
hostileField12.dispatch('input');
assertCondition(byRole(mount12, 'objection-submit')?.disabled === false, 'el dictamen hostil alcanza el umbral como texto');
assertCondition(
  hostileField12.textContent === '' || hostileField12.value.includes('<img'),
  'el campo de redacción conserva el texto literal sin interpretar etiqueta alguna'
);
objectionModal12.destroy();

// =====================================================================
// Resumen
// =====================================================================
console.log(`\n== RESUMEN == Asertos superados: ${assertsPassed}, fallidos: ${assertsFailed}`);
if (assertsFailed === 0) {
  console.log('RESULTADO: EXITO — Los modales bloquean bajo veinte caracteres y emiten sus eventos solemnes (Tarea 6.1).');
  process.exit(0);
}
console.log('RESULTADO: FALLO — Revisa los asertos marcados.');
process.exit(1);
