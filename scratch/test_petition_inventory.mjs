/**
 * test_petition_inventory.mjs — Arnés de la Tarea 5.4 (TASKS-10).
 *
 * Verifica el inventario consolidado de peticiones (`petitionInventoryComponent`),
 * según el «Hecho cuando»:
 *
 *   [1] «Tus peticiones pendientes: N de 3» (Anexo A 10 literal) con el
 *       límite canónico y la clausura por casa SIEMPRE visibles (RF-03.8,
 *       RF-03.1).
 *   [2] Cada petición lista casa y estado castellano (pendiente, dictamen
 *       recibido, retirada) desde el ClanPetitionDto (RF-03.8).
 *   [3] Retirada directa desde la lista: delega `onWithdraw(applicationId)`;
 *       tras la consumación, `removePetition` actualiza el inventario SIN
 *       recarga (RF-03.3).
 *   [4] Veredictos terminales sin leer marcados (`verdictSeen === false`),
 *       con el motivo del dictamen desfavorable visible (RF-03.4, III.3).
 *   [5] Rehidratación íntegra (`setPetitions`) y estado vacío; el componente
 *       jamás consume la API (Artículo II) y no usa innerHTML (Artículo I).
 *
 * Constitución:
 *   - Artículo I (Dogma Vanilla): DOM simulado mínimo, ES Modules nativos.
 *   - Artículo V: identificadores en inglés camelCase; comentarios en castellano.
 *
 * Uso: node scratch/test_petition_inventory.mjs
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
    disabled: false,
  };
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
  element.removeChild = (child) => {
    const index = element.children.indexOf(child);
    if (index !== -1) {
      element.children.splice(index, 1);
      child.parentNode = null;
    }
    return child;
  };
  element.remove = () => {
    if (element.parentNode) {
      element.parentNode.removeChild(element);
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

const {
  createPetitionInventoryComponent,
  formatPetitionInventoryTitle,
  PETITION_INVENTORY_TITLE_PATTERN,
  PETITION_INVENTORY_CLOSURE_LEGEND,
  PETITION_INVENTORY_UNREAD_LABEL,
  PETITION_INVENTORY_LIMIT,
} = await import('../public/assets/js/components/petitionInventoryComponent.js');

function findByClass(root, className, found = []) {
  for (const child of root.children ?? []) {
    if (child.classes?.has?.(className)) found.push(child);
    findByClass(child, className, found);
  }
  return found;
}

/** Sobre canónico `petitions[]` (plan §2.2, ClanPetitionDto serializado). */
function buildPetitions() {
  return [
    {
      applicationId: 'app_1',
      clanId: 'cln_brasa',
      clanName: 'Brasa Viva',
      status: 'pending',
      motivation: 'Sirvo a la forja desde mi primer conjuro de llama.',
      verdictMotive: null,
      verdictSeen: false,
      createdAt: '2026-09-20T10:00:00Z',
    },
    {
      applicationId: 'app_2',
      clanId: 'cln_marea',
      clanName: 'Marea Nocturna',
      status: 'rejected',
      motivation: 'Pido un lugar entre las mareas que no duermen.',
      verdictMotive: 'Tu vocación aún no ha florecido: vuelve cuando la marea te llame de verdad.',
      verdictSeen: false,
      createdAt: '2026-09-19T10:00:00Z',
    },
    {
      applicationId: 'app_3',
      clanId: 'cln_raiz',
      clanName: 'Raíz Antigua',
      status: 'cancelled',
      motivation: 'Retiro mi palabra: otra puerta me aguarda.',
      verdictMotive: null,
      verdictSeen: true,
      createdAt: '2026-09-18T10:00:00Z',
    },
  ];
}

function createInventory(overrides = {}) {
  const withdrawCalls = [];
  const inventory = createPetitionInventoryComponent(overrides.petitions ?? buildPetitions(), {
    documentRef,
    onWithdraw: (applicationId) => withdrawCalls.push(applicationId),
    ...overrides.options,
  });
  return { inventory, root: inventory.element, withdrawCalls };
}

console.log('== VERIFICACIÓN TAREA 5.4 (SPEC-10): Inventario consolidado ==\n');

// =====================================================================
// [1] Título canónico, límite y clausura visibles (RF-03.8, RF-03.1)
// =====================================================================
console.log('[1] «Tus peticiones pendientes: N de 3» con clausura visible');

assertCondition(PETITION_INVENTORY_LIMIT === 3, 'el límite canónico viaja como constante exportada (3)');
assertCondition(
  PETITION_INVENTORY_TITLE_PATTERN === 'Tus peticiones pendientes: {count} de {limit}.',
  'el patrón del título es el literal del Anexo A 10',
);
assertCondition(
  formatPetitionInventoryTitle(2) === 'Tus peticiones pendientes: 2 de 3.',
  'el título se compone con el recuento vivo (2 de 3)',
);
assertCondition(
  formatPetitionInventoryTitle(3, 3) === 'Tus peticiones pendientes: 3 de 3.',
  'el límite de 3 es visible en el propio título',
);

const parts1 = createInventory({ petitions: buildPetitions().slice(0, 2) });
const title1 = parts1.root.children[0];
assertCondition(
  title1.textContent === 'Tus peticiones pendientes: 2 de 3.',
  'el apéndice nace con el recuento exacto de peticiones',
);
const closure1 = parts1.root.children[1];
assertCondition(
  closure1.textContent === PETITION_INVENTORY_CLOSURE_LEGEND && closure1.textContent.includes('clausura'),
  'la leyenda de clausura por casa (RF-03.1) está SIEMPRE visible',
);

// =====================================================================
// [2] Cada petición lista casa y estado castellano (RF-03.8)
// =====================================================================
console.log('\n[2] Casa y estado castellano en cada entrada');

const parts2 = createInventory();
const list2 = findByClass(parts2.root, 'petition-inventory__list')[0];
assertCondition(list2.children.length === 3, 'las tres peticiones del sobre están listadas');

const item2a = list2.children[0];
assertCondition(item2a.getAttribute('data-application-id') === 'app_1', 'la entrada porta su identificador de solicitud');
assertCondition(item2a.getAttribute('data-status') === 'pending', 'la entrada porta su estado canónico');
const house2a = findByClass(item2a, 'petition-inventory__house')[0];
assertCondition(house2a.textContent === 'Brasa Viva', 'la casa se nombra desde el DTO (Velo Arcano)');
const status2a = findByClass(item2a, 'petition-inventory__status')[0];
assertCondition(status2a.textContent === 'Pendiente de dictamen', 'estado pendiente rotulado en castellano');

const item2b = list2.children[1];
assertCondition(
  findByClass(item2b, 'petition-inventory__status')[0].textContent === 'Dictamen desfavorable',
  'el dictamen recibido (rechazo) se rotula en castellano',
);
const item2c = list2.children[2];
assertCondition(
  findByClass(item2c, 'petition-inventory__status')[0].textContent === 'Retirada',
  'la retirada se rotula en castellano',
);

// =====================================================================
// [3] Retirada directa y mudanza sin recarga (RF-03.3)
// =====================================================================
console.log('\n[3] Retirada directa desde la lista, sin recarga');

const parts3 = createInventory();
const list3 = findByClass(parts3.root, 'petition-inventory__list')[0];
const pendingItem = list3.children[0];
const withdrawButton = findByClass(pendingItem, 'petition-inventory__withdraw')[0];
assertCondition(withdrawButton !== undefined, 'la petición pendiente expone la retirada directa');

// Los estados terminales carecen de gesto (la fila queda como memoria).
assertCondition(findByClass(list3.children[1], 'petition-inventory__withdraw').length === 0, 'la rechazada NO ofrece retirada (ya resuelta)');
assertCondition(findByClass(list3.children[2], 'petition-inventory__withdraw').length === 0, 'la retirada previa NO ofrece gesto (ya resuelta)');

withdrawButton.click();
assertCondition(
  parts3.withdrawCalls.length === 1 && parts3.withdrawCalls[0] === 'app_1',
  'el gesto delega onWithdraw(applicationId) — el componente jamás consume la API',
);
assertCondition(list3.children.length === 3, 'sin confirmación de la vista, el inventario NO muta (fuente única: el santuario)');

// La vista orquestadora anuncia la consumación: mudanza SIN recarga.
const mutated = parts3.inventory.removePetition('app_1');
assertCondition(mutated === true, 'removePetition informa de la mudanza efectiva');
assertCondition(findByClass(parts3.root, 'petition-inventory__list')[0].children.length === 2, 'el inventario repinta sin recarga (2 entradas)');
assertCondition(
  findByClass(parts3.root, 'petition-inventory__title')[0].textContent === 'Tus peticiones pendientes: 2 de 3.',
  'el título decrece a «2 de 3» sin recarga',
);
assertCondition(parts3.inventory.removePetition('app_inexistente') === false, 'retirar una petición ausente es un no-op honesto');

// =====================================================================
// [4] Veredictos sin leer marcados y motivo visible (RF-03.4, III.3)
// =====================================================================
console.log('\n[4] Veredictos sin leer marcados');

const parts4 = createInventory();
const list4 = findByClass(parts4.root, 'petition-inventory__list')[0];
assertCondition(
  findByClass(list4.children[0], 'petition-inventory__unread').length === 0,
  'la pendiente NO lleva marca de veredicto (no hay dictamen aún)',
);
const unreadRejected = list4.children[1];
assertCondition(
  unreadRejected.classes.has('petition-inventory__item--unread'),
  'el rechazo SIN contemplar porta la marca solemne de ítem',
);
const unreadBadge = findByClass(unreadRejected, 'petition-inventory__unread')[0];
assertCondition(
  unreadBadge.textContent === PETITION_INVENTORY_UNREAD_LABEL,
  'la marca «Dictamen a la espera de lectura» es la canónica (RF-03.4)',
);
const motive = findByClass(unreadRejected, 'petition-inventory__motive')[0];
assertCondition(
  motive.textContent === 'Tu vocación aún no ha florecido: vuelve cuando la marea te llame de verdad.',
  'el motivo solemne del dictamen (Artículo III.3) es visible',
);
// Contemplado: la marca desaparece, la fila permanece como memoria.
const readRejected = list4.children[2];
assertCondition(
  !readRejected.classes.has('petition-inventory__item--unread'),
  'el veredicto ya contemplado NO porta marca',
);

// =====================================================================
// [5] Rehidratación íntegra, estado vacío y Dogma Vanilla
// =====================================================================
console.log('\n[5] Rehidratación y estado vacío');

const parts5 = createInventory({ petitions: [] });
const emptyList = findByClass(parts5.root, 'petition-inventory__list')[0];
assertCondition(
  findByClass(parts5.root, 'petition-inventory__empty')[0].textContent === 'No tienes peticiones a la espera.',
  'el estado vacío es un rótulo de dirección, no un silencio',
);
assertCondition(
  findByClass(parts5.root, 'petition-inventory__title')[0].textContent === 'Tus peticiones pendientes: 0 de 3.',
  'el título refleja «0 de 3»',
);

// Rehidratación íntegra desde un nuevo sobre (veredictos contemplados).
parts5.inventory.setPetitions(buildPetitions());
const list5 = findByClass(parts5.root, 'petition-inventory__list')[0];
assertCondition(list5.children.length === 3, 'setPetitions rehidrata el inventario íntegro');
assertCondition(
  findByClass(parts5.root, 'petition-inventory__title')[0].textContent === 'Tus peticiones pendientes: 3 de 3.',
  'el título crece a «3 de 3» tras la rehidratación',
);

// Dogma Vanilla: sin innerHTML en el componente (Artículo I).
const { readFileSync } = await import('node:fs');
const componentSource = readFileSync(new URL('../public/assets/js/components/petitionInventoryComponent.js', import.meta.url), 'utf8');
assertCondition(!/innerHTML\s*=/.test(componentSource), 'Dogma Vanilla: innerHTML jamás asignado en el componente');
assertCondition(
  readFileSync(new URL('../public/assets/css/components/vestibule-card.css', import.meta.url), 'utf8').includes('petition-inventory__item--unread'),
  'el CSS de la marca de veredicto sin leer está cubierto en el kit del Vestíbulo',
);

// =====================================================================
// Veredicto
// =====================================================================
console.log('\n========================================');
console.log(`Asertos: ${assertsPassed} en verde, ${assertsFailed} en rojo, ${uncaughtErrors} crudos`);
if (assertsFailed === 0 && uncaughtErrors === 0 && assertsPassed > 0) {
  console.log('VEREDICTO: EXITO — el inventario consolidado cumple el «Hecho cuando» de la Tarea 5.4.');
} else {
  console.log('VEREDICTO: FALLA — revisar asertos en rojo.');
  process.exitCode = 1;
}
