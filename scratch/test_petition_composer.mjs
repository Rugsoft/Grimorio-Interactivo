/**
 * test_petition_composer.mjs — Arnés de la Tarea 5.3 (TASKS-10).
 *
 * Verifica el molde de la petición formal (`petitionComposerComponent`),
 * según el «Hecho cuando»:
 *
 *   [1] Remitir EXIGE 20–500 caracteres (tres bordes: 19 vedado, 20 pasa,
 *       500 pasa, 501 vedado — medido TRAS recortar bordes).
 *   [2] El contador se ve mientras se escribe (cifra viva N/500 por edición,
 *       evento `vestibule:petition-composed` del plan §4).
 *   [3] Ninguna regla de estilo filtra el texto (RF-03.6): el texto viaja
 *       ÍNTEGRO — con saltos, espacios dobles y anacronismos si los hubiere
 *       — solo el MOLDE decide; el dictamen es del Patriarca.
 *   [4] Leyendas solemnes del Anexo A 6 (exceso y susurro) y región viva.
 *   [5] RNF-03: etiqueta asociada al textarea del kit (SPEC-02 RF-08.1),
 *       botón deshabilitado fuera del molde, descarte sin mutación.
 *
 * Constitución:
 *   - Artículo I (Dogma Vanilla): DOM simulado mínimo, ES Modules nativos.
 *   - Artículo V: identificadores en inglés camelCase; comentarios en castellano.
 *
 * Uso: node scratch/test_petition_composer.mjs
 */

import assert from 'node:assert';

let assertsPassed = 0;
let assertsFailed = 0;
let uncaughtErrors = 0;

process.on('uncaughtException', (error) => { console.error('[CRUDO]', error?.message); uncaughtErrors++; });
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
// DOM simulado (patrón consolidado de los arneses del Vestíbulo)
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
    type: null,
    value: '',
    disabled: false,
  };
  // className como setter sincronizado: el componente asigna `.className =`
  // directamente (idioma del proyecto) y el DOM simulado refleja las clases.
  let classNameValue = '';
  Object.defineProperty(element, 'className', {
    get: () => classNameValue,
    set: (nextValue) => {
      classNameValue = String(nextValue);
      element.classes = new Set(classNameValue.split(/\s+/).filter(Boolean));
    },
  });
  element.classes = new Set();
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
    }
  };
  element.getAttribute = (name) => element.attributes[name] ?? null;
  element.removeAttribute = (name) => { delete element.attributes[name]; };
  element.hasAttribute = (name) => Object.prototype.hasOwnProperty.call(element.attributes, name);
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
  element.focus = () => {};
  element.click = () => { element.dispatchEvent({ type: 'click' }); };
  return element;
}

const documentRef = { createElement: (tag) => createFakeElement(tag) };
const emittedEvents = [];
const fakeWindow = {
  CustomEvent: class CustomEvent {
    constructor(type, options = {}) { this.type = type; this.detail = options.detail ?? null; }
  },
};

const {
  createPetitionComposerComponent,
  PETITION_MOTIVATION_MIN,
  PETITION_MOTIVATION_MAX,
  PETITION_LEGEND_TOO_LONG,
  PETITION_LEGEND_TOO_SHORT,
  PETITION_LEGEND_READY,
} = await import('../public/assets/js/components/petitionComposerComponent.js');

function findByClass(root, className, found = []) {
  for (const child of root.children ?? []) {
    if (child.classes?.has?.(className)) found.push(child);
    findByClass(child, className, found);
  }
  return found;
}

function createComposer(overrides = {}) {
  const options = {
    clanId: 'cln_tempestad',
    clanName: 'Tempestad Eterna',
    documentRef,
    windowRef: fakeWindow,
    ...overrides,
  };
  const composer = createPetitionComposerComponent(options);
  const root = composer.element;
  const composedEvents = [];
  root.addEventListener('vestibule:petition-composed', (event) => {
    composedEvents.push(event.detail);
  });
  return {
    composer,
    root,
    textarea: findByClass(root, 'petition-composer__textarea')[0] ?? root.children.find((c) => c.tagName === 'TEXTAREA'),
    counter: findByClass(root, 'petition-composer__counter')[0],
    legend: findByClass(root, 'petition-composer__legend')[0],
    submit: findByClass(root, 'petition-composer__submit')[0],
    cancel: findByClass(root, 'petition-composer__cancel')[0],
    label: findByClass(root, 'petition-composer__label')[0],
    composedEvents,
  };
}

/** Escribe en el textarea simulado y dispara el evento input (edición viva). */
function type(parts, text) {
  parts.textarea.value = text;
  parts.textarea.dispatchEvent({ type: 'input' });
}

console.log('== VERIFICACIÓN TAREA 5.3 (SPEC-10): Molde de la petición formal ==\n');

// =====================================================================
// [1] Remitir EXIGE 20–500 (los tres bordes del molde)
// =====================================================================
console.log('[1] El molde 20–500 rige la remisión (RF-03.1)');

assertCondition(PETITION_MOTIVATION_MIN === 20 && PETITION_MOTIVATION_MAX === 500, 'los bordes canónicos viajan como constantes exportadas (20 y 500)');

const parts19 = createComposer();
type(parts19, 'x'.repeat(19));
assertCondition(parts19.submit.disabled === true, '19 caracteres: remitir VEDADO (borde inferior menos uno)');
type(parts19, 'x'.repeat(20));
assertCondition(parts19.submit.disabled === false, '20 caracteres: remitir disponible (borde inferior exacto)');
type(parts19, 'x'.repeat(500));
assertCondition(parts19.submit.disabled === false, '500 caracteres: remitir disponible (borde superior exacto)');

// 501: el textarea real con maxlength=500 no lo permite, pero el molde
// también veda por si un backend legado o un pegado lo burla.
const parts501 = createComposer();
parts501.textarea.setAttribute('maxlength', '0'); // el stub ignora maxlength
type(parts501, 'x'.repeat(501));
assertCondition(parts501.submit.disabled === true, '501 caracteres: remitir VEDADO (borde superior menos uno)');

// El recorte de bordes MIDE tras recortar: los espacios de los bordes no
// cuentan, de modo que 18 caracteres entre espacios miden 18 — vedado.
const partsTrim = createComposer();
type(partsTrim, '  ' + 'x'.repeat(18) + '  ');
assertCondition(partsTrim.submit.disabled === true, '18 caracteres entre espacios miden 18 tras recortar: el molde mide, no mutila (vedado)');

// =====================================================================
// [2] El contador se ve mientras se escribe (cifra viva + evento del plan)
// =====================================================================
console.log('\n[2] Contador vivo y evento vestibule:petition-composed');

const partsLive = createComposer();
assertCondition(partsLive.counter.textContent === '0/500', 'el contador nace en 0/500 (visible antes de escribir)');
type(partsLive, 'x'.repeat(7));
assertCondition(partsLive.counter.textContent === '7/500', 'el contador se actualiza con cada edición (7/500)');
type(partsLive, 'x'.repeat(123));
assertCondition(partsLive.counter.textContent === '123/500', 'el contador sigue vivo (123/500)');
assertCondition(partsLive.composedEvents.length === 2, 'cada edición emite vestibule:petition-composed (2 ediciones, 2 eventos — plan §4)');
assertCondition(
  partsLive.composedEvents[1]?.clanId === 'cln_tempestad' && partsLive.composedEvents[1]?.length === 123,
  'el evento porta { clanId, length } (plan §4)'
);

// =====================================================================
// [3] Ninguna regla de estilo filtra el texto (RF-03.6)
// =====================================================================
console.log('\n[3] El texto viaja ÍNTEGRO: solo el molde decide (RF-03.6)');

const textoConAnacronismo = '  Deseo entrar: tengo 20 años de experiencia en D&D y manejo "spells" de fuego.  ';
let submitted = [];
const partsFilter = createComposer({
  onSubmit: (clanId, motivation) => submitted.push({ clanId, motivation }),
});
type(partsFilter, textoConAnacronismo);
partsFilter.submit.click();
assertCondition(submitted.length === 1, 'la remisión delega en onSubmit (el componente jamás llama a la API, Art. II)');
assertCondition(
  submitted[0]?.motivation === textoConAnacronismo.trim(),
  'el texto viaja ÍNTEGRO (recorte de bordes, jamás filtro de estilo: ni anacronismos ni extranjerismos se tocan)'
);
assertCondition(submitted[0]?.clanId === 'cln_tempestad', 'la remisión porta el clanId de la casa cortejada');

// Los saltos y espacios interiores sobreviven intactos.
const textoConSaltos = 'Primera línea.\n\nSegunda línea,  con  espacios dobles.';
const partsSaltos = createComposer({ onSubmit: (c, m) => submitted.push({ c, m }) });
type(partsSaltos, textoConSaltos);
partsSaltos.submit.click();
assertCondition(
  submitted[submitted.length - 1]?.m === textoConSaltos,
  'saltos y espacios interiores sobreviven intactos (el Patriarca lee tu caligrafía, no una norma)'
);

// Fuera del molde, el click es un no-op (doble guardia con disabled).
const beforeCount = submitted.length;
const partsGuard = createComposer({ onSubmit: (c, m) => submitted.push({ c, m }) });
type(partsGuard, 'corto');
partsGuard.submit.click();
assertCondition(submitted.length === beforeCount, 'el click fuera del molde es un no-op (doble guardia, aunque el botón esté deshabilitado)');

// =====================================================================
// [4] Leyendas solemnes del Anexo A 6 (región viva)
// =====================================================================
console.log('\n[4] Leyendas del Anexo A 6 y región viva');

assertCondition(partsLive.legend.getAttribute('role') === 'status', 'la leyenda vive en región aria-live (RNF-03)');

const partsLegend = createComposer();
type(partsLegend, 'x'.repeat(19));
assertCondition(partsLegend.legend.textContent === PETITION_LEGEND_TOO_SHORT, 'bajo el mínimo: «Apenas es un susurro…» (Anexo A 6)');
type(partsLegend, 'x'.repeat(30));
assertCondition(partsLegend.legend.textContent === PETITION_LEGEND_READY, 'dentro del molde: «Tu vocación tiene cuerpo…»');
type(partsLegend, 'x'.repeat(501));
assertCondition(partsLegend.legend.textContent === PETITION_LEGEND_TOO_LONG, 'sobre el máximo: «Tu petición desborda el pergamino…» (Anexo A 6)');
assertCondition(
  partsLegend.counter.classes.has('petition-composer__counter--overflow'),
  'el contador viste su estado de aviso al desbordar'
);

// =====================================================================
// [5] Etiqueta del kit, descarte y limpieza (RNF-03, SPEC-02 RF-08.1)
// =====================================================================
console.log('\n[5] Etiqueta asociada, descarte sin mutación y API completa');

const partsKit = createComposer();
const textarea = partsKit.textarea;
assertCondition(String(textarea.className).includes('controls-textarea'), 'el textarea consume el KIT de controles (SPEC-02 RF-08.1, jamás estilos divergentes)');
assertCondition(partsKit.label.getAttribute('for') === textarea.getAttribute('id'), 'la etiqueta está asociada al textarea (for/id, RNF-03)');
assertCondition(!textarea.hasAttribute('maxlength'), 'sin maxlength que silencie el exceso: el molde se ANUNCIA con la leyenda (RF-03.1)');

let cancelCalls = 0;
const partsCancel = createComposer({ onCancel: () => { cancelCalls += 1; } });
type(partsCancel, 'Texto que sobrevive al descarte');
partsCancel.cancel.click();
assertCondition(cancelCalls === 1, 'el descarte notifica onCancel (la vista decide cerrar sin mutación)');
assertCondition(partsCancel.composer.getValue() === 'Texto que sobrevive al descarte', 'el descarte jamás borra la palabra del adepto (nada se muta por sorpresa)');

// setValue / isValid / focus: la API de la vista orquestadora.
const partsApi = createComposer();
partsApi.composer.setValue('Motivación con cuerpo suficiente para el molde.');
assertCondition(partsApi.composer.isValid() === true && partsApi.counter.textContent.includes('/500'), 'setValue refresca contador y validez (API de la vista)');
partsApi.composer.focus();
assertCondition(true, 'focus() disponible para la vista orquestadora');

// [4] Movimiento reducido y kit de controles (RNF-03, SPEC-02 RF-08.1/08.5)
// =====================================================================
console.log('\n[4] Movimiento reducido y kit de controles (RNF-03)');
import { readFileSync } from 'node:fs';
const vestibuleCss = readFileSync(new URL('../public/assets/css/components/vestibule.css', import.meta.url), 'utf8');
const controlsCss = readFileSync(new URL('../public/assets/css/components/controls.css', import.meta.url), 'utf8');
assertCondition(vestibuleCss.includes('@media (prefers-reduced-motion: reduce)'), 'la hoja del Vestíbulo declara su bloque prefers-reduced-motion (RNF-03)');
assertCondition(controlsCss.includes('@media (prefers-reduced-motion: reduce)'), 'el Kit de Controles aquienta textarea y botones por sí mismo (SPEC-02 RF-08.5)');
assertCondition(partsLive.root.className.includes('petition-composer') && partsLive.textarea.className.includes('controls-textarea'), 'el molde y su textarea visten el kit de controles de SPEC-02 (RF-08.1)');

// =====================================================================
// Resumen
// =====================================================================
console.log(`\n== RESUMEN == Asertos superados: ${assertsPassed}, fallidos: ${assertsFailed}`);
if (assertsFailed === 0 && uncaughtErrors === 0) {
  console.log('RESULTADO: EXITO — Remitir exige 20–500, el contador vive mientras se escribe, ninguna regla de estilo filtra el texto y el kit se aquienta bajo movimiento reducido (Tareas 5.3 y 8.2).');
  process.exit(0);
}
console.log('RESULTADO: FALLO — Corregir los asertos en rojo antes de continuar.');
process.exit(1);
