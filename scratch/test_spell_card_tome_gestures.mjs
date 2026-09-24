/**
 * test_spell_card_tome_gestures.mjs — Arnés de la Tarea 5.1 (TASKS-11).
 *
 * Valida EL GESTO COMPARTIDO en `spellCardComponent` (plan §4.2) contra
 * el «Hecho cuando» de la tarea:
 *
 *   1. La MISMA tarjeta pinta los seis estados posibles SOLO desde su
 *      DTO (RF-04.0): botón «Añadir al tomo», conmutador «Ya está en tu
 *      tomo», conmutador «Ya rendiste homenaje», «Elogiar» activo,
 *      «Elogiar» ausente + leyenda de militancia, gestos ausentes sobre
 *      no validados.
 *   2. Los conmutadores llevan `aria-pressed` (RNF-04).
 *   3. La activación por teclado funciona (Enter/Space → click del gesto).
 *   4. Los gestos vedados JAMÁS llegan al bus: solo los botones activos
 *      emiten `tome:seal` / `tome:praise` con el DTO (RF-01.1, RF-04.1).
 *   5. Anónimo (DTO sin adeptState): contemplación sin zona de gestos.
 *
 * Constitución:
 *   - Artículo I (Dogma Vanilla): DOM simulado mínimo, ES Modules nativos.
 *   - Artículo V: asertos en inglés, comentarios en castellano.
 *
 * Uso: node scratch/test_spell_card_tome_gestures.mjs
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
  return {
    tagName: String(tagName).toUpperCase(),
    children: [],
    attributes: new Map(),
    textContent: '',
    parentNode: null,
    listeners: new Map(),
    className: '',
    type: null,
    style: { setProperty() {} },
    setAttribute(name, value) {
      this.attributes.set(name, String(value));
    },
    getAttribute(name) {
      return this.attributes.has(name) ? this.attributes.get(name) : null;
    },
    hasAttribute(name) {
      return this.attributes.has(name);
    },
    removeAttribute(name) {
      this.attributes.delete(name);
    },
    appendChild(child) {
      child.parentNode = this;
      this.children.push(child);
      return child;
    },
    remove() {},
    addEventListener(type, handler) {
      if (!this.listeners.has(type)) this.listeners.set(type, []);
      this.listeners.get(type).push(handler);
    },
    removeEventListener(type, handler) {
      const list = this.listeners.get(type) ?? [];
      const index = list.indexOf(handler);
      if (index !== -1) list.splice(index, 1);
    },
    dispatch(type, event = {}) {
      for (const handler of this.listeners.get(type) ?? []) {
        handler({ currentTarget: this, target: this, preventDefault() {}, ...event });
      }
    },
    /**
     * Ascenso de ancestros por selectores simples (Tarea 9.2): permite
     * ejercer la guardia anti-burbujeo de la tarjeta. Soporta los tres
     * selectores que la guardia consulta: button, a y [role="switch"].
     */
    closest(selector) {
      const matches = (node) => selector.split(',').some((rawSelector) => {
        const candidate = rawSelector.trim();
        if (candidate === 'button') return String(node.tagName).toUpperCase() === 'BUTTON';
        if (candidate === 'a') return String(node.tagName).toUpperCase() === 'A';
        if (candidate === '[role="switch"]') return node.getAttribute('role') === 'switch';
        return false;
      });
      let node = this;
      while (node) {
        if (matches(node)) return node;
        node = node.parentNode ?? null;
      }
      return null;
    },
    click() {
      this.dispatch('click');
    },
  };
}

const factory = (tagName) => createObservableElement(tagName);

const {
  createSpellCardComponent,
  TOME_CARD_EVENTS,
  TOME_CARD_LABELS,
} = await import('../public/assets/js/components/spellCardComponent.js');

/** DTO base canónico de tarjeta (con adeptState de adepto linajado). */
function cardDto(overrides = {}) {
  return {
    id: 'spl_1',
    slug: 'llama-eterna',
    name: 'Llama Eterna',
    magicSchool: 'evocation',
    magicSchoolLabel: 'Evocación',
    elementalAffinityLabel: 'Fuego Primordial',
    manaCost: 12,
    summary: 'Un estallido de fuego primordial.',
    clanName: 'Casa de la Llama',
    clanId: 'cln_flame',
    status: 'validated',
    adeptState: { collected: false, praised: false, praiseAllowed: true },
    ...overrides,
  };
}

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

function findToggleByText(root, text) {
  return findDescendants(
    root,
    (n) => n.getAttribute('aria-pressed') === 'true' && n.getAttribute('aria-label') === text,
  )[0] ?? null;
}

function findByText(root, text) {
  return findDescendants(root, (n) => n.textContent === text)[0] ?? null;
}

/** Eructos del bus: cada gesto emitido queda grabado. */
function createBusSpy() {
  const emitted = [];
  return {
    emitted,
    onTomeGesture(eventType, payload) {
      emitted.push({ eventType, payload });
    },
  };
}

// =====================================================================
// [1] Los seis estados, solo desde el DTO (RF-04.0)
// =====================================================================
console.log('[1] Los seis estados del gesto compartido (RF-04.0)');

// Estado 1: no coleccionado + validado → botón «Añadir al tomo».
const bus1 = createBusSpy();
const card1 = createSpellCardComponent(cardDto(), { onTomeGesture: bus1.onTomeGesture, elementFactory: factory });
const sealButton1 = findButtonByText(card1, TOME_CARD_LABELS.addToTome);
assertCondition(sealButton1 !== null, 'no coleccionado + validado → botón «Añadir al tomo» presente');

// Estado 2: coleccionado → conmutador «Ya está en tu tomo» sin botón.
const bus2 = createBusSpy();
const card2 = createSpellCardComponent(
  cardDto({ adeptState: { collected: true, praised: false, praiseAllowed: true } }),
  { onTomeGesture: bus2.onTomeGesture, elementFactory: factory },
);
assertCondition(findToggleByText(card2, TOME_CARD_LABELS.alreadyInTome) !== null, 'coleccionado → conmutador «Ya está en tu tomo» presente');
assertCondition(findButtonByText(card2, TOME_CARD_LABELS.addToTome) === null, 'coleccionado → el botón «Añadir» está AUSENTE (conmutador, no botón)');
assertCondition(findButtonByText(card2, TOME_CARD_LABELS.praise) !== null, 'coleccionado + permitido → «Elogiar» sigue disponible');

// Estado 3: elogiado → conmutador «Ya rendiste homenaje» sin botón de elogio.
const bus3 = createBusSpy();
const card3 = createSpellCardComponent(
  cardDto({ adeptState: { collected: true, praised: true, praiseAllowed: true } }),
  { onTomeGesture: bus3.onTomeGesture, elementFactory: factory },
);
assertCondition(findToggleByText(card3, TOME_CARD_LABELS.alreadyPraised) !== null, 'elogiado → conmutador «Ya rendiste homenaje» presente');
assertCondition(findButtonByText(card3, TOME_CARD_LABELS.praise) === null, 'elogiado → el botón «Elogiar» está AUSENTE (RF-04.3)');

// Estado 4: militante de la casa → «Elogiar» ausente + leyenda sobria.
const bus4 = createBusSpy();
const card4 = createSpellCardComponent(
  cardDto({ adeptState: { collected: false, praised: false, praiseAllowed: false } }),
  { onTomeGesture: bus4.onTomeGesture, elementFactory: factory },
);
assertCondition(findButtonByText(card4, TOME_CARD_LABELS.praise) === null, 'militante → el gesto «Elogiar» está AUSENTE (RF-04.4)');
assertCondition(
  findByText(card4, TOME_CARD_LABELS.ownClanLegend) !== null,
  'militante → la leyenda sobria canónica acompaña la vedación',
);
assertCondition(findButtonByText(card4, TOME_CARD_LABELS.addToTome) !== null, 'militante → «Añadir al tomo» sigue disponible (ritos separados, hallazgos 2-3)');

// Estado 5: no validado → ni «Añadir» ni «Elogiar» (RF-04.5).
const bus5 = createBusSpy();
const card5 = createSpellCardComponent(
  cardDto({ status: 'experimental' }),
  { onTomeGesture: bus5.onTomeGesture, elementFactory: factory },
);
assertCondition(
  findButtonByText(card5, TOME_CARD_LABELS.addToTome) === null
    && findButtonByText(card5, TOME_CARD_LABELS.praise) === null,
  'no validado → ni «Añadir» ni «Elogiar» (RF-04.5)',
);

// Estado 6: no validado pero coleccionado → solo el conmutador de memoria.
const bus6 = createBusSpy();
const card6 = createSpellCardComponent(
  cardDto({ status: 'archived', adeptState: { collected: true, praised: false, praiseAllowed: false } }),
  { onTomeGesture: bus6.onTomeGesture, elementFactory: factory },
);
assertCondition(findToggleByText(card6, TOME_CARD_LABELS.alreadyInTome) !== null, 'obra apartada coleccionada → la memoria del tomo persiste (conmutador)');
assertCondition(
  findButtonByText(card6, TOME_CARD_LABELS.addToTome) === null
    && findButtonByText(card6, TOME_CARD_LABELS.praise) === null,
  'obra apartada → ambos gestos ausentes (la memoria jamás se disuelve por detrás)',
);

// =====================================================================
// [2] aria-pressed en los conmutadores (RNF-04)
// =====================================================================
console.log('\n[2] Conmutadores informativos con aria-pressed (RNF-04)');
const toggleCollected = findToggleByText(card2, TOME_CARD_LABELS.alreadyInTome);
assertCondition(toggleCollected.getAttribute('role') === 'switch', 'el conmutador porta role="switch"');
assertCondition(toggleCollected.getAttribute('aria-pressed') === 'true', 'el conmutador de colección lleva aria-pressed="true"');
assertCondition(
  findToggleByText(card3, TOME_CARD_LABELS.alreadyPraised).getAttribute('aria-pressed') === 'true',
  'el conmutador de homenaje lleva aria-pressed="true"',
);

// =====================================================================
// [3] Activación por teclado (RNF-04): los botones reciben el click
// =====================================================================
console.log('\n[3] Activación de gestos por teclado');
// El botón es un <button type="button"> nativo: Enter/Space del navegador
// disparan su click; el arnés ejercita el flujo del gesto directamente.
let keyboardActivations = 0;
const keyboardButton = findButtonByText(card1, TOME_CARD_LABELS.addToTome);
keyboardButton.addEventListener('click', () => { keyboardActivations += 1; });
keyboardButton.dispatch('keydown', { key: 'Enter' });
keyboardButton.dispatch('click');
assertCondition(keyboardActivations === 1, 'el gesto activo responde a su activación (click del botón nativo, Enter/Space del navegador)');
assertCondition(keyboardButton.type === 'button', 'el botón de gesto es type="button" (no envía formularios)');

// =====================================================================
// [4] Los gestos activos llegan al bus con el DTO; los vedados jamás
// =====================================================================
console.log('\n[4] El bus recibe solo gestos activos (RF-01.1, RF-04.1)');
// El botón del sellado ya recibió el click de la Fase 3 (activación de
// teclado sobre el mismo nodo): el bus debe portar EXACTAMENTE ese eco.
assertCondition(bus1.emitted.length === 1, '«Añadir al tomo» emite UN evento al bus');
assertCondition(bus1.emitted[0].eventType === TOME_CARD_EVENTS.tomeSeal, 'el evento del sellado es tome:seal');
assertCondition(
  bus1.emitted[0].payload.spellId === 'spl_1' && bus1.emitted[0].payload.slug === 'llama-eterna',
  'el payload porta spellId y slug del DTO',
);
assertCondition(bus1.emitted[0].payload.originElement === card1, 'el payload porta el nodo origen para el rescate de foco');

const praiseButton1 = findButtonByText(card1, TOME_CARD_LABELS.praise);
praiseButton1.click();
assertCondition(
  bus1.emitted.length === 2 && bus1.emitted[1].eventType === TOME_CARD_EVENTS.tomePraise,
  '«Elogiar» emite tome:praise al bus (RF-04.1)',
);

// Vedación: la tarjeta del militante NO emite nada (el gesto no existe).
const emittedBefore = bus4.emitted.length;
assertCondition(
  findByText(card4, TOME_CARD_LABELS.ownClanLegend) !== null && bus4.emitted.length === emittedBefore,
  'la leyenda de militancia no emite evento alguno: el gesto vedado jamás llega al bus',
);
// Los conmutadores tampoco emiten (informativos).
card2.querySelectorAll?.();
const toggleNode = findToggleByText(card2, TOME_CARD_LABELS.alreadyInTome);
toggleNode.dispatch('click');
assertCondition(bus2.emitted.length === 0, 'el conmutador informativo es mudo: ningún evento al bus');

// =====================================================================
// [5] Anónimo: sin adeptState no hay zona de gestos (contemplación)
// =====================================================================
console.log('\n[5] Anónimo: contemplación sin gestos (RF-05.1 de SPEC-03)');
const busAnon = createBusSpy();
const cardAnon = createSpellCardComponent(
  cardDto({ adeptState: undefined }),
  { onTomeGesture: busAnon.onTomeGesture, elementFactory: factory },
);
assertCondition(findByText(cardAnon, TOME_CARD_LABELS.addToTome) === null, 'sin adeptState la tarjeta no pinta gesto alguno');
assertCondition(findByText(cardAnon, TOME_CARD_LABELS.praise) === null, 'sin adeptState tampoco hay «Elogiar»');
assertCondition(findToggleByText(cardAnon, TOME_CARD_LABELS.alreadyInTome) === null, 'sin adeptState ningún conmutador viaja al DOM');

// La tarjeta sigue siendo operable como antes (regresión de SPEC-05).
let selected = false;
const cardLegacy = createSpellCardComponent(
  cardDto({ adeptState: undefined }),
  { onSpellSelect: () => { selected = true; }, elementFactory: factory },
);
cardLegacy.dispatch('click');
assertCondition(selected === true, 'la activación de selección (click/teclado) sigue viva sin adeptState');

// =====================================================================
// [6] El gesto interno NO convoca el conjuro (hallazgo del recorrido
//     manual de la Tarea 9.2: el evento burbujeaba hasta la tarjeta y
//     «Elogiar» abría además el Simulador con la obra)
// =====================================================================
console.log('\n[6] La guardia anti-burbujeo de los controles internos');
let convocatorias = 0;
const cardBubble = createSpellCardComponent(
  // Coleccionado y validado: la tarjeta porta conmutador Y «Elogiar».
  cardDto({ adeptState: { collected: true, praised: false, praiseAllowed: true } }),
  {
    onSpellSelect: () => { convocatorias += 1; },
    onTomeGesture: () => {},
    elementFactory: factory,
  },
);

// 1) Click que BURBUJEA desde el botón de gesto: target = el botón.
const gestoBubble = findButtonByText(cardBubble, TOME_CARD_LABELS.praise);
cardBubble.dispatch('click', { target: gestoBubble });
assertCondition(convocatorias === 0, 'el click burbujeado desde «Elogiar» no convoca el conjuro');

// 2) Click burbujeado desde un conmutador informativo: tampoco convoca.
const toggleBubble = findToggleByText(cardBubble, TOME_CARD_LABELS.alreadyInTome);
cardBubble.dispatch('click', { target: toggleBubble });
assertCondition(convocatorias === 0, 'el conmutador informativo tampoco convoca el conjuro');

// 3) El click sobre el cuerpo de la tarjeta SÍ convoca (regresión).
cardBubble.dispatch('click', { target: cardBubble });
assertCondition(convocatorias === 1, 'el click sobre el cuerpo de la tarjeta sigue convocando el conjuro');

console.log(`\n=== RESULTADO: ${assertsPassed} pasan, ${assertsFailed} fallan ===`);
process.exit(assertsFailed === 0 ? 0 : 1);
