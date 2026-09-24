/**
 * test_vestibule_card_states.mjs — Arnés de la Tarea 5.1 (TASKS-10).
 *
 * Verifica la tarjeta solemne del Vestíbulo (`vestibuleClanCardComponent`)
 * y su estado vacío, según el «Hecho cuando»:
 *
 *   [1] Los CINCO estados de tarjeta (`none`, `join`, `petition`,
 *       `pending`, `own`) se pintan DESDE EL DTO (RF-01.7) — la interfaz
 *       jamás decide.
 *   [2] RF-01.3: lema, Sello Rúnico forjado (SVG, SPEC-02 RF-07), «X de 30»,
 *       régimen rotulado en castellano, corona del Regente con los estilos
 *       del kit, censo «X de 30», y la leyenda de plenitud de la casa llena
 *       del kit de SPEC-07 (`.podium-rank__crown`, sin ad hoc).
 *   [3] RF-03.5: gesto vedado (`gesture: null`) → leyenda solemne del DTO
 *       en lugar del control, sin error ni modal; contemplación íntegra.
 *   [4] RNF-03: tarjeta enfocable; Enter/espaciadora activan el gesto;
 *       el gesto delega en onGesture (jamás consume adhesión por su cuenta).
 *   [5] Estado vacío: «Ninguna hermandad ruega aún tu linaje» con la
 *       invitación discreta (RF-01.4 de la SPEC-10).
 *
 * Constitución:
 *   - Artículo I (Dogma Vanilla): DOM simulado mínimo, ES Modules nativos.
 *   - Artículo V: identificadores en inglés camelCase; comentarios en castellano.
 *
 * Uso: node scratch/test_vestibule_card_states.mjs
 */

import assert from 'node:assert';

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

// =====================================================================
// DOM simulado mínimo: elementos observables + document.createElementNS
// (el sello rúnico forja SVG; el arnés le presta un documento capaz).
// =====================================================================

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
        handler({ currentTarget: this, target: this, preventDefault() {}, click() {}, ...event });
      }
    },
    click() {
      this.dispatch('click');
    },
  };
}

const svgDocument = {
  createElement: (tagName) => createObservableElement(tagName),
  createElementNS: (namespace, tagName) => {
    const element = createObservableElement(tagName);
    element.namespace = namespace;
    return element;
  },
};

const factory = (tagName) => createObservableElement(tagName);

const {
  createVestibuleClanCardComponent,
  createVestibuleEmptyState,
  VESTIBULE_CARD_RELATION_LABELS,
  VESTIBULE_CARD_GESTURE_LABELS,
} = await import('../public/assets/js/components/vestibuleClanCardComponent.js');

/** DTO canónico base (plan §2.2, Endpoint 1 — array `clans[]`). */
function clanDto(overrides = {}) {
  return {
    clanId: 'cln_mareas',
    name: 'Mareas de Aether',
    motto: 'En la marea está la verdad',
    coatOfArms: 'cln_mareas',
    lineageType: 'celestialTides',
    memberCount: 12,
    memberLimit: 30,
    admissionMode: 'open',
    admissionModeLabel: 'Admisión abierta',
    isRegent: false,
    adeptRelation: 'none',
    gesture: null,
    vedadoLegend: null,
    ...overrides,
  };
}

function findDescendant(root, predicate) {
  const stack = [root];
  while (stack.length > 0) {
    const node = stack.shift();
    if (predicate(node)) return node;
    stack.push(...(node.children ?? []));
  }
  return null;
}

// =====================================================================
// [1] Los cinco estados se pintan DESDE EL DTO (RF-01.7)
// =====================================================================
console.log('[1] Cinco estados de tarjeta pintados desde el DTO');

// Estado `join`: casa abierta, adepto apto.
const joinCard = createVestibuleClanCardComponent(clanDto({ gesture: 'join' }), {
  elementFactory: factory,
  documentRef: svgDocument,
});
assertCondition(joinCard.element.getAttribute('data-gesture') === 'join', "estado `join` declarado en data-gesture");
const joinButton = findDescendant(joinCard.element, (node) => String(node.className).includes('vestibule-card__gesture'));
assertCondition(joinButton !== null && joinButton.textContent === VESTIBULE_CARD_GESTURE_LABELS.join, 'el gesto join pinta su botón «Solicitar ingreso» (voz activa)');

// Estado `petition`: casa bajo dictamen.
const petitionCard = createVestibuleClanCardComponent(clanDto({
  admissionMode: 'byApplication',
  admissionModeLabel: 'Requiere petición formal',
  gesture: 'petition',
}), { elementFactory: factory, documentRef: svgDocument });
const petitionButton = findDescendant(petitionCard.element, (node) => String(node.className).includes('vestibule-card__gesture--petition'));
assertCondition(petitionButton !== null && petitionButton.textContent === VESTIBULE_CARD_GESTURE_LABELS.petition, 'el gesto petition pinta «Presentar petición formal»');
const regimeText = findDescendant(petitionCard.element, (node) => String(node.className).includes('vestibule-card__regime'));
assertCondition(regimeText.textContent === 'Requiere petición formal', 'el régimen viaja rotulado en castellano desde el DTO (RF-01.3)');

// Estado `pending`: petición en deliberación.
const pendingCard = createVestibuleClanCardComponent(clanDto({
  adeptRelation: 'pending',
  gesture: 'withdraw',
  petitionId: 'app_9f2',
}), { elementFactory: factory, documentRef: svgDocument });
assertCondition(pendingCard.element.getAttribute('data-relation') === 'pending', 'estado `pending` declarado en data-relation');
const withdrawButton = findDescendant(pendingCard.element, (node) => String(node.className).includes('vestibule-card__gesture--withdraw'));
assertCondition(withdrawButton !== null, 'la petición pendiente ofrece su gesto de retirada');

// Estado `own`: la casa propia.
const ownCard = createVestibuleClanCardComponent(clanDto({ adeptRelation: 'ownHouse', gesture: null }), {
  elementFactory: factory,
  documentRef: svgDocument,
});
const ownRelation = findDescendant(ownCard.element, (node) => String(node.className).includes('vestibule-card__relation'));
assertCondition(ownRelation.textContent === VESTIBULE_CARD_RELATION_LABELS.ownHouse, "estado `own` declara «Tu hermandad» desde el DTO");

// Estado `none`: contemplación sin gesto.
const noneCard = createVestibuleClanCardComponent(clanDto({ gesture: null }), {
  elementFactory: factory,
  documentRef: svgDocument,
});
assertCondition(noneCard.element.getAttribute('data-relation') === 'none' && noneCard.element.getAttribute('data-gesture') === 'vedado', 'estado `none` sin gesto declarado como vedado');
assertCondition(findDescendant(noneCard.element, (node) => String(node.className).includes('vestibule-card__gesture')) === null, 'sin gesto no hay botón alguno (un solo gesto o su leyenda, RF-01.3)');

// =====================================================================
// [2] Contrato heráldico completo (RF-01.3)
// =====================================================================
console.log('\n[2] Leva, sello, censo y corona (RF-01.3)');

const fullCard = createVestibuleClanCardComponent(clanDto({ gesture: 'join', isRegent: true }), {
  elementFactory: factory,
  documentRef: svgDocument,
});
assertCondition(fullCard.element.getAttribute('data-clan-id') === 'cln_mareas', 'la tarjeta porta el identificador de la casa');
const nameNode = findDescendant(fullCard.element, (node) => String(node.className).includes('vestibule-card__name'));
assertCondition(nameNode.textContent === 'Mareas de Aether', 'el nombre canónico se pinta con textContent (XSS blindado)');
const mottoNode = findDescendant(fullCard.element, (node) => String(node.className).includes('vestibule-card__motto'));
assertCondition(mottoNode.textContent === 'En la marea está la verdad', 'el lema heráldico viaja del DTO al DOM');
const seal = fullCard.element.children.find((node) => node.tagName === 'svg' || String(node.className).includes('rune-seal') || node.attributes?.get('class') === 'rune-seal');
assertCondition(seal !== undefined, 'el Sello Rúnico se forja (SVG de SPEC-02 RF-07; coatOfArms codificado, jamás impreso)');
const censusNode = findDescendant(fullCard.element, (node) => String(node.className).includes('vestibule-card__census-count'));
assertCondition(censusNode.textContent === '12 de 30', 'el censo se rótula «X de 30» (RF-01.3)');
const crown = findDescendant(fullCard.element, (node) => String(node.className).includes('podium-rank__crown'));
assertCondition(crown !== null, 'la corona del Regente viste la clase del kit de SPEC-07 (sin estilos ad hoc)');
assertCondition(crown.getAttribute('aria-hidden') === 'true', 'la corona es ornamento puro (aria-hidden)');

// =====================================================================
// [3] Leyendas vedadas (RF-03.5): contemplación íntegra sin error
// =====================================================================
console.log('\n[3] Gesto vedado → leyenda solemne del santuario (RF-03.5)');

const convaleciente = createVestibuleClanCardComponent(clanDto({
  gesture: null,
  vedadoLegend: 'En Convalecencia Arcana: restan 2 días de meditación',
}), { elementFactory: factory, documentRef: svgDocument });
const vedadoNode = findDescendant(convaleciente.element, (node) => String(node.className).includes('vestibule-card__vedado'));
assertCondition(vedadoNode !== null, 'el gesto vedado se sustituye por la leyenda (sin botón, sin error ni modal)');
assertCondition(vedadoNode.textContent.includes('restan 2 días'), 'los días reales del backend viajan en la leyenda (alza al entero, RF-03.5)');

const militante = createVestibuleClanCardComponent(clanDto({
  gesture: null,
  vedadoLegend: 'Tu lealtad ya está empeñada en «Brasa Viva»: solo renunciar a ella —y sobrevivir la convalecencia— abre de nuevo sus puertas.',
}), { elementFactory: factory, documentRef: svgDocument });
const lealtadNode = findDescendant(militante.element, (node) => String(node.className).includes('vestibule-card__vedado'));
assertCondition(lealtadNode.textContent.includes('Brasa Viva'), 'la leyenda de lealtad empeñada nombra la casa (RF-02.3)');

// Casa en plenitud (RF-03.5, leyenda canónica de ClanVestibuleService):
// el gesto join se retira y la leyenda nombra el límite del censo.
const casaLlena = createVestibuleClanCardComponent(clanDto({
  memberCount: 30,
  gesture: null,
  vedadoLegend: 'La hermandad ha alcanzado su plenitud de 30 adeptos activos: ningún ingreso cabe sin una partida.',
}), { elementFactory: factory, documentRef: svgDocument });
const plenitudNode = findDescendant(casaLlena.element, (node) => String(node.className).includes('vestibule-card__vedado'));
assertCondition(plenitudNode !== null && plenitudNode.textContent.includes('plenitud de 30 adeptos'), 'la leyenda de plenitud nombra el límite del censo (RF-03.5)');
assertCondition(findDescendant(casaLlena.element, (node) => String(node.className).includes('vestibule-card__gesture')) === null, 'la casa llena jamás ofrece gesto (sin botón de ingreso)');

// Sin leyenda declarada: rótulo neutro, jamás cadena vacía ni texto técnico.
const sinLeyenda = createVestibuleClanCardComponent(clanDto({ gesture: null, vedadoLegend: null }), {
  elementFactory: factory,
  documentRef: svgDocument,
});
const neutroNode = findDescendant(sinLeyenda.element, (node) => String(node.className).includes('vestibule-card__vedado'));
assertCondition(typeof neutroNode.textContent === 'string' && neutroNode.textContent.length > 0, 'sin leyenda declarada, rótulo neutro de contemplación (jamás vacío)');

// =====================================================================
// [4] Teclado y delegación del gesto (RNF-03)
// =====================================================================
console.log('\n[4] Enfocable, operable por teclado y delegado (RNF-03)');

let gestureCalls = [];
const tecladoCard = createVestibuleClanCardComponent(clanDto({ gesture: 'join' }), {
  onGesture: (clanId, gesture) => gestureCalls.push({ clanId, gesture }),
  elementFactory: factory,
  documentRef: svgDocument,
});
assertCondition(tecladoCard.element.getAttribute('tabindex') === '0', 'la tarjeta es enfocable (tabindex=0, RNF-03)');

tecladoCard.element.dispatch('keydown', { key: 'Enter', target: tecladoCard.element });
assertCondition(gestureCalls.length === 1 && gestureCalls[0].gesture === 'join', 'Enter activa el gesto y delega {clanId, gesture} al orquestador');
tecladoCard.element.dispatch('keydown', { key: ' ', target: tecladoCard.element });
assertCondition(gestureCalls.length === 2, 'la espaciadora también activa (RNF-03)');
tecladoCard.element.dispatch('keydown', { key: 'Escape', target: tecladoCard.element });
assertCondition(gestureCalls.length === 2, 'otras teclas no activan gesto alguno');

// El botón interno delega; la tarjeta jamás consume adhesión por su cuenta.
// (joinCard nació en el bloque [1], con SU PROPIO callback aislado: el
// aserto verifica la delegación del botón, no el contador de otra tarjeta.)
let buttonCalls = [];
const deleganteCard = createVestibuleClanCardComponent(clanDto({ gesture: 'join' }), {
  onGesture: (clanId, gesture) => buttonCalls.push({ clanId, gesture }),
  elementFactory: factory,
  documentRef: svgDocument,
});
const deleganteButton = findDescendant(deleganteCard.element, (node) => String(node.className).includes('vestibule-card__gesture'));
deleganteButton.click();
assertCondition(buttonCalls.length === 1 && buttonCalls[0].clanId === 'cln_mareas' && buttonCalls[0].gesture === 'join', 'el botón del gesto delega en onGesture con {clanId, gesture}');

// Sin gesto: el teclado de la tarjeta es inerte (solo foco).
let intrusoCalls = [];
const inerteCard = createVestibuleClanCardComponent(clanDto({ gesture: null }), {
  onGesture: () => intrusoCalls.push({ clanId: 'intruso' }),
  elementFactory: factory,
  documentRef: svgDocument,
});
inerteCard.element.dispatch('keydown', { key: 'Enter', target: inerteCard.element });
inerteCard.element.dispatch('keydown', { key: ' ', target: inerteCard.element });
assertCondition(intrusoCalls.length === 0 && gestureCalls.length === 2, 'tarjeta vedada: el teclado jamás inventa un gesto (la interfaz no decide, RF-01.7)');

// =====================================================================
// [5] Estado vacío del catálogo (RF-01.4 de la SPEC-10)
// =====================================================================
console.log('\n[5] Estado vacío: leyenda e invitación discreta');

const emptyState = createVestibuleEmptyState({ elementFactory: factory });
const emptyText = emptyState.element.children.map((child) => child.textContent).join(' ');
assertCondition(emptyText.includes('Ninguna hermandad ruega aún tu linaje'), 'la leyenda canónica del vacío se pinta literal');
assertCondition(emptyText.includes('Podrás ser quien funde la primera.'), 'la invitación discreta remite a fundar (sin flujo propio, SPEC-07)');
assertCondition(emptyState.element.getAttribute('role') === 'status', 'el vacío se verbaliza por cortesía (región viva, RNF-03)');

// =====================================================================
// Resumen
// =====================================================================
console.log(`\n== RESUMEN == Asertos superados: ${assertsPassed}, fallidos: ${assertsFailed}`);
if (assertsFailed === 0) {
  console.log('RESULTADO: EXITO — Los cinco estados se pintan desde el DTO, el sello y la corona visten el kit, el vedado porta su leyenda y el vacío invita (Tarea 5.1).');
  process.exit(0);
}
console.log('RESULTADO: DENEGADO — Revisa los asertos marcados.');
process.exit(1);
