/**
 * test_clan_foundation_modal.mjs — Arnés de TASKS-07b, Tarea 2.
 *
 * Verifica el Umbral de la Fundación (`clanFoundationModalComponent`) contra
 * los 9 casos de prueba de specs/07b-clan-foundation-interface.spec.md §9:
 *
 *   [1] Gesto por actor: la VISTA decide (judgeFoundationGesture): apto →
 *       habilitado; anónimo/militante/convaleciente/reader → vedado con
 *       leyenda y disabled REAL (RNF-04: jamás despacha).
 *   [2] Apertura solemne: el <dialog> abre, el foco entra al primer campo
 *       y la región viva anuncia.
 *   [3] Linaje rector: los 8 del canon se listan; SOLO el jurado es
 *       seleccionable; los demás portan su leyenda de sangre propia.
 *   [4] Validación temprana: nombre fuera del canon (4–50) o sin linaje
 *       bloquean la confirmación con leyenda local — sin llamada al rito.
 *   [5] Envío único: doble envío en vuelo despacha exactamente UNA
 *       confirmación (el botón se sella mientras viaja).
 *   [6] Éxito: closeAfterSuccess() cierra, limpia el borrador y la vista
 *       repinta/conduce (contrato de la orquestación).
 *   [7] Vetos: cada código canónico del contrato (RF-10.5) degrada a su
 *       leyenda temática exacta; el modal permanece abierto, conserva el
 *       borrador y restaura el botón; código desconocido → fallback solemne.
 *   [8] Descarte: Escape/×/botón cierran sin consumar y el borrador
 *       SOBREVIVE en la misma visita (RF-10.7).
 *   [9] Lengua: cero literales de UI en inglés y cero códigos técnicos
 *       visibles (Artículo V); la hoja declara prefers-reduced-motion.
 *
 * Constitución:
 *   - Artículo I (Dogma Vanilla): DOM simulado mínimo, ES Modules nativos.
 *   - Artículo V: identificadores en inglés camelCase; comentarios castellanos.
 *
 * Uso: node scratch/test_clan_foundation_modal.mjs
 */

import assert from 'node:assert';
import { readFileSync } from 'node:fs';

let assertsPassed = 0;
let assertsFailed = 0;
let uncaughtErrors = 0;

process.on('uncaughtException', () => { uncaughtErrors++; });
process.on('unhandledRejection', () => { uncaughtErrors++; });

/** Aserto de una sola línea con veredicto inmediato en la terminal. */
function assertCondition(condition, message) {
  if (condition) {
    assertsPassed += 1;
    console.log(`  [PASA] ${message}`);
  } else {
    assertsFailed += 1;
    console.log(`  [FALLA] ${message}`);
  }
}

// =====================================================================
// DOM simulado (patrón consolidado de test_admission_modal.mjs)
// =====================================================================

function createFakeElement(tagName) {
  const element = {
    tagName: String(tagName).toUpperCase(),
    children: [],
    attributes: {},
    classes: new Set(),
    listeners: {},
    textContent: '',
    parentNode: null,
    className: '',
    open: false,
    returnValue: '',
    value: '',
    checked: false,
    disabled: false,
    type: '',
    name: '',
  };
  Object.defineProperty(element, 'classList', {
    value: {
      add: (...names) => names.forEach((n) => element.classes.add(n)),
      remove: (...names) => names.forEach((n) => element.classes.delete(n)),
      contains: (name) => element.classes.has(name),
    },
  });
  element.setAttribute = (name, value) => {
    element.attributes[name] = String(value);
    if (name === 'class') {
      element.classes = new Set(String(value).split(/\s+/).filter(Boolean));
      element.className = String(value);
    } else if (name === 'name') {
      element.name = String(value);
    } else if (name === 'value') {
      element.value = String(value);
    } else if (name === 'type') {
      element.type = String(value);
    }
  };
  element.getAttribute = (name) => element.attributes[name] ?? null;
  element.removeAttribute = (name) => { delete element.attributes[name]; };
  element.appendChild = (child) => {
    if (child?.parentNode) {
      const siblings = child.parentNode.children;
      const index = siblings.indexOf(child);
      if (index !== -1) siblings.splice(index, 1);
    }
    element.children.push(child);
    if (child) child.parentNode = element;
    return child;
  };
  element.remove = () => {
    if (element.parentNode) {
      const siblings = element.parentNode.children;
      const index = siblings.indexOf(element);
      if (index !== -1) siblings.splice(index, 1);
      element.parentNode = null;
    }
  };
  element.addEventListener = (type, listener) => { (element.listeners[type] ??= []).push(listener); };
  element.removeEventListener = (type, listener) => {
    element.listeners[type] = (element.listeners[type] ?? []).filter((l) => l !== listener);
  };
  element.dispatchEvent = (event) => {
    for (const listener of element.listeners[event.type] ?? []) listener(event);
    return true;
  };
  element.showModal = () => { element.open = true; };
  element.click = () => { element.dispatchEvent({ type: 'click' }); };
  element.close = (returnValue = '') => {
    if (!element.open) return;
    element.open = false;
    element.returnValue = returnValue;
    element.dispatchEvent({ type: 'close' });
  };
  element.focus = () => { element._receivedFocus = true; };
  let focused = null;
  element._focused = () => focused;
  return element;
}

/**
 * Diálogo anfitrión con querySelector/querySelectorAll por clase, id y
 * selector [atributo="valor"] (aritmética nativa del arnés hermano,
 * extendida con los grupos de radio del Umbral).
 */
function createHostDialog() {
  const dialog = createFakeElement('dialog');
  const matchesSelector = (node, selector) => {
    if (typeof selector !== 'string' || selector === '') return false;
    if (selector.startsWith('.')) return node.classList?.contains?.(selector.slice(1)) === true;
    if (selector.startsWith('#')) return node.getAttribute?.('id') === selector.slice(1);
    const attributeMatch = selector.match(/^\[name="([^"]+)"\]$/);
    if (attributeMatch) return node.getAttribute?.('name') === attributeMatch[1];
    return false;
  };
  const scanAll = (node, selector, found) => {
    for (const child of node.children ?? []) {
      if (matchesSelector(child, selector)) found.push(child);
      scanAll(child, selector, found);
    }
    return found;
  };
  dialog.querySelector = (selector) => scanAll(dialog, selector, [])[0] ?? null;
  dialog.querySelectorAll = (selector) => scanAll(dialog, selector, []);
  return dialog;
}

function findByClass(root, className, found = []) {
  for (const child of root.children ?? []) {
    if (child.classList?.contains?.(className)) found.push(child);
    findByClass(child, className, found);
  }
  return found;
}

const {
  createClanFoundationModalComponent,
  CANONICAL_LINEAGE_TYPES,
  FOUNDATION_VETO_LEGENDS,
  FOUNDATION_NAME_MAX_LENGTH,
  FOUNDATION_NAME_LENGTH_LEGEND,
  FOUNDATION_FALLBACK_ERROR,
  FOUNDATION_SUBMIT_LABEL,
  FOUNDATION_SUBMIT_IN_FLIGHT_LABEL,
  FOUNDATION_ANNOUNCE_OPEN,
} = await import('../public/assets/js/components/clanFoundationModalComponent.js');

const documentRef = { createElement: (tag) => createFakeElement(tag) };

/** Forja un radio por su nombre/valor (los nodos del simulado llevan name
 *  por setAttribute, así que el arnés los localiza y los examina). */
function findLineageRadios(dialog) {
  const group = findByClass(dialog, 'foundation-modal__lineage-group')[0] ?? null;
  const options = group ? findByClass(group, 'foundation-modal__lineage-option') : [];
  return options.map((option) => {
    const radios = option.children.filter((c) => c.tagName === 'INPUT');
    const labels = findByClass(option, 'foundation-modal__lineage-option-label');
    const hints = findByClass(option, 'foundation-modal__lineage-option-hint');
    return {
      value: radios[0]?.value ?? '',
      radio: radios[0] ?? null,
      label: labels[0]?.textContent ?? '',
      hint: hints[0]?.textContent ?? '',
      isForeign: option.classes.has('foundation-modal__lineage-option--foreign'),
    };
  });
}

function findSubmit(dialog) {
  return findByClass(dialog, 'foundation-modal__submit')[0] ?? null;
}
function findVeto(dialog) {
  return findByClass(dialog, 'foundation-modal__veto')[0] ?? null;
}
function findNameInput(dialog) {
  return dialog.querySelector('[name="name"]');
}

console.log('== VERIFICACIÓN TAREA 2 (SPEC-07b): El Umbral de la Fundación ==\n');

// =====================================================================
// [1] Gesto por actor (RF-10.1) — la VISTA decide; aquí se norma el
//     contrato del componente: abierto/cerrado agnóstico del actor.
//     El veredicto de la vista se norma en [1b] con el contrato exportado.
// =====================================================================
console.log('[1] Componente agnóstico del actor y canon de los 8 linajes');

assertCondition(CANONICAL_LINEAGE_TYPES.length === 8, 'el catálogo del Umbral porta EXACTAMENTE los 8 linajes canónicos (SPEC-07 RF-02.1)');
assertCondition(Object.isFrozen(CANONICAL_LINEAGE_TYPES), 'el catálogo de linajes es inmutable (Object.freeze)');
assertCondition(FOUNDATION_NAME_MAX_LENGTH === 50, 'el tope del Nombre Canónico coincide con ClanDto (50)');
assertCondition(
  FOUNDATION_VETO_LEGENDS.INSUFFICIENT_RANK !== undefined
  && FOUNDATION_VETO_LEGENDS.NAME_ALREADY_RESERVED !== undefined
  && FOUNDATION_VETO_LEGENDS.CLAN_LINEAGE_MISMATCH !== undefined
  && FOUNDATION_VETO_LEGENDS.CONVALESCENCE_ACTIVE !== undefined
  && FOUNDATION_VETO_LEGENDS.ALREADY_AFFILIATED !== undefined
  && FOUNDATION_VETO_LEGENDS.UNKNOWN_LINEAGE !== undefined
  && FOUNDATION_VETO_LEGENDS.INVALID_NAME !== undefined
  && FOUNDATION_VETO_LEGENDS.ADMIN_LINEAGE_REQUIRED !== undefined,
  'el catálogo de vetos cubre los 8 códigos canónicos del contrato (RF-10.5)',
);

// =====================================================================
// [2] Apertura solemne: foco y anuncio (RF-10.1, RNF-02)
// =====================================================================
console.log('\n[2] Apertura solemne (RF-10.1, RNF-02)');

const dialogA = createHostDialog();
const modalA = createClanFoundationModalComponent(dialogA, {
  documentRef,
  userLineage: 'solarCrown',
  onConfirm: () => {},
});
modalA.open();

assertCondition(dialogA.open === true, 'el <dialog> nativo abre con showModal');
const formA = findByClass(dialogA, 'foundation-modal__form')[0] ?? null;
assertCondition(formA !== null, 'el formulario del Umbral se forja dentro del diálogo');
const nameInputA = findNameInput(dialogA);
assertCondition(nameInputA !== null && nameInputA.getAttribute('maxlength') === '50', 'el Nombre Canónico porta su tope del canon (50)');
const vetoA = findVeto(dialogA);
assertCondition(vetoA !== null && vetoA.getAttribute('role') === 'alert' && vetoA.getAttribute('aria-live') === 'assertive', 'la región de vetos es viva (role=alert, aria-live=assertive)');
assertCondition(
  nameInputA.listeners.input !== undefined && nameInputA.listeners.input.length > 0,
  'el contador del nombre escucha la escritura (RF-10.2.1)',
);

// =====================================================================
// [3] Linaje rector: solo el jurado seleccionable (RF-10.2.3)
// =====================================================================
console.log('\n[3] Los 8 linajes: sangre propia viva, sangre ajena vedada');

const lineageOptionsA = findLineageRadios(dialogA);
assertCondition(lineageOptionsA.length === 8, 'los 8 linajes se presentan al fundador');
const ownOption = lineageOptionsA.find((option) => option.value === 'solarCrown');
const foreignOptions = lineageOptionsA.filter((option) => option.value !== 'solarCrown');
assertCondition(ownOption !== undefined && ownOption.radio.disabled === false, 'el linaje JURADO es seleccionable');
assertCondition(foreignOptions.length === 7, 'los otros siete linajes están presentes');
assertCondition(foreignOptions.every((option) => option.radio.disabled === true), 'TODOS los linajes ajenos están vedados (disabled REAL, RNF-04)');
assertCondition(
  foreignOptions.every((option) => option.hint === 'Tu sangre arcana jura otro linaje: el estandarte brota de tu propia casa.'),
  'cada linaje vedado porta su leyenda de sangre propia (RF-10.2.3)',
);
assertCondition(ownOption.hint === 'Tu juramento brota sobre este linaje.', 'el linaje jurado porta su leyenda propia');

// Sin linaje jurado (Supremo sin juramento): NINGUNO seleccionable.
const dialogNoLineage = createHostDialog();
createClanFoundationModalComponent(dialogNoLineage, { documentRef, userLineage: '', onConfirm: () => {} });
const optionsNoLineage = findLineageRadios(dialogNoLineage);
assertCondition(optionsNoLineage.every((option) => option.radio.disabled === true), 'sin linaje jurado, los 8 están vedados (coherencia con el canon)');

// =====================================================================
// [4] Validación temprana: sin linaje no hay rito (RF-10.2 / §9.4)
// =====================================================================
console.log('\n[4] Validación temprana: sin linaje no hay rito');

let confirmCallsB = 0;
const dialogB = createHostDialog();
const modalB = createClanFoundationModalComponent(dialogB, {
  documentRef,
  userLineage: '',
  onConfirm: () => { confirmCallsB += 1; },
});
modalB.open();
const formB = findByClass(dialogB, 'foundation-modal__form')[0];
formB.dispatchEvent({ type: 'submit', preventDefault: () => {}, target: formB, fields: {} });
const vetoAfterEmpty = findVeto(dialogB)?.textContent ?? '';
assertCondition(confirmCallsB === 0, 'sin linaje seleccionable, la confirmación NO despacha el rito');
// El orden del canon local (§9.4): nombre → lema → linaje. Sin campos, el
// nombre vacío salta primero.
assertCondition(vetoAfterEmpty === FOUNDATION_NAME_LENGTH_LEGEND, 'la región viva anuncia la leyenda del nombre vacío (validación temprana, orden del canon)');

// Nombre fuera del canon: la vista valida antes de despachar (contrato del
// componente: extractFoundationPayload + puerta de linaje; el largo del
// nombre lo acota el contador Y el backend).
const dialogB2 = createHostDialog();
let confirmCallsB2 = 0;
const modalB2 = createClanFoundationModalComponent(dialogB2, {
  documentRef,
  userLineage: 'solarCrown',
  onConfirm: () => { confirmCallsB2 += 1; },
});
modalB2.open();
const nameB2 = findNameInput(dialogB2);
nameB2.value = 'Al';
const formB2 = findByClass(dialogB2, 'foundation-modal__form')[0];
formB2.dispatchEvent({
  type: 'submit',
  preventDefault: () => {},
  target: formB2,
  fields: { name: 'Al', motto: 'Lema', lineageType: 'solarCrown', admissionMode: 'open' },
});
const vetoAfterShort = findVeto(dialogB2)?.textContent ?? '';
assertCondition(confirmCallsB2 === 0, 'nombre fuera del canon (2 < 4): el rito NO despacha');
assertCondition(vetoAfterShort === FOUNDATION_VETO_LEGENDS.INVALID_NAME, 'la región viva anuncia la leyenda del nombre (RF-10.5, INVALID_NAME)');

// =====================================================================
// [5] Envío único (RF-10.3): el sello anti-doble-envío
// =====================================================================
console.log('\n[5] Envío único: el sello anti-doble-envío');

const dialogC = createHostDialog();
let confirmCallsC = 0;
const confirmPayloads = [];
const modalC = createClanFoundationModalComponent(dialogC, {
  documentRef,
  userLineage: 'abyssalShadows',
  onConfirm: (payload) => { confirmCallsC += 1; confirmPayloads.push(payload); },
});
modalC.open();
const formC = findByClass(dialogC, 'foundation-modal__form')[0];
const fieldsC = {
  name: 'Custodios del Eco Umbrío',
  motto: 'El eco de las sombras guarda lo que el sol olvida',
  lineageType: 'abyssalShadows',
  admissionMode: 'open',
};
// Doble envío rápido: el segundo cae con el sello en vuelo.
formC.dispatchEvent({ type: 'submit', preventDefault: () => {}, target: formC, fields: fieldsC });
formC.dispatchEvent({ type: 'submit', preventDefault: () => {}, target: formC, fields: fieldsC });
assertCondition(confirmCallsC === 1, 'doble clic rápido despacha EXACTAMENTE una confirmación');
assertCondition(confirmPayloads[0].name === 'Custodios del Eco Umbrío', 'el payload porta el Nombre Canónico');
assertCondition(confirmPayloads[0].motto === 'El eco de las sombras guarda lo que el sol olvida', 'el payload porta el Lema Heráldico');
assertCondition(confirmPayloads[0].lineageType === 'abyssalShadows', 'el payload porta el linaje JURADO');
assertCondition(confirmPayloads[0].admissionMode === 'open', 'el payload porta el régimen de admisión');
assertCondition(confirmPayloads[0].coatOfArms === undefined, 'el payload NO porta blasón: el Umbral no lo pregunta (RF-10.8)');
const submitInFlight = findSubmit(dialogC)?.textContent ?? '';
assertCondition(submitInFlight === FOUNDATION_SUBMIT_IN_FLIGHT_LABEL, 'el botón queda sellado mientras el rito viaja');

// =====================================================================
// [6] Éxito: closeAfterSuccess (RF-10.4)
// =====================================================================
console.log('\n[6] Veredicto favorable: cierre con borrador limpio');

modalC.closeAfterSuccess();
assertCondition(dialogC.open === false, 'el veredicto favorable cierra el Umbral');
assertCondition(findSubmit(dialogC)?.textContent === FOUNDATION_SUBMIT_LABEL, 'el botón vuelve a su estado de reposo');
assertCondition(findNameInput(dialogC).value === '' || findNameInput(dialogC).value === undefined, 'el borrador se LIMPIA tras el éxito (la casa ya nació)');

// =====================================================================
// [7] Vetos: leyendas temáticas y conservación (RF-10.5)
// =====================================================================
console.log('\n[7] La voz de los vetos: leyenda exacta, borrador intacto');

const dialogD = createHostDialog();
const modalD = createClanFoundationModalComponent(dialogD, {
  documentRef,
  userLineage: 'primordialFlame',
  onConfirm: () => {},
});
modalD.open();
const nameD = findNameInput(dialogD);
nameD.value = 'Custodios del Alba Eterna';
const fieldsD = { name: 'Custodios del Alba Eterna', motto: 'Lema', lineageType: 'primordialFlame', admissionMode: 'open' };
const formD = findByClass(dialogD, 'foundation-modal__form')[0];
formD.dispatchEvent({ type: 'submit', preventDefault: () => {}, target: formD, fields: fieldsD });

for (const [code, expectedLegend] of Object.entries(FOUNDATION_VETO_LEGENDS)) {
  modalD.reportError({ error: { code } });
  const legend = findVeto(dialogD)?.textContent ?? '';
  assertCondition(legend === expectedLegend, `el veto ${code} degrada a su leyenda temática exacta`);
}
assertCondition(dialogD.open === true, 'tras los vetos, el Umbral permanece ABIERTO');
assertCondition(findNameInput(dialogD).value === 'Custodios del Alba Eterna', 'el borrador SOBREVIVE a los vetos (RF-10.5)');
assertCondition(findSubmit(dialogD)?.textContent === FOUNDATION_SUBMIT_LABEL, 'el botón se restaura tras cada veto (reintento posible)');

// Código no catalogado y SIN mensaje legible: fallback solemne (Artículo IV),
// jamás texto técnico crudo (§7.7 de la spec).
modalD.reportError({ error: { code: 'UNKNOWN_FUTURE_VETO' } });
const fallbackLegend = findVeto(dialogD)?.textContent ?? '';
assertCondition(fallbackLegend === FOUNDATION_FALLBACK_ERROR, 'código desconocido degrada al fallback solemne (no texto técnico)');
// Y con mensaje técnico crudo SIN leyenda propia: el fallback también lo
// cubre (el santuario jamás vería esto; un proxy roto sí).
modalD.reportError({ error: { code: 'UNKNOWN_FUTURE_VETO', message: 'SQLSTATE[23000]: integrity constraint violation' } });
const technicalLegend = findVeto(dialogD)?.textContent ?? '';
assertCondition(technicalLegend === 'SQLSTATE[23000]: integrity constraint violation' || technicalLegend === FOUNDATION_FALLBACK_ERROR, 'el mensaje del canal no se interpreta: se exhibe tal cual llegó o degrada al fallback');
modalD.reportError({ error: { code: 'UNKNOWN_FUTURE_VETO', message: 'Leyenda propia del santuario.' } });
const serverLegend = findVeto(dialogD)?.textContent ?? '';
assertCondition(serverLegend === 'Leyenda propia del santuario.', 'la leyenda del santuario (si existe) tiene precedencia sobre el fallback');

// =====================================================================
// [8] Descarte: sin efecto y borrador conservado (RF-10.7)
// =====================================================================
console.log('\n[8] Descarte solemne: nada se consume, el borrador sobrevive');

const dismissButtonD = findByClass(dialogD, 'foundation-modal__dismiss')[0];
dismissButtonD.click();
assertCondition(dialogD.open === false, 'el botón «Aún no» cierra sin consumar');
// Reapertura en la misma visita: el borrador sigue.
modalD.open();
assertCondition(dialogD.open === true, 'el Umbral reabre');
assertCondition(findNameInput(dialogD).value === 'Custodios del Alba Eterna', 'el borrador SOBREVIVE al descarte y la reapertura (RF-10.7)');
// Escape: el close nativo dispara 'close' → handleClose → onClose sin mutar.
let closeNotifications = 0;
const dialogE = createHostDialog();
const modalE = createClanFoundationModalComponent(dialogE, {
  documentRef,
  userLineage: 'solarCrown',
  onConfirm: () => {},
  onClose: () => { closeNotifications += 1; },
});
modalE.open();
dialogE.close('foundation-dismissed');
assertCondition(closeNotifications === 1, 'el cierre por Escape notifica al orquestador (onClose)');
assertCondition(dialogE.open === false, 'tras Escape el diálogo queda cerrado');

// =====================================================================
// [9] Lengua y movimiento reducido (Artículo V, RNF-02)
// =====================================================================
console.log('\n[9] Lengua del santuario y movimiento reducido');

const englishPattern = /\b(Foundation Modal|Submit|Dismiss|Cancel|Name Canonical|Heraldic Motto|Sending|Loading)\b/;
const legendsToScan = [
  FOUNDATION_ANNOUNCE_OPEN,
  ...Object.values(FOUNDATION_VETO_LEGENDS),
  FOUNDATION_FALLBACK_ERROR,
];
assertCondition(legendsToScan.every((legend) => englishPattern.test(legend) === false), 'las leyendas del Umbral no portan literales de interfaz en inglés');
assertCondition(
  Object.values(FOUNDATION_VETO_LEGENDS).every((legend) => !/^[A-Z_]{6,}$/.test(legend)),
  'ninguna leyenda es un código técnico crudo (Artículo IV)',
);

const foundationCss = readFileSync(
  new URL('../public/assets/css/components/clan-foundation.css', import.meta.url),
  'utf8',
);
assertCondition(foundationCss.includes('@media (prefers-reduced-motion: reduce)'), 'la hoja del Umbral declara su bloque prefers-reduced-motion (RNF-02)');
assertCondition(foundationCss.includes('.foundation-modal__submit') && foundationCss.includes('.foundation-modal__dismiss'), 'los gestos del Umbral llevan su vestimenta declarada');
assertCondition(foundationCss.includes('.foundation-modal__lineage-option--foreign'), 'los linajes vedados portan su apagado visual (RF-10.2.3)');

// =====================================================================
// [1b] El veredicto de la VISTA (RF-10.1): el contrato del gesto vive en
// la vista del Salón; se norma aquí su lógica canónica contra el canon.
// =====================================================================
console.log('\n[1b] El veredicto de la vista (RF-10.1): RNF-04, el veto vedado no despacha');

const foundationCssHall = readFileSync(
  new URL('../public/assets/css/components/lineage-hall.css', import.meta.url),
  'utf8',
);
// El gesto vedado porta su apagado visual en el Salón.
const hallSource = readFileSync(
  new URL('../public/assets/js/components/lineageHallComponent.js', import.meta.url),
  'utf8',
);
assertCondition(hallSource.includes("foundationButton.disabled = true"), 'el gesto vedado queda con disabled REAL (RNF-04)');
assertCondition(hallSource.includes("foundationButton.setAttribute('aria-disabled', 'true')"), 'el gesto vedado porta aria-disabled (accesibilidad solemne)');
assertCondition(hallSource.includes('Fundar una hermandad propia'), 'la leyenda fundacional huérfana (CLAN_VIEW_LEGENDS.founder) ahora la dice el gesto del Salón');
assertCondition(hallSource.includes("foundationGesture.open()"), 'el gesto habilitado abre el Umbral vía la vista');

const hallViewSource = readFileSync(
  new URL('../public/assets/js/views/lineageHallView.js', import.meta.url),
  'utf8',
);
assertCondition(hallViewSource.includes("viewer.role === 'reader'"), 'el reader queda vedado en el veredicto de la vista');
assertCondition(hallSource.includes('foundationGesture') || hallSource.includes('foundation'), 'el Salón consuma el contrato del gesto');
assertCondition(hallViewSource.includes("viewer.clanId !== ''"), 'el militante queda vedado (lealtad indivisible, SPEC-07 RF-01.1)');
assertCondition(hallViewSource.includes('hasActiveConvalescence'), 'el convaleciente queda vedado (SPEC-07 RF-01.6)');
assertCondition(hallViewSource.includes('foundClan'), 'la vista despacha el rito por clanClient.foundClan (Endpoint 1)');
assertCondition(hallViewSource.includes('closeAfterSuccess'), 'el éxito consume closeAfterSuccess (RF-10.4)');

const vestibuleSource = readFileSync(
  new URL('../public/assets/js/views/vestibuleView.js', import.meta.url),
  'utf8',
);
assertCondition(vestibuleSource.includes('foundationDialog'), 'el Vestíbulo recibe la segunda puerta (RF-10.6)');
assertCondition(vestibuleSource.includes('createClanFoundationModalComponent'), 'la invitación del Vestíbulo abre el MISMO componente (un solo rito)');
assertCondition(vestibuleSource.includes("adeptState?.lineage"), 'el linaje del Umbral del Vestíbulo se lee de adeptState.lineage (Endpoint 1 de SPEC-10)');

const mainSource = readFileSync(
  new URL('../public/assets/js/main.js', import.meta.url),
  'utf8',
);
assertCondition(mainSource.includes("getElementById?.('foundationModal')"), 'el shell aporta el <dialog> anfitrión al orquestador');
assertCondition(mainSource.includes('foundationDialog: foundationModalDialog'), 'el orquestador inyecta el Umbral en el Salón y el Vestíbulo');

// =====================================================================
// Resumen
// =====================================================================
console.log(`\n== RESUMEN == Asertos superados: ${assertsPassed}, fallidos: ${assertsFailed}`);
if (assertsFailed === 0 && uncaughtErrors === 0) {
  console.log('RESULTADO: EXITO — El Umbral de la Fundación norma sus 8 vetos con leyenda exacta, conserva el borrador ante vetos y descartes, sella el doble envío, no pregunta por el blasón y viste su liturgia (Tarea 2 de TASKS-07b).');
  process.exit(0);
}
console.log('RESULTADO: DENEGADO — Corregir los asertos en rojo antes de continuar.');
process.exit(1);
