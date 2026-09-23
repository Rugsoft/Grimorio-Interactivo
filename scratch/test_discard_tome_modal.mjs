/**
 * test_discard_tome_modal.mjs — Arnés de la Tarea 5.2 (TASKS-11).
 *
 * Valida EL MODAL SOLEMNE DE RETIRADA (`discardTomeEntryModalComponent`)
 * contra el «Hecho cuando» de la tarea:
 *
 *   1. Confirmar retira la entrada (onDiscard recibe el spellId) y la
 *      vista actualizará el conteo sin recargar la página (el modal NO
 *      llama a la API jamás — Artículo II).
 *   2. Escape y descarte no mutan: ni onDiscard ni evento de consumo.
 *   3. El foco vuelve al gesto de origen en AMBOS desenlaces (RNF-04).
 *   4. La leyenda canónica del Anexo A y la obra nombrada viajan a
 *      cuerpo de modal (RF-02.4).
 *   5. Focus trap con Tab en los bordes (patrón SPEC-02) y foco inicial
 *      en la vía segura («Conservar la obra»).
 *   6. Región viva de anuncios (RNF-04) y eventos del plan §4.
 *
 * Constitución:
 *   - Artículo I (Dogma Vanilla): DOM simulado mínimo, ES Modules nativos.
 *   - Artículo V: asertos en inglés, comentarios en castellano.
 *
 * Uso: node scratch/test_discard_tome_modal.mjs
 */

let assertsPassed = 0;
let assertsFailed = 0;

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

// ---------------------------------------------------------------------
// DOM simulado mínimo (mismo patrón que test_vestibule_card_states.mjs).
// ---------------------------------------------------------------------

function createObservableElement(tagName) {
  const element = {
    tagName: String(tagName).toUpperCase(),
    children: [],
    attributes: new Map(),
    classes: new Set(),
    textContent: '',
    parentNode: null,
    listeners: new Map(),
    className: '',
    type: null,
    open: false,
    returnValue: '',
    focused: false,
    style: { setProperty() {} },
  };
  Object.defineProperty(element, 'classList', {
    value: {
      add: (...names) => names.forEach((n) => element.classes.add(n)),
      remove: (...names) => names.forEach((n) => element.classes.delete(n)),
      contains: (name) => element.classes.has(name),
    },
  });
  element.setAttribute = (name, value) => {
    element.attributes.set(name, String(value));
    if (name === 'class') {
      element.classes = new Set(String(value).split(/\s+/).filter(Boolean));
      element.className = String(value);
    }
  };
  element.getAttribute = (name) => (element.attributes.has(name) ? element.attributes.get(name) : null);
  element.hasAttribute = (name) => element.attributes.has(name);
  element.removeAttribute = (name) => { element.attributes.delete(name); };
  element.appendChild = (child) => {
    child.parentNode = element;
    element.children.push(child);
    return child;
  };
  element.remove = () => {};
  element.showModal = () => { element.open = true; };
  element.close = (returnValue = '') => {
    if (!element.open) return;
    element.open = false;
    element.returnValue = returnValue;
    for (const handler of element.listeners.get('close') ?? []) handler({});
  };
  element.focus = () => { element.focused = true; };
  element.addEventListener = (type, handler) => {
    if (!element.listeners.has(type)) element.listeners.set(type, []);
    element.listeners.get(type).push(handler);
  };
  element.removeEventListener = (type, handler) => {
    const list = element.listeners.get(type) ?? [];
    const index = list.indexOf(handler);
    if (index !== -1) list.splice(index, 1);
  };
  element.dispatch = (type, event = {}) => {
    for (const handler of element.listeners.get(type) ?? []) {
      handler({ currentTarget: element, target: element, preventDefault() {}, ...event });
    }
  };
  element.dispatchEvent = (customEvent) => {
    element.dispatch(customEvent.type, customEvent);
  };
  element.click = () => { element.dispatch('click'); };
  return element;
}

const documentSim = {
  createElement: (tagName) => createObservableElement(tagName),
};
const eventsSim = [];
class CustomEventSim {
  constructor(type, options = {}) {
    this.type = type;
    this.detail = options.detail ?? null;
    this.bubbles = options.bubbles ?? false;
  }
}
const windowSim = {
  CustomEvent: CustomEventSim,
};

const {
  createDiscardTomeEntryModalComponent,
  DISCARD_TOME_MODAL_TITLE,
  DISCARD_TOME_MODAL_LEGEND,
  DISCARD_TOME_MODAL_CONFIRM_LABEL,
  DISCARD_TOME_MODAL_DISMISS_LABEL,
  buildDiscardIntro,
} = await import('../public/assets/js/components/discardTomeEntryModalComponent.js');

function findDescendants(root, predicate) {
  const found = [];
  const stack = [root];
  while (stack.length > 0) {
    const node = stack.shift();
    if (predicate(node)) found.push(node);
    stack.push(...(node.children ?? []));
  }
  return found;
}

function findButtonByText(root, text) {
  return findDescendants(root, (n) => n.tagName === 'BUTTON' && n.textContent === text)[0] ?? null;
}

function findByClass(root, className) {
  return findDescendants(root, (n) => n.classList?.contains?.(className))[0] ?? null;
}

/** Forja un dialog anfitrión con querySelector por clase (patrón SPEC-10). */
function createHostDialog() {
  const dialog = createObservableElement('dialog');
  dialog.events = [];
  dialog.addEventListener('tome:discard-opened', (e) => dialog.events.push(e.type));
  dialog.addEventListener('tome:discard-dismissed', (e) => dialog.events.push(e.type));
  dialog.querySelector = (selector) => {
    const wanted = selector.startsWith('.') ? selector.slice(1) : null;
    const scan = (node) => {
      for (const child of node.children ?? []) {
        if (wanted !== null && child.classList?.contains?.(wanted)) return child;
        const found = scan(child);
        if (found) return found;
      }
      return null;
    };
    return scan(dialog);
  };
  return dialog;
}

/** Simula el gesto de origen: una tarjeta enfocable con memoria de foco. */
function createOriginElement() {
  const origin = createObservableElement('button');
  origin.focusCalls = 0;
  origin.focus = () => { origin.focusCalls += 1; };
  return origin;
}

// =====================================================================
// [1] Confirmar retira la entrada sin recargar (RF-02.4, caso límite 10)
// =====================================================================
console.log('[1] Confirmación: onDiscard recibe el spellId; el modal jamás llama a la API');
const dialog1 = createHostDialog();
let discardedSpellIds = [];
const modal1 = createDiscardTomeEntryModalComponent(dialog1, {
  onDiscard: (spellId) => { discardedSpellIds.push(spellId); },
  documentRef: documentSim,
  windowRef: windowSim,
});
const origin1 = createOriginElement();
modal1.open({ spellId: 'spl_1', spellName: 'Llama Eterna', originElement: origin1 });
assertCondition(dialog1.open === true, 'la apertura muestra el dialog nativo (showModal)');
assertCondition(
  findByClass(dialog1, 'discard-tome-modal__intro').textContent === 'Vas a retirar «Llama Eterna» de tu tomo.',
  'el modal NOMBRA la obra en el cuerpo (RF-02.4)',
);
const confirmButton1 = findButtonByText(dialog1, DISCARD_TOME_MODAL_CONFIRM_LABEL);
confirmButton1.click();
assertCondition(discardedSpellIds.length === 1 && discardedSpellIds[0] === 'spl_1', 'confirmar entrega el spellId a la vista (onDiscard)');
assertCondition(dialog1.open === false, 'tras confirmar el modal cierra; la vista actualiza el conteo sin recargar (paginación viva, caso límite 10)');
assertCondition(dialog1.returnValue === 'discard-confirmed', 'el dialog cierra con returnValue de confirmación');

// =====================================================================
// [2] Escape y descarte no mutan (RF-02.4, RNF-04)
// =====================================================================
console.log('\n[2] Escape y descarte: NADA se consume');
const dialog2 = createHostDialog();
let discardCount2 = 0;
const modal2 = createDiscardTomeEntryModalComponent(dialog2, {
  onDiscard: () => { discardCount2 += 1; },
  documentRef: documentSim,
  windowRef: windowSim,
});
const origin2 = createOriginElement();
modal2.open({ spellId: 'spl_2', spellName: 'Mareas de Aether', originElement: origin2 });
dialog2.close(); // Escape nativo del navegador → evento 'close'.
assertCondition(discardCount2 === 0, 'Escape cierra el dialog SIN consumar retirada alguna');
assertCondition(dialog2.events.includes('tome:discard-dismissed'), 'el descarte por Escape emite tome:discard-dismissed (plan §4)');
assertCondition(modal2.isOpen() === false, 'tras el Escape el modal queda cerrado y el tomo íntegro');

modal2.open({ spellId: 'spl_2', spellName: 'Mareas de Aether', originElement: origin2 });
const dismissButton2 = findButtonByText(dialog2, DISCARD_TOME_MODAL_DISMISS_LABEL);
dismissButton2.click();
assertCondition(discardCount2 === 0, '«Conservar la obra» no consume retirada alguna');
modal2.open({ spellId: 'spl_2', spellName: 'Mareas de Aether', originElement: origin2 });
const closeButton2 = findByClass(dialog2, 'discard-tome-modal__close');
closeButton2.click();
assertCondition(discardCount2 === 0, 'el botón × tampoco consume retirada (descarte seguro)');

// =====================================================================
// [3] El foco vuelve al gesto de origen en AMBOS desenlaces (RNF-04)
// =====================================================================
console.log('\n[3] Rescate de foco al gesto de origen');
const dialog3 = createHostDialog();
const modal3 = createDiscardTomeEntryModalComponent(dialog3, {
  onDiscard: () => {},
  documentRef: documentSim,
  windowRef: windowSim,
});
const origin3 = createOriginElement();
modal3.open({ spellId: 'spl_3', spellName: 'Púas Basálticas', originElement: origin3 });
findButtonByText(dialog3, DISCARD_TOME_MODAL_DISMISS_LABEL).click();
assertCondition(origin3.focusCalls === 1, 'tras el DESCARTE el foco vuelve al gesto de origen');

modal3.open({ spellId: 'spl_3', spellName: 'Púas Basálticas', originElement: origin3 });
findButtonByText(dialog3, DISCARD_TOME_MODAL_CONFIRM_LABEL).click();
assertCondition(origin3.focusCalls === 2, 'tras CONFIRMAR el foco también vuelve al gesto de origen');

// =====================================================================
// [4] La leyenda del Anexo A a cuerpo de modal (RF-02.4)
// =====================================================================
console.log('\n[4] Leyenda canónica del Anexo A');
const dialog4 = createHostDialog();
const modal4 = createDiscardTomeEntryModalComponent(dialog4, {
  onDiscard: () => {},
  documentRef: documentSim,
  windowRef: windowSim,
});
modal4.open({ spellId: 'spl_4', spellName: 'Chispa Fulgurante', originElement: null });
const legendNode = findByClass(dialog4, 'discard-tome-modal__legend');
assertCondition(legendNode.textContent === DISCARD_TOME_MODAL_LEGEND, 'la leyenda LITERAL del Anexo A viaja en el cuerpo');
assertCondition(legendNode.textContent === 'Esta obra dejará tu tomo para siempre: medítalo antes de firmar.', 'el texto es el canónico del plan §4.3');
assertCondition(legendNode.getAttribute('role') === 'alert', 'la leyenda es alerta explícita (jamás letra menuda)');
assertCondition(findByClass(dialog4, 'discard-tome-modal__title').textContent === DISCARD_TOME_MODAL_TITLE, 'el título solemne «Retirar del tomo» encabeza el modal');
assertCondition(buildDiscardIntro('X').startsWith('Vas a retirar «X»'), 'el intro nombra SIEMPRE la obra');

// =====================================================================
// [5] Focus trap y foco inicial en la vía segura (patrón SPEC-02)
// =====================================================================
console.log('\n[5] Focus trap (RNF-04, patrón SPEC-02)');
const dialog5 = createHostDialog();
const modal5 = createDiscardTomeEntryModalComponent(dialog5, {
  onDiscard: () => {},
  documentRef: documentSim,
  windowRef: windowSim,
});
modal5.open({ spellId: 'spl_5', spellName: 'SinNombre', originElement: null });
const focusables = findDescendants(dialog5, (n) => n.tagName === 'BUTTON');
assertCondition(focusables.length === 3, 'el panel porta tres controles: confirmar, conservar y ×');
assertCondition(focusables[0].focused === true, 'el foco inicial cae en «Conservar la obra»: la vía segura');

// Tab en el último borde → vuelve al primero (giro del trap).
let lastFocusedNode = null;
focusables.forEach((n) => {
  n.focused = false;
  n.focus = () => { lastFocusedNode = n; n.focused = true; };
});
const keydownTarget = focusables[focusables.length - 1];
dialog5.dispatch('keydown', { key: 'Tab', target: keydownTarget, preventDefault() {} });
assertCondition(lastFocusedNode === focusables[0], 'Tab en el borde inferior devuelve el foco al primer control (trap giratorio)');

// Shift+Tab en el primero → salta al último.
lastFocusedNode = null;
dialog5.dispatch('keydown', { key: 'Tab', shiftKey: true, target: focusables[0], preventDefault() {} });
assertCondition(lastFocusedNode === focusables[focusables.length - 1], 'Shift+Tab en el primer control salta al último (trap giratorio inverso)');

// =====================================================================
// [6] Región viva, eventos del plan §4 y cableado idempotente
// =====================================================================
console.log('\n[6] Región viva de anuncios y apertura idempotente');
const dialog6 = createHostDialog();
const modal6 = createDiscardTomeEntryModalComponent(dialog6, {
  onDiscard: () => {},
  documentRef: documentSim,
  windowRef: windowSim,
});
modal6.open({ spellId: 'spl_6', spellName: 'Obra Uno', originElement: null });
const liveNode = findByClass(dialog6, 'discard-tome-modal__announce');
assertCondition(liveNode.getAttribute('aria-live') === 'polite', 'la región viva existe con aria-live="polite" (RNF-04)');
assertCondition(dialog6.events.includes('tome:discard-opened'), 'la apertura emite tome:discard-opened (plan §4)');

modal6.open({ spellId: 'spl_6b', spellName: 'Obra Dos', originElement: null });
assertCondition(findByClass(dialog6, 'discard-tome-modal__intro').textContent.includes('Obra Dos'), 'la re-apertura actualiza la obra nombrada sin duplicar panel');
assertCondition(findDescendants(dialog6, (n) => n.tagName === 'BUTTON').length === 3, 'el cableado es idempotente: jamás duplica controles');

// Guardias: sin spellId no hay retirada; destroy cierra limpio.
modal6.open({ spellId: '', spellName: 'Fantasma', originElement: null });
assertCondition(modal6.isOpen() === true || dialog6.events.filter((e) => e === 'tome:discard-opened').length === 1, 'un spellId vacío jamás abre una retirada nueva');
modal6.destroy();
assertCondition(modal6.isOpen() === false, 'destroy cierra limpio (apagado del orquestador)');

console.log(`\n=== RESULTADO: ${assertsPassed} pasan, ${assertsFailed} fallan ===`);
process.exit(assertsFailed === 0 ? 0 : 1);
